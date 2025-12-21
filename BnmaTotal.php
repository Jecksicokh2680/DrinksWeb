<?php
// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); // Provee $mysqliCentral (o $mysqli)
require("ConnDrinks.php");  // Provee $mysqliPos

// Identificar conexiones (ajustar nombres según tus archivos)
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

// Ejecutar extracciones
$datosCentral = obtenerDatos($dbCentral, $anioMes);
$datosDrinks  = obtenerDatos($dbDrinks, $anioMes);

// Helper Moneda
function money($v) {
    return number_format($v / 1000, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte Dual - Central & Drinks</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #f0f2f5; margin: 20px; font-size: 11px; }
        .section { background: white; padding: 20px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-central { border-left: 10px solid #2980b9; padding-left: 15px; color: #2980b9; }
        .header-drinks { border-left: 10px solid #27ae60; padding-left: 15px; color: #27ae60; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #dee2e6; padding: 4px; text-align: right; }
        th { background: #343a40; color: white; text-align: center; }
        td:first-child { text-align: left; background: #f8f9fa; font-weight: bold; min-width: 150px; }
        .total-col { background: #e9ecef; font-weight: bold; }
        .total-row { background: #343a40 !important; color: white; font-weight: bold; }
        .btn { padding: 8px 15px; background: #343a40; color: white; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

    <div class="section">
        <form method="GET">
            <label>Seleccionar Mes: </label>
            <input type="month" name="fecha" value="<?=$fecha_input?>">
            <button type="submit" class="btn">Actualizar Reportes</button>
        </form>
    </div>

    <?php
    function dibujarTabla($titulo, $idx, $datos, $diasMes) {
        echo "<h3>$titulo</h3>";
        echo "<table><thead><tr><th>Facturador</th>";
        foreach($diasMes as $d) echo "<th>$d</th>";
        echo "<th>TOTAL</th></tr></thead><tbody>";

        $colTotals = array_fill_keys($diasMes, 0);
        $grandTotal = 0;

        if(empty($datos)) {
            echo "<tr><td colspan='".(count($diasMes)+2)."'>No hay datos.</td></tr>";
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
        echo "<td>".money($grandTotal)."</td></tr></tfoot></table>";
    }
    ?>

    <!-- SECCIÓN CENTRAL -->
    <div class="section">
        <h2 class="header-central">🏢 SEDE CENTRAL</h2>
        <?php 
            dibujarTabla("1. Facturación Realizada (Central)", "F", $datosCentral, $diasMes); 
            dibujarTabla("2. Pedidos / Preventa (Central)", "P", $datosCentral, $diasMes); 
        ?>
    </div>

    <!-- SECCIÓN DRINKS -->
    <div class="section">
        <h2 class="header-drinks">🍹 SEDE DRINKS</h2>
        <?php 
            dibujarTabla("1. Facturación Realizada (Drinks)", "F", $datosDrinks, $diasMes); 
            dibujarTabla("2. Pedidos / Preventa (Drinks)", "P", $datosDrinks, $diasMes); 
        ?>
    </div>

</body>
</html>