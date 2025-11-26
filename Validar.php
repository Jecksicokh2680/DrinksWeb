<?php
require 'conexion.php';
require 'helpers.php';

$usuario = limpiar($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if (!$usuario || !$password) {
    header("Location: login.php?msg=Campos incompletos");
    exit;
}

$stmt = $mysqli->prepare("SELECT PasswordHash FROM usuarios_Seguridad WHERE CedulaNit=? LIMIT 1");
$stmt->bind_param("s", $usuario);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($hash);
    $stmt->fetch();
    if (password_verify($password, $hash)) {
        $_SESSION['Usuario'] = $usuario;
        header("Location: panel.php");
        exit;
    }
}

header("Location: login.php?msg=Usuario o contraseña incorrectos");
exit;
?>
