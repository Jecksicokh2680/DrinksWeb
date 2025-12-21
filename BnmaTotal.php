<?php
// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");

$dbCentral = $mysqliCentral ?? $mysqli; 
$dbDrinks  = $mysqliPos;

// =======================
// Configuración de Fecha
// =======================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input);
list($anio, $mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));
$hoyDia = (date('Y-m') == $fecha_input) ? date('d') : $ultimoDiaMes;

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

// Función para sumar totales de un array de datos
function calcularGranTotal($datos, $tipo) {
    $suma = 0;
    foreach($datos as $vendedor) {
        if(isset($vendedor['DIAS'])) {
            foreach($vendedor['DIAS'] as $dia) {
                $suma += $dia[$tipo] ?? 0;
            }
        }
    }
    return $suma;
}

// Totales para la tabla final
$tfCentral = calcularGranTotal($datosCentral, 'F');
$tpCentral = calcularGranTotal($datosCentral, 'P');
$tfDrinks  = calcularGranTotal($datosDrinks, 'F');
$tpDrinks  = calcularGranTotal($datosDrinks, 'P');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Consolidado Mensual</title>
    <style>
        :root { --primary: #2c3e50; --central: #2980b9; --drinks: #27ae60; --light: #f8f9fa; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #eef2f7; margin: 0; padding: 15px; font-size: 12px; color: #333; }
        
        .container { max-width: 1400px; margin: auto; }
        .section { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #dce3e9; }
        
        h2 { margin-top: 0; display: flex; align-items: center; gap: 10px; font-size: 1.4rem; }
        .header-central { color: var(--central); border-bottom: 2px solid var(--central); padding-bottom: 8px; }
        .header-drinks { color: var(--drinks); border-bottom: 2px solid var(--drinks); padding-bottom: 8px; }
        .header-resumen { color: var(--primary); border-bottom: 2px solid var(--primary); padding-bottom: 8px; }
        
        /* Responsive Table Wrapper */
        .table-responsive { width: 100%; overflow-x: auto; margin-top: 15px; -webkit-overflow-scrolling: touch; border-radius: 8px; }
        
        table { border-collapse: collapse; width: 100%; min-width: 800px; background: white; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: right; }
        th { background: var(--primary); color: white; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; position: sticky; top: 0; }
        
        td:first-child { text-align: left; background: var(--light); font-weight: bold; position: sticky; left: 0; z-index: 1; min-width: 160px; }
        
        .total-col { background: #edf2f7; font-weight: bold; color: var(--primary); }
        .total-row { background: var(--primary) !important; color: white; font-weight: bold; }
        
        /* Resumen Table */
        .resumen-table { min-width: 100% !important; font-size: 1.1rem; }
        .resumen-table td { padding: 15px; }
        .resumen-table th { font-size: 12px; padding: 12px; }

        .btn { padding: 10px 20px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .input-month { padding: 8px; border-radius: 6px; border: 1px solid #cbd5e0; margin-right: 10px; }

        @media (max-width: 768px) {
            body { padding: 8px; font-size: 11px; }
            .section { padding: 12px; }
            th, td { padding: 4px; }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- FILTRO -->
    <div class="section">
        <form method="GET" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
            <label><strong>📅 PERÍODO DE CONSULTA:</strong></label>
            <input type="month" name="fecha" class="input-month" value="<?=$fecha_input?>">
            <button type="submit" class="btn">Generar Reporte</button>
        </form>
    </div>

    <?php
    function dibujarTabla($titulo, $idx, $datos, $diasMes) {
        echo "<h3 style='margin-bottom:5px; color:#555;'>$titulo</h3>";
        echo "<div class='table-responsive'>";
        echo "<table><thead><tr><th>Facturador</th>";
        foreach($diasMes as $d) echo "<th>$d</th>";
        echo "<th>TOTAL</th></tr></thead><tbody>";

        $colTotals = array_fill_keys($diasMes, 0);
        $grandTotal = 0;

        if(empty($datos)) {
            echo "<tr><td colspan='".(count($diasMes)+2)."' style='text-align:center;'>No se registraron movimientos.</td></tr>";
        } else {
            foreach($datos as $nit => $info) {
                $rowTotal = 0;
                echo "<tr><td>{$info['NOM']}</td>";
                foreach($diasMes as $d) {
                    $v = $info['DIAS'][$d][$idx] ?? 0;
                    echo "<td>" . ($v > 0 ? money($v) : '-') . "</td>";
                    $colTotals[$d] += $v;
                    $rowTotal += $v;
                }
                echo "<td class='total-col'>".money($rowTotal)."</td>";
                $grandTotal += $rowTotal;
                echo "</tr>";
            }
        }
        echo "</tbody><tfoot><tr class='total-row'><td>TOTALES</td>";
        foreach($colTotals as $ct) echo "<td>".money($ct)."</td>";
        echo "<td>".money($grandTotal)."</td></tr></tfoot></table></div><br>";
    }
    ?>

    <!-- SECCIÓN CENTRAL -->
    <div class="section">
        <h2 class="header-central">🏢 ADMINISTRACIÓN - CENTRAL</h2>
        <?php 
            dibujarTabla("📊 FACTURAS (Venta Legal)", "F", $datosCentral, $diasMes); 
            dibujarTabla("📝 PEDIDOS (Remisiones/Preventa)", "P", $datosCentral, $diasMes); 
        ?>
    </div>

    <!-- SECCIÓN DRINKS -->
    <div class="section">
        <h2 class="header-drinks">🍹 PUNTO DE VENTA - DRINKS</h2>
        <?php 
            dibujarTabla("📊 FACTURAS (Venta Legal)", "F", $datosDrinks, $diasMes); 
            dibujarTabla("📝 PEDIDOS (Remisiones/Preventa)", "P", $datosDrinks, $diasMes); 
        ?>
    </div>

    <!-- TABLA DE RESUMEN FINAL -->
    <div class="section">
        <h2 class="header-resumen">🏁 CONSOLIDADO FINAL (Valores en miles)</h2>
        <div class="table-responsive">
            <table class="resumen-table">
                <thead>
                    <tr>
                        <th style="text-align: left;">ORIGEN</th>
                        <th>TOTAL FACTURAS (Legal)</th>
                        <th>TOTAL PEDIDOS (Interno)</th>
                        <th style="background:#2d3748">TOTAL SEDE</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>SEDE CENTRAL</td>
                        <td style="color:var(--central)">$<?=money($tfCentral)?></td>
                        <td>$<?=money($tpCentral)?></td>
                        <td class="total-col">$<?=money($tfCentral + $tpCentral)?></td>
                    </tr>
                    <tr>
                        <td>SEDE DRINKS</td>
                        <td style="color:var(--drinks)">$<?=money($tfDrinks)?></td>
                        <td>$<?=money($tpDrinks)?></td>
                        <td class="total-col">$<?=money($tfDrinks + $tpDrinks)?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>TOTAL GLOBAL</td>
                        <td>$<?=money($tfCentral + $tfDrinks)?></td>
                        <td>$<?=money($tpCentral + $tpDrinks)?></td>
                        <td style="background:#ffd700; color:#000;">$<?=money($tfCentral + $tfDrinks + $tpCentral + $tpDrinks)?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

</body>
</html>