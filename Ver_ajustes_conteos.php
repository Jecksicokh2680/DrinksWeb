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
$ahoraBogota = date("Y-m-d H:i:s");

/* ============================================================
   LÓGICA AJAX: PROCESAMIENTO Y REFRESCO
   ============================================================ */
if (isset($_POST['accion'])) {
    header('Content-Type: application/json');
    
    // Acción: Ajustar inventario
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

    // Acción: Revertir/Devolver ajuste
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
        .table-historial-interno { font-size: 0.72rem; background-color: #fdfdfd; margin-top: 8px; border: 1px solid #eee; }
        .loading-overlay { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:9999; justify-content:center; align-items:center; }
        .btn-refresh { transition: transform 0.3s ease; }
        .btn-refresh:active { transform: rotate(180deg); }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold small"><i class="bi bi-ui-checks me-2"></i>SIA AUDITORÍA</span>
        <div class="d-flex align-items-center">
            <button onclick="location.reload()" class="btn btn-outline-info btn-sm me-3 btn-refresh">
                <i class="bi bi-arrow-clockwise"></i> REFRESCAR DATOS
            </button>
            <span class="badge bg-primary px-3"><?= $usuarioActual ?></span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 mt-4">
    <div class="row g-4">
        
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2"></i>PENDIENTES POR AJUSTAR</h6>
                    <div class="d-flex gap-2">
                        <select id="sedeFilter" class="form-select form-select-sm" onchange="filtrar()">
                            <option value="">Todas las Sedes</option>
                            <option value="DRINKS">Drinks</option>
                            <option value="CENTRAL">Central</option>
                        </select>
                        <input type="text" id="busqueda" class="form-control form-control-sm" placeholder="Filtrar producto..." onkeyup="filtrar()">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr style="font-size: 11px;">
                                    <th class="ps-4">PRODUCTO / ÚLTIMOS 3 AJUSTES</th>
                                    <th class="text-end">SISTEMA</th>
                                    <th class="text-end">FÍSICO</th>
                                    <th class="text-center">DIF.</th>
                                    <th class="text-center">GESTIÓN</th>
                                </tr>
                            </thead>
                            <tbody id="tablaPendientes">
                                <?php 
                                $resPendientes = $mysqli->query("SELECT c.*, cat.Nombre FROM conteoweb c INNER JOIN categorias cat ON cat.CodCat = c.CodCat WHERE c.estado = 'A' AND c.diferencia <> 0 AND DATE(c.fecha_conteo) = '$hoy' ORDER BY cat.CodCat ASC");
                                while($r = $resPendientes->fetch_assoc()): 
                                    $sede = ($r['NitEmpresa'] === '901724534-7') ? 'Drinks' : 'Central';
                                    $subHist = $mysqli->query("SELECT diferencia_aplicada, fecha_ajuste, usuario FROM historial_ajustes WHERE categoria = '{$r['CodCat']}' AND nit_empresa = '{$r['NitEmpresa']}' ORDER BY fecha_ajuste DESC LIMIT 3");
                                ?>
                                <tr class="fila-producto" data-sede="<?= strtoupper($sede) ?>" data-nombre="<?= strtoupper($r['Nombre']) ?>">
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark"><?= $r['Nombre'] ?></div>
                                        <div class="mb-2"><span class="badge bg-light text-dark border" style="font-size: 9px;"><?= $sede ?></span></div>
                                        
                                        <?php if($subHist->num_rows > 0): ?>
                                        <table class="table table-sm table-historial-interno mb-0 shadow-sm">
                                            <tbody>
                                                <?php while($sh = $subHist->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="text-muted" style="font-size: 10px;"><?= date("d/m H:i", strtotime($sh['fecha_ajuste'])) ?></td>
                                                    <td class="fw-bold <?= $sh['diferencia_aplicada'] < 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 11px;">
                                                        <?= ($sh['diferencia_aplicada'] > 0 ? '+' : '') . $sh['diferencia_aplicada'] ?>
                                                    </td>
                                                    <td class="text-muted text-end" style="font-size: 9px;"><?= $sh['usuario'] ?></td>
                                                </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-muted small"><?= number_format($r['stock_sistema'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $r['diferencia'] < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                            <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group shadow-sm">
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm px-3 fw-bold">AJUSTAR</button>
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'eliminar_ajax')" class="btn btn-white btn-sm border"><i class="bi bi-trash text-danger"></i></button>
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

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="bi bi-clock-history me-2"></i>ÚLTIMOS MOVIMIENTOS</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush" id="listaHistorial">
                        <?php 
                        $resHistorialGral = $mysqli->query("SELECT h.*, cat.Nombre FROM historial_ajustes h LEFT JOIN categorias cat ON cat.CodCat = h.categoria ORDER BY h.fecha_ajuste DESC LIMIT 10");
                        while($h = $resHistorialGral->fetch_assoc()): 
                            $sH = ($h['nit_empresa'] === '901724534-7') ? 'Drinks' : 'Central';
                        ?>
                        <div class="list-group-item py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold small text-uppercase text-truncate" style="max-width: 180px;"><?= $h['Nombre'] ?></div>
                                    <div class="text-muted" style="font-size: 10px;">
                                        <?= $sH ?> • <?= date("H:i", strtotime($h['fecha_ajuste'])) ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold <?= $h['diferencia_aplicada'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= ($h['diferencia_aplicada'] > 0 ? '+' : '') . number_format($h['diferencia_aplicada'], 2) ?>
                                    </div>
                                    <button onclick="revertir(<?= $h['id'] ?>)" class="btn btn-sm btn-link text-warning p-0 fw-bold text-decoration-none" style="font-size: 9px;">REVERTIR</button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Función para filtrar sin recargar
function filtrar() {
    const s = document.getElementById('busqueda').value.toUpperCase();
    const sede = document.getElementById('sedeFilter').value.toUpperCase();
    document.querySelectorAll(".fila-producto").forEach(f => {
        const txt = f.getAttribute('data-nombre');
        const sd = f.getAttribute('data-sede');
        f.style.display = (txt.includes(s) && (sede === "" || sd === sede)) ? "" : "none";
    });
}

// Ejecutar Ajuste o Eliminar
function ejecutar(id, acc) {
    const msg = acc === 'ajustar_ajax' ? "¿Aplicar ajuste al inventario?" : "¿Eliminar este registro?";
    if(!confirm(msg)) return;
    
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', acc);
    fd.append('id_conteo', id);
    
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.status === 'success') {
            location.reload(); // Recarga para ver los cambios reflejados
        } else {
            alert("Error: " + d.message);
            document.getElementById('loader').style.display = 'none';
        }
    });
}

// Revertir ajuste
function revertir(id) {
    if(!confirm("¿Revertir este ajuste y devolver el stock a su estado anterior?")) return;
    document.getElementById('loader').style.display = 'flex';
    const fd = new FormData();
    fd.append('accion', 'devolver_ajuste_ajax');
    fd.append('id_historial', id);
    
    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.status === 'success') location.reload();
    });
}
</script>
</body>
</html>