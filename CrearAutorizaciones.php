<?php
require 'Conexion.php';    // Base Local ($mysqli)
require 'ConnCentral.php'; // Base Central ($mysqliPos)
require 'helpers.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// Mensaje de notificación
$mensaje = "";

/* ============================================================
   ELIMINAR AUTORIZACIÓN (Base Local)
   ============================================================ */
if (isset($_GET['delete'])) {
    $id_delete = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM Autorizaciones WHERE Id_Auto = ?");
    $stmt->bind_param("i", $id_delete);
    if ($stmt->execute()) {
        $mensaje = "Autorización eliminada correctamente.";
    } else {
        $mensaje = "Error al eliminar: " . $mysqli->error;
    }
    $stmt->close();
}

/* ============================================================
   CREAR NUEVA AUTORIZACIÓN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarAutorizacion'])) {
    $nro_auto = str_pad(trim($_POST['Nro_Auto']), 4, "0", STR_PAD_LEFT);
    $nombre = trim($_POST['Nombre']);

    if (empty($nro_auto) || empty($nombre)) {
        $mensaje = "Todos los campos son obligatorios.";
    } else {
        // Ejemplo de validación en la tabla TERCEROS (Base Central)
        // Aquí verificamos si el nombre existe como tercero antes de autorizar
        $stmtT = $mysqliPos->prepare("SELECT nit FROM terceros WHERE (nombres LIKE ? OR apellidos LIKE ?) AND inactivo = 0 LIMIT 1");
        $busqueda = "%$nombre%";
        $stmtT->bind_param("ss", $busqueda, $busqueda);
        $stmtT->execute();
        $resT = $stmtT->get_result();

        if ($resT->num_rows >= 0) { // Cambiar lógica según necesidad
            $stmt = $mysqli->prepare("INSERT INTO Autorizaciones (Nro_Auto, Nombre, F_Creacion, Estado) VALUES (?, ?, NOW(), '1')");
            $stmt->bind_param("ss", $nro_auto, $nombre);
            if ($stmt->execute()) {
                $mensaje = "✅ Autorización $nro_auto creada exitosamente.";
            } else {
                $mensaje = ($mysqli->errno === 1062) ? "❌ El número $nro_auto ya existe." : "Error: " . $mysqli->error;
            }
            $stmt->close();
        }
        $stmtT->close();
    }
}

// Obtener todas las autorizaciones (Local)
$result = $mysqli->query("SELECT * FROM Autorizaciones ORDER BY Nro_Auto ASC");
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Autorizaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; padding: 20px; }
        .card { border-radius: 0.5rem; border: none; }
        .table thead { background-color: #1a2a3a; color: #fff; }
    </style>
</head>
<body>

<div class="container">

    <?php if ($mensaje): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold text-dark">Autorizaciones Registradas</h2>
        <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalAutorizacion">➕ Crear Autorización</button>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Número</th>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Fecha Creación</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-3"><?= $row['Id_Auto'] ?></td>
                        <td class="fw-bold text-primary"><?= $row['Nro_Auto'] ?></td>
                        <td><?= htmlspecialchars($row['Nombre']) ?></td>
                        <td>
                            <span class="badge <?= $row['Estado'] === '1' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $row['Estado'] === '1' ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td><?= $row['F_Creacion'] ?></td>
                        <td class="text-center">
                            <a href="?delete=<?= $row['Id_Auto'] ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('¿Eliminar esta autorización?');">Borrar</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($result->num_rows === 0): ?>
                        <tr><td colspan="6" class="text-center py-4">No hay autorizaciones registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAutorizacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Crear Nueva Autorización</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="Nro_Auto" class="form-label fw-bold">Número de Autorización</label>
                    <input type="number" min="1" max="9999" name="Nro_Auto" id="Nro_Auto" class="form-control" placeholder="Ej: 1" required>
                </div>

                <div class="mb-3">
                    <label for="Nombre" class="form-label fw-bold">Nombre del Autorizado</label>
                    <input type="text" maxlength="80" name="Nombre" id="Nombre" class="form-control" placeholder="Nombre completo" required>
                </div>

                <input type="hidden" name="guardarAutorizacion" value="1">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark">Guardar Datos</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>