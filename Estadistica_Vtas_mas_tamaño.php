<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Conexiones
require("ConnCentral.php"); // Sede Central
require("ConnDrinks.php");  // Sede Drinks (mysqliPos)
require("Conexion.php");    // DB Administrativa (mysqli)

$anioActual = date('Y');
$catFiltro = $_GET['codcat'] ?? ''; 

/* =====================================================
    1. FUNCIONES DE EXTRACCIÓN DE DATOS
===================================================== */

// Obtener categorías ordenadas numéricamente por código
function obtenerCategorias($dbWeb) {
    $cats = [];
    $sql = "SELECT codcat, nombre FROM categorias ORDER BY CAST(codcat AS UNSIGNED) ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $cats[$row['codcat']] = $row['nombre'];
    }
    return $cats;
}

// Obtener SKUs vinculados a la categoría
function obtenerSkusPorCategoria($dbWeb, $codcat) {
    $skus = [];
    if(empty($codcat)) return $skus;
    $sql = "SELECT sku FROM catproductos WHERE codcat = '$codcat'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $skus[] = "'".trim($row['sku'])."'";
    }
    return $skus;
}

// Obtener Stock Real sumando todos los almacenes de la sede
function obtenerStockReal($db, $listaSkus) {
    if(empty($listaSkus)) return 0;
    $inClause = implode(",", $listaSkus);
    $sql = "SELECT SUM(I.cantidad) as stock_total
            FROM inventario I
            INNER JOIN productos P ON I.idproducto = P.idproducto
            WHERE P.barcode IN ($inClause) AND I.idproducto > 0";
    $r = $db->query($sql);
    $row = $r->fetch_assoc();
    return (float)($row['stock_total'] ?? 0);
}

// Obtener Ventas (Facturas) + Preventas (Pedidos) mes a mes
function obtenerMovimientoMensual($db, $anio, $listaSkus) {
    $meses = array_fill_keys(["01","02","03","04","05","06","07","08","09","10","11","12"], 0);
    if(empty($listaSkus)) return $meses;
    $inClause = implode(",", $listaSkus);

    // 1. Sumar Facturas Válidas (Estado 0 y sin devoluciones)
    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL 
                 AND F.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rf = $db->query($sqlFac);
    while($rf && $row = $rf->fetch_assoc()) {
        $meses[$row['mes']] += (float)$row['cant'];
    }

    // 2. Sumar Pedidos Activos (Estado 0)
    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()) {
        $meses[$row['mes']] += (float)$row['cant'];
    }

    return $meses;
}

// Calcular Promedio y Días de Inventario
function calcularMetricas($data, $stock) {
    $suma = 0; $mesesActivos = 0;
    foreach($data as $v) { 
        if($v > 0) { $suma += $v; $mesesActivos++; } 
    }
    $promedio = ($mesesActivos > 0) ? ($suma / $mesesActivos) : 0;
    $ventaDiaria = $promedio / 30;
    $diasInventario = ($ventaDiaria > 0) ? ($stock / $ventaDiaria) : 0;
    
    return [
        'promedio' => $promedio, 
        'dias' => $diasInventario, 
        'total' => $suma
    ];
}

/* =====================================================
    2. PROCESAMIENTO DE INFORMACIÓN
===================================================== */
$listaCategorias = obtenerCategorias($mysqli);
$skusEnCategoria = obtenerSkusPorCategoria($mysqli, $catFiltro);

// Datos Sede Central
$dataC  = obtenerMovimientoMensual($mysqliCentral, $anioActual, $skusEnCategoria);
$stockC = obtenerStockReal($mysqliCentral, $skusEnCategoria);
$metC   = calcularMetricas($dataC, $stockC);

// Datos Sede Drinks
$dataD  = obtenerMovimientoMensual($mysqliPos, $anioActual, $skusEnCategoria);
$stockD = obtenerStockReal($mysqliPos, $skusEnCategoria);
$metD   = calcularMetricas($dataD, $stockD);

// Datos Consolidados (Global)
$dataGlobal = [];
foreach($dataC as $mes => $val) {
    $dataGlobal[$mes] = $val + $dataD[$mes];
}
$metGlobal = calcularMetricas($dataGlobal, $stockC + $stockD);

function nombreMes($n) {
    $m = ["01"=>"Ene","02"=>"Feb","03"=>"Mar","04"=>"Abr","05"=>"May","06"=>"Jun","07"=>"Jul","08"=>"Ago","09"=>"Sep","10"=>"Oct","11"=>"Nov","12"=>"Dic"];
    return $m[$n];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Gerencial de Inventarios</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1a237e;
            --success: #2e7d32;
            --warning: #e65100;
            --info: #0277bd;
            --bg: #f4f7f6;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1400px; margin: auto; }
        
        /* Header y Filtro */
        .header-filter { background: #fff; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; }
        .title-area h2 { margin: 0; color: var(--primary); font-size: 24px; }
        select { padding: 12px 20px; border-radius: 10px; border: 1px solid #ddd; width: 450px; font-size: 16px; background: #fff; cursor: pointer; outline: none; transition: 0.3s; }
        select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1); }

        /* Tarjetas y Gráficas */
        .card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); border-top: 6px solid var(--primary); margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .chart-container { height: 300px; margin-bottom: 25px; }

        /* Tablas */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #f8f9fa; padding: 12px; text-align: right; font-size: 11px; color: #888; text-transform: uppercase; border-bottom: 2px solid #eee; }
        th:first-child { text-align: left; }
        td { padding: 14px 12px; border-bottom: 1px solid #f1f1f1; text-align: right; font-weight: 600; font-size: 14px; }
        td:first-child { text-align: left; color: #666; font-weight: 400; }

        /* Resaltados */
        .bg-total { background: #e8f5e9 !important; color: var(--success); }
        .bg-prom { background: #e3f2fd !important; color: var(--info); }
        .bg-dias { background: #fff3e0 !important; color: var(--warning); }
        .stock-val { font-size: 20px; font-weight: 800; color: var(--primary); }
        
        .alerta-critica { color: #d32f2f !important; font-weight: 900; animation: blinker 1.5s linear infinite; }
        @keyframes blinker { 50% { opacity: 0.4; } }

        @media print { .header-filter { display: none; } .card { box-shadow: none; border: 1px solid #eee; } }
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div class="title-area">
            <h2>📈 Análisis de Movimiento y Stock</h2>
            <small>Basado en Facturas + Pedidos | Año <?= $anioActual ?></small>
        </div>
        <form method="GET">
            <select name="codcat" onchange="this.form.submit()">
                <option value="">-- Seleccione una Categoría --</option>
                <?php foreach($listaCategorias as $cod => $nom): ?>
                    <option value="<?= $cod ?>" <?= $catFiltro == $cod ? 'selected' : '' ?>>
                        [<?= str_pad($cod, 3, "0", STR_PAD_LEFT) ?>] <?= strtoupper($nom) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if($catFiltro): ?>
        
        <?php 
        $sedes = [
            ['id' => 'global', 'nombre' => '🌎 Resumen Global (Consolidado)', 'data' => $dataGlobal, 'met' => $metGlobal, 'stock' => ($stockC + $stockD), 'color' => '#2e7d32'],
            ['id' => 'central', 'nombre' => '🏢 Sede Central', 'data' => $dataC, 'met' => $metC, 'stock' => $stockC, 'color' => '#0d47a1'],
            ['id' => 'drinks', 'nombre' => '🍹 Sede Drinks', 'data' => $dataD, 'met' => $metD, 'stock' => $stockD, 'color' => '#6a1b9a']
        ];

        foreach($sedes as $s): ?>
        <div class="card" style="border-top-color: <?= $s['color'] ?>;">
            <div class="card-header">
                <h3 style="margin:0; color: <?= $s['color'] ?>;"><?= $s['nombre'] ?></h3>
                <div style="text-align:right;">
                    <small style="display:block; color:#888;">STOCK ACTUAL</small>
                    <span class="stock-val"><?= number_format($s['stock'], 0) ?> Unds.</span>
                </div>
            </div>

            <div class="chart-container">
                <canvas id="chart_<?= $s['id'] ?>"></canvas>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Indicador</th>
                            <?php foreach($s['data'] as $m => $v): ?> <th><?= nombreMes($m) ?></th> <?php endforeach; ?>
                            <th class="bg-total">TOTAL</th>
                            <th class="bg-prom">PROM.</th>
                            <th class="bg-dias">DÍAS STOCK</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Ventas Mensuales</td>
                            <?php foreach($s['data'] as $v): ?> 
                                <td><?= number_format($v,0) ?></td> 
                            <?php endforeach; ?>
                            <td class="bg-total"><?= number_format($s['met']['total'], 0) ?></td>
                            <td class="bg-prom"><?= number_format($s['met']['promedio'], 0) ?></td>
                            <td class="bg-dias <?= ($s['met']['dias'] > 0 && $s['met']['dias'] < 10) ? 'alerta-critica' : '' ?>">
                                <?= number_format($s['met']['dias'], 1) ?> d
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        new Chart(document.getElementById('chart_<?= $s['id'] ?>'), {
            type: 'bar',
            data: {
                labels: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
                datasets: [
                    {
                        label: 'Venta Real + Pedidos',
                        data: <?= json_encode(array_values($s['data'])) ?>,
                        backgroundColor: '<?= $s['color'] ?>cc',
                        borderRadius: 5
                    },
                    {
                        label: 'Meta de Stock (Promedio)',
                        data: Array(12).fill(<?= round($s['met']['promedio'], 2) ?>),
                        type: 'line',
                        borderColor: '#ff9800',
                        borderDash: [5, 5],
                        borderWidth: 2,
                        pointRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 12 }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
                    x: { grid: { display: false } }
                }
            }
        });
        </script>
        <?php endforeach; ?>

    <?php else: ?>
        <div style="text-align:center; padding:100px; color:#aaa;">
            <span style="font-size:60px;">📂</span>
            <h3>Seleccione una categoría para iniciar el análisis gerencial</h3>
        </div>
    <?php endif; ?>
</div>

</body>
</html>