<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES (FULL AUDITORÍA)
============================================================ */
date_default_timezone_set('America/Bogota'); 
require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$UsuarioSesion = $_SESSION['Usuario'] ?? '';

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    if(empty($User)) return "NO";
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    return ($row = $stmt->get_result()->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 
$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha_esc   = str_replace('-', '', $fecha_input);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

$sedes = [
    ['conn' => $mysqliCentral, 'nombre' => 'CENTRAL', 'id' => 'central', 'nit_empresa' => '86057267-8'],
    ['conn' => $mysqliDrinks,  'nombre' => 'DRINKS (AWS)', 'id' => 'drinks', 'nit_empresa' => '901724534-7']
];

$globalVentas = 0; $globalEgresos = 0; $globalTransf = 0; $globalFisico = 0; $globalEfectivoEntregado = 0; 
$dataConsolidada = []; $egresosAgrupados = []; $resumenSedes = []; 

foreach ($sedes as $s) {
    $mysqliActiva = $s['conn'];
    $nombreSede   = $s['nombre'];
    $idSede       = $s['id'];
    $nitEmpresa   = $s['nit_empresa'];

    $resumenSedes[$idSede] = ['nombre' => $nombreSede, 'ventas' => 0, 'egresos' => 0, 'transf' => 0, 'efectivo' => 0, 'neto' => 0];

    $qryCajeros = "SELECT NIT, NOMBRE FROM (
        SELECT T1.NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOMBRE FROM FACTURAS F 
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
        UNION 
        SELECT V.NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS NOMBRE FROM PEDIDOS P 
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
    ) X GROUP BY NIT ORDER BY NOMBRE ASC";

    $resCajeros = $mysqliActiva->query($qryCajeros);

    if ($resCajeros) {
        while ($c = $resCajeros->fetch_assoc()) {
            $nit = $c['NIT'];
            $nombreCajero = $c['NOMBRE'];

            // VENTAS NETAS
            $qV = "SELECT SUM(T) AS TOTAL FROM (
                SELECT (DF.CANTIDAD*DF.VALORPROD) AS T FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
                LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$nit'
                UNION ALL 
                SELECT (DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
                INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$nit'
            ) AS X";
            $vts = (float)($mysqliActiva->query($qV)->fetch_assoc()['TOTAL'] ?? 0);

            // EGRESOS
            $qE = "SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $resE = $mysqliActiva->query($qE);
            
            $egrTotalCajero = 0; $efectivoCajero = 0; $tieneTransferenciaEnEgresos = false;

            if($resE->num_rows > 0){
                if(!isset($egresosAgrupados[$nit])){ $egresosAgrupados[$nit] = ['nombre' => $nombreCajero, 'sede' => $nombreSede, 'id_sede' => $idSede, 'detalles' => [], 'total' => 0]; }
                while($eg = $resE->fetch_assoc()){
                    $egresosAgrupados[$nit]['detalles'][] = $eg;
                    $egresosAgrupados[$nit]['total'] += (float)$eg['VALOR'];
                    $egrTotalCajero += (float)$eg['VALOR'];
                    
                    if (stripos($eg['MOTIVO'], 'TRANSFERENCIA') !== false) { $tieneTransferenciaEnEgresos = true; }
                    if (preg_match('/(ENTREGA|EFECTIVO|MONEDA|BASE)/i', $eg['MOTIVO'])) { $efectivoCajero += (float)$eg['VALOR']; }
                }
            }

            // TRANSFERENCIAS DEL REPORTE CENTRAL
            $stmtT = $mysqli->prepare("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = ? AND CedulaNit = ? AND NitEmpresa = ?");
            $stmtT->bind_param("sss", $fecha_input, $nit, $nitEmpresa);
            $stmtT->execute();
            $trf_auto = (float)($stmtT->get_result()->fetch_assoc()['total'] ?? 0);
            
            // DIFERENCIA: Ventas - Egresos. Si no hay item de TRF en egresos, se resta la TRF del reporte.
            $diferencia = $vts - $egrTotalCajero; 
            if (!$tieneTransferenciaEnEgresos) { $diferencia -= $trf_auto; }

            $dataConsolidada[] = [
                'sede' => $nombreSede, 'nombre' => $nombreCajero, 'ventas' => $vts, 
                'egr' => $egrTotalCajero, 'trf' => $trf_auto, 'efectivo' => $efectivoCajero,
                'diferencia' => $diferencia, 'status_trf' => $tieneTransferenciaEnEgresos
            ];

            $globalVentas += $vts; $globalEgresos += $egrTotalCajero; $globalTransf += $trf_auto; 
            $globalFisico += $diferencia; $globalEfectivoEntregado += $efectivoCajero;

            $resumenSedes[$idSede]['ventas'] += $vts; 
            $resumenSedes[$idSede]['egresos'] += $egrTotalCajero;
            $resumenSedes[$idSede]['transf'] += $trf_auto; 
            $resumenSedes[$idSede]['efectivo'] += $efectivoCajero;
            $resumenSedes[$idSede]['neto'] += $diferencia;
        }
    }
}

// CÁLCULO PANEL CONSOLIDADO
$sumaVentas = 0; $sumaEgresos = 0; $sumaTransf = 0; $sumaEfectivo = 0; $sumaNeto = 0;
foreach($resumenSedes as $r) {
    $sumaVentas   += $r['ventas'];
    $sumaEgresos  += $r['egresos'];
    $sumaTransf   += $r['transf'];
    $sumaEfectivo += $r['efectivo'];
    $sumaNeto     += $r['neto'];
}
$claseSuma = (round($sumaNeto) < 0) ? "bg-sobra" : ((round($sumaNeto) > 0) ? "bg-falta" : "bg-ok");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría Consolidada</title>
    <style>
        :root { --primary: #2c3e50; --danger: #e74c3c; --success: #27ae60; --info: #3498db; --warning: #f39c12; --bg: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 10px; }
        .grid { display: grid; gap: 15px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); margin-bottom: 25px; }
        .card { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-top: 5px solid var(--primary); }
        .row-item { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .total-box { margin-top: 10px; padding: 10px; border-radius: 6px; display: flex; justify-content: space-between; font-weight: bold; }
        .bg-sobra { background: #d4edda; color: #155724; }
        .bg-falta { background: #f8d7da; color: #721c24; }
        .bg-ok { background: #e3f2fd; color: #0d47a1; }
        .input-edit { width: 100%; border: 1px solid #ddd; border-radius: 4px; padding: 5px; font-size: 12px; margin-bottom: 4px; }
        .footer-summary { background: #1f2d3d; color: white; padding: 20px; border-radius: 12px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; text-align: center; }
        
        /* Estilos Cronómetro */
        #timer-container { background: var(--primary); color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; display: flex; align-items: center; gap: 8px; }
        .dot { height: 8px; width: 8px; background-color: #2ecc71; border-radius: 50%; display: inline-block; animation: pulse 1s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:white; padding:12px; border-radius:10px;">
    <div style="display:flex; align-items:center; gap:15px;">
        <h3 style="margin:0;">📊 Auditoría Consolidada</h3>
        <div id="timer-container">
            <span class="dot"></span> 
            Refresco en: <span id="timer">180</span>s
        </div>
    </div>
    <input type="date" value="<?= $fecha_input ?>" onchange="location.href='?fecha='+this.value">
</div>

<div class="grid">
    <div class="card" style="border-top-color: var(--warning); background: #fffdf9;">
        <h4 style="margin:0 0 10px 0; color: var(--warning);">⭐ TOTAL CONSOLIDADO</h4>
        <div class="row-item"><span>Ventas (Netas):</span> <b>$<?= money($sumaVentas) ?></b></div>
        <div class="row-item"><span>Egresos:</span> <b>$<?= money($sumaEgresos) ?></b></div>
        <div class="row-item"><span>Transferencias:</span> <b>$<?= money($sumaTransf) ?></b></div>
        <div class="row-item"><span>Efectivo Entregado:</span> <b style="color:var(--info);">$<?= money($sumaEfectivo) ?></b></div>
        <div class="total-box <?= $claseSuma ?>">
            <span>DIFERENCIA:</span> <span>$<?= money(abs($sumaNeto)) ?></span>
        </div>
    </div>

    <?php foreach($resumenSedes as $r): 
        $clase = (round($r['neto']) < 0) ? "bg-sobra" : ((round($r['neto']) > 0) ? "bg-falta" : "bg-ok"); ?>
    <div class="card" style="border-top-color: var(--info);">
        <h4 style="margin:0 0 10px 0;"><?= $r['nombre'] ?></h4>
        <div class="row-item"><span>Ventas (Netas):</span> <b>$<?= money($r['ventas']) ?></b></div>
        <div class="row-item"><span>Total Egresos:</span> <b>$<?= money($r['egresos']) ?></b></div>
        <div class="row-item"><span>Transferencias:</span> <b>$<?= money($r['transf']) ?></b></div>
        <div class="row-item"><span>Efectivo Entregado:</span> <b style="color:var(--info);">$<?= money($r['efectivo']) ?></b></div>
        <div class="total-box <?= $clase ?>"><span>DIFERENCIA:</span> <span>$<?= money(abs($r['neto'])) ?></span></div>
    </div>
    <?php endforeach; ?>
</div>

<h4 style="color:var(--primary); border-left:5px solid var(--primary); padding-left:10px;">👤 Detalle por Cajero</h4>
<div class="grid">
    <?php foreach($dataConsolidada as $item): 
        $clase = (round($item['diferencia']) < 0) ? "bg-sobra" : ((round($item['diferencia']) > 0) ? "bg-falta" : "bg-ok");
    ?>
    <div class="card">
        <small><?= $item['sede'] ?></small>
        <h5 style="margin:5px 0;"><?= htmlspecialchars($item['nombre']) ?></h5>
        <div class="row-item"><span>Ventas:</span> <b>$<?= money($item['ventas']) ?></b></div>
        <div class="row-item"><span>Egresos Totales:</span> <b>$<?= money($item['egr']) ?></b></div>
        <div class="row-item"><span>Efectivo Entregado:</span> <b style="color:var(--info);">$<?= money($item['efectivo']) ?></b></div>
        <div class="row-item">
            <span>Transferencias:</span> 
            <b style="<?= $item['status_trf'] ? 'text-decoration: line-through; color: #aaa;' : '' ?>">
                $<?= money($item['trf']) ?>
            </b>
        </div>
        <div class="total-box <?= $clase ?>">
            <span><?= (round($item['diferencia']) < 0) ? 'SOBRA:' : 'FALTA:' ?></span> 
            <span>$<?= money(abs($item['diferencia'])) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h4 style="color:var(--danger); border-left:5px solid var(--danger); padding-left:10px; margin-top:30px;">💸 Gestión de Egresos</h4>
<div class="grid">
    <?php foreach($egresosAgrupados as $nit => $egAg): ?>
    <div class="card" style="border-top-color: var(--danger);">
        <h5 style="margin:0 0 10px 0;"><?= htmlspecialchars($egAg['nombre']) ?></h5>
        <div style="max-height: 180px; overflow-y: auto;">
            <?php foreach($egAg['detalles'] as $det): $idE = $det['IDSALIDA']; ?>
            <div style="border-bottom:1px dashed #eee; padding:5px 0;">
                <?php if($permiso9999 === 'SI'): ?>
                    <input type="text" id="motivo_<?= $idE ?>" class="input-edit" value="<?= htmlspecialchars($det['MOTIVO']) ?>">
                    <div style="display:flex; gap:4px;">
                        <input type="number" id="valor_<?= $idE ?>" class="input-edit" value="<?= $det['VALOR'] ?>">
                        <button onclick="guardarEgreso(<?= $idE ?>, '<?= $egAg['id_sede'] ?>')" style="background:var(--success); color:white; border:none; border-radius:3px; padding:0 8px;">💾</button>
                    </div>
                <?php else: ?>
                    <div class="row-item"><span><?= htmlspecialchars($det['MOTIVO']) ?></span><b>$<?= money($det['VALOR']) ?></b></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="footer-summary">
    <div>VENTAS NETAS<br><b>$<?= money($globalVentas) ?></b></div>
    <div>EGRESOS TOTALES<br><b>$<?= money($globalEgresos) ?></b></div>
    <div>TRANSF. TOTALES<br><b>$<?= money($globalTransf) ?></b></div>
    <div>EFECTIVO ENTREGADO<br><b style="color:#00d4ff;">$<?= money($globalEfectivoEntregado) ?></b></div>
    <div style="border:1px solid rgba(255,255,255,0.3); border-radius:8px; padding:5px;">NETO GLOBAL<br><b>$<?= money($globalFisico) ?></b></div>
</div>

<script>
    // Configuración del Refresh (180 segundos)
    let secondsLeft = 180;
    const timerDisplay = document.getElementById('timer');

    const countdown = setInterval(() => {
        secondsLeft--;
        timerDisplay.textContent = secondsLeft;
        
        if (secondsLeft <= 0) {
            clearInterval(countdown);
            location.reload();
        }
    }, 1000);

    function guardarEgreso(id, sede){
        const mot = document.getElementById('motivo_'+id).value;
        const val = document.getElementById('valor_'+id).value;
        if(!confirm('¿Actualizar egreso?')) return;
        fetch('update_egreso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}&motivo=${encodeURIComponent(mot)}&valor=${encodeURIComponent(val)}&sede=${sede}`
        }).then(r => r.text()).then(t => { alert(t); location.reload(); });
    }
</script>
</body>
</html>