<?php
session_start();

require('ConnCentral.php'); // $mysqliCentral
require('ConnDrinks.php');  // $mysqliDrinks
require('Conexion.php');    // $mysqliWeb + $mysqli

define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

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

    $params = [];
    $types  = '';

    // Condiciones dinámicas para FACTURAS y PEDIDOS
    $condProdFactura = '';
    $condProdPedido  = '';
    $condFacFactura  = '';
    $condFacPedido   = '';

    if ($busqProd !== '') {
        $likeProd = '%' . $busqProd . '%';
        $condProdFactura = " AND (PRODUCTOS.Descripcion LIKE ? OR PRODUCTOS.Barcode LIKE ?) ";
        $condProdPedido  = " AND (PRODUCTOS.Descripcion LIKE ? OR PRODUCTOS.Barcode LIKE ?) ";
    }
    if ($f_fac !== '') {
        $condFacFactura = " AND T1.NOMBRES = ? ";
        $condFacPedido  = " AND T2.NOMBRES = ? ";
    }

    $sql = "
    SELECT 
        ? AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR,
        FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD
    FROM FACTURAS
    INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR
    WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condProdFactura $condFacFactura
    UNION ALL
    SELECT 
        ? AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR,
        PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO,
        DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD
    FROM PEDIDOS
    INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO
    INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO
    INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO
    WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condProdPedido $condFacPedido
    ORDER BY DOCUMENTO ASC
    ";

    // Parámetros para la primera parte (FACTURAS)
    $types .= 'sss'; // sucursal, f_ini, f_fin
    $params[] = $nombreSucursal;
    $params[] = $f_ini;
    $params[] = $f_fin;
    if ($busqProd !== '') {
        $types .= 'ss';
        $params[] = $likeProd;
        $params[] = $likeProd;
    }
    if ($f_fac !== '') {
        $types .= 's';
        $params[] = $f_fac;
    }

    // Parámetros para la segunda parte (PEDIDOS)
    $types .= 'sss'; // sucursal, f_ini, f_fin
    $params[] = $nombreSucursal;
    $params[] = $f_ini;
    $params[] = $f_fin;
    if ($busqProd !== '') {
        $types .= 'ss';
        $params[] = $likeProd;
        $params[] = $likeProd;
    }
    if ($f_fac !== '') {
        $types .= 's';
        $params[] = $f_fac;
    }

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                    $cajas = ($uni > 0) ? floor($cant_total / $uni) : 0;
                    $unds  = ($uni > 0) ? $cant_total % $uni : $cant_total;
                    $total_item = $cant_total * $r['VALORPROD'];

                    $totalCajasGlobal += $cajas;
                    $totalUndsGlobal  += $unds;
                    $badge_class = ($r['SUCURSAL'] == 'CENTRAL') ? 'central' : 'drinks';

                    $eSuc  = htmlspecialchars($r['SUCURSAL']);
                    $eFact = htmlspecialchars($r['FACTURADOR']);
                    $eDoc  = htmlspecialchars($r['DOCUMENTO']);
                    $eFec  = htmlspecialchars($r['FECHA']);
                    $eHor  = htmlspecialchars($r['HORA']);
                    $eBar  = htmlspecialchars($r['Barcode']);
                    $eProd = htmlspecialchars($r['PRODUCTO']);

                    echo "<tr>
                        <td><span class='badge $badge_class'>$eSuc</span></td>
                        <td>$eFact</td>
                        <td>$eDoc</td>
                        <td>$eFec</td>
                        <td>$eHor</td>
                        <td><code>$eBar</code></td>
                        <td>$eProd</td>
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
    // 1. Conversión segura de datos PHP a JavaScript
    const rows = <?php echo json_encode($rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
    const unicaja = <?php echo json_encode($unicaja, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'; ?>;

    // Validación de datos antes de intentar abrir la ventana
    if (!rows || rows.length === 0) {
        alert("No hay datos en la tabla para imprimir. Por favor, filtre primero.");
        return;
    }

    // 2. Abrir la ventana emergente
    let win = window.open('', '_blank', 'width=450,height=600');

    if (!win || win.closed || typeof win.closed == 'undefined') { 
        alert("⚠️ El navegador bloqueó la ventana de impresión. Por favor, permite los 'Pop-ups' en este sitio.");
        return;
    }

    // 3. Escribir el contenido del Ticket
    win.document.write('<html><head><title>POS Print - Drinks</title>');
    win.document.write('<style>');
    win.document.write('body { font-family: "Courier New", monospace; width: 95%; margin: 0; padding: 10px; font-size: 11px; font-weight: bold; }');
    win.document.write('.text-center { text-align: center; } .text-right { text-align: right; }');
    win.document.write('.border-dashed { border-top: 1px dashed #000; margin: 5px 0; }');
    win.document.write('.doc-header { font-weight: 900; margin-top: 10px; border-bottom: 1px solid #eee; }');
    win.document.write('.item-row { display: flex; justify-content: space-between; margin-bottom: 2px; }');
    win.document.write('</style></head><body>');
    
    win.document.write('<div class="text-center"><strong>SISTEMA DRINKS</strong><br>');
    win.document.write('Reporte: <?= htmlspecialchars($f_ini_raw) ?> / <?= htmlspecialchars($f_fin_raw) ?></div>');
    win.document.write('<div class="border-dashed"></div>');

    let gTotal = 0;
    let subDoc = 0;
    let docAnt = '';
    const formatoCop = new Intl.NumberFormat('de-DE');

    rows.forEach((r, index) => {
        // Al cambiar de documento, imprimimos el subtotal del anterior
        if (docAnt !== '' && docAnt !== r.DOCUMENTO) {
            win.document.write(`<div class="text-right">SUBTOTAL DOC ${docAnt}: $ ${formatoCop.format(subDoc)}</div><div class="border-dashed"></div>`);
            subDoc = 0;
        }

        // Encabezado del documento
        if (docAnt !== r.DOCUMENTO) {
            win.document.write(`<div class="doc-header">DOC: ${r.DOCUMENTO || ''} | HORA: ${r.HORA || ''}</div>`);
        }

        let itemTotal = parseFloat(r.CANTIDAD || 0) * parseFloat(r.VALORPROD || 0);
        subDoc += itemTotal;
        gTotal += itemTotal;
        docAnt = r.DOCUMENTO;

        // Fila del producto
        let nombreProd = (r.PRODUCTO || '').substring(0, 22);
        win.document.write(`
            <div class="item-row">
                <span>${nombreProd}</span>
                <span>$${formatoCop.format(itemTotal)}</span>
            </div>`);
        
        // Si es el último registro de todos, cerramos el último subtotal
        if (index === rows.length - 1) {
            win.document.write(`<div class="text-right">SUBTOTAL DOC ${r.DOCUMENTO}: $ ${formatoCop.format(subDoc)}</div>`);
        }
    });

    win.document.write('<div class="border-dashed"></div>');
    win.document.write(`<div class="text-center" style="font-size:14px; margin-top:10px;">TOTAL GENERAL: $ ${formatoCop.format(gTotal)}</div>`);
    win.document.write('</body></html>');

    // 4. Cerramos el flujo y esperamos a que cargue
    win.document.close(); 

    // Esperamos a que el navegador renderice los datos antes de imprimir
    setTimeout(function() {
        win.focus();
        win.print();
        win.close(); 
    }, 500); 
}




</script>
</body>
</html>