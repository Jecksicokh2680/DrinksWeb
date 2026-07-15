<?php
// 1. Conexiones
require_once 'Conexion.php';    
require_once 'ConnCentral.php'; 

// 2. Lógica de Sincronización Masiva con Validación de Existencia
if (isset($_GET['accion']) && $_GET['accion'] == 'sincronizar') {
    // A. Obtener todos los productos que SÍ existen en la Central
    $resCentral = $mysqliCentral->query("SELECT barcode, estado FROM productos");
    $productosCentral = [];
    while ($p = $resCentral->fetch_assoc()) {
        $productosCentral[$p['barcode']] = $p['estado'];
    }

    // B. Obtener todos los SKUs de la web para comparar
    $resWeb = $mysqliWeb->query("SELECT Sku FROM catproductos");
    $actualizaciones = 0;

    $mysqliWeb->begin_transaction();
    try {
        while ($row = $resWeb->fetch_assoc()) {
            $sku = $row['Sku'];
            // Si el SKU existe en Central, usamos su estado. Si NO existe, forzamos estado 0
            $nuevoEstado = isset($productosCentral[$sku]) ? $productosCentral[$sku] : 0;
            
            $stmt = $mysqliWeb->prepare("UPDATE catproductos SET Estado = ? WHERE Sku = ?");
            $stmt->bind_param("is", $nuevoEstado, $sku);
            $stmt->execute();
            $actualizaciones += $stmt->affected_rows;
            $stmt->close();
        }
        $mysqliWeb->commit();
        echo json_encode(['success' => true, 'message' => "Sincronización completa. Filas actualizadas: $actualizaciones"]);
    } catch (Exception $e) {
        $mysqliWeb->rollback();
        echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
    }
    exit;
}

// 3. Consulta de datos (mismo bloque anterior)
$sqlCategorias = "SELECT c.CodCat, c.Nombre AS CategoriaNombre, cp.Sku, cp.Estado AS EstadoRelacion 
                  FROM categorias c LEFT JOIN catproductos cp ON c.CodCat = cp.CodCat 
                  WHERE c.Estado = '1' ORDER BY c.CodCat ASC";
$resultWeb = $mysqliWeb->query($sqlCategorias);

$categorias = []; $todosLosSkus = [];
while ($row = $resultWeb->fetch_assoc()) {
    $categorias[$row['CodCat']]['Nombre'] = $row['CategoriaNombre'];
    if (!empty($row['Sku'])) {
        $categorias[$row['CodCat']]['Skus_Asociados'][$row['Sku']] = ['estado_web' => $row['EstadoRelacion']];
        $todosLosSkus[] = $row['Sku'];
    }
}

$productosDetalle = [];
if (!empty($todosLosSkus)) {
    $listaIn = implode(',', array_map(fn($s) => "'" . $mysqliCentral->real_escape_string($s) . "'", array_unique($todosLosSkus)));
    $resultCentral = $mysqliCentral->query("SELECT barcode, descripcion, estado FROM productos WHERE barcode IN ($listaIn)");
    while ($p = $resultCentral->fetch_assoc()) {
        $productosDetalle[trim($p['barcode'])] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Estados</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: auto; }
        .controls { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
        select { padding: 10px; border-radius: 5px; border: 1px solid #ccc; flex-grow: 1; min-width: 200px; }
        button { padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .categoria-box { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 500px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-activo { background: #e8f8f5; color: #27ae60; border: 1px solid #27ae60; }
        .badge-inactivo { background: #fdf2f2; color: #e74c3c; border: 1px solid #e74c3c; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Gestión de Estados</h1>
        <div class="controls">
            <select id="filter" onchange="filtrar()">
                <option value="TODAS">-- Todas las Categorías --</option>
                <?php foreach ($categorias as $id => $c): ?>
                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($c['Nombre']); ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="sincronizar()">🔄 Sincronizar Todo</button>
        </div>

        <div id="lista">
            <?php foreach ($categorias as $id => $c): ?>
                <div class="categoria-box" data-id="<?php echo $id; ?>">
                    <h2><?php echo htmlspecialchars($c['Nombre']); ?></h2>
                    <table>
                        <thead><tr><th>SKU</th><th>Producto</th><th>POS</th><th>Web</th></tr></thead>
                        <tbody>
                            <?php foreach ($c['Skus_Asociados'] ?? [] as $sku => $data): $p = $productosDetalle[$sku] ?? null; ?>
                                <tr>
                                    <td><code><?php echo $sku; ?></code></td>
                                    <td><?php echo $p['descripcion'] ?? '<span style="color:red;">No existe en POS</span>'; ?></td>
                                    <td><span class="badge <?php echo ($p && $p['estado'] == 1) ? 'badge-activo' : 'badge-inactivo'; ?>"><?php echo ($p && $p['estado'] == 1) ? 'Activo' : 'Inactivo'; ?></span></td>
                                    <td><span class="badge <?php echo ($data['estado_web'] == 1) ? 'badge-activo' : 'badge-inactivo'; ?>"><?php echo ($data['estado_web'] == 1) ? 'Activo' : 'Inactivo'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
        function filtrar() {
            const val = document.getElementById('filter').value;
            document.querySelectorAll('.categoria-box').forEach(el => {
                el.style.display = (val === 'TODAS' || el.dataset.id === val) ? 'block' : 'none';
            });
        }
        function sincronizar() {
            if (!confirm('Esto inactivará los productos no encontrados en POS. ¿Proceder?')) return;
            fetch('?accion=sincronizar').then(res => res.json()).then(d => { alert(d.message); location.reload(); });
        }
    </script>
</body>
</html>