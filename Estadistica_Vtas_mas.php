<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* =====================================================
    1. CONEXIONES
===================================================== */
require("ConnCentral.php"); // $mysqliCentral
require("ConnDrinks.php");  // $mysqliPos
require("Conexion.php");    // $mysqli (Base de Datos Administrativa)

$dbCentral = $mysqliCentral;
$dbDrinks  = $mysqliPos;
$dbWeb     = $mysqli; 

/* =====================================================
    LÓGICA DE BOTONES (ACCIONES MANUALES)
===================================================== */
$msgAccion = "";
$accionManual = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['reset_0'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '0'")) {
            $msgAccion = "🧹 TODAS las categorías han sido puestas en 0 (Inactivas).";
            $accionManual = true;
        }
    }
    if (isset($_POST['set_1'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '1'")) {
            $msgAccion = "🚀 TODAS las categorías han sido puestas en 1 (Activas).";
            $accionManual = true;
        }
    }
}

/* =====================================================
    2. FUNCIONES DE DATOS
===================================================== */

function cargarMapeoCompleto($dbWeb){
    $map = [];
    $sql = "SELECT cp.sku, c.codcat, c.nombre AS categoria, e.Nombre AS empresa
            FROM catproductos cp
            INNER JOIN categorias c ON c.codcat = cp.codcat
            LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa";
    $res = $dbWeb->query($sql);
    while($res && $r = $res->fetch_assoc()){
        $map[trim($r['sku'])] = [
            'codcat'    => $r['codcat'],
            'categoria' => trim($r['categoria']),
            'empresa'   => trim($r['empresa'] ?? 'SIN EMPRESA')
        ];
    }
    return $map;
}

function obtenerProductosHoy($db){
    $hoy = date('Ymd');
    $out = [];
    $sql = "SELECT PR.barcode, PR.DESCRIPCION producto,
               round(SUM(D.CANTIDAD),1) cant,
               round(SUM(D.CANTIDAD * D.VALORPROD),1) total
        FROM FACTURAS F
        INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
        INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA = '$hoy'
        GROUP BY PR.barcode, PR.DESCRIPCION";
    $r = $db->query($sql);
    while($r && $row = $r->fetch_assoc()){ $out[$row['barcode']] = $row; }

    $sqlPed = "SELECT PR.barcode, PR.DESCRIPCION producto,
               round(SUM(D.CANTIDAD),1) cant,
               round(SUM(D.CANTIDAD * D.VALORPROD),1) total
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
        INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
        WHERE P.ESTADO='0' AND P.FECHA = '$hoy'
        GROUP BY PR.barcode, PR.DESCRIPCION";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()){
        if(isset($out[$row['barcode']])){
            $out[$row['barcode']]['cant']  += $row['cant'];
            $out[$row['barcode']]['total'] += $row['total'];
        } else { $out[$row['barcode']] = $row; }
    }
    return array_values($out);
}

function obtenerTop($arr, $limite = 40){
    usort($arr, fn($a,$b)=>$b['total'] <=> $a['total']);
    return array_slice($arr, 0, $limite);
}

function money($v){ return number_format($v/1000, 0, ',', '.'); }

/* =====================================================
    3. PROCESAMIENTO AUTOMÁTICO
===================================================== */
$mapeo = cargarMapeoCompleto($dbWeb);
$topCentral = obtenerTop(obtenerProductosHoy($dbCentral));
$topDrinks  = obtenerTop(obtenerProductosHoy($dbDrinks));

$logUpdate = "";
// Solo auto-actualiza si no se acaba de presionar un botón manual
if (!$accionManual) {
    $categoriasParaActualizar = [];
    foreach (array_merge($topCentral, $topDrinks) as $p) {
        if (isset($mapeo[$p['barcode']])) {
            $categoriasParaActualizar[] = $mapeo[$p['barcode']]['codcat'];
        }
    }
    $categoriasParaActualizar = array_unique($categoriasParaActualizar);

    if (!empty($categoriasParaActualizar)) {
        $dbWeb->query("UPDATE categorias SET SegWebT = '0'");
        $listaIds = "'" . implode("','", $categoriasParaActualizar) . "'";
        $dbWeb->query("UPDATE categorias SET SegWebT = '1' WHERE CodCat IN ($listaIds)");
        $logUpdate = "✅ Sincronización automática: " . count($categoriasParaActualizar) . " categorías del Top actualizadas.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Sincronizador Top Ventas</title>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:20px; font-size:12px;}
        .container{max-width:1200px; margin:auto; background:#fff; padding:20px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.1);}
        .header-flex{display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #eee; margin-bottom:15px; padding-bottom:10px;}
        .actions-group{display:flex; gap:10px;}
        .status-bar{background:#e3f2fd; padding:15px; border-radius:8px; border-left:5px solid #2196f3; margin-bottom:20px; font-weight:bold; color:#1565c0;}
        .alert-action{background:#e8f5e9; color:#2e7d32; padding:15px; border-radius:8px; border-left:5px solid #4caf50; margin-bottom:20px; font-weight:bold;}
        .grid{display:grid; grid-template-columns:1fr 1fr; gap:25px;}
        h2, h3{margin:0; color:#1a237e;}
        h3{color:#455a64; background:#cfd8dc; padding:8px; border-radius:4px; margin-bottom:10px;}
        table{width:100%; border-collapse:collapse;}
        th, td{padding:8px; text-align:left; border-bottom:1px solid #eee;}
        th{background:#263238; color:#fff; font-size:11px; text-transform:uppercase;}
        .monto{text-align:right; font-weight:bold; color:#2e7d32;}
        .cant{text-align:center; background:#f9f9f9;}
        .btn{color:white; border:none; padding:10px 15px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:11px; transition:0.3s;}
        .btn-red{background:#d32f2f;} .btn-red:hover{background:#b71c1c;}
        .btn-green{background:#2e7d32;} .btn-green:hover{background:#1b5e20;}
        .footer-info{margin:8px 0; font-size:13px; color:#555;}
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>🏆 Panel de Control: Top 40</h2>
        <div class="actions-group">
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las categorías en 1?')">
                <button type="submit" name="set_1" class="btn btn-green">🚀 ACTIVAR TODAS (SET 1)</button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las categorías en 0?')">
                <button type="submit" name="reset_0" class="btn btn-red">🗑️ RESETEAR TODAS (SET 0)</button>
            </form>
        </div>
    </div>
    
    <?php if($msgAccion): ?>
        <div class="alert-action"><?= $msgAccion ?></div>
    <?php endif; ?>

    <div class="status-bar">
        <?= $logUpdate ?: "ℹ️ Esperando acción o actualización automática..." ?>
    </div>

    <div class="grid">
        <div>
            <h3>🏢 SEDE CENTRAL</h3>
            <?php 
                $sumC = array_sum(array_column($topCentral, 'total')); 
                $cantC = array_sum(array_column($topCentral, 'cant'));
            ?>
            <div class="footer-info">Items: <?= count($topCentral) ?> | Cant: <?= $cantC ?> | Vol: $ <?= money($sumC) ?>k</div>
            <table>
                <thead>
                    <tr><th>Categoría</th><th>Producto</th><th>Cant</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach($topCentral as $p): 
                        $info = $mapeo[$p['barcode']] ?? ['categoria'=>'N/A','empresa'=>'N/A'];
                    ?>
                    <tr>
                        <td><strong><?= $info['categoria'] ?></strong><br><small><?= $info['empresa'] ?></small></td>
                        <td><?= $p['producto'] ?></td>
                        <td class="cant"><?= $p['cant'] ?></td>
                        <td class="monto">$ <?= money($p['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div>
            <h3>🍹 SEDE DRINKS</h3>
            <?php 
                $sumD = array_sum(array_column($topDrinks, 'total')); 
                $cantD = array_sum(array_column($topDrinks, 'cant'));
            ?>
            <div class="footer-info">Items: <?= count($topDrinks) ?> | Cant: <?= $cantD ?> | Vol: $ <?= money($sumD) ?>k</div>
            <table>
                <thead>
                    <tr><th>Categoría</th><th>Producto</th><th>Cant</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach($topDrinks as $p): 
                        $info = $mapeo[$p['barcode']] ?? ['categoria'=>'N/A','empresa'=>'N/A'];
                    ?>
                    <tr>
                        <td><strong><?= $info['categoria'] ?></strong><br><small><?= $info['empresa'] ?></small></td>
                        <td><?= $p['producto'] ?></td>
                        <td class="cant"><?= $p['cant'] ?></td>
                        <td class="monto">$ <?= money($p['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>