<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$mensaje = "";

// Obtener lista de terceros
$terceros = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros ORDER BY Nombre ASC");

// Obtener lista de autorizaciones activas
$autorizaciones = $mysqli->query("SELECT Nro_Auto, Nombre FROM Autorizaciones WHERE Estado='1' ORDER BY Nro_Auto ASC");

// Crear asignación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarAsignacion'])) {
    $cedula = $_POST['CedulaNit'];
    $Nro_Auto = $_POST['Nro_Auto'];
    $switch = $_POST['Swich'] ?? 'SI';
    if (empty($cedula) || empty($Nro_Auto)) {
        $mensaje = "Debe seleccionar un tercero y una autorización.";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO autorizacion_tercero (CedulaNit, Nro_Auto, Swich) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $cedula, $Nro_Auto, $switch);
        if ($stmt->execute()) {
            //$mensaje = "Autorización asignada correctamente al tercero.";
        } else {
            $mensaje = ($mysqli->errno === 1062) ? "Esta autorización ya está asignada a este tercero." : "Error: " . $mysqli->error;
        }
        $stmt->close();
    }
}

// Eliminar asignación
if (isset($_GET['delete'])) {
    $id_delete = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM autorizacion_tercero WHERE Id=?");
    $stmt->bind_param("i", $id_delete);
    if ($stmt->execute()) {
        $mensaje = "Asignación eliminada correctamente.";
    } else {
        $mensaje = "Error al eliminar la asignación: " . $mysqli->error;
    }
    $stmt->close();
}

// Obtener todas las asignaciones
$asignaciones = $mysqli->query("
    SELECT at.Id, at.CedulaNit, t.Nombre, a.Nro_Auto, a.Nombre AS AutoNombre, at.Swich, at.Estado, at.F_Creacion
    FROM autorizacion_tercero at
    INNER JOIN terceros t ON at.CedulaNit = t.CedulaNit
    INNER JOIN Autorizaciones a ON at.Nro_Auto = a.Nro_Auto
    ORDER BY at.F_Creacion DESC
");

$UsuarioSesion = $_SESSION['Usuario'];
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Asignar Autorizaciones</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background-color: #f3f4f6;
    padding: 20px;
}
.card { margin-bottom: 20px; border-radius: 0.5rem; }
.table thead { background-color: #343a40; color: #fff; }
</style>
</head>
<body>

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Asignaciones de Autorizaciones</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAsignacion">➕ Nueva Asignación</button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Tercero</th>
                    <th>Cédula/NIT</th>
                    <th>Autorización</th>
                    <th>Switch</th>
                    <th>Estado</th>
                    <th>Fecha Creación</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $asignaciones->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['Id'] ?></td>
                    <td><?= $row['Nombre'] ?></td>
                    <td><?= $row['CedulaNit'] ?></td>
                    <td><?= $row['Nro_Auto'] ?> - <?= $row['AutoNombre'] ?></td>
                    <td><?= $row['Swich'] ?></td>
                    <td><?= $row['Estado'] === '1' ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= $row['F_Creacion'] ?></td>
                    <td>
                        <a href="?delete=<?= $row['Id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Seguro que deseas eliminar esta asignación?');">Borrar</a>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($asignaciones->num_rows === 0): ?>
                    <tr><td colspan="8" class="text-center">No hay asignaciones registradas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal para nueva asignación -->
<div class="modal fade" id="modalAsignacion" tabindex="-1" aria-labelledby="modalAsignacionLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="modalAsignacionLabel">Nueva Asignación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label for="CedulaNit" class="form-label">Tercero</label>
                <select name="CedulaNit" id="CedulaNit" class="form-select" required>
                    <option value="">-- Seleccione un tercero --</option>
                    <?php
                    $terceros->data_seek(0);
                    while ($t = $terceros->fetch_assoc()):
                    ?>
                        <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="Nro_Auto" class="form-label">Autorización</label>
                <select name="Nro_Auto" id="Nro_Auto" class="form-select" required>
                    <option value="">-- Seleccione una autorización --</option>
                    <?php
                    $autorizaciones->data_seek(0);
                    while ($a = $autorizaciones->fetch_assoc()):
                    ?>
                        <option value="<?= $a['Nro_Auto'] ?>"><?= $a['Nro_Auto'] ?> - <?= $a['Nombre'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="Swich" class="form-label">Switch</label>
                <select name="Swich" id="Swich" class="form-select">
                    <option value="SI" selected>SI</option>
                    <option value="NO">NO</option>
                </select>
            </div>
            <input type="hidden" name="guardarAsignacion" value="1">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Asignación</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
