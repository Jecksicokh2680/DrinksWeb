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

// --- 4. CONSULTA DE COLABORADOR SELECCIONADO ---
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
        body { background: #f4f7f6; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .card-nomina { border-top: 5px solid #2c3e50; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .lbl-ley { font-size: 10px; font-weight: 800; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; }
        .bg-resumen { background: #2c3e50; color: white; border-radius: 8px; padding: 20px; }
        .table-xs { font-size: 0.75rem; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 1250px;">
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm text-center fw-bold">✅ PAGO DE NÓMINA REGISTRADO CORRECTAMENTE</div>
    <?php endif; ?>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-secondary">MÓDULO DE LIQUIDACIÓN | SUCURSAL <?= $NroSucursal ?></h6>
                <button type="button" class="btn btn-outline-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalProcesados">
                    📂 VER HISTORIAL DE PAGOS
                </button>
            </div>
            <form method="GET" action="<?= $self ?>" class="row g-2">
                <div class="col-md-10">
                    <select name="cedula" class="form-select select2">
                        <option value="">-- Buscar colaborador por identificación --</option>
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
                    <button type="submit" class="btn btn-primary w-100 fw-bold">CARGAR DATOS</button>
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
                <div class="row mb-4 align-items-center">
                    <div class="col-md-8">
                        <h2 class="fw-bold text-dark mb-0"><?= $nombreCompletoForm ?></h2>
                        <span class="text-muted fw-bold small">NIT/CC: <?= $colaborador['CedulaNit'] ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <label class="lbl-ley">Sueldo Base Quincenal</label>
                        <h3 class="fw-bold text-primary">$<?= number_format($sueldo_q) ?></h3>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <h6 class="text-success fw-bold mb-3">➕ DEVENGADOS</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4"><label class="lbl-ley">Días Lab.</label><input type="number" name="dias_laborados" class="form-control" value="15"></div>
                            <div class="col-md-4"><label class="lbl-ley">Básico</label><input type="number" name="basico" id="basico" class="form-control bg-light" value="<?= $sueldo_q ?>" readonly></div>
                            <div class="col-md-4"><label class="lbl-ley">Aux. Transp</label><input type="number" step="0.01" name="aux_transp" id="aux_transp" class="form-control calc" value="124547"></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4"><label class="lbl-ley">Cant. HED</label><input type="number" name="cant_hed" id="hed" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Cant. HEN</label><input type="number" name="cant_hen" id="hen" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Cant. RN</label><input type="number" name="cant_rn" id="rn" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Cant. DOM</label><input type="number" name="cant_dom" id="dom" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Cant. RNDF</label><input type="number" name="cant_rndf" id="rndf" class="form-control calc" value="0"></div>
                            <div class="col-md-4"><label class="lbl-ley">Bonificaciones</label><input type="number" name="bonificaciones" id="bonos" class="form-control calc" value="0"></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-danger fw-bold mb-3">➖ DEDUCCIONES</h6>
                        <div class="mb-2"><label class="lbl-ley">Salud (4%)</label><input type="number" name="salud" id="salud" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Pensión (4%)</label><input type="number" name="pension" id="pension" class="form-control bg-light" readonly></div>
                        <div class="mb-2"><label class="lbl-ley">Otros Descuentos</label><input type="number" name="descuentos" id="desc" class="form-control calc border-warning" value="0"></div>
                    </div>
                </div>

                <div class="bg-resumen mt-4 d-flex justify-content-between align-items-center shadow">
                    <div>
                        <small class="text-uppercase opacity-75 fw-bold" style="font-size: 10px;">Neto a Pagar en Quincena:</small>
                        <h1 id="txt_neto" class="fw-bold mb-0 text-info">$ 0</h1>
                    </div>
                    <button type="submit" class="btn btn-light btn-lg fw-bold px-5 text-primary">💾 REGISTRAR PAGO</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalProcesados" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Historial Completo de Nómina</h5>
                <button onclick="exportTableToExcel('tblNomina', 'Nomina_Export')" class="btn btn-success btn-sm ms-3 fw-bold">📊 EXPORTAR EXCEL</button>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-xs" id="tblNomina">
                        <thead class="table-secondary text-uppercase" style="font-size: 10px;">
                            <tr>
                                <th class="ps-3">Identificación</th>
                                <th>Nombre Completo del Tercero</th>
                                <th>Periodo</th>
                                <th class="text-center">HED</th>
                                <th class="text-center">HEN</th>
                                <th class="text-center">RN</th>
                                <th class="text-center">DOM</th>
                                <th class="text-center">RNDF</th>
                                <th class="text-end text-success">Total Extras</th>
                                <th class="text-end text-danger">Descuentos</th>
                                <th class="text-end fw-bold text-dark">Total Neto</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $res_h = $mysqli->query("SELECT * FROM nomina_pagos WHERE NitEmpresa = '$NitEmpresa' ORDER BY id DESC");
                            while ($p = $res_h->fetch_assoc()) {
                                
                                // OBTENER NOMBRE COMPLETO DESDE LA TABLA TERCEROS
                                $nit_p = $p['CedulaNit'];
                                $qNom = $mysqliPos->query("SELECT nombres, nombre2, apellidos, apellido2 FROM terceros WHERE nit = '$nit_p' LIMIT 1");
                                if($tH = $qNom->fetch_assoc()){
                                    $nombreCompleto = trim("{$tH['nombres']} {$tH['nombre2']} {$tH['apellidos']} {$tH['apellido2']}");
                                } else {
                                    $nombreCompleto = "SIN NOMBRE";
                                }

                                echo "<tr>
                                    <td class='ps-3 fw-bold text-secondary'>{$p['CedulaNit']}</td>
                                    <td class='fw-semibold text-uppercase'>$nombreCompleto</td>
                                    <td class='text-nowrap'>{$p['periodo']}</td>
                                    <td class='text-center'>{$p['cant_hed']}</td>
                                    <td class='text-center'>{$p['cant_hen']}</td>
                                    <td class='text-center'>{$p['cant_rn']}</td>
                                    <td class='text-center'>{$p['cant_dom']}</td>
                                    <td class='text-center'>{$p['cant_rndf']}</td>
                                    <td class='text-end text-success'>$".number_format($p['valor_extras_total'])."</td>
                                    <td class='text-end text-danger'>$".number_format($p['descuentos'])."</td>
                                    <td class='text-end fw-bold text-primary'>$".number_format($p['total_pagado'])."</td>
                                    <td class='text-center'>
                                        <a href='$self?eliminar={$p['id']}' class='btn btn-sm btn-danger' onclick='return confirm(\"¿Desea borrar este registro?\")'>🗑</a>
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
// SCRIPTS DE CÁLCULO DINÁMICO
const inputs = document.querySelectorAll('.calc');
const vHoraEl = document.getElementById('v_hora');

function calcular() {
    if(!document.getElementById('basico')) return;
    const vH = parseFloat(vHoraEl.value || 0);
    
    let basico = parseFloat(document.getElementById('basico').value) || 0;
    let auxT   = parseFloat(document.getElementById('aux_transp').value) || 0;
    
    // Cálculo de Extras
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