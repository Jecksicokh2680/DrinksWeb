<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once("Conexion.php");      // $mysqliWeb (Donde se guarda historial_stock)

// Forzar zona horaria de Bogotá en PHP
date_default_timezone_set('America/Bogota');

/* ============================================================
   PARÁMETROS DE VISTA (FECHA, FILTROS Y PAGINACIÓN)
============================================================ */
$fechaConsulta = $_GET['fecha'] ?? date("Y-m-d");
$categoria     = $_GET['categoria'] ?? '';
$term          = trim($_GET['term'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 100;
$offset        = ($page - 1) * $limit;

// 1. Obtener lista de fechas disponibles en el historial para el selector
$fechasDisponibles = [];
$resFechas = $mysqliWeb->query("SELECT DISTINCT fecha_registro FROM historial_stock ORDER BY fecha_registro DESC LIMIT 30");
while ($f = $resFechas->fetch_assoc()) {
    $fechasDisponibles[] = $f['fecha_registro'];
}

// 2. Obtener lista de categorías para el filtro
$cats = [];
$resCat = $mysqliWeb->query("SELECT LPAD(CodCat, 4, '0') AS CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while ($c = $resCat->fetch_assoc()) { 
    $cats[$c['CodCat']] = $c['Nombre']; 
}

/* ============================================================
   3. CONSULTAR HISTORIAL GUARDADO
============================================================ */
$whereClauses = ["fecha_registro = ?"];
$params = [$fechaConsulta];
$types = "s";

if (!empty($categoria)) {
    $whereClauses[] = "codcat = ?";
    $params[] = $categoria;
    $types .= "s";
}

if (!empty($term)) {
    $whereClauses[] = "barcode LIKE ?";
    $params[] = "%$term%";
    $types .= "s";
}

$sqlWhere = "WHERE " . implode(" AND ", $whereClauses);

// Contar total para paginación
$sqlCount = "SELECT COUNT(*) as total FROM historial_stock $sqlWhere";
$stmtC = $mysqliWeb->prepare($sqlCount);
$stmtC->bind_param($types, ...$params);
$stmtC->execute();
$totalRows = $stmtC->get_result()->fetch_assoc()['total'] ?? 0;
$totalPages = max(1, ceil($totalRows / $limit));

// Consultar registros con paginación
$sqlData = "SELECT barcode, codcat, stock_central, stock_drinks, stock_total 
            FROM historial_stock 
            $sqlWhere 
            ORDER BY codcat ASC, barcode ASC 
            LIMIT ? OFFSET ?";

$stmtD = $mysqliWeb->prepare($sqlData);
// Añadir limit y offset a los parámetros
$paramsData = array_merge($params, [$limit, $offset]);
$typesData = $types . "ii";
$stmtD->bind_param($typesData, ...$paramsData);
$stmtD->execute();
$resData = $stmtD->get_result();

$registrosHistorial = [];
while ($r = $resData->fetch_assoc()) {
    $registrosHistorial[] = $r;
}

// Consultar TODOS los registros sin límite para la opción de impresión total
$stmtAll = $mysqliWeb->prepare("SELECT barcode, codcat, stock_central, stock_drinks, stock_total FROM historial_stock $sqlWhere ORDER BY codcat ASC, barcode ASC");
$stmtAll->bind_param($types, ...$params);
$stmtAll->execute();
$resAll = $stmtAll->get_result();
$registrosTodos = [];
while ($r = $resAll->fetch_assoc()) {
    $registrosTodos[] = $r;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lectura de Historial de Stock</title>
    <style>
        body{font-family:Segoe UI,Arial;background:#eef1f4;color:#333;margin:0;padding:0}
        .container{max-width:1200px;margin:30px auto;background:#fff;padding:25px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
        h2{color:#2c3e50;text-align:center;margin-bottom:20px}
        form{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;justify-content:center}
        select,input,button{padding:8px 12px;border-radius:6px;border:1px solid #ccc;font-size:14px}
        button{background:#007bff;color:#fff;border:none;cursor:pointer}
        .table-container{overflow-x:auto;border-radius:12px;background:#fff}
        table{width:100%;border-collapse:collapse;min-width:700px}
        th,td{padding:12px 8px;text-align:center;border-bottom:1px solid #e1e1e1}
        th{background:#007bff;color:#fff}
        tr.subtotal{background:#f8f9fa;font-weight:700;color:#007bff;border-top: 2px solid #007bff;}
        tr.total-general{background:#2c3e50;color:#fff;font-weight:700;font-size:1.1em}
        .paginacion{text-align:center;margin-top:15px}
        .paginacion a{padding:6px 10px;margin:2px;border:1px solid #007bff;color:#007bff;text-decoration:none;border-radius:5px}
        .paginacion a.activa{background:#007bff;color:#fff}
        
        .tabla-pantalla { display: block; }
        .tabla-impresion { display: none; }
        .print-header { display: none; }

        @media print {
            body { background: #fff; padding: 0; }
            .container { max-width: 100%; margin: 0; padding: 0; box-shadow: none; border-radius: 0; }
            .no-print { display: none !important; }
            .tabla-pantalla { display: none !important; }
            .tabla-impresion { display: block !important; }
            .print-header { display: block; text-align: left; margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .print-header h3 { margin: 0 0 5px 0; color: #000; }
            .print-header p { margin: 0; font-size: 13px; color: #555; }
            table { font-size: 11px; width: 100%; }
            th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr.subtotal { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr.total-general { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="print-header">
        <h3>Reporte Histórico de Inventario Consolidado</h3>
        <p><strong>Fecha de Respaldo Consultada:</strong> <?= htmlspecialchars($fechaConsulta) ?></p>
        <p><strong>Fecha y Hora de Impresión:</strong> <?= date('Y-m-d H:i:s') ?> (Hora Bogotá)</p>
    </div>

    <h2>📈 Lectura de Historial de Stock Guardado</h2>

    <form method="GET" class="no-print">
        <div>
            <label style="font-size:11px; font-weight:bold; display:block;">Fecha de Respaldo:</label>
            <select name="fecha">
                <?php foreach ($fechasDisponibles as $f): ?>
                    <option value="<?= $f ?>" <?= $fechaConsulta === $f ? 'selected' : '' ?>><?= $f ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px; font-weight:bold; display:block;">Categoría:</label>
            <select name="categoria">
                <option value="">Todas las categorías</option>
                <?php foreach ($cats as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $categoria === $k ? 'selected' : '' ?>><?= htmlspecialchars($k.' - '.$v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px; font-weight:bold; display:block;">Buscar Barcode:</label>
            <input type="text" name="term" placeholder="Barcode..." value="<?= htmlspecialchars($term) ?>">
        </div>
        <div style="display:flex; align-items:flex-end; gap:5px;">
            <button type="submit">🔍 Consultar</button>
            <button type="button" onclick="window.print()" style="background:#6c757d;">🖨️ Imprimir Historial (Todo)</button>
        </div>
    </form>

    <!-- TABLA 1: VISTA PANTALLA (PAGINADA) -->
    <div class="table-container tabla-pantalla">
        <table>
            <thead>
                <tr>
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th>Barcode</th>
                    <th>Drinks</th>
                    <th>Central</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($registrosHistorial)):
                echo '<tr><td colspan="6" style="padding:20px; color:#777;">No se encontraron registros de historial para la fecha seleccionada.</td></tr>';
            else:
                $lastCat = null;
                $subD = $subC = $subT = 0;
                $genD = $genC = $genT = 0; 

                foreach ($registrosHistorial as $index => $r):
                    $cat = $r['codcat'];
                    $nombreCat = $cats[$cat] ?? 'SIN CATEGORÍA';
                    $cantD = (float)$r['stock_drinks'];
                    $cantC = (float)$r['stock_central'];
                    $totalUnit = (float)$r['stock_total'];

                    if($lastCat !== null && $lastCat !== $cat){
                        echo "<tr class='subtotal'>
                                <td colspan='3' style='text-align:right;'>SUBTOTAL ".htmlspecialchars(($cats[$lastCat] ?? 'SIN CATEGORÍA')." ({$lastCat})")."</td>
                                <td>".number_format($subD, 0)."</td>
                                <td>".number_format($subC, 0)."</td>
                                <td>".number_format($subT, 0)."</td>
                              </tr>";
                        $subD = $subC = $subT = 0;
                    }

                    $subD += $cantD; $subC += $cantC; $subT += $totalUnit;
                    $genD += $cantD; $genC += $cantC; $genT += $totalUnit;
                    
                    $lastCat = $cat;
            ?>
            <tr>
                <td><?= htmlspecialchars($cat) ?></td>
                <td><?= htmlspecialchars($nombreCat) ?></td>
                <td><?= htmlspecialchars($r['barcode']) ?></td>
                <td><?= number_format($cantD, 0) ?></td>
                <td><?= number_format($cantC, 0) ?></td>
                <td><strong><?= number_format($totalUnit, 0) ?></strong></td>
            </tr>
            <?php 
                    if ($index === count($registrosHistorial) - 1) {
                        echo "<tr class='subtotal'>
                                <td colspan='3' style='text-align:right;'>SUBTOTAL ".htmlspecialchars("{$nombreCat} ({$cat})")."</td>
                                <td>".number_format($subD, 0)."</td>
                                <td>".number_format($subC, 0)."</td>
                                <td>".number_format($subT, 0)."</td>
                              </tr>";
                    }
                endforeach; 
            endif;
            ?>
            </tbody>
            <tfoot>
                <tr class="total-general">
                    <td colspan="3" style="text-align:right;">TOTAL EN ESTA PÁGINA:</td>
                    <td><?= number_format($genD, 0) ?></td>
                    <td><?= number_format($genC, 0) ?></td>
                    <td><?= number_format($genT, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- TABLA 2: VISTA IMPRESIÓN (MUESTRA TODOS LOS REGISTROS SIN PAGINACIÓN) -->
    <div class="table-container tabla-impresion">
        <table>
            <thead>
                <tr>
                    <th>CodCat</th>
                    <th>Categoría</th>
                    <th>Barcode</th>
                    <th>Drinks</th>
                    <th>Central</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (empty($registrosTodos)):
                echo '<tr><td colspan="6" style="padding:20px; color:#777;">No se encontraron registros.</td></tr>';
            else:
                $lastCatAll = null;
                $subDAll = $subCAll = $subTAll = 0;
                $genDAll = $genCAll = $genTAll = 0; 

                foreach ($registrosTodos as $indexAll => $rAll):
                    $catAll = $rAll['codcat'];
                    $nombreCatAll = $cats[$catAll] ?? 'SIN CATEGORÍA';
                    $cantDAll = (float)$rAll['stock_drinks'];
                    $cantCAll = (float)$rAll['stock_central'];
                    $totalUnitAll = (float)$rAll['stock_total'];

                    if($lastCatAll !== null && $lastCatAll !== $catAll){
                        echo "<tr class='subtotal'>
                                <td colspan='3' style='text-align:right;'>SUBTOTAL ".htmlspecialchars(($cats[$lastCatAll] ?? 'SIN CATEGORÍA')." ({$lastCatAll})")."</td>
                                <td>".number_format($subDAll, 0)."</td>
                                <td>".number_format($subCAll, 0)."</td>
                                <td>".number_format($subTAll, 0)."</td>
                              </tr>";
                        $subDAll = $subCAll = $subTAll = 0;
                    }

                    $subDAll += $cantDAll; $subCAll += $cantCAll; $subTAll += $totalUnitAll;
                    $genDAll += $cantDAll; $genCAll += $cantCAll; $genTAll += $totalUnitAll;
                    
                    $lastCatAll = $catAll;
            ?>
            <tr>
                <td><?= htmlspecialchars($catAll) ?></td>
                <td><?= htmlspecialchars($nombreCatAll) ?></td>
                <td><?= htmlspecialchars($rAll['barcode']) ?></td>
                <td><?= number_format($cantDAll, 0) ?></td>
                <td><?= number_format($cantCAll, 0) ?></td>
                <td><strong><?= number_format($totalUnitAll, 0) ?></strong></td>
            </tr>
            <?php 
                    if ($indexAll === count($registrosTodos) - 1) {
                        echo "<tr class='subtotal'>
                                <td colspan='3' style='text-align:right;'>SUBTOTAL ".htmlspecialchars("{$nombreCatAll} ({$catAll})")."</td>
                                <td>".number_format($subDAll, 0)."</td>
                                <td>".number_format($subCAll, 0)."</td>
                                <td>".number_format($subTAll, 0)."</td>
                              </tr>";
                    }
                endforeach; 
            endif;
            ?>
            </tbody>
            <tfoot>
                <tr class="total-general">
                    <td colspan="3" style="text-align:right;">TOTAL GENERAL ACUMULADO:</td>
                    <td><?= number_format($genDAll, 0) ?></td>
                    <td><?= number_format($genCAll, 0) ?></td>
                    <td><?= number_format($genTAll, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="paginacion no-print">
        <?php for($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i == $page ? 'activa' : '' ?>" href="?fecha=<?= urlencode($fechaConsulta) ?>&categoria=<?= urlencode($categoria) ?>&term=<?= urlencode($term) ?>&page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</div>

</body>
</html>