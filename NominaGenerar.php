<?php
session_start();
require 'Conexion.php';     // Base Local ($mysqli)
include 'ConnCentral.php';  // Base Central ($mysqliPos)

$self = basename($_SERVER['PHP_SELF']);

// 1. VARIABLES DE SESIÓN
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? '';
$Usuario     = $_SESSION['Usuario'] ?? '';
$NroSucursal = $_SESSION['NroSucursal'] ?? '';
$periodoActual = (date('j') <= 15 ? "1ra Quinc" : "2da Quinc") . " " . date('m-Y');

// --- 2. LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $mysqli->query("DELETE FROM nomina_pagos WHERE id = $id AND NitEmpresa = '$NitEmpresa'");
    header("Location: $self"); 
    exit();
}

// --- 3. LÓGICA DE GUARDADO (INSERT O UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['CedulaNit'])) {
    $cedula  = $mysqli->real_escape_string($_POST['CedulaNit']);
    $periodo = $periodoActual;
    
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
    
    $cron_json = $mysqli->real_escape_string(json_encode($_POST['cron']));

    $valor_extras_total = ($c_hed * ($vHora * 1.25)) + ($c_rn * ($vHora * 0.35)) + 
                          ($c_dom * ($vHora * 1.75)) + ($c_rndf * ($vHora * 2.10));
    $total_neto = floatval($_POST['total_pagado']);

    $sql = "INSERT INTO nomina_pagos (
        CedulaNit, fecha_pago, periodo, salario_base, auxilio_transporte, 
        bonificaciones, descuentos, total_pagado, UsuarioRegistra, 
        NitEmpresa, NroSucursal, salud, pension, dias_laborados, 
        cant_hed, cant_rn, cant_dom, cant_rndf, valor_extras_total, cronograma_data
    ) VALUES (
        '$cedula', NOW(), '$periodo', '$basico', '$aux_trans', 
        '$bonos', '$desc', '$total_neto', 
        '$Usuario', '$NitEmpresa', '$NroSucursal', '$salud', '$pension', 
        '$dias_lab', '$c_hed', '$c_rn', '$c_dom', '$c_rndf', '$valor_extras_total', '$cron_json'
    ) ON DUPLICATE KEY UPDATE 
        fecha_pago = NOW(), salario_base = VALUES(salario_base), auxilio_transporte = VALUES(auxilio_transporte),
        bonificaciones = VALUES(bonificaciones), descuentos = VALUES(descuentos), total_pagado = VALUES(total_pagado),
        UsuarioRegistra = '$Usuario', salud = VALUES(salud), pension = VALUES(pension), dias_laborados = VALUES(dias_laborados),
        cant_hed = VALUES(cant_hed), cant_rn = VALUES(cant_rn), cant_dom = VALUES(cant_dom), 
        cant_rndf = VALUES(cant_rndf), valor_extras_total = VALUES(valor_extras_total), 
        cronograma_data = VALUES(cronograma_data)";

    if($mysqli->query($sql)) header("Location: $self?success=1&cedula=$cedula");
    else die("Error: " . $mysqli->error);
    exit();
}

// --- 4. CONSULTA DE COLABORADOR ---
$colaborador = null;
$nominaExistente = null;
$nombreCompletoForm = "";
if (!empty($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $colaborador = $res->fetch_assoc();
    
    $resPre = $mysqli->query("SELECT * FROM nomina_pagos WHERE CedulaNit = '$ced' AND periodo = '$periodoActual' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $nominaExistente = $resPre->fetch_assoc();

    if($colaborador && $mysqliPos){
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
    <title>Nómina Pro 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .card-nomina { border-top: 5px solid #2c3e50; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .lbl-ley { font-size: 10px; font-weight: 800; color: #7f8c8d; text-transform: uppercase; }
        .bg-resumen { background: #2c3e50; color: white; border-radius: 8px; padding: 20px; }
        .table-cronograma { font-size: 0.7rem; text-align: center; }
        .bg-festivo { background-color: #ff00ff !important; color: white !important; }
        .bg-dia { background-color: #ffff00 !important; color: #000; }
        .input-cron { width: 38px; border: 1px solid #ddd; text-align: center; font-size: 0.75rem; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 1350px;">
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm text-center fw-bold">✅ PROCESADO CORRECTAMENTE</div>
    <?php endif; ?>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-secondary">LIQUIDACIÓN | <?= $Usuario ?> | <?= $periodoActual ?></h6>
                <button type="button" class="btn btn-dark btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalProcesados">📂 VER PROCESADOS & EXPORTAR</button>
            </div>
            <form method="GET" action="<?= $self ?>" class="row g-2">
                <div class="col-md-10">
                    <select name="cedula" id="buscar_colaborador" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Buscar Colaborador --</option>
                        <?php
                        $list = $mysqli->query("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = '$NitEmpresa' AND estado = 'ACTIVO'");
                        while($row = $list->fetch_assoc()) {
                            $c_nit = $row['CedulaNit'];
                            $nom_sel = "Cargando...";
                            if($mysqliPos){
                                $qN = $mysqliPos->query("SELECT nombres, apellidos FROM terceros WHERE nit = '$c_nit' LIMIT 1");
                                if($tN = $qN->fetch_assoc()) $nom_sel = trim("{$tN['nombres']} {$tN['apellidos']}");
                            }
                            $sel = (($_GET['cedula']??'')==$c_nit?'selected':'');
                            echo "<option value='$c_nit' $sel>$c_nit - $nom_sel</option>";
                        }
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

        $cronPre = [];
        if($nominaExistente && !empty($nominaExistente['cronograma_data'])){
            $cronPre = json_decode($nominaExistente['cronograma_data'], true);
        }
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
                        <span class="text-muted fw-bold">Sueldo Base Mensual: $<?= number_format($colaborador['salario']) ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <label class="lbl-ley">Neto a Pagar</label>
                        <h1 id="txt_neto" class="fw-bold text-primary mb-0">$ 0</h1>
                    </div>
                </div>

                <div class="table-responsive mb-4 border shadow-sm">
                    <table class="table table-bordered table-cronograma align-middle mb-0">
                        <thead>
                            <tr class="bg-light">
                                <th rowspan="2" class="bg-dark text-white">Concepto</th>
                                <?php for($i=$inicio; $i<=$fin; $i++): 
                                    $fechaUnix = mktime(0, 0, 0, $mesActual, $i, $anioActual);
                                    $nomDia = $nombresDias[date('w', $fechaUnix)];
                                    $esFest = (date('w', $fechaUnix) == 0);
                                ?>
                                <th class="<?= $esFest ? 'bg-festivo' : 'bg-dia' ?>"><?= $i ?><br><?= $nomDia ?></th>
                                <?php endfor; ?>
                                <th class="bg-dark text-white">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $filas = ["Días Lab"=>"d_lab", "Rec Noct"=>"r_noc", "Ext Diur"=>"ext", "Dom/Fest"=>"h_df", "RN Fest"=>"rn_df"];
                            foreach($filas as $lbl => $code): ?>
                            <tr>
                                <td class="text-start ps-2 fw-bold"><?= $lbl ?></td>
                                <?php for($i=$inicio; $i<=$fin; $i++): 
                                    $def = ($code == 'd_lab') ? 1 : 0;
                                    $val = $cronPre[$code][$i] ?? $def;
                                ?>
                                    <td><input type="number" step="0.5" class="input-cron" name="cron[<?= $code ?>][<?= $i ?>]" value="<?= $val ?>"></td>
                                <?php endfor; ?>
                                <td class="bg-light fw-bold" id="total_<?= $code ?>">0</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <h6 class="text-success fw-bold">DEVENGADOS</h6>
                        <div class="row g-2">
                            <div class="col-md-3"><label class="lbl-ley">Días</label><input type="number" name="dias_laborados" id="inp_d_lab" class="form-control fw-bold" readonly></div>
                            <div class="col-md-3"><label class="lbl-ley">Básico</label><input type="number" name="basico" id="basico" class="form-control" readonly></div>
                            <div class="col-md-3"><label class="lbl-ley">Aux Transp</label><input type="number" name="aux_transp" id="aux_transp" class="form-control calc" value="<?= $nominaExistente['auxilio_transporte'] ?? 124547 ?>"></div>
                            <div class="col-md-3"><label class="lbl-ley">Bonos</label><input type="number" name="bonificaciones" id="bonos" class="form-control calc" value="<?= $nominaExistente['bonificaciones'] ?? 0 ?>"></div>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-md-3"><label class="lbl-ley">HED</label><input type="number" id="hed" class="form-control" name="cant_hed" readonly></div>
                            <div class="col-md-3"><label class="lbl-ley">RN</label><input type="number" id="rn" class="form-control" name="cant_rn" readonly></div>
                            <div class="col-md-3"><label class="lbl-ley">DOM</label><input type="number" id="dom" class="form-control" name="cant_dom" readonly></div>
                            <div class="col-md-3"><label class="lbl-ley">RNDF</label><input type="number" id="rndf" class="form-control" name="cant_rndf" readonly></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-danger fw-bold">DEDUCCIONES</h6>
                        <div class="mb-2"><label class="lbl-ley">Salud (4%)</label><input type="number" name="salud" id="salud" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Pensión (4%)</label><input type="number" name="pension" id="pension" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Otros Dctos</label><input type="number" name="descuentos" id="desc" class="form-control calc border-warning" value="<?= $nominaExistente['descuentos'] ?? 0 ?>"></div>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold px-5">💾 GUARDAR CAMBIOS Y LIQUIDAR</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalProcesados" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Historial de Pagos - <?= $periodoActual ?></h5>
                <div>
                    <button onclick="exportarExcel()" class="btn btn-success btn-sm fw-bold me-2">📊 EXPORTAR A EXCEL</button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <table class="table table-sm align-middle mb-0" id="tablaHistorial">
                    <thead class="table-secondary">
                        <tr>
                            <th>ID</th><th>Nombre</th><th>Días</th><th>HED</th><th>RN</th><th>DOM</th><th>RNDF</th>
                            <th>IBC</th><th>Deducciones</th><th>Total Neto</th><th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $res_h = $mysqli->query("SELECT * FROM nomina_pagos WHERE NitEmpresa = '$NitEmpresa' AND periodo = '$periodoActual' ORDER BY id DESC");
                        while ($p = $res_h->fetch_assoc()) {
                            $ced_p = $p['CedulaNit'];
                            $n_h = "Sincronizando...";
                            if($mysqliPos){
                                $qH = $mysqliPos->query("SELECT nombres, apellidos FROM terceros WHERE nit = '$ced_p' LIMIT 1");
                                if($tH = $qH->fetch_assoc()) $n_h = trim("{$tH['nombres']} {$tH['apellidos']}");
                            }
                            ?>
                            <tr>
                                <td><?= $ced_p ?></td>
                                <td><?= $n_h ?></td>
                                <td class="text-center"><?= $p['dias_laborados'] ?></td>
                                <td class="text-center"><?= $p['cant_hed'] ?></td>
                                <td class="text-center"><?= $p['cant_rn'] ?></td>
                                <td class="text-center"><?= $p['cant_dom'] ?></td>
                                <td class="text-center"><?= $p['cant_rndf'] ?></td>
                                <td>$<?= number_format($p['salario_base'] + $p['valor_extras_total']) ?></td>
                                <td class="text-danger">$<?= number_format($p['salud'] + $p['pension'] + $p['descuentos']) ?></td>
                                <td class="fw-bold text-primary">$<?= number_format($p['total_pagado']) ?></td>
                                <td><a href="<?= $self ?>?eliminar=<?= $p['id'] ?>" class="btn btn-sm btn-danger">🗑</a></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>

<script>
$(document).ready(function() {
    $('#buscar_colaborador').select2();
    if(document.getElementById('inp_d_lab')) sumarCronograma();
});

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

function calcular() {
    const sueldoQ = <?= $sueldo_q ?? 0 ?>;
    const vH = parseFloat(document.getElementById('v_hora').value || 0);
    const dias = parseFloat(document.getElementById('inp_d_lab').value || 0);
    
    // Cálculo proporcional: 15 días es el 100% de la quincena
    let basico = Math.round((sueldoQ / 15) * dias);
    document.getElementById('basico').value = basico;

    let extras = (parseFloat(document.getElementById('hed').value)*vH*1.25) +
                 (parseFloat(document.getElementById('rn').value)*vH*0.35) +
                 (parseFloat(document.getElementById('dom').value)*vH*1.75) +
                 (parseFloat(document.getElementById('rndf').value)*vH*2.10);
    
    let ibc = basico + extras + (parseFloat(document.getElementById('bonos').value)||0);
    let salud = Math.round(ibc * 0.04);
    let pension = Math.round(ibc * 0.04);
    
    document.getElementById('salud').value = salud;
    document.getElementById('pension').value = pension;
    
    let neto = (ibc + (parseFloat(document.getElementById('aux_transp').value)||0)) - 
               (salud + pension + (parseFloat(document.getElementById('desc').value)||0));
    
    document.getElementById('txt_neto').innerText = "$" + Math.round(neto).toLocaleString('es-CO');
    document.getElementById('input_neto').value = Math.round(neto);
}

// EXPORTACIÓN A EXCEL
function exportarExcel() {
    let table = document.getElementById("tablaHistorial");
    // Eliminamos la última columna (Acción) para el Excel
    let wb = XLSX.utils.table_to_book(table, { sheet: "Nomina_2026" });
    XLSX.writeFile(wb, "Reporte_Nomina_<?= $periodoActual ?>.xlsx");
}

document.addEventListener('input', e => {
    if (e.target.classList.contains('input-cron')) sumarCronograma();
    if (e.target.classList.contains('calc')) calcular();
});
</script>
</body>
</html>