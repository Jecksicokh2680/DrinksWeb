<?php
require_once 'Conexion.php';

// Manejo de acciones (POST/GET)
$msg = ['text' => '', 'type' => ''];

// Borrar
if (isset($_GET['del'])) {
    $stmt = $mysqliWeb->prepare("DELETE FROM familias WHERE id = ?");
    $stmt->bind_param("i", $_GET['del']);
    $stmt->execute();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Crear
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['nombre'])) {
    $nombre = trim($_POST['nombre']);
    $stmt = $mysqliWeb->prepare("INSERT INTO familias (nombre) VALUES (?)");
    $stmt->bind_param("s", $nombre);
    if ($stmt->execute()) {
        $msg = ['text' => 'Familia registrada correctamente.', 'type' => 'success'];
    } else {
        $msg = ['text' => 'Error: El nombre ya existe.', 'type' => 'danger'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Gestión de Familias</title>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm mx-auto" style="max-width: 600px;">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Administración de Familias</h5>
        </div>
        <div class="card-body">
            <?php if ($msg['text']): ?>
                <div class="alert alert-<?php echo $msg['type']; ?> alert-dismissible fade show">
                    <?php echo $msg['text']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" class="row g-3 mb-4">
                <div class="col-9">
                    <input type="text" name="nombre" class="form-control" placeholder="Nombre de la nueva familia" required>
                </div>
                <div class="col-3">
                    <button type="submit" class="btn btn-success w-100">Guardar</button>
                </div>
            </form>

            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $mysqliWeb->query("SELECT * FROM familias ORDER BY id DESC");
                    while ($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['nombre']) ?></td>
                        <td class="text-center">
                            <a href="?del=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('¿Confirmas eliminar esta familia?')">Eliminar</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>