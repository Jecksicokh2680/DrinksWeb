<?php
require 'Conexion.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

date_default_timezone_set('America/Bogota');

// 1. Rango de fechas
$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

$f_inicio = str_replace('-', '', $fecha_desde);
$f_fin    = str_replace('-', '', $fecha_hasta);

// 2. Consulta con JOIN para traer el nombre del proveedor
$query = "
    SELECT 
        P.F_Creacion,
        P.Nit,
        T.Nombre AS Proveedor,
        SUM(CASE WHEN P.TipoMonto = 'F' THEN ABS(P.Monto) ELSE 0 END) AS TotalFacturado,
        SUM(CASE WHEN P.TipoMonto = 'P' THEN P.Monto ELSE 0 END) AS TotalPagado
    FROM pagosproveedores P
    INNER JOIN terceros T ON P.Nit = T.CedulaNit
    WHERE P.Estado = '1' 
      AND P.F_Creacion BETWEEN '$f_inicio' AND '$f_fin'
    GROUP BY P.F_Creacion, P.Nit
    ORDER BY P.F_Creacion DESC, T.Nombre ASC
";

$res = $mysqli->query($query);

// Agrupamos los resultados en un array por fecha
$datos_por_dia = [];
while($row = $res->fetch_assoc()){
    $datos_por_dia[$row['F_Creacion']][] = $row;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Desglose Diario de Pagos</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; }
        .no-print { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .day-card { background: #fff; border-radius: 8px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 5px solid #007bff; }
        .day-header { background: #f8f9fa; padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .day-header h3 { margin: 0; color: #007bff; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f1f1; padding: 10px; text-align: left; font-size: 0.9em; }
        td { padding: 10px; border-bottom: 1px solid #f9f9f9; font-size: 0.95em; }
        .val-f { color: #d9534f; font-weight: bold; }
        .val-p { color: #5cb85c; font-weight: bold; }
        .val-total { background: #fdfdfe; font-weight: bold; }
        .total-footer { background: #333; color: #fff; padding: 15px; border-radius: 8px; display: flex; justify-content: space-around; }
        @media print { .no-print { display: none; } .day-card { box-shadow: none; border: 1px solid #ccc; } }
    </style>
</head>
<body>

<div class="container">
    <div class="no-print">
        <h2 style="margin-top:0">📋 Desglose Diario por Proveedor a Credito</h2>
        <form method="GET" style="display:flex; gap:10px; align-items:flex-end;">
            <div>Desde: <br><input type="date" name="desde" value="<?= $fecha_desde ?>"></div>
            <div>Hasta: <br><input type="date" name="hasta" value="<?= $fecha_hasta ?>"></div>
            <button type="submit" style="padding:8px 15px; background:#007bff; color:#fff; border:none; border-radius:4px; cursor:pointer;">Consultar</button>
            <button type="button" onclick="window.print()" style="padding:8px 15px; background:#6c757d; color:#fff; border:none; border-radius:4px; cursor:pointer;">Imprimir</button>
        </form>
    </div>

    <?php 
    $granTotalFacturas = 0;
    $granTotalPagos = 0;

    foreach($datos_por_dia as $fecha_raw => $movimientos): 
        $f_fmt = substr($fecha_raw,0,4)."-".substr($fecha_raw,4,2)."-".substr($fecha_raw,6,2);
        $diaFactura = 0;
        $diaPago = 0;
    ?>
        <div class="day-card">
            <div class="day-header">
                <h3>📅 <?= $f_fmt ?></h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>NIT / Cédula</th>
                        <th>Nombre del Proveedor</th>
                        <th style="text-align:right;">Compras (-)</th>
                        <th style="text-align:right;">Pagado (+)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($movimientos as $m): 
                        $diaFactura += $m['TotalFacturado'];
                        $diaPago += $m['TotalPagado'];
                    ?>
                        <tr>
                            <td style="color:#777;"><?= $m['Nit'] ?></td>
                            <td><?= $m['Proveedor'] ?></td>
                            <td style="text-align:right;" class="val-f">$ <?= number_format($m['TotalFacturado'], 0, ',', '.') ?></td>
                            <td style="text-align:right;" class="val-p">$ <?= number_format($m['TotalPagado'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="val-total">
                        <td colspan="2" style="text-align:right;">TOTAL DEL DÍA:</td>
                        <td style="text-align:right;" class="val-f">$ <?= number_format($diaFactura, 0, ',', '.') ?></td>
                        <td style="text-align:right;" class="val-p">$ <?= number_format($diaPago, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php 
        $granTotalFacturas += $diaFactura;
        $granTotalPagos += $diaPago;
    endforeach; ?>

    <div class="total-footer">
        <div>TOTAL PERIODO COMPRADO: <br> <span style="font-size:1.4em; color:#ff8787;">$ <?= number_format($granTotalFacturas, 0, ',', '.') ?></span></div>
        <div>TOTAL PERIODO PAGADO: <br> <span style="font-size:1.4em; color:#87ffaf;">$ <?= number_format($granTotalPagos, 0, ',', '.') ?></span></div>
        <div>BALANCE NETO: <br> <span style="font-size:1.4em;">$ <?= number_format($granTotalPagos - $granTotalFacturas, 0, ',', '.') ?></span></div>
    </div>
</div>

</body>
</html>