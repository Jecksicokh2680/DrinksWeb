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
        .loading-overlay { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:9999; justify-content:center; align-items:center; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark shadow-sm mb-4">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold small"><i class="bi bi-ui-checks me-2"></i>SIA AUDITORÍA</span>
        <button onclick="location.reload()" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-clockwise"></i> REFRESCAR</button>
    </div>
</nav>

<div class="container-fluid px-4">
    <ul class="nav nav-tabs" id="sedeTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDrinks">DRINKS</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCentral">CENTRAL</button></li>
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
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light text-uppercase" style="font-size: 11px;">
                                <tr>
                                    <th class="ps-4">PRODUCTO / HISTORIAL</th>
                                    <th class="text-end">SISTEMA</th>
                                    <th class="text-end">FÍSICO</th>
                                    <th class="text-center">DIF.</th>
                                    <th class="text-center">GESTIÓN</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $res = $mysqli->query("SELECT c.*, cat.Nombre FROM conteoweb c 
                                                      INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                                                      WHERE c.estado = 'A' AND ABS(c.diferencia) > 0.2
                                                      AND DATE(c.fecha_conteo) = '$hoy' 
                                                      AND c.NitEmpresa $filtroNit 
                                                      ORDER BY cat.CodCat ASC");
                                while($r = $res->fetch_assoc()): 
                                    $subHist = $mysqli->query("SELECT diferencia_aplicada, fecha_ajuste, usuario FROM historial_ajustes 
                                                              WHERE categoria = '{$r['CodCat']}' AND nit_empresa = '{$r['NitEmpresa']}' 
                                                              ORDER BY fecha_ajuste DESC LIMIT 3");
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
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
                                    <td class="text-end text-muted small"><?= number_format($r['stock_sistema'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $r['diferencia'] < 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill">
                                            <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'ajustar_ajax')" class="btn btn-primary btn-sm">AJUSTAR</button>
                                            <button onclick="ejecutar(<?= $r['id'] ?>, 'eliminar_ajax')" class="btn btn-outline-secondary btn-sm"><i class="bi bi-trash"></i></button>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mantener pestaña activa tras recargar
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
});

function ejecutar(id, acc) {
    if(!confirm("¿Confirmar acción?")) return;
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