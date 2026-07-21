<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual = date('Y');
$mesActualNum = date('m');
$hoy = date('Y-m-d');

$sedeFiltro = $_GET['idsede'] ?? 'todos'; 
$fechaFiltro = $_GET['fecha'] ?? $hoy; 

/* =====================================================
    1. FUNCIONES DE EXTRACCIÓN
===================================================== */

function obtenerFamilias($dbWeb) {
    $familias = [];
    $sql = "SELECT f.id, f.nombre 
            FROM familias f 
            INNER JOIN categorias c ON f.id = c.Tipo 
            GROUP BY f.id, f.nombre 
            ORDER BY f.nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $familias[$row['id']] = $row['nombre'];
    }
    return $familias;
}

function obtenerSkusPorFamilia($dbWeb, $idFamilia) {
    $skus = [];
    $sql = "SELECT cp.sku 
            FROM catproductos cp 
            INNER JOIN categorias c ON cp.codcat = c.codcat 
            WHERE c.Tipo = '$idFamilia'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $skus[] = "'".trim($row['sku'])."'";
    }
    return array_unique($skus);
}

function obtenerSkusPorCategoria($dbWeb, $codcat) {
    $skus = [];
    $sql = "SELECT sku FROM catproductos WHERE codcat = '$codcat'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $skus[] = "'".trim($row['sku'])."'";
    }
    return array_unique($skus);
}

function obtenerCategoriasPorFamilia($dbWeb, $idFamilia) {
    $categorias = [];
    $sql = "SELECT codcat, nombre FROM categorias WHERE Tipo = '$idFamilia' ORDER BY nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $categorias[$row['codcat']] = $row['nombre'];
    }
    return $categorias;
}

function obtenerMovimientoValorizado($db, $anio, $listaSkus) {
    $meses = [];
    for($i=1; $i<=12; $i++) {
        $m = str_pad($i, 2, "0", STR_PAD_LEFT);
        $meses[$m] = ['cant' => 0, 'pesos' => 0, 'costo' => 0];
    }
    if(empty($listaSkus)) return $meses;
    $inClause = implode(",", $listaSkus);

    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, 
                SUM(D.CANTIDAD) as cant, 
                SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rf = $db->query($sqlFac);
    while($rf && $row = $rf->fetch_assoc()) {
        $meses[$row['mes']]['cant'] += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
        $meses[$row['mes']]['costo'] += (float)($row['totalcosto'] ?? 0);
    }

    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, 
                SUM(D.CANTIDAD) as cant, 
                SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()) {
        $meses[$row['mes']]['cant'] += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
        $meses[$row['mes']]['costo'] += (float)($row['totalcosto'] ?? 0);
    }
    return $meses;
}

function obtenerMovimientoDia($db, $fechaSql, $listaSkus) {
    $res = ['cant' => 0, 'pesos' => 0, 'costo' => 0];
    if(empty($listaSkus)) return $res;
    $inClause = implode(",", $listaSkus);
    $fechaLimpia = str_replace('-', '', $fechaSql);

    $sqlFac = "SELECT SUM(D.CANTIDAD) as cant, 
                SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$fechaLimpia%' AND PR.barcode IN ($inClause)";
    $rf = $db->query($sqlFac);
    if($rf && $row = $rf->fetch_assoc()) {
        $res['cant'] += (float)($row['cant'] ?? 0);
        $res['pesos'] += (float)($row['subtotal'] ?? 0);
        $res['costo'] += (float)($row['totalcosto'] ?? 0);
    }

    $sqlPed = "SELECT SUM(D.CANTIDAD) as cant, 
                SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$fechaLimpia%' AND PR.barcode IN ($inClause)";
    $rp = $db->query($sqlPed);
    if($rp && $row = $rp->fetch_assoc()) {
        $res['cant'] += (float)($row['cant'] ?? 0);
        $res['pesos'] += (float)($row['subtotal'] ?? 0);
        $res['costo'] += (float)($row['totalcosto'] ?? 0);
    }
    return $res;
}

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n];
}

// AJAX: Detalle de categorías para una familia
if(isset($_GET['ajax_familia'])) {
    $idFamAjax = $_GET['ajax_familia'];
    $nombreFamAjax = $_GET['nombre_fam'] ?? '';
    $cats = obtenerCategoriasPorFamilia($mysqli, $idFamAjax);
    
    $listaCatsData = [];
    $totalPesosMesFam = 0;

    $t_diaCant = 0; $t_diaPesos = 0; $t_diaUtil = 0;
    $t_mesCant = 0; $t_mesPesos = 0; $t_mesUtil = 0;

    foreach($cats as $codCat => $nombreCat) {
        $skusCat = obtenerSkusPorCategoria($mysqli, $codCat);
        if(empty($skusCat)) continue;

        $vC_Cat = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusCat);
        $vD_Cat = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusCat);
        
        $diaC_Cat = obtenerMovimientoDia($mysqliCentral, $fechaFiltro, $skusCat);
        $diaD_Cat = obtenerMovimientoDia($mysqliPos, $fechaFiltro, $skusCat);

        if ($sedeFiltro == 'central') {
            $pDia = $diaC_Cat['pesos']; $cDia = $diaC_Cat['cant']; $costoDia = $diaC_Cat['costo'];
            $pMes = $vC_Cat[$mesActualNum]['pesos']; $cMes = $vC_Cat[$mesActualNum]['cant']; $costoMes = $vC_Cat[$mesActualNum]['costo'];
        } elseif ($sedeFiltro == 'drinks') {
            $pDia = $diaD_Cat['pesos']; $cDia = $diaD_Cat['cant']; $costoDia = $diaD_Cat['costo'];
            $pMes = $vD_Cat[$mesActualNum]['pesos']; $cMes = $vD_Cat[$mesActualNum]['cant']; $costoMes = $vD_Cat[$mesActualNum]['costo'];
        } else {
            $pDia = $diaC_Cat['pesos'] + $diaD_Cat['pesos'];
            $cDia = $diaC_Cat['cant'] + $diaD_Cat['cant'];
            $costoDia = $diaC_Cat['costo'] + $diaD_Cat['costo'];
            $pMes = $vC_Cat[$mesActualNum]['pesos'] + $vD_Cat[$mesActualNum]['pesos'];
            $cMes = $vC_Cat[$mesActualNum]['cant'] + $vD_Cat[$mesActualNum]['cant'];
            $costoMes = $vC_Cat[$mesActualNum]['costo'] + $vD_Cat[$mesActualNum]['costo'];
        }

        $utilDia = $pDia - $costoDia;
        $porcUtilDia = ($pDia > 0) ? ($utilDia / $pDia) * 100 : 0;
        $utilMes = $pMes - $costoMes;
        $porcUtilMes = ($pMes > 0) ? ($utilMes / $pMes) * 100 : 0;

        if(($pMes > 0 || $cMes > 0 || $pDia > 0)) {
            $listaCatsData[] = [
                'nombre' => strtoupper($nombreCat),
                'diaCant' => $cDia, 'diaPesos' => $pDia, 'diaUtil' => $utilDia, 'diaPorcUtil' => $porcUtilDia,
                'mesCant' => $cMes, 'mesPesos' => $pMes, 'mesUtil' => $utilMes, 'mesPorcUtil' => $porcUtilMes
            ];
            $totalPesosMesFam += $pMes;

            $t_diaCant += $cDia;
            $t_diaPesos += $pDia;
            $t_diaUtil += $utilDia;
            $t_mesCant += $cMes;
            $t_mesPesos += $pMes;
            $t_mesUtil += $utilMes;
        }
    }

    usort($listaCatsData, function($a, $b) {
        return $b['diaUtil'] <=> $a['diaUtil'];
    });
    
    echo '<h3 style="color:#006064; margin-top:0;">Categorías de: <b>'.htmlspecialchars($nombreFamAjax).'</b> (Día: '.$fechaFiltro.')</h3>';
    if(empty($listaCatsData)) {
        echo '<p style="text-align:center; color:#666;">No hay registros para esta familia en la fecha seleccionada.</p>';
        exit;
    }
    echo '<div style="overflow-x:auto;"><table id="tablaModalCategorias">';
    echo '<thead><tr>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 0)" style="text-align:left">Categoría 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 1)">Unid. Día 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 2)">Ventas Día 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 3)">Util. Día 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 4)">% Util. Día 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 5)">Unid. Mes 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 6)">Ventas Mes 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 7)">Util. Mes 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 8)">% Util. Mes 🔽</th>
            <th onclick="ordenarTablaPorColumna(\'tablaModalCategorias\', 9)">Part. Mes 🔽</th>
          </tr></thead><tbody>';
    foreach($listaCatsData as $cat) {
        $partMesFam = ($totalPesosMesFam > 0) ? ($cat['mesPesos'] / $totalPesosMesFam) * 100 : 0;
        echo '<tr>';
        echo '<td style="text-align:left; color:#006064;">'.$cat['nombre'].'</td>';
        echo '<td>'.number_format($cat['diaCant'], 0).'</td>';
        echo '<td>$'.number_format($cat['diaPesos'], 0).'</td>';
        echo '<td><span class="badge-util" style="background:#fff3e0; color:#e65100;">$'.number_format($cat['diaUtil'], 0).'</span></td>';
        echo '<td><span class="badge-porc">'.number_format($cat['diaPorcUtil'], 1).'%</span></td>';
        echo '<td>'.number_format($cat['mesCant'], 0).'</td>';
        echo '<td>$'.number_format($cat['mesPesos'], 0).'</td>';
        echo '<td><span class="badge-util">$'.number_format($cat['mesUtil'], 0).'</span></td>';
        echo '<td><span class="badge-porc">'.number_format($cat['mesPorcUtil'], 1).'%</span></td>';
        echo '<td><span class="badge-part">'.number_format($partMesFam, 1).'%</span></td>';
        echo '</tr>';
    }

    // Fila de Totales del Modal (excluida del ordenamiento dinámico)
    $t_porcUtilDiaGen = ($t_diaPesos > 0) ? ($t_diaUtil / $t_diaPesos) * 100 : 0;
    $t_porcUtilMesGen = ($t_mesPesos > 0) ? ($t_mesUtil / $t_mesPesos) * 100 : 0;
    echo '<tr class="total-row" data-total="true">';
    echo '<td style="text-align:left;">TOTALES</td>';
    echo '<td>'.number_format($t_diaCant, 0).'</td>';
    echo '<td>$'.number_format($t_diaPesos, 0).'</td>';
    echo '<td>$'.number_format($t_diaUtil, 0).'</td>';
    echo '<td>'.number_format($t_porcUtilDiaGen, 1).'%</td>';
    echo '<td>'.number_format($t_mesCant, 0).'</td>';
    echo '<td>$'.number_format($t_mesPesos, 0).'</td>';
    echo '<td>$'.number_format($t_mesUtil, 0).'</td>';
    echo '<td>'.number_format($t_porcUtilMesGen, 1).'%</td>';
    echo '<td>100.0%</td>';
    echo '</tr>';

    echo '</tbody></table></div>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Utilidad por Familia - Detalle Diario</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:15px;}
        .container{max-width:1600px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; justify-content: space-between; flex-wrap: wrap; gap: 15px;}
        .card{background:#fff; padding:20px; border-radius:15px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 6px solid #00838f; margin-bottom: 25px; overflow-x: auto;}
        select, input[type="date"]{padding:10px 12px; border-radius:10px; border:1px solid #ddd; font-size:15px; font-weight: bold; color: #006064;}
        table{width:100%; border-collapse:collapse; min-width: 900px;}
        th{background:#f8f9fa; padding:12px; text-align:right; font-size:11px; color:#888; border-bottom:2px solid #eee; text-transform: uppercase; cursor: pointer; user-select: none;}
        th:hover { background: #e2e8f0; color: #006064; }
        td{padding:12px; border-bottom:1px solid #f1f1f1; text-align:right; font-weight:600; font-size: 13px;}
        .total-row { background: #f8f9fa; border-top: 2px solid #00838f; font-weight: bold; color: #006064; }
        .total-row td { font-size: 13px; text-align: right; }
        .total-row td:first-child { text-align: left; }
        .badge-part{background: #e0f7fa; color: #006064; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-util{background: #e3f2fd; color: #1565c0; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-porc{background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .familia-link{color:#006064; cursor:pointer; text-decoration: underline;}
        .familia-link:hover{color:#00838f;}

        /* Estilos Modal */
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
        .modal-content { background: #fff; border-radius: 15px; width: 100%; max-width: 1200px; max-height: 90vh; overflow-y: auto; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; }
        .modal-close { position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #888; cursor: pointer; }
        .modal-close:hover { color: #333; }

        @media(max-width: 768px) {
            body { padding: 10px; }
            .header-filter { padding: 15px; flex-direction: column; align-items: stretch; }
            select, input[type="date"] { width: 100%; }
            .card { padding: 15px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0; color:#006064; font-size: 22px;">🏷️ Dashboard de Utilidad y Ventas por Familia</h2>
            <small>Análisis diario y consolidado por Sede (Haz clic en una familia para ver sus categorías)</small>
        </div>
        <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="date" name="fecha" value="<?= htmlspecialchars($fechaFiltro) ?>" onchange="this.form.submit()">
            <select name="idsede" onchange="this.form.submit()">
                <option value="todos" <?= $sedeFiltro == 'todos' ? 'selected' : '' ?>>🌎 Todas las Sedes</option>
                <option value="central" <?= $sedeFiltro == 'central' ? 'selected' : '' ?>>🏢 Sede Central</option>
                <option value="drinks" <?= $sedeFiltro == 'drinks' ? 'selected' : '' ?>>🍹 Drinks Depot</option>
            </select>
        </form>
    </div>

    <?php
    $familias = obtenerFamilias($mysqli);
    $detalleFamilias = [];
    $totalGeneralPesosMes = 0;

    $totGen_diaCant = 0; $totGen_diaPesos = 0; $totGen_diaUtil = 0;
    $totGen_mesCant = 0; $totGen_mesPesos = 0; $totGen_mesUtil = 0;

    foreach($familias as $idFamilia => $nombreFam) {
        $skusFam = obtenerSkusPorFamilia($mysqli, $idFamilia);
        if(empty($skusFam)) continue;

        $vC_Fam = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusFam);
        $vD_Fam = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusFam);
        
        $diaC_Fam = obtenerMovimientoDia($mysqliCentral, $fechaFiltro, $skusFam);
        $diaD_Fam = obtenerMovimientoDia($mysqliPos, $fechaFiltro, $skusFam);
        
        if ($sedeFiltro == 'central') {
            $pDiaFam = $diaC_Fam['pesos']; $cDiaFam = $diaC_Fam['cant']; $costoDiaFam = $diaC_Fam['costo'];
            $pMesFam = $vC_Fam[$mesActualNum]['pesos']; $cMesFam = $vC_Fam[$mesActualNum]['cant']; $costoMesFam = $vC_Fam[$mesActualNum]['costo'];
        } elseif ($sedeFiltro == 'drinks') {
            $pDiaFam = $diaD_Fam['pesos']; $cDiaFam = $diaD_Fam['cant']; $costoDiaFam = $diaD_Fam['costo'];
            $pMesFam = $vD_Fam[$mesActualNum]['pesos']; $cMesFam = $vD_Fam[$mesActualNum]['cant']; $costoMesFam = $vD_Fam[$mesActualNum]['costo'];
        } else {
            $pDiaFam = $diaC_Fam['pesos'] + $diaD_Fam['pesos'];
            $cDiaFam = $diaC_Fam['cant'] + $diaD_Fam['cant'];
            $costoDiaFam = $diaC_Fam['costo'] + $diaD_Fam['costo'];
            $pMesFam = $vC_Fam[$mesActualNum]['pesos'] + $vD_Fam[$mesActualNum]['pesos'];
            $cMesFam = $vC_Fam[$mesActualNum]['cant'] + $vD_Fam[$mesActualNum]['cant'];
            $costoMesFam = $vC_Fam[$mesActualNum]['costo'] + $vD_Fam[$mesActualNum]['costo'];
        }

        $utilDiaFam = $pDiaFam - $costoDiaFam;
        $porcUtilDia = ($pDiaFam > 0) ? ($utilDiaFam / $pDiaFam) * 100 : 0;
        $utilMesFam = $pMesFam - $costoMesFam;
        $porcUtilMes = ($pMesFam > 0) ? ($utilMesFam / $pMesFam) * 100 : 0;

        if(($pMesFam > 0 || $cMesFam > 0 || $pDiaFam > 0)) {
            $detalleFamilias[] = [
                'id' => $idFamilia,
                'nombre' => strtoupper($nombreFam),
                'diaCant' => $cDiaFam, 'diaPesos' => $pDiaFam, 'diaUtil' => $utilDiaFam, 'diaPorcUtil' => $porcUtilDia,
                'mesCant' => $cMesFam, 'mesPesos' => $pMesFam, 'mesUtil' => $utilMesFam, 'mesPorcUtil' => $porcUtilMes
            ];
            $totalGeneralPesosMes += $pMesFam;

            $totGen_diaCant += $cDiaFam;
            $totGen_diaPesos += $pDiaFam;
            $totGen_diaUtil += $utilDiaFam;
            $totGen_mesCant += $cMesFam;
            $totGen_mesPesos += $pMesFam;
            $totGen_mesUtil += $utilMesFam;
        }
    }

    usort($detalleFamilias, function($a, $b) {
        return $b['diaUtil'] <=> $a['diaUtil'];
    });
    ?>

    <?php if(!empty($detalleFamilias)): ?>
    <div class="card">
        <h2 style="color:#006064; margin-top:0; font-size: 18px;">📊 Ventas y Utilidad por Familia - Detalle Día (<?= $fechaFiltro ?>)</h2>
        <table id="tablaFamiliasPrincipal">
            <thead>
                <tr>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 0)" style="text-align:left">Familia 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 1)">Unid. Día 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 2)">Ventas Día 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 3)">Util. Día 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 4)">% Util. Día 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 5)">Unid. Mes 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 6)">Ventas Mes 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 7)">Util. Mes 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 8)">% Util. Mes 🔽</th>
                    <th onclick="ordenarTablaPorColumna('tablaFamiliasPrincipal', 9)">Part. Mes 🔽</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($detalleFamilias as $fam): 
                    $partMes = ($totalGeneralPesosMes > 0) ? ($fam['mesPesos'] / $totalGeneralPesosMes) * 100 : 0;
                ?>
                <tr>
                    <td style="text-align:left;">
                        <span class="familia-link" onclick="abrirModalFamilia('<?= $fam['id'] ?>', '<?= addslashes($fam['nombre']) ?>')">
                            <?= $fam['nombre'] ?> 🔍
                        </span>
                    </td>
                    <td><?= number_format($fam['diaCant'], 0) ?></td>
                    <td>$<?= number_format($fam['diaPesos'], 0) ?></td>
                    <td><span class="badge-util" style="background:#fff3e0; color:#e65100;">$<?= number_format($fam['diaUtil'], 0) ?></span></td>
                    <td><span class="badge-porc"><?= number_format($fam['diaPorcUtil'], 1) ?>%</span></td>
                    <td><?= number_format($fam['mesCant'], 0) ?></td>
                    <td>$<?= number_format($fam['mesPesos'], 0) ?></td>
                    <td><span class="badge-util">$<?= number_format($fam['mesUtil'], 0) ?></span></td>
                    <td><span class="badge-porc"><?= number_format($fam['mesPorcUtil'], 1) ?>%</span></td>
                    <td><span class="badge-part"><?= number_format($partMes, 1) ?>%</span></td>
                </tr>
                <?php endforeach; ?>

                <!-- Fila de Totales Generales (excluida del ordenamiento dinámico) -->
                <?php 
                    $totGen_porcUtilDia = ($totGen_diaPesos > 0) ? ($totGen_diaUtil / $totGen_diaPesos) * 100 : 0;
                    $totGen_porcUtilMes = ($totGen_mesPesos > 0) ? ($totGen_mesUtil / $totGen_mesPesos) * 100 : 0;
                ?>
                <tr class="total-row" data-total="true">
                    <td style="text-align:left;">TOTALES</td>
                    <td><?= number_format($totGen_diaCant, 0) ?></td>
                    <td>$<?= number_format($totGen_diaPesos, 0) ?></td>
                    <td>$<?= number_format($totGen_diaUtil, 0) ?></td>
                    <td><?= number_format($totGen_porcUtilDia, 1) ?>%</td>
                    <td><?= number_format($totGen_mesCant, 0) ?></td>
                    <td>$<?= number_format($totGen_mesPesos, 0) ?></td>
                    <td>$<?= number_format($totGen_mesUtil, 0) ?></td>
                    <td><?= number_format($totGen_porcUtilMes, 1) ?>%</td>
                    <td>100.0%</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- MODAL CATEGORÍAS -->
    <div id="modalFamilia" class="modal-overlay" onclick="if(event.target === this) cerrarModalFamilia();">
        <div class="modal-content">
            <span class="modal-close" onclick="cerrarModalFamilia()">&times;</span>
            <div id="modalBodyContent">
                <p style="text-align:center; color:#666;">Cargando categorías...</p>
            </div>
        </div>
    </div>

    <script>
    function ordenarTablaPorColumna(idTabla, columnaIndex) {
        const tabla = document.getElementById(idTabla);
        if (!tabla) return;
        const tbody = tabla.tBodies[0];
        const filas = Array.from(tbody.rows);

        // Separar la fila de totales (si existe) para que siempre quede al final
        let filaTotales = null;
        const filasDatos = filas.filter(fila => {
            if (fila.getAttribute('data-total') === 'true') {
                filaTotales = fila;
                return false;
            }
            return true;
        });

        // Ordenar filas de manera descendente
        filasDatos.sort((a, b) => {
            let celdaA = a.cells[columnaIndex].textContent.trim();
            let celdaB = b.cells[columnaIndex].textContent.trim();

            // Limpiar símbolos de moneda, porcentajes, comas y badges de texto extra para convertir a número si aplica
            let valA = parseFloat(celdaA.replace(/[^0-9.-]+/g, ""));
            let valB = parseFloat(celdaB.replace(/[^0-9.-]+/g, ""));

            if (!isNaN(valA) && !isNaN(valB)) {
                return valB - valA; // Descendente numérico
            } else {
                return celdaB.localeCompare(celdaA); // Descendente texto
            }
        });

        // Reinsertar filas ordenadas y al final agregar la fila de totales si existía
        filasDatos.forEach(fila => tbody.appendChild(fila));
        if (filaTotales) {
            tbody.appendChild(filaTotales);
        }
    }

    function abrirModalFamilia(idFamilia, nombreFamilia) {
        const modal = document.getElementById('modalFamilia');
        const content = document.getElementById('modalBodyContent');
        modal.style.display = 'flex';
        content.innerHTML = '<p style="text-align:center; color:#666; padding: 20px;">Cargando categorías de ' + nombreFamilia + '...</p>';

        const sede = "<?= $sedeFiltro ?>";
        const fecha = "<?= $fechaFiltro ?>";
        fetch('?idsede=' + sede + '&fecha=' + fecha + '&ajax_familia=' + idFamilia + '&nombre_fam=' + encodeURIComponent(nombreFamilia))
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(error => {
                content.innerHTML = '<p style="text-align:center; color:red; padding: 20px;">Error al cargar los datos.</p>';
            });
    }

    function cerrarModalFamilia() {
        document.getElementById('modalFamilia').style.display = 'none';
    }
    </script>
    <?php else: ?>
    <div class="card">
        <p style="text-align:center; margin:20px; font-weight:bold; color:#666;">No se encontraron registros de ventas para la fecha y sede seleccionadas.</p>
    </div>
    <?php endif; ?>

</div>
</body>
</html>