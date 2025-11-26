<?php
require 'conexion.php';
require 'helpers.php';

$CedulaNit = $_SESSION['CambiarClave'] ?? null;
if (!$CedulaNit) {
    header('Location: login.php');
    exit;
}

$ClaveNueva = $_POST['ClaveNueva'] ?? '';
$ClaveNueva2 = $_POST['ClaveNueva2'] ?? '';

if ($ClaveNueva === '' || $ClaveNueva !== $ClaveNueva2 || strlen($ClaveNueva) < 8) {
    die("Contraseñas no coinciden o no cumplen requisitos");
}

// Crear hash con password_hash (bcrypt/argon2 según configuración PHP)
$hash = password_hash($ClaveNueva, PASSWORD_DEFAULT);

$upd = $mysqli->prepare("UPDATE usuarios_acceso
                         SET PasswordHash = ?, PasswordSalt = NULL,
                             DebeCambiarClave = 0, FechaUltimoCambio = NOW()
                         WHERE CedulaNit = ?");
$upd->bind_param('ss', $hash, $CedulaNit);
$upd->execute();

unset($_SESSION['CambiarClave']);
header('Location: login.php?msg=' . urlencode('Clave actualizada. Inicia sesión.'));
exit;
