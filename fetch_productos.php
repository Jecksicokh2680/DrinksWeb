<?php
// ====================================================================
// === 1. INCLUSIÓN Y LÓGICA DE CONTROL                               ===
// ====================================================================

// Incluye la conexión y el estado ($mysqliPos y $conn_error)
require 'ConnCentral.php'; 

// Variables de estado (Aunque no se usan directamente aquí, se mantienen por consistencia)
$mensaje = "";
$mensaje_error = "";

// Variable para distinguir si la solicitud es una carga inicial, un filtro AJAX o un guardado POST
$is_ajax_filter = isset($_POST['action']) && $_POST['action'] === 'filter';
$is_ajax_save   = isset($_POST['action']) && $_POST['action'] === 'save';


// ====================================================================
// === 2. LÓGICA DE ACTUALIZACIÓN (Guardado de Formulario - AJAX)   ===
// ====================================================================

// Solo intentamos actualizar si NO hay error de conexión y es una solicitud de guardado
if ($conn_error === null && $is_ajax_save) {
    
    header('Content-Type: application/json');

    $barcode = intval($_POST['barcode'] ?? 0); 
    // Usamos real_escape_string sobre $mysqliPos para sanear la entrada
    $descripcion = $mysqliPos->real_escape_string(trim($_POST['descripcion'] ?? ''));
    $precioventa = floatval($_POST['precioventa'] ?? 0.00);
    $precioespecial1 = floatval($_POST['precioespecial1'] ?? 0.00);
    $precioespecial2 = floatval($_POST['precioespecial2'] ?? 0.00);
    $stockmin = floatval($_POST['stockmin'] ?? 0.00); // <--- CORREGIDO: Capturamos stockmin
    $estado = intval($_POST['estado'] ?? 0);

    $respuesta = ['success' => false, 'message' => ''];

    if ($barcode > 0) {
        $stmt = $mysqliPos->prepare("
            UPDATE productos
            SET descripcion = ?, 
                precioventa = ?, 
                precioespecial1 = ?,
                precioespecial2 = ?,
                stockmin = ?,       // <--- CORREGIDO: Incluimos stockmin en el UPDATE
                estado = ?
            WHERE barcode = ?
        ");
        
        // sddddii: string, double, double, double, double, integer, integer
        $stmt->bind_param(
            "sddddii", // <--- CORREGIDO: 7 tipos de dato (4 'd' por los precios y stockmin)
            $descripcion,
            $precioventa,
            $precioespecial1,
            $precioespecial2,
            $stockmin,             // <--- CORREGIDO: Pasamos la variable stockmin
            $estado,
            $barcode
        );

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $respuesta['success'] = true;
                $respuesta['message'] = "✅ Producto con ID **{$barcode}** actualizado correctamente.";
            } else {
                $respuesta['success'] = true;
                $respuesta['message'] = "✏️ Producto con ID **{$barcode}** revisado (sin cambios detectados).";
            }
        } else {
            $respuesta['message'] = "❌ Error al actualizar producto: " . $stmt->error;
        }
        
        $stmt->close();
    } else {
        $respuesta['message'] = "❌ ID de producto inválido para la actualización.";
    }

    $mysqliPos->close();
    echo json_encode($respuesta);
    exit; // Detiene el script para solo devolver JSON
}


// ====================================================================
// === 3. LÓGICA DE BÚSQUEDA Y FILTRADO (Motor AJAX)                ===
// ====================================================================

if ($conn_error === null && $is_ajax_filter) {
    
    header('Content-Type: application/json');

    $filtro = $_POST['filtro'] ?? '';
    $estado_filtro = $_POST['estado'] ?? 'todos'; 

    $sql = "SELECT 
                barcode, codigo, descripcion, costo, 
                precioventa, precioespecial1, precioespecial2, stockmin, estado // <--- CORREGIDO: Incluimos stockmin en la selección
            FROM 
                productos
            WHERE 1=1"; // <--- CORREGIDO: Quitamos el 'and estado=1' fijo para permitir el filtro dinámico

    $params = [];
    $types = '';

    if ($estado_filtro !== 'todos') {
        $sql .= " AND estado = ?";
        $types .= 'i';
        $params[] = $estado_filtro;
    }

    if (!empty($filtro)) {
        $sql .= " AND (barcode LIKE ? OR descripcion LIKE ?)";
        $types .= 'ss';
        $filtro_like = '%' . $filtro . '%';
        $params[] = $filtro_like;
        $params[] = $filtro_like;
    }

    $sql .= " ORDER BY barcode LIMIT 100"; // <--- Añadido límite para eficiencia

    $html_filas = '';
    $stmt = null;

    try {
        if ($stmt = $mysqliPos->prepare($sql)) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params); 
            }
            
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($resultado->num_rows > 0) {
                while ($producto = $resultado->fetch_assoc()) {
                    $estado_select_1 = $producto['estado'] == 1 ? 'selected' : '';
                    $estado_select_0 = $producto['estado'] == 0 ? 'selected' : '';

                    // Se usa la estructura de TR/TD, pero el JS manejará la recolección de datos
                    $html_filas .= '
                        <tr data-barcode="' . htmlspecialchars($producto['barcode']) . '">
                            <td style="font-weight: bold;">' . htmlspecialchars($producto['barcode']) . '</td>
                            <td><input type="text" class="edit-descripcion" value="' . htmlspecialchars($producto['descripcion']) . '" maxlength="80" required></td>
                            <td class="dato-fijo">$' . number_format($producto['costo'], 2, ',', '.') . '</td>
                            <td><input type="number" class="edit-precioventa" value="' . htmlspecialchars(sprintf('%.2f', $producto['precioventa'])) . '" step="0.01" min="0" required></td>
                            <td><input type="number" class="edit-precioespecial1" value="' . htmlspecialchars(sprintf('%.2f', $producto['precioespecial1'])) . '" step="0.01" min="0"></td>
                            <td><input type="number" class="edit-precioespecial2" value="' . htmlspecialchars(sprintf('%.2f', $producto['precioespecial2'])) . '" step="0.01" min="0"></td>
                            <td><input type="number" class="edit-stockmin" value="' . htmlspecialchars(sprintf('%.2f', $producto['stockmin'])) . '" step="0.01" min="0"></td>                             <td>
                                <select class="edit-estado">
                                    <option value="1" ' . $estado_select_1 . '>Activo</option>
                                    <option value="0" ' . $estado_select_0 . '>Inactivo</option>
                                </select>
                            </td>
                            <td><button type="button" class="btn-guardar" data-barcode="' . htmlspecialchars($producto['barcode']) . '">Guardar</button></td>
                        </tr>';

                }
            } else {
                $html_filas = '<tr><td colspan="9" style="text-align: center;">No se encontraron productos que coincidan con el filtro.</td></tr>';
            }
            
            $stmt->close();
        } else {
            $html_filas = '<tr><td colspan="9" style="text-align: center; color: red;">Error en la preparación de la consulta: ' . $mysqliPos->error . '</td></tr>';
        }
    } catch (Exception $e) {
        $html_filas = '<tr><td colspan="9" style="text-align: center; color: red;">Excepción: ' . $e->getMessage() . '</td></tr>';
    }

    $mysqliPos->close();
    echo json_encode(['html' => $html_filas]);
    exit; // Detiene el script aquí
}


// Cerramos la conexión si fue exitosa y no se cerró en los bloques AJAX
if ($conn_error === null && !$is_ajax_filter && !$is_ajax_save) {
    $mysqliPos->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos con Filtro Dinámico</title>
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

    <?php if ($conn_error !== null): ?>
        <p class="mensaje-status error">
            ⚠️ **CONEXIÓN FALLIDA:** El servidor no pudo conectarse a la base de datos.
            <br><small><?= htmlspecialchars($conn_error) ?></small>
        </p>
    <?php else: ?>
        <p class="mensaje-status exito" id="connection-status-message">
            ✅ **CONEXIÓN EXITOSA** a la base de datos `empresa001`.
        </p>
    <?php endif; ?>
    <div id="dynamic-message-area"></div>


    <?php if ($conn_error === null): // Mostrar interfaz solo si la conexión fue exitosa ?>
        
        <div class="filtro-area">
            <label for="filtro_texto">Buscar por Barcode/Descripción:</label>
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
                    <th>Barcode</th>
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
    
    <?php endif; ?>

</div>

<?php if ($conn_error === null): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tablaBody = document.getElementById('tabla_productos');
        const filtroTexto = document.getElementById('filtro_texto');
        const filtroEstado = document.getElementById('filtro_estado');
        const dynamicMessageArea = document.getElementById('dynamic-message-area');
        const staticStatusMessage = document.getElementById('connection-status-message');
        let debounceTimer;

        // Función auxiliar para mostrar mensajes dinámicos (Guardado)
        function showMessage(message, isSuccess) {
            const className = isSuccess ? 'exito' : 'error';
            // Insertar mensaje al inicio del área de mensajes
            dynamicMessageArea.innerHTML = `<p class="mensaje-status ${className}">${message}</p>` + dynamicMessageArea.innerHTML;
            
            // Eliminar el mensaje después de 5 segundos
            setTimeout(() => { 
                const firstMessage = dynamicMessageArea.querySelector('.mensaje-status');
                if (firstMessage) {
                    dynamicMessageArea.removeChild(firstMessage);
                }
            }, 5000);
            
            // Ocultar el mensaje estático de conexión para que no compita
            if (staticStatusMessage) staticStatusMessage.style.display = 'none';
        }

        // Función principal para cargar y filtrar los datos (AJAX Filter)
        function cargarProductos() {
            const filtro = filtroTexto.value;
            const estado = filtroEstado.value;

            tablaBody.innerHTML = '<tr><td colspan="9" class="loading">Buscando productos...</td></tr>';

            // Realizar la solicitud AJAX para FILTRAR
            fetch(window.location.href, { 
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // Incluimos action=filter para que el script PHP sepa qué lógica ejecutar
                body: `action=filter&filtro=${encodeURIComponent(filtro)}&estado=${encodeURIComponent(estado)}`
            })
            .then(response => {
                if (!response.ok) {
                    // Si la respuesta no es 200 OK, es probablemente un error de PHP/servidor
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    tablaBody.innerHTML = `<tr><td colspan="9" class="mensaje-status error">${data.error}</td></tr>`;
                } else {
                    tablaBody.innerHTML = data.html; 
                }
            })
            .catch(error => {
                console.error('Error en la solicitud AJAX de filtro:', error);
                tablaBody.innerHTML = '<tr><td colspan="9" class="mensaje-status error">Error al comunicarse con el servidor (AJAX Filter). Verifique la consola F12.</td></tr>';
            });
        }

        // --- Event Listeners para el filtro dinámico ---
        filtroTexto.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(cargarProductos, 300);
        });
        filtroEstado.addEventListener('change', cargarProductos);

        // Cargar productos al iniciar la página
        cargarProductos();

        // --- Manejo del botón Guardar (AJAX Save) ---
        tablaBody.addEventListener('click', function(e) {
            if (e.target.matches('.btn-guardar')) {
                e.preventDefault();
                const btn = e.target;
                const row = btn.closest('tr');
                const barcode = btn.getAttribute('data-barcode');
                
                // Captura de todos los campos
                const descripcion = row.querySelector('.edit-descripcion').value;
                const precioventa = row.querySelector('.edit-precioventa').value;
                const precioespecial1 = row.querySelector('.edit-precioespecial1').value;
                const precioespecial2 = row.querySelector('.edit-precioespecial2').value;
                const stockmin = row.querySelector('.edit-stockmin').value; // <--- CORREGIDO: Captura de stockmin
                const estado = row.querySelector('.edit-estado').value;

                const formData = new FormData();
                formData.append('action', 'save');
                formData.append('barcode', barcode);
                formData.append('descripcion', descripcion);
                formData.append('precioventa', precioventa);
                formData.append('precioespecial1', precioespecial1);
                formData.append('precioespecial2', precioespecial2);
                formData.append('stockmin', stockmin); // <--- CORREGIDO: Envío de stockmin
                formData.append('estado', estado);

                const originalText = btn.textContent;
                btn.textContent = 'Guardando...';
                btn.disabled = true;

                // Realizar la solicitud AJAX para GUARDAR
                fetch(window.location.href, { 
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Verificación si la respuesta es JSON válida
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.indexOf("application/json") !== -1) {
                        return response.json();
                    } else {
                        // Si no es JSON, lanza error para capturar la respuesta HTML/error de PHP
                        return response.text().then(text => {
                            throw new Error(`Respuesta no JSON. Contenido: ${text}`);
                        });
                    }
                })
                .then(data => {
                    // 1. Mostrar el mensaje de guardado
                    showMessage(data.message, data.success);
                    
                    // 2. Recargar el listado para mostrar los valores actualizados
                    cargarProductos(); 

                    // 3. Restaurar botón (se actualizará con cargarProductos, pero por si acaso)
                    btn.textContent = originalText;
                    btn.disabled = false;
                })
                .catch(error => {
                    console.error('Error de guardado:', error);
                    showMessage('❌ Error al guardar el producto. Verifique la consola F12 para detalles del servidor.', false);
                    btn.textContent = originalText;
                    btn.disabled = false;
                });
            }
        });
    });
</script>
<?php endif; ?>

</body>
</html>