<?php
require('ConnCentral.php');
require('ConnDrinks.php');
require('Conexion.php');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) { header("Location: Login.php"); exit; }

// --- Función para obtener datos ---
function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];
    $extraCond = ($busqProd != "") ? " AND (PRODUCTOS.Descripcion LIKE '%".$cnx->real_escape_string($busqProd)."%' OR PRODUCTOS.Barcode LIKE '%".$cnx->real_escape_string($busqProd)."%') " : "";
    $condFactura = $extraCond . ($f_fac != "" ? " AND T1.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");
    $condPedido  = $extraCond . ($f_fac != "" ? " AND T2.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");

    $sql = "SELECT '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR, FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD FROM FACTURAS INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura UNION ALL SELECT '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR, PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD FROM PEDIDOS INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido ORDER BY FECHA DESC, HORA DESC, DOCUMENTO DESC";

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
    $valorTotalItem = $r['CANTIDAD'] * $r['VALORPROD'];
    $pedidos[$doc]['ITEMS'][] = ['PROD'=>$r['PRODUCTO'], 'C'=>$cajas, 'U'=>$unds, 'VAL'=>$valorTotalItem];
    $pedidos[$doc]['TOTAL'] += $valorTotalItem;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría de Pedidos</title>
    <style>
        *{ box-sizing:border-box; }
        body{ margin:0; font-family:'Segoe UI',sans-serif; background:#f4f7f6; padding:10px; }
        .filtros{ display:flex; flex-wrap:wrap; gap:10px; background:#fff; padding:15px; border-radius:10px; box-shadow:0 2px 6px rgba(0,0,0,.08); margin-bottom:20px; }
        .filtros input, .filtros select, .filtros button{ padding:8px; border-radius:6px; border:1px solid #CCC; flex:1; min-width:150px; }
        .filtros button{ background:#f57c00; color:white; font-weight:bold; cursor:pointer; flex:0; }
        .grid-container{ display:grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:15px; }
        .card{ background:white; border-radius:12px; padding:15px; box-shadow:0 5px 12px rgba(0,0,0,.08); border-top:5px solid #f57c00; }
        .card-header{ font-size:13px; font-weight:bold; border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:10px; }
        
        /* Ajuste de columna para permitir envolver texto */
        .table-grid { display: grid; grid-template-columns: 30px minmax(0, 1fr) 50px 50px 90px; gap: 5px; align-items: center; font-size: 14px; }
        
        .item-row { padding: 8px 0; border-bottom: 1px solid #f4f4f4; }
        .resaltar-cero { background-color: #ffebee; }
        .row-total { font-weight: bold; border-top: 2px solid #ddd; padding-top: 10px; margin-top: 5px; color: #2e7d32; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        @media (max-width: 480px) { 
            .grid-container { display: flex; flex-direction: column; gap: 20px; } 
            .card { width: 100%; margin-bottom: 10px; }
        }
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
        <div class="grid-container">
            <?php foreach($pedidos as $nro => $d): 
                $totalCajas = 0; $totalUnidades = 0;
            ?>
            <div class="card">
                <div class="card-header">Doc: <?= $nro ?> | <?= $d['SUCURSAL'] ?> | <?= $d['FACTURADOR'] ?> | Hora: <?= $d['HORA'] ?></div>
                <div class="table-grid" style="font-weight:bold; background:#f9f9f9; padding:5px;">
                    <span></span><span>Prod</span><span class="text-center">Cjs</span><span class="text-center">Und</span><span class="text-right">Val</span>
                </div>
                <?php foreach($d['ITEMS'] as $idx => $i): 
                    $totalCajas += $i['C']; $totalUnidades += $i['U'];
                    $claseCero = ($i['VAL'] == 0) ? 'resaltar-cero' : '';
                ?>
                    <div class="table-grid item-row <?= $claseCero ?>">
                        <input type="checkbox" name="audit[]" value="<?= $nro ?>_<?= $idx ?>">
                        <span style="word-wrap: break-word; overflow-wrap: break-word;">
                            <?= htmlspecialchars($i['PROD']) ?>
                        </span>
                        <span class="text-center"><?= $i['C'] ?></span>
                        <span class="text-center"><?= $i['U'] ?></span>
                        <span class="text-right">$<?= number_format($i['VAL'],0) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="table-grid row-total">
                    <span></span><span class="text-right">TOTAL:</span>
                    <span class="text-center"><?= $totalCajas ?></span>
                    <span class="text-center"><?= $totalUnidades ?></span>
                    <span class="text-right">$<?= number_format($d['TOTAL'], 0) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </form>
</body>
</html>