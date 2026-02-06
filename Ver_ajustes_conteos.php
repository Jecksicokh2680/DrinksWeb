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
$primerDiaMes = date("Y-m-01"); // Variable para el filtro mensual
$ahoraBogota = date("Y-m-d H:i:s");

/* ============================================================
   LÓGICA AJAX: PROCESAMIENTO SIN RECARGA
   ============================================================ */
if (isset($_POST['accion'])) {
    header('Content-Type: application/json');
    
    // --- ACCIÓN: AJUSTAR ---
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

    // --- ACCIÓN: DEVOLVER AJUSTE ---
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

                    // Regresar conteo a Abierto y borrar historial
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

    // --- ACCIÓN: ELIMINAR ---
    if ($_POST['accion'] === 'eliminar_ajax') {
        $idConteo = (int)$_POST['id_conteo'];
        $upd = $mysqli->query("UPDATE conteoweb SET estado='E' WHERE id=$idConteo");
        if($upd) echo json_encode(["status" => "success"]);
        else echo json_encode(["status" => "error", "message" => "No se pudo eliminar"]);
        exit;
    }
}

// Consultas para la vista
$resPendientes = $mysqli->query("SELECT c.*, cat.Nombre 
    FROM conteoweb c 
    INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
    WHERE c.estado = 'A' AND ABS(c.diferencia) > 0.2 AND DATE(c.fecha_conteo) = '$hoy'
    ORDER BY c.id DESC");

// MODIFICACIÓN: Se cambió el filtro para mostrar todo el mes actual
$resHistorial = $mysqli->query("SELECT h.*, cat.Nombre 
    FROM historial_ajustes h
    LEFT JOIN categorias cat ON cat.CodCat = h.categoria
    WHERE h.fecha_ajuste >= '$primerDiaMes 00:00:00'
    ORDER BY cat.CodCat ,h.fecha_ajuste DESC");
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
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .row-fade-out { opacity: 0; transform: scale(0.95); transition: all 0.4s ease; }
        .loading-overlay { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index:9999; justify-content:center; align-items:center; }
        .hist-previo { background: #f1f5f9; border-left: 3px solid #94a3b8; font-size: 10px; padding: 4px 8px; margin-top: 4px; border-radius: 4px; }
        .btn-undo { font-size: 10px; padding: 2px 8px; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark mb-4 shadow">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold text-uppercase"><i class="bi bi-shield-shaded me-2"></i>SIA Panel de Auditoría</span>
        <div class="text-white d-flex align-items-center">
            <button onclick="location.reload()" class="btn btn-outline-light btn-sm me-3"><i class="bi bi-arrow-clockwise"></i> Recargar</button>
            <span class="small me-3 border-start ps-3"><i class="bi bi-clock"></i> <?= date("H:i") ?></span>
            <span class="badge bg-primary"><i class="bi bi-person"></i> <?= $usuarioActual ?></span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <div class="row g-4">
        
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <div class="row align-items-center">
                        <div class="col"><h6 class="mb-0 fw-bold">DIFERENCIAS PENDIENTES</h6></div>
                        <div class="col text-end"><input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Buscar categoría..."></div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table align-middle mb-0" id="tablePendientes">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">CATEGORÍA / SEDE</th>
                                <th class="text-end">SISTEMA</th>
                                <th class="text-end">FÍSICO</th>
                                <th class="text-center">DIF.</th>
                                <th class="text-center">GESTIÓN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($r = $resPendientes->fetch_assoc()): ?>
                            <tr id="fila-<?= $r['id'] ?>">
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= $r['Nombre'] ?></div>
                                    <div class="text-muted" style="font-size: 11px;">
                                        <?= ($r['NitEmpresa'] === '901724534-7') ? 'Drinks' : 'Central' ?> | <i class="bi bi-clock"></i> <?= date("H:i", strtotime($r['fecha_conteo'])) ?>
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
                                        <button onclick="procesarAccion(<?= $r['id'] ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm px-3">AJUSTAR</button>
                                        <button onclick="procesarAccion(<?= $r['id'] ?>, 'eliminar_ajax')" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
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
                    <h6 class="mb-0 fw-bold text-secondary">MOVIMIENTOS APLICADOS</h6>
                </div>
                <div class="card-body p-0">
                    <div id="historialList" class="list-group list-group-flush" style="max-height: 750px; overflow-y: auto;">
                        <?php if($resHistorial->num_rows > 0): ?>
                            <?php while($h = $resHistorial->fetch_assoc()): ?>
                                <div class="list-group-item py-3 px-4">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <div class="fw-bold text-uppercase small text-dark"><?= $h['Nombre'] ?></div>
                                            <div class="text-muted italic" style="font-size: 10px;">Sede: <?= ($h['nit_empresa'] === '901724534-7') ? 'Drinks' : 'Central' ?></div>
                                        </div>
                                        <div class="text-end">
                                            <button onclick="devolverAjuste(<?= $h['id'] ?>)" class="btn btn-outline-warning btn-undo text-dark fw-bold mb-1">
                                                <i class="bi bi-arrow-counterclockwise"></i> DEVOLVER
                                            </button>
                                            <div class="badge bg-light text-dark border d-block" style="font-size: 10px;"><?= date("d/m H:i", strtotime($h['fecha_ajuste'])) ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="small fw-bold <?= $h['diferencia_aplicada'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        DIFERENCIA APLICADA: <?= ($h['diferencia_aplicada'] > 0 ? '+' : '') . number_format($h['diferencia_aplicada'], 2) ?>
                                    </div>

                                    <?php 
                                        $catActual = $h['categoria'];
                                        $idActual = $h['id'];
                                        $stmtPrev = $mysqli->prepare("SELECT diferencia_aplicada, fecha_ajuste FROM historial_ajustes WHERE categoria = ? AND id < ? ORDER BY id DESC LIMIT 2");
                                        $stmtPrev->bind_param("si", $catActual, $idActual);
                                        $stmtPrev->execute();
                                        $resPrev = $stmtPrev->get_result();
                                        if($resPrev->num_rows > 0):
                                            while($p = $resPrev->fetch_assoc()):
                                    ?>
                                                <div class="hist-previo d-flex justify-content-between text-muted">
                                                    <span>Anterior (<?= date("d/m H:i", strtotime($p['fecha_ajuste'])) ?>):</span>
                                                    <span class="fw-bold"><?= ($p['diferencia_aplicada'] > 0 ? '+' : '') . number_format($p['diferencia_aplicada'], 2) ?></span>
                                                </div>
                                    <?php 
                                            endwhile;
                                        endif;
                                        $stmtPrev->close();
                                    ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="p-5 text-center text-muted italic small">No hay ajustes realizados en el mes.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function procesarAccion(id, accion) {
    if(!confirm("¿Desea realizar esta operación?")) return;
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', accion);
    fd.append('id_conteo', id);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        document.getElementById('loader').style.display = 'none';
        if(data.status === 'success') location.reload();
        else alert("Error: " + data.message);
    });
}

function devolverAjuste(idHistorial) {
    if(!confirm("¡ATENCIÓN! Se revertirá el ajuste en el inventario real y el conteo volverá a pendientes. ¿Continuar?")) return;
    
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', 'devolver_ajuste_ajax');
    fd.append('id_historial', idHistorial);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        document.getElementById('loader').style.display = 'none';
        if(data.status === 'success') {
            alert("Ajuste revertido correctamente.");
            location.reload();
        } else {
            alert("Error al devolver: " + data.message);
        }
    });
}

document.getElementById('searchInput').addEventListener('keyup', function() {
    let q = this.value.toUpperCase();
    let r = document.querySelector("#tablePendientes tbody").rows;
    for (let i = 0; i < r.length; i++) {
        let t = r[i].cells[0].textContent.toUpperCase();
        r[i].style.display = (t.indexOf(q) > -1) ? "" : "none";
    }
});
</script>

</body>
</html>