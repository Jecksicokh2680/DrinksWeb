<?php
/* ============================================================
    CONFIGURACIÓN DE TIEMPO Y CONEXIONES
============================================================ */
date_default_timezone_set('America/Bogota');
session_start();

require("ConnCentral.php"); 
require("Conexion.php"); 
require("ConnDrinks.php"); 

$fecha_ini_input = $_GET['fecha_ini'] ?? date('Y-m-d');
$fecha_fin_input = $_GET['fecha_fin'] ?? date('Y-m-d');

$f_ini_db = str_replace('-', '', $fecha_ini_input);
$f_fin_db = str_replace('-', '', $fecha_fin_input);

/* ============================================================
    LÓGICA DE CONSULTA MULTI-SEDE Y CONSOLIDACIÓN
============================================================ */
$sedes = [
    'CENTRAL' => $mysqliCentral,
    'DRINKS'  => $mysqliDrinks
];

$reporte = [];
$resumen_cajeros = [];
$resumen_sedes = ['CENTRAL' => 0, 'DRINKS' => 0];
$gran_total = 0;

foreach ($sedes as $nombre_sede => $conexion) {
    if (!$conexion) continue;

    $query = "SELECT 
                S1.NOMBREPC, S1.FECHA, T1.NIT,
                CONCAT(T1.nombres, ' ', T1.apellidos) AS USUA, 
                S1.MOTIVO, S1.VALOR, S1.IDSALIDA
              FROM SALIDASCAJA S1   
              INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = S1.IDUSUARIO
              INNER JOIN TERCEROS AS T1 ON T1.IDTERCERO = V1.IDTERCERO
              WHERE (S1.FECHA BETWEEN '$f_ini_db' AND '$f_fin_db')
              AND (UPPER(S1.MOTIVO) LIKE '%ENTREGA%' OR UPPER(S1.MOTIVO) LIKE '%EFECTIVO%' OR UPPER(S1.MOTIVO) LIKE '%MONEDA%')
              ORDER BY USUA ASC, S1.FECHA ASC";

    $res = $conexion->query($query);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['SEDE_ORIGEN'] = $nombre_sede;
            $cajero = $row['USUA'];
            
            $reporte[$cajero][] = $row;
            
            // Sumatorias para los resúmenes iniciales
            $resumen_cajeros[$cajero] = ($resumen_cajeros[$cajero] ?? 0) + (float)$row['VALOR'];
            $resumen_sedes[$nombre_sede] += (float)$row['VALOR'];
            $gran_total += (float)$row['VALOR'];
        }
    }
}
ksort($reporte);
ksort($resumen_cajeros);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }
function formatFecha($f){ return substr($f,0,4)."-".substr($f,4,2)."-".substr($f,6,2); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Consolidado de Entregas</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .wrapper { max-width: 1100px; margin: auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 25px; }
        
        /* Panel de Resumen Inicial */
        .summary-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 40px; }
        .summary-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .summary-card h3 { margin-top: 0; color: #2c3e50; border-bottom: 2px solid #27ae60; padding-bottom: 8px; }
        .resumen-table { width: 100%; border-collapse: collapse; }
        .resumen-table td { padding: 8px 0; border-bottom: 1px dashed #eee; }
        
        .cajero-card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px; }
        .cajero-header { background: #2c3e50; color: white; padding: 12px 15px; font-weight: bold; display: flex; justify-content: space-between; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 0.9em; }
        th { background: #f8f9fa; color: #7f8c8d; text-transform: uppercase; font-size: 0.75em; }
        .badge-sede { padding: 3px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-CENTRAL { background: #d4edda; color: #155724; }
        .badge-DRINKS { background: #fff3cd; color: #856404; }
        .gran-total-banner { background: #27ae60; color: white; padding: 25px; border-radius: 10px; text-align: center; font-size: 2.2em; }
        .no-print { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 10px; margin-bottom: 25px; }
        @media print { .no-print { display: none; } .wrapper { box-shadow: none; width: 100%; } }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="header">
        <h1 style="margin:0;">REPORTE CONSOLIDADO DE RECAUDO</h1>
        <p style="color:#7f8c8d;">Periodo: <?= $fecha_ini_input ?> al <?= $fecha_fin_input ?></p>
    </div>

    <div class="no-print">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; justify-content: center;">
            <label>Desde: <input type="date" name="fecha_ini" value="<?= $fecha_ini_input ?>"></label>
            <label>Hasta: <input type="date" name="fecha_fin" value="<?= $fecha_fin_input ?>"></label>
            <button type="submit" style="background:#2c3e50; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Actualizar Reporte</button>
            <button type="button" onclick="window.print()" style="padding:10px 20px; cursor:pointer;">🖨️ Imprimir</button>
        </form>
    </div>

    <?php if (empty($reporte)): ?>
        <div style="text-align:center; padding:50px; color:#95a5a6;"><h3>No hay datos para este rango.</h3></div>
    <?php else: ?>

        <div class="summary-grid">
            <div class="summary-card">
                <h3>👥 Totalizado por Cajero</h3>
                <table class="resumen-table">
                    <?php foreach($resumen_cajeros as $nom => $valor): ?>
                    <tr>
                        <td><strong><?= strtoupper($nom) ?></strong></td>
                        <td align="right" style="color:#27ae60; font-weight:bold;">$ <?= money($valor) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="summary-card">
                <h3>🏢 Total por Sede</h3>
                <table class="resumen-table">
                    <tr>
                        <td>Sede Central:</td>
                        <td align="right">$ <?= money($resumen_sedes['CENTRAL']) ?></td>
                    </tr>
                    <tr>
                        <td>Sede Drinks:</td>
                        <td align="right">$ <?= money($resumen_sedes['DRINKS']) ?></td>
                    </tr>
                    <tr style="border-top: 2px solid #2c3e50; font-weight:bold;">
                        <td>TOTAL:</td>
                        <td align="right" style="font-size:1.2em;">$ <?= money($gran_total) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid #eee; margin-bottom:40px;">

        <h2 style="color:#2c3e50; text-align:center;">📋 Detalle de Movimientos</h2>
        <?php foreach ($reporte as $cajero => $movs): $sub = 0; ?>
            <div class="cajero-card">
                <div class="cajero-header">
                    <span>👤 CAJERO: <?= strtoupper($cajero) ?></span>
                    <span>NIT: <?= $movs[0]['NIT'] ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Sede</th>
                            <th>Fecha</th>
                            <th>ID</th>
                            <th>PC</th>
                            <th>Motivo</th>
                            <th align="right">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movs as $m): $sub += $m['VALOR']; ?>
                        <tr>
                            <td><span class="badge-sede badge-<?= $m['SEDE_ORIGEN'] ?>"><?= $m['SEDE_ORIGEN'] ?></span></td>
                            <td><?= formatFecha($m['FECHA']) ?></td>
                            <td>#<?= $m['IDSALIDA'] ?></td>
                            <td><small><?= $m['NOMBREPC'] ?></small></td>
                            <td><?= $m['MOTIVO'] ?></td>
                            <td align="right"><strong>$ <?= money($m['VALOR']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#fcfcfc; font-weight:bold;">
                            <td colspan="5" align="right">SUBTOTAL CAJERO:</td>
                            <td align="right" style="color:#2980b9;">$ <?= money($sub) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="gran-total-banner">
            <small style="font-size: 0.4em; display: block; opacity: 0.8;">GRAN TOTAL RECAUDADO PERIODOS</small>
            $ <?= money($gran_total) ?>
        </div>

    <?php endif; ?>
</div>

</body>
</html>