<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb + $mysqli

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

/* ============================================================
    RELACIÓN PRODUCTO → CATEGORÍA Y UNICAJA (DESDE WEB)
============================================================ */
$prodCat = [];
$catNombres = [];
$unicaja = [];

$resCat = $mysqliWeb->query("SELECT LPAD(CodCat, 4, '0') AS CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) {
    $catNombres[$c['CodCat']] = $c['Nombre'];
}

$resPC = $mysqliWeb->query("SELECT cp.sku, LPAD(cp.CodCat, 4, '0') AS CodCat, cat.Unicaja FROM catproductos cp LEFT JOIN categorias cat ON cp.CodCat = cat.CodCat");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
    $unicaja[$r['sku']] = $r['Unicaja'] ?? 1;
}

/* =====================================================
    PARÁMETROS DE FILTRO (FECHAS)
===================================================== */
$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');

$f_ini = str_replace('-', '', $f_ini_raw);
$f_fin = str_replace('-', '', $f_fin_raw);

$dt_desde = new DateTime($f_ini_raw);
$dt_hasta = new DateTime($f_fin_raw);
$dias_diferencia = $dt_desde->diff($dt_hasta)->days + 1;

/* =====================================================
    OBTENCIÓN DE VENTAS Y COSTOS (POR SUCURSAL)
===================================================== */
function obtenerDatosVentas($cnx, $f_ini, $f_fin) {
    if (!$cnx || $cnx->connect_error) return [];

    $sql = "
    SELECT 
        P.Barcode, 
        P.Descripcion AS PRODUCTO,
        SUM(V.CANTIDAD) AS CANTIDAD, 
        SUM(V.CANTIDAD * V.PRECIO_UNITARIO) AS TOTAL_VENTA,
        IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo) AS COSTO_PROMEDIO,
        SUM(V.CANTIDAD * IFNULL(CP.COSTO_PROMEDIO_COMPRA, P.costo)) AS TOTAL_COSTO
    FROM (
        SELECT DF.IDPRODUCTO, DF.CANTIDAD, DF.VALORPROD AS PRECIO_UNITARIO
        FROM FACTURAS F
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO = '0' AND F.FECHA BETWEEN '$f_ini' AND '$f_fin' AND DV.IDFACTURA IS NULL
        UNION ALL
        SELECT DP.IDPRODUCTO, DP.CANTIDAD, DP.VALORPROD AS PRECIO_UNITARIO
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
        WHERE P.ESTADO = '0' AND P.FECHA BETWEEN '$f_ini' AND '$f_fin'
    ) AS V
    INNER JOIN PRODUCTOS P ON P.IDPRODUCTO = V.IDPRODUCTO
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
    GROUP BY P.Barcode, P.Descripcion, CP.COSTO_PROMEDIO_COMPRA, P.costo
    ";

    $res = $cnx->query($sql);
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC);
}

$ventasCentral = [];
if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
    foreach (obtenerDatosVentas($mysqliCentral, $f_ini, $f_fin) as $row) {
        $ventasCentral[$row['Barcode']] = $row;
    }
}

$ventasDrinks = [];
if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
    foreach (obtenerDatosVentas($mysqliDrinks, $f_ini, $f_fin) as $row) {
        $ventasDrinks[$row['Barcode']] = $row;
    }
}

/* =====================================================
    OBTENCIÓN DE INVENTARIO ACTUAL
===================================================== */
$stockCentral = [];
if (isset($mysqliCentral)) {
    $resC = $mysqliCentral->query("SELECT p.barcode, IFNULL(SUM(i.cantidad),0) as cant FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto WHERE p.estado='1' GROUP BY p.barcode");
    while($r = $resC->fetch_assoc()){ $stockCentral[$r['barcode']] = (float)$r['cant']; }
}

$stockDrinks = [];
if (isset($mysqliDrinks)) {
    $resD = $mysqliDrinks->query("SELECT p.barcode, IFNULL(SUM(i.cantidad),0) as cant FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto WHERE p.estado='1' GROUP BY p.barcode");
    while($r = $resD->fetch_assoc()){ $stockDrinks[$r['barcode']] = (float)$r['cant']; }
}

/* =====================================================
    CONSOLIDACIÓN Y CRUCE DE DATOS
===================================================== */
$todos_barcodes = array_unique(array_merge(array_keys($ventasCentral), array_keys($ventasDrinks)));

$datosCompletos = [];
$total_ingresos = 0;
$total_costos = 0;
$total_utilidad = 0;

foreach ($todos_barcodes as $bc) {
    $cat = $prodCat[$bc] ?? 'SIN';
    $nomCat = $catNombres[$cat] ?? 'SIN CATEGORÍA';

    $prodNombre = $ventasCentral[$bc]['PRODUCTO'] ?? ($ventasDrinks[$bc]['PRODUCTO'] ?? 'Sin Nombre');

    $cantCentral = $ventasCentral[$bc]['CANTIDAD'] ?? 0;
    $ventaCentral = $ventasCentral[$bc]['TOTAL_VENTA'] ?? 0;
    $costoCentral = $ventasCentral[$bc]['TOTAL_COSTO'] ?? 0;

    $cantDrinks = $ventasDrinks[$bc]['CANTIDAD'] ?? 0;
    $ventaDrinks = $ventasDrinks[$bc]['TOTAL_VENTA'] ?? 0;
    $costoDrinks = $ventasDrinks[$bc]['TOTAL_COSTO'] ?? 0;

    // Costo promedio ponderado general combinando ambas sedes si aplica, o tomando el disponible
    $cantTotal = $cantCentral + $cantDrinks;
    $costoTotalItem = $costoCentral + $costoDrinks;
    $ventaTotalItem = $ventaCentral + $ventaDrinks;

    $costoPromedio = ($cantTotal > 0) ? ($costoTotalItem / $cantTotal) : ($ventasCentral[$bc]['COSTO_PROMEDIO'] ?? ($ventasDrinks[$bc]['COSTO_PROMEDIO'] ?? 0));
    
    $utilidadTotal = $ventaTotalItem - $costoTotalItem;
    $margen = ($ventaTotalItem > 0) ? round(($utilidadTotal / $ventaTotalItem) * 100, 2) : 0;

    $sCentral = $stockCentral[$bc] ?? 0;
    $sDrinks  = $stockDrinks[$bc] ?? 0;

    $total_ingresos += $ventaTotalItem;
    $total_costos   += $costoTotalItem;
    $total_utilidad += $utilidadTotal;

    $datosCompletos[] = [
        'CodCat'        => $cat,
        'NomCat'        => $nomCat,
        'Barcode'       => $bc,
        'Producto'      => $prodNombre,
        'StockCentral'  => $sCentral,
        'StockDrinks'   => $sDrinks,
        'StockTotal'    => $sCentral + $sDrinks,
        'CantCentral'   => $cantCentral,
        'CantDrinks'    => $cantDrinks,
        'CantTotal'     => $cantTotal,
        'CostoProm'     => $costoPromedio,
        'VentaCentral'  => $ventaCentral,
        'VentaDrinks'   => $ventaDrinks,
        'TotalVenta'    => $ventaTotalItem,
        'UtilidadTotal' => $utilidadTotal,
        'Margen'        => $margen
    ];
}

usort($datosCompletos, function($a, $b) {
    return $a['CodCat'] <=> $b['CodCat'];
});

$margen_global = ($total_ingresos > 0) ? round(($total_utilidad / $total_ingresos) * 100, 2) : 0;
$utilidad_promedio_dia = ($dias_diferencia > 0) ? ($total_utilidad / $dias_diferencia) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Integral: Inventario, Ventas y Utilidades</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-family:'Segoe UI', Arial, sans-serif; font-size:12px; background: #eceff1; margin: 20px;}
        .card{background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);}
        .filter-box{ background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6;}
        .filter-group{ display: flex; flex-direction: column; gap: 5px; }
        label{ font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
        input, select, button{ padding: 8px 12px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
        button{ background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold;}
        .btn-excel{ background: #2e7d32 !important; }
        .table-container { max-height: 600px; overflow-y: auto; margin-top: 15px; border: 1px solid #ddd; border-radius: 6px; }
        table{ border-collapse: collapse; width: 100%; background: white; white-space: nowrap; }
        th{ position: sticky; top: 0; background: #212529; color: white; padding: 10px; text-align: center; z-index: 2; font-size: 11px; cursor: pointer; user-select: none; }
        th:hover { background: #343a40; }
        th::after { content: ' ↕'; font-size: 0.8em; color: #aaa; }
        th.asc::after { content: ' ▲'; color: #fff; }
        th.desc::after { content: ' ▼'; color: #fff; }
        td{ padding: 8px 10px; border-bottom: 1px solid #eee; text-align: center; }
        td.text-left { text-align: left; }
        td.text-right { text-align: right; }
        .subtotal-row{ background: #f1f8e9; font-weight: bold; color: #33691e; }
        .gran-total{ background: #212529; color: #fff; font-weight: 900; }
        .badge{ padding: 3px 6px; border-radius: 4px; font-size: 11px; color: white; font-weight: bold; background: #546e7a; }
    </style>
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="container-fluid px-0">
    <div class="card mb-3">
        <h2 class="mb-3 text-center fw-bold">📦📊 Relación de Inventario, Ventas por Sede y Utilidades</h2>

        <form method="GET" class="filter-box mb-3">
            <div class="filter-group"><label>Desde:</label><input type="date" name="fecha_ini" value="<?=$f_ini_raw?>"></div>
            <div class="filter-group"><label>Hasta:</label><input type="date" name="fecha_fin" value="<?=$f_fin_raw?>"></div>
            
            <div class="filter-group" style="flex-grow: 1;">
                <label>Filtrar Categoría:</label>
                <select id="filtroCategoria" class="form-select" style="padding: 8px 12px; border: 1px solid #cfd8dc; border-radius: 6px;">
                    <option value="">-- Todas las Categorías --</option>
                    <?php foreach($catNombres as $cod => $nombre): ?>
                        <option value="<?=$cod?>"><?=$cod?> - <?=$nombre?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit">🔍 Filtrar</button>
            <button type="button" class="btn-excel" onclick="exportarExcel()">Excel 📥</button>
        </form>

        <!-- TARJETAS DE KPI -->
        <div class="row text-center mb-3 g-2">
            <div class="col-6 col-xl">
                <div class="card shadow-sm h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4 border-primary">
                    <div class="card-body p-2">
                        <h6 class="card-title text-muted fs-6 mb-1">Ventas Totales</h6>
                        <h4 class="text-primary fs-5 fw-bold mb-0">$<?php echo number_format($total_ingresos, 0, ',', '.'); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="card shadow-sm h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4 border-danger">
                    <div class="card-body p-2">
                        <h6 class="card-title text-muted fs-6 mb-1">Costo Total</h6>
                        <h4 class="text-danger fs-5 fw-bold mb-0">$<?php echo number_format($total_costos, 0, ',', '.'); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="card shadow-sm h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4 border-success">
                    <div class="card-body p-2">
                        <h6 class="card-title text-muted fs-6 mb-1">Utilidad Neta</h6>
                        <h4 class="<?php echo ($total_utilidad >= 0) ? 'text-success' : 'text-danger'; ?> fs-5 fw-bold mb-0">$<?php echo number_format($total_utilidad, 0, ',', '.'); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="card shadow-sm h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4 border-info">
                    <div class="card-body p-2">
                        <h6 class="card-title text-muted fs-6 mb-1">% Utilidad (Período)</h6>
                        <h4 class="<?php echo ($margen_global >= 0) ? 'text-info' : 'text-danger'; ?> fs-5 fw-bold mb-0"><?php echo $margen_global; ?>%</h4>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl">
                <div class="card shadow-sm h-100 mb-0 border-top-0 border-end-0 border-bottom-0 border-4 border-warning">
                    <div class="card-body p-2">
                        <h6 class="card-title text-muted fs-6 mb-1">Utilidad Prom. / Día</h6>
                        <h4 class="<?php echo ($utilidad_promedio_dia >= 0) ? 'text-success' : 'text-danger'; ?> fs-5 fw-bold mb-0">$<?php echo number_format($utilidad_promedio_dia, 0, ',', '.'); ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <?php if(empty($datosCompletos)): ?>
            <p style="margin-top:20px; text-align:center; color:#777;">No se encontraron registros de ventas para el período seleccionado.</p>
        <?php else: ?>
        <div class="table-container">
            <table id="tablaInventarioVentas">
                <thead>
                    <tr>
                        <th data-type="text" data-index="0">CodCat</th>
                        <th data-type="text" data-index="1">Categoría</th>
                        <th data-type="text" data-index="2">Barcode</th>
                        <th data-type="text" data-index="3" class="text-left">Producto</th>
                        <th data-type="number" data-index="4">Stock Central</th>
                        <th data-type="number" data-index="5">Stock Drinks</th>
                        <th data-type="number" data-index="6">Stock Total</th>
                        <th data-type="number" data-index="7">Venta Drinks</th>
                        <th data-type="number" data-index="8">Venta Central</th>
                        <th data-type="number" data-index="9">Costo Prom.</th>
                        <th data-type="number" data-index="10" class="text-right">Total Venta</th>
                        <th data-type="number" data-index="11" class="text-right">Utilidad Total</th>
                        <th data-type="number" data-index="12">Margen</th>
                    </tr>
                </thead>
                <tbody id="cuerpoTabla">
                    <?php
                    $lastCat = null;
                    $subVentaC = $subVentaD = $subVentaTot = $subUtilidad = $subStockC = $subStockD = $subStockT = 0;
                    $genVentaC = $genVentaD = $genVentaTot = $genUtilidad = $genStockC = $genStockD = $genStockT = 0;

                    foreach($datosCompletos as $row) {
                        if($lastCat !== null && $lastCat !== $row['CodCat']) {
                            $subMargen = ($subVentaTot > 0) ? round(($subUtilidad / $subVentaTot) * 100, 2) : 0;
                            echo "<tr class='subtotal-row' data-codcat='$lastCat' data-subtotal='true'>
                                <td colspan='4' class='text-right'>SUBTOTAL CATEGORÍA ($lastCat):</td>
                                <td>".number_format($subStockC, 0)."</td>
                                <td>".number_format($subStockD, 0)."</td>
                                <td>".number_format($subStockT, 0)."</td>
                                <td class='text-right'>$ ".number_format($subVentaD, 0, ',', '.')."</td>
                                <td class='text-right'>$ ".number_format($subVentaC, 0, ',', '.')."</td>
                                <td>-</td>
                                <td class='text-right'>$ ".number_format($subVentaTot, 0, ',', '.')."</td>
                                <td class='text-right'>$ ".number_format($subUtilidad, 0, ',', '.')."</td>
                                <td>$subMargen%</td>
                            </tr>";
                            $subVentaC = $subVentaD = $subVentaTot = $subUtilidad = $subStockC = $subStockD = $subStockT = 0;
                        }

                        $subStockC   += $row['StockCentral'];
                        $subStockD   += $row['StockDrinks'];
                        $subStockT   += $row['StockTotal'];
                        $subVentaD   += $row['VentaDrinks'];
                        $subVentaC   += $row['VentaCentral'];
                        $subVentaTot += $row['TotalVenta'];
                        $subUtilidad += $row['UtilidadTotal'];

                        $genStockC   += $row['StockCentral'];
                        $genStockD   += $row['StockDrinks'];
                        $genStockT   += $row['StockTotal'];
                        $genVentaD   += $row['VentaDrinks'];
                        $genVentaC   += $row['VentaCentral'];
                        $genVentaTot += $row['TotalVenta'];
                        $genUtilidad += $row['UtilidadTotal'];

                        $lastCat = $row['CodCat'];
                    ?>
                    <tr data-codcat="<?=$row['CodCat']?>">
                        <td><span class="badge"><?=$row['CodCat']?></span></td>
                        <td class="text-left"><strong><?=$row['NomCat']?></strong></td>
                        <td><code><?=$row['Barcode']?></code></td>
                        <td class="text-left"><?=$row['Producto']?></td>
                        <td><?=number_format($row['StockCentral'], 0)?></td>
                        <td><?=number_format($row['StockDrinks'], 0)?></td>
                        <td><strong><?=number_format($row['StockTotal'], 0)?></strong></td>
                        <td class="text-right">$ <?=number_format($row['VentaDrinks'], 0, ',', '.')?></td>
                        <td class="text-right">$ <?=number_format($row['VentaCentral'], 0, ',', '.')?></td>
                        <td class="text-right">$ <?=number_format($row['CostoProm'], 2, ',', '.')?></td>
                        <td class="text-right"><strong>$ <?=number_format($row['TotalVenta'], 0, ',', '.')?></strong></td>
                        <td class="text-right <?=$row['UtilidadTotal'] >= 0 ? 'text-success' : 'text-danger'?>"><strong>$ <?=number_format($row['UtilidadTotal'], 0, ',', '.')?></strong></td>
                        <td><span class="badge bg-<?=$row['Margen'] >= 0 ? 'info' : 'danger'?>"><?=$row['Margen']?>%</span></td>
                    </tr>
                    <?php } ?>
                    
                    <?php 
                        $subMargen = ($subVentaTot > 0) ? round(($subUtilidad / $subVentaTot) * 100, 2) : 0;
                    ?>
                    <tr class='subtotal-row' data-codcat='<?=$lastCat?>' data-subtotal='true'>
                        <td colspan='4' class='text-right'>SUBTOTAL CATEGORÍA (<?=$lastCat?>):</td>
                        <td><?=number_format($subStockC, 0)?></td>
                        <td><?=number_format($subStockD, 0)?></td>
                        <td><?=number_format($subStockT, 0)?></td>
                        <td class='text-right'>$ <?=number_format($subVentaD, 0, ',', '.')?></td>
                        <td class='text-right'>$ <?=number_format($subVentaC, 0, ',', '.')?></td>
                        <td>-</td>
                        <td class='text-right'>$ <?=number_format($subVentaTot, 0, ',', '.')?></td>
                        <td class='text-right'>$ <?=number_format($subUtilidad, 0, ',', '.')?></td>
                        <td><?=$subMargen?>%</td>
                    </tr>
                </tbody>
                <tfoot>
                    <?php 
                        $genMargen = ($genVentaTot > 0) ? round(($genUtilidad / $genVentaTot) * 100, 2) : 0;
                    ?>
                    <tr class="gran-total">
                        <td colspan="4" class="text-right">GRAN TOTAL GENERAL:</td>
                        <td><?=number_format($genStockC, 0)?></td>
                        <td><?=number_format($genStockD, 0)?></td>
                        <td><?=number_format($genStockT, 0)?></td>
                        <td class="text-right">$ <?=number_format($genVentaD, 0, ',', '.')?></td>
                        <td class="text-right">$ <?=number_format($genVentaC, 0, ',', '.')?></td>
                        <td>-</td>
                        <td class="text-right">$ <?=number_format($genVentaTot, 0, ',', '.')?></td>
                        <td class="text-right">$ <?=number_format($genUtilidad, 0, ',', '.')?></td>
                        <td><?=$genMargen?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const selectCat = document.getElementById("filtroCategoria");
    const cuerpoTabla = document.getElementById("cuerpoTabla");
    const tabla = document.getElementById("tablaInventarioVentas");

    if (selectCat && cuerpoTabla) {
        selectCat.addEventListener("change", function() {
            const catSeleccionada = this.value.trim();
            const filas = cuerpoTabla.querySelectorAll("tr");

            filas.forEach(fila => {
                const codCatFila = fila.getAttribute("data-codcat");
                if (catSeleccionada === "" || codCatFila === catSeleccionada) {
                    fila.style.display = "";
                } else {
                    fila.style.display = "none";
                }
            });
        });
    }

    if (tabla) {
        const headers = tabla.querySelectorAll("thead th");
        headers.forEach(th => {
            th.addEventListener("click", function() {
                const index = parseInt(this.getAttribute("data-index"));
                const dataType = this.getAttribute("data-type");
                const isAscending = this.classList.contains("asc");

                headers.forEach(h => h.classList.remove("asc", "desc"));
                this.classList.toggle("asc", !isAscending);
                this.classList.toggle("desc", isAscending);

                const filasProd = Array.from(cuerpoTabla.querySelectorAll("tr:not([data-subtotal])"));
                const filasSub = Array.from(cuerpoTabla.querySelectorAll("tr[data-subtotal]"));

                filasProd.sort((a, b) => {
                    const cellA = a.children[index] ? a.children[index].textContent.trim() : "";
                    const cellB = b.children[index] ? b.children[index].textContent.trim() : "";

                    if (dataType === "number") {
                        const numA = parseFloat(cellA.replace(/[^0-9.-]+/g, "")) || 0;
                        const numB = parseFloat(cellB.replace(/[^0-9.-]+/g, "")) || 0;
                        return !isAscending ? numB - numA : numA - numB;
                    } else {
                        return !isAscending ? cellB.localeCompare(cellA) : cellA.localeCompare(cellB);
                    }
                });

                filasProd.forEach(fila => cuerpoTabla.appendChild(fila));
                filasSub.forEach(fila => cuerpoTabla.appendChild(fila));
            });
        });
    }
});

function exportarExcel() {
    let table = document.getElementById("tablaInventarioVentas");
    if(!table) return;
    TableToExcel.convert(table, { 
        name: "Inventario_Ventas_Sede_<?=date('Ymd_His')?>.xlsx",
        sheet: { name: "VentasPorSede" }
    });
}
</script>
</body>
</html>