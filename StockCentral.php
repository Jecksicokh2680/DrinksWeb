<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* =============================
   PARÁMETROS
============================= */
$categoria = $_GET['categoria'] ?? '';
$term      = $_GET['term'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 15;
$offset    = ($page - 1) * $limit;

$like = "%$term%";

/* =============================
   CATEGORÍAS (ORDEN ASC POR CODCAT)
============================= */
$cats = [];
$resCat = $mysqliWeb->query("
    SELECT CodCat, Nombre
    FROM categorias
    WHERE Estado='1'
    ORDER BY CodCat ASC
");
while ($c = $resCat->fetch_assoc()) {
    $cats[$c['CodCat']] = $c['Nombre'];
}

/* =============================
   BARCODES POR CATEGORÍA
============================= */
$barcodesCat = [];
if ($categoria) {
    $stmtCat = $mysqliWeb->prepare("
        SELECT sku
        FROM catproductos
        WHERE CodCat = ?
    ");
    $stmtCat->bind_param("s", $categoria);
    $stmtCat->execute();
    $resBC = $stmtCat->get_result();

    while ($r = $resBC->fetch_assoc()) {
        $barcodesCat[] = $r['sku'];
    }

    if (!$barcodesCat) {
        $barcodesCat = ['__NONE__'];
    }
}

/* =============================
   RELACIÓN PRODUCTO → CATEGORÍA
============================= */
$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
}

/* =============================
   WHERE UNIFICADO
============================= */
$where = "p.estado='1' AND (p.descripcion LIKE ? OR p.barcode LIKE ?)";
if ($categoria) {
    $in = "'" . implode("','", $barcodesCat) . "'";
    $where .= " AND p.barcode IN ($in)";
}

/* =============================
   CONTADOR (PAGINACIÓN REAL)
============================= */
$sqlCount = "
    SELECT COUNT(DISTINCT p.barcode) total
    FROM productos p
    WHERE $where
";
$stmtCnt = $mysqliCentral->prepare($sqlCount);
$stmtCnt->bind_param("ss", $like, $like);
$stmtCnt->execute();
$totalRows  = $stmtCnt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $limit));

/* =============================
   QUERY BASE (ORDEN ASC POR BARCODE)
============================= */
$sql = "
    SELECT
        p.barcode,
        p.descripcion,
        IFNULL(SUM(i.cantidad),0) cantidad
    FROM productos p
    LEFT JOIN inventario i ON p.idproducto = i.idproducto
    WHERE $where
    GROUP BY p.barcode, p.descripcion
    ORDER BY p.barcode ASC
    LIMIT $limit OFFSET $offset
";

/* CENTRAL */
$stmtC = $mysqliCentral->prepare($sql);
$stmtC->bind_param("ss", $like, $like);
$stmtC->execute();
$resC = $stmtC->get_result();
$central = [];
while ($r = $resC->fetch_assoc()) {
    $central[$r['barcode']] = $r;
}

/* DRINKS */
$stmtD = $mysqliDrinks->prepare($sql);
$stmtD->bind_param("ss", $like, $like);
$stmtD->execute();
$resD = $stmtD->get_result();
$drinks = [];
while ($r = $resD->fetch_assoc()) {
    $drinks[$r['barcode']] = $r;
}

/* BARCODES ORDENADOS POR CATEGORÍA */
$barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
usort($barcodes, function($a, $b) use($prodCat) {
    return ($prodCat[$a] ?? '') <=> ($prodCat[$b] ?? '');
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventario Consolidado</title>
<style>
body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
.container{max-width:1200px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
h2{color:#2c3e50;text-align:center;margin-bottom:20px}
form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center}
select,input,button{padding:8px 12px;border-radius:6px;border:1px solid #ccc;font-size:14px}
button{background:#007bff;color:#fff;border:none;cursor:pointer;transition:0.3s}
button:hover{background:#0056b3}
.table-container{overflow-x:auto;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.05);background:#fff}
table{width:100%;border-collapse:collapse;min-width:700px}
th,td{padding:12px 8px;text-align:center;border-bottom:1px solid #e1e1e1}
th{background:#007bff;color:#fff;font-weight:500}
td.text{text-align:left}
tr.subtotal{background:#f1f1f1;font-weight:700}
tr.subtotal td{text-align:center}
.paginacion{text-align:center;margin-top:15px}
.paginacion a{padding:6px 10px;margin:2px;border:1px solid #007bff;color:#007bff;text-decoration:none;border-radius:5px;transition:0.3s}
.paginacion a:hover{background:#007bff;color:#fff}
.paginacion a.activa{background:#007bff;color:#fff}
</style>
</head>
<body>

<div class="container">
<h2>📦 Inventario Central + Drinks</h2>

<form method="GET">
<select name="categoria">
<option value="">Todas las categorías</option>
<?php foreach ($cats as $k=>$v): ?>
<option value="<?= $k ?>" <?= $categoria==$k?'selected':'' ?>>
<?= htmlspecialchars($k.' - '.$v) ?>
</option>
<?php endforeach; ?>
</select>

<input type="text" name="term" placeholder="Buscar por nombre o barcode" value="<?= htmlspecialchars($term) ?>">
<button>Buscar</button>
</form>

<div class="table-container">
<table>
<thead>
<tr>
<th>CodCat</th>
<th>Nombre Categoría</th>
<th>Barcode</th>
<th class="text">Producto</th>
<th>Drinks</th>
<th>Central</th>
<th>Total</th>
</tr>
</thead>
<tbody>

<?php
$lastCat = '';
$subDrinks = $subCentral = $subTotal = 0;

foreach ($barcodes as $b):
    $c = $central[$b] ?? ['cantidad'=>0,'descripcion'=>''];
    $d = $drinks[$b] ?? ['cantidad'=>0];
    $cat = $prodCat[$b] ?? 'SIN';
    $catNombre = $cats[$cat] ?? 'SIN';

    // Subtotal al cambiar de categoría
    if($lastCat !== '' && $lastCat !== $cat){
        echo "<tr class='subtotal'>
                <td colspan='4'>Subtotal {$lastCat} - {$cats[$lastCat]}</td>
                <td>".number_format($subDrinks,0,',','.')."</td>
                <td>".number_format($subCentral,0,',','.')."</td>
                <td>".number_format($subTotal,0,',','.')."</td>
              </tr>";
        $subDrinks = $subCentral = $subTotal = 0;
    }

    $subDrinks += $d['cantidad'];
    $subCentral += $c['cantidad'];
    $subTotal += $c['cantidad'] + $d['cantidad'];
    $lastCat = $cat;
?>
<tr>
    <td><?= htmlspecialchars($cat) ?></td>
    <td><?= htmlspecialchars($catNombre) ?></td>
    <td><?= htmlspecialchars($b) ?></td>
    <td class="text"><?= htmlspecialchars($c['descripcion']) ?></td>
    <td><?= number_format($d['cantidad'],0,',','.') ?></td>
    <td><?= number_format($c['cantidad'],0,',','.') ?></td>
    <td><strong><?= number_format($c['cantidad']+$d['cantidad'],0,',','.') ?></strong></td>
</tr>
<?php endforeach; ?>

<!-- Último subtotal -->
<?php if($lastCat !== ''): ?>
<tr class='subtotal'>
    <td colspan='4'>Subtotal <?= $lastCat ?> - <?= $cats[$lastCat] ?></td>
    <td><?= number_format($subDrinks,0,',','.') ?></td>
    <td><?= number_format($subCentral,0,',','.') ?></td>
    <td><?= number_format($subTotal,0,',','.') ?></td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>

<div class="paginacion">
<?php for($i=1;$i<=$totalPages;$i++): ?>
<a class="<?= $i==$page?'activa':'' ?>"
href="?categoria=<?=urlencode($categoria)?>&term=<?=urlencode($term)?>&page=<?=$i?>">
<?= $i ?>
</a>
<?php endfor; ?>
</div>

</div>
</body>
</html>
