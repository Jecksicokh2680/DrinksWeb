<?php
// ====================================================================
// AUDITORÍA DE DUPLICADOS CON RESALTADO DE DIFERENCIA > 1.000
// ====================================================================
require 'ConnCentral.php'; 
require 'ConnDrinks.php'; 

// --- LÓGICA PARA ACTUALIZACIONES AJAX ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $bc = $_POST['barcode'];
    $sede = $_POST['sede'];
    $db = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;

    if ($_POST['action'] === 'toggle_status') {
        $nuevo_estado = intval($_POST['nuevo_estado']);
        $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
        $stmt->bind_param("is", $nuevo_estado, $bc);
        echo json_encode(['success' => $stmt->execute()]);
    }

    if ($_POST['action'] === 'update_price') {
        $nuevo_precio = floatval($_POST['nuevo_precio']);
        $stmt = $db->prepare("UPDATE productos SET precioventa = ? WHERE barcode = ?");
        $stmt->bind_param("ds", $nuevo_precio, $bc);
        echo json_encode(['success' => $stmt->execute()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Auditoría de Precios | SIA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; font-size: 13px; }
        /* Resaltado de fila cuando la diferencia es > 1.000 */
        .fila-alerta { background-color: #fff3cd !important; transition: background 0.3s; }
        .fila-alerta td { border-bottom: 2px solid #ffc107 !important; }
        
        .badge-diff { background: #856404; color: #fff; font-size: 10px; padding: 3px 7px; border-radius: 5px; }
        .price-input { width: 100px !important; text-align: right; font-weight: bold; }
        
        /* Switch */
        .switch { position: relative; display: inline-block; width: 34px; height: 18px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
        .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #198754; }
        input:checked + .slider:before { transform: translateX(16px); }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-search text-warning"></i> Duplicados con Diferencia de Precios</h5>
            <button onclick="location.reload()" class="btn btn-dark btn-sm">
                <i class="bi bi-arrow-clockwise"></i> Refrescar Datos
            </button>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 35%;">Descripción del Producto</th>
                        <th class="text-center">Reps</th>
                        <th>Gestión por Sede (Central / Drinks)</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $resC = $mysqliCentral->query("SELECT barcode, descripcion, precioventa, 'Central' as sede FROM productos WHERE estado = 1");
                $resD = $mysqliDrinks->query("SELECT barcode, descripcion, precioventa, 'Drinks' as sede FROM productos WHERE estado = 1");

                $conteo = [];
                while($r = $resC->fetch_assoc()){
                    $desc = strtoupper(trim($r['descripcion']));
                    $conteo[$desc]['detalles'][] = $r;
                    $conteo[$desc]['precios'][] = $r['precioventa'];
                }
                while($r = $resD->fetch_assoc()){
                    $desc = strtoupper(trim($r['descripcion']));
                    $conteo[$desc]['detalles'][] = $r;
                    $conteo[$desc]['precios'][] = $r['precioventa'];
                }

                // Filtramos solo los que se repiten
                $duplicados = array_filter($conteo, function($v) { return count($v['detalles']) > 1; });
                // Ordenamos descendente por cantidad de repeticiones
                uasort($duplicados, function($a, $b) { return count($b['detalles']) <=> count($a['detalles']); });

                foreach ($duplicados as $nombre => $datos):
                    $maxP = max($datos['precios']);
                    $minP = min($datos['precios']);
                    $diferencia = $maxP - $minP;
                    $esAlerta = ($diferencia > 1000);
                ?>
                    <tr class="<?= $esAlerta ? 'fila-alerta' : '' ?>">
                        <td>
                            <span class="fw-bold"><?= $nombre ?></span>
                            <?php if($esAlerta): ?>
                                <br><span class="badge-diff"><i class="bi bi-exclamation-circle"></i> Diferencia: $<?= number_format($diferencia,0) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= count($datos['detalles']) ?></span>
                        </td>
                        <td>
                            <?php foreach ($datos['detalles'] as $d): ?>
                            <div class="d-flex align-items-center justify-content-between p-2 mb-1 bg-white rounded shadow-sm border">
                                <div style="min-width: 120px;">
                                    <small class="fw-bold text-primary"><?= $d['sede'] ?></small><br>
                                    <small class="text-muted"><?= $d['barcode'] ?></small>
                                </div>
                                
                                <div class="d-flex align-items-center gap-2">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control price-input" value="<?= round($d['precioventa']) ?>" id="input-<?= $d['barcode'] ?>-<?= $d['sede'] ?>">
                                        <button class="btn btn-success btn-update" data-bc="<?= $d['barcode'] ?>" data-sede="<?= $d['sede'] ?>">
                                            <i class="bi bi-save"></i>
                                        </button>
                                    </div>

                                    <div class="ms-3 text-end" style="min-width: 100px;">
                                        <label class="switch">
                                            <input type="checkbox" checked class="toggle-estado" data-bc="<?= $d['barcode'] ?>" data-sede="<?= $d['sede'] ?>">
                                            <span class="slider"></span>
                                        </label>
                                        <span class="status-label fw-bold text-success" style="font-size: 10px; margin-left: 5px;">ACTIVO</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Actualizar Precio
    $('.btn-update').click(function() {
        const btn = $(this);
        const bc = btn.data('bc');
        const sede = btn.data('sede');
        const precio = $(`#input-${bc}-${sede}`).val();

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.post('', { action: 'update_price', barcode: bc, sede: sede, nuevo_precio: precio }, function(r) {
            if(r.success) {
                btn.removeClass('btn-success').addClass('btn-primary').html('<i class="bi bi-check-all"></i>');
                setTimeout(() => { btn.removeClass('btn-primary').addClass('btn-success').html('<i class="bi bi-save"></i>').prop('disabled', false); }, 800);
            }
        });
    });

    // Inhabilitar
    $('.toggle-estado').change(function() {
        const chk = $(this);
        const bc = chk.data('bc');
        const sede = chk.data('sede');
        const isChecked = chk.is(':checked');
        const label = chk.parent().next('.status-label');

        if(!isChecked) {
            label.text('INACTIVO').removeClass('text-success').addClass('text-danger');
            chk.closest('.d-flex').css('opacity', '0.4');
        } else {
            label.text('ACTIVO').removeClass('text-danger').addClass('text-success');
            chk.closest('.d-flex').css('opacity', '1');
        }

        $.post('', { action: 'toggle_status', barcode: bc, sede: sede, nuevo_estado: isChecked ? 1 : 0 }, function(r) {
            if(!r.success) alert('Error en servidor');
        });
    });
});
</script>
</body>
</html>