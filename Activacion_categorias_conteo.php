<?php
date_default_timezone_set('America/Bogota');
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* =====================================================
    1. CONEXIONES
===================================================== */
require("Conexion.php");    // $mysqli (Base de Datos Administrativa)

$dbWeb = $mysqli; 

/* =====================================================
    LÓGICA DE BOTONES Y ACCIONES MANUALES
===================================================== */
$msgAccion = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Opción 1: Resetear todas a 0
    if (isset($_POST['reset_0'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '0'")) {
            $msgAccion = "🧹 TODAS las categorías han sido puestas en 0 (Inactivas).";
        }
    }
    // Opción 2: Activar todas a 1
    if (isset($_POST['set_1'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '1'")) {
            $msgAccion = "🚀 TODAS las categorías han sido puestas en 1 (Activas).";
        }
    }
    // Opción 3: Guardar selección manual de checkboxes por categoría
    if (isset($_POST['guardar_categorias'])) {
        // Primero apagamos todas
        $dbWeb->query("UPDATE categorias SET SegWebT = '0'");
        
        // Si hay categorías seleccionadas, las encendemos
        if (!empty($_POST['cats_activas']) && is_array($_POST['cats_activas'])) {
            $idsSeleccionados = array_map([$dbWeb, 'real_escape_string'], $_POST['cats_activas']);
            $listaIds = "'" . implode("','", $idsSeleccionados) . "'";
            $dbWeb->query("UPDATE categorias SET SegWebT = '1' WHERE CodCat IN ($listaIds)");
        }
        $msgAccion = "✅ Selección de categorías actualizada correctamente.";
    }
}

/* =====================================================
    2. FUNCIONES DE DATOS
===================================================== */

// Obtener únicamente categorías con Estado = '1' y SegWebT = '1' agrupadas por Familia
function obtenerCategoriasActivasPorFamilia($dbWeb){
    $familias = [];
    $sql = "SELECT f.id AS id_familia, f.nombre AS nombre_familia, 
                   c.CodCat, c.Nombre AS nombre_categoria, c.SegWebT
            FROM familias f
            LEFT JOIN categorias c ON c.Tipo = f.id AND c.Estado = '1'
            ORDER BY f.nombre ASC, c.Nombre ASC";
    $res = $dbWeb->query($sql);
    while($res && $r = $res->fetch_assoc()){
        $famId = $r['id_familia'];
        if (!isset($familias[$famId])) {
            $familias[$famId] = [
                'nombre' => $r['nombre_familia'],
                'categorias' => []
            ];
        }
        if ($r['CodCat'] !== null) {
            $familias[$famId]['categorias'][] = [
                'codcat' => $r['CodCat'],
                'nombre' => $r['nombre_categoria'],
                'SegWebT' => $r['SegWebT']
            ];
        }
    }
    return $familias;
}

/* =====================================================
    3. PROCESAMIENTO
===================================================== */
$familiasCategorias = obtenerCategoriasActivasPorFamilia($dbWeb);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Activación - Categorías Activas</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; padding: 10px; font-size: 13px; color: #333; }
        
        .container { width: 100%; margin: 0; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        
        .header-flex { display: flex; flex-direction: column; gap: 15px; border-bottom: 2px solid #eee; margin-bottom: 15px; padding-bottom: 15px; }
        @media(min-width: 768px) {
            .header-flex { flex-direction: row; justify-content: space-between; align-items: center; }
        }
        
        .actions-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .alert-action { background: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 8px; border-left: 5px solid #4caf50; margin-bottom: 15px; font-weight: bold; }
        h2 { margin: 0; color: #1a237e; font-size: 1.4rem; }
        
        .btn { color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.3s; width: 100%; }
        @media(min-width: 480px) { .btn { width: auto; } }
        .btn-red { background: #d32f2f; } .btn-red:hover { background: #b71c1c; }
        .btn-green { background: #2e7d32; } .btn-green:hover { background: #1b5e20; }
        .btn-blue { background: #1976d2; } .btn-blue:hover { background: #115293; width: 100%; padding: 12px; font-size: 13px; }
        
        .category-panel { background: #fafafa; border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .family-section { margin-bottom: 12px; background: #fff; padding: 12px; border: 1px solid #e0e0e0; border-radius: 6px; }
        
        .family-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cfd8dc; padding-bottom: 6px; margin-bottom: 10px; flex-wrap: wrap; gap: 5px; }
        .family-title { font-weight: bold; color: #263238; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .select-all-label { font-size: 11px; color: #1976d2; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; user-select: none; }
        
        .family-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
        .category-item { display: flex; align-items: center; gap: 8px; font-size: 12px; cursor: pointer; background: #fdfdfd; padding: 6px 8px; border-radius: 4px; border: 1px solid #f0f0f0; }
        .category-item:hover { background: #f0f4f8; border-color: #d0d7de; }
        
        .families-container { max-height: 70vh; overflow-y: auto; padding-right: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>🎛️ Panel de Activación - Categorías Activas</h2>
        <div class="actions-group">
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las categorías en 1?')" style="flex: 1; min-width: 150px;">
                <button type="submit" name="set_1" class="btn btn-green" style="width:100%;">🚀 ACTIVAR TODAS</button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Poner TODAS las categorías en 0?')" style="flex: 1; min-width: 150px;">
                <button type="submit" name="reset_0" class="btn btn-red" style="width:100%;">🗑️ RESETEAR TODAS</button>
            </form>
        </div>
    </div>

    <?php if($msgAccion): ?>
        <div class="alert-action"><?= $msgAccion ?></div>
    <?php endif; ?>

    <!-- PANEL DE SELECCIÓN MANUAL (SOLO CATEGORÍAS ACTIVAS Y ESTADO 1) -->
    <div class="category-panel">
        <form method="POST">
            <div class="families-container">
                <?php foreach($familiasCategorias as $famId => $fam): ?>
                    <div class="family-section">
                        <div class="family-header">
                            <span class="family-title">📁 <?= htmlspecialchars($fam['nombre']) ?></span>
                            <?php if(!empty($fam['categorias'])): ?>
                                <label class="select-all-label">
                                    <input type="checkbox" class="check-familia" data-fam="<?= $famId ?>" onchange="toggleFamilia(this)"> Seleccionar Familia
                                </label>
                            <?php endif; ?>
                        </div>
                        
                        <?php if(!empty($fam['categorias'])): ?>
                            <div class="family-grid">
                                <?php foreach($fam['categorias'] as $cat): ?>
                                    <label class="category-item">
                                        <input type="checkbox" class="cat-checkbox-<?= $famId ?>" name="cats_activas[]" value="<?= $cat['codcat'] ?>" <?= ($cat['SegWebT'] == '1') ? 'checked' : '' ?> onchange="actualizarEstadoFamilia(<?= $famId ?>)">
                                        <span><?= htmlspecialchars($cat['nombre']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <small style="color: #888;">No hay categorías activas en esta familia.</small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="guardar_categorias" class="btn btn-blue">💾 Guardar Selección por Familias</button>
        </form>
    </div>
</div>

<script>
    function toggleFamilia(source) {
        let famId = source.getAttribute('data-fam');
        let checkboxes = document.querySelectorAll('.cat-checkbox-' + famId);
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = source.checked;
        });
    }

    function actualizarEstadoFamilia(famId) {
        let checkboxes = document.querySelectorAll('.cat-checkbox-' + famId);
        let masterCheckbox = document.querySelector('.check-familia[data-fam="' + famId + '"]');
        if (!masterCheckbox) return;

        let todasMarcadas = true;
        let algunaMarcada = false;

        checkboxes.forEach(function(checkbox) {
            if (checkbox.checked) {
                algunaMarcada = true;
            } else {
                todasMarcadas = false;
            }
        });

        masterCheckbox.checked = todasMarcadas;
        masterCheckbox.indeterminate = algunaMarcada && !todasMarcadas;
    }

    window.addEventListener('DOMContentLoaded', (event) => {
        let masterCheckboxes = document.querySelectorAll('.check-familia');
        masterCheckboxes.forEach(function(master) {
            let famId = master.getAttribute('data-fam');
            actualizarEstadoFamilia(famId);
        });
    });
</script>

</body>
</html>