<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión si no se ha iniciado antes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Establecer la zona horaria de Bogotá para PHP
date_default_timezone_set('America/Bogota');

// 1. Incluir e implementar el archivo de conexión existente
require_once __DIR__ . '/Conexion.php';

if (isset($conn_error)) {
    die("<div class='alert alert-danger text-center m-3'>" . htmlspecialchars($conn_error) . "</div>");
}

if (!isset($mysqliWeb)) {
    die("<div class='alert alert-danger text-center m-3'>❌ Error: La variable de conexión \$mysqliWeb no está definida.</div>");
}

$mysqliWeb->query("SET time_zone = '-05:00'");

// --- DATOS DE SESIÓN ACTUAL ---
$usuario_actual = isset($_SESSION['Usuario']) ? trim($_SESSION['Usuario']) : '';
$nit_empresa    = isset($_SESSION['NitEmpresa']) ? trim($_SESSION['NitEmpresa']) : 'No asignado';
$nro_sucursal   = isset($_SESSION['NroSucursal']) ? trim($_SESSION['NroSucursal']) : 'No asignada';

// --- PROCESAR ACCIÓN AJAX (GUARDAR / ELIMINAR CHECK) ---
if (isset($_POST['action']) && $_POST['action'] === 'toggle_check') {
    header('Content-Type: application/json');
    
    if (empty($usuario_actual)) {
        echo json_encode(['status' => 'error', 'message' => 'Sesión inválida o expirada.']);
        exit;
    }

    $id_trans = filter_input(INPUT_POST, 'id_transferencia', FILTER_VALIDATE_INT);
    $estado   = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT); // 1 = marcar, 0 = desmarcar

    if (!$id_trans) {
        echo json_encode(['status' => 'error', 'message' => 'ID de transferencia no válido.']);
        exit;
    }

    if ($estado === 1) {
        // Intentar adueñarse de la transferencia (INSERT)
        $fecha_actual = date('Y-m-d H:i:s');
        $stmt_ins = $mysqliWeb->prepare("INSERT INTO control_checks_nequi (id_transferencia, nit_empresa, nro_sucursal, usuario_cedula, fecha_hora_check) VALUES (?, ?, ?, ?, ?)");
        
        if ($stmt_ins) {
            $stmt_ins->bind_param("issss", $id_trans, $nit_empresa, $nro_sucursal, $usuario_actual, $fecha_actual);
            if ($stmt_ins->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Transferencia marcada correctamente.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Esta transferencia ya fue reclamada por otro usuario.']);
            }
            $stmt_ins->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error en la preparación de la consulta.']);
        }
    } else {
        // Intentar desmarcar (DELETE) - Solo si el registro le pertenece al usuario actual
        $stmt_del = $mysqliWeb->prepare("DELETE FROM control_checks_nequi WHERE id_transferencia = ? AND usuario_cedula = ?");
        if ($stmt_del) {
            $stmt_del->bind_param("is", $id_trans, $usuario_actual);
            $stmt_del->execute();
            
            if ($stmt_del->affected_rows > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Transferencia liberada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No puedes desmarcar una transferencia que pertenece a otro usuario.']);
            }
            $stmt_del->close();
        }
    }
    exit; 
}

// --- FUNCIÓN DE AUTORIZACIÓN ---
function Autorizacion($User, $Solicitud) {
    global $mysqliWeb; 
    if (empty($User) || trim($User) === '' || $User === '01') { return 'NO'; }
    
    $stmt = $mysqliWeb->prepare("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit=? AND Nro_Auto=?");
    if ($stmt) {
        $stmt->bind_param("ss", $User, $Solicitud);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return ($row && isset($row['Swich'])) ? strtoupper(trim($row['Swich'])) : 'NO';
    }
    return 'NO';
}

$esAdminStock = ($usuario_actual !== '01' && $usuario_actual !== '') ? (Autorizacion($usuario_actual, '9999') === 'SI') : false;

// Consultar nombre de usuario
$nombre_usuario_sesion = "Invitado";
if (!empty($usuario_actual)) {
    $stmt_user = $mysqliWeb->prepare("SELECT Nombre FROM terceros WHERE CedulaNit = ? AND Estado = 1 LIMIT 1");
    if ($stmt_user) {
        $stmt_user->bind_param("s", $usuario_actual);
        $stmt_user->execute();
        $stmt_user->bind_result($nombre_obtenido);
        if ($stmt_user->fetch()) { $nombre_usuario_sesion = $nombre_obtenido; }
        $stmt_user->close();
    }
}

// 2. Ejecutar el script nativo de Python para leer Gmail
$command = 'python3 ' . __DIR__ . '/minequi.py 2>&1';
$output = shell_exec($command);
$nuevos_correos = json_decode($output, true);

$error_python = null;
if ($output === null) {
    $error_python = "No se pudo ejecutar el script de Python.";
} elseif ($nuevos_correos === null && !empty(trim($output))) {
    $error_python = "Error al decodificar JSON.";
}

// 3. Procesar e insertar registros nuevos si no hay errores
if (empty($error_python) && !empty($nuevos_correos) && is_array($nuevos_correos)) {
    $stmt_check = $mysqliWeb->prepare("SELECT id FROM notificaciones_nequi WHERE id_unico = ?");
    $stmt_insert = $mysqliWeb->prepare("INSERT IGNORE INTO notificaciones_nequi 
        (id_unico, uid_correo, monto, celular_origen, pagador, banco_origen, referencia, numero_transaccion_largo, asunto, estado_sesion, fecha_correo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Recibido', ?)");

    $ids_procesados_en_lote = [];
    foreach ($nuevos_correos as $correo) {
        if (!isset($correo['id_unico'])) continue;
        $id_unico = $correo['id_unico'];
        if (in_array($id_unico, $ids_procesados_en_lote)) { continue; }

        $stmt_check->bind_param("s", $id_unico);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 0) {
            $fecha_bogota = !empty($correo['fecha_correo']) ? date('Y-m-d H:i:s', strtotime($correo['fecha_correo'])) : date('Y-m-d H:i:s');
            $uid          = $correo['uid_correo'] ?? '0';
            $monto        = isset($correo['monto']) ? (float)$correo['monto'] : 0.00;
            $celular      = $correo['celular'] ?? 'No detectado';
            $pagador      = $correo['pagador'] ?? 'No detectado';
            $banco        = $correo['banco_origen'] ?? 'Nequi';
            $referencia   = $correo['referencia'] ?? 'No detectado';
            $num_largo    = $correo['numero_transaccion_largo'] ?? 'No detectado';
            $asunto       = $correo['asunto'] ?? 'Sin Asunto';

            $stmt_insert->bind_param("ssdsssssss", $id_unico, $uid, $monto, $celular, $pagador, $banco, $referencia, $num_largo, $asunto, $fecha_bogota);
            $stmt_insert->execute();
            $ids_procesados_en_lote[] = $id_unico;
        }
    }
    $stmt_check->close();
    $stmt_insert->close();
}

$hoy = date('Y-m-d');

// --- CONDICIÓN DE FILTRADO PARA EL RESUMEN ACUMULADO ---
$filtro_resumen = "";
if (!$esAdminStock) {
    // Si no es admin, filtramos estrictamente por su cédula Y el NIT de la sesión actual
    $user_escapado = $mysqliWeb->real_escape_string($usuario_actual);
    $nit_escapado  = $mysqliWeb->real_escape_string($nit_empresa);
    $filtro_resumen = " AND c.usuario_cedula = '$user_escapado' AND c.nit_empresa = '$nit_escapado' ";
}

// --- CONSULTA TOTALES (FILTRADA SEGÚN EL ROL Y EL NIT) ---
$sql_totales = "SELECT c.nit_empresa, c.nro_sucursal, c.usuario_cedula, t.Nombre AS nombre_usuario, SUM(n.monto) AS total_monto, COUNT(n.id) AS total_cantidad
                FROM control_checks_nequi c
                INNER JOIN notificaciones_nequi n ON c.id_transferencia = n.id
                LEFT JOIN terceros t ON (c.usuario_cedula COLLATE utf8mb4_general_ci) = (t.CedulaNit COLLATE utf8mb4_general_ci)
                WHERE DATE(n.fecha_correo) = '$hoy' $filtro_resumen
                GROUP BY c.nit_empresa, c.nro_sucursal, c.usuario_cedula, t.Nombre
                ORDER BY c.nit_empresa ASC, c.nro_sucursal ASC, total_monto DESC";
$res_totales = $mysqliWeb->query($sql_totales);
$totales_por_sede = [];
if ($res_totales && $res_totales->num_rows > 0) {
    while ($r_tot = $res_totales->fetch_assoc()) {
        $totales_por_sede[] = $r_tot;
    }
}

// 4. CONSULTA GENERAL DE TRANSFERENCIAS DEL DÍA (Sigue visible para todos)
$sql_select = "SELECT n.id, n.celular_origen, n.pagador, n.banco_origen, n.referencia, n.numero_transaccion_largo, n.monto, n.fecha_correo,
                       c.usuario_cedula, c.nit_empresa, c.nro_sucursal, t.Nombre AS nombre_dueno
               FROM notificaciones_nequi n
               LEFT JOIN control_checks_nequi c ON n.id = c.id_transferencia
               LEFT JOIN terceros t ON (c.usuario_cedula COLLATE utf8mb4_general_ci) = (t.CedulaNit COLLATE utf8mb4_general_ci)
               WHERE DATE(n.fecha_correo) = '$hoy' 
               ORDER BY n.fecha_correo DESC";

$resultado = $mysqliWeb->query($sql_select);

$monto_total = 0;
$total_transferencias = 0;
$promedio_transferencias = 0;
$filas = [];
$transacciones_procesadas = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $id_largo = $row['numero_transaccion_largo'];
        if ($id_largo !== 'No detectado' && in_array($id_largo, $transacciones_procesadas)) { continue; }
        if ($id_largo !== 'No detectado') { $transacciones_procesadas[] = $id_largo; }

        $monto_total += (float)$row['monto'];
        $filas[] = $row;
    }
    $total_transferencias = count($filas);
    if ($total_transferencias > 0) { $promedio_transferencias = $monto_total / $total_transferencias; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferencias Bre-B</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .text-break-custom { word-break: break-all; white-space: normal; }
        .pagador-texto { max-width: 100%; white-space: normal; word-wrap: break-word; display: inline-block; }
        .form-check-input { width: 1.5em; height: 1.5em; cursor: pointer; }
        .form-check-input:disabled { opacity: 0.6; cursor: not-allowed; }
        
        @media (max-width: 768px) {
            body { padding: 4px !important; }
            .container-main { padding: 8px !important; border-radius: 6px !important; }
            
            .table-responsive-desktop {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table th, .table td { padding: 6px 4px !important; font-size: 0.78rem !important; }
            .fs-mobile-amount { font-size: 1.05rem !important; }
            .badge-mobile { font-size: 0.65rem !important; padding: 3px 5px !important; }
            
            .badge-green-flexible {
                white-space: normal !important;
                word-break: break-word;
                text-align: left;
            }
        }
    </style>
</head>
<body class="bg-light p-2 p-md-4">

<div class="container-fluid container-xl mb-3">
    <div class="bg-dark text-white p-2 px-3 rounded shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2" style="font-size: 0.85rem;">
        <div class="text-truncate">
            👤 <strong>Usuario:</strong> <?php echo htmlspecialchars($nombre_usuario_sesion); ?> 
            <span class="text-secondary mx-1">|</span> 
            🆔 <strong>CC/NIT:</strong> <?php echo htmlspecialchars($usuario_actual ?: 'No asignado'); ?>
        </div>
        <div class="text-truncate">
            🏢 <strong>Empresa (NIT):</strong> <?php echo htmlspecialchars($nit_empresa); ?> 
            <span class="text-secondary mx-1">|</span> 
            📍 <strong>Sucursal:</strong> <?php echo htmlspecialchars($nro_sucursal); ?>
        </div>
    </div>
</div>

<div class="container-fluid container-xl bg-white p-2 p-md-4 rounded shadow-sm container-main">
    
    <div class="row align-items-center justify-content-between g-2 mb-3">        
        <div class="col-12 col-md-auto">
            <button onclick="forzarRefresco();" class="btn btn-success btn-sm w-100 w-md-auto text-nowrap px-3 py-2 py-md-1">🔄 Sincronizar Banco</button>
        </div>
        <div class="col-12 col-md-auto text-center text-md-end">
            <small class="text-muted fw-medium text-nowrap" style="font-size: 0.85rem;">
                ⏱️ Próxima actualización: <span id="timer" class="text-danger fw-bold">03:00</span>
            </small>
        </div>
    </div>

    <?php if ($error_python): ?>
        <div class="alert alert-warning alert-dismissible fade show small py-2 px-3 mb-3" role="alert" style="font-size:0.8rem;">
            <strong>Atención:</strong> <?php echo htmlspecialchars($error_python); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm border-0">
        <div class="card-header bg-secondary text-white p-2 px-3 fw-bold small">
            📊 Resumen Acumulado <?php echo $esAdminStock ? "por Sede y Usuario" : "de tu Usuario"; ?> (Checks de Hoy)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive-desktop">
                <table class="table table-sm table-striped table-hover mb-0 align-middle" style="font-size: 0.85rem;">
                    <thead class="table-light text-secondary text-nowrap">
                        <tr>
                            <th class="ps-3">🏢 NIT</th>
                            <th>📍 Sede</th>
                            <th>🆔 Usuario</th>
                            <th>👤 Nombre</th>
                            <th class="text-center">📦 Cant.</th>
                            <th class="text-end pe-3">💰 Total </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($totales_por_sede)): ?>
                            <?php foreach ($totales_por_sede as $item): ?>
                                <tr>
                                    <td class="ps-3 font-monospace fw-medium text-secondary"><?php echo htmlspecialchars($item['nit_empresa']); ?></td>
                                    <td><span class="badge bg-light text-dark border badge-mobile"><?php echo htmlspecialchars($item['nro_sucursal']); ?></span></td>
                                    <td class="font-monospace text-muted"><?php echo htmlspecialchars($item['usuario_cedula']); ?></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($item['nombre_usuario'] ?? 'Sin Nombre'); ?></td>
                                    <td class="text-center font-monospace"><span class="badge bg-dark badge-mobile"><?php echo $item['total_cantidad']; ?></span></td>
                                    <td class="text-end pe-3 fw-bold text-primary fs-6">$<?php echo number_format($item['total_monto'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">
                                    No tienes asignaciones registradas el día de hoy para esta empresa.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($esAdminStock === true): ?>
        <div class="card bg-dark text-white shadow-sm mb-3">
            <div class="card-body p-2 p-md-3">
                <div class="row g-1 text-center align-items-center">
                    <div class="col-4 border-end border-secondary">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Recibido Hoy</h6>
                        <span class="fw-bold fs-5 fs-md-4">$<?php echo number_format($monto_total, 0, ',', '.'); ?></span>
                    </div>
                    <div class="col-4 border-end border-secondary">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Transferencias</h6>
                        <span class="fw-bold fs-5 fs-md-4"><?php echo $total_transferencias; ?></span>
                    </div>
                    <div class="col-4">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Promedio</h6>
                        <span class="fw-bold fs-5 fs-md-4">$<?php echo number_format($promedio_transferencias, 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="table-responsive-desktop border rounded bg-white">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark text-nowrap">
                <tr>
                    <th class="text-center" style="width: 50px;">ID</th>
                    <th class="text-center" style="width: 50px;">Asignar</th>
                    <th>Fecha / Hora</th>
                    <th>Remitente</th>
                    <th>Monto</th>
                    <th>Viene de / Ref</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($filas)): ?>
                    <?php foreach ($filas as $row): 
                        $tiene_dueno = !empty($row['usuario_cedula']);
                        $soy_el_dueno = ($tiene_dueno && $row['usuario_cedula'] === $usuario_actual);
                        
                        $checked_attr = $tiene_dueno ? 'checked' : '';
                        $disabled_attr = ($tiene_dueno && !$soy_el_dueno) ? 'disabled' : '';
                        if (empty($usuario_actual)) { $disabled_attr = 'disabled'; } 
                    ?>
                        <tr>
                            <td class="text-center font-monospace small text-muted">#<?php echo $row['id']; ?></td>
                            <td class="text-center">
                                <input type="checkbox" 
                                       class="form-check-input check-transferencia" 
                                       data-id="<?php echo $row['id']; ?>" 
                                       <?php echo $checked_attr; ?> 
                                       <?php echo $disabled_attr; ?>>
                            </td>
                            <td class="text-nowrap">
                                <strong class="d-block"><?php echo date("d/m", strtotime($row['fecha_correo'])); ?></strong>
                                <span class="text-muted small" style="font-size:0.75rem;"><?php echo date("h:i A", strtotime($row['fecha_correo'])); ?></span>
                            </td>
                            <td class="celda-remitente">
                                <?php if ($row['celular_origen'] !== 'No detectado'): ?>
                                    <span class="badge bg-secondary mb-1 d-inline-block badge-mobile"><?php echo htmlspecialchars($row['celular_origen']); ?></span><br>
                                <?php endif; ?>
                                
                                <?php if ($row['pagador'] !== 'No detectado'): ?>
                                    <small class="text-dark fw-semibold pagador-texto"><?php echo htmlspecialchars($row['pagador']); ?></small><br>
                                <?php endif; ?>

                                <?php if ($row['celular_origen'] === 'No detectado' && $row['pagador'] === 'No detectado'): ?>
                                    <span class="badge bg-danger badge-mobile">No detectado</span><br>
                                <?php endif; ?>

                                <?php if ($tiene_dueno): ?>
                                    <div class="mt-1">
                                        <span class="badge bg-success d-inline-flex align-items-center flex-wrap gap-1 badge-green-flexible" style="font-size: 0.68rem; padding: 4px 6px;">
                                            <span>📌 Por: <?php echo htmlspecialchars($row['nombre_dueno'] ?? $row['usuario_cedula']); ?></span>
                                            <?php if(!empty($row['nit_empresa'])): ?>
                                                <span class="opacity-90 font-monospace" style="font-size: 0.62rem;">
                                                    - NIT: <?php echo htmlspecialchars($row['nit_empresa']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold fs-5 fs-mobile-amount text-nowrap">$<?php echo number_format($row['monto'], 0, ',', '.'); ?></td>
                            <td class="celda-detalles">
                                <div class="mb-1 d-flex flex-wrap gap-1 align-items-center">
                                    <span class="badge bg-info text-dark text-uppercase badge-mobile" style="font-size:0.65rem;"><?php echo htmlspecialchars($row['banco_origen']); ?></span>
                                    <span class="text-muted font-monospace text-break-custom" style="font-size:0.72rem;">Ref:<?php echo htmlspecialchars($row['referencia']); ?></span>
                                </div>
                                <div class="text-muted text-break-custom font-monospace opacity-75" style="max-width: 220px; font-size: 0.68rem; line-height: 1.1;">
                                    ID: <?php echo htmlspecialchars($row['numero_transaccion_largo']); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4" style="font-size:0.85rem;">
                            No hay transferencias registradas hoy.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function forzarRefresco() {
        const urlActual = new URL(window.location.href);
        urlActual.searchParams.set('v', new Date().getTime());
        window.location.href = urlActual.toString();
    }

    document.querySelectorAll('.check-transferencia').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const idTransferencia = this.getAttribute('data-id');
            const estadoNuevo = this.checked ? 1 : 0;
            const elemento = this;

            elemento.disabled = true;

            const formData = new FormData();
            formData.append('action', 'toggle_check');
            formData.append('id_transferencia', idTransferencia);
            formData.append('estado', estadoNuevo);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    forzarRefresco();
                } else {
                    alert('⚠️ ' + data.message);
                    elemento.checked = !elemento.checked;
                    elemento.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Ocurrió un error en la conexión.');
                elemento.checked = !elemento.checked;
                elemento.disabled = false;
            });
        });
    });

    let tiempoRestante = 180; 
    const contenedorTimer = document.getElementById('timer');

    const cuentaRegresiva = setInterval(function() {
        tiempoRestante--;
        let minutos = Math.floor(tiempoRestante / 60);
        let segundos = tiempoRestante % 60;

        minutos = minutos < 10 ? '0' + minutos : minutos;
        segundos = segundos < 10 ? '0' + segundos : segundos;

        if(contenedorTimer) {
            contenedorTimer.textContent = minutos + ':' + segundos;
        }

        if (tiempoRestante <= 0) {
            clearInterval(cuentaRegresiva);
            forzarRefresco(); 
        }
    }, 1000);
</script>

</body>
</html>