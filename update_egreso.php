<?php
session_start();
require("Conexion.php");    // Para permisos y control
require("ConnCentral.php"); // Para sede central
require("ConnDrinks.php");  // Para sede drinks (AWS)

// 1. Validar Sesión
$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if ($UsuarioSesion === '') { die("❌ Sesión expirada"); }

// 2. Validar Permiso 1700 (Reutilizando tu función de sesión si existe o consulta directa)
$stmtP = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto='1700' LIMIT 1");
$stmtP->bind_param("s", $UsuarioSesion);
$stmtP->execute();
$resP = $stmtP->get_result()->fetch_assoc();
if (!$resP || $resP['Swich'] !== 'SI') { die("❌ No tiene permiso para editar egresos"); }

// 3. Captura de datos
$id   = $_POST['id'] ?? '';
$mot  = $_POST['motivo'] ?? '';
$val  = $_POST['valor'] ?? 0;
$sede = $_POST['sede'] ?? 'central';

// 4. Seleccionar conexión según la sede recibida
$conn = ($sede === 'drinks') ? $mysqliDrinks : $mysqliCentral;

// 5. Ejecutar Update
$stmt = $conn->prepare("UPDATE SALIDASCAJA SET MOTIVO=?, VALOR=? WHERE IDSALIDA=?");
$stmt->bind_param("sdi", $mot, $val, $id);

if ($stmt->execute()) {
    echo "✅ Guardado con éxito en sede " . strtoupper($sede);
} else {
    echo "❌ Error al guardar en base de datos";
}

$stmt->close();
$conn->close();
?>