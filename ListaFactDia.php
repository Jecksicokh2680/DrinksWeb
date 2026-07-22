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
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No tiene autorización para acceder a esta página</h2>");
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

    // Se agrega FACTURAS.FECHA / PEDIDOS.FECHA al SELECT para poder ordenar cronológicamente de forma estricta
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
    ORDER BY FECHA ASC, HORA ASC, DOCUMENTO ASC
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$f_ini_raw = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw = $_GET['fecha_fin'] ?? date('Y-m-d');
$f_prod     = trim($_GET['filtro_prod'] ?? '');
$fSuc       = $_GET['sucursal'] ?? '';
$fFac       = $_GET['facturador'] ?? '';

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

// Si se unieron datos de dos bases de datos distintas, volvemos a ordenar el array global en PHP para que no se mezclen los días entre sucursales
if (!empty($rows)) {
    usort($rows, function($a, $b) {
        if ($a['FECHA'] != $b['FECHA']) {
            return strcmp($a['FECHA'], $b['FECHA']);
        }
        if ($a['HORA'] != $b['HORA']) {
            return strcmp($a['HORA'], $b['HORA']);
        }
        return strcmp($a['DOCUMENTO'], $b['DOCUMENTO']);
    });
}

// Mantener lista de facturadores para el select
if ($fFac == '' && !empty($rows)) {
    $listaFacturadores = array_unique(array_column($rows, 'FACTURADOR'));
    $_SESSION['UltimosFacturadores'] = $listaFacturadores;
} else {
    $listaFacturadores = $_SESSION['UltimosFacturadores'] ?? [];
}
sort($listaFacturadores);

// Obtener Unicaja
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
        html, body { height: 100%; margin: 0; padding: 0; }
        body{font-family:'Segoe UI', sans-serif; font-size:14px; background: #eceff1; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column;}
        .card{background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); flex: 1; display: flex; flex-direction: column; box-sizing: border-box;}
        .filter-box{ background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6;}
        .filter-group{ display: flex; flex-direction: column; gap: 5px; }
        label{ font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
        input, select, button{ padding: 10px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
        button{ background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold;}
        .btn-excel{ background: #2e7d32 !important; }
        .btn-print{ background: #455a64 !important; }
        .table-container { flex: 1; max-height: none; overflow-y: auto; margin-top: 20px; border: 1px solid #ddd; }
        table{ border-collapse: collapse; width: 100%; background: white; }
        th{ position: sticky; top: 0; background: #263238; color: white; padding: 12px; text-align: left; z-index: 2; }
        td{ padding: 10px; border-bottom: 1px solid #eee; }
        .total-row{ background: #f1f8e9; font-weight: bold; }
        .gran-total{ background: #263238; color: #fff; font-weight: 900; }
        .badge{ padding: 4px 8px; border-radius: 4px; font-size: 11px; color: white; font-weight: bold; }
        .central{ background: #1565c0; } .drinks{ background: #2e7d32; }
    </style>
    <script src="https://cdn.jsdelivr.net/gh/linways/table-to-excel@v1.0.4/dist/tableToExcel.js"></script>
</head>
<body>

<div class="card">
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
                    <th>Sucursal</th><th>Facturador</th><th>Documento</th><th>Hora</th>
                    <th>Sku</th><th>Producto</th><th>Costo</th>
                    <th style="text-align:center">Cajas</th>
                    <th style="text-align:center">Und</th>
                    <th>Total Item</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $granTotal = 0; $subDoc = 0; $docAnt = '';
                $totalCajasGlobal = 0; $totalUndsGlobal = 0;

                foreach($rows as $r){
                    if($docAnt && $docAnt != $r['DOCUMENTO']){
                        echo "<tr class='total-row'><td colspan='9' style='text-align:right'>Subtotal Doc $docAnt:</td><td>$ ".number_format($subDoc,0,'.','.')."</td></tr>";
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
                if($docAnt) echo "<tr class='total-row'><td colspan='9' style='text-align:right'>Subtotal Doc $docAnt:</td><td>$ ".number_format($subDoc,0,'.','.')."</td></tr>";
                ?>
            </tbody>
            <tfoot>
                <tr class="gran-total">
                    <td colspan="7" style="text-align:right">GRAN TOTAL GENERAL:</td>
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
    const tabla = document.getElementById("tablaVentas");
    
    if (!tabla || tabla.querySelector("tbody").rows.length === 0) {
        alert("No hay datos en la tabla para imprimir.");
        return;
    }

    const fIni = "<?= $f_ini_raw ?>";
    const fFin = "<?= $f_fin_raw ?>";
    
    let win = window.open('', '_blank', 'width=450,height=600');
    win.document.write(`
        <html>
        <head>
            <title>Ticket POS</title>
            <style>
                body { font-family: "Courier New", monospace; font-size: 11px; padding: 10px; font-weight: bold; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .border-dashed { border-top: 1px dashed #000; margin: 5px 0; }
                .doc-header { background: #eee; padding: 2px; margin-top: 8px; }
                .item-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
                .subtotal-line { text-align: right; font-style: italic; margin: 3px 0 8px 0; font-size: 10px; }
            </style>
        </head>
        <body>
            <div class="text-center">
                <strong>SISTEMA DRINKS</strong><br>
                REPORTE: ${fIni} / ${fFin}
            </div>
            <div class="border-dashed"></div>
    `);

    const filas = tabla.querySelectorAll("tbody tr");
    let ultimoDoc = "";

    filas.forEach(fila => {
        if (fila.classList.contains('total-row')) {
            win.document.write(`<div class="subtotal-line">${fila.innerText.trim()}</div><div class="border-dashed"></div>`);
        } else {
            const c = fila.cells;
            const docActual = c[2].innerText;

            if(ultimoDoc !== docActual) {
                win.document.write(`<div class="doc-header">DOC: ${docActual} - ${c[3].innerText}</div>`);
                ultimoDoc = docActual;
            }

            win.document.write(`
                <div class="item-row">
                    <span>${c[5].innerText.substring(0, 22)}</span>
                    <span>${c[7].innerText}c+${c[8].innerText}u</span>
                    <span>${c[9].innerText}</span>
                </div>
            `);
        }
    });

    const granTotal = tabla.querySelector("tfoot .gran-total td:last-child").innerText;
    win.document.write(`
            <div class="border-dashed"></div>
            <div class="text-center" style="font-size:13px; margin-top:10px;">
                <strong>TOTAL GENERAL: ${granTotal}</strong>
            </div>
            <div style="margin-top:20px; text-align:center; font-size:9px;">Generado el: <?=date('d/m/Y H:i:s')?></div>
        </body></html>
    `);

    win.document.close();
    setTimeout(() => { win.print(); }, 500);
}
</script>
</body>
</html>