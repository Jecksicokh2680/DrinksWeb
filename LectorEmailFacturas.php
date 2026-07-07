<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establecer la zona horaria de Bogotá para PHP
date_default_timezone_set('America/Bogota');

// 1. Incluir el archivo de conexión que proporcionaste
require_once __DIR__ . '/Conexion.php';

// Verificar si hubo un error en la conexión externa
if (isset($conn_error) && !empty($conn_error)) {
    die("<div class='alert alert-danger text-center m-3'>" . htmlspecialchars($conn_error) . "</div>");
}

// Asegurarnos de usar la conexión global asignada en tu archivo
if (!isset($mysqliWeb) || $mysqliWeb->connect_error) {
    die("<div class='alert alert-danger text-center m-3'>❌ La conexión remota ($mysqliWeb) no está disponible o falló.</div>");
}

// Configurar la zona horaria en la sesión de la Base de Datos usando tu variable global
$mysqliWeb->query("SET time_zone = '-05:00'");

// 2. Ejecutar el script nativo de Python para leer las facturas de Gmail
$command = 'python3 ' . __DIR__ . '/LectorEmailFacturas.py 2>&1';
$output = shell_exec($command);

// Decodificar la respuesta de Python
$nuevas_facturas = json_decode($output, true);

// Variable para capturar errores de ejecución o parsing
$error_python = null;
if ($output === null) {
    $error_python = "No se pudo ejecutar el script de Python.";
} elseif ($nuevas_facturas === null && !empty(trim($output))) {
    $error_python = "Error al decodificar JSON de Python. Salida cruda: " . substr($output, 0, 100);
} elseif (isset($nuevas_facturas[0]['error'])) {
    $error_python = $nuevas_facturas[0]['error'];
}

// 3. Procesar e insertar registros nuevos si no hay errores
if (empty($error_python) && !empty($nuevas_facturas) && is_array($nuevas_facturas)) {
    
    $stmt_check = $mysqliWeb->prepare("SELECT id FROM facturas_recibidas WHERE id_unico = ?");
    $stmt_insert = $mysqliWeb->prepare("INSERT INTO facturas_recibidas 
        (id_unico, uid_correo, cuenta_receptora, remitente_correo, proveedor, numero_documento, tipo_documento, fecha_emision, valor, fecha_recepcion_correo, asunto_correo, estado_procesado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

    foreach ($nuevas_facturas as $factura) {
        if (!isset($factura['id_unico'])) continue;

        $stmt_check->bind_param("s", $factura['id_unico']);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 0) {
            
            if (!empty($factura['fecha_recepcion_correo'])) {
                $fecha_bogota = date('Y-m-d H:i:s', strtotime($factura['fecha_recepcion_correo']));
            } else {
                $fecha_bogota = date('Y-m-d H:i:s'); 
            }

            $id_unico         = $factura['id_unico'];
            $uid              = $factura['uid_correo'] ?? '0';
            $cuenta_rec       = $factura['cuenta_receptora'] ?? 'No detectado';
            $remitente_corr   = $factura['remitente_correo'] ?? 'No detectado';
            $proveedor        = $factura['proveedor'] ?? 'No detectado';
            $num_documento    = $factura['numero_documento'] ?? 'No detectado';
            $tipo_documento   = $factura['tipo_documento'] ?? 'Factura de Venta';
            $fecha_emision    = !empty($factura['fecha_emision']) ? date('Y-m-d', strtotime($factura['fecha_emision'])) : date('Y-m-d');
            $valor            = isset($factura['valor']) ? (float)$factura['valor'] : 0.00;
            $asunto           = $factura['asunto'] ?? 'Sin Asunto';

            $stmt_insert->bind_param("ssssssssdss", 
                $id_unico, 
                $uid, 
                $cuenta_rec,
                $remitente_corr,
                $proveedor, 
                $num_documento, 
                $tipo_documento, 
                $fecha_emision,
                $valor, 
                $fecha_bogota,
                $asunto
            );
            $stmt_insert->execute();
        }
    }
    $stmt_check->close();
    $stmt_insert->close();
}

// 4. Filtrar por el día actual en Bogotá
$hoy = date('Y-m-d');
$inicio_dia = $hoy . ' 00:00:00';
$fin_dia    = $hoy . ' 23:59:59';

$sql_select = "SELECT id, id_unico, cuenta_receptora, remitente_correo, proveedor, numero_documento, tipo_documento, fecha_emision, valor, fecha_recepcion_correo, estado_procesado 
               FROM facturas_recibidas 
               WHERE fecha_recepcion_correo >= ? AND fecha_recepcion_correo <= ? 
               ORDER BY fecha_recepcion_correo DESC";

$stmt_select = $mysqliWeb->prepare($sql_select);
$stmt_select->bind_param("ss", $inicio_dia, $fin_dia);
$stmt_select->execute();
$resultado = $stmt_select->get_result();

$valor_total = 0;
$filas = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($row = $resultado->fetch_assoc()) {
        $valor_total += (float)$row['valor'];
        $filas[] = $row;
    }
}
$stmt_select->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Facturas Electrónicas - Hoy</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        @media (max-width: 576px) {
            .container-main { padding: 10px !important; }
            .table th, .table td { font-size: 0.85rem !important; padding: 8px 4px !important; }
            .fs-5 { font-size: 1rem !important; }
            .badge { font-size: 0.75rem !important; }
        }
        .text-break-custom { word-break: break-all; white-space: normal; }
    </style>
</head>
<body class="bg-light p-2 p-md-4">

<div class="container-fluid container-xl bg-white p-3 p-md-4 rounded shadow-sm container-main">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-4">
        <div>
            <h2 class="text-primary m-0 fs-3 fs-md-2">📋 Facturas Recibidas Hoy</h2>
            <small class="text-muted">Mostrando registros de Bogotá: <?php echo date('d/m/Y'); ?></small>
        </div>
        <button onclick="location.reload();" class="btn btn-success w-100 w-sm-auto text-nowrap">🔄 Sincronizar Facturas</button>
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
                    <h6 class="card-title text-uppercase opacity-75 small mb-1">Total Facturado Hoy</h6>
                    <h2 class="card-text fw-bold m-0 fs-2">$<?php echo number_format($valor_total, 0, ',', '.'); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive border rounded">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-dark text-nowrap">
                <tr>
                    <th>Fecha / Hora Correo</th>
                    <th>Proveedor / Origen</th>
                    <th>Número Documento</th>
                    <th>Valor Factura</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($filas)): ?>
                    <?php foreach ($filas as $row): ?>
                        <tr>
                            <td class="text-nowrap">
                                <strong><?php echo date("d/m/Y", strtotime($row['fecha_recepcion_correo'])); ?></strong><br>
                                <span class="text-muted small"><?php echo date("h:i A", strtotime($row['fecha_recepcion_correo'])); ?></span>
                            </td>

                            <td>
                                <small class="text-dark fw-bold d-block text-wrap" style="max-width: 250px;">
                                    <?php echo htmlspecialchars($row['proveedor']); ?>
                                </small>
                                <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                    <?php if (strpos($row['remitente_correo'], 'ramo') !== false): ?>
                                        <span class="badge bg-danger font-monospace" style="font-size: 0.65rem;">RAMO</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary font-monospace" style="font-size: 0.65rem;">CEN.BIZ</span>
                                    <?php endif; ?>
                                    <span class="text-muted small opacity-75"><?php echo htmlspecialchars($row['cuenta_receptora']); ?></span>
                                </div>
                            </td>

                            <td>
                                <span class="badge bg-secondary fs-6 mb-1 font-monospace"><?php echo htmlspecialchars($row['numero_documento']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.75rem;">
                                    Emisión: <?php echo date("d/m/Y", strtotime($row['fecha_emision'])); ?>
                                </small>
                            </td>

                            <td class="text-success fw-bold fs-5 text-nowrap">
                                $<?php echo number_format($row['valor'], 0, ',', '.'); ?>
                            </td>

                            <td>
                                <?php if ((int)$row['estado_procesado'] === 1): ?>
                                    <span class="badge bg-info text-dark">Cargado a Egreso</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Recibido</span>
                                <?php endif; ?>
                                <div class="text-muted text-break-custom font-monospace mt-1 opacity-50" style="max-width: 150px; font-size: 0.65rem;">
                                    ID: <?php echo htmlspecialchars($row['id_unico']); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No se encontraron facturas registradas el día de hoy.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>