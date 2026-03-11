<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES (AMBAS SEDES)
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
$todosLosEgresos = [];

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

            $qV = "SELECT SUM(VAL) AS TOTAL FROM (
                SELECT SUM(DF.CANTIDAD*DF.VALORPROD) AS VAL FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND F.IDVENDEDOR IN (SELECT IDTERCERO FROM TERCEROS WHERE NIT='$nit')
                UNION ALL 
                SELECT SUM(DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND P.IDUSUARIO IN (SELECT IDUSUARIO FROM USUVENDEDOR UV INNER JOIN TERCEROS T ON T.IDTERCERO=UV.IDTERCERO WHERE T.NIT='$nit')
            ) AS X";
            $vts = (float)($mysqliActiva->query($qV)->fetch_assoc()['TOTAL'] ?? 0);

            $qE = "SELECT S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $resE = $mysqliActiva->query($qE);
            $egrTotalCajero = 0;
            while($egDetalle = $resE->fetch_assoc()){
                $egrTotalCajero += (float)$egDetalle['VALOR'];
                $todosLosEgresos[] = [
                    'sede'   => $nombreSede,
                    'cajero' => $nombreCajero,
                    'motivo' => $egDetalle['MOTIVO'],
                    'valor'  => $egDetalle['VALOR']
                ];
            }

            $qT = "SELECT SUM(Monto) AS TOTAL FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$nit'";
            $trf = (float)($mysqli->query($qT)->fetch_assoc()['TOTAL'] ?? 0);

            $fisico = $vts - $egrTotalCajero - $trf;

            $dataConsolidada[] = [
                'sede' => $nombreSede, 'nombre' => $nombreCajero,
                'ventas' => $vts, 'egr' => $egrTotalCajero, 'trf' => $trf, 'fisico' => $fisico
            ];

            $globalVentas += $vts; $globalEgresos += $egrTotalCajero; $globalTransf += $trf; $globalFisico += $fisico;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Resumen Consolidado Responsive</title>
    <style>
        :root { --primary: #2c3e50; --secondary: #1f2d3d; --accent: #f39c12; --success: #27ae60; --danger: #e74c3c; --bg: #f4f7f6; }
        
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: var(--bg); margin: 0; padding: 10px; color: #333; line-height: 1.4; }
        
        /* Contenedor Superior */
        .header-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; flex-direction: column; gap: 15px; }
        @media (min-width: 768px) { .header-box { flex-direction: row; justify-content: space-between; align-items: center; padding: 20px; } }

        /* Grids Universales */
        .universal-grid { 
            display: grid; 
            grid-template-columns: 1fr; /* Por defecto 1 columna (móvil) */
            gap: 15px; 
            margin-bottom: 30px; 
        }
        @media (min-width: 640px) { .universal-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .universal-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1440px) { .universal-grid { grid-template-columns: repeat(4, 1fr); } }

        /* Tarjetas */
        .card { background: white; border-radius: 12px; padding: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; }
        .card-main { border-top: 6px solid var(--primary); }
        .card-egreso { border-left: 6px solid var(--danger); }
        
        .label-sede { font-size: 10px; text-transform: uppercase; font-weight: bold; color: #999; margin-bottom: 5px; }
        .cajero-name { margin: 0 0 12px 0; color: var(--primary); font-size: 1.15rem; word-break: break-word; }
        
        .row-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f8f8f8; font-size: 14px; }
        .total-box { background: #fff3cd; margin-top: auto; padding: 12px; border-radius: 8px; display: flex; justify-content: space-between; font-weight: bold; border: 1px solid #ffeeba; margin-top: 15px; }

        .section-title { margin: 30px 0 15px 0; color: var(--danger); font-size: 1.3rem; padding-left: 5px; border-left: 4px solid var(--danger); }

        /* Footer Consolidado */
        .footer-summary { background: var(--secondary); color: white; padding: 20px; border-radius: 15px; margin-top: 30px; display: grid; grid-template-columns: 1fr; gap: 20px; text-align: center; }
        @media (min-width: 640px) { .footer-summary { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .footer-summary { grid-template-columns: repeat(4, 1fr); } }
        
        .footer-summary span { font-size: 11px; color: #bdc3c7; text-transform: uppercase; display: block; margin-bottom: 5px; }
        .footer-summary b { font-size: 1.4rem; color: var(--accent); }

        /* Inputs y Botones */
        input[type="date"] { padding: 8px; border-radius: 6px; border: 1px solid #ddd; font-family: inherit; width: 100%; max-width: 200px; }
        #timer { background: var(--accent); color: white; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 13px; white-space: nowrap; }
    </style>
</head>
<body>

<div class="header-box">
    <h2 style="margin:0; font-size: 1.4rem;">📊 Consolidado General</h2>
    <div style="display: flex; gap: 10px; align-items: center; width: 100%; justify-content: flex-end; flex-wrap: wrap;">
        <form method="GET" style="display:contents;">
            <input type="date" name="fecha" value="<?= $fecha_input ?>" onchange="this.form.submit()">
        </form>
        <span id="timer">05:00</span>
    </div>
</div>

<div class="universal-grid">
    <?php foreach($dataConsolidada as $item): ?>
    <div class="card card-main">
        <span class="label-sede"><?= $item['sede'] ?></span>
        <h3 class="cajero-name"><?= htmlspecialchars($item['nombre']) ?></h3>
        
        <div class="row-item"><span>Ventas Brutas:</span> <b>$ <?= money($item['ventas']) ?></b></div>
        <div class="row-item"><span>(-) Egresos:</span> <b style="color:var(--danger);">$ <?= money($item['egr']) ?></b></div>
        <div class="row-item"><span>(-) Transferencias:</span> <b style="color:blue;">$ <?= money($item['trf']) ?></b></div>
        
        <div class="total-box">
            <span>TOTAL FÍSICO</span>
            <span>$ <?= money($item['fisico']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h3 class="section-title">💸 Detalle de Egresos</h3>

<div class="universal-grid">
    <?php if(count($todosLosEgresos) > 0): foreach($todosLosEgresos as $eg): ?>
    <div class="card card-egreso">
        <div>
            <span class="label-sede"><?= $eg['sede'] ?></span>
            <div style="font-weight: bold; font-size: 15px; margin-bottom: 5px;"><?= htmlspecialchars($eg['cajero']) ?></div>
            <div style="font-size: 13px; color: #666; font-style: italic; line-height: 1.4;">"<?= htmlspecialchars($eg['motivo']) ?>"</div>
        </div>
        <div style="text-align: right; margin-top: 15px; border-top: 1px solid #f0f0f0; padding-top: 10px;">
            <b style="color: var(--danger); font-size: 1.2rem;">$ <?= money($eg['valor']) ?></b>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div style="grid-column: 1/-1; text-align: center; color: #999; padding: 40px; background: #fff; border-radius: 12px;">Sin egresos hoy.</div>
    <?php endif; ?>
</div>

<div class="footer-summary">
    <div><span>Ventas Global</span><b>$ <?= money($globalVentas) ?></b></div>
    <div><span>Egresos Global</span><b>$ <?= money($globalEgresos) ?></b></div>
    <div><span>Transferencias</span><b>$ <?= money($globalTransf) ?></b></div>
    <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 10px;">
        <span>Efectivo Total</span>
        <b style="color: var(--success); font-size: 1.8rem;">$ <?= money($globalFisico) ?></b>
    </div>
</div>

<script>
    let timeLeft = 300;
    const timerDisplay = document.getElementById('timer');
    setInterval(() => {
        if(timeLeft <= 0) location.reload();
        timeLeft--;
        let m = Math.floor(timeLeft/60), s = timeLeft%60;
        timerDisplay.innerText = `${m}:${s<10?'0':''}${s}`;
    }, 1000);
</script>

</body>
</html>