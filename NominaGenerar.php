<?php
session_start();
require 'Conexion.php';    // Base Local ($mysqli)
include 'ConnCentral.php'; // Base Central ($mysqliPos)

$self = basename($_SERVER['PHP_SELF']);

// 1. VARIABLES DE SESIÓN (Instrucción 2026-01-15)
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

// --- 3. LÓGICA DE GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['CedulaNit'])) {
    $cedula  = $mysqli->real_escape_string($_POST['CedulaNit']);
    $periodo = (date('j') <= 15 ? "1ra Quinc" : "2da Quinc") . " " . date('m-Y');
    
    $vHora = floatval($_POST['v_hora_hidden']); 
    $v_hed = (floatval($_POST['cant_hed']) * ($vHora * 1.25));
    $v_hen = (floatval($_POST['cant_hen']) * ($vHora * 1.75));
    $v_rn  = (floatval($_POST['cant_rn'])  * ($vHora * 0.35));
    $v_dom = (floatval($_POST['cant_dom']) * ($vHora * 1.75));
    $v_rndf = (floatval($_POST['cant_rndf']) * ($vHora * 2.10));
    
    $valor_extras_total = $v_hed + $v_hen + $v_rn + $v_dom + $v_rndf;

    $sql = "INSERT INTO nomina_pagos (
        CedulaNit, fecha_pago, periodo, salario_base, auxilio_transporte, 
        bonificaciones, descuentos, total_pagado, UsuarioRegistra, 
        NitEmpresa, NroSucursal, salud, pension, dias_laborados, 
        cant_hed, cant_hen, cant_rn, cant_dom, cant_rndf, valor_extras_total
    ) VALUES (
        '$cedula', NOW(), '$periodo', '{$_POST['basico']}', '{$_POST['aux_transp']}', 
        '{$_POST['bonificaciones']}', '{$_POST['descuentos']}', '{$_POST['total_pagado']}', 
        '$Usuario', '$NitEmpresa', '$NroSucursal', '{$_POST['salud']}', '{$_POST['pension']}', 
        '{$_POST['dias_laborados']}', '{$_POST['cant_hed']}', '{$_POST['cant_hen']}', 
        '{$_POST['cant_rn']}', '{$_POST['cant_dom']}', '{$_POST['cant_rndf']}', '$valor_extras_total'
    )";

    if($mysqli->query($sql)) header("Location: $self?success=1");
    else die("Error: " . $mysqli->error);
    exit();
}

// --- 4. CONSULTA DE COLABORADOR ---
$colaborador = null;
$nombreEmpleado = "";
if (!empty($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $colaborador = $res->fetch_assoc();
    if($colaborador){
        $resNom = $mysqliPos->query("SELECT nombres FROM terceros WHERE nit = '$ced' LIMIT 1");
        $nombreEmpleado = ($resNom) ? ($resNom->fetch_assoc())['nombres'] : 'Empleado Encontrado';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nómina Pro 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card-nomina { border-top: 5px solid #198754; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .lbl-ley { font-size: 11px; font-weight: 700; color: #6c757d; text-transform: uppercase; }
        .bg-resumen { background: #1a1c1e; color: white; border-radius: 10px; padding: 20px; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 1150px;">
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success text-center fw-bold">✅ REGISTRO GUARDADO CON ÉXITO</div>
    <?php endif; ?>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-muted mb-0">PROCESAR NÓMINA - SUCURSAL <?= $NroSucursal ?></h6>
                <button type="button" class="btn btn-dark btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalProcesados">📋 HISTORIAL Y EXCEL</button>
            </div>
            <form method="GET" action="<?= $self ?>" class="row g-2">
                <div class="col-md-10">
                    <select name="cedula" class="form-select border-success">
                        <option value="">-- Seleccionar Colaborador --</option>
                        <?php
                        $list = $mysqli->query("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = '$NitEmpresa' AND estado = 'ACTIVO'");
                        while($row = $list->fetch_assoc()){
                            $s = (isset($_GET['cedula']) && $_GET['cedula'] == $row['CedulaNit']) ? 'selected' : '';
                            echo "<option value='{$row['CedulaNit']}' $s>{$row['CedulaNit']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100 fw-bold">CARGAR</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($colaborador): 
        $sueldo_q = $colaborador['salario'] / 2;
        $v_h = $colaborador['salario'] / 220; 
    ?>
    <div class="card card-nomina border-0">
        <form action="<?= $self ?>" method="POST">
            <input type="hidden" id="v_hora" name="v_hora_hidden" value="<?= $v_h ?>">
            <input type="hidden" name="CedulaNit" value="<?= $colaborador['CedulaNit'] ?>">
            <input type="hidden" name="total_pagado" id="input_neto">

            <div class="card-body p-4">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h3 class="fw-bold mb-0 text-dark"><?= $nombreEmpleado ?></h3>
                        <span class="badge bg-secondary">CC: <?= $colaborador['CedulaNit'] ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <small class="lbl-ley">Salario Base Quincenal</small>
                        <h4 class="fw-bold text-primary">$<?= number_format($sueldo_q) ?></h4>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <p class="fw-bold text-success border-bottom pb-1">INGRESOS (DEVENGADOS)</p>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4"><label class="lbl-ley">Días</label><input type="number" name="dias_laborados" class="form-control" value="15"></div>
                            <div class="col-md-4"><label class="lbl-ley">Básico</label><input type="number" name="basico" id="basico" class="form-control bg-light" value="<?= $sueldo_q ?>" readonly></div>
                            <div class="col-md-4"><label class="lbl-ley">Aux Transp</label><input type="number" step="0.01" name="aux_transp" id="aux_transp" class="form-control calc" value="124547"></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4"><label class="lbl-ley">H.E. Diurna</label><input type="number" name="cant_hed" id="hed" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">H.E. Nocturna</label><input type="number" name="cant_hen" id="hen" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Rec. Nocturno</label><input type="number" name="cant_rn" id="rn" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Dominicales</label><input type="number" name="cant_dom" id="dom" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">R. Noct. Fest</label><input type="number" name="cant_rndf" id="rndf" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Bonos</label><input type="number" name="bonificaciones" id="bonos" class="form-control calc" value="0"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <p class="fw-bold text-danger border-bottom pb-1">DEDUCCIONES</p>
                        <div class="mb-2"><label class="lbl-ley">Salud (4%)</label><input type="number" name="salud" id="salud" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Pensión (4%)</label><input type="number" name="pension" id="pension" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Otros Descuentos</label><input type="number" name="descuentos" id="desc" class="form-control calc border-warning" value="0"></div>
                    </div>
                </div>

                <div class="bg-resumen mt-4 shadow-sm d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase opacity-50">Neto a recibir:</small>
                        <h2 id="txt_neto" class="fw-bold mb-0 text-info">$ 0</h2>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg fw-bold shadow px-5">💾 GUARDAR PAGO</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalProcesados" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Historial de Nómina</h5>
                <button onclick="exportTableToExcel('tblNomina', 'Reporte_Nomina')" class="btn btn-success btn-sm ms-3 fw-bold">📊 EXCEL</button>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tblNomina" style="font-size: 0.8rem;">
                        <thead class="table-light text-uppercase">
                            <tr>
                                <th class="ps-3">Cédula</th>
                                <th>Periodo</th>
                                <th class="text-center">HED</th>
                                <th class="text-center">HEN</th>
                                <th class="text-center">RN</th>
                                <th class="text-center">DOM</th>
                                <th class="text-center">RNDF</th>
                                <th class="text-end text-success">Total Extras</th>
                                <th class="text-end text-danger">Descuentos</th> <th class="text-end fw-bold">Neto Pagado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $res_h = $mysqli->query("SELECT * FROM nomina_pagos WHERE NitEmpresa = '$NitEmpresa' ORDER BY id DESC");
                            while ($p = $res_h->fetch_assoc()) {
                                echo "<tr>
                                    <td class='ps-3 fw-bold'>{$p['CedulaNit']}</td>
                                    <td>{$p['periodo']}</td>
                                    <td class='text-center'>{$p['cant_hed']}</td>
                                    <td class='text-center'>{$p['cant_hen']}</td>
                                    <td class='text-center'>{$p['cant_rn']}</td>
                                    <td class='text-center'>{$p['cant_dom']}</td>
                                    <td class='text-center'>{$p['cant_rndf']}</td>
                                    <td class='text-end'>$".number_format($p['valor_extras_total'])."</td>
                                    <td class='text-end text-danger fw-bold'>$".number_format($p['descuentos'])."</td>
                                    <td class='text-end fw-bold text-primary'>$".number_format($p['total_pagado'])."</td>
                                    <td class='text-center'>
                                        <a href='$self?eliminar={$p['id']}' class='btn btn-sm btn-outline-danger' onclick='return confirm(\"¿Eliminar?\")'>🗑</a>
                                    </td>
                                </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const inputs = document.querySelectorAll('.calc');
const vHoraEl = document.getElementById('v_hora');

function calcular() {
    if(!document.getElementById('basico')) return;
    const vH = parseFloat(vHoraEl.value || 0);
    
    let basico = parseFloat(document.getElementById('basico').value) || 0;
    let auxT   = parseFloat(document.getElementById('aux_transp').value) || 0;
    let c_hed  = (parseFloat(document.getElementById('hed').value) || 0) * (vH * 1.25);
    let c_hen  = (parseFloat(document.getElementById('hen').value) || 0) * (vH * 1.75);
    let c_rn   = (parseFloat(document.getElementById('rn').value) || 0) * (vH * 0.35);
    let c_dom  = (parseFloat(document.getElementById('dom').value) || 0) * (vH * 1.75);
    let c_rndf = (parseFloat(document.getElementById('rndf').value) || 0) * (vH * 2.10);
    let bonos  = parseFloat(document.getElementById('bonos').value) || 0;

    let ibc = basico + c_hed + c_hen + c_rn + c_dom + c_rndf + bonos;
    let salud = Math.round(ibc * 0.04);
    let pension = Math.round(ibc * 0.04);
    
    document.getElementById('salud').value = salud;
    document.getElementById('pension').value = pension;

    let totalDev = ibc + auxT;
    let totalDed = salud + pension + (parseFloat(document.getElementById('desc').value) || 0);
    let neto = totalDev - totalDed;

    document.getElementById('txt_neto').innerText = "$" + Math.round(neto).toLocaleString('es-CO');
    document.getElementById('input_neto').value = Math.round(neto);
}

function exportTableToExcel(tableID, filename = ''){
    let tableSelect = document.getElementById(tableID);
    let tableHTML = tableSelect.outerHTML.replace(/<a.*?>.*?<\/a>/g, '');
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = 'data:application/vnd.ms-excel,' + encodeURIComponent(tableHTML);
    downloadLink.download = filename + '.xls';
    downloadLink.click();
}

inputs.forEach(i => i.addEventListener('input', calcular));
document.addEventListener('DOMContentLoaded', calcular);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>