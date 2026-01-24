<?php
session_start();

/* =========================
   CONEXIONES
========================= */
require('Conexion.php');        
require('ConnCentral.php');     
require('ConnDrinks.php');      

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
    <title>Compras Gerenciales - Reporte Mensual</title>
    <style>
        body{ font-family:Segoe UI,Arial; margin:15px; background:#f4f6f8; font-size:14px; }
        .card{ background:#fff; padding:20px; border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.10) }
        h2 { font-size: 28px; margin-bottom: 20px; color: #2c3e50; }
        
        .filters{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:15px }
        label{ font-size:12px; font-weight:700; color:#555; }
        select,button,input{ width:100%; padding:12px; border-radius:8px; border:1px solid #ccc; font-size:15px; box-sizing: border-box; }
        button{ background:#0d6efd; color:#fff; font-weight:700; cursor:pointer; transition: 0.3s; }
        button:hover{ background:#0a58ca; }
        
        .search-container { margin-bottom: 15px; background: #eef2f7; padding: 20px; border-radius: 10px; border-left: 6px solid #0d6efd; }
        .search-input { border: 2px solid #0d6efd !important; font-size: 18px !important; font-weight: bold; }

        .table-container{ max-height:65vh; overflow:auto; border-radius:12px; border:1px solid #ddd; background:#fff; }
        table{ border-collapse:collapse; width:100%; min-width:1300px; }
        
        /* TAMAÑO DE TEXTOS Y NÚMEROS EN LA TABLA */
        th,td{ border:1px solid #ddd; padding:12px 10px; text-align:right; white-space:nowrap; font-size: 15px; }
        
        /* Nombres de Productos resaltados */
        .nombre-prod { 
            font-size: 17px !important; 
            font-weight: 600; 
            color: #1a1a1a; 
            white-space: normal !important; /* Permite que el nombre use más de una línea si es necesario */
            min-width: 300px;
        }

        .monto-grande { font-size: 17px; font-weight: 700; color: #2c3e50; }
        
        thead th{ position:sticky; top:0; background:#f8f9fa; font-weight:800; z-index: 20; font-size: 14px; color: #444; }
        .badge{ padding:5px 10px; border-radius:12px; color:#fff; font-size:12px; font-weight: bold; }
        .central{background:#0d6efd} .drinks{background:#198754}
        
        /* ESTILOS DE FILAS ESPECIALES */
        .subtotal{ background:#f1f8ff; font-weight:800; }
        .subtotal td { font-size: 18px; color: #0d6efd; }
        
        .total{ background:#d1fae5; font-weight:900; }
        .total td { font-size: 22px; color: #065f46; padding: 20px 10px; }
        
        .text-left{ text-align:left }
        .porc-pos{color:#1b5e20; font-weight:800; font-size: 16px; }
        .porc-neg{color:#b71c1c; font-weight:800; font-size: 16px; }
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales por Mes</h2>

    <form method="GET" class="filters">
        <div><label>Año</label>
            <select name="Anio">
                <?php for($i=date('Y'); $i>=2023; $i--) echo "<option value='$i' ".($AnioSel==$i?'selected':'').">$i</option>"; ?>
            </select>
        </div>
        <div><label>Mes</label>
            <select name="Mes">
                <?php 
                $meses = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
                foreach($meses as $num=>$nom) echo "<option value='$num' ".($MesSel==$num?'selected':'').">$nom</option>";
                ?>
            </select>
        </div>
        <div><label>Sucursal</label>
            <select name="Sucursal">
                <option value="AMBAS" <?=$Sucursal=='AMBAS'?'selected':''?>>Ambas</option>
                <option value="CENTRAL" <?=$Sucursal=='CENTRAL'?'selected':''?>>Central</option>
                <option value="DRINKS" <?=$Sucursal=='DRINKS'?'selected':''?>>Drinks</option>
            </select>
        </div>
        <div><label>Proveedor</label>
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
        <div><label>&nbsp;</label><button type="submit">Cargar Reporte</button></div>
    </form>

    <div class="search-container">
        <label>🔍 Filtro Dinámico de Productos (Nombre o Sku):</label>
        <input type="text" id="inputBusqueda" class="search-input" placeholder="Comience a escribir el nombre del producto..." onkeyup="filtrarProductos()">
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

        $pvC=precioProm($mysqliCentral); $pvD=precioProm($mysqliDrinks);

        function comprasMes($mysqli, $suc, $m, $a, $nitProv){
            $cond = $nitProv ? " AND T.NIT='$nitProv' " : "";
            return $mysqli->query("
                SELECT '$suc' sucursal, C.FECHA, C.idcompra, CONCAT(T.nombres,' ',T.apellidos) prov,
                       P.Barcode, P.descripcion, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni
                FROM compras C
                JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
                JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
                JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
                WHERE MONTH(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$m' AND YEAR(STR_TO_DATE(C.FECHA,'%Y%m%d'))='$a' AND C.ESTADO='0' $cond
                ORDER BY prov, C.FECHA ASC");
        }

        $resultados = [];
        if($Sucursal!='DRINKS') { $res=comprasMes($mysqliCentral,'Central',$MesSel,$AnioSel,$ProveedorSel); while($row=$res->fetch_assoc()) $resultados[]=$row; }
        if($Sucursal!='CENTRAL') { $res=comprasMes($mysqliDrinks,'Drinks',$MesSel,$AnioSel,$ProveedorSel); while($row=$res->fetch_assoc()) $resultados[]=$row; }

        usort($resultados, function($a, $b) { return strcmp($a['prov'], $b['prov']); });

        echo "<div class='table-container'><table id='tablaCompras'><thead><tr>
              <th>Suc</th><th>Fecha</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>
              <th>Cant</th><th>Costo</th><th>Total Compra</th>";
        if($PuedeVerUtil) echo "<th>P.Venta</th><th>Utilidad</th><th>%</th>";
        echo "</tr></thead><tbody>";

        $provAnt=''; $subTotal=0; $granTotal=0;

        foreach($resultados as $x){
            $cant=$x['CANTIDAD'];
            $net=($x['VALOR']-($x['descuento']/max($cant,1)));
            $costo=$net+($net*$x['porciva']/100)+$x['ValICUIUni'];
            $total=$costo*$cant;
            $pv = ($x['sucursal']=='Central') ? ($pvC[$x['Barcode']]??0) : ($pvD[$x['Barcode']]??0);
            $util = ($pv - $costo) * $cant;
            $porc = $costo > 0 ? (($pv - $costo) / $costo) * 100 : 0;

            if($provAnt != '' && $provAnt != $x['prov']){
                echo "<tr class='subtotal prov-row'><td colspan='8'>SUBTOTAL: $provAnt</td><td class='monto-grande'>".fmoneda($subTotal)."</td>".($PuedeVerUtil?"<td colspan='3'></td>":"")."</tr>";
                $subTotal = 0;
            }

            $subTotal += $total; $granTotal += $total; $provAnt = $x['prov'];
            $cls = $x['sucursal']=='Central' ? 'central' : 'drinks';
            $clsP = $porc >= 0 ? 'porc-pos' : 'porc-neg';

            echo "<tr class='item-row'>
                    <td><span class='badge $cls'>{$x['sucursal']}</span></td>
                    <td>{$x['FECHA']}</td>
                    <td>{$x['idcompra']}</td>
                    <td class='text-left'>{$x['prov']}</td>
                    <td class='text-left barcode'>{$x['Barcode']}</td>
                    <td class='text-left nombre-prod'>{$x['descripcion']}</td>
                    <td class='monto-grande'>".number_format($cant,0)."</td>
                    <td class='monto-grande'>".fmoneda($costo)."</td>
                    <td class='monto-grande' style='color:#0d6efd;'>".fmoneda($total)."</td>";
            if($PuedeVerUtil) echo "<td class='monto-grande'>".fmoneda($pv)."</td><td class='monto-grande'>".fmoneda($util)."</td><td class='$clsP'>".number_format($porc,1)."%</td>";
            echo "</tr>";
        }

        if($provAnt != '') echo "<tr class='subtotal prov-row'><td colspan='8'>SUBTOTAL: $provAnt</td><td class='monto-grande'>".fmoneda($subTotal)."</td>".($PuedeVerUtil?"<td colspan='3'></td>":"")."</tr>";
        echo "<tr class='total' id='rowTotal'><td colspan='8'>TOTAL CONSOLIDADO MES</td><td class='monto-grande'>".fmoneda($granTotal)."</td>".($PuedeVerUtil?"<td colspan='3'></td>":"")."</tr>";
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

    if (input.length > 0) {
        for (let s of subtotales) s.style.display = "none";
        if(totalGeneral) totalGeneral.style.display = "none";
    } else {
        for (let s of subtotales) s.style.display = "";
        if(totalGeneral) totalGeneral.style.display = "";
    }

    for (let i = 0; i < filas.length; i++) {
        let nombre = filas[i].querySelector(".nombre-prod").innerText.toLowerCase();
        let barcode = filas[i].querySelector(".barcode").innerText.toLowerCase();
        filas[i].style.display = (nombre.includes(input) || barcode.includes(input)) ? "" : "none";
    }
}
</script>
</body>
</html>