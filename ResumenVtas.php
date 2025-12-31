<?php
/**
 * DASHBOARD VENTAS - DETALLE CON EFECTIVO HOY
 * Localización: America/Bogota
 */

// 1. Configurar Zona Horaria de Bogotá
date_default_timezone_set('America/Bogota');

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");

$dbCentral = $mysqliCentral ?? $mysqli; 
$dbDrinks  = $mysqliPos;

// ===========================================
// Configuración de Fechas
// ===========================================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input); 
list($anio, $mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));

$esMesActual = (date('Y-m') == $fecha_input);
$diaHoyStr = date('d'); 
$hoyDiaNum = $esMesActual ? (int)$diaHoyStr : (int)$ultimoDiaMes;

// ===========================================
// Función para Extraer Ventas (Facturas + Pedidos)
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
        $data[$r['NIT']]['VENTAS'][$dia] = ($data[$r['NIT']]['VENTAS'][$dia] ?? 0) + (float)$r['TOTAL'];
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
        $data[$r['NIT']]['VENTAS'][$dia] = ($data[$r['NIT']]['VENTAS'][$dia] ?? 0) + (float)$r['TOTAL'];
    }
    return $data;
}

// ===========================================
// Función para Extraer Entregas de Efectivo (Detalle)
// ===========================================
function obtenerEntregasDetalle($db, $anioMes) {
    $data = [];
    $qry = "SELECT T1.NIT, S1.FECHA, SUM(VALOR) AS TOTAL
            FROM SALIDASCAJA S1
            INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO
            WHERE S1.FECHA LIKE '$anioMes%' AND
            (UPPER(S1.MOTIVO) LIKE '%ENTREGA%' OR UPPER(S1.MOTIVO) LIKE '%EFECTIVO%' OR 
             UPPER(S1.MOTIVO) LIKE '%UNIDADES%' OR UPPER(S1.MOTIVO) LIKE '%ENTREGADO%')
            GROUP BY T1.NIT, S1.FECHA";
    $res = $db->query($qry);
    while($res && $r = $res->fetch_assoc()){
        $dia = substr($r['FECHA'], 6, 2);
        $data[$r['NIT']][$dia] = (float)$r['TOTAL'];
    }
    return $data;
}

// Cargar Datos
$ventasC = obtenerVentas($dbCentral, $anioMes);
$entregasDetC = obtenerEntregasDetalle($dbCentral, $anioMes);

$ventasD = obtenerVentas($dbDrinks, $anioMes);
$entregasDetD = obtenerEntregasDetalle($dbDrinks, $anioMes);

// Helper Moneda
function money($v) {
    return number_format($v / 1000, 0, ',', '.');
}

// Cálculo Totales para Resumen Global
function calcularTotalesSede($ventas, $entregasDet, $diaHoy, $esMesActual) {
    $t = ['v_hoy' => 0, 'v_mes' => 0, 'e_hoy' => 0, 'e_mes' => 0];
    foreach($ventas as $u) {
        foreach(($u['VENTAS'] ?? []) as $d => $v) {
            $t['v_mes'] += $v;
            if($esMesActual && $d == $diaHoy) $t['v_hoy'] += $v;
        }
    }
    foreach($entregasDet as $nit => $dias) {
        foreach($dias as $d => $v) {
            $t['e_mes'] += $v;
            if($esMesActual && $d == $diaHoy) $t['e_hoy'] += $v;
        }
    }
    return $t;
}

$totC = calcularTotalesSede($ventasC, $entregasDetC, $diaHoyStr, $esMesActual);
$totD = calcularTotalesSede($ventasD, $entregasDetD, $diaHoyStr, $esMesActual);

// --- PREPARACIÓN DATOS GRÁFICA ---
$mergedUsers = [];
$sources = [$ventasC, $ventasD];
foreach($sources as $src) {
    foreach($src as $nit => $uData) {
        if(!isset($mergedUsers[$nit])) {
            $mergedUsers[$nit] = ['name' => $uData['NOM'], 'sales_by_day' => array_fill(1, $ultimoDiaMes, 0)];
        }
        if(isset($uData['VENTAS'])) {
            foreach($uData['VENTAS'] as $dayStr => $amount) {
                $d = (int)$dayStr;
                if($d >= 1 && $d <= $ultimoDiaMes) {
                    $mergedUsers[$nit]['sales_by_day'][$d] += $amount;
                }
            }
        }
    }
}

// Colores para la gráfica
$palette = [
    '#3366CC','#DC3912','#FF9900','#109618','#990099','#3B3EAC','#0099C6','#DD4477',
    '#66AA00','#B82E2E','#316395','#994499','#22AA99','#AAAA11','#6633CC','#E67300'
];
$chartDatasets = [];
$pIdx = 0;

foreach($mergedUsers as $u) {
    $chartDatasets[] = [
        'label' => $u['name'],
        'data' => array_values($u['sales_by_day']),
        'backgroundColor' => $palette[$pIdx % count($palette)],
    ];
    $pIdx++;
}

$chartLabels = range(1, $ultimoDiaMes);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Dashboard Gerencial - Bogotá</title>
    <style>
        :root { --primary: #2c3e50; --accent: #e67e22; --blue: #2980b9; --cash: #27ae60; }
        body { font-family: 'Segoe UI', sans-serif; background: #eef2f7; margin: 0; padding: 15px; font-size: 11px; }
        .container { max-width: 1300px; margin: auto; }
        .section { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin: 0 0 10px 0; font-size: 1.3rem; border-bottom: 2px solid #eee; padding-bottom: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #dee2e6; padding: 10px; text-align: right; }
        th { background: var(--primary); color: white; font-size: 10px; text-transform: uppercase; }
        td:first-child { text-align: left; font-weight: bold; background: #fafafa; }
        .col-hoy { background: #fff9db !important; font-weight: bold; color: #d9480f; }
        .col-acum { background: #e7f5ff !important; font-weight: bold; color: #0b7285; }
        .col-efec { color: var(--cash); font-weight: bold; }
        .total-row { background: #343a40 !important; color: white; font-weight: bold; }
        .btn { padding: 8px 16px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    
    <!-- FILTRO -->
    <div class="section">
        <form method="GET" style="display:flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Periodo:</strong>
                <input type="month" name="fecha" value="<?=$fecha_input?>">
                <button type="submit" class="btn">ACTUALIZAR</button>
            </div>
            <div style="text-align:right;">
                <strong>Reloj Bogotá: <?=date('d/m/Y H:i')?></strong><br>
                <small>Días promediados: <?=$hoyDiaNum?></small>
            </div>
        </form>
    </div>

    <!-- RESUMEN GLOBAL SUPERIOR -->
    <div class="section" style="border-top: 5px solid var(--accent);">
        <h2 style="color:var(--primary);">📊 RESUMEN GENERAL (Consolidado F + P)</h2>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left;">Sede</th>
                    <th>Venta Hoy</th>
                    <th>Efectivo Hoy</th>
                    <th>Venta Acum. Mes</th>
                    <th>Efectivo Mes</th>
                    <th style="background:var(--accent);">Promedio Diario</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>🏢 SEDE CENTRAL</td>
                    <td class="col-hoy">$ <?=money($totC['v_hoy'])?></td>
                    <td class="col-efec">$ <?=money($totC['e_hoy'])?></td>
                    <td class="col-acum">$ <?=money($totC['v_mes'])?></td>
                    <td class="col-efec">$ <?=money($totC['e_mes'])?></td>
                    <td style="font-weight:bold;">$ <?=money($totC['v_mes'] / $hoyDiaNum)?></td>
                </tr>
                <tr>
                    <td>🍹 SEDE DRINKS</td>
                    <td class="col-hoy">$ <?=money($totD['v_hoy'])?></td>
                    <td class="col-efec">$ <?=money($totD['e_hoy'])?></td>
                    <td class="col-acum">$ <?=money($totD['v_mes'])?></td>
                    <td class="col-efec">$ <?=money($totD['e_mes'])?></td>
                    <td style="font-weight:bold;">$ <?=money($totD['v_mes'] / $hoyDiaNum)?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td>TOTAL GLOBAL</td>
                    <td>$ <?=money($totC['v_hoy'] + $totD['v_hoy'])?></td>
                    <td>$ <?=money($totC['e_hoy'] + $totD['e_hoy'])?></td>
                    <td>$ <?=money($totC['v_mes'] + $totD['v_mes'])?></td>
                    <td>$ <?=money($totC['e_mes'] + $totD['e_mes'])?></td>
                    <td style="background:var(--accent) !important;">$ <?=money(($totC['v_mes'] + $totD['v_mes']) / $hoyDiaNum)?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php
    /**
     * Función para dibujar detalle con columna de Efectivo Hoy
     */
    function dibujarDetalleCompleto($titulo, $ventas, $entregasDet, $diaHoy, $esMesActual, $divisor, $color) {
        echo "<div class='section'><h2 style='color:$color;'>$titulo</h2>";
        echo "<table><thead><tr>
                <th style='text-align:left;'>Usuario / Vendedor</th>
                <th style='background:#d9480f'>Venta Hoy</th>
                <th style='background:#27ae60'>Efectivo Hoy</th>
                <th style='background:#1971c2'>Venta Acumulada</th>
                <th style='background:#2b8a3e'>Promedio Diario</th>
              </tr></thead><tbody>";

        $sumVH = 0; $sumEH = 0; $sumVM = 0;

        if(empty($ventas)) {
            echo "<tr><td colspan='5' style='text-align:center;'>No hay registros</td></tr>";
        } else {
            foreach($ventas as $nit => $u) {
                $v_hoy = ($esMesActual) ? ($u['VENTAS'][$diaHoy] ?? 0) : 0;
                $e_hoy = ($esMesActual) ? ($entregasDet[$nit][$diaHoy] ?? 0) : 0;
                $v_mes = array_sum($u['VENTAS'] ?? []);
                $prom  = ($v_mes > 0) ? ($v_mes / $divisor) : 0;

                echo "<tr>
                        <td>{$u['NOM']}</td>
                        <td class='col-hoy'>$ ".money($v_hoy)."</td>
                        <td class='col-efec'>$ ".money($e_hoy)."</td>
                        <td class='col-acum'>$ ".money($v_mes)."</td>
                        <td style='font-weight:bold; color:#2b8a3e;'>$ ".money($prom)."</td>
                      </tr>";
                
                $sumVH += $v_hoy; $sumEH += $e_hoy; $sumVM += $v_mes;
            }
        }

        echo "</tbody><tfoot><tr class='total-row'>
                <td>TOTALES</td>
                <td>$ ".money($sumVH)."</td>
                <td>$ ".money($sumEH)."</td>
                <td>$ ".money($sumVM)."</td>
                <td>$ ".money($sumVM / $divisor)."</td>
              </tr></tfoot></table></div>";
    }

    // --- DETALLE CENTRAL ---
    dibujarDetalleCompleto("🏢 CENTRAL: Detalle Ventas y Entregas", $ventasC, $entregasDetC, $diaHoyStr, $esMesActual, $hoyDiaNum, "#2980b9");

    // --- DETALLE DRINKS ---
    dibujarDetalleCompleto("🍹 DRINKS: Detalle Ventas y Entregas", $ventasD, $entregasDetD, $diaHoyStr, $esMesActual, $hoyDiaNum, "#27ae60");
    ?>

    <!-- GRÁFICA -->
    <div class="section">
        <h2>📈 Evolución Ventas Diarias (Global)</h2>
        <div style="position: relative; height:450px; width:100%">
            <canvas id="salesChart"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: <?= json_encode($chartDatasets) ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { 
                            stacked: true, 
                            title: {display:true, text:'Día del Mes <?=$mes?>'} 
                        },
                        y: { 
                            stacked: true, 
                            title: {display:true, text:'Ventas ($)'},
                            ticks: {
                                callback: function(value) {
                                    return '$' + new Intl.NumberFormat('es-CO').format(value/1000) + 'k';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            modal: 'index',
                            intersect: false, // Permite ver todos los stacks al pasar por la columna
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>

</div>

</body>
</html>