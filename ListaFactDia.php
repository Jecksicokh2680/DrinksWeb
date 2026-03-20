<?php
require('ConnCentral.php'); // $mysqliCentral
require('ConnDrinks.php');  // $mysqliDrinks
require('Conexion.php');    // $mysqliWeb + $mysqli

define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

session_start();

mysqli_report(MYSQLI_REPORT_OFF);

/* =====================================================
    CONFIGURACIÓN Y AUTORIZACIÓN
===================================================== */
$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) {
    header("Location: Login.php");
    exit;
}

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    if (!isset($_SESSION['Autorizaciones'])) $_SESSION['Autorizaciones'] = [];
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) return $_SESSION['Autorizaciones'][$key];

    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}

if (Autorizacion($UsuarioSesion, '0003') !== "SI" && Autorizacion($UsuarioSesion, '9999') !== "SI") {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No tiene autorización</h2>");
}

date_default_timezone_set('America/Bogota');

/* =====================================================
    LÓGICA DE OBTENCIÓN DE DATOS
===================================================== */
function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];

    $extraCond = "";
    if ($busqProd != "") {
        $busqProdEsc = $cnx->real_escape_string($busqProd);
        $extraCond .= " AND (PRODUCTOS.Descripcion LIKE '%$busqProdEsc%' OR PRODUCTOS.Barcode LIKE '%$busqProdEsc%') ";
    }
    
    $condFactura = $extraCond;
    $condPedido = $extraCond;
    if ($f_fac != "") {
        $f_fac_esc = $cnx->real_escape_string($f_fac);
        $condFactura .= " AND T1.NOMBRES = '$f_fac_esc' ";
        $condPedido  .= " AND T2.NOMBRES = '$f_fac_esc' ";
    }

    $sql = "
    SELECT 
        '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR,
        FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD
    FROM FACTURAS
    INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR
    WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura
    UNION ALL
    SELECT 
        '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR,
        PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD
    FROM PEDIDOS
    INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO
    INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO
    INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO
    WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido
    ORDER BY DOCUMENTO ASC
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');
$f_prod    = trim($_GET['filtro_prod'] ?? '');
$fSuc      = $_GET['sucursal'] ?? '';
$fFac      = $_GET['facturador'] ?? '';

$f_ini = str_replace('-', '', $f_ini_raw);
$f_fin = str_replace('-', '', $f_fin_raw);

$rows = [];
if ($fSuc == '' || $fSuc == 'CENTRAL') {
    if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
        $rows = array_merge($rows, obtenerDatos($mysqliCentral, 'CENTRAL', $f_ini, $f_fin, $f_prod, $fFac));
    }
}
if ($fSuc == '' || $fSuc == 'DRINKS') {
    if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
        $rows = array_merge($rows, obtenerDatos($mysqliDrinks, 'DRINKS', $f_ini, $f_fin, $f_prod, $fFac));
    }
}

if ($fFac == '' && !empty($rows)) {
    $listaFacturadores = array_unique(array_column($rows, 'FACTURADOR'));
    $_SESSION['UltimosFacturadores'] = $listaFacturadores;
} else {
    $listaFacturadores = $_SESSION['UltimosFacturadores'] ?? [];
}
sort($listaFacturadores);

$unicaja = [];
$skus = array_unique(array_column($rows, 'Barcode'));
if ($skus && isset($mysqliWeb)) {
    $listaSkus = "'" . implode("','", array_map(array($mysqliWeb, 'real_escape_string'), $skus)) . "'";
    $q = $mysqliWeb->query("SELECT cp.Sku, cat.Unicaja FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat WHERE cp.Sku IN ($listaSkus)");
    if ($q) {
        while ($u = $q->fetch_assoc()) $unicaja[$u['Sku']] = $u['Unicaja'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Ejecutivo - Sistema Drinks</title>
    <style>
        body{font-family:'Segoe UI', sans-serif; font-size:14px; background: #eceff1; margin: 20px;}
        .card{background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);}
        .filter-box{ background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6;}
        .filter-group{ display: flex; flex-direction: column; gap: 5px; }
        label{ font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
        input, select, button{ padding: 10px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
        button{ background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold;}
        .btn-excel{ background: #2e7d32 !important; }
        .btn-print{ background: #455a64 !important; }
        .table-container { max-height: 600px; overflow-y: auto; margin-top: 20px; border: 1px solid #ddd; }
        table{ border-collapse: collapse; width: 100%; background: white; }
        th{ position: sticky; top: 0; background: #263238; color: white; padding: 12px; text-align: left; z-index: 2; }
        td{ padding: 10px; border-bottom: 1px solid #eee; }
        .total-row{ background: #f1f8e9; font-weight: bold; }
        .gran-total{ background: #263238; color: #fff; font-weight: 900; }
        .badge{ padding: 4px 8px; border-radius: 4px; font-size: 11px; color: white; font-weight: bold; }
        .central{ background: #1565c0; } .drinks{ background: #2e7d32; }
        .fecha-actual{ color: #78909c; font-size: 12px; font-weight: bold; margin-bottom: 5px; display: block;}
    </style>
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="card">
    <span class="fecha-actual">📅 Generado: <?= date('d/m/Y h:i A') ?></span>
    <h2>📊 Ejecución de Ventas</h2>

    <form method="GET" class="filter-box">
        <div class="filter-group"><label>Desde:</label><input type="date" name="fecha_ini" value="<?=$f_ini_raw?>"></div>
        <div class="filter-group"><label>Hasta:</label><input type="date" name="fecha_fin" value="<?=$f_fin_raw?>"></div>
        <div class="filter-group" style="flex-grow: 1;"><label>Producto:</label><input type="text" name="filtro_prod" value="<?=htmlspecialchars($f_prod)?>" placeholder="Nombre o Barcode..."></div>
        
        <div class="filter-group">
            <label>Sucursal:</label>
            <select name="sucursal">
                <option value="">Todas</option>
                <option value="CENTRAL" <?=$fSuc=='CENTRAL'?'selected':''?>>CENTRAL</option>
                <option value="DRINKS" <?=$fSuc=='DRINKS'?'selected':''?>>DRINKS</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Facturador:</label>
            <select name="facturador">
                <option value="">-- Todos --</option>
                <?php foreach($listaFacturadores as $fact): ?>
                    <option value="<?=htmlspecialchars($fact)?>" <?=$fFac==$fact?'selected':''?>><?=htmlspecialchars($fact)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">🔍 Filtrar</button>
        <button type="button" class="btn-excel" onclick="exportarExcel()">Excel 📥</button>
        <button type="button" class="btn-print" onclick="imprimirReporte()">Imprimir 🖨️</button>
    </form>

    <?php if(!$rows): ?>
        <p style="margin-top:20px; text-align:center; color:#777;">No se encontraron registros.</p>
    <?php else: ?>
    <div class="table-container">
        <table id="tablaVentas">
            <thead>
                <tr>
                    <th>Sucursal</th><th>Facturador</th><th>Documento</th><th>Fecha</th><th>Hora</th>
                    <th>Sku</th><th>Producto</th><th>Precio</th>
                    <th style="text-align:center">Cajas</th>
                    <th style="text-align:center">Und</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $granTotal = 0; $subDoc = 0; $docAnt = '';
                $totalCajasGlobal = 0; $totalUndsGlobal = 0;

                foreach($rows as $r){
                    if($docAnt && $docAnt != $r['DOCUMENTO']){
                        echo "<tr class='total-row'><td colspan='10' style='text-align:right'>Subtotal Doc $docAnt:</td><td>$ ".number_format($subDoc,0,'.','.')."</td></tr>";
                        $subDoc = 0;
                    }

                    $uni = $unicaja[$r['Barcode']] ?? 1;
                    $cant_total = $r['CANTIDAD'];
                    $cajas = floor($cant_total);
                    $unds  = round(($cant_total - $cajas) * $uni);
                    $total_item = $cant_total * $r['VALORPROD'];

                    $totalCajasGlobal += $cajas;
                    $totalUndsGlobal  += $unds;
                    $badge_class = ($r['SUCURSAL'] == 'CENTRAL') ? 'central' : 'drinks';

                    echo "<tr>
                        <td><span class='badge $badge_class'>{$r['SUCURSAL']}</span></td>
                        <td>{$r['FACTURADOR']}</td>
                        <td>{$r['DOCUMENTO']}</td>
                        <td>{$r['FECHA']}</td>
                        <td>{$r['HORA']}</td>
                        <td><code>{$r['Barcode']}</code></td>
                        <td>{$r['PRODUCTO']}</td>
                        <td>".number_format($r['VALORPROD'],0,'.','.')."</td>
                        <td align='center'>$cajas</td>
                        <td align='center'>$unds</td>
                        <td><strong>".number_format($total_item,0,'.','.')."</strong></td>
                    </tr>";

                    $granTotal += $total_item;
                    $subDoc    += $total_item;
                    $docAnt     = $r['DOCUMENTO'];
                }
                if($docAnt) echo "<tr class='total-row'><td colspan='10' style='text-align:right'>Subtotal Doc $docAnt:</td><td>$ ".number_format($subDoc,0,'.','.')."</td></tr>";
                ?>
            </tbody>
            <tfoot>
                <tr class="gran-total">
                    <td colspan="8" style="text-align:right">GRAN TOTAL:</td>
                    <td align="center"><?=number_format($totalCajasGlobal,0)?></td>
                    <td align="center"><?=number_format($totalUndsGlobal,0)?></td>
                    <td>$ <?=number_format($granTotal,0,'.','.')?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function exportarExcel() {
    let table = document.getElementById("tablaVentas");
    if(!table) return;
    TableToExcel.convert(table, { 
        name: "Reporte_Ventas_<?=date('Ymd_His')?>.xlsx",
        sheet: { name: "Ventas" }
    });
}

function imprimirReporte() {
    const rows = <?= json_encode($rows) ?>;
    const unicaja = <?= json_encode($unicaja) ?>;
    if (rows.length === 0) return;

    let win = window.open('', '', 'width=400,height=600');
    win.document.write('<html><head><title>POS Print</title>');
    win.document.write('<style>');
    win.document.write('body{ font-family: "Courier New", Courier, monospace; width: 100%; margin: 0; padding: 0; font-size: 11px; line-height: 1.0; color: #000; font-weight: 700; }');
    win.document.write('.text-center{ text-align: center; } .text-right{ text-align: right; }');
    win.document.write('.border-dashed{ border-top: 1px dashed #000; margin: 2px 0; }');
    win.document.write('.doc-header{ font-weight: 900; margin-top: 4px; font-size: 11px; }');
    win.document.write('.item-row{ display: flex; justify-content: space-between; margin-bottom: 1px; }');
    win.document.write('.item-qty{ font-size: 10px; margin-bottom: 3px; border-bottom: 0.5px solid #eee; }');
    win.document.write('.total-destacado{ font-size: 13px; font-weight: 900; margin-top: 5px; }');
    win.document.write('</style></head><body>');
    
    win.document.write('<div class="text-center"><strong>SISTEMA DRINKS</strong><br>');
    win.document.write('Rep: <?= $f_ini_raw ?> al <?= $f_fin_raw ?></div>');
    win.document.write('<div class="border-dashed"></div>');

    let gTotal = 0;
    let subDoc = 0;
    let docAnt = '';

    const formatoCop = new Intl.NumberFormat('de-DE', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });

    rows.forEach((r, index) => {
        if (docAnt !== '' && docAnt !== r.DOCUMENTO) {
            win.document.write(`<div class="text-right">TOTAL DOC: $ ${formatoCop.format(subDoc)}</div>`);
            win.document.write('<div class="border-dashed"></div>');
            subDoc = 0;
        }

        if (docAnt !== r.DOCUMENTO) {
            win.document.write(`<div class="doc-header">DOC: ${r.DOCUMENTO} | ${r.FECHA} ${r.HORA}</div>`);
        }

        let uni = unicaja[r.Barcode] || 1;
        let cTotal = parseFloat(r.CANTIDAD);
        let cajas = Math.floor(cTotal);
        let unds = Math.round((cTotal - cajas) * uni);
        let itemTotal = cTotal * parseFloat(r.VALORPROD);

        subDoc += itemTotal;
        gTotal += itemTotal;
        docAnt = r.DOCUMENTO;

        win.document.write('<div class="item-row">');
        win.document.write(`<span>${r.PRODUCTO.substring(0, 20)}</span>`);
        win.document.write(`<span>$${formatoCop.format(itemTotal)}</span>`);
        win.document.write('</div>');
        
        win.document.write(`<div class="item-qty">  ${cajas}Cj ${unds}Un x $${formatoCop.format(r.VALORPROD)}</div>`);

        if (index === rows.length - 1) {
            win.document.write(`<div class="text-right">TOTAL DOC: $ ${formatoCop.format(subDoc)}</div>`);
        }
    });

    win.document.write('<div class="border-dashed"></div>');
    win.document.write(`<div class="text-center total-destacado">TOTAL GENERAL: $ ${formatoCop.format(gTotal)}</div>`);
    win.document.write('<div class="text-center" style="font-size:9px;"><br>*** FIN REPORTE ***</div><br><br>');

    win.document.write('<script>window.onload = function() { window.print(); window.close(); };<\/script>');
    win.document.write('</body></html>');
    win.document.close();
}</script>
</body>
</html>