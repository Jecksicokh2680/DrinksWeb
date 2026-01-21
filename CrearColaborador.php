<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// 1. CONEXIONES
require 'Conexion.php';    // Base Local ($mysqli)
include 'ConnCentral.php'; // Base Central ($mysqliPos)

if (!isset($_SESSION['NitEmpresa'])) {
    die("Error: No se encuentra la sesión de la empresa.");
}

$NitEmpresa = $_SESSION['NitEmpresa'];
$mensaje = "";

/* ============================================================
    LÓGICA 1: IMPORTACIÓN MASIVA DE TERCEROS
   ============================================================ */
if (isset($_POST['importar_terceros'])) {
    $resCen = $mysqliPos->query("SELECT nit, nombres, nomcomercial, email FROM terceros WHERE inactivo = 0");
    $importados = 0;

    while ($tc = $resCen->fetch_assoc()) {
        $nit  = $mysqli->real_escape_string($tc['nit']);
        $nom  = $mysqli->real_escape_string($tc['nombres']);
        $com  = $mysqli->real_escape_string($tc['nomcomercial'] ?? '');
        $mail = $mysqli->real_escape_string($tc['email'] ?? '');
        
        $sqlSync = "INSERT IGNORE INTO terceros (IdTercero, CedulaNit, Nombre, NombreCom, Email, Estado) 
                    VALUES ('$nit', '$nit', '$nom', '$com', '$mail', 1)";
        if ($mysqli->query($sqlSync) && $mysqli->affected_rows > 0) {
            $importados++;
        }
    }
    $mensaje = "<div class='alert alert-info fw-bold shadow-sm'>🔄 Se importaron $importados nuevos terceros.</div>";
}

/* ============================================================
    LÓGICA 2: ELIMINACIÓN DE COLABORADOR
   ============================================================ */
if (isset($_GET['eliminar_id'])) {
    $id_a_borrar = intval($_GET['eliminar_id']);
    $stmtDel = $mysqli->prepare("DELETE FROM colaborador WHERE id = ? AND NitEmpresa = ?");
    $stmtDel->bind_param("is", $id_a_borrar, $NitEmpresa);
    
    if ($stmtDel->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=deleted");
        exit();
    }
    $stmtDel->close();
}

/* ============================================================
    LÓGICA 3: GUARDADO CON VALIDACIÓN DE DUPLICADOS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_colaborador'])) {
    $cedula   = $_POST['nit_seleccionado'];
    $salario  = floatval($_POST['salario']);
    $tipo_con = $_POST['tipo_contrato'];
    $arl      = intval($_POST['nivel_arl']);
    $cargo    = $_POST['cargo'];
    $fecha    = $_POST['fecha_ingreso'];

    // VALIDACIÓN: ¿Ya existe el colaborador en esta empresa?
    $stmtCheck = $mysqli->prepare("SELECT id FROM colaborador WHERE CedulaNit = ? AND NitEmpresa = ?");
    $stmtCheck->bind_param("ss", $cedula, $NitEmpresa);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();

    if ($resCheck->num_rows > 0) {
        $mensaje = "<div class='alert alert-danger fw-bold shadow-sm'>⚠️ Error: El colaborador con cédula $cedula ya está registrado en esta empresa.</div>";
    } else {
        // A. Sincronizar Tercero si no existe localmente
        $checkT = $mysqli->query("SELECT CedulaNit FROM terceros WHERE CedulaNit = '$cedula'");
        if ($checkT->num_rows == 0) {
            $resC = $mysqliPos->query("SELECT nit, nombres, email FROM terceros WHERE nit = '$cedula' LIMIT 1");
            if ($rowC = $resC->fetch_assoc()) {
                $nomC = $mysqli->real_escape_string($rowC['nombres']);
                $emC  = $mysqli->real_escape_string($rowC['email'] ?? '');
                $mysqli->query("INSERT INTO terceros (IdTercero, CedulaNit, Nombre, Email, Estado) VALUES ('$cedula', '$cedula', '$nomC', '$emC', 1)");
            }
        }

        // B. Insertar Colaborador usando Prepared Statement
        $stmtIns = $mysqli->prepare("INSERT INTO colaborador (CedulaNit, NitEmpresa, salario, tipo_contrato, nivel_arl, cargo, fecha_ingreso, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVO')");
        $stmtIns->bind_param("ssdssss", $cedula, $NitEmpresa, $salario, $tipo_con, $arl, $cargo, $fecha);

        if ($stmtIns->execute()) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=success");
            exit();
        } else {
            $mensaje = "<div class='alert alert-danger'>Error al guardar: " . $mysqli->error . "</div>";
        }
        $stmtIns->close();
    }
    $stmtCheck->close();
}

// Mensajes de sistema
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $mensaje = "<div class='alert alert-warning fw-bold'>🗑️ Registro eliminado correctamente.</div>";
    if ($_GET['msg'] == 'success') $mensaje = "<div class='alert alert-success fw-bold'>✅ Colaborador guardado correctamente.</div>";
}

$resTerceros = $mysqliPos->query("SELECT nit, nombres FROM terceros WHERE inactivo = 0 ORDER BY nombres ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Colaboradores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .card-custom { border: none; border-radius: 15px; border-top: 6px solid #0d6efd; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 900px;">
    
    <?= $mensaje ?>

    <div class="card mb-4 shadow-sm border-0">
        <div class="card-body d-flex justify-content-between align-items-center bg-white" style="border-radius: 15px;">
            <div>
                <h6 class="mb-0 fw-bold text-primary">Sincronización de Datos</h6>
                <small class="text-muted">Actualiza terceros desde la base central</small>
            </div>
            <form method="POST">
                <button type="submit" name="importar_terceros" class="btn btn-outline-primary btn-sm fw-bold">
                    📥 IMPORTAR TODO DESDE SERVIDOR CENTRAL
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow card-custom mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold text-dark mb-0">💼 Registro de Colaborador</h4>
                <button type="button" class="btn btn-dark btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalListado">
                    📋 VER REGISTRADOS
                </button>
            </div>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold small">Seleccionar Empleado (Base Central):</label>
                    <select name="nit_seleccionado" class="form-select" required>
                        <option value="">-- Seleccione un Tercero --</option>
                        <?php while ($t = $resTerceros->fetch_assoc()): ?>
                            <option value="<?= $t['nit'] ?>"><?= htmlspecialchars($t['nombres']) ?> (<?= $t['nit'] ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cargo</label>
                        <input type="text" name="cargo" class="form-control" placeholder="Ej: Administrador" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Salario Mensual</label>
                        <input type="number" name="salario" class="form-control" required placeholder="1750905">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Tipo de Contrato</label>
                        <select name="tipo_contrato" class="form-select">
                            <option value="Indefinido">Indefinido</option>
                            <option value="Fijo">Fijo</option>
                            <option value="Obra o Labor">Obra o Labor</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nivel ARL</label>
                        <input type="number" name="nivel_arl" class="form-control" value="1">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-bold">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <button type="submit" name="guardar_colaborador" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                    💾 GUARDAR EN NÓMINA LOCAL
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalListado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title fw-bold">📋 Personal Registrado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Identificación</th>
                                <th>Nombre Completo</th>
                                <th>Cargo</th>
                                <th>Salario</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sqlL = "SELECT c.*, t.Nombre FROM colaborador c 
                                     LEFT JOIN terceros t ON c.CedulaNit = t.CedulaNit 
                                     WHERE c.NitEmpresa = '$NitEmpresa' ORDER BY c.id DESC";
                            $resL = $mysqli->query($sqlL);
                            while ($c = $resL->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= $c['CedulaNit'] ?></td>
                                <td><?= htmlspecialchars($c['Nombre'] ?? 'No sincronizado') ?></td>
                                <td><?= htmlspecialchars($c['cargo']) ?></td>
                                <td>$<?= number_format($c['salario'], 0) ?></td>
                                <td class="text-center">
                                    <a href="?eliminar_id=<?= $c['id'] ?>" class="btn btn-link text-danger p-0" onclick="return confirm('¿Eliminar?');">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-trash-fill" viewBox="0 0 16 16">
                                            <path d="M2.5 1a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4h.5a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1zm3 4a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 .5-.5M8 5a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7A.5.5 0 0 1 8 5m3 .5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 1 0"/>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>