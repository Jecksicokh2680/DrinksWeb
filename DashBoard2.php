<?php
require("ConnDrinks.php");
require("Conexion.php");

// =======================
// Mes (YYYY-MM)
// =======================
$fecha_input = $_GET['fecha'] ?? date('Y-m');
$anioMes = str_replace('-', '', $fecha_input); // YYYYMM
$anioMes_esc = $mysqliPos->real_escape_string($anioMes);

// =======================
// Obtener todos los días del mes hasta hoy si es el mes actual
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
// Facturadores
// =======================
$qryFacturadores = "
SELECT DISTINCT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, T1.NOMBRES AS FACTURADOR
    FROM FACTURAS F
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
    WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND LEFT(F.FECHA,6)='$anioMes_esc'
    UNION
    SELECT V.NIT AS FACTURADOR_NIT, V.NOMBRES AS FACTURADOR
    FROM PEDIDOS P
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO
    INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO
    WHERE P.ESTADO='0' AND LEFT(P.FECHA,6)='$anioMes_esc'
) X
ORDER BY FACTURADOR;
";
$factList = $mysqliPos->query($qryFacturadores);

// =======================
// Ventas por facturador y día
// =======================
$qryVentas = "
SELECT FACTURADOR_NIT, FACTURADOR, FECHA, SUM(TOTAL_LINEA) AS TOTAL
FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, T1.NOMBRES AS FACTURADOR, F.FECHA, (DF.CANTIDAD*DF.VALORPROD) AS TOTAL_LINEA
    FROM FACTURAS F
    INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA=F.IDFACTURA
    WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND LEFT(F.FECHA,6)='$anioMes_esc'
    UNION ALL
    SELECT V.NIT AS FACTURADOR_NIT, V.NOMBRES AS FACTURADOR, P.FECHA, (DP.CANTIDAD*DP.VALORPROD) AS TOTAL_LINEA
    FROM PEDIDOS P
    INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO
    INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO
    WHERE P.ESTADO='0' AND LEFT(P.FECHA,6)='$anioMes_esc'
) X
GROUP BY FACTURADOR_NIT, FACTURADOR, FECHA
ORDER BY FACTURADOR, FECHA;
";
$resV = $mysqliPos->query($qryVentas);

// =======================
// Egresos sin transferencias
// =======================
$qryEgresosNoTrans = "
SELECT T1.NIT AS FACTURADOR_NIT, CONCAT(T1.nombres,' ',T1.apellidos) AS FACTURADOR, S1.FECHA, SUM(VALOR) AS TOTAL_EGRESOS
FROM SALIDASCAJA S1
INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO
INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO
WHERE LEFT(S1.FECHA,6)='$anioMes_esc'
GROUP BY T1.NIT, T1.nombres, T1.apellidos, S1.FECHA
ORDER BY FACTURADOR, S1.FECHA;
";
$resEgresosNoTrans = $mysqliPos->query($qryEgresosNoTrans);

// =======================
// Transferencias
// =======================
$qryTransferencias = "
SELECT TRIM(CedulaNit) AS FACTURADOR_NIT, Fecha, SUM(Monto) AS TOTAL_TRANSFER
FROM Relaciontransferencias
WHERE LEFT(Fecha,6)='$anioMes_esc'
GROUP BY FACTURADOR_NIT, Fecha
ORDER BY FACTURADOR_NIT, Fecha;
";
$resTransfer = $mysqli->query($qryTransferencias);

// =======================
// Entregas de efectivo
// =======================
$qryEntregaEfectivo = "
SELECT T1.NIT AS FACTURADOR_NIT, CONCAT(T1.nombres,' ',T1.apellidos) AS FACTURADOR, S1.FECHA, SUM(VALOR) AS TOTAL_ENTREGA
FROM SALIDASCAJA S1
INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO
INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO
WHERE LEFT(S1.FECHA,6)='$anioMes_esc' AND
 (
 UPPER(S1.MOTIVO) LIKE '%ENTREGA%' OR
 UPPER(S1.MOTIVO) LIKE '%ENTREGADO%' OR
 UPPER(S1.MOTIVO) LIKE '%ENTREGAS%' OR
 UPPER(S1.MOTIVO) LIKE '%UNIDADES%' OR
 UPPER(S1.MOTIVO) LIKE '%EFECTIVO%'
 )
GROUP BY T1.NIT, T1.nombres, T1.apellidos, S1.FECHA
ORDER BY FACTURADOR, S1.FECHA;
";
$resEntrega = $mysqliPos->query($qryEntregaEfectivo);

// =======================
// Helper
// =======================
define('MOSTRAR_EN_MILES', true);

function money($v){
    if(MOSTRAR_EN_MILES){
        return number_format($v / 1000, 0, ',', '.');
    }
    return number_format($v, 0, ',', '.');
}

// function money($v){ return number_format((float)$v,0,',','.'); }

// =======================
// Armar array multidimensional
// =======================
$datos = [];
foreach($resV as $v){
    $nit = trim($v['FACTURADOR_NIT']);
    $fecha = substr($v['FECHA'],6,2);
    if(!isset($datos[$nit])) $datos[$nit] = ['FACTURADOR'=>$v['FACTURADOR'],'DIAS'=>[]];
    $datos[$nit]['DIAS'][$fecha]['VENTAS'] = (float)$v['TOTAL'];
}
foreach($resEgresosNoTrans as $e){
    $nit = trim($e['FACTURADOR_NIT']);
    $fecha = substr($e['FECHA'],6,2);
    if(!isset($datos[$nit])) continue;
    $datos[$nit]['DIAS'][$fecha]['EGRESOS'] = (float)$e['TOTAL_EGRESOS'];
}
foreach($resTransfer as $t){
    $nit = trim($t['FACTURADOR_NIT']);
    $fecha = substr($t['Fecha'],6,2);
    if(!isset($datos[$nit])) continue;
    $datos[$nit]['DIAS'][$fecha]['TRANSFER'] = (float)$t['TOTAL_TRANSFER'];
}
foreach($resEntrega as $e){
    $nit = trim($e['FACTURADOR_NIT']);
    $fecha = substr($e['FECHA'],6,2);
    if(!isset($datos[$nit])) continue;
    $datos[$nit]['DIAS'][$fecha]['ENTREGA'] = (float)$e['TOTAL_ENTREGA'];
}

// =======================
// Asegurar días con 0
// =======================
foreach($datos as $nit => &$d){
    foreach($diasMes as $dia => $nombre){
        if(!isset($d['DIAS'][$dia])){
            $d['DIAS'][$dia] = ['VENTAS'=>0,'EGRESOS'=>0,'TRANSFER'=>0,'ENTREGA'=>0];
        } else {
            $d['DIAS'][$dia]['VENTAS'] = $d['DIAS'][$dia]['VENTAS'] ?? 0;
            $d['DIAS'][$dia]['EGRESOS'] = $d['DIAS'][$dia]['EGRESOS'] ?? 0;
            $d['DIAS'][$dia]['TRANSFER'] = $d['DIAS'][$dia]['TRANSFER'] ?? 0;
            $d['DIAS'][$dia]['ENTREGA'] = $d['DIAS'][$dia]['ENTREGA'] ?? 0;
        }
    }
}
unset($d);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Dashboard Mensual por Facturador</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:"Segoe UI",Arial,sans-serif;background:#eef3f7;margin:0;padding:20px;}
.container{max-width:100%;margin:0 auto;overflow-x:auto;}
.panel{background:#fff;padding:18px;border-radius:10px;margin-bottom:20px;box-shadow:0 6px 20px rgba(31,45,61,0.06);}
.button,.btn-print{background:#1f2d3d;color:white;border:none;padding:9px 14px;border-radius:6px;cursor:pointer;}
.table{border-collapse:collapse;width:100%;min-width:600px;}
.table th,.table td{border:1px solid #d7dee6;padding:6px;font-size:12px;}
.table th{text-align:center;background:#1f2d3d;color:white;}
.table td{text-align:right;}
.table td:first-child{text-align:left;}
.total-final{font-weight:700;padding:4px;border-radius:4px;}
.total-final-td{font-weight:700;font-size:14px;border-radius:4px;}
@media(max-width:768px){.table th,.table td{font-size:10px;padding:4px;}}
</style>
<script>
function imprimirCierre(){
    const content=document.getElementById('cierre-print').innerHTML;
    const original=document.body.innerHTML;
    document.body.innerHTML=content;
    window.print();
    document.body.innerHTML=original;
    location.reload();
}
</script>
</head>
<body>
<div class="container">
<div class="panel">
<form method="GET">
<label>Mes:</label>
<input type="month" name="fecha" value="<?= htmlspecialchars($fecha_input) ?>">
<button class="button" type="submit">Consultar</button>
<button type="button" class="button" onclick="imprimirCierre()">🖨 Imprimir</button>
</form>
</div>

<div id="cierre-print" class="panel">

<?php
function generarTabla($titulo, $tipo, $datos, $diasMes, $colorSaldo=false){
    echo "<h3>$titulo</h3>";
    echo "<table class='table'><tr><th>Facturador</th>";
    foreach($diasMes as $nombre) echo "<th>$nombre</th>";
    echo "</tr>";
    $totales = array_fill_keys(array_keys($diasMes),0);
    foreach($datos as $nit=>$d){
        echo "<tr><td>".htmlspecialchars($d['FACTURADOR'])." ($nit)</td>";
        foreach($diasMes as $dia=>$nombre){
            $valor = 0;
            if($tipo==='SALDO'){
                // $valor = ($d['DIAS'][$dia]['VENTAS'] ?? 0) - ($d['DIAS'][$dia]['EGRESOS'] ?? 0);
                
                $valor = ($d['DIAS'][$dia]['EGRESOS'] ?? 0) - ($d['DIAS'][$dia]['VENTAS'] ?? 0);

                $color = $colorSaldo ? (($valor>=0)?'#d4f1d4':'#f5d4d4') : (($valor>=0)?'#0abf53':'#d93025');
                echo "<td style='background:$color' class='total-final'>\$".money($valor)."</td>";
            } else {
                $valor = $d['DIAS'][$dia][$tipo] ?? 0;
                echo "<td>\$".money($valor)."</td>";
            }
            $totales[$dia] += $valor;
        }
        echo "</tr>";
    }
    echo "<tr><td><strong>TOTALES</strong></td>";
    foreach($totales as $v){
        $color = ($v>=0)?'#d4f1d4':'#f5d4d4';
        echo "<td style='background:$color' class='total-final-td'>$".money($v)."</td>";
    }
    echo "</tr></table>";
}

// Generar tablas

generarTabla("📈 Ventas Día a Día por Facturador","VENTAS",$datos,$diasMes);
generarTabla("💰 Entrega de Efectivo Día a Día por Facturador","ENTREGA",$datos,$diasMes);
// generarTabla("💸 Transferencias Día a Día por Facturador","TRANSFER",$datos,$diasMes);
generarTabla("📊 Cierre  Diario por Facturador (Ventas − Egresos)","SALDO",$datos,$diasMes,true);
generarTabla("📉 Egresos Día a Día por Facturador (Excluyendo Transferencias)","EGRESOS",$datos,$diasMes);

?>

</div>
</div>
</body>
</html>
