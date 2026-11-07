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
$mes_seleccionado = substr($fecha_seleccionada, 0, 7); // Extraer 'YYYY-MM'

// 1. Procesar Acciones POST (Guardar, Actualizar, Eliminar, Copiar, Limpiar Día)
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
    } elseif ($accion === 'limpiar_dia') {
        $stmt_del = $mysqliWeb->prepare("DELETE FROM RegistroGastos WHERE fecha = ?");
        $stmt_del->bind_param("s", $fecha_seleccionada);
        if ($stmt_del->execute()) {
            $eliminados = $stmt_del->affected_rows;
            $mensaje = "Se han eliminado $eliminados registros correspondientes al día $fecha_seleccionada.";
        } else {
            $error = "Error al limpiar los registros del día.";
        }
        $stmt_del->close();
    } elseif ($accion === 'copiar_desde_fecha') {
        $fecha_origen = $_POST['fecha_origen'] ?? '';

        if (!empty($fecha_origen)) {
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
$total_gastos_fijos = 0;
$total_gastos_variables = 0;
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
                } elseif ($row['tipo'] === 'Fijo') {
                    $total_gastos_fijos += $monto_val;
                    $total_gastos += $monto_val;
                } elseif ($row['tipo'] === 'Variable') {
                    $total_gastos_variables += $monto_val;
                    $total_gastos += $monto_val;
                } else {
                    $total_gastos += $monto_val;
                }
            }
        }
    }
}
$stmt_regs->close();

$total_haber_activo = $total_pasivos + $total_gastos;

// 5. Obtener el RESUMEN Y DESGLOSE DÍA A DÍA AGRUPADO POR MES
$sql_desglosado = "
    SELECT 
        r.fecha,
        SUM(CASE WHEN t.tipo = 'Activo' AND (r.causado = 1 OR r.causado IS NULL) THEN r.monto ELSE 0 END) AS act_dia,
        SUM(CASE WHEN t.tipo <> 'Activo' AND (r.causado = 1 OR r.causado IS NULL) THEN r.monto ELSE 0 END) AS pas_dia
    FROM RegistroGastos r
    INNER JOIN TipologiaGastos t ON r.tipologia_id = t.id
    GROUP BY r.fecha
    ORDER BY r.fecha ASC
";
$res_desglosado = $mysqliWeb->query($sql_desglosado);
$desglose_por_mes = [];

$pasivo_acumulado = 0;
$activo_dia_anterior = null;
$mes_actual_control = null;

// Arrays exclusivos para la gráfica del mes actual
$fechas_grafica = [];
$activos_grafica = [];

if ($res_desglosado) {
    while ($row_d = $res_desglosado->fetch_assoc()) {
        $act_actual = floatval($row_d['act_dia']);
        $pas_actual = floatval($row_d['pas_dia']);
        
        $row_d['suma_activo_pasivo'] = $act_actual + $pas_actual;

        // Extraer el mes del registro actual (YYYY-MM)
        $mes_registro = substr($row_d['fecha'], 0, 7);

        // Si cambió el mes, reiniciamos los acumuladores/comparadores al día 1 del mes
        if ($mes_actual_control !== $mes_registro) {
            $mes_actual_control = $mes_registro;
            $pasivo_acumulado = 0;
            $activo_dia_anterior = null; 
        }

        // 1. Variación de Activo Vs Día Anterior (dentro del mismo mes)
        if ($activo_dia_anterior === null) {
            $row_d['variacion_activo'] = 0;
        } else {
            $row_d['variacion_activo'] = $act_actual - $activo_dia_anterior;
        }
        $activo_dia_anterior = $act_actual;

        // 2. Pasivo Acumulado Día a Día (reiniciándose desde el 1 de cada mes)
        $pasivo_acumulado += $pas_actual;
        $row_d['pasivo_acumulado'] = $pasivo_acumulado;

        // Alimentar la gráfica SOLO con los días correspondientes al mes de la fecha seleccionada
        if ($mes_registro === $mes_seleccionado) {
            $fechas_grafica[] = $row_d['fecha'];
            $activos_grafica[] = $act_actual;
        }

        // Agrupar en matriz por mes para el acordeón
        $desglose_por_mes[$mes_registro][] = $row_d;
    }
}

$fecha_sugerida_origen = date('Y-m-d', strtotime($fecha_seleccionada . ' -1 day'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuenta T - Activos, Pasivos y Neto (P&G)</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">

<!-- CONTENEDOR 100% FLUIDO -->
<div class="container-fluid px-4 my-4">
    <!-- Cabecera con Título, Filtro de Fecha y Botones -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <h2 class="mb-0">Balance y Movimientos Día a Día (Cuenta T)</h2>
            
            <!-- FILTRADOR DE FECHA INTEGRADO AL LADO DEL TÍTULO -->
            <form method="GET" action="<?= htmlspecialchars($pagina_actual) ?>" class="d-flex align-items-center gap-2 bg-white p-2 rounded shadow-sm border">
                <label for="fecha" class="fw-bold col-form-label col-form-label-sm">Fecha:</label>
                <input type="date" name="fecha" id="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($fecha_seleccionada) ?>" required>
                <button type="submit" class="btn btn-secondary btn-sm text-nowrap">Filtrar</button>
            </form>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <form action="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $fecha_seleccionada ?>" method="POST" class="d-inline" onsubmit="return confirm('¿Estás seguro de eliminar TODOS los registros de la fecha <?= $fecha_seleccionada ?>? Esta acción no se puede deshacer.');">
                <input type="hidden" name="accion" value="limpiar_dia">
                <button type="submit" class="btn btn-outline-danger btn-sm fw-bold">
                    🗑️ Limpiar Día
                </button>
            </form>
            <button type="button" class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalCopiar">
                📋 Copiar desde Fecha...
            </button>
            <button type="button" class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalMovimiento" onclick="resetFormulario()">
                + Nuevo Movimiento
            </button>
            <a href="TipologiasGastos.php" class="btn btn-outline-secondary btn-sm">&larr; Tipologías</a>
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

    <!-- 1. ESTRUCTURA TIPO CUENTA T (2 COLUMNAS: DEBE Y HABER) -->
    <div class="row g-3 mb-4">
        <!-- COLUMNA IZQUIERDA: ACTIVOS -->
        <div class="col-xxl-6 col-xl-6 col-lg-6 col-12 mb-4 mb-lg-0">
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
        <div class="col-xxl-6 col-xl-6 col-lg-6 col-12">
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

    <!-- 2. SECCIÓN: GRÁFICA DE EVOLUCIÓN DEL TOTAL DE ACTIVOS (MES ACTUAL) - UBICADA ABAJO -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white py-2">
            <h6 class="mb-0">📊 Gráfica de Evolución: Total Activos (Mes: <?= htmlspecialchars($mes_seleccionado) ?>)</h6>
        </div>
        <div class="card-body bg-white p-3">
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="graficoActivos"></canvas>
            </div>
        </div>
    </div>

    <!-- 3. SECCIÓN: DESGLOSE DÍA A DÍA EN FORMATO ACORDEÓN POR MES - UBICADO ABAJO -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white py-2">
            <h6 class="mb-0">📈 Desglose Día a Día por Mes (Acordeón)</h6>
        </div>
        <div class="card-body p-3">
            <?php if (count($desglose_por_mes) > 0): ?>
                <div class="accordion" id="accordionMeses">
                    <?php 
                    $i = 0;
                    foreach ($desglose_por_mes as $mes => $registros_mes): 
                        $i++;
                        $is_expanded = ($mes === $mes_seleccionado) ? 'true' : 'false';
                        $is_show = ($mes === $mes_seleccionado) ? 'show' : '';
                        $collapsed_class = ($mes === $mes_seleccionado) ? '' : 'collapsed';
                    ?>
                        <div class="accordion-item mb-2 border">
                            <h2 class="accordion-header" id="heading<?= $i ?>">
                                <button class="accordion-button <?= $collapsed_class ?> bg-light fw-bold py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $i ?>" aria-expanded="<?= $is_expanded ?>" aria-controls="collapse<?= $i ?>">
                                    📁 Mes: <?= $mes ?> &nbsp;<span class="badge bg-secondary ms-2"><?= count($registros_mes) ?> días registrados</span>
                                </button>
                            </h2>
                            <div id="collapse<?= $i ?>" class="accordion-collapse collapse <?= $is_show ?>" aria-labelledby="heading<?= $i ?>" data-bs-parent="#accordionMeses">
                                <div class="accordion-body p-0 table-responsive">
                                    <table class="table table-striped table-hover align-middle mb-0" style="font-size: 0.9rem;">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Fecha (Día a Día)</th>
                                                <th>Total Activos (Día)</th>
                                                <th>Var. Activo (Vs Día Ant.)</th>
                                                <th>Total Pasivos/Gastos (Día)</th>
                                                <th>Pasivo Acumulado (Desde el 1ro)</th>
                                                <th>Suma Activo + Pasivo (Día)</th>
                                                <th class="text-center">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($registros_mes as $dd): ?>
                                                <tr>
                                                    <td class="fw-bold"><?= $dd['fecha'] ?></td>
                                                    <td class="text-success">$<?= number_format($dd['act_dia'], 2, ',', '.') ?></td>
                                                    
                                                    <!-- Variación Activo Vs Día Anterior -->
                                                    <td>
                                                        <?php 
                                                            $var_act = $dd['variacion_activo'];
                                                            if ($var_act > 0) {
                                                                echo '<span class="text-success fw-bold">+$' . number_format($var_act, 2, ',', '.') . '</span>';
                                                            } elseif ($var_act < 0) {
                                                                echo '<span class="text-danger fw-bold">-$' . number_format(abs($var_act), 2, ',', '.') . '</span>';
                                                            } else {
                                                                echo '<span class="text-muted">$0,00</span>';
                                                            }
                                                        ?>
                                                    </td>

                                                    <td class="text-danger">$<?= number_format($dd['pas_dia'], 2, ',', '.') ?></td>
                                                    
                                                    <!-- Pasivo Acumulado desde el 1ro del mes -->
                                                    <td class="fw-bold text-dark">$<?= number_format($dd['pasivo_acumulado'], 2, ',', '.') ?></td>

                                                    <!-- Suma Activo Diario + Pasivo Diario -->
                                                    <td class="fw-bold text-secondary">$<?= number_format($dd['suma_activo_pasivo'], 2, ',', '.') ?></td>

                                                    <td class="text-center">
                                                        <a href="<?= htmlspecialchars($pagina_actual) ?>?fecha=<?= $dd['fecha'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.8rem;">Ver Día</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-3">No hay registros diarios para mostrar.</div>
            <?php endif; ?>
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
// Inicializar Gráfica de Activos (Filtrada para el mes actual)
const ctx = document.getElementById('graficoActivos').getContext('2d');
const graficoActivos = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($fechas_grafica) ?>,
        datasets: [{
            label: 'Total Activos del Mes ($)',
            data: <?= json_encode($activos_grafica) ?>,
            borderColor: 'rgba(25, 135, 84, 1)',
            backgroundColor: 'rgba(25, 135, 84, 0.1)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.2,
            pointRadius: 4,
            pointBackgroundColor: 'rgba(25, 135, 84, 1)'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let value = context.parsed.y;
                        return ' Total Activos: $' + value.toLocaleString('es-CO', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString('es-CO', {notation: 'compact'});
                    }
                }
            }
        }
    }
});

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