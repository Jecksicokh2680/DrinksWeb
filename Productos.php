<?php
require_once("ConnCentral.php");   
require_once("ConnDrinks.php");    
require_once("Conexion.php");      

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $bc   = $_POST['barcode'];
    $sede = $_POST['sede'] ?? 'Central';
    $db   = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;

    if ($_POST['action'] === 'update_stock') {
        $nueva_cant = floatval($_POST['cantidad']);
        $res = $db->query("SELECT idproducto FROM productos WHERE barcode = '$bc' LIMIT 1");
        if ($r = $res->fetch_assoc()) {
            $idp = $r['idproducto'];
            $stmt = $db->prepare("UPDATE inventario SET cantidad = ? WHERE idproducto = ?");
            $stmt->bind_param("di", $nueva_cant, $idp);
            echo json_encode(['success' => $stmt->execute()]);
        }
    }

    if ($_POST['action'] === 'toggle_status') {
        $nuevo_estado = intval($_POST['estado']);
        $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
        $stmt->bind_param("is", $nuevo_estado, $bc);
        echo json_encode(['success' => $stmt->execute()]);
    }

    if ($_POST['action'] === 'update_name') {
        $nombre = $_POST['nombre'];
        $mysqliCentral->query("UPDATE productos SET descripcion = '$nombre' WHERE barcode = '$bc'");
        $mysqliDrinks->query("UPDATE productos SET descripcion = '$nombre' WHERE barcode = '$bc'");
        echo json_encode(['success' => true]);
    }
    exit;
}

$term = $_GET['term'] ?? '';
$like = "%$term%";

// Consulta incluyendo precioventa
$sql = "SELECT p.barcode, p.descripcion, p.estado, p.precioventa, IFNULL(SUM(i.cantidad),0) cantidad 
        FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
        WHERE p.barcode LIKE ? OR p.descripcion LIKE ? GROUP BY p.barcode";

$stmtC = $mysqliCentral->prepare($sql);
$stmtC->bind_param("ss", $like, $like);
$stmtC->execute();
$central = [];
$resC = $stmtC->get_result();
while ($r = $resC->fetch_assoc()) { $central[$r['barcode']] = $r; }

$stmtD = $mysqliDrinks->prepare($sql);
$stmtD->bind_param("ss", $like, $like);
$stmtD->execute();
$drinks = [];
$resD = $stmtD->get_result();
while ($r = $resD->fetch_assoc()) { $drinks[$r['barcode']] = $r; }

$barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
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
        
        .row-drinks { background: #fffcf5; }
        .row-central { background: #f5f9ff; }
        .product-header { background: #f8f9fa; font-weight: bold; text-align: left !important; border-top: 2px solid #ddd; }
        
        .stock-input { width: 70px; padding: 5px; border-radius: 4px; border: 1px solid #ccc; text-align: center; font-weight: bold; }
        .btn-save { background: #28a745; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; }
        
        .switch { position: relative; display: inline-block; width: 34px; height: 18px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
        .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:checked + .slider:before { transform: translateX(16px); }
        
        .badge-sede { font-size: 10px; padding: 3px 6px; border-radius: 4px; color: white; font-weight: bold; text-transform: uppercase; }
        .bg-drinks { background: #d97706; }
        .bg-central { background: #2563eb; }
        #filtro { width: 100%; padding: 12px; margin-bottom: 15px; border: 2px solid #ddd; border-radius: 6px; }
    </style>
</head>
<body>

<div class="container">
    <h2>🛠️ Panel de Inventario con Precios</h2>
    <input type="text" id="filtro" placeholder="🔍 Buscar por nombre o código..." onkeyup="filtrar()">

    <table>
        <thead>
            <tr>
                <th style="text-align:left">Producto / Sede</th>
                <th>Estado</th>
                <th>Precio Venta</th>
                <th>Stock</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($barcodes as $b): 
                $d = $drinks[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---', 'precioventa'=>0];
                $c = $central[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---', 'precioventa'=>0];
                $desc = ($c['descripcion'] !== '---') ? $c['descripcion'] : $d['descripcion'];
                $pv = ($c['precioventa'] != 0) ? $c['precioventa'] : $d['precioventa'];
            ?>
            <tr class="product-header">
                <td colspan="5" style="text-align:left;">
                    <span style="color:#666; font-size: 12px;">[<?= $b ?>]</span>
                    <input type="text" value="<?= htmlspecialchars($desc) ?>" style="border:none; background:transparent; width: 70%; font-weight:bold; font-size: 14px;" onblur="updateName('<?= $b ?>', this.value)">
                </td>
            </tr>

            <tr class="row-drinks">
                <td style="text-align:left; padding-left: 30px;"><span class="badge-sede bg-drinks">Drinks</span></td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Drinks', this)" <?= $d['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>$<?= number_format($pv, 0) ?></td>
                <td><input type="number" step="any" class="stock-input" id="input-drinks-<?= $b ?>" value="<?= $d['cantidad'] ?>"></td>
                <td><button class="btn-save" onclick="saveStock('<?= $b ?>', 'Drinks')">💾</button></td>
            </tr>

            <tr class="row-central">
                <td style="text-align:left; padding-left: 30px;"><span class="badge-sede bg-central">Central</span></td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Central', this)" <?= $c['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>$<?= number_format($pv, 0) ?></td>
                <td><input type="number" step="any" class="stock-input" id="input-central-<?= $b ?>" value="<?= $c['cantidad'] ?>"></td>
                <td><button class="btn-save" onclick="saveStock('<?= $b ?>', 'Central')">💾</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function filtrar() {
    const term = document.getElementById('filtro').value.toLowerCase();
    document.querySelectorAll('.product-header').forEach(header => {
        const text = header.textContent.toLowerCase();
        const row1 = header.nextElementSibling;
        const row2 = row1 ? row1.nextElementSibling : null;
        const visible = text.includes(term);
        header.style.display = visible ? '' : 'none';
        if(row1) row1.style.display = visible ? '' : 'none';
        if(row2) row2.style.display = visible ? '' : 'none';
    });
}
function updateName(b, v) { fetch('', {method:'POST', body:new URLSearchParams({action:'update_name', barcode:b, nombre:v})}); }
function saveStock(b, s) { 
    let v = document.getElementById(`input-${s.toLowerCase()}-${b}`).value;
    fetch('', {method:'POST', body:new URLSearchParams({action:'update_stock', barcode:b, sede:s, cantidad:v})}).then(()=>alert('Actualizado')); 
}
function toggleStatus(b, s, e) { fetch('', {method:'POST', body:new URLSearchParams({action:'toggle_status', barcode:b, sede:s, estado:e.checked?1:0})}); }
</script>
</body>
</html>