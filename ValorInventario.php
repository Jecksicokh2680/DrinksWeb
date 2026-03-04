<?php
session_start();

require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

function moneda($v){
    return '$' . number_format((float)$v, 0, ',', '.');
}

$fechaHoy = date('Y-m-d');
$fechaSQL = date('Y-m-d');
$fechaSinGuion = date('Ymd');
$mes  = date('m');
$anio = date('Y');

$nitSedes = [
    'CENTRAL' => '86057267-8',
    'DRINKS'  => '901724534-7'
];

/* ===============================
    FUNCIÓN: COMPRAS DEL DÍA
================================ */
function obtenerComprasDia($mysqli_conn, $sede) {
    global $fechaSinGuion;
    if (!$mysqli_conn) return [];
    
    $data = [];
    $res = $mysqli_conn->query("
        SELECT 
            C.idcompra,
            CONCAT(T.nombres, ' ', T.apellidos) AS proveedor,
            SUM(D.CANTIDAD * (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0)) + ( (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * D.porciva / 100) + D.ValICUIUni)) AS total_compra
        FROM compras C
        INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
        INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        WHERE C.FECHA = '$fechaSinGuion' AND C.ESTADO = '0'
        GROUP BY T.NIT, C.idcompra
    ");

    if($res){
        while($row = $res->fetch_assoc()) {
            $row['sede'] = $sede;
            $data[] = $row;
        }
    }
    return $data;
}

$comprasCentral = obtenerComprasDia($mysqliCentral, 'CENTRAL');
$comprasDrinks  = obtenerComprasDia($mysqliDrinks, 'DRINKS');
$todasLasCompras = array_merge($comprasCentral, $comprasDrinks);
$totalEgresosDia = array_sum(array_column($todasLasCompras, 'total_compra'));

/* ===============================
    FUNCIÓN DE CÁLCULO POR SUCURSAL
================================ */
function analizarSucursal($mysqli_conn, $nombreSede){
    global $mes, $anio, $fechaSQL, $mysqli, $nitSedes; 
    if (!$mysqli_conn) return ['inventario'=>0, 'venta_dia'=>0, 'trans_dia'=>0, 'venta_mes'=>0, 'utilidad'=>0];

    $nitEspecifico = $nitSedes[$nombreSede] ?? '';

    $inv = $mysqli_conn->query("SELECT SUM(I.cantidad * P.costo) AS total FROM inventario I INNER JOIN productos P ON P.idproducto = I.idproducto WHERE I.estado='0'")->fetch_assoc()['total'] ?? 0;

    $ventaDia = $mysqli_conn->query("
        SELECT SUM(total) AS venta_dia FROM (
            SELECT D.CANTIDAD * D.VALORPROD AS total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
            UNION ALL
            SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
            UNION ALL
            SELECT (DDV.CANTIDAD * DDV.VALORPROD) * -1 FROM DEVVENTAS DV INNER JOIN detdevventas DDV ON DV.iddevventas = DDV.iddevventas WHERE DATE(DV.fecha)='$fechaSQL'
        ) X
    ")->fetch_assoc()['venta_dia'] ?? 0;

    $transDia = 0;
    if ($nitEspecifico != '') {
        $tr_res = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = '$fechaSQL' AND NitEmpresa = '$nitEspecifico' AND Estado = 1");
        $transDia = ($tr_res) ? $tr_res->fetch_assoc()['total'] : 0;
    }

    $r = $mysqli_conn->query("
        SELECT SUM(venta) AS ventas, SUM(util) AS utilidad FROM (
            SELECT D.CANTIDAD * D.VALORPROD AS venta, (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS util
            FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'
            UNION ALL
            SELECT DP.CANTIDAD * DP.VALORPROD, (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo)
            FROM PEDIDOS PE INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'
        ) T
    ")->fetch_assoc();

    return [
        'inventario' => $inv,
        'venta_dia'  => $ventaDia,
        'trans_dia'  => $transDia,
        'venta_mes'  => $r['ventas'] ?? 0,
        'utilidad'   => $r['utilidad'] ?? 0
    ];
}

$central = analizarSucursal($mysqliCentral, 'CENTRAL');
$drinks  = analizarSucursal($mysqliDrinks, 'DRINKS');

$totalVentaD = $central['venta_dia'] + $drinks['venta_dia'];
$totalTransD = $central['trans_dia'] + $drinks['trans_dia'];
$totalNetoD  = $totalVentaD - $totalTransD;
$totalVentaM = $central['venta_mes'] + $drinks['venta_mes'];
$totalUtilM  = $central['utilidad'] + $drinks['utilidad'];
$totalBodega = $central['inventario'] + $drinks['inventario'];
$pctUtil     = ($totalVentaM > 0) ? round(($totalUtilM / $totalVentaM) * 100, 1) : 0;

$deudaProv = $mysqli->query("SELECT SUM(Saldo) AS total FROM (SELECT SUM(p.Monto) AS Saldo FROM terceros t INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit WHERE t.Estado = 1 AND p.Estado = '1' GROUP BY t.CedulaNit HAVING SUM(p.Monto) <> 0) X")->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado Administrativo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f7f6; margin: 0; padding: 15px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .card { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border: 1px solid #eee; }
        .card-total { border: 2px solid #3b82f6; background-color: #eff6ff; }
        .card .main-value { font-size: 1.6rem; font-weight: 800; color: #2563eb; margin: 5px 0; display: block; }
        .separator { border-top: 1px solid #f3f4f6; margin: 12px 0; }
        .details { font-size: 0.85rem; line-height: 1.5; color: #6b7280; }
        .val-orange { color: #f97316; font-weight: bold; }
        .val-green { color: #10b981; font-weight: bold; }
        .val-blue { color: #2563eb; font-weight: bold; }
        .sections-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 20px; }
        @media (min-width: 1024px) { .sections-grid { grid-template-columns: 1fr 1fr; } .full-width { grid-column: span 2; } }
        .wrap-box { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 400px; }
        th { text-align: left; padding: 12px; background: #1f2937; color: #fff; font-size: 0.8rem; }
        td { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 0.85rem; }
        .total-row { background: #f9fafb; font-weight: bold; }
        .editable { color: #2563eb; font-weight: bold; border-bottom: 1px dashed; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <div class="grid-cards">
        <div class="card">
            <h3>🏢 Central</h3>
            <span class="main-value"><?= moneda($central['venta_dia']) ?></span>
            <div class="details">
                Trans: <b><?= moneda($central['trans_dia']) ?></b><br>
                Neto: <b class="val-blue"><?= moneda($central['venta_dia'] - $central['trans_dia']) ?></b>
            </div>
            <div class="separator"></div>
            <div class="details">
                Venta Mes: <span class="val-orange"><?= moneda($central['venta_mes']) ?></span><br>
                Utilidad: <span class="val-green"><?= moneda($central['utilidad']) ?></span><br>
                Bodega: <b><?= moneda($central['inventario']) ?></b>
            </div>
        </div>

        <div class="card">
            <h3>🍹 Drinks</h3>
            <span class="main-value"><?= moneda($drinks['venta_dia']) ?></span>
            <div class="details">
                Trans: <b><?= moneda($drinks['trans_dia']) ?></b><br>
                Neto: <b class="val-blue"><?= moneda($drinks['venta_dia'] - $drinks['trans_dia']) ?></b>
            </div>
            <div class="separator"></div>
            <div class="details">
                Venta Mes: <span class="val-orange"><?= moneda($drinks['venta_mes']) ?></span><br>
                Utilidad: <span class="val-green"><?= moneda($drinks['utilidad']) ?></span><br>
                Bodega: <b><?= moneda($drinks['inventario']) ?></b>
            </div>
        </div>

        <div class="card card-total">
            <h3>📌 Total Neto</h3>
            <span class="main-value"><?= moneda($totalVentaD) ?></span>
            <div class="details">
                Trans: <b><?= moneda($totalTransD) ?></b><br>
                Neto Hoy: <b class="val-blue"><?= moneda($totalNetoD) ?></b>
            </div>
            <div class="separator"></div>
            <div class="details">
                Venta Mes: <span class="val-orange"><?= moneda($totalVentaM) ?></span><br>
                Utilidad: <span class="val-green"><?= moneda($totalUtilM) ?> (<?= $pctUtil ?>%)</span><br>
                Total Bodega: <b><?= moneda($totalBodega) ?></b>
            </div>
        </div>

        <div class="card">
            <h3>💼 Proveedores</h3>
            <span class="main-value" style="color:#ef4444"><?= moneda($deudaProv) ?></span>
            <div class="separator"></div>
            <div class="details">
                <b>Inventario Neto:</b><br>
                <span class="val-green" style="font-size:1.3rem"><?= moneda($totalBodega + $deudaProv) ?></span>
            </div>
        </div>
    </div>

    <div class="sections-grid">
        <div class="wrap-box">
            <h4 style="margin:0 0 15px 0; text-align:center;">📊 % Valor de Bodega por Sede</h4>
            <canvas id="graficoBarras" style="max-height: 250px;"></canvas>
        </div>
        <div class="wrap-box">
            <h4 style="margin:0 0 15px 0; text-align:center;">🥧 % Participación Ventas Hoy</h4>
            <canvas id="graficoTorta" style="max-height: 250px;"></canvas>
        </div>
        
        <div class="wrap-box full-width">
            <h3 style="margin-top:0">🚚 Egresos del Día (Editables)</h3>
            <table>
                <thead>
                    <tr><th>Sede</th><th>Proveedor</th><th style="text-align:right">Valor Compra</th></tr>
                </thead>
                <tbody>
                    <?php foreach($todasLasCompras as $c): ?>
                    <tr>
                        <td><small style="background:#eee; padding:2px 5px; border-radius:3px"><?= $c['sede'] ?></small></td>
                        <td><?= $c['proveedor'] ?></td>
                        <td style="text-align:right">
                            <span contenteditable="true" class="editable"><?= number_format($c['total_compra'], 0, ',', '.') ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2" style="text-align:right">TOTAL EGRESOS HOY:</td>
                        <td style="text-align:right; color:#ef4444; font-size:1.1rem"><?= moneda($totalEgresosDia) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
Chart.register(ChartDataLabels);

const configLabels = {
    color: '#fff',
    font: { weight: 'bold', size: 12 },
    formatter: (value, ctx) => {
        let sum = 0;
        let dataArr = ctx.chart.data.datasets[0].data;
        dataArr.map(data => { sum += data; });
        let percentage = (value * 100 / sum).toFixed(1) + "%";
        return percentage;
    }
};

new Chart(document.getElementById('graficoBarras'), {
    type: 'bar',
    data: {
        labels: ['Central', 'Drinks'],
        datasets: [{
            label: 'Valor Bodega',
            data: [<?= $central['inventario'] ?>, <?= $drinks['inventario'] ?>],
            backgroundColor: ['#2563eb', '#10b981']
        }]
    },
    options: { 
        responsive: true, maintainAspectRatio: false,
        plugins: { datalabels: configLabels }
    }
});

new Chart(document.getElementById('graficoTorta'), {
    type: 'pie',
    data: {
        labels: ['Central', 'Drinks'],
        datasets: [{
            data: [<?= $central['venta_dia'] ?>, <?= $drinks['venta_dia'] ?>],
            backgroundColor: ['#3b82f6', '#34d399']
        }]
    },
    options: { 
        responsive: true, maintainAspectRatio: false,
        plugins: { datalabels: configLabels }
    }
});
</script>

</body>
</html>