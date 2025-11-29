<?php
// ------------------------------------------------------------
// CONEXIÓN
// ------------------------------------------------------------
require('conexion.php');
$mysqli->set_charset("utf8mb4");

// ------------------------------------------------------------
// FILTROS
// ------------------------------------------------------------
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');

$where = "WHERE rt.Estado = 1 AND rt.Fecha BETWEEN '$desde' AND '$hasta'";

// ------------------------------------------------------------
// OBTENER LISTA DE DIAS
// ------------------------------------------------------------
$sqlDias = "
SELECT DISTINCT Fecha
FROM Relaciontransferencias rt
$where
ORDER BY Fecha
";
$resDias = $mysqli->query($sqlDias);

$dias = [];
while ($d = $resDias->fetch_assoc()) {
    $dias[] = $d['Fecha'];
}

// ------------------------------------------------------------
// OBTENER RESUMEN PIVOT
// ------------------------------------------------------------
$sql = "
SELECT
    rt.Fecha,
    rt.CedulaNit,
    COALESCE(t.NombreCom, t.Nombre) AS Nombre,
    SUM(rt.Monto) AS Total
FROM Relaciontransferencias rt
LEFT JOIN terceros t ON t.CedulaNit = rt.CedulaNit
$where
GROUP BY rt.Fecha, rt.CedulaNit, Nombre
ORDER BY Nombre, rt.Fecha
";

$res = $mysqli->query($sql);

// ------------------------------------------------------------
// ARMAR MATRIZ
// ------------------------------------------------------------
$matriz = [];
$terceros = [];

while ($row = $res->fetch_assoc()) {

    $cedula = $row['CedulaNit'];
    $nombre = $row['Nombre'] ?? 'SIN NOMBRE';

    if (!isset($matriz[$cedula])) {
        $matriz[$cedula] = [
            'CedulaNit'=>$cedula,
            'Nombre'=>$nombre,
            'Dias' => array_fill_keys($dias, 0),
            'TotalFila' => 0
        ];
        $terceros[] = $cedula;
    }

    $matriz[$cedula]['Dias'][$row['Fecha']] = $row['Total'];
    $matriz[$cedula]['TotalFila']           += $row['Total'];
}

// ------------------------------------------------------------
// TOTALES POR COLUMNA
// ------------------------------------------------------------
$totalesDia = array_fill_keys($dias, 0);
$totalGeneral = 0;

foreach ($matriz as $fila) {
    foreach ($fila['Dias'] as $fecha => $valor) {
        $totalesDia[$fecha] += $valor;
        $totalGeneral       += $valor;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen Matriz Transferencias</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f6f9;
}
h2{
    text-align:center;
}
table{
    border-collapse:collapse;
    width:98%;
    margin:auto;
    background:#fff;
    font-size:13px;
}
th{
    background:#233b55;
    color:white;
    padding:5px;
}
td{
    border:1px solid #ccc;
    padding:4px;
}
.num{
    text-align:right;
}
.filtros{
    width:98%;
    padding:10px;
    margin:10px auto;
    background:#fff;
}
tfoot td{
    background:#233b55;
    color:white;
    font-weight:bold;
}
</style>
</head>
<body>

<h2>📊 Matriz de Transferencias por Tercero / Día</h2>

<div class="filtros">
<form method="GET">
    Desde:
    <input type="date" name="desde" value="<?= $desde ?>">
    Hasta:
    <input type="date" name="hasta" value="<?= $hasta ?>">
    <button type="submit">Filtrar</button>
</form>
</div>

<table>
<thead>
<tr>
    <th>Tercero</th>
    <th>Nombre</th>

    <?php foreach ($dias as $dia): ?>
        <th><?= $dia ?></th>
    <?php endforeach; ?>

    <th>Total</th>
</tr>
</thead>

<tbody>
<?php foreach ($matriz as $fila): ?>
<tr>
    <td><?= $fila['CedulaNit'] ?></td>
    <td><?= htmlspecialchars($fila['Nombre']) ?></td>

    <?php foreach ($fila['Dias'] as $valor): ?>
        <td class="num">$ <?= number_format($valor,2,',','.') ?></td>
    <?php endforeach; ?>

    <td class="num"><strong>$ <?= number_format($fila['TotalFila'],2,',','.') ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>

<tfoot>
<tr>
    <td colspan="2">TOTAL</td>

    <?php foreach ($totalesDia as $valor): ?>
        <td class="num">$ <?= number_format($valor,2,',','.') ?></td>
    <?php endforeach; ?>

    <td class="num">$ <?= number_format($totalGeneral,2,',','.') ?></td>
</tr>
</tfoot>

</table>

</body>
</html>
