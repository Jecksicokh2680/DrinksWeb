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

/** * 2. LÓGICA DE VALIDACIÓN DE ESTADO (Abierto / Cerrado)
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
            $conexiones_estado[$nombre] = [
                'status' => 'Cerrado', 
                'color' => '#ef4444', 
                'msg' => 'Error de Red: ' . $con->connect_error, 
                'tipo' => 'cerrado'
            ];
        } else {
            $conexiones_estado[$nombre] = [
                'status' => 'Abierto', 
                'color' => '#10b981', 
                'msg' => 'En línea', 
                'tipo' => 'abierto'
            ];
        }
    } else {
        $causa = is_null($con) ? 'Archivo no encontrado' : 'Tipo de objeto inválido';
        $conexiones_estado[$nombre] = [
            'status' => 'Cerrado', 
            'color' => '#6b7280', 
            'msg' => $causa, 
            'tipo' => 'cerrado'
        ];
    }
}

/**
 * 3. SIMULACIÓN DE DATOS PARA EGRESOS (Editable)
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
        
        /* Contenedor y Botones de Filtros */
        .filter-container { margin-bottom: 25px; }
        .filter-menu { display: flex; gap: 10px; }
        .btn-filter { background: white; border: 1px solid #e5e7eb; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: 0.2s; color: #4b5563; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn-filter:hover { background: #f9fafb; border-color: #d1d5db; }
        .btn-filter.active { background: #374151; color: white; border-color: #374151; }

        /* Monitor de Sedes */
        .db-monitor { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
        .card { background: white; padding: 18px 20px; border-radius: 12px; min-width: 240px; flex: 1; max-width: 320px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border-top: 5px solid; transition: all 0.25s ease; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; vertical-align: middle; }
        
        /* Tablas y otros elementos */
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

    <header style="margin-bottom: 25px;">
        <h1 style="margin: 0; font-size: 1.75rem;">Panel de Control Administrativo</h1>
        <p style="margin: 5px 0 0 0; color: #6b7280;">Monitoreo de conexiones Túneles.</p>
    </header>

    <div class="filter-container">
        <div class="filter-menu">
            <button class="btn-filter active" onclick="filtrarSedes('todos', this)">Ver Todos</button>
            <button class="btn-filter" onclick="filtrarSedes('abierto', this)">Abiertos</button>
            <button class="btn-filter" onclick="filtrarSedes('cerrado', this)">Cerrados</button>
        </div>
    </div>

    <div class="db-monitor">
        <?php foreach ($conexiones_estado as $sede => $info): ?>
            <div class="card card-sede" data-tipo="<?= $info['tipo'] ?>" style="border-top-color: <?= $info['color'] ?>;">
                <div style="font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 700; letter-spacing: 0.05em;"><?= $sede ?></div>
                <div style="font-size: 1.4rem; font-weight: 700; margin: 6px 0; color: #111827; display: flex; align-items: center;">
                    <span class="status-dot" style="background-color: <?= $info['color'] ?>;"></span>
                    <?= $info['status'] ?>
                </div>
                <small style="color: #9ca3af; font-style: italic; font-size: 0.85rem;"><?= $info['msg'] ?></small>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    function filtrarSedes(filtro, boton) {
        // 1. Alternar la clase activa en los botones
        document.querySelectorAll('.btn-filter').forEach(btn => btn.classList.remove('active'));
        boton.classList.add('active');

        // 2. Ocultar o mostrar las tarjetas según el data-tipo
        document.querySelectorAll('.card-sede').forEach(tarjeta => {
            if (filtro === 'todos') {
                tarjeta.style.display = 'block';
            } else {
                if (tarjeta.getAttribute('data-tipo') === filtro) {
                    tarjeta.style.display = 'block';
                } else {
                    tarjeta.style.display = 'none';
                }
            }
        });
    }
    </script>

</body>
</html>