<?php
// 1. CONFIGURACIÓN Y CONEXIONES
date_default_timezone_set('America/Bogota');

require('ConnCentral.php'); // Debe definir $mysqliCentral
require('ConnDrinks.php');  // Debe definir $mysqliDrinks

$anioSel = $_POST['anio'] ?? date('Y');
$sedeSel = $_POST['sede'] ?? 'todas'; 

ResumenAnualSedes($anioSel, $sedeSel);

function ResumenAnualSedes($anio, $sedeSel) {
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
            'CENTRAL' => 0,
            'DRINKS' => 0,
            'CONSOLIDADO' => 0
        ];
    }

    $fechaInicio = $anio . "0101"; 
    $fechaFin    = $anio . "1231";

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
            $stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $mesKey = substr($row['DIA'], 4, 2);
                $total = (float)$row['TOTAL'];
                
                if (isset($datosMensuales[$mesKey])) {
                    $datosMensuales[$mesKey][$nombreSede] += $total;
                    $datosMensuales[$mesKey]['CONSOLIDADO'] += $total;
                }
            }
            $stmt->close();
        }
    }

    $labelsJS = [];
    $centralJS = [];
    $drinksJS = [];
    $consolidadoJS = [];

    foreach ($datosMensuales as $mKey => $valores) {
        $labelsJS[] = $valores['mes'];
        $centralJS[] = $valores['CENTRAL'];
        $drinksJS[] = $valores['DRINKS'];
        $consolidadoJS[] = $valores['CONSOLIDADO'];
    }
    ?>

    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Dashboard de Ventas Mensuales</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
        <style>
            body { font-family: 'Segoe UI', sans-serif; margin:20px; background:#f4f7f6; color: #333; }
            .card { background:#fff; padding:25px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.05); max-width: 1200px; margin: 0 auto; }
            .filter-container { display: flex; justify-content: center; flex-wrap: wrap; gap: 15px; margin-bottom: 25px; padding: 15px; background: #e8eaf6; border-radius: 10px; align-items: center; }
            .interactive-box { background: #e0f2f1; border: 1px solid #b2dfdb; padding: 12px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
            .interactive-box select { padding: 8px 15px; font-size: 14px; font-weight: bold; color: #004d40; border: 1px solid #00796b; border-radius: 6px; outline: none; background: #fff;}
            select, input[type="submit"] { padding:8px 12px; border-radius:5px; border:1px solid #ccc; font-size: 14px; }
            input[type="submit"] { background:#3f51b5; color:white; cursor:pointer; font-weight:bold; border: none; }
            .dashboard-layout { display: grid; grid-template-columns: 4fr 8fr; gap: 25px; margin-top: 20px; }
            @media (max-width: 900px) { .dashboard-layout { grid-template-columns: 1fr; } }
            table { border-collapse:collapse; width:100%; font-size:13px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            th, td { padding:12px 15px; text-align:right; }
            th { background:#283593; color:white; text-align:center; font-weight: 600; }
            td { border-bottom: 1px solid #f0f0f0; font-weight: 500; }
            .total-row { background:#1a237e; color:white; font-weight:bold; font-size: 14px; }
            .chart-wrapper { background:#fff; border:1px solid #e0e0e0; padding:20px 15px 15px 15px; border-radius:8px; min-height: 400px; position: relative; }
            label { font-size: 12px; font-weight: bold; color: #5c6bc0; text-transform: uppercase; }
        </style>
    </head>
    <body>

    <div class="card">
        <h2 style="text-align:center; color:#283593; margin-top: 0;">📊 Valorización Mensual de Ventas</h2>

        <form method="post" class="filter-container">
            <div>
                <label>Año de Consulta:</label>
                <select name="anio">
                    <?php for($y=date('Y'); $y>=2024; $y--) echo "<option value='$y' ".($y==$anioSel?'selected':'').">$y</option>"; ?>
                </select>
            </div>
            <input type="submit" value="🔄 CARGAR AÑO">
        </form>

        <div class="interactive-box">
            <span style="font-weight: bold; color: #004d40; font-size: 14px;">📍 Filtrar Vista de Sede:</span>
            <select id="filtroPantallaSede" onchange="conmutarSedeDashboard()">
                <option value="CONSOLIDADO">🏢 VER CONSOLIDADO EMPRESA</option>
                <option value="CENTRAL">🔹 SEDE CENTRAL</option>
                <option value="DRINKS">🍹 SEDE DRINKS</option>
            </select>
            <span style="font-size: 11px; color: #546e7a;">*Valores sobre las barras expresados automáticamente en miles (K).</span>
        </div>

        <div class="dashboard-layout">
            
            <div>
                <table id="tablaMensualizada">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Mes / Período</th>
                            <th>Total Valorizado</th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTablaMensual">
                        </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td style="text-align: left;">TOTAL ACUMULADO</td>
                            <td id="txtCeldaTotal">$ 0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="chart-wrapper">
                <canvas id="graficoMensualVentas"></canvas>
            </div>

        </div>
    </div>

    <script>
        // Registrar globalmente el plugin de etiquetas de datos para Chart.js
        Chart.register(ChartDataLabels);

        const mesesEjeX = <?= json_encode($labelsJS) ?>;
        const dataSedeCentral = <?= json_encode($centralJS) ?>;
        const dataSedeDrinks = <?= json_encode($drinksJS) ?>;
        const dataEmpresaTotal = <?= json_encode($consolidadoJS) ?>;

        let objGrafico = null;

        document.addEventListener("DOMContentLoaded", function() {
            conmutarSedeDashboard();
        });

        function conmutarSedeDashboard() {
            const sedeSeleccionada = document.getElementById("filtroPantallaSede").value;
            
            let datasetActivo = [];
            let colorGrafico = '';
            let nombreLeyenda = '';

            if (sedeSeleccionada === 'CENTRAL') {
                datasetActivo = dataSedeCentral;
                colorGrafico = '#1e88e5'; 
                nombreLeyenda = 'Ventas Sede CENTRAL';
            } else if (sedeSeleccionada === 'DRINKS') {
                datasetActivo = dataSedeDrinks;
                colorGrafico = '#43a047'; 
                nombreLeyenda = 'Ventas Sede DRINKS';
            } else {
                datasetActivo = dataEmpresaTotal;
                colorGrafico = '#ff9800'; 
                nombreLeyenda = 'Consolidado General';
            }

            // 1. INYECTAR DATOS EN TABLA (Muestra valor completo real)
            const tbody = document.getElementById("cuerpoTablaMensual");
            tbody.innerHTML = "";
            let sumatoriaAnual = 0;

            mesesEjeX.forEach((mes, index) => {
                const valorMes = datasetActivo[index];
                sumatoriaAnual += valorMes;

                let fila = `<tr>
                    <td style="text-align: left; font-weight: bold; color: #3f51b5;">${mes}</td>
                    <td style="font-weight: bold; font-size: 14px; color: #212121;">$ ${valorMes.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}</td>
                </tr>`;
                tbody.innerHTML += fila;
            });

            document.getElementById("txtCeldaTotal").innerText = "$ " + sumatoriaAnual.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

            // 2. GENERAR GRÁFICA CON VALORES EN MILES (K) SOBRE LAS BARRAS
            const ctx = document.getElementById('graficoMensualVentas').getContext('2d');
            
            if (objGrafico) {
                objGrafico.destroy();
            }

            objGrafico = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: mesesEjeX,
                    datasets: [{
                        label: nombreLeyenda,
                        data: datasetActivo,
                        backgroundColor: colorGrafico,
                        borderColor: colorGrafico,
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 25 // Espacio superior para que el texto no se corte
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(val) { return '$' + (val / 1000).toLocaleString('es-CO') + 'K'; }
                            }
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
                        // Configuración del plugin nativo de etiquetas de datos
                        datalabels: {
                            anchor: 'end',
                            align: 'top',
                            backgroundColor: '#263238',
                            color: '#ffffff',
                            borderRadius: 4,
                            padding: 4,
                            font: {
                                weight: 'bold',
                                size: 10
                            },
                            formatter: function(value) {
                                if (value === 0) return null; // No dibuja etiquetas si el mes está en 0
                                // Divide entre 1,000, redondea a un decimal y añade la K
                                let enMiles = value / 1000000;
                                return '$' + enMiles.toLocaleString('es-CO', { maximumFractionDigits: 1 }) + 'K';
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