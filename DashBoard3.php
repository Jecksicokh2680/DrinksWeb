<?php
// 1. CONFIGURACIÓN Y CONEXIONES
session_start();
date_default_timezone_set('America/Bogota');

require('ConnCentral.php'); // Debe definir $mysqliCentral
require('ConnDrinks.php');  // Debe definir $mysqliDrinks

$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') { header("Location: Login.php"); exit; }

$anioSel = $_POST['anio'] ?? date('Y');
$sedeSel = $_POST['sede'] ?? 'todas'; 

ResumenAnualCrucesComerciales($anioSel, $sedeSel);

function ResumenAnualCrucesComerciales($anio, $sedeSel) {
    global $mysqliCentral, $mysqliDrinks;

    $datosMensuales = [];
    $mesesNombres = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
    ];

    foreach ($mesesNombres as $num => $txt) {
        $datosMensuales[$num] = [
            'mes' => $txt,
            'VENTAS'  => ['CENTRAL' => 0, 'DRINKS' => 0, 'CONSOLIDADO' => 0],
            'COMPRAS' => ['CENTRAL' => 0, 'DRINKS' => 0, 'CONSOLIDADO' => 0]
        ];
    }

    $fechaInicio = $anio . "0101";
    $fechaFin    = $anio . "1231";

    $conexionesActivas = [];
    if ($sedeSel == 'todas' || $sedeSel == 'central') $conexionesActivas['CENTRAL'] = $mysqliCentral;
    if ($sedeSel == 'todas' || $sedeSel == 'drinks')  $conexionesActivas['DRINKS']  = $mysqliDrinks;

    /* =============================================
       1. EXTRACCIÓN DE VENTAS (Corregido IDPEDIDO)
    ============================================= */
    $sqlVentas = "
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

    /* =============================================
       2. EXTRACCIÓN DE COMPRAS
    ============================================= */
    $sqlCompras = "
        SELECT C.FECHA AS DIA, 
               SUM((D.VALOR - (D.descuento/NULLIF(D.CANTIDAD,0))) * (1 + D.porciva/100) * D.CANTIDAD + (D.ValICUIUni * D.CANTIDAD)) as TOTAL
        FROM compras C
        INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        WHERE C.ESTADO = '0' AND C.FECHA BETWEEN ? AND ?
        GROUP BY DIA
    ";

    foreach ($conexionesActivas as $nombreSede => $db) {
        if (!$db) continue; 

        // Procesar Ventas (Requiere 4 parámetros string)
        if ($stmt = $db->prepare($sqlVentas)) {
            $stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $mesKey = substr($row['DIA'], 4, 2);
                $total  = (float)$row['TOTAL'];
                if (isset($datosMensuales[$mesKey])) {
                    $datosMensuales[$mesKey]['VENTAS'][$nombreSede] += $total;
                    $datosMensuales[$mesKey]['VENTAS']['CONSOLIDADO'] += $total;
                }
            }
            $stmt->close();
        }

        // Procesar Compras (Corregido: Solo requiere 2 parámetros string)
        if ($stmt = $db->prepare($sqlCompras)) {
            $stmt->bind_param('ss', $fechaInicio, $fechaFin);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $mesKey = substr($row['DIA'], 4, 2);
                $total  = (float)$row['TOTAL'];
                if (isset($datosMensuales[$mesKey])) {
                    $datosMensuales[$mesKey]['COMPRAS'][$nombreSede] += $total;
                    $datosMensuales[$mesKey]['COMPRAS']['CONSOLIDADO'] += $total;
                }
            }
            $stmt->close();
        }
    }

    // Preparar vectores de datos para JavaScript
    $labelsJS = [];
    $vCentral = []; $cCentral = [];
    $vDrinks  = []; $cDrinks  = [];
    $vTotal   = []; $cTotal   = [];

    foreach ($datosMensuales as $mKey => $valores) {
        $labelsJS[] = $valores['mes'];
        $vCentral[] = $valores['VENTAS']['CENTRAL'];
        $cCentral[] = $valores['COMPRAS']['CENTRAL'];
        $vDrinks[]  = $valores['VENTAS']['DRINKS'];
        $cDrinks[]  = $valores['COMPRAS']['DRINKS'];
        $vTotal[]   = $valores['VENTAS']['CONSOLIDADO'];
        $cTotal[]   = $valores['COMPRAS']['CONSOLIDADO'];
    }
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cruce de Ventas vs Compras</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; background:#f4f7f6; color: #333; padding: 10px; }
            
            .container { width: 100%; max-width: 1500px; margin: 0 auto; padding: 15px; }
            .card { background:#fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
            
            h2 { text-align: center; color: #1a237e; margin-bottom: 20px; font-size: 1.6rem; }
            
            .filter-container { display: flex; flex-wrap: wrap; justify-content: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: #e8eaf6; border-radius: 10px; align-items: center; }
            .filter-group { display: flex; align-items: center; gap: 10px; }
            label { font-size: 11px; font-weight: bold; color: #3f51b5; text-transform: uppercase; }
            select, input[type="submit"] { padding: 8px 12px; border-radius: 5px; border: 1px solid #ccc; font-size: 14px; width: 100%; max-width: 200px; }
            input[type="submit"] { background: #3f51b5; color: white; cursor: pointer; font-weight: bold; border: none; transition: background 0.2s; }
            input[type="submit"]:hover { background: #1a237e; }

            .interactive-box { background: #e1f5fe; border: 1px solid #b3e5fc; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
            .interactive-box select { color: #01579b; border: 1px solid #0288d1; background: #fff; max-width: 280px; font-weight: bold; }
            .info-nota { font-size: 11px; color: #546e7a; flex: 1; min-width: 250px; }

            .dashboard-layout { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
            @media (min-width: 1200px) { .dashboard-layout { grid-template-columns: 7fr 5fr; } }
            
            .table-responsive { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #e0e0e0; }
            table { border-collapse: collapse; width: 100%; font-size: 12px; background: white; min-width: 700px; }
            th, td { padding: 11px 12px; text-align: right; }
            th { background: #1a237e; color: white; text-align: center; font-weight: 600; }
            td { border-bottom: 1px solid #f0f0f0; font-weight: 500; }
            tr:hover td { background-color: #f5f5f5; }
            
            .total-row { background: #0d123f; color: white; font-weight: bold; font-size: 13px; }
            
            .chart-wrapper { background: #fff; border: 1px solid #e0e0e0; padding: 15px; border-radius: 8px; min-height: 440px; height: 100%; position: relative; width: 100%; }

            .txt-utilidad { color: #2e7d32; font-weight: bold; }
            .txt-deficit { color: #c62828; font-weight: bold; }
            .badge-v { display: inline-block; padding: 3px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
            .b-positivo { background: #e8f5e9; color: #2e7d32; }
            .b-negativo { background: #ffebee; color: #c62828; }
        </style>
    </head>
    <body>

    <div class="container">
        <div class="card">
            <h2>📊 Cruce Financiero: Ventas Mensuales vs Compras Realizadas</h2>

            <form method="post" class="filter-container">
                <div class="filter-group">
                    <label>Año Principal:</label>
                    <select name="anio">
                        <?php for($y=date('Y'); $y>=2024; $y--) echo "<option value='$y' ".($y==$anioSel?'selected':'').">$y</option>"; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Sucursal Filtro Base:</label>
                    <select name="sede">
                        <option value="todas" <?= $sedeSel=='todas'?'selected':'' ?>>Todas las Sedes</option>
                        <option value="central" <?= $sedeSel=='central'?'selected':'' ?>>Solo Central</option>
                        <option value="drinks" <?= $sedeSel=='drinks'?'selected':'' ?>>Solo Drinks</option>
                    </select>
                </div>
                <input type="submit" value="🔄 GENERAR CRUCE">
            </form>

            <div class="interactive-box">
                <select id="filtroPantallaSede" onchange="conmutarCruceDashboard()">
                    <option value="CONSOLIDADO">🏢 CONSOLIDADO GENERAL</option>
                    <option value="CENTRAL">🔹 SEDE CENTRAL</option>
                    <option value="DRINKS">🍹 SEDE DRINKS</option>
                </select>
                <div class="info-nota">* Margen de Flujo = Ventas Totales - Compras Brutas. Permite evaluar la liquidez y rotación mensual.</div>
            </div>

            <div class="dashboard-layout">
                
                <div class="table-responsive">
                    <table id="tablaCruceFinanciero">
                        <thead>
                            <tr>
                                <th style="text-align: left;">Mes Comercial</th>
                                <th>🟢 Total Ventas</th>
                                <th>🔴 Total Compras</th>
                                <th>Diferencia Flujo</th>
                                <th style="text-align: center;">Margen Bruto</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoTablaCruce"></tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td style="text-align: left;">RESUMEN ACUMULADO</td>
                                <td id="totVentas">$ 0</td>
                                <td id="totCompras">$ 0</td>
                                <td id="totDiferencia">$ 0</td>
                                <td id="totMargen" style="text-align: center;">0%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div>
                    <div class="chart-wrapper">
                        <canvas id="graficoCruceMensual"></canvas>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        Chart.register(ChartDataLabels);

        const anioActivo = '<?= $anioSel ?>';
        const mesesEjeX = <?= json_encode($labelsJS) ?>;
        
        const vC = <?= json_encode($vCentral) ?>; const cC = <?= json_encode($cCentral) ?>;
        const vD = <?= json_encode($vDrinks) ?>;  const cD = <?= json_encode($cDrinks) ?>;
        const vT = <?= json_encode($vTotal) ?>;   const cT = <?= json_encode($cTotal) ?>;

        let objGrafico = null;

        document.addEventListener("DOMContentLoaded", function() {
            conmutarCruceDashboard();
        });

        function conmutarCruceDashboard() {
            const sede = document.getElementById("filtroPantallaSede").value;
            
            let ventasActivas = []; let comprasActivas = [];

            if (sede === 'CENTRAL') {
                ventasActivas = vC; comprasActivas = cC;
            } else if (sede === 'DRINKS') {
                ventasActivas = vD; comprasActivas = cD;
            } else {
                ventasActivas = vT; comprasActivas = cT;
            }

            // 1. CONSTRUIR EN DOM LA TABLA DE COMPARATIVAS
            const tbody = document.getElementById("cuerpoTablaCruce");
            tbody.innerHTML = "";
            
            let totalV = 0; let totalC = 0;

            mesesEjeX.forEach((mes, i) => {
                const vMes = ventasActivas[i];
                const cMes = comprasActivas[i];
                
                totalV += vMes;
                totalC += cMes;

                const difMes = vMes - cMes;
                let pctMargen = 0;
                if (vMes > 0) pctMargen = (difMes / vMes) * 100;

                const claseEstilo = difMes >= 0 ? "txt-utilidad" : "txt-deficit";
                const claseBadge  = difMes >= 0 ? "badge-v b-positivo" : "badge-v b-negativo";

                let fila = `<tr>
                    <td style="text-align: left; font-weight: bold; color: #1a237e;">${mes}</td>
                    <td style="color: #2e7d32; font-weight: 600;">$ ${vMes.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td style="color: #c62828;">$ ${cMes.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td class="${claseEstilo}">$ ${difMes.toLocaleString('es-CO', {maximumFractionDigits:0})}</td>
                    <td style="text-align: center;"><span class="${claseBadge}">${pctMargen.toFixed(1)}%</span></td>
                </tr>`;
                tbody.innerHTML += fila;
            });

            // Asignación de totales en el Footer
            const totalDif = totalV - totalC;
            const totalPct = totalV > 0 ? (totalDif / totalV) * 100 : 0;
            
            document.getElementById("totVentas").innerText = "$ " + totalV.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("totCompras").innerText = "$ " + totalC.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("totDiferencia").innerText = "$ " + totalDif.toLocaleString('es-CO', {maximumFractionDigits:0});
            document.getElementById("totDiferencia").className = totalDif >= 0 ? "txt-utilidad" : "txt-deficit";
            document.getElementById("totMargen").innerHTML = `<span class="badge-v ${totalDif >= 0 ? 'b-positivo' : 'b-negativo'}">${totalPct.toFixed(1)}%</span>`;

            // 2. RENDERIZACIÓN DE GRÁFICA MIXTA
            const ctx = document.getElementById('graficoCruceMensual').getContext('2d');
            if (objGrafico) objGrafico.destroy();

            const dataDiferencia = ventasActivas.map((v, idx) => v - comprasActivas[idx]);

            objGrafico = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: mesesEjeX,
                    datasets: [
                        {
                            type: 'line',
                            label: 'Flujo Neto ($)',
                            data: dataDiferencia,
                            borderColor: '#ff9800',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            pointBackgroundColor: '#fff',
                            pointRadius: 4,
                            yAxisID: 'y',
                            datalabels: { display: false }
                        },
                        {
                            label: 'Ventas (Ingresos)',
                            data: ventasActivas,
                            backgroundColor: 'rgba(46, 125, 50, 0.85)',
                            borderColor: '#2e7d32',
                            borderWidth: 1,
                            borderRadius: 4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Compras (Egresos)',
                            data: comprasActivas,
                            backgroundColor: 'rgba(198, 40, 40, 0.85)',
                            borderColor: '#c62828',
                            borderWidth: 1,
                            borderRadius: 4,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 35 } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f1f1' },
                            ticks: { 
                                callback: function(val) { 
                                    return '$' + (val / 1000000).toLocaleString('es-CO') + 'M'; 
                                } 
                            }
                        }
                    },
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.dataset.label + ': $' + context.parsed.y.toLocaleString('es-CO');
                                }
                            }
                        },
                        datalabels: {
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            font: { size: 9, weight: 'bold' },
                            formatter: function(value, ctx) {
                                if (value === 0) return null;
                                if (ctx.dataset.type === 'line') return null;
                                return '$' + (value / 1000000).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + 'M';
                            },
                            color: '#2d3748'
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
?>