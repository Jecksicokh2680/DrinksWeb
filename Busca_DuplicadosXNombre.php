<?php
// ====================================================================
// ANÁLISIS DE DUPLICADOS CON ORDEN DESCENDENTE (AJAX)
// ====================================================================
require 'ConnCentral.php'; 
require 'ConnDrinks.php'; 

// Lógica para actualizar el estado vía AJAX
if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    header('Content-Type: application/json');
    $bc = $_POST['barcode'];
    $sede = $_POST['sede'];
    $nuevo_estado = intval($_POST['nuevo_estado']);
    
    $db = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;
    $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
    $stmt->bind_param("is", $nuevo_estado, $bc);
    $res = $stmt->execute();
    
    echo json_encode(['success' => $res]);
    exit;
}

echo "<h2><i class='bi bi-sort-numeric-down'></i> Duplicados (Ordenados por Repetición)</h2>";
?>

<style>
    .switch { position: relative; display: inline-block; width: 34px; height: 18px; vertical-align: middle; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
    .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #198754; }
    input:checked + .slider:before { transform: translateX(16px); }
    .status-label { font-size: 10px; font-weight: bold; margin-left: 8px; }
    .badge-reps { background: #dc3545; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<?php
$todos_los_productos = [];

// 1. Carga de datos
$resC = $mysqliCentral->query("SELECT barcode, descripcion, precioventa, 'Central' as sede FROM productos WHERE estado = 1");
while($row = $resC->fetch_assoc()) $todos_los_productos[] = $row;

$resD = $mysqliDrinks->query("SELECT barcode, descripcion, precioventa, 'Drinks' as sede FROM productos WHERE estado = 1");
while($row = $resD->fetch_assoc()) $todos_los_productos[] = $row;

// 2. Agrupamiento
$conteo = [];
foreach ($todos_los_productos as $p) {
    $desc = strtoupper(trim($p['descripcion'])); 
    if (!isset($conteo[$desc])) {
        $conteo[$desc] = ['cantidad' => 0, 'detalles' => []];
    }
    $conteo[$desc]['cantidad']++;
    $conteo[$desc]['detalles'][] = ['bc' => $p['barcode'], 'sede' => $p['sede'], 'precio' => $p['precioventa']];
}

// 3. FILTRAR Y ORDENAR DESCENDENTE
// Solo dejamos los que tienen cantidad > 1
$duplicados = array_filter($conteo, function($v) { return $v['cantidad'] > 1; });

// Ordenamos por la columna 'cantidad' de mayor a menor
uasort($duplicados, function($a, $b) {
    return $b['cantidad'] <=> $a['cantidad'];
});

// 4. Renderizado
echo "<table border='1' style='font-family: sans-serif; font-size: 12px; border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #212529; color: white;'>
        <th style='padding: 10px; width: 40%;'>PRODUCTO</th>
        <th style='text-align: center;'>REPS</th>
        <th style='padding: 10px;'>GESTIÓN POR SEDE</th>
      </tr>";

foreach ($duplicados as $nombre => $datos) {
    echo "<tr>";
    echo "<td style='padding: 8px; text-transform: uppercase;'><b>$nombre</b></td>";
    echo "<td style='text-align: center;'><span class='badge-reps'>{$datos['cantidad']}</span></td>";
    echo "<td style='padding: 8px;'>";
    
    foreach ($datos['detalles'] as $d) {
        echo "<div style='margin-bottom: 8px; border-bottom: 1px solid #f0f0f0; padding-bottom: 5px; display: flex; align-items: center; justify-content: space-between;'>
                <span><code>{$d['bc']}</code> (<b>{$d['sede']}</b>) - <b>$".number_format($d['precio'],0)."</b></span>
                <div style='min-width: 100px; text-align: right;'>
                    <label class='switch'>
                        <input type='checkbox' checked class='toggle-estado' data-bc='{$d['bc']}' data-sede='{$d['sede']}'>
                        <span class='slider'></span>
                    </label>
                    <span class='status-label text-success'>ACTIVO</span>
                </div>
              </div>";
    }
    
    echo "</td></tr>";
}

if (empty($duplicados)) {
    echo "<tr><td colspan='3' style='padding: 30px; text-align: center; color: #666;'>No se encontraron productos activos duplicados.</td></tr>";
}
echo "</table>";
?>

<script>
$(document).on('change', '.toggle-estado', function() {
    const chk = $(this);
    const isChecked = chk.is(':checked');
    const label = chk.parent().next('.status-label');
    const data = {
        action: 'toggle_status',
        barcode: chk.data('bc'),
        sede: chk.data('sede'),
        nuevo_estado: isChecked ? 1 : 0
    };

    if(!isChecked) {
        label.text('INACTIVO').css('color', '#dc3545');
        chk.closest('div').css('opacity', '0.5');
    } else {
        label.text('ACTIVO').css('color', '#198754');
        chk.closest('div').css('opacity', '1');
    }

    $.post('', data, function(r) {
        if(!r.success) alert('Error en la base de datos');
    }, 'json');
});
</script>