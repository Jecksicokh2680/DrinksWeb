<?php
// ====================================================================
// 1. CONFIGURACIÓN Y LÓGICA
// ====================================================================
date_default_timezone_set('America/Bogota');
require 'ConnCentral.php'; 
$connCentral = $mysqliCentral;
require 'ConnDrinks.php'; 
$connDrinks = $mysqliDrinks;

$is_ajax_filter = isset($_POST['action']) && $_POST['action'] === 'filter';
$is_ajax_save = isset($_POST['action']) && $_POST['action'] === 'save';

if ($is_ajax_save) {
    header('Content-Type: application/json');
    $barcode = $_POST['barcode'] ?? ''; 
    $estado = intval($_POST['estado'] ?? 0);
    $precioventa_c = floatval($_POST['precioventa_c'] ?? 0);
    $precioespecial1_c = floatval($_POST['precioespecial1_c'] ?? 0);
    $precioespecial2_c = floatval($_POST['precioespecial2_c'] ?? 0);
    $precioventa_d = floatval($_POST['precioventa_d'] ?? 0);
    $precioespecial1_d = floatval($_POST['precioespecial1_d'] ?? 0);
    $precioespecial2_d = floatval($_POST['precioespecial2_d'] ?? 0);

    $msgs = [];
    if (!empty($barcode)) {
        if ($connCentral) {
            $stmtC = $connCentral->prepare("UPDATE productos SET precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ? WHERE barcode = ?");
            $typeStr = "dddis" . (is_numeric($barcode) ? "" : ""); // Simplificado bind_param
            $stmtC->bind_param("dddis", $precioventa_c, $precioespecial1_c, $precioespecial2_c, $estado, $barcode);
            $msgs[] = $stmtC->execute() ? "Cen: OK" : "Cen: Error";
            $stmtC->close();
        }
        if ($connDrinks) {
            $stmtD = $connDrinks->prepare("UPDATE productos SET precioventa = ?, precioespecial1 = ?, precioespecial2 = ?, estado = ? WHERE barcode = ?");
            $stmtD->bind_param("dddis", $precioventa_d, $precioespecial1_d, $precioespecial2_d, $estado, $barcode);
            $msgs[] = $stmtD->execute() ? "Dri: OK" : "Dri: Error";
            $stmtD->close();
        }
    }
    echo json_encode(['success' => true, 'message' => implode(" | ", $msgs)]);
    exit;
}

if ($is_ajax_filter) {
    header('Content-Type: application/json');
    $filtro = trim($_POST['filtro'] ?? '');
    $estado_filtro = $_POST['estado'] ?? 'todos';
    
    $sql = "SELECT barcode, descripcion, costo, precioventa, precioespecial1, precioespecial2, estado FROM productos WHERE 1=1";
    if ($estado_filtro !== 'todos') $sql .= " AND estado = $estado_filtro";
    if ($filtro !== '') {
        $sql .= is_numeric($filtro) ? " AND barcode LIKE '$filtro%'" : " AND descripcion LIKE '%$filtro%'";
    }
    $sql .= " ORDER BY barcode ASC LIMIT 100";
    $res = $connCentral->query($sql);
    
    $barcodes = [];
    $productos = [];
    while($row = $res->fetch_assoc()){
        $productos[] = $row;
        $barcodes[] = "'" . $row['barcode'] . "'";
    }

    $promediosCompra = [];
    if (!empty($barcodes)) {
        $sqlP = "SELECT P.Barcode, 
                 SUM(( (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * (1 + D.porciva / 100) + D.ValICUIUni ) * D.CANTIDAD) / NULLIF(SUM(D.CANTIDAD), 0) as prom
                 FROM DETCOMPRAS D
                 JOIN compras C ON C.idcompra = D.idcompra
                 JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
                 WHERE C.ESTADO = '0' AND P.Barcode IN (".implode(',', $barcodes).")
                 AND C.FECHA >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 30 DAY), '%Y%m%d')
                 GROUP BY P.Barcode";
        $resP = $connCentral->query($sqlP);
        if ($resP) { while ($rowP = $resP->fetch_assoc()) { $promediosCompra[$rowP['Barcode']] = $rowP['prom']; } }
    }

    $preciosDrinks = [];
    if(!empty($barcodes)){
        $resD = $connDrinks->query("SELECT barcode, precioventa, precioespecial1, precioespecial2 FROM productos WHERE barcode IN (".implode(',',$barcodes).")");
        while($rd = $resD->fetch_assoc()) $preciosDrinks[$rd['barcode']] = $rd;
    }

    $html = '';
    foreach ($productos as $p) {
        $bc = $p['barcode'];
        $pD = $preciosDrinks[$bc] ?? ['precioventa'=>0, 'precioespecial1'=>0, 'precioespecial2'=>0];
        $promC = $promediosCompra[$bc] ?? 0;
        $baseCalculo = ($promC > 0) ? $promC : $p['costo'];
        
        $mkC = ($baseCalculo > 0) ? (($p['precioventa'] / $baseCalculo) - 1) * 100 : 0;
        $mkD = ($baseCalculo > 0) ? (($pD['precioventa'] / $baseCalculo) - 1) * 100 : 0;

        $html .= '<tr data-barcode="'.htmlspecialchars($bc).'">
            <td class="fw-bold text-center">'.$bc.'</td>
            <td class="fw-bold text-uppercase" style="font-size: 10px;">'.htmlspecialchars($p['descripcion']).'</td>
            <td class="col-money border-end">'.number_format($p['costo'],0).'</td>
            <td class="col-money bg-light fw-bold">'.($promC > 0 ? number_format($promC, 0) : '-').'</td>
            <td class="bg-primary bg-opacity-10"><input type="number" class="form-control form-control-sm ev-c fw-bold" value="'.round($p['precioventa']).'"></td>
            <td class="bg-primary bg-opacity-10"><input type="number" class="form-control form-control-sm e1-c" value="'.round($p['precioespecial1']).'"></td>
            <td class="bg-primary bg-opacity-10"><input type="number" class="form-control form-control-sm e2-c" value="'.round($p['precioespecial2']).'"></td>
            <td class="bg-warning bg-opacity-10"><input type="number" class="form-control form-control-sm ev-d fw-bold" value="'.round($pD['precioventa']).'"></td>
            <td class="bg-warning bg-opacity-10"><input type="number" class="form-control form-control-sm e1-d" value="'.round($pD['precioespecial1']).'"></td>
            <td class="bg-warning bg-opacity-10"><input type="number" class="form-control form-control-sm e2-d" value="'.round($pD['precioespecial2']).'"></td>
            <td class="col-mk fw-bold '.($mkC < 0 ? 'text-danger':'text-success').'">'.number_format($mkC, 1).'%</td>
            <td class="col-mk fw-bold '.($mkD < 0 ? 'text-danger':'text-success').'">'.number_format($mkD, 1).'%</td>
            <td>
                <select class="form-select form-select-sm e-est col-est">
                    <option value="1" '.($p['estado']==1?'selected':'').'>A</option>
                    <option value="0" '.($p['estado']==0?'selected':'').'>I</option>
                </select>
            </td>
            <td class="text-center"><button class="btn btn-success btn-sm btn-save px-1"><i class="bi bi-save"></i></button></td>
        </tr>';
    }
    echo json_encode(['html' => $html]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIA | Sincronización</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; font-size: 11px; padding: 5px; }
        .container-fluid { padding: 0; }
        .card { border-radius: 8px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .table th, .table td { padding: 2px 1px !important; vertical-align: middle; }
        
        input.form-control-sm { font-size: 11px; padding: 1px 3px; height: 22px; }
        input[type="number"] { width: 65px !important; }
        
        .col-money { width: 55px !important; text-align: right; padding-right: 3px !important; font-size: 10px; }
        .col-mk { width: 40px !important; text-align: center; font-size: 9px; }
        .col-est { width: 36px !important; padding: 1px !important; font-size: 10px; height: 22px; }
        
        th { font-size: 10px; text-align: center; white-space: nowrap; }
        .h-c { background-color: #0d6efd !important; color: white; }
        .h-d { background-color: #fd7e14 !important; color: white; }
        
        #msg { position: fixed; top: 10px; right: 10px; z-index: 2000; padding: 8px; border-radius: 5px; color: white; display: none; font-size: 11px; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="card p-2">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold m-0 text-primary"><i class="bi bi-arrow-left-right"></i> SINCRONIZACIÓN</h6>
            <div class="d-flex gap-1">
                <input type="text" id="busqueda" class="form-control form-control-sm" placeholder="Buscar..." style="width: 160px;">
                <select id="filtro-est" class="form-select form-select-sm" style="width: 80px;">
                    <option value="todos">Todos</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
                <button onclick="location.reload()" class="btn btn-light btn-sm border px-2"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th rowspan="2">COD</th>
                        <th rowspan="2">PRODUCTO</th>
                        <th rowspan="2">COSTO</th>
                        <th rowspan="2" class="bg-secondary">PROM</th>
                        <th colspan="3" class="h-c">CENTRAL</th>
                        <th colspan="3" class="h-d">DRINKS</th>
                        <th colspan="2">MK%</th>
                        <th rowspan="2">EST</th>
                        <th rowspan="2">OK</th>
                    </tr>
                    <tr>
                        <th class="h-c small">VTA</th> <th class="h-c small">P1</th> <th class="h-c small">P2</th>
                        <th class="h-d small">VTA</th> <th class="h-d small">P1</th> <th class="h-d small">P2</th>
                        <th class="col-mk">C</th> <th class="col-mk">D</th>
                    </tr>
                </thead>
                <tbody id="lista"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="msg"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    let t;
    function load() {
        $.post('', { action: 'filter', filtro: $('#busqueda').val(), estado: $('#filtro-est').val() }, function(r) { 
            $('#lista').html(r.html); 
        }, 'json');
    }
    $('#busqueda').on('keyup', function() { clearTimeout(t); t = setTimeout(load, 400); });
    $('#filtro-est').on('change', load);

    $(document).on('click', '.btn-save', function() {
        const tr = $(this).closest('tr');
        const btn = $(this);
        const data = {
            action: 'save',
            barcode: tr.data('barcode'),
            precioventa_c: tr.find('.ev-c').val(),
            precioespecial1_c: tr.find('.e1-c').val(),
            precioespecial2_c: tr.find('.e2-c').val(),
            precioventa_d: tr.find('.ev-d').val(),
            precioespecial1_d: tr.find('.e1-d').val(),
            precioespecial2_d: tr.find('.e2-d').val(),
            estado: tr.find('.e-est').val()
        };
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        $.post('', data, function(r) {
            $('#msg').text(r.message).css('background', '#198754').fadeIn().delay(1500).fadeOut();
            btn.prop('disabled', false).html('<i class="bi bi-save"></i>');
        }, 'json');
    });
    load();
});
</script>
</body>
</html>