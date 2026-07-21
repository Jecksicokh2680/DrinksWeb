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
        if ($dbWeb->query("UPDATE familias SET SegWebT = '0'")) {
            $msgAccion = "🧹 TODAS las familias han sido puestas en 0 (Inactivas).";
            $accionManual = true;
        }
    }
    if (isset($_POST['set_1'])) {
        if ($dbWeb->query("UPDATE familias SET SegWebT = '1'")) {
            $msgAccion = "🚀 TODAS las familias han sido puestas en 1 (Activas).";
            $accionManual = true;
        }
    }
}

/* =====================================================
    2. FUNCIONES DE DATOS (ADAPTADAS A FAMILIAS)
===================================================== */

function cargarMapeoCompleto($dbWeb){
    $map = [];
    // Relacionamos los productos directamente con la tabla familias mediante familia_id
    $sql = "SELECT p.barcode, f.id AS familia_id, f.nombre AS familia
            FROM productos p
            INNER JOIN familias f ON f.id = p.familia_id
            WHERE p.barcode IS NOT NULL AND p.barcode != ''";
    $res = $dbWeb->query($sql);
    while($res && $r = $res->fetch_assoc()){
        $map[trim($r['barcode'])] = [
            'familia_id' => $r['familia_id'],
            'familia'    => trim($r['familia'])
        ];
    }
    return $map;
}

function obtenerProductosHoy($db){
    $hoy = date('Ymd');
    $out = [];
    $totalVentaGlobal = 0; // Para calcular participación

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
    while($r && $row = $r->fetch_assoc()){ 
        $out[$row['barcode']] = $row; 
        $totalVentaGlobal += $row['total'];
    }

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
        $totalVentaGlobal += $row['total'];
        if(isset($out[$row['barcode']])){
            $out[$row['barcode']]['cant']  += $row['cant'];
            $out[$row['barcode']]['total'] += $row['total'];
        } else { $out[$row['barcode']] = $row; }
    }
    return ['lista' => array_values($out), 'global' => $totalVentaGlobal];
}

function obtenerTop($arr, $limite = 25){
    usort($arr, fn($a,$b)=>$b['total'] <=> $a['total']);
    return array_slice($arr, 0, $limite);
}

function money($v){ return number_format($v/1000, 0, ',', '.'); }

/* =====================================================
    3. PROCESAMIENTO
===================================================== */
$mapeo = cargarMapeoCompleto($dbWeb);

$resC = obtenerProductosHoy($dbCentral);
$topCentral = obtenerTop($resC['lista']);
$globalC = $resC['global'];

$resD = obtenerProductosHoy($dbDrinks);
$topDrinks  = obtenerTop($resD['lista']);
$globalD = $resD['global'];

// Logica de Actualización Automática (SegWebT en familias)
$logUpdate = "";
if (!$accionManual) {
    $familiasParaActualizar = [];
    foreach (array_merge($topCentral, $topDrinks) as $p) {
        if (isset($mapeo[$p['barcode']])) {
            $familiasParaActualizar[] = $mapeo[$p['barcode']]['familia_id'];
        }
    }
    $familiasParaActualizar = array_unique($familiasParaActualizar);

    if (!empty($familiasParaActualizar)) {
        $dbWeb->query("UPDATE familias SET SegWebT = '0'");
        $listaIds = implode(",", $familiasParaActualizar);
        $dbWeb->query("UPDATE familias SET SegWebT = '1' WHERE id IN ($listaIds)");
        $logUpdate = "✅ Sincronización automática: " . count($familiasParaActualizar) . " familias del Top actualizadas.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Sincronizador Top Ventas por Familia</title>
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
        /* Estilos Participación */
        .part-badge{background:#fff3e0; color:#e65100; padding:2px 6px; border-radius:4px; font-weight:bold; font-size:10px;}
        .stats-summary{display:flex; justify-content:space-between; background:#f8f9fa; padding:10px; border-radius:8px; margin-bottom:10px; border:1px solid #dee2e6;}
        .stat-val{font-size:14px; color:#1a237e; font-weight:bold;}
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>🏆 Panel de Control: Top 25 por Familia</h2>
        <div class="actions-group">
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las familias en 1?')">
                <button type="submit" name="set_1" class="btn btn-green">🚀 ACTIVAR TODAS (SET 1)</button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las familias en 0?')">
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
                $percC = ($globalC > 0) ? ($sumC / $globalC) * 100 : 0;
            ?>
            <div class="stats-summary">
                <div>Venta Total Hoy: <span class="stat-val">$ <?= money($globalC) ?>k</span></div>
                <div>Participación Top 25: <span class="stat-val"><?= number_format($percC, 1) ?>%</span></div>
            </div>
            <div class="footer-info">Items Top: <?= count($topCentral) ?> | Cant: <?= $cantC ?> | Vol Top: $ <?= money($sumC) ?>k</div>
            <table>
                <thead>
                    <tr><th>Producto</th><th>Cant</th><th>Total</th><th>%</th></tr>
                </thead>
                <tbody>
                    <?php foreach($topCentral as $p): 
                        $info = $mapeo[$p['barcode']] ?? ['familia'=>'SIN FAMILIA'];
                        $p_perc = ($globalC > 0) ? ($p['total'] / $globalC) * 100 : 0;
                    ?>
                    <tr>
                        <td><strong><?= $p['producto'] ?></strong><br><small>📁 <?= $info['familia'] ?></small></td>
                        <td class="cant"><?= $p['cant'] ?></td>
                        <td class="monto">$ <?= money($p['total']) ?></td>
                        <td><span class="part-badge"><?= number_format($p_perc, 1) ?>%</span></td>
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
                $percD = ($globalD > 0) ? ($sumD / $globalD) * 100 : 0;
            ?>
            <div class="stats-summary">
                <div>Venta Total Hoy: <span class="stat-val">$ <?= money($globalD) ?>k</span></div>
                <div>Participación Top 25: <span class="stat-val"><?= number_format($percD, 1) ?>%</span></div>
            </div>
            <div class="footer-info">Items Top: <?= count($topDrinks) ?> | Cant: <?= $cantD ?> | Vol Top: $ <?= money($sumD) ?>k</div>
            <table>
                <thead>
                    <tr><th>Producto</th><th>Cant</th><th>Total</th><th>%</th></tr>
                </thead>
                <tbody>
                    <?php foreach($topDrinks as $p): 
                        $info = $mapeo[$p['barcode']] ?? ['familia'=>'SIN FAMILIA'];
                        $p_perc = ($globalD > 0) ? ($p['total'] / $globalD) * 100 : 0;
                    ?>
                    <tr>
                        <td><strong><?= $p['producto'] ?></strong><br><small>📁 <?= $info['familia'] ?></small></td>
                        <td class="cant"><?= $p['cant'] ?></td>
                        <td class="monto">$ <?= money($p['total']) ?></td>
                        <td><span class="part-badge"><?= number_format($p_perc, 1) ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>