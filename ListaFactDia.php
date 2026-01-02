<?php
require('ConnCentral.php'); // $mysqliCentral
require('ConnDrinks.php');  // $mysqliDrinks
require('Conexion.php');    // $mysqliWeb + $mysqli

session_start();

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) {
    header("Location: Login.php");
    exit;
}

date_default_timezone_set('America/Bogota');

Lista_Pedido();

/* =====================================================
   AUTORIZACIÓN
===================================================== */
function Autorizacion($UsuarioSesion, $Solicitud) {
    global $mysqli;

    $stmt = $mysqli->prepare("
        SELECT Swich 
        FROM autorizacion_tercero 
        WHERE CedulaNit=? AND Nro_Auto=?
    ");
    if(!$stmt) return 'NO';

    $stmt->bind_param("ss",$UsuarioSesion,$Solicitud);
    $stmt->execute();
    $res=$stmt->get_result();
    return ($row=$res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

/* =====================================================
   CONSULTA BASE
===================================================== */
function obtenerDatos($cnx,$nombreSucursal,$fecha){

$sql = "
SELECT 
    '$nombreSucursal' AS SUCURSAL,
    FACTURAS.HORA,
    T1.NOMBRES AS FACTURADOR,
    FACTURAS.NUMERO AS DOCUMENTO,
    PRODUCTOS.Barcode,
    PRODUCTOS.Descripcion AS PRODUCTO,
    DETFACTURAS.CANTIDAD,
    DETFACTURAS.VALORPROD
FROM FACTURAS
INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA
INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO
INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR
WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA=?

UNION ALL

SELECT 
    '$nombreSucursal' AS SUCURSAL,
    PEDIDOS.HORA,
    T2.NOMBRES AS FACTURADOR,
    PEDIDOS.NUMERO AS DOCUMENTO,
    PRODUCTOS.Barcode,
    PRODUCTOS.Descripcion AS PRODUCTO,
    DETPEDIDOS.CANTIDAD,
    DETPEDIDOS.VALORPROD
FROM PEDIDOS
INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO
INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO
INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO
INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO
WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA=?
";

$stmt=$cnx->prepare($sql);
$stmt->bind_param("ss",$fecha,$fecha);
$stmt->execute();
$res=$stmt->get_result();

$rows=[];
while($r=$res->fetch_assoc()){
    $rows[]=$r;
}
return $rows;
}

/* =====================================================
   LISTADO GENERAL
===================================================== */
function Lista_Pedido(){

global $mysqliCentral,$mysqliDrinks,$mysqliWeb,$UsuarioSesion;

$hoy=date('Ymd');

/* ====== CARGAR LAS DOS SUCURSALES ====== */
$rows = array_merge(
    obtenerDatos($mysqliCentral,'CENTRAL',$hoy),
    obtenerDatos($mysqliDrinks,'DRINKS',$hoy)
);

if(!$rows){
    echo "<h3>No hay información para hoy</h3>";
    return;
}

/* ====== CATEGORÍAS ====== */
$skus=array_unique(array_column($rows,'Barcode'));
$categoria=$unicaja=[];

if($skus){
    $lista="'".implode("','",$skus)."'";

    $q=$mysqliWeb->query("SELECT Sku,CodCat FROM catproductos WHERE Sku IN ($lista)");
    while($c=$q->fetch_assoc()) $categoria[$c['Sku']]=$c['CodCat'];

    $cats="'".implode("','",array_unique($categoria))."'";
    $q2=$mysqliWeb->query("SELECT CodCat,Unicaja FROM categorias WHERE CodCat IN ($cats)");
    while($u=$q2->fetch_assoc()) $uniCat[$u['CodCat']]=$u['Unicaja'];

    foreach($categoria as $s=>$c){
        $unicaja[$s]=$uniCat[$c] ?? 1;
    }
}

/* ====== FILTROS ====== */
$fSuc=$_GET['sucursal']??'';
$fFac=$_GET['facturador']??'';
$fSku=$_GET['producto']??'';
$fDoc=$_GET['documento']??'';

$suc=$fac=$prod=$doc=[];
foreach($rows as $r){
    $suc[$r['SUCURSAL']]=true;
    $fac[$r['FACTURADOR']]=true;
    $prod[$r['Barcode']]=$r['PRODUCTO'];
    $doc[$r['DOCUMENTO']]=true;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Ejecución diaria</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial;font-size:18px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ccc;padding:10px}
th{background:#eee;position:sticky;top:0}
.total{background:#c6e2ff;font-weight:bold}
.gran{background:#c6f6c6;font-weight:bold}
select{font-size:16px}
</style>
</head>
<body>

<h2>Ejecución del día – Central + Drinks</h2>

<form>
Sucursal:
<select name="sucursal" onchange="this.form.submit()">
<option value="">Todas</option>
<?php foreach($suc as $k=>$_){ ?>
<option <?=$k==$fSuc?'selected':''?>><?=$k?></option>
<?php } ?>
</select>

Facturador:
<select name="facturador" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($fac as $k=>$_){ ?>
<option <?=$k==$fFac?'selected':''?>><?=$k?></option>
<?php } ?>
</select>

Producto:
<select name="producto" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($prod as $k=>$v){ ?>
<option value="<?=$k?>" <?=$k==$fSku?'selected':''?>><?=$k?> - <?=$v?></option>
<?php } ?>
</select>

Documento:
<select name="documento" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($doc as $k=>$_){ ?>
<option <?=$k==$fDoc?'selected':''?>><?=$k?></option>
<?php } ?>
</select>
</form>

<table>
<tr>
<th>Sucursal</th><th>Facturador</th><th>Doc</th><th>Hora</th>
<th>Cat</th><th>Sku</th><th>Producto</th>
<th>Valor</th><th>Cajas</th><th>Und</th><th>Total</th>
</tr>

<?php
$gran=0;$sub=0;$docAnt='';
foreach($rows as $r){

    if($fSuc && $r['SUCURSAL']!=$fSuc) continue;
    if($fFac && $r['FACTURADOR']!=$fFac) continue;
    if($fSku && $r['Barcode']!=$fSku) continue;
    if($fDoc && $r['DOCUMENTO']!=$fDoc) continue;

    if($docAnt && $docAnt!=$r['DOCUMENTO']){
        echo "<tr class=total><td colspan=10>Subtotal $docAnt</td><td>".number_format($sub,0,'.','.')."</td></tr>";
        $sub=0;
    }

    $uni=$unicaja[$r['Barcode']]??1;
    $c=floor($r['CANTIDAD']);
    $u=round(($r['CANTIDAD']-$c)*$uni);
    $t=$r['CANTIDAD']*$r['VALORPROD'];

    echo "<tr>
        <td>{$r['SUCURSAL']}</td>
        <td>{$r['FACTURADOR']}</td>
        <td>{$r['DOCUMENTO']}</td>
        <td>{$r['HORA']}</td>
        <td>{$categoria[$r['Barcode']]}</td>
        <td>{$r['Barcode']}</td>
        <td>{$r['PRODUCTO']}</td>
        <td>".number_format($r['VALORPROD'],0,'.','.')."</td>
        <td>$c</td><td>$u</td>
        <td>".number_format($t,0,'.','.')."</td>
    </tr>";

    $gran+=$t;
    $sub+=$t;
    $docAnt=$r['DOCUMENTO'];
}

if($docAnt){
    echo "<tr class=total><td colspan=10>Subtotal $docAnt</td><td>".number_format($sub,0,'.','.')."</td></tr>";
}
?>
<tr class="gran">
<td colspan="10">GRAN TOTAL</td>
<td><?=number_format($gran,0,'.','.')?></td>
</tr>
</table>

</body>
</html>
<?php } ?>
