<?php
// ====================================================================
// 1. CONEXIONES
// ====================================================================
require 'ConnCentral.php'; // $mysqliCentral
require 'Conexion.php';    // $mysqli

$dbProd = $mysqliCentral;
$dbRel  = $mysqli;

$is_ajax = isset($_POST['action']);

// ====================================================================
// 2. ACCIÓN: OBTENER PRODUCTOS HUÉRFANOS (SOLO ACTIVOS)
// ====================================================================
if ($is_ajax && $_POST['action'] === 'get_orphans') {
    header('Content-Type: application/json');

    // 1. Obtener SKUs ya relacionados para excluirlos
    $res = $dbRel->query("SELECT Sku FROM catproductos");
    $asignados = [];
    while($row = $res->fetch_assoc()) { $asignados[] = "'".$row['Sku']."'"; }
    
    $not_in = !empty($asignados) ? implode(",", $asignados) : "'0'";

    // 2. Buscar en Central productos que NO están en catproductos Y ESTÁN ACTIVOS
    $sql = "SELECT barcode, descripcion FROM productos WHERE barcode NOT IN ($not_in) AND estado = 1 LIMIT 100";
    $resP = $dbProd->query($sql);
    
    $html = "";

    while($p = $resP->fetch_assoc()) {
        $html .= "<tr>
                    <td>{$p['barcode']}</td>
                    <td align='left'>".htmlspecialchars($p['descripcion'])."</td>
                    <td><button class='btn-add-orphan' data-bc='{$p['barcode']}'>➕ Asignar</button></td>
                  </tr>";
    }
    echo json_encode(['html' => $html ?: "<tr><td colspan='3'>No hay productos activos sin categoría</td></tr>"]);
    exit;
}

// ====================================================================
// 3. ACCIÓN: GUARDAR RELACIÓN
// ====================================================================
if ($is_ajax && $_POST['action'] === 'save_rel') {
    header('Content-Type: application/json');
    $sku = $_POST['barcode'];
    $codCat = $_POST['codCat'];

    if(empty($codCat)) {
        echo json_encode(['success' => false, 'msg' => 'Elija categoría']);
        exit;
    }

    $sql = "INSERT INTO catproductos (CodCat, Sku, Estado) VALUES (?, ?, '1') 
            ON DUPLICATE KEY UPDATE CodCat = ?, Estado = '1'";
    $stmt = $dbRel->prepare($sql);
    $stmt->bind_param("sss", $codCat, $sku, $codCat);
    $success = $stmt->execute();
    echo json_encode(['success' => $success, 'msg' => $success ? 'OK' : 'Error']);
    exit;
}

// ====================================================================
// 4. CARGAR CATEGORÍAS (Para los Selects)
// ====================================================================
$cat_options = "<option value=''>-- Seleccionar --</option>";
$resC = $dbRel->query("SELECT CodCat, Nombre FROM categorias WHERE Estado = '1' ORDER BY Nombre ASC");
while($c = $resC->fetch_assoc()){ 
    $cat_options .= "<option value='{$c['CodCat']}'>".htmlspecialchars($c['Nombre'])."</option>"; 
}

// ====================================================================
// 5. FILTRO DINÁMICO MEJORADO (BUSCA POR CODCAT, BARCODE Y NOMBRE)
// ====================================================================
if ($is_ajax && $_POST['action'] === 'filter') {
    header('Content-Type: application/json');
    $q = "%".$_POST['buscar']."%";

    // A. Buscar SKUs que coincidan con el CodCat ingresado
    $skus_por_cat = [];
    $stmtC = $dbRel->prepare("SELECT Sku FROM catproductos WHERE CodCat LIKE ?");
    $stmtC->bind_param("s", $q);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while($r = $resC->fetch_assoc()) { $skus_por_cat[] = "'".$r['Sku']."'"; }

    // B. Construir filtro adicional si se encontraron coincidencias por CodCat
    $extra_sql = "";
    if(!empty($skus_por_cat)){
        $lista = implode(",", $skus_por_cat);
        $extra_sql = " OR barcode IN ($lista)";
    }
    
    // C. Consulta principal a productos
    $stmt = $dbProd->prepare("SELECT barcode, descripcion FROM productos WHERE estado='1' AND (barcode LIKE ? OR descripcion LIKE ? $extra_sql) LIMIT 40");
    $stmt->bind_param("ss", $q, $q);
    $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // D. Mapear relaciones existentes
    $barcodes = array_column($productos, 'barcode');
    $relaciones = [];
    if (!empty($barcodes)) {
        $listaBC = "'" . implode("','", $barcodes) . "'";
        $resRel = $dbRel->query("SELECT Sku, CodCat FROM catproductos WHERE Sku IN ($listaBC)");
        while($r = $resRel->fetch_assoc()) { $relaciones[$r['Sku']] = $r['CodCat']; }
    }

    $html = '';
    foreach ($productos as $p) {
        $bc = $p['barcode'];
        $catActual = $relaciones[$bc] ?? '';
        $select = str_replace("value='$catActual'", "value='$catActual' selected", $cat_options);

        $html .= "<tr data-bc='{$bc}'>
            <td><b>{$bc}</b></td>
            <td align='left'>".htmlspecialchars($p['descripcion'])."</td>
            <td><select class='in-cat'>$select</select></td>
            <td><button class='btn-save'>💾 Guardar</button></td>
        </tr>";
    }
    echo json_encode(['html' => $html]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestor de Categorías Pro</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; padding: 20px; }
        .container { max-width: 1100px; margin: auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #eee; padding: 12px; text-align: center; }
        th { background: #007bff; color: white; }
        #search { width: 65%; padding: 12px; border: 2px solid #007bff; border-radius: 5px; font-size: 16px; outline: none; }
        .btn-orphan { background: #dc3545; color: white; border: none; padding: 12px; cursor: pointer; border-radius: 5px; float: right; font-weight: bold; }
        .btn-save { background: #28a745; color: white; border: none; padding: 7px 15px; cursor: pointer; border-radius: 4px; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); }
        .modal-content { background: white; margin: 5% auto; padding: 25px; width: 75%; border-radius: 12px; max-height: 80vh; overflow-y: auto; position: relative; }
        .close { position: absolute; right: 20px; top: 15px; font-size: 30px; cursor: pointer; color: #aaa; }
        .btn-add-orphan { background: #17a2b8; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Relación de Productos con Categorías</h2>
    
    <div style="overflow: hidden; margin-bottom: 20px;">
        <input type="text" id="search" placeholder="Buscar por Nombre, Barcode o Cód. Categoría...">
        <button class="btn-orphan" onclick="openOrphans()">⚠️ Revisar Activos sin Categoría</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Barcode (Sku)</th>
                <th width="45%">Descripción</th>
                <th>Categoría</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody id="main-tbody">
            <tr><td colspan="4">Cargando productos...</td></tr>
        </tbody>
    </table>
</div>

<div id="orphanModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3>⚠️ Productos Activos sin Categoría</h3>
        <p>Seleccione una categoría global y haga clic en ➕ para asignar:</p>
        <div style="margin-bottom: 20px; padding: 15px; background: #e9ecef; border-radius: 8px;">
            Categoría a asignar: <select id="global-cat" style="padding: 8px;"><?php echo $cat_options; ?></select>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Descripción</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody id="orphan-tbody"></tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let timer;
    function loadMain(q = "") {
        $.post('', { action: 'filter', buscar: q }, function(r) {
            $('#main-tbody').html(r.html);
        }, 'json');
    }

    $('#search').on('keyup', function() {
        clearTimeout(timer);
        timer = setTimeout(() => { loadMain($(this).val()); }, 300);
    });

    $(document).on('click', '.btn-save', function() {
        const tr = $(this).closest('tr');
        const bc = tr.data('bc');
        const cat = tr.find('.in-cat').val();
        const btn = $(this);

        btn.text('...').prop('disabled', true);
        $.post('', { action: 'save_rel', barcode: bc, codCat: cat }, function(r) {
            btn.text('💾 Guardar').prop('disabled', false);
        }, 'json');
    });

    function openOrphans() {
        $('#orphanModal').show();
        $('#orphan-tbody').html('<tr><td colspan="3">Analizando productos activos...</td></tr>');
        $.post('', { action: 'get_orphans' }, function(r) {
            $('#orphan-tbody').html(r.html);
        }, 'json');
    }

    function closeModal() { $('#orphanModal').hide(); loadMain($('#search').val()); }

    $(document).on('click', '.btn-add-orphan', function() {
        const bc = $(this).data('bc');
        const cat = $('#global-cat').val();
        const row = $(this).closest('tr');

        if(!cat) { alert("Seleccione una categoría primero"); return; }

        $.post('', { action: 'save_rel', barcode: bc, codCat: cat }, function(r) {
            if(r.success) row.css("background", "#d4edda").fadeOut(400);
        }, 'json');
    });

    window.onclick = function(e) { if(e.target == document.getElementById('orphanModal')) closeModal(); }

    loadMain();
</script>

</body>
</html>