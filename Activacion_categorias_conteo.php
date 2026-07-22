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
    // Opción 1: Resetear SegWebT a 0
    if (isset($_POST['reset_0_t'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '0'")) {
            $msgAccion = "🧹 TODAS las categorías (SegWebT) han sido puestas en 0 (Inactivas).";
        }
    }
    // Opción 2: Activar SegWebT a 1
    if (isset($_POST['set_1_t'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebT = '1'")) {
            $msgAccion = "🚀 TODAS las categorías (SegWebT) han sido puestas en 1 (Activas).";
        }
    }
    // Opción 3: Resetear SegWebF a 0
    if (isset($_POST['reset_0_f'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebF = '0'")) {
            $msgAccion = "🧹 TODAS las categorías (SegWebF) han sido puestas en 0 (Inactivas).";
        }
    }
    // Opción 4: Activar SegWebF a 1
    if (isset($_POST['set_1_f'])) {
        if ($dbWeb->query("UPDATE categorias SET SegWebF = '1'")) {
            $msgAccion = "🚀 TODAS las categorías (SegWebF) han sido puestas en 1 (Activas).";
        }
    }
    // Opción 5: Guardar selección manual de checkboxes por categoría (SegWebT y SegWebF)
    if (isset($_POST['guardar_categorias'])) {
        // Primero apagamos ambas columnas en todas
        $dbWeb->query("UPDATE categorias SET SegWebT = '0', SegWebF = '0'");
        
        // Si hay categorías seleccionadas para SegWebT, las encendemos
        if (!empty($_POST['cats_activas_t']) && is_array($_POST['cats_activas_t'])) {
            $idsT = array_map([$dbWeb, 'real_escape_string'], $_POST['cats_activas_t']);
            $listaIdsT = "'" . implode("','", $idsT) . "'";
            $dbWeb->query("UPDATE categorias SET SegWebT = '1' WHERE CodCat IN ($listaIdsT)");
        }

        // Si hay categorías seleccionadas para SegWebF, las encendemos
        if (!empty($_POST['cats_activas_f']) && is_array($_POST['cats_activas_f'])) {
            $idsF = array_map([$dbWeb, 'real_escape_string'], $_POST['cats_activas_f']);
            $listaIdsF = "'" . implode("','", $idsF) . "'";
            $dbWeb->query("UPDATE categorias SET SegWebF = '1' WHERE CodCat IN ($listaIdsF)");
        }

        $msgAccion = "✅ Selección de categorías (SegWebT y SegWebF) actualizada correctamente.";
    }
}

/* =====================================================
    2. FUNCIONES DE DATOS
===================================================== */

// Obtener categorías agrupadas por Familia incluyendo SegWebT y SegWebF
function obtenerCategoriasActivasPorFamilia($dbWeb){
    $familias = [];
    $sql = "SELECT f.id AS id_familia, f.nombre AS nombre_familia, 
                   c.CodCat, c.Nombre AS nombre_categoria, c.SegWebT, c.SegWebF
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
                'SegWebT' => $r['SegWebT'],
                'SegWebF' => $r['SegWebF']
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
    <title>Panel de Activación - Categorías Activas (SegWebT y SegWebF)</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; padding: 10px; font-size: 13px; color: #333; }
        
        .container { width: 100%; margin: 0; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        
        .header-flex { display: flex; flex-direction: column; gap: 15px; border-bottom: 2px solid #eee; margin-bottom: 15px; padding-bottom: 15px; }
        @media(min-width: 768px) {
            .header-flex { flex-direction: row; justify-content: space-between; align-items: center; }
        }
        
        .actions-group { display: flex; flex-wrap: wrap; gap: 12px; }
        .action-block { background: #f9f9f9; padding: 8px; border: 1px solid #ddd; border-radius: 6px; display: flex; gap: 5px; flex: 1; min-width: 220px; align-items: center; justify-content: space-between; }
        .action-block span { font-weight: bold; font-size: 11px; color: #555; }

        .alert-action { background: #e8f5e9; color: #2e7d32; padding: 12px; border-radius: 8px; border-left: 5px solid #4caf50; margin-bottom: 15px; font-weight: bold; }
        h2 { margin: 0; color: #1a237e; font-size: 1.4rem; }
        
        .btn { color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 11px; transition: 0.3s; }
        .btn-red { background: #d32f2f; } .btn-red:hover { background: #b71c1c; }
        .btn-green { background: #2e7d32; } .btn-green:hover { background: #1b5e20; }
        .btn-blue { background: #1976d2; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; padding: 12px; font-size: 13px; transition: 0.3s; }
        .btn-blue:hover { background: #115293; }
        
        .category-panel { background: #fafafa; border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .family-section { margin-bottom: 12px; background: #fff; padding: 12px; border: 1px solid #e0e0e0; border-radius: 6px; }
        
        .family-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #cfd8dc; padding-bottom: 6px; margin-bottom: 10px; flex-wrap: wrap; gap: 5px; }
        .family-title { font-weight: bold; color: #263238; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .family-selectors { display: flex; gap: 15px; }
        .select-all-label { font-size: 11px; color: #1976d2; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; user-select: none; }
        
        .family-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
        .category-item { display: flex; align-items: center; justify-content: space-between; font-size: 12px; background: #fdfdfd; padding: 6px 10px; border-radius: 4px; border: 1px solid #f0f0f0; }
        .category-item:hover { background: #f0f4f8; border-color: #d0d7de; }
        .category-name { flex-grow: 1; margin-right: 8px; }
        .category-checks { display: flex; gap: 10px; align-items: center; font-size: 11px; font-weight: bold; color: #666; }
        .category-checks label { cursor: pointer; display: flex; align-items: center; gap: 2px; }
        
        .families-container { max-height: 70vh; overflow-y: auto; padding-right: 5px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>🎛️ Panel de Activación - SegWebT & SegWebF</h2>
        <div class="actions-group">
            <!-- Bloque Acciones SegWebT -->
            <div class="action-block">
                <span>SegWebT:</span>
                <form method="POST" onsubmit="return confirm('¿Poner SegWebT en 1 para TODAS?')" style="display:inline;">
                    <button type="submit" name="set_1_t" class="btn btn-green">🚀 TODAS T</button>
                </form>
                <form method="POST" onsubmit="return confirm('¿Poner SegWebT en 0 para TODAS?')" style="display:inline;">
                    <button type="submit" name="reset_0_t" class="btn btn-red">🗑️ RESET T</button>
                </form>
            </div>
            <!-- Bloque Acciones SegWebF -->
            <div class="action-block">
                <span>SegWebF:</span>
                <form method="POST" onsubmit="return confirm('¿Poner SegWebF en 1 para TODAS?')" style="display:inline;">
                    <button type="submit" name="set_1_f" class="btn btn-green">🚀 TODAS F</button>
                </form>
                <form method="POST" onsubmit="return confirm('¿Poner SegWebF en 0 para TODAS?')" style="display:inline;">
                    <button type="submit" name="reset_0_f" class="btn btn-red">🗑️ RESET F</button>
                </form>
            </div>
        </div>
    </div>

    <?php if($msgAccion): ?>
        <div class="alert-action"><?= $msgAccion ?></div>
    <?php endif; ?>

    <!-- PANEL DE SELECCIÓN MANUAL -->
    <div class="category-panel">
        <form method="POST">
            <div class="families-container">
                <?php foreach($familiasCategorias as $famId => $fam): ?>
                    <div class="family-section">
                        <div class="family-header">
                            <span class="family-title">📁 <?= htmlspecialchars($fam['nombre']) ?></span>
                            <?php if(!empty($fam['categorias'])): ?>
                                <div class="family-selectors">
                                    <label class="select-all-label">
                                        <input type="checkbox" class="check-familia-t" data-fam="<?= $famId ?>" onchange="toggleFamilia(this, 't')"> Todo T
                                    </label>
                                    <label class="select-all-label" style="color: #2e7d32;">
                                        <input type="checkbox" class="check-familia-f" data-fam="<?= $famId ?>" onchange="toggleFamilia(this, 'f')"> Todo F
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if(!empty($fam['categorias'])): ?>
                            <div class="family-grid">
                                <?php foreach($fam['categorias'] as $cat): ?>
                                    <div class="category-item">
                                        <span class="category-name"><?= htmlspecialchars($cat['nombre']) ?></span>
                                        <div class="category-checks">
                                            <label title="SegWebT">
                                                T <input type="checkbox" class="cat-checkbox-t-<?= $famId ?>" name="cats_activas_t[]" value="<?= $cat['codcat'] ?>" <?= ($cat['SegWebT'] == '1') ? 'checked' : '' ?> onchange="actualizarEstadoFamilia(<?= $famId ?>, 't')">
                                            </label>
                                            <label title="SegWebF">
                                                F <input type="checkbox" class="cat-checkbox-f-<?= $famId ?>" name="cats_activas_f[]" value="<?= $cat['codcat'] ?>" <?= ($cat['SegWebF'] == '1') ? 'checked' : '' ?> onchange="actualizarEstadoFamilia(<?= $famId ?>, 'f')">
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <small style="color: #888;">No hay categorías activas en esta familia.</small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="guardar_categorias" class="btn-blue">💾 Guardar Selección por Familias (SegWebT y SegWebF)</button>
        </form>
    </div>
</div>

<script>
    function toggleFamilia(source, tipo) {
        let famId = source.getAttribute('data-fam');
        let checkboxes = document.querySelectorAll('.cat-checkbox-' + tipo + '-' + famId);
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = source.checked;
        });
    }

    function actualizarEstadoFamilia(famId, tipo) {
        let checkboxes = document.querySelectorAll('.cat-checkbox-' + tipo + '-' + famId);
        let masterCheckbox = document.querySelector('.check-familia-' + tipo + '[data-fam="' + famId + '"]');
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
        let famIds = [];
        document.querySelectorAll('.check-familia-t').forEach(function(master) {
            let famId = master.getAttribute('data-fam');
            actualizarEstadoFamilia(famId, 't');
            actualizarEstadoFamilia(famId, 'f');
        });
    });
</script>

</body>
</html>