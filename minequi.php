<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    $stmt_insert = $mysqliWeb->prepare("INSERT INTO notificaciones_nequi 
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

// 4. Consultar SOLO las transferencias del día (Fecha Bogotá)
$hoy = date('Y-m-d');
$sql_select = "SELECT id, celular_origen, pagador, banco_origen, referencia, numero_transaccion_largo, monto, fecha_correo, asunto, estado_sesion 
               FROM notificaciones_nequi 
               WHERE DATE(fecha_correo) = '$hoy' 
               ORDER BY fecha_correo DESC";

$resultado = $mysqliWeb->query($sql_select);

$monto_total = 0;
$filas = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $monto_total += (float)$row['monto'];
        $filas[] = $row;
    }
}

// Detectar si la página YA está abierta dentro del popup
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
        /* Optimización total para entornos Popup y pantallas muy pequeñas */
        @media (max-width: 768px) {
            body {
                padding: 4px !important;
            }
            .container-main {
                padding: 12px !important;
                border-radius: 4px !important;
            }
            .table th, .table td {
                font-size: 0.78rem !important;
                padding: 6px 4px !important;
            }
            .fs-5 {
                font-size: 0.95rem !important;
            }
            .fs-3 {
                font-size: 1.25rem !important;
            }
            .badge {
                font-size: 0.7rem !important;
                padding: 4px 6px !important;
            }
            /* Ocultar el ID largo de transacción en pantallas diminutas para priorizar espacio */
            .id-transaccion-larga {
                display: none !important;
            }
        }

        /* Romper texto largo de forma limpia para evitar scroll horizontal innecesario */
        .text-break-custom {
            word-break: break-all;
            white-space: normal;
        }

        /* Permitir que los nombres de pagadores fluyan correctamente en espacios reducidos */
        .pagador-texto {
            max-width: 150px;
            white-space: normal;
            word-wrap: break-word;
            display: inline-block;
        }
    </style>
</head>
<body class="bg-light p-2 p-md-4">

<div class="container-fluid container-xl bg-white p-3 p-md-4 rounded shadow-sm container-main">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
        <h2 class="text-primary m-0 fs-3 fw-bold">📥 Control Bre-B</h2>
        
        <div class="d-flex flex-column flex-sm-row align-items-center gap-2 w-100 w-md-auto">
            
            
            <div class="d-flex flex-column align-items-center align-items-sm-end gap-1 w-100 w-md-auto">
                <button onclick="location.reload();" class="btn btn-success btn-sm w-100 text-nowrap">🔄 Sincronizar Banco</button>
                <small class="text-muted text-center text-sm-end w-100 fw-medium" style="font-size: 0.72rem;">
                    ⏱️ Próxima actualización: <span id="timer" class="text-danger fw-bold">03:00</span>
                </small>
            </div>
        </div>
    </div>

    <?php if ($error_python): ?>
        <div class="alert alert-warning alert-dismissible fade show small py-2 px-3 mb-3" role="alert" style="font-size:0.8rem;">
            <strong>Atención:</strong> <?php echo htmlspecialchars($error_python); ?>
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body p-2 p-md-3">
                    <h6 class="card-title text-uppercase opacity-75 mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Total Recibido Hoy</h6>
                    <h3 class="card-text fw-bold m-0 fs-3">$<?php echo number_format($monto_total, 0, ',', '.'); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive border rounded">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark text-nowrap">
                <tr>
                    <th>Fecha / Hora</th>
                    <th>Remitente</th>
                    <th>Monto</th>
                    <th>Detalles / Ref</th>
                    <th>Estado</th>
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
                            <td>
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
                            <td>
                                <div class="mb-0 d-flex flex-wrap gap-1 align-items-center">
                                    <span class="badge bg-info text-dark text-uppercase" style="font-size:0.65rem;"><?php echo htmlspecialchars($row['banco_origen']); ?></span>
                                    <span class="text-muted font-monospace text-break-custom" style="font-size:0.7rem;">Ref:<?php echo htmlspecialchars($row['referencia']); ?></span>
                                </div>
                                <div class="text-muted text-break-custom font-monospace opacity-75 id-transaccion-larga" style="max-width: 200px; font-size: 0.68rem;">
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
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4" style="font-size:0.85rem;">
                            No hay transferencias registradas hoy.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // 1. Lógica de Cuenta Regresiva en Tiempo Real
    let tiempoRestante = 180; 
    const contenedorTimer = document.getElementById('timer');

    const cuentaRegresiva = setInterval(function() {
        tiempoRestante--;
        let minutos = Math.floor(tiempoRestante / 60);
        let segundos = tiempoRestante % 60;

        minutos = minutos < 10 ? '0' + minutos : minutes;
        segundos = segundos < 10 ? '0' + segundos : segundos;

        if(contenedorTimer) {
            contenedorTimer.textContent = minutos + ':' + segundos;
        }

        if (tiempoRestante <= 0) {
            clearInterval(cuentaRegresiva);
            location.reload();
        }
    }, 1000);

    // 2. Función para abrir la página actual en un popup centrado
    function abrirComoPopup() {
        const ancho = 640; // Ajustado ideal para vista tipo barra lateral/widget
        const alto = 680;
        const izquierda = (screen.width - ancho) / 2;
        const arriba = (screen.height - alto) / 2;
        
        const urlActual = window.location.origin + window.location.pathname + '?popup=1';
        const caracteristicas = `width=${ancho},height=${alto},left=${izquierda},top=${arriba},resizable=yes,scrollbars=yes,status=no,toolbar=no,menubar=no`;
        
        window.open(urlActual, 'ControlTransferenciasPopup', caracteristicas);
    }
</script>

</body>
</html>