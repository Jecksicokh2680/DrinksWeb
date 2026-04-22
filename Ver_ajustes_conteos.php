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
$hoy = date("Y-m-d");
$primerDiaMes = date("Y-m-01"); 
$ahoraBogota = date("Y-m-d H:i:s");

/* ============================================================
   LÓGICA AJAX: PROCESAMIENTO SIN RECARGA
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
            $st = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=?");
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

    if ($_POST['accion'] === 'devolver_ajuste_ajax') {
        $idHistorial = (int)$_POST['id_historial'];
        $stmt = $mysqli->prepare("SELECT id_conteo, categoria, nit_empresa, diferencia_aplicada FROM historial_ajustes WHERE id=?");
        $stmt->bind_param("i", $idHistorial);
        $stmt->execute();
        $hist = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($hist) {
            $nitFila = trim($hist['nit_empresa']);
            $CodCat = $hist['categoria'];
            $difAplicada = (float)$hist['diferencia_aplicada'];
            $idConteoOriginal = $hist['id_conteo'];

            $dbDestino = ($nitFila === '901724534-7') ? $mysqliDrinks : $mysqliCentral;

            $skus = [];
            $st = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=?");
            $st->bind_param("s", $CodCat);
            $st->execute();
            $resSKU = $st->get_result();
            while ($row = $resSKU->fetch_assoc()) $skus[] = $row['Sku'];
            $st->close();

            if (!empty($skus)) {
                $reversaPorUnidad = ($difAplicada * -1) / count($skus);
                $dbDestino->begin_transaction();
                try {
                    $ph = implode(",", array_fill(0, count($skus), '?'));
                    $sqlUpd = "UPDATE inventario SET cantidad = cantidad + ?, localchange=1, syncweb=0, sincalmacenes='DEVOLUCION_WEB' 
                               WHERE idalmacen=1 AND idproducto IN (SELECT idproducto FROM productos WHERE barcode IN ($ph))";
                    
                    $updStmt = $dbDestino->prepare($sqlUpd);
                    $types = "d" . str_repeat("s", count($skus));
                    $updStmt->bind_param($types, $reversaPorUnidad, ...$skus);
                    $updStmt->execute();

                    $mysqli->query("UPDATE conteoweb SET estado='A' WHERE id=$idConteoOriginal");
                    $mysqli->query("DELETE FROM historial_ajustes WHERE id=$idHistorial");

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
        if($upd) echo json_encode(["status" => "success"]);
        else echo json_encode(["status" => "error", "message" => "No se pudo eliminar"]);
        exit;
    }
}

// CONSULTA CORREGIDA: Mostrará cualquier diferencia distinta de cero
$resPendientes = $mysqli->query("SELECT c.*, cat.Nombre 
    FROM conteoweb c 
    INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
    WHERE c.estado = 'A' 
    AND c.diferencia <> 0 
    AND DATE(c.fecha_conteo) = '$hoy'
    ORDER BY cat.CodCat ASC");

$resHistorial = $mysqli->query("SELECT h.*, cat.Nombre 
    FROM historial_ajustes h
    LEFT JOIN categorias cat ON cat.CodCat = h.categoria
    WHERE h.fecha_ajuste >= '$primerDiaMes 00:00:00'
    ORDER BY h.fecha_ajuste DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIA | Auditoría Total</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); overflow: hidden; }
        .loading-overlay { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index:9999; justify-content:center; align-items:center; }
        .list-group-item:hover { background-color: #f8fafc; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark mb-4 shadow">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold text-uppercase small"><i class="bi bi-shield-shaded me-2"></i>SIA Panel de Auditoría</span>
        <div class="text-white d-flex align-items-center">
            <button onclick="location.reload()" class="btn btn-outline-light btn-sm me-3"><i class="bi bi-arrow-clockwise"></i></button>
            <span class="badge bg-primary px-3"><?= $usuarioActual ?></span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <div class="row g-4">
        
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <div class="row align-items-center g-2">
                        <div class="col-md-4"><h6 class="mb-0 fw-bold">PENDIENTES</h6></div>
                        <div class="col-md-4">
                            <select id="sedeFilter" class="form-select form-select-sm" onchange="filtrarTodo()">
                                <option value="">Sede: Todas</option>
                                <option value="DRINKS">Drinks</option>
                                <option value="CENTRAL">Central</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Buscar categoría..." onkeyup="filtrarTodo()">
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table align-middle mb-0" id="tablePendientes">
                        <thead class="table-light">
                            <tr style="font-size: 11px;">
                                <th class="ps-4">CATEGORÍA</th>
                                <th class="text-end">SISTEMA</th>
                                <th class="text-end">FÍSICO</th>
                                <th class="text-center">DIF.</th>
                                <th class="text-center">GESTIÓN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($r = $resPendientes->fetch_assoc()): 
                                $nombreSede = ($r['NitEmpresa'] === '901724534-7') ? 'Drinks' : 'Central';
                            ?>
                            <tr class="item-conteo" data-sede="<?= strtoupper($nombreSede) ?>">
                                <td class="ps-4">
                                    <div class="fw-bold text-dark text-categoria" style="font-size:13px;"><?= $r['Nombre'] ?></div>
                                    <div class="text-muted" style="font-size: 10px;">
                                        <span class="badge bg-light text-dark border"><?= $nombreSede ?></span> | <?= date("H:i", strtotime($r['fecha_conteo'])) ?>
                                    </div>
                                </td>
                                <td class="text-end text-muted small"><?= number_format($r['stock_sistema'], 2) ?></td>
                                <td class="text-end fw-bold small"><?= number_format($r['stock_fisico'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $r['diferencia'] < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                        <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <button onclick="procesarAccion(<?= $r['id'] ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm px-3 shadow-sm">AJUSTAR</button>
                                        <button onclick="procesarAccion(<?= $r['id'] ?>, 'eliminar_ajax')" class="btn btn-outline-danger btn-sm px-2 shadow-sm"><i class="bi bi-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 fw-bold text-secondary">MOVIMIENTOS APLICADOS</h6>
                        <span class="badge bg-secondary-subtle text-secondary" id="countHist"><?= $resHistorial->num_rows ?> registros</span>
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-funnel"></i></span>
                        <input type="text" id="histSearch" class="form-control" placeholder="Buscar en historial..." onkeyup="filtrarTodo()">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="historialList" class="list-group list-group-flush" style="max-height: 700px; overflow-y: auto;">
                        <?php if($resHistorial->num_rows > 0): ?>
                            <?php while($h = $resHistorial->fetch_assoc()): 
                                $sedeHist = ($h['nit_empresa'] === '901724534-7') ? 'Drinks' : 'Central';
                            ?>
                                <div class="list-group-item py-3 px-4 item-historial" data-sede="<?= strtoupper($sedeHist) ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div style="flex: 1;">
                                            <div class="fw-bold text-uppercase text-dark text-categoria-hist" style="font-size: 12px;"><?= $h['Nombre'] ?></div>
                                            <div class="d-flex gap-2 mt-1">
                                                <span class="badge bg-light text-dark border" style="font-size: 9px;"><?= $sedeHist ?></span>
                                                <span class="text-muted" style="font-size: 10px;"><i class="bi bi-clock"></i> <?= date("d/m H:i", strtotime($h['fecha_ajuste'])) ?></span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold <?= $h['diferencia_aplicada'] < 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 14px;">
                                                <?= ($h['diferencia_aplicada'] > 0 ? '+' : '') . number_format($h['diferencia_aplicada'], 2) ?>
                                            </div>
                                            <button onclick="devolverAjuste(<?= $h['id'] ?>)" class="btn btn-link text-warning p-0 text-decoration-none fw-bold" style="font-size:10px">REVERTIR</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="p-5 text-center text-muted small italic">No hay registros hoy.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function filtrarTodo() {
    const searchGlobal = document.getElementById('searchInput').value.toUpperCase();
    const searchHistorial = document.getElementById('histSearch').value.toUpperCase();
    const sedeSelected = document.getElementById('sedeFilter').value.toUpperCase();

    // Filtro Pendientes
    document.querySelectorAll(".item-conteo").forEach(fila => {
        const categoria = fila.querySelector(".text-categoria").textContent.toUpperCase();
        const sedeFila = fila.getAttribute('data-sede');
        const visible = ( (sedeSelected === "" || sedeFila === sedeSelected) && (categoria.indexOf(searchGlobal) > -1) );
        fila.style.display = visible ? "" : "none";
    });

    // Filtro Historial
    let count = 0;
    document.querySelectorAll(".item-historial").forEach(item => {
        const categoria = item.querySelector(".text-categoria-hist").textContent.toUpperCase();
        const sedeItem = item.getAttribute('data-sede');
        const visible = ( (sedeSelected === "" || sedeItem === sedeSelected) && (categoria.indexOf(searchGlobal) > -1 && categoria.indexOf(searchHistorial) > -1) );
        item.style.display = visible ? "" : "none";
        if(visible) count++;
    });
    document.getElementById('countHist').textContent = count + " registros";
}

function procesarAccion(id, accion) {
    const msg = accion === 'eliminar_ajax' ? "¿Eliminar este conteo pendiente?" : "¿Desea ajustar el inventario ahora?";
    if(!confirm(msg)) return;
    
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', accion);
    fd.append('id_conteo', id);
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if(data.status === 'success') location.reload();
        else { 
            document.getElementById('loader').style.display = 'none'; 
            alert("Error: " + data.message); 
        }
    });
}

function devolverAjuste(idHistorial) {
    if(!confirm("¿Desea revertir este cambio? El stock volverá a su estado original.")) return;
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', 'devolver_ajuste_ajax');
    fd.append('id_historial', idHistorial);
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if(data.status === 'success') location.reload();
        else { 
            document.getElementById('loader').style.display = 'none'; 
            alert("Error: " + data.message); 
        }
    });
}
</script>
</body>
</html>