<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

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
    margin: 0;
    font-family: Arial, sans-serif;
    display: flex;
    min-height: 100vh;
    overflow-x: hidden;
}
.sidebar {
    width: 220px;
    background-color: #0d6efd;
    color: white;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    transition: transform 0.3s ease;
}
.sidebar .nav-link {
    color: white;
}
.sidebar .nav-link.active {
    background-color: #084298;
}
.sidebar .navbar-brand {
    padding: 1rem;
    font-weight: bold;
    font-size: 1.2rem;
}
.sidebar .mt-auto {
    margin-top: auto;
}
.content-frame {
    flex-grow: 1;
    border: none;
    width: 100%;
    height: 100vh;
}
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        left: -250px;
        top: 0;
        height: 100%;
        z-index: 999;
        transform: translateX(0);
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
        <a class="nav-link" href="Transfers.php" target="contentFrame">➕ Registrar Transferencia</a>
        <a class="nav-link" href="Reportes.php" target="contentFrame">📄 Reportes</a>

        <?php if (Autorizacion($UsuarioSesion,'0001') === "SI"): ?>
            <a class="nav-link" href="CrearUsuarios.php" target="contentFrame">👥 Usuarios</a>
            <a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">📋 Autorizaciones</a>
            <a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">🗂️ Autorizaciones por Usuario</a>
        <?php endif; ?>
    </nav>
    <div class="mt-auto p-3">
        <div>Bienvenido, <?= htmlspecialchars($UsuarioSesion) ?></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<!-- Contenido principal (iframe) -->
<iframe src="" name="contentFrame" class="content-frame" id="contentFrame"></iframe>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botón toggle sidebar en móviles
    const toggleBtn = document.createElement('button');
    toggleBtn.className = 'btn btn-primary d-md-none';
    toggleBtn.textContent = '☰ Menú';
    toggleBtn.style.position = 'fixed';
    toggleBtn.style.top = '10px';
    toggleBtn.style.left = '10px';
    toggleBtn.style.zIndex = '1000';
    document.body.appendChild(toggleBtn);

    const sidebar = document.getElementById('sidebar');
    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('show');
    });

    // Cerrar sidebar al hacer click fuera en móviles
    window.addEventListener('click', (e) => {
        if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target) && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
        }
    });
</script>

</body>
</html>
