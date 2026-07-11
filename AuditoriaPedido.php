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

// --- Validación de acceso ---
$permisosValidos = ['0003', '0004', '0014', '9999'];
$tieneAcceso = false;
foreach ($permisosValidos as $p) {
    if (Autorizacion($UsuarioSesion, $p) === "SI") {
        $tieneAcceso = true;
        break;
    }
}
if (!$tieneAcceso) die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No autorizado</h2>");

$esAdminTotal = (Autorizacion($UsuarioSesion, '9999') === "SI");
$sedeAsignada = "";
if (!$esAdminTotal) {
    $stmtSede = $mysqli->prepare("SELECT Sede FROM usuarios WHERE Cedula = ?");
    $stmtSede->bind_param("s", $UsuarioSesion);
    $stmtSede->execute();
    $resSede = $stmtSede->get_result()->fetch_assoc();
    $sedeAsignada = $resSede['Sede'] ?? 'CENTRAL';
    $stmtSede->close();
}

function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqDoc) {
    if (!$cnx || (isset($cnx->connect_error) && $cnx->connect_error)) return [];
    $condDoc = ($busqDoc != "") ? " AND (FACTURAS.NUMERO = '".$cnx->real_escape_string($busqDoc)."' OR PEDIDOS.NUMERO = '".$cnx->real_escape_string($busqDoc)."') " : "";
    $fechaSql = ($busqDoc != "") ? "" : " AND FACTURAS.FECHA BETWEEN ? AND ? ";
    $fechaSqlPed = ($busqDoc != "") ? "" : " AND PEDIDOS.FECHA BETWEEN ? AND ? ";

    $sql = "SELECT '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR, FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD 
            FROM FACTURAS INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR 
            WHERE FACTURAS.ESTADO='0' $fechaSql $condDoc UNION ALL 
            SELECT '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR, PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD 
            FROM PEDIDOS INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO 
            WHERE PEDIDOS.ESTADO='0' $fechaSqlPed $condDoc ORDER BY FECHA DESC, HORA DESC, DOCUMENTO DESC";
    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    if ($busqDoc == "") $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$busqDoc = $_GET['buscar_doc'] ?? '';
$f_ini = ($busqDoc != "") ? '00000000' : str_replace('-', '', $_GET['fecha_ini'] ?? date('Y-m-d'));
$f_fin = ($busqDoc != "") ? '99999999' : str_replace('-', '', $_GET['fecha_fin'] ?? date('Y-m-d'));
$fSuc = !$esAdminTotal ? $sedeAsignada : ($_GET['sucursal'] ?? '');

$rows = [];
if (($fSuc == '' || $fSuc == 'CENTRAL') && isset($mysqliCentral)) $rows = array_merge($rows, obtenerDatos($mysqliCentral, 'CENTRAL', $f_ini, $f_fin, $busqDoc));
if (($fSuc == '' || $fSuc == 'DRINKS') && isset($mysqliDrinks)) $rows = array_merge($rows, obtenerDatos($mysqliDrinks, 'DRINKS', $f_ini, $f_fin, $busqDoc));

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
    if (!isset($pedidos[$doc])) $pedidos[$doc] = ['SUCURSAL'=>$r['SUCURSAL'], 'FACTURADOR'=>$r['FACTURADOR'], 'HORA'=>$r['HORA'], 'ITEMS'=>[], 'TOTAL'=>0, 'TC'=>0, 'TU'=>0];
    $uni = $unicaja[$r['Barcode']] ?? 1;
    $cajas = floor($r['CANTIDAD']);
    $unds = round(($r['CANTIDAD'] - $cajas) * $uni);
    $pedidos[$doc]['ITEMS'][] = ['PROD'=>$r['PRODUCTO'], 'C'=>$cajas, 'U'=>$unds, 'VAL'=>$r['CANTIDAD'] * $r['VALORPROD']];
    $pedidos[$doc]['TOTAL'] += ($r['CANTIDAD'] * $r['VALORPROD']);
    $pedidos[$doc]['TC'] += $cajas;
    $pedidos[$doc]['TU'] += $unds;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de Pedidos</title>
    <style>
        *{ box-sizing:border-box; }
        body{ margin:0; font-family:'Segoe UI',sans-serif; background:#f4f7f6; padding:15px; }
        .filtros{ margin-bottom:20px; background:#fff; padding:15px; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,.08); display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .grid-container{ display:grid; grid-template-columns:repeat(auto-fill,minmax(420px,1fr)); gap:20px; }
        .card{ background:white; border-radius:12px; padding:15px; box-shadow:0 5px 12px rgba(0,0,0,.08); border-top:5px solid #f57c00; transition: transform 0.3s; }
        .card.highlight{ border:3px solid #f57c00; transform: scale(1.02); }
        .card-header{ font-size:14px; font-weight:bold; border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:10px; }
        .header-row{ display:grid; grid-template-columns: 30px 1fr 50px 50px 85px; font-weight:bold; font-size:12px; color:#555; padding-bottom:5px; border-bottom:1px solid #ccc; margin-bottom:5px; }
        .item-row{ display:grid; grid-template-columns: 30px 1fr 50px 50px 85px; align-items:center; padding:8px 0; border-bottom:1px solid #f4f4f4; font-size:13px; }
        .resumen{ margin-top:10px; padding-top:10px; border-top:2px solid #eee; display:flex; justify-content:space-between; font-weight:bold; }
        .total{ color:#2e7d32; font-size:18px; }
    </style>
</head>
<body>
    <form method="GET" class="filtros">
        Desde: <input type="date" name="fecha_ini" value="<?= $_GET['fecha_ini'] ?? date('Y-m-d') ?>">
        Hasta: <input type="date" name="fecha_fin" value="<?= $_GET['fecha_fin'] ?? date('Y-m-d') ?>">
        Doc: <input type="text" id="targetDoc" name="buscar_doc" placeholder="Nro..." value="<?= htmlspecialchars($busqDoc) ?>" style="width:100px;">
        <button type="submit">Filtrar</button>
    </form>
    <div class="grid-container">
        <?php foreach($pedidos as $nro => $d): ?>
        <div class="card" id="card-<?= $nro ?>">
            <div class="card-header">Doc: <?= $nro ?> | <?= $d['SUCURSAL'] ?> | <?= $d['FACTURADOR'] ?> | Hora: <?= $d['HORA'] ?></div>
            <div class="header-row"><span></span><span>Producto</span><span>Caj</span><span>Und</span><span>Total</span></div>
            <?php foreach($d['ITEMS'] as $idx => $i): ?>
                <div class="item-row">
                    <input type="checkbox">
                    <span><?= htmlspecialchars($i['PROD']) ?></span>
                    <span><?= $i['C'] ?></span>
                    <span><?= $i['U'] ?></span>
                    <span>$<?= number_format($i['VAL'],0) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="resumen">
                <span>Cajas: <?= $d['TC'] ?> | Unds: <?= $d['TU'] ?></span>
                <span class="total">Total: $<?= number_format($d['TOTAL'], 0) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
        // Si hay un documento en la URL, hace scroll hacia él y lo resalta
        const urlParams = new URLSearchParams(window.location.search);
        const docBuscado = urlParams.get('buscar_doc');
        if (docBuscado) {
            const el = document.getElementById('card-' + docBuscado);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('highlight');
            }
        }
    </script>
</body>
</html>