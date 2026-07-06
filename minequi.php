<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establecer la zona horaria de Bogotá para PHP
date_default_timezone_set('America/Bogota');

// 1. Configuración de la conexión remota a MySQL
$host    = "52.15.192.69";
$usuario = "root";
$pass    = "root";
$db      = "BnmaWeb";
$puerto  = 32768;

$mysqli = new mysqli($host, $usuario, $pass, $db, $puerto);

if ($mysqli->connect_error) {
    die("<div class='alert alert-danger text-center m-3'>La conexión a MySQL falló: " . $mysqli->connect_error . "</div>");
}

// Configurar la zona horaria en la sesión de la Base de Datos
$mysqli->query("SET time_zone = '-05:00'");

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
    
    $stmt_check = $mysqli->prepare("SELECT id FROM notificaciones_nequi WHERE id_unico = ?");
    $stmt_insert = $mysqli->prepare("INSERT INTO notificaciones_nequi 
        (id_unico, uid_correo, monto, celular_origen, pagador, banco_origen, referencia, numero_transaccion_largo, asunto, estado_sesion, fecha_correo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Recibido', ?)");

    foreach ($nuevos_correos as $correo) {
        if (!isset($correo['id_unico'])) continue;

        $stmt_check->bind_param("s", $correo['id_unico']);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 0) {
            
            if (!empty($correo['fecha_correo'])) {
                $fecha_bogota = date('Y-m-d H:i:s', strtotime($correo['fecha_correo']));
            } else {
                $fecha_bogota = date('Y-m-d H:i:s'); 
            }

            $id_unico     = $correo['id_unico'];
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
        }
    }
    $stmt_check->close();
    $stmt_insert->close();
}

// 4. Consultar las últimas 50 transferencias
$sql_select = "SELECT id, celular_origen, pagador, banco_origen, referencia, numero_transaccion_largo, monto, fecha_correo, asunto, estado_sesion FROM notificaciones_nequi ORDER BY fecha_correo DESC LIMIT 50";
$resultado = $mysqli->query($sql_select);

$monto_total = 0;
$filas = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $monto_total += (float)$row['monto'];
        $filas[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Transferencias Nequi & Bre-B</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* Ajustes responsivos personalizados para pantallas móviles */
        @media (max-width: 576px) {
            .container-main {
                padding: 10px !important;
            }
            .table th, .table td {
                font-size: 0.85rem !important;
                padding: 8px 4px !important;
            }
            .fs-5 {
                font-size: 1rem !important;
            }
            .badge {
                font-size: 0.75rem !important;
            }
        }
        /* Forzar que el texto largo no rompa el layout responsivo */
        .text-break-custom {
            word-break: break-all;
            white-space: normal;
        }
    </style>
</head>
<body class="bg-light p-2 p-md-4">

<div class="container-fluid container-xl bg-white p-3 p-md-4 rounded shadow-sm container-main">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-4">
        <h2 class="text-primary m-0 fs-3 fs-md-2">📥 Control de Transferencias Nequi & Bre-B</h2>
        <button onclick="location.reload();" class="btn btn-success w-100 w-sm-auto text-nowrap">🔄 Sincronizar Caja</button>
    </div>

    <?php if ($error_python): ?>
        <div class="alert alert-warning alert-dismissible fade show small" role="alert">
            <strong>Atención:</strong> <?php echo htmlspecialchars($error_python); ?>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body p-3 p-md-4">
                    <h6 class="card-title text-uppercase opacity-75 small mb-1">Total Recibido (Últimos 50)</h6>
                    <h2 class="card-text fw-bold m-0 fs-2">$<?php echo number_format($monto_total, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive border rounded">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark text-nowrap">
                <tr>
                    <th>Fecha / Hora (Bogotá)</th>
                    <th>Identificación Remitente</th>
                    <th>Monto Recibido</th>
                    <th>Canal / Detalles de Referencia</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($filas)): ?>
                    <?php foreach ($filas as $row): ?>
                        <tr>
                            <td class="text-nowrap">
                                <strong><?php echo date("d/m/Y", strtotime($row['fecha_correo'])); ?></strong><br>
                                <span class="text-muted small"><?php echo date("h:i A", strtotime($row['fecha_correo'])); ?></span>
                            </td>
                            <td>
                                <?php if ($row['celular_origen'] !== 'No detectado'): ?>
                                    <span class="badge bg-secondary fs-6 mb-1"><?php echo htmlspecialchars($row['celular_origen']); ?></span>
                                <?php endif; ?>
                                
                                <?php if ($row['pagador'] !== 'No detectado'): ?>
                                    <small class="text-dark fw-semibold d-block text-wrap" style="max-width: 200px;"><?php echo htmlspecialchars($row['pagador']); ?></small>
                                <?php endif; ?>

                                <?php if ($row['celular_origen'] === 'No detectado' && $row['pagador'] === 'No detectado'): ?>
                                    <span class="badge bg-danger fs-6">No detectado</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold fs-5 text-nowrap">$<?php echo number_format($row['monto'], 0, ',', '.'); ?></td>
                            <td>
                                <div class="mb-1 d-flex flex-wrap gap-1 align-items-center">
                                    <span class="badge bg-info text-dark text-uppercase"><?php echo htmlspecialchars($row['banco_origen']); ?></span>
                                    <span class="text-muted font-monospace small">Ref: <?php echo htmlspecialchars($row['referencia']); ?></span>
                                </div>
                                <div class="text-muted text-break-custom small font-monospace opacity-75" style="max-width: 250px; font-size: 0.72rem;">
                                    ID: <?php echo htmlspecialchars($row['numero_transaccion_largo']); ?>
                                </div>
                            </td>
                            <td>
                                <?php if (strcasecmp($row['estado_sesion'], 'pendiente') === 0): ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Procesado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No hay transferencias registradas aún en el sistema.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>