<?php
// Habilitar errores para ver qué pasa exactamente si falla
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Usamos Conexion.php porque ahí está la tabla cierres_caja
require("Conexion.php"); 
date_default_timezone_set('America/Bogota');

// 2. Recibir datos
$fecha   = $_POST['fecha'] ?? '';
$nit     = $_POST['nit'] ?? '';
$monto   = $_POST['monto'] ?? 0;
$sede    = $_POST['sede'] ?? '';
$usuario = $_POST['usuario'] ?? '';
$ahora   = date("Y-m-d H:i:s");

// 3. Validar conexión (usando la variable de tu archivo Conexion.php)
if (!$mysqli || $mysqli->connect_error) {
    die("Error de conexión a BnmaWeb: " . ($mysqli->connect_error ?? "Variable no definida"));
}

// 4. Evitar Duplicados
$stmtCheck = $mysqli->prepare("SELECT IdCierre FROM cierres_caja WHERE FechaCorte = ? AND NitFacturador = ? AND Sede = ?");
$stmtCheck->bind_param("sss", $fecha, $nit, $sede);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    die("Error: Ya existe un cierre registrado para este usuario hoy.");
}

// 5. Insertar
// Asegúrate que los nombres de columnas coincidan con tu tabla en BnmaWeb
$sql = "INSERT INTO cierres_caja (FechaCorte, NitFacturador, MontoFinal, Sede, UsuarioCierre, FechaRegistro) VALUES (?, ?, ?, ?, ?, ?)";
$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    die("Error en Prepare: " . $mysqli->error);
}

$stmt->bind_param("ssdsss", $fecha, $nit, $monto, $sede, $usuario, $ahora);

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "Error al insertar: " . $stmt->error;
}
?>