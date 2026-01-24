<?php
// 1. CONFIGURACIÓN Y CONEXIONES
date_default_timezone_set('America/Bogota');

require('ConnCentral.php'); // Debe definir $mysqliCentral
require('ConnDrinks.php');  // Debe definir $mysqliDrinks

$anioSel = $_POST['anio'] ?? date('Y');
$mesSel  = $_POST['mes'] ?? date('m');
$sedeSel = $_POST['sede'] ?? 'todas'; 

ResumenMensualSedes($anioSel, $mesSel, $sedeSel);

function ResumenMensualSedes($anio, $mes, $sedeSel) {
    global $mysqliCentral, $mysqliDrinks;

    $resumen = [];
    $facturadores = [];
    
    $mesFmt      = str_pad($mes, 2, "0", STR_PAD_LEFT);
    $fechaInicio = $anio . $mesFmt . "01"; 
    $fechaFin    = date("Ymt", strtotime("$anio-$mesFmt-01"));
    $diasNombres = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];

    $conexionesActivas = [];
    if ($sedeSel == 'todas' || $sedeSel == 'central') $conexionesActivas['CENTRAL'] = $mysqliCentral;
    if ($sedeSel == 'todas' || $sedeSel == 'drinks')  $conexionesActivas['DRINKS']  = $mysqliDrinks;

    $sql = "
        SELECT F.FECHA AS DIA, T1.NOMBRES AS FACTURADOR, SUM(DF.CANTIDAD * DF.VALORPROD) AS TOTAL
        FROM FACTURAS F
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO = '0' AND F.FECHA BETWEEN ? AND ? AND DV.IDFACTURA IS NULL
        GROUP BY DIA, FACTURADOR
        UNION ALL
        SELECT P.FECHA AS DIA, T2.NOMBRES AS FACTURADOR, SUM(DP.CANTIDAD * DP.VALORPROD) AS TOTAL
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON P.IDPEDIDO = DP.IDPEDIDO
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO = P.IDUSUARIO
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V1.IDTERCERO
        WHERE P.ESTADO = '0' AND P.FECHA BETWEEN ? AND ?
        GROUP BY DIA, FACTURADOR
    ";

    foreach ($conexionesActivas as $nombreSede => $db) {
        if (!$db) continue; 
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $diaKey = substr($row['DIA'], 0, 4) . "-" . substr($row['DIA'], 4, 2) . "-" . substr($row['DIA'], 6, 2);
                $nombreFac = trim($row['FACTURADOR']);
                $facturador = ($sedeSel == 'todas') ? $nombreFac . " ($nombreSede)" : $nombreFac;
                $total = (float)$row['TOTAL'];
                if ($total <= 0) continue;
                $facturadores[$facturador] = true;
                if (!isset($resumen[$diaKey])) $resumen[$diaKey] = [];
                $resumen[$diaKey][$facturador] = ($resumen[$diaKey][$facturador] ?? 0) + $total;
            }
            $stmt->close();
        }
    }

    $listaFacturadores = array_keys($facturadores);
    sort($listaFacturadores);

    // Preparación de datos para la gráfica de BARRAS (EN MILLONES)
    $numDias = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
    $labelsGrafico = []; 
    $totalesMillones = [];
    for ($d=1; $d<=$numDias; $d++) {
        $dKey = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $labelsGrafico[] = $diasNombres[date('w', strtotime($dKey))] . " " . str_pad($d, 2, "0", STR_PAD_LEFT);
        $sumaDia = 0;
        if(isset($resumen[$dKey])) foreach($resumen[$dKey] as $v) $sumaDia += $v;
        $totalesMillones[] = round($sumaDia / 1000000, 2); // Conversión a Millones
    }
    
    $diasMesDesc = [];
    for ($d=$numDias; $d>=1; $d--) $diasMesDesc[] = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $hoy = date('Y-m-d');
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Reporte de Ventas</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
        <style>
            body { font-family: 'Segoe UI', sans-serif; margin:20px; background:#f4f7f6; }
            .card { background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.05); }
            form { text-align:center; margin-bottom:20px; padding:15px; background:#e8eaf6; border-radius:10px; }
            select, input[type="submit"] { padding:8px; border-radius:5px; border:1px solid #ccc; margin:5px; }
            table { border-collapse:collapse; width:100%; font-size:12px; margin-top:20px; }
            th, td { border:1px solid #eee; padding:10px; text-align:right; }
            th { background:#3f51b5; color:white; text-align:center; }
            .total-row { background:#1a237e; color:white; font-weight:bold; }
            .hoy-row { background:#fff9c4 !important; font-weight:bold; }
            .charts-container { display:flex; justify-content:center; flex-wrap:wrap; gap:20px; margin:20px 0; }
            .chart-box { width:30%; min-width:320px; background:#fff; padding:10px; border-radius:8px; border:1px solid #ddd; }
        </style>
    </head>
    <body>

    <div class="card">
        <h2 style="text-align:center; color:#3f51b5;">Reporte de Ventas: <?php echo strtoupper($sedeSel); ?></h2>

        <form method="post">
            Sede: <select name="sede">
                <option value="todas" <?php echo ($sedeSel=='todas'?'selected':''); ?>>🌎 TODAS LAS SEDES</option>
                <option value="central" <?php echo ($sedeSel=='central'?'selected':''); ?>>🏢 SEDE CENTRAL</option>
                <option value="drinks" <?php echo ($sedeSel=='drinks'?'selected':''); ?>>🍹 SEDE DRINKS</option>
            </select>
            Año: <select name="anio">
                <?php for($y=date('Y'); $y>=2024; $y--) echo "<option value='$y' ".($y==$anioSel?'selected':'').">$y</option>"; ?>
            </select>
            Mes: <select name="mes">
                <?php 
                $meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
                foreach($meses as $i=>$m) { $v=$i+1; echo "<option value='$v' ".($v==$mesSel?'selected':'').">$m</option>"; }
                ?>
            </select>
            <input type="submit" value="🔄 ACTUALIZAR" style="background:#3f51b5; color:white; cursor:pointer; font-weight:bold;">
        </form>

        <div style="background:#fff; border:1px solid #ddd; padding:15px; border-radius:8px;">
            <canvas id="cBarras" style="max-height: 280px;"></canvas>
        </div>

        <div class="charts-container">
            <div class="chart-box"><canvas id="cHoy"></canvas></div>
            <div class="chart-box"><canvas id="cMes"></canvas></div>
        </div>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Día</th>
                        <?php foreach($listaFacturadores as $f) echo "<th>$f</th>"; ?>
                        <th>TOTAL DÍA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totF = array_fill_keys($listaFacturadores, 0);
                    $totH = array_fill_keys($listaFacturadores, 0);
                    foreach($diasMesDesc as $dia) {
                        if(!isset($resumen[$dia])) continue;
                        $cls = ($dia == $hoy) ? "class='hoy-row'" : "";
                        echo "<tr $cls><td>".date('d', strtotime($dia))."</td>";
                        $sumaD = 0;
                        foreach($listaFacturadores as $f) {
                            $v = $resumen[$dia][$f] ?? 0;
                            echo "<td>".number_format($v,0,',','.')."</td>";
                            $totF[$f] += $v;
                            if($dia == $hoy) $totH[$f] += $v;
                            $sumaD += $v;
                        }
                        echo "<td style='background:#f5f5f5; font-weight:bold;'>".number_format($sumaD,0,',','.')."</td></tr>";
                    }
                    ?>
                    <tr class="total-row">
                        <td>TOTAL</td>
                        <?php foreach($listaFacturadores as $f) echo "<td>".number_format($totF[$f],0,',','.')."</td>"; ?>
                        <td><?php echo number_format(array_sum($totF),0,',','.'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        Chart.register(ChartDataLabels);

        // Gráfica de Barras - EN MILLONES Y COLOR NARANJA
        new Chart(document.getElementById('cBarras'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labelsGrafico); ?>,
                datasets: [{
                    label: 'Ventas (Millones de $)',
                    data: <?php echo json_encode($totalesMillones); ?>,
                    backgroundColor: '#ff9800', // COLOR NARANJA
                    borderColor: '#e65100',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ' $' + ctx.raw + ' Millones' } }
                },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: { callback: (v) => '$' + v + 'M' } 
                    } 
                }
            }
        });

        const labelsFac = <?php echo json_encode($listaFacturadores); ?>;
        const configPie = {
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                datalabels: {
                    color: '#fff', 
                    font: { weight: 'bold' },
                    formatter: (v, c) => {
                        let total = c.dataset.data.reduce((a, b) => a + b, 0);
                        return total > 0 ? ((v/total)*100).toFixed(0) + '%' : '';
                    }
                }
            }
        };

        // Tortas (Escala proporcional)
        new Chart(document.getElementById('cHoy'), {
            type: 'pie',
            data: { 
                labels: labelsFac, 
                datasets: [{ 
                    data: <?php echo json_encode(array_values($totH)); ?>, 
                    backgroundColor: labelsFac.map((_,i)=>`hsl(${(i*137)%360},70%,50%)`) 
                }] 
            },
            options: { ...configPie, plugins: { ...configPie.plugins, title: { display:true, text: 'Participación Hoy' } } }
        });

        new Chart(document.getElementById('cMes'), {
            type: 'pie',
            data: { 
                labels: labelsFac, 
                datasets: [{ 
                    data: <?php echo json_encode(array_values($totF)); ?>, 
                    backgroundColor: labelsFac.map((_,i)=>`hsl(${(i*137)%360},70%,50%)`) 
                }] 
            },
            options: { ...configPie, plugins: { ...configPie.plugins, title: { display:true, text: 'Participación Mes' } } }
        });
    </script>
    </body>
    </html>
    <?php
}