<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual = date('Y');
$mesActualNum = date('m');
$empresaFiltro = $_GET['idempresa'] ?? ''; 

/* =====================================================
    1. FUNCIONES DE EXTRACCIÓN
===================================================== */

function obtenerEmpresas($dbWeb) {
    $emps = [];
    $sql = "SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) $emps[$row['IdEmpresa']] = $row['Nombre'];
    return $emps;
}

function obtenerSkusPorEmpresa($dbWeb, $idEmpresa) {
    $skus = [];
    if(empty($idEmpresa)) return $skus;
    $sql = "SELECT cp.sku FROM catproductos cp INNER JOIN categorias c ON cp.codcat = c.codcat WHERE c.idempresa = '$idEmpresa'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) $skus[] = "'".trim($row['sku'])."'";
    return array_unique($skus);
}

function obtenerStockReal($db, $listaSkus) {
    if(empty($listaSkus)) return 0;
    $inClause = implode(",", $listaSkus);
    $sql = "SELECT SUM(I.cantidad) as stock FROM inventario I INNER JOIN productos P ON I.idproducto = P.idproducto WHERE P.barcode IN ($inClause) AND I.idproducto > 0";
    $r = $db->query($sql);
    $row = $r->fetch_assoc();
    return (float)($row['stock'] ?? 0);
}

function obtenerMovimientoValorizado($db, $anio, $listaSkus) {
    $meses = [];
    for($i=1; $i<=12; $i++) {
        $m = str_pad($i, 2, "0", STR_PAD_LEFT);
        $meses[$m] = ['cant' => 0, 'pesos' => 0];
    }
    if(empty($listaSkus)) return $meses;
    $inClause = implode(",", $listaSkus);

    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant, SUM(D.CANTIDAD * PR.precioventa) as subtotal
               FROM FACTURAS F
               INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
               WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rf = $db->query($sqlFac);
    while($rf && $row = $rf->fetch_assoc()) {
        $meses[$row['mes']]['cant'] += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
    }

    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, SUM(D.CANTIDAD) as cant, SUM(D.CANTIDAD * PR.precioventa) as subtotal
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()) {
        $meses[$row['mes']]['cant'] += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
    }
    return $meses;
}

function calcularMetricas($data, $stock) {
    $sumC = 0; $sumP = 0; $mesesActivos = 0;
    foreach($data as $v) { 
        if($v['cant'] > 0) { $sumC += $v['cant']; $sumP += $v['pesos']; $mesesActivos++; } 
    }
    $promC = ($mesesActivos > 0) ? ($sumC / $mesesActivos) : 0;
    $promP = ($mesesActivos > 0) ? ($sumP / $mesesActivos) : 0;
    $ventaDiaria = $promC / 30;
    $dias = ($ventaDiaria > 0) ? ($stock / $ventaDiaria) : 0;
    return ['promCant' => $promC, 'promPesos' => $promP, 'dias' => $dias, 'totCant' => $sumC, 'totPesos' => $sumP];
}

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n];
}

/* =====================================================
    2. PROCESAMIENTO INICIAL
===================================================== */
$listaEmpresas = obtenerEmpresas($mysqli);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Gerencial Valorizado</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:20px;}
        .container{max-width:1450px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; justify-content: space-between;}
        .card{background:#fff; padding:25px; border-radius:15px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 6px solid #1a237e; margin-bottom: 25px;}
        select{padding:12px; border-radius:10px; border:1px solid #ddd; width:400px; font-size:16px; font-weight: bold; color: #1a237e;}
        .chart-box{height: 280px; margin-bottom: 20px;}
        table{width:100%; border-collapse:collapse;}
        th{background:#f8f9fa; padding:10px; text-align:right; font-size:10px; color:#888; border-bottom:2px solid #eee; text-transform: uppercase;}
        td{padding:10px; border-bottom:1px solid #f1f1f1; text-align:right; font-weight:600; font-size: 13px;}
        .val-pesos{ color: #2e7d32; display: block; font-size: 11px; font-weight: bold;}
        .bg-total{background:#e8f5e9; color:#2e7d32;}
        .bg-prom{background:#e3f2fd; color:#1565c0;}
        .bg-dias{background:#fff3e0; color:#e65100;}
        .stock-tag{background: #1a237e; color: #fff; padding: 6px 18px; border-radius: 20px; font-size: 14px; font-weight: bold;}
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0; color:#1a237e;">📈 Dashboard de Ventas por Empresa</h2>
            <small>Consolidado: Central de Bebidas & Drinks Depot</small>
        </div>
        <form method="GET">
            <select name="idempresa" onchange="this.form.submit()">
                <option value="">-- Seleccione para detalle --</option>
                <?php foreach($listaEmpresas as $id => $nom): ?>
                    <option value="<?= $id ?>" <?= $empresaFiltro == $id ? 'selected' : '' ?>><?= strtoupper($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if($empresaFiltro): 
        $skusEmpresa = obtenerSkusPorEmpresa($mysqli, $empresaFiltro);
        $dataC = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusEmpresa);
        $stockC = obtenerStockReal($mysqliCentral, $skusEmpresa);
        $metC = calcularMetricas($dataC, $stockC);

        $dataD = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusEmpresa);
        $stockD = obtenerStockReal($mysqliPos, $skusEmpresa);
        $metD = calcularMetricas($dataD, $stockD);

        $dataGlobal = [];
        foreach($dataC as $m => $v) $dataGlobal[$m] = ['cant' => $v['cant'] + $dataD[$m]['cant'], 'pesos' => $v['pesos'] + $dataD[$m]['pesos']];
        $metGlobal = calcularMetricas($dataGlobal, $stockC + $stockD);

        $sedes = [
            ['id' => 'global', 'nombre' => '🌎 Total: ' . $listaEmpresas[$empresaFiltro], 'data' => $dataGlobal, 'met' => $metGlobal, 'stock' => ($stockC + $stockD), 'color' => '#2e7d32'],
            ['id' => 'central', 'nombre' => '🏢 Sede Central', 'data' => $dataC, 'met' => $metC, 'stock' => $stockC, 'color' => '#0d47a1'],
            ['id' => 'drinks', 'nombre' => '🍹 Drinks Depot', 'data' => $dataD, 'met' => $metD, 'stock' => $stockD, 'color' => '#6a1b9a']
        ];

        foreach($sedes as $s): ?>
        <div class="card" style="border-top-color: <?= $s['color'] ?>;">
            <div style="display:flex; justify-content:space-between; align-items: center; margin-bottom:10px;">
                <h3 style="margin:0; color:<?= $s['color'] ?>;"><?= $s['nombre'] ?></h3>
                <span class="stock-tag">Stock: <?= number_format($s['stock'], 0) ?></span>
            </div>
            <div class="chart-box"><canvas id="c_<?= $s['id'] ?>"></canvas></div>
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left">Mes</th>
                        <?php foreach($s['data'] as $m => $v): ?> <th><?= substr(nombreMes($m),0,3) ?></th> <?php endforeach; ?>
                        <th class="bg-total">TOTAL</th>
                        <th class="bg-dias">DÍAS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="text-align:left">Ventas</td>
                        <?php foreach($s['data'] as $v): ?> 
                            <td><?= number_format($v['cant'],0) ?><span class="val-pesos">$<?= number_format($v['pesos'],0) ?></span></td> 
                        <?php endforeach; ?>
                        <td class="bg-total"><?= number_format($s['met']['totCant'],0) ?><span class="val-pesos">$<?= number_format($s['met']['totPesos'],0) ?></span></td>
                        <td class="bg-dias"><?= number_format($s['met']['dias'],1) ?>d</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <script>
        new Chart(document.getElementById('c_<?= $s['id'] ?>'), {
            type: 'bar',
            data: {
                labels: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
                datasets: [{ label: 'Pesos ($)', data: <?= json_encode(array_values(array_column($s['data'], 'pesos'))) ?>, backgroundColor: '<?= $s['color'] ?>cc', borderRadius: 5 }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } } } }
        });
        </script>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    $labelsEmps = []; $pesosEmps = []; $cantEmps = [];
    foreach($listaEmpresas as $idE => $nomE) {
        $skusE = obtenerSkusPorEmpresa($mysqli, $idE);
        if(empty($skusE)) continue;
        $vC = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusE);
        $vD = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusE);
        $pMes = $vC[$mesActualNum]['pesos'] + $vD[$mesActualNum]['pesos'];
        $cMes = $vC[$mesActualNum]['cant'] + $vD[$mesActualNum]['cant'];
        if($pMes > 0) { $labelsEmps[] = $nomE; $pesosEmps[] = $pMes; $cantEmps[] = $cMes; }
    }
    ?>

    <div class="card" style="border-top: 6px solid #ff5722; margin-top: 40px; background: #fffcfb;">
        <h2 style="text-align:center; color:#d84315;">📊 Participación por Empresa - <?= nombreMes($mesActualNum) ?></h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 20px;">
            <div style="height: 350px;">
                <h4 style="text-align:center;">Ventas en Pesos ($)</h4>
                <canvas id="finalPesos"></canvas>
            </div>
            <div style="height: 350px;">
                <h4 style="text-align:center;">Volumen en Unidades</h4>
                <canvas id="finalCant"></canvas>
            </div>
        </div>
    </div>

    <script>
    const colors = ['#1a237e','#2e7d32','#c62828','#f9a825','#6a1b9a','#00838f','#ef6c00','#4e342e'];
    new Chart(document.getElementById('finalPesos'), {
        type: 'pie',
        data: { labels: <?= json_encode($labelsEmps) ?>, datasets: [{ data: <?= json_encode($pesosEmps) ?>, backgroundColor: colors }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });
    new Chart(document.getElementById('finalCant'), {
        type: 'bar',
        data: { labels: <?= json_encode($labelsEmps) ?>, datasets: [{ label: 'Unidades', data: <?= json_encode($cantEmps) ?>, backgroundColor: '#ff5722cc' }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
    </script>
</div>
</body>
</html>