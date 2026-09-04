<?php
/* ============================================================
    CONFIGURACIÓN DE SESIÓN Y CONEXIONES
============================================================ */
date_default_timezone_set('America/Bogota'); 
ini_set('session.gc_maxlifetime', 3600);
session_set_cookie_params(3600);

session_start();
require 'auth_check.php';
session_regenerate_id(true);

require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

// Definición de NITs para las sedes
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

$inactive_timeout = 1800;

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout)) {
    header("Location: logout.php?msg=Sesion expirada");
    exit;
}
$_SESSION['ultimo_acceso'] = time();

// Validación de usuario basada en CedulaNit
$UsuarioSesion = $_SESSION['CedulaNit'] ?? '';
if ($UsuarioSesion === '') { 
    header("Location: logout.php?msg=Sesion expirada");
    exit; 
}

/* ============================================================
    FUNCIÓN DE PERMISOS
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 
$permiso7777 = Autorizacion($UsuarioSesion, '7777'); 
$permiso0003 = Autorizacion($UsuarioSesion, '0003'); 

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha       = str_replace('-', '', $fecha_input); 
$UsuarioFact = trim($_GET['nit'] ?? '');

// Si no tiene permisos de supervisor/administrador, se fuerza su propia cédula
if($permiso9999 !== 'SI' && $permiso0003 !== 'SI') {
    $UsuarioFact = $UsuarioSesion;
}

$fecha_esc       = $fecha; // Se escapará por conexión individual

/* ============================================================
    CONFIGURACIÓN DE SEDES A PROCESAR SIMULTÁNEAMENTE
============================================================ */
$sedesArray = [
    'central' => [
        'nombre'      => 'CENTRAL',
        'mysqli'      => $mysqliCentral,
        'nit_empresa' => NIT_CENTRAL
    ],
    'drinks' => [
        'nombre'      => 'DRINKS (AWS)',
        'mysqli'      => $mysqliDrinks,
        'nit_empresa' => NIT_DRINKS
    ]
];

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

// Función auxiliar para obtener datos de un cajero específico en una sede dada
function obtenerDatosCajero($nitCajero, $mysqliActiva, $fecha_esc, $fecha_input, $nit_empresa_filtro) {
    $nitCajero_esc = $mysqliActiva->real_escape_string($nitCajero);
    $fecha_esc_s   = $mysqliActiva->real_escape_string($fecha_esc);
    
    // Validación de Cierre
    $cierreRealizado = false;
    $qryCheckCierre = "SELECT T2.NIT FROM ARQUEO AS A1
                        INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = A1.IDUSUARIO
                        INNER JOIN TERCEROS AS T2 ON T2.IDTERCERO = V1.IDTERCERO
                        WHERE DATE_FORMAT(A1.fechacie, '%Y-%m-%d') = '$fecha_input' 
                        AND T2.NIT = '$nitCajero_esc' LIMIT 1";
    $resCheck = $mysqliActiva->query($qryCheckCierre);
    if ($resCheck && $resCheck->num_rows > 0) { $cierreRealizado = true; }

    // Ventas
    $totalVentas = 0; $nombreCompleto = ""; 
    $qryV = "SELECT SUM(T) AS TOTAL, NOM FROM (
        SELECT (DF.CANTIDAD*DF.VALORPROD) AS T, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOM FROM FACTURAS F 
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc_s' AND T1.NIT='$nitCajero_esc' 
        UNION ALL 
        SELECT (DP.CANTIDAD*DP.VALORPROD), CONCAT_WS(' ', V.nombres, V.apellidos) FROM PEDIDOS P 
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc_s' AND V.NIT='$nitCajero_esc'
    ) X GROUP BY NOM";
    $resV = $mysqliActiva->query($qryV);
    if($vRow = $resV->fetch_assoc()){ 
        $totalVentas = (float)$vRow['TOTAL']; 
        $nombreCompleto = $vRow['NOM']; 
    } else {
        global $mysqli;
        $qNom = $mysqli->prepare("SELECT CONCAT_WS(' ', Nombre, NombreCom) AS NOM FROM terceros WHERE CedulaNit = ?");
        $qNom->bind_param("s", $nitCajero);
        $qNom->execute();
        $rNom = $qNom->get_result()->fetch_assoc();
        $nombreCompleto = $rNom['NOM'] ?? 'Cajero ID: '.$nitCajero;
    }

    // Egresos
    $totalEgresos = 0; $listaEgresos = []; $yaExisteTransferEnEgresos = false;
    $resE = $mysqliActiva->query("SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
        WHERE S1.FECHA='$fecha_esc_s' AND T1.NIT='$nitCajero_esc'");
    if($resE){ 
        while($eg=$resE->fetch_assoc()){ 
            $totalEgresos += (float)$eg['VALOR']; 
            $listaEgresos[] = $eg; 
            if (stripos($eg['MOTIVO'], 'TRANSFERENCIA') !== false || stripos($eg['MOTIVO'], 'TRANSFER') !== false) {
                $yaExisteTransferEnEgresos = true;
            }
        } 
    }

    // Normalización de NIT para búsquedas flexibles
    $nitLimpio = preg_replace('/[^0-9]/', '', $nitCajero);

    // Transferencias Manuales (Búsqueda flexible)
    global $mysqli;
    $stmtT = $mysqli->prepare("SELECT SUM(Monto) AS total FROM Relaciontransferencias 
                               WHERE Fecha = ? AND (CedulaNit = ? OR REPLACE(REPLACE(CedulaNit, '-', ''), ' ', '') LIKE CONCAT('%', ?, '%')) AND NitEmpresa = ?");
    $stmtT->bind_param("ssss", $fecha_input, $nitCajero, $nitLimpio, $nit_empresa_filtro);
    $stmtT->execute();
    $totalTransfer = (float)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);

    // Transferencias Automáticas (Búsqueda flexible)
    $stmtTA = $mysqli->prepare("SELECT SUM(n.monto) AS total_auto 
                                FROM control_checks_nequi c
                                INNER JOIN notificaciones_nequi n ON c.id_transferencia = n.id
                                WHERE DATE(c.fecha_hora_check) = ? 
                                AND (c.usuario_cedula = ? OR REPLACE(REPLACE(c.usuario_cedula, '-', ''), ' ', '') LIKE CONCAT('%', ?, '%'))
                                AND c.nit_empresa = ?");
    $stmtTA->bind_param("ssss", $fecha_input, $nitCajero, $nitLimpio, $nit_empresa_filtro);
    $stmtTA->execute();
    $totalTransferAuto = (float)($stmtTA->get_result()->fetch_assoc()['total_auto'] ?? 0);

    $totalTransferGeneral = $totalTransfer + $totalTransferAuto;

    if ($yaExisteTransferEnEgresos) {
        $efectivo_neto_final = $totalEgresos - $totalVentas;
    } else {
        $efectivo_neto_final = ($totalEgresos + $totalTransfer + $totalTransferAuto) - $totalVentas;
    }

    return [
        'nit'                  => $nitCajero,
        'nombre'               => $nombreCompleto,
        'cierreRealizado'      => $cierreRealizado,
        'totalVentas'          => $totalVentas,
        'totalEgresos'         => $totalEgresos,
        'listaEgresos'         => $listaEgresos,
        'totalTransfer'        => $totalTransfer,
        'totalTransferAuto'    => $totalTransferAuto,
        'totalTransferGeneral' => $totalTransferGeneral,
        'efectivo_neto_final'  => $efectivo_neto_final
    ];
}

$mes_sel  = (int)($_GET['mm'] ?? date('m'));
$anio_sel = (int)($_GET['aa'] ?? date('Y'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja Masivo - Ambas Sedes</title>
    <style>
        * { box-sizing: border-box; }
        body{font-family:"Segoe UI",sans-serif; margin:15px; background:#eef3f7; color:#333;}
        .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
        .form-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 5px; }
        .form-group select, .form-group input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 15px; }
        .sede-section-title { background: #1f2d3d; color: #fff; padding: 12px 15px; border-radius: 6px; font-size: 18px; margin-top: 25px; margin-bottom: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        
        /* Tarjeta de Resumen por Sede */
        .sede-summary-box { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 12px 20px; margin-bottom: 15px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; }
        .sede-summary-item { font-size: 14px; color: #0d47a1; }
        .sede-summary-item b { font-size: 15px; }

        /* Tarjeta de Gran Total General */
        .grand-total-box { background: #fffde7; border: 2px solid #fbc02d; border-radius: 10px; padding: 20px; margin-top: 30px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .grand-total-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; text-align: center; }
        .grand-total-metric { background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #f9a825; }
        .grand-total-metric span { display: block; font-size: 12px; color: #666; font-weight: bold; margin-bottom: 5px; }
        .grand-total-metric strong { font-size: 16px; color: #272727; }

        .cajero-panel-container { border: 2px solid #cfd8dc; border-radius: 10px; background: #fff; padding: 15px; margin-bottom: 20px; box-shadow: 0 3px 8px rgba(0,0,0,0.08); }
        .cajero-inner-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 1100px) { .cajero-inner-grid { grid-template-columns: 1fr; } }

        .table{width:100%; border-collapse:collapse;}
        .table td, .table th{padding:8px 10px; border-bottom:1px solid #eee; text-align: left; font-size: 13px;}
        .button{padding:10px 20px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold; width: auto; text-align: center;}
        .btn-save{background:#0b63a3; color:#fff; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;}
        .actions-container { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 10px; }
        .text-end{ text-align: right; }
        .input-edit { width: 100%; padding: 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); overflow-y: auto; padding: 10px; }
        .modal-content { background: white; margin: 20px auto; padding: 15px; width: 100%; max-width: 420px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

        @media print {
            body * { visibility: hidden; }
            #modalVoucher, #modalVoucher * { visibility: visible; }
            #modalVoucher { position: absolute; left: 0; top: 0; width: 100%; height: auto; background: transparent !important; padding: 0; }
            .modal-content { box-shadow: none !important; margin: 0 auto !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; font-size: 10px !important; color: #000 !important; font-weight: 900 !important; }
            .modal-content * { color: #000 !important; font-weight: 900 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="panel no-print">
    <form method="GET" class="form-grid">
        <div class="form-group">
            <label>Fecha:</label>
            <input type="date" name="fecha" value="<?= $fecha_input ?>">
        </div>
        <div class="form-group">
            <label>Filtrar por Cajero (Opcional):</label>
            <select name="nit">
                <option value="">-- TODOS LOS CAJEROS DE AMBAS SEDES --</option>
                <?php 
                $nitsVistosSelect = [];
                foreach($sedesArray as $sKey => $sInfo) {
                    $mActivo = $sInfo['mysqli'];
                    if (!$mActivo || $mActivo->connect_error) continue;
                    $fEsc = $mActivo->real_escape_string($fecha);
                    $qF = "SELECT FACTURADOR_NIT, FACTURADOR FROM (
                        SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS FACTURADOR FROM FACTURAS F 
                        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fEsc'
                        UNION 
                        SELECT V.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS FACTURADOR FROM PEDIDOS P 
                        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fEsc'
                    ) X GROUP BY FACTURADOR_NIT ORDER BY FACTURADOR ASC";
                    $resF = $mActivo->query($qF);
                    if($resF) {
                        while($rowF = $resF->fetch_assoc()) {
                            if(!isset($nitsVistosSelect[$rowF['FACTURADOR_NIT']])) {
                                $nitsVistosSelect[$rowF['FACTURADOR_NIT']] = $rowF['FACTURADOR'];
                ?>
                                <option value="<?= $rowF['FACTURADOR_NIT'] ?>" <?= ($rowF['FACTURADOR_NIT'] === $UsuarioFact)?'selected':'' ?>><?= $rowF['FACTURADOR'] ?> (<?= $sInfo['nombre'] ?>)</option>
                <?php 
                            }
                        }
                    }
                } 
                ?>
            </select>
        </div>
        <input type="hidden" name="mm" value="<?= $mes_sel ?>">
        <input type="hidden" name="aa" value="<?= $anio_sel ?>">
        <button class="button" type="submit">Consultar Ambas Sedes</button>
    </form>
</div>

<div class="dashboard-grid">
    <?php 
    // Acumuladores para el Gran Total General
    $granTotalVentas = 0;
    $granTotalEgresos = 0;
    $granTotalTransferMan = 0;
    $granTotalTransferAuto = 0;
    $granTotalTransferGen = 0;
    $granTotalFisico = 0;

    foreach($sedesArray as $sedeKey => $sedeInfo): 
        $nombreSedeDisplay = $sedeInfo['nombre'];
        $mysqliActiva = $sedeInfo['mysqli'];
        $nitEmpresaFiltro = $sedeInfo['nit_empresa'];

        if (!$mysqliActiva || $mysqliActiva->connect_error) {
            echo '<div class="panel"><p style="color:red;">Error de conexión con la sede: ' . $nombreSedeDisplay . '</p></div>';
            continue;
        }

        $fEsc = $mysqliActiva->real_escape_string($fecha);
        $qryFacturadores = "SELECT FACTURADOR_NIT FROM (
            SELECT T1.NIT AS FACTURADOR_NIT FROM FACTURAS F 
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fEsc'
            UNION 
            SELECT V.NIT AS FACTURADOR_NIT FROM PEDIDOS P 
            INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fEsc'
        ) X GROUP BY FACTURADOR_NIT";
        $factList = $mysqliActiva->query($qryFacturadores);

        $cajerosSede = [];
        if ($UsuarioFact !== '') {
            $cajerosSede[] = $UsuarioFact;
        } else {
            if ($factList) {
                while ($f = $factList->fetch_assoc()) {
                    $cajerosSede[] = $f['FACTURADOR_NIT'];
                }
            }
        }

        // Acumuladores específicos de la Sede
        $sedeVentas = 0;
        $sedeEgresos = 0;
        $sedeTransferMan = 0;
        $sedeTransferAuto = 0;
        $sedeTransferGen = 0;
        $sedeFisico = 0;
        
        $datosCajerosSede = [];
        foreach($cajerosSede as $nitCajeroItem) {
            $datos = obtenerDatosCajero($nitCajeroItem, $mysqliActiva, $fecha, $fecha_input, $nitEmpresaFiltro);
            $datosCajerosSede[] = $datos;

            $sedeVentas         += $datos['totalVentas'];
            $sedeEgresos        += $datos['totalEgresos'];
            $sedeTransferMan    += $datos['totalTransfer'];
            $sedeTransferAuto   += $datos['totalTransferAuto'];
            $sedeTransferGen    += $datos['totalTransferGeneral'];
            $sedeFisico         += $datos['efectivo_neto_final'];
        }

        // Sumar al Gran Total
        $granTotalVentas      += $sedeVentas;
        $granTotalEgresos     += $sedeEgresos;
        $granTotalTransferMan += $sedeTransferMan;
        $granTotalTransferAuto+= $sedeTransferAuto;
        $granTotalTransferGen += $sedeTransferGen;
        $granTotalFisico      += $sedeFisico;
    ?>
        <div class="sede-section-title">
            <span>🏢 SEDE: <?= $nombreSedeDisplay ?></span>
            <span style="font-size: 13px; font-weight: normal;">Cajeros activos: <?= count($cajerosSede) ?></span>
        </div>

        <?php if(empty($cajerosSede)): ?>
            <div class="panel">
                <p style="text-align:center; color:#777; margin: 10px;">No se encontraron cajeros con actividad o ventas para esta fecha en la sede <?= $nombreSedeDisplay ?>.</p>
            </div>
        <?php else: ?>
            <!-- TARJETA DE RESUMEN POR SEDE -->
            <div class="sede-summary-box">
                <div class="sede-summary-item">Ventas Brutas: <b>$ <?= money($sedeVentas) ?></b></div>
                <div class="sede-summary-item">Egresos: <b style="color:c, #d32f2f;">$ <?= money($sedeEgresos) ?></b></div>
                <div class="sede-summary-item">Transferencias: <b style="color:#0277bd;">$ <?= money($sedeTransferGen) ?></b></div>
                <div class="sede-summary-item" style="background:#fff; padding:5px 10px; border-radius:4px; border:1px solid #90caf9;">Total Físico Sede: <b style="color:#2e7d32;">$ <?= money($sedeFisico) ?></b></div>
            </div>

            <?php foreach($datosCajerosSede as $datos): 
                $ocultarValores = ($permiso0003 !== 'SI' && $permiso9999 !== 'SI' && !$datos['cierreRealizado']);
            ?>
                <div class="cajero-panel-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #37474f; padding-bottom: 8px; margin-bottom: 12px;">
                        <h2 style="margin:0; font-size:18px; color:#263238;">👤 <?= htmlspecialchars($datos['nombre']) ?> <span style="font-size:12px; color:#666;">(NIT/Cédula: <?= $datos['nit'] ?>)</span></h2>
                        <div>
                            <?php if($datos['cierreRealizado']): ?>
                                <span style="background:#d32f2f; color:#fff; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;">🔒 CERRADO</span>
                            <?php else: ?>
                                <span style="background:#2e7d32; color:#fff; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;">🔓 ABIERTO</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cajero-inner-grid">
                        <!-- Columna Izquierda Totales -->
                        <div>
                            <div class="table-responsive">
                                <table class="table">
                                    <tr><td>(+) Ventas Brutas:</td><td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($datos['totalVentas']) ?></b></td></tr>
                                    <tr><td>(-) Egresos:</td><td class="text-end" style="color:red;">$ <?= money($datos['totalEgresos']) ?></td></tr>
                                    <tr><td>(-) Transferencias Manuales:</td><td class="text-end" style="color:blue;">$ <?= money($datos['totalTransfer']) ?></td></tr>
                                    <tr><td>(-) Transferencias Automáticas:</td><td class="text-end" style="color:purple;">$ <?= money($datos['totalTransferAuto']) ?></td></tr>
                                    <tr style="background:#f8f9fa; border-top:1px dashed #ccc;">
                                        <td><b>ℹ️ Total Transferencias:</b></td>
                                        <td class="text-end" style="color:#0056b3;"><b>$ <?= money($datos['totalTransferGeneral']) ?></b></td>
                                    </tr>
                                    <tr style="font-size:1.2em; border-top:2px solid #333; background:#fff3cd;">
                                        <td><b>TOTAL FÍSICO:</b></td>
                                        <td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($datos['efectivo_neto_final']) ?></b></td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div class="actions-container no-print">
                                <button class="button" style="background:#f39c12; padding:6px 12px; font-size:13px;" onclick="mostrarVoucher('precierre', '<?= $datos['nit'] ?>', '<?= htmlspecialchars($datos['nombre'], ENT_QUOTES) ?>', '<?= $datos['totalVentas'] ?>', '<?= $datos['totalEgresos'] ?>', '<?= $datos['totalTransfer'] ?>', '<?= $datos['totalTransferAuto'] ?>', '<?= $datos['totalTransferGeneral'] ?>', '<?= $datos['efectivo_neto_final'] ?>', '<?= $datos['cierreRealizado'] ? '1' : '0' ?>', '<?= $nombreSedeDisplay ?>')">📋 Precierre</button>
                                <?php if($datos['cierreRealizado']): ?>
                                    <button class="button" style="background:#2ecc71; padding:6px 12px; font-size:13px;" onclick="mostrarVoucher('cierre', '<?= $datos['nit'] ?>', '<?= htmlspecialchars($datos['nombre'], ENT_QUOTES) ?>', '<?= $datos['totalVentas'] ?>', '<?= $datos['totalEgresos'] ?>', '<?= $datos['totalTransfer'] ?>', '<?= $datos['totalTransferAuto'] ?>', '<?= $datos['totalTransferGeneral'] ?>', '<?= $datos['efectivo_neto_final'] ?>', '1', '<?= $nombreSedeDisplay ?>')">🖨️ Imprimir Cierre</button>
                                <?php else: ?>
                                    <button class="button" style="background:#d32f2f; padding:6px 12px; font-size:13px;" onclick="mostrarVoucher('cierre', '<?= $datos['nit'] ?>', '<?= htmlspecialchars($datos['nombre'], ENT_QUOTES) ?>', '<?= $datos['totalVentas'] ?>', '<?= $datos['totalEgresos'] ?>', '<?= $datos['totalTransfer'] ?>', '<?= $datos['totalTransferAuto'] ?>', '<?= $datos['totalTransferGeneral'] ?>', '<?= $datos['efectivo_neto_final'] ?>', '0', '<?= $nombreSedeDisplay ?>')">🔒 Cierre Definitivo</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Columna Derecha Egresos Individuales -->
                        <div>
                            <h4 style="margin:0 0 5px 0; font-size:14px;">💸 Egresos de este Cajero</h4>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead><tr style="background:#f1f1f1;"><th>ID</th><th>Motivo</th><th class="text-end">Valor</th><th>Acción</th></tr></thead>
                                    <tbody>
                                        <?php if(empty($datos['listaEgresos'])): ?>
                                            <tr><td colspan="4" style="text-align:center; color:#777;">Sin egresos registrados.</td></tr>
                                        <?php else: foreach($datos['listaEgresos'] as $eg): $idE = $eg['IDSALIDA']; ?>
                                        <tr>
                                            <td><?= $idE ?></td>
                                            <td><?= ($permiso9999 === 'SI') ? "<input type='text' id='motivo_$idE' class='input-edit' value='".htmlspecialchars($eg['MOTIVO'])."'>" : $eg['MOTIVO'] ?></td>
                                            <td class="text-end"><?= ($permiso9999 === 'SI') ? "<input type='number' id='valor_$idE' class='input-edit text-end' value='{$eg['VALOR']}'>" : "$".money($eg['VALOR']) ?></td>
                                            <td style="text-align:center;"><?= ($permiso9999 === 'SI') ? "<button class='btn-save' onclick='guardarEgreso($idE, \"$sedeKey\")'>💾</button>" : "-" ?></td>
                                        </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- TARJETA DE GRAN TOTAL GENERAL CONSOLIDADO -->
    <div class="grand-total-box">
        <h3 style="margin:0 0 15px 0; text-align:center; color:#f57f17; font-size:20px;">🌟 GRAN TOTAL GENERAL (AMBAS SEDES CONSOLIDADAS)</h3>
        <div class="grand-total-grid">
            <div class="grand-total-metric">
                <span>Ventas Brutas Totales</span>
                <strong>$ <?= money($granTotalVentas) ?></strong>
            </div>
            <div class="grand-total-metric">
                <span>Egresos Totales</span>
                <strong style="color:#d32f2f;">$ <?= money($granTotalEgresos) ?></strong>
            </div>
            <div class="grand-total-metric">
                <span>Transferencias Manuales</span>
                <strong style="color:#1565c0;">$ <?= money($granTotalTransferMan) ?></strong>
            </div>
            <div class="grand-total-metric">
                <span>Transferencias Automáticas</span>
                <strong style="color:#7b1fa2;">$ <?= money($granTotalTransferAuto) ?></strong>
            </div>
            <div class="grand-total-metric" style="background:#e3f2fd;">
                <span>Total General Transferencias</span>
                <strong style="color:#0d47a1;">$ <?= money($granTotalTransferGen) ?></strong>
            </div>
            <div class="grand-total-metric" style="background:#e8f5e9; border-color:#66bb6a;">
                <span>GRAN TOTAL FÍSICO NETO</span>
                <strong style="color:#2e7d32; font-size:18px;">$ <?= money($granTotalFisico) ?></strong>
            </div>
        </div>
    </div>
</div>

<div id="modalVoucher" class="modal">
    <div class="modal-content" id="printArea"></div>
</div>

<script>
    function moneyJs(num) {
        return Number(num).toLocaleString('es-CO');
    }

    function mostrarVoucher(tipo, nitCajero, nombreCajero, vVentas, vEgresos, vTransM, vTransA, vTransG, vNeto, cierreHechoStr, nombreSede) {
        const p9999 = '<?= $permiso9999 ?>';
        const p7777 = '<?= $permiso7777 ?>';
        const p0003 = '<?= $permiso0003 ?>';
        const cierreYaHecho = (cierreHechoStr === '1');

        if(tipo === 'cierre' && !cierreYaHecho && p7777 !== 'SI' && p9999 !== 'SI' && p0003 !== 'SI') {
            alert('ACCESO DENEGADO: Requiere permiso de supervisor para realizar el cierre.'); 
            return;
        }

        const titulo = (tipo === 'precierre') ? 'PRECIERRE' : 'CIERRE FINAL';
        const horaImpresion = '<?= date("h:i a") ?>';
        const estadoSesion = cierreYaHecho ? "SESIÓN CERRADA" : "SESIÓN ABIERTA";
        
        const displayVentas = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$' + moneyJs(vVentas) : '***';
        const displayTotal = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$' + moneyJs(vNeto) : '***';

        let html = `
            <div class="ticket-header" style="text-align:center;">
                <h2 style="margin:0;"><b>${titulo}</b></h2>
                <p style="margin:0;"><b>SEDE: ${nombreSede}</b></p>
                <p style="margin:0;">FECHA: <?= $fecha_input ?> | ${horaImpresion}</p>
                <p style="margin:0;">CAJERO: ${nombreCajero.substring(0, 25)}</p>
                <p style="margin:0;"><b>ESTADO: ${estadoSesion}</b></p>
                <hr style="border: 1px solid #000;">
            </div>
            <table class="ticket-table" style="width:100%;">
                <tr><td>VENTAS BRUTAS:</td><td style="text-align:right;"><b>${displayVentas}</b></td></tr>
                <tr><td>(-) EGRESOS:</td><td style="text-align:right;"><b>$${moneyJs(vEgresos)}</b></td></tr>
                <tr><td>(-) TRANSFER. MANUAL:</td><td style="text-align:right;"><b>$${moneyJs(vTransM)}</b></td></tr>
                <tr><td>(-) TRANS. AUTO:</td><td style="text-align:right;"><b>$${moneyJs(vTransA)}</b></td></tr>
                <tr><td><b>TOT. TRANSFER.:</b></td><td style="text-align:right;"><b>$${moneyJs(vTransG)}</b></td></tr>
                <tr><td colspan="2"><hr style="border: 1px solid #000;"></td></tr>
                <tr style="font-size:15px;">
                    <td><b>TOTAL FÍSICO:</b></td>
                    <td style="text-align:right;"><b>${displayTotal}</b></td>
                </tr>
            </table>
            <div style="margin-top:40px; display:flex; justify-content:space-between; font-size:11px;">
                <div style="border-top:2px solid #000; width:45%; text-align:center; padding-top:4px;"><b>FIRMA CAJERO</b></div>
                <div style="border-top:2px solid #000; width:45%; text-align:center; padding-top:4px;"><b>SUPERVISOR</b></div>
            </div>
            <div class="no-print" style="margin-top:20px;">
                <button class="button" style="background:#2ecc71; width:100%; font-size:18px;" onclick="window.print()">🖨 IMPRIMIR</button>
                <button class="button" style="background:#7f8c8d; width:100%; margin-top:10px;" onclick="document.getElementById('modalVoucher').style.display='none'">Cerrar</button>
            </div>
        `;
        document.getElementById('printArea').innerHTML = html;
        document.getElementById('modalVoucher').style.display = 'block';
    }

    function guardarEgreso(id, sedeKey){
        const mot = document.getElementById('motivo_'+id).value;
        const val = document.getElementById('valor_'+id).value;
        if(!confirm('¿Desea actualizar este egreso?')) return;
        fetch('update_egreso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}&motivo=${encodeURIComponent(mot)}&valor=${encodeURIComponent(val)}&sede=${sedeKey}`
        }).then(r => r.text()).then(t => { alert(t); location.reload(); });
    }
</script>
</body>
</html>