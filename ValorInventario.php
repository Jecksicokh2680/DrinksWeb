<?php
session_start();
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

// --- Lógica de guardado de egresos ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] == 'update_compra') {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data) {
        $idCompra = intval($data['id']);
        $nuevoValor = floatval(preg_replace('/[^0-9.]/', '', str_replace(',', '.', $data['valor'])));
        $db = ($data['sede'] === 'CENTRAL') ? $mysqliCentral : $mysqliDrinks;
        
        if ($db) {
            $stmt = $db->prepare("UPDATE DETCOMPRAS SET VALOR = (? / NULLIF(CANTIDAD, 0)), descuento = 0, porciva = 0, ValICUIUni = 0 WHERE idcompra = ?");
            $stmt->bind_param("di", $nuevoValor, $idCompra);
            $echo_success = $stmt->execute();
            echo json_encode(['success' => $echo_success]);
            $stmt->close();
        }
    }
    exit;
}

function moneda($v){ return '$' . number_format((float)$v, 0, ',', '.'); }
function porcentaje($parte, $total) { 
    if ($total <= 0) return '0%';
    return number_format(($parte / $total) * 100, 1, ',', '.') . '%'; 
}
function diaCorto3($fechaYmd) {
    $dias = [
        'Mon' => 'Lun',
        'Tue' => 'Mar',
        'Wed' => 'Mié',
        'Thu' => 'Jue',
        'Fri' => 'Vie',
        'Sat' => 'Sáb',
        'Sun' => 'Dom'
    ];
    $en = date('D', strtotime($fechaYmd));
    return $dias[$en] ?? substr($en, 0, 3);
}

$fechaSQL = date('Y-m-d');
$fechaSinGuion = date('Ymd');
$mes = date('m');
$anio = date('Y');
$diaActual = (int)date('j');
$fecha_45_atras = date('Y-m-d', strtotime('-60 days'));

$nitSedes = ['CENTRAL' => '86057267-8', 'DRINKS' => '901724534-7'];

// Función mejorada para calcular el costo promedio ponderado real con impuestos de las últimas compras
function precioPromCompraAvanzado($mysqli_conn, $fDesde, $fHasta){
    $sql = "SELECT 
                d.idproducto,
                P.Barcode,
                SUM(d.cantidad * ( (d.valor - (d.descuento / NULLIF(d.cantidad, 0))) * (1 + d.porciva/100) + d.ValICUIUni )) / NULLIF(SUM(d.cantidad), 0) AS pv_compra 
            FROM detcompras d
            INNER JOIN compras c ON c.idcompra = d.idcompra AND c.estado = '0'
            INNER JOIN productos P ON P.idproducto = d.idproducto
            WHERE c.fecha BETWEEN '$fDesde' AND '$fHasta'
            GROUP BY d.idproducto, P.Barcode";
    
    $out = []; 
    $r = $mysqli_conn->query($sql);
    while($r && $x = $r->fetch_assoc()) {
        $out[$x['Barcode']] = $x['pv_compra'];
    }
    return $out;
}

// Generamos los diccionarios de precios promedios de compra con impuestos
$pcC = ($mysqliCentral) ? precioPromCompraAvanzado($mysqliCentral, str_replace('-', '', $fecha_45_atras), $fechaSinGuion) : [];
$pcD = ($mysqliDrinks) ? precioPromCompraAvanzado($mysqliDrinks, str_replace('-', '', $fecha_45_atras), $fechaSinGuion) : [];

function analizarSucursal($mysqli_conn, $nombreSede, $pcData){
    global $mes, $anio, $fechaSQL, $mysqli, $nitSedes; 
    if (!$mysqli_conn) return ['inventario'=>0, 'inv_venta'=>0, 'venta_dia'=>0, 'trans_dia'=>0, 'trans_auto_val'=>0, 'trans_auto_cant'=>0, 'venta_mes'=>0, 'utilidad'=>0];
    
    $nit = $nitSedes[$nombreSede];

    // 2. INVENTARIOS (COSTO Y VENTA DIRECTA CON RESPALDO A PRECIO DE COMPRA)
    $invCosto = 0;
    $invVenta = 0;
    $resInv = $mysqli_conn->query("SELECT I.idproducto, I.cantidad, P.costo, P.precioventa, P.Barcode FROM inventario I INNER JOIN productos P ON P.idproducto = I.idproducto WHERE I.estado='0'");
    
    if($resInv){
        while($i = $resInv->fetch_assoc()){
            $costoReal = $pcData[$i['Barcode']] ?? $i['costo'];
            $invCosto += ($i['cantidad'] * $costoReal);
            
            // Si el precio de venta no existe o es menor/igual a 0, usamos el costo real de compra
            $p_venta = (!empty($i['precioventa']) && $i['precioventa'] > 0) ? $i['precioventa'] : $costoReal;
            $invVenta += ($i['cantidad'] * $p_venta);
        }
    }
    
    // 3. VENTA DÍA
    $ventaDia = $mysqli_conn->query("SELECT SUM(total) AS v FROM (
        SELECT D.CANTIDAD * D.VALORPROD AS total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
        UNION ALL
        SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
    ) X")->fetch_assoc()['v'] ?? 0;

    // 4. TRANSFERENCIAS MANUALES
    $tr_res = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = '$fechaSQL' AND NitEmpresa = '$nit' AND Estado = 1");
    $transDia = ($tr_res) ? $tr_res->fetch_assoc()['total'] : 0;

    // 4.1. TRANSFERENCIAS AUTOMÁTICAS (Nequi)
    $transAutoVal = 0;
    $transAutoCant = 0;
    if ($mysqli) {
        $stmtAuto = $mysqli->prepare("SELECT COUNT(n.id) AS total, COALESCE(SUM(n.monto), 0) AS valor FROM notificaciones_nequi n INNER JOIN control_checks_nequi c ON n.id = c.id_transferencia WHERE DATE(n.fecha_correo) = ? AND c.nit_empresa = ? AND n.estado_sesion = 'Recibido'");
        if ($stmtAuto) {
            $stmtAuto->bind_param("ss", $fechaSQL, $nit);
            $stmtAuto->execute();
            $resAuto = $stmtAuto->get_result()->fetch_assoc();
            if ($resAuto) {
                $transAutoVal = (float)$resAuto['valor'];
                $transAutoCant = (int)$resAuto['total'];
            }
            $stmtAuto->close();
        }
    }

    // 5. VENTA MES Y UTILIDAD CON COSTO REAL PONDERADO
    $sqlMesF = "SELECT D.CANTIDAD, D.VALORPROD, P.costo, P.Barcode FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'";
    $sqlMesP = "SELECT DP.CANTIDAD, DP.VALORPROD, P.costo, P.Barcode FROM PEDIDOS PE INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'";

    $totalVentasMes = 0;
    $totalUtilidadMes = 0;

    foreach([$sqlMesF, $sqlMesP] as $queryMes) {
        $resM = $mysqli_conn->query($queryMes);
        if($resM) {
            while($rowM = $resM->fetch_assoc()) {
                $vta = $rowM['CANTIDAD'] * $rowM['VALORPROD'];
                $cst = $pcData[$rowM['Barcode']] ?? $rowM['costo'];
                $util = $vta - ($rowM['CANTIDAD'] * $cst);
                
                $totalVentasMes += $vta;
                $totalUtilidadMes += $util;
            }
        }
    }

    return [
        'inventario'     => $invCosto, 
        'inv_venta'      => $invVenta,
        'venta_dia'      => $ventaDia, 
        'trans_dia'      => $transDia, 
        'trans_auto_val' => $transAutoVal,
        'trans_auto_cant'=> $transAutoCant,
        'venta_mes'      => $totalVentasMes, 
        'utilidad'       => $totalUtilidadMes
    ];
}

// ============================================================
// VENTAS DÍA A DÍA DEL MES HASTA EL DÍA ACTUAL (SIN DÍAS EN 0)
// ============================================================
function ventasDiariasMes($mysqli_conn, $mes, $anio, $diaActual) {
    $diario = array_fill(1, $diaActual, 0.0);
    if (!$mysqli_conn) return $diario;

    $sqlDiario = "SELECT dia, SUM(total) AS v FROM (
        SELECT DAY(F.FECHA) AS dia, (D.CANTIDAD * D.VALORPROD) AS total
        FROM FACTURAS F
        INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO = '0' AND DV.IDFACTURA IS NULL
          AND MONTH(F.FECHA) = '$mes' AND YEAR(F.FECHA) = '$anio'
          AND DAY(F.FECHA) <= $diaActual
        UNION ALL
        SELECT DAY(P.FECHA) AS dia, (DP.CANTIDAD * DP.VALORPROD) AS total
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
        WHERE P.ESTADO = '0'
          AND MONTH(P.FECHA) = '$mes' AND YEAR(P.FECHA) = '$anio'
          AND DAY(P.FECHA) <= $diaActual
    ) X GROUP BY dia";

    $resD = $mysqli_conn->query($sqlDiario);
    if ($resD) {
        while ($row = $resD->fetch_assoc()) {
            $d = (int)$row['dia'];
            if (isset($diario[$d])) {
                $diario[$d] = (float)$row['v'];
            }
        }
    }
    return $diario;
}

$central = analizarSucursal($mysqliCentral, 'CENTRAL', $pcC);
$drinks  = analizarSucursal($mysqliDrinks, 'DRINKS', $pcD);

$rawCentral = ventasDiariasMes($mysqliCentral, $mes, $anio, $diaActual);
$rawDrinks  = ventasDiariasMes($mysqliDrinks, $mes, $anio, $diaActual);

$labelsDiasMes        = [];
$ventasDiariasTotal   = [];
$ventasDiariasCentral = [];
$ventasDiariasDrinks  = [];
$maxVentaDiaMes       = 0;
$diaPicoLabel         = '-';
$diasConVenta         = 0;

for ($d = 1; $d <= $diaActual; $d++) {
    $vC = $rawCentral[$d] ?? 0.0;
    $vD = $rawDrinks[$d] ?? 0.0;
    $valTotal = $vC + $vD;

    // Omitir días sin ventas (venta consolidada igual a 0)
    if ($valTotal <= 0) {
        continue;
    }

    $diaStr = str_pad($d, 2, '0', STR_PAD_LEFT);
    $fechaStr = "$anio-$mes-$diaStr";
    $nomDia3 = diaCorto3($fechaStr);
    $lbl = "$nomDia3 $diaStr/$mes";

    $labelsDiasMes[]        = $lbl;
    $ventasDiariasTotal[]   = $valTotal;
    $ventasDiariasCentral[] = $vC;
    $ventasDiariasDrinks[]  = $vD;
    $diasConVenta++;

    if ($valTotal > $maxVentaDiaMes) {
        $maxVentaDiaMes = $valTotal;
        $diaPicoLabel   = $lbl;
    }
}

$totalMesConsolidado = $central['venta_mes'] + $drinks['venta_mes'];
$promedioDiarioMes   = $diasConVenta > 0 ? ($totalMesConsolidado / $diasConVenta) : 0;

// Compras del día
$todasLasCompras = [];
foreach(['CENTRAL' => $mysqliCentral, 'DRINKS' => $mysqliDrinks] as $s => $db){
    if (!$db) continue;
    $res = $db->query("SELECT C.idcompra, CONCAT(T.nombres, ' ', T.apellidos) AS prov, SUM(D.CANTIDAD * (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0)) + ( (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * D.porciva / 100) + D.ValICUIUni)) AS total FROM compras C INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra WHERE C.FECHA = '$fechaSinGuion' AND C.ESTADO = '0' GROUP BY C.idcompra");
    if($res) {
        while($row =$res->fetch_assoc()){ $row['sede'] =$s; $todasLasCompras[] =$row; }
    }
}

$deudaProv =$mysqli->query("SELECT SUM(Saldo) AS total FROM (SELECT SUM(p.Monto) AS Saldo FROM terceros t INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit WHERE t.Estado = 1 AND p.Estado = '1' GROUP BY t.CedulaNit HAVING SUM(p.Monto) <> 0) X")->fetch_assoc()['total'] ?? 0;

// ============================================================
// RENDIMIENTO DE VENTAS POR CAJERO
// ============================================================
$rankingCajeros = [];$ventaGlobalConsolidada = $central['venta_dia'] +$drinks['venta_dia'];

foreach(['CENTRAL' => $mysqliCentral, 'DRINKS' =>$mysqliDrinks] as $sedeNombre =>$dbActiva){
    if (!$dbActiva) continue;
    
    $sqlCajeros = "SELECT NIT, NOMBRE FROM (
        SELECT T1.NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOMBRE FROM FACTURAS F 
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fechaSinGuion'
        UNION 
        SELECT V.NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS NOMBRE FROM PEDIDOS P 
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fechaSinGuion'
    ) X GROUP BY NIT";
    
    $resCajeros = $dbActiva->query($sqlCajeros);
    if($resCajeros){
        while($caj = $resCajeros->fetch_assoc()){$nitCajero = $caj['NIT'];$nombreCajero = $caj['NOMBRE'];$sqlVentaCajero = "SELECT SUM(T) AS TOTAL FROM (
                SELECT (DF.CANTIDAD*DF.VALORPROD) AS T FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
                LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fechaSinGuion' AND T1.NIT='$nitCajero'
                UNION ALL 
                SELECT (DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
                INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fechaSinGuion' AND V.NIT='$nitCajero'
            ) AS X";
            
            $vtaNeto = (float)($dbActiva->query($sqlVentaCajero)->fetch_assoc()['TOTAL'] ?? 0);
            if($vtaNeto > 0){$rankingCajeros[] = [
                    'nombre' => $nombreCajero,
                    'sede' => $sedeNombre,
                    'total' => $vtaNeto
                ];
            }
        }
    }
}
usort($rankingCajeros, function($a,$b) { return $b['total'] <=>$a['total']; });
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #f4f7f6; margin: 0; padding: 15px; color: #374151; box-sizing: border-box; }
        *, *:before, *:after { box-sizing: inherit; }
        
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .header-top h2 { margin:0; font-size: 1.4rem; color: #1f2937; }

        .header-actions { display: flex; align-items: center; gap: 10px; }
        .timer-box { background: #1e293b; color: #fff; padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .timer-box span { color: #60a5fa; min-width: 45px; }

        .btn-refresh { background: #2563eb; color: #fff; border: none; padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background 0.2s; }
        .btn-refresh:hover { background: #1d4ed8; }

        .grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 15px; margin-bottom: 20px; }
        
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); text-align: center; border: 1px solid #e5e7eb; transition: transform 0.2s; height: 100%; display: flex; flex-direction: column; justify-content: space-between; }
        .card:hover { transform: translateY(-2px); }
        .card h3 { font-size: 1.1rem; margin: 0 0 12px 0; display: flex; align-items: center; justify-content: center; gap: 8px; color: #111827; }

        .main-value { font-size: 1.6rem; font-weight: 800; color: #2563eb; display: block; margin-bottom: 8px; letter-spacing: -0.5px; word-break: break-all; }
        .details { font-size: 0.85rem; line-height: 1.5; color: #4b5563; }
        .separator { border-top: 1px solid #f3f4f6; margin: 12px 0; width: 100%; }

        .sections-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .full-width { grid-column: 1 / -1; }

        .wrap-box { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; min-width: 0; }

        .trend-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; }
        .trend-stats { display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.82rem; }
        .trend-pill { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; font-weight: 600; color: #334155; }
        .trend-pill span { color: #2563eb; font-weight: 800; }

        .table-container { width: 100%; overflow-x: auto; border-radius: 8px; -webkit-overflow-scrolling: touch; background: #fff; border: 1px solid #e5e7eb; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 500px; }
        th { text-align: left; padding: 12px; background: #1f2937; color: #fff; font-weight: 600; position: sticky; top: 0; }
        td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        
        .editable { color: #2563eb; font-weight: bold; border-bottom: 2px dashed #bfdbfe; cursor: pointer; padding: 2px 4px; border-radius: 4px; display: inline-block; }

        .ranking-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.85rem; gap: 10px; }
        .ranking-user { width: 30%; font-weight: 600; min-width: 110px; }
        .ranking-bar-bg { flex-grow: 1; background: #e5e7eb; height: 100%; min-height: 8px; max-height: 8px; border-radius: 4px; overflow: hidden; }
        .ranking-bar-fill { background: #8b5cf6; height: 8px; border-radius: 4px; }
        .ranking-total { width: 30%; text-align: right; font-weight: bold; min-width: 140px; display: flex; align-items: center; justify-content: flex-end; gap: 8px; }

        @media (max-width: 768px) {
            .sections-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: unset; }
        }

        @media (max-width: 480px) {
            body { padding: 10px; }
            .header-top { flex-direction: column; align-items: flex-start; }
            .header-actions { align-self: flex-end; width: 100%; justify-content: space-between; }
            .main-value { font-size: 1.4rem; }
            .card { padding: 15px; }
            
            .ranking-item { flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px 0; }
            .ranking-user { width: 100%; }
            .ranking-bar-bg { width: 100%; height: 6px; }
            .ranking-total { width: 100%; text-align: left; justify-content: space-between; }
        }
    </style>
</head>
<body>

    <div class="header-top">
        <h2>Panel Administrativo</h2>
        <div class="header-actions">
            <button class="btn-refresh" onclick="location.reload();">🔄 Actualizar</button>
            <div class="timer-box">⏱️ Act: <span id="countdown">03:00</span></div>
        </div>
    </div>

    <div class="grid-cards">
        <div class="card">
            <div>
                <h3>🏢 Central</h3>
                <span class="main-value"><?= moneda($central['venta_dia']) ?></span>
                <div class="details">
                    Trans. Manual: <?= moneda($central['trans_dia']) ?><br>
                    Trans. Automática: <?= moneda($central['trans_auto_val']) ?> <small>(<?=$central['trans_auto_cant'] ?>)</small><br>
                    Neto: <b style="color:#2563eb"><?= moneda($central['venta_dia'] - ($central['trans_dia'] +$central['trans_auto_val'])) ?></b>
                </div>
            </div>
            <div>
                <div class="separator"></div>
                <div class="details">
                    Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']) ?></span><br>
                    Utilidad Mes: <span style="color:#10b981"><?= moneda($central['utilidad']) ?></span> <b>(<?= porcentaje($central['utilidad'],$central['venta_mes']) ?>)</b><br>
                    Bodega Costo: <?= moneda($central['inventario']) ?><br>
                    <b>Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($central['inv_venta']) ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div>
                <h3>🍹 Drinks</h3>
                <span class="main-value"><?= moneda($drinks['venta_dia']) ?></span>
                <div class="details">
                    Trans. Manual: <?= moneda($drinks['trans_dia']) ?><br>
                    Trans. Automática: <?= moneda($drinks['trans_auto_val']) ?> <small>(<?=$drinks['trans_auto_cant'] ?>)</small><br>
                    Neto: <b style="color:#2563eb"><?= moneda($drinks['venta_dia'] - ($drinks['trans_dia'] +$drinks['trans_auto_val'])) ?></b>
                </div>
            </div>
            <div>
                <div class="separator"></div>
                <div class="details">
                    Venta Mes: <span style="color:#f97316"><?= moneda($drinks['venta_mes']) ?></span><br>
                    Utilidad Mes: <span style="color:#10b981"><?= moneda($drinks['utilidad']) ?></span> <b>(<?= porcentaje($drinks['utilidad'],$drinks['venta_mes']) ?>)</b><br>
                    Bodega Costo: <?= moneda($drinks['inventario']) ?><br>
                    <b>Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($drinks['inv_venta']) ?></span>
                </div>
            </div>
        </div>

        <div class="card" style="border: 2px solid #3b82f6; background-color: #eff6ff;">
            <div>
                <h3>📌 Totales Consolidados</h3>
                <span class="main-value"><?= moneda($central['venta_dia']+$drinks['venta_dia']) ?></span>
                <div class="details">
                    Trans. Manual: <?= moneda($central['trans_dia']+$drinks['trans_dia']) ?><br>
                    Trans. Automática: <?= moneda($central['trans_auto_val']+$drinks['trans_auto_val']) ?> <small>(<?= $central['trans_auto_cant']+$drinks['trans_auto_cant'] ?>)</small><br>
                    Neto: <b style="color:#2563eb"><?= moneda(($central['venta_dia']+$drinks['venta_dia']) - (($central['trans_dia']+$central['trans_auto_val']) + ($drinks['trans_dia']+$drinks['trans_auto_val']))) ?></b>
                </div>
            </div>
            <div>
                <div class="separator"></div>
                <div class="details">
                    Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']+$drinks['venta_mes']) ?></span><br>
                    Utilidad Mes: <span style="color:#10b981"><?= moneda($central['utilidad']+$drinks['utilidad']) ?></span> <b>(<?= porcentaje($central['utilidad']+$drinks['utilidad'], $central['venta_mes']+$drinks['venta_mes']) ?>)</b><br>
                    Tot. Bodega Costo: <?= moneda($central['inventario']+$drinks['inventario']) ?><br>
                    <b>Tot. Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($central['inv_venta']+$drinks['inv_venta']) ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div>
                <h3>💼 Proveedores</h3>
                <span class="main-value" style="color:#ef4444"><?= moneda($deudaProv) ?></span>
            </div>
            <div>
                <div class="separator"></div>
                <div class="details">
                    <b>Inv. Neto a Compra:</b><br><span style="color:#2563eb"><?= moneda(($central['inventario']+$drinks['inventario'])+$deudaProv) ?></span><br>
                    <b>Inv. Neto a Venta:</b><br><span style="color:#10b981"><?= moneda(($central['inv_venta']+$drinks['inv_venta'])+$deudaProv) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="sections-grid">
        <div class="wrap-box">
            <h4 style="text-align:center; margin-top:0; color:#4b5563;">📊 % PARTICIPACION INVENTARIOS</h4>
            <div style="position: relative; height:200px;"><canvas id="chartInv"></canvas></div>
        </div>
        <div class="wrap-box">
            <h4 style="text-align:center; margin-top:0; color:#4b5563;">🥧 % PARTICIPACION VENTAS</h4>
            <div style="position: relative; height:200px;"><canvas id="chartVenta"></canvas></div>
        </div>

        <!-- PANEL ADICIONAL: TENDENCIA DE VENTAS DÍA A DÍA DEL MES (HASTA HOY, SIN DÍAS EN 0) -->
        <div class="wrap-box full-width" style="border-left: 4px solid #2563eb;">
            <div class="trend-header">
                <h3 style="margin:0; color:#111827; font-size:1.1rem; display:flex; align-items:center; gap:8px;">
                    📉 Tendencia de Ventas Día a Día (01/<?= $mes ?> al <?= str_pad($diaActual, 2, '0', STR_PAD_LEFT) . '/' . $mes ?>)
                </h3>
                <div class="trend-stats">
                    <div class="trend-pill">Acumulado Mes: <span><?= moneda($totalMesConsolidado) ?></span></div>
                    <div class="trend-pill">Prom. Día Vendido: <span><?= moneda($promedioDiarioMes) ?></span></div>
                    <div class="trend-pill">Día Pico (<?= $diaPicoLabel ?>): <span><?= moneda($maxVentaDiaMes) ?></span></div>
                </div>
            </div>
            <div style="position: relative; height: 290px; width: 100%;">
                <?php if(empty($labelsDiasMes)): ?>
                    <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#9ca3af; font-size:0.9rem;">
                        No hay días con ventas registradas hasta la fecha
                    </div>
                <?php else: ?>
                    <canvas id="chartTendenciaVentasMes"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <div class="wrap-box full-width">
            <h3 style="margin-top:0; color:#111827; font-size:1.1rem; display:flex; align-items:center; gap:8px;">🏆 Rendimiento de Ventas por Cajero</h3>
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <?php if(empty($rankingCajeros)): ?>
                    <div style="text-align:center; color:#9ca3af; padding: 10px; font-size: 0.85rem;">No se registran ventas de cajeros el día de hoy</div>
                <?php else: ?>
                    <?php foreach($rankingCajeros as $index =>$caj): 
                        $porcentaje =$ventaGlobalConsolidada > 0 ? ($caj['total'] /$ventaGlobalConsolidada) * 100 : 0;
                    ?>
                    <div class="ranking-item">
                        <div class="ranking-user">
                            <span><?= $index + 1 ?>. <?= htmlspecialchars($caj['nombre']) ?></span>
                            <small style="display:block; color:#9ca3af; font-size:10px; font-weight: normal;"><?= $caj['sede'] ?></small>
                        </div>
                        <div class="ranking-bar-bg">
                            <div class="ranking-bar-fill" style="width: <?= $porcentaje ?>%;"></div>
                        </div>
                        <div class="ranking-total">
                            <span style="color: #6b7280; font-weight: 600;"><?= number_format($porcentaje, 1) ?>%</span>
                            <span><?= moneda($caj['total']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="wrap-box full-width">
            <h3 style="margin-top:0; color:#111827; font-size:1.1rem;">🚚 Compras del Día</h3>
            <div class="table-container">
                <table>
                    <thead><tr><th>Sede</th><th>Proveedor</th><th style="text-align:right">Valor Compra</th></tr></thead>
                    <tbody>
                        <?php foreach($todasLasCompras as$c): ?>
                        <tr>
                            <td><small style="background:#f3f4f6; color:#4b5563; padding:4px 8px; border-radius:6px; font-weight:bold;"><?= $c['sede'] ?></small></td>
                            <td><?= $c['prov'] ?></td>
                            <td style="text-align:right"><span contenteditable="true" class="editable edit-compra" data-id="<?= $c['idcompra'] ?>" data-sede="<?= $c['sede'] ?>"><?= number_format($c['total'], 0, ',', '.') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($todasLasCompras)): ?>
                            <tr><td colspan="3" style="text-align:center; color:#9ca3af;">No hay compras hoy</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<script>
    let secondsLeft = 180;
    const timerDisplay = document.getElementById('countdown');
    const timer = setInterval(() => {
        secondsLeft--;
        let mins = Math.floor(secondsLeft / 60);
        let secs = secondsLeft % 60;
        timerDisplay.innerText = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        if (secondsLeft <= 0) { clearInterval(timer); location.reload(); }
    }, 1000);

    Chart.register(ChartDataLabels);
    const chartOptions = { 
        maintainAspectRatio: false, responsive: true, 
        plugins: { legend: { display: false }, datalabels: { color: '#fff', font: { weight: 'bold', size: 11 }, formatter: (v, c) => { let s = c.chart.data.datasets[0].data.reduce((a, b) => a + b, 0); return s > 0 ? (v * 100 / s).toFixed(1) + "%" : "0%"; } } } 
    };
    
    new Chart(document.getElementById('chartInv'), { 
        type: 'bar', 
        data: { labels: ['Central', 'Drinks'], datasets: [{ data: [<?= $central['inventario'] ?>, <?=$drinks['inventario'] ?>], backgroundColor: ['#2563eb', '#10b981'], borderRadius: 6 }] }, 
        options: chartOptions 
    });

    new Chart(document.getElementById('chartVenta'), { 
        type: 'pie', 
        data: { labels: ['Central', 'Drinks'], datasets: [{ data: [<?= $central['venta_dia'] ?>, <?=$drinks['venta_dia'] ?>], backgroundColor: ['#3b82f6', '#34d399'] }] }, 
        options: { ...chartOptions, plugins: { ...chartOptions.plugins, legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } } 
    });

    // GRÁFICA DE TENDENCIA DE VENTAS DÍA A DÍA (HASTA HOY, SIN DÍAS EN 0)
    const fmtCOP = (val) => '$' + Math.round(Number(val || 0)).toLocaleString('es-CO');
    const canvasTendencia = document.getElementById('chartTendenciaVentasMes');
    if (canvasTendencia) {
        const ctxTendencia = canvasTendencia.getContext('2d');
        const gradTotal = ctxTendencia.createLinearGradient(0, 0, 0, 290);
        gradTotal.addColorStop(0, 'rgba(37, 99, 235, 0.25)');
        gradTotal.addColorStop(1, 'rgba(37, 99, 235, 0.01)');

        new Chart(ctxTendencia, {
            type: 'line',
            data: {
                labels: <?= json_encode($labelsDiasMes) ?>,
                datasets: [
                    {
                        label: 'Total Consolidado',
                        data: <?= json_encode($ventasDiariasTotal) ?>,
                        borderColor: '#2563eb',
                        backgroundColor: gradTotal,
                        borderWidth: 3,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 3.5,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2563eb',
                        datalabels: { display: false }
                    },
                    {
                        label: 'Central',
                        data: <?= json_encode(array_values($ventasDiariasCentral)) ?>,
                        borderColor: '#f97316',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [4, 4],
                        tension: 0.35,
                        fill: false,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        datalabels: { display: false }
                    },
                    {
                        label: 'Drinks',
                        data: <?= json_encode(array_values($ventasDiariasDrinks)) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        datalabels: { display: false }
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { boxWidth: 14, font: { size: 12, weight: 'bold' } }
                    },
                    datalabels: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ` ${ctx.dataset.label}: ${fmtCOP(ctx.parsed.y)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) { return fmtCOP(value); }
                        }
                    }
                }
            }
        });
    }

    document.querySelectorAll('.edit-compra').forEach(el => {
        el.addEventListener('blur', function() {
            const rawVal = this.innerText.replace(/\./g, '').replace(/,/g, '.');
            const val = parseFloat(rawVal);
            if(isNaN(val)) return;
            
            fetch('?action=update_compra', { 
                method: 'POST', 
                body: JSON.stringify({ id: this.dataset.id, sede: this.dataset.sede, valor: val }), 
                headers: { 'Content-Type': 'application/json' } 
            }).then(res => res.json()).then(data => { 
                if(data.success) { 
                    this.innerText = new Intl.NumberFormat('es-CO').format(val); 
                    this.style.backgroundColor = "#dcfce7"; 
                    setTimeout(() => this.style.backgroundColor = "transparent", 1000); 
                } 
            });
        });
    });
</script>
</body>
</html>