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
   PARÁMETROS DE VISTA (FILTROS Y PAGINACIÓN DINÁMICA)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term      = trim($_GET['term'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 100; 
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

$totalRows  = count($barcodes_filtrados);
$totalPages = max(1, ceil($totalRows / $limit));
$offset     = ($page - 1) * $limit;
$barcodes_pagina = array_slice($barcodes_filtrados, $offset, $limit);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Consolidado Dinámico</title>
    <style>
        body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
        .container{max-width:1200px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
        h2{color:#2c3e50;text-align:center;margin-bottom:20px}
        form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center}
        select,input,button{padding:8px 12px;border-radius:6px;border:1px solid #ccc;font-size:14px}
        button{background:#007bff;color:#fff;border:none;cursor:pointer}
        .table-container{overflow-x:auto;border-radius:12px;background:#fff}
        table{width:100%;border-collapse:collapse;min-width:700px}
        th,td{padding:12px 8px;text-align:center;border-bottom:1px solid #e1e1e1}
        th{background:#007bff;color:#fff}
        tr.subtotal{background:#f8f9fa;font-weight:700;color:#007bff;border-top: 2px solid #007bff;}
        tr.total-general{background:#2c3e50;color:#fff;font-weight:700;font-size:1.1em}
        .paginacion{text-align:center;margin-top:15px}
        .paginacion a{padding:6px 10px;margin:2px;border:1px solid #007bff;color:#007bff;text-decoration:none;border-radius:5px}
        .paginacion a.activa{background:#007bff;color:#fff}
        .alert { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; border: 1px solid #bee5eb; }
    </style>
</head>
<body>

<div class="container">
    <h2>📦 Inventario Central + Drinks (Dinámico)</h2>

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
        <input type="text" name="term" placeholder="Buscar producto..." value="<?= htmlspecialchars($term) ?>">
        <button type="submit">🔍 Consultar</button>
        <button type="button" onclick="location.href='?action=backup_db'" style="background:#28a745;">💾 Sincronizar Historial</button>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th>Barcode</th>
                    <th style="text-align:left;">Producto</th>
                    <th>Drinks</th>
                    <th>Central</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($barcodes_pagina)):
                echo '<tr><td colspan="7" style="padding:20px; color:#777;">No se encontraron productos con los filtros seleccionados.</td></tr>';
            else:
                $lastCat = null;
                $subD = $subC = $subT = 0;
                $genD = $genC = $genT = 0; 

                foreach ($barcodes_pagina as $index => $b):
                    $c = $central[$b] ?? ['cantidad' => 0, 'descripcion' => 'Sin descripción'];
                    $d = $drinks[$b] ?? ['cantidad' => 0];
                    $cat = $prodCat[$b] ?? 'SIN';
                    $nombreCat = $cats[$cat] ?? 'SIN CATEGORÍA';
                    
                    $descripcionProd = !empty($c['descripcion']) && $c['descripcion'] !== 'Sin descripción' 
                                       ? $c['descripcion'] 
                                       : ($d['descripcion'] ?? 'Sin descripción');

                    if($lastCat !== null && $lastCat !== $cat){
                        echo "<tr class='subtotal'>
                                <td colspan='4' style='text-align:right;'>SUBTOTAL ".htmlspecialchars(($cats[$lastCat] ?? 'SIN CATEGORÍA')." ({$lastCat})")."</td>
                                <td>".number_format($subD, 0)."</td>
                                <td>".number_format($subC, 0)."</td>
                                <td>".number_format($subT, 0)."</td>
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
                <td><?= htmlspecialchars($nombreCat) ?></td>
                <td><?= htmlspecialchars($b) ?></td>
                <td style="text-align:left;"><?= htmlspecialchars($descripcionProd) ?></td>
                <td><?= number_format($cantD, 0) ?></td>
                <td><?= number_format($cantC, 0) ?></td>
                <td><strong><?= number_format($totalUnit, 0) ?></strong></td>
            </tr>
            <?php 
                    if ($index === count($barcodes_pagina) - 1) {
                        echo "<tr class='subtotal'>
                                <td colspan='4' style='text-align:right;'>SUBTOTAL ".htmlspecialchars("{$nombreCat} ({$cat})")."</td>
                                <td>".number_format($subD, 0)."</td>
                                <td>".number_format($subC, 0)."</td>
                                <td>".number_format($subT, 0)."</td>
                              </tr>";
                    }
                endforeach; 
            endif;
            ?>
            </tbody>
            <tfoot>
                <tr class="total-general">
                    <td colspan="4" style="text-align:right;">TOTAL EN ESTA PÁGINA:</td>
                    <td><?= number_format($genD, 0) ?></td>
                    <td><?= number_format($genC, 0) ?></td>
                    <td><?= number_format($genT, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="paginacion">
        <?php for($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i == $page ? 'activa' : '' ?>" href="?categoria=<?= urlencode($categoria) ?>&term=<?= urlencode($term) ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</div>

</body>
</html>