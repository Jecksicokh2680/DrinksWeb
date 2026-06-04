<?php
// 1. CONFIGURACIÓN Y CONEXIONES
date_default_timezone_set('America/Bogota');

require('ConnCentral.php'); // Debe definir $mysqliCentral
require('ConnDrinks.php');  // Debe definir $mysqliDrinks

$anioSel = $_POST['anio'] ?? date('Y');
$sedeSel = $_POST['sede'] ?? 'todas'; 

ResumenAnualComparativoSedes($anioSel, $sedeSel);

function ResumenAnualComparativoSedes($anio, $sedeSel) {
    global $mysqliCentral, $mysqliDrinks;

    $anioPasado = $anio - 1;
    $datosMensuales = [];
    $mesesNombres = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
    ];

    foreach ($mesesNombres as $num => $txt) {
        $datosMensuales[$num] = [
            'mes' => $txt,
            'ACTUAL' => ['CENTRAL' => 0, 'DRINKS' => 0, 'CONSOLIDADO' => 0],
            'PASADO' => ['CENTRAL' => 0, 'DRINKS' => 0, 'CONSOLIDADO' => 0]
        ];
    }

    $fechaInicioPasado = $anioPasado . "0101";
    $fechaFinActual    = $anio . "1231";

    $conexionesActivas = [];
    if ($sedeSel == 'todas' || $sedeSel == 'central') $conexionesActivas['CENTRAL'] = $mysqliCentral;
    if ($sedeSel == 'todas' || $sedeSel == 'drinks')  $conexionesActivas['DRINKS']  = $mysqliDrinks;

    $sql = "
        SELECT F.FECHA AS DIA, SUM(DF.CANTIDAD * DF.VALORPROD) AS TOTAL
        FROM FACTURAS F
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO = '0' AND F.FECHA BETWEEN ? AND ? AND DV.IDFACTURA IS NULL
        GROUP BY DIA
        UNION ALL
        SELECT P.FECHA AS DIA, SUM(DP.CANTIDAD * DP.VALORPROD) AS TOTAL
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON P.IDPEDIDO = DP.IDPEDIDO
        WHERE P.ESTADO = '0' AND P.FECHA BETWEEN ? AND ?
        GROUP BY DIA
    ";

    foreach ($conexionesActivas as $nombreSede => $db) {
        if (!$db) continue; 
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ssss', $fechaInicioPasado, $fechaFinActual, $fechaInicioPasado, $fechaFinActual);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rawAnio = substr($row['DIA'], 0, 4);
                $mesKey  = substr($row['DIA'], 4, 2);
                $total   = (float)$row['TOTAL'];
                
                if (isset($datosMensuales[$mesKey])) {
                    $periodo = ($rawAnio == $anio) ? 'ACTUAL' : (($rawAnio == $anioPasado) ? 'PASADO' : null);
                    if ($periodo) {
                        $datosMensuales[$mesKey][$periodo][$nombreSede] += $total;
                        $datosMensuales[$mesKey][$periodo]['CONSOLIDADO'] += $total;
                    }
                }
            }
            $stmt->close();
        }
    }

    $labelsJS = [];
    $actualCentral = []; $pasadoCentral = [];
    $actualDrinks  = []; $pasadoDrinks  = [];
    $actualTotal   = []; $pasadoTotal   = [];

    foreach ($datosMensuales as $mKey => $valores) {
        $labelsJS[]      = $valores['mes'];
        $actualCentral[] = $valores['ACTUAL']['CENTRAL'];
        $pasadoCentral[] = $valores['PASADO']['CENTRAL'];
        $actualDrinks[]  = $valores['ACTUAL']['DRINKS'];
        $pasadoDrinks[]  = $valores['PASADO']['DRINKS'];
        $actualTotal[]   = $valores['ACTUAL']['CONSOLIDADO'];
        $pasadoTotal[]   = $valores['PASADO']['CONSOLIDADO'];
    }
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard Control Comercial Comparativo</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; background:#f4f7f6; color: #333; padding: 10px; }
            
            .container { width: 100%; max-width: 1400px; margin: 0 auto; padding: 15px; }
            .card { background:#fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
            
            h2 { text-align: center; color: #283593; margin-bottom: 20px; font-size: 1.6rem; }
            
            .filter-container { display: flex; flex-wrap: wrap; justify-content: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: #e8eaf6; border-radius: 10px; align-items: center; }
            .filter-group { display: flex; align-items: center; gap: 10px; }
            label { font-size: 11px; font-weight: bold; color: #5c6bc0; text-transform: uppercase; }
            select, input[type="submit"] { padding: 8px 12px; border-radius: 5px; border: 1px solid #ccc; font-size: 14px; width: 100%; max-width: 200px; }
            input[type="submit"] { background: #3f51b5; color: white; cursor: pointer; font-weight: bold; border: none; transition: background 0.2s; }
            input[type="submit"]:hover { background: #283593; }

            .interactive-box { background: #e0f2f1; border: 1px solid #b2dfdb; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
            .interactive-box select { color: #004d40; border: 1px solid #00796b; background: #fff; max-width: 280px; font-weight: bold; }
            .info-nota { font-size: 11px; color: #546e7a; flex: 1; min-width: 250px; }

            .dashboard-layout { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
            @media (min-width: 1150px) { .dashboard-layout { grid-template-columns: 7fr 5fr; } }
            
            .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
            table { border-collapse: collapse; width: 100%; font-size: 12px; background: white; min-width: 630px; }
            th, td { padding: 10px 12px; text-align: right; }
            th { background: #283593; color: white; text-align: center; font-weight: 600; white-space: nowrap; }
            td { border-bottom: 1px solid #f0f0f0; font-weight: 500; white-space: nowrap; }
            tr:hover td { background-color: #fcfdfe; }
            
            .total-row { background: #1a237e; color: white; font-weight: bold; font-size: 13px; }
            .total-row td { border-top: 2px solid #0d123f; }
            
            .chart-wrapper { background: #fff; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; min-height: 420px; height: 100%; position: relative; width: 100%; }

            .txt-subio { color: #2e7d32; font-weight: bold; }
            .txt-bajo { color: #c62828; font-weight: bold; }
            .txt-neutro { color: #757575; font-weight: bold; }
            .badge-v { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
            .b-subio { background: #e8f5e9; }
            .b-bajo { background: #ffebee; }
            .b-neutro { background: #f5f5f5; }
        </style>
    </head>
    <body>

    <div class="container">
        <div class="card">
            <h2>📊 Control Comercial: Comparativa de Ventas Históricas</h2>

            <form method="post" class="filter-container">
                <div class="filter-group">
                    <label>Año de Análisis:</label>
                    <select name="anio">
                        <?php for($y=date('Y'); $y>=2025; $y--) echo "<option value='$y' ".($y==$anioSel?'selected':'').">$y</option>"; ?>
                    </select>
                </div>
                <input type="submit" value="🔄 COMPARAR">
            </form>

            <div class="interactive-box">
                <select id="filtroPantallaSede" onchange="conmutarSedeDashboard()">
                    <option value="CONSOLIDADO">🏢 CONSOLIDADO EMPRESA</option>
                    <option value="CENTRAL">🔹 SEDE CENTRAL</option>
                    <option value="DRINKS">🍹 SEDE DRINKS</option>
                </select>
                <div class="info-nota">* La tabla y la gráfica se actualizan automáticamente en paralelo mostrando el año seleccionado frente al periodo previo.</div>
            </div>

            <div class="dashboard-layout">
                
                <div class="table-responsive">
                    <table id="tablaMensualizada">
                        <thead>
                            <tr>
                                <th style="text-align: left;">Mes</th>
                                <th>Año <span id="lblHeaderPasado"><?= $anioPasado ?></span></th>
                                <th>Año <span id="lblHeaderActual"><?= $anio ?></span></th>
                                <th>Diferencia ($)</th>
                                <th style="text-align: center;">Variación</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoTablaMensual">
                            </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td style="text-align: left;">TOTAL ACUMULADO</td>
                                <td id="txtTotalPasado">$ 0</td>
                                <td id="txtTotalActual">$ 0</td>
                                <td id="txtTotalDiferencia">$ 0</td>
                                <td id="txtTotalVariacion" style="text-align: center;">0%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div>
                    <div class="chart-wrapper">
                        <canvas id="graficoMensualVentas"></canvas>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        Chart.register(ChartDataLabels);

        // Variables dinámicas desde PHP de años consultados
        const anioActualEtiqueta = '<?= $anio ?>';
        const anioPasadoEtiqueta = '<?= $anioPasado ?>';

        const mesesEjeX = <?= json_encode($labelsJS) ?>;
        const cActual = <?= json_encode($actualCentral) ?>; const cPasado = <?= json_encode($pasadoCentral) ?>;
        const dActual = <?= json_encode($actualDrinks) ?>;  const dPasado = <?= json_encode($pasadoDrinks) ?>;
        const tActual = <?= json_encode($actualTotal) ?>;   const tPasado = <?= json_encode($pasadoTotal) ?>;

        let objGrafico = null;

        document.addEventListener("DOMContentLoaded", function() {
            conmutarSedeDashboard();
        });

        function conmutarSedeDashboard() {
            const sedeSeleccionada = document.getElementById("filtroPantallaSede").value;
            
            let dataActiva = []; let dataPasada = [];
            let colorActual = ''; let colorPasado = '#9e9e9e'; // Gris neutro estático para el año anterior

            if (sedeSeleccionada === 'CENTRAL') {
                dataActiva = cActual; dataPasada = cPasado;
                colorActual = '#1e88e5'; // Azul para Central
            } else if (sedeSeleccionada === 'DRINKS') {
                dataActiva = dActual; dataPasada = dPasado;
                colorActual = '#43a047'; // Verde para Drinks
            } else {
                dataActiva = tActual; dataPasada = tPasado;
                colorActual = '#ff9800'; // Naranja para Consolidado
            }

            // 1. POBLAR TABLA COMERCIAL CON DESVIACIONES
            const tbody = document.getElementById("cuerpoTablaMensual");
            tbody.innerHTML = "";
            
            let sumPasado = 0; let sumActual = 0;

            mesesEjeX.forEach((mes, index) => {
                const valPasado = dataPasada[index];
                const valActual = dataActiva[index];
                
                sumPasado += valPasado;
                sumActual += valActual;

                const diferenciaVal = valActual - valPasado;
                let txtVariacion = "0.0%";
                let claseEstilo = "txt-neutro";
                let claseBadge = "badge-v b-neutro";

                if (diferenciaVal > 0) { claseEstilo = "txt-subio"; claseBadge = "badge-v b-subio"; }
                else if (diferenciaVal < 0) { claseEstilo = "txt-bajo"; claseBadge = "badge-v b-bajo"; }

                if (valPasado > 0) {
                    let porcentaje = (diferenciaVal / valPasado) * 100;
                    txtVariacion = (porcentaje > 0 ? "+" : "") + porcentaje.toFixed(1) + "%";
                } else if (valPasado === 0 && valActual > 0) {
                    txtVariacion = "+100.0%";
                }

                let fila = `<tr>
                    <td style="text-align: left; font-weight: bold; color: #3f51b5;">${mes}</td>
                    <td style="color: #616161;">$ ${valPasado.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td style="font-weight: bold; color: #212121;">$ ${valActual.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td class="${claseEstilo}">$ ${diferenciaVal.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td style="text-align: center;"><span class="${claseBadge} ${claseEstilo}">${txtVariacion}</span></td>
                </tr>`;
                tbody.innerHTML += fila;
            });

            // Totales de la Matriz Comercial inferior
            const totalDiferencia = sumActual - sumPasado;
            let totalVarTxt = "0.0%";
            let totalClaseEstilo = "txt-neutro";
            let totalClaseBadge = "badge-v b-neutro";

            if (totalDiferencia > 0) { totalClaseEstilo = "txt-subio"; totalClaseBadge = "badge-v b-subio"; }
            else if (totalDiferencia < 0) { totalClaseEstilo = "txt-bajo"; totalClaseBadge = "badge-v b-bajo"; }

            if (sumPasado > 0) {
                let totalPorcentaje = (totalDiferencia / sumPasado) * 100;
                totalVarTxt = (totalPorcentaje > 0 ? "+" : "") + totalPorcentaje.toFixed(1) + "%";
            }

            document.getElementById("txtTotalPasado").innerText = "$ " + sumPasado.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("txtTotalActual").innerText = "$ " + sumActual.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("txtTotalDiferencia").className = totalClaseEstilo;
            document.getElementById("txtTotalDiferencia").innerText = "$ " + totalDiferencia.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("txtTotalVariacion").innerHTML = `<span class="${totalClaseBadge} ${totalClaseEstilo}">${totalVarTxt}</span>`;

            // 2. RENDERIZAR GRÁFICA DE BARRAS COMPARATIVAS DOBLES (AÑO PASADO VS ACTUAL)
            const ctx = document.getElementById('graficoMensualVentas').getContext('2d');
            if (objGrafico) objGrafico.destroy();

            objGrafico = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: mesesEjeX,
                    datasets: [
                        {
                            label: 'Año ' + anioPasadoEtiqueta + ' ($K)',
                            data: dataPasada,
                            backgroundColor: colorPasado,
                            borderColor: colorPasado,
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Año ' + anioActualEtiqueta + ' ($K)',
                            data: dataActiva,
                            backgroundColor: colorActual,
                            borderColor: colorActual,
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 30 } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: function(val) { return '$' + (val / 1000000).toLocaleString('es-CO') + 'K'; } }
                        }
                    },
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.dataset.label + ': $' + context.parsed.y.toLocaleString('es-CO');
                                }
                            }
                        },
                        datalabels: {
                            anchor: 'end',
                            align: 'top',
                            backgroundColor: '#263238',
                            color: '#ffffff',
                            borderRadius: 3,
                            padding: 3,
                            font: { weight: 'bold', size: 9 },
                            formatter: function(value) {
                                if (value === 0) return null;
                                return '$' + (value / 1000000).toLocaleString('es-CO', { maximumFractionDigits: 0 }) + 'K';
                            }
                        }
                    }
                }
            });
        }
    </script>
    </body>
    </html>
    <?php
}