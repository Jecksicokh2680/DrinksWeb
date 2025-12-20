<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

// --- SEGURIDAD: GENERAR O VALIDAR CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    // Generar un token seguro si no existe en la sesión
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// Mensaje de estado para el usuario (éxito o error)
$mensaje = "";
$mensaje_error_listado = "";

// Tipos de categoría disponibles (nombres completos)
const TIPOS_CATEGORIA = [
    "Cerveza", "Gaseosa", "Agua", "Jugo", "Hidratante",
    "Energizante", "Dulceria", "Maltas", "Papeleria",
    "Suero", "Aseo", "Elementos"
];

// --- FUNCIONES AUXILIARES DE MAPPING Y SANEAMIENTO ---

/**
 * Crea un mapa (array asociativo) donde la clave es el código de 2 caracteres 
 * y el valor es el nombre completo (ej: ['Ce' => 'Cerveza']).
 * Esto facilita la búsqueda del nombre completo para el listado.
 * @return array
 */
function get_tipo_map(): array {
    $map = [];
    foreach (TIPOS_CATEGORIA as $t) {
        $key = substr($t, 0, 2); // Left(Tipo, 2)
        $map[$key] = $t;
    }
    return $map;
}

/**
 * Función auxiliar para obtener el valor del checkbox (como INT).
 */
function get_checkbox_value(string $key): int {
    return isset($_POST[$key]) ? 1 : 0;
}

/**
 * Función centralizada para recolectar y sanear datos POST comunes.
 * Aplica substr(Tipo, 0, 2) para guardar solo los dos primeros caracteres.
 */
function collect_category_data(bool $is_creation = false): array {
    $data = [];

    $codCat = trim($_POST['CodCat'] ?? '');
    $data['CodCat'] = $is_creation ? strtoupper($codCat) : $codCat;
    
    $data['Nombre'] = trim($_POST['Nombre'] ?? '');
    $data['Unicaja'] = intval($_POST['Unicaja'] ?? 1); 
    $data['Estado'] = $_POST['Estado'] ?? '1';
    
    // --- LÓGICA DE Left(ComboTipo, 2) (PARA GUARDAR) ---
    $tipoCompleto = trim($_POST['Tipo'] ?? '');
    $data['Tipo'] = substr($tipoCompleto, 0, 2); // Guarda solo los 2 primeros caracteres
    // ------------------------------------

    $data['SegWebF'] = get_checkbox_value('SegWebF');
    $data['SegWebT'] = get_checkbox_value('SegWebT');

    return $data;
}

/**
 * Función para verificar el token CSRF
 */
function check_csrf(string $posted_token, string $session_token) {
    if (empty($posted_token) || !hash_equals($session_token, $posted_token)) {
        http_response_code(403);
        die("Error de seguridad: Solicitud rechazada. Token CSRF inválido o faltante.");
    }
}


// --- LÓGICA DE PROCESAMIENTO (POST) ---

/* =======================================
 * PROCESAR CREACIÓN DE CATEGORÍA
 * ======================================= */
if (isset($_POST['crear'])) {
    check_csrf($_POST['csrf_token'] ?? '', $csrf_token);
    
    // $datos['Tipo'] contendrá el valor de 2 caracteres
    $datos = collect_category_data(true); 

    $stmt = $mysqli->prepare("
        INSERT INTO categorias (CodCat, Nombre, SegWebF, SegWebT, Unicaja, Estado, Tipo)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    // "ssiiiss" 
    $stmt->bind_param(
        "ssiiiss",
        $datos['CodCat'],
        $datos['Nombre'],
        $datos['SegWebF'], 
        $datos['SegWebT'], 
        $datos['Unicaja'],
        $datos['Estado'],
        $datos['Tipo'] 
    );

    if ($stmt->execute()) {
        $mensaje = "✅ Categoría **{$datos['CodCat']}** creada correctamente. Tipo guardado: **{$datos['Tipo']}**";
    } else {
        if ($mysqli->errno == 1062) {
            $mensaje = "❌ Error: El código **{$datos['CodCat']}** ya existe.";
        } else {
            $mensaje = "❌ Error al crear categoría: " . $stmt->error;
        }
    }
    
    $stmt->close();
}

/* =======================================
 * PROCESAR ACTUALIZACIÓN DE CATEGORÍA
 * ======================================= */
if (isset($_POST['actualizar'])) {
    check_csrf($_POST['csrf_token'] ?? '', $csrf_token);

    // $datos['Tipo'] contendrá el valor de 2 caracteres
    $datos = collect_category_data(false);
    
    $stmt = $mysqli->prepare("
        UPDATE categorias
        SET Nombre=?, SegWebF=?, SegWebT=?, Unicaja=?, Estado=?, Tipo=?
        WHERE CodCat=?
    ");
    
    // "siiisss" 
    $stmt->bind_param(
        "siiisss",
        $datos['Nombre'],
        $datos['SegWebF'], 
        $datos['SegWebT'], 
        $datos['Unicaja'],
        $datos['Estado'],
        $datos['Tipo'], 
        $datos['CodCat'] 
    );
    
    $stmt->execute();
    $stmt->close();

    $mensaje = "✏️ Categoría **{$datos['CodCat']}** actualizada. Tipo guardado: **{$datos['Tipo']}**";
}

// --- CONSULTA PARA LISTAR CATEGORÍAS ---

$categorias = [];
$tipo_map = get_tipo_map(); // Generar el mapa de códigos de 2 letras a nombres completos
$sql_listado = "SELECT CodCat, Nombre, SegWebF, SegWebT, Unicaja, Estado, Tipo FROM categorias ORDER BY CodCat";
$res = $mysqli->query($sql_listado);

if ($res) {
    while ($r = $res->fetch_assoc()) {
        // CORRECCIÓN PARA EL LISTADO: Reemplazar el código de 2 caracteres por el nombre completo si existe
        if (isset($tipo_map[$r['Tipo']])) {
            $r['Tipo_Completo'] = $tipo_map[$r['Tipo']];
        } else {
            // Si no encuentra el mapeo (ej: valor antiguo o incorrecto), muestra el código (2 caracteres)
            $r['Tipo_Completo'] = $r['Tipo']; 
        }
        $categorias[] = $r;
    }
    $res->free(); 
} else {
    $mensaje_error_listado = "❌ Error al cargar categorías: " . $mysqli->error;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Categorías</title>
    
    <style>
        /* [Estilos CSS omitidos para brevedad] */
        :root {
            --color-primary: #007bff;
            --color-danger: #dc3545;
            --color-success: #28a745;
            --color-bg: #f4f6f9;
            --color-card-bg: #ffffff;
            --color-border: #dee2e6;
            --color-header-bg: #343a40;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--color-bg);
            padding: 20px;
            line-height: 1.5;
        }

        .card {
            background: var(--color-card-bg);
            padding: 25px;
            border-radius: 12px;
            max-width: 1200px;
            margin: 20px auto;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }

        h2 {
            margin-top: 0;
            color: var(--color-header-bg);
            border-bottom: 2px solid var(--color-primary);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .form-creacion {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        input[type="text"], input[type="number"], select, button {
            padding: 10px 12px;
            font-size: 15px;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus {
            border-color: var(--color-primary);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        button {
            background: var(--color-primary);
            color: white;
            cursor: pointer;
            border: none;
            flex-grow: 0;
            white-space: nowrap;
        }
        
        button[name="actualizar"] {
            background: var(--color-success);
        }
        
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            border-radius: 8px;
            overflow: hidden; 
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
        }
        
        th {
            background: var(--color-header-bg);
            color: white;
            text-align: center;
        }
        
        td:nth-child(4), td:nth-child(5), td:nth-child(6), td:nth-child(7), td:nth-child(8) {
            text-align: center;
        }
        
        .fila-inactiva {
            background: #ffe6e6; 
            color: #7a1f1f;
        }
        
        .fila-inactiva input[type="text"], .fila-inactiva select {
            background: #fdf5f5;
        }
        
        #filtro {
            width: 300px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        #tabla input[type="text"], #tabla select, #tabla input[type="number"] {
            padding: 5px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
        }
        
        #tabla input[type="number"] {
            width: 70px;
        }
        
        .mensaje-status {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-weight: bold;
        }
        
        .mensaje-status.exito {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .mensaje-status.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>

    <script>
        /**
         * FUNCIÓN DE FILTRADO DINÁMICO
         */
        function filtrar(){
            const filtro = document.getElementById("filtro").value.toLowerCase();
            const filas = document.querySelectorAll("#tabla tbody tr");
            
            filas.forEach(tr => {
                // El innerText incluye el nombre completo del Tipo mostrado en el <td>
                const textoFila = tr.innerText.toLowerCase(); 
                tr.style.display = textoFila.includes(filtro) ? "" : "none";
            });
        }
    </script>
</head>

<body>

<div class="card">
    <h2>➕ Crear Nueva Categoría</h2>

    <?php 
    if ($mensaje) {
        $mensaje_san = htmlentities($mensaje);
        $clase_mensaje = strpos($mensaje, '✅') !== false || strpos($mensaje, '✏️') !== false ? 'exito' : 'error';
        echo "<p class=\"mensaje-status $clase_mensaje\">$mensaje_san</p>";
    } elseif (isset($mensaje_error_listado)) {
        echo "<p class=\"mensaje-status error\">" . htmlentities($mensaje_error_listado) . "</p>";
    }
    ?>
    
    <form method="post" class="form-creacion">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <label>Código: 
            <input type="text" name="CodCat" maxlength="4" required placeholder="Ej: CERV">
        </label>
        
        <label>Nombre: 
            <input type="text" name="Nombre" required placeholder="Ej: Cervezas Nacionales">
        </label>

        <label>Tipo:
            <select name="Tipo" required>
                <option value="">-- Seleccione --</option>
                <?php 
                // Al crear, se muestra el nombre completo en el dropdown
                foreach(TIPOS_CATEGORIA as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        
        <label>Unicaja: 
            <input type="number" name="Unicaja" value="1" min="1" style="width:100px">
        </label>

        <label>SegWebF: 
            <input type="checkbox" name="SegWebF" value="1" <?= ($_POST['SegWebF'] ?? '') === '1' ? 'checked' : '' ?>>
        </label>
        <label>SegWebT: 
            <input type="checkbox" name="SegWebT" value="1" <?= ($_POST['SegWebT'] ?? '') === '1' ? 'checked' : '' ?>>
        </label>

        <label>Estado: 
            <select name="Estado">
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>
        </label>

        <button name="crear">Crear Categoría</button>
    </form>
</div>

<div class="card">
    <h2>📋 Categorías Existentes</h2>

    <input type="text" id="filtro" placeholder="🔍 Buscar por código, nombre, o tipo completo..." onkeyup="filtrar()">

    <table id="tabla">
        <thead>
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Tipo</th> <th>SegF</th>
                <th>SegT</th>
                <th>Unicaja</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>

            <?php foreach($categorias as $c): ?>
            <tr class="<?= $c['Estado'] === '0' ? 'fila-inactiva' : '' ?>">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <td>
                        <?= htmlspecialchars($c['CodCat']) ?>
                        <input type="hidden" name="CodCat" value="<?= htmlspecialchars($c['CodCat']) ?>">
                    </td>

                    <td>
                        <input type="text" name="Nombre" value="<?= htmlspecialchars($c['Nombre']) ?>" required>
                    </td>

                    <td>
                        <span title="Código DB: <?= htmlspecialchars($c['Tipo']) ?>">
                            <?= htmlspecialchars($c['Tipo_Completo'] ?? $c['Tipo']) ?>
                        </span>
                        
                        <select name="Tipo" style="display:none;"> 
                            <?php 
                            foreach(TIPOS_CATEGORIA as $t): 
                                $t_corto = substr($t, 0, 2); 
                            ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $c['Tipo'] === $t_corto ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>

                    <td>
                        <input type="checkbox" name="SegWebF" value="1" <?= $c['SegWebF'] == 1 ? 'checked' : '' ?>>
                    </td>
                    
                    <td>
                        <input type="checkbox" name="SegWebT" value="1" <?= $c['SegWebT'] == 1 ? 'checked' : '' ?>>
                    </td>

                    <td>
                        <input type="number" name="Unicaja" value="<?= htmlspecialchars($c['Unicaja']) ?>" min="1" style="width:70px">
                    </td>

                    <td>
                        <select name="Estado">
                            <option value="1" <?= $c['Estado'] === '1' ? 'selected' : '' ?>>Activo</option>
                            <option value="0" <?= $c['Estado'] === '0' ? 'selected' : '' ?>>Inactivo</option>
                        </select>
                    </td>

                    <td>
                        <button name="actualizar">Guardar</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>

        </tbody>
    </table>
    
    <?php if (empty($categorias)): ?>
        <p style="text-align: center; margin-top: 20px;">No hay categorías para mostrar.</p>
    <?php endif; ?>
    
</div>

</body>
</html>