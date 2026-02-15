<?php
require 'Conexion.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

date_default_timezone_set('America/Bogota');

// 1. Inicialización con fecha de hoy
$fecha_desde = $_GET['desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

$f_inicio = str_replace('-', '', $fecha_desde);
$f_fin    = str_replace('-', '', $fecha_hasta);

/* 2. CÁLCULO DE SALDOS INICIALES PREVIOS */
$saldos_iniciales = [];
$qPrev = "SELECT Nit, 
          SUM(CASE WHEN TipoMonto = 'P' THEN Monto ELSE 0 END) - 
          SUM(CASE WHEN TipoMonto = 'F' THEN ABS(Monto) ELSE 0 END) as SaldoAnterior
          FROM pagosproveedores 
          WHERE Estado = '1' AND F_Creacion < '$f_inicio'
          GROUP BY Nit";
$resPrev = $mysqli->query($qPrev);
if($resPrev){
    while($rowP = $resPrev->fetch_assoc()){
        $saldos_iniciales[$rowP['Nit']] = (float)$rowP['SaldoAnterior'];
    }
}

/* 3. CONSULTA Y PROCESAMIENTO ACUMULADO (ASC) */
$query = "
    SELECT 
        P.F_Creacion, P.Nit, T.Nombre AS Proveedor,
        SUM(CASE WHEN P.TipoMonto = 'F' THEN ABS(P.Monto) ELSE 0 END) AS TotalFacturado,
        SUM(CASE WHEN P.TipoMonto = 'P' THEN P.Monto ELSE 0 END) AS TotalPagado
    FROM pagosproveedores P
    INNER JOIN terceros T ON P.Nit = T.CedulaNit
    WHERE P.Estado = '1' AND P.F_Creacion BETWEEN '$f_inicio' AND '$f_fin'
    GROUP BY P.F_Creacion, P.Nit
    ORDER BY P.F_Creacion ASC, T.Nombre ASC
";

$res = $mysqli->query($query);
$datos_por_dia = [];
$saldos_vivos = $saldos_iniciales;

if($res){
    while($row = $res->fetch_assoc()){
        $nit = $row['Nit'];
        $previo = $saldos_vivos[$nit] ?? 0;
        $nuevo_saldo = $previo + $row['TotalPagado'] - $row['TotalFacturado'];
        
        $row['SaldoAnteriorCalculado'] = $previo;
        $row['SaldoActualizadoCalculado'] = $nuevo_saldo;
        
        $saldos_vivos[$nit] = $nuevo_saldo;
        $datos_por_dia[$row['F_Creacion']][] = $row;
    }
}

// 4. INVERSIÓN DESCENDENTE
krsort($datos_por_dia);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }
function formatFecha($f){ return substr($f,0,4)."-".substr($f,4,2)."-".substr($f,6,2); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Saldos Acumulados - <?= $fecha_desde ?></title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: auto; }
        .no-print { background: #1a252f; color: white; padding: 20px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .day-card { background: #fff; border-radius: 10px; margin-bottom: 30px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-top: 4px solid #3498db; }
        .day-header { background: #fff; padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 12px; font-size: 0.75em; text-transform: uppercase; color: #7f8c8d; }
        td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 0.9em; }
        .text-end { text-align: right; }
        .val-f { color: #e74c3c; }
        .val-p { color: #27ae60; }
        .row-total-dia { background: #fcfcfc; font-weight: bold; border-top: 2px solid #eee; }
        .saldo-final { background: #f4f7f6; font-weight: bold; }
        .banner-total { background: #27ae60; color: white; padding: 25px; border-radius: 10px; text-align: center; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="container">
    <div class="no-print">
        <form method="GET" style="display: flex; align-items: center; gap: 10px;">
            <h2 style="margin: 0; flex-grow: 1;">📊 Cartera Acumulada</h2>
            <span>Desde:</span> <input type="date" name="desde" value="<?= $fecha_desde ?>">
            <span>Hasta:</span> <input type="date" name="hasta" value="<?= $fecha_hasta ?>">
            <button type="submit" style="padding: 9px 20px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer;">Consultar</button>
        </form>
    </div>

    <?php foreach($datos_por_dia as $fecha_raw => $movimientos): 
        $sumAnterior = 0; $sumFacturado = 0; $sumPagado = 0; $sumFinal = 0;
    ?>
        <div class="day-card">
            <div class="day-header"><h3>📅 <?= formatFecha($fecha_raw) ?></h3></div>
            <table>
                <thead>
                    <tr>
                        <th align="left">Proveedor</th>
                        <th class="text-end">Saldo Anterior</th>
                        <th class="text-end">Compras (-)</th>
                        <th class="text-end">Abonos (+)</th>
                        <th class="text-end">Saldo Final</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($movimientos as $m): 
                        $sumAnterior  += $m['SaldoAnteriorCalculado'];
                        $sumFacturado += $m['TotalFacturado'];
                        $sumPagado    += $m['TotalPagado'];
                        $sumFinal     += $m['SaldoActualizadoCalculado'];
                    ?>
                        <tr>
                            <td><strong><?= strtoupper($m['Proveedor']) ?></strong></td>
                            <td class="text-end">$ <?= money($m['SaldoAnteriorCalculado']) ?></td>
                            <td class="text-end val-f">$ <?= money($m['TotalFacturado']) ?></td>
                            <td class="text-end val-p">$ <?= money($m['TotalPagado']) ?></td>
                            <td class="text-end saldo-final <?= ($m['SaldoActualizadoCalculado'] < 0) ? 'color:#c0392b;' : '' ?>">
                                $ <?= money($m['SaldoActualizadoCalculado']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="row-total-dia">
                        <td align="right">TOTALES DEL DÍA:</td>
                        <td class="text-end">$ <?= money($sumAnterior) ?></td>
                        <td class="text-end val-f">$ <?= money($sumFacturado) ?></td>
                        <td class="text-end val-p">$ <?= money($sumPagado) ?></td>
                        <td class="text-end" style="background: #ebf5fb; font-size: 1.1em;">$ <?= money($sumFinal) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endforeach; ?>

    <div class="banner-total">
        <small style="text-transform: uppercase;">Saldo Global de Cartera al <?= $fecha_hasta ?></small>
        <div style="font-size: 2.2em; font-weight: bold;">$ <?= money(array_sum($saldos_vivos)) ?></div>
    </div>
</div>

</body>
</html>