<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("Conexion.php");

if (isset($conn_error) && !empty($conn_error)) {
    die($conn_error);
}

$mensaje = "";
$error = "";
$pagina_actual = basename($_SERVER['PHP_SELF']);

// 1. Procesar Acciones POST (Guardar, Actualizar, Eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar' || $accion === 'actualizar') {
        $id_egreso = $_POST['id_egreso'] ?? '';
        $tipologia_id = $_POST['tipologia_id'] ?? '';
        $monto = $_POST['monto'] ?? '';
        $fecha = $_POST['fecha'] ?? '';
        $observacion = trim($_POST['observacion'] ?? '');

        if (!empty($tipologia_id) && !empty($monto) && !empty($fecha)) {
            if ($accion === 'actualizar' && !empty($id_egreso)) {
                $stmt = $mysqliWeb->prepare("UPDATE RegistroGastos SET tipologia_id = ?, monto = ?, fecha = ?, observacion = ? WHERE id = ?");
                $stmt->bind_param("idssi", $tipologia_id, $monto, $fecha, $observacion, $id_egreso);
                if ($stmt->execute()) {
                    $mensaje = "Gasto actualizado correctamente.";
                } else {
                    $error = "Error al actualizar: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $mysqliWeb->prepare("INSERT INTO RegistroGastos (tipologia_id, monto, fecha, observacion) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("idss", $tipologia_id, $monto, $fecha, $observacion);
                if ($stmt->execute()) {
                    $mensaje = "Gasto registrado correctamente.";
                } else {
                    $error = "Error al registrar: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $error = "Por favor completa los campos obligatorios.";
        }
    } elseif ($accion === 'eliminar') {
        $id_egreso = $_POST['id_egreso'] ?? '';
        if (!empty($id_egreso)) {
            $stmt = $mysqliWeb->prepare("DELETE FROM RegistroGastos WHERE id = ?");
            $stmt->bind_param("i", $id_egreso);
            if ($stmt->execute()) {
                $mensaje = "Gasto eliminado correctamente.";
            } else {
                $error = "Error al eliminar el registro.";
            }
            $stmt->close();
        }
    }
}

// 2. Obtener tipologías para el select
$resultado_tip = $mysqliWeb->query("SELECT id, nombre, tipo FROM TipologiaGastos ORDER BY nombre ASC");
$tipologias = [];
if ($resultado_tip) {
    while ($row = $resultado_tip->fetch_assoc()) {
        $tipologias[] = $row;
    }
}

// 3. Obtener todos los registros ordenados por Tipología y Fecha descendente
$sql_registros = "
    SELECT r.id, r.tipologia_id, t.nombre AS tipologia, t.tipo, r.monto, r.fecha, r.observacion 
    FROM RegistroGastos r 
    INNER JOIN TipologiaGastos t ON r.tipologia_id = t.id 
    ORDER BY t.nombre ASC, r.fecha DESC, r.id DESC
";
$resultado_regs = $mysqliWeb->query($sql_registros);
$registros = [];
$total_general = 0;
$total_fijos = 0;
$total_variables = 0;

if ($resultado_regs) {
    while ($row = $resultado_regs->fetch_assoc()) {
        $registros[] = $row;
        $monto_val = floatval($row['monto']);
        $total_general += $monto_val;
        if ($row['tipo'] === 'Fijo') {
            $total_fijos += $monto_val;
        } else {
            $total_variables += $monto_val;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Registro de Gastos</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Control y Edición de Egresos</h2>
        <a href="TipologiasGastos.php" class="btn btn-outline-secondary">&larr; Ir a Tipologías</a>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tarjetas de Totales -->
    <div class="row text-center mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body">
                    <h6 class="text-muted">Total General Egresos</h6>
                    <h3 class="text-primary">$<?= number_format($total_general, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-info">
                <div class="card-body">
                    <h6 class="text-muted">Total Gastos Fijos</h6>
                    <h3 class="text-info">$<?= number_format($total_fijos, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body">
                    <h6 class="text-muted">Total Gastos Variables</h6>
                    <h3 class="text-warning">$<?= number_format($total_variables, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Formulario Dinámico (Crear / Editar) -->
        <div class="col-md-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white" id="form-header">
                    <h5 class="mb-0" id="form-title">Registrar Nuevo Gasto</h5>
                </div>
                <div class="card-body">
                    <form id="form-gasto" action="<?= htmlspecialchars($pagina_actual) ?>" method="POST">
                        <input type="hidden" name="accion" id="accion" value="guardar">
                        <input type="hidden" name="id_egreso" id="id_egreso" value="">

                        <div class="mb-3">
                            <label for="tipologia_id" class="form-label">Tipología de Gasto</label>
                            <select class="form-select" id="tipologia_id" name="tipologia_id" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($tipologias as $tip): ?>
                                    <option value="<?= $tip['id'] ?>">
                                        <?= htmlspecialchars($tip['nombre']) ?> (<?= $tip['tipo'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="monto" class="form-label">Monto ($)</label>
                            <input type="number" step="0.01" class="form-control" id="monto" name="monto" required>
                        </div>

                        <div class="mb-3">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input type="date" class="form-control" id="fecha" name="fecha" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="observacion" class="form-label">Observación</label>
                            <textarea class="form-control" id="observacion" name="observacion" rows="3"></textarea>
                        </div>

                        <button type="submit" class="btn btn-success w-100" id="btn-submit">Guardar Gasto</button>
                        <button type="button" class="btn btn-secondary w-100 mt-2 d-none" id="btn-cancelar" onclick="resetFormulario()">Cancelar Edición</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabla de Listado Agrupada por Tipología -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Historial de Egresos por Tipología</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipología</th>
                                <th>Monto</th>
                                <th>Observación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($registros) > 0): ?>
                                <?php 
                                $tipologia_actual = "";
                                $subtotal_tipologia = 0;
                                
                                // Para manejar los subtotales por corte de grupo, recorreremos el array agrupado
                                // Como ya viene ordenado por tipología, podemos detectar el cambio.
                                for ($i = 0; $i < count($registros); $i++):
                                    $row = $registros[$i];
                                    $siguiente_row = $registros[$i + 1] ?? null;
                                    
                                    $subtotal_tipologia += floatval($row['monto']);
                                    $es_ultima_de_tipologia = ($siguiente_row === null || $siguiente_row['tipologia_id'] !== $row['tipologia_id']);
                                ?>
                                    <tr>
                                        <td><?= $row['fecha'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['tipologia']) ?></strong><br>
                                            <small class="text-muted"><?= $row['tipo'] ?></small>
                                        </td>
                                        <td>$<?= number_format($row['monto'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($row['observacion']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning" 
                                                onclick='prepararEdicion(<?= json_encode($row) ?>)'>
                                                Editar
                                            </button>
                                            
                                            <form action="<?= htmlspecialchars($pagina_actual) ?>" method="POST" class="d-inline" onsubmit="return confirm('¿Estás seguro de eliminar este registro?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_egreso" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Fila de Subtotal al terminar cada tipología -->
                                    <?php if ($es_ultima_de_tipologia): ?>
                                        <tr class="table-dark fw-bold">
                                            <td colspan="2" class="text-end">Subtotal <?= htmlspecialchars($row['tipologia']) ?>:</td>
                                            <td colspan="3">$<?= number_format($subtotal_tipologia, 2, ',', '.') ?></td>
                                        </tr>
                                        <?php 
                                        // Reiniciar subtotal para la siguiente tipología
                                        $subtotal_tipologia = 0; 
                                        ?>
                                    <?php endif; ?>

                                <?php endfor; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No hay egresos registrados aún.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript para alternar entre Crear y Editar dinámicamente -->
<script>
function prepararEdicion(gasto) {
    document.getElementById('accion').value = 'actualizar';
    document.getElementById('id_egreso').value = gasto.id;
    document.getElementById('tipologia_id').value = gasto.tipologia_id;
    document.getElementById('monto').value = gasto.monto;
    document.getElementById('fecha').value = gasto.fecha;
    document.getElementById('observacion').value = gasto.observacion || '';

    document.getElementById('form-title').innerText = 'Editar Gasto ID: ' + gasto.id;
    document.getElementById('form-header').classList.replace('bg-primary', 'bg-warning');
    document.getElementById('btn-submit').innerText = 'Actualizar Gasto';
    document.getElementById('btn-submit').classList.replace('btn-success', 'btn-warning');
    document.getElementById('btn-cancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetFormulario() {
    document.getElementById('form-gasto').reset();
    document.getElementById('accion').value = 'guardar';
    document.getElementById('id_egreso').value = '';
    document.getElementById('fecha').value = '<?= date('Y-m-d') ?>';

    document.getElementById('form-title').innerText = 'Registrar Nuevo Gasto';
    document.getElementById('form-header').classList.replace('bg-warning', 'bg-primary');
    document.getElementById('btn-submit').innerText = 'Guardar Gasto';
    document.getElementById('btn-submit').classList.replace('btn-warning', 'btn-success');
    document.getElementById('btn-cancelar').classList.add('d-none');
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>