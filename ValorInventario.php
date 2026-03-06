<?php
session_start();

require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

// ===============================================
// LÓGICA DE GUARDADO PARA EGRESOS EDITABLES
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] == 'update_compra') {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data) {
        $idCompra = intval($data['id']);
        $nuevoValor = floatval(str_replace(['.', ','], ['', '.'], $data['valor']));
        $sede = $data['sede'];
        $db = ($sede === 'CENTRAL') ? $mysqliCentral : $mysqliDrinks;
        if ($db) {
            $query = "UPDATE DETCOMPRAS SET VALOR = ($nuevoValor / CANTIDAD) WHERE idcompra = $idCompra LIMIT 1";
            if ($db->query($query)) { echo json_encode(['success' => true]); } 
            else { echo json_encode(['success' => false]); }
        }
    }
    exit;
}

// ===============================================
// VARIABLES Y LOGICA ORIGINAL
// ===============================================
function moneda($v){
    return '$' . number_format((float)$v, 0, ',', '.');
}

$fechaHoy = date('Y-m-d');
$fechaSQL = date('Y-m-d');
$fechaSinGuion = date('Ymd');
$mes  = date('m');
$anio = date('Y');
$ultimoDiaMes = date('t');
$anioMes = date('Ym');

$horaActual = date('H:i:s');
$proximaActualizacion = date('H:i:s', strtotime('+3 minutes'));

// GENERAR ETIQUETAS CON NOMBRE DE DÍA
$labelsDias = [];
$diasSemana = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
for ($i = 1; $i <= $ultimoDiaMes; $i++) {
    $fechaLabel = "$anio-$mes-" . str_pad($i, 2, "0", STR_PAD_LEFT);
    $nombreDia = $diasSemana[date('w', strtotime($fechaLabel))];
    $labelsDias[] = str_pad($i, 2, "0", STR_PAD_LEFT) . " " . $nombreDia;
}

$nitSedes = [
    'CENTRAL' => '86057267-8',
    'DRINKS'  => '901724534-7'
];

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

function obtenerVentasMensuales($db) {
    global $anioMes, $ultimoDiaMes;
    $ventas = array_fill(1, (int)$ultimoDiaMes, 0);
    if (!$db) return $ventas;
    $q = "SELECT FECHA, SUM(total) as total_dia FROM (
            SELECT F.FECHA, D.CANTIDAD * D.VALORPROD as total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA LIKE '$anioMes%'
            UNION ALL
            SELECT P.FECHA, DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA LIKE '$anioMes%'
          ) X GROUP BY FECHA";
    $res = $db->query($q);
    while($r = @$res->fetch_assoc()){
        $dia = (int)substr($r['FECHA'], 6, 2);
        if($dia >= 1 && $dia <= $ultimoDiaMes) $ventas[$dia] = (float)$r['total_dia'];
    }
    return $ventas;
}

$comprasCentral = obtenerComprasDia($mysqliCentral, 'CENTRAL');
$comprasDrinks  = obtenerComprasDia($mysqliDrinks, 'DRINKS');
$todasLasCompras = array_merge($comprasCentral, $comprasDrinks);
$totalEgresosDia = array_sum(array_column($todasLasCompras, 'total_compra'));

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
        'inventario' => $inv, 'venta_dia'  => $ventaDia, 'trans_dia'  => $transDia,
        'venta_mes'  => $r['ventas'] ?? 0, 'utilidad'   => $r['utilidad'] ?? 0
    ];
}

$central = analizarSucursal($mysqliCentral, 'CENTRAL');
$drinks  = analizarSucursal($mysqliDrinks, 'DRINKS');
$ventasGraficaCentral = obtenerVentasMensuales($mysqliCentral);
$ventasGraficaDrinks  = obtenerVentasMensuales($mysqliDrinks);

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
    <meta http-equiv="refresh" content="180"> 
    <title>Consolidado Administrativo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f7f6; margin: 0; padding: 15px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .status-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .update-info { font-size: 0.9rem; color: #4b5563; font-weight: 500; }
        .next-update { color: #2563eb; font-weight: bold; border-left: 2px solid #e5e7eb; padding-left: 15px; }
        #countdown { color: #ef4444; font-size: 1rem; }

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
    <div class="status-bar">
        <div class="update-info">🕒 Última: <b><?= $horaActual ?></b></div>
        <div class="update-info next-update">
            🔄 Próxima actualización a las: <b><?= $proximaActualizacion ?></b> 
            (En <span id="countdown">180</span>s)
        </div>
    </div>
    
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
            <h4 style="margin:0 0 15px 0; text-align:center;">📊 % PARTICIPACION DE INVENTARIOS</h4>
            <canvas id="graficoBarras" style="max-height: 250px;"></canvas>
        </div>
        <div class="wrap-box">
            <h4 style="margin:0 0 15px 0; text-align:center;">🥧 % PARTICIPACION DE VENTAS</h4>
            <canvas id="graficoTorta" style="max-height: 250px;"></canvas>
        </div>
        
        <div class="wrap-box full-width">
            <h3 style="margin-top:0">🚚 Compras del Día</h3>
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
                            <span contenteditable="true" class="editable edit-compra" data-id="<?= $c['idcompra'] ?>" data-sede="<?= $c['sede'] ?>"><?= number_format($c['total_compra'], 0, ',', '.') ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="wrap-box full-width">
            <h3 style="margin-top:0">📈 Evolución de Ventas Diarias</h3>
            <div style="height: 380px;"><canvas id="graficoTendencia"></canvas></div>
        </div>
    </div>
</div>

<script>
Chart.register(ChartDataLabels);

// --- CONTADOR REGRESIVO ---
let timeLeft = 180;
const timerElement = document.getElementById('countdown');
setInterval(() => {
    timeLeft--;
    if (timeLeft >= 0) {
        timerElement.innerText = timeLeft;
    }
}, 1000);

// --- GRAFICO BARRAS BODEGA ---
new Chart(document.getElementById('graficoBarras'), {
    type: 'bar',
    data: {
        labels: ['Central', 'Drinks'],
        datasets: [{ label: 'Valor Bodega', data: [<?= $central['inventario'] ?>, <?= $drinks['inventario'] ?>], backgroundColor: ['#2563eb', '#10b981'] }]
    },
    options: { 
        responsive: true, maintainAspectRatio: false, 
        plugins: { 
            datalabels: { 
                color: '#fff', font: { weight: 'bold' },
                formatter: (value, ctx) => {
                    let sum = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                    return (value * 100 / sum).toFixed(1) + "%";
                }
            } 
        } 
    }
});

// --- GRAFICO TORTA VENTAS ---
new Chart(document.getElementById('graficoTorta'), {
    type: 'pie',
    data: {
        labels: ['Central', 'Drinks'],
        datasets: [{ data: [<?= $central['venta_dia'] ?>, <?= $drinks['venta_dia'] ?>], backgroundColor: ['#3b82f6', '#34d399'] }]
    },
    options: { 
        responsive: true, maintainAspectRatio: false,
        plugins: { 
            datalabels: { 
                color: '#fff', font: { weight: 'bold' },
                formatter: (value, ctx) => {
                    let sum = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                    return sum > 0 ? (value * 100 / sum).toFixed(1) + "%" : "0%";
                }
            } 
        }
    }
});

// --- GRAFICO TENDENCIA (CON TOTALES EN MILES) ---
new Chart(document.getElementById('graficoTendencia'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labelsDias) ?>,
        datasets: [
            { label: 'Central', data: <?= json_encode(array_values($ventasGraficaCentral)) ?>, backgroundColor: '#2563eb' },
            { label: 'Drinks', data: <?= json_encode(array_values($ventasGraficaDrinks)) ?>, backgroundColor: '#10b981' }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 30 } },
        scales: { 
            x: { stacked: true, ticks: { font: { size: 10 } } }, 
            y: { stacked: true } 
        },
        plugins: { 
            datalabels: {
                anchor: 'end', align: 'top', color: '#1f2937', font: { weight: 'bold', size: 10 },
                formatter: (value, ctx) => {
                    if (ctx.datasetIndex === 1) {
                        let totalDia = ctx.chart.data.datasets[0].data[ctx.dataIndex] + ctx.chart.data.datasets[1].data[ctx.dataIndex];
                        if (totalDia > 0) return (totalDia / 1000).toLocaleString('es-CO', {maximumFractionDigits: 0});
                    }
                    return null;
                }
            }
        }
    }
});

// --- LÓGICA DE EDICIÓN ---
document.querySelectorAll('.edit-compra').forEach(el => {
    el.addEventListener('blur', function() {
        const val = this.innerText.replace(/\./g, '');
        this.style.opacity = "0.5";
        fetch('?action=update_compra', {
            method: 'POST',
            body: JSON.stringify({ id: this.dataset.id, sede: this.dataset.sede, valor: val }),
            headers: { 'Content-Type': 'application/json' }
        }).then(res => res.json()).then(data => {
            this.style.opacity = "1";
            if(data.success) this.innerText = new Intl.NumberFormat('es-CO').format(val);
            else alert("Error al guardar");
        });
    });
});
</script>
</body>
</html>