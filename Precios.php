<?php
// Incluye las conexiones
require 'ConnCentral.php'; 
// Guardamos la conexión central y posible error
$connCentral = $mysqliCentral;
$errorCentral = isset($mysqliCentral->connect_error) ? $mysqliCentral->connect_error : (isset($conn_error) ? $conn_error : null);
// Limpiamos $conn_error para el siguiente include si es necesario, aunque ConnDrinks lo sobrescribirá si falla
$conn_error = null;

require 'ConnDrinks.php'; 
$connDrinks = $mysqliDrinks;
$errorDrinks = isset($mysqliDrinks->connect_error) ? $mysqliDrinks->connect_error : (isset($conn_error) ? $conn_error : null);

// Consolidar estado de conexión (para mostrar en UI si al menos uno falla, o ambos)
$conn_error_msg = null;
if ($errorCentral) $conn_error_msg .= "Central: $errorCentral. ";
if ($errorDrinks)  $conn_error_msg .= "Drinks: $errorDrinks. ";

// Variables para distinguir tipos de solicitudes AJAX
$is_ajax_filter = isset($_POST['action']) && $_POST['action'] === 'filter';
$is_ajax_save = isset($_POST['action']) && $_POST['action'] === 'save';

// ====================================================================
// === LÓGICA DE GUARDADO (AJAX Save)                               ===
// ====================================================================

if ($is_ajax_save) {
    header('Content-Type: application/json');
    
    // Si hay error crítico de conexión en ambas, no podemos guardar nada.
    // Si solo falta una, podríamos intentar guardar en la otra. 
    // Para simplificar: intentamos en ambas si están disponibles.

    $barcode = $_POST['barcode'] ?? ''; 
    $descripcion = trim($_POST['descripcion'] ?? '');
    $estado = intval($_POST['estado'] ?? 0);

    // Datos Central
    $precioventa_c = floatval($_POST['precioventa_c'] ?? 0.00);
    $precioespecial1_c = floatval($_POST['precioespecial1_c'] ?? 0.00);
    $precioespecial2_c = floatval($_POST['precioespecial2_c'] ?? 0.00);

    // Datos Drinks
    $precioventa_d = floatval($_POST['precioventa_d'] ?? 0.00);
    $precioespecial1_d = floatval($_POST['precioespecial1_d'] ?? 0.00);
    $precioespecial2_d = floatval($_POST['precioespecial2_d'] ?? 0.00);

    $respuesta = ['success' => false, 'message' => ''];
    $msgs = [];

    if (!empty($barcode)) {
        
        // --- ACTUALIZAR CENTRAL ---
        if ($connCentral && !$errorCentral) {
            $stmtC = $connCentral->prepare("
                UPDATE productos
                SET descripcion = ?, precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ?
                WHERE barcode = ?
            ");
            $descEsc = $connCentral->real_escape_string($descripcion); // Aunque bind_param maneja esto, mantenemos consistencia si se usara raw
            
            // bind_param
            // s=desc, d=pv, d=pe1, d=pe2, i=est, s/i=barcode
            $typeStr = "sdddi" . (is_numeric($barcode) ? "i" : "s");
            $stmtC->bind_param($typeStr, $descripcion, $precioventa_c, $precioespecial1_c, $precioespecial2_c, $estado, $barcode);

            if ($stmtC->execute()) {
                $msgs[] = "Central: OK";
            } else {
                $msgs[] = "Central: Error (" . $stmtC->error . ")";
            }
            $stmtC->close();
        } else {
            $msgs[] = "Central: No conectado";
        }

        // --- ACTUALIZAR DRINKS ---
        if ($connDrinks && !$errorDrinks) {
            // Verificamos primero si existe el producto en Drinks para hacer UPDATE, 
            // o si asumimos que están sincronizados. El requerimiento es "cambiar precios", asume existencia.
            // Hacemos UPDATE directo.
            
            $stmtD = $connDrinks->prepare("
                UPDATE productos
                SET descripcion = ?, precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ?
                WHERE barcode = ?
            ");
            
            $typeStrD = "sdddi" . (is_numeric($barcode) ? "i" : "s");
            // Usamos la misma descripción y estado para mantener consistencia, pero precios de Drinks
            $stmtD->bind_param($typeStrD, $descripcion, $precioventa_d, $precioespecial1_d, $precioespecial2_d, $estado, $barcode);

            if ($stmtD->execute()) {
                 // Si affected_rows es 0 puede ser que no exista o no cambios.
                 if ($stmtD->affected_rows >= 0) {
                     $msgs[] = "Drinks: OK";
                 } else {
                     $msgs[] = "Drinks: Error";
                 }
            } else {
                $msgs[] = "Drinks: Error (" . $stmtD->error . ")";
            }
            $stmtD->close();
        } else {
            $msgs[] = "Drinks: No conectado";
        }

        $respuesta['success'] = true; 
        $respuesta['message'] = "Proceso finalizado. Result: " . implode(" | ", $msgs);

    } else {
        $respuesta['message'] = "❌ Barcode inválido.";
    }

    echo json_encode($respuesta);
    exit;
}

// ====================================================================
// === LÓGICA DE BÚSQUEDA Y FILTRADO (Motor AJAX)                   ===
// ====================================================================

if ($is_ajax_filter) {
    header('Content-Type: application/json');

    if (!$connCentral || $errorCentral) {
         echo json_encode(['html' => '<tr><td colspan="11" class="mensaje-status error">Error conexión Central</td></tr>']);
         exit;
    }

    $filtro = trim($_POST['filtro'] ?? '');
    $estado_filtro = $_POST['estado'] ?? 'todos';
    $page = max(1, intval($_POST['page'] ?? 1));
    $limit = intval($_POST['limit'] ?? 100);
    $offset = ($page - 1) * $limit;

    // 1. Obtener datos de CENTRAL (Driving list)
    $sql = "SELECT barcode, descripcion, costo, precioventa, precioespecial1, precioespecial2, estado 
            FROM productos WHERE 1=1";

    $params = [];
    $types = '';

    if ($estado_filtro !== 'todos') {
        $sql .= " AND estado = ?";
        $types .= 'i';
        $params[] = $estado_filtro;
    }

    if ($filtro !== '') {
        if (ctype_digit($filtro)) {
            $sql .= " AND barcode LIKE ?";
            $types .= 's';
            $params[] = $filtro . '%';
        } elseif (mb_strlen($filtro) >= 3) {
            $sql .= " AND descripcion LIKE ?";
            $types .= 's';
            $params[] = '%' . $filtro . '%';
        }
    }

    $sql .= " ORDER BY barcode LIMIT ? OFFSET ?";
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $html_filas = '';
    $productosCentral = [];
    $barcodes = [];

    if ($stmt = $connCentral->prepare($sql)) {
        if (!empty($params)) {
             $stmt->bind_param($types, ...$params); 
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $productosCentral[] = $row;
            // Guardamos barcode para consultar Drinks
            $barcodes[] = "'" . $connCentral->real_escape_string($row['barcode']) . "'";
        }
        $stmt->close();
    } else {
        $html_filas = '<tr><td colspan="11">Error SQL Central: ' . $connCentral->error . '</td></tr>';
    }

    // 2. Obtener datos de DRINKS (Supplementary)
    $preciosDrinks = []; // Map: barcode -> [pv, pe1, pe2]
    if (!empty($barcodes) && $connDrinks && !$errorDrinks) {
        $barcodesStr = implode(',', $barcodes);
        $sqlD = "SELECT barcode, precioventa, precioespecial1, precioespecial2 FROM productos WHERE barcode IN ($barcodesStr)";
        $resD = $connDrinks->query($sqlD);
        if ($resD) {
            while ($rowD = $resD->fetch_assoc()) {
                $preciosDrinks[$rowD['barcode']] = $rowD;
            }
        }
    }

    // 3. Renderizar
    if (empty($productosCentral) && empty($html_filas)) {
        $html_filas = '<tr><td colspan="11" style="text-align: center;">No se encontraron productos.</td></tr>';
    } else {
        foreach ($productosCentral as $p) {
            $bc = $p['barcode'];
            $pDrinks = $preciosDrinks[$bc] ?? ['precioventa'=>0, 'precioespecial1'=>0, 'precioespecial2'=>0];

            $estado_select_1 = $p['estado'] == 1 ? 'selected' : '';
            $estado_select_0 = $p['estado'] == 0 ? 'selected' : '';

            $html_filas .= '
            <tr data-barcode="' . htmlspecialchars($bc) . '">
                <td style="font-weight: bold; font-size: 0.9em;">' . htmlspecialchars($bc) . '</td>
                <td><input type="text" class="edit-descripcion" value="' . htmlspecialchars($p['descripcion']) . '" maxlength="80"></td>
                <td class="dato-fijo">' . sprintf('%.2f', $p['costo']) . '</td>
                
                <!-- Central -->
                <td style="background:#eaf2ff"><input type="number" class="edit-pv-c" value="' . sprintf('%.2f', $p['precioventa']) . '" step="0.01"></td>
                <td style="background:#eaf2ff"><input type="number" class="edit-pe1-c" value="' . sprintf('%.2f', $p['precioespecial1']) . '" step="0.01"></td>
                <td style="background:#eaf2ff"><input type="number" class="edit-pe2-c" value="' . sprintf('%.2f', $p['precioespecial2']) . '" step="0.01"></td>
                
                <!-- Drinks -->
                <td style="background:#fff4e6"><input type="number" class="edit-pv-d" value="' . sprintf('%.2f', $pDrinks['precioventa']) . '" step="0.01"></td>
                <td style="background:#fff4e6"><input type="number" class="edit-pe1-d" value="' . sprintf('%.2f', $pDrinks['precioespecial1']) . '" step="0.01"></td>
                <td style="background:#fff4e6"><input type="number" class="edit-pe2-d" value="' . sprintf('%.2f', $pDrinks['precioespecial2']) . '" step="0.01"></td>

                <td>
                    <select class="edit-estado">
                        <option value="1" ' . $estado_select_1 . '>Activo</option>
                        <option value="0" ' . $estado_select_0 . '>Inactivo</option>
                    </select>
                </td>
                <td><button type="button" class="btn-guardar" data-barcode="' . htmlspecialchars($bc) . '">💾</button></td>
            </tr>';
        }
    }

    echo json_encode(['html' => $html_filas]);
    exit;
}

// Cierre de conexiones si no es ajax
if (!$is_ajax_filter && !$is_ajax_save) {
    if ($connCentral) $connCentral->close();
    if ($connDrinks) $connDrinks->close();
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
        .btn-guardar:hover { background-color: #218838; }
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
            ✅ **CONEXIÓN EXITOSA** a la base de datos Central.
        </p>
    <?php endif; ?>
    <div id="dynamic-message-area"></div>

    <?php if ($conn_error === null): ?>
        
        <div class="filtro-area">
            <label for="filtro_texto">Buscar por Barcode/Descripción:</label>
            <input type="text" id="filtro_texto" placeholder="Escriba aquí para filtrar..." style="width: 300px;">

            <label for="filtro_estado">Estado:</label>
            <select id="filtro_estado">
                <option value="todos">Todos</option>
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>
            
            <label for="page_size_select">Tamaño:</label>
            <select id="page_size_select">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100" selected>100</option>
            </select>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Desc</th>
                    <th title="Costo Ponderado">Ponderado</th>
                    <th title="Central: Precio Venta" style="background:#0056b3">C. Venta</th>
                    <th title="Central: Precio Esp 1" style="background:#0056b3">C. Esp1</th>
                    <th title="Central: Precio Esp 2" style="background:#0056b3">C. Esp2</th>
                    <th title="Drinks: Precio Venta" style="background:#e65100">D. Venta</th>
                    <th title="Drinks: Precio Esp 1" style="background:#e65100">D. Esp1</th>
                    <th title="Drinks: Precio Esp 2" style="background:#e65100">D. Esp2</th>
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
        
        <div id="pagination_controls" style="margin-top:12px; display:flex; gap:8px; align-items:center;">
            <button id="prev_page" type="button" style="padding:6px 10px;">« Anterior</button>
            <span id="page_info">Página 1</span>
            <button id="next_page" type="button" style="padding:6px 10px;">Siguiente »</button>
        </div>

        <p style="margin-top: 12px; font-size: small;">* Mostrando hasta 100 resultados por página.</p>
    
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
        let currentPage = 1;
        let pageLimit = 100; // will be initialized from selector below

        // Función auxiliar para mostrar mensajes dinámicos
        function showMessage(message, isSuccess) {
            const className = isSuccess ? 'exito' : 'error';
            dynamicMessageArea.innerHTML = `<p class="mensaje-status ${className}">${message}</p>` + dynamicMessageArea.innerHTML;
            
            setTimeout(() => { 
                const firstMessage = dynamicMessageArea.querySelector('.mensaje-status');
                if (firstMessage) {
                    dynamicMessageArea.removeChild(firstMessage);
                }
            }, 5000);
            
            if (staticStatusMessage) staticStatusMessage.style.display = 'none';
        }

        // Función principal para cargar y filtrar los datos
        function cargarProductos() {
                const filtro = filtroTexto.value;
                const estado = filtroEstado.value;

                tablaBody.innerHTML = '<tr><td colspan="8" class="loading">Buscando productos...</td></tr>';

                fetch(window.location.href, { 
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                            body: `action=filter&filtro=${encodeURIComponent(filtro)}&estado=${encodeURIComponent(estado)}&page=${currentPage}&limit=${pageLimit}`
                })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    tablaBody.innerHTML = `<tr><td colspan="8" class="mensaje-status error">${data.error}</td></tr>`;
                } else {
                    tablaBody.innerHTML = data.html; 

                    // Gestionar estado de paginador según número de filas recibidas
                    const rows = tablaBody.querySelectorAll('tr');
                    let dataRows = 0;
                    rows.forEach(r => {
                        if (!r.querySelector('.loading') && r.querySelectorAll('td').length > 0) dataRows++;
                    });

                    // Actualizar indicador de página
                    document.getElementById('page_info').textContent = `Página ${currentPage}`;

                    // Si no hay filas de datos (mensaje de 'No se encontraron'), deshabilitar siguiente
                    const noResults = tablaBody.textContent.includes('No se encontraron productos');
                    document.getElementById('prev_page').disabled = currentPage <= 1;
                    // Si dataRows < limit entonces no hay siguiente página
                    document.getElementById('next_page').disabled = (dataRows === 0 || dataRows < pageLimit);
                }
            })
            .catch(error => {
                console.error('Error en la solicitud AJAX de filtro:', error);
                tablaBody.innerHTML = '<tr><td colspan="8" class="mensaje-status error">Error al comunicarse con el servidor. Verifique la consola F12.</td></tr>';
            });
        }

        // Event Listeners para el filtro dinámico
        filtroTexto.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            currentPage = 1; // resetear paginación al cambiar el filtro
            debounceTimer = setTimeout(cargarProductos, 300);
        });
        filtroEstado.addEventListener('change', function(){ currentPage = 1; cargarProductos(); });

        // Paginación: botones
        const prevBtn = document.getElementById('prev_page');
        const nextBtn = document.getElementById('next_page');
        prevBtn.addEventListener('click', function(){
            if (currentPage > 1) {
                currentPage--;
                cargarProductos();
            }
        });
        nextBtn.addEventListener('click', function(){
            currentPage++;
            cargarProductos();
        });

        // Cargar productos al iniciar la página
        cargarProductos();

        // Manejo del botón Guardar
        tablaBody.addEventListener('click', function(e) {
            if (e.target.matches('.btn-guardar')) {
                e.preventDefault();
                const btn = e.target;
                const row = btn.closest('tr');
                const barcode = btn.getAttribute('data-barcode');
                
                const descripcion = row.querySelector('.edit-descripcion').value;
                
                // Central
                const pv_c = row.querySelector('.edit-pv-c').value;
                const pe1_c = row.querySelector('.edit-pe1-c').value;
                const pe2_c = row.querySelector('.edit-pe2-c').value;
                
                // Drinks
                const pv_d = row.querySelector('.edit-pv-d').value;
                const pe1_d = row.querySelector('.edit-pe1-d').value;
                const pe2_d = row.querySelector('.edit-pe2-d').value;

                const estado = row.querySelector('.edit-estado').value;

                const formData = new FormData();
                formData.append('action', 'save');
                formData.append('barcode', barcode);
                formData.append('descripcion', descripcion);
                
                formData.append('precioventa_c', pv_c);
                formData.append('precioespecial1_c', pe1_c);
                formData.append('precioespecial2_c', pe2_c);

                formData.append('precioventa_d', pv_d);
                formData.append('precioespecial1_d', pe1_d);
                formData.append('precioespecial2_d', pe2_d);

                formData.append('estado', estado);

                const originalText = btn.textContent;
                btn.textContent = 'Guardando...';
                btn.disabled = true;

                fetch(window.location.href, { 
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    const contentType = response.headers.get("content-type");
                    if (contentType && contentType.indexOf("application/json") !== -1) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            throw new Error(`Respuesta no JSON. Contenido: ${text}`);
                        });
                    }
                })
                .then(data => {
                    showMessage(data.message, data.success);
                    cargarProductos();
                    btn.textContent = originalText;
                    btn.disabled = false;
                })
                .catch(error => {
                    console.error('Error de guardado:', error);
                    showMessage('❌ Error al guardar el producto. Verifique la consola F12.', false);
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
