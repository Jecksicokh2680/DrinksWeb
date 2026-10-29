<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   RELACIÓN PRODUCTO → CATEGORÍA (FORZADO CON LPAD DESDE SQL)
============================================================ */
$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, LPAD(CodCat, 4, '0') AS CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
}

/* ============================================================
   LÓGICA DE BACKUP: INSERTAR O ACTUALIZAR (UPSERT) - SIN DESCRIPCIÓN
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
   PARÁMETROS DE VISTA (FILTROS SIN PAGINACIÓN)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term      = trim($_GET['term'] ?? '');
$like      = "%$term%";

// 1. Obtener lista de categorías activas para el select (usando LPAD)
$cats = [];
$resCat = $mysqliWeb->query("SELECT LPAD(CodCat, 4, '0') AS CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) { 
    $cats[$c['CodCat']] = $c['Nombre']; 
}

/* 
  2. CONSULTA DINÁMICA UNIFICADA
*/
$sqlCentralQuery = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) cantidad FROM productos p
                    LEFT JOIN inventario i ON p.idproducto = i.idproducto
                    WHERE p.estado='1' AND (p.descripcion LIKE ? OR p.barcode LIKE ?) 
                    GROUP BY p.barcode, p.descripcion";

$stmtC = $mysqliCentral->prepare($sqlCentralQuery);
$stmtC->bind_param("ss", $like, $like);
$stmtC->execute();
$resC = $stmtC->get_result();
$central = [];
while ($r = $resC->fetch_assoc()) { $central[$r['barcode']] = $r; }

$stmtD = $mysqliDrinks->prepare($sqlCentralQuery);
$stmtD->bind_param("ss", $like, $like);
$stmtD->execute();
$resD = $stmtD->get_result();
$drinks = [];
while ($r = $resD->fetch_assoc()) { $drinks[$r['barcode']] = $r; }

$todos_barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));

// 3. Filtrado y Ordenamiento Dinámico en Memoria
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
    <title>Reporte Gerencial de Inventario Consolidado</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; color: #2c3e50; margin: 0; padding: 20px; }
        .container { max-width: 1250px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.05); }
        
        .header-reporte { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; margin-bottom: 25px; }
        .header-reporte h2 { margin: 0; color: #2c3e50; font-size: 22px; }
        .header-reporte .meta { text-align: right; font-size: 13px; color: #555; }

        form { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 25px; align-items: center; background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e1e8ed; }
        select, input { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccd1d9; font-size: 14px; background: #fff; }
        button { padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-weight: 600; font-size: 14px; }
        .btn-primary { background: #0056b3; color: #fff; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-print { background: #6c757d; color: #fff; }

        .table-container { overflow-x: auto; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 8px; text-align: center; border-bottom: 1px solid #e2e8f0; }
        th { background: #2c3e50; color: #fff; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        
        tr.subtotal { background: #edf2f7; font-weight: 700; color: #1a202c; border-top: 2px solid #cbd5e0; border-bottom: 2px solid #cbd5e0; }
        tr.total-general { background: #1a202c; color: #fff; font-weight: 700; font-size: 14px; }
        
        .alert { background: #e6fffa; color: #234e52; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 20px; border: 1px solid #b2f5ea; font-size: 14px; }

        /* Estilos optimizados para Impresión / PDF */
        @media print {
            body { background: #fff; padding: 0; color: #000; }
            .container { box-shadow: none; padding: 0; max-width: 100%; }
            form, .alert, .btn-print, .btn-success, .btn-primary, button { display: none !important; }
            th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr.total-general { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr.subtotal { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-reporte">
        <div>
            <h2>📊 Reporte Gerencial de Inventario Consolidado</h2>
            <p style="margin: 5px 0 0; color: #666; font-size: 13px;">Stock unificado: Central + Drinks</p>
        </div>
        <div class="meta">
            <strong>Fecha de emisión:</strong> <?= date("d/m/Y H:i") ?><br>
            <strong>Filtro aplicado:</strong> <?= $categoria !== '' ? htmlspecialchars($cats[$categoria] ?? $categoria) : 'Todas las categorías' ?>
        </div>
    </div>

    <?php if(isset($msg_backup)): ?>
        <div class="alert"><?= $msg_backup ?></div>
    <?php endif; ?>

    <form method="GET">
        <select name="categoria">
            <option value="">Todas las categorías</option>
            <?php foreach ($cats as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $categoria === $k ? 'selected' : '' ?>><?= htmlspecialchars($k.' - '.$v) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="term" placeholder="Buscar por producto o código..." value="<?= htmlspecialchars($term) ?>" style="flex-grow: 1; max-width: 350px;">
        <button type="submit" class="btn-primary">🔍 Consultar</button>
        <button type="button" onclick="window.print()" class="btn-print">🖨️ Imprimir Listado</button>
        <button type="button" onclick="location.href='?action=backup_db'" class="btn-success">💾 Sincronizar Historial</button>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 70px;">CodCat</th>
                    <th style="text-align: left; width: 180px;">Categoría</th>
                    <th style="width: 120px;">Barcode</th>
                    <th style="text-align: left;">Descripción del Producto</th>
                    <th style="width: 90px;">Drinks</th>
                    <th style="width: 90px;">Central</th>
                    <th style="width: 100px;">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($barcodes_filtrados)):
                echo '<tr><td colspan="7" style="padding: 30px; color: #777; font-style: italic;">No se encontraron productos con los filtros seleccionados.</td></tr>';
            else:
                $lastCat = null;
                $subD = $subC = $subT = 0;
                $genD = $genC = $genT = 0; 

                foreach ($barcodes_filtrados as $index => $b):
                    $c = $central[$b] ?? ['cantidad' => 0, 'descripcion' => 'Sin descripción'];
                    $d = $drinks[$b] ?? ['cantidad' => 0];
                    $cat = $prodCat[$b] ?? 'SIN';
                    $nombreCat = $cats[$cat] ?? 'SIN CATEGORÍA';
                    
                    $descripcionProd = !empty($c['descripcion']) && $c['descripcion'] !== 'Sin descripción' 
                                       ? $c['descripcion'] 
                                       : ($d['descripcion'] ?? 'Sin descripción');

                    if($lastCat !== null && $lastCat !== $cat){
                        echo "<tr class='subtotal'>
                                <td colspan='4' style='text-align: right;'>SUBTOTAL " . htmlspecialchars(($cats[$lastCat] ?? 'SIN CATEGORÍA') . " ({$lastCat})") . "</td>
                                <td>" . number_format($subD, 0) . "</td>
                                <td>" . number_format($subC, 0) . "</td>
                                <td>" . number_format($subT, 0) . "</td>
                              </tr>";
                        $subD = $subC = $subT = 0;
                    }

                    $cantD = $d['cantidad'] ?? 0;
                    $cantC = $c['cantidad'] ?? 0;
                    $totalUnit = $cantC + $cantD;

                    $subD += $cantD; $subC += $cantC; $subT += $totalUnit;
                    $genD += $cantD; $genC += $cantC; $genT += $totalUnit;
                    
                    $lastCat = $cat;
            ?>
            <tr>
                <td><?= htmlspecialchars($cat) ?></td>
                <td style="text-align: left;"><?= htmlspecialchars($nombreCat) ?></td>
                <td><?= htmlspecialchars($b) ?></td>
                <td style="text-align: left;"><?= htmlspecialchars($descripcionProd) ?></td>
                <td><?= number_format($cantD, 0) ?></td>
                <td><?= number_format($cantC, 0) ?></td>
                <td><strong><?= number_format($totalUnit, 0) ?></strong></td>
            </tr>
            <?php 
                    // Imprimir último subtotal al finalizar el bucle
                    if ($index === count($barcodes_filtrados) - 1) {
                        echo "<tr class='subtotal'>
                                <td colspan='4' style='text-align: right;'>SUBTOTAL " . htmlspecialchars("{$nombreCat} ({$cat})") . "</td>
                                <td>" . number_format($subD, 0) . "</td>
                                <td>" . number_format($subC, 0) . "</td>
                                <td>" . number_format($subT, 0) . "</td>
                              </tr>";
                    }
                endforeach; 
            endif;
            ?>
            </tbody>
            <?php if (!empty($barcodes_filtrados)): ?>
            <tfoot>
                <tr class="total-general">
                    <td colspan="4" style="text-align: right; text-transform: uppercase;">TOTAL GENERAL:</td>
                    <td><?= number_format($genD, 0) ?></td>
                    <td><?= number_format($genC, 0) ?></td>
                    <td><?= number_format($genT, 0) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</body>
</html>