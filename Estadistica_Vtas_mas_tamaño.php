<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual = date('Y');
$catFiltro = $_GET['codcat'] ?? ''; 

/* =====================================================
    1. FUNCIONES DE DATOS (CONSOLIDANDO FACTURAS + PEDIDOS)
===================================================== */

function obtenerCategorias($dbWeb) {
    $cats = [];
    $sql = "SELECT codcat, nombre FROM categorias ORDER BY nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) $cats[$row['codcat']] = $row['nombre'];
    return $cats;
}

function obtenerSkusPorCategoria($dbWeb, $codcat) {
    $skus = [];
    if(empty($codcat)) return $skus;
    $sql = "SELECT sku FROM catproductos WHERE codcat = '$codcat'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) $skus[] = "'".trim($row['sku'])."'";
    return $skus;
}

function obtenerVentaTotalCategoria($db, $anio, $listaSkus) {
    $meses = array_fill_keys(["01","02","03","04","05","06","07","08","09","10","11","12"], 0);
    if(empty($listaSkus)) return $meses;
    $inClause = implode(",", $listaSkus);

    // --- 1. SUMAR FACTURAS ---
    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL 
                 AND F.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    
    $rf = $db->query($sqlFac);
    while($rf && $row = $rf->fetch_assoc()){
        $meses[$row['mes']] += (float)$row['cant'];
    }

    // --- 2. SUMAR PEDIDOS ---
    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anio%' 
                 AND PR.barcode IN ($inClause)
               GROUP BY mes";
               
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()){
        $meses[$row['mes']] += (float)$row['cant'];
    }

    return $meses;
}

/* =====================================================
    2. PROCESAMIENTO
===================================================== */
$listaCategorias = obtenerCategorias($mysqli);
$skusEnCategoria = obtenerSkusPorCategoria($mysqli, $catFiltro);

$dataC = obtenerVentaTotalCategoria($mysqliCentral, $anioActual, $skusEnCategoria);
$dataD = obtenerVentaTotalCategoria($mysqliPos, $anioActual, $skusEnCategoria);

$dataGlobal = [];
foreach($dataC as $mes => $val) $dataGlobal[$mes] = $val + $dataD[$mes];

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Análisis Categoría (Facturas + Pedidos)</title>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f4f7f6; padding:20px;}
        .container{max-width:1100px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; gap:20px;}
        .grid{display:grid; grid-template-columns: 1fr 1fr; gap:20px;}
        .card{background:#fff; padding:20px; border-radius:12px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 4px solid #1a237e;}
        .full-width{grid-column: span 2;}
        select{padding:12px; border-radius:8px; border:1px solid #ddd; width:350px; font-size:15px;}
        table{width:100%; border-collapse:collapse; margin-top:15px;}
        th{background:#f8f9fa; padding:10px; text-align:left; font-size:11px; color:#666; text-transform:uppercase; border-bottom:2px solid #eee;}
        td{padding:12px 10px; border-bottom:1px solid #f1f1f1; font-weight:500;}
        .r{text-align:right;}
        .total-row{background:#e8f5e9; font-weight:bold; color:#2e7d32;}
        .info-tag{background:#fff3e0; color:#e65100; padding:8px 15px; border-radius:8px; font-size:12px; font-weight:bold; border:1px solid #ffe0b2;}
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0;">📂 Categoría: Facturación + Pedidos</h2>
            <small>Consolidado anual <?= $anioActual ?></small>
        </div>
        <form method="GET" style="margin-left:auto;">
            <select name="codcat" onchange="this.form.submit()">
                <option value="">-- Seleccionar Categoría --</option>
                <?php foreach($listaCategorias as $cod => $nom): ?>
                    <option value="<?= $cod ?>" <?= $catFiltro == $cod ? 'selected' : '' ?>><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="info-tag">📊 Incluye Ventas y Preventas</div>
    </div>

    <?php if($catFiltro): ?>
    <div class="grid">
        <div class="card full-width" style="border-top-color:#2e7d32;">
            <h3>🌎 Resumen Global (Ambas Sedes)</h3>
            <table>
                <thead>
                    <tr>
                        <?php foreach($dataGlobal as $m => $v): ?> <th class="r"><?= nombreMes($m) ?></th> <?php endforeach; ?>
                        <th class="r" style="background:#2e7d32; color:white;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php $tg = 0; foreach($dataGlobal as $v): $tg += $v; ?>
                            <td class="r" style="font-size:16px; color:#2e7d32; font-weight:bold;"><?= number_format($v,0) ?></td>
                        <?php endforeach; ?>
                        <td class="r" style="font-size:18px; font-weight:900;"><?= number_format($tg,0) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>🏢 Sede Central</h3>
            <table>
                <thead><tr><th>Mes</th><th class="r">Cantidades</th></tr></thead>
                <tbody>
                    <?php $tc = 0; foreach($dataC as $m => $v): $tc += $v; ?>
                        <tr><td><?= nombreMes($m) ?></td><td class="r"><?= number_format($v,0) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total-row"><td>TOTAL ANUAL</td><td class="r"><?= number_format($tc,0) ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="border-top-color:#7b1fa2;">
            <h3>🍹 Sede Drinks</h3>
            <table>
                <thead><tr><th>Mes</th><th class="r">Cantidades</th></tr></thead>
                <tbody>
                    <?php $td = 0; foreach($dataD as $m => $v): $td += $v; ?>
                        <tr><td><?= nombreMes($m) ?></td><td class="r"><?= number_format($v,0) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total-row"><td>TOTAL ANUAL</td><td class="r"><?= number_format($td,0) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

</body>
</html>