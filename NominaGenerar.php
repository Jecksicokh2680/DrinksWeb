<?php
session_start();
require 'Conexion.php';     // Base Local ($mysqli)
include 'ConnCentral.php';  // Base Central ($mysqliPos)

$self = basename($_SERVER['PHP_SELF']);

// 1. VARIABLES DE SESIÓN (Configuradas el 2026-01-15)
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? '';
$Usuario     = $_SESSION['Usuario'] ?? '';
$NroSucursal = $_SESSION['NroSucursal'] ?? '';

// --- 2. LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $mysqli->query("DELETE FROM nomina_pagos WHERE id = $id AND NitEmpresa = '$NitEmpresa'");
    header("Location: $self"); 
    exit();
}

// --- 3. LÓGICA DE GUARDADO (ACTUALIZA SI YA EXISTE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['CedulaNit'])) {
    $cedula  = $mysqli->real_escape_string($_POST['CedulaNit']);
    $periodo = (date('j') <= 15 ? "1ra Quinc" : "2da Quinc") . " " . date('m-Y');
    
    $vHora     = floatval($_POST['v_hora_hidden']); 
    $basico    = floatval($_POST['basico']);
    $aux_trans = floatval($_POST['aux_transp']);
    $bonos     = floatval($_POST['bonificaciones']);
    $desc      = floatval($_POST['descuentos']);
    $salud     = floatval($_POST['salud']);
    $pension   = floatval($_POST['pension']);
    $dias_lab  = intval($_POST['dias_laborados']);
    
    $c_hed  = floatval($_POST['cant_hed']);
    $c_rn   = floatval($_POST['cant_rn']);
    $c_dom  = floatval($_POST['cant_dom']);
    $c_rndf = floatval($_POST['cant_rndf']);
    
    $valor_extras_total = ($c_hed * ($vHora * 1.25)) + ($c_rn * ($vHora * 0.35)) + 
                          ($c_dom * ($vHora * 1.75)) + ($c_rndf * ($vHora * 2.10));
    $total_neto = floatval($_POST['total_pagado']);

    $sql = "INSERT INTO nomina_pagos (
        CedulaNit, fecha_pago, periodo, salario_base, auxilio_transporte, 
        bonificaciones, descuentos, total_pagado, UsuarioRegistra, 
        NitEmpresa, NroSucursal, salud, pension, dias_laborados, 
        cant_hed, cant_rn, cant_dom, cant_rndf, valor_extras_total
    ) VALUES (
        '$cedula', NOW(), '$periodo', '$basico', '$aux_trans', 
        '$bonos', '$desc', '$total_neto', 
        '$Usuario', '$NitEmpresa', '$NroSucursal', '$salud', '$pension', 
        '$dias_lab', '$c_hed', '$c_rn', '$c_dom', '$c_rndf', '$valor_extras_total'
    ) ON DUPLICATE KEY UPDATE 
        fecha_pago = NOW(), salario_base = VALUES(salario_base), auxilio_transporte = VALUES(auxilio_transporte),
        bonificaciones = VALUES(bonificaciones), descuentos = VALUES(descuentos), total_pagado = VALUES(total_pagado),
        UsuarioRegistra = '$Usuario', salud = VALUES(salud), pension = VALUES(pension), dias_laborados = VALUES(dias_laborados),
        cant_hed = VALUES(cant_hed), cant_rn = VALUES(cant_rn), cant_dom = VALUES(cant_dom), 
        cant_rndf = VALUES(cant_rndf), valor_extras_total = VALUES(valor_extras_total)";

    if($mysqli->query($sql)) header("Location: $self?success=1");
    else die("Error: " . $mysqli->error);
    exit();
}

// --- 4. CONSULTA DE COLABORADOR ---
$colaborador = null;
$nombreCompletoForm = "";
if (!empty($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $colaborador = $res->fetch_assoc();
    if($colaborador){
        $resNom = $mysqliPos->query("SELECT nombres, nombre2, apellidos, apellido2 FROM terceros WHERE nit = '$ced' LIMIT 1");
        if($t = $resNom->fetch_assoc()){
            $nombreCompletoForm = trim("{$t['nombres']} {$t['nombre2']} {$t['apellidos']} {$t['apellido2']}");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sincronización de Nómina 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .card-nomina { border-top: 5px solid #2c3e50; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .lbl-ley { font-size: 10px; font-weight: 800; color: #7f8c8d; text-transform: uppercase; }
        .bg-resumen { background: #2c3e50; color: white; border-radius: 8px; padding: 20px; }
        .table-cronograma { font-size: 0.7rem; text-align: center; }
        .bg-festivo { background-color: #ff00ff !important; color: white !important; font-weight: bold; }
        .bg-dia { background-color: #ffff00 !important; font-weight: bold; color: #000; }
        .bg-vacio { background-color: #00ff00; font-weight: bold; color: #000; }
        .input-cron { width: 38px; border: 1px solid #ddd; text-align: center; font-size: 0.75rem; padding: 2px; }
        .table-xs { font-size: 0.75rem; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 1350px;">
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm text-center fw-bold">✅ PROCESADO CORRECTAMENTE (INSERT/UPDATE)</div>
    <?php endif; ?>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-secondary">LIQUIDACIÓN | <?= $Usuario ?> | SUCURSAL <?= $NroSucursal ?></h6>
                <button type="button" class="btn btn-dark btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalProcesados">📂 HISTORIAL DE PAGOS</button>
            </div>
            <form method="GET" action="<?= $self ?>" class="row g-2">
                <div class="col-md-10">
                    <select name="cedula" class="form-select border-primary shadow-sm">
                        <option value="">-- Buscar colaborador para liquidar --</option>
                        <?php
                        $list = $mysqli->query("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = '$NitEmpresa' AND estado = 'ACTIVO'");
                        while($row = $list->fetch_assoc()) echo "<option value='{$row['CedulaNit']}' ".(($_GET['cedula']??'')==$row['CedulaNit']?'selected':'').">{$row['CedulaNit']}</option>";
                        ?>
                    </select>
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold">CARGAR</button></div>
            </form>
        </div>
    </div>

    <?php if ($colaborador): 
        $sueldo_q = $colaborador['salario'] / 2;
        $v_h = $colaborador['salario'] / 240; 
        $diaActual = (int)date('j'); $mesActual = (int)date('m'); $anioActual = (int)date('Y');
        if ($diaActual <= 15) { $inicio = 1; $fin = 15; } else { $inicio = 16; $fin = date('t'); }
        $nombresDias = ["DOM", "LUN", "MAR", "MIÉ", "JUE", "VIE", "SÁB"];
    ?>
    <div class="card card-nomina border-0">
        <form action="<?= $self ?>" method="POST">
            <input type="hidden" id="v_hora" name="v_hora_hidden" value="<?= $v_h ?>">
            <input type="hidden" name="CedulaNit" value="<?= $colaborador['CedulaNit'] ?>">
            <input type="hidden" name="total_pagado" id="input_neto">

            <div class="card-body p-4">
                <div class="row mb-3">
                    <div class="col-md-8">
                        <h2 class="fw-bold text-dark mb-0"><?= $nombreCompletoForm ?></h2>
                        <span class="text-muted fw-bold">NIT/CC: <?= $colaborador['CedulaNit'] ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <label class="lbl-ley">Devengado Quincenal</label>
                        <h3 class="fw-bold text-primary mb-0">$<?= number_format($sueldo_q) ?></h3>
                    </div>
                </div>

                <div class="table-responsive mb-4 border shadow-sm">
                    <table class="table table-bordered table-cronograma align-middle mb-0">
                        <thead>
                            <tr class="bg-light">
                                <th rowspan="2" class="bg-vacio">Novedad</th>
                                <?php for($i=$inicio; $i<=$fin; $i++): 
                                    $fechaUnix = mktime(0, 0, 0, $mesActual, $i, $anioActual);
                                    $nomDia = $nombresDias[date('w', $fechaUnix)];
                                    $esFest = (date('w', $fechaUnix) == 0 || ($mesActual==1 && ($i==1 || $i==12)));
                                ?>
                                <th class="<?= $esFest ? 'bg-festivo' : 'bg-dia' ?>"><?= str_pad($i, 2, "0", STR_PAD_LEFT) ?><br><?= $nomDia ?></th>
                                <?php endfor; ?>
                                <th class="bg-dark text-white">CANT.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $filas = [
                                "Días Lab" => ["code"=>"d_lab", "init"=>1],
                                "Rec Noct" => ["code"=>"r_noc", "init"=>""],
                                "Ext Diur" => ["code"=>"ext",   "init"=>""],
                                "Dom/Fest" => ["code"=>"h_df",  "init"=>""],
                                "RN Fest"  => ["code"=>"rn_df", "init"=>""]
                            ];
                            foreach($filas as $lbl => $dt): ?>
                            <tr>
                                <td class="text-start ps-2 fw-bold"><?= $lbl ?></td>
                                <?php for($i=$inicio; $i<=$fin; $i++): ?>
                                    <td><input type="number" step="0.5" class="input-cron" name="cron[<?= $dt['code'] ?>][<?= $i ?>]" value="<?= $dt['init'] ?>"></td>
                                <?php endfor; ?>
                                <td class="bg-light fw-bold" id="total_<?= $dt['code'] ?>">0</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <h6 class="text-success fw-bold">➕ DEVENGADOS</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4"><label class="lbl-ley">Días Trabajados</label><input type="number" name="dias_laborados" id="inp_d_lab" class="form-control text-center fw-bold" readonly></div>
                            <div class="col-md-4"><label class="lbl-ley">Básico</label><input type="number" name="basico" id="basico" class="form-control bg-light" value="<?= $sueldo_q ?>" readonly></div>
                            <div class="col-md-4"><label class="lbl-ley">Aux Transp</label><input type="number" name="aux_transp" id="aux_transp" class="form-control calc" value="124547"></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3"><label class="lbl-ley">HED</label><input type="number" name="cant_hed" id="hed" class="form-control calc" value="0"></div>
                            <div class="col-md-3"><label class="lbl-ley">RN</label><input type="number" name="cant_rn" id="rn" class="form-control calc" value="0"></div>
                            <div class="col-md-3"><label class="lbl-ley">DOM</label><input type="number" name="cant_dom" id="dom" class="form-control calc" value="0"></div>
                            <div class="col-md-3"><label class="lbl-ley">RNDF</label><input type="number" name="cant_rndf" id="rndf" class="form-control calc" value="0"></div>
                        </div>
                        <input type="hidden" name="cant_hen" value="0">
                        <div class="mt-2"><label class="lbl-ley">Bonificaciones</label><input type="number" name="bonificaciones" id="bonos" class="form-control calc" value="0"></div>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-danger fw-bold">➖ DEDUCCIONES</h6>
                        <div class="mb-2"><label class="lbl-ley">Salud (4%)</label><input type="number" name="salud" id="salud" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Pensión (4%)</label><input type="number" name="pension" id="pension" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Otros Descuentos</label><input type="number" name="descuentos" id="desc" class="form-control calc border-warning" value="0"></div>
                    </div>
                </div>

                <div class="bg-resumen mt-4 d-flex justify-content-between align-items-center">
                    <div><small class="text-uppercase opacity-75 fw-bold" style="font-size: 10px;">Neto a Pagar:</small><h1 id="txt_neto" class="fw-bold mb-0 text-info">$ 0</h1></div>
                    <button type="submit" class="btn btn-light btn-lg fw-bold px-5">💾 GUARDAR/ACTUALIZAR PAGO</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalProcesados" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl" style="max-width: 95%;">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Historial Técnico de Nómina</h5>
                <button onclick="exportTableToExcel('tblNomina', 'Nomina_Detallada')" class="btn btn-success btn-sm ms-3 fw-bold">📊 EXCEL</button>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-xs align-middle mb-0" id="tblNomina">
                        <thead class="table-secondary">
                            <tr>
                                <th class="ps-3">Identificación</th><th>Nombre</th><th>Periodo</th><th>Días</th><th>HED</th><th>RN</th><th>DOM</th><th>RNDF</th><th>Salud</th><th>Pens.</th><th>Dcto</th><th class="text-end fw-bold">Total</th><th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $res_h = $mysqli->query("SELECT * FROM nomina_pagos WHERE NitEmpresa = '$NitEmpresa' ORDER BY id DESC");
                            while ($p = $res_h->fetch_assoc()) {
                                $nit_p = $p['CedulaNit'];
                                $qNom = $mysqliPos->query("SELECT nombres, nombre2, apellidos, apellido2 FROM terceros WHERE nit = '$nit_p' LIMIT 1");
                                $nomH = ($tH = $qNom->fetch_assoc()) ? trim("{$tH['nombres']} {$tH['nombre2']} {$tH['apellidos']} {$tH['apellido2']}") : "CC: $nit_p";
                                echo "<tr>
                                    <td class='ps-3 fw-bold'>{$p['CedulaNit']}</td><td style='font-size: 10px;'>$nomH</td><td>{$p['periodo']}</td><td class='text-center'>{$p['dias_laborados']}</td>
                                    <td class='text-center'>{$p['cant_hed']}</td><td class='text-center'>{$p['cant_rn']}</td><td class='text-center'>{$p['cant_dom']}</td><td class='text-center'>{$p['cant_rndf']}</td>
                                    <td class='text-end'>$".number_format($p['salud'])."</td><td class='text-end'>$".number_format($p['pension'])."</td><td class='text-end'>$".number_format($p['descuentos'])."</td>
                                    <td class='text-end fw-bold text-primary'>$".number_format($p['total_pagado'])."</td>
                                    <td class='text-center'><a href='$self?eliminar={$p['id']}' class='btn btn-sm btn-outline-danger' onclick='return confirm(\"¿Borrar?\")'>🗑</a></td>
                                </tr>";
                            } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function calcular() {
    if(!document.getElementById('basico')) return;
    const vH = parseFloat(document.getElementById('v_hora').value || 0);
    let basico = parseFloat(document.getElementById('basico').value) || 0;
    let auxT   = parseFloat(document.getElementById('aux_transp').value) || 0;
    let c_hed = (parseFloat(document.getElementById('hed').value)||0)*(vH*1.25);
    let c_rn = (parseFloat(document.getElementById('rn').value)||0)*(vH*0.35);
    let c_dom = (parseFloat(document.getElementById('dom').value)||0)*(vH*1.75);
    let c_rndf = (parseFloat(document.getElementById('rndf').value)||0)*(vH*2.10);
    let bonos = parseFloat(document.getElementById('bonos').value) || 0;
    let ibc = basico + c_hed + c_rn + c_dom + c_rndf + bonos;
    let salud = Math.round(ibc * 0.04), pension = Math.round(ibc * 0.04);
    document.getElementById('salud').value = salud; document.getElementById('pension').value = pension;
    let neto = (ibc + auxT) - (salud + pension + (parseFloat(document.getElementById('desc').value) || 0));
    document.getElementById('txt_neto').innerText = "$" + Math.round(neto).toLocaleString('es-CO');
    document.getElementById('input_neto').value = Math.round(neto);
}

function sumarCronograma() {
    const filas = { 'd_lab': 'inp_d_lab', 'r_noc': 'rn', 'ext': 'hed', 'h_df': 'dom', 'rn_df': 'rndf' };
    for (let key in filas) {
        let total = 0;
        document.querySelectorAll(`input[name^="cron[${key}]"]`).forEach(i => total += parseFloat(i.value) || 0);
        document.getElementById(`total_${key}`).innerText = total;
        document.getElementById(filas[key]).value = total;
    }
    calcular();
}

function exportTableToExcel(tableID, filename = ''){
    let tableSelect = document.getElementById(tableID);
    let tableHTML = tableSelect.outerHTML.replace(/<a.*?>.*?<\/a>/g, ''); 
    let downloadLink = document.createElement("a");
    downloadLink.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(tableHTML);
    downloadLink.download = filename + '.xls';
    downloadLink.click();
}

document.addEventListener('input', e => {
    if (e.target.classList.contains('input-cron')) sumarCronograma();
    if (e.target.classList.contains('calc')) calcular();
});

document.addEventListener('DOMContentLoaded', sumarCronograma);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>