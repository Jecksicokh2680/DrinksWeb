<?php
session_start();
if (!isset($_SESSION['Usuario'])) { die("Acceso denegado"); }

$id   = $_POST['id'] ?? '';
$mot  = $_POST['motivo'] ?? '';
$val  = $_POST['valor'] ?? 0;
$sede = $_POST['sede'] ?? 'central';

if ($sede === 'drinks') {
    $conn = new mysqli("52.15.192.69", "aws_user", "root", "empresa001", 3308);
} else {
    require("ConnCentral.php");
    $conn = $mysqliCentral;
}

if ($conn->connect_error) { die("Error conexión: " . $conn->connect_error); }

$stmt = $conn->prepare("UPDATE SALIDASCAJA SET MOTIVO=?, VALOR=? WHERE IDSALIDA=?");
$stmt->bind_param("sdi", $mot, $val, $id);

if ($stmt->execute()) {
    echo "✅ Guardado con éxito en sede " . strtoupper($sede);
} else {
    echo "❌ Error al guardar";
}
$stmt->close();
$conn->close();
?>