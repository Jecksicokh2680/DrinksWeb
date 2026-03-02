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

// Validación de sesión para el NIT
$nitEmpresa = $_SESSION['datos']['NitEmpresa'] ?? '000000000';

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

/* ===============================
    PROCESAR Y GUARDAR DATOS
================================ */
$central = analizarSucursal($mysqliCentral);
$drinks  = analizarSucursal($mysqliDrinks);

$totalInv    = $central['inventario'] + $drinks['inventario'];
$totalVentaD = $central['venta_dia']  + $drinks['venta_dia'];
$totalVentaM = $central['venta_mes']  + $drinks['venta_mes'];
$totalUtil   = $central['utilidad']   + $drinks['utilidad'];
$totalPct    = ($totalVentaM > 0) ? round(($totalUtil / $totalVentaM) * 100, 1) : 0;

// Deuda Proveedores
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

// Guardado Automático (Upsert)
function guardarDia($db, $fecha, $nit, $suc, $inv, $vd, $vm, $ut){
    $stmt = $db->prepare("INSERT INTO fechainventariofisico (fecha, nit_empresa, sucursal, valor_bodega, venta_dia, venta_mes, utilidad_mes) 
                          VALUES (?, ?, ?, ?, ?, ?, ?) 
                          ON DUPLICATE KEY UPDATE valor_bodega=VALUES(valor_bodega), venta_dia=VALUES(venta_dia), venta_mes=VALUES(venta_mes), utilidad_mes=VALUES(utilidad_mes)");
    $stmt->bind_param("sssdddd", $fecha, $nit, $suc, $inv, $vd, $vm, $ut);
    $stmt->execute();
    $stmt->close();
}

guardarDia($mysqli, $fechaSQL, $nitEmpresa, 'CENTRAL', $central['inventario'], $central['venta_dia'], $central['venta_mes'], $central['utilidad']);
guardarDia($mysqli, $fechaSQL, $nitEmpresa, 'DRINKS', $drinks['inventario'], $drinks['venta_dia'], $drinks['venta_mes'], $drinks['utilidad']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado General</title>
    <style>
        body{font-family:'Segoe UI',Arial;background:#f4f6f8;color:#333;margin:0;padding:20px}
        .cards{display:flex;gap:20px;justify-content:center;flex-wrap:wrap;margin-bottom:30px}
        .card{background:#fff;border-radius:12px;padding:20px;width:300px;box-shadow:0 4px 6px rgba(0,0,0,.05);text-align:center;transition:0.3s}
        .card:hover{transform:translateY(-5px);box-shadow:0 8px 15px rgba(0,0,0,.1)}
        .valor{font-size:26px;font-weight:800;color:#0d6efd;margin:10px 0}
        .green{color:#198754;font-weight:700}
        .orange{color:#fd7e14;font-weight:700}
        .red{color:#dc3545;font-weight:700}
        .total{border:2px solid #0d6efd;background:#f0f7ff}
        .line{border-top:1px solid #eee;margin:15px 0}
        .btn{padding:10px 18px;border-radius:8px;border:none;color:#fff;font-weight:600;cursor:pointer;transition:0.2s}
        .btn-primary{background:#0d6efd}
        .btn-success{background:#198754}
        .btn-edit{background:#6c757d;padding:4px 8px;font-size:11px}
        
        /* Modal Corregido */
        #modalHistorico{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:1000}
        .modal-content{background:#fff;width:95%;max-width:1000px;margin:40px auto;border-radius:15px;padding:25px;max-height:85vh;overflow-y:auto}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th{background:#0d6efd;color:#fff;padding:12px;position:sticky;top:0}
        td{padding:10px;border-bottom:1px solid #eee;text-align:center}
    </style>
</head>
<body>

<h2 style="text-align:center">📊 Consolidado Central + Drinks<br>
    <small style="color:#666"><?= "Periodo: $anio-$mes" ?></small>
</h2>

<div style="text-align:center;margin-bottom:30px">
    <button onclick="abrirModal()" class="btn btn-primary">📅 Ver Histórico Completo</button>
    <button onclick="location.reload()" class="btn btn-success">🔄 Actualizar Datos</button>
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
        Deuda Actual:<br><span class="red" style="font-size:20px"><?= moneda($deudaProv) ?></span>
        <div class="line"></div>
        <b>Inventario Neto:</b><br>
        <span class="green" style="font-size:20px"><?= moneda($inventarioNeto) ?></span>
    </div>
</div>

<div id="modalHistorico">
    <div class="modal-content">
        <h3 style="text-align:center">📋 Histórico de Inventarios y Ventas</h3>
        
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Bodega</th>
                    <th>Venta Día</th>
                    <th>Venta Mes</th>
                    <th>Utilidad</th>
                    <th>Acción</th>
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
                        <td>
                            <button class="btn btn-edit" onclick="editarRegistro('<?= $r['fecha'] ?>', '<?= $r['sucursal'] ?>', <?= $r['valor_bodega'] ?>)">✏️ Editar</button>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="7">No hay datos históricos disponibles.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="text-align:center;margin-top:20px">
            <button onclick="cerrarModal()" class="btn" style="background:#666">Cerrar</button>
        </div>
    </div>
</div>

<script>
function abrirModal(){ document.getElementById('modalHistorico').style.display='block'; }
function cerrarModal(){ document.getElementById('modalHistorico').style.display='none'; }

function editarRegistro(fecha, sucursal, valorActual) {
    let nuevoValor = prompt("Editar Valor de Bodega para " + sucursal + " (" + fecha + "):", valorActual);
    if (nuevoValor !== null && nuevoValor !== "") {
        // Aquí podrías enviar por AJAX a un archivo PHP de actualización
        alert("Función de edición activada. Enviando actualización para: " + nuevoValor);
        // location.href = "update.php?fecha=" + fecha + "&suc=" + sucursal + "&val=" + nuevoValor;
    }
}
</script>

</body>
</html>