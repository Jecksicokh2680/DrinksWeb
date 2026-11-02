<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar zona horaria de Bogotá, Colombia
date_default_timezone_set('America/Bogota');

include("Conexion.php");

if (isset($conn_error) && !empty($conn_error)) {
    die($conn_error);
}

// ---------------------------------------------------------
// PROCESAMIENTO AJAX: ACTUALIZAR ESTADO DEL CHECK (CAUSADO)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'actualizar_causado') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id_registro = $input['id'] ?? '';
    $causado = isset($input['causado']) ? (int)$input['causado'] : 0;

    if (!empty($id_registro)) {
        $stmt = $mysqliWeb->prepare("UPDATE RegistroGastos SET causado = ? WHERE id = ?");
        $stmt->bind_param("ii", $causado, $id_registro);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Estado actualizado']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al actualizar']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
    }
    exit;
}

$mensaje = "";
$error = "";
$pagina_actual = basename($_SERVER['PHP_SELF']);

// Capturar la fecha destino seleccionada (por defecto la fecha actual de Bogotá: YYYY-MM-DD)
$fecha_seleccionada = $_GET['fecha'] ?? date('Y-m-d');

// 1. Procesar Acciones POST (Guardar, Actualizar, Eliminar, Copiar validando duplicados)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
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
                    $mensaje = "Registro actualizado correctamente.";
                } else {
                    $error = "Error al actualizar: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $mysqliWeb->prepare("INSERT INTO RegistroGastos (tipologia_id, monto, fecha, observacion, causado) VALUES (?, ?, ?, ?, 1)");
                $stmt->bind_param("idss", $tipologia_id, $monto, $fecha, $observacion);
                if ($stmt->execute()) {
                    $mensaje = "Registro guardado correctamente.";
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
                $mensaje = "Registro eliminado correctamente.";
            } else {
                $error = "Error al eliminar el registro.";
            }
            $stmt->close();
        }
    } elseif ($accion === 'copiar_desde_fecha') {
        $fecha_origen = $_POST['fecha_origen'] ?? '';

        if (!empty($fecha_origen)) {
            // Copiar TODOS los registros (Activos, Pasivos, Fijos, Variables) de la fecha de origen
            $sql_copiar = "SELECT r.tipologia_id, r.monto, r.observacion, r.causado, t.tipo 
                           FROM RegistroGastos r
                           INNER JOIN TipologiaGastos t ON r.tipologia_id = t.id
                           WHERE r.fecha = ?";
            $stmt_c = $mysqliWeb->prepare($sql_copiar);
            $stmt_c->bind_param("s", $fecha_origen);
            $stmt_c->execute();
            $res_c = $stmt_c->get_result();

            $copiados = 0;
            $duplicados = 0;

            while ($row_c = $res_c->fetch_assoc()) {
                $tip_id = $row_c['tipologia_id'];
                $monto_val = $row_c['monto'];
                $tipo_reg = $row_c['tipo'];
                $estado_causado = isset($row_c['causado']) ? (int)$row_c['causado'] : 1;
                $obs = "Copiado desde fecha {$fecha_origen} ({$tipo_reg}) - " . ($row_c['observacion'] ?? '');

                // Verificar si ya existe este registro (misma tipología y misma fecha de destino)
                $stmt_chk = $mysqliWeb->prepare("SELECT COUNT(*) FROM RegistroGastos WHERE tipologia_id = ? AND fecha = ?");
                $stmt_chk->bind_param("is", $tip_id, $fecha_seleccionada);
                $stmt_chk->execute();
                $stmt_chk->bind_result($existe);
                $stmt_chk->fetch();
                $stmt_chk->close();

                if ($existe == 0) {
                    $stmt_ins = $mysqliWeb->prepare("INSERT INTO RegistroGastos (tipologia_id, monto, fecha, observacion, causado) VALUES (?, ?, ?, ?, ?)");
                    $stmt_ins->bind_param("idssi", $tip_id, $monto_val, $fecha_seleccionada, $obs, $estado_causado);
                    if ($stmt_ins->execute()) {
                        $copiados++;
                    }
                    $stmt_ins->close();
                } else {
                    $duplicados++;
                }
            }
            $stmt_c->close();

            if ($copiados > 0 || $duplicados > 0) {
                $mensaje = "¡Copia finalizada! Se copiaron $copiados registros nuevos ($duplicados ya existían y fueron omitidos).";
            } else {
                $error = "No se encontraron registros en la fecha de origen seleccionada ($fecha_origen) para copiar.";
            }
        } else {
            $error = "Por favor selecciona una fecha de origen válida.";
        }
    }
}

// 2. Obtener tipologías con su ámbito y tipo para el select
$resultado_tip = $mysqliWeb->query("SELECT id, nombre, ambito, tipo FROM TipologiaGastos ORDER BY ambito ASC, tipo ASC, nombre ASC");
$tipologias = [];
if ($resultado_tip) {
    while ($row = $resultado_tip->fetch_assoc()) {
        $tipologias[] = $row;
    }
}

// 3. Obtener registros filtrados estrictamente por la FECHA exacta seleccionada
$sql_registros = "
    SELECT r.id, r.tipologia_id, t.nombre AS tipologia, t.tipo, t.ambito, r.monto, r.fecha, r.observacion, r.causado 
    FROM RegistroGastos r 
    INNER JOIN TipologiaGastos t ON r.tipologia_id = t.id 
    WHERE r.fecha = ?
    ORDER BY t.ambito ASC, t.tipo ASC, r.id DESC
";
$stmt_regs = $mysqliWeb->prepare($sql_registros);
$stmt_regs->bind_param("s", $fecha_seleccionada);
$stmt_regs->execute();
$resultado_regs = $stmt_regs->get_result();

$activos = [];
$pasivos_gastos = [];
$total_activos = 0;
$total_pasivos_empresa = 0;
$total_pasivos_propio = 0;
$total_pasivos = 0;
$total_gastos = 0;

if ($resultado_regs) {
    while ($row = $resultado_regs->fetch_assoc()) {
        $monto_val = floatval($row['monto']);
        $ambito_row = $row['ambito'] ?? 'Empresa';
        $es_causado = !isset($row['causado']) || $row['causado'] == 1;

        if ($row['tipo'] === 'Activo') {
            $activos[] = $row;
            if ($es_causado) {
                $total_activos += $monto_val;
            }
        } else {
            $pasivos_gastos[] = $row;
            if ($es_causado) {
                if ($row['tipo'] === 'Pasivo') {
                    $total_pasivos += $monto_val;
                    if ($ambito_row === 'Empresa') {
                        $total_pasivos_empresa += $monto_val;
                    } else {
                        $total_pasivos_propio += $monto_val;
                    }
                } else {
                    $total_gastos += $monto_val;
                }
            }
        }
    }
}
$stmt_regs->close();

$total_haber_activo = $total_pasivos + $total_gastos;
$total_neto = $total_activos - $total_pasivos;

$fecha_sugerida_origen = date('Y-m-d', strtotime($fecha_seleccionada . ' -1 day'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuenta T - Activos, Pasivos y Neto (P&G)</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container my-4">
    <!-- Cabecera y Botones -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Balance y Movimientos Día a Día (Cuenta T)</h2>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalCopiar">
                📋 Copiar desde Fecha...
            </button>
            <button type="button" class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalMovimiento" onclick="resetFormulario()">
                + Nuevo Movimiento
            </button>
            <a href="TipologiasGastos.php" class="btn btn-outline-secondary btn-sm">&larr; Tipologías</a>
        </div>
    </div>

    <!-- FILTRADOR DE FECHA EXACTA (DÍA - MES - AÑO) -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" action="<?= htmlspecialchars($pagina_actual) ?>" class="row align-items-center g-2">
                <div class="col-auto">
                    <label for="fecha" class="fw-bold col-form-label col-form-label-sm">Fecha (Día - Mes - Año):</label>
                </div>
                <div class="col-md-3">
                    <input type="date" name="fecha" id="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($fecha_seleccionada) ?>" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar Día</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <?= $mensaje ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- TARJETAS RESUMEN SUPERIORES -->
    <div class="row text-center mb-4">
        <div class="col-md-4 mb-2">
            <div class="card shadow-sm border-success">
                <div class="card-body p-2">
                    <h6 class="text-muted mb-1" style="font-size: 0.85rem;">Total Activos (Día)</h6>
                    <h4 class="text-success mb-0">$<?= number_format($total_activos, 2, ',', '.') ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card shadow-sm border-dark">
                <div class="card-body p-2">
                    <h6 class="text-muted mb-1" style="font-size: 0.85rem;">Total Pasivos y Gastos Activos (Día)</h6>
                    <h5 class="text-dark mb-0">$<?= number_format($total_haber_activo, 2, ',', '.') ?></h5>
                    <div style="font-size: 0.75rem;" class="text-muted mt-1">
                        Pasivos Empresa: <span class="fw-bold text-dark">$<?= number_format($total_pasivos_empresa, 2, ',', '.') ?></span> | 
                        Propio: <span class="fw-bold text-dark">$<?= number_format($total_pasivos_propio, 2, ',', '.') ?></span> |
                        Gastos: <span class="fw-bold text-dark">$<?= number_format($total_gastos, 2, ',', '.') ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="card shadow-sm <?= $total_neto >= 0 ? 'border-primary' : 'border-danger' ?>">
                <div class="card-body p-2">
                    <h6 class="text-muted mb-1" style="font-size: 0.85rem;">Neto (Patrimonio / Diferencia)</h6>
                    <h4 class="<?= $total_neto >= 0 ? 'text-primary' : 'text-danger' ?> mb-0">$<?= number_format($total_neto, 2, ',', '.') ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- ESTRUCTURA TIPO CUENTA T (2 COLUMNAS: ACTIVOS IZQ / PASIVOS Y GASTOS DER) -->
    <div class="row">
        <!-- COLUMNA IZQUIERDA: ACTIVOS -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm h-100 border-success">
                <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">DEBE / ACTIVOS (Bienes e Inversiones)</h6>
                    <span class="badge bg-light text-success fw-bold">$<?= number_format($total_activos, 2, ',', '.') ?></span>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 40px;" title="Causado / Activo">Check</th>
                                <th>Fecha</th>
                                <th>Tipología / Ámbito</th>
                                <th>Monto</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($activos) > 0): ?>
                                <?php foreach ($activos as $row): 
                                    $is_checked = (!isset($row['causado']) || $row['causado'] == 1) ? 'checked' : '';
                                ?>
                                    <tr class="<?= ($is_checked == '') ? 'opacity-50 text-decoration-line-through' : '' ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input check-causado" data-id="<?= $row['id'] ?>" <?= $is_checked ?> onchange="cambiarCausado(this)">
                                        </td>
                                        <td><?= $row['fecha'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['tipologia']) ?></strong><br>
                                            <span class="badge bg-primary" style="font-size: 0.65rem;"><?= $row['ambito'] ?? 'Empresa' ?></span>
                                            <span class="badge bg-success" style="font-size: 0.65rem;">Activo</span>
                                        </td>
                                        <td class="fw-bold text-success">$<?= number_format($row['monto'], 2, ',', '.') ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-warning py-0 px-1" onclick='prepararEdicion(<?= json_encode($row) ?>)' data-bs-toggle="modal" data-bs-target="#modalMovimiento">Editar</button>
                                            <form action="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $fecha_seleccionada ?>" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar registro?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_egreso" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger py-0 px-1">X</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No hay activos registrados en esta fecha.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- COLUMNA DERECHA: PASIVOS Y GASTOS -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm h-100 border-dark">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">HABER / PASIVOS Y GASTOS</h6>
                    <span class="badge bg-light text-dark fw-bold">$<?= number_format($total_haber_activo, 2, ',', '.') ?></span>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 40px;" title="Causado / Activo">Check</th>
                                <th>Fecha</th>
                                <th>Tipología / Tipo</th>
                                <th>Monto</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($pasivos_gastos) > 0): ?>
                                <?php foreach ($pasivos_gastos as $row): 
                                    $badge_tipo = 'bg-secondary';
                                    if ($row['tipo'] === 'Pasivo') $badge_tipo = 'bg-dark text-white';
                                    elseif ($row['tipo'] === 'Fijo') $badge_tipo = 'bg-info text-dark';
                                    elseif ($row['tipo'] === 'Variable') $badge_tipo = 'bg-warning text-dark';
                                    
                                    $is_checked = (!isset($row['causado']) || $row['causado'] == 1) ? 'checked' : '';
                                ?>
                                    <tr class="<?= ($is_checked == '') ? 'opacity-50 text-decoration-line-through' : '' ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input check-causado" data-id="<?= $row['id'] ?>" <?= $is_checked ?> onchange="cambiarCausado(this)">
                                        </td>
                                        <td><?= $row['fecha'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['tipologia']) ?></strong><br>
                                            <span class="badge bg-primary" style="font-size: 0.65rem;"><?= $row['ambito'] ?? 'Empresa' ?></span>
                                            <span class="badge <?= $badge_tipo ?>" style="font-size: 0.65rem;"><?= $row['tipo'] ?></span>
                                        </td>
                                        <td class="fw-bold text-danger">$<?= number_format($row['monto'], 2, ',', '.') ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-warning py-0 px-1" onclick='prepararEdicion(<?= json_encode($row) ?>)' data-bs-toggle="modal" data-bs-target="#modalMovimiento">Editar</button>
                                            <form action="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $fecha_seleccionada ?>" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar registro?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_egreso" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger py-0 px-1">X</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No hay pasivos o gastos registrados en esta fecha.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PARA ELEGIR FECHA DE ORIGEN Y COPIAR -->
<div class="modal fade" id="modalCopiar" tabindex="-1" aria-labelledby="modalCopiarLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $fecha_seleccionada ?>" method="POST">
                <div class="modal-header bg-warning text-dark py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="modalCopiarLabel">📋 Copiar Todos los Movimientos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="copiar_desde_fecha">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size: 0.85rem;">
                            Se copiarán **todos** los registros (Activos, Pasivos, Fijos y Variables) hacia el día destino: <strong><?= $fecha_seleccionada ?></strong>. Si ya existe un registro con la misma tipología en la fecha destino, se omitirá automáticamente para evitar duplicados.
                        </label>
                    </div>

                    <div class="mb-3">
                        <label for="fecha_origen" class="form-label fw-bold">Seleccione la Fecha de Origen:</label>
                        <input type="date" class="form-control" id="fecha_origen" name="fecha_origen" value="<?= htmlspecialchars($fecha_sugerida_origen) ?>" required>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold">Ejecutar Copia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL PARA CREAR / EDITAR MOVIMIENTO -->
<div class="modal fade" id="modalMovimiento" tabindex="-1" aria-labelledby="modalMovimientoLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="form-gasto" action="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $fecha_seleccionada ?>" method="POST">
                <div class="modal-header bg-primary text-white py-2">
                    <h5 class="modal-title fs-6" id="modal-title">Registrar Nuevo Movimiento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" id="accion" value="guardar">
                    <input type="hidden" name="id_egreso" id="id_egreso" value="">

                    <div class="mb-3">
                        <label for="tipologia_id" class="form-label fw-bold">Tipología (Ámbito y Tipo)</label>
                        <select class="form-select" id="tipologia_id" name="tipologia_id" required>
                            <option value="">Seleccione...</option>
                            <?php foreach ($tipologias as $tip): ?>
                                <option value="<?= $tip['id'] ?>">
                                    [<?= $tip['ambito'] ?? 'Empresa' ?>] <?= htmlspecialchars($tip['nombre']) ?> (<?= $tip['tipo'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="monto" class="form-label fw-bold">Monto ($)</label>
                        <input type="number" step="0.01" class="form-control" id="monto" name="monto" required>
                    </div>

                    <div class="mb-3">
                        <label for="fecha" class="form-label fw-bold">Fecha</label>
                        <input type="date" class="form-control" id="fecha" name="fecha" value="<?= htmlspecialchars($fecha_seleccionada) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="observacion" class="form-label fw-bold">Observación / Detalle</label>
                        <textarea class="form-control" id="observacion" name="observacion" rows="2" placeholder="Detalle opcional..."></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success btn-sm fw-bold" id="btn-submit">Guardar Registro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function cambiarCausado(checkbox) {
    const id = checkbox.getAttribute('data-id');
    const causado = checkbox.checked ? 1 : 0;

    fetch('<?= htmlspecialchars($pagina_actual) ?>?action=actualizar_causado', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ id: id, causado: causado })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert('Error al actualizar el estado.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function prepararEdicion(gasto) {
    document.getElementById('accion').value = 'actualizar';
    document.getElementById('id_egreso').value = gasto.id;
    document.getElementById('tipologia_id').value = gasto.tipologia_id;
    document.getElementById('monto').value = gasto.monto;
    document.getElementById('fecha').value = gasto.fecha;
    document.getElementById('observacion').value = gasto.observacion || '';

    document.getElementById('modal-title').innerText = 'Editar Registro ID: ' + gasto.id;
    document.getElementById('btn-submit').innerText = 'Actualizar Registro';
}

function resetFormulario() {
    document.getElementById('form-gasto').reset();
    document.getElementById('accion').value = 'guardar';
    document.getElementById('id_egreso').value = '';
    document.getElementById('fecha').value = '<?= htmlspecialchars($fecha_seleccionada) ?>';

    document.getElementById('modal-title').innerText = 'Registrar Nuevo Movimiento';
    document.getElementById('btn-submit').innerText = 'Guardar Registro';
}
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>