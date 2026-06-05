<?php
/**
 * 1. PROCESAMIENTO DE PETICIONES ASÍNCRONAS (API AUTO-CONSULTA)
 */
if (isset($_GET['ajax']) && isset($_GET['sede'])) {
    header('Content-Type: application/json');
    date_default_timezone_set('America/Bogota');

    ini_set('mysql.connect_timeout', 3);
    ini_set('default_socket_timeout', 3);

    $sede = $_GET['sede'];
    $response = [
        'status' => 'Cerrado',
        'color' => '#6b7280',
        'msg' => 'Instancia no válida',
        'tipo' => 'cerrado'
    ];

    $con = null;

    if ($sede === 'General' && file_exists(__DIR__ . '/Conexion.php')) {
        include __DIR__ . '/Conexion.php';
        $con = $mysqli ?? null;
    } elseif ($sede === 'Central' && file_exists(__DIR__ . '/ConnCentral.php')) {
        include __DIR__ . '/ConnCentral.php';
        $con = $mysqliCentral ?? null;
    } elseif ($sede === 'Drinks' && file_exists(__DIR__ . '/ConnDrinks.php')) {
        include __DIR__ . '/ConnDrinks.php';
        $con = $mysqliDrinks ?? null;
    }

    if (isset($con)) {
        if ($con instanceof mysqli) {
            if ($con->connect_error) {
                $response = [
                    'status' => 'Cerrado',
                    'color' => '#ef4444',
                    'msg' => 'Error de Red / Túnel Caído: ' . $con->connect_error,
                    'tipo' => 'cerrado'
                ];
            } else {
                $con->close();
                $response = [
                    'status' => 'Abierto',
                    'color' => '#10b981',
                    'msg' => 'Túnel estable - En línea',
                    'tipo' => 'abierto'
                ];
            }
        } else {
            $response = [
                'status' => 'Cerrado',
                'color' => '#ef4444',
                'msg' => 'Error de inicialización del controlador MySQL',
                'tipo' => 'cerrado'
            ];
        }
    } else {
        $response['msg'] = 'Archivo de conexión correspondiente ausente u objeto nulo';
    }

    echo json_encode($response);
    exit;
}

/**
 * 2. CARGA DE VISTA PRINCIPAL
 */
date_default_timezone_set('America/Bogota');
$sedes_a_monitorear = ['General', 'Central', 'Drinks'];
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
        .filter-menu { display: flex; gap: 10px; margin-bottom: 15px; }
        .btn-filter { background: white; border: 1px solid #e5e7eb; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: 0.2s; color: #4b5563; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn-filter:hover { background: #f9fafb; border-color: #d1d5db; }
        .btn-filter.active { background: #374151; color: white; border-color: #374151; }

        /* Monitor de Sedes */
        .db-monitor { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
        .card { background: white; padding: 18px 20px; border-radius: 12px; min-width: 240px; flex: 1; max-width: 320px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border-top: 5px solid #9ca3af; transition: all 0.25s ease; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; vertical-align: middle; background-color: #9ca3af; }
        
        /* Animación de carga */
        .loading-pulse { animation: pulse 1.5s infinite ease-in-out; }
        @keyframes pulse { 0% { opacity: 0.5; } 50% { opacity: 1; } 100% { opacity: 0.5; } }
    </style>
</head>
<body>

    <header style="margin-bottom: 25px;">
        <h1 style="margin: 0; font-size: 1.75rem;">Panel de Control Administrativo</h1>
        <p style="margin: 5px 0 0 0; color: #6b7280;">Monitoreo individual de conexiones y estado de Túneles.</p>
    </header>

    <div class="filter-container">
        <div class="filter-menu">
            <button class="btn-filter active" onclick="filtrarSedes('todos', this)">Ver Todos</button>
            <button class="btn-filter" onclick="filtrarSedes('abierto', this)">Abiertos</button>
            <button class="btn-filter" onclick="filtrarSedes('cerrado', this)">Cerrados</button>
        </div>
    </div>

    <div class="db-monitor">
        <?php foreach ($sedes_a_monitorear as $sede): ?>
            <div class="card card-sede loading-pulse" id="card-<?= $sede ?>" data-nombre="<?= $sede ?>" data-tipo="pendiente" data-status="Cargando..." style="border-top-color: #9ca3af;">
                <div style="font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 700; letter-spacing: 0.05em;"><?= $sede ?></div>
                <div style="font-size: 1.4rem; font-weight: 700; margin: 6px 0; color: #111827; display: flex; align-items: center;">
                    <span class="status-dot" id="dot-<?= $sede ?>"></span>
                    <span id="status-txt-<?= $sede ?>">Consultando...</span>
                </div>
                <small id="msg-txt-<?= $sede ?>" style="color: #9ca3af; font-style: italic; font-size: 0.85rem;">Validando credenciales de puerto...</small>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    let filtroActivo = 'todos';

    document.addEventListener("DOMContentLoaded", function() {
        consultarCadaTunel();
    });

    function consultarCadaTunel() {
        const tarjetas = document.querySelectorAll('.card-sede');
        let promesas = [];

        tarjetas.forEach(tarjeta => {
            const sede = tarjeta.getAttribute('data-nombre');
            
            let peticion = fetch(`?ajax=1&sede=${sede}`)
                .then(res => res.json())
                .then(data => {
                    tarjeta.classList.remove('loading-pulse');
                    tarjeta.setAttribute('data-tipo', data.tipo);
                    tarjeta.setAttribute('data-status', data.status);
                    tarjeta.style.borderTopColor = data.color;
                    
                    document.getElementById(`dot-${sede}`).style.backgroundColor = data.color;
                    document.getElementById(`status-txt-${sede}`).innerText = data.status;
                    document.getElementById(`msg-txt-${sede}`).innerText = data.msg;
                })
                .catch(err => {
                    tarjeta.classList.remove('loading-pulse');
                    tarjeta.setAttribute('data-tipo', 'cerrado');
                    tarjeta.setAttribute('data-status', 'Cerrado');
                    tarjeta.style.borderTopColor = '#ef4444';
                    
                    document.getElementById(`dot-${sede}`).style.backgroundColor = '#ef4444';
                    document.getElementById(`status-txt-${sede}`).innerText = 'Cerrado';
                    document.getElementById(`msg-txt-${sede}`).innerText = 'Tiempo de respuesta agotado / Caída crítica.';
                });
            
            promesas.push(peticion);
        });

        Promise.all(promesas).then(() => {
            procesarVisibilidadTarjetas();
        });
    }

    function filtrarSedes(filtro, boton) {
        if (boton) {
            document.querySelectorAll('.btn-filter').forEach(btn => btn.classList.remove('active'));
            boton.classList.add('active');
        }
        filtroActivo = filtro;
        procesarVisibilidadTarjetas();
    }

    function procesarVisibilidadTarjetas() {
        document.querySelectorAll('.card-sede').forEach(tarjeta => {
            const tipo = tarjeta.getAttribute('data-tipo');

            if (filtroActivo === 'todos' || tipo === filtroActivo) {
                tarjeta.style.display = 'block';
            } else {
                tarjeta.style.display = 'none';
            }
        });
    }
    </script>
</body>
</html>