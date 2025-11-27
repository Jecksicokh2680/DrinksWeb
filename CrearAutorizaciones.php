<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// Mensaje de notificación
$mensaje = "";

// Eliminar autorización si se envía `delete`
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
    $nro_auto = trim($_POST['Nro_Auto']);
    $nombre = trim($_POST['Nombre']);
    
    if (empty($nro_auto) || empty($nombre)) {
        $mensaje = "Todos los campos son obligatorios.";
    } else {
        $nro_auto = str_pad($nro_auto, 4, "0", STR_PAD_LEFT);
        $stmt = $mysqli->prepare("INSERT INTO Autorizaciones (Nro_Auto, Nombre) VALUES (?, ?)");
        $stmt->bind_param("ss", $nro_auto, $nombre);
        if ($stmt->execute()) {
            $mensaje = "Autorización creada exitosamente con número $nro_auto.";
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
    <title>Autorizaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h2>Crear Autorización</h2>
    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>
    <form method="post" class="mt-3 mb-5">
        <div class="mb-3">
            <label for="Nro_Auto" class="form-label">Número de Autorización</label>
            <input type="number" min="1" max="9999" name="Nro_Auto" id="Nro_Auto" class="form-control" required>
            <small class="text-muted">Se rellenará automáticamente con ceros a la izquierda (ej: 1 → 0001).</small>
        </div>
        <div class="mb-3">
            <label for="Nombre" class="form-label">Nombre</label>
            <input type="text" maxlength="80" name="Nombre" id="Nombre" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="Panel.php" class="btn btn-secondary">Volver</a>
    </form>

    <h3>Autorizaciones Registradas</h3>
    <table class="table table-bordered table-striped mt-3">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
