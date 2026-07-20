<?php
require('ConnCentral.php');
require('ConnDrinks.php');
require('Conexion.php');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$UsuarioSesion = $_SESSION['Usuario'] ?? '';

if (!$UsuarioSesion) {
    echo '<script>
        window.close();
        // Por si el navegador bloquea el cierre de ventanas que no fueron abiertas por un script:
        setTimeout(function() {
            document.body.innerHTML = "<h2 style=\'text-align:center; font-family:sans-serif; margin-top:20vh;\'>Sesión no iniciada. Puedes cerrar esta pestaña.</h2>";
        }, 100);
    </script>';
    exit;
}

function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];
    $extraCond = ($busqProd != "") ? " AND (PRODUCTOS.Descripcion LIKE '%".$cnx->real_escape_string($busqProd)."%' OR PRODUCTOS.Barcode LIKE '%".$cnx->real_escape_string($busqProd)."%') " : "";
    $condFactura = $extraCond . ($f_fac != "" ? " AND T1.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");
    $condPedido  = $extraCond . ($f_fac != "" ? " AND T2.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");

    $sql = "SELECT '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR, CLI.nombres AS CLIENTE, FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD 
            FROM FACTURAS 
            INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA 
            INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO 
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR 
            INNER JOIN TERCEROS CLI ON CLI.IDTERCERO=FACTURAS.IDTERCERO
            WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura 
            UNION ALL 
            SELECT '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR, CLI.nombres AS CLIENTE, PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD 
            FROM PEDIDOS 
            INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO 
            INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO 
            INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO 
            INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO 
            INNER JOIN TERCEROS CLI ON CLI.IDTERCERO=PEDIDOS.IDTERCERO
            WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido 
            ORDER BY FECHA DESC, HORA DESC, DOCUMENTO DESC";

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

$auditados = [];
$estados_doc = [];
$res_audit = $mysqli->query("SELECT *, CONCAT(TRIM(sede), '|', TRIM(nro_pedido), '|', TRIM(barcode), '|', TRIM(facturador)) as llave FROM auditoria_Pedido");
while($row = $res_audit->fetch_assoc()) { 
    if($row['estado_check'] == 1) $auditados[$row['llave']] = $row['usuario_verificador'];
    if($row['estado_pedido'] != '' && $row['estado_pedido'] != null) $estados_doc[$row['sede'].'|'.$row['nro_pedido']] = $row['estado_pedido'];
}

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
    if (!isset($pedidos[$doc])) $pedidos[$doc] = ['SUCURSAL'=>$r['SUCURSAL'], 'FACTURADOR'=>$r['FACTURADOR'], 'CLIENTE'=>$r['CLIENTE'], 'HORA'=>$r['HORA'], 'ITEMS'=>[], 'TOTAL'=>0, 'AUDITORES'=>[]];
    $llave = trim($r['SUCURSAL']) . '|' . trim($doc) . '|' . trim($r['Barcode']) . '|' . trim($r['FACTURADOR']);
    
    if (isset($auditados[$llave])) $pedidos[$doc]['AUDITORES'][$auditados[$llave]] = true;

    $uni = $unicaja[$r['Barcode']] ?? 1;
    $cajas = floor($r['CANTIDAD']);
    $unds = round(($r['CANTIDAD'] - $cajas) * $uni);
    $valorTotalItem = $r['CANTIDAD'] * $r['VALORPROD'];
    $pedidos[$doc]['ITEMS'][] = ['PROD'=>$r['PRODUCTO'], 'BARCODE'=>$r['Barcode'], 'C'=>$cajas, 'U'=>$unds, 'VAL'=>$valorTotalItem, 'LLAVE'=>$llave];
    $pedidos[$doc]['TOTAL'] += $valorTotalItem;
}

foreach ($pedidos as &$p) {
    usort($p['ITEMS'], function($a, $b) { return strcmp($a['PROD'], $b['PROD']); });
}
unset($p);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
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
        .table-grid { display: grid; grid-template-columns: 30px minmax(0, 1fr) 50px 50px 90px; gap: 5px; align-items: center; font-size: 14px; }
        .item-row { padding: 8px 0; border-bottom: 1px solid #f4f4f4; }
        .auditado { background-color: #e8f5e9 !important; }
        .resaltar-cero { background-color: #ffebee !important; color: #c62828 !important; font-weight: bold !important; }
        .row-total { font-weight: bold; border-top: 2px solid #ddd; padding-top: 10px; margin-top: 5px; color: #2e7d32; }
        .text-right { text-align: right; } .text-center { text-align: center; }
        .msg-auditor { color: #2e7d32; font-size: 11px; margin-top:5px; font-weight:bold; }
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
    
    <div class="grid-container">
        <?php foreach($pedidos as $nro => $d): 
            $totalCajas = 0; $totalUnidades = 0;
            $est = $estados_doc[$d['SUCURSAL'].'|'.$nro] ?? 'pendiente';
        ?>
        <div class="card">
            <div class="card-header">
                Doc: <?= $nro ?> | <?= $d['SUCURSAL'] ?> <br>
                Vendedor: <?= $d['FACTURADOR'] ?> | Cliente: <strong><?= htmlspecialchars($d['CLIENTE']) ?></strong><br>
                Hora: <?= $d['HORA'] ?>
                <div style="margin:8px 0;">
                    <button class="btn-est" onclick="cambiarEstado(this, '<?= $d['SUCURSAL'] ?>', '<?= $nro ?>', 'entregado')" style="background:<?= $est=='entregado'?'#2e7d32':'#ccc' ?>; border:none; padding:3px 8px; color:white; cursor:pointer; border-radius:4px;">Entregado</button>
                    <button class="btn-est" onclick="cambiarEstado(this, '<?= $d['SUCURSAL'] ?>', '<?= $nro ?>', 'Para anular')" style="background:<?= $est=='Para anular'?'#c62828':'#ccc' ?>; border:none; padding:3px 8px; color:white; cursor:pointer; border-radius:4px;">Para Anular</button>
                </div>
                <div class="msg-auditor"><?php if (!empty($d['AUDITORES'])): ?>✓ Auditado por: <?= implode(', ', array_keys($d['AUDITORES'])) ?><?php endif; ?></div>
            </div>
            <div class="table-grid" style="font-weight:bold; background:#f9f9f9; padding:5px;">
                <span></span><span>Prod</span><span class="text-center">Cjs</span><span class="text-center">Und</span><span class="text-right">Val</span>
            </div>
            <?php foreach($d['ITEMS'] as $i): 
                $totalCajas += $i['C']; $totalUnidades += $i['U'];
                $isChecked = isset($auditados[$i['LLAVE']]);
                $claseCero = ($i['VAL'] <= 100) ? 'resaltar-cero' : '';
            ?>
            <div class="table-grid item-row <?= $isChecked ? 'auditado' : '' ?> <?= $claseCero ?>">
                <input type="checkbox" class="check-auditoria" data-item="<?= $i['LLAVE'] ?>" data-cant="<?= ($i['C'] + ($i['U']/100)) ?>" <?= $isChecked ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($i['PROD']) ?></span>
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

    <script>
    document.querySelectorAll('.check-auditoria').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = this.closest('.card');
            const msgAuditor = card.querySelector('.msg-auditor');
            fetch('procesar_auditoria.php', { 
                method: 'POST', 
                body: new URLSearchParams({'item': this.dataset.item, 'estado': this.checked ? 1 : 0, 'cantidad': this.dataset.cant}) 
            })
            .then(r => r.text())
            .then(user => {
                this.closest('.item-row').classList.toggle('auditado', this.checked);
                msgAuditor.innerText = this.checked ? "✓ Auditado por: " + user.trim() : "";
            });
        });
    });

    function cambiarEstado(btn, sede, nro, estado) {
        fetch('procesar_auditoria.php', {
            method: 'POST',
            body: new URLSearchParams({'tipo': 'estado_pedido', 'sede': sede, 'nro': nro, 'estado': estado})
        }).then(() => {
            btn.parentElement.querySelectorAll('.btn-est').forEach(b => b.style.background = '#ccc');
            btn.style.background = (estado === 'entregado') ? '#2e7d32' : '#c62828';
        });
    }
    </script>
</body>
</html>