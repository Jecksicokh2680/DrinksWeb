<?php
// Forzar la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

require_once("Conexion.php"); 

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

$mysqli->set_charset("utf8");

// Captura de filtros
$filtroCat   = $_GET['codcat'] ?? '';
$fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01'); 
$fechaFin    = $_GET['f_fin'] ?? date('Y-m-d');

/* ============================================================
   CONSULTA DE CATEGORÍAS (SOLO LAS QUE TIENEN AJUSTES)
============================================================ */
$resCats = $mysqli->query("SELECT DISTINCT h.categoria, cat.Nombre 
                           FROM historial_ajustes h 
                           INNER JOIN categorias cat ON cat.CodCat = h.categoria 
                           ORDER BY cat.Nombre ASC");

/* ============================================================
   CONSULTA DE MOVIMIENTOS DÍA A DÍA
============================================================ */
$sql = "SELECT h.*, cat.Nombre as NombreCat 
        FROM historial_ajustes h
        INNER JOIN categorias cat ON cat.CodCat = h.categoria 
        WHERE 1=1";

if ($filtroCat != '') {
    $sql .= " AND h.categoria = '$filtroCat'";
}
$sql .= " AND DATE(h.fecha_ajuste) BETWEEN '$fechaInicio' AND '$fechaFin'";
$sql .= " ORDER BY h.fecha_ajuste DESC";

$resHistorial = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIA | Historial por Categoría</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-xs { font-size: 0.85rem; }
        .badge-plus { background-color: #d1e7dd; color: #0f5132; }
        .badge-minus { background-color: #f8d7da; color: #842029; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4 shadow">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold"><i class="bi bi-calendar3 me-2"></i> ANÁLISIS POR CATEGORÍA</span>
        <a href="javascript:history.back()" class="btn btn-outline-light btn-sm">Volver al Panel</a>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Categoría (Solo con ajustes realizados)</label>
                    <select name="codcat" class="form-select form-select-sm">
                        <option value="">-- Todas las ajustadas --</option>
                        <?php while($c = $resCats->fetch_assoc()): ?>
                            <option value="<?= $c['categoria'] ?>" <?= ($filtroCat == $c['categoria']) ? 'selected' : '' ?>>
                                <?= $c['Nombre'] ?> (<?= $c['categoria'] ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Desde</label>
                    <input type="date" name="f_inicio" class="form-control form-control-sm" value="<?= $fechaInicio ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Hasta</label>
                    <input type="date" name="f_fin" class="form-control form-control-sm" value="<?= $fechaFin ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Filtrar</button>
                </div>
                <div class="col-md-2">
                    <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-printer"></i> Imprimir</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold text-dark">
                <?= ($filtroCat != '') ? "Movimientos Detallados" : "Todos los movimientos del periodo" ?>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-xs align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Fecha / Hora</th>
                            <th>Sede</th>
                            <th>Categoría</th>
                            <th class="text-end">Stock Ant.</th>
                            <th class="text-center">Ajuste</th>
                            <th class="text-end">Stock Nuevo</th>
                            <th>Usuario</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($resHistorial && $resHistorial->num_rows > 0): ?>
                            <?php while($h = $resHistorial->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-bold"><?= date("d/m/Y", strtotime($h['fecha_ajuste'])) ?></span>
                                        <div class="text-muted small"><?= date("H:i:s", strtotime($h['fecha_ajuste'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge border text-dark">
                                            <?= ($h['nit_empresa'] === '901724534-7') ? 'Drinks' : 'Central' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= $h['NombreCat'] ?></div>
                                        <div class="text-muted small"><?= $h['categoria'] ?></div>
                                    </td>
                                    <td class="text-end text-muted"><?= number_format($h['stock_anterior'], 2) ?></td>
                                    <td class="text-center">
                                        <?php 
                                            $val = $h['diferencia_aplicada'];
                                            $clase = ($val > 0) ? 'badge-plus' : 'badge-minus';
                                        ?>
                                        <span class="badge <?= $clase ?> rounded-pill px-3">
                                            <?= ($val > 0 ? '+' : '') . number_format($val, 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold"><?= number_format($h['stock_nuevo'], 2) ?></td>
                                    <td>
                                        <div class="small"><i class="bi bi-person me-1"></i><?= $h['usuario'] ?></div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted italic">
                                    No se encontraron registros para los filtros seleccionados.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>