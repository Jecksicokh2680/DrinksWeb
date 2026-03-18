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
    $NroDoc = trim($NroDoc);

    $stmtP = $db->prepare("SELECT estado, fechaanul FROM pedidos WHERE numero = ? LIMIT 1");
    $stmtP->bind_param("s", $NroDoc);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($rowP = $resP->fetch_assoc()) {
        if ($rowP['estado'] != '0' || !empty(trim($rowP['fechaanul'] ?? ''))) return 1; 
    }    

    $stmtF = $db->prepare("SELECT estado, fechaanul, motivoanulacion FROM facturas WHERE numero = ? LIMIT 1");
    $stmtF->bind_param("s", $NroDoc);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    if ($rowF = $resF->fetch_assoc()) {
        $estado = (int)$rowF['estado'];
        $fecha  = trim($rowF['fechaanul'] ?? '');
        $motivo = trim($rowF['motivoanulacion'] ?? '');
        if (($estado !== 1 && $estado !== 0) || !empty($fecha) || !empty($motivo)) return 1;
    }

    $stmtD = $db->prepare("SELECT F.numero FROM facturas F INNER JOIN devventas D ON F.idfactura = D.idfactura WHERE F.numero = ?");
    $stmtD->bind_param("s", $NroDoc);
    $stmtD->execute();
    $resD = $stmtD->get_result();
    return ($resD && $resD->num_rows > 0) ? 1 : 0;
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
            header("Location: ?error=no_anulado&factura=".$factTarget."&fConsulta=".$fechaConsulta); exit;
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
    $queryDocs = "SELECT NUMERO, VALORTOTAL FROM facturas WHERE (estado = '0' OR estado = '1') AND fecha = '$fPosFormat' AND (fechaanul IS NULL OR fechaanul = '') UNION ALL SELECT NUMERO, VALORTOTAL FROM pedidos WHERE estado = '0' AND fecha = '$fPosFormat' ORDER BY NUMERO DESC";
    $listaDocs = $dbSede->query($queryDocs);
    if($listaDocs) while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="180"> <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anulaciones - <?= $nombreSedeActual ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.72rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        #timer-box { font-family: monospace; font-size: 0.9rem; background: #212529; padding: 2px 8px; border-radius: 4px; border: 1px solid #0d6efd; }
        .badge-wait { font-size: 0.6rem; display: block; color: #dc3545; font-weight: bold; }
        .bg-input-dark { background: #2b3035; color: white; border: 1px solid #495057; }
        .btn-delete { color: #dc3545; cursor: pointer; border: none; background: none; font-size: 1.1rem; }
        .uppercase-input { text-transform: uppercase; }
        .nombre-cajero { font-weight: bold; color: #212529; display: block; }
        .nit-cajero { font-size: 0.65rem; color: #6c757d; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= ($_GET['error'] == 'mismo_documento') ? "⚠️ No puedes usar el mismo documento como reemplazo." : "⚠️ El documento #".$_GET['factura']." sigue ACTIVO en el POS." ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

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
                <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
            </select>
        </div>
    </div>

    <div class="card p-3 shadow-sm border-0 mb-4">
        <form method="post" id="formAnulacion">
            <input type="hidden" name="FactAnular" id="FactAnular">
            <input type="hidden" name="ValorAnular" id="ValorAnular">
            <div class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" id="selAnular" required>
                        <option value="">-- Seleccione a Anular --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>"><?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="NroFactReemplaza" id="selReemplaza" class="form-select form-select-sm" required>
                        <option value="">-- Doc. Reemplazo --</option>
                        <option value="N/A">-- NO LLEVA NADA --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>"><?= $d['NUMERO'] ?></option>
                        <?php endforeach; ?>
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
                        <th>HORA</th><th>SEDE</th><th>CAJERO</th><th>DOC. ANULAR</th><th>VALOR</th><th>REEMPLAZO</th>
                        <th style="width: 20%;">MOTIVO</th><th>BODEGA</th><th>GERENCIA</th><th>ESTADO POS</th>
                        <?php if($puedeBorrar): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $sql = "SELECT s.*, t.Nombre as NombreCajero FROM solicitud_anulacion s 
                            LEFT JOIN terceros t ON s.NitCajero COLLATE utf8mb4_unicode_ci = t.CedulaNit 
                            WHERE s.F_Creacion = ? AND s.Estado = '1' ORDER BY s.FH_CajeroCheck DESC";
                    $stmtH = $mysqli->prepare($sql);
                    $stmtH->bind_param("s", $fechaConsulta);
                    $stmtH->execute();
                    $resH = $stmtH->get_result();
                    while ($r = $resH->fetch_assoc()): 
                        $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                        $txtSede = ($r['NroSucursal'] == '002') ? 'DRINKS' : 'CENTRAL';
                    ?>
                    <tr>
                        <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                        <td class="text-start">
                            <span class="nombre-cajero text-uppercase"><?= $r['NombreCajero'] ?: $r['NitCajero'] ?></span>
                            <span class="nit-cajero"><?= $r['NitCajero'] ?></span>
                        </td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0)?></td>
                        <td class="text-primary fw-bold"><?= $r['NroFactReemplaza'] ?></td>
                        <td class="text-start small"><?= $r['MotivoAnulacion'] ?></td>
                        <td><input class="form-check-input" type="checkbox" <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : (($esBodega) ? "onclick=\"confirmar('bodega', '{$r['NroFactAnular']}', '{$r['NroSucursal']}')\"" : 'disabled') ?>></td>
                        <td>
                            <?php if ($r['GerenteCheck'] == '1'): ?>
                                <input class="form-check-input" type="checkbox" checked disabled>
                            <?php elseif ($esGerente && $anuladoPOS == 1): ?>
                                <input class="form-check-input border-primary shadow-sm" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>')">
                            <?php else: ?>
                                <input class="form-check-input" type="checkbox" disabled>
                                <?php if($esGerente && $anuladoPOS == 0): ?><span class="badge-wait">ESPERANDO POS</span><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= ($anuladoPOS) ? 'bg-success' : 'bg-warning text-dark' ?> rounded-pill"><?= ($anuladoPOS) ? 'ANULADO OK' : 'ACTIVO' ?></span></td>
                        <?php if($puedeBorrar): ?>
                        <td><button class="btn-delete" onclick="borrarSolicitud('<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>', '<?= $r['F_Creacion'] ?>')">🗑️</button></td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
        }
    });

    function confirmar(tipo, fact, sede) {
        if (confirm(`¿Autorizar ${tipo.toUpperCase()} para la factura ${fact}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}&s=${sede}&f=<?= $fechaConsulta ?>`;
        } else { event.target.checked = false; }
    }

    function borrarSolicitud(fact, sede, fecha) {
        if (confirm(`¿Eliminar permanentemente la solicitud?`)) {
            window.location.href = `?accion=borrar&factura=${fact}&s=${sede}&f=${fecha}`;
        }
    }
</script>
</body>
</html>