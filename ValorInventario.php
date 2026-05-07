<?php
session_start();
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

// Lógica de guardado rápido para egresos (Se mantiene igual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] == 'update_compra') {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data) {
        $idCompra = intval($data['id']);
        $nuevoValor = floatval(preg_replace('/[^0-9.]/', '', str_replace(',', '.', $data['valor'])));
        $db = ($data['sede'] === 'CENTRAL') ? $mysqliCentral : $mysqliDrinks;
        if ($db) {
            $stmt = $db->prepare("UPDATE DETCOMPRAS SET VALOR = (? / NULLIF(CANTIDAD, 0)) WHERE idcompra = ?");
            $stmt->bind_param("di", $nuevoValor, $idCompra);
            echo json_encode(['success' => $stmt->execute()]);
            $stmt->close();
        }
    }
    exit;
}

function moneda($v){ return '$' . number_format((float)$v, 0, ',', '.'); }

$fechaSQL = date('Y-m-d');
$fechaSinGuion = date('Ymd');
$mes = date('m');
$anio = date('Y');

$nitSedes = ['CENTRAL' => '86057267-8', 'DRINKS' => '901724534-7'];

function analizarSucursal($mysqli_conn, $nombreSede){
    global $mes, $anio, $fechaSQL, $mysqli, $nitSedes; 
    if (!$mysqli_conn) return ['inventario'=>0, 'venta_dia'=>0, 'trans_dia'=>0, 'venta_mes'=>0, 'utilidad'=>0];
    
    $nit = $nitSedes[$nombreSede];
    $inv = $mysqli_conn->query("SELECT SUM(I.cantidad * P.costo) AS total FROM inventario I INNER JOIN productos P ON P.idproducto = I.idproducto WHERE I.estado='0'")->fetch_assoc()['total'] ?? 0;
    
    $ventaDia = $mysqli_conn->query("SELECT SUM(total) AS v FROM (
        SELECT D.CANTIDAD * D.VALORPROD AS total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
        UNION ALL
        SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
    ) X")->fetch_assoc()['v'] ?? 0;

    $tr_res = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = '$fechaSQL' AND NitEmpresa = '$nit' AND Estado = 1");
    $transDia = ($tr_res) ? $tr_res->fetch_assoc()['total'] : 0;

    $r = $mysqli_conn->query("SELECT SUM(venta) AS ventas, SUM(util) AS utilidad FROM (
        SELECT D.CANTIDAD * D.VALORPROD AS venta, (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS util FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'
        UNION ALL
        SELECT DP.CANTIDAD * DP.VALORPROD, (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo) FROM PEDIDOS PE INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'
    ) T")->fetch_assoc();

    return ['inventario' => $inv, 'venta_dia' => $ventaDia, 'trans_dia' => $transDia, 'venta_mes' => $r['ventas'] ?? 0, 'utilidad' => $r['utilidad'] ?? 0];
}

$central = analizarSucursal($mysqliCentral, 'CENTRAL');
$drinks  = analizarSucursal($mysqliDrinks, 'DRINKS');

// Compras del día
$todasLasCompras = [];
foreach(['CENTRAL' => $mysqliCentral, 'DRINKS' => $mysqliDrinks] as $s => $db){
    $res = $db->query("SELECT C.idcompra, CONCAT(T.nombres, ' ', T.apellidos) AS prov, SUM(D.CANTIDAD * (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0)) + ( (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * D.porciva / 100) + D.ValICUIUni)) AS total FROM compras C INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra WHERE C.FECHA = '$fechaSinGuion' AND C.ESTADO = '0' GROUP BY C.idcompra");
    while($row = $res->fetch_assoc()){ $row['sede'] = $s; $todasLasCompras[] = $row; }
}

$deudaProv = $mysqli->query("SELECT SUM(Saldo) AS total FROM (SELECT SUM(p.Monto) AS Saldo FROM terceros t INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit WHERE t.Estado = 1 AND p.Estado = '1' GROUP BY t.CedulaNit HAVING SUM(p.Monto) <> 0) X")->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #f4f7f6; margin: 0; padding: 10px; color: #374151; }
        
        /* Estilo del Contador */
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 0 5px; }
        .timer-box { background: #1e293b; color: #fff; padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .timer-box span { color: #60a5fa; min-width: 45px; }

        .grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); text-align: center; border: 1px solid #e5e7eb; transition: transform 0.2s; }
        .card:hover { transform: translateY(-2px); }
        .card h3 { font-size: 1.1rem; margin: 0 0 12px 0; display: flex; align-items: center; justify-content: center; gap: 8px; color: #111827; }
        .main-value { font-size: 1.75rem; font-weight: 800; color: #2563eb; display: block; margin-bottom: 8px; letter-spacing: -0.5px; }
        .details { font-size: 0.9rem; line-height: 1.5; color: #4b5563; }
        .separator { border-top: 1px solid #f3f4f6; margin: 12px 0; }
        .sections-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .wrap-box { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; }
        .full-width { grid-column: span 2; }
        .table-container { width: 100%; overflow-x: auto; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 500px; }
        th { text-align: left; padding: 12px; background: #1f2937; color: #fff; font-weight: 600; }
        td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        .editable { color: #2563eb; font-weight: bold; border-bottom: 2px dashed #bfdbfe; cursor: pointer; padding: 2px 4px; border-radius: 4px; }
        .editable:focus { outline: none; background: #eff6ff; border-bottom-color: #2563eb; }

        @media (max-width: 768px) {
            .sections-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
            .main-value { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

    <div class="header-top">
        <h2 style="margin:0; font-size: 1.2rem; color: #1f2937;">Panel Administrativo</h2>
        <div class="timer-box">
            ⏱️ Actualización en: <span id="countdown">03:00</span>
        </div>
    </div>

    <div class="grid-cards">
        <div class="card">
            <h3>🏢 Central</h3>
            <span class="main-value"><?= moneda($central['venta_dia']) ?></span>
            <div class="details">Trans: <?= moneda($central['trans_dia']) ?><br>Neto: <b style="color:#2563eb"><?= moneda($central['venta_dia']-$central['trans_dia']) ?></b></div>
            <div class="separator"></div>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']) ?></span><br>Utilidad: <span style="color:#10b981"><?= moneda($central['utilidad']) ?></span><br>Bodega: <?= moneda($central['inventario']) ?></div>
        </div>

        <div class="card">
            <h3>🍹 Drinks</h3>
            <span class="main-value"><?= moneda($drinks['venta_dia']) ?></span>
            <div class="details">Trans: <?= moneda($drinks['trans_dia']) ?><br>Neto: <b style="color:#2563eb"><?= moneda($drinks['venta_dia']-$drinks['trans_dia']) ?></b></div>
            <div class="separator"></div>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($drinks['venta_mes']) ?></span><br>Utilidad: <span style="color:#10b981"><?= moneda($drinks['utilidad']) ?></span><br>Bodega: <?= moneda($drinks['inventario']) ?></div>
        </div>

        <div class="card" style="border: 2px solid #3b82f6; background-color: #eff6ff;">
            <h3>📌 Total Neto</h3>
            <span class="main-value"><?= moneda($central['venta_dia']+$drinks['venta_dia']) ?></span>
            <div class="details">Trans: <?= moneda($central['trans_dia']+$drinks['trans_dia']) ?><br>Neto Hoy: <b style="color:#2563eb"><?= moneda(($central['venta_dia']+$drinks['venta_dia'])-($central['trans_dia']+$drinks['trans_dia'])) ?></b></div>
            <div class="separator"></div>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']+$drinks['venta_mes']) ?></span><br>Utilidad: <span style="color:#10b981"><?= moneda($central['utilidad']+$drinks['utilidad']) ?></span><br>Bodega: <?= moneda($central['inventario']+$drinks['inventario']) ?></div>
        </div>

        <div class="card">
            <h3>💼 Proveedores</h3>
            <span class="main-value" style="color:#ef4444"><?= moneda($deudaProv) ?></span>
            <div class="separator"></div>
            <div class="details"><b>Inventario Neto:</b><br><span style="color:#10b981; font-size:1.1rem"><?= moneda(($central['inventario']+$drinks['inventario'])+$deudaProv) ?></span></div>
        </div>
    </div>

    <div class="sections-grid">
        <div class="wrap-box">
            <h4 style="text-align:center; margin-top:0; color:#4b5563;">📊 % PARTICIPACION DE INVENTARIOS</h4>
            <div style="position: relative; height:200px;">
                <canvas id="chartInv"></canvas>
            </div>
        </div>
        <div class="wrap-box">
            <h4 style="text-align:center; margin-top:0; color:#4b5563;">🥧 % PARTICIPACION DE VENTAS</h4>
            <div style="position: relative; height:200px;">
                <canvas id="chartVenta"></canvas>
            </div>
        </div>
        <div class="wrap-box full-width">
            <h3 style="margin-top:0; color:#111827;">🚚 Compras del Día</h3>
            <div class="table-container">
                <table>
                    <thead><tr><th>Sede</th><th>Proveedor</th><th style="text-align:right">Valor Compra</th></tr></thead>
                    <tbody>
                        <?php foreach($todasLasCompras as $c): ?>
                        <tr>
                            <td><small style="background:#f3f4f6; color:#4b5563; padding:4px 8px; border-radius:6px; font-weight:bold;"><?= $c['sede'] ?></small></td>
                            <td><?= $c['prov'] ?></td>
                            <td style="text-align:right"><span contenteditable="true" class="editable edit-compra" data-id="<?= $c['idcompra'] ?>" data-sede="<?= $c['sede'] ?>"><?= number_format($c['total'], 0, ',', '.') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($todasLasCompras)): ?>
                            <tr><td colspan="3" style="text-align:center; color:#9ca3af;">No hay compras registradas hoy</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<script>
    // Lógica del Contador (3 minutos)
    let secondsLeft = 180;
    const timerDisplay = document.getElementById('countdown');

    const timer = setInterval(() => {
        secondsLeft--;
        let mins = Math.floor(secondsLeft / 60);
        let secs = secondsLeft % 60;
        timerDisplay.innerText = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;

        if (secondsLeft <= 0) {
            clearInterval(timer);
            location.reload();
        }
    }, 1000);

    // Gráficas (Se mantiene igual)
    Chart.register(ChartDataLabels);
    const chartOptions = { 
        maintainAspectRatio: false,
        responsive: true, 
        plugins: { 
            legend: { display: false },
            datalabels: { 
                color: '#fff', 
                font: { weight: 'bold', size: 12 }, 
                formatter: (v, c) => { 
                    let s = c.chart.data.datasets[0].data.reduce((a, b) => a + b, 0); 
                    return s > 0 ? (v * 100 / s).toFixed(1) + "%" : "0%"; 
                } 
            } 
        } 
    };
    
    new Chart(document.getElementById('chartInv'), { 
        type: 'bar', 
        data: { 
            labels: ['Central', 'Drinks'], 
            datasets: [{ data: [<?= $central['inventario'] ?>, <?= $drinks['inventario'] ?>], backgroundColor: ['#2563eb', '#10b981'], borderRadius: 6 }] 
        }, 
        options: chartOptions 
    });

    new Chart(document.getElementById('chartVenta'), { 
        type: 'pie', 
        data: { 
            labels: ['Central', 'Drinks'], 
            datasets: [{ data: [<?= $central['venta_dia'] ?>, <?= $drinks['venta_dia'] ?>], backgroundColor: ['#3b82f6', '#34d399'] }] 
        }, 
        options: { ...chartOptions, plugins: { ...chartOptions.plugins, legend: { display: true, position: 'bottom' } } } 
    });

    // Guardado de egresos (Se mantiene igual)
    document.querySelectorAll('.edit-compra').forEach(el => {
        el.addEventListener('blur', function() {
            const rawVal = this.innerText.replace(/\./g, '').replace(/,/g, '.');
            const val = parseFloat(rawVal);
            if(isNaN(val)) return;

            fetch('?action=update_compra', { 
                method: 'POST', 
                body: JSON.stringify({ id: this.dataset.id, sede: this.dataset.sede, valor: val }), 
                headers: { 'Content-Type': 'application/json' } 
            })
            .then(res => res.json())
            .then(data => { 
                if(data.success) {
                    this.innerText = new Intl.NumberFormat('es-CO').format(val);
                    this.style.backgroundColor = "#dcfce7";
                    setTimeout(() => this.style.backgroundColor = "transparent", 1000);
                } else {
                    alert("Error al actualizar");
                }
            });
        });
    });
</script>
</body>
</html>