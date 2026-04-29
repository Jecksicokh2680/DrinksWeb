<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   LÓGICA AJAX: PROCESAR ACTUALIZACIONES
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $bc   = $_POST['barcode'];
    $sede = $_POST['sede']; // 'Central' o 'Drinks'
    $db   = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;

    if ($_POST['action'] === 'update_stock') {
        $nueva_cant = floatval($_POST['cantidad']);
        // Buscamos el idproducto para afectar la tabla inventario
        $res = $db->query("SELECT idproducto FROM productos WHERE barcode = '$bc' LIMIT 1");
        if ($r = $res->fetch_assoc()) {
            $idp = $r['idproducto'];
            $stmt = $db->prepare("UPDATE inventario SET cantidad = ? WHERE idproducto = ?");
            $stmt->bind_param("di", $nueva_cant, $idp);
            echo json_encode(['success' => $stmt->execute()]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        }
    }

    if ($_POST['action'] === 'toggle_status') {
        $nuevo_estado = intval($_POST['estado']);
        $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
        $stmt->bind_param("is", $nuevo_estado, $bc);
        echo json_encode(['success' => $stmt->execute()]);
    }
    exit;
}

/* ============================================================
   CONSULTA DE DATOS (Mantenemos tu lógica de mapeo)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term      = $_GET['term'] ?? '';
$like      = "%$term%";

// 1. Mapeo de Categorías
$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) { $prodCat[$r['sku']] = $r['CodCat']; }

$cats = [];
$resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1'");
while ($c = $resCat->fetch_assoc()) { $cats[$c['CodCat']] = $c['Nombre']; }

// 2. Consulta a Sedes (Se asume la misma lógica de WHERE del código anterior)
$sql = "SELECT p.barcode, p.descripcion, p.estado, IFNULL(SUM(i.cantidad),0) cantidad 
        FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
        WHERE p.barcode LIKE ? OR p.descripcion LIKE ? GROUP BY p.barcode";

// ... Ejecución de $stmtC y $stmtD para llenar arrays $central y $drinks ...
// (Para el ejemplo, asumo que $barcodes contiene la lista de SKUs a mostrar)

$stmtC = $mysqliCentral->prepare($sql);
$stmtC->bind_param("ss", $like, $like);
$stmtC->execute();
$resC = $stmtC->get_result();
$central = [];
while ($r = $resC->fetch_assoc()) { $central[$r['barcode']] = $r; }

$stmtD = $mysqliDrinks->prepare($sql);
$stmtD->bind_param("ss", $like, $like);
$stmtD->execute();
$resD = $stmtD->get_result();
$drinks = [];
while ($r = $resD->fetch_assoc()) { $drinks[$r['barcode']] = $r; }

$barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
usort($barcodes, function($a, $b) use($prodCat) { return ($prodCat[$a] ?? 'SIN') <=> ($prodCat[$b] ?? 'SIN'); });
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión Dual Sede | Corabastos</title>
    <style>
        body{ font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container{ max-width: 1100px; margin: auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #1a2a6c; color: white; padding: 12px; font-size: 13px; }
        td { border-bottom: 1px solid #eee; padding: 8px; text-align: center; }
        
        /* Estilos de fila por sede */
        .row-drinks { background: #fffcf5; }
        .row-central { background: #f5f9ff; }
        .product-header { background: #f8f9fa; font-weight: bold; text-align: left !important; border-top: 2px solid #ddd; }
        
        /* Controles */
        .stock-input { width: 70px; padding: 5px; border-radius: 4px; border: 1px solid #ccc; text-align: center; font-weight: bold; }
        .btn-save { background: #28a745; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; }
        .btn-save:hover { background: #218838; }

        /* Switch */
        .switch { position: relative; display: inline-block; width: 34px; height: 18px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
        .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:checked + .slider:before { transform: translateX(16px); }
        
        .badge-sede { font-size: 10px; padding: 3px 6px; border-radius: 4px; color: white; font-weight: bold; text-transform: uppercase; }
        .bg-drinks { background: #d97706; }
        .bg-central { background: #2563eb; }
    </style>
</head>
<body>

<div class="container">
    <h2>🛠️ Panel de Inventario Edición Directa</h2>

    <table>
        <thead>
            <tr>
                <th style="text-align:left">Producto / Sede</th>
                <th>Estado</th>
                <th>Stock Actual</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($barcodes as $b): 
                $d = $drinks[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---'];
                $c = $central[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---'];
                $desc = ($c['descripcion'] !== '---') ? $c['descripcion'] : $d['descripcion'];
            ?>
            <tr>
                <td colspan="4" class="product-header">
                    <span style="color:#666">[<?= $b ?>]</span> <?= htmlspecialchars($desc) ?>
                </td>
            </tr>

            <tr class="row-drinks">
                <td style="text-align:left; padding-left: 30px;">
                    <span class="badge-sede bg-drinks">Drinks</span>
                </td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Drinks', this)" <?= $d['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>
                    <input type="number" step="any" class="stock-input" id="input-drinks-<?= $b ?>" value="<?= $d['cantidad'] ?>">
                </td>
                <td>
                    <button class="btn-save" onclick="saveStock('<?= $b ?>', 'Drinks')">💾</button>
                </td>
            </tr>

            <tr class="row-central">
                <td style="text-align:left; padding-left: 30px;">
                    <span class="badge-sede bg-central">Central</span>
                </td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Central', this)" <?= $c['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>
                    <input type="number" step="any" class="stock-input" id="input-central-<?= $b ?>" value="<?= $c['cantidad'] ?>">
                </td>
                <td>
                    <button class="btn-save" onclick="saveStock('<?= $b ?>', 'Central')">💾</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function saveStock(barcode, sede) {
    const qty = document.getElementById(`input-${sede.toLowerCase()}-${barcode}`).value;
    
    const formData = new FormData();
    formData.append('action', 'update_stock');
    formData.append('barcode', barcode);
    formData.append('sede', sede);
    formData.append('cantidad', qty);

    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            alert(`Stock de ${sede} actualizado correctamente.`);
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function toggleStatus(barcode, sede, element) {
    const estado = element.checked ? 1 : 0;
    
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('barcode', barcode);
    formData.append('sede', sede);
    formData.append('estado', estado);

    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(!data.success) alert('No se pudo cambiar el estado.');
    });
}
</script>

</body>
</html>