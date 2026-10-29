<?php
// Aseguramos que PHP use estrictamente la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

// ==========================================
// SELECCIÓN DE SEDE Y RANGOS DE FECHA
// ==========================================
$sede = isset($_POST['sede']) ? $_POST['sede'] : 'central';

// Capturar el rango de fechas (por defecto la fecha actual de Bogotá para ambos)
$fecha_desde_input = isset($_POST['fecha_desde']) ? $_POST['fecha_desde'] : date('Y-m-d');
$fecha_hasta_input = isset($_POST['fecha_hasta']) ? $_POST['fecha_hasta'] : date('Y-m-d');

$fecha_desde_db = str_replace('-', '', $fecha_desde_input); // Convierte '2026-01-01' a '20260101'
$fecha_hasta_db = str_replace('-', '', $fecha_hasta_input); // Convierte '2026-10-24' a '20261024'

// Cálculo de la cantidad de días en el rango seleccionado
$dt_desde = new DateTime($fecha_desde_input);
$dt_hasta = new DateTime($fecha_hasta_input);
$dias_diferencia = $dt_desde->diff($dt_hasta)->days + 1;

// Variables para los totales generales
$total_ingresos = 0;
$total_costos = 0;
$total_utilidad = 0;
$resultados = [];

// Consulta SQL optimizada limpia de tablas o subconsultas de inventario
$sql = "
SELECT 
    P.barcode,
    P.descripcion,
    SUM(V.CANTIDAD) AS CANTIDAD_TOTAL,
    AVG(V.PRECIO_UNITARIO) AS PRECIO_PROMEDIO,
    IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo) AS COSTO_PROMEDIO,
    SUM(V.CANTIDAD * V.PRECIO_UNITARIO) AS TOTAL_VENTA,
    SUM(V.CANTIDAD * IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo)) AS TOTAL_COSTO,
    SUM(V.CANTIDAD * (V.PRECIO_UNITARIO - IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo))) AS UTILIDAD_TOTAL,
    CASE WHEN SUM(V.CANTIDAD * V.PRECIO_UNITARIO) > 0 
         THEN ROUND((SUM(V.CANTIDAD * (V.PRECIO_UNITARIO - IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo))) / SUM(V.CANTIDAD * V.PRECIO_UNITARIO)) * 100, 2) 
         ELSE 0 
    END AS MARGEN_PORCENTAJE
FROM (
    -- 1. FACTURAS VÁLIDAS
    SELECT 
        DF.IDPRODUCTO,
        DF.CANTIDAD,
        DF.VALORPROD AS PRECIO_UNITARIO
    FROM FACTURAS F
    INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
    WHERE F.ESTADO = '0'
      AND F.FECHA BETWEEN '$fecha_desde_db' AND '$fecha_hasta_db'
      AND DV.IDFACTURA IS NULL

    UNION ALL

    -- 2. PEDIDOS VÁLIDOS
    SELECT 
        DP.IDPRODUCTO,
        DP.CANTIDAD,
        DP.VALORPROD AS PRECIO_UNITARIO
    FROM PEDIDOS P
    INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
    WHERE P.ESTADO = '0'
      AND P.FECHA BETWEEN '$fecha_desde_db' AND '$fecha_hasta_db'
) AS V
INNER JOIN PRODUCTOS P ON P.IDPRODUCTO = V.IDPRODUCTO
-- Subconsulta para calcular el costo promedio ponderado real con impuestos de las últimas 3 compras
LEFT JOIN (
    SELECT 
        d.idproducto,
        SUM(d.cantidad * ( (d.valor - (d.descuento / NULLIF(d.cantidad, 0))) * (1 + d.porciva/100) + d.ValICUIUni )) / NULLIF(SUM(d.cantidad), 0) AS COSTO_PROMEDIO_COMPRA
    FROM detcompras d
    INNER JOIN compras c ON c.idcompra = d.idcompra AND c.estado = '0'
    WHERE (
        SELECT COUNT(DISTINCT c2.fecha, c2.idcompra) 
        FROM detcompras d2 
        INNER JOIN compras c2 ON c2.idcompra = d2.idcompra AND c2.estado = '0'
        WHERE d2.idproducto = d.idproducto 
          AND (c2.fecha > c.fecha OR (c2.fecha = c.fecha AND c2.idcompra >= c.idcompra))
    ) <= 3
    GROUP BY d.idproducto
) CP ON CP.idproducto = P.IDPRODUCTO
GROUP BY P.idproducto, P.barcode, P.descripcion, CP.COSTO_PROMEDIO_COMPRA, P.costo
";

// Lógica según la sede seleccionada
if ($sede == 'ambas') {
    $datos_combinados = [];

    // 1. Consultar Central
    if (file_exists('ConnCentral.php')) {
        include 'ConnCentral.php';
        if (isset($mysqliPos)) {
            $res = $mysqliPos->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $bc = $row['barcode'];
                    $row['CANTIDAD_CENTRAL'] = floatval($row['CANTIDAD_TOTAL']);
                    $row['CANTIDAD_DRINKS'] = 0;
                    $datos_combinados[$bc] = $row;
                }
            }
            $mysqliPos->close();
        }
    }

    // 2. Consultar Drinks
    if (file_exists('ConnDrinks.php')) {
        unset($mysqliPos);
        include 'ConnDrinks.php';
        if (isset($mysqliPos)) {
            $res = $mysqliPos->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $bc = $row['barcode'];
                    $cant2 = floatval($row['CANTIDAD_TOTAL']);

                    if (isset($datos_combinados[$bc])) {
                        $cant1 = $datos_combinados[$bc]['CANTIDAD_TOTAL'];
                        $cant_total = $cant1 + $cant2;

                        $venta1 = $datos_combinados[$bc]['TOTAL_VENTA'];
                        $venta2 = $row['TOTAL_VENTA'];
                        $venta_total = $venta1 + $venta2;

                        $costo1 = $datos_combinados[$bc]['TOTAL_COSTO'];
                        $costo2 = $row['TOTAL_COSTO'];
                        $costo_total = $costo1 + $costo2;

                        $utilidad_total = $venta_total - $costo_total;
                        $margen = ($venta_total > 0) ? round(($utilidad_total / $venta_total) * 100, 2) : 0;
                        $costo_promedio = ($cant_total > 0) ? ($costo_total / $cant_total) : $datos_combinados[$bc]['COSTO_PROMEDIO'];

                        $datos_combinados[$bc]['CANTIDAD_TOTAL'] = $cant_total;
                        $datos_combinados[$bc]['CANTIDAD_DRINKS'] = $cant2;
                        $datos_combinados[$bc]['TOTAL_VENTA'] = $venta_total;
                        $datos_combinados[$bc]['TOTAL_COSTO'] = $costo_total;
                        $datos_combinados[$bc]['UTILIDAD_TOTAL'] = $utilidad_total;
                        $datos_combinados[$bc]['PRECIO_PROMEDIO'] = ($cant_total > 0) ? ($venta_total / $cant_total) : 0;
                        $datos_combinados[$bc]['COSTO_PROMEDIO'] = $costo_promedio;
                        $datos_combinados[$bc]['MARGEN_PORCENTAJE'] = $margen;
                    } else {
                        $row['CANTIDAD_CENTRAL'] = 0;
                        $row['CANTIDAD_DRINKS'] = $cant2;
                        $datos_combinados[$bc] = $row;
                    }
                }
            }
            $mysqliPos->close();
        }
    }

    ksort($datos_combinados);
    $resultados = array_values($datos_combinados);

    foreach ($resultados as $row) {
        $total_ingresos += $row['TOTAL_VENTA'];
        $total_costos += $row['TOTAL_COSTO'];
        $total_utilidad += $row['UTILIDAD_TOTAL'];
    }

} else {
    if ($sede == 'drinks') {
        include_once 'ConnDrinks.php';
    } else {
        include_once 'ConnCentral.php'; 
    }

    $sql_ordenado = $sql . " ORDER BY barcode ASC;";

    if (isset($mysqliPos)) {
        $res = $mysqliPos->query($sql_ordenado);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if ($sede == 'drinks') {
                    $row['CANTIDAD_CENTRAL'] = 0;
                    $row['CANTIDAD_DRINKS'] = floatval($row['CANTIDAD_TOTAL']);
                } else {
                    $row['CANTIDAD_CENTRAL'] = floatval($row['CANTIDAD_TOTAL']);
                    $row['CANTIDAD_DRINKS'] = 0;
                }
                $resultados[] = $row;
                $total_ingresos += $row['TOTAL_VENTA'];
                $total_costos += $row['TOTAL_COSTO'];
                $total_utilidad += $row['UTILIDAD_TOTAL'];
            }
        }
    }
}

// Ordenar resultados de mayor a menor cantidad vendida para preparar el Top 15
usort($resultados, function($a, $b) {
    return $b['CANTIDAD_TOTAL'] <=> $a['CANTIDAD_TOTAL'];
});

$margen_global = ($total_ingresos > 0) ? round(($total_utilidad / $total_ingresos) * 100, 2) : 0;
$utilidad_promedio_dia = ($dias_diferencia > 0) ? ($total_utilidad / $dias_diferencia) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Utilidades y Ventas</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Chart.js DataLabels Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
    <style>
        body {
            width: 100%;
            overflow-x: hidden;
            background-color: #f4f6f9;
        }
        .container-fluid {
            width: 100% !important;
            padding-left: 15px !important;
            padding-right: 15px !important;
        }
        .table-responsive-sticky {
            max-height: 72vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-responsive-sticky thead th {
            position: sticky;
            top: 0;
            z-index: 1020;
            background-color: #212529 !important;
            color: #fff;
            white-space: nowrap;
        }
        th.sortable {
            cursor: pointer;
            user-select: none;
        }
        th.sortable:hover {
            background-color: #343a40 !important;
        }
        th.sortable::after {
            content: ' ↕';
            font-size: 0.8em;
            color: #aaa;
        }
        th.sortable.asc::after {
            content: ' ▲';
            color: #fff;
        }
        th.sortable.desc::after {
            content: ' ▼';
            color: #fff;
        }

        /* Contenedor Responsivo Adaptable para Gráfica */
        .chart-container {
            position: relative;
            width: 100%;
            min-height: 400px;
            max-height: 520px;
            height: 48vh;
        }
    </style>
</head>
<body>

<div class="container-fluid my-3">
    <h2 class="mb-3 text-center fw-bold">📊 Reporte de Utilidades y Ventas</h2>

    <!-- Formulario 100% Ancho -->
    <div class="card shadow-sm mb-3 border-0">
        <div class="card-body bg-white rounded shadow-sm">
            <form method="POST" class="row g-2 align-items-center justify-content-center">
                <div class="col-12 col-md-auto">
                    <label for="sede" class="form-label fw-bold mb-0">Sede:</label>
                </div>
                <div class="col-12 col-md-3">
                    <select name="sede" id="sede" class="form-select">
                        <option value="central" <?php echo ($sede == 'central') ? 'selected' : ''; ?>>Distribuidora Central</option>
                        <option value="drinks" <?php echo ($sede == 'drinks') ? 'selected' : ''; ?>>Drinks Depot S.A.S.</option>
                        <option value="ambas" <?php echo ($sede == 'ambas') ? 'selected' : ''; ?>>🏢 Ambas Sedes (Consolidado)</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label for="fecha_desde" class="form-label fw-bold mb-0">Desde:</label>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" value="<?php echo $fecha_desde_input; ?>">
                </div>
                <div class="col-6 col-md-auto">
                    <label for="fecha_hasta" class="form-label fw-bold mb-0">Hasta:</label>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control" value="<?php echo $fecha_hasta_input; ?>">
                </div>
                <div class="col-12 col-md-auto text-center">
                    <button type="submit" class="btn btn-primary w-100 px-4">Consultar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tarjetas de Resumen (KPIs) -->
    <div class="row text-center mb-3 g-2">
        <div class="col-6 col-xl">
            <div class="card shadow-sm border-primary h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4">
                <div class="card-body p-2">
                    <h6 class="card-title text-muted fs-6 mb-1">Ventas Totales</h6>
                    <h4 class="text-primary fs-5 fw-bold mb-0">$<?php echo number_format($total_ingresos, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="card shadow-sm border-danger h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4">
                <div class="card-body p-2">
                    <h6 class="card-title text-muted fs-6 mb-1">Costo Total</h6>
                    <h4 class="text-danger fs-5 fw-bold mb-0">$<?php echo number_format($total_costos, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="card shadow-sm border-success h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4">
                <div class="card-body p-2">
                    <h6 class="card-title text-muted fs-6 mb-1">Utilidad Neta</h6>
                    <h4 class="<?php echo ($total_utilidad >= 0) ? 'text-success' : 'text-danger'; ?> fs-5 fw-bold mb-0">$<?php echo number_format($total_utilidad, 0, ',', '.'); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="card shadow-sm border-info h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4">
                <div class="card-body p-2">
                    <h6 class="card-title text-muted fs-6 mb-1">% Utilidad (Período)</h6>
                    <h4 class="<?php echo ($margen_global >= 0) ? 'text-info' : 'text-danger'; ?> fs-5 fw-bold mb-0"><?php echo $margen_global; ?>%</h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="card shadow-sm border-warning h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4">
                <div class="card-body p-2">
                    <h6 class="card-title text-muted fs-6 mb-1">Utilidad Prom. / Día</h6>
                    <h4 class="<?php echo ($utilidad_promedio_dia >= 0) ? 'text-success' : 'text-danger'; ?> fs-5 fw-bold mb-0">$<?php echo number_format($utilidad_promedio_dia, 0, ',', '.'); ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN DE GRÁFICA APILADA RESPONSIVA: Top 15 Productos -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-dark text-white text-uppercase py-2 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fs-6">🏆 Top 15 Productos - Ventas Apiladas por Sede</h5>
        </div>
        <div class="card-body bg-white">
            <div class="chart-container">
                <canvas id="graficoTopProductos"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabla Agrupada por Producto 100% Ancho -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white text-uppercase py-2 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <h5 class="mb-0 fs-6">
                Resumen Consolidado de Ventas 
                (Sede: <?php 
                    if($sede == 'drinks') echo 'Drinks Depot S.A.S.';
                    elseif($sede == 'ambas') echo 'Ambas Sedes (Consolidado)';
                    else echo 'Distribuidora Central'; 
                ?> | Desde: <?php echo $fecha_desde_input; ?> Hasta: <?php echo $fecha_hasta_input; ?> | Total días: <?php echo $dias_diferencia; ?>)
            </h5>
            <!-- Input de Filtro Dinámico -->
            <div style="width: 300px;" class="w-100 w-md-auto">
                <input type="text" id="filtroProducto" class="form-control form-control-sm" placeholder="🔍 Buscar por nombre o barcode...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-responsive-sticky">
                <table class="table table-striped table-hover align-middle mb-0 w-100" id="tablaUtilidades">
                    <thead class="table-dark">
                        <tr>
                            <th class="sortable" data-type="text">Barcode</th>
                            <th class="sortable" data-type="text">Producto</th>
                            <th class="sortable text-end" data-type="number">Costo Prom.</th>
                            <th class="sortable text-end" data-type="number">P. Venta Prom.</th>
                            <th class="sortable text-end" data-type="number">Utilidad Unit.</th>
                            <th class="sortable text-end" data-type="number">Cant. Vendida</th>
                            <th class="sortable text-end" data-type="number">Total Venta</th>
                            <th class="sortable text-end" data-type="number">Utilidad Total</th>
                            <th class="sortable text-end" data-type="number">Margen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($resultados) > 0): ?>
                            <?php foreach ($resultados as $row): 
                                $utilidad_unitaria = $row['PRECIO_PROMEDIO'] - $row['COSTO_PROMEDIO'];
                            ?>
                                <tr>
                                    <td><code><?php echo $row['barcode']; ?></code></td>
                                    <td><?php echo $row['descripcion']; ?></td>
                                    <td class="text-end text-muted">$<?php echo number_format($row['COSTO_PROMEDIO'], 2); ?></td>
                                    <td class="text-end">$<?php echo number_format($row['PRECIO_PROMEDIO'], 2); ?></td>
                                    <td class="text-end fw-bold <?php echo ($utilidad_unitaria >= 0) ? 'text-success' : 'text-danger'; ?>">
                                        $<?php echo number_format($utilidad_unitaria, 0, ',', '.'); ?>
                                    </td>
                                    <td class="text-end fw-bold"><?php echo number_format($row['CANTIDAD_TOTAL'], 2); ?></td>
                                    <td class="text-end fw-bold">$<?php echo number_format($row['TOTAL_VENTA'], 2); ?></td>
                                    <td class="text-end fw-bold <?php echo ($row['UTILIDAD_TOTAL'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                        $<?php echo number_format($row['UTILIDAD_TOTAL'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge <?php echo ($row['MARGEN_PORCENTAJE'] >= 0) ? 'bg-info text-dark' : 'bg-danger text-white'; ?>">
                                            <?php echo $row['MARGEN_PORCENTAJE']; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">No se encontraron ventas ni pedidos registrados para el rango de fechas seleccionado.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Extraer los datos para enviar a JavaScript (Top 15)
$top_15 = array_slice($resultados, 0, 15);
$labels_chart = [];
$data_central = [];
$data_drinks = [];

foreach ($top_15 as $item) {
    $labels_chart[] = mb_strimwidth($item['descripcion'], 0, 35, "...");
    $data_central[] = floatval($item['CANTIDAD_CENTRAL']);
    $data_drinks[] = floatval($item['CANTIDAD_DRINKS']);
}
?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Registrar el plugin de DataLabels
    Chart.register(ChartDataLabels);

    const modoSede = '<?php echo $sede; ?>';
    const datasetsConfig = [];

    // Configuración de colores para barras apiladas (Stacked)
    if (modoSede === 'ambas') {
        datasetsConfig.push({
            label: 'Distribuidora Central',
            data: <?php echo json_encode($data_central); ?>,
            backgroundColor: 'rgba(13, 110, 253, 0.85)', // Azul
            borderColor: 'rgba(13, 110, 253, 1)',
            borderWidth: 1,
            borderRadius: 2
        });
        datasetsConfig.push({
            label: 'Drinks Depot S.A.S.',
            data: <?php echo json_encode($data_drinks); ?>,
            backgroundColor: 'rgba(25, 135, 84, 0.85)', // Verde
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1,
            borderRadius: 2
        });
    } else if (modoSede === 'drinks') {
        datasetsConfig.push({
            label: 'Drinks Depot S.A.S.',
            data: <?php echo json_encode($data_drinks); ?>,
            backgroundColor: 'rgba(25, 135, 84, 0.85)',
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1,
            borderRadius: 4
        });
    } else {
        datasetsConfig.push({
            label: 'Distribuidora Central',
            data: <?php echo json_encode($data_central); ?>,
            backgroundColor: 'rgba(13, 110, 253, 0.85)',
            borderColor: 'rgba(13, 110, 253, 1)',
            borderWidth: 1,
            borderRadius: 4
        });
    }

    // 0. Renderizar Gráfica Apilada con Chart.js
    const ctx = document.getElementById('graficoTopProductos').getContext('2d');
    const graficoTopProductos = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($labels_chart); ?>,
            datasets: datasetsConfig
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 25,
                    left: 5,
                    right: 5,
                    bottom: 0
                }
            },
            plugins: {
                legend: {
                    display: (modoSede === 'ambas'),
                    position: 'top',
                    labels: {
                        font: {
                            weight: 'bold',
                            size: 12
                        }
                    }
                },
                datalabels: {
                    display: function(context) {
                        return context.dataset.data[context.dataIndex] > 0; // Solo muestra etiqueta si hay valor mayor a 0
                    },
                    color: '#fff', // Texto blanco sobre las secciones de color para mayor contraste
                    font: {
                        weight: 'bold',
                        size: 10
                    },
                    formatter: function(value) {
                        return value.toLocaleString();
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.dataset.label + ': ' + context.raw.toLocaleString() + ' un.';
                        }
                    }
                }
            },
            scales: {
                y: {
                    stacked: true, // Habilita el apilamiento en el eje Y
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    stacked: true, // Habilita el apilamiento en el eje X
                    ticks: {
                        font: {
                            size: 11,
                            weight: '500'
                        },
                        color: '#212529',
                        maxRotation: 45,
                        minRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 15
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    const table = document.getElementById("tablaUtilidades");
    if (!table) return;

    // 1. Filtro dinámico por nombre o barcode
    const inputFiltro = document.getElementById("filtroProducto");
    if (inputFiltro) {
        inputFiltro.addEventListener("input", function() {
            const filtro = this.value.toLowerCase().trim();
            const rows = table.querySelectorAll("tbody tr");

            rows.forEach(row => {
                if (row.cells.length < 2) return;

                const barcodeCell = row.children[0];
                const productoCell = row.children[1];

                if (barcodeCell && productoCell) {
                    const barcode = barcodeCell.textContent.toLowerCase();
                    const producto = productoCell.textContent.toLowerCase();

                    if (barcode.includes(filtro) || producto.includes(filtro)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                }
            });
        });
    }

    // 2. Ordenamiento por Columnas
    const headers = table.querySelectorAll("th.sortable");

    headers.forEach((th, index) => {
        th.addEventListener("click", () => {
            const tbody = table.querySelector("tbody");
            const rows = Array.from(tbody.querySelectorAll("tr"));
            const isAscending = th.classList.contains("asc");

            headers.forEach(h => h.classList.remove("asc", "desc"));

            if (isAscending) {
                th.classList.add("desc");
            } else {
                th.classList.add("asc");
            }

            const dataType = th.getAttribute("data-type");

            rows.sort((a, b) => {
                if (!a.children[index] || !b.children[index]) return 0;

                const cellA = a.children[index].textContent.trim();
                const cellB = b.children[index].textContent.trim();

                if (dataType === "number") {
                    const numA = parseFloat(cellA.replace(/[^0-9.-]+/g, "")) || 0;
                    const numB = parseFloat(cellB.replace(/[^0-9.-]+/g, "")) || 0;
                    return isAscending ? numB - numA : numA - numB;
                } else {
                    return isAscending ? cellB.localeCompare(cellA) : cellA.localeCompare(cellB);
                }
            });

            rows.forEach(row => tbody.appendChild(row));
        });
    });
});
</script>

</body>
</html>