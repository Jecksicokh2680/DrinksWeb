<?php
session_start();
date_default_timezone_set('America/Bogota'); 
require_once 'Conexion.php'; // Se asume que define $mysqli

/* ============================================================
    CONFIGURACIÓN DE SEDES POR NIT
============================================================ */
define('NIT_CENTRAL', '86057267-8'); 
define('NIT_DRINKS', '901724534-7');  

/* ============================================================
    ASIGNACIÓN DE VARIABLES DE SESIÓN Y SEGURIDAD
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa']  ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? NIT_CENTRAL; 

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("Error: No se detectó una sesión activa válida.");
}

/* ============================================================
    FUNCIONES DE AUTORIZACIÓN Y CONEXIÓN (Inyección de dependencia)
============================================================ */
function Autorizacion($mysqliConn, $User, $Solicitud) {
    if (!$mysqliConn) return 'NO';
    $stmt = $mysqliConn->prepare("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res && $row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

// Control estricto: Si no es Jefe de Bodega (Código 0004), no entra
$esBodega = (Autorizacion($mysqli, $Usuario, '0004') === 'SI');
if (!$esBodega) {
    die("Error: No tienes los permisos requeridos (Jefe de Bodega) para acceder a este resumen.");
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
            if ($rowP['estado'] == 1 || !empty($rowP['idusuarioanul']) || !empty($rowP['fechaanul'])) return 1; 
        }
    }

    $stmtF = $db->prepare("SELECT idfactura, estado, fechaanul FROM facturas WHERE numero = ? LIMIT 1");
    if ($stmtF) {
        $stmtF->bind_param("s", $NroDoc);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        if ($rowF = $resF->fetch_assoc()) {
            if ($rowF['estado'] == 1 || !empty($rowF['fechaanul'])) return 1;
        }
    }
    return 0;
}

/* ============================================================
    PROCESAR FILTRO DE FECHA (Seguridad aplicada)
============================================================ */
$puedeCambiarFecha = (Autorizacion($mysqli, $Usuario, '9999') === 'SI');
// Si no puede cambiar fecha, siempre será el día de hoy
$fechaConsulta = ($puedeCambiarFecha && isset($_GET['fConsulta'])) ? $_GET['fConsulta'] : date('Y-m-d');

/* ============================================================
    PROCESAR ACCIONES POR POST (Más seguro contra CSRF)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'bodega') {
    $factTarget    = $_POST['factura'] ?? '';
    $fechaRef      = $_POST['f'] ?? $fechaConsulta;
    $nitSedeAccion = $_POST['s'] ?? $NitEmpresa;
    $ahora         = date('Y-m-d H:i:s');
    
    if (!empty($factTarget)) {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET JefeBodCheck='1', NitJefeBod=?, FH_JefeBodCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND Nit_Empresa=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $nitSedeAccion);
        $stmt->execute();
    }
    
    header("Location: ?fConsulta=" . $fechaConsulta); 
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="60"> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Control - Jefe de Bodega</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.8rem; }
        .header-bar { background: #112233; color: #fff; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #ffc107; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        #timer-box { font-family: monospace; font-size: 0.9rem; background: #212529; padding: 2px 8px; border-radius: 4px; border: 1px solid #ffc107; }
        .nombre-cajero { font-weight: bold; color: #212529; display: block; }
        .nit-cajero { font-size: 0.7rem; color: #6c757d; }
        .form-check-input { width: 1.5em; height: 1.5em; cursor: pointer; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info"><small class="text-secondary d-block">JEFE BODEGA</small><span class="fw-bold text-warning"><?= htmlspecialchars($Usuario) ?></span></div>
            <div class="header-info">
                <small class="text-secondary d-block fw-bold">FECHA CONSULTA</small>
                <?php if($puedeCambiarFecha): ?>
                    <input type="date" class="form-control form-control-sm bg-dark text-white border-secondary" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
                <?php else: ?>
                    <span class="fw-bold text-info"><?= $fechaConsulta ?></span>
                <?php endif; ?>
            </div>
            <div><small class="text-secondary d-block">MONITOR ACTIVOS</small><span id="timer-box" class="text-warning">60s</span></div>
        </div>
        <div class="text-end">
            <h5 class="m-0 text-white fw-bold">Solicitudes de Anulación</h5>
            <small class="text-secondary">Control General de Sedes</small>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span class="fw-bold m-0">📋 Pendientes por Validar e Inventario</span>
            <span class="badge bg-warning text-dark">Doble verificación requerida</span>
        </div>
        <div class="table-responsive">
            <form id="formAprobar" method="POST" action="">
                <input type="hidden" name="accion" value="bodega">
                <input type="hidden" name="factura" id="postFactura" value="">
                <input type="hidden" name="s" id="postSede" value="">
                <input type="hidden" name="f" value="<?= $fechaConsulta ?>">
            </form>

            <table class="table table-hover table-xs align-middle text-center mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>HORA</th>
                        <th>SEDE</th>
                        <th>SOLICITA (CAJERO)</th>
                        <th>TIPO</th>
                        <th>DOCUMENTO A ANULAR</th>
                        <th>VALOR REGISTRADO</th>
                        <th>DOC. REEMPLAZO</th>
                        <th style="width: 25%;">MOTIVO EXPLICADO</th>
                        <th class="table-warning" style="width: 12%;">APROBAR BODEGA</th>
                        <th>ESTADO FINAL</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $sql = "SELECT s.*, t.Nombre as NombreCajero FROM solicitud_anulacion s 
                            LEFT JOIN terceros t ON s.NitCajero COLLATE utf8mb4_unicode_ci = t.CedulaNit 
                            WHERE s.F_Creacion = ? AND s.Estado = '1' 
                            ORDER BY s.JefeBodCheck ASC, s.FH_CajeroCheck DESC";
                    
                    $stmtH = $mysqli->prepare($sql);
                    if($stmtH):
                        $stmtH->bind_param("s", $fechaConsulta);
                        $stmtH->execute();
                        $resH = $stmtH->get_result();
                        
                        if($resH->num_rows === 0): ?>
                            <tr>
                                <td colspan="10" class="text-muted py-4">No hay solicitudes registradas para la fecha seleccionada.</td>
                            </tr>
                        <?php endif;

                        while ($r = $resH->fetch_assoc()): 
                            $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['Nit_Empresa']);
                            $txtSede = ($r['Nit_Empresa'] == NIT_DRINKS) ? 'DRINKS' : 'CENTRAL';
                            
                            $badgeEstado = ($anuladoPOS || $r['GerenteCheck'] == '1') ? 'bg-success' : 'bg-warning text-dark';
                            $textoEstado = ($anuladoPOS || $r['GerenteCheck'] == '1') ? 'ANULADO OK' : 'EN ESPERA GERENCIA';
                            
                            $tipoBadgeColor = ($r['Tipo'] === 'P') ? 'bg-info text-dark' : 'bg-primary';
                            $tipoTexto      = ($r['Tipo'] === 'P') ? 'PEDIDO' : 'FACTURA';
                        ?>
                        <tr class="<?= ($r['JefeBodCheck']=='1') ? 'table-light text-secondary' : '' ?>">
                            <td><strong><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></strong></td>
                            <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                            <td class="text-start">
                                <span class="nombre-cajero text-uppercase"><?= htmlspecialchars($r['NombreCajero'] ?: $r['NitCajero']) ?></span>
                                <span class="nit-cajero">ID: <?= htmlspecialchars($r['NitCajero']) ?></span>
                            </td>
                            <td><span class="badge <?= $tipoBadgeColor ?>"><?= $tipoTexto ?></span></td>
                            <td class="fw-bold text-danger fs-6"><?= htmlspecialchars($r['NroFactAnular']) ?></td>
                            <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0)?></td>
                            <td class="text-primary fw-bold"><?= htmlspecialchars($r['NroFactReemplaza']) ?></td>
                            <td class="text-start bg-light italic small"><?= htmlspecialchars($r['MotivoAnulacion']) ?></td>
                            
                            <td class="table-warning">
                                <?php if ($r['JefeBodCheck'] == '1'): ?>
                                    <span class="badge bg-success">✔️ APROBADO</span>
                                    <small class="d-block text-muted" style="font-size:0.6rem;"><?= date('H:i', strtotime($r['FH_JefeBodCheck'])) ?></small>
                                <?php else: ?>
                                    <input class="form-check-input border-danger shadow-sm" type="checkbox" 
                                           onclick="confirmarAprobacion(this, '<?= htmlspecialchars($r['NroFactAnular']) ?>', '<?= htmlspecialchars($r['Nit_Empresa']) ?>')">
                                <?php endif; ?>
                            </td>

                            <td><span class="badge <?= $badgeEstado ?> rounded-pill text-uppercase"><?= $textoEstado ?></span></td>
                        </tr>
                        <?php 
                        endwhile; 
                    endif;
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Contador para refrescar la pantalla automáticamente
    let timeLeft = 60;
    const timerDisplay = document.getElementById('timer-box');
    setInterval(() => {
        timeLeft--;
        timerDisplay.textContent = timeLeft + "s";
        if (timeLeft <= 10) timerDisplay.classList.replace('text-warning', 'text-danger');
        if (timeLeft <= 0) window.location.reload();
    }, 1000);

    // Confirmación nativa y envío por POST seguro
    function confirmarAprobacion(element, fact, nitSede) {
        if (confirm(`¿Dar el Visto Bueno (Bodega) para el Documento Nro: ${fact}?\nEsto confirmará que la mercancía está físicamente disponible.`)) {
            document.getElementById('postFactura').value = fact;
            document.getElementById('postSede').value = nitSede;
            document.getElementById('formAprobar').submit();
        } else { 
            element.checked = false; 
        }
    }
</script>
</body>
</html>