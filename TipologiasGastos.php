<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("Conexion.php");

if (isset($conn_error) && !empty($conn_error)) {
    die($conn_error);
}

$editando = false;
$id_tipologia = '';
$nombre = '';
$tipo = 'Variable';
$descripcion = '';
$pagina_actual = basename($_SERVER['PHP_SELF']);

// 1. Procesar Guardar / Actualizar (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_tipologia = $_POST['id_tipologia'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = $_POST['tipo'] ?? 'Variable';
    $descripcion = trim($_POST['descripcion'] ?? '');

    if (!empty($nombre) && !empty($tipo)) {
        if (!empty($id_tipologia)) {
            $stmt = $mysqliWeb->prepare("UPDATE TipologiaGastos SET nombre = ?, tipo = ?, descripcion = ? WHERE id = ?");
            $stmt->bind_param("sssi", $nombre, $tipo, $descripcion, $id_tipologia);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqliWeb->prepare("INSERT INTO TipologiaGastos (nombre, tipo, descripcion) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $nombre, $tipo, $descripcion);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: " . $pagina_actual);
        exit;
    }
}

// 2. Si se solicita editar
if (isset($_GET['editar'])) {
    $editando = true;
    $id_tipologia = $_GET['editar'];
    
    $stmt = $mysqliWeb->prepare("SELECT * FROM TipologiaGastos WHERE id = ?");
    $stmt->bind_param("i", $id_tipologia);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($tipologia = $resultado->fetch_assoc()) {
        $nombre = $tipologia['nombre'];
        $tipo = $tipologia['tipo'];
        $descripcion = $tipologia['descripcion'];
    }
    $stmt->close();
}

// 3. Obtener todas las tipologías
$resultado_tipologias = $mysqliWeb->query("SELECT * FROM TipologiaGastos ORDER BY nombre ASC");
$tipologias = [];
if ($resultado_tipologias) {
    while ($row = $resultado_tipologias->fetch_assoc()) {
        $tipologias[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Tipología de Gastos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Gestión de Tipología de Gastos</h2>
        <a href="RegistroGastos.php" class="btn btn-outline-primary">Ir a Registro de Egresos &rarr;</a>
    </div>

    <div class="row">
        <!-- Formulario -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><?= $editando ? 'Editar Tipología' : 'Nueva Tipología' ?></h5>
                </div>
                <div class="card-body">
                    <form action="<?= htmlspecialchars($pagina_actual) ?>" method="POST">
                        <input type="hidden" name="id_tipologia" value="<?= $id_tipologia ?>">

                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre) ?>" maxlength="50" required>
                        </div>

                        <div class="mb-3">
                            <label for="tipo" class="form-label">Tipo</label>
                            <select class="form-select" id="tipo" name="tipo" required>
                                <option value="Fijo" <?= ($tipo === 'Fijo') ? 'selected' : '' ?>>Fijo</option>
                                <option value="Variable" <?= ($tipo === 'Variable') ? 'selected' : '' ?>>Variable</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3" maxlength="255"><?= htmlspecialchars($descripcion) ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-success w-100"><?= $editando ? 'Actualizar Tipología' : 'Guardar Tipología' ?></button>
                        
                        <?php if ($editando): ?>
                            <a href="<?= htmlspecialchars($pagina_actual) ?>" class="btn btn-secondary w-100 mt-2">Cancelar Edición</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabla -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Listado de Tipologías</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Descripción</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($tipologias) > 0): ?>
                                <?php foreach ($tipologias as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['nombre']) ?></strong></td>
                                        <td>
                                            <span class="badge <?= $row['tipo'] === 'Fijo' ? 'bg-info text-dark' : 'bg-warning text-dark' ?>">
                                                <?= $row['tipo'] ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($row['descripcion']) ?></td>
                                        <td>
                                            <a href="<?= htmlspecialchars($pagina_actual) ?>?editar=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Editar</a>
                                            <a href="eliminar_tipologia.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Estás seguro?');">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No hay tipologías registradas.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>