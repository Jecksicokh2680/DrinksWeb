<?php
require_once("Conexion.php");      // $mysqliWeb (Donde reside historial_stock)
date_default_timezone_set('America/Bogota'); 
session_start();

$fecha_consulta = $_GET['fecha'] ?? date('Y-m-d');
$term = $_GET['term'] ?? '';

// Obtener categorías para filtros
$cats = [];
$resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) { $cats[$c['CodCat']] = $c['Nombre']; }

// Consulta del stock histórico guardado en esa fecha
$where = "fecha_registro = ?";
$params = [$fecha_consulta];
$types = "s";

if (!empty($term)) {
    $where .= " AND (descripcion LIKE ? OR barcode LIKE ?)";
    $like = "%$term%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$sqlHist = "SELECT * FROM historial_stock WHERE $where ORDER BY codcat ASC, descripcion ASC";
$stmt = $mysqliWeb->prepare($sqlHist);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$resultadoHistorial = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de Stock Histórico</title>
    <style>
        body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
        .container{max-width:1200px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
        h2{color:#2c3e50;text-align:center;margin-bottom:20px}
        form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center}
        select,input,button{padding:8px 12px;border-radius:6px;border:1px solid #ccc;font-size:14px}
        button{background:#6c757d;color:#fff;border:none;cursor:pointer}
        .table-container{overflow-x:auto;border-radius:12px;background:#fff}
        table{width:100%;border-collapse:collapse;min-width:700px}
        th,td{padding:12px 8px;text-align:center;border-bottom:1px solid #e1e1e1}
        th{background:#6c757d;color:#fff}
        .btn-nav { background:#007bff; text-decoration: none; display: inline-block; padding: 8px 12px; border-radius: 6px; color: #fff; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <h2>📅 Auditoría de Stock por Fecha (Historial)</h2>
    
    <form method="GET">
        <div>
            <label style="font-size:11px; display:block; color:#666;">Seleccionar Fecha:</label>
            <input type="date" name="fecha" value="<?= $fecha_consulta ?>">
        </div>
        <div>
            <label style="font-size:11px; display:block; color:#666;">Buscar Producto:</label>
            <input type="text" name="term" placeholder="Nombre o barcode..." value="<?= htmlspecialchars($term) ?>">
        </div>
        <div style="display:flex; align-items:flex-end; gap:5px;">
            <button type="submit" style="background:#6c757d;">🔍 Consultar Historial</button>
            <a href="inventario_actual.php" class="btn-nav">⬅️ Volver al Stock Actual</a>
        </div>
    </form>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th>Barcode</th>
                    <th style="text-align:left;">Producto</th>
                    <th>Drinks (Hist.)</th>
                    <th>Central (Hist.)</th>
                    <th>Total (Hist.)</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            if ($resultadoHistorial->num_rows > 0):
                $totD = $totC = $totT = 0;
                while($row = $resultadoHistorial->fetch_assoc()):
                    $totD += $row['stock_drinks'];
                    $totC += $row['stock_central'];
                    $totT += $row['stock_total'];
            ?>
                <tr>
                    <td><?= $row['codcat'] ?></td>
                    <td><?= $cats[$row['codcat']] ?? 'SIN CATEGORÍA' ?></td>
                    <td><?= $row['barcode'] ?></td>
                    <td style="text-align:left;"><?= htmlspecialchars($row['descripcion']) ?></td>
                    <td><?= number_format($row['stock_drinks'], 0) ?></td>
                    <td><?= number_format($row['stock_central'], 0) ?></td>
                    <td><strong><?= number_format($row['stock_total'], 0) ?></strong></td>
                </tr>
            <?php 
                endwhile;
            else:
            ?>
                <tr>
                    <td colspan="7" style="padding:20px; color:#e74c3c; font-weight:bold;">
                        ❌ No hay registros de respaldo guardados para la fecha <?= $fecha_consulta ?>. 
                        (Asegúrate de haber presionado "Sincronizar Historial" en el inventario actual para esa fecha).
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
            <?php if (isset($totT)): ?>
            <tfoot>
                <tr style="background:#343a40; color:#fff; font-weight:bold;">
                    <td colspan="4" style="text-align:right;">TOTALES HISTÓRICOS:</td>
                    <td><?= number_format($totD, 0) ?></td>
                    <td><?= number_format($totC, 0) ?></td>
                    <td><?= number_format($totT, 0) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</body>
</html>