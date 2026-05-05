<?php
/**
 * 1. CARGA DE CONEXIONES
 * Se verifica la existencia de los archivos y se cargan las variables de conexión.
 */
require_once 'Conexion.php'; // Define $mysqli (General)

if (file_exists(__DIR__ . '/ConnCentral.php')) {
    require_once 'ConnCentral.php'; // Define $mysqliCentral
}
if (file_exists(__DIR__ . '/ConnDrinks.php')) {
    require_once 'ConnDrinks.php';  // Define $mysqliDrinks
}

/** 
 * 2. LÓGICA DE VALIDACIÓN DE ESTADO
 */
$instancias = [
    'General' => isset($mysqli) ? $mysqli : null,
    'Central' => isset($mysqliCentral) ? $mysqliCentral : null,
    'Drinks'  => isset($mysqliDrinks) ? $mysqliDrinks : null
];

$conexiones_estado = [];

foreach ($instancias as $nombre => $con) {
    if ($con instanceof mysqli) {
        if ($con->connect_error) {
            $conexiones_estado[$nombre] = ['status' => 'Error de Red', 'color' => '#ef4444', 'msg' => $con->connect_error];
        } else {
            $conexiones_estado[$nombre] = ['status' => 'Conectado', 'color' => '#10b981', 'msg' => 'En línea'];
        }
    } else {
        // Determinamos por qué no es una instancia de mysqli
        $causa = is_null($con) ? 'Archivo no encontrado o variable no definida' : 'Tipo de objeto inválido';
        $conexiones_estado[$nombre] = ['status' => 'Tunel Cerrado', 'color' => '#6b7280', 'msg' => $causa];
    }
}

/**
 * 3. SIMULACIÓN DE DATOS PARA EGRESOS (Editable)
 * Aquí cargarías los datos de tu base de datos.
 */
$egresos_ejemplo = [
    ['id' => 101, 'concepto' => 'Pago Proveedor Bebidas', 'monto' => 1500000, 'sede' => 'Central'],
    ['id' => 102, 'concepto' => 'Mantenimiento Bodega', 'monto' => 450000, 'sede' => 'Drinks'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo | Control de Sedes</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f3f4f6; color: #1f2937; margin: 20px; }
        .db-monitor { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
        .card { background: white; padding: 15px; border-radius: 10px; min-width: 200px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-top: 5px solid; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background-color: #374151; color: white; }
        tr:hover { background-color: #f9fafb; }
        
        .btn-edit { background: #2563eb; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; transition: 0.2s; }
        .btn-edit:hover { background: #1d4ed8; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; background: #e5e7eb; }
    </style>
</head>
<body>

    <header>
        <h1>Panel de Control Administrativo</h1>
        <p>Monitoreo de conexiones y gestión de egresos operativos.</p>
    </header>

    <!-- Sección de Conexiones -->
    <div class="db-monitor">
        <?php foreach ($conexiones_estado as $sede => $info): ?>
            <div class="card" style="border-top-color: <?= $info['color'] ?>;">
                <div style="font-size: 0.8rem; text-transform: uppercase; color: #6b7280; font-weight: bold;"><?= $sede ?></div>
                <div style="font-size: 1.2rem; font-weight: bold; margin: 5px 0;">
                    <span class="status-dot" style="background-color: <?= $info['color'] ?>;"></span>
                    <?= $info['status'] ?>
                </div>
                <small style="color: #9ca3af; font-style: italic;"><?= $info['msg'] ?></small>
            </div>
        <?php endforeach; ?>
    </div>

   

</body>
</html>