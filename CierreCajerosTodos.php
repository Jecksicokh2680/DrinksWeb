<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES (AMBAS SEDES)
============================================================ */
date_default_timezone_set('America/Bogota'); //
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

            $qV = "SELECT SUM(VAL) AS TOTAL FROM (
                SELECT SUM(DF.CANTIDAD*DF.VALORPROD) AS VAL FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
                WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND T1.NIT='$nit'
                UNION ALL 
                SELECT SUM(DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
                INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$nit'
            ) AS X";
            $vts = (float)($mysqliActiva->query($qV)->fetch_assoc()['TOTAL'] ?? 0);

            $qE = "SELECT SUM(VALOR) AS TOTAL FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $egr = (float)($mysqliActiva->query($qE)->fetch_assoc()['TOTAL'] ?? 0);

            $qT = "SELECT SUM(Monto) AS TOTAL FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$nit'";
            $trf = (float)($mysqli->query($qT)->fetch_assoc()['TOTAL'] ?? 0);

            $fisico = $vts - $egr - $trf;

            $dataConsolidada[] = [
                'sede'   => $nombreSede,
                'nombre' => $c['NOMBRE'],
                'ventas' => $vts, 'egr' => $egr, 'trf' => $trf, 'fisico' => $fisico
            ];

            $globalVentas += $vts; $globalEgresos += $egr; $globalTransf += $trf; $globalFisico += $fisico;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consolidado Total - Autorefresh</title>
    <style>
        :root { --primary: #2c3e50; --secondary: #1f2d3d; --accent: #f39c12; --success: #27ae60; --danger: #e74c3c; --bg: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 10px; color: #333; }
        
        /* Contenedor Superior Responsive */
        .top-bar { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; flex-direction: column; gap: 10px; }
        @media (min-width: 768px) { .top-bar { flex-direction: row; justify-content: space-between; align-items: center; } }

        /* Grid de Tarjetas */
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
        .card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; border-left: 6px solid var(--primary); }
        .card:hover { transform: translateY(-3px); }
        .card-body { padding: 15px; }
        .sede-badge { background: #eee; font-size: 11px; padding: 3px 8px; border-radius: 20px; font-weight: bold; color: #666; }
        
        .row-data { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; }
        .total-box { background: #fff9e6; margin-top: 10px; padding: 12px; border-radius: 8px; display: flex; justify-content: space-between; font-weight: bold; font-size: 16px; border: 1px solid #ffeeba; }

        /* Footer Flotante/Responsive */
        .global-footer { background: var(--secondary); color: white; padding: 20px; border-radius: 15px; margin-top: 30px; display: grid; grid-template-columns: 1fr; gap: 15px; text-align: center; }
        @media (min-width: 768px) { .global-footer { grid-template-columns: repeat(4, 1fr); } }
        .global-footer div span { display: block; font-size: 12px; color: #bdc3c7; text-transform: uppercase; margin-bottom: 5px; }
        .global-footer b { font-size: 1.4em; color: var(--accent); }

        .refresh-info { font-size: 12px; color: #888; text-align: center; margin-top: 10px; }
    </style>
</head>
<body>

<div class="top-bar">
    <h2 style="margin:0; font-size: 1.3em;">💰 Resumen de Cajas Unificado</h2>
    <form method="GET" style="display: flex; gap: 8px; align-items: center;">
        <input type="date" name="fecha" value="<?= $fecha_input ?>" onchange="this.form.submit()" style="padding:8px; border-radius:6px; border:1px solid #ddd;">
        <span id="countdown" style="background: var(--accent); color: white; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">Carga lista</span>
    </form>
</div>

<div class="card-grid">
    <?php foreach($dataConsolidada as $item): ?>
    <div class="card">
        <div class="card-body">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span class="sede-badge"><?= $item['sede'] ?></span>
                <span style="font-size: 12px; color: #aaa;"># <?= substr($fecha_input, -2) ?></span>
            </div>
            <h3 style="margin: 10px 0; color: var(--primary); font-size: 1.1em;"><?= htmlspecialchars($item['nombre']) ?></h3>
            
            <div class="row-data"><span>(+) Ventas:</span> <b>$ <?= money($item['ventas']) ?></b></div>
            <div class="row-data"><span>(-) Egresos:</span> <b style="color:var(--danger);">$ <?= money($item['egr']) ?></b></div>
            <div class="row-data"><span>(-) Transf:</span> <b style="color:blue;">$ <?= money($item['trf']) ?></b></div>
            
            <div class="total-box">
                <span>TOTAL FÍSICO</span>
                <span>$ <?= money($item['fisico']) ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="global-footer">
    <div><span>Ventas Totales</span> <b>$ <?= money($globalVentas) ?></b></div>
    <div><span>Egresos Totales</span> <b>$ <?= money($globalEgresos) ?></b></div>
    <div><span>Transf. Totales</span> <b>$ <?= money($globalTransf) ?></b></div>
    <div style="border-top: 1px solid #444; padding-top: 10px; margin-top: 5px;">
        <span>Físico en Sedes</span> <b style="color: var(--success); font-size: 1.8em;">$ <?= money($globalFisico) ?></b>
    </div>
</div>

<p class="refresh-info">Esta página se actualiza automáticamente cada 5 minutos. <br> Última carga: <?= date("h:i:s A") ?></p>

<script>
    // Configuración del autorefresh (5 minutos = 300 segundos)
    let secondsLeft = 300;
    const countdownEl = document.getElementById('countdown');

    const timer = setInterval(() => {
        secondsLeft--;
        let mins = Math.floor(secondsLeft / 60);
        let secs = secondsLeft % 60;
        countdownEl.innerText = `Actualizando en ${mins}:${secs < 10 ? '0' : ''}${secs}`;
        
        if (secondsLeft <= 0) {
            clearInterval(timer);
            location.reload();
        }
    }, 1000);
</script>

</body>
</html>