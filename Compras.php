<?php
session_start();

/* =========================
   CONEXIONES Y FUNCIONES BASE
========================= */
require('Conexion.php');
require('ConnCentral.php');
require('ConnDrinks.php');

$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') {
    header("Location: Login.php");
    exit;
}

function Autorizacion($User, $Solicitud) {
    global $mysqliWeb;
    $stmt = $mysqliWeb->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=? LIMIT 1");
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
    <title>Compras Gerenciales</title>
    <!-- Se mantienen tus estilos originales -->
    <style>
        body{ font-family:Segoe UI,Arial; margin:15px; background:#f4f6f8; font-size:16px; }
        .card{ background:#fff; padding:20px; border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.10) }
        .filters{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:15px }
        label{ font-size:14px; font-weight:700 }
        select,input,button{ width:100%; padding:10px 12px; border-radius:8px; border:1px solid #ccc; font-size:16px }
        button{ background:#0d6efd; color:#fff; font-weight:700; cursor:pointer }
        .table-container{ max-height:70vh; overflow:auto; border-radius:12px; border:1px solid #ddd }
        table{ border-collapse:collapse; width:100%; min-width:1200px; font-size:15px }
        th,td{ border:1px solid #ddd; padding:8px 10px; text-align:right; white-space:nowrap }
        .text-left{text-align:left}
        thead th{ position:sticky; top:0; z-index:10; background:#f1f3f5; font-weight:800; text-align:center }
        .badge{ padding:4px 10px; border-radius:14px; color:#fff; font-size:13px; font-weight:700 }
        .central{background:#0d6efd} .drinks{background:#198754}
        .subtotal{ background:#eef6ff; font-weight:800; }
        .total{ background:#e6fffa; font-weight:900; }
        .porc-pos{color:#1b5e20;font-weight:800} .porc-neg{color:#b71c1c;font-weight:800}
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales</h2>

    <form method="GET" class="filters">
        <div>
            <label>Fecha</label>
            <input type="date" name="Fecha" value="<?=htmlspecialchars($_GET['Fecha']??date('Y-m-d'))?>">
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
            <label>ID Compra (Global)</label>
            <input type="number" name="IDCompra" placeholder="Busca sin fecha..." value="<?=htmlspecialchars($_GET['IDCompra']??'')?>">
        </div>

        <div>
            <label>Proveedor</label>
            <select name="Proveedor">
                <option value="">Todos</option>
                <?php
                // Los proveedores se siguen cargando por fecha para el dropdown
                if (!empty($_GET['Fecha'])) {
                    $FechaSQL = DateTime::createFromFormat('Y-m-d', $_GET['Fecha'])->format('Ymd');
                    $SucursalSel = $_GET['Sucursal'] ?? 'AMBAS';
                    function provs($mysqli,$f){
                        return $mysqli->query("SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov FROM compras C JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO WHERE C.FECHA='$f' AND C.ESTADO='0' ORDER BY prov");
                    }
                    $pList=[];
                    if($SucursalSel!='DRINKS'){ $r=provs($mysqliCentral,$FechaSQL); while($r && $p=$r->fetch_assoc()) $pList[$p['NIT']]=$p['prov']; }
                    if($SucursalSel!='CENTRAL'){ $r=provs($mysqliDrinks,$FechaSQL); while($r && $p=$r->fetch_assoc()) $pList[$p['NIT']]=$p['prov']; }
                    foreach($pList as $n=>$nm){ $sel=($_GET['Proveedor']??'')==$n?'selected':''; echo "<option value='$n' $sel>$nm</option>"; }
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
$FechaGet = $_GET['Fecha'] ?? '';
$IDCompraGet = preg_replace('/[^0-9]/','',$_GET['IDCompra']??'');
$ProvGet = preg_replace('/[^0-9]/','',$_GET['Proveedor']??'');
$SucursalGet = $_GET['Sucursal'] ?? 'AMBAS';

// Ejecutar si hay fecha O si hay un ID de compra
if (!empty($FechaGet) || !empty($IDCompraGet)) {

    $FechaSQL = !empty($FechaGet) ? DateTime::createFromFormat('Y-m-d', $FechaGet)->format('Ymd') : '';

    /* --- PRECIO PROMEDIO VENTA (Últimos 15 días) --- */
    function precioProm($mysqli){
        $sql="SELECT Q.Barcode, SUM(Q.CANTIDAD*Q.VALORPROD)/NULLIF(SUM(Q.CANTIDAD),0) pv FROM(
              SELECT P.Barcode,D.CANTIDAD,D.VALORPROD FROM DETPEDIDOS D JOIN PEDIDOS PE ON PE.IDPEDIDO=D.IDPEDIDO JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE PE.ESTADO='0' AND STR_TO_DATE(PE.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
              UNION ALL
              SELECT P.Barcode,D.CANTIDAD,D.VALORPROD FROM FACTURAS F JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE F.ESTADO='0' AND STR_TO_DATE(F.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
            )Q GROUP BY Q.Barcode";
        $out=[]; $r=$mysqli->query($sql);
        while($r && $x=$r->fetch_assoc()) $out[$x['Barcode']]=$x['pv'];
        return $out;
    }

    $pvC = precioProm($mysqliCentral);
    $pvD = precioProm($mysqliDrinks);

    function consultarCompras($mysqli, $suc, $f, $p, $id){
        $cond = " WHERE C.ESTADO='0' ";
        
        // SI HAY ID DE COMPRA, IGNORAMOS LA FECHA
        if(!empty($id)){
            $cond .= " AND C.idcompra = '$id' ";
        } else {
            // SI NO HAY ID, LA FECHA ES OBLIGATORIA
            $cond .= " AND C.FECHA = '$f' ";
        }

        if(!empty($p)) $cond .= " AND T.NIT = '$p' ";

        return $mysqli->query("
            SELECT '$suc' sucursal, C.idcompra, CONCAT(T.nombres,' ',T.apellidos) prov,
                   P.Barcode, P.descripcion, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni
            FROM compras C
            JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
            JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
            JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
            $cond
            ORDER BY prov, C.idcompra
        ");
    }

    $resultados=[];
    if($SucursalGet != 'DRINKS') $resultados[] = consultarCompras($mysqliCentral, 'Central', $FechaSQL, $ProvGet, $IDCompraGet);
    if($SucursalGet != 'CENTRAL') $resultados[] = consultarCompras($mysqliDrinks, 'Drinks', $FechaSQL, $ProvGet, $IDCompraGet);

    echo "<div class='table-container'><table><thead><tr>
          <th>Suc</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>
          <th>Cant</th><th>Costo</th><th>Total</th>";
    if($PuedeVerUtil) echo "<th>P.Venta</th><th>Util</th><th>%</th>";
    echo "</tr></thead><tbody>";

    $provAnt=''; $sub=0; $gran=0;

    foreach($resultados as $res){
        while($res && $x=$res->fetch_assoc()){
            $cant=$x['CANTIDAD'];
            $net=($x['VALOR']-($x['descuento']/max($cant,1)));
            $costo=$net+($net*$x['porciva']/100)+$x['ValICUIUni'];
            $total=$costo*$cant;

            $pv=($x['sucursal']=='Central')?($pvC[$x['Barcode']]??0):($pvD[$x['Barcode']]??0);
            $util=($pv-$costo)*$cant;
            $porc=$costo>0?(($pv-$costo)/$costo)*100:0;

            if($provAnt && $provAnt!=$x['prov']){
                echo "<tr class='subtotal'><td colspan='7'>Subtotal $provAnt</td><td>".fmoneda($sub)."</td>";
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
                echo "<td>".fmoneda($pv)."</td><td>".fmoneda($util)."</td><td class='$clsP'>".number_format($porc,1)."%</td>";
            }
            echo "</tr>";
        }
    }

    if($gran > 0){
        echo "<tr class='subtotal'><td colspan='7'>Subtotal $provAnt</td><td>".fmoneda($sub)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        echo "<tr class='total'><td colspan='7'>TOTAL GENERAL</td><td>".fmoneda($gran)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
    } else {
        echo "<tr><td colspan='11' style='text-align:center;padding:20px;'>No se encontraron registros.</td></tr>";
    }
    echo "</tbody></table></div>";
}
?>
</div>
</body>
</html>