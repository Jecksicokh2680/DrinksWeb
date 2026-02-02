<?php
require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");  
require_once("Conexion.php");    

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

$mysqli->set_charset("utf8");
$usuarioActual = $_SESSION['Usuario'];
$hoy = date("Y-m-d");

/* ============================================================
   LÓGICA AJAX: PROCESAMIENTO SIN RECARGA
   ============================================================ */
if (isset($_POST['accion']) && $_POST['accion'] === 'ajustar_ajax') {
    $idConteo = (int)$_POST['id_conteo'];
    
    $stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa, stock_sistema, stock_fisico FROM conteoweb WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $conteo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conteo) {
        $nitFila         = trim($conteo['NitEmpresa']);
        $CodCat          = $conteo['CodCat'];
        $difTotal        = (float)$conteo['diferencia'];
        $stockAnterior   = (float)$conteo['stock_sistema'];
        $stockNuevo      = (float)$conteo['stock_fisico']; 

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
                // Actualización masiva por SKUs
                $ph = implode(",", array_fill(0, count($skus), '?'));
                $sqlUpd = "UPDATE inventario SET cantidad = cantidad + ?, localchange=1, syncweb=0, sincalmacenes='AJUSTE_WEB' 
                           WHERE idalmacen=1 AND idproducto IN (SELECT idproducto FROM productos WHERE barcode IN ($ph))";
                
                $updStmt = $dbDestino->prepare($sqlUpd);
                $types = "d" . str_repeat("s", count($skus));
                $updStmt->bind_param($types, $difPorUnidad, ...$skus);
                $updStmt->execute();

                $mysqli->query("UPDATE conteoweb SET estado='C' WHERE id=$idConteo");
                
                $log = $mysqli->prepare("INSERT INTO historial_ajustes 
                    (id_conteo, usuario, nit_empresa, categoria, stock_anterior, diferencia_aplicada, stock_nuevo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $log->bind_param("isssddd", $idConteo, $usuarioActual, $nitFila, $CodCat, $stockAnterior, $difTotal, $stockNuevo);
                $log->execute();

                $dbDestino->commit();
                echo json_encode(["status" => "success", "nombre" => $CodCat]);
                exit;
            } catch (Exception $e) {
                $dbDestino->rollback();
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
                exit;
            }
        }
    }
    echo json_encode(["status" => "error", "message" => "No se encontró el registro"]);
    exit;
}

$resPendientes = $mysqli->query("SELECT c.*, cat.Nombre 
    FROM conteoweb c 
    INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
    WHERE c.estado = 'A' AND ABS(c.diferencia) > 0.2 AND DATE(c.fecha_conteo) = '$hoy'
    ORDER BY c.id DESC");

$resHistorial = $mysqli->query("SELECT h.*, cat.Nombre 
    FROM historial_ajustes h
    LEFT JOIN categorias cat ON cat.CodCat = h.categoria
    WHERE DATE(h.fecha_ajuste) = '$hoy'
    ORDER BY h.fecha_ajuste DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SIA | Control Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .table thead th { border: none; background: #f8fafc; font-size: 0.7rem; color: #94a3b8; }
        .row-fade-out { opacity: 0; transform: translateX(20px); transition: all 0.5s ease; }
        .btn-primary { background: #4361ee; border: none; }
        .btn-primary:hover { background: #3f37c9; }
        .loading-overlay { display:none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:9999; justify-content:center; align-items:center; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loader"><div class="spinner-border text-primary"></div></div>

<nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold"><i class="bi bi-cpu me-2"></i>SIA INVENTARIOS</span>
        <span class="text-white-50 small">Sesión: <?= $usuarioActual ?></span>
    </div>
</nav>

<div class="container">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">DIFERENCIAS PENDIENTES</h6>
                    <div class="input-group input-group-sm" style="width: 250px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchInput" class="form-control bg-light border-start-0" placeholder="Filtrar...">
                        <button onclick="location.reload()" class="btn btn-outline-secondary border-start-0"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table align-middle mb-0" id="tablePendientes">
                        <thead>
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
                                    <div class="text-muted" style="font-size: 11px;"><?= $r['CodCat'] ?> | <?= ($r['NitEmpresa'] === '901724534-7') ? 'DRINKS' : 'CENTRAL' ?></div>
                                </td>
                                <td class="text-end text-muted"><?= number_format($r['stock_sistema'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill <?= $r['diferencia'] < 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>">
                                        <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button onclick="procesarAjuste(<?= $r['id'] ?>)" class="btn btn-primary btn-sm px-3 rounded-pill">Ajustar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white py-3 fw-bold">ÚLTIMOS MOVIMIENTOS</div>
                <div class="card-body p-0">
                    <div id="historialList" class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                        <?php while($h = $resHistorial->fetch_assoc()): ?>
                            <div class="list-group-item border-0 border-bottom py-3">
                                <div class="d-flex justify-content-between">
                                    <small class="fw-bold text-uppercase"><?= $h['Nombre'] ?></small>
                                    <small class="text-muted"><?= date("H:i", strtotime($h['fecha_ajuste'])) ?></small>
                                </div>
                                <div class="text-muted small">Ajustado: <strong><?= number_format($h['diferencia_aplicada'], 2) ?></strong> unid.</div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Función AJAX para procesar sin recargar
function procesarAjuste(id) {
    if(!confirm("¿Desea aplicar este ajuste al inventario real?")) return;

    document.getElementById('loader').style.display = 'flex';

    const formData = new FormData();
    formData.append('accion', 'ajustar_ajax');
    formData.append('id_conteo', id);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('loader').style.display = 'none';
        if(data.status === 'success') {
            const fila = document.getElementById('fila-' + id);
            fila.classList.add('row-fade-out');
            setTimeout(() => { 
                fila.remove(); 
                // Opcional: Podrías actualizar el historial aquí también sin recargar
                location.reload(); 
            }, 500);
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(error => {
        document.getElementById('loader').style.display = 'none';
        console.error('Error:', error);
    });
}

// Filtro de búsqueda
document.getElementById('searchInput').addEventListener('keyup', function() {
    let filter = this.value.toUpperCase();
    let rows = document.querySelector("#tablePendientes tbody").rows;
    for (let i = 0; i < rows.length; i++) {
        let text = rows[i].cells[0].textContent.toUpperCase();
        rows[i].style.display = (text.indexOf(filter) > -1) ? "" : "none";
    }
});
</script>

</body>
</html>