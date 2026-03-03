<?php
session_start();
date_default_timezone_set('America/Bogota');

// Incluimos las conexiones
include_once 'ConnCentral.php';
include_once 'ConnDrinks.php';

$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');
$sedeFiltro  = $_GET['sede']  ?? 'TODAS'; 
$fSQL = date('Ymd', strtotime($fechaFiltro));

// Mapeamos las conexiones recibidas de los archivos incluidos
$todasLasSedes = [
    'CENTRAL' => $mysqliCentral,
    'DRINKS'  => $mysqliDrinks
];

function moneda($v) { return '$' . number_format($v, 0, ',', '.'); }

/**
 * Función para detectar números faltantes en una secuencia
 */
function detectarSaltos($numeros) {
    if (count($numeros) < 2) return [];
    $soloNumeros = array_map(function($n) {
        return (int)preg_replace('/[^0-9]/', '', $n);
    }, $numeros);
    $min = min($soloNumeros);
    $max = max($soloNumeros);
    $rangoCompleto = range($min, $max);
    return array_diff($rangoCompleto, $soloNumeros);
}

/* ============================================================
   LÓGICA DE EXTRACCIÓN
============================================================ */
$reporteGlobal = [];
$erroresConexion = [];
$consecutivos = []; 

foreach ($todasLasSedes as $nombre => $db) {
    if ($sedeFiltro !== 'TODAS' && $sedeFiltro !== $nombre) continue;

    if (!$db || $db->connect_error) {
        $erroresConexion[$nombre] = "No se pudo conectar a $nombre (Puerto " . ($nombre=='CENTRAL'?3307:3308) . ")";
        continue;
    }

    $sql = "SELECT T1.NOMBRES AS FACTURADOR, 'FACTURA' AS TIPO_DOC, F.NUMERO, F.HORA, T2.NOMBRES AS CLIENTE, F.VALORTOTAL
            FROM FACTURAS F
            INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
            INNER JOIN TERCEROS T2 ON T2.IDTERCERO = F.IDTERCERO
            WHERE F.ESTADO = '0' AND F.FECHA = '$fSQL'
            UNION ALL
            SELECT V.NOMBRES AS FACTURADOR, 'PEDIDO' AS TIPO_DOC, P.NUMERO, P.HORA, C.NOMBRES AS CLIENTE, P.VALORTOTAL
            FROM PEDIDOS P
            INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO
            INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO
            INNER JOIN TERCEROS C ON C.IDTERCERO = P.IDVENDEDOR
            WHERE P.ESTADO = '0' AND P.FECHA = '$fSQL'
            ORDER BY NUMERO ASC";

    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $reporteGlobal[$nombre][$row['FACTURADOR']][$row['TIPO_DOC']][] = $row;
            $consecutivos[$nombre][$row['TIPO_DOC']][] = $row['NUMERO'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de Ventas y Consecutivos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .badge-salto { background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; margin-right: 4px; font-size: 0.8rem; }
        .vendedor-header { background: #e9ecef; color: #495057; font-weight: bold; padding: 10px; border-left: 5px solid #0d6efd; margin-top: 20px; }
        .resumen-total-vendedor { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 10px; margin-top: 5px; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">📊 Auditoría de Ventas</span>
        <form class="d-flex gap-2">
            <input type="date" name="fecha" class="form-control form-control-sm" value="<?= $fechaFiltro ?>" onchange="this.form.submit()">
            <select name="sede" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="TODAS" <?= $sedeFiltro=='TODAS'?'selected':'' ?>>Ambas Sedes</option>
                <option value="CENTRAL" <?= $sedeFiltro=='CENTRAL'?'selected':'' ?>>Central</option>
                <option value="DRINKS" <?= $sedeFiltro=='DRINKS'?'selected':'' ?>>Drinks</option>
            </select>
        </form>
    </div>
</nav>

<div class="container-fluid">
    <?php foreach ($erroresConexion as $sedeErr => $msg): ?>
        <div class="alert alert-danger shadow-sm">⚠️ <strong><?= $sedeErr ?>:</strong> <?= $msg ?></div>
    <?php endforeach; ?>

    <?php foreach ($reporteGlobal as $sede => $vendedores): ?>
        <div class="card mb-5 shadow-sm border-0">
            <div class="card-header bg-primary text-white"><h3>Sede: <?= $sede ?></h3></div>
            <div class="card-body">
                
                <div class="row mb-4">
                    <?php foreach (['FACTURA', 'PEDIDO'] as $tipoSalto): ?>
                        <div class="col-md-6">
                            <div class="p-2 border rounded bg-white">
                                <small class="fw-bold">Saltos en <?= $tipoSalto ?>s:</small>
                                <div class="mt-1">
                                    <?php 
                                    $faltantes = detectarSaltos($consecutivos[$sede][$tipoSalto] ?? []);
                                    if (empty($consecutivos[$sede][$tipoSalto])) echo '<span class="text-muted small">Sin datos</span>';
                                    elseif (empty($faltantes)) echo '<span class="text-success small">✅ Completo</span>';
                                    else foreach ($faltantes as $f) echo "<span class='badge-salto'>$f</span>";
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($vendedores as $vendedor => $tipos): 
                    $sumaFacturas = 0;
                    $sumaPedidos = 0;
                ?>
                    <div class="vendedor-header shadow-sm">👤 Facturador: <?= $vendedor ?></div>
                    <div class="row g-3 mt-1">
                        <?php foreach (['FACTURA', 'PEDIDO'] as $t): ?>
                            <div class="col-md-6">
                                <h6 class="fw-bold small <?= $t == 'FACTURA' ? 'text-success' : 'text-warning' ?>"><?= $t ?>S</h6>
                                <table class="table table-sm table-bordered bg-white" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr><th>Nro</th><th>Hora</th><th class="text-end">Valor</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (isset($tipos[$t])): foreach ($tipos[$t] as $d): 
                                            if ($t == 'FACTURA') $sumaFacturas += $d['VALORTOTAL'];
                                            else $sumaPedidos += $d['VALORTOTAL'];
                                        ?>
                                            <tr>
                                                <td><?= $d['NUMERO'] ?></td>
                                                <td><?= date('H:i', strtotime($d['HORA'])) ?></td>
                                                <td class="text-end"><?= moneda($d['VALORTOTAL']) ?></td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="3" class="text-center text-muted">Sin movimientos</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="2" class="text-end small">Subtotal <?= $t ?>:</td>
                                            <td class="text-end"><?= moneda($t == 'FACTURA' ? $sumaFacturas : $sumaPedidos) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="resumen-total-vendedor d-flex justify-content-end align-items-center gap-3">
                        <span class="text-muted small italic">Total (Facturas + Pedidos) de <strong><?= $vendedor ?></strong>:</span>
                        <h4 class="mb-0 text-dark fw-bold"><?= moneda($sumaFacturas + $sumaPedidos) ?></h4>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>