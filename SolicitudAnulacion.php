<?php
session_start();
require 'auth_check.php';
date_default_timezone_set('America/Bogota'); 
require_once 'Conexion.php'; 

/* ============================================================
    CONFIGURACIÓN DE SEDES POR NIT
============================================================ */
define('NIT_CENTRAL', '86057267-8'); 
define('NIT_DRINKS', '901724534-7');  

/* ============================================================
    ASIGNACIÓN DE VARIABLES DE SESIÓN Y SEGURIDAD
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? NIT_CENTRAL; 

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("Error: No se detectó una sesión activa válida.");
}

// Permiso administrativo para borrar cualquier solicitud
$puedeBorrarGlobal = ($Usuario == '9999' || $Usuario == '0003'); 

/* ============================================================
    FUNCIONES DE AUTORIZACIÓN Y CONEXIÓN
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res && $row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

function conectarPOS($nitSede) {
    if ($nitSede == NIT_DRINKS) {
        include_once 'ConnDrinks.php'; 
        return $GLOBALS['mysqliDrinks'] ?? null; 
    } else {
        include_once 'ConnCentral.php'; 
        return $GLOBALS['mysqliPos'] ?? null; 
    }
}

function VerificarAnulacion($NroDoc, $nitSede) {
    $NroDoc = trim($NroDoc);
    if (empty($NroDoc)) return 0;

    $db = conectarPOS($nitSede);
    if (!$db) return 0;

    $stmtP = $db->prepare("SELECT estado, idusuarioanul, fechaanul FROM pedidos WHERE numero = ? LIMIT 1");
    if ($stmtP) {
        $stmtP->bind_param("s", $NroDoc);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        if ($rowP = $resP->fetch_assoc()) {
            if ($rowP['estado'] == 1 || !empty($rowP['idusuarioanul']) || !empty($rowP['fechaanul'])) {
                return 1; 
            }
        }
    }

    $stmtF = $db->prepare("SELECT idfactura, estado, fechaanul FROM facturas WHERE numero = ? LIMIT 1");
    if ($stmtF) {
        $stmtF->bind_param("s", $NroDoc);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        if ($rowF = $resF->fetch_assoc()) {
            if ($rowF['estado'] == 1 || !empty($rowF['fechaanul'])) return 1;

            $stmtD = $db->prepare("SELECT idfactura FROM devventas WHERE idfactura = ? LIMIT 1");
            if ($stmtD) {
                $stmtD->bind_param("i", $rowF['idfactura']);
                $stmtD->execute();
                if ($stmtD->get_result()->num_rows > 0) return 1;
            }
        }
    }
    return 0;
}

/* ============================================================
    PROCESAR ACCIONES / ENDPOINT AJAX PARA EL MODAL
============================================================ */
if (isset($_GET['ajax_detalle_doc'])) {
    header('Content-Type: application/json; charset=utf-8');
    $nroDoc = $_GET['ajax_detalle_doc'];
    $nitSede = $_GET['nit_sede'] ?? NIT_CENTRAL;
    $tipoDoc = $_GET['tipo_doc'] ?? 'F';

    $db = conectarPOS($nitSede);
    $resultado = ['error' => true, 'mensaje' => 'No se pudo conectar a la base de datos'];

    if ($db) {
        if ($tipoDoc === 'P') {
            $sql = "SELECT P.NUMERO, P.FECHA, P.VALORTOTAL, CLI.nombres AS CLIENTE, PROD.Descripcion AS PRODUCTO, DP.CANTIDAD, DP.VALORPROD 
                    FROM PEDIDOS P
                    INNER JOIN DETPEDIDOS DP ON P.IDPEDIDO = DP.IDPEDIDO
                    INNER JOIN PRODUCTOS PROD ON DP.IDPRODUCTO = PROD.IDPRODUCTO
                    LEFT JOIN TERCEROS CLI ON P.IDTERCERO = CLI.IDTERCERO
                    WHERE P.NUMERO = ?";
        } else {
            $sql = "SELECT F.NUMERO, F.FECHA, F.VALORTOTAL, CLI.nombres AS CLIENTE, PROD.Descripcion AS PRODUCTO, DF.CANTIDAD, DF.VALORPROD 
                    FROM FACTURAS F
                    INNER JOIN DETFACTURAS DF ON F.IDFACTURA = DF.IDFACTURA
                    INNER JOIN PRODUCTOS PROD ON DF.IDPRODUCTO = PROD.IDPRODUCTO
                    LEFT JOIN TERCEROS CLI ON F.IDTERCERO = CLI.IDTERCERO
                    WHERE F.NUMERO = ?";
        }

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $nroDoc);
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            $cliente = 'CLIENTE MOSTRADOR';
            $fecha = '';
            $total = 0;

            while ($row = $res->fetch_assoc()) {
                if (!empty($row['CLIENTE'])) $cliente = $row['CLIENTE'];
                $fecha = $row['FECHA'];
                $total = $row['VALORTOTAL'];
                $items[] = [
                    'producto' => $row['PRODUCTO'],
                    'cantidad' => $row['CANTIDAD'],
                    'valor' => $row['VALORPROD']
                ];
            }

            if (!empty($items)) {
                $resultado = [
                    'error' => false,
                    'numero' => $nroDoc,
                    'tipo' => ($tipoDoc === 'P' ? 'PEDIDO' : 'FACTURA'),
                    'fecha' => $fecha,
                    'cliente' => $cliente,
                    'total' => $total,
                    'items' => $items
                ];
            } else {
                $sqlAlt = ($tipoDoc === 'P') ? 
                    "SELECT F.NUMERO, F.FECHA, F.VALORTOTAL, CLI.nombres AS CLIENTE, PROD.Descripcion AS PRODUCTO, DF.CANTIDAD, DF.VALORPROD FROM FACTURAS F INNER JOIN DETFACTURAS DF ON F.IDFACTURA = DF.IDFACTURA INNER JOIN PRODUCTOS PROD ON DF.IDPRODUCTO = PROD.IDPRODUCTO LEFT JOIN TERCEROS CLI ON F.IDTERCERO = CLI.IDTERCERO WHERE F.NUMERO = ?" :
                    "SELECT P.NUMERO, P.FECHA, P.VALORTOTAL, CLI.nombres AS CLIENTE, PROD.Descripcion AS PRODUCTO, DP.CANTIDAD, DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON P.IDPEDIDO = DP.IDPEDIDO INNER JOIN PRODUCTOS PROD ON DP.IDPRODUCTO = PROD.IDPRODUCTO LEFT JOIN TERCEROS CLI ON P.IDTERCERO = CLI.IDTERCERO WHERE P.NUMERO = ?";
                
                $stmtAlt = $db->prepare($sqlAlt);
                if ($stmtAlt) {
                    $stmtAlt->bind_param("s", $nroDoc);
                    $stmtAlt->execute();
                    $resAlt = $stmtAlt->get_result();
                    $itemsAlt = [];
                    $tipoRealAlt = ($tipoDoc === 'P' ? 'FACTURA' : 'PEDIDO');

                    while ($rowAlt = $resAlt->fetch_assoc()) {
                        if (!empty($rowAlt['CLIENTE'])) $cliente = $rowAlt['CLIENTE'];
                        $fecha = $rowAlt['FECHA'];
                        $total = $rowAlt['VALORTOTAL'];
                        $itemsAlt[] = [
                            'producto' => $rowAlt['PRODUCTO'],
                            'cantidad' => $rowAlt['CANTIDAD'],
                            'valor' => $rowAlt['VALORPROD']
                        ];
                    }

                    if (!empty($itemsAlt)) {
                        $resultado = [
                            'error' => false,
                            'numero' => $nroDoc,
                            'tipo' => $tipoRealAlt,
                            'fecha' => $fecha,
                            'cliente' => $cliente,
                            'total' => $total,
                            'items' => $itemsAlt
                        ];
                    } else {
                        $resultado = ['error' => true, 'mensaje' => 'No se encontraron detalles para este documento en la base de datos.'];
                    }
                } else {
                    $resultado = ['error' => true, 'mensaje' => 'No se encontraron detalles para este documento.'];
                }
            }
        }
    }
    echo json_encode($resultado);
    exit;
}

$puedeCambiarFecha = (Autorizacion($Usuario, '9999') === 'SI');
$fechaConsulta = ($puedeCambiarFecha && isset($_GET['fConsulta'])) ? $_GET['fConsulta'] : (isset($_GET['fConsulta']) ? $_GET['fConsulta'] : date('Y-m-d'));
$fPosFormat    = date('Ymd', strtotime($fechaConsulta));

if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && $_GET['accion'] == 'borrar') {
    $facturaAEliminar = $_GET['factura'];
    $fechaAEliminar   = $_GET['f'];
    $sedeAEliminar    = $_GET['s'];

    if (!$puedeBorrarGlobal) {
        $stmtCheck = $mysqli->prepare("SELECT NitCajero, JefeBodCheck, GerenteCheck FROM solicitud_anulacion WHERE NroFactAnular=? AND F_Creacion=? AND Nit_Empresa=?");
        $stmtCheck->bind_param("sss", $facturaAEliminar, $fechaAEliminar, $sedeAEliminar);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result()->fetch_assoc();

        $anuladoEnPOS = VerificarAnulacion($facturaAEliminar, $sedeAEliminar);

        if (!$resCheck || $resCheck['NitCajero'] !== $Usuario || $resCheck['JefeBodCheck'] == '1' || $resCheck['GerenteCheck'] == '1' || $anuladoEnPOS == 1) {
            die("Error: No tienes permisos para eliminar esta solicitud o ya ha sido procesada.");
        }
    }

    $stmt = $mysqli->prepare("DELETE FROM solicitud_anulacion WHERE NroFactAnular=? AND F_Creacion=? AND Nit_Empresa=?");
    $stmt->bind_param("sss", $facturaAEliminar, $fechaAEliminar, $sedeAEliminar);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v         = (float)str_replace(['$', ' ', ','], '', $_POST['ValorAnular']);
    $tipoDoc   = $_POST['TipoDoc'] ?? ''; 
    $motivoMayusculas = mb_strtoupper($_POST['motivo'], 'UTF-8');
    
    if (!empty($reemplazo) && $factura === $reemplazo) {
        header("Location: ?error=mismo_documento&fConsulta=".$fechaConsulta); exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado, Tipo) VALUES (?,?,?,?,?,?,?,?,?, '1', ?)");
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdsss", $fechaConsulta, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $motivoMayusculas, $reemplazo, $tipoDoc);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura'])) {
    $factTarget = $_GET['factura'];
    $fechaRef   = $_GET['f'] ?? $fechaConsulta;
    $ahora      = date('Y-m-d H:i:s');
    $nitSedeAccion = $_GET['s'] ?? $NitEmpresa;
    
    if ($_GET['accion'] == 'bodega' && Autorizacion($Usuario, '0004') === 'SI') {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET JefeBodCheck='1', NitJefeBod=?, FH_JefeBodCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND Nit_Empresa=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $nitSedeAccion);
        $stmt->execute();
    }
    
    if ($_GET['accion'] == 'gerencia' && Autorizacion($Usuario, '2010') === 'SI') {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET GerenteCheck='1', NitGerente=?, FH_GerenteCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND Nit_Empresa=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $nitSedeAccion);
        $stmt->execute();
    }
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

/* ============================================================
    DATOS PARA LA VISTA
============================================================ */
$dbSede = conectarPOS($NroSucursal);
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSedeActual = ($NroSucursal == NIT_DRINKS) ? 'DRINKS' : 'CENTRAL';

$docsArray = [];
if ($dbSede) {
    $queryDocs = "SELECT 'FACTURA' AS TIPO, NUMERO, VALORTOTAL FROM facturas WHERE (estado = '0' OR estado = '1') AND fecha = '$fPosFormat' AND (fechaanul IS NULL OR fechaanul = '') 
                  UNION ALL 
                  SELECT 'PEDIDO' AS TIPO, NUMERO, VALORTOTAL FROM pedidos WHERE estado = '0' AND fecha = '$fPosFormat' 
                  ORDER BY NUMERO DESC";
    $listaDocs = $dbSede->query($queryDocs);
    if($listaDocs) {
        while($row = $listaDocs->fetch_assoc()) { 
            $docsArray[] = $row; 
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="180">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anulaciones - <?= $nombreSedeActual ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.72rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        #timer-box { font-family: monospace; font-size: 0.9rem; background: #212529; padding: 2px 8px; border-radius: 4px; border: 1px solid #0d6efd; }
        .badge-wait { font-size: 0.6rem; display: block; color: #0d6efd; font-weight: bold; }
        .bg-input-dark { background: #2b3035; color: white; border: 1px solid #495057; }
        .btn-delete { color: #dc3545; cursor: pointer; border: none; background: none; font-size: 1.1rem; }
        .uppercase-input { text-transform: uppercase; }
        .nombre-cajero { font-weight: bold; color: #212529; display: block; }
        .nit-cajero { font-size: 0.65rem; color: #6c757d; }
        .doc-link { color: #0d6efd; text-decoration: underline; cursor: pointer; font-weight: bold; }
        .doc-link:hover { color: #0a58ca; }
        .reemplazo-link { color: #198754; text-decoration: underline; cursor: pointer; font-weight: bold; }
        .reemplazo-link:hover { color: #146c43; }

        /* Estilos para Ventanas Flotantes Arrastrables */
        .floating-window {
            position: fixed;
            z-index: 1050;
            width: 480px;
            max-width: 95vw;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            border: 1px solid rgba(0,0,0,0.15);
            display: none;
            resize: both;
            overflow: hidden;
        }
        .floating-header {
            background: #212529;
            color: #fff;
            padding: 10px 15px;
            cursor: move;
            user-select: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .floating-body {
            padding: 15px;
            max-height: 70vh;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info"><small class="text-secondary d-block">USUARIO</small><span class="fw-bold text-info"><?= $Usuario ?></span></div>
            <div class="header-info"><small class="text-secondary d-block">SEDE ACTUAL</small><span class="fw-bold text-primary"><?= $nombreSedeActual ?></span></div>
            <div class="header-info">
                <small class="text-warning d-block fw-bold">FECHA TRABAJO</small>
                <?php if($puedeCambiarFecha): ?>
                    <input type="date" class="form-control form-control-sm bg-input-dark" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
                <?php else: ?>
                    <span class="fw-bold"><?= $fechaConsulta ?></span>
                <?php endif; ?>
            </div>
            <div><small class="text-secondary d-block">ACTUALIZACIÓN</small><span id="timer-box" class="text-warning">180s</span></div>
        </div>
        <div class="text-end">
            <small class="text-secondary d-block">CAMBIAR SEDE</small>
            <select class="form-select form-select-sm bg-dark text-white" onchange="location.href='?setSede='+this.value+'&fConsulta=<?= $fechaConsulta ?>'">
                <option value="<?= NIT_CENTRAL ?>" <?= ($NroSucursal==NIT_CENTRAL)?'selected':'' ?>>CENTRAL</option>
                <option value="<?= NIT_DRINKS ?>" <?= ($NroSucursal==NIT_DRINKS)?'selected':'' ?>>DRINKS</option>
            </select>
        </div>
    </div>

    <div class="card p-3 shadow-sm border-0 mb-4">
        <form method="post" id="formAnulacion">
            <input type="hidden" name="FactAnular" id="FactAnular">
            <input type="hidden" name="ValorAnular" id="ValorAnular">
            <input type="hidden" name="TipoDoc" id="TipoDoc">
            
            <div class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" id="selAnular" required>
                        <option value="">-- Seleccione a Anular --</option>
                        <?php if(!empty($docsArray)): ?>
                            <?php foreach($docsArray as $d): ?>
                                <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>" data-tipo="<?= $d['TIPO'] ?>">[<?= $d['TIPO'] ?>] <?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="NroFactReemplaza" id="selReemplaza" class="form-select form-select-sm" required>
                        <option value="">-- Doc. Reemplazo --</option>
                        <option value="N/A">-- NO LLEVA NADA --</option>
                        <?php if(!empty($docsArray)): ?>
                            <?php foreach($docsArray as $d): ?>
                                <option value="<?= $d['NUMERO'] ?>">[<?= $d['TIPO'] ?>] <?= $d['NUMERO'] ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="motivo" class="form-control form-control-sm uppercase-input" required placeholder="Motivo...">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="grabar" class="btn btn-primary btn-sm w-100 fw-bold">CREAR SOLICITUD</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>HORA</th><th>SEDE</th><th>CAJERO</th><th>TIPO</th><th>DOC. ANULAR</th><th>VALOR</th><th>REEMPLAZO</th>
                        <th style="width: 20%;">MOTIVO</th><th>BODEGA</th><th>GERENCIA</th><th>ESTADO POS</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $sql = "SELECT s.*, t.Nombre as NombreCajero FROM solicitud_anulacion s 
                            LEFT JOIN terceros t ON s.NitCajero COLLATE utf8mb4_unicode_ci = t.CedulaNit 
                            WHERE s.F_Creacion = ? AND s.Estado = '1' 
                            ORDER BY s.Nit_Empresa ASC, s.FH_CajeroCheck DESC";
                    $stmtH = $mysqli->prepare($sql);
                    if($stmtH) {
                        $stmtH->bind_param("s", $fechaConsulta);
                        $stmtH->execute();
                        $resH = $stmtH->get_result();
                        while ($r = $resH->fetch_assoc()): 
                            $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['Nit_Empresa']);
                            $txtSede = ($r['Nit_Empresa'] == NIT_DRINKS) ? 'DRINKS' : 'CENTRAL';
                            
                            $badgeEstado = ($anuladoPOS || $r['GerenteCheck'] == '1') ? 'bg-success' : 'bg-warning text-dark';
                            $textoEstado = ($anuladoPOS || $r['GerenteCheck'] == '1') ? 'ANULADO OK' : 'ACTIVO';
                            
                            $tipoBadgeColor = ($r['Tipo'] === 'P') ? 'bg-info text-dark' : 'bg-primary';
                            $tipoTexto      = ($r['Tipo'] === 'P') ? 'PEDIDO' : 'FACTURA';

                            $esCreador = ($r['NitCajero'] === $Usuario);
                            $estaLimpio = ($r['JefeBodCheck'] !== '1' && $r['GerenteCheck'] !== '1' && $anuladoPOS == 0);
                            $puedeBorrarPropio = ($esCreador && $estaLimpio);
                        ?>
                        <tr>
                            <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                            <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                            <td class="text-start">
                                <span class="nombre-cajero text-uppercase"><?= $r['NombreCajero'] ?: $r['NitCajero'] ?></span>
                                <span class="nit-cajero"><?= $r['NitCajero'] ?></span>
                            </td>
                            <td><span class="badge <?= $tipoBadgeColor ?>"><?= $tipoTexto ?></span></td>
                            <td>
                                <span class="doc-link" onclick="abrirVentanaFlotante('<?= $r['NroFactAnular'] ?>', '<?= $r['Nit_Empresa'] ?>', '<?= $r['Tipo'] ?>')">
                                    <?= $r['NroFactAnular'] ?>
                                </span>
                            </td>
                            <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0)?></td>
                            <td>
                                <?php if (!empty($r['NroFactReemplaza']) && $r['NroFactReemplaza'] !== 'N/A'): ?>
                                    <span class="reemplazo-link" onclick="abrirVentanaFlotante('<?= $r['NroFactReemplaza'] ?>', '<?= $r['Nit_Empresa'] ?>', '<?= $r['Tipo'] ?>')">
                                        <?= $r['NroFactReemplaza'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-start small"><?= $r['MotivoAnulacion'] ?></td>
                            
                            <td><input class="form-check-input" type="checkbox" <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : (($esBodega) ? "onclick=\"confirmar('bodega', '{$r['NroFactAnular']}', '{$r['Nit_Empresa']}')\"" : 'disabled') ?>></td>
                            
                            <td>
                                <?php if ($r['GerenteCheck'] == '1'): ?>
                                    <input class="form-check-input" type="checkbox" checked disabled>
                                <?php elseif ($esGerente): ?>
                                    <input class="form-check-input border-primary shadow-sm" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>', '<?= $r['Nit_Empresa'] ?>')">
                                    <?php if($anuladoPOS == 0): ?><span class="badge-wait">PENDIENTE POS</span><?php endif; ?>
                                <?php else: ?>
                                    <input class="form-check-input" type="checkbox" disabled>
                                <?php endif; ?>
                            </td>

                            <td><span class="badge <?= $badgeEstado ?> rounded-pill"><?= $textoEstado ?></span></td>
                            
                            <td>
                                <?php if($puedeBorrarGlobal || $puedeBorrarPropio): ?>
                                    <button class="btn-delete" title="Eliminar Solicitud" onclick="borrarSolicitud('<?= $r['NroFactAnular'] ?>', '<?= $r['Nit_Empresa'] ?>', '<?= $r['F_Creacion'] ?>')">🗑️</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php 
                        endwhile; 
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Contenedor Dinámico para Ventanas Flotantes Múltiples -->
<div id="contenedorVentanasFlotantes"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let timeLeft = 180;
    const timerDisplay = document.getElementById('timer-box');
    setInterval(() => {
        timeLeft--;
        timerDisplay.textContent = timeLeft + "s";
        if (timeLeft <= 10) timerDisplay.classList.replace('text-warning', 'text-danger');
        if (timeLeft <= 0) window.location.reload();
    }, 1000);

    document.getElementById('formAnulacion').addEventListener('submit', function(e) {
        const sel = document.getElementById('selAnular');
        const opt = sel.options[sel.selectedIndex];
        if (opt.value !== "") {
            document.getElementById('FactAnular').value = opt.value;
            document.getElementById('ValorAnular').value = opt.dataset.valor || '0';
            
            const tipoCompleto = opt.dataset.tipo || 'FACTURA';
            document.getElementById('TipoDoc').value = (tipoCompleto === 'PEDIDO') ? 'P' : 'F';
        }
    });

    function confirmar(tipo, fact, nitSede) {
        if (confirm(`¿Autorizar ${tipo.toUpperCase()} para la factura ${fact}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}&s=${nitSede}&f=<?= $fechaConsulta ?>`;
        } else { event.target.checked = false; }
    }

    function borrarSolicitud(fact, nitSede, fecha) {
        if (confirm(`¿Eliminar permanentemente la solicitud?`)) {
            window.location.href = `?accion=borrar&factura=${fact}&s=${nitSede}&f=${fecha}`;
        }
    }

    let contadorVentanas = 0;

    function abrirVentanaFlotante(nroDoc, nitSede, tipoDoc) {
        contadorVentanas++;
        const windowId = `floating_win_${contadorVentanas}`;
        
        // Posicionamiento inteligente en cascada o disperso para poder ver varios a la vez
        const offsetX = 50 + ((contadorVentanas % 5) * 30);
        const offsetY = 80 + ((contadorVentanas % 5) * 30);

        const winHtml = `
            <div id="${windowId}" class="floating-window shadow" style="display: block; top: ${offsetY}px; left: ${offsetX}px;">
                <div class="floating-header" onmousedown="iniciarArrastre(event, '${windowId}')">
                    <span class="fw-bold">Doc: <span class="text-info">${nroDoc}</span></span>
                    <button type="button" class="btn-close btn-close-white btn-sm" onclick="document.getElementById('${windowId}').remove()"></button>
                </div>
                <div class="floating-body">
                    <div class="row mb-2 small">
                        <div class="col-4"><strong>Tipo:</strong> <span class="win-tipo">-</span></div>
                        <div class="col-4"><strong>Fecha:</strong> <span class="win-fecha">-</span></div>
                        <div class="col-4"><strong>Cliente:</strong> <span class="win-cliente">-</span></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-bordered text-center align-middle" style="font-size: 0.8rem;">
                            <thead class="table-secondary">
                                <tr>
                                    <th class="text-start">Producto</th>
                                    <th>Cant</th>
                                    <th class="text-end">V. Unit</th>
                                    <th class="text-end">V. Total</th>
                                </tr>
                            </thead>
                            <tbody class="win-tabla-items">
                                <tr><td colspan="4" class="text-center">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end fw-bold mt-2 small">
                        Total: $<span class="win-total">0</span>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('contenedorVentanasFlotantes').insertAdjacentHTML('beforeend', winHtml);
        const winElement = document.getElementById(windowId);

        // Traer al frente al hacer clic en cualquier parte de la ventana
        winElement.addEventListener('mousedown', () => {
            document.querySelectorAll('.floating-window').forEach(w => w.style.zIndex = '1050');
            winElement.style.zIndex = '1055';
        });

        // Petición AJAX para llenar la ventana
        fetch(`?ajax_detalle_doc=${nroDoc}&nit_sede=${nitSede}&tipo_doc=${tipoDoc}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    winElement.querySelector('.win-tabla-items').innerHTML = `<tr><td colspan="4" class="text-center text-danger">${data.mensaje}</td></tr>`;
                    return;
                }

                winElement.querySelector('.win-tipo').textContent = data.tipo;
                winElement.querySelector('.win-fecha').textContent = data.fecha;
                winElement.querySelector('.win-cliente').textContent = data.cliente;
                winElement.querySelector('.win-total').textContent = Number(data.total).toLocaleString('es-CO');

                let htmlItems = '';
                data.items.forEach(item => {
                    let vTotalItem = item.cantidad * item.valor;
                    htmlItems += `
                        <tr>
                            <td class="text-start">${item.producto}</td>
                            <td>${item.cantidad}</td>
                            <td class="text-end">$${Number(item.valor).toLocaleString('es-CO')}</td>
                            <td class="text-end">$${Number(vTotalItem).toLocaleString('es-CO')}</td>
                        </tr>
                    `;
                });
                winElement.querySelector('.win-tabla-items').innerHTML = htmlItems;
            })
            .catch(err => {
                winElement.querySelector('.win-tabla-items').innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error al cargar.</td></tr>';
            });
    }

    // Lógica para arrastrar las ventanas flotantes desde la cabecera
    function iniciarArrastre(e, windowId) {
        if (e.target.tagName === 'BUTTON') return; // Evita interferir con el botón de cerrar
        const win = document.getElementById(windowId);
        let startX = e.clientX;
        let startY = e.clientY;
        let initialLeft = win.offsetLeft;
        let initialTop = win.offsetTop;

        function enMovimiento(e) {
            let dx = e.clientX - startX;
            let dy = e.clientY - startY;
            win.style.left = (initialLeft + dx) + 'px';
            win.style.top = (initialTop + dy) + 'px';
        }

        function soltar() {
            window.removeEventListener('mousemove', enMovimiento);
            window.removeEventListener('mouseup', soltar);
        }

        window.addEventListener('mousemove', enMovimiento);
        window.addEventListener('mouseup', soltar);
    }
</script>
</body>
</html>