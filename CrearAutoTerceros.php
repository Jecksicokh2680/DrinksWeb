<?php
require 'Conexion.php';
require 'helpers.php';

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$mensaje = "";

// Obtener lista de terceros (supongo que tienes una tabla 'terceros')
$terceros = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros ORDER BY Nombre ASC");

// Obtener lista de autorizaciones activas
$autorizaciones = $mysqli->query("SELECT Id_Auto, Nro_Auto, Nombre FROM Autorizaciones WHERE Estado='1' ORDER BY Nro_Auto ASC");

// Crear asignación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula = $_POST['CedulaNit'];
    $id_auto = intval($_POST['Id_Auto']);
    $switch = $_POST['Swich'] ?? 'SI';
    //echo "Valor de cedula: " . htmlspecialchars($cedula) . "<br>";
    //echo "Valor de Id_Auto: " . $id_auto . "<br>";
    //echo "Valor de Switch: " . $switch . "<br>";
    if (empty($cedula) || empty($id_auto)) {
        $mensaje = "Debe seleccionar un tercero y una autorización.";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO autorizacion_tercero (CedulaNit, Id_Auto, Swich) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $cedula, $id_auto, $switch);
        if ($stmt->execute()) {
            $mensaje = "Autorización asignada correctamente al tercero.";
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
    INNER JOIN Autorizaciones a ON at.Id_Auto = a.Id_Auto
    ORDER BY at.F_Creacion DESC
");
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Asignar Autorizaciones a Terceros</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h2>Asignar Autorización a Tercero</h2>
    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>
    <form method="post" class="mt-3 mb-5">
        <div class="mb-3">
            <label for="CedulaNit" class="form-label">Tercero</label>
            <select name="CedulaNit" id="CedulaNit" class="form-select" required>
                <option value="">-- Seleccione un tercero --</option>
                <?php while ($t = $terceros->fetch_assoc()): ?>
                    <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)</option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="Id_Auto" class="form-label">Autorización</label>
            <select name="Id_Auto" id="Id_Auto" class="form-select" required>
                <option value="">-- Seleccione una autorización --</option>
                <?php while ($a = $autorizaciones->fetch_assoc()): ?>
                    <option value="<?= $a['Id_Auto'] ?>"><?= $a['Nro_Auto'] ?> - <?= $a['Nombre'] ?></option>
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
        <button type="submit" class="btn btn-primary">Asignar</button>
        <a href="Panel.php" class="btn btn-secondary">Volver</a>
    </form>

    <h3>Asignaciones Registradas</h3>
    <table class="table table-bordered table-striped mt-3">
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
                <td><?= $row['NombreCom'] ?></td>
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
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
