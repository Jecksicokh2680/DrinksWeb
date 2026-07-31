<?php
require('ConnCentral.php'); // $mysqliCentral
require('ConnDrinks.php');  // $mysqliDrinks
require('Conexion.php');    // $mysqliWeb / $mysqli

define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

date_default_timezone_set('America/Bogota');

/* =====================================================
    1. PARÁMETROS DE FILTRO (Mes para Objetivos / Día Actual para Ventas)
===================================================== */
$mes_sel  = (int)($_GET['mm'] ?? date('m'));
$anio_sel = (int)($_GET['aa'] ?? date('Y'));

// Rango de fechas ajustado estrictamente al DÍA ACTUAL para las ventas
$f_ini = date('Ymd'); 
$f_fin = date('Ymd'); 

/* =====================================================
    2. OBTENER EQUIVALENCIAS UNICAJA DE PRODUCTOS
===================================================== */
$unicaja = [];
if (isset($mysqliWeb) && !$mysqliWeb->connect_error) {
    $mysqliWeb->set_charset("utf8mb4");
    $qUnicaja = $mysqliWeb->query("SELECT cp.Sku, cat.Unicaja FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat");
    if ($qUnicaja) {
        while ($u = $qUnicaja->fetch_assoc()) {
            $unicaja[$u['Sku']] = (float)$u['Unicaja'];
        }
    }
}

/* =====================================================
    3. OBTENER VENTAS EJECUTADAS (CENTRAL Y DRINKS)
===================================================== */
function obtenerVentasEjecutadas($cnx, $f_ini, $f_fin) {
    if (!$cnx || $cnx->connect_error) return [];

    $sql = "
        SELECT 
            T1.NOMBRES AS FACTURADOR,
            PRODUCTOS.Barcode AS SKU,
            DETFACTURAS.CANTIDAD,
            (DETFACTURAS.CANTIDAD * DETFACTURAS.VALORPROD) AS TOTAL_VENTA
        FROM FACTURAS
        INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA = FACTURAS.IDFACTURA
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETFACTURAS.IDPRODUCTO
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = FACTURAS.IDVENDEDOR
        WHERE FACTURAS.ESTADO = '0' AND FACTURAS.FECHA BETWEEN ? AND ?

        UNION ALL

        SELECT 
            T2.NOMBRES AS FACTURADOR,
            PRODUCTOS.Barcode AS SKU,
            DETPEDIDOS.CANTIDAD,
            (DETPEDIDOS.CANTIDAD * DETPEDIDOS.VALORPROD) AS TOTAL_VENTA
        FROM PEDIDOS
        INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO = DETPEDIDOS.IDPEDIDO
        INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO = DETPEDIDOS.IDPRODUCTO
        INNER JOIN USUVENDEDOR V ON V.IDUSUARIO = PEDIDOS.IDUSUARIO
        INNER JOIN TERCEROS T2 ON T2.IDTERCERO = V.IDTERCERO
        WHERE PEDIDOS.ESTADO = '0' AND PEDIDOS.FECHA BETWEEN ? AND ?
    ";

    $stmt = $cnx->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$rawVentas = [];
if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
    $rawVentas = array_merge($rawVentas, obtenerVentasEjecutadas($mysqliCentral, $f_ini, $f_fin));
}
if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
    $rawVentas = array_merge($rawVentas, obtenerVentasEjecutadas($mysqliDrinks, $f_ini, $f_fin));
}

// Consolidar ventas por Facturador y SKU
$ventasPorFacturador = [];
foreach ($rawVentas as $v) {
    $facturador = trim($v['FACTURADOR']);
    $sku        = trim($v['SKU']);
    $cantidad   = (float)$v['CANTIDAD'];
    $valor      = (float)$v['TOTAL_VENTA'];

    if (!isset($ventasPorFacturador[$facturador])) {
        $ventasPorFacturador[$facturador] = [
            'total_valor' => 0.0,
            'skus'        => []
        ];
    }

    $ventasPorFacturador[$facturador]['total_valor'] += $valor;

    if (!isset($ventasPorFacturador[$facturador]['skus'][$sku])) {
        $ventasPorFacturador[$facturador]['skus'][$sku] = 0.0;
    }
    $ventasPorFacturador[$facturador]['skus'][$sku] += $cantidad;
}

/* =====================================================
    4. OBTENER OBJETIVOS DE LA BASE DE DATOS (WEB)
===================================================== */
$sqlObjetivos = "
    SELECT 
        t.CedulaNit,
        t.Nombre,
        t.NombreCom,
        cab.id_cabecera,
        cab.NitEmpresa,
        cab.mm,
        cab.aa,
        cab.meta_valor_total,
        det.Sku,
        det.meta_cajas
    FROM terceros t
    INNER JOIN objetivos_cajeros_cab cab ON t.CedulaNit = cab.CedulaNit
    LEFT JOIN objetivos_cajeros_det det ON cab.id_cabecera = det.id_cabecera
    WHERE cab.mm = ? AND cab.aa = ?
    ORDER BY t.Nombre ASC, det.Sku ASC
";

$stmtObj = $mysqliWeb->prepare($sqlObjetivos);
$stmtObj->bind_param("ii", $mes_sel, $anio_sel);
$stmtObj->execute();
$resObj = $stmtObj->get_result();

$reporte = [];

while ($row = $resObj->fetch_assoc()) {
    $cedula     = $row['CedulaNit'];
    $nombre     = $row['NombreCom'] ?: $row['Nombre'] ?: 'Sin Nombre';
    $idCabecera = $row['id_cabecera'];

    if (!isset($reporte[$cedula])) {
        $ejecutadoValor = $ventasPorFacturador[$nombre]['total_valor'] ?? 0.0;

        $reporte[$cedula] = [
            'nombre'           => $nombre,
            'cedula'           => $cedula,
            'meta_valor_total' => (float)$row['meta_valor_total'],
            'ejecutado_valor'  => $ejecutadoValor,
            'detalles'         => []
        ];
    }

    if (!empty($row['Sku'])) {
        $sku       = $row['Sku'];
        $metaCajas = (float)$row['meta_cajas'];

        $cantVendida = $ventasPorFacturador[$nombre]['skus'][$sku] ?? 0.0;

        $reporte[$cedula]['detalles'][] = [
            'sku'            => $sku,
            'meta_cajas'     => $metaCajas,
            'cajas_vendidas' => $cantVendida,
            'unds_vendidas'  => $cantVendida
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seguimiento de Cumplimiento de Objetivos</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; margin: 20px; color: #333; }
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        .filter-box { background: #eef2f5; padding: 15px; border-radius: 8px; display: flex; gap: 15px; align-items: center; margin-bottom: 25px; }
        select, button { padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 14px; }
        button { background: #0288d1; color: white; border: none; cursor: pointer; font-weight: bold; }
        
        .grid-paneles { 
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 20px; 
        }
        @media (max-width: 900px) {
            .grid-paneles { grid-template-columns: 1fr; }
        }

        .tercero-card { border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; background: #fff; }
        .tercero-header { background: #263238; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .tercero-header h3 { margin: 0; font-size: 18px; }
        .resumen-meta { display: flex; gap: 15px; background: #f8f9fa; padding: 15px; border-bottom: 1px solid #e0e0e0; }
        .metric-box { flex: 1; text-align: center; }
        .metric-box .title { font-size: 11px; text-transform: uppercase; color: #666; font-weight: bold; }
        .metric-box .value { font-size: 16px; font-weight: bold; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 13px; }
        th { background: #eceff1; font-size: 11px; text-transform: uppercase; color: #455a64; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: white; display: inline-block; }
        .bg-success { background-color: #2e7d32; }
        .bg-warning { background-color: #f57c00; }
        .bg-danger { background-color: #c62828; }
        .progress-bar-bg { background: #e0e0e0; border-radius: 10px; height: 10px; width: 100%; overflow: hidden; margin-top: 4px; }
        .progress-bar-fill { height: 100%; border-radius: 10px; }
    </style>
</head>
<body>

<div class="card">
    <form method="GET" class="filter-box">
        <label><strong>Mes:</strong></label>
        <select name="mm">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?=$m?>" <?=$m == $mes_sel ? 'selected' : ''?>><?=date('F', mktime(0, 0, 0, $m, 1))?> (<?=$m?>)</option>
            <?php endfor; ?>
        </select>

        <label><strong>Año:</strong></label>
        <select name="aa">
            <?php for ($a = date('Y'); $a >= date('Y') - 2; $a--): ?>
                <option value="<?=$a?>" <?=$a == $anio_sel ? 'selected' : ''?>><?=$a?></option>
            <?php endfor; ?>
        </select>

        <button type="submit">🔍 Cargar Cumplimiento</button>
    </form>

    <?php if (empty($reporte)): ?>
        <p style="text-align:center; color:#777;">No se registraron objetivos para el período seleccionado (<?=$mes_sel?>/<?=$anio_sel?>).</p>
    <?php else: ?>
        <div class="grid-paneles">
            <?php foreach ($reporte as $tercero): 
                $metaVal  = $tercero['meta_valor_total'];
                $ejecVal  = $tercero['ejecutado_valor'];
                $pctValor = ($metaVal > 0) ? min(100, round(($ejecVal / $metaVal) * 100, 1)) : 0;
                $badgeValClass = ($pctValor >= 100) ? 'bg-success' : (($pctValor >= 70) ? 'bg-warning' : 'bg-danger');
            ?>
                <div class="tercero-card">
                    <div class="tercero-header">
                        <h3><?= htmlspecialchars($tercero['nombre']) ?></h3>
                        <span style="font-size: 12px;">NIT: <?= htmlspecialchars($tercero['cedula']) ?></span>
                    </div>

                    <div class="resumen-meta">
                        <div class="metric-box">
                            <div class="title">Meta Total</div>
                            <div class="value" style="color:#0288d1; font-size:14px;">$ <?= number_format($metaVal, 0, ',', '.') ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="title">Ejecutado Hoy</div>
                            <div class="value" style="color:#2e7d32; font-size:14px;">$ <?= number_format($ejecVal, 0, ',', '.') ?></div>
                        </div>
                        <div class="metric-box">
                            <div class="title">% Cumplimiento</div>
                            <div class="value">
                                <span class="badge <?= $badgeValClass ?>"><?= $pctValor ?>%</span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill <?= $badgeValClass ?>" style="width: <?= $pctValor ?>%;"></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($tercero['detalles'])): ?>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Meta</th>
                                        <th>Hoy</th>
                                        <th>% SKU</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tercero['detalles'] as $det): 
                                        $metaValSku = $det['meta_cajas'];
                                        $cantValSku = $det['cajas_vendidas'];
                                        $pctSku     = ($metaValSku > 0) ? round(($cantValSku / $metaValSku) * 100, 1) : 0;
                                        $badgeSkuClass = ($pctSku >= 100) ? 'bg-success' : (($pctSku >= 70) ? 'bg-warning' : 'bg-danger');
                                    ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($det['sku']) ?></code></td>
                                            <td><?= number_format($metaValSku, 2, ',', '.') ?></td>
                                            <td><strong><?= number_format($cantValSku, 0, ',', '.') ?></strong></td>
                                            <td>
                                                <span class="badge <?= $badgeSkuClass ?>"><?= $pctSku ?>%</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="padding:15px; margin:0; color:#888; font-size:13px;"><em>Sin metas específicas por SKU.</em></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>