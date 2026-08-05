<?php
require_once("ConnCentral.php");    
require_once("ConnDrinks.php");    
require_once("Conexion.php");      

$mensaje = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedulaNit      = trim($_POST['CedulaNit'] ?? '');
    $nitEmpresa     = trim($_POST['NitEmpresa'] ?? '');
    $periodo        = $_POST['periodo'] ?? '';
    $estado         = isset($_POST['estado']) ? 1 : 0; 
    
    // Limpiamos los puntos de miles antes de guardar en la BD
    $metaValorTotal = floatval(str_replace('.', '', $_POST['meta_valor_total'] ?? '0'));
    
    if (!empty($periodo)) {
        $partes = explode('-', $periodo);
        $aa = intval($partes[0]);
        $mm = intval($partes[1]);
    } else {
        $aa = intval(date('Y'));
        $mm = intval(date('n'));
    }

    $skus       = $_POST['Sku'] ?? [];
    $metasCajas = $_POST['meta_cajas'] ?? [];

    if (!empty($cedulaNit) && !empty($nitEmpresa) && $mm > 0 && $aa > 0 && !empty($skus)) {
        
        $mysqliWeb->begin_transaction();

        try {
            $sqlCab = "INSERT INTO objetivos_cajeros_cab (CedulaNit, NitEmpresa, mm, aa, meta_valor_total, estado) 
                       VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE meta_valor_total = VALUES(meta_valor_total), estado = VALUES(estado), id_cabecera = LAST_INSERT_ID(id_cabecera)";
            
            $stmtCab = $mysqliWeb->prepare($sqlCab);
            $stmtCab->bind_param("ssiidi", $cedulaNit, $nitEmpresa, $mm, $aa, $metaValorTotal, $estado);
            $stmtCab->execute();
            
            $idCabecera = $mysqliWeb->insert_id;
            if ($idCabecera == 0) {
                $stmtId = $mysqliWeb->prepare("SELECT id_cabecera FROM objetivos_cajeros_cab WHERE CedulaNit = ? AND NitEmpresa = ? AND mm = ? AND aa = ?");
                $stmtId->bind_param("ssii", $cedulaNit, $nitEmpresa, $mm, $aa);
                $stmtId->execute();
                $resId = $stmtId->get_result();
                if ($rowId = $resId->fetch_assoc()) {
                    $idCabecera = $rowId['id_cabecera'];
                }
                $stmtId->close();
            }
            $stmtCab->close();

            $sqlDet = "INSERT INTO objetivos_cajeros_det (id_cabecera, Sku, meta_cajas) 
                       VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE meta_cajas = VALUES(meta_cajas)";
            
            $stmtDet = $mysqliWeb->prepare($sqlDet);

            for ($i = 0; $i < count($skus); $i++) {
                $sku = trim($skus[$i]);
                $mCajas = floatval($metasCajas[$i]);

                if (!empty($sku)) {
                    $stmtDet->bind_param("isd", $idCabecera, $sku, $mCajas);
                    $stmtDet->execute();
                }
            }
            $stmtDet->close();

            $mysqliWeb->commit();
            $mensaje = "<p style='color: green;'>✅ Objetivo general y de productos guardados correctamente.</p>";

        } catch (Exception $e) {
            $mysqliWeb->rollback();
            $mensaje = "<p style='color: red;'>❌ Error al guardar: " . $e->getMessage() . "</p>";
        }

    } else {
        $mensaje = "<p style='color: orange;'>⚠️ Por favor completa los campos principales y añade al menos un producto.</p>";
    }
}

// Cargar productos, cajeros y empresas para los selects
$productosCentral = [];
$mapaProductos = [];
if (isset($mysqliCentral)) {
    $res = $mysqliCentral->query("SELECT barcode, descripcion FROM productos WHERE estado = 1 ORDER BY descripcion ASC");
    while ($row = $res->fetch_assoc()) { 
        $row['descripcion'] = html_entity_decode($row['descripcion'], ENT_QUOTES, 'UTF-8');
        $productosCentral[] = $row; 
        $mapaProductos[$row['barcode']] = $row['descripcion'];
    }
}

$tercerosCajeros = [];
if (isset($mysqliWeb)) {
    $resT = $mysqliWeb->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre ASC");
    while ($rowT = $resT->fetch_assoc()) { 
        $tercerosCajeros[] = $rowT; 
    }
}

$empresasLista = [];
if (isset($mysqliWeb)) {
    $resE = $mysqliWeb->query("SELECT Nit, RazonSocial FROM empresa WHERE Estado = 1 ORDER BY RazonSocial ASC");
    while ($rowE = $resE->fetch_assoc()) { 
        $empresasLista[] = $rowE; 
    }
}

// Consultar todos los objetivos registrados para JS
$todosObjetivos = [];
if (isset($mysqliWeb)) {
    $sqlList = "SELECT c.id_cabecera, c.CedulaNit, c.NitEmpresa, c.mm, c.aa, c.meta_valor_total, c.estado,
                        d.Sku, d.meta_cajas 
                FROM objetivos_cajeros_cab c 
                LEFT JOIN objetivos_cajeros_det d ON c.id_cabecera = d.id_cabecera 
                ORDER BY c.aa DESC, c.mm DESC, c.id_cabecera DESC";
    $resObj = $mysqliWeb->query($sqlList);
    while($r = $resObj->fetch_assoc()){
        $id = $r['id_cabecera'];
        if(!isset($todosObjetivos[$id])){
            $todosObjetivos[$id] = [
                'cedula_nit' => trim($r['CedulaNit']),
                'nit_empresa' => trim($r['NitEmpresa']),
                'periodo_input' => sprintf("%04d-%02d", $r['aa'], $r['mm']),
                'meta_valor' => $r['meta_valor_total'],
                'estado' => isset($r['estado']) ? (int)$r['estado'] : 1,
                'detalles' => []
            ];
        }
        if(!empty($r['Sku'])){
            $todosObjetivos[$id]['detalles'][] = [
                'sku' => $r['Sku'],
                'meta_cajas' => $r['meta_cajas']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar Objetivos por Cajero</title>
    <!-- Incluir estilos de Tom Select -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background-color: #f4f7f6; 
            width: 100%;
            min-height: 100vh;
        }
        .form-container { 
            background: #fff; 
            padding: 25px; 
            border-radius: 12px; 
            width: 100%; 
            max-width: 100%; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1); 
            margin: 0 auto; 
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        
        .form-row { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 15px; 
        }
        .form-row .form-group { 
            flex: 1; 
            min-width: 280px; 
        }

        /* Estilo Switch Dinámico con Etiquetas de Texto Integradas */
        .switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 15px;
            border-radius: 6px;
            height: 43px;
            margin-top: 28px;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 75px;
            height: 26px;
        }
        .switch input { 
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ef4444; /* Rojo por defecto (Inactivo) */
            transition: background-color 0.3s ease;
            border-radius: 26px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: transform 0.3s ease;
            border-radius: 50%;
            z-index: 2;
        }
        input:checked + .slider {
            background-color: #10b981; /* Verde cuando está Activo */
        }
        input:checked + .slider:before {
            transform: translateX(49px);
        }
        
        /* Textos dinámicos en el interior del switch */
        .slider-text-on, .slider-text-off {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 9px;
            font-weight: bold;
            color: white;
            z-index: 1;
            transition: opacity 0.3s ease;
            user-select: none;
        }
        .slider-text-on {
            left: 8px;
            opacity: 0; /* Oculto cuando está apagado */
        }
        .slider-text-off {
            right: 8px;
            opacity: 1; /* Visible cuando está apagado */
        }
        
        /* Cambios al activar el checkbox */
        input:checked ~ .slider-text-on {
            opacity: 1;
        }
        input:checked ~ .slider-text-off {
            opacity: 0;
        }

        .producto-row { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
            margin-bottom: 10px; 
            align-items: center; 
            background: #f9f9f9; 
            padding: 10px; 
            border-radius: 6px; 
            border: 1px solid #e2e8f0; 
        }
        .producto-row > div:nth-child(1) { flex: 3; min-width: 250px; }
        .producto-row > div:nth-child(2) { flex: 2; min-width: 150px; }
        .producto-row > div:nth-child(3) { flex: 0; }

        .btn-add { background: #10b981; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-bottom: 15px; width: 100%; }
        .btn-add:hover { background: #059669; }
        
        .btn-del { background: #ef4444; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; height: 38px; width: 100%; }
        .btn-del:hover { background: #dc2626; }

        button[type="submit"] { background: #1a2a6c; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; font-size: 16px; margin-top: 15px; }
        button[type="submit"]:hover { background: #0f1c4d; }

        .ts-wrapper { width: 100% !important; }

        @media (max-width: 600px) {
            body { padding: 10px; }
            .form-container { padding: 15px; }
            .producto-row { flex-direction: column; align-items: stretch; }
            .producto-row > div { width: 100% !important; }
            .btn-del { width: 100%; }
        }
    </style>
</head>
<body>

<div class="form-container">
    <h2>🎯 Registrar Objetivo del Cajero</h2>
    <?php echo $mensaje; ?>

    <form action="" method="POST" onsubmit="prepararEnvio()">
        <div class="form-row">
            <div class="form-group">
                <label for="CedulaNit">Cajero (Terceros):</label>
                <select id="CedulaNit" name="CedulaNit" required>
                    <option value="">-- Seleccione un cajero --</option>
                    <?php foreach ($tercerosCajeros as $tercero): ?>
                        <option value="<?= htmlspecialchars($tercero['CedulaNit']) ?>"><?= htmlspecialchars($tercero['Nombre']) ?> (NIT: <?= $tercero['CedulaNit'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="NitEmpresa">Empresa:</label>
                <select id="NitEmpresa" name="NitEmpresa" required>
                    <option value="">-- Seleccione una empresa --</option>
                    <?php foreach ($empresasLista as $emp): ?>
                        <option value="<?= htmlspecialchars($emp['Nit']) ?>"><?= htmlspecialchars($emp['RazonSocial']) ?> (NIT: <?= $emp['Nit'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="periodo">Mes y Año:</label>
                <input type="month" id="periodo" name="periodo" required value="<?= date('Y-m'); ?>" onchange="verificarObjetivoExistente()">
            </div>

            <div class="form-group">
                <label for="meta_valor_total">Meta de Valor Fija ($) del Cajero:</label>
                <input type="text" id="meta_valor_total" name="meta_valor_total" required value="0" onkeyup="formatearMiles(this)">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <div class="switch-container">
                    <label for="estado" style="margin:0; cursor:pointer;">Estado del Objetivo:</label>
                    <label class="switch">
                        <input type="checkbox" id="estado" name="estado" value="1" checked>
                        <span class="slider"></span>
                        <span class="slider-text-on">ACTIVO</span>
                        <span class="slider-text-off">INACTIVO</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <!-- Espaciador visual para mantener simetría -->
            </div>
        </div>

        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
        
        <label style="font-weight: bold; margin-bottom: 10px; display: block;">Productos Objetivo (SKUs y sus Metas en Cajas):</label>
        
        <div id="productos-container">
            <div class="producto-row">
                <div>
                    <select name="Sku[]" class="sku-select" required>
                        <option value="">-- Seleccione producto --</option>
                        <?php foreach ($productosCentral as $prod): ?>
                            <option value="<?= htmlspecialchars($prod['barcode']) ?>"><?= htmlspecialchars($prod['descripcion']) ?> (<?= $prod['barcode'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="number" step="0.01" name="meta_cajas[]" placeholder="Meta Cajas" required value="0.00" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc; height: 38px; box-sizing: border-box;">
                </div>
                <div>
                    <button type="button" class="btn-del" onclick="eliminarFila(this)" disabled>❌ Eliminar</button>
                </div>
            </div>
        </div>

        <button type="button" class="btn-add" onclick="agregarFila()">➕ Añadir otro producto</button>

        <button type="submit">Guardar Objetivo</button>
    </form>
</div>

<!-- Script de Tom Select -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
const objetivosRegistrados = <?= json_encode(array_values($todosObjetivos)) ?>;
const productosDisponibles = <?= json_encode($productosCentral) ?>;

let tsCajero = new TomSelect("#CedulaNit", { 
    create: false, 
    sortField: { field: "text", direction: "asc" },
    maxOptions: null, 
    onChange: function(value) { verificarObjetivoExistente(); }
});

let tsEmpresa = new TomSelect("#NitEmpresa", { 
    create: false, 
    sortField: { field: "text", direction: "asc" },
    maxOptions: null, 
    onChange: function(value) { verificarObjetivoExistente(); }
});

function inicializarTomSelectSku(element) {
    return new TomSelect(element, {
        create: false,
        sortField: { field: "text", direction: "asc" },
        maxOptions: null, 
        placeholder: "-- Seleccione o busque producto --"
    });
}

document.querySelectorAll('.sku-select').forEach((el) => {
    inicializarTomSelectSku(el);
});

function obtenerOpcionesHTML(seleccionado = '') {
    let html = '<option value="">-- Seleccione producto --</option>';
    productosDisponibles.forEach(prod => {
        let selected = (String(prod.barcode) === String(seleccionado)) ? 'selected' : '';
        html += `<option value="${prod.barcode}" ${selected}>${prod.descripcion} (${prod.barcode})</option>`;
    });
    return html;
}

function verificarObjetivoExistente() {
    let cedula = document.getElementById('CedulaNit').value.trim();
    let empresa = document.getElementById('NitEmpresa').value.trim();
    let periodo = document.getElementById('periodo').value;

    if (!cedula || !empresa || !periodo) return;

    let encontrado = objetivosRegistrados.find(obj => 
        String(obj.cedula_nit) === String(cedula) && 
        String(obj.nit_empresa) === String(empresa) && 
        obj.periodo_input === periodo
    );

    let inputValor = document.getElementById('meta_valor_total');
    let inputEstado = document.getElementById('estado');

    if (encontrado) {
        inputValor.value = Number(encontrado.meta_valor).toLocaleString("es-CO");
        inputEstado.checked = (encontrado.estado === 1);

        if (encontrado.detalles && encontrado.detalles.length > 0) {
            let container = document.getElementById('productos-container');
            container.innerHTML = ''; 

            encontrado.detalles.forEach((det) => {
                const nuevaFila = document.createElement('div');
                nuevaFila.className = 'producto-row';
                nuevaFila.innerHTML = `
                    <div>
                        <select name="Sku[]" class="sku-select" required>
                            ${obtenerOpcionesHTML(det.sku)}
                        </select>
                    </div>
                    <div>
                        <input type="number" step="0.01" name="meta_cajas[]" placeholder="Meta Cajas" required value="${det.meta_cajas}" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc; height: 38px; box-sizing: border-box;">
                    </div>
                    <div>
                        <button type="button" class="btn-del" onclick="eliminarFila(this)">❌ Eliminar</button>
                    </div>
                `;
                container.appendChild(nuevaFila);

                let nuevoSelect = nuevaFila.querySelector('.sku-select');
                inicializarTomSelectSku(nuevoSelect);
            });
            actualizarBotonesEliminar();
        }
    } else {
        inputValor.value = "0";
        inputEstado.checked = true; // Por defecto activo para nuevos registros
    }
}

function formatearMiles(input) {
    let valor = input.value.replace(/\D/g, "");
    if (valor === "") {
        input.value = "0";
        return;
    }
    input.value = Number(valor).toLocaleString("es-CO");
}

function prepararEnvio() {
    let input = document.getElementById('meta_valor_total');
    input.value = input.value.replace(/\./g, '');
}

function agregarFila() {
    const container = document.getElementById('productos-container');
    const nuevaFila = document.createElement('div');
    nuevaFila.className = 'producto-row';
    nuevaFila.innerHTML = `
        <div>
            <select name="Sku[]" class="sku-select" required>
                ${obtenerOpcionesHTML()}
            </select>
        </div>
        <div>
            <input type="number" step="0.01" name="meta_cajas[]" placeholder="Meta Cajas" required value="0.00" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc; height: 38px; box-sizing: border-box;">
        </div>
        <div>
            <button type="button" class="btn-del" onclick="eliminarFila(this)">❌ Eliminar</button>
        </div>
    `;
    container.appendChild(nuevaFila);
    
    let nuevoSelect = nuevaFila.querySelector('.sku-select');
    inicializarTomSelectSku(nuevoSelect);

    actualizarBotonesEliminar();
}

function eliminarFila(boton) {
    boton.closest('.producto-row').remove();
    actualizarBotonesEliminar();
}

function actualizarBotonesEliminar() {
    const filas = document.querySelectorAll('.producto-row');
    filas.forEach((fila) => {
        const btnDel = fila.querySelector('.btn-del');
        btnDel.disabled = (filas.length === 1);
    });
}
</script>

</body>
</html>