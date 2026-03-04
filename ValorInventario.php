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
$horaHoy  = date('H:i:s');
$fechaSQL = date('Y-m-d');
$fechaSinGuion = date('Ymd');
$mes  = date('m');
$anio = date('Y');

$nitEmpresa = $_SESSION['datos']['NitEmpresa'] ?? '000000000';

/* ===============================
    NUEVA FUNCIÓN: COMPRAS DEL DÍA
================================ */
function obtenerComprasDia($mysqli_conn, $sede) {
    global $fechaSinGuion;
    if (!$mysqli_conn) return [];
    
    $data = [];
    $res = $mysqli_conn->query("
        SELECT 
            CONCAT(T.nombres, ' ', T.apellidos) AS proveedor,
            SUM(D.CANTIDAD * (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0)) + ( (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * D.porciva / 100) + D.ValICUIUni)) AS total_compra
        FROM compras C
        INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
        INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        WHERE C.FECHA = '$fechaSinGuion' AND C.ESTADO = '0'
        GROUP BY T.NIT
    ");

    while($row = $res->fetch_assoc()) {
        $row['sede'] = $sede;
        $data[] = $row;
    }
    return $data;
}

$comprasCentral = obtenerComprasDia($mysqliCentral, 'CENTRAL');
$comprasDrinks  = obtenerComprasDia($mysqliDrinks, 'DRINKS');
$todasLasCompras = array_merge($comprasCentral, $comprasDrinks);

/* ===============================
    FUNCIÓN DE CÁLCULO POR SUCURSAL
================================ */
function analizarSucursal($mysqli_conn){
    global $mes, $anio, $fechaSQL;
    if (!$mysqli_conn) return ['inventario'=>0, 'venta_dia'=>0, 'venta_mes'=>0, 'utilidad'=>0];

    $inv = $mysqli_conn->query("
        SELECT SUM(I.cantidad * P.costo) AS total
        FROM inventario I
        INNER JOIN productos P ON P.idproducto = I.idproducto
        WHERE I.estado='0'
    ")->fetch_assoc()['total'] ?? 0;

    $ventaDia = $mysqli_conn->query("
        SELECT SUM(total) AS venta_dia FROM (
            SELECT D.CANTIDAD * D.VALORPROD AS total
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
            UNION ALL
            SELECT DP.CANTIDAD * DP.VALORPROD
            FROM PEDIDOS P
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
            WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
            UNION ALL
            SELECT (DDV.CANTIDAD * DDV.VALORPROD) * -1
            FROM DEVVENTAS DV
            INNER JOIN detdevventas DDV ON DV.iddevventas = DDV.iddevventas
            WHERE DATE(DV.fecha)='$fechaSQL'
        ) X
    ")->fetch_assoc()['venta_dia'] ?? 0;

    $r = $mysqli_conn->query("
        SELECT SUM(venta) AS ventas, SUM(util) AS utilidad FROM (
            SELECT 
                D.CANTIDAD * D.VALORPROD AS venta,
                (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS util
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO
            WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'
            UNION ALL
            SELECT 
                DP.CANTIDAD * DP.VALORPROD,
                (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo)
            FROM PEDIDOS PE
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO
            INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO
            WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'
            UNION ALL
            SELECT 
                (DDV.CANTIDAD * DDV.VALORPROD) * -1,
                ((DDV.CANTIDAD * DDV.VALORPROD) - (DDV.CANTIDAD * P.costo)) * -1
            FROM DEVVENTAS DV
            INNER JOIN detdevventas DDV ON DV.iddevventas = DDV.iddevventas
            INNER JOIN productos P ON P.idproducto = DDV.idproducto
            WHERE MONTH(DV.fecha)='$mes' AND YEAR(DV.fecha)='$anio'
        ) T
    ")->fetch_assoc();

    return [
        'inventario' => $inv,
        'venta_dia'  => $ventaDia,
        'venta_mes'  => $r['ventas'] ?? 0,
        'utilidad'   => $r['utilidad'] ?? 0
    ];
}

$central = analizarSucursal($mysqliCentral);
$drinks  = analizarSucursal($mysqliDrinks);

$totalInv    = $central['inventario'] + $drinks['inventario'];
$totalVentaD = $central['venta_dia']  + $drinks['venta_dia'];
$totalVentaM = $central['venta_mes']  + $drinks['venta_mes'];
$totalUtil   = $central['utilidad']   + $drinks['utilidad'];
$totalPct    = ($totalVentaM > 0) ? round(($totalUtil / $totalVentaM) * 100, 1) : 0;

$deudaProv = $mysqli->query("
    SELECT SUM(Saldo) AS total FROM (
        SELECT SUM(p.Monto) AS Saldo
        FROM terceros t
        INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit
        WHERE t.Estado = 1 AND p.Estado = '1'
        GROUP BY t.CedulaNit
        HAVING SUM(p.Monto) <> 0
    ) X
")->fetch_assoc()['total'] ?? 0;

$inventarioNeto = $totalInv + $deudaProv;

// Cálculo de porcentajes para las gráficas
$p_v_c = ($totalVentaD > 0) ? round(($central['venta_dia'] / $totalVentaD) * 100, 1) : 0;
$p_v_d = ($totalVentaD > 0) ? round(($drinks['venta_dia'] / $totalVentaD) * 100, 1) : 0;
$p_i_c = ($totalInv > 0) ? round(($central['inventario'] / $totalInv) * 100, 1) : 0;
$p_i_d = ($totalInv > 0) ? round(($drinks['inventario'] / $totalInv) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="180">
    <title>Consolidado General</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #0d6efd;
            --success: #198754;
            --warning: #fd7e14;
            --danger: #dc3545;
            --dark: #212529;
            --light: #f4f6f8;
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--light); color: #333; margin: 0; padding: 10px; }
        
        /* Estructura Principal */
        .container { max-width: 1400px; margin: 0 auto; }
        
        h2 { text-align: center; font-size: 1.5rem; margin-bottom: 20px; }
        h2 small { font-size: 0.9rem; display: block; margin-top: 5px; }

        /* Tarjetas Responsivas */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .card { background: #fff; border-radius: 12px; padding: 15px; box-shadow: 0 4px 6px rgba(0,0,0,.05); text-align: center; transition: 0.3s; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,.1); }
        .valor { font-size: 1.8rem; font-weight: 800; color: var(--primary); margin: 8px 0; }
        .line { border-top: 1px solid #eee; margin: 12px 0; }
        
        .green { color: var(--success); font-weight: 700; }
        .orange { color: var(--warning); font-weight: 700; }
        .red { color: var(--danger); font-weight: 700; }
        .total { border: 2px solid var(--primary); background: #f0f7ff; }

        /* Botones */
        .btn-group { text-align: center; margin-bottom: 25px; display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; color: #fff; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; font-size: 0.9rem; }
        .btn-primary { background: var(--primary); }
        .btn-success { background: var(--success); }

        /* Contenedor de Gráficos */
        .graficos-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 25px 0; }
        .chart-box { background: #fff; border-radius: 12px; padding: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); min-height: 300px; position: relative; }

        /* Tablas Responsivas */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { background: var(--dark); color: white; padding: 12px; text-align: left; font-size: 0.9rem; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 0.9rem; }

        /* Badges */
        .badge-sede { padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; color: white; text-transform: uppercase; }
        .bg-central { background: var(--primary); }
        .bg-drinks { background: var(--success); }

        /* Modales */
        #modalHistorico { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.7); z-index: 1000; padding: 10px; box-sizing: border-box; }
        .modal-content { background: #fff; width: 100%; max-width: 1000px; margin: 20px auto; border-radius: 15px; padding: 20px; max-height: 90vh; overflow-y: auto; }

        /* Media Queries para ajustes finos */
        @media (max-width: 600px) {
            body { padding: 5px; }
            .valor { font-size: 1.5rem; }
            .card { padding: 10px; }
            h2 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>📊 Consolidado General<br>
        <small style="color:#666"><?= "Actualizado: $horaHoy | Periodo: $anio-$mes" ?></small>
    </h2>

    <div class="btn-group">
        <button onclick="abrirModal()" class="btn btn-primary">📅 Ver Histórico</button>
        <button onclick="location.reload()" class="btn btn-success">🔄 Actualizar</button>
    </div>

    <div class="cards">
        <div class="card">
            <h3>🏢 Central</h3>
            <div class="valor"><?= moneda($central['venta_dia']) ?></div>
            <div class="line"></div>
            Venta Mes: <span class="orange"><?= moneda($central['venta_mes']) ?></span><br>
            Utilidad: <span class="green"><?= moneda($central['utilidad']) ?></span><br>
            Bodega: <b><?= moneda($central['inventario']) ?></b>
        </div>

        <div class="card">
            <h3>🍹 Drinks</h3>
            <div class="valor"><?= moneda($drinks['venta_dia']) ?></div>
            <div class="line"></div>
            Venta Mes: <span class="orange"><?= moneda($drinks['venta_mes']) ?></span><br>
            Utilidad: <span class="green"><?= moneda($drinks['utilidad']) ?></span><br>
            Bodega: <b><?= moneda($drinks['inventario']) ?></b>
        </div>

        <div class="card total">
            <h3>📌 Total Neto</h3>
            <div class="valor"><?= moneda($totalVentaD) ?></div>
            <div class="line"></div>
            Venta Mes: <span class="orange"><?= moneda($totalVentaM) ?></span><br>
            Utilidad: <span class="green"><?= moneda($totalUtil) ?> (<?= $totalPct ?>%)</span><br>
            Total Bodega: <b><?= moneda($totalInv) ?></b>
        </div>

        <div class="card">
            <h3>💼 Proveedores</h3>
            Deuda Actual:<br><span class="red" style="font-size:1.4rem"><?= moneda($deudaProv) ?></span>
            <div class="line"></div>
            <b>Inventario Neto:</b><br>
            <span class="green" style="font-size:1.4rem"><?= moneda($inventarioNeto) ?></span>
        </div>
    </div>

    <div class="graficos-container">
        <div class="chart-box">
            <canvas id="graficoVentas"></canvas>
        </div>
        <div class="chart-box">
            <canvas id="graficoInventario"></canvas>
        </div>
    </div>

    <h3 style="margin-top:30px">🚚 Compras del Día (<?= $fechaHoy ?>)</h3>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Sede</th>
                    <th>Proveedor</th>
                    <th style="text-align:right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $granTotalCompras = 0;
                if(!empty($todasLasCompras)):
                    foreach($todasLasCompras as $c): 
                        $granTotalCompras += $c['total_compra'];
                        $claseSede = ($c['sede'] == 'CENTRAL') ? 'bg-central' : 'bg-drinks';
                ?>
                    <tr>
                        <td><span class="badge-sede <?= $claseSede ?>"><?= $c['sede'] ?></span></td>
                        <td><?= $c['proveedor'] ?></td>
                        <td style="text-align:right; font-weight:bold;"><?= moneda($c['total_compra']) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#f8f9fa;">
                        <td colspan="2" style="text-align:right; font-weight:bold;">TOTAL COMPRAS HOY:</td>
                        <td style="text-align:right; font-weight:bold; color:var(--danger); font-size:1.1rem;"><?= moneda($granTotalCompras) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align:center; color:#999;">No se han registrado compras.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="modalHistorico">
    <div class="modal-content">
        <h3 style="text-align:center">📋 Histórico Completo</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Sucursal</th>
                        <th>Bodega</th>
                        <th>Venta Día</th>
                        <th>Venta Mes</th>
                        <th>Utilidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $hist = $mysqli->query("SELECT * FROM fechainventariofisico WHERE nit_empresa = '$nitEmpresa' ORDER BY fecha DESC, sucursal ASC");
                    if($hist && $hist->num_rows > 0):
                        while($r = $hist->fetch_assoc()): ?>
                        <tr>
                            <td><b><?= $r['fecha'] ?></b></td>
                            <td><?= $r['sucursal'] ?></td>
                            <td><?= moneda($r['valor_bodega']) ?></td>
                            <td><?= moneda($r['venta_dia']) ?></td>
                            <td><?= moneda($r['venta_mes']) ?></td>
                            <td class="green"><?= moneda($r['utilidad_mes']) ?></td>
                        </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
        <div style="text-align:center;margin-top:20px">
            <button onclick="cerrarModal()" class="btn" style="background:#666">Cerrar</button>
        </div>
    </div>
</div>

<script>
function abrirModal(){ document.getElementById('modalHistorico').style.display='block'; }
function cerrarModal(){ document.getElementById('modalHistorico').style.display='none'; }

// Configuración de Gráficos Responsivos
const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false }
    }
};

new Chart(document.getElementById('graficoVentas'), {
    type: 'bar',
    data: {
        labels: ['Central (<?= $p_v_c ?>%)', 'Drinks (<?= $p_v_d ?>%)'],
        datasets: [{
            label: 'Ventas del Día',
            data: [<?= $central['venta_dia'] ?>, <?= $drinks['venta_dia'] ?>],
            backgroundColor: ['#0d6efd', '#0dcaf0']
        }]
    },
    options: {
        ...commonOptions,
        plugins: { 
            title: { display: true, text: 'Ventas por Sede' },
            legend: { display: false }
        }
    }
});

new Chart(document.getElementById('graficoInventario'), {
    type: 'bar',
    data: {
        labels: ['Central (<?= $p_i_c ?>%)', 'Drinks (<?= $p_i_d ?>%)'],
        datasets: [{
            label: 'Valor Bodega',
            data: [<?= $central['inventario'] ?>, <?= $drinks['inventario'] ?>],
            backgroundColor: ['#198754', '#20c997']
        }]
    },
    options: {
        ...commonOptions,
        plugins: { 
            title: { display: true, text: 'Inventario por Sede' },
            legend: { display: false }
        }
    }
});
</script>

</body>
</html>