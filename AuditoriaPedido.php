<?php
require('ConnCentral.php');
require('ConnDrinks.php');
require('Conexion.php');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) { header("Location: Login.php"); exit; }

// --- Funciones de soporte ---
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
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No autorizado</h2>");
}

function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];
    $extraCond = ($busqProd != "") ? " AND (PRODUCTOS.Descripcion LIKE '%".$cnx->real_escape_string($busqProd)."%' OR PRODUCTOS.Barcode LIKE '%".$cnx->real_escape_string($busqProd)."%') " : "";
    $condFactura = $extraCond . ($f_fac != "" ? " AND T1.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");
    $condPedido  = $extraCond . ($f_fac != "" ? " AND T2.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");

    $sql = "SELECT '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR, FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD FROM FACTURAS INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura UNION ALL SELECT '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR, PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD FROM PEDIDOS INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido ORDER BY FECHA ASC, HORA ASC, DOCUMENTO ASC";

    $stmt = $cnx->prepare($sql);
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$f_ini = str_replace('-', '', $_GET['fecha_ini'] ?? date('Y-m-d'));
$f_fin = str_replace('-', '', $_GET['fecha_fin'] ?? date('Y-m-d'));
$fSuc = $_GET['sucursal'] ?? '';
$rows = [];
if ($fSuc == '' || $fSuc == 'CENTRAL') $rows = array_merge($rows, obtenerDatos($mysqliCentral, 'CENTRAL', $f_ini, $f_fin, $_GET['filtro_prod'] ?? '', $_GET['facturador'] ?? ''));
if ($fSuc == '' || $fSuc == 'DRINKS') $rows = array_merge($rows, obtenerDatos($mysqliDrinks, 'DRINKS', $f_ini, $f_fin, $_GET['filtro_prod'] ?? '', $_GET['facturador'] ?? ''));

$pedidos = [];
$skus = array_unique(array_column($rows, 'Barcode'));
$unicaja = [];
if ($skus && isset($mysqliWeb)) {
    $listaSkus = "'" . implode("','", array_map(array($mysqliWeb, 'real_escape_string'), $skus)) . "'";
    $q = $mysqliWeb->query("SELECT cp.Sku, cat.Unicaja FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat WHERE cp.Sku IN ($listaSkus)");
    while ($u = $q->fetch_assoc()) $unicaja[$u['Sku']] = $u['Unicaja'];
}

foreach ($rows as $r) {
    $doc = $r['DOCUMENTO'];
    if (!isset($pedidos[$doc])) $pedidos[$doc] = ['SUCURSAL'=>$r['SUCURSAL'], 'FACTURADOR'=>$r['FACTURADOR'], 'HORA'=>$r['HORA'], 'ITEMS'=>[], 'TOTAL'=>0];
    $uni = $unicaja[$r['Barcode']] ?? 1;
    $cajas = floor($r['CANTIDAD']);
    $unds = round(($r['CANTIDAD'] - $cajas) * $uni);
    $pedidos[$doc]['ITEMS'][] = ['PROD'=>$r['PRODUCTO'], 'C'=>$cajas, 'U'=>$unds, 'VAL'=>$r['CANTIDAD'] * $r['VALORPROD']];
    $pedidos[$doc]['TOTAL'] += ($r['CANTIDAD'] * $r['VALORPROD']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría de Pedidos</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: sans-serif; background: #f4f7f6; margin: 0; padding: 10px; }
        
        /* Filtros: apilados en móvil, línea en escritorio */
        form.filtros { display: flex; flex-direction: column; gap: 10px; background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        form.filtros input, form.filtros select, form.filtros button { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; }
        
        /* Grid: 1 columna en móvil, 3 columnas cuando hay espacio */
        .container { 
            display: grid; 
            grid-template-columns: 1fr; 
            gap: 15px; 
        }
        @media (min-width: 900px) {
            .container { grid-template-columns: repeat(3, 1fr); }
        }

        .card { background: white; padding: 15px; border-radius: 8px; border-top: 5px solid #f57c00; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        
        .item-row { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; gap: 10px; font-size: 13px; cursor: pointer; }
        .item-row input { width: 18px; height: 18px; }
        .item-name { flex-grow: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .item-val { width: 70px; text-align: right; }

        .btn-fix { position: sticky; bottom: 0; width: 100%; padding: 18px; background: #f57c00; color: white; border: none; font-size: 18px; font-weight: bold; border-radius: 8px; z-index: 100; margin-top: 20px; }
    </style>
</head>
<body>

    <form method="GET" class="filtros">
        <input type="date" name="fecha_ini" value="<?= $_GET['fecha_ini'] ?? date('Y-m-d') ?>">
        <input type="date" name="fecha_fin" value="<?= $_GET['fecha_fin'] ?? date('Y-m-d') ?>">
        <select name="sucursal">
            <option value="">Todas</option>
            <option value="CENTRAL" <?= $fSuc=='CENTRAL'?'selected':'' ?>>CENTRAL</option>
            <option value="DRINKS" <?= $fSuc=='DRINKS'?'selected':'' ?>>DRINKS</option>
        </select>
        <button type="submit">Filtrar</button>
    </form>

    <form action="procesar_auditoria.php" method="POST">
        <div class="container">
            <?php foreach($pedidos as $nro => $d): ?>
            <div class="card">
                <div style="font-weight:bold; font-size: 14px;">Doc: <?= $nro ?></div>
                <div style="font-size: 11px; color: #666; margin-bottom: 10px;"><?= $d['SUCURSAL'] ?> - <?= $d['FACTURADOR'] ?></div>
                
                <?php foreach($d['ITEMS'] as $idx => $i): ?>
                    <label class="item-row">
                        <input type="checkbox" name="audit[]" value="<?= $nro ?>_<?= $idx ?>">
                        <span class="item-name"><?= htmlspecialchars($i['PROD']) ?></span>
                        <span class="item-val">$<?= number_format($i['VAL'],0) ?></span>
                    </label>
                <?php endforeach; ?>
                
                <div style="text-align: right; font-weight: bold; margin-top: 10px; font-size: 14px;">Total: $<?= number_format($d['TOTAL'], 0) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
    </form>

</body>
</html>