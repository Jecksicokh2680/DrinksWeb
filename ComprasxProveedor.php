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
    $stmt = $mysqliWeb->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=? LIMIT 1");
    $stmt->bind_param("ss",$User,$Solicitud);
    $stmt->execute();
    $r = $stmt->get_result();
    return ($r && $r->num_rows)?$r->fetch_assoc()['Swich']:"NO";
}

$PuedeVerUtil = (Autorizacion($User,'9999')==='SI');
function fmoneda($v){ return number_format($v,0,',','.'); }

/* =========================
   VARIABLES DE FILTRO
========================= */
$MesSel = $_GET['Mes'] ?? date('m');
$AnioSel = $_GET['Anio'] ?? date('Y');
$Sucursal = $_GET['Sucursal'] ?? 'AMBAS';
$ProveedorSel = $_GET['Proveedor'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Compras Gerenciales - Filtro Dinámico</title>
    <style>
        body{ font-family:Segoe UI,Arial; margin:15px; background:#f4f6f8; font-size:14px; }
        .card{ background:#fff; padding:20px; border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.10) }
        .filters{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:15px }
        label{ font-size:12px; font-weight:700; color:#555; }
        select,button,input{ width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; font-size:14px; box-sizing: border-box; }
        button{ background:#0d6efd; color:#fff; font-weight:700; cursor:pointer }
        
        /* Estilo para el buscador dinámico */
        .search-container { margin-bottom: 15px; background: #e9ecef; padding: 15px; border-radius: 8px; border-left: 5px solid #0d6efd; }
        .search-input { border: 2px solid #0d6efd !important; font-weight: bold; }

        .table-container{ max-height:65vh; overflow:auto; border-radius:12px; border:1px solid #ddd }
        table{ border-collapse:collapse; width:100%; min-width:1100px; }
        th,td{ border:1px solid #ddd; padding:8px; text-align:right; }
        thead th{ position:sticky; top:0; background:#f1f3f5; font-weight:800; z-index: 20; }
        .badge{ padding:4px 8px; border-radius:10px; color:#fff; font-size:11px; }
        .central{background:#0d6efd} .drinks{background:#198754}
        .subtotal{ background:#f8f9fa; font-weight:800; }
        .total{ background:#e6fffa; font-weight:900; font-size:16px }
        .text-left{ text-align:left }
        .no-result { display: none; text-align: center; padding: 20px; font-weight: bold; color: red; }
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales por Mes</h2>

    <form method="GET" class="filters">
        <div>
            <label>Año</label>
            <select name="Anio">
                <?php for($i=date('Y'); $i>=2023; $i--) echo "<option value='$i' ".($AnioSel==$i?'selected':'').">$i</option>"; ?>
            </select>
        </div>
        <div>
            <label>Mes</label>
            <select name="Mes">
                <?php 
                $meses = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
                foreach($meses as $num=>$nom) echo "<option value='$num' ".($MesSel==$num?'selected':'').">$nom</option>";
                ?>
            </select>
        </div>
        <div>
            <label>Sucursal</label>
            <select name="Sucursal">
                <option value="AMBAS" <?=$Sucursal=='AMBAS'?'selected':''?>>Ambas</option>
                <option value="CENTRAL" <?=$Sucursal=='CENTRAL'?'selected':''?>>Central</option>
                <option value="DRINKS" <?=$Sucursal=='DRINKS'?'selected':''?>>Drinks</option>
            </select>
        </div>
        <div>
            <label>Proveedor</label>
            <select name="Proveedor">
                <option value="">-- Todos los Proveedores --</option>
                <?php
                function listarProv($mysqli, $m, $a){
                    return $mysqli->query("SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) as prov 
                                           FROM compras C JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO 
                                           WHERE MONTH(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$m' 
                                           AND YEAR(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$a' AND C.ESTADO='0'");
                }
                $provList=[];
                if($Sucursal!='DRINKS'){ $r=listarProv($mysqliCentral, $MesSel, $AnioSel); while($r && $p=$r->fetch_assoc()) $provList[$p['NIT']]=$p['prov']; }
                if($Sucursal!='CENTRAL'){ $r=listarProv($mysqliDrinks, $MesSel, $AnioSel); while($r && $p=$r->fetch_assoc()) $provList[$p['NIT']]=$p['prov']; }
                asort($provList);
                foreach($provList as $nit=>$nom) echo "<option value='$nit' ".($ProveedorSel==$nit?'selected':'').">$nom</option>";
                ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Cargar Datos</button>
        </div>
    </form>

    <hr>

    <div class="search-container">
        <label>🔍 Filtro Rápido (Nombre o Sku):</label>
        <input type="text" id="inputBusqueda" class="search-input" placeholder="Escribe el nombre del producto para filtrar la lista actual..." onkeyup="filtrarProductos()">
    </div>

    <?php
    if ($MesSel) {
        function precioProm($mysqli){
            $sql="SELECT Q.Barcode, SUM(Q.CANTIDAD*Q.VALORPROD)/NULLIF(SUM(Q.CANTIDAD),0) pv
                  FROM(SELECT P.Barcode,D.CANTIDAD,D.VALORPROD FROM DETPEDIDOS D JOIN PEDIDOS PE ON PE.IDPEDIDO=D.IDPEDIDO JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE PE.ESTADO='0' AND STR_TO_DATE(PE.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
                  UNION ALL SELECT P.Barcode,D.CANTIDAD,D.VALORPROD FROM FACTURAS F JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE F.ESTADO='0' AND STR_TO_DATE(F.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)) Q GROUP BY Q.Barcode";
            $out=[]; $r=$mysqli->query($sql);
            while($r && $x=$r->fetch_assoc()) $out[$x['Barcode']]=$x['pv'];
            return $out;
        }

        $pvC=precioProm($mysqliCentral);
        $pvD=precioProm($mysqliDrinks);

        function comprasMes($mysqli, $suc, $m, $a, $nitProv){
            $cond = $nitProv ? " AND T.NIT='$nitProv' " : "";
            return $mysqli->query("
                SELECT '$suc' sucursal, C.FECHA, C.idcompra, CONCAT(T.nombres,' ',T.apellidos) prov,
                       P.Barcode, P.descripcion, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni
                FROM compras C
                JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
                JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
                JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
                WHERE MONTH(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$m' 
                  AND YEAR(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$a'
                  AND C.ESTADO='0' $cond
                ORDER BY prov, C.FECHA ASC
            ");
        }

        $resultados = [];
        if($Sucursal!='DRINKS') { $res = comprasMes($mysqliCentral, 'Central', $MesSel, $AnioSel, $ProveedorSel); while($row = $res->fetch_assoc()) $resultados[] = $row; }
        if($Sucursal!='CENTRAL') { $res = comprasMes($mysqliDrinks, 'Drinks', $MesSel, $AnioSel, $ProveedorSel); while($row = $res->fetch_assoc()) $resultados[] = $row; }

        usort($resultados, function($a, $b) { return strcmp($a['prov'], $b['prov']); });

        echo "<div class='table-container'>
                <table id='tablaCompras'>
                <thead><tr>
                  <th>Suc</th><th>Fecha</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>
                  <th>Cant</th><th>Costo</th><th>Total</th>";
        if($PuedeVerUtil) echo "<th>P.Venta</th><th>Util</th>";
        echo "</tr></thead><tbody>";

        $provAnt=''; $subTotal=0; $granTotal=0;

        foreach($resultados as $x){
            $cant=$x['CANTIDAD'];
            $net=($x['VALOR']-($x['descuento']/max($cant,1)));
            $costo=$net+($net*$x['porciva']/100)+$x['ValICUIUni'];
            $total=$costo*$cant;
            $pv = ($x['sucursal']=='Central') ? ($pvC[$x['Barcode']]??0) : ($pvD[$x['Barcode']]??0);
            $util = ($pv - $costo) * $cant;

            if($provAnt != '' && $provAnt != $x['prov']){
                echo "<tr class='subtotal prov-row'><td colspan='8'>Subtotal $provAnt</td><td>".fmoneda($subTotal)."</td>".($PuedeVerUtil?"<td colspan='2'></td>":"")."</tr>";
                $subTotal = 0;
            }

            $subTotal += $total;
            $granTotal += $total;
            $provAnt = $x['prov'];

            $cls = $x['sucursal']=='Central' ? 'central' : 'drinks';
            // Agregamos una clase 'item-row' para identificarlas en JS
            echo "<tr class='item-row'>
                    <td><span class='badge $cls'>{$x['sucursal']}</span></td>
                    <td>{$x['FECHA']}</td>
                    <td>{$x['idcompra']}</td>
                    <td class='text-left'>{$x['prov']}</td>
                    <td class='text-left barcode'>{$x['Barcode']}</td>
                    <td class='text-left nombre-prod'>{$x['descripcion']}</td>
                    <td>".number_format($cant,0)."</td>
                    <td>".fmoneda($costo)."</td>
                    <td>".fmoneda($total)."</td>";
            if($PuedeVerUtil) echo "<td>".fmoneda($pv)."</td><td>".fmoneda($util)."</td>";
            echo "</tr>";
        }

        if($provAnt != ''){
            echo "<tr class='subtotal prov-row'><td colspan='8'>Subtotal $provAnt</td><td>".fmoneda($subTotal)."</td>".($PuedeVerUtil?"<td colspan='2'></td>":"")."</tr>";
        }
        echo "<tr class='total' id='rowTotal'><td colspan='8'>TOTAL GENERAL DEL MES</td><td>".fmoneda($granTotal)."</td>".($PuedeVerUtil?"<td colspan='2'></td>":"")."</tr>";
        echo "</tbody></table></div>";
    }
    ?>
</div>

<script>
function filtrarProductos() {
    let input = document.getElementById("inputBusqueda").value.toLowerCase();
    let filas = document.getElementsByClassName("item-row");
    let subtotales = document.getElementsByClassName("prov-row");
    let totalGeneral = document.getElementById("rowTotal");

    // Si hay búsqueda, ocultamos totales y subtotales porque pierden sentido matemático al filtrar solo unos ítems
    if (input.length > 0) {
        for (let s of subtotales) s.style.display = "none";
        if(totalGeneral) totalGeneral.style.display = "none";
    } else {
        for (let s of subtotales) s.style.display = "";
        if(totalGeneral) totalGeneral.style.display = "";
    }

    // Filtrar filas de productos
    for (let i = 0; i < filas.length; i++) {
        let nombre = filas[i].getElementsByClassName("nombre-prod")[0].innerText.toLowerCase();
        let barcode = filas[i].getElementsByClassName("barcode")[0].innerText.toLowerCase();
        
        if (nombre.includes(input) || barcode.includes(input)) {
            filas[i].style.display = "";
        } else {
            filas[i].style.display = "none";
        }
    }
}
</script>

</body>
</html>