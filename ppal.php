<?php
// ===============================
//  INICIAR SESIÓN
// ===============================
session_start();

// Validar las tres variables de sesión
if (
    !isset($_SESSION['Usuario']) ||
    !isset($_SESSION['Empresa']) ||
    !isset($_SESSION['Sucursal'])
) {
    header("Location: login.php");
    exit();
}

$Usuario  = $_SESSION['Usuario'];
$Empresa  = $_SESSION['Empresa'];
$Sucursal = $_SESSION['Sucursal'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Principal</title>

<style>
body {
    font-family: Arial;
    margin: 0;
    background: #f5f5f5;
}
header {
    background: #2c3e50;
    color: white;
    padding: 15px 20px;
    font-size: 18px;
}
header .info {
    font-size: 14px;
    margin-top: 5px;
}
.container {
    padding: 20px;
}
.menu {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #ccc;
    box-shadow: 0px 0px 5px #ccc;
    text-align: center;
}
.card a {
    text-decoration: none;
    font-size: 18px;
    color: #1a5276;
}
.card:hover {
    box-shadow: 0px 0px 10px #aaa;
}
.logout {
    background: #c0392b;
    color: white;
    padding: 8px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
}
.logout:hover {
    background: #922b21;
}
</style>
</head>
<body>

<header>
    Panel Principal
    <span style="float:right;">
        <a class="logout" href="logout.php">Cerrar Sesión</a>
    </span>

    <div class="info">
        <b>Usuario:</b> <?= $Usuario ?> &nbsp; |
        <b>Empresa:</b> <?= $Empresa ?> &nbsp; |
        <b>Sucursal:</b> <?= $Sucursal ?>
    </div>
</header>

<div class="container">
    <h2>Menú de Opciones</h2>

    <div class="menu">

        <!-- MÓDULO AUTORIZACIONES -->
        <div class="card">
            <h3>Autorizaciones</h3>
            <p>Crear, editar y gestionar autorizaciones.</p>
            <a href="autorizaciones.php">Entrar →</a>
        </div>

        <!-- Más módulos -->
        <div class="card">
            <h3>Próximo módulo</h3>
            <p>Puedes agregar todas las opciones que necesites.</p>
            <a href="#">Disponible pronto</a>
        </div>

    </div>
</div>

</body>
</html>
