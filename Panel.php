<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}
?>
<h2>Bienvenido <?= $_SESSION['Usuario'] ?></h2>
<a href="Logout.php">Cerrar sesión</a>
