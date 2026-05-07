<?php
require('ConnCentral.php'); 
require('ConnDrinks.php');  
require('Conexion.php');    

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) { header("Location: Login.php"); exit; }

date_default_timezone_set('America/Bogota');

// 1. Obtener rango de fechas
$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-01');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');
$f_ini = str_replace('-', '', $f_ini_raw);
$f_fin = str_replace('-', '', $f_fin_raw);

function obtenerVentasCompletas($cnx, $f_ini, $f_fin) {
    if (!$cnx || $cnx->connect_error) return [];
    $sql = "SELECT FACTURAS.FECHA, T1.NOMBRES AS FACTURADOR, FACTURAS.HORA, (DETFACTURAS.CANTIDAD * DETFACTURAS.VALORPROD) AS TOTAL
            FROM FACTURAS 
            INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR
            WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ?
            UNION ALL
            SELECT PEDIDOS.FECHA, T2.NOMBRES AS FACTURADOR, PEDIDOS.HORA, (DETPEDIDOS.CANTIDAD * DETPEDIDOS.VALORPROD) AS TOTAL
            FROM PEDIDOS
            INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO
            INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO
            INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO
            INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO
            WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ?";
    $stmt = $cnx->prepare($sql);
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$rows = [];
if (isset($mysqliCentral)) $rows = array_merge($rows, obtenerVentasCompletas($mysqliCentral, $f_ini, $f_fin));
if (isset($mysqliDrinks))  $rows = array_merge($rows, obtenerVentasCompletas($mysqliDrinks, $f_ini, $f_fin));

// 2. Procesamiento con los NUEVOS RANGOS
$rangosLabels = ['Apertura-6am', '6am-9am', '9am-12pm', '12pm-Cierre'];
$dataFacturadores = [];
$totalGlobal = array_fill_keys($rangosLabels, 0);
$diasUnicos = [];

foreach($rows as $r) {
    $fac = $r['FACTURADOR'];
    $fecha = $r['FECHA'];
    $h = (int)date("H", strtotime($r['HORA']));
    $diasUnicos[$fecha] = true;

    $rango = '';
    if ($h < 6) {
        $rango = 'Apertura-6am';
    } elseif ($h >= 6 && $h < 9) {
        $rango = '6am-9am';
    } elseif ($h >= 9 && $h < 12) {
        $rango = '9am-12pm';
    } else {
        $rango = '12pm-Cierre';
    }

    if ($rango !== '') {
        if (!isset($dataFacturadores[$fac])) $dataFacturadores[$fac] = array_fill_keys($rangosLabels, 0);
        $dataFacturadores[$fac][$rango] += (float)$r['TOTAL'];
        $totalGlobal[$rango] += (float)$r['TOTAL'];
    }
}

$conteoDias = count($diasUnicos) ?: 1;
$promedioGlobal = [];
foreach($totalGlobal as $rango => $suma) {
    $promedioGlobal[$rango] = $suma / $conteoDias;
}

ksort($dataFacturadores);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Analítico - Rangos Equilibrados</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        :root { --primary: #1a73e8; --avg: #f4b400; --global: #34a853; --bg: #f8f9fa; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); margin: 0; padding: 20px; }
        .header { background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .filters { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .f-item { display: flex; flex-direction: column; gap: 5px; }
        .f-item label { font-size: 11px; font-weight: bold; color: #666; }
        input, button { padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
        button { background: var(--primary); color: white; border: none; cursor: pointer; font-weight: bold; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; }
        .card { background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .full-width { grid-column: 1 / -1; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { font-weight: bold; color: #444; }
        .canvas-container { height: 300px; position: relative; }
    </style>
</head>
<body>

<div class="header">
    <h2 style="margin:0 0 20px 0; color: var(--primary)">📈 Inteligencia de Ventas (Rangos Ajustados)</h2>
    <form method="GET" class="filters">
        <div class="f-item"><label>FECHA INICIO</label><input type="date" name="fecha_ini" value="<?=$f_ini_raw?>"></div>
        <div class="f-item"><label>FECHA FIN</label><input type="date" name="fecha_fin" value="<?=$f_fin_raw?>"></div>
        <button type="submit">Generar Análisis</button>
    </form>
</div>

<div class="grid">
    <div class="card full-width" style="border-left: 5px solid var(--avg);">
        <div class="card-header"><span class="card-title">📉 PROMEDIO DIARIO (<?=$conteoDias?> días)</span></div>
        <div class="canvas-container"><canvas id="chart_promedio"></canvas></div>
    </div>

    <div class="card full-width" style="border-left: 5px solid var(--global);">
        <div class="card-header">
            <span class="card-title">🌍 TOTAL ACUMULADO</span>
            <span style="font-weight:bold; color:var(--global)">$<?=number_format(array_sum($totalGlobal),0,',','.')?></span>
        </div>
        <div class="canvas-container"><canvas id="chart_total"></canvas></div>
    </div>

    <?php foreach($dataFacturadores as $nombre => $valores): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">👤 <?=$nombre?></span>
            <span style="font-size:13px; font-weight:bold">$<?=number_format(array_sum($valores),0,',','.')?></span>
        </div>
        <div class="canvas-container" style="height: 250px;">
            <canvas id="ch_<?=md5($nombre)?>"></canvas>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
Chart.register(ChartDataLabels);
const labelsX = <?= json_encode($rangosLabels) ?>;
const formatMoney = (v) => v > 0 ? '$' + v.toLocaleString('es-CO') : '';

const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    layout: { padding: { top: 30 } },
    plugins: {
        legend: { display: false },
        datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold', size: 11 }, formatter: formatMoney }
    },
    scales: { y: { display: false, beginAtZero: true }, x: { grid: { display: false }, ticks: { font: { weight: 'bold' } } } }
};

new Chart(document.getElementById('chart_promedio'), {
    type: 'bar',
    data: { labels: labelsX, datasets: [{ data: <?= json_encode(array_values($promedioGlobal)) ?>, backgroundColor: '#f4b400cc', borderRadius: 8 }] },
    options: commonOptions
});

new Chart(document.getElementById('chart_total'), {
    type: 'bar',
    data: { labels: labelsX, datasets: [{ data: <?= json_encode(array_values($totalGlobal)) ?>, backgroundColor: '#34a853cc', borderRadius: 8 }] },
    options: commonOptions
});

const factData = <?= json_encode($dataFacturadores) ?>;
const factHashes = <?php echo json_encode(array_combine(array_keys($dataFacturadores), array_map('md5', array_keys($dataFacturadores)))); ?>;

Object.keys(factData).forEach(name => {
    const id = "ch_" + factHashes[name];
    new Chart(document.getElementById(id), {
        type: 'bar',
        data: {
            labels: labelsX,
            datasets: [{ data: labelsX.map(l => factData[name][l]), backgroundColor: '#1a73e8cc', borderRadius: 5 }]
        },
        options: { ...commonOptions, plugins: { ...commonOptions.plugins, datalabels: { ...commonOptions.plugins.datalabels, font: { size: 9 } } } }
    });
});
</script>
</body>
</html>