<?php
// 1. CONFIGURACIÓN Y CONEXIÓN
date_default_timezone_set('America/Bogota');
require('ConnCentral.php'); 

$anioSel = $_POST['anio'] ?? date('Y');
$mesSel  = $_POST['mes'] ?? date('m');

ResumenMensualFacturador($anioSel, $mesSel);

function ResumenMensualFacturador($anio, $mes) {
    global $mysqliPos;

    $mesFmt      = str_pad($mes, 2, "0", STR_PAD_LEFT);
    $fechaInicio = $anio . $mesFmt . "01"; 
    $fechaFin    = date("Ymt", strtotime("$anio-$mesFmt-01"));

    // Array para nombres de días abreviados
    $diasNombres = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];

    $sql = "
        SELECT F.FECHA AS DIA,
               T1.NOMBRES AS FACTURADOR, 
               SUM(DF.CANTIDAD * DF.VALORPROD) AS TOTAL
        FROM FACTURAS F
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO = '0'
          AND F.FECHA BETWEEN ? AND ?
          AND DV.IDFACTURA IS NULL
        GROUP BY DIA, FACTURADOR
        UNION ALL
        SELECT P.FECHA AS DIA,
               T2.NOMBRES AS FACTURADOR,
               SUM(DP.CANTIDAD * DP.VALORPROD) AS TOTAL
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON P.IDPEDIDO = DP.IDPEDIDO
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO = P.IDUSUARIO
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V1.IDTERCERO
        WHERE P.ESTADO = '0'
          AND P.FECHA BETWEEN ? AND ?
        GROUP BY DIA, FACTURADOR
    ";

    if ($stmt = $mysqliPos->prepare($sql)) {
        $stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
        $stmt->execute();
        $result = $stmt->get_result();

        $resumen = [];
        $facturadores = [];

        while ($row = $result->fetch_assoc()) {
            $rawDia = $row['DIA'];
            $diaKey = substr($rawDia, 0, 4) . "-" . substr($rawDia, 4, 2) . "-" . substr($rawDia, 6, 2);
            $facturador = trim($row['FACTURADOR']);
            $total = (float)$row['TOTAL'];
            if ($total <= 0) continue;

            $facturadores[$facturador] = true;
            if (!isset($resumen[$diaKey])) $resumen[$diaKey] = [];
            $resumen[$diaKey][$facturador] = ($resumen[$diaKey][$facturador] ?? 0) + $total;
        }
        $stmt->close();

        $listaFacturadores = array_keys($facturadores);
        sort($listaFacturadores);

        $numDias = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $diasMesDesc = []; 
        $labelsGrafico = []; 
        $totalesGrafico = [];

        for ($d=1; $d<=$numDias; $d++) {
            $dKey = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
            $numDiaSemana = date('w', strtotime($dKey));
            $labelsGrafico[] = $diasNombres[$numDiaSemana] . " " . str_pad($d, 2, "0", STR_PAD_LEFT);
            
            $sumaDia = 0;
            if(isset($resumen[$dKey])) foreach($resumen[$dKey] as $v) $sumaDia += $v;
            $totalesGrafico[] = $sumaDia;
        }
        
        for ($d=$numDias; $d>=1; $d--) $diasMesDesc[] = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $hoy = date('Y-m-d');
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="refresh" content="300">
            <title>Resumen de Ventas</title>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
            <style>
                body { font-family: 'Segoe UI', sans-serif; margin:20px; background:#f8f9fa; }
                .card { background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
                h2 { text-align:center; color:#333; margin-bottom:5px; }
                table { border-collapse:collapse; width:100%; margin-top:20px; background:#fff; font-size:12px; }
                th, td { border:1px solid #dee2e6; padding:8px; text-align:right; }
                th { background:#556ee6; color:white; text-align:center; position:sticky; top:0; }
                .total-row { background:#2c3e50; color:white; font-weight:bold; }
                .avg-row { background:#27ae60; color:white; font-weight:bold; }
                .pct-row { background:#e9ecef; font-weight:bold; text-align:center; color:#495057; }
                .hoy-row { background:#fff9db !important; font-weight:bold; }
                
                /* Layout Gráficos */
                .chart-full { width:100%; margin-top:15px; background:#fff; padding:10px; border-radius:8px; border:1px solid #eee; }
                .charts-container { display:flex; justify-content:center; flex-wrap:wrap; margin-top:15px; gap:20px; }
                
                /* Tamaño Tortas reducido 30% aproximadamente vía width/max-width */
                .chart-box { width:28%; min-width:320px; max-width:380px; background:#fff; padding:10px; border-radius:8px; border:1px solid #eee; }
                
                form { text-align:center; margin-bottom:15px; }
                .btn-cerrar { display:inline-block; padding:8px 15px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px; margin-bottom:10px; }
            </style>
        </head>
        <body>

        <div class="card">
            <p style="text-align:center; color:#666; margin-top:0;">Mes: <?php echo "$mes / $anio"; ?></p>

            <form method="post">
                Año: <select name="anio">
                    <?php for($y=date('Y'); $y>=2023; $y--) echo "<option value='$y' ".($y==$anioSel?'selected':'').">$y</option>"; ?>
                </select>
                Mes: <select name="mes">
                    <?php 
                    $mesesNom = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
                    foreach($mesesNom as $i=>$m) { $v=$i+1; echo "<option value='$v' ".($v==$mesSel?'selected':'').">$m</option>"; }
                    ?>
                </select>
                <input type="submit" value="Consultar" style="background:#556ee6; color:#fff; border:none; padding:5px 15px; cursor:pointer; border-radius:4px;">
            </form>

            <div class="chart-full">
                <canvas id="cBarras" style="max-height: 250px;"></canvas>
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
                        $totalesF = array_fill_keys($listaFacturadores, 0);
                        $totalesH = array_fill_keys($listaFacturadores, 0);
                        $diasVentaF = array_fill_keys($listaFacturadores, 0);
                        
                        foreach($resumen as $dia => $dataFac) {
                            foreach($listaFacturadores as $f) {
                                $v = $dataFac[$f] ?? 0;
                                $totalesF[$f] += $v;
                                if($v > 0) $diasVentaF[$f]++;
                                if($dia == $hoy) $totalesH[$f] += $v;
                            }
                        }
                        $granTotal = array_sum($totalesF);

                        echo "<tr class='avg-row'><td>⭐ PROMEDIO DIARIO</td>";
                        foreach($listaFacturadores as $f) {
                            $prom = $diasVentaF[$f] > 0 ? $totalesF[$f]/$diasVentaF[$f] : 0;
                            echo "<td>".number_format($prom,0,',','.')."</td>";
                        }
                        echo "<td>-</td></tr>";

                        echo "<tr class='pct-row'><td>📈 PARTICIPACIÓN %</td>";
                        foreach($listaFacturadores as $f) {
                            $p = $granTotal > 0 ? ($totalesF[$f]/$granTotal)*100 : 0;
                            echo "<td>".number_format($p,1)."%</td>";
                        }
                        echo "<td>100%</td></tr>";

                        foreach($diasMesDesc as $dia) {
                            if(!isset($resumen[$dia])) continue;
                            $cls = ($dia == $hoy) ? "class='hoy-row'" : "";
                            $fechaTs = strtotime($dia);
                            $nombreDiaTab = $diasNombres[date('w', $fechaTs)] . " " . date('d', $fechaTs);
                            
                            echo "<tr $cls><td>$nombreDiaTab</td>";
                            $sumaD = 0;
                            foreach($listaFacturadores as $f) {
                                $v = $resumen[$dia][$f] ?? 0;
                                echo "<td>".number_format($v,0,',','.')."</td>";
                                $sumaD += $v;
                            }
                            echo "<td style='font-weight:bold;'>".number_format($sumaD,0,',','.')."</td></tr>";
                        }

                        echo "<tr class='total-row'><td>💰 TOTAL MES</td>";
                        foreach($listaFacturadores as $f) echo "<td>".number_format($totalesF[$f],0,',','.')."</td>";
                        echo "<td>".number_format($granTotal,0,',','.')."</td></tr>";
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            Chart.register(ChartDataLabels);

            // Gráfico de Barras
            new Chart(document.getElementById('cBarras'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($labelsGrafico); ?>,
                    datasets: [{
                        label: 'Venta Total',
                        data: <?php echo json_encode($totalesGrafico); ?>,
                        backgroundColor: 'rgba(85, 110, 230, 0.7)',
                        borderColor: '#556ee6',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Ventas por Día', font: { size: 14 } },
                        datalabels: { display: false }
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 } } },
                        y: { beginAtZero: true, ticks: { callback: (val) => '$' + val.toLocaleString() } }
                    }
                }
            });

            // Configuración de Tortas
            const labelsFac = <?php echo json_encode($listaFacturadores); ?>;
            const colores = labelsFac.map((_, i) => `hsl(${(i * 360) / labelsFac.length}, 70%, 55%)`);
            
            const opcionesPie = {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                    datalabels: {
                        color: '#fff',
                        font: { weight: 'bold', size: 16 }, // PORCENTAJES MÁS GRANDES
                        formatter: (val, ctx) => {
                            let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            let perc = ((val / total) * 100).toFixed(0);
                            return perc > 4 ? perc + "%" : ""; // Solo mostrar si es significativo
                        }
                    }
                }
            };

            new Chart(document.getElementById('cHoy'), {
                type: 'pie',
                data: { labels: labelsFac, datasets: [{ data: <?php echo json_encode(array_values($totalesH)); ?>, backgroundColor: colores }] },
                options: { ...opcionesPie, plugins: { ...opcionesPie.plugins, title: { display: true, text: 'Participación Hoy', font: { size: 13 } } } }
            });

            new Chart(document.getElementById('cMes'), {
                type: 'pie',
                data: { labels: labelsFac, datasets: [{ data: <?php echo json_encode(array_values($totalesF)); ?>, backgroundColor: colores }] },
                options: { ...opcionesPie, plugins: { ...opcionesPie.plugins, title: { display: true, text: 'Participación Mes', font: { size: 13 } } } }
            });
        </script>
        </body>
        </html>
        <?php
    }
}