<?php
// Forzar la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");  
require_once("Conexion.php");    

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

$mysqli->set_charset("utf8");
$usuarioActual = $_SESSION['Usuario'];

// Obtener fecha seleccionada por el usuario o usar la de hoy por defecto
$fechaFiltro = isset($_GET['fecha']) && !empty($_GET['fecha']) ? $_GET['fecha'] : date("Y-m-d");
// Fecha específica para el filtro de la pestaña Historial de Ajustes
$fechaHistorial = isset($_GET['fecha_historial']) && !empty($_GET['fecha_historial']) ? $_GET['fecha_historial'] : $fechaFiltro;
$ahoraBogota = date("Y-m-d H:i:s");

/* ============================================================
   FUNCIÓN AUXILIAR OPTIMIZADA: PRECIO PROMEDIO POR CATEGORÍA
   ============================================================ */
function obtenerPreciosVentaMasivos($mysqliWeb, $dbCentral, $dbDrinks) {
    $precios = [];
    
    // Obtener todos los mapeos de categoría a SKU en una sola consulta
    $res = $mysqliWeb->query("SELECT CodCat, Sku FROM catproductos WHERE estado = 1");
    $mapCatSkus = [];
    $allSkus = [];
    while ($row = $res->fetch_assoc()) {
        $mapCatSkus[$row['CodCat']][] = $row['Sku'];
        $allSkus[] = $row['Sku'];
    }

    if (empty($allSkus)) return [];

    $allSkus = array_unique($allSkus);
    $chunks = array_chunk($allSkus, 500); // Evitar desbordamiento por exceso de parámetros IN

    $skuPrecios = [];
    foreach ($chunks as $chunk) {
        $ph = implode(",", array_fill(0, count($chunk), '?'));
        $types = str_repeat("s", count($chunk));

        // Consultar Central
        if ($dbCentral) {
            $stmt = $dbCentral->prepare("SELECT barcode, AVG(precioventa) as precio FROM productos WHERE barcode IN ($ph) AND precioventa > 0 GROUP BY barcode");
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $resPos = $stmt->get_result();
            while ($r = $resPos->fetch_assoc()) {
                $skuPrecios[$r['barcode']] = (float)$r['precio'];
            }
            $stmt->close();
        }

        // Consultar Drinks
        if ($dbDrinks) {
            $stmt = $dbDrinks->prepare("SELECT barcode, AVG(precioventa) as precio FROM productos WHERE barcode IN ($ph) AND precioventa > 0 GROUP BY barcode");
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $resPos = $stmt->get_result();
            while ($r = $resPos->fetch_assoc()) {
                $skuPrecios[$r['barcode']] = (float)$r['precio'];
            }
            $stmt->close();
        }
    }

    // Calcular promedio por CodCat en memoria RAM
    foreach ($mapCatSkus as $codCat => $skusCat) {
        $suma = 0;
        $count = 0;
        foreach ($skusCat as $sku) {
            if (isset($skuPrecios[$sku])) {
                $suma += $skuPrecios[$sku];
                $count++;
            }
        }
        $precios[$codCat] = ($count > 0) ? ($suma / $count) : 0;
    }

    return $precios;
}

/* ============================================================
   LÓGICA AJAX: PROCESAMIENTO Y REFRESCO
   ============================================================ */
if (isset($_POST['accion'])) {
    header('Content-Type: application/json');
    
    if ($_POST['accion'] === 'ajustar_ajax') {
        $idConteo = (int)$_POST['id_conteo'];
        $stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa, stock_sistema, stock_fisico FROM conteoweb WHERE id=? AND estado='A'");
        $stmt->bind_param("i", $idConteo);
        $stmt->execute();
        $conteo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($conteo) {
            $nitFila = trim($conteo['NitEmpresa']);
            $CodCat = $conteo['CodCat'];
            $difTotal = (float)$conteo['diferencia'];
            $stockAnterior = (float)$conteo['stock_sistema'];
            $stockNuevo = (float)$conteo['stock_fisico']; 

            $dbDestino = ($nitFila === '901724534-7') ? $mysqliDrinks : $mysqliCentral;

            $skus = [];
            $st = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=? and estado=1");
            $st->bind_param("s", $CodCat);
            $st->execute();
            $resSKU = $st->get_result();
            while ($row = $resSKU->fetch_assoc()) $skus[] = $row['Sku'];
            $st->close();

            if (!empty($skus)) {
                $difPorUnidad = $difTotal / count($skus);
                $dbDestino->begin_transaction();
                try {
                    $ph = implode(",", array_fill(0, count($skus), '?'));
                    $sqlUpd = "UPDATE inventario SET cantidad = cantidad + ?, localchange=1, syncweb=0, sincalmacenes='AJUSTE_WEB' 
                               WHERE idalmacen=1 AND idproducto IN (SELECT idproducto FROM productos WHERE barcode IN ($ph))";
                    
                    $updStmt = $dbDestino->prepare($sqlUpd);
                    $types = "d" . str_repeat("s", count($skus));
                    $updStmt->bind_param($types, $difPorUnidad, ...$skus);
                    $updStmt->execute();

                    $mysqli->query("UPDATE conteoweb SET estado='C' WHERE id=$idConteo");
                    
                    $log = $mysqli->prepare("INSERT INTO historial_ajustes 
                        (id_conteo, usuario, nit_empresa, categoria, stock_anterior, diferencia_aplicada, stock_nuevo, fecha_ajuste) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $log->bind_param("isssddds", $idConteo, $usuarioActual, $nitFila, $CodCat, $stockAnterior, $difTotal, $stockNuevo, $ahoraBogota);
                    $log->execute();

                    $dbDestino->commit();
                    echo json_encode(["status" => "success"]); exit;
                } catch (Exception $e) {
                    $dbDestino->rollback();
                    echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
                }
            }
        }
    }
    
    if ($_POST['accion'] === 'eliminar_ajax') {
        $idConteo = (int)$_POST['id_conteo'];
        $upd = $mysqli->query("UPDATE conteoweb SET estado='E' WHERE id=$idConteo");
        echo json_encode(["status" => $upd ? "success" : "error"]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIA | Auditoría de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.04); }
        .table-historial-interno { font-size: 0.70rem; background-color: #fdfdfd; margin-top: 5px; border: 1px solid #eee; }
        .loading-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:9999; justify-content:center; align-items:center; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold small"><i class="bi bi-ui-checks me-2"></i>AUDITORÍA DE INVENTARIOS</span>
        
        <div class="d-flex align-items-center gap-2">
            <form method="GET" action="" class="d-flex align-items-center gap-2 mb-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-secondary text-white border-secondary"><i class="bi bi-calendar-event"></i></span>
                    <input type="date" name="fecha" id="filtroFecha" class="form-control form-control-sm" value="<?= htmlspecialchars($fechaFiltro) ?>" onchange="this.form.submit()">
                </div>
            </form>
            <button onclick="location.href='?'" class="btn btn-outline-info btn-sm" title="Ir a la fecha de hoy"><i class="bi bi-arrow-clockwise"></i> Hoy</button>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <ul class="nav nav-tabs" id="sedeTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDrinks">DRINKS</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCentral">CENTRAL</button></li>
        <li class="nav-item"><button class="nav-link text-warning fw-bold" data-bs-toggle="tab" data-bs-target="#tabCruce"><i class="bi bi-arrow-left-right me-1"></i> CRUCE DE BODEGAS</button></li>
        <li class="nav-item"><button class="nav-link text-success fw-bold" data-bs-toggle="tab" data-bs-target="#tabValorizacion"><i class="bi bi-currency-dollar me-1"></i> VALORIZACIÓN DE AJUSTES</button></li>
        <li class="nav-item"><button class="nav-link text-primary fw-bold" data-bs-toggle="tab" data-bs-target="#tabHistorialAjustes"><i class="bi bi-clock-history me-1"></i> AJUSTES REALIZADOS</button></li>
    </ul>

    <div class="tab-content pt-3">
        <?php 
        $sedes = ['DRINKS', 'CENTRAL'];
        foreach ($sedes as $nombreSede): 
            $tabId = ($nombreSede == 'DRINKS') ? 'tabDrinks' : 'tabCentral';
            $isActive = ($nombreSede == 'DRINKS') ? 'show active' : '';
            $filtroNit = ($nombreSede == 'DRINKS') ? "= '901724534-7'" : "!= '901724534-7'";
        ?>
        <div class="tab-pane fade <?= $isActive ?>" id="<?= $tabId ?>">
            <div class="input-group mb-3 shadow-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control filtro-tabla" placeholder="Filtrar por código o nombre de categoría en <?= $nombreSede ?>..." data-table-id="tabla_<?= $tabId ?>">
            </div>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="tabla_<?= $tabId ?>">
                            <thead class="table-light text-uppercase" style="font-size: 11px;">
                                <tr>
                                    <th class="ps-4">CÓD. CAT.</th>
                                    <th>PRODUCTO / HISTORIAL</th>
                                    <th class="text-center">HORA</th>
                                    <th class="text-end">SISTEMA</th>
                                    <th class="text-end">FÍSICO</th>
                                    <th class="text-center">DIF.</th>
                                    <th class="text-center">GESTIÓN</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $fechaEsc = $mysqli->real_escape_string($fechaFiltro);
                                $res = $mysqli->query("SELECT c.*, cat.Nombre FROM conteoweb c 
                                                      INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                                                      WHERE c.estado = 'A' AND ABS(c.diferencia) > 0.2
                                                      AND DATE(c.fecha_conteo) = '$fechaEsc' 
                                                      AND c.NitEmpresa $filtroNit 
                                                      ORDER BY c.fecha_conteo DESC");
                                while($r = $res->fetch_assoc()): 
                                    $subHist = $mysqli->query("SELECT diferencia_aplicada, fecha_ajuste, usuario FROM historial_ajustes 
                                                             WHERE categoria = '{$r['CodCat']}' AND nit_empresa = '{$r['NitEmpresa']}' 
                                                             ORDER BY fecha_ajuste DESC LIMIT 3");
                                ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-secondary small"><?= $r['CodCat'] ?></td>
                                    <td class="py-3">
                                        <div class="fw-bold text-dark"><?= $r['Nombre'] ?></div>
                                        <?php if($subHist->num_rows > 0): ?>
                                            <table class="table table-sm table-historial-interno mb-0 shadow-sm">
                                                <tbody>
                                                    <?php while($sh = $subHist->fetch_assoc()): ?>
                                                    <tr>
                                                        <td class="text-muted"><?= date("d/m H:i", strtotime($sh['fecha_ajuste'])) ?></td>
                                                        <td class="fw-bold <?= $sh['diferencia_aplicada'] < 0 ? 'text-danger' : 'text-success' ?>">
                                                            <?= ($sh['diferencia_aplicada'] > 0 ? '+' : '') . $sh['diferencia_aplicada'] ?>
                                                        </td>
                                                        <td class="text-muted text-end"><?= $sh['usuario'] ?></td>
                                                    </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted small">
                                        <?= date("H:i:s", strtotime($r['fecha_conteo'])) ?>
                                    </td>
                                    <td class="text-end text-muted small"><?= number_format($r['stock_sistema'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $r['diferencia'] < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                            <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm">AJUSTAR</button>
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'eliminar_ajax')" class="btn btn-outline-danger btn-sm" title="Borrar Conteo"><i class="bi bi-trash"></i> Borrar</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- PESTAÑA: CRUCE DE BODEGAS -->
        <div class="tab-pane fade" id="tabCruce">
            <div class="input-group mb-3 shadow-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control filtro-tabla" placeholder="Filtrar por código o nombre de categoría en Cruce de Bodegas..." data-table-id="tabla_cruce">
            </div>
            <div class="card">
                <div class="card-header bg-warning bg-opacity-10 py-3">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-info-circle me-2"></i>Comparativa de Desviaciones y Stock Contado: Lo que falta en una bodega frente a lo que sobra en la otra (<?= htmlspecialchars($fechaFiltro) ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="tabla_cruce">
                            <thead class="table-light text-uppercase" style="font-size: 11px;">
                                <tr>
                                    <th class="ps-4">CÓD. CAT.</th>
                                    <th>CATEGORÍA / PRODUCTO</th>
                                    <th class="text-center">DIF. DRINKS (FÍSICO)</th>
                                    <th class="text-center">DIF. CENTRAL (FÍSICO)</th>
                                    <th class="text-center">ANÁLISIS DE CRUCE</th>
                                    <th class="text-center">GESTIÓN DRINKS</th>
                                    <th class="text-center">GESTIÓN CENTRAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $fechaEsc = $mysqli->real_escape_string($fechaFiltro);
                                $sqlCruce = "SELECT cat.CodCat, cat.Nombre, 
                                                    MAX(CASE WHEN c.NitEmpresa = '901724534-7' THEN c.id ELSE NULL END) as id_drinks,
                                                    MAX(CASE WHEN c.NitEmpresa != '901724534-7' THEN c.id ELSE NULL END) as id_central,
                                                    SUM(CASE WHEN c.NitEmpresa = '901724534-7' THEN c.diferencia ELSE 0 END) as dif_drinks,
                                                    SUM(CASE WHEN c.NitEmpresa = '901724534-7' THEN c.stock_fisico ELSE 0 END) as fisico_drinks,
                                                    SUM(CASE WHEN c.NitEmpresa != '901724534-7' THEN c.diferencia ELSE 0 END) as dif_central,
                                                    SUM(CASE WHEN c.NitEmpresa != '901724534-7' THEN c.stock_fisico ELSE 0 END) as fisico_central
                                             FROM conteoweb c
                                             INNER JOIN categorias cat ON cat.CodCat = c.CodCat
                                             WHERE c.estado = 'A' AND DATE(c.fecha_conteo) = '$fechaEsc'
                                             GROUP BY cat.CodCat, cat.Nombre
                                             HAVING ABS(dif_drinks) > 0.2 OR ABS(dif_central) > 0.2
                                             ORDER BY cat.Nombre ASC";
                                $resCruce = $mysqli->query($sqlCruce);

                                if ($resCruce && $resCruce->num_rows > 0):
                                    while($cr = $resCruce->fetch_assoc()):
                                        $dDrinks = (float)$cr['dif_drinks'];
                                        $fDrinks = (float)$cr['fisico_drinks'];
                                        $dCentral = (float)$cr['dif_central'];
                                        $fCentral = (float)$cr['fisico_central'];
                                        $idDrinks = $cr['id_drinks'];
                                        $idCentral = $cr['id_central'];

                                        $analisisHtml = "";
                                        if ($dDrinks < 0 && $dCentral > 0) {
                                            $analisisHtml = "<span class='badge bg-danger'>Falta en Drinks</span> <i class='bi bi-arrow-right mx-1'></i> <span class='badge bg-success'>Sobra en Central</span>";
                                            if (abs(abs($dDrinks) - abs($dCentral)) < 0.5) {
                                                $analisisHtml .= " <span class='badge bg-info text-dark'>¡Posible Desplazamiento Cruzado!</span>";
                                            }
                                        } elseif ($dDrinks > 0 && $dCentral < 0) {
                                            $analisisHtml = "<span class='badge bg-success'>Sobra en Drinks</span> <i class='bi bi-arrow-right mx-1'></i> <span class='badge bg-danger'>Falta en Central</span>";
                                            if (abs(abs($dDrinks) - abs($dCentral)) < 0.5) {
                                                $analisisHtml .= " <span class='badge bg-info text-dark'>¡Posible Desplazamiento Cruzado!</span>";
                                            }
                                        } elseif ($dDrinks < 0 && $dCentral < 0) {
                                            $analisisHtml = "<span class='badge bg-dark'>Faltante en Ambas Sedes</span>";
                                        } elseif ($dDrinks > 0 && $dCentral > 0) {
                                            $analisisHtml = "<span class='badge bg-primary'>Sobrante en Ambas Sedes</span>";
                                        } else {
                                            $analisisHtml = "<span class='text-muted'>Sin desviación cruzada relevante</span>";
                                        }
                                ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-secondary small"><?= $cr['CodCat'] ?></td>
                                    <td class="py-3 fw-bold text-dark"><?= $cr['Nombre'] ?></td>
                                    <td class="text-center">
                                        <div>
                                            <span class="badge <?= $dDrinks < 0 ? 'bg-danger' : ($dDrinks > 0 ? 'bg-success' : 'bg-secondary') ?> rounded-pill">
                                                Dif: <?= ($dDrinks > 0 ? '+' : '') . number_format($dDrinks, 2) ?>
                                            </span>
                                        </div>
                                        <small class="text-muted fw-bold">Contado: <?= number_format($fDrinks, 2) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div>
                                            <span class="badge <?= $dCentral < 0 ? 'bg-danger' : ($dCentral > 0 ? 'bg-success' : 'bg-secondary') ?> rounded-pill">
                                                Dif: <?= ($dCentral > 0 ? '+' : '') . number_format($dCentral, 2) ?>
                                            </span>
                                        </div>
                                        <small class="text-muted fw-bold">Contado: <?= number_format($fCentral, 2) ?></small>
                                    </td>
                                    <td class="text-center"><?= $analisisHtml ?></td>
                                    <td class="text-center">
                                        <?php if ($idDrinks): ?>
                                            <div class="btn-group" role="group">
                                                <button onclick="ejecutar(<?= $idDrinks ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm">Ajustar</button>
                                                <button onclick="ejecutar(<?= $idDrinks ?>, 'eliminar_ajax')" class="btn btn-outline-danger btn-sm" title="Borrar Conteo Drinks"><i class="bi bi-trash"></i></button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($idCentral): ?>
                                            <div class="btn-group" role="group">
                                                <button onclick="ejecutar(<?= $idCentral ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm">Ajustar</button>
                                                <button onclick="ejecutar(<?= $idCentral ?>, 'eliminar_ajax')" class="btn btn-outline-danger btn-sm" title="Borrar Conteo Central"><i class="bi bi-trash"></i></button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                    <?php endwhile; 
                                else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No hay suficientes registros de conteo en ambas sedes para realizar el cruce en la fecha seleccionada.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PESTAÑA: VALORIZACIÓN DE AJUSTES A PRECIO DE VENTA -->
        <div class="tab-pane fade" id="tabValorizacion">
            <?php
            $preciosMasivos = obtenerPreciosVentaMasivos($mysqli, $mysqliCentral ?? null, $mysqliDrinks ?? null);

            $fechaEsc = $mysqli->real_escape_string($fechaFiltro);
            $sqlVal = "SELECT c.id, c.CodCat, c.NitEmpresa, c.diferencia, c.estado, c.fecha_conteo, cat.Nombre 
                       FROM conteoweb c 
                       INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                       WHERE c.estado IN ('A', 'C') AND ABS(c.diferencia) > 0.2 
                       AND DATE(c.fecha_conteo) = '$fechaEsc' 
                       ORDER BY c.NitEmpresa ASC, c.fecha_conteo DESC";
            $resVal = $mysqli->query($sqlVal);

            $filasValorizadas = [];
            $totalFaltante = 0;
            $totalSobrante = 0;

            if ($resVal) {
                while ($rv = $resVal->fetch_assoc()) {
                    $precioVenta = $preciosMasivos[$rv['CodCat']] ?? 0;
                    $dif = (float)$rv['diferencia'];
                    $valorAjuste = $dif * $precioVenta;

                    if ($valorAjuste < 0) {
                        $totalFaltante += $valorAjuste;
                    } else {
                        $totalSobrante += $valorAjuste;
                    }

                    $rv['precio_venta'] = $precioVenta;
                    $rv['valor_ajuste'] = $valorAjuste;
                    $filasValorizadas[] = $rv;
                }
            }
            $balanceNeto = $totalSobrante + $totalFaltante;
            ?>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="card border-start border-danger border-4 p-3">
                        <small class="text-muted text-uppercase fw-bold">Total Faltantes (Precio Venta)</small>
                        <h4 class="text-danger fw-bold mb-0">$<?= number_format($totalFaltante, 0, ',', '.') ?></h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-start border-success border-4 p-3">
                        <small class="text-muted text-uppercase fw-bold">Total Sobrantes (Precio Venta)</small>
                        <h4 class="text-success fw-bold mb-0">$<?= number_format($totalSobrante, 0, ',', '.') ?></h4>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-start border-primary border-4 p-3">
                        <small class="text-muted text-uppercase fw-bold">Balance Neto del Día</small>
                        <h4 class="<?= $balanceNeto < 0 ? 'text-danger' : 'text-primary' ?> fw-bold mb-0">
                            <?= ($balanceNeto > 0 ? '+' : '') ?>$<?= number_format($balanceNeto, 0, ',', '.') ?>
                        </h4>
                    </div>
                </div>
            </div>

            <div class="input-group mb-3 shadow-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control filtro-tabla" placeholder="Filtrar por sede, código o categoría en Valorización..." data-table-id="tabla_valorizacion">
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="tabla_valorizacion">
                            <thead class="table-light text-uppercase" style="font-size: 11px;">
                                <tr>
                                    <th class="ps-4">SEDE</th>
                                    <th>CÓD. CAT.</th>
                                    <th>CATEGORÍA / PRODUCTO</th>
                                    <th class="text-center">ESTADO</th>
                                    <th class="text-center">DIF. UNIDADES</th>
                                    <th class="text-end">PRECIO VENTA UNIT.</th>
                                    <th class="text-end pe-4">VALOR AJUSTE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($filasValorizadas)): ?>
                                    <?php foreach ($filasValorizadas as $fv): 
                                        $sedeLabel = (trim($fv['NitEmpresa']) === '901724534-7') ? 'DRINKS' : 'CENTRAL';
                                        $badgeSede = ($sedeLabel === 'DRINKS') ? 'bg-info text-dark' : 'bg-secondary';
                                        $esAplicado = ($fv['estado'] === 'C');
                                    ?>
                                    <tr>
                                        <td class="ps-4"><span class="badge <?= $badgeSede ?>"><?= $sedeLabel ?></span></td>
                                        <td class="font-monospace fw-bold text-secondary small"><?= $fv['CodCat'] ?></td>
                                        <td class="fw-bold text-dark"><?= $fv['Nombre'] ?></td>
                                        <td class="text-center">
                                            <?php if ($esAplicado): ?>
                                                <span class="badge bg-success">APLICADO</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">PENDIENTE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $fv['diferencia'] < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                                <?= ($fv['diferencia'] > 0 ? '+' : '') . number_format($fv['diferencia'], 2) ?>
                                            </span>
                                        </td>
                                        <td class="text-end text-muted">$<?= number_format($fv['precio_venta'], 0, ',', '.') ?></td>
                                        <td class="text-end pe-4 fw-bold <?= $fv['valor_ajuste'] < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= ($fv['valor_ajuste'] > 0 ? '+' : '') ?>$<?= number_format($fv['valor_ajuste'], 0, ',', '.') ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No hay ajustes pendientes ni aplicados registrados para la fecha seleccionada.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PESTAÑA: HISTORIAL DE AJUSTES REALIZADOS (INCLUYE BORRADOS) -->
        <div class="tab-pane fade" id="tabHistorialAjustes">
            <div class="card mb-3 p-3 bg-white">
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <form method="GET" action="" class="d-flex align-items-center gap-2 mb-0">
                            <input type="hidden" name="fecha" value="<?= htmlspecialchars($fechaFiltro) ?>">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-primary text-white fw-bold"><i class="bi bi-calendar3 me-1"></i> Fecha Ajuste:</span>
                                <input type="date" name="fecha_historial" class="form-control" value="<?= htmlspecialchars($fechaHistorial) ?>" onchange="this.form.submit()">
                                <a href="?fecha=<?= htmlspecialchars($fechaFiltro) ?>&fecha_historial=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary" title="Hoy">Hoy</a>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-7">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control filtro-tabla" placeholder="Filtrar por sede, código, categoría o usuario..." data-table-id="tabla_historial_ajustes">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-primary bg-opacity-10 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-journal-check me-2"></i>Registro Histórico de Ajustes Aplicados al Inventario (<?= htmlspecialchars($fechaHistorial) ?>)
                    </h6>
                    <span class="badge bg-primary">Incluye conteos anulados/borrados</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="tabla_historial_ajustes">
                            <thead class="table-light text-uppercase" style="font-size: 11px;">
                                <tr>
                                    <th class="ps-4">HORA CONTEO</th>
                                    <th>HORA AJUSTE</th>
                                    <th>SEDE</th>
                                    <th>CÓD. CAT.</th>
                                    <th>CATEGORÍA / PRODUCTO</th>
                                    <th class="text-end">STOCK ANT.</th>
                                    <th class="text-center">DIF. APLICADA</th>
                                    <th class="text-end">STOCK NUEVO</th>
                                    <th class="text-center">USUARIO</th>
                                    <th class="text-center pe-4">ESTADO CONTEO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $stmtHist = $mysqli->prepare("
                                    SELECT h.*, cat.Nombre AS nombre_categoria, c.estado AS estado_conteo, c.fecha_conteo
                                    FROM historial_ajustes h
                                    LEFT JOIN categorias cat ON cat.CodCat = h.categoria
                                    LEFT JOIN conteoweb c ON c.id = h.id_conteo
                                    WHERE DATE(h.fecha_ajuste) = ?
                                    ORDER BY h.fecha_ajuste DESC
                                ");
                                $stmtHist->bind_param("s", $fechaHistorial);
                                $stmtHist->execute();
                                $resHist = $stmtHist->get_result();

                                if ($resHist && $resHist->num_rows > 0):
                                    while ($ha = $resHist->fetch_assoc()):
                                        $sedeHa = (trim($ha['nit_empresa']) === '901724534-7') ? 'DRINKS' : 'CENTRAL';
                                        $badgeSedeHa = ($sedeHa === 'DRINKS') ? 'bg-info text-dark' : 'bg-secondary';
                                        $difHa = (float)$ha['diferencia_aplicada'];
                                        $estConteo = $ha['estado_conteo'] ?? '';
                                        $horaConteoHa = !empty($ha['fecha_conteo']) ? date("H:i:s", strtotime($ha['fecha_conteo'])) : 'N/A';

                                        if ($estConteo === 'E' || $estConteo === 'X') {
                                            $badgeEstado = '<span class="badge bg-danger">BORRADO</span>';
                                        } elseif ($estConteo === 'C') {
                                            $badgeEstado = '<span class="badge bg-success">APLICADO</span>';
                                        } elseif ($estConteo === 'A') {
                                            $badgeEstado = '<span class="badge bg-warning text-dark">ACTIVO</span>';
                                        } else {
                                            $badgeEstado = '<span class="badge bg-secondary">SIN REF.</span>';
                                        }
                                ?>
                                <tr>
                                    <td class="ps-4 text-primary small font-monospace fw-bold"><?= $horaConteoHa ?></td>
                                    <td class="text-muted small font-monospace fw-bold"><?= date("H:i:s", strtotime($ha['fecha_ajuste'])) ?></td>
                                    <td><span class="badge <?= $badgeSedeHa ?>"><?= $sedeHa ?></span></td>
                                    <td class="font-monospace fw-bold text-secondary small"><?= htmlspecialchars($ha['categoria']) ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($ha['nombre_categoria'] ?? 'Categoría no encontrada') ?></td>
                                    <td class="text-end text-muted small"><?= number_format((float)$ha['stock_anterior'], 2) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $difHa < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                            <?= ($difHa > 0 ? '+' : '') . number_format($difHa, 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold"><?= number_format((float)$ha['stock_nuevo'], 2) ?></td>
                                    <td class="text-center small text-muted"><?= htmlspecialchars($ha['usuario']) ?></td>
                                    <td class="text-center pe-4"><?= $badgeEstado ?></td>
                                </tr>
                                <?php 
                                    endwhile;
                                else: 
                                ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No se encontraron ajustes realizados en la fecha seleccionada (<?= htmlspecialchars($fechaHistorial) ?>).</td>
                                </tr>
                                <?php 
                                endif; 
                                $stmtHist->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const activeTab = sessionStorage.getItem('activeTab');
    if (activeTab) {
        const tabTrigger = document.querySelector(`button[data-bs-target="${activeTab}"]`);
        if (tabTrigger) {
            const tab = new bootstrap.Tab(tabTrigger);
            tab.show();
        }
    }
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(button => {
        button.addEventListener('shown.bs.tab', e => {
            sessionStorage.setItem('activeTab', e.target.getAttribute('data-bs-target'));
        });
    });

    document.querySelectorAll('.filtro-tabla').forEach(input => {
        input.addEventListener('keyup', function() {
            let filtro = this.value.toLowerCase();
            let tableId = this.getAttribute('data-table-id');
            let filas = document.querySelectorAll(`#${tableId} tbody tr`);

            filas.forEach(fila => {
                let textoFila = fila.textContent.toLowerCase();
                if (textoFila.includes(filtro)) {
                    fila.style.display = "";
                } else {
                    fila.style.display = "none";
                }
            });
        });
    });
});

function ejecutar(id, acc) {
    let mensaje = (acc === 'eliminar_ajax') ? "¿Estás seguro de borrar este conteo?" : "¿Confirmar acción de ajuste?";
    if(!confirm(mensaje)) return;
    document.getElementById('loader').style.display = 'flex';
    
    const fd = new FormData();
    fd.append('accion', acc); 
    fd.append('id_conteo', id);
    
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { 
        if(d.status === 'success') {
            location.reload(); 
        } else {
            alert("Error: " + (d.message || "No se pudo completar la acción"));
            document.getElementById('loader').style.display = 'none';
        }
    })
    .catch(err => {
        alert("Error de conexión");
        document.getElementById('loader').style.display = 'none';
    });
}
</script>
</body>
</html>