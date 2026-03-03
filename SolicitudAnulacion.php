<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once 'Conexion.php'; 

/* ============================================================
    ASIGNACIÓN DE VARIABLES DE SESIÓN Y SEGURIDAD
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("Error: No se detectó una sesión activa válida.");
}

$puedeBorrarFisicamente = ($Usuario == '9999' || $Usuario == '0003');

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

$puedeCambiarFecha = (Autorizacion($Usuario, '9999') === 'SI');
$fechaConsulta = ($puedeCambiarFecha && isset($_GET['fConsulta'])) ? $_GET['fConsulta'] : (isset($_GET['fConsulta']) ? $_GET['fConsulta'] : date('Y-m-d'));
$fPosFormat    = date('Ymd', strtotime($fechaConsulta));

function conectarPOS($sede) {
    if ($sede == '002') {
        include_once 'ConnDrinks.php'; 
        return $GLOBALS['mysqliDrinks'] ?? null; 
    } else {
        include_once 'ConnCentral.php'; 
        return $GLOBALS['mysqliPos'] ?? null; 
    }
}

function VerificarAnulacion($NroDoc, $sede) {
    $db = conectarPOS($sede);
    if (!$db) return 0;
    
    $stmtP = $db->prepare("SELECT estado FROM PEDIDOS WHERE numero = ?");
    $stmtP->bind_param("s", $NroDoc);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($rowP = $resP->fetch_assoc()) {
        if ($rowP['estado'] != '0') return 1; 
    }
    
    $stmtF = $db->prepare("SELECT F.numero FROM FACTURAS F INNER JOIN DEVVENTAS D ON F.IDFACTURA = D.IDFACTURA WHERE F.numero = ?");
    $stmtF->bind_param("s", $NroDoc);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    return ($resF && $resF->num_rows > 0) ? 1 : 0;
}

/* ============================================================
    PROCESAR ACCIONES
============================================================ */
if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && $_GET['accion'] == 'borrar' && $puedeBorrarFisicamente) {
    $stmt = $mysqli->prepare("DELETE FROM solicitud_anulacion WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
    $stmt->bind_param("sss", $_GET['factura'], $_GET['f'], $_GET['s']);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && $_GET['accion'] == 'desactivar') {
    $f = $_GET['factura'];
    $s = $_GET['s'];
    $stmtV = $mysqli->prepare("SELECT NitCajero FROM solicitud_anulacion WHERE NroFactAnular=? AND NroSucursal=?");
    $stmtV->bind_param("ss", $f, $s);
    $stmtV->execute();
    $resV = $stmtV->get_result();
    $dataV = $resV->fetch_assoc();

    if ($dataV && $dataV['NitCajero'] == $Usuario && VerificarAnulacion($f, $s) == 0) {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET Estado = '0' WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
        $stmt->bind_param("sss", $f, $_GET['f'], $s);
        $stmt->execute();
    }
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v = (float)str_replace(['$', ' ', ','], '', $_POST['ValorAnular']);
    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fechaConsulta, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $_POST['motivo'], $reemplazo);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura']) && !in_array($_GET['accion'], ['borrar', 'desactivar'])) {
    $factTarget = $_GET['factura'];
    $fechaRef   = $_GET['f'] ?? $fechaConsulta;
    $ahora      = date('Y-m-d H:i:s');
    $sedeAccion = $_GET['s'] ?? $NroSucursal;
    
    if ($_GET['accion'] == 'bodega' && Autorizacion($Usuario, '0004') === 'SI') {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET JefeBodCheck='1', NitJefeBod=?, FH_JefeBodCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $sedeAccion);
        $stmt->execute();
    }
    
    if ($_GET['accion'] == 'gerencia' && Autorizacion($Usuario, '2010') === 'SI') {
        if (VerificarAnulacion($factTarget, $sedeAccion) == 1) {
            $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET GerenteCheck='1', NitGerente=?, FH_GerenteCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
            $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $sedeAccion);
            $stmt->execute();
        } else {
            header("Location: ?error=no_anulado&fConsulta=".$fechaConsulta); exit;
        }
    }
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

/* ============================================================
    DATOS PARA LA VISTA
============================================================ */
$dbSede = conectarPOS($NroSucursal);
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSedeActual = ($NroSucursal == '002') ? 'DRINKS' : 'CENTRAL';

$docsArray = [];
if ($dbSede) {
    $queryDocs = "SELECT NUMERO, VALORTOTAL FROM FACTURAS WHERE ESTADO='0' AND fecha='$fPosFormat' UNION ALL SELECT NUMERO, VALORTOTAL FROM PEDIDOS WHERE ESTADO='0' AND fecha='$fPosFormat' ORDER BY NUMERO DESC";
    $listaDocs = $dbSede->query($queryDocs);
    if($listaDocs) while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anulaciones - <?= $nombreSedeActual ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.75rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .btn-desactivar { color: #fd7e14; cursor: pointer; border: none; background: none; font-size: 1.1rem; }
        .btn-desactivar:disabled { color: #adb5bd; cursor: not-allowed; opacity: 0.5; }
        .btn-delete { color: #dc3545; cursor: pointer; border: none; background: none; font-size: 1.1rem; }
        .btn-delete:hover:not(:disabled), .btn-desactivar:hover:not(:disabled) { transform: scale(1.2); transition: 0.2s; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">SOLICITUDES ACTIVAS</h6>
            <input type="text" id="inputFiltro" class="form-control form-control-sm w-25" placeholder="🔍 Filtrar...">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0" id="tablaPrincipal">
                <thead class="table-dark">
                    <tr>
                        <th>HORA</th>
                        <th>SEDE</th>
                        <th>DOC. ANULAR</th>
                        <th>VALOR</th>
                        <th>REEMPLAZO</th>
                        <th>MOTIVO</th>
                        <th>BOD</th>
                        <th>GER</th>
                        <th>ESTADO POS</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $stmtH = $mysqli->prepare("SELECT * FROM solicitud_anulacion WHERE F_Creacion = ? AND Estado = '1' ORDER BY FH_CajeroCheck DESC");
                    $stmtH->bind_param("s", $fechaConsulta);
                    $stmtH->execute();
                    $resH = $stmtH->get_result();

                    while ($r = $resH->fetch_assoc()): 
                        $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                        $txtSede = ($r['NroSucursal'] == '002') ? 'DRINKS' : 'CENTRAL';
                        
                        // Lógica de validación
                        $esDueño = ($r['NitCajero'] == $Usuario);
                        $estaActivoPOS = ($anuladoPOS == 0);
                        $puedeDesactivar = ($esDueño && $estaActivoPOS);
                        
                        // Mensajes de ayuda para el usuario
                        $hint = "";
                        if (!$esDueño) $hint = "Solo el creador puede desactivar.";
                        elseif (!$estaActivoPOS) $hint = "No se puede desactivar un documento ya anulado en POS.";
                        else $hint = "Desactivar/Ocultar solicitud.";
                    ?>
                    <tr>
                        <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td>$<?= number_format($r['ValorFactAnular'], 0)?></td>
                        <td class="text-primary fw-bold"><?= $r['NroFactReemplaza'] ?></td>
                        <td class="text-start small"><?= $r['MotivoAnulacion'] ?></td>
                        <td>
                            <input class="form-check-input" type="checkbox" <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : (($esBodega) ? "onclick=\"confirmar('bodega', '{$r['NroFactAnular']}', '{$r['NroSucursal']}')\"" : 'disabled') ?>>
                        </td>
                        <td>
                            <?php if ($r['GerenteCheck'] == '1'): ?>
                                <input class="form-check-input" type="checkbox" checked disabled>
                            <?php elseif ($esGerente && $anuladoPOS == 1): ?>
                                <input class="form-check-input border-primary" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>')">
                            <?php else: ?>
                                <input class="form-check-input" type="checkbox" disabled>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($anuladoPOS) ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                <?= ($anuladoPOS) ? 'ANULADO OK' : 'ACTIVO' ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-center gap-2">
                                <button class="btn-desactivar" 
                                        title="<?= $hint ?>" 
                                        <?= !$puedeDesactivar ? 'disabled' : '' ?>
                                        onclick="gestionarSolicitud('desactivar', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>', '<?= $r['F_Creacion'] ?>')">
                                    ⚠️
                                </button>
                                
                                <?php if($puedeBorrarFisicamente): ?>
                                    <button class="btn-delete" title="Borrar de la BD" onclick="gestionarSolicitud('borrar', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>', '<?= $r['F_Creacion'] ?>')">🗑️</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function gestionarSolicitud(accion, fact, sede, fecha) {
        let msg = (accion === 'borrar') ? `¿ELIMINAR PERMANENTEMENTE?` : `¿OCULTAR esta solicitud?`;
        if (confirm(msg)) {
            window.location.href = `?accion=${accion}&factura=${fact}&s=${sede}&f=${fecha}`;
        }
    }
    // ... (Mantener resto de scripts de filtro y confirmación igual)
</script>
</body>
</html>