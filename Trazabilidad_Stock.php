<?php
require('ConnCentral.php'); // $mysqliCentral
require('ConnDrinks.php');  // $mysqliDrinks
require('Conexion.php');    // $mysqliWeb + $mysqli

define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

/* =====================================================
    CONFIGURACIÓN Y AUTORIZACIÓN
===================================================== */
$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) {
    header("Location: Login.php");
    exit;
}

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    if (!isset($_SESSION['Autorizaciones'])) $_SESSION['Autorizaciones'] = [];
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) return $_SESSION['Autorizaciones'][$key];

    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}

if (Autorizacion($UsuarioSesion, '0003') !== "SI" && Autorizacion($UsuarioSesion, '9999') !== "SI") {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No tiene autorización para acceder a esta página</h2>");
}

date_default_timezone_set('America/Bogota');

/* =====================================================
    LÓGICA DE OBTENCIÓN DE DATOS (VENTAS)
===================================================== */
function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];
    
    $condFactura = "";
    $condPedido = "";
    if ($f_fac != "") {
        $f_fac_esc = $cnx->real_escape_string($f_fac);
        $condFactura .= " AND T1.NOMBRES = '$f_fac_esc' ";
        $condPedido  .= " AND T2.NOMBRES = '$f_fac_esc' ";
    }

    $sql = "
    SELECT 
        '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR,
        FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD
    FROM FACTURAS
    INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR
    WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura
    UNION ALL
    SELECT 
        '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR,
        PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD
    FROM PEDIDOS
    INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO
    INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO
    INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO
    WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido
    ORDER BY FECHA ASC, HORA ASC, DOCUMENTO ASC
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* =====================================================
    LÓGICA DE OBTENCIÓN DE COMPRAS DIRECTAS DESDE LAS BASES DE SUCURSAL
===================================================== */
function obtenerComprasSucursal($cnx, $nombreSucursal, $f_ini, $f_fin) {
    if (!$cnx || $cnx->connect_error) return [];

    $sql = "
        SELECT 
            '$nombreSucursal' AS SUCURSAL, 
            P.Barcode AS SKU, 
            D.CANTIDAD
        FROM compras C
        JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
        JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
        WHERE C.FECHA BETWEEN ? AND ? AND C.ESTADO = '0'
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ss", $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');
$fSuc      = $_GET['sucursal'] ?? '';
$fFac      = $_GET['facturador'] ?? '';
$fCat      = $_GET['categoria'] ?? '';

$f_ini = str_replace('-', '', $f_ini_raw);
$f_fin = str_replace('-', '', $f_fin_raw);

$rows = [];
$comprasRaw = [];

if ($fSuc == '' || $fSuc == 'CENTRAL') {
    if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
        $rows = array_merge($rows, obtenerDatos($mysqliCentral, 'CENTRAL', $f_ini, $f_fin, $fFac));
        $comprasRaw = array_merge($comprasRaw, obtenerComprasSucursal($mysqliCentral, 'CENTRAL', $f_ini, $f_fin));
    }
}
if ($fSuc == '' || $fSuc == 'DRINKS') {
    if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
        $rows = array_merge($rows, obtenerDatos($mysqliDrinks, 'DRINKS', $f_ini, $f_fin, $fFac));
        $comprasRaw = array_merge($comprasRaw, obtenerComprasSucursal($mysqliDrinks, 'DRINKS', $f_ini, $f_fin));
    }
}

// Obtener listado completo de categorías para llenar el select del filtro
$listaCategorias = [];
if (isset($mysqliWeb)) {
    $qCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE ESTADO='1' ORDER BY CodCat ASC");
    if ($qCat) {
        while ($c = $qCat->fetch_assoc()) {
            $listaCategorias[] = $c;
        }
    }
}

// Obtener CodCat, Nombre de categoría y Unicaja desde la base web asociados por Sku (Barcode = Sku)
$datosWeb = [];
$skusVentas = array_column($rows, 'Barcode');
$skusCompras = array_column($comprasRaw, 'SKU');
$skus = array_unique(array_merge($skusVentas, $skusCompras));

if ($skus && isset($mysqliWeb)) {
    $listaSkus = "'" . implode("','", array_map(array($mysqliWeb, 'real_escape_string'), $skus)) . "'";
    $q = $mysqliWeb->query("SELECT cp.Sku, cat.CodCat, cat.Unicaja, cat.Nombre AS CATEGORIA FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat WHERE cp.Sku IN ($listaSkus)");
    if ($q) {
        while ($u = $q->fetch_assoc()) {
            $datosWeb[$u['Sku']] = [
                'CodCat'    => $u['CodCat'] ?? 'N/A',
                'Unicaja'   => $u['Unicaja'],
                'Categoria' => $u['CATEGORIA'] ?? 'SIN CATEGORIA'
            ];
        }
    }
}

// Inyectar datos de categoría a cada registro de venta
foreach ($rows as &$r) {
    $r['CODCAT']    = $datosWeb[$r['Barcode']]['CodCat'] ?? 'N/A';
    $r['CATEGORIA'] = $datosWeb[$r['Barcode']]['Categoria'] ?? 'SIN CATEGORIA';
    $r['UNICAYA_VAL'] = $datosWeb[$r['Barcode']]['Unicaja'] ?? 1;
}
unset($r);

// Aplicar filtro de categoría en ventas si fue seleccionado
if ($fCat != '') {
    $rows = array_filter($rows, function($r) use ($fCat) {
        return $r['CODCAT'] == $fCat;
    });
}

// ---------------------------------------------------------
// OBTENER STOCK INICIAL (Del día anterior a $f_ini_raw desde historial_stock agrupado por codcat)
// ---------------------------------------------------------
$fechaAnterior = date('Y-m-d', strtotime($f_ini_raw . ' -1 day'));
$stockInicialAgrupado = [];

if (isset($mysqliWeb)) {
    $sqlStock = "SELECT codcat, SUM(stock_central) as central, SUM(stock_drinks) as drinks FROM historial_stock WHERE fecha_registro = ? GROUP BY codcat";
    $stmtStk = $mysqliWeb->prepare($sqlStock);
    if ($stmtStk) {
        $stmtStk->bind_param("s", $fechaAnterior);
        $stmtStk->execute();
        $resStk = $stmtStk->get_result();
        while($stk = $resStk->fetch_assoc()) {
            $stockInicialAgrupado[$stk['codcat']] = [
                'CENTRAL' => floatval($stk['central']),
                'DRINKS'  => floatval($stk['drinks'])
            ];
        }
        $stmtStk->close();
    }
}

// ---------------------------------------------------------
// OBTENER MOVIMIENTOS DE INVENTARIO DESGLOSADOS (Traslados y Ajustes)
// ---------------------------------------------------------
$trasladosEntradasAgrupadas = [];
$trasladosSalidasAgrupadas  = [];
$ajustesNetosAgrupados      = [];

if (isset($mysqliWeb)) {
    $nitCentralVal = NIT_CENTRAL;
    $nitDrinksVal  = NIT_DRINKS;
    $fIniMov = $f_ini_raw . ' 00:00:00';
    $fFinMov = $f_fin_raw . ' 23:59:59';

    $sqlMov = "
        SELECT 
            m.barcode, 
            m.NitEmpresa_Orig, 
            m.NitEmpresa_Dest, 
            m.tipo, 
            m.cant 
        FROM inventario_movimientos m 
        WHERE m.fecha BETWEEN ? AND ? 
          AND m.Estado = 1 AND m.Aprobado = 1
    ";
    $stmtMov = $mysqliWeb->prepare($sqlMov);
    if ($stmtMov) {
        $stmtMov->bind_param("ss", $fIniMov, $fFinMov);
        $stmtMov->execute();
        $resMov = $stmtMov->get_result();
        while($m = $resMov->fetch_assoc()) {
            $barcode = $m['barcode'];
            $codCatSku = $datosWeb[$barcode]['CodCat'] ?? 'N/A';
            
            if ($codCatSku == 'N/A') {
                $qSku = $mysqliWeb->prepare("SELECT CodCat FROM catproductos WHERE Sku = ? LIMIT 1");
                if ($qSku) {
                    $qSku->bind_param("s", $barcode);
                    $qSku->execute();
                    $resSku = $qSku->get_result();
                    if ($rowSku = $resSku->fetch_assoc()) {
                        $codCatSku = $rowSku['CodCat'];
                    }
                    $qSku->close();
                }
            }
            if ($codCatSku == 'N/A') continue;

            $cantMov = floatval($m['cant']);
            $tipoMov = $m['tipo']; // 'ENTRA', 'SALE', 'AJUSTE_IN', 'AJUSTE_OUT'

            $sucOrig = '';
            if ($m['NitEmpresa_Orig'] == $nitCentralVal) $sucOrig = 'CENTRAL';
            elseif ($m['NitEmpresa_Orig'] == $nitDrinksVal) $sucOrig = 'DRINKS';

            $sucDest = '';
            if ($m['NitEmpresa_Dest'] == $nitCentralVal) $sucDest = 'CENTRAL';
            elseif ($m['NitEmpresa_Dest'] == $nitDrinksVal) $sucDest = 'DRINKS';

            // 1. TRASLADOS ENTRANTES (Entradas que provienen de la otra sucursal interna)
            if ($tipoMov == 'ENTRA' && !empty($sucOrig) && !empty($sucDest) && $sucOrig != $sucDest) {
                $trasladosEntradasAgrupadas[$codCatSku][$sucDest] = ($trasladosEntradasAgrupadas[$codCatSku][$sucDest] ?? 0) + $cantMov;
            }

            // 2. TRASLADOS SALIENTES (Salidas que van hacia la otra sucursal interna)
            if ($tipoMov == 'SALE' && !empty($sucOrig) && !empty($sucDest) && $sucOrig != $sucDest) {
                $trasladosSalidasAgrupadas[$codCatSku][$sucOrig] = ($trasladosSalidasAgrupadas[$codCatSku][$sucOrig] ?? 0) + $cantMov;
            }

            // 3. AJUSTES (+/-)
            if ($tipoMov == 'AJUSTE_IN') {
                $targetSuc = !empty($sucOrig) ? $sucOrig : $sucDest;
                if ($targetSuc != '') {
                    $ajustesNetosAgrupados[$codCatSku][$targetSuc] = ($ajustesNetosAgrupados[$codCatSku][$targetSuc] ?? 0) + $cantMov;
                }
            } elseif ($tipoMov == 'AJUSTE_OUT') {
                $targetSuc = !empty($sucOrig) ? $sucOrig : $sucDest;
                if ($targetSuc != '') {
                    $ajustesNetosAgrupados[$codCatSku][$targetSuc] = ($ajustesNetosAgrupados[$codCatSku][$targetSuc] ?? 0) - $cantMov;
                }
            }
        }
        $stmtMov->close();
    }
}

// Agrupar compras por categoría y sucursal usando $datosWeb
$comprasAgrupadas = [];
foreach ($comprasRaw as $comp) {
    $sku = $comp['SKU'];
    $sucursal = $comp['SUCURSAL'];
    $codCat = $datosWeb[$sku]['CodCat'] ?? 'N/A';

    if ($codCat == 'N/A' && isset($mysqliWeb)) {
        $qSku = $mysqliWeb->prepare("SELECT CodCat FROM catproductos WHERE Sku = ? LIMIT 1");
        if ($qSku) {
            $qSku->bind_param("s", $sku);
            $qSku->execute();
            $resSku = $qSku->get_result();
            if ($rowSku = $resSku->fetch_assoc()) {
                $codCat = $rowSku['CodCat'];
            }
            $qSku->close();
        }
    }

    if ($codCat != 'N/A') {
        $comprasAgrupadas[$codCat][$sucursal] = ($comprasAgrupadas[$codCat][$sucursal] ?? 0) + floatval($comp['CANTIDAD']);
    }
}

// Consolidar datos por Categoría (CodCat) y Sucursal
$categoriasAgrupadas = [];
$totalVendidoGlobal            = 0;
$totalStockInicialGlobal       = 0;
$totalStockFinalGlobal         = 0;
$totalComprasGlobal            = 0;
$totalTrasladosEntradaGlobal   = 0;
$totalTrasladosSalidaGlobal    = 0;
$totalAjustesGlobal            = 0;

$allCodCats = array_keys($stockInicialAgrupado);
foreach($rows as $r) { $allCodCats[] = $r['CODCAT']; }
foreach(array_keys($comprasAgrupadas) as $cCat) { $allCodCats[] = $cCat; }
foreach(array_keys($trasladosEntradasAgrupadas) as $tCat) { $allCodCats[] = $tCat; }
foreach(array_keys($trasladosSalidasAgrupadas) as $tCat) { $allCodCats[] = $tCat; }
foreach(array_keys($ajustesNetosAgrupados) as $aCat) { $allCodCats[] = $aCat; }
$allCodCats = array_unique($allCodCats);

$mapNombresCat = [];
foreach($listaCategorias as $lc) {
    $mapNombresCat[$lc['CodCat']] = $lc['Nombre'];
}

$sucursalesEvaluar = ($fSuc != '') ? [$fSuc] : ['CENTRAL', 'DRINKS'];

foreach($allCodCats as $codCat) {
    if ($codCat == 'N/A') continue;
    $nombreCat = $mapNombresCat[$codCat] ?? 'SIN CATEGORIA';

    foreach($sucursalesEvaluar as $sucursal) {
        if ($fCat != '' && $codCat != $fCat) continue;

        $key = $codCat . '_' . $sucursal;

        $stockIniCat = $stockInicialAgrupado[$codCat][$sucursal] ?? 0;
        $comprasCat  = $comprasAgrupadas[$codCat][$sucursal] ?? 0;
        $traslEntra  = $trasladosEntradasAgrupadas[$codCat][$sucursal] ?? 0;
        $traslSalida = $trasladosSalidasAgrupadas[$codCat][$sucursal] ?? 0;
        $ajusteCat   = $ajustesNetosAgrupados[$codCat][$sucursal] ?? 0;

        $ventasCat = 0;
        foreach($rows as $r) {
            if ($r['CODCAT'] == $codCat && $r['SUCURSAL'] == $sucursal) {
                $ventasCat += $r['CANTIDAD'];
            }
        }

        if ($stockIniCat == 0 && $comprasCat == 0 && $traslEntra == 0 && $traslSalida == 0 && $ajusteCat == 0 && $ventasCat == 0) {
            continue;
        }

        // Fórmula solicitada: Stock Final = (Stock Inicial + Compras + Traslado Entrando) - (Traslado Saliendo + Ventas) + Ajustes
        $stockFinal = ($stockIniCat + $comprasCat + $traslEntra) - ($traslSalida + $ventasCat) + $ajusteCat;

        if (!isset($categoriasAgrupadas[$key])) {
            $categoriasAgrupadas[$key] = [
                'SUCURSAL'              => $sucursal,
                'CODCAT'                => $codCat,
                'CATEGORIA'             => $nombreCat,
                'STOCK_INICIAL'         => $stockIniCat,
                'COMPRAS'               => $comprasCat,
                'TRASLADO_ENTRADA'      => $traslEntra,
                'TRASLADO_SALIDA'       => $traslSalida,
                'TOTAL_VENDIDO_CANTIDAD'=> $ventasCat,
                'AJUSTES'               => $ajusteCat,
                'STOCK_FINAL'           => $stockFinal
            ];
            
            $totalStockInicialGlobal     += $stockIniCat;
            $totalComprasGlobal          += $comprasCat;
            $totalTrasladosEntradaGlobal += $traslEntra;
            $totalTrasladosSalidaGlobal  += $traslSalida;
            $totalVendidoGlobal          += $ventasCat;
            $totalAjustesGlobal          += $ajusteCat;
            $totalStockFinalGlobal       += $stockFinal;
        }
    }
}

// Ordenar las categorías por CodCat
usort($categoriasAgrupadas, function($a, $b) {
    return strcmp($a['CODCAT'], $b['CODCAT']);
});

// Mantener lista de facturadores para el select
if ($fFac == '' && !empty($rows)) {
    $listaFacturadores = array_unique(array_column($rows, 'FACTURADOR'));
    $_SESSION['UltimosFacturadores'] = $listaFacturadores;
} else {
    $listaFacturadores = $_SESSION['UltimosFacturadores'] ?? [];
}
sort($listaFacturadores);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Consolidado por Categorías - Sistema Drinks</title>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body{font-family:'Segoe UI', sans-serif; font-size:14px; background: #eceff1; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column;}
        .card{background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); flex: 1; display: flex; flex-direction: column; box-sizing: border-box;}
        .filter-box{ background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6;}
        .filter-group{ display: flex; flex-direction: column; gap: 5px; }
        label{ font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
        input, select, button{ padding: 10px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
        button{ background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold;}
        .btn-excel{ background: #2e7d32 !important; }
        .btn-print{ background: #455a64 !important; }
        .table-container { flex: 1; max-height: none; overflow-y: auto; margin-top: 20px; border: 1px solid #ddd; }
        table{ border-collapse: collapse; width: 100%; background: white; }
        th{ position: sticky; top: 0; background: #263238; color: white; padding: 12px; text-align: left; z-index: 2; }
        td{ padding: 12px; border-bottom: 1px solid #eee; }
        .gran-total{ background: #263238; color: #fff; font-weight: 900; }
        .badge{ padding: 4px 8px; border-radius: 4px; font-size: 11px; color: white; font-weight: bold; }
        .central{ background: #1565c0; } .drinks{ background: #2e7d32; }
    </style>
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="card">
    <h2>📊 Reporte Consolidado por Categorías (Stock, Compras, Traslados, Ventas y Ajustes)</h2>

    <form method="GET" class="filter-box">
        <div class="filter-group"><label>Desde:</label><input type="date" name="fecha_ini" value="<?=$f_ini_raw?>"></div>
        <div class="filter-group"><label>Hasta:</label><input type="date" name="fecha_fin" value="<?=$f_fin_raw?>"></div>
        
        <div class="filter-group">
            <label>Categoría:</label>
            <select name="categoria">
                <option value="">-- Todas --</option>
                <?php foreach($listaCategorias as $cat): ?>
                    <option value="<?=$cat['CodCat']?>" <?=$fCat == $cat['CodCat'] ? 'selected' : ''?>>
                        [<?=$cat['CodCat']?>] <?=$cat['Nombre']?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Sucursal:</label>
            <select name="sucursal">
                <option value="">Todas</option>
                <option value="CENTRAL" <?=$fSuc=='CENTRAL'?'selected':''?>>CENTRAL</option>
                <option value="DRINKS" <?=$fSuc=='DRINKS'?'selected':''?>>DRINKS</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Facturador:</label>
            <select name="facturador">
                <option value="">-- Todos --</option>
                <?php foreach($listaFacturadores as $fact): ?>
                    <option value="<?=htmlspecialchars($fact)?>" <?=$fFac==$fact?'selected':''?>><?=htmlspecialchars($fact)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">🔍 Filtrar</button>
        <button type="button" class="btn-excel" onclick="exportarExcel()">Excel 📥</button>
        <button type="button" class="btn-print" onclick="imprimirReporte()">Imprimir 🖨️</button>
    </form>

    <?php if(empty($categoriasAgrupadas)): ?>
        <p style="margin-top:20px; text-align:center; color:#777;">No se encontraron registros.</p>
    <?php else: ?>
    <div class="table-container">
        <table id="tablaVentas">
            <thead>
                <tr>
                    <th>Sucursal</th>
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th style="text-align:center">Stock Inicial</th>
                    <th style="text-align:center">Compras</th>
                    <th style="text-align:center">Traslado Entradas</th>
                    <th style="text-align:center">Traslado Salidas</th>
                    <th style="text-align:center">Cantidad Vendida</th>
                    <th style="text-align:center">Ajustes (+/-)</th>
                    <th style="text-align:center">Stock Final</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($categoriasAgrupadas as $cat): 
                    $badge_class = ($cat['SUCURSAL'] == 'CENTRAL') ? 'central' : 'drinks';
                ?>
                    <tr>
                        <td><span class='badge <?=$badge_class?>'><?=$cat['SUCURSAL']?></span></td>
                        <td><code><?=$cat['CODCAT']?></code></td>
                        <td><strong><?=htmlspecialchars($cat['CATEGORIA'])?></strong></td>
                        <td align='center'><strong><?=number_format($cat['STOCK_INICIAL'], 2, '.', ',')?></strong></td>
                        <td align='center' style="color: #2e7d32;"><strong><?=number_format($cat['COMPRAS'], 2, '.', ',')?></strong></td>
                        <td align='center' style="color: #0288d1;"><strong><?=number_format($cat['TRASLADO_ENTRADA'], 2, '.', ',')?></strong></td>
                        <td align='center' style="color: #d32f2f;"><strong><?=number_format($cat['TRASLADO_SALIDA'], 2, '.', ',')?></strong></td>
                        <td align='center'><?=number_format($cat['TOTAL_VENDIDO_CANTIDAD'], 2, '.', ',')?></td>
                        <td align='center' style="color: <?=$cat['AJUSTES'] >= 0 ? '#2e7d32' : '#d32f2f'?>;"><strong><?=number_format($cat['AJUSTES'], 2, '.', ',')?></strong></td>
                        <td align='center' style="background-color: #f1f8e9;"><strong><?=number_format($cat['STOCK_FINAL'], 2, '.', ',')?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="gran-total">
                    <td colspan="3" style="text-align:right">GRAN TOTAL GENERAL:</td>
                    <td align="center"><?=number_format($totalStockInicialGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalComprasGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalTrasladosEntradaGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalTrasladosSalidaGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalVendidoGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalAjustesGlobal, 2, '.', ',')?></td>
                    <td align="center"><?=number_format($totalStockFinalGlobal, 2, '.', ',')?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function exportarExcel() {
    let table = document.getElementById("tablaVentas");
    if(!table) return;
    TableToExcel.convert(table, { 
        name: "Reporte_Categorias_Stock_<?=date('Ymd_His')?>.xlsx",
        sheet: { name: "Categorias Stock" }
    });
}

function imprimirReporte() {
    window.print();
}
</script>
</body>
</html>