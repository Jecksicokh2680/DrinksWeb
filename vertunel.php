<?php
// 1. Carga de archivos de conexión y configuración
require_once 'Conexion.php';       // Define $mysqli (General)
if (file_exists(__DIR__ . '/ConexionCentral.php')) {
    require_once 'ConexionCentral.php'; // Define $mysqliCentral
}
if (file_exists(__DIR__ . '/ConexionDrinks.php')) {
    require_once 'ConexionDrinks.php';  // Define $mysqliDrinks
}

/** 
 * 2. Lógica de Validación de Conexiones
 * Centralizamos el estado de las sedes antes de cualquier operación
 */
$conexiones_estado = [];
$instancias = [
    'General' => isset($mysqli) ? $mysqli : null,
    'Central' => isset($mysqliCentral) ? $mysqliCentral : null,
    'Drinks'  => isset($mysqliDrinks) ? $mysqliDrinks : null
];

foreach ($instancias as $nombre => $con) {
    if ($con instanceof mysqli) {
        if ($con->connect_error) {
            $conexiones_estado[$nombre] = ['status' => 'Error', 'color' => '#ef4444', 'msg' => $con->connect_error];
        } else {
            $conexiones_estado[$nombre] = ['status' => 'Conectado', 'color' => '#10b981', 'msg' => 'OK'];
        }
    } else {
        $conexiones_estado[$nombre] = ['status' => 'Tunel Cerrado', 'color' => '#6b7280', 'msg' => 'Variable ausente'];
    }
}

/**
 * 3. Interfaz de Monitoreo (Dashboard)
 * Este bloque HTML muestra el estado de las conexiones y permite gestionar egresos
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Administrativo - Control de Conexiones</title>
    <style>
        .db-monitor { display: flex; gap: 15px; margin: 20px 0; font-family: sans-serif; }
        .card { padding: 10px 15px; border-radius: 8px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-top: 4px solid; }
        .btn-edit { background: #3b82f6; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>

    <h2>Estado del Sistema</h2>
    
    <!-- Visualización de Conexiones -->
    <div class="db-monitor">
        <?php foreach ($conexiones_estado as $sede => $info): ?>
            <div class="card" style="border-top-color: <?= $info['color'] ?>;">
                <strong><?= $sede ?></strong><br>
                <span style="color: <?= $info['color'] ?>;"><?= $info['status'] ?></span>
                <small style="display:block; color: #9ca3af;"><?= $info['msg'] ?></small>
            </div>
        <?php endforeach; ?>
    </div>

    <hr>

    

</body>
</html>