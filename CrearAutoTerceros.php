<?php
require 'Conexion.php';
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
   CARGAR LISTAS DE TERCEROS Y AUTORIZACIONES
   ============================================================ */
$terceros = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros ORDER BY Nombre ASC");
$autorizaciones = $mysqli->query("SELECT Nro_Auto, Nombre FROM Autorizaciones WHERE Estado='1' ORDER BY Nro_Auto ASC");

/* ============================================================
   FILTRO POR TERCERO
   ============================================================ */
$filtroTercero = $_GET['filtro_tercero'] ?? "";

/* ============================================================
   GUARDAR NUEVA ASIGNACIÓN  (BLOQUE TOTALMENTE REPARADO)
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarAsignacion'])) {

    $cedula   = trim($_POST['CedulaNit'] ?? "");
    $Nro_Auto = trim($_POST['Nro_Auto'] ?? "");
    $switch   = ($_POST['Swich'] ?? 'SI') === "NO" ? "NO" : "SI";

    if ($cedula === "" || $Nro_Auto === "") {
        $mensaje = "Debe seleccionar un tercero y una autorización.";
    } else {

        /* 1) Verificar existencia del tercero */
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM terceros WHERE CedulaNit=?");
        $stmt->bind_param("s", $cedula);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row['total'] == 0) {
            $mensaje = "❌ El tercero no existe en la base de datos.";
        } else {

            /* 2) Verificar existencia de la autorización */
            $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM Autorizaciones WHERE Nro_Auto=?");
            $stmt->bind_param("s", $Nro_Auto);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row['total'] == 0) {
                $mensaje = "❌ La autorización no existe.";
            } else {

                /* 3) Verificar si YA existe la combinación */
                $stmt = $mysqli->prepare("
                    SELECT COUNT(*) AS total 
                    FROM autorizacion_tercero 
                    WHERE CedulaNit=? AND Nro_Auto=?
                ");
                $stmt->bind_param("ss", $cedula, $Nro_Auto);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row['total'] > 0) {
                    $mensaje = "⚠️ Esta autorización ya está asignada a este tercero.";
                } else {

                    /* 4) Insertar nueva autorización */
                    $stmt = $mysqli->prepare("
                        INSERT INTO autorizacion_tercero (CedulaNit, Nro_Auto, Swich)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->bind_param("sss", $cedula, $Nro_Auto, $switch);

                    if ($stmt->execute()) {
                        $mensaje = "✅ Autorización asignada correctamente.";
                    } else {
                        $mensaje = "❌ Error al insertar: " . $stmt->error;
                    }

                    $stmt->close();
                }
            }
        }
    }
}


/* ============================================================
   COPIAR PERMISOS ENTRE TERCEROS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copiarPermisos'])) {

    $origen  = $_POST['CedulaOrigen']  ?? "";
    $destino = $_POST['CedulaDestino'] ?? "";

    if (empty($origen) || empty($destino) || $origen === $destino) {
        $mensaje = "Debe seleccionar un tercero origen y otro destino diferente.";
    } else {

        $result = $mysqli->query("
            SELECT Nro_Auto, Swich 
            FROM autorizacion_tercero 
            WHERE CedulaNit='$origen'
        ");

        while ($row = $result->fetch_assoc()) {
            $stmt = $mysqli->prepare("
                INSERT IGNORE INTO autorizacion_tercero (CedulaNit, Nro_Auto, Swich)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param("sss", $destino, $row['Nro_Auto'], $row['Swich']);
            $stmt->execute();
            $stmt->close();
        }

        $mensaje = "Permisos copiados de manera exitosa.";
    }
}

/* ============================================================
   ELIMINAR ASIGNACIÓN
   ============================================================ */
if (isset($_GET['delete'])) {

    $id_delete = intval($_GET['delete']);

    $stmt = $mysqli->prepare("DELETE FROM autorizacion_tercero WHERE Id=?");
    $stmt->bind_param("i", $id_delete);

    if ($stmt->execute()) {
        $mensaje = "Asignación eliminada correctamente.";
    } else {
        $mensaje = "Error al eliminar: " . $stmt->error;
    }

    $stmt->close();
}

/* ============================================================
   CONSULTA DE ASIGNACIONES CON FILTRO
   ============================================================ */
$where = $filtroTercero ? "WHERE at.CedulaNit='$filtroTercero'" : "";

$asignaciones = $mysqli->query("
    SELECT at.Id, at.CedulaNit, t.Nombre, a.Nro_Auto, a.Nombre AS AutoNombre,
           at.Swich, at.Estado, at.F_Creacion
    FROM autorizacion_tercero at
    INNER JOIN terceros t ON at.CedulaNit = t.CedulaNit
    INNER JOIN Autorizaciones a ON at.Nro_Auto = a.Nro_Auto
    $where
    ORDER BY at.CedulaNit, a.Nro_Auto ASC
");

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Asignar Autorizaciones</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background-color: #f3f4f6; padding: 20px; }
.card { margin-bottom: 20px; border-radius: 0.5rem; }
.table thead { background-color: #343a40; color: white; }
</style>

</head>
<body>

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Asignaciones de Autorizaciones</h2>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAsignacion">➕ Nueva Asignación</button>
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalCopiar">📋 Copiar Permisos</button>
        </div>
    </div>

    <!-- Filtro por tercero -->
    <form method="get" class="mb-3">
        <div class="row g-2">
            <div class="col-auto">
                <label class="col-form-label">Filtrar por Tercero:</label>
            </div>
            <div class="col-auto">
                <select name="filtro_tercero" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Todos los terceros --</option>

                    <?php 
                    $terceros->data_seek(0);
                    while ($t = $terceros->fetch_assoc()):
                        $selected = ($t['CedulaNit'] === $filtroTercero) ? "selected" : "";
                    ?>
                        <option value="<?= $t['CedulaNit'] ?>" <?= $selected ?>>
                            <?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-auto">
                <a href="?" class="btn btn-outline-secondary">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <!-- Tabla -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
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
                    <td><?= $row['Estado'] == 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= $row['F_Creacion'] ?></td>
                    <td>
                        <a href="?delete=<?= $row['Id'] ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('¿Eliminar asignación?');">
                           Borrar
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>

            <?php if ($asignaciones->num_rows === 0): ?>
                <tr><td colspan="8" class="text-center">No hay asignaciones.</td></tr>
            <?php endif; ?>

            </tbody>
        </table>
    </div>

</div>

<!-- Modal Nueva Asignación -->
<div class="modal fade" id="modalAsignacion" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">

        <div class="modal-header">
            <h5 class="modal-title">Nueva Asignación</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Tercero</label>
                <select name="CedulaNit" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php
                    $terceros->data_seek(0);
                    while ($t = $terceros->fetch_assoc()):
                    ?>
                        <option value="<?= $t['CedulaNit'] ?>">
                            <?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Autorización</label>
                <select name="Nro_Auto" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php
                    $autorizaciones->data_seek(0);
                    while ($a = $autorizaciones->fetch_assoc()):
                    ?>
                        <option value="<?= $a['Nro_Auto'] ?>">
                            <?= $a['Nro_Auto'] ?> - <?= $a['Nombre'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Swich</label>
                <select name="Swich" class="form-select">
                    <option value="SI">SI</option>
                    <option value="NO">NO</option>
                </select>
            </div>

            <input type="hidden" name="guardarAsignacion" value="1">

        </div>

        <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Guardar</button>
        </div>

      </form>
    </div>
  </div>
</div>

<!-- Modal Copiar Permisos -->
<div class="modal fade" id="modalCopiar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">

        <div class="modal-header">
            <h5 class="modal-title">Copiar Permisos</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

            <div class="mb-3">
                <label class="form-label">Tercero Origen</label>
                <select name="CedulaOrigen" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php
                    $terceros->data_seek(0);
                    while ($t = $terceros->fetch_assoc()):
                    ?>
                        <option value="<?= $t['CedulaNit'] ?>">
                            <?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Tercero Destino</label>
                <select name="CedulaDestino" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php
                    $terceros->data_seek(0);
                    while ($t = $terceros->fetch_assoc()):
                    ?>
                        <option value="<?= $t['CedulaNit'] ?>">
                            <?= $t['Nombre'] ?> (<?= $t['CedulaNit'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <input type="hidden" name="copiarPermisos" value="1">

        </div>

        <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-success" type="submit">Copiar</button>
        </div>

      </form>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
