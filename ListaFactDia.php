<?php
require('ConnCentral.php'); // POS → $mysqliCentral
require('Conexion.php');    // WEB → $mysqliWeb y $mysqli

session_start();

$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

date_default_timezone_set('America/Bogota');

if (empty($UsuarioSesion)) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

Lista_Pedido();

/* =====================================================
   AUTORIZACIÓN
===================================================== */
function Autorizacion($UsuarioSesion, $Solicitud) {
    global $mysqli;

    if (!isset($_SESSION['Autorizaciones'])) {
        $_SESSION['Autorizaciones'] = [];
    }

    $key = $UsuarioSesion . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) {
        return $_SESSION['Autorizaciones'][$key];
    }

    $stmt = $mysqli->prepare(
        "SELECT Swich 
         FROM autorizacion_tercero 
         WHERE CedulaNit = ? AND Nro_Auto = ?"
    );
    if (!$stmt) return "NO";

    $stmt->bind_param("ss", $UsuarioSesion, $Solicitud);
    $stmt->execute();

    $res = $stmt->get_result();
    $permiso = ($row = $res->fetch_assoc()) ? ($row['Swich'] ?? 'NO') : 'NO';

    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();

    return $permiso;
}

/* =====================================================
   LISTADO FACTURAS / PEDIDOS
===================================================== */
function Lista_Pedido() {

    global $mysqliCentral, $mysqliWeb;
    global $UsuarioSesion;

    $hoy = date('Ymd');

    $sql = "
        SELECT 
            TRIM(NOMBREPC) AS NOMBREPC,
            FACTURAS.FECHA,
            FACTURAS.HORA,
            T1.NIT AS FACTURADOR_NIT,
            T1.NOMBRES AS FACTURADOR,
            T2.NOMBRES AS CLIENTE,
            FACTURAS.NUMERO AS DOCUMENTO,
            PRODUCTOS.Barcode,
            PRODUCTOS.Descripcion AS PRODUCTO,
            DETFACTURAS.CANTIDAD,
            DETFACTURAS.VALORPROD,
            FACTURAS.VALORTOTAL,
            'FACTURA' AS TIPO_DOC
        FROM FACTURAS
        INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA = FACTURAS.IDFACTURA
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETFACTURAS.IDPRODUCTO
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = FACTURAS.IDVENDEDOR
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = FACTURAS.IDTERCERO
        WHERE FACTURAS.IDFACTURA NOT IN (SELECT IDFACTURA FROM DEVVENTAS)
          AND FACTURAS.ESTADO = '0'
          AND FACTURAS.FECHA = ?

        UNION ALL

        SELECT 
            '' AS NOMBREPC,
            PEDIDOS.FECHA,
            PEDIDOS.HORA,
            T2.NIT AS FACTURADOR_NIT,
            T2.NOMBRES AS FACTURADOR,
            T1.NOMBRES AS CLIENTE,
            PEDIDOS.NUMERO AS DOCUMENTO,
            PRODUCTOS.Barcode,
            PRODUCTOS.Descripcion AS PRODUCTO,
            DETPEDIDOS.CANTIDAD,
            DETPEDIDOS.VALORPROD,
            PEDIDOS.VALORTOTAL,
            'PEDIDO' AS TIPO_DOC
        FROM PEDIDOS
        INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO = DETPEDIDOS.IDPEDIDO
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETPEDIDOS.IDPRODUCTO
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO = PEDIDOS.IDUSUARIO
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V1.IDTERCERO
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = PEDIDOS.IDVENDEDOR
        WHERE PEDIDOS.ESTADO = '0'
          AND PEDIDOS.FECHA = ?
    ";

    $stmt = $mysqliCentral->prepare($sql);
    if (!$stmt) die("Error SQL: ".$mysqliCentral->error);

    $stmt->bind_param("ss", $hoy, $hoy);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows == 0) {
        echo "<h3>No existen facturas o pedidos para hoy.</h3>";
        return;
    }

    $rows = [];
    $barcodes = [];

    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
        $barcodes[] = $r['Barcode'];
    }

    $barcodes = array_values(array_unique($barcodes));
    $categoriaCache = [];
    $unicajaCacheBySku = [];

    /* =======================
       CATEGORÍAS / UNICAJA
    ======================= */
    if ($barcodes) {
        $in = implode(',', array_fill(0, count($barcodes), '?'));

        $stmtCat = $mysqliWeb->prepare(
            "SELECT Sku, CodCat FROM catproductos WHERE Sku IN ($in)"
        );
        $stmtCat->execute($barcodes);
        $resCat = $stmtCat->get_result();

        $skuCat = [];
        while ($c = $resCat->fetch_assoc()) {
            $skuCat[$c['Sku']] = $c['CodCat'];
        }
        $stmtCat->close();

        if ($skuCat) {
            $codCats = array_values(array_unique(array_values($skuCat)));
            $in2 = implode(',', array_fill(0, count($codCats), '?'));

            $stmtUni = $mysqliWeb->prepare(
                "SELECT CodCat, Unicaja FROM categorias WHERE CodCat IN ($in2)"
            );
            $stmtUni->execute($codCats);
            $resUni = $stmtUni->get_result();

            $uniCat = [];
            while ($u = $resUni->fetch_assoc()) {
                $uniCat[$u['CodCat']] = $u['Unicaja'];
            }
            $stmtUni->close();

            foreach ($barcodes as $sku) {
                $cat = $skuCat[$sku] ?? '';
                $categoriaCache[$sku] = $cat;
                $unicajaCacheBySku[$sku] = $uniCat[$cat] ?? 1;
            }
        }
    }

    /* =======================
       FILTROS
    ======================= */
    $filtroFacturador = $_GET['facturador'] ?? '';
    $filtroProducto   = $_GET['producto'] ?? ''; // SKU
    $filtroDocumento  = $_GET['documento'] ?? '';

    $facturadoresList = [];
    $productosList = [];
    $documentosList = [];

    foreach ($rows as $r) {
        $facturadoresList[$r['FACTURADOR']] = true;
        $documentosList[$r['DOCUMENTO']] = true;

        $sku = $r['Barcode'];
        $productosList[$sku] = [
            'producto' => $r['PRODUCTO'],
            'sku' => $sku,
            'cat' => $categoriaCache[$sku] ?? ''
        ];
    }

    usort($productosList, function($a,$b){
        return [$a['cat'],$a['sku']] <=> [$b['cat'],$b['sku']];
    });
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ejecución del día</title>
<style>
body{font-family:Arial;font-size:15px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ccc;padding:6px}
th{background:#eee;position:sticky;top:0}
.center{text-align:center}
.total-facturador{background:#c6e2ff;font-weight:bold}
.gran-total{background:#c6f6c6;font-weight:bold}
</style>
</head>
<body>

<h2>Ejecución del día</h2>

<form method="get">
Facturador:
<select name="facturador" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($facturadoresList as $f=>$_){ ?>
<option value="<?=$f?>" <?=($f==$filtroFacturador?'selected':'')?>>
    <?=$f?>
</option>
<?php } ?>
</select>

Producto:
<select name="producto" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($productosList as $p){ ?>
<option value="<?=$p['sku']?>" <?=($p['sku']==$filtroProducto?'selected':'')?>>
    [<?=$p['cat']?>] <?=$p['sku']?> - <?=$p['producto']?>
</option>
<?php } ?>
</select>

Documento:
<select name="documento" onchange="this.form.submit()">
<option value="">Todos</option>
<?php foreach($documentosList as $d=>$_){ ?>
<option value="<?=$d?>" <?=($d==$filtroDocumento?'selected':'')?>>
    <?=$d?>
</option>
<?php } ?>
</select>
</form>

<table>
<tr>
<th>Facturador</th><th>Documento</th><th>Hora</th>
<th>Cat</th><th>Sku</th><th>Producto</th>
<th>Valor</th><th>Cajas</th><th>Unid</th><th>Subtotal</th>
</tr>

<?php
$granTotal=0;
$totFact=[];
$docAnt='';
$subDoc=0;

foreach($rows as $r){

    if($filtroFacturador && $r['FACTURADOR']!=$filtroFacturador) continue;
    if($filtroProducto && $r['Barcode']!=$filtroProducto) continue;
    if($filtroDocumento && $r['DOCUMENTO']!=$filtroDocumento) continue;

    if($docAnt && $docAnt!=$r['DOCUMENTO']){
        echo "<tr><td colspan=9><b>Subtotal $docAnt</b></td><td><b>".number_format($subDoc,0,'.','.')."</b></td></tr>";
        $subDoc=0;
    }

    $uni = $unicajaCacheBySku[$r['Barcode']] ?? 1;
    $cajas=floor($r['CANTIDAD']);
    $unid=round(($r['CANTIDAD']-$cajas)*$uni);
    $total=$r['CANTIDAD']*$r['VALORPROD'];

    echo "<tr>
        <td>{$r['FACTURADOR']}</td>
        <td>{$r['DOCUMENTO']}</td>
        <td>{$r['HORA']}</td>
        <td>{$categoriaCache[$r['Barcode']]}</td>
        <td>{$r['Barcode']}</td>
        <td>{$r['PRODUCTO']}</td>
        <td>".number_format($r['VALORPROD'],0,'.','.')."</td>
        <td class=center>$cajas</td>
        <td class=center>$unid</td>
        <td>".number_format($total,0,'.','.')."</td>
    </tr>";

    $granTotal+=$total;
    $totFact[$r['FACTURADOR']] = ($totFact[$r['FACTURADOR']] ?? 0) + $total;
    $subDoc+=$total;
    $docAnt=$r['DOCUMENTO'];
}

if($docAnt){
    echo "<tr><td colspan=9><b>Subtotal $docAnt</b></td><td><b>".number_format($subDoc,0,'.','.')."</b></td></tr>";
}

if(Autorizacion($UsuarioSesion,'9999')=='SI'){
    foreach($totFact as $f=>$t){
        echo "<tr class=total-facturador><td colspan=9>TOTAL $f</td><td>".number_format($t,0,'.','.')."</td></tr>";
    }
}
?>
<tr class="gran-total">
<td colspan="9">GRAN TOTAL</td>
<td><?=number_format($granTotal,0,'.','.')?></td>
</tr>
</table>

</body>
</html>
<?php } ?>
