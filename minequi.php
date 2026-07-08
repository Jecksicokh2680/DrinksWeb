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

// Validar si la conexión del archivo externo falló
if (isset($conn_error)) {
    die("<div class='alert alert-danger text-center m-3'>" . htmlspecialchars($conn_error) . "</div>");
}

// Usar la variable global definida en tu Conexion.php
if (!isset($mysqliWeb)) {
    die("<div class='alert alert-danger text-center m-3'>❌ Error: La variable de conexión \$mysqliWeb no está definida.</div>");
}

// Configurar la zona horaria en la sesión de la Base de Datos usando tu objeto global
$mysqliWeb->query("SET time_zone = '-05:00'");

// --- FUNCIÓN DE AUTORIZACIÓN CORREGIDA Y REFORZADA ---
function Autorizacion($User, $Solicitud) {
    global $mysqliWeb; 
    
    // Si el usuario es "01" o está vacío, y la solicitud es 9999, denegar inmediatamente
    if (empty($User) || trim($User) === '' || $User === '01') {
        return 'NO';
    }
    
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

// --- VALIDACIÓN ESTRICTA DE USUARIO ---
$usuario_actual = isset($_SESSION['Usuario']) ? trim($_SESSION['Usuario']) : '';

if ($usuario_actual === '01' || $usuario_actual === '') {
    $esAdminStock = false;
} else {
    $esAdminStock = (Autorizacion($usuario_actual, '9999') === 'SI');
}

// --- PROCESAR ELIMINACIÓN SEGURA SI ES ADMIN CON AUTORIZACIÓN 9999 ---
$mensaje_eliminar = "";
if ($esAdminStock && isset($_POST['action']) && $_POST['action'] === 'eliminar_registro') {
    $id_eliminar = filter_input(INPUT_POST, 'id_transferencia', FILTER_VALIDATE_INT);
    if ($id_eliminar) {
        $stmt_del = $mysqliWeb->prepare("DELETE FROM notificaciones_nequi WHERE id = ?");
        if ($stmt_del) {
            $stmt_del->bind_param("i", $id_eliminar);
            if ($stmt_del->execute()) {
                $mensaje_eliminar = "<div class='alert alert-success py-2 px-3 mb-3' style='font-size:0.85rem;'>✅ Registro eliminado correctamente.</div>";
            } else {
                $mensaje_eliminar = "<div class='alert alert-danger py-2 px-3 mb-3' style='font-size:0.85rem;'>❌ Error al intentar eliminar el registro de la base de datos.</div>";
            }
            $stmt_del->close();
        }
    }
}

// 2. Ejecutar el script nativo de Python para leer Gmail
$command = 'python3 ' . __DIR__ . '/minequi.py 2>&1';
$output = shell_exec($command);

// Decodificar la respuesta de Python
$nuevos_correos = json_decode($output, true);

// Variable para capturar errores de ejecución o parsing
$error_python = null;
if ($output === null) {
    $error_python = "No se pudo ejecutar el script de Python.";
} elseif ($nuevos_correos === null && !empty(trim($output))) {
    $error_python = "Error al decodificar JSON de Python. Salida cruda: " . substr($output, 0, 100);
} elseif (isset($nuevos_correos[0]['error'])) {
    $error_python = $nuevos_correos[0]['error'];
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

        if (in_array($id_unico, $ids_procesados_en_lote)) {
            continue;
        }

        $stmt_check->bind_param("s", $id_unico);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 0) {
            
            if (!empty($correo['fecha_correo'])) {
                $fecha_bogota = date('Y-m-d H:i:s', strtotime($correo['fecha_correo']));
            } else {
                $fecha_bogota = date('Y-m-d H:i:s'); 
            }

            $uid          = $correo['uid_correo'] ?? '0';
            $monto        = isset($correo['monto']) ? (float)$correo['monto'] : 0.00;
            $celular      = $correo['celular'] ?? 'No detectado';
            $pagador      = $correo['pagador'] ?? 'No detectado';
            $banco        = $correo['banco_origen'] ?? 'Nequi';
            $referencia   = $correo['referencia'] ?? 'No detectado';
            $num_largo    = $correo['numero_transaccion_largo'] ?? 'No detectado';
            $asunto       = $correo['asunto'] ?? 'Sin Asunto';

            $stmt_insert->bind_param("ssdsssssss", 
                $id_unico, 
                $uid, 
                $monto, 
                $celular, 
                $pagador, 
                $banco, 
                $referencia, 
                $num_largo, 
                $asunto, 
                $fecha_bogota
            );
            $stmt_insert->execute();

            $ids_procesados_en_lote[] = $id_unico;
        }
    }
    $stmt_check->close();
    $stmt_insert->close();
}

// 4. Consultar SOLO las transferencias del día (Fecha Bogotá)
$hoy = date('Y-m-d');
$sql_select = "SELECT id, celular_origen, pagador, banco_origen, referencia, numero_transaccion_largo, monto, fecha_correo, asunto, estado_sesion 
               FROM notificaciones_nequi 
               WHERE DATE(fecha_correo) = '$hoy' 
               ORDER BY fecha_correo DESC";

$resultado = $mysqliWeb->query($sql_select);

$monto_total = 0;
$total_transferencias = 0;
$promedio_transferencias = 0;
$filas = [];
$transacciones_procesadas = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $id_largo = $row['numero_transaccion_largo'];

        if ($id_largo !== 'No detectado' && in_array($id_largo, $transacciones_procesadas)) {
            continue;
        }

        if ($id_largo !== 'No detectado') {
            $transacciones_processed[] = $id_largo; 
            $transacciones_procesadas[] = $id_largo;
        }

        $monto_total += (float)$row['monto'];
        $filas[] = $row;
    }
    
    $total_transferencias = count($filas);
    if ($total_transferencias > 0) {
        $promedio_transferencias = $monto_total / $total_transferencias;
    }
}

$es_popup = isset($_GET['popup']) && $_GET['popup'] == 1;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Transferencias Nequi & Bre-B</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .text-break-custom { word-break: break-all; white-space: normal; }
        .pagador-texto { max-width: 150px; white-space: normal; word-wrap: break-word; display: inline-block; }
        
        @media (max-width: 768px) {
            body { padding: 4px !important; }
            .container-main { padding: 12px !important; border-radius: 4px !important; }
            .table th, .table td { font-size: 0.8rem !important; padding: 8px 4px !important; }
            .fs-5 { font-size: 0.95rem !important; }
            .fs-4 { font-size: 1.1rem !important; }
            .fs-3 { font-size: 1.25rem !important; }
            .badge { font-size: 0.7rem !important; padding: 4px 6px !important; }
            .celda-remitente { min-width: 110px; }
            .celda-detalles { min-width: 130px; }
        }
    </style>
</head>
<body class="bg-light p-2 p-md-4">

<div class="container-fluid container-xl bg-white p-3 p-md-4 rounded shadow-sm container-main">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3 mb-3">
        <h2 class="text-primary m-0 fs-3 fw-bold text-center text-md-start">📥 Transferencias Bre-B</h2>
        
        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-md-center gap-2">
            <button onclick="forzarRefresco();" class="btn btn-success btn-sm text-nowrap px-3 py-2 py-md-1">🔄 Sincronizar Banco</button>
            <small class="text-muted text-center text-md-end fw-medium align-self-center" style="font-size: 0.75rem;">
                ⏱️ Próxima actualización: <span id="timer" class="text-danger fw-bold">03:00</span>
            </small>
        </div>
    </div>

    <?php 
    // Mostrar retroalimentación de la eliminación si existe
    if (!empty($mensaje_eliminar)) { echo $mensaje_eliminar; } 
    ?>

    <?php if ($error_python): ?>
        <div class="alert alert-warning alert-dismissible fade show small py-2 px-3 mb-3" role="alert" style="font-size:0.8rem;">
            <strong>Atención:</strong> <?php echo htmlspecialchars($error_python); ?>
        </div>
    <?php endif; ?>

    <?php if ($esAdminStock === true): ?>
        <div class="card bg-dark text-white shadow-sm mb-3">
            <div class="card-body p-2 p-md-3">
                <div class="row g-1 text-center align-items-center">
                    <div class="col-4 border-end border-secondary">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Recibido Hoy</h6>
                        <span class="fw-bold fs-4">$<?php echo number_format($monto_total, 0, ',', '.'); ?></span>
                    </div>
                    <div class="col-4 border-end border-secondary">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Transferencias</h6>
                        <span class="fw-bold fs-4"><?php echo $total_transferencias; ?></span>
                    </div>
                    <div class="col-4">
                        <h6 class="text-uppercase opacity-75 mb-1 text-truncate" style="font-size: 0.65rem; letter-spacing: 0.5px;">Promedio</h6>
                        <span class="fw-bold fs-4">$<?php echo number_format($promedio_transferencias, 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="table-responsive border rounded bg-white">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark text-nowrap">
                <tr>
                    <th>Fecha / Hora</th>
                    <th>Remitente</th>
                    <th>Monto</th>
                    <th>Viene de / Ref</th>
                    <th>Estado</th>
                    <?php if ($esAdminStock): ?>
                        <th class="text-center">Acción</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($filas)): ?>
                    <?php foreach ($filas as $row): ?>
                        <tr>
                            <td class="text-nowrap">
                                <strong><?php echo date("d/m", strtotime($row['fecha_correo'])); ?></strong><br>
                                <span class="text-muted small" style="font-size:0.75rem;"><?php echo date("h:i A", strtotime($row['fecha_correo'])); ?></span>
                            </td>
                            <td class="celda-remitente">
                                <?php if ($row['celular_origen'] !== 'No detectado'): ?>
                                    <span class="badge bg-secondary mb-1 d-inline-block"><?php echo htmlspecialchars($row['celular_origen']); ?></span><br>
                                <?php endif; ?>
                                
                                <?php if ($row['pagador'] !== 'No detectado'): ?>
                                    <small class="text-dark fw-semibold pagador-texto"><?php echo htmlspecialchars($row['pagador']); ?></small>
                                <?php endif; ?>

                                <?php if ($row['celular_origen'] === 'No detectado' && $row['pagador'] === 'No detectado'): ?>
                                    <span class="badge bg-danger">No detectado</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold fs-5 text-nowrap">$<?php echo number_format($row['monto'], 0, ',', '.'); ?></td>
                            <td class="celda-detalles">
                                <div class="mb-1 d-flex flex-wrap gap-1 align-items-center">
                                    <span class="badge bg-info text-dark text-uppercase" style="font-size:0.65rem;"><?php echo htmlspecialchars($row['banco_origen']); ?></span>
                                    <span class="text-muted font-monospace text-break-custom" style="font-size:0.72rem;">Ref:<?php echo htmlspecialchars($row['referencia']); ?></span>
                                </div>
                                <div class="text-muted text-break-custom font-monospace opacity-75" style="max-width: 220px; font-size: 0.68rem; line-height: 1.1;">
                                    ID: <?php echo htmlspecialchars($row['numero_transaccion_largo']); ?>
                                </div>
                            </td>
                            <td>
                                <?php if (strcasecmp($row['estado_sesion'], 'pendiente') === 0): ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Recibido</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($esAdminStock): ?>
                                <td class="text-center">
                                    <form method="POST" action="" onsubmit="return confirmarEliminacion();" style="display:inline;">
                                        <input type="hidden" name="action" value="eliminar_registro">
                                        <input type="hidden" name="id_transferencia" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm px-2 py-1" style="font-size: 0.75rem;">
                                            🗑️ Eliminar
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $esAdminStock ? '6' : '5'; ?>" class="text-center text-muted py-4" style="font-size:0.85rem;">
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
        window.location.href = window.location.origin + window.location.pathname + (window.location.search || '');
    }

    function confirmarEliminacion() {
        return confirm("⚠️ ¿Estás seguro de que deseas eliminar permanentemente esta transferencia del sistema?");
    }

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