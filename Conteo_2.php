<?php
/* ============================================================
   1. CONEXIONES Y CONFIGURACIÓN
============================================================ */
require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");  
require_once("Conexion.php");    

session_start();
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

$nitSesion = $_SESSION['NitEmpresa'] ?? NIT_CENTRAL;
$dbSede = ($nitSesion == NIT_DRINKS) ? $mysqliDrinks : $mysqliCentral;

// Función para obtener categorías
$categorias = [];
$res = $mysqli->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' AND (SegWebT+SegWebF)>=1 ORDER BY CodCat");
while ($r = $res->fetch_assoc()) $categorias[$r['CodCat']] = $r;

// Lógica de filtrado
$catSeleccionada = $_GET['cat'] ?? '';
$productos = [];
$totalCat = 0;

if ($catSeleccionada && isset($categorias[$catSeleccionada])) {
    $stmt = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=? AND Estado='1'");
    $stmt->bind_param("s", $catSeleccionada);
    $stmt->execute();
    $skus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if ($skus) {
        $skuList = array_column($skus, 'Sku');
        $ph = implode(',', array_fill(0, count($skuList), '?'));
        $tp = str_repeat('s', count($skuList));
        $sql = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) as stock 
                FROM productos p 
                LEFT JOIN inventario i ON i.idproducto=p.idproducto AND i.idalmacen=1 
                WHERE p.barcode IN ($ph) AND p.estado='1' GROUP BY p.barcode ORDER BY p.descripcion ASC";
        
        $stmtP = $dbSede->prepare($sql);
        $stmtP->bind_param($tp, ...$skuList);
        $stmtP->execute();
        $productos = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($productos as $p) $totalCat += $p['stock'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario Filtrado</title>
    <style>
        body{font-family:sans-serif; background:#f4f7f6; padding:20px;}
        .card{max-width:600px; margin:auto; background:#fff; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.1);}
        select{width:100%; padding:12px; font-size:16px; border-radius:8px; margin-bottom:20px;}
        table{width:100%; border-collapse:collapse; margin-top:10px;}
        th{background:#f8f9fa; padding:8px; border-bottom:2px solid #ddd; text-align:left;}
        td{padding:8px; border-bottom:1px solid #eee;}
    </style>
</head>
<body>
    <div class="card">
        <h3>Filtrar Inventario</h3>
        <select onchange="window.location.href='?cat='+this.value">
            <option value="">-- Seleccione una Categoría --</option>
            <?php foreach($categorias as $c): ?>
                <option value="<?= $c['CodCat'] ?>" <?= $catSeleccionada == $c['CodCat'] ? 'selected' : '' ?>>
                    <?= $c['CodCat'].' - '.$c['Nombre'] ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if($catSeleccionada): ?>
            <h4>Resultados: <?= $categorias[$catSeleccionada]['Nombre'] ?></h4>
            <table>
                <thead><tr><th>Descripción</th><th style="text-align:right;">Stock</th></tr></thead>
                <tbody>
                    <?php foreach($productos as $p): ?>
                        <tr><td><?= htmlspecialchars($p['descripcion']) ?></td><td align="right"><?= number_format($p['stock'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    <tr style="background:#eee; font-weight:bold;"><td>TOTAL</td><td align="right"><?= number_format($totalCat, 2) ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>