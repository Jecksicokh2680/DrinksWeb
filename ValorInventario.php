<?php
require('ConnCentral.php');  // Base empresa001 (donde están productos, facturas, etc.)
require('Conexion.php');     // Base ADM_BNMA (donde está la tabla fechainventariofisico)

header('Content-Type: text/html; charset=utf-8');
$mysqliPos->set_charset('utf8mb4');
$mysqli->set_charset('utf8mb4');

date_default_timezone_set('America/Bogota');

// ---------------- FECHAS ----------------
$FechaInicioInputv = isset($_GET['inicio']) ? $_GET['inicio'] : date('Y-m-01');
$FechaInicioInput = isset($_GET['inicio']) ? $_GET['inicio'] : date('Y-(m-1)-01');
$FechaFinalInput  = isset($_GET['final'])  ? $_GET['final']  : date('Y-m-t');

$FechaIniciov = str_replace('-', '', $FechaInicioInputv);
$FechaInicio = str_replace('-', '', $FechaInicioInput);
$FechaFinal  = str_replace('-', '', $FechaFinalInput);

// ---------------- CONSULTA INVENTARIO ----------------
$sqlInventario = "
SELECT 
    ROUND(IFNULL(i.cantidad, 0) * IFNULL(SUM(v.ValorTotalVenta) / NULLIF(SUM(v.CantidadVenta), 0), 0), 0) AS ValorInventarioVenta,
    ROUND(
        (IFNULL(SUM(v.ValorTotalVenta) / NULLIF(SUM(v.CantidadVenta), 0), 0) -
         IFNULL(SUM(c.ValorTotalCompra) / NULLIF(SUM(c.CantidadCompra), 0), 0))
         / NULLIF(IFNULL(SUM(c.ValorTotalCompra) / NULLIF(SUM(c.CantidadCompra), 0), 0), 0) * 100
    , 0) AS PorcentajeUtilidad
FROM productos p
LEFT JOIN (
    SELECT 
        dc.idproducto,
        SUM(dc.cantidad) AS CantidadCompra,
        SUM(dc.valor * dc.cantidad) AS ValorTotalCompra
    FROM compras c
    INNER JOIN detcompras dc ON c.idcompra = dc.idcompra
    WHERE c.estado = '0'
      AND c.fecha BETWEEN '$FechaInicio' AND '$FechaFinal'
    GROUP BY dc.idproducto
) c ON c.idproducto = p.idproducto
LEFT JOIN (
    SELECT 
        idproducto,
        SUM(CantidadVenta) AS CantidadVenta,
        SUM(ValorTotalVenta) AS ValorTotalVenta
    FROM (
        SELECT 
            df.idproducto,
            SUM(df.cantidad) AS CantidadVenta,
            SUM(df.valorprod * df.cantidad) AS ValorTotalVenta
        FROM facturas f
        INNER JOIN detfacturas df ON df.idfactura = f.idfactura
        WHERE f.estado = '0'
          AND f.idfactura NOT IN (SELECT idfactura FROM devventas)
          AND f.fecha BETWEEN '$FechaInicio' AND '$FechaFinal'
        GROUP BY df.idproducto
        UNION ALL
        SELECT 
            dp.idproducto,
            SUM(dp.cantidad) AS CantidadVenta,
            SUM(dp.valorprod * dp.cantidad) AS ValorTotalVenta
        FROM pedidos p
        INNER JOIN detpedidos dp ON p.idpedido = dp.idpedido
        WHERE p.estado = '0'
          AND p.fecha BETWEEN '$FechaInicio' AND '$FechaFinal'
        GROUP BY dp.idproducto
    ) v
    GROUP BY idproducto
) v ON v.idproducto = p.idproducto
LEFT JOIN inventario i ON i.idproducto = p.idproducto
WHERE p.estado='1'
GROUP BY p.idproducto;
";

$result = $mysqliPos->query($sqlInventario);
if (!$result) {
    die("<b>Error en la consulta de inventario:</b> " . $mysqliPos->error);
}

// ---------------- CALCULAR TOTALES ----------------
$totalValorInventario = 0;
$totalUtilidad = 0;
$totalProductos = 0;

while ($fila = $result->fetch_assoc()) {
    $totalValorInventario += $fila['ValorInventarioVenta'];
    $totalUtilidad += $fila['PorcentajeUtilidad'];
    $totalProductos++;
}

$promedioUtilidad = $totalProductos > 0 ? $totalUtilidad / $totalProductos : 0;

// ---------------- CONSULTA VENTAS DEL MES ----------------
$sqlVentas = "
    SELECT SUM(FACTURAS.VALORTOTAL) AS TotalVentas
    FROM FACTURAS 
    WHERE FACTURAS.ESTADO = '0'
      AND FACTURAS.IDFACTURA NOT IN (SELECT IDFACTURA FROM DEVVENTAS)
      AND FACTURAS.FECHA BETWEEN ? AND ?
    UNION ALL
    SELECT SUM(PEDIDOS.VALORTOTAL) AS TotalVentas
    FROM PEDIDOS 
    WHERE PEDIDOS.ESTADO = '0'
      AND PEDIDOS.FECHA BETWEEN ? AND ?;
";

$stmt = $mysqliPos->prepare($sqlVentas);
$stmt->bind_param('ssss', $FechaIniciov, $FechaFinal, $FechaIniciov, $FechaFinal);
$stmt->execute();
$resVentas = $stmt->get_result();

$totalVentasMes = 0;
while ($row = $resVentas->fetch_assoc()) {
    $totalVentasMes += $row['TotalVentas'];
}
$stmt->close();

// ---------------- GUARDAR O ACTUALIZAR EN ADM_BNMA ----------------
$fechaActual = date('Y-m-d');
$horaActual = date('H:i:s');

$check = $mysqli->prepare("SELECT IdReg FROM fechainventariofisico WHERE Fecha = ?");
$check->bind_param('s', $fechaActual);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    $update = $mysqli->prepare("UPDATE fechainventariofisico SET Valor = ?, utilidad = ?, Vtames = ?, Hora = ? WHERE Fecha = ?");
    $update->bind_param('dddss', $totalValorInventario, $promedioUtilidad, $totalVentasMes, $horaActual, $fechaActual);
    $update->execute();
    $accion = "🔁 Actualizado";
    $update->close();
} else {
    $insert = $mysqli->prepare("INSERT INTO fechainventariofisico (Fecha, Hora, Valor, utilidad, Vtames) VALUES (?, ?, ?, ?, ?)");
    $insert->bind_param('ssddd', $fechaActual, $horaActual, $totalValorInventario, $promedioUtilidad, $totalVentasMes);
    $insert->execute();
    $accion = "✅ Insertado";
    $insert->close();
}

$check->close();

// ---------------- CONSULTAR REGISTROS DEL MES ----------------
$sqlHist = "
SELECT Fecha, Hora, Valor, utilidad, Vtames
FROM fechainventariofisico
WHERE MONTH(STR_TO_DATE(Fecha, '%Y-%m-%d')) = MONTH(CURDATE())
  AND YEAR(STR_TO_DATE(Fecha, '%Y-%m-%d')) = YEAR(CURDATE())
ORDER BY Fecha DESC;
";
$historial = $mysqli->query($sqlHist);

// Convertir historial a arreglo para poder calcular diferencias
$rows = [];
while ($r = $historial->fetch_assoc()) {
    $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Total Inventario y Ventas</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { 
    background-color: #f8f9fa; 
    font-size: 1.2rem;
}
h2 { font-size: 2.5rem; }
h3 { font-size: 2rem; }
h4, h5 { font-size: 1.5rem; }
.table th, .table td { font-size: 1.2rem; }
.card { border-radius: 15px; }
</style>
</head>
<body>
<div class="container text-center mt-5">
    <h3 class="mb-4">📊 Total Inventario y Ventas<br>(<?= htmlspecialchars($FechaInicioInput) ?> a <?= htmlspecialchars($FechaFinalInput) ?>)</h3>
    
    <div class="card p-4 shadow-sm mb-5" style="max-width: 700px; margin: 0 auto;">
        <h4 class="mb-3 text-success">💰 Valor Total del Inventario</h4>
        <h2 class="text-primary fw-bold">$<?= number_format($totalValorInventario, 0, ',', '.') ?></h2>
        <hr>
        <h5>Promedio de Utilidad: <span class="text-info fw-bold"><?= number_format($promedioUtilidad, 0) ?>%</span></h5>
        <h5 class="mt-3">Ventas del Mes: <span class="text-warning fw-bold">$<?= number_format($totalVentasMes, 0, ',', '.') ?></span></h5>
        <h5 class="mt-3">Utilidad del Mes: <span class="text-success fw-bold">$<?= number_format(($totalVentasMes*$promedioUtilidad)/100, 0, ',', '.') ?></span></h5>
        <p class="mt-4 text-muted" style="font-size:1rem;">
            Registro <b><?= $accion ?></b> en <b>ADM_BNMA → fechainventariofisico</b><br>
            Fecha: <?= $fechaActual ?> | Hora: <?= $horaActual ?>
        </p>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-header bg-secondary text-white fw-bold" style="font-size:1.4rem;">📅 Registros del Mes Actual</div>
        <div class="card-body">
            <table class="table table-striped table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Inventario</th>
                        <th>Utilidad (%)</th>
                        <th>Venta Mes</th>
                        <th>Utilidad Mes</th>
                        <th>Diferencia Inventario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < count($rows); $i++): 
                        $actual = $rows[$i]['Valor'];
                        $siguiente = ($i + 1 < count($rows)) ? $rows[$i+1]['Valor'] : $rows[$i]['Valor'];
                        $diferencia = $actual - $siguiente;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($rows[$i]['Fecha']) ?></td>
                        <td><?= htmlspecialchars($rows[$i]['Hora']) ?></td>
                        <td>$<?= number_format($actual, 0, ',', '.') ?></td>
                        <td><?= number_format($rows[$i]['utilidad'], 0) ?>%</td>
                        <td>$<?= number_format($rows[$i]['Vtames'], 0, ',', '.') ?></td>
                        <td>$<?= number_format($rows[$i]['Vtames']*$rows[$i]['utilidad']/100, 0, ',', '.') ?></td>
                        <td class="fw-bold <?= $diferencia < 0 ? 'text-danger' : 'text-success' ?>">
                            $<?= number_format($diferencia, 0, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
