<?php
/**
 * DASHBOARD VENTAS - CON TOP PRODUCTOS HOY
 * Zona: America/Bogota
 */

date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");

$dbCentral = $mysqliCentral ?? $mysqli;
$dbDrinks  = $mysqliPos;

/* =====================================================
   FECHAS
===================================================== */
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input);
list($anio, $mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));
$esMesActual = (date('Y-m') == $fecha_input);
$diaHoyStr = date('d');
$hoyDiaNum = $esMesActual ? (int)$diaHoyStr : (int)$ultimoDiaMes;

/* =====================================================
   FUNCIONES BASE (VENTAS + EFECTIVO)
===================================================== */
function obtenerVentas($db, $anioMes) {
    $data = [];

    // FACTURAS
    $qF = "SELECT T1.NIT, T1.NOMBRES, F.FECHA,
                  SUM(DF.CANTIDAD * DF.VALORPROD) AS TOTAL
           FROM FACTURAS F
           INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
           INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
           LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
           WHERE F.ESTADO='0'
             AND DV.IDFACTURA IS NULL
             AND F.FECHA LIKE '$anioMes%'
           GROUP BY T1.NIT, T1.NOMBRES, F.FECHA";
    $resF = $db->query($qF);
    while($resF && $r = $resF->fetch_assoc()){
        $d = substr($r['FECHA'],6,2);
        $data[$r['NIT']]['NOM'] = $r['NOMBRES'];
        $data[$r['NIT']]['VENTAS'][$d] =
            ($data[$r['NIT']]['VENTAS'][$d] ?? 0) + $r['TOTAL'];
    }

    // PEDIDOS
    $qP = "SELECT V.NIT, V.NOMBRES, P.FECHA,
                  SUM(DP.CANTIDAD * DP.VALORPROD) AS TOTAL
           FROM PEDIDOS P
           INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
           INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO
           INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO
           WHERE P.ESTADO='0'
             AND P.FECHA LIKE '$anioMes%'
           GROUP BY V.NIT, V.NOMBRES, P.FECHA";
    $resP = $db->query($qP);
    while($resP && $r = $resP->fetch_assoc()){
        $d = substr($r['FECHA'],6,2);
        $data[$r['NIT']]['NOM'] = $r['NOMBRES'];
        $data[$r['NIT']]['VENTAS'][$d] =
            ($data[$r['NIT']]['VENTAS'][$d] ?? 0) + $r['TOTAL'];
    }

    return $data;
}

function obtenerEntregasDetalle($db, $anioMes) {
    $data = [];
    $q = "SELECT T1.NIT, S1.FECHA, SUM(VALOR) TOTAL
          FROM SALIDASCAJA S1
          INNER JOIN USUVENDEDOR U ON U.IDUSUARIO=S1.IDUSUARIO
          INNER JOIN TERCEROS T1 ON T1.IDTERCERO=U.IDTERCERO
          WHERE S1.FECHA LIKE '$anioMes%'
            AND (UPPER(MOTIVO) LIKE '%ENTREGA%' OR UPPER(MOTIVO) LIKE '%EFECTIVO%')
          GROUP BY T1.NIT, S1.FECHA";
    $res = $db->query($q);
    while($res && $r = $res->fetch_assoc()){
        $d = substr($r['FECHA'],6,2);
        $data[$r['NIT']][$d] = $r['TOTAL'];
    }
    return $data;
}

/* =====================================================
   🏆 PRODUCTOS MÁS VENDIDOS HOY (POR VALOR $)
===================================================== */
function obtenerTopProductosHoy($db) {

    $hoy = date('Ymd');
    $items = [];

    // FACTURAS
    $sqlF = "SELECT PR.DESCRIPCION AS producto,
                    SUM(DF.CANTIDAD) cant,
                    SUM(DF.CANTIDAD * DF.VALORPROD) total
             FROM FACTURAS F
             INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
             INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = DF.IDPRODUCTO
             LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
             WHERE F.ESTADO='0'
               AND DV.IDFACTURA IS NULL
               AND F.FECHA='$hoy'
             GROUP BY PR.DESCRIPCION";
    $rF = $db->query($sqlF);
    while($rF && $r = $rF->fetch_assoc()){
        $items[$r['producto']]['producto'] = $r['producto'];
        $items[$r['producto']]['cant'] =
            ($items[$r['producto']]['cant'] ?? 0) + $r['cant'];
        $items[$r['producto']]['total'] =
            ($items[$r['producto']]['total'] ?? 0) + $r['total'];
    }

    // PEDIDOS
    $sqlP = "SELECT PR.DESCRIPCION AS producto,
                    SUM(DP.CANTIDAD) cant,
                    SUM(DP.CANTIDAD * DP.VALORPROD) total
             FROM PEDIDOS P
             INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
             INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = DP.IDPRODUCTO
             WHERE P.ESTADO='0'
               AND P.FECHA='$hoy'
             GROUP BY PR.DESCRIPCION";
    $rP = $db->query($sqlP);
    while($rP && $r = $rP->fetch_assoc()){
        $items[$r['producto']]['producto'] = $r['producto'];
        $items[$r['producto']]['cant'] =
            ($items[$r['producto']]['cant'] ?? 0) + $r['cant'];
        $items[$r['producto']]['total'] =
            ($items[$r['producto']]['total'] ?? 0) + $r['total'];
    }

    // 👉 ORDENAR POR VALOR TOTAL ($)
    usort($items, fn($a,$b) => $b['total'] <=> $a['total']);

    return array_slice($items, 0, 18);
}

/* =====================================================
   CARGA DATOS
===================================================== */
$ventasC = obtenerVentas($dbCentral,$anioMes);
$ventasD = obtenerVentas($dbDrinks,$anioMes);
$entC    = obtenerEntregasDetalle($dbCentral,$anioMes);
$entD    = obtenerEntregasDetalle($dbDrinks,$anioMes);

$topC = obtenerTopProductosHoy($dbCentral);
$topD = obtenerTopProductosHoy($dbDrinks);

function money($v){ return number_format($v/1000,0,',','.'); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Dashboard Gerencial</title>
<style>
body{font-family:Segoe UI;background:#eef2f7;font-size:11px;margin:0;padding:15px}
.section{background:#fff;padding:15px;border-radius:8px;margin-bottom:20px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ddd;padding:8px;text-align:right}
th{background:#2c3e50;color:#fff}
td:first-child{text-align:left;font-weight:bold}
h2,h3{margin:5px 0}
</style>
</head>
<body>

<div class="section">
<h2>🏆 PRODUCTOS MÁS VENDIDOS HOY (<?=date('d/m/Y')?>)</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<div>
<h3>🏢 Central</h3>
<table>
<tr><th>Producto</th><th>Cant</th><th>Total</th></tr>
<?php foreach($topC as $p): ?>
<tr>
<td><?=$p['producto']?></td>
<td><?=$p['cant']?></td>
<td>$ <?=money($p['total'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div>
<h3>🍹 Drinks</h3>
<table>
<tr><th>Producto</th><th>Cant</th><th>Total</th></tr>
<?php foreach($topD as $p): ?>
<tr>
<td><?=$p['producto']?></td>
<td><?=$p['cant']?></td>
<td>$ <?=money($p['total'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>
</div>

</body>
</html>
