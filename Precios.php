<?php
// ====================================================================
// 1. CONFIGURACIÓN Y CONEXIONES
// ====================================================================

// Asegúrate de que estos archivos existan en la misma carpeta
require 'ConnCentral.php'; 
$connCentral = $mysqliCentral;
$errorCentral = isset($mysqliCentral->connect_error) ? $mysqliCentral->connect_error : (isset($conn_error) ? $conn_error : null);
$conn_error = null;

require 'ConnDrinks.php'; 
$connDrinks = $mysqliDrinks;
$errorDrinks = isset($mysqliDrinks->connect_error) ? $mysqliDrinks->connect_error : (isset($conn_error) ? $conn_error : null);

// Variables para distinguir tipos de solicitudes AJAX
$is_ajax_filter = isset($_POST['action']) && $_POST['action'] === 'filter';
$is_ajax_save = isset($_POST['action']) && $_POST['action'] === 'save';

// ====================================================================
// 2. LÓGICA DE GUARDADO (AJAX Save)
// ====================================================================

if ($is_ajax_save) {
    header('Content-Type: application/json');
    
    $barcode = $_POST['barcode'] ?? ''; 
    $descripcion = trim($_POST['descripcion'] ?? '');
    $estado = intval($_POST['estado'] ?? 0);

    $precioventa_c = floatval($_POST['precioventa_c'] ?? 0.00);
    $precioespecial1_c = floatval($_POST['precioespecial1_c'] ?? 0.00);
    $precioespecial2_c = floatval($_POST['precioespecial2_c'] ?? 0.00);

    $precioventa_d = floatval($_POST['precioventa_d'] ?? 0.00);
    $precioespecial1_d = floatval($_POST['precioespecial1_d'] ?? 0.00);
    $precioespecial2_d = floatval($_POST['precioespecial2_d'] ?? 0.00);

    $respuesta = ['success' => false, 'message' => ''];
    $msgs = [];

    if (!empty($barcode)) {
        // --- ACTUALIZAR CENTRAL ---
        if ($connCentral && !$errorCentral) {
            $stmtC = $connCentral->prepare("UPDATE productos SET descripcion = ?, precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ? WHERE barcode = ?");
            $typeStr = "sdddi" . (is_numeric($barcode) ? "i" : "s");
            $stmtC->bind_param($typeStr, $descripcion, $precioventa_c, $precioespecial1_c, $precioespecial2_c, $estado, $barcode);
            if ($stmtC->execute()) $msgs[] = "Central: OK";
            else $msgs[] = "Central: Error";
            $stmtC->close();
        }

        // --- ACTUALIZAR DRINKS (Sincronizando nombre) ---
        if ($connDrinks && !$errorDrinks) {
            $stmtD = $connDrinks->prepare("UPDATE productos SET descripcion = ?, precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ? WHERE barcode = ?");
            $typeStrD = "sdddi" . (is_numeric($barcode) ? "i" : "s");
            $stmtD->bind_param($typeStrD, $descripcion, $precioventa_d, $precioespecial1_d, $precioespecial2_d, $estado, $barcode);
            if ($stmtD->execute()) $msgs[] = "Drinks: OK";
            else $msgs[] = "Drinks: Error";
            $stmtD->close();
        }

        $respuesta['success'] = true; 
        $respuesta['message'] = implode(" | ", $msgs);
    }
    echo json_encode($respuesta);
    exit;
}

// ====================================================================
// 3. LÓGICA DE FILTRADO (Motor AJAX)
// ====================================================================

if ($is_ajax_filter) {
    header('Content-Type: application/json');
    if (!$connCentral || $errorCentral) {
         echo json_encode(['html' => '<tr><td colspan="13">Error conexión Central</td></tr>']);
         exit;
    }

    $filtro = trim($_POST['filtro'] ?? '');
    $estado_filtro = $_POST['estado'] ?? 'todos';
    $limit = 100;

    $sql = "SELECT barcode, descripcion, costo, precioventa, precioespecial1, precioespecial2, estado FROM productos WHERE 1=1";
    $params = []; $types = '';

    if ($estado_filtro !== 'todos') { $sql .= " AND estado = ?"; $types .= 'i'; $params[] = $estado_filtro; }
    if ($filtro !== '') {
        if (ctype_digit($filtro)) { $sql .= " AND barcode LIKE ?"; $types .= 's'; $params[] = $filtro . '%'; }
        else { $sql .= " AND descripcion LIKE ?"; $types .= 's'; $params[] = '%' . $filtro . '%'; }
    }
    $sql .= " ORDER BY descripcion ASC LIMIT ?"; $types .= 'i'; $params[] = $limit;

    $productosCentral = []; $barcodes = [];
    if ($stmt = $connCentral->prepare($sql)) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $productosCentral[] = $row;
            $barcodes[] = "'" . $connCentral->real_escape_string($row['barcode']) . "'";
        }
        $stmt->close();
    }

    $preciosDrinks = [];
    if (!empty($barcodes) && $connDrinks && !$errorDrinks) {
        $sqlD = "SELECT barcode, precioventa, precioespecial1, precioespecial2 FROM productos WHERE barcode IN (".implode(',', $barcodes).")";
        $resD = $connDrinks->query($sqlD);
        if ($resD) { while ($rowD = $resD->fetch_assoc()) { $preciosDrinks[$rowD['barcode']] = $rowD; } }
    }

    $html = '';
    foreach ($productosCentral as $p) {
        $bc = $p['barcode'];
        $pD = $preciosDrinks[$bc] ?? ['precioventa'=>0, 'precioespecial1'=>0, 'precioespecial2'=>0];
        $mkC = ($p['costo'] > 0) ? (($p['precioventa'] / $p['costo']) - 1) * 100 : 0;
        $mkD = ($p['costo'] > 0) ? (($pD['precioventa'] / $p['costo']) - 1) * 100 : 0;

        $html .= '<tr data-barcode="'.htmlspecialchars($bc).'">
            <td><b>'.htmlspecialchars($bc).'</b></td>
            <td><input type="text" class="edit-desc" value="'.htmlspecialchars($p['descripcion']).'" style="width:300px"></td>
            <td>'.number_format($p['costo'], 2).'</td>
            <td style="background:#eaf2ff"><input type="number" class="ev-c" value="'.$p['precioventa'].'" step="0.01"></td>
            <td style="background:#eaf2ff"><input type="number" class="e1-c" value="'.$p['precioespecial1'].'" step="0.01"></td>
            <td style="background:#eaf2ff"><input type="number" class="e2-c" value="'.$p['precioespecial2'].'" step="0.01"></td>
            <td style="background:#fff4e6"><input type="number" class="ev-d" value="'.$pD['precioventa'].'" step="0.01"></td>
            <td style="background:#fff4e6"><input type="number" class="e1-d" value="'.$pD['precioespecial1'].'" step="0.01"></td>
            <td style="background:#fff4e6"><input type="number" class="e2-d" value="'.$pD['precioespecial2'].'" step="0.01"></td>
            <td>'.number_format($mkC, 1).'%</td>
            <td>'.number_format($mkD, 1).'%</td>
            <td>
                <select class="e-est">
                    <option value="1" '.($p['estado']==1?'selected':'').'>Activo</option>
                    <option value="0" '.($p['estado']==0?'selected':'').'>Inactivo</option>
                </select>
            </td>
            <td><button class="btn-save">💾</button></td>
        </tr>';
    }
    echo json_encode(['html' => $html ?: '<tr><td colspan="13">No hay resultados</td></tr>']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sincronizador Central & Drinks</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; background: #eee; padding: 20px; }
        .card { background: #fff; padding: 15px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: center; }
        th { background: #444; color: white; }
        .h-c { background: #0056b3; } .h-d { background: #d35400; }
        input { padding: 4px; border: 1px solid #ddd; border-radius: 3px; }
        input[type="number"] { width: 70px; }
        .btn-save { background: #27ae60; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; }
        #msg { position: fixed; top: 10px; right: 10px; padding: 10px; display: none; color: white; border-radius: 5px; z-index: 100; }
    </style>
</head>
<body>

<div class="card">
    <h2>Sincronización de Productos y Precios</h2>
    
    <input type="text" id="busqueda" placeholder="Buscar producto..." style="width: 300px;">
    <select id="filtro-est">
        <option value="todos">Todos</option>
        <option value="1">Activos</option>
        <option value="0">Inactivos</option>
    </select>

    <table>
        <thead>
            <tr>
                <th rowspan="2">Código</th>
                <th rowspan="2">Descripción</th>
                <th rowspan="2">Costo</th>
                <th colspan="3" class="h-c">CENTRAL</th>
                <th colspan="3" class="h-d">DRINKS</th>
                <th colspan="2">Markup</th>
                <th rowspan="2">Estado</th>
                <th rowspan="2">Acción</th>
            </tr>
            <tr>
                <th class="h-c">Venta</th> <th class="h-c">PE1</th> <th class="h-c">PE2</th>
                <th class="h-d">Venta</th> <th class="h-d">PE1</th> <th class="h-d">PE2</th>
                <th>Cen</th> <th>Dri</th>
            </tr>
        </thead>
        <tbody id="lista"></tbody>
    </table>
</div>

<div id="msg"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function load() {
        $.post('', { 
            action: 'filter', 
            filtro: $('#busqueda').val(), 
            estado: $('#filtro-est').val() 
        }, function(r) { $('#lista').html(r.html); }, 'json');
    }

    $('#busqueda').on('keyup', load);
    $('#filtro-est').on('change', load);

    $(document).on('click', '.btn-save', function() {
        const tr = $(this).closest('tr');
        const btn = $(this);
        const data = {
            action: 'save',
            barcode: tr.data('barcode'),
            descripcion: tr.find('.edit-desc').val(),
            precioventa_c: tr.find('.ev-c').val(),
            precioespecial1_c: tr.find('.e1-c').val(),
            precioespecial2_c: tr.find('.e2-c').val(),
            precioventa_d: tr.find('.ev-d').val(),
            precioespecial1_d: tr.find('.e1-d').val(),
            precioespecial2_d: tr.find('.e2-d').val(),
            estado: tr.find('.e-est').val()
        };

        btn.prop('disabled', true).text('...');
        $.post('', data, function(r) {
            $('#msg').text(r.message).css('background', r.success ? '#27ae60' : '#e74c3c').fadeIn().delay(2000).fadeOut();
            btn.prop('disabled', false).text('💾');
        }, 'json');
    });

    load();
});
</script>
</body>
</html>