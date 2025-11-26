<?php
require 'helpers.php';
if(empty($_SESSION['Usuario'])) {
    header('Location: login.php?msg=Debe iniciar sesión');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Panel</title></head>
<body>
<h2>Bienvenido, <?= $_SESSION['Usuario'] ?></h2>
<a href="logout.php">Cerrar sesión</a>
</body>
</html>
