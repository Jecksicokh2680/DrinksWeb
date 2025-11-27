<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// Mensaje de notificación
$mensaje = "";

// Eliminar autorización
if (isset($_GET['delete'])) {
    $id_delete = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM Autorizaciones WHERE Id_Auto = ?");
    $stmt->bind_param("i", $id_delete);
    if ($stmt->execute()) {
        $mensaje = "Autorización eliminada correctamente.";
    } else {
        $mensaje = "Error al eliminar la autorización: " . $mysqli->error;
    }
    $stmt->close();
}

// Crear nueva autorización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nro_auto = str_pad(trim($_POST['Nro_Auto']), 4, "0", STR_PAD_LEFT);
    $nombre = trim($_POST['Nombre']);

    if (empty($nro_auto) || empty($nombre)) {
        $mensaje = "Todos los campos son obligatorios.";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO Autorizaciones (Nro_Auto, Nombre) VALUES (?, ?)");
        $stmt->bind_param("ss", $nro_auto, $nombre);
        if ($stmt->execute()) {
            //$mensaje = "Autorización creada exitosamente con número $nro_auto.";
        } else {
            $mensaje = ($mysqli->errno === 1062) ? "El número de autorización $nro_auto ya existe." : "Error: " . $mysqli->error;
        }
        $stmt->close();
    }
}

// Obtener todas las autorizaciones
$result = $mysqli->query("SELECT * FROM Autorizaciones ORDER BY Id_Auto DESC");
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Autorizaciones - Panel Gerencial</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body {
        display: flex;
        min-height: 100vh;
        overflow-x: hidden;
    }
    .sidebar {
        width: 240px;
        background-color: #1f2937;
        color: #fff;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
    }
    .sidebar a.nav-link {
        color: #fff;
        padding: 0.75rem 1rem;
    }
    .sidebar a.nav-link.active, .sidebar a.nav-link:hover {
        background-color: #111827;
    }
    .sidebar .brand {
        font-size: 1.5rem;
        font-weight: bold;
        padding: 1rem;
        text-align: center;
        border-bottom: 1px solid #374151;
    }
    .sidebar .user-info {
        margin-top: auto;
        padding: 1rem;
        border-top: 1px solid #374151;
        text-align: center;
    }
    .content {
        flex-grow: 1;
        padding: 2rem;
        background-color: #f3f4f6;
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
        .content {
            padding: 1rem;
        }
    }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="brand">Mi Panel</div>
    <nav class="nav flex-column mt-3">
        <a class="nav-link" href="Transfers.php">➕ Registrar Transferencia</a>
        <a class="nav-link" href="Reportes.php">📄 Reportes</a>
        <a class="nav-link" href="CrearUsuarios.php">👥 Usuarios</a>
        <a class="nav-link active" href="CrearAutorizaciones.php">📋 Autorizaciones</a>
        <a class="nav-link" href="CrearAutoTerceros.php">🗂️ Autorizaciones por Usuario</a>
    </nav>
    <div class="user-info">
        <div>Bienvenido, <?= $_SESSION['Usuario'] ?></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<!-- Contenido principal -->
<div class="content">
    <button class="btn btn-primary mb-3 d-md-none" id="toggleSidebar">☰ Menú</button>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">Crear Nueva Autorización</div>
        <div class="card-body">
            <?php if ($mensaje): ?>
                <div class="alert alert-info"><?= $mensaje ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label for="Nro_Auto" class="form-label">Número de Autorización</label>
                    <input type="number" min="1" max="9999" name="Nro_Auto" id="Nro_Auto" class="form-control" required>
                    <small class="text-muted">Se rellenará automáticamente con ceros a la izquierda (ej: 1 → 0001).</small>
                </div>
                <div class="mb-3">
                    <label for="Nombre" class="form-label">Nombre</label>
                    <input type="text" maxlength="80" name="Nombre" id="Nombre" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-success">Guardar</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-secondary text-white">Autorizaciones Registradas</div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Número</th>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Fecha Creación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['Id_Auto'] ?></td>
                        <td><?= $row['Nro_Auto'] ?></td>
                        <td><?= $row['Nombre'] ?></td>
                        <td><?= $row['Estado'] === '1' ? 'Activo' : 'Inactivo' ?></td>
                        <td><?= $row['F_Creacion'] ?></td>
                        <td>
                            <a href="?delete=<?= $row['Id_Auto'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Seguro que deseas eliminar esta autorización?');">Borrar</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
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
