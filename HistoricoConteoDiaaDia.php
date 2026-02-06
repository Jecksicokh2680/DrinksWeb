<?php
// Forzar la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

require_once("Conexion.php"); 

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

$mysqli->set_charset("utf8");

// Captura de filtros
$filtroCat   = $_GET['codcat'] ?? '';
$filtroSede  = $_GET['sede'] ?? ''; // Nuevo filtro de sede
$fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01'); 
$fechaFin    = $_GET['f_fin'] ?? date('Y-m-d');

/* ============================================================
   CONSULTA DE CATEGORÍAS (Basada en conteoweb)
============================================================ */
$resCats = $mysqli->query("SELECT DISTINCT c.CodCat, cat.Nombre 
                           FROM conteoweb c 
                           INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                           ORDER BY cat.Nombre ASC");

/* ============================================================
   CONSULTA DE CONTEOS DÍA A DÍA
============================================================ */
$sql = "SELECT c.*, cat.Nombre as NombreCat 
        FROM conteoweb c
        INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
        WHERE 1=1";

if ($filtroCat != '') {
    $sql .= " AND c.CodCat = '$filtroCat'";
}

if ($filtroSede != '') {
    // Filtro por NIT según tu lógica previa
    $sql .= " AND c.NitEmpresa = '$filtroSede'";
}

$sql .= " AND DATE(c.fecha_conteo) BETWEEN '$fechaInicio' AND '$fechaFin'";
$sql .= " ORDER BY DATE(c.fecha_conteo) DESC, c.fecha_conteo DESC";

$resConteos = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIA | Reporte Diario de Conteos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .table-xs { font-size: 0.85rem; }
        .fecha-header { background-color: #f1f3f5; font-weight: bold; color: #2d3436; border-left: 5px solid #0d6efd; }
        .estado-a { color: #fd7e14; font-weight: bold; } 
        .estado-c { color: #198754; font-weight: bold; } 
        .estado-e { color: #dc3545; text-decoration: line-through; } 
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4 shadow">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold"><i class="bi bi-clipboard-check me-2"></i> CONTEOS DÍA A DÍA</span>
        <a href="javascript:history.back()" class="btn btn-outline-light btn-sm">Regresar</a>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Sede</label>
                    <select name="sede" class="form-select form-select-sm">
                        <option value="">-- Todas las Sedes --</option>
                        <option value="901724534-7" <?= ($filtroSede == '901724534-7') ? 'selected' : '' ?>>Drinks</option>
                        <option value="900351234-1" <?= ($filtroSede == '900351234-1') ? 'selected' : '' ?>>Central</option> </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Categoría</label>
                    <select name="codcat" class="form-select form-select-sm">
                        <option value="">-- Todas las categorías --</option>
                        <?php while($c = $resCats->fetch_assoc()): ?>
                            <option value="<?= $c['CodCat'] ?>" <?= ($filtroCat == $c['CodCat']) ? 'selected' : '' ?>>
                                <?= $c['Nombre'] ?>
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
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-filter"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-xs align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Hora</th>
                            <th>Sede / Suc</th>
                            <th>Categoría</th>
                            <th class="text-end">Stock Sis.</th>
                            <th class="text-end">Stock Fís.</th>
                            <th class="text-center">Diferencia</th>
                            <th>Usuario</th>
                            <th class="text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $fechaActual = "";
                        if($resConteos && $resConteos->num_rows > 0): 
                            while($r = $resConteos->fetch_assoc()): 
                                $fechaFila = date("d/m/Y", strtotime($r['fecha_conteo']));
                                
                                if ($fechaActual != $fechaFila): 
                                    $fechaActual = $fechaFila;
                        ?>
                                <tr class="fecha-header">
                                    <td colspan="8" class="ps-4 py-2">
                                        <i class="bi bi-calendar-event me-2"></i> FECHA: <?= $fechaActual ?>
                                    </td>
                                </tr>
                        <?php endif; ?>
                                <tr>
                                    <td class="ps-4"><?= date("H:i:s", strtotime($r['fecha_conteo'])) ?></td>
                                    <td>
                                        <div class="small fw-bold"><?= ($r['NitEmpresa'] === '901724534-7') ? 'Drinks' : 'Central' ?></div>
                                        <div class="text-muted" style="font-size: 10px;">Suc: <?= $r['NroSucursal'] ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= $r['NombreCat'] ?></div>
                                        <div class="text-muted small"><?= $r['CodCat'] ?></div>
                                    </td>
                                    <td class="text-end"><?= number_format($r['stock_sistema'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-center">
                                        <?php 
                                            $dif = $r['diferencia'];
                                            $badge = ($dif < 0) ? 'bg-danger' : ($dif > 0 ? 'bg-success' : 'bg-secondary');
                                        ?>
                                        <span class="badge <?= $badge ?> rounded-pill">
                                            <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                                        </span>
                                    </td>
                                    <td><i class="bi bi-person small me-1"></i><?= $r['usuario'] ?></td>
                                    <td class="text-center">
                                        <?php 
                                            $est = strtoupper($r['estado']);
                                            $lbl = ($est == 'A') ? 'Abierto' : (($est == 'C') ? 'Cerrado' : 'Eliminado');
                                            $cls = ($est == 'A') ? 'estado-a' : (($est == 'C') ? 'estado-c' : 'estado-e');
                                        ?>
                                        <span class="<?= $cls ?> small"><?= $lbl ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No se encontraron conteos.</td>
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