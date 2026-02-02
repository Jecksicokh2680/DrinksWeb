<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   RELACIÓN PRODUCTO → CATEGORÍA (Se necesita para el Backup)
============================================================ */
$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
}

/* ============================================================
   LÓGICA DE BACKUP: INSERTAR O ACTUALIZAR (UPSERT) CON CODCAT
============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'backup_db') {
    $fecha_actual = date("Y-m-d");

    // Traemos el stock actual de ambas sedes
    $sqlAll = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) as cant 
               FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
               WHERE p.estado='1' GROUP BY p.barcode";

    $resC_all = $mysqliCentral->query($sqlAll);
    $dataC = [];
    while($r = $resC_all->fetch_assoc()){ $dataC[$r['barcode']] = $r; }

    $resD_all = $mysqliDrinks->query($sqlAll);
    $dataD = [];
    while($r = $resD_all->fetch_assoc()){ $dataD[$r['barcode']] = $r; }

    $todos_barcodes = array_unique(array_merge(array_keys($dataC), array_keys($dataD)));

    // SQL UPSERT incluyendo codcat
    $sqlUpsert = "INSERT INTO historial_stock 
                  (barcode, descripcion, codcat, stock_central, stock_drinks, stock_total, fecha_registro) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE 
                  stock_central = VALUES(stock_central),
                  stock_drinks  = VALUES(stock_drinks),
                  stock_total   = VALUES(stock_total),
                  descripcion   = VALUES(descripcion),
                  codcat        = VALUES(codcat)";

    $stmtIns = $mysqliWeb->prepare($sqlUpsert);

    foreach($todos_barcodes as $bc){
        $desc_ins = $dataC[$bc]['descripcion'] ?? ($dataD[$bc]['descripcion'] ?? 'Sin nombre');
        $cat_ins  = $prodCat[$bc] ?? 'SIN'; // Obtenemos el CodCat
        $c_ins    = $dataC[$bc]['cant'] ?? 0;
        $d_ins    = $dataD[$bc]['cant'] ?? 0;
        $t_ins    = $c_ins + $d_ins;
        
        // s = string, d = double/decimal
        $stmtIns->bind_param("ssdddds", $bc, $desc_ins, $cat_ins, $c_ins, $d_ins, $t_ins, $fecha_actual);
        $stmtIns->execute();
    }
    $msg_backup = "✅ Respaldo completo: Stock y Categorías actualizados para hoy ($fecha_actual).";
}

/* ============================================================
   PARÁMETROS DE VISTA (PAGINACIÓN Y FILTROS)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term      = $_GET['term'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 15;
$offset    = ($page - 1) * $limit;
$like      = "%$term%";

// Categorías para el selector
$cats = [];
$resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) { $cats[$c['CodCat']] = $c['Nombre']; }

// Barcodes filtrados por categoría (si aplica)
$barcodesCat = [];
if ($categoria) {
    $stmtCat = $mysqliWeb->prepare("SELECT sku FROM catproductos WHERE CodCat = ?");
    $stmtCat->bind_param("s", $categoria);
    $stmtCat->execute();
    $resBC = $stmtCat->get_result();
    while ($r = $resBC->fetch_assoc()) { $barcodesCat[] = $r['sku']; }
    if (!$barcodesCat) { $barcodesCat = ['__NONE__']; }
}

$where = "p.estado='1' AND (p.descripcion LIKE ? OR p.barcode LIKE ?)";
if ($categoria) {
    $in = "'" . implode("','", $barcodesCat) . "'";
    $where .= " AND p.barcode IN ($in)";
}

/* CONTADOR Y CONSULTA PARA LA TABLA VISUAL */
$sqlCount = "SELECT COUNT(DISTINCT p.barcode) total FROM productos p WHERE $where";
$stmtCnt = $mysqliCentral->prepare($sqlCount);
$stmtCnt->bind_param("ss", $like, $like);
$stmtCnt->execute();
$totalRows  = $stmtCnt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) cantidad FROM productos p
        LEFT JOIN inventario i ON p.idproducto = i.idproducto
        WHERE $where GROUP BY p.barcode, p.descripcion ORDER BY p.barcode ASC LIMIT $limit OFFSET $offset";

$stmtC = $mysqliCentral->prepare($sql);
$stmtC->bind_param("ss", $like, $like);
$stmtC->execute();
$resC = $stmtC->get_result();
$central = [];
while ($r = $resC->fetch_assoc()) { $central[$r['barcode']] = $r; }

$stmtD = $mysqliDrinks->prepare($sql);
$stmtD->bind_param("ss", $like, $like);
$stmtD->execute();
$resD = $stmtD->get_result();
$drinks = [];
while ($r = $resD->fetch_assoc()) { $drinks[$r['barcode']] = $r; }

$barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
usort($barcodes, function($a, $b) use($prodCat) {
    return ($prodCat[$a] ?? '') <=> ($prodCat[$b] ?? '');
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario Consolidado</title>
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
        tr.subtotal{background:#f1f1f1;font-weight:700}
        .paginacion{text-align:center;margin-top:15px}
        .paginacion a{padding:6px 10px;margin:2px;border:1px solid #007bff;color:#007bff;text-decoration:none;border-radius:5px}
        .paginacion a.activa{background:#007bff;color:#fff}
        .alert { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; border: 1px solid #bee5eb; }
    </style>
</head>
<body>

<div class="container">
    <h2>📦 Inventario Central + Drinks</h2>

    <?php if(isset($msg_backup)): ?>
        <div class="alert"><?= $msg_backup ?></div>
    <?php endif; ?>

    <form method="GET">
        <select name="categoria">
            <option value="">Todas las categorías</option>
            <?php foreach ($cats as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $categoria==$k?'selected':'' ?>><?= htmlspecialchars($k.' - '.$v) ?></option>
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
            $lastCat = '';
            $subD = $subC = $subT = 0;

            foreach ($barcodes as $b):
                $c = $central[$b] ?? ['cantidad'=>0,'descripcion'=>''];
                $d = $drinks[$b] ?? ['cantidad'=>0];
                $cat = $prodCat[$b] ?? 'SIN';
                $nombreCat = $cats[$cat] ?? 'SIN';

                if($lastCat !== '' && $lastCat !== $cat){
                    echo "<tr class='subtotal'>
                            <td colspan='4'>Subtotal {$lastCat}</td>
                            <td>".number_format($subD,0)."</td>
                            <td>".number_format($subC,0)."</td>
                            <td>".number_format($subT,0)."</td>
                          </tr>";
                    $subD = $subC = $subT = 0;
                }

                $subD += $d['cantidad']; $subC += $c['cantidad']; $subT += ($c['cantidad'] + $d['cantidad']);
                $lastCat = $cat;
            ?>
            <tr>
                <td><?= $cat ?></td>
                <td><?= $nombreCat ?></td>
                <td><?= $b ?></td>
                <td style="text-align:left;"><?= htmlspecialchars($c['descripcion']) ?></td>
                <td><?= number_format($d['cantidad'],0) ?></td>
                <td><?= number_format($c['cantidad'],0) ?></td>
                <td><strong><?= number_format($c['cantidad']+$d['cantidad'],0) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginacion">
        <?php for($i=1;$i<=$totalPages;$i++): ?>
            <a class="<?= $i==$page?'activa':'' ?>" href="?categoria=<?=urlencode($categoria)?>&term=<?=urlencode($term)?>&page=<?=$i?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</div>

</body>
</html>