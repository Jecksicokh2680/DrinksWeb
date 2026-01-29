<?php
session_start();
require("Conexion.php"); // Conexión a la base donde guardas los cierres

// Verificar si el usuario tiene permiso 9999 para realizar el cierre final
if (!isset($_SESSION['Usuario']) || !isset($_SESSION['Autorizaciones'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no válida']);
    exit;
}

$permisos = $_SESSION['Autorizaciones'][$_SESSION['Usuario'] . '_9999'] ?? 'NO';

if ($permisos !== 'SI') {
    echo json_encode(['status' => 'error', 'msg' => 'No tiene autorización para cerrar caja']);
    exit;
}

// Recibir datos
$nit_cajero = $_POST['nit'] ?? '';
$fecha_cierre = $_POST['fecha'] ?? '';
$sede = $_POST['sede'] ?? '';
$ventas = (float)($_POST['ventas'] ?? 0);
$egresos = (float)($_POST['egresos'] ?? 0);
$transfer = (float)($_POST['transfer'] ?? 0);
$saldo = (float)($_POST['saldo'] ?? 0);
$usuario_autoriza = $_SESSION['Usuario'];

if (empty($nit_cajero) || empty($fecha_cierre)) {
    echo json_encode(['status' => 'error', 'msg' => 'Datos incompletos']);
    exit;
}

// 1. Verificar si ya existe un cierre definitivo para este cajero en esta fecha y sede
$check = $mysqli->prepare("SELECT id FROM cierres_caja WHERE nit_cajero = ? AND fecha_caja = ? AND sede = ?");
$check->bind_param("sss", $nit_cajero, $fecha_cierre, $sede);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(['status' => 'error', 'msg' => 'Ya existe un cierre definitivo registrado para este cajero en esta fecha']);
    exit;
}

// 2. Insertar el registro de cierre
$sql = "INSERT INTO cierres_caja (nit_cajero, fecha_caja, sede, ventas_brutas, total_egresos, total_transfer, saldo_efectivo, usuario_autoriza, fecha_registro) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("sssdddds", $nit_cajero, $fecha_cierre, $sede, $ventas, $egresos, $transfer, $saldo, $usuario_autoriza);

if ($stmt->execute()) {
    echo json_encode(['status' => 'ok', 'msg' => 'Cierre definitivo guardado con éxito']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Error al guardar en base de datos: ' . $mysqli->error]);
}
?>