<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// Función para verificar autorización
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['Swich'] ?? "NO";
    }
    return "NO";
}

$UsuarioSesion = $_SESSION['Usuario'];

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
        display: flex;
        flex-direction: column;
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
    @media (max-width: 768px) {
        .sidebar {
            position: fixed;
            left: -250px;
            top: 0;
            height: 100%;
            transition: 0.3s;
            z-index: 999;
        }
        .sidebar.show {
            left: 0;
        }
    }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar d-flex flex-column" id="sidebar">
    <a class="navbar-brand text-white" href="#">Mi App</a>
    <nav class="nav flex-column px-2">
        <a class="nav-link" href="Transfers.php">➕ Registrar Transferencia</a>
        <a class="nav-link" href="Reportes.php">📄 Reportes</a>

        <!-- Permiso Usuarios -->
        <?php if (Autorizacion($UsuarioSesion,'0001') === "SI"): ?>
            <a class="nav-link" href="CrearUsuarios.php">👥 Usuarios</a>
            <a class="nav-link" href="CrearAutorizaciones.php">📋 Autorizaciones</a>
            <a class="nav-link" href="CrearAutoTerceros.php">🗂️ Autorizaciones por Usuario</a>
        <?php endif; ?>

        
    </nav>
    <div class="mt-auto p-3">
        <div>Bienvenido, <?= htmlspecialchars($UsuarioSesion) ?></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<!-- Contenido principal -->
<div class="content">
    <button class="btn btn-primary mb-3 d-md-none" id="toggleSidebar">☰ Menú</button>

    <h2>Panel de control</h2>
    <p>Selecciona una opción del menú para comenzar.</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('show');
    });
</script>

</body>
</html>
