<?php
// 1. Configurar Zona Horaria de Bogotá
date_default_timezone_set('America/Bogota');

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");

$dbCentral = $mysqliCentral ?? $mysqli; 
$dbDrinks  = $mysqliPos;

// =======================
// Configuración de Fechas
// =======================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input); // Formato YYYYMM
list($anio, $mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));

$esMesActual = (date('Y-m') == $fecha_input);
$diaHoyStr = date('d'); 
$hoyDiaNum = $esMesActual ? (int)$diaHoyStr : (int)$ultimoDiaMes;

$diasMes = [];
for ($d = 1; $d <= $hoyDiaNum; $d++) {
    $diasMes[] = str_pad($d, 2, '0', STR_PAD_LEFT);
}

// ===========================================
// Función para Extraer Ventas (Consolidado F+P)
// ===========================================
function obtenerVentas($db, $anioMes) {
    $data = [];
    // Facturas
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
        $data[$r['NIT']]['DIAS'][$dia] = ($data[$r['NIT']]['DIAS'][$dia] ?? 0) + (float)$r['TOTAL'];
    }
    // Pedidos
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
        $data[$r['NIT']]['DIAS'][$dia] = ($data[$r['NIT']]['DIAS'][$dia] ?? 0) + (float)$r['TOTAL'];
    }
    return $data;
}

// ===========================================
// Función para Extraer Entregas de Efectivo
// ===========================================
function obtenerEntregas($db, $anioMes) {
    $data = [];
    $qry = "SELECT T1.NIT, CONCAT(T1.nombres,' ',T1.apellidos) AS FACTURADOR, S1.FECHA, SUM(VALOR) AS TOTAL_ENTREGA
            FROM SALIDASCAJA S1
            INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO
            WHERE S1.FECHA LIKE '$anioMes%' AND
            (UPPER(S1.MOTIVO) LIKE '%ENTREGA%' OR UPPER(S1.MOTIVO) LIKE '%ENTREGADO%' OR 
             UPPER(S1.MOTIVO) LIKE '%ENTREGAS%' OR UPPER(S1.MOTIVO) LIKE '%UNIDADES%' OR 
             UPPER(S1.MOTIVO) LIKE '%EFECTIVO%')
            GROUP BY T1.NIT, T1.nombres, T1.apellidos, S1.FECHA";
    $res = $db->query($qry);
    while($res && $r = $res->fetch_assoc()){
        $dia = substr($r['FECHA'], 6, 2);
        $data[$r['NIT']]['NOM'] = $r['FACTURADOR'];
        $data[$r['NIT']]['DIAS'][$dia] = ($data[$r['NIT']]['DIAS'][$dia] ?? 0) + (float)$r['TOTAL_ENTREGA'];
    }
    return $data;
}

// Cargar Datos
$ventasCentral = obtenerVentas($dbCentral, $anioMes);
$entregasCentral = obtenerEntregas($dbCentral, $anioMes);

$ventasDrinks = obtenerVentas($dbDrinks, $anioMes);
$entregasDrinks = obtenerEntregas($dbDrinks, $anioMes);

// Helper Moneda
function money($v) {
    return number_format($v / 1000, 0, ',', '.');
}

// Función para calcular totales de resumen
function totalesResumen($datos, $diaHoy, $esMesActual) {
    $t = ['hoy' => 0, 'mes' => 0];
    foreach($datos as $u) {
        foreach($u['DIAS'] as $d => $val) {
            $t['mes'] += $val;
            if($esMesActual && $d == $diaHoy) $t['hoy'] += $val;
        }
    }
    return $t;
}

$resVentasC = totalesResumen($ventasCentral, $diaHoyStr, $esMesActual);
$resVentasD = totalesResumen($ventasDrinks, $diaHoyStr, $esMesActual);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Dashboard Ventas y Entregas - Bogotá</title>
    <style>
        :root { --primary: #2c3e50; --accent: #e67e22; --cash: #27ae60; --blue: #2980b9; }
        body { font-family: 'Segoe UI', sans-serif; background: #eef2f7; margin: 0; padding: 10px; font-size: 10px; color: #333; }
        .container { max-width: 100%; margin: auto; }
        .section { background: white; padding: 12px; border-radius: 8px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { margin: 0 0 8px 0; font-size: 1.2rem; display: flex; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #dee2e6; padding: 4px; text-align: right; }
        th { background: var(--primary); color: white; font-weight: normal; }
        td:first-child { text-align: left; font-weight: bold; background: #fafafa; position: sticky; left: 0; z-index: 5; min-width: 120px; }
        .col-hoy { background: #fff9db !important; font-weight: bold; color: #d9480f; }
        .col-total { background: #f1f3f5; font-weight: bold; }
        .total-row { background: #343a40 !important; color: white; font-weight: bold; }
        .btn { padding: 6px 12px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; }
        .table-responsive { width: 100%; overflow-x: auto; }
    </style>
</head>
<body>

<div class="container">
    <!-- FILTRO -->
    <div class="section">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <strong>Periodo:</strong>
            <input type="month" name="fecha" value="<?=$fecha_input?>">
            <button type="submit" class="btn">Actualizar</button>
            <div style="margin-left:auto; text-align:right;">
                <strong>Bogotá:</strong> <?=date('d/m/Y H:i')?> | <strong>Días:</strong> <?=$hoyDiaNum?>
            </div>
        </form>
    </div>

    <!-- 1. RESUMEN SUPERIOR -->
    <div class="section">
        <h2 style="color:var(--blue);">📊 RESUMEN CONSOLIDADO DE VENTAS (Cifras en Miles)</h2>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left;">SEDE</th>
                    <th style="background: #d9480f;">VENTA HOY</th>
                    <th style="background: #1971c2;">ACUMULADO MES</th>
                    <th style="background: #2b8a3e;">PROMEDIO DIARIO</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>🏢 CENTRAL</td>
                    <td class="col-hoy">$ <?=money($resVentasC['hoy'])?></td>
                    <td class="col-total">$ <?=money($resVentasC['mes'])?></td>
                    <td>$ <?=money($resVentasC['mes'] / $hoyDiaNum)?></td>
                </tr>
                <tr>
                    <td>🍹 DRINKS</td>
                    <td class="col-hoy">$ <?=money($resVentasD['hoy'])?></td>
                    <td class="col-total">$ <?=money($resVentasD['mes'])?></td>
                    <td>$ <?=money($resVentasD['mes'] / $hoyDiaNum)?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td>TOTAL GENERAL</td>
                    <td>$ <?=money($resVentasC['hoy'] + $resVentasD['hoy'])?></td>
                    <td>$ <?=money($resVentasC['mes'] + $resVentasD['mes'])?></td>
                    <td style="background:var(--accent);">$ <?=money(($resVentasC['mes'] + $resVentasD['mes']) / $hoyDiaNum)?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php
    // Función reutilizable para dibujar tablas día a día
    function dibujarTablaDiaADia($titulo, $datos, $diasMes, $color) {
        echo "<div class='section'><h2 style='color:$color;'>$titulo</h2>";
        echo "<div class='table-responsive'><table><thead><tr><th>Usuario</th>";
        foreach($diasMes as $d) echo "<th>$d</th>";
        echo "<th>TOTAL</th></tr></thead><tbody>";

        $colTotals = array_fill_keys($diasMes, 0);
        $grandTotal = 0;

        if(empty($datos)) {
            echo "<tr><td colspan='".(count($diasMes)+2)."' style='text-align:center;'>No hay registros</td></tr>";
        } else {
            foreach($datos as $nit => $info) {
                $rowTotal = 0;
                echo "<tr><td>{$info['NOM']}</td>";
                foreach($diasMes as $d) {
                    $v = $info['DIAS'][$d] ?? 0;
                    echo "<td>" . ($v > 0 ? money($v) : '-') . "</td>";
                    $colTotals[$d] += $v;
                    $rowTotal += $v;
                }
                echo "<td class='col-total'>".money($rowTotal)."</td>";
                $grandTotal += $rowTotal;
                echo "</tr>";
            }
        }

        echo "</tbody><tfoot><tr class='total-row'><td>TOTALES</td>";
        foreach($colTotals as $ct) echo "<td>".money($ct)."</td>";
        echo "<td>".money($grandTotal)."</td></tr></tfoot></table></div></div>";
    }

    // --- SECCIÓN CENTRAL ---
    dibujarTablaDiaADia("🏢 CENTRAL: Detalle Ventas Diarias (F + P)", $ventasCentral, $diasMes, "#2980b9");
    dibujarTablaDiaADia("🏢 CENTRAL: Detalle Entregas Efectivo Diarias", $entregasCentral, $diasMes, "#e67e22");

    // --- SECCIÓN DRINKS ---
    dibujarTablaDiaADia("🍹 DRINKS: Detalle Ventas Diarias (F + P)", $ventasDrinks, $diasMes, "#27ae60");
    dibujarTablaDiaADia("🍹 DRINKS: Detalle Entregas Efectivo Diarias", $entregasDrinks, $diasMes, "#d35400");
    ?>

</div>

</body>
</html>