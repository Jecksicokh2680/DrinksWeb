<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establecer la zona horaria de Bogotá para PHP
date_default_timezone_set('America/Bogota');

// 1. Configuración manual de la conexión remota a MySQL
$host    = "52.15.192.69";
$usuario = "root";
$pass    = "root";
$db      = "BnmaWeb";
$puerto  = 32768;

$mysqli = new mysqli($host, $usuario, $pass, $db, $puerto);

if ($mysqli->connect_error) {
    die("<div class='alert alert-danger'>La conexión a MySQL falló: " . $mysqli->connect_error . "</div>");
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
    // CORRECCIÓN: Se cambió substring por substr ya que substring no existe en PHP
    $error_python = "Error al decodificar JSON de Python. Salida cruda: " . substr($output, 0, 100);
} elseif (isset($nuevos_correos[0]['error'])) {
    $error_python = $nuevos_correos[0]['error'];
}

// 3. Si Python encontró correos nuevos válidos, PHP los guarda en la base de datos
if (empty($error_python) && !empty($nuevos_correos) && is_array($nuevos_correos)) {
    
    $stmt_check = $mysqli->prepare("SELECT id FROM notificaciones_nequi WHERE uid_correo = ?");
    $stmt_insert = $mysqli->prepare("INSERT INTO notificaciones_nequi (celular_origen, monto, fecha_correo, asunto, uid_correo, estado_sesion) VALUES (?, ?, ?, ?, ?, 'pendiente')");

    foreach ($nuevos_correos as $correo) {
        if (!isset($correo['uid_correo'])) continue;

        // Verificar duplicados por UID
        $stmt_check->bind_param("s", $correo['uid_correo']);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 0) {
            
            // Validar la fecha entregada por Python
            if (!empty($correo['fecha_correo'])) {
                $fecha_bogota = date('Y-m-d H:i:s', strtotime($correo['fecha_correo']));
            } else {
                $fecha_bogota = date('Y-m-d H:i:s'); 
            }

            // Mapear los datos limpios de Python
            $celular = $correo['celular_origen'] ?? 'No detectado';
            $monto   = isset($correo['monto']) ? (float)$correo['monto'] : 0.00;
            $asunto  = $correo['asunto'] ?? 'Sin Asunto';
            $uid     = $correo['uid_correo'];

            // Insertar registro
            $stmt_insert->bind_param("sdsss", $celular, $monto, $fecha_bogota, $asunto, $uid);
            $stmt_insert->execute();
        }
    }
    $stmt_check->close();
    $stmt_insert->close();
}

// 4. Consultar las últimas 50 transferencias totales para pintarlas en la tabla
$sql_select = "SELECT celular_origen, monto, fecha_correo, asunto, estado_sesion FROM notificaciones_nequi ORDER BY fecha_correo DESC LIMIT 50";
$resultado = $mysqli->query($sql_select);

// --- LÓGICA DE TOTALIZACIÓN ---
$monto_total = 0;
$filas = [];

// Guardamos los datos en un array para poder sumar el total antes de renderizar la tabla
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
    <title>Consulta Nequi - Sistema BNMA</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">

<div class="container bg-white p-4 rounded shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary m-0">📥 Control de Transferencias Nequi</h2>
        <button onclick="location.reload();" class="btn btn-success">🔄 Sincronizar BancoCaja</button>
    </div>

    <?php if ($error_python): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Atención:</strong> <?php echo htmlspecialchars($error_python); ?>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-uppercase opacity-75 small">Total Recibido (Últimos 50)</h6>
                    <h2 class="card-text fw-bold">$<?php echo number_format($monto_total, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Fecha / Hora (Bogotá)</th>
                    <th>Celular Origen</th>
                    <th>Monto Recibido</th>
                    <th>Asunto / Referencia</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($filas)): ?>
                    <?php foreach ($filas as $row): ?>
                        <tr>
                            <td><strong><?php echo date("d/m/Y h:i A", strtotime($row['fecha_correo'])); ?></strong></td>
                            <td>
                                <?php if (strpos($row['celular_origen'], 'Bre-B') !== false): ?>
                                    <span class="badge bg-info text-dark fs-6">Bre-B</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary fs-6"><?php echo htmlspecialchars($row['celular_origen']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold fs-5">$<?php echo number_format($row['monto'], 0, ',', '.'); ?></td>
                            <td class="text-muted small"><?php echo htmlspecialchars($row['asunto']); ?></td>
                            <td>
                                <?php if ($row['estado_sesion'] == 'pendiente'): ?>
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