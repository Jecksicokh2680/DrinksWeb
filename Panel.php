<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Panel de Usuario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .sidebar {
            width: 220px;
            background-color: #0d6efd;
            color: white;
            flex-shrink: 0;
        }
        .sidebar .nav-link {
            color: white;
        }
        .sidebar .nav-link.active {
            background-color: #084298;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
        }
        .sidebar .navbar-brand {
            padding: 1rem;
            font-weight: bold;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column">
    <a class="navbar-brand text-white" href="#">Mi App</a>
    <nav class="nav flex-column px-2">
        <a class="nav-link" href="Transfers.php">➕ Registrar Transferencia</a>
        <a class="nav-link" href="Reportes.php">📄 Reportes</a>
        <a class="nav-link" href="CrearUsuarios.php">👥 Usuarios</a>
        <a class="nav-link" href="CrearAutorizaciones.php">📋 Autorizaciones</a>
        <a class="nav-link" href="CrearAutoTerceros.php">🗂️ Autorizaciones por Usuario</a>
    </nav>
    <div class="mt-auto p-3">
        <div>Bienvenido, <?= $_SESSION['Usuario'] ?></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<div class="content">
    <h2>Panel de control</h2>
    <p>Selecciona una opción del menú para comenzar.</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
