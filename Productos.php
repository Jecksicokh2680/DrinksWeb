<?php
// Incluye la conexión y el manejo de la lógica de guardado
require 'ConnCentral.php'; 

// Verifica la conexión a ConnCentral
if (!isset($mysqliPos) || $mysqliPos->connect_error) {
    die("Error de Conexión a la base de datos ConnCentral. Revise su archivo ConnCentral.php: " . ($mysqliPos->connect_error ?? 'Variable no definida.'));
}
else {
    // Conexión exitosa
    echo "Conexión a ConnCentral establecida correctamente.";
}

$mensaje = "";
$mensaje_error = "";

// --- LÓGICA DE ACTUALIZACIÓN DE PRODUCTO (POST) ---
if (isset($_POST['actualizar_producto'])) {
    
    $idproducto = intval($_POST['idproducto'] ?? 0); 
    $descripcion = $mysqliPos->real_escape_string(trim($_POST['descripcion'] ?? ''));
    $precioventa = floatval($_POST['precioventa'] ?? 0.00);
    $precioespecial1 = floatval($_POST['precioespecial1'] ?? 0.00);
    $precioespecial2 = floatval($_POST['precioespecial2'] ?? 0.00);
    $stockmin = floatval($_POST['stockmin'] ?? 0.00);
    $estado = intval($_POST['estado'] ?? 0);

    if ($idproducto > 0) {
        $stmt = $mysqliPos->prepare("
            UPDATE productos
            SET descripcion = ?, 
                precioventa = ?, 
                precioespecial1 = ?,
                precioespecial2 = ?,
                stockmin = ?,
                estado = ?
            WHERE idproducto = ?
        ");
        
        $stmt->bind_param(
            "sddddii",
            $descripcion,
            $precioventa,
            $precioespecial1,
            $precioespecial2,
            $stockmin,
            $estado,
            $idproducto
        );

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $mensaje = "✅ Producto con ID **{$idproducto}** actualizado correctamente en ConnCentral.";
            } else {
                $mensaje = "✏️ Producto con ID **{$idproducto}** revisado (sin cambios detectados).";
            }
        } else {
            $mensaje_error = "❌ Error al actualizar producto: " . $stmt->error;
        }
        
        $stmt->close();
    } else {
        $mensaje_error = "ID de producto inválido para la actualización.";
    }
}

// Cerramos la conexión después de la lógica de actualización
$mysqliPos->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos con Filtro</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f4f6f9; }
        .container { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); overflow-x: auto; }
        h2 { color: #343a40; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        .filtro-area { display: flex; gap: 15px; margin-bottom: 20px; align-items: center; }
        .filtro-area input, .filtro-area select { padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        table { width: 100%; min-width: 1000px; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        th { background-color: #007bff; color: white; text-transform: uppercase; font-size: 14px; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        table input[type="text"], table input[type="number"], table select { padding: 5px; border: 1px solid #ccc; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 14px; }
        .dato-fijo { white-space: nowrap; text-align: right; font-weight: bold; }
        .btn-guardar { background-color: #28a745; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; transition: background-color 0.3s; }
        .mensaje-status { padding: 10px; margin-bottom: 20px; border-radius: 6px; font-weight: bold; }
        .mensaje-status.exito { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .mensaje-status.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .loading { text-align: center; padding: 20px; font-style: italic; color: #6c757d; }
    </style>
</head>
<body>

<div class="container">
    <h2>📝 Edición de Productos con Filtro Dinámico</h2>

    <?php 
    if ($mensaje) {
        $clase_mensaje = strpos($mensaje, '✅') !== false || strpos($mensaje, '✏️') !== false ? 'exito' : 'error';
        echo "<p class=\"mensaje-status $clase_mensaje\">" . htmlspecialchars($mensaje) . "</p>";
    } elseif ($mensaje_error) {
        echo "<p class=\"mensaje-status error\">⚠️ " . htmlspecialchars($mensaje_error) . "</p>";
    }
    ?>

    <div class="filtro-area">
        <label for="filtro_texto">Buscar por Código/Descripción:</label>
        <input type="text" id="filtro_texto" placeholder="Escriba aquí para filtrar..." style="width: 300px;">

        <label for="filtro_estado">Estado:</label>
        <select id="filtro_estado">
            <option value="todos">Todos</option>
            <option value="1">Activo</option>
            <option value="0">Inactivo</option>
        </select>
    </div>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th class="dato-fijo">Costo Ponderado</th>
                <th class="dato-fijo">Precio Venta</th>
                <th class="dato-fijo">Precio Esp. 1</th>
                <th class="dato-fijo">Precio Esp. 2</th>
                <th class="dato-fijo">Stock Mín.</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody id="tabla_productos">
            <tr>
                <td colspan="9" class="loading">Cargando productos...</td>
            </tr>
        </tbody>
    </table>
    
    <p style="margin-top: 20px; font-size: small;">* Mostrando hasta 100 resultados filtrados de la tabla `productos`.</p>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tablaBody = document.getElementById('tabla_productos');
        const filtroTexto = document.getElementById('filtro_texto');
        const filtroEstado = document.getElementById('filtro_estado');
        let debounceTimer;

        // Función principal para cargar y filtrar los datos
        function cargarProductos() {
            const filtro = filtroTexto.value;
            const estado = filtroEstado.value;

            // Mostrar estado de carga
            tablaBody.innerHTML = '<tr><td colspan="9" class="loading">Buscando productos...</td></tr>';

            // Realizar la solicitud AJAX
            fetch('fetch_productos.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // Enviar el filtro de texto y el estado
                body: `filtro=${encodeURIComponent(filtro)}&estado=${encodeURIComponent(estado)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    tablaBody.innerHTML = `<tr><td colspan="9" class="mensaje-status error">${data.error}</td></tr>`;
                } else {
                    // Insertar el HTML de las filas devuelto por el servidor
                    tablaBody.innerHTML = data.html; 
                }
            })
            .catch(error => {
                console.error('Error en la solicitud AJAX:', error);
                tablaBody.innerHTML = '<tr><td colspan="9" class="mensaje-status error">Error al comunicarse con el servidor.</td></tr>';
            });
        }

        // --- Event Listeners para el filtro dinámico ---

        // Evento para el campo de texto (con debounce para no saturar el servidor)
        filtroTexto.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(cargarProductos, 300); // Espera 300ms antes de buscar
        });

        // Evento para el selector de estado
        filtroEstado.addEventListener('change', cargarProductos);

        // Cargar productos al iniciar la página
        cargarProductos();

        // --- Manejo del formulario de Guardar (para que la actualización no recargue la página) ---

        // Delegación de eventos para manejar el envío de los formularios
        tablaBody.addEventListener('submit', function(e) {
            if (e.target.matches('form')) {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);

                // Opcional: Mostrar un estado de guardando
                const btnGuardar = form.querySelector('.btn-guardar');
                btnGuardar.textContent = 'Guardando...';
                btnGuardar.disabled = true;

                fetch(window.location.href, { // Envía al mismo index.php para la lógica de UPDATE
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text()) // Leer la respuesta como texto
                .then(html => {
                    // Recargar los productos filtrados después de guardar (para ver el cambio)
                    cargarProductos(); 

                    // Opcional: Mostrar mensaje de éxito/error (si el mensaje está en el HTML)
                    // Como la lógica de mensaje está en PHP al inicio, se recomienda un refresh simple
                    // o extraer el mensaje de la respuesta. Para simplificar, recargamos la tabla.
                    alert('Producto actualizado. Recargando listado...');
                })
                .catch(error => {
                    alert('Error al guardar el producto.');
                    console.error('Error de guardado:', error);
                    btnGuardar.textContent = 'Guardar';
                    btnGuardar.disabled = false;
                });
            }
        });
    });
</script>

</body>
</html>