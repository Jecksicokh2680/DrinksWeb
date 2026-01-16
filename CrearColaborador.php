<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// 1. CONEXIONES
require 'Conexion.php';    // Base Local ($mysqli)
include 'ConnCentral.php'; // Base Central ($mysqliPos)

$NitEmpresa = $_SESSION['NitEmpresa'] ?? '';
$Usuario    = $_SESSION['Usuario'] ?? '';
$mensaje    = "";

/* ============================================================
   LÓGICA DE ELIMINACIÓN (Procesada en el mismo archivo)
   ============================================================ */
if (isset($_GET['eliminar_id'])) {
    $id_a_borrar = intval($_GET['eliminar_id']);
    
    // Seguridad: Validamos que el ID pertenezca al NIT de la sesión
    $stmtDel = $mysqli->prepare("DELETE FROM colaborador WHERE id = ? AND NitEmpresa = ?");
    $stmtDel->bind_param("is", $id_a_borrar, $NitEmpresa);
    
    if ($stmtDel->execute()) {
        // Redireccionamos a sí mismo para limpiar la URL y actualizar la tabla
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=deleted");
        exit();
    } else {
        $mensaje = "<div class='alert alert-danger'>Error al eliminar: " . $mysqli->error . "</div>";
    }
    $stmtDel->close();
}

// Mensaje de confirmación tras redirección
if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    $mensaje = "<div class='alert alert-warning fw-bold shadow-sm'>🗑️ Registro eliminado correctamente.</div>";
}

/* ============================================================
   LÓGICA DE GUARDADO
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_colaborador'])) {
    $cedula   = $mysqli->real_escape_string($_POST['nit_seleccionado']);
    $salario  = $_POST['salario'];
    $tipo_con = $_POST['tipo_contrato'];
    $arl      = $_POST['nivel_arl'];
    $cargo    = $mysqli->real_escape_string($_POST['cargo']);
    $fecha    = $_POST['fecha_ingreso'];

    $sqlInsert = "INSERT INTO colaborador (CedulaNit, NitEmpresa, salario, tipo_contrato, nivel_arl, cargo, fecha_ingreso, estado) 
                  VALUES ('$cedula', '$NitEmpresa', $salario, '$tipo_con', $arl, '$cargo', '$fecha', 'ACTIVO')";

    if ($mysqli->query($sqlInsert)) {
        $mensaje = "<div class='alert alert-success fw-bold shadow-sm'>✅ Colaborador guardado correctamente.</div>";
    }
}

// Consulta de terceros para el select
$resTerceros = $mysqliPos->query("SELECT nit, nombres FROM terceros WHERE inactivo = 0 ORDER BY nombres ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Colaboradores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: sans-serif; }
        .card-nomina { border-top: 5px solid #0d6efd; border-radius: 12px; }
        .btn-delete { color: #dc3545; transition: 0.3s; padding: 5px; }
        .btn-delete:hover { color: #842029; transform: scale(1.2); }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 800px;">
    
    <?= $mensaje ?>

    <div class="card shadow card-nomina mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="text-primary fw-bold mb-0">💼 Registro de Colaborador</h4>
                <button type="button" class="btn btn-dark btn-sm fw-bold px-3" data-bs-toggle="modal" data-bs-target="#modalListado">
                    📋 VER REGISTRADOS
                </button>
            </div>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold small">Empleado (Terceros Central):</label>
                    <select name="nit_seleccionado" class="form-select border-primary" required>
                        <option value="">-- Seleccione un Tercero --</option>
                        <?php while ($t = $resTerceros->fetch_assoc()): ?>
                            <option value="<?= $t['nit'] ?>"><?= htmlspecialchars($t['nombres']) ?> (<?= $t['nit'] ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cargo</label>
                        <input type="text" name="cargo" class="form-control" required placeholder="Ej: Cajero">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Salario Mensual</label>
                        <input type="number" name="salario" class="form-control" required placeholder="1300000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Tipo Contrato</label>
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
                    <div class="col-md-12 mb-3">
                        <label class="form-label small fw-bold">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <button type="submit" name="guardar_colaborador" class="btn btn-primary w-100 btn-lg fw-bold shadow">
                    💾 GUARDAR COLABORADOR
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalListado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title fw-bold">📋 Personal Registrado en Nómina</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Cédula</th>
                                <th>Nombre</th>
                                <th>Cargo</th>
                                <th>Sueldo</th>
                                <th>Ingreso</th>
                                <th class="text-center">Eliminar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sqlL = "SELECT * FROM colaborador WHERE NitEmpresa = '$NitEmpresa' ORDER BY id DESC";
                            $resL = $mysqli->query($sqlL);

                            while ($c = $resL->fetch_assoc()):
                                $ced = $c['CedulaNit'];
                                $resN = $mysqliPos->query("SELECT nombres FROM terceros WHERE nit = '$ced' LIMIT 1");
                                $nom = ($resN && $resN->num_rows > 0) ? $resN->fetch_assoc()['nombres'] : 'No encontrado';
                            ?>
                            <tr>
                                <td class="fw-bold ps-3"><?= $c['CedulaNit'] ?></td>
                                <td><?= htmlspecialchars($nom) ?></td>
                                <td><?= $c['cargo'] ?></td>
                                <td>$<?= number_format($c['salario']) ?></td>
                                <td><?= $c['fecha_ingreso'] ?></td>
                                <td class="text-center">
                                    <a href="?eliminar_id=<?= $c['id'] ?>" 
                                       class="btn-delete" 
                                       onclick="return confirm('¿Está seguro de eliminar permanentemente a este colaborador?');">
                                       <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-trash3-fill" viewBox="0 0 16 16">
                                          <path d="M11 1.5v1h3.5a.5.5 0 0 1 0 1h-.538l-.853 10.66A2 2 0 0 1 11.115 16h-6.23a2 2 0 0 1-1.994-1.84L2.038 3.5H1.5a.5.5 0 0 1 0-1H5v-1A1.5 1.5 0 0 1 6.5 0h3A1.5 1.5 0 0 1 11 1.5m-5 0v1h3v-1a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5M4.5 5.029l.5 8.5a.5.5 0 1 0 .998-.06l-.5-8.5a.5.5 0 1 0-.998.06m6.53-.528a.5.5 0 0 0-.528.47l-.5 8.5a.5.5 0 0 0 .998.058l.5-8.5a.5.5 0 0 0-.47-.528M8 4.5a.5.5 0 0 0-.5.5v8.5a.5.5 0 0 0 1 0V5a.5.5 0 0 0-.5-.5"/>
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