<?php
require_once("ConnCentral.php");    
require_once("ConnDrinks.php");    
require_once("Conexion.php");      

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $bc   = $_POST['barcode'] ?? '';
    $sede = $_POST['sede'] ?? 'Central';
    $db   = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;

    $response = ['success' => false];

    switch ($_POST['action']) {
        case 'update_stock':
            $nueva_cant = floatval($_POST['cantidad']);
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("SELECT idproducto FROM productos WHERE barcode = ? LIMIT 1");
                $stmt->bind_param("s", $bc);
                $stmt->execute();
                $res = $stmt->get_result();
                
                if ($r = $res->fetch_assoc()) {
                    $idp = $r['idproducto'];
                    $stmt_up = $db->prepare("UPDATE inventario SET cantidad = ? WHERE idproducto = ?");
                    $stmt_up->bind_param("di", $nueva_cant, $idp);
                    $stmt_up->execute();
                    
                    $db->commit();
                    $response['success'] = true;
                } else {
                    $db->rollback();
                }
            } catch (Exception $e) {
                $db->rollback();
            }
            break;

        case 'toggle_status':
            $nuevo_estado = intval($_POST['estado']);
            $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
            $stmt->bind_param("is", $nuevo_estado, $bc);
            $response['success'] = $stmt->execute();
            break;

        case 'update_name':
            $nombre = trim($_POST['nombre']);
            $stmtC = $mysqliCentral->prepare("UPDATE productos SET descripcion = ? WHERE barcode = ?");
            $stmtC->bind_param("ss", $nombre, $bc);
            $res1 = $stmtC->execute();

            $stmtD = $mysqliDrinks->prepare("UPDATE productos SET descripcion = ? WHERE barcode = ?");
            $stmtD->bind_param("ss", $nombre, $bc);
            $res2 = $stmtD->execute();

            $response['success'] = ($res1 && $res2);
            break;

        case 'update_price':
            $nuevo_precio = floatval($_POST['precio']);
            
            $mysqliCentral->begin_transaction();
            $mysqliDrinks->begin_transaction();
            try {
                $stmt1 = $mysqliCentral->prepare("UPDATE productos SET precioventa = ? WHERE barcode = ?");
                $stmt1->bind_param("ds", $nuevo_precio, $bc);
                $stmt1->execute();

                $stmt2 = $mysqliDrinks->prepare("UPDATE productos SET precioventa = ? WHERE barcode = ?");
                $stmt2->bind_param("ds", $nuevo_precio, $bc);
                $stmt2->execute();

                $mysqliCentral->commit();
                $mysqliDrinks->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $mysqliCentral->rollback();
                $mysqliDrinks->rollback();
            }
            break;

        case 'zero_stock_retired':
            $mysqliCentral->begin_transaction();
            $mysqliDrinks->begin_transaction();
            try {
                $resC = $mysqliCentral->query("SELECT idproducto FROM productos WHERE estado = 0");
                while ($row = $resC->fetch_assoc()) {
                    $idp = intval($row['idproducto']);
                    $mysqliCentral->query("UPDATE inventario SET cantidad = 0 WHERE idproducto = $idp");
                }

                $resD = $mysqliDrinks->query("SELECT idproducto FROM productos WHERE estado = 0");
                while ($row = $resD->fetch_assoc()) {
                    $idp = intval($row['idproducto']);
                    $mysqliDrinks->query("UPDATE inventario SET cantidad = 0 WHERE idproducto = $idp");
                }

                $mysqliCentral->commit();
                $mysqliDrinks->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $mysqliCentral->rollback();
                $mysqliDrinks->rollback();
            }
            break;
    }

    echo json_encode($response);
    exit;
}

/* --- LOGICA PRECIO PROMEDIO DE COMPRA (Últimas 3 compras) --- */
function precioPromCompra($mysqli){
    $sql = "SELECT P.Barcode, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni, C.idcompra, C.FECHA
            FROM compras C
            JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
            JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
            WHERE C.ESTADO = '0'
            ORDER BY P.Barcode, C.FECHA DESC, C.idcompra DESC";
    $r = $mysqli->query($sql);
    $comprasPorProd = [];
    while($r && $row = $r->fetch_assoc()){
        $bc = $row['Barcode'];
        if(!isset($comprasPorProd[$bc])) {
            $comprasPorProd[$bc] = [];
        }
        if(!isset($comprasPorProd[$bc][$row['idcompra']]) && count($comprasPorProd[$bc]) < 3){
            $comprasPorProd[$bc][$row['idcompra']] = [];
        }
        if(isset($comprasPorProd[$bc][$row['idcompra']])){
            $comprasPorProd[$bc][$row['idcompra']][] = $row;
        }
    }

    $out = [];
    foreach($comprasPorProd as $bc => $compras){
        $acumuladoCostoPonderado = 0;
        $cantidadTotal = 0;
        foreach($compras as $idcompra => $items){
            foreach($items as $row){
                $cant = (double)$row['CANTIDAD'];
                if ($cant <= 0) continue;
                $net = ($row['VALOR'] - ($row['descuento'] / $cant));
                $costoBruto = $net + ($net * (double)$row['porciva'] / 100) + (double)$row['ValICUIUni'];
                $acumuladoCostoPonderado += ($costoBruto * $cant);
                $cantidadTotal += $cant;
            }
        }
        $out[$bc] = ($cantidadTotal > 0) ? ($acumuladoCostoPonderado / $cantidadTotal) : 0;
    }
    return $out;
}

$pcC = isset($mysqliCentral) ? precioPromCompra($mysqliCentral) : [];
$pcD = isset($mysqliDrinks) ? precioPromCompra($mysqliDrinks) : [];

$term = $_GET['term'] ?? '';
$like = "%$term%";

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
        body{ font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding: 10px; }
        .container{ width: 100%; box-sizing: border-box; margin: auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .filter-container { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #1a2a6c; color: white; padding: 12px; font-size: 13px; }
        td { border-bottom: 1px solid #eee; padding: 8px; text-align: center; }
        .row-drinks { background: #fffcf5; }
        .row-central { background: #f5f9ff; }
        .product-header { background: #f8f9fa; font-weight: bold; text-align: left !important; border-top: 2px solid #ddd; }
        .stock-input { width: 80px; padding: 5px; border-radius: 4px; border: 1px solid #ccc; text-align: center; font-weight: bold; transition: background 0.3s; }
        .stock-input:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 5px rgba(37, 99, 235, 0.4); }
        .price-input { width: 90px; padding: 5px; border-radius: 4px; border: 1px solid #ccc; text-align: center; font-weight: bold; color: #166534; background: #f0fdf4; }
        .price-input:focus { background: #fff; border-color: #22c55e; outline: none; box-shadow: 0 0 5px rgba(34, 197, 94, 0.4); }
        .compra-val { font-weight: bold; color: #b45309; background: #fef3c7; padding: 5px 10px; border-radius: 4px; display: inline-block; font-size: 13px; }
        .variacion-val { font-weight: bold; padding: 5px 10px; border-radius: 4px; display: inline-block; font-size: 13px; }
        .var-pos { color: #166534; background: #dcfce7; }
        .var-neg { color: #991b1b; background: #fee2e2; }
        .btn-masive { background: #d97706; color: white; border: none; padding: 12px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 5px; }
        .btn-masive:hover { background: #b45309; }
        .switch { position: relative; display: inline-block; width: 34px; height: 18px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
        .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:checked + .slider:before { transform: translateX(16px); }
        .badge-sede { font-size: 10px; padding: 3px 6px; border-radius: 4px; color: white; font-weight: bold; text-transform: uppercase; }
        .bg-drinks { background: #d97706; }
        .bg-central { background: #2563eb; }
        #filtro { flex: 2; min-width: 200px; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; }
        .select-filtro { flex: 1; min-width: 140px; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; background: white; cursor: pointer; }
        .total-row { background: #e2e8f0; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h2>🛠️ Panel de Inventario con Precios y Variación</h2>
    
    <div class="filter-container">
        <input type="text" id="filtro" placeholder="🔍 Buscar por nombre o código..." onkeyup="filtrar()">
        
        <select id="filtro-sede" class="select-filtro" onchange="filtrar()">
            <option value="todos">🏢 Todas las sedes</option>
            <option value="central">🔹 Central</option>
            <option value="drinks">🔸 Drinks</option>
        </select>

        <select id="filtro-estado" class="select-filtro" onchange="filtrar()">
            <option value="todos">⚡ Todos los estados</option>
            <option value="1" selected>🟢 Activos</option>
            <option value="0">🔴 Retirados</option>
        </select>

        <button class="btn-masive" onclick="setZeroStockRetiredMasive()" title="Poner el stock en 0 a todos los productos inactivos/retirados">
            ⚠️ Stock 0 a Retirados Masivo
        </button>
    </div>

    <table>
        <thead>
            <tr>
                <th style="text-align:left">Producto / Sede</th>
                <th>Estado</th>
                <th>Precio Venta</th>
                <th>Precio Prom. Compra (Últ. 3)</th>
                <th>% Variación (Compra vs Venta)</th>
                <th>Stock</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($barcodes as $b): 
                $d = $drinks[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---', 'precioventa'=>0];
                $c = $central[$b] ?? ['cantidad'=>0, 'estado'=>0, 'descripcion'=>'---', 'precioventa'=>0];
                $desc = ($c['descripcion'] !== '---') ? $c['descripcion'] : $d['descripcion'];
                $pv = ($c['precioventa'] != 0) ? $c['precioventa'] : $d['precioventa'];
                
                // Promedios de compra por sede (últimas 3 compras)
                $compraDrinks = $pcD[$b] ?? 0;
                $compraCentral = $pcC[$b] ?? 0;

                // Cálculo de % de variación: ((Precio Venta - Costo Compra) / Costo Compra) * 100
                $varDrinks = ($compraDrinks > 0) ? (($pv - $compraDrinks) / $compraDrinks) * 100 : 0;
                $varCentral = ($compraCentral > 0) ? (($pv - $compraCentral) / $compraCentral) * 100 : 0;
            ?>
            <tr class="product-header" data-barcode="<?= $b ?>">
                <td colspan="6" style="text-align:left;">
                    <span style="color:#666; font-size: 12px; margin-right: 10px;">[<?= $b ?>]</span>
                    <input type="text" class="nombre-producto" value="<?= htmlspecialchars($desc) ?>" style="border:none; background:transparent; width: 70%; font-weight:bold; font-size: 14px;" onblur="updateName('<?= $b ?>', this.value)">
                </td>
            </tr>
            <tr class="row-drinks" data-sede="drinks" data-barcode="<?= $b ?>" data-estado="<?= $d['estado'] ?>">
                <td style="text-align:left; padding-left: 30px;"><span class="badge-sede bg-drinks">Drinks</span></td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Drinks', this); updateRowState(this, 'drinks', '<?= $b ?>')" <?= $d['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>
                    <input type="number" step="any" class="price-input" id="price-drinks-<?= $b ?>" value="<?= $pv ?>" oninput="syncPrices('<?= $b ?>', this.value)" onblur="updatePrice('<?= $b ?>', this.value)" title="Modificar precio de venta de forma dinámica">
                </td>
                <td>
                    <span class="compra-val">$<?= number_format($compraDrinks, 0, ',', '.') ?></span>
                </td>
                <td>
                    <span class="variacion-val <?= ($varDrinks >= 0) ? 'var-pos' : 'var-neg' ?>">
                        <?= ($varDrinks > 0 ? '+' : '') . number_format($varDrinks, 2, ',', '.') ?>%
                    </span>
                </td>
                <td>
                    <input type="number" step="any" class="stock-input stock-val" id="input-drinks-<?= $b ?>" value="<?= $d['cantidad'] ?>" oninput="filtrar()" onblur="updateStockDynamic('<?= $b ?>', 'Drinks', this.value)">
                </td>
            </tr>
            <tr class="row-central" data-sede="central" data-barcode="<?= $b ?>" data-estado="<?= $c['estado'] ?>">
                <td style="text-align:left; padding-left: 30px;"><span class="badge-sede bg-central">Central</span></td>
                <td>
                    <label class="switch">
                        <input type="checkbox" onchange="toggleStatus('<?= $b ?>', 'Central', this); updateRowState(this, 'central', '<?= $b ?>')" <?= $c['estado']==1?'checked':'' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>
                    <input type="number" step="any" class="price-input" id="price-central-<?= $b ?>" value="<?= $pv ?>" oninput="syncPrices('<?= $b ?>', this.value)" onblur="updatePrice('<?= $b ?>', this.value)" title="Modificar precio de venta de forma dinámica">
                </td>
                <td>
                    <span class="compra-val">$<?= number_format($compraCentral, 0, ',', '.') ?></span>
                </td>
                <td>
                    <span class="variacion-val <?= ($varCentral >= 0) ? 'var-pos' : 'var-neg' ?>">
                        <?= ($varCentral > 0 ? '+' : '') . number_format($varCentral, 2, ',', '.') ?>%
                    </span>
                </td>
                <td>
                    <input type="number" step="any" class="stock-input stock-val" id="input-central-<?= $b ?>" value="<?= $c['cantidad'] ?>" oninput="filtrar()" onblur="updateStockDynamic('<?= $b ?>', 'Central', this.value)">
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align: right; padding-right: 15px;">TOTAL STOCK VISIBLE:</td>
                <td id="total-stock" style="text-align: center; font-size: 15px; color: #1a2a6c;">0</td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
function filtrar() {
    const term = document.getElementById('filtro').value.toLowerCase();
    const sedeSeleccionada = document.getElementById('filtro-sede').value;
    const estadoSeleccionado = document.getElementById('filtro-estado').value;
    const headers = document.querySelectorAll('.product-header');

    let stockTotal = 0;

    headers.forEach(header => {
        const barcode = header.getAttribute('data-barcode');
        const codigo = header.querySelector('span').textContent.toLowerCase();
        const inputNombre = header.querySelector('.nombre-producto');
        const nombre = inputNombre ? inputNombre.value.toLowerCase() : "";
        
        const coincideTexto = codigo.includes(term) || nombre.includes(term);

        const rowDrinks = document.querySelector(`.row-drinks[data-barcode="${barcode}"]`);
        const rowCentral = document.querySelector(`.row-central[data-barcode="${barcode}"]`);

        let mostrarDrinksSede = (sedeSeleccionada === 'todos' || sedeSeleccionada === 'drinks');
        let mostrarCentralSede = (sedeSeleccionada === 'todos' || sedeSeleccionada === 'central');

        const estadoDrinks = rowDrinks.getAttribute('data-estado');
        const estadoCentral = rowCentral.getAttribute('data-estado');

        let mostrarDrinksEstado = (estadoSeleccionado === 'todos' || estadoDrinks === estadoSeleccionado);
        let mostrarCentralEstado = (estadoSeleccionado === 'todos' || estadoCentral === estadoSeleccionado);

        const mostrarFilaDrinks = coincideTexto && mostrarDrinksSede && mostrarDrinksEstado;
        const mostrarFilaCentral = coincideTexto && mostrarCentralSede && mostrarCentralEstado;

        if (rowDrinks) {
            rowDrinks.style.display = mostrarFilaDrinks ? '' : 'none';
            if (mostrarFilaDrinks) {
                stockTotal += parseFloat(rowDrinks.querySelector('.stock-val').value) || 0;
            }
        }

        if (rowCentral) {
            rowCentral.style.display = mostrarFilaCentral ? '' : 'none';
            if (mostrarFilaCentral) {
                stockTotal += parseFloat(rowCentral.querySelector('.stock-val').value) || 0;
            }
        }

        if (mostrarFilaDrinks || mostrarFilaCentral) {
            header.style.display = '';
        } else {
            header.style.display = 'none';
        }
    });

    document.getElementById('total-stock').textContent = stockTotal.toLocaleString('es-CO', {maximumFractionDigits: 2});
}

function updateRowState(checkbox, sede, barcode) {
    const row = document.querySelector(`.row-${sede}[data-barcode="${barcode}"]`);
    if (row) {
        row.setAttribute('data-estado', checkbox.checked ? '1' : '0');
        filtrar(); 
    }
}

function updateName(b, v) { 
    fetch('', {
        method:'POST', 
        body:new URLSearchParams({action:'update_name', barcode:b, nombre:v})
    }).then(res => res.json()).then(data => {
        if(!data.success) alert('Error al actualizar el nombre del producto.');
    });
}

function syncPrices(b, v) {
    const pDrinks = document.getElementById(`price-drinks-${b}`);
    const pCentral = document.getElementById(`price-central-${b}`);
    if (pDrinks) pDrinks.value = v;
    if (pCentral) pCentral.value = v;
}

function updatePrice(b, v) { 
    fetch('', {
        method: 'POST', 
        body: new URLSearchParams({action: 'update_price', barcode: b, precio: v})
    }).then(response => response.json()).then(data => {
        if (!data.success) {
            alert('Error al actualizar el precio en el servidor.');
        }
    });
}

function updateStockDynamic(b, s, v) {
    fetch('', {
        method: 'POST', 
        body: new URLSearchParams({action: 'update_stock', barcode: b, sede: s, cantidad: v})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const inputField = document.getElementById(`input-${s.toLowerCase()}-${b}`);
            inputField.style.backgroundColor = '#dcfce7';
            setTimeout(() => { inputField.style.backgroundColor = ''; }, 600);
            filtrar();
        } else {
            alert('Error al actualizar el stock.');
        }
    })
    .catch(error => {
        console.error("Error de red:", error);
    });
}

function setZeroStockRetiredMasive() {
    if (confirm("⚠️ ¿Estás seguro de poner el stock en 0 a TODOS los productos que se encuentran con estado 'Retirado' en ambas sedes?")) {
        fetch('', {
            method: 'POST',
            body: new URLSearchParams({ action: 'zero_stock_retired' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("¡Stock actualizado masivamente a 0 para todos los productos retirados!");
                location.reload();
            } else {
                alert("Hubo un error al ejecutar la acción masiva.");
            }
        })
        .catch(error => {
            console.error("Error:", error);
            alert("No se pudo conectar con el servidor.");
        });
    }
}

function toggleStatus(b, s, e) { 
    fetch('', {
        method:'POST', 
        body:new URLSearchParams({action:'toggle_status', barcode:b, sede:s, estado:e.checked?1:0})
    }).then(res => res.json()).then(data => {
        if(!data.success) alert('Error al cambiar el estado.');
    });
}

window.onload = function() {
    filtrar();
};
</script>
</body>
</html>