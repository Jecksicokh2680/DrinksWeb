<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require("ConnCentral.php"); 
require("ConnDrinks.php");  
require("Conexion.php");    

$anioActual = date('Y');
$mesActualNum = date('m');
$sedeFiltro = $_GET['idsede'] ?? 'todos'; 

/* =====================================================
    1. FUNCIONES DE EXTRACCIÓN
===================================================== */

function obtenerFamilias($dbWeb) {
    $familias = [];
    $sql = "SELECT f.id, f.nombre 
            FROM familias f 
            INNER JOIN categorias c ON f.id = c.Tipo 
            GROUP BY f.id, f.nombre 
            ORDER BY f.nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $familias[$row['id']] = $row['nombre'];
    }
    return $familias;
}

function obtenerSkusPorFamilia($dbWeb, $idFamilia) {
    $skus = [];
    $sql = "SELECT cp.sku 
            FROM catproductos cp 
            INNER JOIN categorias c ON cp.codcat = c.codcat 
            WHERE c.Tipo = '$idFamilia'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $skus[] = "'".trim($row['sku'])."'";
    }
    return array_unique($skus);
}

function obtenerSkusPorCategoria($dbWeb, $codcat) {
    $skus = [];
    $sql = "SELECT sku FROM catproductos WHERE codcat = '$codcat'";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $skus[] = "'".trim($row['sku'])."'";
    }
    return array_unique($skus);
}

function obtenerCategoriasPorFamilia($dbWeb, $idFamilia) {
    $categorias = [];
    $sql = "SELECT codcat, nombre FROM categorias WHERE Tipo = '$idFamilia' ORDER BY nombre ASC";
    $r = $dbWeb->query($sql);
    while($r && $row = $r->fetch_assoc()) {
        $categorias[$row['codcat']] = $row['nombre'];
    }
    return $categorias;
}

function obtenerMovimientoValorizado($db, $anio, $listaSkus) {
    $meses = [];
    for($i=1; $i<=12; $i++) {
        $m = str_pad($i, 2, "0", STR_PAD_LEFT);
        $meses[$m] = ['cant' => 0, 'pesos' => 0, 'costo' => 0];
    }
    if(empty($listaSkus)) return $meses;
    $inClause = implode(",", $listaSkus);

    $sqlFac = "SELECT SUBSTRING(F.FECHA, 5, 2) as mes, 
                      SUM(D.CANTIDAD) as cant, 
                      SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                      SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
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
        $meses[$row['mes']]['costo'] += (float)($row['totalcosto'] ?? 0);
    }

    $sqlPed = "SELECT SUBSTRING(P.FECHA, 5, 2) as mes, 
                      SUM(D.CANTIDAD) as cant, 
                      SUM(D.CANTIDAD * PR.precioventa) as subtotal,
                      SUM(D.CANTIDAD * COALESCE(PR.costo, 0)) as totalcosto
               FROM PEDIDOS P
               INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
               INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
               WHERE P.ESTADO='0' AND P.FECHA LIKE '$anio%' AND PR.barcode IN ($inClause)
               GROUP BY mes";
    $rp = $db->query($sqlPed);
    while($rp && $row = $rp->fetch_assoc()) {
        $meses[$row['mes']]['cant'] += (float)$row['cant'];
        $meses[$row['mes']]['pesos'] += (float)($row['subtotal'] ?? 0);
        $meses[$row['mes']]['costo'] += (float)($row['totalcosto'] ?? 0);
    }
    return $meses;
}

function nombreMes($n) {
    $m = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
    return $m[$n];
}

// AJAX: Si se solicita el detalle de categorías para una familia
if(isset($_GET['ajax_familia'])) {
    $idFamAjax = $_GET['ajax_familia'];
    $nombreFamAjax = $_GET['nombre_fam'] ?? '';
    $cats = obtenerCategoriasPorFamilia($mysqli, $idFamAjax);
    
    $totPesosMesFam = 0; $totPesosAnioFam = 0;
    $listaCatsData = [];

    foreach($cats as $codCat => $nombreCat) {
        $skusCat = obtenerSkusPorCategoria($mysqli, $codCat);
        if(empty($skusCat)) continue;

        $vC_Cat = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusCat);
        $vD_Cat = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusCat);

        if ($sedeFiltro == 'central') {
            $pMes = $vC_Cat[$mesActualNum]['pesos'];
            $cMes = $vC_Cat[$mesActualNum]['cant'];
            $costoMes = $vC_Cat[$mesActualNum]['costo'];
            
            $totPesos = 0; $totCant = 0; $totCosto = 0;
            foreach($vC_Cat as $m => $val) {
                $totPesos += $val['pesos'];
                $totCant += $val['cant'];
                $totCosto += $val['costo'];
            }
        } elseif ($sedeFiltro == 'drinks') {
            $pMes = $vD_Cat[$mesActualNum]['pesos'];
            $cMes = $vD_Cat[$mesActualNum]['cant'];
            $costoMes = $vD_Cat[$mesActualNum]['costo'];
            
            $totPesos = 0; $totCant = 0; $totCosto = 0;
            foreach($vD_Cat as $m => $val) {
                $totPesos += $val['pesos'];
                $totCant += $val['cant'];
                $totCosto += $val['costo'];
            }
        } else {
            $pMes = $vC_Cat[$mesActualNum]['pesos'] + $vD_Cat[$mesActualNum]['pesos'];
            $cMes = $vC_Cat[$mesActualNum]['cant'] + $vD_Cat[$mesActualNum]['cant'];
            $costoMes = $vC_Cat[$mesActualNum]['costo'] + $vD_Cat[$mesActualNum]['costo'];
            
            $totPesos = 0; $totCant = 0; $totCosto = 0;
            foreach($vC_Cat as $m => $val) {
                $totPesos += $val['pesos'] + $vD_Cat[$m]['pesos'];
                $totCant += $val['cant'] + $vD_Cat[$m]['cant'];
                $totCosto += $val['costo'] + $vD_Cat[$m]['costo'];
            }
        }

        $utilMes = $pMes - $costoMes;
        $porcUtilMes = ($pMes > 0) ? ($utilMes / $pMes) * 100 : 0;
        $totUtil = $totPesos - $totCosto;
        $porcUtilAnio = ($totPesos > 0) ? ($totUtil / $totPesos) * 100 : 0;

        if($totPesos > 0 || $totCant > 0) {
            $listaCatsData[] = [
                'nombre' => strtoupper($nombreCat),
                'mesCant' => $cMes,
                'mesPesos' => $pMes,
                'mesUtil' => $utilMes,
                'mesPorcUtil' => $porcUtilMes,
                'totCant' => $totCant,
                'totPesos' => $totPesos,
                'totUtil' => $totUtil,
                'totPorcUtil' => $porcUtilAnio
            ];
            $totPesosMesFam += $pMes;
            $totPesosAnioFam += $totPesos;
        }
    }

    usort($listaCatsData, function($a, $b) {
        return $b['totPesos'] <=> $a['totPesos'];
    });
    
    echo '<h3 style="color:#006064; margin-top:0;">Categorías de: <b>'.htmlspecialchars($nombreFamAjax).'</b></h3>';
    if(empty($listaCatsData)) {
        echo '<p style="text-align:center; color:#666;">No hay registros para esta familia en la sede seleccionada.</p>';
        exit;
    }
    echo '<div style="overflow-x:auto;"><table>';
    echo '<thead><tr>
            <th style="text-align:left">Categoría</th>
            <th>Unid. Mes</th>
            <th>Ventas Mes</th>
            <th>Util. Mes</th>
            <th>% Util. Mes</th>
            <th>Part. Familia</th>
            <th>Unid. Año</th>
            <th>Ventas Año</th>
            <th>Util. Año</th>
            <th>% Util. Año</th>
          </tr></thead><tbody>';
    foreach($listaCatsData as $cat) {
        $partFam = ($totPesosAnioFam > 0) ? ($cat['totPesos'] / $totPesosAnioFam) * 100 : 0;
        echo '<tr>';
        echo '<td style="text-align:left; color:#006064;">'.$cat['nombre'].'</td>';
        echo '<td>'.number_format($cat['mesCant'], 0).'</td>';
        echo '<td>$'.number_format($cat['mesPesos'], 0).'</td>';
        echo '<td><span class="badge-util">$'.number_format($cat['mesUtil'], 0).'</span></td>';
        echo '<td><span class="badge-porc">'.number_format($cat['mesPorcUtil'], 1).'%</span></td>';
        echo '<td><span class="badge-part">'.number_format($partFam, 1).'%</span></td>';
        echo '<td>'.number_format($cat['totCant'], 0).'</td>';
        echo '<td>$'.number_format($cat['totPesos'], 0).'</td>';
        echo '<td><span class="badge-util" style="background:#e8f5e9; color:#2e7d32;">$'.number_format($cat['totUtil'], 0).'</span></td>';
        echo '<td><span class="badge-porc">'.number_format($cat['totPorcUtil'], 1).'%</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Utilidad por Familia</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f0f2f5; margin:0; padding:15px;}
        .container{max-width:1600px; margin:auto;}
        .header-filter{background:#fff; padding:20px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; align-items:center; justify-content: space-between; flex-wrap: wrap; gap: 15px;}
        .card{background:#fff; padding:20px; border-radius:15px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-top: 6px solid #00838f; margin-bottom: 25px; overflow-x: auto;}
        select{padding:12px; border-radius:10px; border:1px solid #ddd; width:100%; max-width:300px; font-size:15px; font-weight: bold; color: #006064;}
        table{width:100%; border-collapse:collapse; min-width: 900px;}
        th{background:#f8f9fa; padding:12px; text-align:right; font-size:11px; color:#888; border-bottom:2px solid #eee; text-transform: uppercase;}
        td{padding:12px; border-bottom:1px solid #f1f1f1; text-align:right; font-weight:600; font-size: 13px;}
        .badge-part{background: #e0f7fa; color: #006064; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-util{background: #e3f2fd; color: #1565c0; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .badge-porc{background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold;}
        .familia-link{color:#006064; cursor:pointer; text-decoration: underline;}
        .familia-link:hover{color:#00838f;}

        /* Estilos Modal */
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 999; justify-content: center; align-items: center; padding: 15px; box-sizing: border-box; }
        .modal-content { background: #fff; border-radius: 15px; width: 100%; max-width: 1200px; max-height: 90vh; overflow-y: auto; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; }
        .modal-close { position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: #888; cursor: pointer; }
        .modal-close:hover { color: #333; }

        @media(max-width: 768px) {
            body { padding: 10px; }
            .header-filter { padding: 15px; flex-direction: column; align-items: stretch; }
            select { max-width: 100%; }
            .card { padding: 15px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-filter">
        <div>
            <h2 style="margin:0; color:#006064; font-size: 22px;">🏷️ Dashboard de Utilidad y Ventas por Familia</h2>
            <small>Consolidado por Sede (Haz clic en una familia para ver sus categorías)</small>
        </div>
        <form method="GET">
            <select name="idsede" onchange="this.form.submit()">
                <option value="todos" <?= $sedeFiltro == 'todos' ? 'selected' : '' ?>>🌎 Todas las Sedes</option>
                <option value="central" <?= $sedeFiltro == 'central' ? 'selected' : '' ?>>🏢 Sede Central</option>
                <option value="drinks" <?= $sedeFiltro == 'drinks' ? 'selected' : '' ?>>🍹 Drinks Depot</option>
            </select>
        </form>
    </div>

    <?php
    $familias = obtenerFamilias($mysqli);
    $detalleFamilias = [];
    $totalGeneralPesosMes = 0;
    $totalGeneralPesosAnio = 0;

    foreach($familias as $idFamilia => $nombreFam) {
        $skusFam = obtenerSkusPorFamilia($mysqli, $idFamilia);
        if(empty($skusFam)) continue;

        $vC_Fam = obtenerMovimientoValorizado($mysqliCentral, $anioActual, $skusFam);
        $vD_Fam = obtenerMovimientoValorizado($mysqliPos, $anioActual, $skusFam);
        
        if ($sedeFiltro == 'central') {
            $pMesFam = $vC_Fam[$mesActualNum]['pesos'];
            $cMesFam = $vC_Fam[$mesActualNum]['cant'];
            $costoMesFam = $vC_Fam[$mesActualNum]['costo'];
            
            $totPesosFam = 0; $totCantFam = 0; $totCostoFam = 0;
            foreach($vC_Fam as $m => $val) {
                $totPesosFam += $val['pesos'];
                $totCantFam += $val['cant'];
                $totCostoFam += $val['costo'];
            }
        } elseif ($sedeFiltro == 'drinks') {
            $pMesFam = $vD_Fam[$mesActualNum]['pesos'];
            $cMesFam = $vD_Fam[$mesActualNum]['cant'];
            $costoMesFam = $vD_Fam[$mesActualNum]['costo'];
            
            $totPesosFam = 0; $totCantFam = 0; $totCostoFam = 0;
            foreach($vD_Fam as $m => $val) {
                $totPesosFam += $val['pesos'];
                $totCantFam += $val['cant'];
                $totCostoFam += $val['costo'];
            }
        } else {
            $pMesFam = $vC_Fam[$mesActualNum]['pesos'] + $vD_Fam[$mesActualNum]['pesos'];
            $cMesFam = $vC_Fam[$mesActualNum]['cant'] + $vD_Fam[$mesActualNum]['cant'];
            $costoMesFam = $vC_Fam[$mesActualNum]['costo'] + $vD_Fam[$mesActualNum]['costo'];
            
            $totPesosFam = 0; $totCantFam = 0; $totCostoFam = 0;
            foreach($vC_Fam as $m => $val) {
                $totPesosFam += $val['pesos'] + $vD_Fam[$m]['pesos'];
                $totCantFam += $val['cant'] + $vD_Fam[$m]['cant'];
                $totCostoFam += $val['costo'] + $vD_Fam[$m]['costo'];
            }
        }

        $utilMesFam = $pMesFam - $costoMesFam;
        $porcUtilMes = ($pMesFam > 0) ? ($utilMesFam / $pMesFam) * 100 : 0;

        $totUtilFam = $totPesosFam - $totCostoFam;
        $porcUtilAnio = ($totPesosFam > 0) ? ($totUtilFam / $totPesosFam) * 100 : 0;

        if($totPesosFam > 0 || $totCantFam > 0) {
            $detalleFamilias[] = [
                'id' => $idFamilia,
                'nombre' => strtoupper($nombreFam),
                'totPesos' => $totPesosFam,
                'totCant' => $totCantFam,
                'mesPesos' => $pMesFam,
                'mesCant' => $cMesFam,
                'mesUtil' => $utilMesFam,
                'mesPorcUtil' => $porcUtilMes,
                'totUtil' => $totUtilFam,
                'totPorcUtil' => $porcUtilAnio
            ];
            $totalGeneralPesosMes += $pMesFam;
            $totalGeneralPesosAnio += $totPesosFam;
        }
    }

    // Ordenar por ventas del año de mayor a menor
    usort($detalleFamilias, function($a, $b) {
        return $b['totPesos'] <=> $a['totPesos'];
    });

    $labelsFam = array_column($detalleFamilias, 'nombre');
    $pesosFamAnual = array_column($detalleFamilias, 'totPesos');
    $utilFamAnual = array_column($detalleFamilias, 'totUtil');
    ?>

    <?php if(!empty($detalleFamilias)): ?>
    <div class="card">
        <h2 style="color:#006064; margin-top:0; font-size: 18px;">📊 Ventas y Utilidad por Familia - <?= nombreMes($mesActualNum) ?> / Anual</h2>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left">Familia</th>
                    <th>Unidades (Mes)</th>
                    <th>Ventas ($ Mes)</th>
                    <th>Utilidad ($ Mes)</th>
                    <th>% Util. Mes</th>
                    <th>Part. Mes</th>
                    <th>Unidades Totales (Año)</th>
                    <th>Ventas Totales ($ Año)</th>
                    <th>Utilidad Total ($ Año)</th>
                    <th>% Util. Año</th>
                    <th>Part. Anual</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($detalleFamilias as $fam): 
                    $partMes = ($totalGeneralPesosMes > 0) ? ($fam['mesPesos'] / $totalGeneralPesosMes) * 100 : 0;
                    $partAnio = ($totalGeneralPesosAnio > 0) ? ($fam['totPesos'] / $totalGeneralPesosAnio) * 100 : 0;
                ?>
                <tr>
                    <td style="text-align:left;">
                        <span class="familia-link" onclick="abrirModalFamilia('<?= $fam['id'] ?>', '<?= addslashes($fam['nombre']) ?>')">
                            <?= $fam['nombre'] ?> 🔍
                        </span>
                    </td>
                    <td><?= number_format($fam['mesCant'], 0) ?></td>
                    <td>$<?= number_format($fam['mesPesos'], 0) ?></td>
                    <td><span class="badge-util">$<?= number_format($fam['mesUtil'], 0) ?></span></td>
                    <td><span class="badge-porc"><?= number_format($fam['mesPorcUtil'], 1) ?>%</span></td>
                    <td><span class="badge-part"><?= number_format($partMes, 1) ?>%</span></td>
                    <td><?= number_format($fam['totCant'], 0) ?></td>
                    <td>$<?= number_format($fam['totPesos'], 0) ?></td>
                    <td><span class="badge-util" style="background:#e8f5e9; color:#2e7d32;">$<?= number_format($fam['totUtil'], 0) ?></span></td>
                    <td><span class="badge-porc"><?= number_format($fam['totPorcUtil'], 1) ?>%</span></td>
                    <td><span class="badge-part"><?= number_format($partAnio, 1) ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="border-top-color: #ff5722; background: #fffcfb;">
        <h2 style="text-align:center; color:#d84315; margin-top:0; font-size: 18px;">📈 Gráfico Comparativo: Ventas vs Utilidad Anual por Familia</h2>
        <div style="position: relative; height: 350px; width: 100%; margin-top: 20px;">
            <canvas id="graficoFamilias"></canvas>
        </div>
    </div>

    <!-- MODAL CATEGORÍAS -->
    <div id="modalFamilia" class="modal-overlay" onclick="if(event.target === this) cerrarModalFamilia();">
        <div class="modal-content">
            <span class="modal-close" onclick="cerrarModalFamilia()">&times;</span>
            <div id="modalBodyContent">
                <p style="text-align:center; color:#666;">Cargando categorías...</p>
            </div>
        </div>
    </div>

    <script>
    function abrirModalFamilia(idFamilia, nombreFamilia) {
        const modal = document.getElementById('modalFamilia');
        const content = document.getElementById('modalBodyContent');
        modal.style.display = 'flex';
        content.innerHTML = '<p style="text-align:center; color:#666; padding: 20px;">Cargando categorías de ' + nombreFamilia + '...</p>';

        const sede = "<?= $sedeFiltro ?>";
        fetch('?idsede=' + sede + '&ajax_familia=' + idFamilia + '&nombre_fam=' + encodeURIComponent(nombreFamilia))
            .then(response => response.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(error => {
                content.innerHTML = '<p style="text-align:center; color:red; padding: 20px;">Error al cargar los datos.</p>';
            });
    }

    function cerrarModalFamilia() {
        document.getElementById('modalFamilia').style.display = 'none';
    }

    new Chart(document.getElementById('graficoFamilias'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($labelsFam) ?>,
            datasets: [
                {
                    label: 'Ventas Totales ($ Año)',
                    data: <?= json_encode($pesosFamAnual) ?>,
                    backgroundColor: '#00838fcc',
                    borderColor: '#006064',
                    borderWidth: 1,
                    borderRadius: 5
                },
                {
                    label: 'Utilidad Total ($ Año)',
                    data: <?= json_encode($utilFamAnual) ?>,
                    backgroundColor: '#2e7d32cc',
                    borderColor: '#1b5e20',
                    borderWidth: 1,
                    borderRadius: 5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => '$' + v.toLocaleString() }
                }
            },
            plugins: {
                legend: { display: true, position: 'top' }
            }
        }
    });
    </script>
    <?php else: ?>
    <div class="card">
        <p style="text-align:center; margin:20px; font-weight:bold; color:#666;">No se encontraron registros de ventas para la sede seleccionada.</p>
    </div>
    <?php endif; ?>

</div>
</body>
</html>