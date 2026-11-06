<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   FUNCIÓN DE PRECIO DE COMPRA POR PRODUCTO (CORREGIDA Y EXACTA)
============================================================ */
/* ============================================================
   FUNCIÓN DE PRECIO DE COMPRA POR PRODUCTO (CORREGIDA Y NORMALIZADA)
============================================================ */
function precioCompraExacto($mysqli_conn){
    // Calcula el costo unitario real asegurando la correcta proporción entre el valor total de la compra y las unidades
    $sql = "SELECT 
                p.barcode,
                ROUND(
                    IFNULL(
                        (SUM(d.VALOR - IFNULL(d.descuento, 0)) * (1 + (IFNULL(MAX(d.porciva), 0) / 100)) + SUM(IFNULL(d.ValICUIUni, IFNULL(d.ValIBUAUni, 0)) * d.cantidad)) 
                        / NULLIF(SUM(d.cantidad), 0),
                        p.ultcosto
                    ), 
                    0
                ) AS costo_unitario_real
            FROM detcompras d
            JOIN compras c ON c.idcompra = d.idcompra
            JOIN productos p ON p.idproducto = d.idproducto
            JOIN (
                SELECT d2.idproducto, MAX(c2.idcompra) as max_idcompra
                FROM detcompras d2
                JOIN compras c2 ON c2.idcompra = d2.idcompra
                WHERE c2.estado = 1
                GROUP BY d2.idproducto
            ) ultima ON ultima.idproducto = d.idproducto AND ultima.max_idcompra = c.idcompra
            WHERE p.estado = '1' AND c.estado = 1
            GROUP BY p.barcode, p.ultcosto";
    
    $out = []; 
    $r = $mysqli_conn->query($sql);
    while($r && $x = $r->fetch_assoc()) {
        $out[$x['barcode']] = (float)$x['costo_unitario_real'];
    }

    // Fallback: Si no hay registro en detcompras, usa ultcosto directo de la tabla de productos
    $sqlFallback = "SELECT barcode, ROUND(IFNULL(ultcosto, 0), 0) AS ultcosto FROM productos WHERE estado = '1'";
    $rFB = $mysqli_conn->query($sqlFallback);
    while($rFB && $xFB = $rFB->fetch_assoc()) {
        if (!isset($out[$xFB['barcode']]) || $out[$xFB['barcode']] <= 0) {
            $out[$xFB['barcode']] = (float)$xFB['ultcosto'];
        }
    }

    return $out;
}/* ============================================================
   FUNCIÓN DE PRECIO PROMEDIO DE VENTA
============================================================ */
function precioPromVenta($mysqli_conn){
    $sql = "SELECT 
                PRODUCTOS.Barcode,
                ROUND(SUM(DETALLE.CANTIDAD * DETALLE.VALORPROD) / NULLIF(SUM(DETALLE.CANTIDAD), 0), 0) AS PRECIO_PROMEDIO
            FROM (
                SELECT IDPRODUCTO, CANTIDAD, VALORPROD FROM DETFACTURAS
                INNER JOIN FACTURAS ON FACTURAS.IDFACTURA = DETFACTURAS.IDFACTURA
                WHERE FACTURAS.ESTADO = '0' 
                  AND STR_TO_DATE(FACTURAS.FECHA, '%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                
                UNION ALL
                
                SELECT IDPRODUCTO, CANTIDAD, VALORPROD FROM DETPEDIDOS
                INNER JOIN PEDIDOS ON PEDIDOS.IDPEDIDO = DETPEDIDOS.IDPEDIDO
                WHERE PEDIDOS.ESTADO = '0' 
                  AND STR_TO_DATE(PEDIDOS.FECHA, '%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
            ) AS DETALLE
            INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETALLE.IDPRODUCTO
            WHERE PRODUCTOS.estado = '1'
            GROUP BY PRODUCTOS.Barcode";

    $out = [];
    $r = $mysqli_conn->query($sql);
    while($r && $x = $r->fetch_assoc()){
        $out[$x['Barcode']] = (float)$x['PRECIO_PROMEDIO'];
    }
    return $out;
}

/* ============================================================
   RELACIÓN PRODUCTO → CATEGORÍA
============================================================ */
$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, LPAD(CodCat, 4, '0') AS CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
}

/* ============================================================
   LÓGICA DE BACKUP: INSERTAR O ACTUALIZAR (UPSERT)
============================================================ */
$total_registros_guardados = 0;
if (isset($_GET['action']) && $_GET['action'] === 'backup_db') {
    $fecha_actual = date("Y-m-d");

    $sqlAll = "SELECT p.barcode, IFNULL(SUM(i.cantidad),0) as cant 
               FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
               WHERE p.estado='1' GROUP BY p.barcode";

    $resC_all = $mysqliCentral->query($sqlAll);
    $dataC = [];
    while($r = $resC_all->fetch_assoc()){ $dataC[$r['barcode']] = $r; }

    $resD_all = $mysqliDrinks->query($sqlAll);
    $dataD = [];
    while($r = $resD_all->fetch_assoc()){ $dataD[$r['barcode']] = $r; }

    $todos_barcodes = array_unique(array_merge(array_keys($dataC), array_keys($dataD)));

    $sqlUpsert = "INSERT INTO historial_stock 
                  (barcode, codcat, stock_central, stock_drinks, stock_total, fecha_registro) 
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE 
                  stock_central = VALUES(stock_central),
                  stock_drinks  = VALUES(stock_drinks),
                  stock_total   = VALUES(stock_total),
                  codcat        = VALUES(codcat)";

    $stmtIns = $mysqliWeb->prepare($sqlUpsert);
    if (!$stmtIns) {
        die("Error en prepare: " . $mysqliWeb->error);
    }

    foreach($todos_barcodes as $bc){
        $cat_ins  = $prodCat[$bc] ?? 'SIN';
        $c_ins    = (float)($dataC[$bc]['cant'] ?? 0);
        $d_ins    = (float)($dataD[$bc]['cant'] ?? 0);
        $t_ins    = (float)($c_ins + $d_ins);
        
        $stmtIns->bind_param("ssddds", $bc, $cat_ins, $c_ins, $d_ins, $t_ins, $fecha_actual);
        
        if (!$stmtIns->execute()) {
            die("Error en execute para barcode $bc: " . $stmtIns->error);
        }
        $total_registros_guardados++;
    }
    $msg_backup = "✅ Respaldo completo: Stock y Categorías actualizados para hoy ($fecha_actual). Total de registros procesados: <strong>{$total_registros_guardados}</strong>.";
}

/* ============================================================
   PARÁMETROS DE VISTA (FILTROS Y FECHAS)
============================================================ */
$categoria       = $_GET['categoria'] ?? '';
$filtroUtilidad  = isset($_GET['filtro_utilidad']) && $_GET['filtro_utilidad'] !== '' ? (float)$_GET['filtro_utilidad'] : '';

$fHasta = $_GET['f_hasta'] ?? date("Y-m-d");
$fDesde = $_GET['f_desde'] ?? date("Y-m-d", strtotime("-3 months"));

$preciosCentral = precioCompraExacto($mysqliCentral);
$preciosDrinks  = precioCompraExacto($mysqliDrinks);

$ventasCentral = precioPromVenta($mysqliCentral);
$ventasDrinks  = precioPromVenta($mysqliDrinks);

$cats = [];
$resCat = $mysqliWeb->query("SELECT LPAD(CodCat, 4, '0') AS CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) { 
    $cats[$c['CodCat']] = $c['Nombre']; 
}

$sqlCentralQuery = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) cantidad FROM productos p
                    LEFT JOIN inventario i ON p.idproducto = i.idproducto
                    WHERE p.estado = '1' 
                    GROUP BY p.barcode, p.descripcion";

$resC = $mysqliCentral->query($sqlCentralQuery);
$central = [];
$nombres_prod = [];
while ($r = $resC->fetch_assoc()) { 
    $central[$r['barcode']] = $r; 
    $nombres_prod[$r['barcode']] = $r['descripcion'];
}

$resD = $mysqliDrinks->query($sqlCentralQuery);
$drinks = [];
while ($r = $resD->fetch_assoc()) { 
    $drinks[$r['barcode']] = $r; 
    if(!isset($nombres_prod[$r['barcode']])) {
        $nombres_prod[$r['barcode']] = $r['descripcion'];
    }
}

$todos_barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));

$barcodes_filtrados = [];
foreach ($todos_barcodes as $b) {
    $catProd = $prodCat[$b] ?? 'SIN';
    if ($categoria !== '' && $catProd !== $categoria) {
        continue; 
    }
    $barcodes_filtrados[] = $b;
}

usort($barcodes_filtrados, function($a, $b) use ($nombres_prod) {
    return strcmp($nombres_prod[$a] ?? '', $nombres_prod[$b] ?? '');
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Inventario Activo por Producto</title>
    <style>
        body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
        .container{max-width:1650px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
        h2{color:#2c3e50;text-align:center;margin-bottom:20px}
        
        /* Tarjeta de Resumen en la Cabecera */
        .header-summary {
            background: #2c3e50;
            color: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 15px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .summary-item .title { font-size: 11px; text-transform: uppercase; color: #adb5bd; margin-bottom: 5px; font-weight: bold; }
        .summary-item .value { font-size: 15px; font-weight: bold; color: #fff; }
        .summary-item .value.green { color: #28a745; }

        form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center;align-items:center}
        select,input,button{padding:8px 12px;border-radius:6px;border:1px solid #ccc;font-size:14px}
        button{background:#007bff;color:#fff;border:none;cursor:pointer}
        .table-container{overflow-x:auto;border-radius:12px;background:#fff}
        table{width:100%;border-collapse:collapse;min-width:1300px}
        th,td{padding:10px 8px;text-align:center;border-bottom:1px solid #e1e1e1;font-size: 13px;}
        th{background:#007bff;color:#fff}
        tr.item-row{background:#fff;}
        tr.item-row:hover{background:#f1f5f9;}
        
        tr.alerta-utilidad { background-color: #f8d7da !important; color: #721c24; }
        tr.alerta-utilidad td { color: #721c24; }

        .alert { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; border: 1px solid #bee5eb; }
    </style>
</head>
<body>

<div class="container">
    <h2>📦 Resumen de Inventario Activo por Producto (Costo de Compra Gerencial y Valorización)</h2>

    <?php if(isset($msg_backup)): ?>
        <div class="alert"><?= $msg_backup ?></div>
    <?php endif; ?>

    <?php
    // Pre-procesamiento global para la cabecera
    $accCompra = 0; $accVenta = 0; $gD = 0; $gC = 0; $gT = 0;
    $gSumaC = 0; $gCountC = 0; $gSumaV = 0; $gCountV = 0;

    foreach ($barcodes_filtrados as $b) {
        $cantD = $drinks[$b]['cantidad'] ?? 0;
        $cantC = $central[$b]['cantidad'] ?? 0;
        $totalUnit = $cantC + $cantD;

        $pvC = $preciosCentral[$b] ?? 0;
        $pvD = $preciosDrinks[$b] ?? 0;
        $pCompra = ($pvC > 0) ? $pvC : $pvD;

        $vtC = $ventasCentral[$b] ?? 0;
        $vtD = $ventasDrinks[$b] ?? 0;
        $pVenta = ($vtC > 0) ? $vtC : $vtD;

        $gPrc = ($pCompra > 0) ? (($pVenta - $pCompra) / $pCompra) * 100 : 0;
        if ($filtroUtilidad !== '' && $gPrc >= $filtroUtilidad) { continue; }

        $vComp = $pCompra * $totalUnit;
        $vVent = $pVenta * $totalUnit;

        $accCompra += $vComp;
        $accVenta += $vVent;
        $gD += $cantD;
        $gC += $cantC;
        $gT += $totalUnit;

        if ($pCompra > 0) { $gSumaC += $pCompra; $gCountC++; }
        if ($pVenta > 0) { $gSumaV += $pVenta; $gCountV++; }
    }

    $promGenCompra = ($gCountC > 0) ? ($gSumaC / $gCountC) : 0;
    $promGenVenta  = ($gCountV > 0) ? ($gSumaV / $gCountV) : 0;
    $difGenDinero  = $promGenVenta - $promGenCompra;
    $gananciaGenPrc = ($accCompra > 0) ? (($accVenta - $accCompra) / $accCompra) * 100 : 0;
    ?>

    <!-- TARJETA RESUMEN EN LA CABECERA -->
    <div class="header-summary">
        <div class="summary-item">
            <div class="title">Drinks Stock</div>
            <div class="value"><?= number_format($gD, 0) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">Central Stock</div>
            <div class="value"><?= number_format($gC, 0) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">Total Stock</div>
            <div class="value"><?= number_format($gT, 0) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">P. Compra Prom.</div>
            <div class="value">$<?= number_format($promGenCompra, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">P. Venta Prom.</div>
            <div class="value">$<?= number_format($promGenVenta, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">Diferencia ($)</div>
            <div class="value green">$<?= number_format($difGenDinero, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">Ganancia (%)</div>
            <div class="value green"><?= number_format($gananciaGenPrc, 2) ?>%</div>
        </div>
        <div class="summary-item">
            <div class="title">Val. Inv. Compra</div>
            <div class="value">$<?= number_format($accCompra, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="title">Val. Inv. Venta</div>
            <div class="value">$<?= number_format($accVenta, 2) ?></div>
        </div>
    </div>

    <form method="GET" id="filterForm">
        <label>Desde: <input type="date" name="f_desde" value="<?= htmlspecialchars($fDesde) ?>"></label>
        <label>Hasta: <input type="date" name="f_hasta" value="<?= htmlspecialchars($fHasta) ?>"></label>
        
        <select name="categoria" onchange="document.getElementById('filterForm').submit();">
            <option value="">Todas las categorías</option>
            <?php foreach ($cats as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $categoria === $k ? 'selected' : '' ?>><?= htmlspecialchars($k.' - '.$v) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Utilidad <input type="number" step="0.1" name="filtro_utilidad" placeholder="Ej: 6" value="<?= htmlspecialchars($_GET['filtro_utilidad'] ?? '') ?>" style="width: 80px;">%</label>

        <button type="submit">🔍 Consultar</button>
        <button type="button" onclick="location.href='?action=backup_db'" style="background:#28a745;">💾 Sincronizar Historial</button>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Drinks</th>
                    <th>Central</th>
                    <th>Total Stock</th>
                    <th>Costo Compra</th>
                    <th>P. Venta Prom.</th>
                    <th>Diferencia ($)</th>
                    <th>Ganancia (%)</th>
                    <th>Val. Inv. Compra</th>
                    <th>Val. Inv. Venta</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($barcodes_filtrados)):
                echo '<tr><td colspan="12" style="padding:20px; color:#777;">No se encontraron productos activos con los filtros seleccionados.</td></tr>';
            else:
                $filasProcesadas = [];
                foreach ($barcodes_filtrados as $b) {
                    $catCode = $prodCat[$b] ?? 'SIN';
                    $nombreCat = $cats[$catCode] ?? 'SIN CATEGORÍA';
                    $nombreProducto = $nombres_prod[$b] ?? 'DESCONOCIDO';

                    $cantD = $drinks[$b]['cantidad'] ?? 0;
                    $cantC = $central[$b]['cantidad'] ?? 0;
                    $totalUnit = $cantC + $cantD;

                    $pvC = $preciosCentral[$b] ?? 0;
                    $pvD = $preciosDrinks[$b] ?? 0;
                    $precioCompraExacto = ($pvC > 0) ? $pvC : $pvD;

                    $vtC = $ventasCentral[$b] ?? 0;
                    $vtD = $ventasDrinks[$b] ?? 0;
                    $precioVenta = ($vtC > 0) ? $vtC : $vtD;

                    $valInvCompra = $precioCompraExacto * $totalUnit;
                    $valInvVenta  = $precioVenta * $totalUnit;
                    
                    $diferenciaDinero = $precioVenta - $precioCompraExacto;
                    $gananciaPorcentaje = ($precioCompraExacto > 0) ? (($precioVenta - $precioCompraExacto) / $precioCompraExacto) * 100 : 0;

                    if ($filtroUtilidad !== '' && $gananciaPorcentaje >= $filtroUtilidad) {
                        continue;
                    }

                    $referenciaAlerta = $filtroUtilidad !== '' ? $filtroUtilidad : 6;
                    $claseFila = 'item-row';
                    if ($gananciaPorcentaje < $referenciaAlerta) {
                        $claseFila .= ' alerta-utilidad';
                    }

                    $filasProcesadas[] = "<tr class='$claseFila'>
                            <td>" . htmlspecialchars($b) . "</td>
                            <td style='text-align:left; font-weight:600;'>" . htmlspecialchars($nombreProducto) . "</td>
                            <td style='text-align:left;'>" . htmlspecialchars($catCode . ' - ' . $nombreCat) . "</td>
                            <td>" . number_format($cantD, 0) . "</td>
                            <td>" . number_format($cantC, 0) . "</td>
                            <td>" . number_format($totalUnit, 0) . "</td>
                            <td>$" . number_format($precioCompraExacto, 2) . "</td>
                            <td>$" . number_format($precioVenta, 2) . "</td>
                            <td style='color:#28a745;'>$" . number_format($diferenciaDinero, 2) . "</td>
                            <td style='color:#28a745;'>" . number_format($gananciaPorcentaje, 2) . "%</td>
                            <td>$" . number_format($valInvCompra, 2) . "</td>
                            <td>$" . number_format($valInvVenta, 2) . "</td>
                          </tr>";
                }

                if (empty($filasProcesadas)) {
                    echo '<tr><td colspan="12" style="padding:20px; color:#777;">No se encontraron productos con un % de utilidad por debajo del valor especificado.</td></tr>';
                } else {
                    echo implode('', $filasProcesadas);
                }
            endif;
            ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>