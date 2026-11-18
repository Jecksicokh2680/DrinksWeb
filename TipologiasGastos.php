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
$ambito = 'Empresa';
$tipo = 'Activo'; // Valor predeterminado actualizado
$descripcion = '';
$pagina_actual = basename($_SERVER['PHP_SELF']);

// 1. Si se solicita editar (GET) - Ocurre PRIMERO para cargar los datos en el formulario
if (isset($_GET['editar']) && !empty($_GET['editar'])) {
    $id_tipologia = intval($_GET['editar']);
    
    $stmt = $mysqliWeb->prepare("SELECT * FROM TipologiaGastos WHERE id = ?");
    $stmt->bind_param("i", $id_tipologia);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($tipologia = $resultado->fetch_assoc()) {
        $editando = true;
        $nombre = $tipologia['nombre'];
        $ambito = $tipologia['ambito'] ?? 'Empresa';
        $tipo = $tipologia['tipo'];
        $descripcion = $tipologia['descripcion'];
    }
    $stmt->close();
}

// 2. Procesar Guardar / Actualizar (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Capturamos el ID oculto del formulario (si viene lleno, es actualización; si viene vacío, es nuevo)
    $id_tipologia = trim($_POST['id_tipologia'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $ambito = $_POST['ambito'] ?? 'Empresa';
    $tipo = $_POST['tipo'] ?? 'Activo';
    $descripcion = trim($_POST['descripcion'] ?? '');

    if (!empty($nombre) && !empty($ambito) && !empty($tipo)) {
        if (!empty($id_tipologia)) {
            // ACTUALIZAR registro existente
            $stmt = $mysqliWeb->prepare("UPDATE TipologiaGastos SET nombre = ?, ambito = ?, tipo = ?, descripcion = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $nombre, $ambito, $tipo, $descripcion, $id_tipologia);
            $stmt->execute();
            $stmt->close();
        } else {
            // INSERTAR nuevo registro
            $stmt = $mysqliWeb->prepare("INSERT INTO TipologiaGastos (nombre, ambito, tipo, descripcion) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $nombre, $ambito, $tipo, $descripcion);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: " . $pagina_actual);
        exit;
    }
}

// 3. Procesar Eliminación (GET)
if (isset($_GET['eliminar']) && !empty($_GET['eliminar'])) {
    $id_a_eliminar = intval($_GET['eliminar']);
    $stmtDel = $mysqliWeb->prepare("DELETE FROM TipologiaGastos WHERE id = ?");
    $stmtDel->bind_param("i", $id_a_eliminar);
    $stmtDel->execute();
    $stmtDel->close();
    
    header("Location: " . $pagina_actual);
    exit;
}

// 4. Obtener todas las tipologías ordenadas para la tabla
$resultado_tipologias = $mysqliWeb->query("SELECT * FROM TipologiaGastos ORDER BY ambito ASC, tipo ASC, nombre ASC");
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
    <title>Gestión de Tipologías (P&G)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Gestión de Tipologías (Propios y Empresa)</h2>
        <a href="RegistroGastos.php" class="btn btn-outline-primary btn-sm">&larr; Ir a Registro</a>
    </div>

    <!-- FORMULARIO -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white py-2" id="form-header">
            <h6 class="mb-0" id="form-title"><?= $editando ? 'Editar Tipología ID: ' . $id_tipologia : 'Nueva Tipología' ?></h6>
        </div>
        <div class="card-body bg-white py-3">
            <form action="<?= htmlspecialchars($pagina_actual) ?>" method="POST">
                <!-- Campo oculto indispensable para conservar el ID al actualizar -->
                <input type="hidden" name="id_tipologia" value="<?= htmlspecialchars($id_tipologia) ?>">

                <div class="row align-items-end g-3">
                    <div class="col-md-3">
                        <label for="nombre" class="form-label form-label-sm fw-bold">Nombre</label>
                        <input type="text" class="form-control form-control-sm" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre) ?>" maxlength="50" required placeholder="Ej. Inversión, Préstamo...">
                    </div>

                    <div class="col-md-2">
                        <label for="ambito" class="form-label form-label-sm fw-bold">Ámbito</label>
                        <select class="form-select form-select-sm" id="ambito" name="ambito" required>
                            <option value="Empresa" <?= ($ambito === 'Empresa') ? 'selected' : '' ?>>Empresa</option>
                            <option value="Propio" <?= ($ambito === 'Propio') ? 'selected' : '' ?>>Propio (Personal)</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="tipo" class="form-label form-label-sm fw-bold">Clasificación</label>
                        <select class="form-select form-select-sm" id="tipo" name="tipo" required>
                            <option value="Activo" <?= ($tipo === 'Activo') ? 'selected' : '' ?>>Activo</option>
                            <option value="Pasivo" <?= ($tipo === 'Pasivo') ? 'selected' : '' ?>>Pasivo</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="descripcion" class="form-label form-label-sm fw-bold">Descripción</label>
                        <input type="text" class="form-control form-control-sm" id="descripcion" name="descripcion" value="<?= htmlspecialchars($descripcion) ?>" maxlength="255" placeholder="Detalle opcional...">
                    </div>

                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-success btn-sm w-100 fw-bold"><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
                        <?php if ($editando): ?>
                            <a href="<?= htmlspecialchars($pagina_actual) ?>" class="btn btn-secondary btn-sm w-100">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA DE LISTADO -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white py-2">
            <h6 class="mb-0">Listado de Tipologías Registradas</h6>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nombre</th>
                        <th>Ámbito</th>
                        <th>Clasificación (Tipo)</th>
                        <th>Descripción</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tipologias) > 0): ?>
                        <?php foreach ($tipologias as $row): ?>
                            <?php 
                                $badge_tipo = 'bg-secondary';
                                if ($row['tipo'] === 'Activo') $badge_tipo = 'bg-success';
                                elseif ($row['tipo'] === 'Pasivo') $badge_tipo = 'bg-dark text-white';

                                $badge_ambito = (($row['ambito'] ?? 'Empresa') === 'Empresa') ? 'bg-primary' : 'bg-secondary';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['nombre']) ?></strong></td>
                                <td>
                                    <span class="badge <?= $badge_ambito ?>" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($row['ambito'] ?? 'Empresa') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $badge_tipo ?>" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($row['tipo']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['descripcion']) ?></td>
                                <td class="text-center">
                                    <a href="<?= htmlspecialchars($pagina_actual) ?>?editar=<?= $row['id'] ?>" class="btn btn-sm btn-warning py-0 px-2">Editar</a>
                                    <a href="<?= htmlspecialchars($pagina_actual) ?>?eliminar=<?= $row['id'] ?>" class="btn btn-sm btn-danger py-0 px-2" onclick="return confirm('¿Estás seguro de eliminar esta tipología?');">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">No hay tipologías registradas.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>