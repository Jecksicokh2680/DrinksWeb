<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php");
require("ConnDrinks.php");
require("Conexion.php");

$dbCentral = $mysqliCentral ?? $mysqli;
$dbDrinks  = $mysqliPos;
$dbWeb     = $mysqliWeb;

/* =====================================================
   MAPEO SKU → CATEGORÍA + EMPRESA
===================================================== */
function cargarCategorias($dbWeb){
    $map = [];

    $sql = "
        SELECT cp.sku, c.nombre AS categoria, e.Nombre AS empresa
        FROM catproductos cp
        INNER JOIN categorias c ON c.codcat = cp.codcat
        LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa
    ";
    $res = $dbWeb->query($sql);

    while($res && $r = $res->fetch_assoc()){
        $map[trim($r['sku'])] = [
            'categoria' => trim($r['categoria']),
            'empresa'   => trim($r['empresa'] ?? 'SIN EMPRESA')
        ];
    }
    return $map;
}

/* =====================================================
   PRODUCTOS VENDIDOS HOY
===================================================== */
function obtenerProductosHoy($db){
    $hoy = date('Ymd');
    $out = [];

    // FACTURAS
    $sql = "
        SELECT PR.barcode, PR.DESCRIPCION producto,
               round(SUM(D.CANTIDAD),1) cant,
               round(SUM(D.CANTIDAD * D.VALORPROD),1) total
        FROM FACTURAS F
        INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
        INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO='0'
          AND DV.IDFACTURA IS NULL
          AND F.FECHA = '$hoy'
        GROUP BY PR.barcode, PR.DESCRIPCION
    ";
    $r = $db->query($sql);
    while($r && $row = $r->fetch_assoc()){
        $out[$row['barcode']] = $row;
    }

    // PEDIDOS
    $sql = "
        SELECT PR.barcode, PR.DESCRIPCION producto,
               round(SUM(D.CANTIDAD),1) cant,
               round(SUM(D.CANTIDAD * D.VALORPROD),1) total
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
        INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
        WHERE P.ESTADO='0'
          AND P.FECHA = '$hoy'
        GROUP BY PR.barcode, PR.DESCRIPCION
    ";
    $r = $db->query($sql);
    while($r && $row = $r->fetch_assoc()){
        if(isset($out[$row['barcode']])){
            $out[$row['barcode']]['cant']  += $row['cant'];
            $out[$row['barcode']]['total'] += $row['total'];
        } else {
            $out[$row['barcode']] = $row;
        }
    }

    return array_values($out);
}

/* =====================================================
   TOP 18 POR TOTAL $
===================================================== */
function top18($arr){
    usort($arr, fn($a,$b)=>$b['total'] <=> $a['total']);
    return array_slice($arr, 0, 20);
}

function money($v){
    return number_format($v/1000, 0, ',', '.');
}

/* =====================================================
   DATOS
===================================================== */
$categorias = cargarCategorias($dbWeb);

$topCentral = top18(obtenerProductosHoy($dbCentral));
$topDrinks  = top18(obtenerProductosHoy($dbDrinks));
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Top 18 Productos</title>
<style>
body{font-family:Segoe UI;background:#f2f4f8;font-size:11px;padding:15px}
.box{background:#fff;padding:15px;border-radius:8px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ddd;padding:6px}
th{background:#2c3e50;color:#fff}
td{text-align:right}
td:nth-child(1),td:nth-child(2),td:nth-child(3){text-align:left}
h2,h3{margin:6px 0}
.inactivo{color:red;font-weight:bold;}
</style>
</head>

<body>

<div class="box">
<h2>🏆 TOP 18 PRODUCTOS — <?=date('d/m/Y')?></h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- CENTRAL -->
<div>
<h3>🏢 Central</h3>
<?php 
$totalCantCentral = array_sum(array_column($topCentral,'cant'));
$totalMontoCentral = array_sum(array_column($topCentral,'total'));
?>
<p><strong>Total Cantidad:</strong> <?=$totalCantCentral?> &nbsp; | &nbsp; <strong>Total $:</strong> $ <?=money($totalMontoCentral)?></p>

<table>
<tr>
<th>Empresa</th>
<th>Categoría</th>
<th>Producto</th>
<th>Cantidad</th>
<th>Total</th>
</tr>
<?php foreach($topCentral as $p): 
    $cat = $categorias[$p['barcode']] ?? ['categoria'=>'SIN CATEGORÍA','empresa'=>'SIN EMPRESA'];
?>
<tr>
<td><?=$cat['empresa']?></td>
<td><?=$cat['categoria']?></td>
<td><?=$p['producto']?></td>
<td><?=$p['cant']?></td>
<td>$ <?=money($p['total'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<!-- DRINKS -->
<div>
<h3>🍹 Drinks</h3>
<?php 
$totalCantDrinks = array_sum(array_column($topDrinks,'cant'));
$totalMontoDrinks = array_sum(array_column($topDrinks,'total'));
?>
<p><strong>Total Cantidad:</strong> <?=$totalCantDrinks?> &nbsp; | &nbsp; <strong>Total $:</strong> $ <?=money($totalMontoDrinks)?></p>

<table>
<tr>
<th>Empresa</th>
<th>Categoría</th>
<th>Producto</th>
<th>Cantidad</th>
<th>Total</th>
</tr>
<?php foreach($topDrinks as $p): 
    $cat = $categorias[$p['barcode']] ?? ['categoria'=>'SIN CATEGORÍA','empresa'=>'SIN EMPRESA'];
?>
<tr>
<td><?=$cat['empresa']?></td>
<td><?=$cat['categoria']?></td>
<td><?=$p['producto']?></td>
<td><?=$p['cant']?></td>
<td>$ <?=money($p['total'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>
</div>

</body>
</html>
