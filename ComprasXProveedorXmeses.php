<?php
session_start();
require('Conexion.php');        
require('ConnCentral.php');     
require('ConnDrinks.php');      

$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') { header("Location: Login.php"); exit; }

$AnioSel = $_GET['Anio'] ?? date('Y');
$ProveedorSel = $_GET['Proveedor'] ?? '';
function fmoneda($v){ return "$ ".number_format($v,0,',','.'); }

$mesesNombres = ["Ene","Feb","Mar","Abr","May","Jun","Jul","Ago","Sep","Oct","Nov","Dic"];

/* =============================================
   1. OBTENER LISTA DE PROVEEDORES ACTIVOS
============================================= */
function obtenerProveedoresActivos($mysqli, $anio) {
    $sql = "SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) as nombre 
            FROM compras C 
            JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO 
            WHERE YEAR(STR_TO_DATE(C.FECHA,'%Y%m%d')) = '$anio' AND C.ESTADO='0'
            ORDER BY nombre ASC";
    $res = $mysqli->query($sql);
    $list = [];
    if($res){ while($r = $res->fetch_assoc()){ $list[$r['NIT']] = $r['nombre']; } }
    return $list;
}

$provC = obtenerProveedoresActivos($mysqliCentral, $AnioSel);
$provD = obtenerProveedoresActivos($mysqliDrinks, $AnioSel);
$listaProveedores = array_unique($provC + $provD);
asort($listaProveedores);

/* =============================================
   2. EXTRACCIÓN Y CONSOLIDACIÓN DE DATOS
============================================= */
function getDatosAnuales($mysqli, $anio, $nitProv) {
    $cond = $nitProv ? " AND T.NIT = '$nitProv' " : "";
    $sql = "SELECT 
                CONCAT(T.nombres,' ',T.apellidos) as prov,
                MONTH(STR_TO_DATE(C.FECHA,'%Y%m%d')) as mes,
                SUM((D.VALOR - (D.descuento/NULLIF(D.CANTIDAD,0))) * (1 + D.porciva/100) * D.CANTIDAD + (D.ValICUIUni * D.CANTIDAD)) as total_mes
            FROM compras C
            JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
            JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
            WHERE YEAR(STR_TO_DATE(C.FECHA,'%Y%m%d')) = '$anio' AND C.ESTADO='0' $cond
            GROUP BY prov, mes";
    $res = $mysqli->query($sql);
    $data = [];
    if($res){ while($r = $res->fetch_assoc()){ $data[$r['prov']][$r['mes']] = (float)$r['total_mes']; } }
    return $data;
}

$comprasC = getDatosAnuales($mysqliCentral, $AnioSel, $ProveedorSel);
$comprasD = getDatosAnuales($mysqliDrinks, $AnioSel, $ProveedorSel);

$consolidado = [];
$totalesMesGrafica = array_fill(1, 12, 0);

foreach ([$comprasC, $comprasD] as $fuente) {
    foreach ($fuente as $p => $meses) {
        if(!isset($consolidado[$p])){ $consolidado[$p] = array_fill(1, 12, 0); $consolidado[$p]['TOTAL_ANUAL'] = 0; }
        for ($i=1; $i<=12; $i++) {
            $monto = $meses[$i] ?? 0;
            $consolidado[$p][$i] += $monto;
            $consolidado[$p]['TOTAL_ANUAL'] += $monto;
            $totalesMesGrafica[$i] += $monto;
        }
    }
}

// ORDENAR DESCENDENTE POR TOTAL ANUAL
uasort($consolidado, function($a, $b) {
    return $b['TOTAL_ANUAL'] <=> $a['TOTAL_ANUAL'];
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Compras Gerencial</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        body{ font-family: 'Segoe UI', Arial; background:#f4f6f8; margin:0; padding:20px; }
        .card{ background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1); margin-bottom:20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #eee; padding: 10px; text-align: right; }
        th { background: #f8f9fa; position: sticky; top:0; z-index: 10; }
        .text-left { text-align: left; }
        .total-row { background: #e8f4fd; font-weight: bold; }
        .prov-col { background: #fafafa; font-weight: 600; }
        .filters { display: flex; gap: 15px; align-items: flex-end; background: #eef2f7; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .filters div { flex: 1; }
        label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 5px; text-transform: uppercase; color: #555; }
        select, button { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ccc; cursor: pointer; }
        button { background: #0d6efd; color: white; border: none; font-weight: bold; }
        .chart-wrapper { height: 350px; padding: 10px; }
    </style>
</head>
<body>

<div style="max-width: 1400px; margin: auto;">

    <div class="card">
        <h3 style="margin-top:0">📊 Análisis de Compras Mensuales (Valores en Miles)</h3>
        <div class="chart-wrapper">
            <canvas id="canvasGrafica"></canvas>
        </div>
    </div>

    <form method="GET" class="filters">
        <div>
            <label>Año de Gestión</label>
            <select name="Anio" onchange="this.form.submit()">
                <?php for($i=date('Y'); $i>=2023; $i--) echo "<option value='$i' ".($AnioSel==$i?'selected':'').">$i</option>"; ?>
            </select>
        </div>
        <div>
            <label>Proveedor con Compras en <?= $AnioSel ?></label>
            <select name="Proveedor">
                <option value="">-- Todos los proveedores --</option>
                <?php foreach($listaProveedores as $nit => $nom): ?>
                    <option value="<?= $nit ?>" <?= ($ProveedorSel == $nit ? 'selected' : '') ?>><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 0 0 200px;">
            <button type="submit">🔍 Actualizar Reporte</button>
        </div>
    </form>

    <div class="card">
        <h3 style="margin-top:0">📋 Ranking de Proveedores por Compra Acumulada</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th class="text-left">Proveedor</th>
                        <?php foreach($mesesNombres as $m) echo "<th>$m</th>"; ?>
                        <th>TOTAL ANUAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $granTotal = 0;
                    foreach($consolidado as $prov => $datos): 
                        $granTotal += $datos['TOTAL_ANUAL'];
                    ?>
                        <tr>
                            <td class="text-left prov-col"><?= htmlspecialchars($prov) ?></td>
                            <?php for($i=1; $i<=12; $i++): $v = $datos[$i]; ?>
                                <td><?= $v > 0 ? number_format($v,0,',','.') : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-row" style="color:#0d6efd; font-size:14px;"><?= fmoneda($datos['TOTAL_ANUAL']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row" style="background:#212529; color:white;">
                        <td class="text-left">TOTAL CONSOLIDADO</td>
                        <?php for($i=1; $i<=12; $i++) echo "<td>".number_format($totalesMesGrafica[$i],0,',','.')."</td>"; ?>
                        <td style="font-size:15px;"><?= fmoneda($granTotal) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<script>
// Registrar el plugin de etiquetas
Chart.register(ChartDataLabels);

const ctx = document.getElementById('canvasGrafica').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($mesesNombres) ?>,
        datasets: [{
            label: 'Compras (en miles)',
            data: <?= json_encode(array_values($totalesMesGrafica)) ?>,
            backgroundColor: 'rgba(13, 110, 253, 0.7)',
            borderColor: '#0d6efd',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 30 } }, // Espacio para que no se corten los números
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                formatter: function(value) {
                    return value > 0 ? (value / 1000).toLocaleString('es-CO', {maximumFractionDigits: 0}) + 'k' : '';
                },
                font: { weight: 'bold', size: 11 },
                color: '#444'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Monto: $' + context.parsed.y.toLocaleString('es-CO');
                    }
                }
            }
        },
        scales: {
            y: { 
                beginAtZero: true, 
                grid: { display: false },
                ticks: { display: false } // Ocultamos el eje Y para limpiar la gráfica ya que tenemos los valores arriba
            },
            x: { grid: { display: false } }
        }
    }
});
</script>

</body>
</html>