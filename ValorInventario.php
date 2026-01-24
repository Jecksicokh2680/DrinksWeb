<?php
session_start();

require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

/* ===============================
   ZONA HORARIA BOGOTÁ
================================ */
date_default_timezone_set('America/Bogota');

function moneda($v){
    return '$' . number_format((float)$v, 0, ',', '.');
}

$fechaHoy = date('Y-m-d');
$horaHoy  = date('H:i:s');
$fechaSQL = date('Y-m-d');
$mes  = date('m');
$anio = date('Y');

$nitEmpresa = $_SESSION['datos']['NitEmpresa'] ?? '000000000';

/* ===============================
   FUNCIÓN DE CÁLCULO POR SUCURSAL
================================ */
function analizarSucursal($mysqli){
    global $mes, $anio, $fechaSQL;

    $inv = $mysqli->query("
        SELECT SUM(I.cantidad * P.costo) AS total
        FROM inventario I
        INNER JOIN productos P ON P.idproducto = I.idproducto
        WHERE I.estado='0'
    ")->fetch_assoc()['total'] ?? 0;

    $ventaDia = $mysqli->query("
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
        ) X
    ")->fetch_assoc()['venta_dia'] ?? 0;

    $r = $mysqli->query("
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
        ) T
    ")->fetch_assoc();

    return [
        'inventario' => $inv,
        'venta_dia'  => $ventaDia,
        'venta_mes'  => $r['ventas'] ?? 0,
        'utilidad'   => $r['utilidad'] ?? 0
    ];
}

/* ===============================
   PROCESAR SUCURSALES
================================ */
$central = analizarSucursal($mysqliCentral);
$drinks  = analizarSucursal($mysqliDrinks);

/* ===============================
   TOTALES
================================ */
$totalInv    = $central['inventario'] + $drinks['inventario'];
$totalVentaD = $central['venta_dia']  + $drinks['venta_dia'];
$totalVentaM = $central['venta_mes']  + $drinks['venta_mes'];
$totalUtil   = $central['utilidad']   + $drinks['utilidad'];
$totalPct    = ($totalVentaM > 0) ? round(($totalUtil / $totalVentaM) * 100,1) : 0;

/* ===============================
   DEUDA PROVEEDORES
================================ */
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

/* ===============================
   INSERTAR / ACTUALIZAR
================================ */
function guardarDia($mysqli, $fecha, $nit, $sucursal, $inv, $vd, $vm, $util){
    $stmt = $mysqli->prepare("
        INSERT INTO fechainventariofisico
        (fecha, nit_empresa, sucursal, valor_bodega, venta_dia, venta_mes, utilidad_mes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            valor_bodega = VALUES(valor_bodega),
            venta_dia    = VALUES(venta_dia),
            venta_mes    = VALUES(venta_mes),
            utilidad_mes = VALUES(utilidad_mes)
    ");
    $stmt->bind_param("sssdddd", $fecha, $nit, $sucursal, $inv, $vd, $vm, $util);
    $stmt->execute();
    $stmt->close();
}

guardarDia($mysqli, $fechaSQL, $nitEmpresa, 'CENTRAL',
    $central['inventario'], $central['venta_dia'], $central['venta_mes'], $central['utilidad']);

guardarDia($mysqli, $fechaSQL, $nitEmpresa, 'DRINKS',
    $drinks['inventario'], $drinks['venta_dia'], $drinks['venta_mes'], $drinks['utilidad']);

/* ===============================
   HISTÓRICO
================================ */
$hist = $mysqli->query("
    SELECT fecha, sucursal, valor_bodega, venta_dia, venta_mes, utilidad_mes
    FROM fechainventariofisico
    WHERE nit_empresa = '$nitEmpresa'
    ORDER BY fecha DESC, sucursal
");
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Consolidado General</title>

<style>
body{font-family:Arial;background:#f4f6f8}
.cards{display:flex;gap:20px;justify-content:center;flex-wrap:wrap;margin:20px}
.card{background:#fff;border-radius:12px;padding:20px;width:320px;
box-shadow:0 2px 10px rgba(0,0,0,.1);text-align:center}
.valor{font-size:24px;font-weight:800;color:#0d6efd}
.green{color:#198754;font-weight:700}
.orange{color:#fd7e14;font-weight:700}
.red{color:#dc3545;font-weight:700}
.total{border:2px solid #0d6efd}
.small{font-size:12px;color:#666}
.line{border-top:1px solid #eee;margin:10px 0}
.btn{padding:8px 14px;border-radius:8px;border:none;color:#fff;font-weight:600;cursor:pointer;margin:0 5px}
.btn-primary{background:#0d6efd}
.btn-success{background:#198754}
</style>
</head>

<body>

<h2 style="text-align:center">
📊 Consolidado Central + Drinks<br>
<small><?= "$anio-$mes-01 a $anio-$mes-31" ?></small>
</h2>

<div style="text-align:center;margin:10px">
    <button onclick="abrirModal()" class="btn btn-primary">📅 Ver Histórico</button>
    <button onclick="location.reload()" class="btn btn-success">🔄 Actualizar Consultas</button>
</div>

<div class="cards">
<div class="card">
<h3>🏢 Central</h3>
<div class="small">Venta Día</div>
<div class="valor"><?= moneda($central['venta_dia']) ?></div>
<div class="line"></div>
Venta Mes: <span class="orange"><?= moneda($central['venta_mes']) ?></span><br>
Utilidad: <span class="green"><?= moneda($central['utilidad']) ?></span><br>
Valor Bodega: <b><?= moneda($central['inventario']) ?></b>
</div>

<div class="card">
<h3>🍹 Drinks</h3>
<div class="small">Venta Día</div>
<div class="valor"><?= moneda($drinks['venta_dia']) ?></div>
<div class="line"></div>
Venta Mes: <span class="orange"><?= moneda($drinks['venta_mes']) ?></span><br>
Utilidad: <span class="green"><?= moneda($drinks['utilidad']) ?></span><br>
Valor Bodega: <b><?= moneda($drinks['inventario']) ?></b>
</div>

<div class="card total">
<h3>📌 Total Consolidado</h3>
<div class="small">Venta Total Día</div>
<div class="valor"><?= moneda($totalVentaD) ?></div>
<div class="line"></div>
Venta Mes: <span class="orange"><?= moneda($totalVentaM) ?></span><br>
Utilidad: <span class="green"><?= moneda($totalUtil) ?></span><br>
Utilidad %: <b><?= $totalPct ?>%</b><br>
Total Bodega: <b><?= moneda($totalInv) ?></b>
</div>
</div>

<div class="cards">
<div class="card total">
<h3>💼 Proveedores</h3>
<div class="line"></div>
Deuda Proveedores:<br>
<span class="red"><?= moneda($deudaProv) ?></span>
<div class="line"></div>
Inventario Neto:<br>
<span class="green"><?= moneda($inventarioNeto) ?></span>
</div>
</div>

<p class="small" style="text-align:center">
Fecha: <?= $fechaHoy ?> | Hora: <?= $horaHoy ?> (Bogotá)
</p>

<div id="modalHistorico" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5)">
<div style="background:#fff;width:90%;max-width:900px;margin:50px auto;border-radius:12px;padding:20px;max-height:80%;overflow:auto">
<h3 style="text-align:center">📊 Histórico</h3>

<table style="width:100%;border-collapse:collapse;font-size:13px">
<tr style="background:#f1f1f1">
<th>Fecha</th><th>Sucursal</th><th>Inventario</th><th>Venta Día</th><th>Venta Mes</th><th>Utilidad</th>
</tr>

<?php while($r = $hist->fetch_assoc()): ?>
<tr style="text-align:center;border-bottom:1px solid #eee">
<td><?= $r['fecha'] ?></td>
<td><?= $r['sucursal'] ?></td>
<td><?= moneda($r['valor_bodega']) ?></td>
<td><?= moneda($r['venta_dia']) ?></td>
<td><?= moneda($r['venta_mes']) ?></td>
<td class="green"><?= moneda($r['utilidad_mes']) ?></td>
</tr>
<?php endwhile; ?>

</table>

<div style="text-align:center;margin-top:15px">
<button onclick="cerrarModal()" style="padding:8px 16px;border:none;border-radius:8px;background:#dc3545;color:#fff;font-weight:600">Cerrar</button>
</div>
</div>
</div>

<script>
function abrirModal(){ document.getElementById('modalHistorico').style.display='block'; }
function cerrarModal(){ document.getElementById('modalHistorico').style.display='none'; }
</script>

</body>
</html>