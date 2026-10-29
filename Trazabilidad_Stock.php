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
$UsuarioSesion = $_SESSION['CedulaNit'] ?? '';
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
    LÓGICA DE OBTENCIÓN DE COMPRAS DIRECTAS
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

// Obtener listado completo de categorías
$listaCategorias = [];
if (isset($mysqliWeb)) {
    $qCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE ESTADO='1' ORDER BY Nombre ASC");
    if ($qCat) {
        while ($c = $qCat->fetch_assoc()) {
            $listaCategorias[] = $c;
        }
    }
}

// Obtener datos desde la base web asociados por Sku
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

foreach ($rows as &$r) {
    $r['CODCAT']    = $datosWeb[$r['Barcode']]['CodCat'] ?? 'N/A';
    $r['CATEGORIA'] = $datosWeb[$r['Barcode']]['Categoria'] ?? 'SIN CATEGORIA';
    $r['UNICAYA_VAL'] = $datosWeb[$r['Barcode']]['Unicaja'] ?? 1;
}
unset($r);

if ($fCat != '') {
    $rows = array_filter($rows, function($r) use ($fCat) {
        return $r['CODCAT'] == $fCat;
    });
}

// ---------------------------------------------------------
// OBTENER STOCK INICIAL
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
// OBTENER MOVIMIENTOS DE INVENTARIO
// ---------------------------------------------------------
$trasladosEntradasAgrupadas = [];
$trasladosSalidasAgrupadas  = [];

if (isset($mysqliWeb)) {
    $nitCentralVal = NIT_CENTRAL;
    $nitDrinksVal  = NIT_DRINKS;
    $fIniMov = $f_ini_raw . ' 00:00:00';
    $fFinMov = $f_fin_raw . ' 23:59:59';

    $qBarcodes = $mysqliWeb->prepare("SELECT DISTINCT TRIM(barcode) AS barcode FROM inventario_movimientos WHERE fecha BETWEEN ? AND ? AND Estado = 1 AND Aprobado = 1");
    if ($qBarcodes) {
        $qBarcodes->bind_param("ss", $fIniMov, $fFinMov);
        $qBarcodes->execute();
        $resB = $qBarcodes->get_result();
        $movBarcodes = [];
        while($rb = $resB->fetch_assoc()) {
            if (!empty($rb['barcode'])) $movBarcodes[] = $rb['barcode'];
        }
        $qBarcodes->close();

        if (!empty($movBarcodes)) {
            $listaMovSkus = "'" . implode("','", array_map([$mysqliWeb, 'real_escape_string'], array_unique($movBarcodes))) . "'";
            $qWebMov = $mysqliWeb->query("SELECT cp.Sku, cat.CodCat, cat.Unicaja, cat.Nombre AS CATEGORIA FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat WHERE cp.Sku IN ($listaMovSkus)");
            if ($qWebMov) {
                while ($u = $qWebMov->fetch_assoc()) {
                    $datosWeb[$u['Sku']] = [
                        'CodCat'    => $u['CodCat'] ?? 'N/A',
                        'Unicaja'   => $u['Unicaja'] ?? 1,
                        'Categoria' => $u['CATEGORIA'] ?? 'SIN CATEGORIA'
                    ];
                }
            }
        }
    }

    $sqlMov = "
        SELECT 
            TRIM(m.barcode) AS barcode, 
            m.NitEmpresa_Orig, 
            m.NitEmpresa_Dest, 
            UPPER(TRIM(m.tipo)) AS tipo, 
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
            if ($codCatSku == 'N/A') continue;

            $cantMov = floatval($m['cant']);
            $tipoMov = $m['tipo']; 

            $sucOrig = '';
            if ($m['NitEmpresa_Orig'] == $nitCentralVal) $sucOrig = 'CENTRAL';
            elseif ($m['NitEmpresa_Orig'] == $nitDrinksVal) $sucOrig = 'DRINKS';

            $sucDest = '';
            if ($m['NitEmpresa_Dest'] == $nitCentralVal) $sucDest = 'CENTRAL';
            elseif ($m['NitEmpresa_Dest'] == $nitDrinksVal) $sucDest = 'DRINKS';

            if (!empty($sucOrig) && !empty($sucDest) && $sucOrig != $sucDest) {
                $trasladosSalidasAgrupadas[$codCatSku][$sucOrig]  = ($trasladosSalidasAgrupadas[$codCatSku][$sucOrig] ?? 0) + $cantMov;
                $trasladosEntradasAgrupadas[$codCatSku][$sucDest] = ($trasladosEntradasAgrupadas[$codCatSku][$sucDest] ?? 0) + $cantMov;
            }
        }
        $stmtMov->close();
    }
}

// ---------------------------------------------------------
// OBTENER AJUSTES DESDE 'historial_ajustes'
// ---------------------------------------------------------
$ajustesNetosAgrupados = [];

if (isset($mysqliWeb)) {
    $fIniAjuste = $f_ini_raw . ' 00:00:00';
    $fFinAjuste = $f_fin_raw . ' 23:59:59';

    $sqlAjustes = "
        SELECT 
            nit_empresa, 
            TRIM(categoria) AS categoria, 
            SUM(diferencia_aplicada) AS total_diferencia 
        FROM historial_ajustes 
        WHERE fecha_ajuste BETWEEN ? AND ? 
        GROUP BY nit_empresa, categoria
    ";

    $stmtAjt = $mysqliWeb->prepare($sqlAjustes);
    if ($stmtAjt) {
        $stmtAjt->bind_param("ss", $fIniAjuste, $fFinAjuste);
        $stmtAjt->execute();
        $resAjt = $stmtAjt->get_result();
        while($ajt = $resAjt->fetch_assoc()) {
            $nitEmpresa = $ajt['nit_empresa'];
            $codCat     = $ajt['categoria'];
            $dif        = floatval($ajt['total_diferencia']);

            $sucursalAjuste = '';
            if ($nitEmpresa == NIT_CENTRAL) {
                $sucursalAjuste = 'CENTRAL';
            } elseif ($nitEmpresa == NIT_DRINKS) {
                $sucursalAjuste = 'DRINKS';
            }

            if ($sucursalAjuste != '' && !empty($codCat)) {
                $ajustesNetosAgrupados[$codCat][$sucursalAjuste] = ($ajustesNetosAgrupados[$codCat][$sucursalAjuste] ?? 0) + $dif;
            }
        }
        $stmtAjt->close();
    }
}

// ---------------------------------------------------------
// OBTENER STOCK A HOY
// ---------------------------------------------------------
function obtenerStockHoyConQuery($cnx, $mysqliWeb) {
    if (!$cnx || $cnx->connect_error || !$mysqliWeb || $mysqliWeb->connect_error) return [];
    
    $mapaWebCompleto = [];
    $qWeb = $mysqliWeb->query("SELECT TRIM(cp.Sku) AS Sku, cat.CodCat FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat");
    if ($qWeb) {
        while ($row = $qWeb->fetch_assoc()) {
            if (!empty($row['Sku'])) {
                $mapaWebCompleto[$row['Sku']] = $row['CodCat'];
            }
        }
    }

    $stockCat = [];
    $sqlAll = "SELECT TRIM(p.barcode) AS barcode, IFNULL(SUM(i.cantidad), 0) AS cant 
               FROM productos p 
               LEFT JOIN inventario i ON p.idproducto = i.idproducto 
               WHERE p.estado = '1' 
               GROUP BY TRIM(p.barcode)";
    
    $res = $cnx->query($sqlAll);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $barcode = $row['barcode'];
            $cant = floatval($row['cant']);
            
            $codCat = $mapaWebCompleto[$barcode] ?? 'N/A';
            if ($codCat !== 'N/A') {
                $stockCat[$codCat] = ($stockCat[$codCat] ?? 0) + $cant;
            }
        }
    }
    return $stockCat;
}

$stockHoyCentral = (isset($mysqliCentral) && !$mysqliCentral->connect_error) ? obtenerStockHoyConQuery($mysqliCentral, $mysqliWeb) : [];
$stockHoyDrinks  = (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) ? obtenerStockHoyConQuery($mysqliDrinks, $mysqliWeb) : [];

// Agrupar compras
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

// Consolidar datos por Categoría y Sucursal
$categoriasAgrupadas = [];
$totalVendidoGlobal            = 0;
$totalStockInicialGlobal       = 0;
$totalStockFinalGlobal         = 0;
$totalComprasGlobal            = 0;
$totalTrasladosEntradaGlobal   = 0;
$totalTrasladosSalidaGlobal    = 0;
$totalAjustesGlobal            = 0;
$totalStockHoyGlobal           = 0;
$totalDiferenciaGlobal         = 0;

$allCodCats = array_keys($stockInicialAgrupado);
foreach($rows as $r) { $allCodCats[] = $r['CODCAT']; }
foreach(array_keys($comprasAgrupadas) as $cCat) { $allCodCats[] = $cCat; }
foreach(array_keys($trasladosEntradasAgrupadas) as $tCat) { $allCodCats[] = $tCat; }
foreach(array_keys($trasladosSalidasAgrupadas) as $tCat) { $allCodCats[] = $tCat; }
foreach(array_keys($ajustesNetosAgrupados) as $aCat) { $allCodCats[] = $aCat; }
foreach(array_keys($stockHoyCentral) as $sCat) { $allCodCats[] = $sCat; }
foreach(array_keys($stockHoyDrinks) as $sCat) { $allCodCats[] = $sCat; }
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

        $stockHoyCat = ($sucursal == 'CENTRAL') ? ($stockHoyCentral[$codCat] ?? 0) : ($stockHoyDrinks[$codCat] ?? 0);

        $ventasCat = 0;
        foreach($rows as $r) {
            if ($r['CODCAT'] == $codCat && $r['SUCURSAL'] == $sucursal) {
                $ventasCat += $r['CANTIDAD'];
            }
        }

        if ($stockIniCat == 0 && $comprasCat == 0 && $traslEntra == 0 && $traslSalida == 0 && $ajusteCat == 0 && $ventasCat == 0 && $stockHoyCat == 0) {
            continue;
        }

        $stockFinal = ($stockIniCat + $comprasCat + $traslEntra) - ($traslSalida + $ventasCat) + $ajusteCat;
        
        $diferencia = $stockHoyCat - $stockFinal;

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
                'STOCK_FINAL'           => $stockFinal,
                'STOCK_HOY'             => $stockHoyCat,
                'DIFERENCIA'            => $diferencia
            ];
            
            $totalStockInicialGlobal     += $stockIniCat;
            $totalComprasGlobal          += $comprasCat;
            $totalTrasladosEntradaGlobal += $traslEntra;
            $totalTrasladosSalidaGlobal  += $traslSalida;
            $totalVendidoGlobal          += $ventasCat;
            $totalAjustesGlobal          += $ajusteCat;
            $totalStockFinalGlobal       += $stockFinal;
            $totalStockHoyGlobal         += $stockHoyCat;
            $totalDiferenciaGlobal       += $diferencia;
        }
    }
}

usort($categoriasAgrupadas, function($a, $b) {
    return strcmp($a['CODCAT'], $b['CODCAT']);
});

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Consolidado por Categorías - Sistema Drinks</title>
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        html, body { 
            height: 100%; 
            margin: 0; 
            padding: 0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #eceff1;
        }
        
        body { 
            font-size: 13px; 
            padding: 10px; 
            box-sizing: border-box; 
            display: flex; 
            flex-direction: column;
        }

        .card {
            background: white; 
            padding: 15px; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            box-sizing: border-box;
            min-height: 0;
        }

        h2 {
            font-size: 1.2rem;
            margin-top: 0;
            margin-bottom: 15px;
            color: #263238;
        }

        .filter-box { 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 8px; 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
            align-items: flex-end; 
            border: 1px solid #dee2e6;
            margin-bottom: 15px;
        }

        .filter-group { 
            display: flex; 
            flex-direction: column; 
            gap: 4px; 
            flex: 1 1 140px;
            min-width: 120px;
        }

        label { 
            font-size: 10px; 
            font-weight: bold; 
            color: #546e7a; 
            text-transform: uppercase;
        }

        input, select, button { 
            padding: 8px 10px; 
            border: 1px solid #cfd8dc; 
            border-radius: 6px; 
            outline: none;
            font-size: 12px;
            width: 100%;
            box-sizing: border-box;
        }

        /* Ajuste visual para Select2 */
        .select2-container .select2-selection--single {
            height: 36px !important;
            border: 1px solid #cfd8dc !important;
            border-radius: 6px !important;
            padding-top: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 34px !important;
        }

        .button-group {
            display: flex;
            gap: 8px;
            flex: 1 1 220px;
        }

        button { 
            background: #0288d1; 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-weight: bold;
            transition: background 0.2s;
        }

        button:hover { opacity: 0.9; }
        .btn-excel { background: #2e7d32 !important; }
        .btn-print { background: #455a64 !important; }

        .table-container { 
            flex: 1; 
            overflow: auto; 
            border: 1px solid #cfd8dc; 
            border-radius: 6px;
            position: relative;
        }

        table { 
            border-collapse: separate; 
            border-spacing: 0;
            width: 100%; 
            background: white; 
            white-space: nowrap;
        }

        th { 
            position: sticky; 
            top: 0; 
            background: #263238; 
            color: white; 
            padding: 10px; 
            text-align: left; 
            z-index: 10; 
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 2px solid #102a43;
        }

        td { 
            padding: 8px 10px; 
            border-bottom: 1px solid #eceff1; 
            font-size: 12px;
        }

        tr:hover td {
            background-color: #f5f5f5;
        }

        tfoot tr.gran-total td {
            position: sticky;
            bottom: 0;
            background: #263238;
            color: #fff;
            font-weight: bold;
            z-index: 9;
            border-top: 2px solid #102a43;
        }

        .badge { 
            padding: 3px 6px; 
            border-radius: 4px; 
            font-size: 10px; 
            color: white; 
            font-weight: bold; 
            display: inline-block;
        }
        .central { background: #1565c0; } 
        .drinks { background: #2e7d32; }

        @media print {
            .filter-box, button, .select2 {
                display: none !important;
            }

            body, html {
                background: white !important;
                padding: 0 !important;
                font-size: 10px !important;
            }

            .card {
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
            }

            .table-container {
                overflow: visible !important;
                border: none !important;
                max-height: none !important;
            }

            table {
                width: 100% !important;
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            th, td {
                padding: 5px 6px !important;
                font-size: 9px !important;
                border-color: #999 !important;
            }

            th, tfoot tr.gran-total td {
                background-color: #263238 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media (max-width: 768px) {
            body { padding: 5px; }
            .card { padding: 10px; }
            .filter-group { flex: 1 1 100%; }
            .button-group { flex: 1 1 100%; }
            h2 { font-size: 1rem; }
            td, th { padding: 6px 8px; font-size: 11px; }
        }
    </style>
    <!-- jQuery y Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="card">
    <h2>📊 Reporte Consolidado por Categorías</h2>

    <form method="GET" class="filter-box">
        <div class="filter-group"><label>Desde:</label><input type="date" name="fecha_ini" value="<?=$f_ini_raw?>"></div>
        <div class="filter-group"><label>Hasta:</label><input type="date" name="fecha_fin" value="<?=$f_fin_raw?>"></div>
        
        <div class="filter-group">
            <label>Categoría:</label>
            <select name="categoria" id="select-categoria">
                <option value="">-- Todas las Categorías --</option>
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

        <div class="button-group">
            <button type="submit">🔍 Filtrar</button>
            <button type="button" class="btn-excel" onclick="exportarExcel()">Excel 📥</button>
            <button type="button" class="btn-print" onclick="imprimirReporte()">Imprimir 🖨️</button>
        </div>
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
                    <th style="text-align:center">Stock Calc</th>
                    <th style="text-align:center">Stock Sist</th>
                    <th style="text-align:center; background-color: #37474f;">Diferencia (Sist - Calc)</th>
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
                        <td align='center' style="background-color: #e3f2fd; color: #01579b;"><strong><?=number_format($cat['STOCK_HOY'], 2, '.', ',')?></strong></td>
                        <td align='center' style="background-color: #fff3e0; color: <?=$cat['DIFERENCIA'] >= 0 ? '#2e7d32' : '#d32f2f'?>;"><strong><?=number_format($cat['DIFERENCIA'], 2, '.', ',')?></strong></td>
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
                    <td align="center"><?=number_format($totalStockHoyGlobal, 2, '.', ',')?></td>
                    <td align="center" style="background-color: #e65100;"><?=number_format($totalDiferenciaGlobal, 2, '.', ',')?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    // Activar Select2 en el selector de categoría
    $('#select-categoria').select2({
        placeholder: "-- Seleccione o escriba una categoría --",
        allowClear: true,
        width: '100%'
    });
});

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