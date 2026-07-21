<?php
date_default_timezone_set('America/Bogota');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual = date('Y');
$mesActualNum = date('m');
$hoy = date('Y-m-d');

$sedeFiltro = $_GET['idsede'] ?? 'todos'; 
$fechaFiltro = $_GET['fecha'] ?? $hoy; 

$anioFiltro = date('Y', strtotime($fechaFiltro));
$mesFiltroNum = date('m', strtotime($fechaFiltro));

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

function obtenerMovimientoMesDiaADia($db, $anio, $mes, $listaSkus) {
    $diasMes = [];
    $totalDias = cal_days_in_month(CAL_GREGORIAN, (int)$mes, (int)$anio);
    for($i=1; $i<=$totalDias; $i++) {
        $d = str_pad($i, 2, "0", STR_PAD_LEFT);
        $fechaStr = "$anio-$mes-$d";
        $diasMes[$fechaStr] = ['cant' => 0, 'pesos' => 0, 'costo' => 0];
    }
    if(empty($listaSkus)) return $diasMes;
    $inClause = implode(",", $listaSkus);
    $prefijoMes = "$anio$mes";

    $sqlFac = "SELECT SUBSTRING(F.FECHA, 1, 8) as fecha_corta, 
                    SUM(D.CANTIDAD) as cant, 
                    SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                    SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$prefijoMes%' AND PR.barcode IN ($inClause)
               GROUP BY fecha_corta";
    $rf = $db->query($sqlFac);
    while($rf && $row = $rf->fetch_assoc()) {
        $fRaw = $row['fecha_corta'];
        $fechaKey = substr($fRaw, 0, 4) . '-' . substr($fRaw, 4, 2) . '-' . substr($fRaw, 6, 2);
        if(isset($diasMes[$fechaKey])) {
            $diasMes[$fechaKey]['cant'] += (float)$row['cant'];
            $diasMes[$fechaKey]['pesos'] += (float)($row['subtotal'] ?? 0);
            $diasMes[$fechaKey]['costo'] += (float)($row['totalcosto'] ?? 0);
        }
    }

    $sqlPed = "SELECT SUBSTRING(P.FECHA, 1, 8) as fecha_corta, 
                    SUM(D.CANTIDAD) as cant, 
                    SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                    SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$prefijoMes%' AND PR.barcode IN ($inClause)
               GROUP BY fecha_corta";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()) {
        $fRaw = $row['fecha_corta'];
        $fechaKey = substr($fRaw, 0, 4) . '-' . substr($fRaw, 4, 2) . '-' . substr($fRaw, 6, 2);
        if(isset($diasMes[$fechaKey])) {
            $diasMes[$fechaKey]['cant'] += (float)$row['cant'];
            $diasMes[$fechaKey]['pesos'] += (float)($row['subtotal'] ?? 0);
            $diasMes[$fechaKey]['costo'] += (float)($row['totalcosto'] ?? 0);
        }
    }
    return $diasMes;
}

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n];
}

function nombreDiaCorto($fechaStr) {
    $diasMap = [
        'Mon' => 'Lun',
        'Tue' => 'Mar',
        'Wed' => 'Mié',
        'Thu' => 'Jue',
        'Fri' => 'Vie',
        'Sat' => 'Sáb',
        'Sun' => 'Dom'
    ];
    $ingles = date('D', strtotime($fechaStr));
    return $diasMap[$ingles] ?? $ingles;
}

// AJAX: Matriz con Días en columnas (horizontal) y Categorías de la familia hacia abajo (en CANTIDAD)
if(isset($_GET['ajax_familia_diario'])) {
    $idFamAjax = $_GET['ajax_familia_diario'];
    $nombreFamAjax = $_GET['nombre_fam'] ?? '';
    
    $categoriasFamilia = obtenerCategoriasPorFamilia($mysqli, $idFamAjax);
    
    // Validar si el mes seleccionado es el actual o anterior para limitar los días hasta la fecha seleccionada
    $diaLimiteMax = cal_days_in_month(CAL_GREGORIAN, (int)$mesFiltroNum, (int)$anioFiltro);
    $mesActualFiltroStr = $anioFiltro . '-' . $mesFiltroNum;
    $mesHoyStr = date('Y-m');
    
    if ($mesActualFiltroStr === $mesHoyStr) {
        $diaLimiteMax = (int)date('d', strtotime($fechaFiltro));
    } elseif ($mesFiltroNum > date('m') && $anioFiltro >= date('Y')) {
        $diaLimiteMax = 0; // Si el mes es futuro respecto a hoy
    }

    $matrizCategorias = [];
    $totalesPorDia = [];

    for($i=1; $i<=$diaLimiteMax; $i++) {
        $d = str_pad($i, 2, "0", STR_PAD_LEFT);
        $totalesPorDia["$anioFiltro-$mesFiltroNum-$d"] = 0;
    }

    foreach($categoriasFamilia as $codCat => $nomCat) {
        $skusCat = obtenerSkusPorCategoria($mysqli, $codCat);
        if(empty($skusCat)) continue;

        $diasC_Cat = obtenerMovimientoMesDiaADia($mysqliCentral, $anioFiltro, $mesFiltroNum, $skusCat);
        $diasD_Cat = obtenerMovimientoMesDiaADia($mysqliPos, $anioFiltro, $mesFiltroNum, $skusCat);

        $diasFila = [];
        $totalCatMes = 0;
        $diasConVentaCount = 0;

        for($i=1; $i<=$diaLimiteMax; $i++) {
            $d = str_pad($i, 2, "0", STR_PAD_LEFT);
            $fechaDia = "$anioFiltro-$mesFiltroNum-$d";

            $datC = $diasC_Cat[$fechaDia] ?? ['cant'=>0];
            $datD = $diasD_Cat[$fechaDia] ?? ['cant'=>0];

            if ($sedeFiltro == 'central') {
                $c = $datC['cant'];
            } elseif ($sedeFiltro == 'drinks') {
                $c = $datD['cant'];
            } else {
                $c = $datC['cant'] + $datD['cant'];
            }

            $diasFila[$fechaDia] = $c;
            $totalCatMes += $c;
            $totalesPorDia[$fechaDia] += $c;

            if ($c > 0) {
                $diasConVentaCount++;
            }
        }

        $promedioCat = ($diasConVentaCount > 0) ? ($totalCatMes / $diasConVentaCount) : 0;

        if($totalCatMes > 0 || $diaLimiteMax == 0) {
            $matrizCategorias[] = [
                'nombre' => strtoupper($nomCat),
                'dias' => $diasFila,
                'total' => $totalCatMes,
                'promedio' => $promedioCat
            ];
        }
    }

    // Ordenar las categorías de mayor a menor según el total del mes acumulado
    usort($matrizCategorias, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });

    $htmlOutput = '<h3 style="color:#006064; margin:0 0 8px 0; font-size: 15px; word-break: break-word; flex-shrink: 0;">📅 Matriz Día a Día en Cantidades por Categoría ('.nombreMes($mesFiltroNum) . " " . $anioFiltro.') - Familia: <b>'.htmlspecialchars($nombreFamAjax).'</b></h3>';
    $htmlOutput .= '<div class="table-responsive-wrapper"><table class="modal-table">';
    
    // Encabezado horizontal con los días del mes ordenados de mayor a menor y el nombre del día abajo
    $htmlOutput .= '<thead><tr>';
    $htmlOutput .= '<th class="sticky-col-header" style="vertical-align: middle;">Categoría</th>';
    for($i = $diaLimiteMax; $i >= 1; $i--) {
        $d = str_pad($i, 2, "0", STR_PAD_LEFT);
        $fechaDia = "$anioFiltro-$mesFiltroNum-$d";
        $nombreDia = nombreDiaCorto($fechaDia);
        $htmlOutput .= '<th class="day-col-header">' . $i . '<br><span style="font-size:9px; color:#555; font-weight:normal;">' . $nombreDia . '</span></th>';
    }
    $htmlOutput .= '<th class="total-col-header" style="vertical-align: middle;">TOTAL</th>';
    $htmlOutput .= '</tr></thead><tbody>';

    if(empty($matrizCategorias) || $diaLimiteMax <= 0) {
        $colSpanTotal = $diaLimiteMax + 2;
        $htmlOutput .= '<tr><td colspan="'.$colSpanTotal.'" style="text-align:center; color:#666; padding:20px;">No hay registros para esta familia en este período.</td></tr>';
    } else {
        $granTotalMes = 0;
        foreach($matrizCategorias as $cat) {
            $htmlOutput .= '<tr>';
            $htmlOutput .= '<td class="sticky-col-cell">' . $cat['nombre'] . ' <span style="font-size:10px; color:#00838f; font-weight:normal;">(Prom: ' . number_format($cat['promedio'], 1) . ')</span></td>';
            
            for($i = $diaLimiteMax; $i >= 1; $i--) {
                $d = str_pad($i, 2, "0", STR_PAD_LEFT);
                $fechaDia = "$anioFiltro-$mesFiltroNum-$d";
                $valDia = $cat['dias'][$fechaDia] ?? 0;
                $estiloCelda = ($valDia > 0) ? 'color:#006064; font-weight:600;' : 'color:#ccc;';
                $htmlOutput .= '<td style="text-align:center; font-size:11px; ' . $estiloCelda . '">' . ($valDia > 0 ? number_format($valDia, 0) : '-') . '</td>';
            }
            
            $htmlOutput .= '<td style="text-align:center; background:#f8f9fa; font-weight:bold; color:#006064; font-size:11px;">' . number_format($cat['total'], 0) . '</td>';
            $htmlOutput .= '</tr>';
            $granTotalMes += $cat['total'];
        }

        // Fila de Totales Generales diarios abajo ordenados de mayor a menor
        $htmlOutput .= '<tr class="total-row">';
        $htmlOutput .= '<td class="sticky-col-cell" style="background:#f8f9fa; font-weight:bold;">TOTALES DÍA</td>';
        for($i = $diaLimiteMax; $i >= 1; $i--) {
            $d = str_pad($i, 2, "0", STR_PAD_LEFT);
            $fechaDia = "$anioFiltro-$mesFiltroNum-$d";
            $valTotDia = $totalesPorDia[$fechaDia] ?? 0;
            $htmlOutput .= '<td style="text-align:center; font-size:11px;">' . ($valTotDia > 0 ? number_format($valTotDia, 0) : '-') . '</td>';
        }
        $htmlOutput .= '<td style="text-align:center; font-size:11px; background:#eef2f3;">' . number_format($granTotalMes, 0) . '</td>';
        $htmlOutput .= '</tr>';
    }

    $htmlOutput .= '</tbody></table></div>';
    echo $htmlOutput;
    exit;
}

$familiasGlobal = obtenerFamilias($mysqli);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Utilidad por Familia - Detalle Diario y Mensual</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:15px;}
        .container{max-width:1600px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; justify-content: space-between; flex-wrap: wrap; gap: 15px;}
        .card{background:#fff; padding:20px; border-radius:15px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 6px solid #00838f; margin-bottom: 25px; overflow-x: auto;}
        select, input[type="date"]{padding:10px 12px; border-radius:10px; border:1px solid #ddd; font-size:15px; font-weight: bold; color: #006064;}
        table{width:100%; border-collapse:collapse; min-width: 900px;}
        th{background:#f8f9fa; padding:12px; text-align:right; font-size:11px; color:#888; border-bottom:2px solid #eee; text-transform: uppercase;}
        td{padding:12px; border-bottom:1px solid #f1f1f1; text-align:right; font-weight:600; font-size: 13px;}
        .total-row { background: #f8f9fa; border-top: 2px solid #00838f; font-weight: bold; color: #006064; }
        .total-row td { font-size: 12px; text-align: right; }
        .total-row td:first-child { text-align: left; }
        .badge-part{background: #e0f7fa; color: #006064; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-util{background: #e3f2fd; color: #1565c0; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-porc{background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .familia-link{color:#006064; cursor:pointer; text-decoration: underline;}
        .familia-link:hover{color:#00838f;}

        /* Estilos Modal Compacto y 100% Pantalla */
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 999; justify-content: center; align-items: center; padding: 10px; box-sizing: border-box; }
        .modal-content { background: #fff; border-radius: 12px; width: 98vw; height: 94vh; max-width: 98vw; max-height: 94vh; display: flex; flex-direction: column; padding: 15px 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); position: relative; box-sizing: border-box; }
        .modal-close { position: absolute; top: 12px; right: 18px; font-size: 22px; font-weight: bold; color: #888; cursor: pointer; z-index: 10; }
        .modal-close:hover { color: #333; }
        
        .table-responsive-wrapper { width: 100%; flex: 1; overflow: auto; margin-top: 6px; border: 1px solid #e1e4e8; border-radius: 8px; -webkit-overflow-scrolling: touch; }
        .modal-table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .modal-table th, .modal-table td { padding: 5px 8px; white-space: nowrap; }
        
        /* Celdas fijas responsivas para la columna de categorías */
        .sticky-col-header { text-align: left; position: sticky; left: 0; background: #f8f9fa; z-index: 3; min-width: 220px; max-width: 280px; box-shadow: 2px 0 5px rgba(0,0,0,0.05); font-size: 11px; }
        .sticky-col-cell { text-align: left; position: sticky; left: 0; background: #fff; z-index: 2; font-weight: 600; color: #333; font-size: 11px; min-width: 220px; max-width: 280px; box-shadow: 2px 0 5px rgba(0,0,0,0.05); }
        .day-col-header { text-align: center; min-width: 38px; font-size: 11px; }
        .total-col-header { text-align: center; background: #eef2f3; min-width: 70px; font-size: 11px; }

        @media(max-width: 768px) {
            body { padding: 10px; }
            .header-filter { padding: 15px; flex-direction: column; align-items: stretch; }
            select, input[type="date"] { width: 100%; }
            .card { padding: 15px; }
            .modal-content { width: 100vw; height: 98vh; max-width: 100vw; max-height: 98vh; padding: 10px; border-radius: 8px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0; color:#006064; font-size: 22px;">🏷️ Dashboard de Utilidad y Ventas por Familia</h2>
            <small>Análisis diario, mensual y por Sede (Haz clic en una familia para ver su matriz horizontal por categorías en cantidades)</small>
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
    $detalleFamilias = [];
    $totalGeneralPesosMes = 0;

    $totGen_diaCant = 0; $totGen_diaPesos = 0; $totGen_diaUtil = 0;
    $totGen_mesCant = 0; $totGen_mesPesos = 0; $totGen_mesUtil = 0;

    foreach($familiasGlobal as $idFamilia => $nombreFam) {
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
        <table>
            <thead>
                <tr>
                    <th style="text-align:left">Familia</th>
                    <th>Unid. Día</th>
                    <th>Ventas Día</th>
                    <th>Util. Día</th>
                    <th>% Util. Día</th>
                    <th>Unid. Mes</th>
                    <th>Ventas Mes</th>
                    <th>Util. Mes</th>
                    <th>% Util. Mes</th>
                    <th>Part. Mes</th>
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

                <?php 
                    $totGen_porcUtilDia = ($totGen_diaPesos > 0) ? ($totGen_diaUtil / $totGen_diaPesos) * 100 : 0;
                    $totGen_porcUtilMes = ($totGen_mesPesos > 0) ? ($totGen_mesUtil / $totGen_mesPesos) * 100 : 0;
                ?>
                <tr class="total-row">
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

    <!-- MODAL MATRIZ DÍAS HORIZONTALES Y CATEGORÍAS VERTICALES EN CANTIDADES -->
    <div id="modalFamilia" class="modal-overlay" onclick="if(event.target === this) cerrarModalFamilia();">
        <div class="modal-content">
            <span class="modal-close" onclick="cerrarModalFamilia()">&times;</span>
            <div id="modalBodyContent" style="display: flex; flex-direction: column; overflow: hidden; height: 100%;">
                <p style="text-align:center; color:#666;">Cargando matriz de días y categorías...</p>
            </div>
        </div>
    </div>

    <script>
    function abrirModalFamilia(idFamilia, nombreFamilia) {
        const modal = document.getElementById('modalFamilia');
        const content = document.getElementById('modalBodyContent');
        modal.style.display = 'flex';
        content.innerHTML = '<p style="text-align:center; color:#666; padding: 20px;">Cargando matriz horizontal de ' + nombreFamilia + '...</p>';

        const sede = "<?= $sedeFiltro ?>";
        const fecha = "<?= $fechaFiltro ?>";
        fetch('?idsede=' + sede + '&fecha=' + fecha + '&ajax_familia_diario=' + idFamilia + '&nombre_fam=' + encodeURIComponent(nombreFamilia))
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