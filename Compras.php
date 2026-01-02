<?php
session_start();

/* =========================
   CONEXIONES
========================= */
require('Conexion.php');        // $mysqliWeb
require('ConnCentral.php');     // $mysqliCentral
require('ConnDrinks.php');      // $mysqliDrinks

/* =========================
   VALIDAR SESIÓN
========================= */
$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') {
    header("Location: Login.php");
    exit;
}

/* =========================
   AUTORIZACIÓN
========================= */
function Autorizacion($User, $Solicitud) {
    global $mysqliWeb;
    $stmt = $mysqliWeb->prepare("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE CedulaNit=? AND Nro_Auto=?
        LIMIT 1
    ");
    $stmt->bind_param("ss",$User,$Solicitud);
    $stmt->execute();
    $r = $stmt->get_result();
    return ($r && $r->num_rows)?$r->fetch_assoc()['Swich']:"NO";
}

$PuedeVerUtil = (Autorizacion($User,'9999')==='SI');
function fmoneda($v){ return number_format($v,0,',','.'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compras Gerenciales</title>

<style>
/* =======================
   BASE
======================= */
body{
    font-family:Segoe UI,Arial;
    margin:15px;
    background:#f4f6f8;
    font-size:16px;
}

h2{
    font-size:26px;
    margin-bottom:15px
}

/* =======================
   CARD
======================= */
.card{
    background:#fff;
    padding:20px;
    border-radius:14px;
    box-shadow:0 6px 16px rgba(0,0,0,.10)
}

/* =======================
   FILTROS
======================= */
.filters{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:14px;
    margin-bottom:15px
}

label{
    font-size:14px;
    font-weight:700
}

select,input,button{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:16px
}

button{
    background:#0d6efd;
    color:#fff;
    font-weight:700;
    cursor:pointer
}

/* =======================
   CONTENEDOR TABLA
======================= */
.table-container{
    max-height:70vh;
    overflow:auto;
    border-radius:12px;
    border:1px solid #ddd
}

/* =======================
   TABLA
======================= */
table{
    border-collapse:collapse;
    width:100%;
    min-width:1200px;
    font-size:15px
}

th,td{
    border:1px solid #ddd;
    padding:8px 10px;
    text-align:right;
    white-space:nowrap
}

.text-left{text-align:left}

/* =======================
   CABECERA FIJA
======================= */
thead th{
    position:sticky;
    top:0;
    z-index:10;
    background:#f1f3f5;
    font-size:15px;
    font-weight:800;
    text-align:center
}

/* =======================
   ESTADOS
======================= */
.badge{
    padding:4px 10px;
    border-radius:14px;
    color:#fff;
    font-size:13px;
    font-weight:700
}
.central{background:#0d6efd}
.drinks{background:#198754}

/* =======================
   TOTALES
======================= */
.subtotal{
    background:#eef6ff;
    font-weight:800;
    font-size:16px
}

.total{
    background:#e6fffa;
    font-weight:900;
    font-size:17px
}

.porc-pos{color:#1b5e20;font-weight:800}
.porc-neg{color:#b71c1c;font-weight:800}

/* =======================
   MOBILE
======================= */
@media(max-width:768px){
    body{font-size:17px}
    h2{font-size:22px}
    table{font-size:16px}
    th,td{padding:10px}
}
</style>
</head>

<body>

<div class="card">
<h2>📊 Compras Gerenciales</h2>

<form method="GET" class="filters">
<div>
<label>Fecha</label>
<input type="date" name="Fecha" required
value="<?=htmlspecialchars($_GET['Fecha']??date('Y-m-d'))?>">
</div>

<div>
<label>Sucursal</label>
<select name="Sucursal">
<option value="AMBAS">Ambas</option>
<option value="CENTRAL" <?=($_GET['Sucursal']??'')=='CENTRAL'?'selected':''?>>Central</option>
<option value="DRINKS" <?=($_GET['Sucursal']??'')=='DRINKS'?'selected':''?>>Drinks</option>
</select>
</div>

<div>
<label>Proveedor</label>
<select name="Proveedor">
<option value="">Todos</option>
<?php
if (!empty($_GET['Fecha'])) {
    $FechaSQL = DateTime::createFromFormat('Y-m-d', $_GET['Fecha'])->format('Ymd');
    $Sucursal = $_GET['Sucursal'] ?? 'AMBAS';

    function proveedoresDia($mysqli,$FechaSQL){
        return $mysqli->query("
            SELECT DISTINCT T.NIT,
                   CONCAT(T.nombres,' ',T.apellidos) prov
            FROM compras C
            INNER JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
            WHERE C.FECHA='$FechaSQL'
              AND C.ESTADO='0'
            ORDER BY prov
        ");
    }

    $prov=[];
    if($Sucursal!='DRINKS'){
        $r=proveedoresDia($mysqliCentral,$FechaSQL);
        while($r && $p=$r->fetch_assoc()) $prov[$p['NIT']]=$p['prov'];
    }
    if($Sucursal!='CENTRAL'){
        $r=proveedoresDia($mysqliDrinks,$FechaSQL);
        while($r && $p=$r->fetch_assoc()) $prov[$p['NIT']]=$p['prov'];
    }

    foreach($prov as $nit=>$nom){
        $sel=($_GET['Proveedor']??'')==$nit?'selected':'';
        echo "<option value='$nit' $sel>$nom</option>";
    }
}
?>
</select>
</div>

<div>
<label>&nbsp;</label>
<button type="submit">Consultar</button>
</div>
</form>

<?php
if (!empty($_GET['Fecha'])) {

$Proveedor=preg_replace('/[^0-9]/','',$_GET['Proveedor']??'');

/* =========================
   PRECIO PROMEDIO VENTA
========================= */
function precioProm($mysqli){
    $sql="
    SELECT Q.Barcode,
    SUM(Q.CANTIDAD*Q.VALORPROD)/NULLIF(SUM(Q.CANTIDAD),0) pv
    FROM(
      SELECT P.Barcode,D.CANTIDAD,D.VALORPROD
      FROM DETPEDIDOS D
      JOIN PEDIDOS PE ON PE.IDPEDIDO=D.IDPEDIDO
      JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
      WHERE PE.ESTADO='0' AND STR_TO_DATE(PE.FECHA,'%Y%m%d') 
              >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
      UNION ALL
      SELECT P.Barcode,D.CANTIDAD,D.VALORPROD
      FROM FACTURAS F
      JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA
      JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
      WHERE F.ESTADO='0' AND STR_TO_DATE(F.FECHA,'%Y%m%d') 
              >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
    )Q GROUP BY Q.Barcode";
    $out=[]; $r=$mysqli->query($sql);
    while($r && $x=$r->fetch_assoc()) $out[$x['Barcode']]=$x['pv'];
    return $out;
}

$pvC=precioProm($mysqliCentral);
$pvD=precioProm($mysqliDrinks);

function compras($mysqli,$suc,$FechaSQL,$Proveedor){
    $cond=$Proveedor?"AND T.NIT='$Proveedor'":"";
    return $mysqli->query("
    SELECT '$suc' sucursal,
           C.idcompra,
           CONCAT(T.nombres,' ',T.apellidos) prov,
           P.Barcode,P.descripcion,
           D.CANTIDAD,D.VALOR,D.descuento,
           D.porciva,D.ValICUIUni
    FROM compras C
    JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
    JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
    JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
    WHERE C.FECHA='$FechaSQL'
      AND C.ESTADO='0' $cond
    ORDER BY prov,C.idcompra
    ");
}

$res=[];
if(($Sucursal??'AMBAS')!='DRINKS') $res[]=compras($mysqliCentral,'Central',$FechaSQL,$Proveedor);
if(($Sucursal??'AMBAS')!='CENTRAL') $res[]=compras($mysqliDrinks,'Drinks',$FechaSQL,$Proveedor);

echo "<div class='table-container'><table><thead><tr>
<th>Suc</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>
<th>Cant</th><th>Costo</th><th>Total</th>";
if($PuedeVerUtil) echo "<th>P.Venta</th><th>Util</th><th>%</th>";
echo "</tr></thead><tbody>";

$provAnt=''; $sub=0; $gran=0;

foreach($res as $r){
while($r && $x=$r->fetch_assoc()){

$cant=$x['CANTIDAD'];
$net=($x['VALOR']-($x['descuento']/max($cant,1)));
$costo=$net+($net*$x['porciva']/100)+$x['ValICUIUni'];
$total=$costo*$cant;

$pv=($x['sucursal']=='Central')?($pvC[$x['Barcode']]??0):($pvD[$x['Barcode']]??0);
$util=($pv-$costo)*$cant;
$porc=$costo>0?(($pv-$costo)/$costo)*100:0;

if($provAnt && $provAnt!=$x['prov']){
echo "<tr class='subtotal'><td colspan='7'>Subtotal $provAnt</td>
<td>".fmoneda($sub)."</td>";
if($PuedeVerUtil) echo "<td colspan='3'></td>";
echo "</tr>"; $sub=0;
}

$sub+=$total; $gran+=$total; $provAnt=$x['prov'];

$cls=$x['sucursal']=='Central'?'central':'drinks';
$clsP=$porc>=0?'porc-pos':'porc-neg';

echo "<tr>
<td><span class='badge $cls'>{$x['sucursal']}</span></td>
<td>{$x['idcompra']}</td>
<td class='text-left'>{$x['prov']}</td>
<td class='text-left'>{$x['Barcode']}</td>
<td class='text-left'>{$x['descripcion']}</td>
<td>".number_format($cant,0)."</td>
<td>".fmoneda($costo)."</td>
<td>".fmoneda($total)."</td>";

if($PuedeVerUtil){
echo "<td>".fmoneda($pv)."</td>
<td>".fmoneda($util)."</td>
<td class='$clsP'>".number_format($porc,1)."%</td>";
}
echo "</tr>";
}}

echo "<tr class='subtotal'><td colspan='7'>Subtotal $provAnt</td>
<td>".fmoneda($sub)."</td>";
if($PuedeVerUtil) echo "<td colspan='3'></td>";
echo "</tr>";

echo "<tr class='total'><td colspan='7'>TOTAL GENERAL</td>
<td>".fmoneda($gran)."</td>";
if($PuedeVerUtil) echo "<td colspan='3'></td>";
echo "</tr>";

echo "</tbody></table></div>";
}
?>
</div>
</body>
</html>
