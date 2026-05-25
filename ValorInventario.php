<?php
session_start();
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

// --- Lógica de guardado de egresos (Sin cambios) ---
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
$fecha_45_atras = date('Y-m-d', strtotime('-45 days'));

$nitSedes = ['CENTRAL' => '86057267-8', 'DRINKS' => '901724534-7'];

function analizarSucursal($mysqli_conn, $nombreSede){
    global $mes, $anio, $fechaSQL, $mysqli, $nitSedes, $fecha_45_atras; 
    if (!$mysqli_conn) return ['inventario'=>0, 'inv_venta'=>0, 'venta_dia'=>0, 'trans_dia'=>0, 'venta_mes'=>0, 'utilidad'=>0];
    
    $nit = $nitSedes[$nombreSede];

    // 1. PROMEDIOS DE VENTA (45 DÍAS)
    $promedios = [];
    $sqlProm = "SELECT IDPRODUCTO, SUM(CANTIDAD * VALORPROD) / SUM(CANTIDAD) as promedio 
                FROM (
                    SELECT IDPRODUCTO, CANTIDAD, VALORPROD FROM DETFACTURAS DF INNER JOIN FACTURAS F ON F.IDFACTURA=DF.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA >= '$fecha_45_atras'
                    UNION ALL
                    SELECT IDPRODUCTO, CANTIDAD, VALORPROD FROM DETPEDIDOS DP INNER JOIN PEDIDOS P ON P.IDPEDIDO=DP.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA >= '$fecha_45_atras'
                ) AS historial GROUP BY IDPRODUCTO";
    
    $resProm = $mysqli_conn->query($sqlProm);
    if($resProm) while($p = $resProm->fetch_assoc()) $promedios[$p['IDPRODUCTO']] = $p['promedio'];

    // 2. INVENTARIOS (COSTO Y VENTA)
    $invCosto = 0;
    $invVenta = 0;
    $resInv = $mysqli_conn->query("SELECT I.idproducto, I.cantidad, P.costo FROM inventario I INNER JOIN productos P ON P.idproducto = I.idproducto WHERE I.estado='0'");
    
    if($resInv){
        while($i = $resInv->fetch_assoc()){
            $invCosto += ($i['cantidad'] * $i['costo']);
            $p_venta = $promedios[$i['idproducto']] ?? $i['costo'];
            $invVenta += ($i['cantidad'] * $p_venta);
        }
    }
    
    // 3. VENTA DÍA
    $ventaDia = $mysqli_conn->query("SELECT SUM(total) AS v FROM (
        SELECT D.CANTIDAD * D.VALORPROD AS total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
        UNION ALL
        SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
    ) X")->fetch_assoc()['v'] ?? 0;

    // 4. TRANSFERENCIAS
    $tr_res = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = '$fechaSQL' AND NitEmpresa = '$nit' AND Estado = 1");
    $transDia = ($tr_res) ? $tr_res->fetch_assoc()['total'] : 0;

    // 5. VENTA MES Y UTILIDAD
    $r = $mysqli_conn->query("SELECT SUM(venta) AS ventas, SUM(util) AS utilidad FROM (
        SELECT D.CANTIDAD * D.VALORPROD AS venta, (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS util FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'
        UNION ALL
        SELECT DP.CANTIDAD * DP.VALORPROD, (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo) FROM PEDIDOS PE INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'
    ) T")->fetch_assoc();

    return [
        'inventario' => $invCosto, 
        'inv_venta'  => $invVenta,
        'venta_dia'  => $ventaDia, 
        'trans_dia'  => $transDia, 
        'venta_mes'  => $r['ventas'] ?? 0, 
        'utilidad'   => $r['utilidad'] ?? 0
    ];
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
        
        /* Ajuste de Header */
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
        .header-top h2 { margin:0; font-size: 1.2rem; color: #1f2937; }

        .timer-box { background: #1e293b; color: #fff; padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .timer-box span { color: #60a5fa; min-width: 45px; }

        /* Grid Responsivo de Tarjetas */
        .grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px; }
        
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); text-align: center; border: 1px solid #e5e7eb; transition: transform 0.2s; }
        .card:hover { transform: translateY(-2px); }
        .card h3 { font-size: 1.1rem; margin: 0 0 12px 0; display: flex; align-items: center; justify-content: center; gap: 8px; color: #111827; }

        .main-value { font-size: 1.6rem; font-weight: 800; color: #2563eb; display: block; margin-bottom: 8px; letter-spacing: -0.5px; word-break: break-all; }
        .details { font-size: 0.85rem; line-height: 1.5; color: #4b5563; }
        .separator { border-top: 1px solid #f3f4f6; margin: 12px 0; }

        /* Grid Responsivo de Secciones Inferiores */
        .sections-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        
        .wrap-box { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e5e7eb; min-width: 0; }
        .full-width { grid-column: 1 / -1; }

        /* Tabla Adaptable */
        .table-container { width: 100%; overflow-x: auto; border-radius: 8px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 500px; }
        th { text-align: left; padding: 12px; background: #1f2937; color: #fff; font-weight: 600; position: sticky; top: 0; }
        td { padding: 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        
        .editable { color: #2563eb; font-weight: bold; border-bottom: 2px dashed #bfdbfe; cursor: pointer; padding: 2px 4px; border-radius: 4px; }

        /* Media Queries para ajustes finos */
        @media (max-width: 600px) {
            body { padding: 5px; }
            .main-value { font-size: 1.4rem; }
            .card { padding: 15px; }
            .sections-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="header-top">
        <h2>Panel Administrativo</h2>
        <div class="timer-box">⏱️ Act: <span id="countdown">03:00</span></div>
    </div>

    <div class="grid-cards">
        <div class="card">
            <h3>🏢 Central</h3>
            <span class="main-value"><?= moneda($central['venta_dia']) ?></span>
            <div class="details">Trans: <?= moneda($central['trans_dia']) ?><br>Neto: <b style="color:#2563eb"><?= moneda($central['venta_dia']-$central['trans_dia']) ?></b></div>
            <div class="separator"></div>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']) ?></span><br>Bodega Costo: <?= moneda($central['inventario']) ?><br><b>Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($central['inv_venta']) ?></span></div>
        </div>

        <div class="card">
            <h3>🍹 Drinks</h3>
            <span class="main-value"><?= moneda($drinks['venta_dia']) ?></span>
            <div class="details">Trans: <?= moneda($drinks['trans_dia']) ?><br>Neto: <b style="color:#2563eb"><?= moneda($drinks['venta_dia']-$drinks['trans_dia']) ?></b></div>
            <div class="separator"></div>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($drinks['venta_mes']) ?></span><br>Bodega Costo: <?= moneda($drinks['inventario']) ?><br><b>Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($drinks['inv_venta']) ?></span></div>
        </div>

        <div class="card" style="border: 2px solid #3b82f6; background-color: #eff6ff;">
            <h3>📌 Totales Consolidados</h3>
            <span class="main-value"><?= moneda($central['venta_dia']+$drinks['venta_dia']) ?></span>
            <div class="details">Venta Mes: <span style="color:#f97316"><?= moneda($central['venta_mes']+$drinks['venta_mes']) ?></span><br>Utilidad Mes: <span style="color:#10b981"><?= moneda($central['utilidad']+$drinks['utilidad']) ?></span></div>
            <div class="separator"></div>
            <div class="details">Tot. Bodega Costo: <?= moneda($central['inventario']+$drinks['inventario']) ?><br><b>Tot. Bodega Venta:</b> <span style="color:#8b5cf6"><?= moneda($central['inv_venta']+$drinks['inv_venta']) ?></span></div>
        </div>

        <div class="card">
            <h3>💼 Proveedores</h3>
            <span class="main-value" style="color:#ef4444"><?= moneda($deudaProv) ?></span>
            <div class="separator"></div>
            <div class="details">
                <b>Inv. Neto a Compra:</b><br><span style="color:#2563eb"><?= moneda(($central['inventario']+$drinks['inventario'])+$deudaProv) ?></span><br>
                <b>Inv. Neto a Venta:</b><br><span style="color:#10b981"><?= moneda(($central['inv_venta']+$drinks['inv_venta'])+$deudaProv) ?></span>
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
        <div class="wrap-box full-width">
            <h3 style="margin-top:0; color:#111827; font-size:1.1rem;">🚚 Compras del Día</h3>
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
        data: { labels: ['Central', 'Drinks'], datasets: [{ data: [<?= $central['inventario'] ?>, <?= $drinks['inventario'] ?>], backgroundColor: ['#2563eb', '#10b981'], borderRadius: 6 }] }, 
        options: chartOptions 
    });

    new Chart(document.getElementById('chartVenta'), { 
        type: 'pie', 
        data: { labels: ['Central', 'Drinks'], datasets: [{ data: [<?= $central['venta_dia'] ?>, <?= $drinks['venta_dia'] ?>], backgroundColor: ['#3b82f6', '#34d399'] }] }, 
        options: { ...chartOptions, plugins: { ...chartOptions.plugins, legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } } 
    });

    document.querySelectorAll('.edit-compra').forEach(el => {
        el.addEventListener('blur', function() {
            const rawVal = this.innerText.replace(/\./g, '').replace(/,/g, '.');
            const val = parseFloat(rawVal);
            if(isNaN(val)) return;
            fetch('?action=update_compra', { 
                method: 'POST', body: JSON.stringify({ id: this.dataset.id, sede: this.dataset.sede, valor: val }), 
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