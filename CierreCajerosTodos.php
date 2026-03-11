<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES (AMBAS SEDES)
============================================================ */
date_default_timezone_set('America/Bogota');
require("ConnCentral.php"); // Conexión Sede Central
require("Conexion.php");    // Conexión General
require("ConnDrinks.php");  // Conexión Sede Drinks

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha_esc   = str_replace('-', '', $fecha_input);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

// Definimos los canales de consulta para consolidar
$sedes = [
    ['conn' => $mysqliCentral, 'nombre' => 'CENTRAL'],
    ['conn' => $mysqliDrinks,  'nombre' => 'DRINKS (AWS)']
];

$globalVentas  = 0;
$globalEgresos = 0;
$globalTransf  = 0;
$globalFisico  = 0;

$dataConsolidada = [];

/* ============================================================
    PROCESAMIENTO DE TODAS LAS SEDES
============================================================ */
foreach ($sedes as $s) {
    $mysqliActiva = $s['conn'];
    $nombreSede   = $s['nombre'];

    // Listar cajeros activos en esta sede específica
    $qryCajeros = "SELECT NIT, NOMBRE FROM (
        SELECT T1.NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOMBRE FROM FACTURAS F 
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
        UNION 
        SELECT V.NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS NOMBRE FROM PEDIDOS P 
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
    ) X GROUP BY NIT ORDER BY NOMBRE ASC";

    $resCajeros = $mysqliActiva->query($qryCajeros);

    if ($resCajeros) {
        while ($c = $resCajeros->fetch_assoc()) {
            $nit = $c['NIT'];

            // 1. Suma de Ventas (Facturas + Pedidos)
            $qV = "SELECT SUM(VAL) AS TOTAL FROM (
                SELECT SUM(DF.CANTIDAD*DF.VALORPROD) AS VAL FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
                WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND T1.NIT='$nit'
                UNION ALL 
                SELECT SUM(DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
                INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$nit'
            ) AS X";
            $resV = $mysqliActiva->query($qV);
            $vts  = (float)($resV->fetch_assoc()['TOTAL'] ?? 0);

            // 2. Suma de Egresos
            $qE = "SELECT SUM(S1.VALOR) AS TOTAL FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $resE = $mysqliActiva->query($qE);
            $egr  = (float)($resE->fetch_assoc()['TOTAL'] ?? 0);

            // 3. Suma de Transferencias
            $qT = "SELECT SUM(Monto) AS TOTAL FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$nit'";
            $resT = $mysqli->query($qT);
            $trf  = (float)($resT->fetch_assoc()['TOTAL'] ?? 0);

            $fisico = $vts - $egr - $trf; // Cálculo corregido

            $dataConsolidada[] = [
                'sede'   => $nombreSede,
                'nombre' => $c['NOMBRE'],
                'ventas' => $vts,
                'egr'    => $egr,
                'trf'    => $trf,
                'fisico' => $fisico
            ];

            // Acumuladores Globales
            $globalVentas  += $vts;
            $globalEgresos += $egr;
            $globalTransf  += $trf;
            $globalFisico  += $fisico;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Resumen Consolidado Todas las Sedes</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #f0f4f8; margin: 20px; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
        .card { background: white; padding: 15px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 5px solid #2c3e50; }
        .sede-label { font-size: 0.8em; color: #777; font-weight: bold; }
        .table-res { width: 100%; margin-top: 10px; font-size: 0.9em; }
        .text-end { text-align: right; font-weight: bold; }
        .total-fisico { background: #fff3cd; padding: 8px; border-radius: 5px; font-weight: bold; margin-top: 10px; display: flex; justify-content: space-between; }
        .global-footer { background: #1f2d3d; color: white; padding: 20px; border-radius: 10px; margin-top: 30px; display: flex; justify-content: space-around; flex-wrap: wrap; text-align: center; }
        .global-footer b { display: block; font-size: 1.5em; color: #f39c12; }
    </style>
</head>
<body>

<div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 20px;">
    <h2>Resumen General (Todas las Sedes)</h2>
    <form method="GET">
        <b>Fecha:</b> <input type="date" name="fecha" value="<?= $fecha_input ?>" onchange="this.form.submit()">
    </form>
</div>

<div class="card-grid">
    <?php foreach($dataConsolidada as $item): ?>
    <div class="card">
        <span class="sede-label"><?= $item['sede'] ?></span>
        <h3 style="margin: 5px 0;"><?= htmlspecialchars($item['nombre']) ?></h3>
        <table class="table-res">
            <tr><td>Ventas:</td><td class="text-end">$ <?= money($item['ventas']) ?></td></tr>
            <tr><td>Egresos:</td><td class="text-end" style="color:red;">$ <?= money($item['egr']) ?></td></tr>
            <tr><td>Transf:</td><td class="text-end" style="color:blue;">$ <?= money($item['trf']) ?></td></tr>
        </table>
        <div class="total-fisico">
            <span>TOTAL FÍSICO:</span>
            <span>$ <?= money($item['fisico']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="global-footer">
    <div>VENTAS TOTALES <b>$ <?= money($globalVentas) ?></b></div>
    <div>EGRESOS TOTALES <b>$ <?= money($globalEgresos) ?></b></div>
    <div>TRANSF. TOTALES <b>$ <?= money($globalTransf) ?></b></div>
    <div style="border-left: 2px solid #555; padding-left: 20px;">
        TOTAL EFECTIVO GLOBAL <b style="color:#2ecc71;">$ <?= money($globalFisico) ?></b>
    </div>
</div>

</body>
</html>