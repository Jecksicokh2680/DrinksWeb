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

$puedeBorrar = ($Usuario == '9999' || $Usuario == '0003');

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
$fPosFormat     = date('Ymd', strtotime($fechaConsulta));

function conectarPOS($sede) {
    if ($sede == '002') {
        include_once 'ConnDrinks.php'; 
        return $GLOBALS['mysqliDrinks'] ?? null; 
    } else {
        include_once 'ConnCentral.php'; 
        return $GLOBALS['mysqliPos'] ?? null; 
    }
}

/**
 * FUNCIÓN ACTUALIZADA SEGÚN CAPTURA DE PANTALLA
 */
function VerificarAnulacion($NroDoc, $sede) {
    $db = conectarPOS($sede);
    if (!$db) return 0;
    
    // 1. Revisar en PEDIDOS
    $stmtP = $db->prepare("SELECT estado FROM PEDIDOS WHERE numero = ?");
    $stmtP->bind_param("s", $NroDoc);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($rowP = $resP->fetch_assoc()) {
        // Según tu imagen, el estado 1 es Anulado. 
        // Agregamos comprobación explícita para estado 1 y estado 9.
        if ($rowP['estado'] == '0' || $rowP['estado'] == '9' || $rowP['estado'] != '0') {
            return 1; 
        }
    }
    
    // 2. Revisar en FACTURAS (por si ya se cruzó a devolución)
    $stmtF = $db->prepare("SELECT F.numero FROM FACTURAS F INNER JOIN DEVVENTAS D ON F.IDFACTURA = D.IDFACTURA WHERE F.numero = ?");
    $stmtF->bind_param("s", $NroDoc);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    if ($resF && $resF->num_rows > 0) return 1;
    
    return 0;
}

/* ============================================================
    PROCESAR ACCIONES
============================================================ */
if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && $_GET['accion'] == 'borrar' && $puedeBorrar) {
    $stmt = $mysqli->prepare("DELETE FROM solicitud_anulacion WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
    $stmt->bind_param("sss", $_GET['factura'], $_GET['f'], $_GET['s']);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v = (float)str_replace(['$', ' ', ','], '', $_POST['ValorAnular']);
    $motivoMayusculas = mb_strtoupper($_POST['motivo'], 'UTF-8');
    
    if (!empty($reemplazo) && $factura === $reemplazo) {
        header("Location: ?error=mismo_documento&fConsulta=".$fechaConsulta); exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fechaConsulta, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $motivoMayusculas, $reemplazo);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura'])) {
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
        .table-xs { font-size: 0.72rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        .badge-wait { font-size: 0.6rem; display: block; color: #dc3545; font-weight: bold; }
        .bg-input-dark { background: #2b3035; color: white; border: 1px solid #495057; }
        .btn-delete { color: #dc3545; cursor: pointer; border: none; background: none; font-size: 1.1rem; }
        .nombre-cajero { font-weight: bold; color: #212529; display: block; }
        .nit-cajero { font-size: 0.65rem; color: #6c757d; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= ($_GET['error'] == 'mismo_documento') ? "⚠️ No puedes usar el mismo documento como reemplazo." : "⚠️ El documento aún aparece ACTIVO en el POS." ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info"><small class="text-secondary d-block">USUARIO</small><span class="fw-bold text-info"><?= $Usuario ?></span></div>
            <div class="header-info"><small class="text-secondary d-block">SEDE ACTUAL</small><span class="fw-bold"><?= $nombreSedeActual ?></span></div>
            <div>
                <small class="text-warning d-block fw-bold">FECHA TRABAJO</small>
                <input type="date" class="form-control form-control-sm bg-input-dark" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
            </div>
        </div>
        <div class="text-end">
            <small class="text-secondary d-block">CAMBIAR SEDE</small>
            <select class="form-select form-select-sm bg-dark text-white" onchange="location.href='?setSede='+this.value+'&fConsulta=<?= $fechaConsulta ?>'">
                <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
            </select>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">SOLICITUDES ACTIVAS</h6>
            <input type="text" id="inputFiltro" class="form-control form-control-sm w-25" placeholder="🔍 Buscar...">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0" id="tablaPrincipal">
                <thead class="table-dark">
                    <tr>
                        <th>HORA</th>
                        <th>SEDE</th>
                        <th>CAJERO / FACTURADOR</th>
                        <th>DOC. ANULAR</th>
                        <th>VALOR</th>
                        <th>REEMPLAZO</th>
                        <th style="width: 20%;">MOTIVO</th>
                        <th>BODEGA</th>
                        <th>GERENCIA</th>
                        <th>ESTADO POS</th>
                        <?php if($puedeBorrar): ?><th>ELIMINAR</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    // SQL con COLLATE para evitar Error 1267
                    $sql = "SELECT s.*, t.Nombre as NombreCajero 
                            FROM solicitud_anulacion s 
                            LEFT JOIN terceros t ON s.NitCajero COLLATE utf8mb4_unicode_ci = t.CedulaNit 
                            WHERE s.F_Creacion = ? AND s.Estado = '1' 
                            ORDER BY s.FH_CajeroCheck DESC";
                            
                    $stmtH = $mysqli->prepare($sql);
                    $stmtH->bind_param("s", $fechaConsulta);
                    $stmtH->execute();
                    $resH = $stmtH->get_result();

                    while ($r = $resH->fetch_assoc()): 
                        $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                        $txtSede = ($r['NroSucursal'] == '002') ? 'DRINKS' : 'CENTRAL';
                        $nombreDisplay = !empty($r['NombreCajero']) ? $r['NombreCajero'] : 'NO ENCONTRADO';
                    ?>
                    <tr>
                        <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                        <td class="text-start">
                            <span class="nombre-cajero text-uppercase"><?= $nombreDisplay ?></span>
                            <span class="nit-cajero"><?= $r['NitCajero'] ?></span>
                        </td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0)?></td>
                        <td class="text-primary fw-bold"><?= $r['NroFactReemplaza'] ?></td>
                        <td class="text-start small"><?= $r['MotivoAnulacion'] ?></td>
                        <td>
                            <input class="form-check-input" type="checkbox" 
                                <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : 
                                    (($esBodega) ? "onclick=\"confirmar('bodega', '{$r['NroFactAnular']}', '{$r['NroSucursal']}')\"" : 'disabled') ?>>
                        </td>
                        <td>
                            <?php if ($r['GerenteCheck'] == '1'): ?>
                                <input class="form-check-input" type="checkbox" checked disabled>
                            <?php elseif ($esGerente && $anuladoPOS == 1): ?>
                                <input class="form-check-input border-primary" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>')">
                            <?php else: ?>
                                <input class="form-check-input" type="checkbox" disabled>
                                <?php if($esGerente && $anuladoPOS == 0): ?><span class="badge-wait">ESPERANDO POS</span><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($anuladoPOS) ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                <?= ($anuladoPOS) ? 'ANULADO OK' : 'ACTIVO' ?>
                            </span>
                        </td>
                        <?php if($puedeBorrar): ?>
                        <td>
                            <button class="btn-delete" onclick="borrarSolicitud('<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>', '<?= $r['F_Creacion'] ?>')">🗑️</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.getElementById('inputFiltro').addEventListener('keyup', function() {
        let texto = this.value.toLowerCase();
        document.querySelectorAll('#tablaPrincipal tbody tr').forEach(fila => {
            fila.style.display = fila.textContent.toLowerCase().includes(texto) ? '' : 'none';
        });
    });

    function confirmar(tipo, fact, sede) {
        if (confirm(`¿Autorizar ${tipo} para la factura ${fact}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}&s=${sede}&f=<?= $fechaConsulta ?>`;
        } else {
            event.target.checked = false;
        }
    }

    function borrarSolicitud(fact, sede, fecha) {
        if (confirm(`¿Eliminar permanentemente la solicitud de ${fact}?`)) {
            window.location.href = `?accion=borrar&factura=${fact}&s=${sede}&f=${fecha}`;
        }
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>