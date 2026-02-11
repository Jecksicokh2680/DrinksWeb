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
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    if(!$stmt) return 'NO';
    $stmt->bind_param("ss",$UsuarioSesion,$Solicitud);
    $stmt->execute();
    $res=$stmt->get_result();
    return ($row=$res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

/* =====================================================
    CONSULTA BASE (RANGO FECHAS + FILTRO PRODUCTO)
===================================================== */
function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd){
    
    $extraCond = "";
    if($busqProd != ""){
        $busqProdEsc = $cnx->real_escape_string($busqProd);
        $extraCond = " AND (PRODUCTOS.Descripcion LIKE '%$busqProdEsc%' OR PRODUCTOS.Barcode LIKE '%$busqProdEsc%') ";
    }

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
    WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $extraCond

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
    WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $extraCond
    ";

    $stmt=$cnx->prepare($sql);
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    $res=$stmt->get_result();

    $rows=[];
    while($r=$res->fetch_assoc()) $rows[]=$r;
    return $rows;
}

/* =====================================================
    LISTADO GENERAL
===================================================== */
function Lista_Pedido(){

global $mysqliCentral,$mysqliDrinks,$mysqliWeb;

// Captura de Filtros
$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');
$f_prod    = trim($_GET['filtro_prod'] ?? '');
$fSuc      = $_GET['sucursal'] ?? '';
$fFac      = $_GET['facturador'] ?? '';

$f_ini = str_replace('-', '', $f_ini_raw);
$f_fin = str_replace('-', '', $f_fin_raw);

// Carga de datos
$rows = array_merge(
    obtenerDatos($mysqliCentral,'CENTRAL', $f_ini, $f_fin, $f_prod),
    obtenerDatos($mysqliDrinks,'DRINKS', $f_ini, $f_fin, $f_prod)
);

// Variables para llenar los selects dinámicos
$suc=$fac=[];
foreach($rows as $r){
    $suc[$r['SUCURSAL']]=true;
    $fac[$r['FACTURADOR']]=true;
}

/* ====== LÓGICA DE UNICAJA Y CATEGORÍAS ====== */
$skus=array_unique(array_column($rows,'Barcode'));
$categoria=$unicaja=[];
if($skus){
    $lista="'".implode("','",$skus)."'";
    $q=$mysqliWeb->query("SELECT Sku,CodCat FROM catproductos WHERE Sku IN ($lista)");
    while($c=$q->fetch_assoc()) $categoria[$c['Sku']]=$c['CodCat'];
    
    if(!empty($categoria)){
        $cats="'".implode("','",array_unique($categoria))."'";
        $q2=$mysqliWeb->query("SELECT CodCat,Unicaja FROM categorias WHERE CodCat IN ($cats)");
        $uniCat=[];
        while($u=$q2->fetch_assoc()) $uniCat[$u['CodCat']]=$u['Unicaja'];
        foreach($categoria as $s=>$c) $unicaja[$s]=$uniCat[$c] ?? 1;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Ejecutivo</title>
<style>
    body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; background: #eceff1; margin: 20px;}
    .card{background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);}
    h2{ color: #263238; margin-top: 0;}
    .filter-box{ background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6;}
    .filter-group{ display: flex; flex-direction: column; gap: 5px; }
    label{ font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
    input, select, button{ padding: 10px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
    input:focus{ border-color: #0288d1; }
    button{ background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold; padding: 10px 20px;}
    button:hover{ background: #01579b;}
    .btn-excel{ background: #2e7d32 !important; }
    .btn-excel:hover{ background: #1b5e20 !important; }

    table{border-collapse:collapse; width:100%; margin-top: 20px; background: white; border-radius: 8px; overflow: hidden;}
    th{background:#263238; color: white; padding: 12px; text-align: left; position: sticky; top: 0;}
    td{padding: 10px; border-bottom: 1px solid #eee;}
    .total-row{background:#e1f5fe; font-weight:bold;}
    .gran-total{background:#c8e6c9; font-weight:900; font-size: 16px;}
    .badge{ padding: 4px 8px; border-radius: 4px; font-size: 11px; color: white; font-weight: bold;}
    .central{ background: #1565c0; } .drinks{ background: #2e7d32; }
</style>
<script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="card">
    <h2>📊 Ejecución de Ventas (Rango y Producto)</h2>

    <form method="GET" class="filter-box">
        <div class="filter-group">
            <label>Desde:</label>
            <input type="date" name="fecha_ini" value="<?=$f_ini_raw?>">
        </div>
        <div class="filter-group">
            <label>Hasta:</label>
            <input type="date" name="fecha_fin" value="<?=$f_fin_raw?>">
        </div>
        <div class="filter-group" style="flex-grow: 1;">
            <label>Buscar Producto:</label>
            <input type="text" name="filtro_prod" placeholder="Nombre o Sku del producto..." value="<?=htmlspecialchars($f_prod)?>">
        </div>
        <div class="filter-group">
            <label>Sucursal:</label>
            <select name="sucursal">
                <option value="">Todas</option>
                <?php foreach($suc as $k=>$_){ ?>
                <option <?=$k==$fSuc?'selected':''?>><?=$k?></option>
                <?php } ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Facturador:</label>
            <select name="facturador">
                <option value="">Todos</option>
                <?php foreach($fac as $k=>$_){ ?>
                <option <?=$k==$fFac?'selected':''?>><?=$k?></option>
                <?php } ?>
            </select>
        </div>
        <button type="submit">🔍 Filtrar Datos</button>
        <button type="button" class="btn-excel" onclick="exportarExcel()">Excel 📥</button>
    </form>

    <?php if(!$rows): ?>
        <p style="margin-top:20px; color: #78909c;">No se encontraron registros con esos criterios.</p>
    <?php else: ?>
    <table id="tablaVentas">
        <thead>
            <tr>
                <th>Sucursal</th><th>Facturador</th><th>Documento</th><th>Hora</th>
                <th>Sku</th><th>Producto</th><th>Costo</th>
                <th style="text-align:center">Cajas</th>
                <th style="text-align:center">Und</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $gran=0; $sub=0; $docAnt='';
            $totalCajasGlobal = 0; // Acumulador total cajas
            $totalUndsGlobal  = 0; // Acumulador total unidades

            foreach($rows as $r){
                if($fSuc && $r['SUCURSAL']!=$fSuc) continue;
                if($fFac && $r['FACTURADOR']!=$fFac) continue;

                if($docAnt && $docAnt!=$r['DOCUMENTO']){
                    echo "<tr class='total-row'><td colspan='9' style='text-align:right'>Subtotal Doc $docAnt:</td><td>".number_format($sub,0,'.','.')."</td></tr>";
                    $sub=0;
                }

                $uni = $unicaja[$r['Barcode']] ?? 1;
                $cant_total = $r['CANTIDAD'];
                $cajas = floor($cant_total);
                $unds  = round(($cant_total - $cajas) * $uni);
                $total_item = $cant_total * $r['VALORPROD'];

                // Sumamos a los totales globales
                $totalCajasGlobal += $cajas;
                $totalUndsGlobal  += $unds;

                $badge_class = ($r['SUCURSAL'] == 'CENTRAL') ? 'central' : 'drinks';

                echo "<tr>
                    <td><span class='badge $badge_class'>{$r['SUCURSAL']}</span></td>
                    <td>{$r['FACTURADOR']}</td>
                    <td>{$r['DOCUMENTO']}</td>
                    <td>{$r['HORA']}</td>
                    <td><code>{$r['Barcode']}</code></td>
                    <td>{$r['PRODUCTO']}</td>
                    <td>".number_format($r['VALORPROD'],0,'.','.')."</td>
                    <td align='center'>$cajas</td>
                    <td align='center'>$unds</td>
                    <td><strong>".number_format($total_item,0,'.','.')."</strong></td>
                </tr>";

                $gran += $total_item;
                $sub  += $total_item;
                $docAnt = $r['DOCUMENTO'];
            }
            // Último subtotal
            echo "<tr class='total-row'><td colspan='9' style='text-align:right'>Subtotal Doc $docAnt:</td><td>".number_format($sub,0,'.','.')."</td></tr>";
            ?>
            <tr class="gran-total">
                <td colspan="7" style="text-align:right">GRAN TOTAL GENERAL:</td>
                <td align="center"><?=number_format($totalCajasGlobal,0)?></td>
                <td align="center"><?=number_format($totalUndsGlobal,0)?></td>
                <td><?=number_format($gran,0,'.','.')?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
function exportarExcel() {
    let table = document.getElementById("tablaVentas");
    if (!table) {
        alert("No hay datos para exportar");
        return;
    }
    TableToExcel.convert(table, {
        name: "Reporte_Ventas_<?=$f_ini?>_<?=$f_fin?>.xlsx",
        sheet: {
            name: "Ventas"
        }
    });
}
</script>

</body>
</html>
<?php 
} // Cierre de Lista_Pedido
?>