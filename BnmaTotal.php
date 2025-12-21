<?php
require("ConnCentral.php");
require("ConnDrinks.php");

// =======================
// Mes (YYYY-MM)
// =======================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input); // YYYYMM
$anioMes_esc = $mysqliPos->real_escape_string($anioMes);

// =======================
// Obtener todos los días del mes
// =======================
list($anio,$mes) = explode('-', $fecha_input);
$ultimoDiaMes = date('t', strtotime("$anio-$mes-01"));
$hoyDia = (date('Y-m')==$fecha_input) ? date('d') : $ultimoDiaMes;
$diasMes = [];
$nombreDias = ['Sun'=>'DOM','Mon'=>'LUN','Tue'=>'MAR','Wed'=>'MIE','Thu'=>'JUE','Fri'=>'VIE','Sat'=>'SAB'];
for($d=1;$d<=$hoyDia;$d++){
    $diaStr = str_pad($d,2,'0',STR_PAD_LEFT);
    $nombreDia = date('D', strtotime("$anio-$mes-$diaStr"));
    $diasMes[$diaStr] = $nombreDias[$nombreDia] . " ($diaStr)";
}

// =======================
// 1. CONSULTA DE FACTURAS (Venta Formal)
// =======================
$qryFacturas = "
    SELECT T1.NIT AS FACTURADOR_NIT, T1.NOMBRES AS FACTURADOR, F.FECHA, SUM(DF.CANTIDAD*DF.VALORPROD) AS TOTAL
    FROM FACTURAS F
    INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA=F.IDFACTURA
    WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND LEFT(F.FECHA,6)='$anioMes_esc'
    GROUP BY FACTURADOR_NIT, FACTURADOR, F.FECHA
    ORDER BY FACTURADOR, F.FECHA;
";
$resFacturas = $mysqliPos->query($qryFacturas);

// =======================
// 2. CONSULTA DE PEDIDOS (Venta Informal/Preventa)
// =======================
$qryPedidos = "
    SELECT V.NIT AS FACTURADOR_NIT, V.NOMBRES AS FACTURADOR, P.FECHA, SUM(DP.CANTIDAD*DP.VALORPROD) AS TOTAL
    FROM PEDIDOS P
    INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO
    INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO
    WHERE P.ESTADO='0' AND LEFT(P.FECHA,6)='$anioMes_esc'
    GROUP BY FACTURADOR_NIT, FACTURADOR, P.FECHA
    ORDER BY FACTURADOR, P.FECHA;
";
$resPedidos = $mysqliPos->query($qryPedidos);

// [Otras consultas como Egresos y Transferencias se mantienen igual...]

// =======================
// Helper y Preparación de Datos
// =======================
define('MOSTRAR_EN_MILES', true);
function money($v){
    if(MOSTRAR_EN_MILES) return number_format($v / 1000, 0, ',', '.');
    return number_format($v, 0, ',', '.');
}

$datos = [];

// Procesar Facturas
while($f = $resFacturas->fetch_assoc()){
    $nit = trim($f['FACTURADOR_NIT']);
    $dia = substr($f['FECHA'],6,2);
    if(!isset($datos[$nit])) $datos[$nit] = ['FACTURADOR'=>$f['FACTURADOR'],'DIAS'=>[]];
    $datos[$nit]['DIAS'][$dia]['FACTURAS'] = (float)$f['TOTAL'];
}

// Procesar Pedidos
while($p = $resPedidos->fetch_assoc()){
    $nit = trim($p['FACTURADOR_NIT']);
    $dia = substr($p['FECHA'],6,2);
    if(!isset($datos[$nit])) $datos[$nit] = ['FACTURADOR'=>$p['FACTURADOR'],'DIAS'=>[]];
    $datos[$nit]['DIAS'][$dia]['PEDIDOS'] = (float)$p['TOTAL'];
}

// Asegurar que todos los días existan en el array para evitar errores de índice
foreach($datos as $nit => &$d){
    foreach($diasMes as $dia => $nombre){
        if(!isset($d['DIAS'][$dia])){
            $d['DIAS'][$dia] = ['FACTURAS'=>0,'PEDIDOS'=>0,'EGRESOS'=>0];
        } else {
            $d['DIAS'][$dia]['FACTURAS'] = $d['DIAS'][$dia]['FACTURAS'] ?? 0;
            $d['DIAS'][$dia]['PEDIDOS'] = $d['DIAS'][$dia]['PEDIDOS'] ?? 0;
        }
    }
}
unset($d);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Dashboard Desagrupado</title>
    <!-- [Tus estilos CSS se mantienen igual] -->
    <style>
        body{font-family:"Segoe UI",Arial,sans-serif;background:#eef3f7;margin:0;padding:20px;}
        .panel{background:#fff;padding:18px;border-radius:10px;margin-bottom:20px;box-shadow:0 6px 20px rgba(31,45,61,0.06);}
        .table{border-collapse:collapse;width:100%;margin-bottom:30px;}
        .table th,.table td{border:1px solid #d7dee6;padding:6px;font-size:11px;}
        .table th{background:#1f2d3d;color:white;}
        .table tr:nth-child(even){background:#f9fafb;}
        .total-row{background:#f1f4f7 !important; font-weight:bold;}
        h3{color:#1f2d3d; border-left: 5px solid #1f2d3d; padding-left:10px; margin-top:30px;}
    </style>
</head>
<body>
<div class="container">
    <div class="panel">
        <form method="GET">
            <input type="month" name="fecha" value="<?= htmlspecialchars($fecha_input) ?>">
            <button type="submit">Consultar</button>
        </form>
    </div>

    <div id="cierre-print" class="panel">
        <?php
        function generarTabla($titulo, $key, $datos, $diasMes){
            echo "<h3>$titulo</h3>";
            echo "<table class='table'><tr><th>Facturador</th>";
            foreach($diasMes as $nombre) echo "<th>$nombre</th>";
            echo "<th>TOTAL</th></tr>";
            
            $totalesColumnas = array_fill_keys(array_keys($diasMes), 0);
            $totalGeneral = 0;

            foreach($datos as $nit => $d){
                echo "<tr><td>" . htmlspecialchars($d['FACTURADOR']) . "</td>";
                $totalFila = 0;
                foreach($diasMes as $dia => $nombre){
                    $valor = $d['DIAS'][$dia][$key] ?? 0;
                    echo "<td>$" . money($valor) . "</td>";
                    $totalesColumnas[$dia] += $valor;
                    $totalFila += $valor;
                }
                echo "<td style='background:#f0f0f0; font-weight:bold;'>$" . money($totalFila) . "</td>";
                $totalGeneral += $totalFila;
                echo "</tr>";
            }

            // Fila de totales
            echo "<tr class='total-row'><td>TOTALES</td>";
            foreach($totalesColumnas as $v){
                echo "<td>$" . money($v) . "</td>";
            }
            echo "<td>$" . money($totalGeneral) . "</td></tr>";
            echo "</table>";
        }

        // Renderizar las tablas por separado
        generarTabla("📄 Detalle de FACTURAS (Sincronizadas)", "FACTURAS", $datos, $diasMes);
        generarTabla("📝 Detalle de PEDIDOS (Pendientes/Otros)", "PEDIDOS", $datos, $diasMes);
        ?>
    </div>
</div>
</body>
</html>