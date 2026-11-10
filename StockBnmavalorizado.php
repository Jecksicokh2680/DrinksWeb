<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   FUNCIÓN DE PRECIO PROMEDIO DE COMPRA (PONDERADO REAL Y SEGURO)
============================================================ */
function precioPromCompra($mysqli_conn){
    // Calculamos el costo ponderado real de las últimas 3 compras de manera agrupada por producto
    $sql = "SELECT 
                p.barcode,
                ROUND(
                    IFNULL(
                        CASE 
                            WHEN compras_recientes.cantidad_total > 0 THEN 
                                (compras_recientes.costo_total_neto / compras_recientes.cantidad_total)
                            ELSE p.ultcosto 
                        END, 0
                    ), 2
                ) AS preciopromedio
            FROM 
                productos p
            LEFT JOIN (
                SELECT 
                    d.idproducto,
                    SUM(d.base + IFNULL(d.ivaprod, 0) + IFNULL(d.ValIco, 0) + (IFNULL(d.ValIBUAUni, 0) * d.cantidad)) AS costo_total_neto,
                    SUM(d.cantidad) AS cantidad_total
                FROM detcompras d
                INNER JOIN compras c ON d.idcompra = c.idcompra
                WHERE c.estado = 1 
                  AND d.cantidad > 0
                  AND (
                      SELECT COUNT(*) 
                      FROM detcompras d2 
                      INNER JOIN compras c2 ON d2.idcompra = c2.idcompra 
                      WHERE d2.idproducto = d.idproducto 
                        AND c2.estado = 1 
                        AND (c2.fecha > c.fecha OR (c2.fecha = c.fecha AND c2.idcompra >= c.idcompra))
                  ) <= 3
                GROUP BY d.idproducto
            ) compras_recientes ON p.idproducto = compras_recientes.idproducto
            WHERE p.estado = '1'";
    
    $out = []; 
    $r = $mysqli_conn->query($sql);
    while($r && $x = $r->fetch_assoc()) {
        $out[$x['barcode']] = (float)$x['preciopromedio'];
    }

    // Fallback de seguridad: Si algún producto no tiene compras recientes, usa el ultcosto oficial de la tabla productos
    $sqlFallback = "SELECT barcode, ultcosto FROM productos WHERE estado = '1'";
    $rFB = $mysqli_conn->query($sqlFallback);
    while($rFB && $xFB = $rFB->fetch_assoc()) {
        $bc = $xFB['barcode'];
        if (!isset($out[$bc]) || $out[$bc] <= 0) {
            $out[$bc] = (float)$xFB['ultcosto'];
        }
    }

    return $out;
}

/* ============================================================
   FUNCIÓN DE PRECIO DE VENTA DESDE LA TABLA PRODUCTOS (precioventa)
============================================================ */
function precioVentaMaestro($mysqli_conn){
    $sql = "SELECT barcode, precioventa FROM productos WHERE estado = '1'";
    
    $out = [];
    $r = $mysqli_conn->query($sql);
    while($r && $x = $r->fetch_assoc()){
        $out[$x['barcode']] = (float)$x['precioventa'];
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

$preciosCentral = precioPromCompra($mysqliCentral);
$preciosDrinks  = precioPromCompra($mysqliDrinks);

$ventasCentral = precioVentaMaestro($mysqliCentral);
$ventasDrinks  = precioVentaMaestro($mysqliDrinks);

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
while ($r = $resC->fetch_assoc()) { $central[$r['barcode']] = $r; }

$resD = $mysqliDrinks->query($sqlCentralQuery);
$drinks = [];
while ($r = $resD->fetch_assoc()) { $drinks[$r['barcode']] = $r; }

$todos_barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));

$barcodes_filtrados = [];
foreach ($todos_barcodes as $b) {
    $catProd = $prodCat[$b] ?? 'SIN';
    if ($categoria !== '' && $catProd !== $categoria) {
        continue; 
    }
    $barcodes_filtrados[] = $b;
}

usort($barcodes_filtrados, function($a, $b) use ($prodCat) {
    $catA = $prodCat[$a] ?? 'SIN';
    $catB = $prodCat[$b] ?? 'SIN';
    return $catA <=> $catB;
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Inventario Activo - Ganancias y Valorización</title>
    <style>
        body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
        .container{max-width:1600px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
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
        table{width:100%;border-collapse:collapse;min-width:1200px}
        th,td{padding:12px 8px;text-align:center;border-bottom:1px solid #e1e1e1;font-size: 13px;}
        th{background:#007bff;color:#fff}
        tr.subtotal{background:#f8f9fa;font-weight:700;color:#007bff;border-top: 1px solid #ddd;}
        
        tr.alerta-utilidad { background-color: #f8d7da !important; color: #721c24; }
        tr.alerta-utilidad td { color: #721c24; }

        .alert { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; border: 1px solid #bee5eb; }
    </style>
</head>
<body>

<div class="container">
    <h2>📦 Resumen de Inventario Activo (Subtotales, Ganancias y Valor de Venta)</h2>

    <?php if(isset($msg_backup)): ?>
        <div class="alert"><?= $msg_backup ?></div>
    <?php endif; ?>

    <?php
    $categoriasAgrupadasTemp = [];
    foreach ($barcodes_filtrados as $b) {
        $cat = $prodCat[$b] ?? 'SIN';
        if (!isset($categoriasAgrupadasTemp[$cat])) {
            $categoriasAgrupadasTemp[$cat] = [
                'cantD' => 0, 'cantC' => 0, 'totalUnit' => 0,
                'sumaCompra' => 0, 'countCompra' => 0,
                'sumaVenta' => 0, 'countVenta' => 0
            ];
        }
        $cantD = $drinks[$b]['cantidad'] ?? 0;
        $cantC = $central[$b]['cantidad'] ?? 0;
        $totalUnit = $cantC + $cantD;

        $pvC = $preciosCentral[$b] ?? 0;
        $pvD = $preciosDrinks[$b] ?? 0;
        $pCompra = ($pvC > 0) ? $pvC : $pvD;

        $vtC = $ventasCentral[$b] ?? 0;
        $vtD = $ventasDrinks[$b] ?? 0;
        $pVenta = ($vtC > 0) ? $vtC : $vtD;

        $categoriasAgrupadasTemp[$cat]['cantD'] += $cantD;
        $categoriasAgrupadasTemp[$cat]['cantC'] += $cantC;
        $categoriasAgrupadasTemp[$cat]['totalUnit'] += $totalUnit;
        if ($pCompra > 0) { $categoriasAgrupadasTemp[$cat]['sumaCompra'] += $pCompra; $categoriasAgrupadasTemp[$cat]['countCompra']++; }
        if ($pVenta > 0) { $categoriasAgrupadasTemp[$cat]['sumaVenta'] += $pVenta; $categoriasAgrupadasTemp[$cat]['countVenta']++; }
    }

    $accCompra = 0; $accVenta = 0; $gD = 0; $gC = 0; $gT = 0;
    $gSumaC = 0; $gCountC = 0; $gSumaV = 0; $gCountV = 0;

    foreach ($categoriasAgrupadasTemp as $cat => $datos) {
        $pComp = ($datos['countCompra'] > 0) ? ($datos['sumaCompra'] / $datos['countCompra']) : 0;
        $pVent = ($datos['countVenta'] > 0) ? ($datos['sumaVenta'] / $datos['countVenta']) : 0;
        $vComp = $pComp * $datos['totalUnit'];
        $vVent = $pVent * $datos['totalUnit'];

        $gPrc = ($pComp > 0) ? (($pVent - $pComp) / $pComp) * 100 : 0;
        if ($filtroUtilidad !== '' && $gPrc >= $filtroUtilidad) { continue; }

        $accCompra += $vComp;
        $accVenta += $vVent;
        $gD += $datos['cantD'];
        $gC += $datos['cantC'];
        $gT += $datos['totalUnit'];

        if ($pComp > 0) { $gSumaC += $pComp; $gCountC++; }
        if ($pVent > 0) { $gSumaV += $pVent; $gCountV++; }
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
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th>Drinks</th>
                    <th>Central</th>
                    <th>Total Stock</th>
                    <th>P. Compra Prom.</th>
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
                echo '<tr><td colspan="11" style="padding:20px; color:#777;">No se encontraron productos activos con los filtros seleccionados.</td></tr>';
            else:
                $categoriasAgrupadas = [];

                foreach ($barcodes_filtrados as $b) {
                    $cat = $prodCat[$b] ?? 'SIN';
                    if (!isset($categoriasAgrupadas[$cat])) {
                        $categoriasAgrupadas[$cat] = [
                            'cantD'         => 0,
                            'cantC'         => 0,
                            'totalUnit'     => 0,
                            'sumaCompra'    => 0,
                            'countCompra'   => 0,
                            'sumaVenta'     => 0,
                            'countVenta'    => 0
                        ];
                    }

                    $cantD = $drinks[$b]['cantidad'] ?? 0;
                    $cantC = $central[$b]['cantidad'] ?? 0;
                    $totalUnit = $cantC + $cantD;

                    $pvC = $preciosCentral[$b] ?? 0;
                    $pvD = $preciosDrinks[$b] ?? 0;
                    $precioCompra = ($pvC > 0) ? $pvC : $pvD;

                    $vtC = $ventasCentral[$b] ?? 0;
                    $vtD = $ventasDrinks[$b] ?? 0;
                    $precioVenta = ($vtC > 0) ? $vtC : $vtD;

                    $categoriasAgrupadas[$cat]['cantD'] += $cantD;
                    $categoriasAgrupadas[$cat]['cantC'] += $cantC;
                    $categoriasAgrupadas[$cat]['totalUnit'] += $totalUnit;

                    if ($precioCompra > 0) {
                        $categoriasAgrupadas[$cat]['sumaCompra'] += $precioCompra;
                        $categoriasAgrupadas[$cat]['countCompra']++;
                    }

                    if ($precioVenta > 0) {
                        $categoriasAgrupadas[$cat]['sumaVenta'] += $precioVenta;
                        $categoriasAgrupadas[$cat]['countVenta']++;
                    }
                }

                $filasProcesadas = [];
                foreach ($categoriasAgrupadas as $cat => $datos) {
                    $nombreCat = $cats[$cat] ?? 'SIN CATEGORÍA';
                    
                    $promedioCompra = ($datos['countCompra'] > 0) ? ($datos['sumaCompra'] / $datos['countCompra']) : 0;
                    $promedioVenta  = ($datos['countVenta'] > 0) ? ($datos['sumaVenta'] / $datos['countVenta']) : 0;

                    $valInvCompra = $promedioCompra * $datos['totalUnit'];
                    $valInvVenta  = $promedioVenta * $datos['totalUnit'];
                    
                    $diferenciaDinero = $promedioVenta - $promedioCompra;
                    $gananciaPorcentaje = ($promedioCompra > 0) ? (($promedioVenta - $promedioCompra) / $promedioCompra) * 100 : 0;

                    if ($filtroUtilidad !== '' && $gananciaPorcentaje >= $filtroUtilidad) {
                        continue;
                    }

                    $referenciaAlerta = $filtroUtilidad !== '' ? $filtroUtilidad : 6;
                    $claseFila = 'subtotal';
                    if ($gananciaPorcentaje < $referenciaAlerta) {
                        $claseFila .= ' alerta-utilidad';
                    }

                    $filasProcesadas[] = "<tr class='$claseFila'>
                            <td>" . htmlspecialchars($cat) . "</td>
                            <td style='text-align:left;'>" . htmlspecialchars($nombreCat) . "</td>
                            <td>" . number_format($datos['cantD'], 0) . "</td>
                            <td>" . number_format($datos['cantC'], 0) . "</td>
                            <td>" . number_format($datos['totalUnit'], 0) . "</td>
                            <td>$" . number_format($promedioCompra, 2) . "</td>
                            <td>$" . number_format($promedioVenta, 2) . "</td>
                            <td style='color:#28a745;'>$" . number_format($diferenciaDinero, 2) . "</td>
                            <td style='color:#28a745;'>" . number_format($gananciaPorcentaje, 2) . "%</td>
                            <td>$" . number_format($valInvCompra, 2) . "</td>
                            <td>$" . number_format($valInvVenta, 2) . "</td>
                          </tr>";
                }

                if (empty($filasProcesadas)) {
                    echo '<tr><td colspan="11" style="padding:20px; color:#777;">No se encontraron categorías con un % de utilidad por debajo del valor especificado.</td></tr>';
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