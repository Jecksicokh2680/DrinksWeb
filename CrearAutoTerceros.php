<?php
require 'Conexion.php';    // Base Local ($mysqli)
require 'ConnCentral.php'; // Base Central ($mysqliPos)
require 'helpers.php';
session_start();

/* ============================================================
   VALIDAR SESIÓN
   ============================================================ */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$mensaje = "";

/* ============================================================
   CARGAR LISTAS (Terceros desde CENTRAL, Autorizaciones LOCAL)
   ============================================================ */
// Nota: Ajusté 'nit' y 'nombres/apellidos' según la estructura estándar de tu base central
$terceros = $mysqliPos->query("SELECT nit AS CedulaNit, trim(concat(nombres,' ',apellidos)) AS Nombre FROM terceros WHERE inactivo = 0 ORDER BY nombres ASC");
$autorizaciones = $mysqli->query("SELECT Nro_Auto, Nombre FROM Autorizaciones WHERE Estado='1' ORDER BY Nro_Auto ASC");

/* ============================================================
   FILTRO POR TERCERO
   ============================================================ */
$filtroTercero = $_GET['filtro_tercero'] ?? "";

/* ============================================================
   GUARDAR NUEVA ASIGNACIÓN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarAsignacion'])) {
    $cedula   = trim($_POST['CedulaNit'] ?? "");
    $Nro_Auto = trim($_POST['Nro_Auto'] ?? "");
    $switch   = ($_POST['Swich'] ?? 'SI') === "NO" ? "NO" : "SI";

    if ($cedula === "" || $Nro_Auto === "") {
        $mensaje = "Debe seleccionar un tercero y una autorización.";
    } else {
        // 1) Verificar existencia del tercero en CENTRAL
        $stmt = $mysqliPos->prepare("SELECT COUNT(*) AS total FROM terceros WHERE nit=?");
        $stmt->bind_param("s", $cedula);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row['total'] == 0) {
            $mensaje = "❌ El tercero no existe en el Servidor Central.";
        } else {
            // 2) Verificar existencia de la autorización en LOCAL
            $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM Autorizaciones WHERE Nro_Auto=?");
            $stmt->bind_param("s", $Nro_Auto);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row['total'] == 0) {
                $mensaje = "❌ La autorización no existe.";
            } else {
                // 3) Verificar duplicados y 4) Insertar en LOCAL
                $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
                $stmt->bind_param("ss", $cedula, $Nro_Auto);
                $stmt->execute();
                $existe = $stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();

                if ($existe > 0) {
                    $mensaje = "⚠️ Esta autorización ya está asignada.";
                } else {
                    $stmt = $mysqli->prepare("INSERT INTO autorizacion_tercero (CedulaNit, Nro_Auto, Swich, F_Creacion, Estado) VALUES (?, ?, ?, NOW(), 1)");
                    $stmt->bind_param("sss", $cedula, $Nro_Auto, $switch);
                    $mensaje = $stmt->execute() ? "✅ Asignada correctamente." : "❌ Error: " . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }
}

/* ============================================================
   ELIMINAR ASIGNACIÓN
   ============================================================ */
if (isset($_GET['delete'])) {
    $id_delete = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM autorizacion_tercero WHERE Id=?");
    $stmt->bind_param("i", $id_delete);
    $mensaje = $stmt->execute() ? "Asignación eliminada." : "Error al eliminar.";
    $stmt->close();
}

/* ============================================================
   CONSULTA DE ASIGNACIONES (Cruce de bases de datos)
   ============================================================ */
$where = $filtroTercero ? "WHERE at.CedulaNit='$filtroTercero'" : "";

// Traemos las asignaciones locales
$resAsignaciones = $mysqli->query("
    SELECT at.Id, at.CedulaNit, at.Nro_Auto, a.Nombre AS AutoNombre, at.Swich, at.Estado, at.F_Creacion
    FROM autorizacion_tercero at
    INNER JOIN Autorizaciones a ON at.Nro_Auto = a.Nro_Auto
    $where
    ORDER BY at.CedulaNit ASC
");

$asignacionesFinales = [];
while ($reg = $resAsignaciones->fetch_assoc()) {
    // Buscamos el nombre del tercero en la base CENTRAL para cada fila
    $nit = $reg['CedulaNit'];
    $buscT = $mysqliPos->query("SELECT trim(concat(nombres,' ',apellidos)) AS Nombre FROM terceros WHERE nit='$nit' LIMIT 1");
    $infoT = $buscT->fetch_assoc();
    $reg['NombreTercero'] = $infoT ? $infoT['Nombre'] : "<span class='text-danger'>No encontrado en Central</span>";
    $asignacionesFinales[] = $reg;
}
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Asignar Autorizaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; padding: 20px; }
        .table thead { background-color: #1a2a3a; color: white; }
    </style>
</head>
<body>

<div class="container bg-white p-4 shadow-sm rounded">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold">Gestión de Permisos</h2>
        <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalAsignacion">➕ Nueva Asignación</button>
    </div>

    <form method="get" class="row g-3 mb-4 align-items-end">
        <div class="col-md-4">
            <label class="form-label fw-bold">Filtrar por Tercero:</label>
            <select name="filtro_tercero" class="form-select" onchange="this.form.submit()">
                <option value="">-- Todos los terceros --</option>
                <?php 
                $terceros->data_seek(0);
                while ($t = $terceros->fetch_assoc()): 
                    $sel = ($t['CedulaNit'] === $filtroTercero) ? "selected" : "";
                ?>
                    <option value="<?= $t['CedulaNit'] ?>" <?= $sel ?>><?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)</option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2">
            <a href="?" class="btn btn-outline-secondary w-100">Limpiar</a>
        </div>
    </form>

    <?php if ($mensaje): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= $mensaje ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Tercero (Central)</th>
                    <th>Cédula/NIT</th>
                    <th>Autorización</th>
                    <th>Switch</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($asignacionesFinales as $row): ?>
                <tr>
                    <td><strong><?= $row['NombreTercero'] ?></strong></td>
                    <td><?= $row['CedulaNit'] ?></td>
                    <td><small class="badge bg-light text-dark border"><?= $row['Nro_Auto'] ?></small> <?= $row['AutoNombre'] ?></td>
                    <td><span class="badge <?= $row['Swich']=='SI'?'bg-success':'bg-warning' ?>"><?= $row['Swich'] ?></span></td>
                    <td><?= $row['Estado'] == 1 ? '✅' : '❌' ?></td>
                    <td>
                        <a href="?delete=<?= $row['Id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar asignación?');">Borrar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($asignacionesFinales)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron registros.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalAsignacion" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Asignar Autorización</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Tercero (Desde Central)</label>
                    <select name="CedulaNit" class="form-select" required>
                        <option value="">-- Seleccione un tercero --</option>
                        <?php 
                        $terceros->data_seek(0);
                        while ($t = $terceros->fetch_assoc()): ?>
                            <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Autorización (Local)</label>
                    <select name="Nro_Auto" class="form-select" required>
                        <option value="">-- Seleccione --</option>
                        <?php 
                        $autorizaciones->data_seek(0);
                        while ($a = $autorizaciones->fetch_assoc()): ?>
                            <option value="<?= $a['Nro_Auto'] ?>"><?= $a['Nro_Auto'] ?> - <?= $a['Nombre'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Habilitar Switch</label>
                    <select name="Swich" class="form-select">
                        <option value="SI">SI</option>
                        <option value="NO">NO</option>
                    </select>
                </div>
                <input type="hidden" name="guardarAsignacion" value="1">
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary w-100 fw-bold">GUARDAR ASIGNACIÓN</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>