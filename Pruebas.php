<?php
// Forzar la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks
require_once("Conexion.php");    // $mysqliWeb + $mysqli

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

/* =====================================================
   PARÁMETROS DE FILTRADO
===================================================== */
$f_ini_raw  = $_GET['fecha_ini'] ?? date('Y-m-d');
$f_fin_raw  = $_GET['fecha_fin'] ?? date('Y-m-d');
$f_prod     = trim($_GET['filtro_prod'] ?? '');
$fSuc       = $_GET['sucursal'] ?? '';
$fFac       = $_GET['facturador'] ?? '';

$f_ini_db = str_replace('-', '', $f_ini_raw); 
$f_fin_db = str_replace('-', '', $f_fin_raw);

$rowsTrazabilidad = [];

/* =====================================================
   1. OBTENER MOVIMIENTOS GENERALES (Ajustes, Traslados)
===================================================== */
$extraCondMov = "";
$paramsMov = [$f_ini_raw, $f_fin_raw];
$typesMov = "ss";

if ($f_prod != "") {
    $extraCondMov .= " AND barcode = ?";
    $paramsMov[] = $f_prod;
    $typesMov .= "s";
}

if ($fSuc == 'CENTRAL') {
    $extraCondMov .= " AND (NitEmpresa_Orig = ? OR NitEmpresa_Dest = ?)";
    $paramsMov[] = NIT_CENTRAL;
    $paramsMov[] = NIT_CENTRAL;
    $typesMov .= "ss";
} elseif ($fSuc == 'DRINKS') {
    $extraCondMov .= " AND (NitEmpresa_Orig = ? OR NitEmpresa_Dest = ?)";
    $paramsMov[] = NIT_DRINKS;
    $paramsMov[] = NIT_DRINKS;
    $typesMov .= "ss";
}

$sqlMov = "SELECT 
            'MOVIMIENTO / AJUSTE' AS ORIGEN_TIPO,
            fecha AS FECHA_HORA,
            tipo AS TIPO_MOV,
            barcode AS BARCODE,
            cant AS CANTIDAD,
            NitEmpresa_Orig AS SUC_ORIGEN,
            NitEmpresa_Dest AS SUC_DESTINO,
            Observacion AS DETALLE,
            usuario_Orig AS USUARIO,
            '' AS DOCUMENTO
           FROM inventario_movimientos 
           WHERE DATE(fecha) BETWEEN ? AND ? $extraCondMov";

$stmtMov = $mysqli->prepare($sqlMov);
if ($stmtMov) {
    $stmtMov->bind_param($typesMov, ...$paramsMov);
    $stmtMov->execute();
    $resMov = $stmtMov->get_result();
    while ($m = $resMov->fetch_assoc()) {
        $rowsTrazabilidad[] = [
            'ORIGEN_TIPO' => $m['ORIGEN_TIPO'],
            'FECHA_HORA'  => $m['FECHA_HORA'],
            'TIPO_MOV'    => $m['TIPO_MOV'], 
            'BARCODE'     => $m['BARCODE'],
            'CANTIDAD'    => (float)$m['CANTIDAD'],
            'SUCURSAL'    => ($m['SUC_ORIGEN'] === NIT_DRINKS) ? 'DRINKS' : 'CENTRAL',
            'DETALLE'     => $m['DETALLE'],
            'USUARIO'     => $m['USUARIO'],
            'DOCUMENTO'   => $m['DOCUMENTO']
        ];
    }
    $stmtMov->close();
}

/* =====================================================
   2. VENTAS
===================================================== */
function obtenerVentasParaTrazabilidad($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
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
        'VENTA FACTURA' AS ORIGEN_TIPO,
        CONCAT(FACTURAS.FECHA, ' ', FACTURAS.HORA) AS FECHA_HORA,
        'SALE' AS TIPO_MOV,
        PRODUCTOS.Barcode AS BARCODE,
        DETFACTURAS.CANTIDAD AS CANTIDAD,
        '$nombreSucursal' AS SUCURSAL,
        CONCAT('Factura #', FACTURAS.NUMERO, ' - Total: $', FORMAT(DETFACTURAS.CANTIDAD * DETFACTURAS.VALORPROD, 0)) AS DETALLE,
        T1.NOMBRES AS USUARIO,
        FACTURAS.NUMERO AS DOCUMENTO
    FROM FACTURAS
    INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA = FACTURAS.IDFACTURA
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETFACTURAS.IDPRODUCTO
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = FACTURAS.IDVENDEDOR
    WHERE FACTURAS.ESTADO = '0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura
    
    UNION ALL
    
    SELECT 
        'VENTA PEDIDO' AS ORIGEN_TIPO,
        CONCAT(PEDIDOS.FECHA, ' ', PEDIDOS.HORA) AS FECHA_HORA,
        'SALE' AS TIPO_MOV,
        PRODUCTOS.Barcode AS BARCODE,
        DETPEDIDOS.CANTIDAD AS CANTIDAD,
        '$nombreSucursal' AS SUCURSAL,
        CONCAT('Pedido #', PEDIDOS.NUMERO, ' - Total: $', FORMAT(DETPEDIDOS.CANTIDAD * DETPEDIDOS.VALORPROD, 0)) AS DETALLE,
        T2.NOMBRES AS USUARIO,
        PEDIDOS.NUMERO AS DOCUMENTO
    FROM PEDIDOS
    INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO = DETPEDIDOS.IDPEDIDO
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETPEDIDOS.IDPRODUCTO
    INNER JOIN USUVENDEDOR V ON V.IDUSUARIO = PEDIDOS.IDUSUARIO
    INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V.IDTERCERO
    WHERE PEDIDOS.ESTADO = '0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($fSuc == '' || $fSuc == 'CENTRAL') {
    if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
        $rowsTrazabilidad = array_merge($rowsTrazabilidad, obtenerVentasParaTrazabilidad($mysqliCentral, 'CENTRAL', $f_ini_db, $f_fin_db, $f_prod, $fFac));
    }
}

if ($fSuc == '' || $fSuc == 'DRINKS') {
    if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
        $rowsTrazabilidad = array_merge($rowsTrazabilidad, obtenerVentasParaTrazabilidad($mysqliDrinks, 'DRINKS', $f_ini_db, $f_fin_db, $f_prod, $fFac));
    }
}

/* =====================================================
   3. COMPRAS
===================================================== */
function obtenerComprasParaTrazabilidad($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];

    $extraCond = "";
    if ($busqProd != "") {
        $busqProdEsc = $cnx->real_escape_string($busqProd);
        $extraCond .= " AND (PRODUCTOS.Descripcion LIKE '%$busqProdEsc%' OR PRODUCTOS.Barcode LIKE '%$busqProdEsc%') ";
    }
    if ($f_fac != "") {
        $f_fac_esc = $cnx->real_escape_string($f_fac);
        $extraCond .= " AND TERCEROS.nombres LIKE '%$f_fac_esc%' ";
    }

    $sql = "
    SELECT 
        'COMPRA' AS ORIGEN_TIPO,
        CONCAT(COMPRAS.FECHA, ' 00:00:00') AS FECHA_HORA,
        'ENTRA' AS TIPO_MOV,
        PRODUCTOS.Barcode AS BARCODE,
        DETCOMPRAS.CANTIDAD AS CANTIDAD,
        '$nombreSucursal' AS SUCURSAL,
        CONCAT('Compra ID #', COMPRAS.idcompra, ' - Proveedor: ', TERCEROS.nombres, ' ', TERCEROS.apellidos) AS DETALLE,
        TERCEROS.nombres AS USUARIO,
        COMPRAS.idcompra AS DOCUMENTO
    FROM COMPRAS
    INNER JOIN DETCOMPRAS ON DETCOMPRAS.idcompra = COMPRAS.idcompra
    INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETCOMPRAS.IDPRODUCTO
    INNER JOIN TERCEROS ON TERCEROS.IDTERCERO = COMPRAS.IDTERCERO
    WHERE COMPRAS.ESTADO = '0' AND COMPRAS.FECHA BETWEEN ? AND ? $extraCond
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ss", $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($fSuc == '' || $fSuc == 'CENTRAL') {
    if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
        $rowsTrazabilidad = array_merge($rowsTrazabilidad, obtenerComprasParaTrazabilidad($mysqliCentral, 'CENTRAL', $f_ini_db, $f_fin_db, $f_prod, $fFac));
    }
}

if ($fSuc == '' || $fSuc == 'DRINKS') {
    if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
        $rowsTrazabilidad = array_merge($rowsTrazabilidad, obtenerComprasParaTrazabilidad($mysqliDrinks, 'DRINKS', $f_ini_db, $f_fin_db, $f_prod, $fFac));
    }
}

// Ordenar trazabilidad de forma cronológica descendente
if (!empty($rowsTrazabilidad)) {
    usort($rowsTrazabilidad, function($a, $b) {
        return strcmp($b['FECHA_HORA'], $a['FECHA_HORA']);
    });
}

// Extraer lista de usuarios/responsables
$listaFacturadores = [];
foreach($rowsTrazabilidad as $r) {
    if (!empty($r['USUARIO']) && in_array($r['ORIGEN_TIPO'], ['VENTA FACTURA', 'VENTA PEDIDO', 'COMPRA'])) {
        $listaFacturadores[] = $r['USUARIO'];
    }
}
$listaFacturadores = array_unique($listaFacturadores);
sort($listaFacturadores);

/* =====================================================
   4. CÁLCULO DE TOTALES
===================================================== */
$totalEntradas = 0;
$totalSalidas  = 0;

foreach($rowsTrazabilidad as $r) {
    $tipo = strtoupper(trim($r['TIPO_MOV']));
    $cant = (float)$r['CANTIDAD'];
    
    // Consideramos ENTRA y AJUSTE_IN como entradas
    if ($tipo === 'ENTRA' || $tipo === 'AJUSTE_IN' || $tipo === 'ENTRADA') {
        $totalEntradas += $cant;
    } 
    // Consideramos SALE y AJUSTE_OUT como salidas
    elseif ($tipo === 'SALE' || $tipo === 'AJUSTE_OUT' || $tipo === 'SALIDA') {
        $totalSalidas += $cant;
    }
}
$diferenciaNeta = $totalEntradas - $totalSalidas;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Trazabilidad Completa de Inventario (Kardex)</title>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; font-size: 13px; background: #eceff1; padding: 15px; box-sizing: border-box; display: flex; flex-direction: column; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); flex: 1; display: flex; flex-direction: column; box-sizing: border-box; }
        .filter-box { background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; border: 1px solid #dee2e6; margin-bottom: 15px;}
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-size: 11px; font-weight: bold; color: #546e7a; text-transform: uppercase;}
        input, select, button { padding: 8px 12px; border: 1px solid #cfd8dc; border-radius: 6px; outline: none;}
        button { background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold;}
        
        /* Tarjetas de Resumen */
        .resumen-cards { display: flex; gap: 15px; margin-bottom: 15px; }
        .r-card { flex: 1; background: #f1f8e9; border-left: 4px solid #33691e; padding: 10px 15px; border-radius: 6px; display: flex; flex-direction: column; }
        .r-card.salidas { background: #ffebee; border-left-color: #b71c1c; }
        .r-card.neto { background: #e3f2fd; border-left-color: #0d47a1; }
        .r-card span.title { font-size: 11px; font-weight: bold; color: #555; text-transform: uppercase; }
        .r-card span.value { font-size: 18px; font-weight: bold; margin-top: 3px; }

        .table-container { flex: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; position: relative; }
        table { border-collapse: collapse; width: 100%; background: white; }
        th { position: sticky; top: 0; background: #263238; color: white; padding: 12px; text-align: left; z-index: 2; font-size: 11px; text-transform: uppercase;}
        td { padding: 10px; border-bottom: 1px solid #eee; }
        
        /* Fila Totales fija abajo */
        tfoot tr td { position: sticky; bottom: 0; background: #37474f !important; color: white; font-weight: bold; padding: 12px; z-index: 2; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; color: white; font-weight: bold; }
        .central { background: #1565c0; } 
        .drinks { background: #2e7d32; }
        .tipo-entra, .tipo-ajuste_in, .tipo-entrada { color: #2e7d32; font-weight: bold; }
        .tipo-sale, .tipo-ajuste_out, .tipo-salida { color: #c62828; font-weight: bold; }
    </style>
</head>
<body>

<div class="card">
    <h2>📦 Trazabilidad de Stock (Kardex Unificado con Compras)</h2>

    <form method="GET" class="filter-box">
        <div class="filter-group"><label>Desde:</label><input type="date" name="fecha_ini" value="<?=htmlspecialchars($f_ini_raw)?>"></div>
        <div class="filter-group"><label>Hasta:</label><input type="date" name="fecha_fin" value="<?=htmlspecialchars($f_fin_raw)?>"></div>
        <div class="filter-group" style="flex-grow: 1;"><label>Barcode o Producto:</label><input type="text" name="filtro_prod" value="<?=htmlspecialchars($f_prod)?>" placeholder="Código de barra exacto o aproximado..."></div>
        
        <div class="filter-group">
            <label>Sucursal:</label>
            <select name="sucursal">
                <option value="">Todas</option>
                <option value="CENTRAL" <?=$fSuc=='CENTRAL'?'selected':''?>>CENTRAL</option>
                <option value="DRINKS" <?=$fSuc=='DRINKS'?'selected':''?>>DRINKS</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Usuario / Responsable:</label>
            <select name="facturador">
                <option value="">-- Todos --</option>
                <?php foreach($listaFacturadores as $fact): ?>
                    <option value="<?=htmlspecialchars($fact)?>" <?=$fFac==$fact?'selected':''?>><?=htmlspecialchars($fact)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">🔍 Consultar Trazabilidad</button>
    </form>

    <?php if(!empty($rowsTrazabilidad)): ?>
        <!-- Tarjetas de Resumen Rápido -->
        <div class="resumen-cards">
            <div class="r-card">
                <span class="title">Total Entradas (+)</span>
                <span class="value" style="color: #2e7d32;"><?= number_format($totalEntradas, 2) ?></span>
            </div>
            <div class="r-card salidas">
                <span class="title">Total Salidas (-)</span>
                <span class="value" style="color: #c62828;"><?= number_format($totalSalidas, 2) ?></span>
            </div>
            <div class="r-card neto">
                <span class="title">Impacto Neto en Inventario</span>
                <span class="value" style="color: <?= ($diferenciaNeta >= 0) ? '#1b5e20' : '#b71c1c' ?>;"><?= ($diferenciaNeta > 0 ? '+' : '') . number_format($diferenciaNeta, 2) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($rowsTrazabilidad)): ?>
        <p style="margin-top:20px; text-align:center; color:#777;">No se encontraron movimientos, ventas ni compras en el rango seleccionado.</p>
    <?php else: ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sucursal</th>
                    <th>Fecha / Hora</th>
                    <th>Tipo Movimiento</th>
                    <th>Barcode</th>
                    <th style="text-align:center">Cantidad</th>
                    <th>Origen / Tipo</th>
                    <th>Detalle</th>
                    <th>Responsable</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rowsTrazabilidad as $r): 
                    $badgeSuc = ($r['SUCURSAL'] == 'CENTRAL') ? 'central' : 'drinks';
                    $claseTipo = 'tipo-' . strtolower($r['TIPO_MOV']);
                    $signoCant = in_array(strtoupper($r['TIPO_MOV']), ['ENTRA', 'AJUSTE_IN', 'ENTRADA']) ? '+' : '-';
                ?>
                <tr>
                    <td><span class='badge <?= $badgeSuc ?>'><?= $r['SUCURSAL'] ?></span></td>
                    <td><?= $r['FECHA_HORA'] ?></td>
                    <td><span class="<?= $claseTipo ?>"><?= $r['TIPO_MOV'] ?></span></td>
                    <td><code><?= $r['BARCODE'] ?></code></td>
                    <td align="center" class="<?= $claseTipo ?>">
                        <strong><?= $signoCant . number_format($r['CANTIDAD'], 2) ?></strong>
                    </td>
                    <td><small style="background:#e0e0e0; padding:2px 6px; border-radius:4px;"><?= $r['ORIGEN_TIPO'] ?></small></td>
                    <td><?= htmlspecialchars($r['DETALLE']) ?></td>
                    <td><?= htmlspecialchars($r['USUARIO']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" align="right">TOTALES GENERALES DEL FILTRO:</td>
                    <td align="center">
                        <span style="color: #a5d6a7;">Entradas: +<?= number_format($totalEntradas, 2) ?></span><br>
                        <span style="color: #ef9a9a;">Salidas: -<?= number_format($totalSalidas, 2) ?></span>
                    </td>
                    <td colspan="3">
                        Neto: <span style="color: <?= ($diferenciaNeta >= 0) ? '#a5d6a7' : '#ef9a9a' ?>; font-size: 14px;">
                            <?= ($diferenciaNeta > 0 ? '+' : '') . number_format($diferenciaNeta, 2) ?>
                        </span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

</body>
</html>