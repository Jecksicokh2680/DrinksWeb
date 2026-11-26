<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual    = date('Y');
$mesActualNum  = date('m');
$empresaFiltro = trim($_GET['idempresa'] ?? '');

// Resolver conexión de Drinks de forma segura
$dbDrinks = (isset($mysqliDrinks) && $mysqliDrinks instanceof mysqli && !$mysqliDrinks->connect_error)
    ? $mysqliDrinks
    : ((isset($mysqliPos) && $mysqliPos instanceof mysqli && !$mysqliPos->connect_error) ? $mysqliPos : null);

/* =====================================================
    1. FUNCIONES DE EXTRACCIÓN
===================================================== */

function obtenerEmpresas($dbWeb) {
    $emps = [];
    if (!$dbWeb || $dbWeb->connect_error) return $emps;
    $sql = "SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre ASC";
    $r = $dbWeb->query($sql);
    while ($r && $row = $r->fetch_assoc()) {
        $emps[$row['IdEmpresa']] = $row['Nombre'];
    }
    return $emps;
}

function obtenerSkusPorEmpresa($dbWeb, $idEmpresa) {
    $skus = [];
    if (!$dbWeb || $dbWeb->connect_error || empty($idEmpresa)) return $skus;
    $idEsc = $dbWeb->real_escape_string($idEmpresa);
    $sql = "SELECT DISTINCT TRIM(cp.sku) AS sku
            FROM catproductos cp
            INNER JOIN categorias c ON cp.codcat = c.codcat
            WHERE c.idempresa = '$idEsc' AND cp.sku <> ''";
    $r = $dbWeb->query($sql);
    while ($r && $row = $r->fetch_assoc()) {
        $skus[] = $row['sku'];
    }
    return array_values(array_unique($skus));
}

function construirInClause($db, $skus) {
    if (empty($skus)) return "''";
    return implode(",", array_map(function($s) use ($db) {
        return "'" . $db->real_escape_string($s) . "'";
    }, $skus));
}

function obtenerStockReal($db, $listaSkus) {
    if (!$db || $db->connect_error || empty($listaSkus)) return 0;
    $inClause = construirInClause($db, $listaSkus);
    $sql = "SELECT SUM(I.cantidad) as stock
            FROM inventario I
            INNER JOIN productos P ON I.idproducto = P.idproducto
            WHERE P.barcode IN ($inClause) AND I.idproducto > 0";
    $r = $db->query($sql);
    $row = $r ? $r->fetch_assoc() : null;
    return (float)($row['stock'] ?? 0);
}

function obtenerMovimientoValorizado($db, $anio, $listaSkus) {
    $meses = [];
    for ($i = 1; $i <= 12; $i++) {
        $m = str_pad($i, 2, "0", STR_PAD_LEFT);
        $meses[$m] = ['cant' => 0, 'pesos' => 0];
    }
    if (!$db || $db->connect_error || empty($listaSkus)) return $meses;
    $anioEsc  = $db->real_escape_string($anio);
    $inClause = construirInClause($db, $listaSkus);

    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant, SUM(D.CANTIDAD * PR.precioventa) as subtotal
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$anioEsc%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rf = $db->query($sqlFac);
    while ($rf && $row = $rf->fetch_assoc()) {
        $meses[$row['mes']]['cant']  += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
    }

    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant, SUM(D.CANTIDAD * PR.precioventa) as subtotal
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anioEsc%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rp = $db->query($sqlPed);
    while ($rp && $row = $rp->fetch_assoc()) {
        $meses[$row['mes']]['cant']  += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
    }
    return $meses;
}

function obtenerDetalleMensualPorProducto($db, $anio, $listaSkus) {
    $productos = [];
    if (!$db || $db->connect_error || empty($listaSkus)) return $productos;

    $anioEsc  = $db->real_escape_string($anio);
    $inClause = construirInClause($db, $listaSkus);

    // Base de productos con su stock actual
    $sqlBase = "SELECT TRIM(PR.barcode) AS sku,
                       MAX(PR.descripcion) AS descripcion,
                       COALESCE(SUM(I.cantidad), 0) AS stock
                FROM PRODUCTOS PR
                LEFT JOIN INVENTARIO I ON I.idproducto = PR.idproducto AND I.idproducto > 0
                WHERE PR.barcode IN ($inClause)
                GROUP BY TRIM(PR.barcode)";
    $rb = $db->query($sqlBase);
    while ($rb && $row = $rb->fetch_assoc()) {
        $sku = $row['sku'];
        $mesesInit = [];
        for ($i = 1; $i <= 12; $i++) {
            $m = str_pad($i, 2, "0", STR_PAD_LEFT);
            $mesesInit[$m] = ['cant' => 0.0, 'pesos' => 0.0];
        }
        $productos[$sku] = [
            'sku'         => $sku,
            'descripcion' => $row['descripcion'] ?: 'Sin descripción',
            'stock'       => (float)$row['stock'],
            'meses'       => $mesesInit,
            'totCant'     => 0.0,
            'totPesos'    => 0.0,
            'promCant'    => 0.0,
            'promPesos'   => 0.0
        ];
    }

    // Asegurar SKUs vinculados aunque no tengan fila en PRODUCTOS
    foreach ($listaSkus as $sku) {
        if (!isset($productos[$sku])) {
            $mesesInit = [];
            for ($i = 1; $i <= 12; $i++) {
                $m = str_pad($i, 2, "0", STR_PAD_LEFT);
                $mesesInit[$m] = ['cant' => 0.0, 'pesos' => 0.0];
            }
            $productos[$sku] = [
                'sku'         => $sku,
                'descripcion' => 'Producto no encontrado en sede',
                'stock'       => 0.0,
                'meses'       => $mesesInit,
                'totCant'     => 0.0,
                'totPesos'    => 0.0,
                'promCant'    => 0.0,
                'promPesos'   => 0.0
            ];
        }
    }

    // Facturas válidas
    $sqlFac = "SELECT TRIM(PR.barcode) AS sku,
                      MAX(PR.descripcion) AS descripcion,
                      SUBSTRING(F.FECHA, 5, 2) AS mes,
                      SUM(D.CANTIDAD) AS cant,
                      SUM(D.CANTIDAD * PR.precioventa) AS subtotal
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL
                 AND F.FECHA LIKE '$anioEsc%' AND PR.barcode IN ($inClause)
               GROUP BY TRIM(PR.barcode), mes";
    $rf = $db->query($sqlFac);
    while ($rf && $row = $rf->fetch_assoc()) {
        $sku = $row['sku'];
        $mes = $row['mes'];
        if (isset($productos[$sku]) && isset($productos[$sku]['meses'][$mes])) {
            if (!empty($row['descripcion']) && $productos[$sku]['descripcion'] === 'Producto no encontrado en sede') {
                $productos[$sku]['descripcion'] = $row['descripcion'];
            }
            $productos[$sku]['meses'][$mes]['cant']  += (float)$row['cant'];
            $productos[$sku]['meses'][$mes]['pesos'] += (float)($row['subtotal'] ?? 0);
        }
    }

    // Pedidos válidos
    $sqlPed = "SELECT TRIM(PR.barcode) AS sku,
                      MAX(PR.descripcion) AS descripcion,
                      SUBSTRING(P.FECHA, 5, 2) AS mes,
                      SUM(D.CANTIDAD) AS cant,
                      SUM(D.CANTIDAD * PR.precioventa) AS subtotal
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anioEsc%' AND PR.barcode IN ($inClause)
               GROUP BY TRIM(PR.barcode), mes";
    $rp = $db->query($sqlPed);
    while ($rp && $row = $rp->fetch_assoc()) {
        $sku = $row['sku'];
        $mes = $row['mes'];
        if (isset($productos[$sku]) && isset($productos[$sku]['meses'][$mes])) {
            if (!empty($row['descripcion']) && $productos[$sku]['descripcion'] === 'Producto no encontrado en sede') {
                $productos[$sku]['descripcion'] = $row['descripcion'];
            }
            $productos[$sku]['meses'][$mes]['cant']  += (float)$row['cant'];
            $productos[$sku]['meses'][$mes]['pesos'] += (float)($row['subtotal'] ?? 0);
        }
    }

    // Totales y promedios
    foreach ($productos as &$p) {
        $sumC = 0.0; $sumP = 0.0; $mesesActivos = 0;
        foreach ($p['meses'] as $mData) {
            if ($mData['cant'] > 0 || $mData['pesos'] > 0) {
                $sumC += $mData['cant'];
                $sumP += $mData['pesos'];
                $mesesActivos++;
            }
        }
        $p['totCant']   = $sumC;
        $p['totPesos']  = $sumP;
        $p['promCant']  = $mesesActivos > 0 ? ($sumC / $mesesActivos) : 0;
        $p['promPesos'] = $mesesActivos > 0 ? ($sumP / $mesesActivos) : 0;
    }
    unset($p);

    // Ordenar por mayor venta en pesos del año
    uasort($productos, function($a, $b) {
        return $b['totPesos'] <=> $a['totPesos'];
    });

    return $productos;
}

function consolidarProductosPorSede($prodCentral, $prodDrinks) {
    $allSkus = array_unique(array_merge(array_keys($prodCentral), array_keys($prodDrinks)));
    $consolidado = [];

    foreach ($allSkus as $sku) {
        $c = $prodCentral[$sku] ?? null;
        $d = $prodDrinks[$sku]  ?? null;

        $mesesInit = [];
        $sumC = 0.0; $sumP = 0.0; $mesesActivos = 0;

        for ($i = 1; $i <= 12; $i++) {
            $m = str_pad($i, 2, "0", STR_PAD_LEFT);
            $cant = ($c['meses'][$m]['cant'] ?? 0) + ($d['meses'][$m]['cant'] ?? 0);
            $pesos = ($c['meses'][$m]['pesos'] ?? 0) + ($d['meses'][$m]['pesos'] ?? 0);
            $mesesInit[$m] = ['cant' => $cant, 'pesos' => $pesos];
            if ($cant > 0 || $pesos > 0) {
                $sumC += $cant;
                $sumP += $pesos;
                $mesesActivos++;
            }
        }

        $desc = ($c && $c['descripcion'] !== 'Producto no encontrado en sede')
            ? $c['descripcion']
            : (($d['descripcion'] ?? $sku));

        $consolidado[$sku] = [
            'sku'         => $sku,
            'descripcion' => $desc,
            'stock'       => ($c['stock'] ?? 0) + ($d['stock'] ?? 0),
            'meses'       => $mesesInit,
            'totCant'     => $sumC,
            'totPesos'    => $sumP,
            'promCant'    => $mesesActivos > 0 ? ($sumC / $mesesActivos) : 0,
            'promPesos'   => $mesesActivos > 0 ? ($sumP / $mesesActivos) : 0
        ];
    }

    uasort($consolidado, function($a, $b) {
        return $b['totPesos'] <=> $a['totPesos'];
    });

    return $consolidado;
}

function calcularMetricas($data, $stock) {
    $sumC = 0; $sumP = 0; $mesesActivos = 0;
    foreach ($data as $v) { 
        if ($v['cant'] > 0) { $sumC += $v['cant']; $sumP += $v['pesos']; $mesesActivos++; } 
    }
    $promC = ($mesesActivos > 0) ? ($sumC / $mesesActivos) : 0;
    $promP = ($mesesActivos > 0) ? ($sumP / $mesesActivos) : 0;
    $ventaDiaria = $promC / 30;
    $dias = ($ventaDiaria > 0) ? ($stock / $ventaDiaria) : 0;
    return ['promCant' => $promC, 'promPesos' => $promP, 'dias' => $dias, 'totCant' => $sumC, 'totPesos' => $sumP];
}

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n] ?? $n;
}

/* =====================================================
    2. PROCESAMIENTO INICIAL
===================================================== */
$listaEmpresas = obtenerEmpresas($mysqli);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Gerencial Valorizado</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:20px;}
        .container{max-width:1450px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; justify-content: space-between; flex-wrap:wrap; gap:15px;}
        .card{background:#fff; padding:25px; border-radius:15px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 6px solid #1a237e; margin-bottom: 25px;}
        select{padding:12px; border-radius:10px; border:1px solid #ddd; width:400px; max-width:100%; font-size:16px; font-weight: bold; color: #1a237e;}
        .chart-box{height: 280px; margin-bottom: 20px;}
        .trend-chart-box{height: 340px; margin-bottom: 20px;}
        .table-wrap{width:100%; overflow-x:auto;}
        table{width:100%; border-collapse:collapse;}
        th{background:#f8f9fa; padding:10px; text-align:right; font-size:10px; color:#888; border-bottom:2px solid #eee; text-transform: uppercase;}
        td{padding:10px; border-bottom:1px solid #f1f1f1; text-align:right; font-weight:600; font-size: 13px;}
        .prod-info{text-align:left !important; min-width:220px;}
        .prod-sku{font-size:11px; color:#5c6bc0; font-weight:700; display:block;}
        .prod-name{font-size:13px; color:#222; font-weight:700; display:block;}
        .val-pesos{ color: #2e7d32; display: block; font-size: 11px; font-weight: bold;}
        .bg-total{background:#e8f5e9; color:#2e7d32;}
        .bg-prom{background:#e3f2fd; color:#1565c0;}
        .bg-dias{background:#fff3e0; color:#e65100;}
        .stock-tag{background: #1a237e; color: #fff; padding: 6px 18px; border-radius: 20px; font-size: 14px; font-weight: bold;}
        .toolbar-prod{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;}
        .search-prod{padding:9px 14px; border-radius:8px; border:1px solid #ccc; width:280px; max-width:100%; font-size:13px;}
        .mode-btn{padding:8px 14px; border:1px solid #1a237e; background:#fff; color:#1a237e; border-radius:8px; cursor:pointer; font-weight:700; font-size:12px;}
        .mode-btn.active{background:#1a237e; color:#fff;}
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0; color:#1a237e;">📈 Dashboard de Ventas por Empresa</h2>
            <small>Consolidado: Central de Bebidas & Drinks Depot</small>
        </div>
        <form method="GET">
            <select name="idempresa" onchange="this.form.submit()">
                <option value="">-- Seleccione para detalle --</option>
                <?php foreach ($listaEmpresas as $id => $nom): ?>
                    <option value="<?= htmlspecialchars($id) ?>" <?= $empresaFiltro == $id ? 'selected' : '' ?>><?= strtoupper(htmlspecialchars($nom)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($empresaFiltro && isset($listaEmpresas[$empresaFiltro])): 
        $skusEmpresa = obtenerSkusPorEmpresa($mysqli, $empresaFiltro);
        $dataC = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusEmpresa);
        $stockC = obtenerStockReal($mysqliCentral, $skusEmpresa);
        $metC = calcularMetricas($dataC, $stockC);

        $dataD = obtenerMovimientoValorizado($dbDrinks, $anioActual, $skusEmpresa);
        $stockD = obtenerStockReal($dbDrinks, $skusEmpresa);
        $metD = calcularMetricas($dataD, $stockD);

        $dataGlobal = [];
        foreach ($dataC as $m => $v) {
            $dataGlobal[$m] = [
                'cant'  => $v['cant'] + $dataD[$m]['cant'],
                'pesos' => $v['pesos'] + $dataD[$m]['pesos']
            ];
        }
        $metGlobal = calcularMetricas($dataGlobal, $stockC + $stockD);

        $prodC = obtenerDetalleMensualPorProducto($mysqliCentral, $anioActual, $skusEmpresa);
        $prodD = obtenerDetalleMensualPorProducto($dbDrinks, $anioActual, $skusEmpresa);
        $prodGlobal = consolidarProductosPorSede($prodC, $prodD);

        $sedes = [
            ['id' => 'global',  'nombre' => '🌎 Total: ' . $listaEmpresas[$empresaFiltro], 'data' => $dataGlobal, 'met' => $metGlobal, 'stock' => ($stockC + $stockD), 'color' => '#2e7d32', 'prods' => $prodGlobal],
            ['id' => 'central', 'nombre' => '🏢 Sede Central',                              'data' => $dataC,      'met' => $metC,      'stock' => $stockC,              'color' => '#0d47a1', 'prods' => $prodC],
            ['id' => 'drinks',  'nombre' => '🍹 Drinks Depot',                               'data' => $dataD,      'met' => $metD,      'stock' => $stockD,              'color' => '#6a1b9a', 'prods' => $prodD]
        ];

        $lineColors = ['#1a237e','#2e7d32','#c62828','#ef6c00','#6a1b9a','#00838f','#ad1457','#4e342e','#0277bd','#558b2f'];
        $mesesLabels = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $mesesKeys   = ['01','02','03','04','05','06','07','08','09','10','11','12'];

        foreach ($sedes as $s):
            // Top 8 productos para trazar en la gráfica de tendencia mensual
            $topProds = array_slice(array_values($s['prods']), 0, 8);
            $datasetsPesos = [];
            $datasetsCant  = [];
            foreach ($topProds as $idx => $pItem) {
                $col = $lineColors[$idx % count($lineColors)];
                $seriePesos = [];
                $serieCant  = [];
                foreach ($mesesKeys as $mk) {
                    $seriePesos[] = round((float)$pItem['meses'][$mk]['pesos'], 0);
                    $serieCant[]  = round((float)$pItem['meses'][$mk]['cant'], 2);
                }
                $labelCorto = htmlspecialchars($pItem['sku'] . ' - ' . mb_strimwidth($pItem['descripcion'], 0, 26, '...'));
                $datasetsPesos[] = [
                    'label' => $labelCorto,
                    'data' => $seriePesos,
                    'borderColor' => $col,
                    'backgroundColor' => $col . '22',
                    'tension' => 0.3,
                    'fill' => false
                ];
                $datasetsCant[] = [
                    'label' => $labelCorto,
                    'data' => $serieCant,
                    'borderColor' => $col,
                    'backgroundColor' => $col . '22',
                    'tension' => 0.3,
                    'fill' => false
                ];
            }
        ?>
        <div class="card" style="border-top-color: <?= $s['color'] ?>;">
            <div style="display:flex; justify-content:space-between; align-items: center; margin-bottom:10px; flex-wrap:wrap; gap:10px;">
                <h3 style="margin:0; color:<?= $s['color'] ?>;"><?= htmlspecialchars($s['nombre']) ?></h3>
                <span class="stock-tag">Stock: <?= number_format($s['stock'], 0) ?></span>
            </div>
            <div class="chart-box"><canvas id="c_<?= $s['id'] ?>"></canvas></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left">Mes</th>
                            <?php foreach ($s['data'] as $m => $v): ?> <th><?= substr(nombreMes($m),0,3) ?></th> <?php endforeach; ?>
                            <th class="bg-total">TOTAL</th>
                            <th class="bg-dias">DÍAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="text-align:left">Ventas</td>
                            <?php foreach ($s['data'] as $v): ?> 
                                <td><?= number_format($v['cant'],0) ?><span class="val-pesos">$<?= number_format($v['pesos'],0) ?></span></td> 
                            <?php endforeach; ?>
                            <td class="bg-total"><?= number_format($s['met']['totCant'],0) ?><span class="val-pesos">$<?= number_format($s['met']['totPesos'],0) ?></span></td>
                            <td class="bg-dias"><?= number_format($s['met']['dias'],1) ?>d</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- TENDENCIA MENSUAL POR PRODUCTO -->
            <div style="margin-top:25px; padding-top:18px; border-top:2px dashed #e0e0e0;">
                <div class="toolbar-prod">
                    <div>
                        <h4 style="margin:0; color:#222;">📉 Tendencia Mensual por Producto (<?= $anioActual ?>)</h4>
                        <small style="color:#666;">Gráfica de tendencia (Top 8 productos por venta) + detalle mes a mes de todos los productos</small>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <button type="button" class="mode-btn active" id="btn_pesos_<?= $s['id'] ?>" onclick="cambiarModoTendencia('<?= $s['id'] ?>', 'pesos')">Pesos ($)</button>
                        <button type="button" class="mode-btn" id="btn_cant_<?= $s['id'] ?>" onclick="cambiarModoTendencia('<?= $s['id'] ?>', 'cant')">Unidades</button>
                        <input type="text" class="search-prod" placeholder="🔍 Filtrar producto o SKU..." onkeyup="filtrarTablaProductos('<?= $s['id'] ?>', this.value)">
                    </div>
                </div>

                <div class="trend-chart-box"><canvas id="trend_<?= $s['id'] ?>"></canvas></div>

                <div class="table-wrap">
                    <table id="tbl_prod_<?= $s['id'] ?>">
                        <thead>
                            <tr>
                                <th class="prod-info">Producto</th>
                                <?php foreach ($mesesLabels as $ml): ?> <th><?= $ml ?></th> <?php endforeach; ?>
                                <th class="bg-total">TOTAL AÑO</th>
                                <th class="bg-prom">PROMEDIO</th>
                                <th class="bg-dias">STOCK</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($s['prods'])): ?>
                                <tr><td colspan="16" style="text-align:center; color:#777;">No hay productos registrados para esta empresa.</td></tr>
                            <?php else: foreach ($s['prods'] as $pItem): ?>
                                <tr>
                                    <td class="prod-info">
                                        <span class="prod-sku"><?= htmlspecialchars($pItem['sku']) ?></span>
                                        <span class="prod-name"><?= htmlspecialchars($pItem['descripcion']) ?></span>
                                    </td>
                                    <?php foreach ($mesesKeys as $mk): 
                                        $mC = $pItem['meses'][$mk]['cant'];
                                        $mP = $pItem['meses'][$mk]['pesos'];
                                    ?>
                                        <td>
                                            <?= number_format($mC, 0) ?>
                                            <span class="val-pesos">$<?= number_format($mP, 0) ?></span>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="bg-total">
                                        <?= number_format($pItem['totCant'], 0) ?>
                                        <span class="val-pesos">$<?= number_format($pItem['totPesos'], 0) ?></span>
                                    </td>
                                    <td class="bg-prom">
                                        <?= number_format($pItem['promCant'], 1) ?>
                                        <span class="val-pesos">$<?= number_format($pItem['promPesos'], 0) ?></span>
                                    </td>
                                    <td class="bg-dias"><?= number_format($pItem['stock'], 0) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        new Chart(document.getElementById('c_<?= $s['id'] ?>'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($mesesLabels) ?>,
                datasets: [{ label: 'Pesos ($)', data: <?= json_encode(array_values(array_column($s['data'], 'pesos'))) ?>, backgroundColor: '<?= $s['color'] ?>cc', borderRadius: 5 }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } } } }
        });

        window.tendenciaCharts = window.tendenciaCharts || {};
        window.tendenciaData   = window.tendenciaData   || {};
        window.tendenciaData['<?= $s['id'] ?>'] = {
            pesos: <?= json_encode($datasetsPesos) ?>,
            cant:  <?= json_encode($datasetsCant) ?>
        };

        window.tendenciaCharts['<?= $s['id'] ?>'] = new Chart(document.getElementById('trend_<?= $s['id'] ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($mesesLabels) ?>,
                datasets: <?= json_encode($datasetsPesos) ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                const modo = window.tendenciaModo?.['<?= $s['id'] ?>'] || 'pesos';
                                return modo === 'pesos' ? ('$' + Number(v).toLocaleString()) : Number(v).toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        </script>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    $labelsEmps = []; $pesosEmps = []; $cantEmps = [];
    foreach ($listaEmpresas as $idE => $nomE) {
        $skusE = obtenerSkusPorEmpresa($mysqli, $idE);
        if (empty($skusE)) continue;
        $vC = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusE);
        $vD = obtenerMovimientoValorizado($dbDrinks, $anioActual, $skusE);
        $pMes = $vC[$mesActualNum]['pesos'] + $vD[$mesActualNum]['pesos'];
        $cMes = $vC[$mesActualNum]['cant']  + $vD[$mesActualNum]['cant'];
        if ($pMes > 0) { $labelsEmps[] = $nomE; $pesosEmps[] = $pMes; $cantEmps[] = $cMes; }
    }
    $totalPesosMes = array_sum($pesosEmps);
    ?>

    <div class="card" style="border-top: 6px solid #ff5722; margin-top: 40px; background: #fffcfb;">
        <h2 style="text-align:center; color:#d84315;">📊 Participación por Empresa - <?= nombreMes($mesActualNum) ?></h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 20px;">
            <div style="height: 380px;">
                <h4 style="text-align:center;">Participación en Ventas (%)</h4>
                <canvas id="finalPesos"></canvas>
            </div>
            <div style="height: 380px;">
                <h4 style="text-align:center;">Volumen en Unidades</h4>
                <canvas id="finalCant"></canvas>
            </div>
        </div>

        <div class="table-wrap" style="margin-top: 30px;">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left">Empresa Productora</th>
                        <th>Ventas Mes ($)</th>
                        <th>Participación (%)</th>
                        <th>Unidades</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($labelsEmps as $index => $empName): 
                        $valPesos = $pesosEmps[$index];
                        $valCant = $cantEmps[$index];
                        $porcent = ($totalPesosMes > 0) ? ($valPesos / $totalPesosMes) * 100 : 0;
                    ?>
                    <tr>
                        <td style="text-align:left"><?= strtoupper(htmlspecialchars($empName)) ?></td>
                        <td>$<?= number_format($valPesos, 0) ?></td>
                        <td style="color: #d84315; font-weight: bold;"><?= number_format($porcent, 1) ?>%</td>
                        <td><?= number_format($valCant, 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    window.tendenciaModo = window.tendenciaModo || {};

    function cambiarModoTendencia(sedeId, modo) {
        window.tendenciaModo[sedeId] = modo;
        const chart = window.tendenciaCharts?.[sedeId];
        const sets  = window.tendenciaData?.[sedeId]?.[modo];
        if (chart && sets) {
            chart.data.datasets = sets;
            chart.update();
        }
        document.getElementById('btn_pesos_' + sedeId)?.classList.toggle('active', modo === 'pesos');
        document.getElementById('btn_cant_'  + sedeId)?.classList.toggle('active', modo === 'cant');
    }

    function filtrarTablaProductos(sedeId, texto) {
        const filtro = (texto || '').toLowerCase().trim();
        const tbody  = document.querySelector('#tbl_prod_' + sedeId + ' tbody');
        if (!tbody) return;
        tbody.querySelectorAll('tr').forEach(tr => {
            const contenido = (tr.textContent || '').toLowerCase();
            tr.style.display = contenido.indexOf(filtro) > -1 ? '' : 'none';
        });
    }

    const colors = ['#1a237e','#2e7d32','#c62828','#f9a825','#6a1b9a','#00838f','#ef6c00','#4e342e'];
    
    new Chart(document.getElementById('finalPesos'), {
        type: 'doughnut',
        data: { 
            labels: <?= json_encode($labelsEmps) ?>, 
            datasets: [{ data: <?= json_encode($pesosEmps) ?>, backgroundColor: colors }] 
        },
        plugins: [ChartDataLabels],
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { 
                legend: { position: 'right' },
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold', size: 12 },
                    formatter: (value) => {
                        let total = <?= $totalPesosMes > 0 ? $totalPesosMes : 1; ?>;
                        let percentage = ((value / total) * 100).toFixed(1) + '%';
                        return ((value / total) * 100) > 3 ? percentage : '';
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let value = context.raw;
                            let total = <?= $totalPesosMes > 0 ? $totalPesosMes : 1; ?>;
                            let percentage = ((value / total) * 100).toFixed(1) + '%';
                            return ' $' + value.toLocaleString() + ' (' + percentage + ')';
                        }
                    }
                }
            } 
        }
    });

    new Chart(document.getElementById('finalCant'), {
        type: 'bar',
        data: { labels: <?= json_encode($labelsEmps) ?>, datasets: [{ label: 'Unidades', data: <?= json_encode($cantEmps) ?>, backgroundColor: '#ff5722cc' }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
    </script>
</div>
</body>
</html>