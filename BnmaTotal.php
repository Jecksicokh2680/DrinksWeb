<?php
// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");

$dbCentral = $mysqliCentral ?? $mysqli; 
$dbDrinks  = $mysqliPos;

// =======================
// Configuración de Fecha y Divisor de Promedio
// =======================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input);
list($anio, $mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));

// Si es el mes actual, promediamos por los días transcurridos hasta hoy. 
// Si es un mes pasado, promediamos por el total de días del mes.
$hoyDia = (date('Y-m') == $fecha_input) ? (int)date('d') : (int)$ultimoDiaMes;

$diasMes = [];
for ($d = 1; $d <= $hoyDia; $d++) {
    $diasMes[] = str_pad($d, 2, '0', STR_PAD_LEFT);
}

// =======================
// Función para Extraer Datos
// =======================
function obtenerDatos($db, $anioMes) {
    $data = [];
    // 1. Facturas
    $qF = "SELECT T1.NIT, T1.NOMBRES, F.FECHA, SUM(DF.CANTIDAD*DF.VALORPROD) AS TOTAL
           FROM FACTURAS F
           INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA
           INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR
           LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA=F.IDFACTURA
           WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$anioMes%'
           GROUP BY T1.NIT, T1.NOMBRES, F.FECHA";
    $resF = $db->query($qF);
    while($resF && $r = $resF->fetch_assoc()){
        $dia = substr($r['FECHA'], 6, 2);
        $data[$r['NIT']]['NOM'] = $r['NOMBRES'];
        $data[$r['NIT']]['DIAS'][$dia]['F'] = (float)$r['TOTAL'];
    }
    // 2. Pedidos
    $qP = "SELECT V.NIT, V.NOMBRES, P.FECHA, SUM(DP.CANTIDAD*DP.VALORPROD) AS TOTAL
           FROM PEDIDOS P
           INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO
           INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO
           INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO
           WHERE P.ESTADO='0' AND P.FECHA LIKE '$anioMes%'
           GROUP BY V.NIT, V.NOMBRES, P.FECHA";
    $resP = $db->query($qP);
    while($resP && $r = $resP->fetch_assoc()){
        $dia = substr($r['FECHA'], 6, 2);
        $data[$r['NIT']]['NOM'] = $r['NOMBRES'];
        $data[$r['NIT']]['DIAS'][$dia]['P'] = (float)$r['TOTAL'];
    }
    return $data;
}

$datosCentral = obtenerDatos($dbCentral, $anioMes);
$datosDrinks  = obtenerDatos($dbDrinks, $anioMes);

// Helper Moneda
function money($v) {
    return number_format($v / 1000, 0, ',', '.');
}

// Totales para la tabla final
function calcularSuma($datos, $tipo) {
    $suma = 0;
    foreach($datos as $vendedor) {
        if(isset($vendedor['DIAS'])) {
            foreach($vendedor['DIAS'] as $dia) $suma += $dia[$tipo] ?? 0;
        }
    }
    return $suma;
}

$tfCentral = calcularSuma($datosCentral, 'F');
$tpCentral = calcularSuma($datosCentral, 'P');
$tfDrinks  = calcularSuma($datosDrinks, 'F');
$tpDrinks  = calcularSuma($datosDrinks, 'P');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Ventas - Promedios</title>
    <style>
        :root { --primary: #2c3e50; --central: #2980b9; --drinks: #27ae60; --light: #f8f9fa; --accent: #e67e22; }
        body { font-family: 'Segoe UI', sans-serif; background: #eef2f7; margin: 0; padding: 15px; font-size: 11px; color: #333; }
        .container { max-width: 1600px; margin: auto; }
        .section { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin: 0 0 10px 0; font-size: 1.3rem; display: flex; align-items: center; gap: 8px; }
        .header-central { color: var(--central); border-bottom: 2px solid var(--central); }
        .header-drinks { color: var(--drinks); border-bottom: 2px solid var(--drinks); }
        
        .table-responsive { width: 100%; overflow-x: auto; border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; min-width: 1000px; }
        th, td { border: 1px solid #dee2e6; padding: 5px; text-align: right; }
        th { background: var(--primary); color: white; font-size: 9px; position: sticky; top: 0; }
        
        td:first-child { text-align: left; background: #fdfdfd; font-weight: bold; position: sticky; left: 0; z-index: 2; min-width: 140px; }
        
        .col-total { background: #f1f5f9; font-weight: bold; color: #000; }
        .col-avg { background: #fff4e6; font-weight: bold; color: var(--accent); }
        .total-row { background: var(--primary) !important; color: white; font-weight: bold; }
        
        .resumen-table { min-width: 100% !important; font-size: 1rem; }
        .btn { padding: 8px 16px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <div class="section">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <strong>Mes:</strong>
            <input type="month" name="fecha" value="<?=$fecha_input?>" style="padding:5px;">
            <button type="submit" class="btn">Actualizar</button>
            <span style="font-size:0.9rem; color:#666; margin-left:auto;">
                Promediando sobre <b><?=$hoyDia?></b> días transcurridos.
            </span>
        </form>
    </div>

    <?php
    function dibujarTablaConPromedio($titulo, $idx, $datos, $diasMes, $divisor) {
        echo "<h3 style='margin:10px 0 5px 0;'>$titulo</h3>";
        echo "<div class='table-responsive'><table><thead><tr><th>Facturador</th>";
        foreach($diasMes as $d) echo "<th>$d</th>";
        echo "<th>TOTAL</th><th class='col-avg'>PROM. DÍA</th></tr></thead><tbody>";

        $colTotals = array_fill_keys($diasMes, 0);
        $grandTotal = 0;

        foreach($datos as $nit => $info) {
            $rowTotal = 0;
            echo "<tr><td>{$info['NOM']}</td>";
            foreach($diasMes as $d) {
                $v = $info['DIAS'][$d][$idx] ?? 0;
                echo "<td>" . ($v > 0 ? money($v) : '-') . "</td>";
                $colTotals[$d] += $v;
                $rowTotal += $v;
            }
            $promedio = $rowTotal / $divisor;
            echo "<td class='col-total'>".money($rowTotal)."</td>";
            echo "<td class='col-avg'>".money($promedio)."</td>";
            $grandTotal += $rowTotal;
            echo "</tr>";
        }

        echo "</tbody><tfoot><tr class='total-row'><td>TOTALES</td>";
        foreach($colTotals as $ct) echo "<td>".money($ct)."</td>";
        echo "<td>".money($grandTotal)."</td>";
        echo "<td style='background:var(--accent);'>".money($grandTotal / $divisor)."</td></tr></tfoot></table></div>";
    }
    ?>

    <div class="section">
        <h2 class="header-central">🏢 CENTRAL</h2>
        <?php 
            dibujarTablaConPromedio("Facturas Central", "F", $datosCentral, $diasMes, $hoyDia); 
            dibujarTablaConPromedio("Pedidos Central", "P", $datosCentral, $diasMes, $hoyDia); 
        ?>
    </div>

    <div class="section">
        <h2 class="header-drinks">🍹 DRINKS</h2>
        <?php 
            dibujarTablaConPromedio("Facturas Drinks", "F", $datosDrinks, $diasMes, $hoyDia); 
            dibujarTablaConPromedio("Pedidos Drinks", "P", $datosDrinks, $diasMes, $hoyDia); 
        ?>
    </div>

    <div class="section" style="border-top: 4px solid var(--accent);">
        <h2 style="color:var(--primary);">🏁 RESUMEN CONSOLIDADO (Cifras en miles)</h2>
        <div class="table-responsive">
            <table class="resumen-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Sede / Concepto</th>
                        <th>Facturas</th>
                        <th>Pedidos</th>
                        <th style="background:#2d3748">Total Sede</th>
                        <th class="col-avg">Promedio Diario</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>SEDE CENTRAL</td>
                        <td>$<?=money($tfCentral)?></td>
                        <td>$<?=money($tpCentral)?></td>
                        <td class="col-total">$<?=money($tfCentral + $tpCentral)?></td>
                        <td class="col-avg">$<?=money(($tfCentral + $tpCentral) / $hoyDia)?></td>
                    </tr>
                    <tr>
                        <td>SEDE DRINKS</td>
                        <td>$<?=money($tfDrinks)?></td>
                        <td>$<?=money($tpDrinks)?></td>
                        <td class="col-total">$<?=money($tfDrinks + $tpDrinks)?></td>
                        <td class="col-avg">$<?=money(($tfDrinks + $tpDrinks) / $hoyDia)?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row" style="font-size:1.2rem;">
                        <td>TOTAL GLOBAL</td>
                        <td>$<?=money($tfCentral + $tfDrinks)?></td>
                        <td>$<?=money($tpCentral + $tpDrinks)?></td>
                        <td style="background:#f1c40f; color:#000;">$<?=money($tfCentral + $tfDrinks + $tpCentral + $tpDrinks)?></td>
                        <td style="background:var(--accent);">$<?=money(($tfCentral + $tfDrinks + $tpCentral + $tpDrinks) / $hoyDia)?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

</body>
</html>