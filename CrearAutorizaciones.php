<?php
require 'Conexion.php';
require 'helpers.php';
session_start();session_start();

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarAutorizacion'])) {
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
$result = $mysqli->query("SELECT * FROM Autorizaciones ORDER BY Nro_auto ASC");
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Autorizaciones</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body { background-color: #f3f4f6; padding: 20px; }
    .card { margin-bottom: 20px; border-radius: 0.5rem; }
    .table thead { background-color: #343a40; color: #fff; }
</style>
</head>
<body>

<div class="container">

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Autorizaciones Registradas</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAutorizacion">➕ Crear Autorización</button>
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead>
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
                        <td><?= htmlspecialchars($row['Nombre']) ?></td>
                        <td><?= $row['Estado'] === '1' ? 'Activo' : 'Inactivo' ?></td>
                        <td><?= $row['F_Creacion'] ?></td>
                        <td>
                            <a href="?delete=<?= $row['Id_Auto'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Seguro que deseas eliminar esta autorización?');">Borrar</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($result->num_rows === 0): ?>
                        <tr><td colspan="6" class="text-center">No hay autorizaciones registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal Crear Autorización -->
<div class="modal fade" id="modalAutorizacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Crear Nueva Autorización</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

            <div class="mb-3">
                <label for="Nro_Auto" class="form-label">Número de Autorización</label>
                <input type="number" min="1" max="9999" name="Nro_Auto" id="Nro_Auto" class="form-control" required>
                <small class="text-muted">Se rellenará automáticamente con ceros a la izquierda (ej: 1 → 0001).</small>
            </div>

            <div class="mb-3">
                <label for="Nombre" class="form-label">Nombre</label>
                <input type="text" maxlength="80" name="Nombre" id="Nombre" class="form-control" required>
            </div>

            <input type="hidden" name="guardarAutorizacion" value="1">

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">Guardar Autorización</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
