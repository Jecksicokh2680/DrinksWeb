<?php
/* ============================================================
    CONFIGURACIÓN DE SESIÓN Y CONEXIONES
============================================================ */
$session_timeout  = 3600;
$inactive_timeout = 2400;
date_default_timezone_set('America/Bogota'); 
ini_set('session.gc_maxlifetime', $session_timeout);
session_set_cookie_params($session_timeout);

session_start();
require 'auth_check.php';
session_regenerate_id(true);

require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

// Definición de NITs para las sedes
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

$sede_actual = $_GET['sede'] ?? 'central';

if ($sede_actual === 'drinks') {
    if ($mysqliDrinks->connect_error) die("Error Sede Drinks: " . $mysqliDrinks->connect_error);
    $mysqliActiva = $mysqliDrinks;
    $nombre_sede_display = "DRINKS (AWS)";
    $nit_empresa_filtro = NIT_DRINKS; 
} else {
    $mysqliActiva = $mysqliCentral;
    $nombre_sede_display = "CENTRAL";
    $nit_empresa_filtro = NIT_CENTRAL;  
}

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

if($permiso9999 !== 'SI' && $permiso0003 !== 'SI') {
    $UsuarioFact = $UsuarioSesion;
}

$fecha_esc       = $mysqliActiva->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliActiva->real_escape_string($UsuarioFact);

/* ============================================================
    VALIDACIÓN DE CIERRE (ARQUEO)
============================================================ */
$cierreRealizado = false;
$qryCheckCierre = "SELECT T2.NIT FROM ARQUEO AS A1
                    INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = A1.IDUSUARIO
                    INNER JOIN TERCEROS AS T2 ON T2.IDTERCERO = V1.IDTERCERO
                    WHERE DATE_FORMAT(A1.fechacie, '%Y-%m-%d') = '$fecha_input' 
                    AND T2.NIT = '$UsuarioFact_esc' LIMIT 1";
$resCheck = $mysqliActiva->query($qryCheckCierre);
if ($resCheck && $resCheck->num_rows > 0) { $cierreRealizado = true; }

/* ============================================================
    QUERIES DE DATOS
============================================================ */
$qryFacturadores = "SELECT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS FACTURADOR FROM FACTURAS F 
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
    UNION 
    SELECT V.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS FACTURADOR FROM PEDIDOS P 
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
) X GROUP BY FACTURADOR_NIT ORDER BY FACTURADOR ASC";
$factList = $mysqliActiva->query($qryFacturadores);

$totalVentas = 0; $nombreCompleto = ""; $totalEgresos = 0; $totalTransfer = 0; $totalTransferAuto = 0; $totalTransferGeneral = 0;
$listaEgresos = [];
$yaExisteTransferEnEgresos = false;

if($UsuarioFact !== ''){
    // VENTAS
    $qryV = "SELECT SUM(T) AS TOTAL, NOM FROM (
        SELECT (DF.CANTIDAD*DF.VALORPROD) AS T, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOM FROM FACTURAS F 
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc' 
        UNION ALL 
        SELECT (DP.CANTIDAD*DP.VALORPROD), CONCAT_WS(' ', V.nombres, V.apellidos) FROM PEDIDOS P 
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$UsuarioFact_esc'
    ) X GROUP BY NOM";
    $resV = $mysqliActiva->query($qryV);
    if($vRow = $resV->fetch_assoc()){ $totalVentas = (float)$vRow['TOTAL']; $nombreCompleto = $vRow['NOM']; }

    // EGRESOS
    $resE = $mysqliActiva->query("SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
        WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'");
    if($resE){ 
        while($eg=$resE->fetch_assoc()){ 
            $totalEgresos += (float)$eg['VALOR']; 
            $listaEgresos[] = $eg; 
            if (stripos($eg['MOTIVO'], 'TRANSFERENCIA') !== false || stripos($eg['MOTIVO'], 'TRANSFER') !== false) {
                $yaExisteTransferEnEgresos = true;
            }
        } 
    }

    // TRANSFERENCIAS MANUALES
    $stmtT = $mysqli->prepare("SELECT SUM(Monto) AS total FROM Relaciontransferencias 
                               WHERE Fecha = ? AND CedulaNit = ? AND NitEmpresa = ?");
    $stmtT->bind_param("sss", $fecha_input, $UsuarioFact, $nit_empresa_filtro);
    $stmtT->execute();
    $resT = $stmtT->get_result();
    $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);

    // TRANSFERENCIAS AUTOMÁTICAS
    $stmtTA = $mysqli->prepare("SELECT SUM(n.monto) AS total_auto 
                                FROM control_checks_nequi c
                                INNER JOIN notificaciones_nequi n ON c.id_transferencia = n.id
                                WHERE DATE(c.fecha_hora_check) = ? 
                                AND c.usuario_cedula = ? 
                                AND c.nit_empresa = ?");
    $stmtTA->bind_param("sss", $fecha_input, $UsuarioFact, $nit_empresa_filtro);
    $stmtTA->execute();
    $resTA = $stmtTA->get_result();
    $totalTransferAuto = (float)($resTA->fetch_assoc()['total_auto'] ?? 0);

    $totalTransferGeneral = $totalTransfer + $totalTransferAuto;
}

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

if ($yaExisteTransferEnEgresos) {
    $efectivo_neto_final = $totalEgresos - $totalVentas;
} else {
    $efectivo_neto_final = ($totalEgresos + $totalTransfer + $totalTransferAuto) - $totalVentas;
}

$ocultarValores = ($permiso0003 !== 'SI' && $permiso9999 !== 'SI' && !$cierreRealizado);

/* ============================================================
    MÓDULO DE OBJETIVOS
============================================================ */
$mes_sel  = (int)($_GET['mm'] ?? date('m'));
$anio_sel = (int)($_GET['aa'] ?? date('Y'));

$f_ini = str_replace('-', '', $fecha_input);
$f_fin = str_replace('-', '', $fecha_input);

// Obtener nombres de productos desde ConnCentral (barcode -> nombre)
$nombresProductos = [];
if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
    $qProd = $mysqliCentral->query("SELECT Barcode, descripcion FROM productos");
    if ($qProd) {
        while ($p = $qProd->fetch_assoc()) {
            $nombresProductos[trim($p['Barcode'])] = $p['descripcion'];
        }
    }
}

function obtenerVentasEjecutadas($cnx, $f_ini, $f_fin) {
    if (!$cnx || $cnx->connect_error) return [];
    $sql = "
        SELECT T1.NOMBRES AS FACTURADOR, PRODUCTOS.Barcode AS SKU, DETFACTURAS.CANTIDAD, (DETFACTURAS.CANTIDAD * DETFACTURAS.VALORPROD) AS TOTAL_VENTA
        FROM FACTURAS
        INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA = FACTURAS.IDFACTURA
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETFACTURAS.IDPRODUCTO
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = FACTURAS.IDVENDEDOR
        WHERE FACTURAS.ESTADO = '0' AND FACTURAS.FECHA BETWEEN ? AND ?
        UNION ALL
        SELECT T2.NOMBRES AS FACTURADOR, PRODUCTOS.Barcode AS SKU, DETPEDIDOS.CANTIDAD, (DETPEDIDOS.CANTIDAD * DETPEDIDOS.VALORPROD) AS TOTAL_VENTA
        FROM PEDIDOS
        INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO = DETPEDIDOS.IDPEDIDO
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETPEDIDOS.IDPRODUCTO
        INNER JOIN USUVENDEDOR V ON V.IDUSUARIO = PEDIDOS.IDUSUARIO
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V.IDTERCERO
        WHERE PEDIDOS.ESTADO = '0' AND PEDIDOS.FECHA BETWEEN ? AND ?
    ";
    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$rawVentas = [];
if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
    $rawVentas = array_merge($rawVentas, obtenerVentasEjecutadas($mysqliCentral, $f_ini, $f_fin));
}
if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
    $rawVentas = array_merge($rawVentas, obtenerVentasEjecutadas($mysqliDrinks, $f_ini, $f_fin));
}

$ventasPorFacturador = [];
foreach ($rawVentas as $v) {
    $facturador = trim($v['FACTURADOR']);
    $sku        = trim($v['SKU']);
    $cantidad   = (float)$v['CANTIDAD'];
    $valor      = (float)$v['TOTAL_VENTA'];

    if (!isset($ventasPorFacturador[$facturador])) {
        $ventasPorFacturador[$facturador] = ['total_valor' => 0.0, 'skus' => []];
    }
    $ventasPorFacturador[$facturador]['total_valor'] += $valor;
    if (!isset($ventasPorFacturador[$facturador]['skus'][$sku])) {
        $ventasPorFacturador[$facturador]['skus'][$sku] = 0.0;
    }
    $ventasPorFacturador[$facturador]['skus'][$sku] += $cantidad;
}

$sqlObjetivos = "
    SELECT t.CedulaNit, t.Nombre, t.NombreCom, cab.id_cabecera, cab.NitEmpresa, cab.mm, cab.aa, cab.meta_valor_total, det.Sku, det.meta_cajas
    FROM terceros t
    INNER JOIN objetivos_cajeros_cab cab ON t.CedulaNit = cab.CedulaNit
    LEFT JOIN objetivos_cajeros_det det ON cab.id_cabecera = det.id_cabecera
    WHERE cab.mm = ? AND cab.aa = ? AND cab.estado = '1'
";

if (!empty($UsuarioFact)) {
    $sqlObjetivos .= " AND t.CedulaNit = ?";
}
$sqlObjetivos .= " ORDER BY t.Nombre ASC, det.Sku ASC";

$stmtObj = $mysqli->prepare($sqlObjetivos);
if (!empty($UsuarioFact)) {
    $stmtObj->bind_param("iis", $mes_sel, $anio_sel, $UsuarioFact);
} else {
    $stmtObj->bind_param("ii", $mes_sel, $anio_sel);
}
$stmtObj->execute();
$resObj = $stmtObj->get_result();

$reporte = [];
while ($row = $resObj->fetch_assoc()) {
    $cedula = $row['CedulaNit'];
    $nombre = $row['NombreCom'] ?: $row['Nombre'] ?: 'Sin Nombre';

    if (!isset($reporte[$cedula])) {
        $ejecutadoValor = $ventasPorFacturador[$nombre]['total_valor'] ?? 0.0;
        $reporte[$cedula] = [
            'nombre' => $nombre,
            'cedula' => $cedula,
            'meta_valor_total' => (float)$row['meta_valor_total'],
            'ejecutado_valor' => $ejecutadoValor,
            'detalles' => []
        ];
    }

    if (!empty($row['Sku'])) {
        $sku = trim($row['Sku']);
        $metaCajas = (float)$row['meta_cajas'];
        $cantVendida = $ventasPorFacturador[$nombre]['skus'][$sku] ?? 0.0;
        $nombreProducto = $nombresProductos[$sku] ?? 'Producto No Encontrado';

        $reporte[$cedula]['detalles'][] = [
            'sku' => $sku,
            'nombre_producto' => $nombreProducto,
            'meta_cajas' => $metaCajas,
            'cajas_vendidas' => $cantVendida,
            'unds_vendidas' => $cantVendida
        ];
    }
}

// Calcular el porcentaje de ejecución general en valor para el cajero actual
$pctValorCajeroGlobal = 0.0;
if (!empty($reporte)) {
    foreach ($reporte as $repItem) {
        $metaV = $repItem['meta_valor_total'];
        $ejecV = $repItem['ejecutado_valor'];
        if ($metaV > 0) {
            $pctValorCajeroGlobal = round(($ejecV / $metaV) * 100, 1);
        }
        break; 
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja y Objetivos</title>
    <style>
        * { box-sizing: border-box; }
        body{font-family:"Segoe UI",sans-serif; margin:15px; background:#eef3f7; color:#333;}
        .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
        .form-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 5px; }
        .form-group select, .form-group input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        @media (max-width: 1100px) { .dashboard-grid { grid-template-columns: 1fr; } }

        .column-left { display: flex; flex-direction: column; gap: 15px; }
        .column-right { display: flex; flex-direction: column; }

        .row-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .status-container { display: flex; align-items: center; justify-content: center; height: 100%; min-height: 150px; }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table{width:100%; border-collapse:collapse;}
        .table td, .table th{padding:10px; border-bottom:1px solid #eee; text-align: left;}
        .button{padding:10px 20px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold; width: auto; text-align: center;}
        .btn-save{background:#0b63a3; color:#fff; border:none; padding:8px 12px; border-radius:4px; cursor:pointer;}
        .actions-container { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .text-end{ text-align: right; }
        .input-edit { width: 100%; padding: 5px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        
        .grid-paneles { display: grid; grid-template-columns: 1fr; gap: 15px; }
        .tercero-card { border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; background: #fff; margin-bottom: 15px; }
        .tercero-header { background: #263238; color: white; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; }
        .tercero-header h3 { margin: 0; font-size: 16px; }
        .resumen-meta { display: flex; gap: 15px; background: #f8f9fa; padding: 12px 15px; border-bottom: 1px solid #e0e0e0; }
        .metric-box { flex: 1; text-align: center; }
        .metric-box .title { font-size: 11px; text-transform: uppercase; color: #666; font-weight: bold; }
        .metric-box .value { font-size: 15px; font-weight: bold; margin-top: 3px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; display: inline-block; }
        .bg-success { background-color: #2e7d32; }
        .bg-warning { background-color: #f57c00; }
        .bg-danger { background-color: #c62828; }
        .progress-bar-bg { background: #e0e0e0; border-radius: 10px; height: 8px; width: 100%; overflow: hidden; margin-top: 4px; }
        .progress-bar-fill { height: 100%; border-radius: 10px; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); overflow-y: auto; padding: 10px; }
        .modal-content { background: white; margin: 20px auto; padding: 15px; width: 100%; max-width: 420px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

        /* REGLAS DE IMPRESIÓN PARA NEGRITA FUERTE, MODO OSCURO/TINTA Y TAMAÑO REDUCIDO */
        @media print {
            body * {
                visibility: hidden;
            }
            #modalVoucher, #modalVoucher * {
                visibility: visible;
            }
            #modalVoucher {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: auto;
                background: transparent !important;
                padding: 0;
            }
            .modal-content {
                box-shadow: none !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                font-size: 10px !important;
                color: #000 !important; /* Texto negro puro */
                font-weight: 900 !important; /* Máxima negrita global para oscurecer la impresión térmica */
            }
            .modal-content * {
                color: #000 !important;
                font-weight: 900 !important; /* Forzar negrita fuerte en todos los elementos internos */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .modal-content h2 {
                font-size: 13px !important;
            }
            .modal-content table {
                font-size: 9px !important;
            }
            .no-print {
                display: none !important;
            }
        }

        @media (max-width: 576px) {
            body { margin: 10px; }
            .panel { padding: 12px; }
            .form-group { min-width: 100%; }
            .button { width: 100%; }
        }
    </style>
</head>
<body>

<div class="panel no-print">
    <form method="GET" class="form-grid">
        <div class="form-group">
            <label>Sede:</label>
            <select name="sede" onchange="this.form.submit()">
                <option value="central" <?= ($sede_actual==='central'?'selected':'') ?>>CENTRAL</option>
                <option value="drinks" <?= ($sede_actual==='drinks'?'selected':'') ?>>DRINKS (AWS)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Fecha:</label>
            <input type="date" name="fecha" value="<?= $fecha_input ?>">
        </div>
        <div class="form-group">
            <label>Facturador:</label>
            <select name="nit">
                <?php if($permiso9999 !== 'SI' && $permiso0003 !== 'SI'): ?>
                    <option value="<?= $UsuarioSesion ?>"><?= $UsuarioSesion ?> (Yo)</option>
                <?php else: ?>
                    <option value="">-- Seleccione Usuario --</option>
                    <?php if($factList): while($f=$factList->fetch_assoc()): ?>
                        <option value="<?= $f['FACTURADOR_NIT'] ?>" <?= ($f['FACTURADOR_NIT']===$UsuarioFact)?'selected':'' ?>><?= $f['FACTURADOR'] ?></option>
                    <?php endwhile; endif; ?>
                <?php endif; ?>
            </select>
        </div>
        <input type="hidden" name="mm" value="<?= $mes_sel ?>">
        <input type="hidden" name="aa" value="<?= $anio_sel ?>">
        <button class="button" type="submit">Consultar</button>
    </form>
</div>

<?php if($UsuarioFact !== ''): ?>
    <div class="dashboard-grid no-print">
        
        <div class="column-left">
            <div class="row-grid" style="margin-bottom: 0;">
                <div class="panel" style="margin-bottom: 0;">
                    <h3>📊 Resumen: <?= htmlspecialchars($nombreCompleto) ?></h3>
                    <div class="table-responsive">
                        <table class="table">
                            <tr><td>(+) Ventas Brutas:</td><td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($totalVentas) ?></b></td></tr>
                            <tr><td>(-) Egresos:</td><td class="text-end" style="color:red;">$ <?= money($totalEgresos) ?></td></tr>
                            <tr><td>(-) Transferencias Manuales:</td><td class="text-end" style="color:blue;">$ <?= money($totalTransfer) ?></td></tr>
                            <tr><td>(-) Transferencias Automáticas:</td><td class="text-end" style="color:purple;">$ <?= money($totalTransferAuto) ?></td></tr>
                            <tr style="background:#f8f9fa; border-top:1px dashed #ccc;">
                                <td><b>ℹ️ Total Transferencias (Man. + Auto.):</b></td>
                                <td class="text-end" style="color:#0056b3;"><b>$ <?= money($totalTransferGeneral) ?></b></td>
                            </tr>
                            <tr style="font-size:1.4em; border-top:2px solid #333; background:#fff3cd;">
                                <td><b>TOTAL FÍSICO:</b></td>
                                <td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($efectivo_neto_final) ?></b></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="panel status-container" style="margin-bottom: 0;">
                    <?php if($cierreRealizado): ?>
                        <div style="width: 100%; border: 2px solid #d32f2f; border-radius: 8px; padding: 20px; text-align: center;">
                            <div style="font-size: 3em;">🔒</div>
                            <h3 style="color: #d32f2f; margin: 0;">SESIÓN CERRADA</h3>
                        </div>
                    <?php else: ?>
                        <div style="width: 100%; border: 2px solid #2e7d32; border-radius: 8px; padding: 20px; text-align: center;">
                            <div style="font-size: 3em;">🔓</div>
                            <h3 style="color: #2e7d32; margin: 0;">SESIÓN ABIERTA</h3>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel" style="margin-bottom: 0;">
                <h3>💸 Egresos de Caja</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr style="background:#f1f1f1;"><th>ID</th><th>Motivo</th><th class="text-end">Valor</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php foreach($listaEgresos as $eg): $idE = $eg['IDSALIDA']; ?>
                            <tr>
                                <td><?= $idE ?></td>
                                <td><?= ($permiso9999 === 'SI') ? "<input type='text' id='motivo_$idE' class='input-edit' value='".htmlspecialchars($eg['MOTIVO'])."'>" : $eg['MOTIVO'] ?></td>
                                <td class="text-end"><?= ($permiso9999 === 'SI') ? "<input type='number' id='valor_$idE' class='input-edit text-end' value='{$eg['VALOR']}'>" : "$".money($eg['VALOR']) ?></td>
                                <td style="text-align:center;"><?= ($permiso9999 === 'SI') ? "<button class='btn-save' onclick='guardarEgreso($idE)'>💾</button>" : "-" ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel actions-container" style="margin-bottom: 0;">
                <button class="button" style="background:#f39c12;" onclick="mostrarVoucher('precierre')">📋 Ver Precierre</button>
                <?php if($cierreRealizado): ?>
                    <button class="button" style="background:#2ecc71;" onclick="mostrarVoucher('cierre')">🖨️ Imprimir Cierre</button>
                <?php else: ?>
                    <button class="button" style="background:#d32f2f;" onclick="mostrarVoucher('cierre')">🔒 Cierre Definitivo</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="column-right">
            <div class="panel" style="height: 100%; margin-bottom: 0;">
                <h3>🎯 Objetivos </h3>
                <?php if (empty($reporte)): ?>
                    <p style="text-align:center; color:#777; margin-top: 40px;">No se registraron objetivos para el período seleccionado o para el cajero seleccionado.</p>
                <?php else: ?>
                    <div class="grid-paneles" style="margin-top: 15px;">
                        <?php foreach ($reporte as $tercero): 
                            $metaVal  = $tercero['meta_valor_total'];
                            $ejecVal  = $tercero['ejecutado_valor'];
                            $pctValor = ($metaVal > 0) ? min(100, round(($ejecVal / $metaVal) * 100, 1)) : 0;
                            $badgeValClass = ($pctValor >= 100) ? 'bg-success' : (($pctValor >= 70) ? 'bg-warning' : 'bg-danger');
                        ?>
                            <div class="tercero-card">
                                <div class="tercero-header">
                                    <h3><?= htmlspecialchars($tercero['nombre']) ?></h3>
                                    <span class="badge <?= $badgeValClass ?>"><?= $pctValor ?>%</span>
                                </div>
                                <div class="resumen-meta">
                                    <div class="metric-box">
                                        <div class="title">Meta Valor</div>
                                        <div class="value">$ <?= money($metaVal) ?></div>
                                    </div>
                                    
                                    <?php if ($permiso9999 === 'SI'): ?>
                                    <div class="metric-box">
                                        <div class="title">Ejecutado (Día)</div>
                                        <div class="value" style="color: #0288d1;">$ <?= money($ejecVal) ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="table-responsive">
                                    <table style="width:100%; font-size: 13px;">
                                        <thead>
                                            <tr style="background:#fafafa;">
                                                <th>SKU / Producto</th>
                                                <th>Meta C.</th>
                                                <th>Vend.</th>
                                                <th>Progreso</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($tercero['detalles'])): ?>
                                                <tr><td colspan="4" style="text-align:center; color:#777;">Sin detalles de SKU asignados.</td></tr>
                                            <?php else: foreach ($tercero['detalles'] as $det): 
                                                $metaC = $det['meta_cajas'];
                                                $cantVendida = $det['cajas_vendidas'];
                                                $pctSku = ($metaC > 0) ? min(100, round(($cantVendida / $metaC) * 100, 1)) : 0;
                                                $fillColor = ($pctSku >= 100) ? '#2e7d32' : (($pctSku >= 70) ? '#f57c00' : '#c62828');
                                            ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($det['sku']) ?></strong><br>
                                                        <span style="font-size:11px; color:#555;"><?= htmlspecialchars($det['nombre_producto']) ?></span>
                                                    </td>
                                                    <td><?= number_format($metaC, 1) ?></td>
                                                    <td><?= $cantVendida ?></td>
                                                    <td>
                                                        <div style="font-size:10px; font-weight:bold;"><?= $pctSku ?>%</div>
                                                        <div class="progress-bar-bg">
                                                            <div class="progress-bar-fill" style="width: <?= $pctSku ?>%; background-color: <?= $fillColor ?>;"></div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div id="modalVoucher" class="modal">
        <div class="modal-content" id="printArea"></div>
    </div>
<?php endif; ?>

<script>
    function mostrarVoucher(tipo) {
        const p9999 = '<?= $permiso9999 ?>';
        const p7777 = '<?= $permiso7777 ?>';
        const p0003 = '<?= $permiso0003 ?>';
        const cierreYaHecho = <?= $cierreRealizado ? 'true' : 'false' ?>;

        if(tipo === 'cierre' && !cierreYaHecho && p7777 !== 'SI' && p9999 !== 'SI' && p0003 !== 'SI') {
            alert('ACCESO DENEGADO: Requiere permiso de supervisor para realizar el cierre.'); 
            return;
        }

        let egresosHtml = "";
        <?php foreach($listaEgresos as $e): ?>
            egresosHtml += `<tr><td style="padding:1px; max-width:240px; overflow:hidden;">- <?= htmlspecialchars($e['MOTIVO']) ?></td><td style="text-align:right;"><b>$<?= money($e['VALOR']) ?></b></td></tr>`;
        <?php endforeach; ?>

        const titulo = (tipo === 'precierre') ? 'PRECIERRE' : 'CIERRE FINAL';
        const horaImpresion = '<?= date("h:i a") ?>';
        const estadoSesion = cierreYaHecho ? "SESIÓN CERRADA" : "SESIÓN ABIERTA";
        
        const vVentas = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$<?= money($totalVentas) ?>' : '***';
        const vTotal = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$<?= money($efectivo_neto_final) ?>' : '***';
        const pctEjecucionGlobal = '<?= $pctValorCajeroGlobal ?>%';

        let html = `
            <div class="ticket-header" style="text-align:center;">
                <h2 style="margin:0;"><b>${titulo}</b></h2>
                <p style="margin:0;"><b>SEDE: <?= strtoupper($nombre_sede_display) ?></b></p>
                <p style="margin:0;">FECHA: <?= $fecha_input ?> | ${horaImpresion}</p>
                <p style="margin:0;">CAJERO: <?= strtoupper(substr($nombreCompleto, 0, 25)) ?></p>
                <p style="margin:0;"><b>ESTADO: ${estadoSesion}</b></p>
                <hr style="border: 1px solid #000;">
            </div>
            <table class="ticket-table" style="width:100%;">
                <tr><td>VENTAS BRUTAS:</td><td style="text-align:right;"><b>${vVentas}</b></td></tr>
                <tr><td>(-) EGRESOS:</td><td style="text-align:right;"><b>$<?= money($totalEgresos) ?></b></td></tr>
                <tr><td>(-) TRANSFER. MANUAL:</td><td style="text-align:right;"><b>$<?= money($totalTransfer) ?></b></td></tr>
                <tr><td>(-) TRANS. AUTO:</td><td style="text-align:right;"><b>$<?= money($totalTransferAuto) ?></b></td></tr>
                <tr><td><b>TOT. TRANSFER.:</b></td><td style="text-align:right;"><b>$<?= money($totalTransferGeneral) ?></b></td></tr>
                <tr><td colspan="2"><hr style="border: 1px solid #000;"></td></tr>
                <tr style="font-size:15px;">
                    <td><b>TOTAL FÍSICO:</b></td>
                    <td style="text-align:right;"><b>${vTotal}</b></td>
                </tr>
                <tr><td colspan="2"><hr style="border: 1px solid #000;"></td></tr>
                <tr style="font-size:14px;">
                    <td><b>% EJECUCIÓN (META):</b></td>
                    <td style="text-align:right;"><b>${pctEjecucionGlobal}</b></td>
                </tr>
            </table>
            <div style="margin-top:10px; font-size:12px; font-weight:900; border-bottom:2px solid #000; text-transform: uppercase;">Detalle de Egresos</div>
            <table class="ticket-table" style="font-size:11px; width:100%; margin-top:4px;">
                ${egresosHtml !== "" ? egresosHtml : '<tr><td colspan="2">Sin egresos registrados</td></tr>'}
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

    function guardarEgreso(id){
        const mot = document.getElementById('motivo_'+id).value;
        const val = document.getElementById('valor_'+id).value;
        if(!confirm('¿Desea actualizar este egreso?')) return;
        fetch('update_egreso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}&motivo=${encodeURIComponent(mot)}&valor=${encodeURIComponent(val)}&sede=<?= $sede_actual ?>`
        }).then(r => r.text()).then(t => { alert(t); location.reload(); });
    }
</script>
</body>
</html>