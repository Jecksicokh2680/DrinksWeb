<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES
============================================================ */
date_default_timezone_set('America/Bogota'); 
require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha_esc   = str_replace('-', '', $fecha_input);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

$sedes = [
    ['conn' => $mysqliCentral, 'nombre' => 'CENTRAL'],
    ['conn' => $mysqliDrinks,  'nombre' => 'DRINKS (AWS)']
];

$globalVentas = 0; $globalEgresos = 0; $globalTransf = 0; $globalFisico = 0;
$dataConsolidada = [];
$egresosAgrupados = [];

foreach ($sedes as $s) {
    $mysqliActiva = $s['conn'];
    $nombreSede   = $s['nombre'];

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

            // 1. VENTAS
            $qV = "SELECT SUM(VAL) AS TOTAL FROM (
                SELECT SUM(DF.CANTIDAD*DF.VALORPROD) AS VAL FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND F.IDVENDEDOR IN (SELECT IDTERCERO FROM TERCEROS WHERE NIT='$nit')
                UNION ALL 
                SELECT SUM(DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND P.IDUSUARIO IN (SELECT IDUSUARIO FROM USUVENDEDOR UV INNER JOIN TERCEROS T ON T.IDTERCERO=UV.IDTERCERO WHERE T.NIT='$nit')
            ) AS X";
            $vts = (float)($mysqliActiva->query($qV)->fetch_assoc()['TOTAL'] ?? 0);

            // 2. EGRESOS Y DETECCIÓN DE TRANSFERENCIAS MANUALES
            $qE = "SELECT S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $resE = $mysqliActiva->query($qE);
            
            $egrTotalCajero = 0;
            $tieneTransferenciaEnEgresos = false;

            if($resE->num_rows > 0){
                if(!isset($egresosAgrupados[$nit])){
                    $egresosAgrupados[$nit] = ['nombre' => $nombreCajero, 'sede' => $nombreSede, 'detalles' => [], 'total' => 0];
                }
                while($eg = $resE->fetch_assoc()){
                    $egresosAgrupados[$nit]['detalles'][] = $eg;
                    $egresosAgrupados[$nit]['total'] += (float)$eg['VALOR'];
                    $egrTotalCajero += (float)$eg['VALOR'];

                    // Si el motivo contiene "TRANSF", marcamos que ya se restó manualmente
                    if (stripos($eg['MOTIVO'], 'TRANSF') !== false) {
                        $tieneTransferenciaEnEgresos = true;
                    }
                }
            }

            // 3. TRANSFERENCIAS AUTOMÁTICAS (Solo se restan si NO están en Egresos)
            $qT = "SELECT SUM(Monto) AS TOTAL FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$nit'";
            $trf_auto = (float)($mysqli->query($qT)->fetch_assoc()['TOTAL'] ?? 0);
            
            // Lógica de validación:
            $trf_a_restar = ($tieneTransferenciaEnEgresos) ? 0 : $trf_auto;

            $fisico = $vts - $egrTotalCajero - $trf_a_restar;

            $dataConsolidada[] = [
                'sede' => $nombreSede, 'nombre' => $nombreCajero,
                'ventas' => $vts, 'egr' => $egrTotalCajero, 'trf' => $trf_auto, 'fisico' => $fisico,
                'audit' => $tieneTransferenciaEnEgresos // Para saber si se ignoró la resta auto
            ];

            $globalVentas += $vts; $globalEgresos += $egrTotalCajero; $globalTransf += $trf_auto; $globalFisico += $fisico;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado Auditoría</title>
    <style>
        :root { --primary: #2c3e50; --secondary: #1f2d3d; --accent: #f39c12; --success: #27ae60; --danger: #e74c3c; --bg: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 10px; }
        .header-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .universal-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .card { background: white; border-radius: 12px; padding: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 6px solid var(--primary); }
        .row-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f9f9f9; font-size: 14px; }
        .total-box { background: #fff3cd; margin-top: 15px; padding: 12px; border-radius: 8px; display: flex; justify-content: space-between; font-weight: bold; border: 1px solid #ffeeba; }
        .card-egreso { background: white; border-radius: 12px; border-left: 6px solid var(--danger); padding: 15px; box-shadow: 0 3px 5px rgba(0,0,0,0.05); }
        .egreso-item { font-size: 13px; color: #555; display: flex; justify-content: space-between; margin-bottom: 4px; padding-left: 8px; border-left: 2px solid #eee; }
        .footer-summary { background: var(--secondary); color: white; padding: 20px; border-radius: 15px; margin-top: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; text-align: center; }
        #timer { background: var(--accent); color: white; padding: 5px 12px; border-radius: 6px; font-weight: bold; font-size: 13px; }
        .badge-info { font-size: 10px; background: #d1ecf1; color: #0c5460; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 5px; }
    </style>
</head>
<body>

<div class="header-box">
    <h2 style="margin:0; font-size: 1.3rem;">🚀 Auditoría General de Sedes</h2>
    <div style="display:flex; align-items:center; gap:10px;">
        <input type="date" value="<?= $fecha_input ?>" onchange="location.href='?fecha='+this.value" style="padding:7px; border-radius:6px; border:1px solid #ddd;">
        <span id="timer">05:00</span>
    </div>
</div>

<div class="universal-grid">
    <?php foreach($dataConsolidada as $item): ?>
    <div class="card">
        <span style="font-size: 10px; font-weight: bold; color: #999;"><?= $item['sede'] ?></span>
        <h3 style="margin: 5px 0 12px 0; color: var(--primary); font-size: 1.1rem;"><?= htmlspecialchars($item['nombre']) ?></h3>
        
        <div class="row-item"><span>Ventas:</span> <b>$ <?= money($item['ventas']) ?></b></div>
        <div class="row-item"><span>Egresos:</span> <b style="color:var(--danger);">$ <?= money($item['egr']) ?></b></div>
        <div class="row-item"><span>Transferencias:</span> <b style="color:blue;">$ <?= money($item['trf']) ?></b></div>
        
        <?php if($item['audit']): ?>
            <span class="badge-info">✔ Transferencia incluida en Egresos</span>
        <?php endif; ?>

        <div class="total-box">
            <span>FÍSICO ESTIMADO</span>
            <span>$ <?= money($item['fisico']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h3 style="margin: 30px 0 15px 5px; color: var(--danger); border-left: 4px solid var(--danger); padding-left: 10px;">🚫 Detalle de Salidas Agrupadas</h3>

<div class="universal-grid">
    <?php if(count($egresosAgrupados) > 0): foreach($egresosAgrupados as $egAg): ?>
    <div class="card-egreso">
        <span style="font-size: 10px; font-weight: bold; color: #999;"><?= $egAg['sede'] ?></span>
        <h4 style="margin: 5px 0 12px 0;"><?= htmlspecialchars($egAg['nombre']) ?></h4>
        <?php foreach($egAg['detalles'] as $det): ?>
        <div class="egreso-item">
            <span>• <?= htmlspecialchars($det['MOTIVO']) ?></span>
            <span style="color: var(--danger); font-weight: bold;">$ <?= money($det['VALOR']) ?></span>
        </div>
        <?php endforeach; ?>
        <div style="text-align: right; border-top: 1px solid #eee; padding-top: 8px; margin-top: 10px; font-weight: bold; color: var(--danger);">
            Total: $ <?= money($egAg['total']) ?>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div style="grid-column: 1/-1; text-align: center; color: #999; padding: 20px; background: #fff; border-radius: 12px;">Sin movimientos de egreso.</div>
    <?php endif; ?>
</div>

<div class="footer-summary">
    <div><span>VENTAS</span><b>$ <?= money($globalVentas) ?></b></div>
    <div><span>EGRESOS</span><b>$ <?= money($globalEgresos) ?></b></div>
    <div><span>TRANSFERENCIAS</span><b>$ <?= money($globalTransf) ?></b></div>
    <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 8px;">
        <span>EFECTIVO NETO</span><b style="color: var(--success);">$ <?= money($globalFisico) ?></b>
    </div>
</div>

<script>
    let timeLeft = 300;
    setInterval(() => {
        if(timeLeft <= 0) location.reload();
        timeLeft--;
        let m = Math.floor(timeLeft/60), s = timeLeft%60;
        document.getElementById('timer').innerText = `${m}:${s<10?'0':''}${s}`;
    }, 1000);
</script>

</body>
</html>