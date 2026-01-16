<?php
session_start();
require 'Conexion.php';    // $mysqli
include 'ConnCentral.php'; // $mysqliPos

$NitEmpresa = $_SESSION['NitEmpresa'];
$Usuario    = $_SESSION['Usuario'];

$colaborador = null;
if (isset($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa'");
    $colaborador = $res->fetch_assoc();
    
    $resNom = $mysqliPos->query("SELECT nombres FROM terceros WHERE nit = '$ced'");
    $nombreEmpleado = ($resNom->fetch_assoc())['nombres'] ?? 'No encontrado';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Liquidación Detallada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-calc { border-top: 5px solid #198754; }
        .lbl-ley { font-size: 0.75rem; color: #555; font-weight: bold; text-transform: uppercase; }
        .bg-total { background: #e9ecef; border-radius: 8px; padding: 20px; }
    </style>
</head>
<body class="p-4 bg-light">

<div class="container" style="max-width: 950px;">
    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-9">
                    <select name="cedula" class="form-select form-select-lg">
                        <option value="">-- Seleccionar Colaborador --</option>
                        <?php
                        $list = $mysqli->query("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = '$NitEmpresa' AND estado = 'ACTIVO'");
                        while($row = $list->fetch_assoc()){
                            $selected = (isset($_GET['cedula']) && $_GET['cedula'] == $row['CedulaNit']) ? 'selected' : '';
                            echo "<option value='{$row['CedulaNit']}' $selected>{$row['CedulaNit']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark btn-lg w-100">Cargar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($colaborador): 
        $sueldo_quincena = $colaborador['salario'] / 2;
        $valor_hora = $colaborador['salario'] / 240; // Base mes 240 horas
    ?>
    <div class="card shadow card-calc">
        <form action="ProcesarNomina.php" method="POST">
            <input type="hidden" id="v_hora" value="<?= $valor_hora ?>">
            <input type="hidden" name="CedulaNit" value="<?= $colaborador['CedulaNit'] ?>">
            
            <div class="card-body">
                <div class="d-flex justify-content-between mb-4">
                    <div>
                        <h4 class="mb-0"><?= htmlspecialchars($nombreEmpleado) ?></h4>
                        <span class="text-muted">Sueldo Base: $<?= number_format($colaborador['salario']) ?></span>
                    </div>
                    <div class="text-end">
                        <label class="lbl-ley">Días Laborados</label>
                        <input type="number" name="dias_laborados" id="dias" class="form-control form-control-sm text-center" value="15" style="width: 80px; float: right;">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-7 border-end">
                        <h6 class="text-success fw-bold mb-3">CONCEPTO DE INGRESOS</h6>
                        
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="lbl-ley">Sueldo Quincenal</label>
                                <input type="number" name="basico" id="basico" class="form-control" value="<?= $sueldo_quincena ?>" readonly>
                            </div>
                            <div class="col-6">
                                <label class="lbl-ley">Auxilio Transporte (Q)</label>
                                <input type="number" name="aux_transp" id="aux_transp" class="form-control" value="85000">
                            </div>

                            <div class="col-3">
                                <label class="lbl-ley">H.E. Diurna</label>
                                <input type="number" name="cant_hed" id="hed" class="form-control calc" value="0">
                            </div>
                            <div class="col-3">
                                <label class="lbl-ley">H.E. Nocturna</label>
                                <input type="number" name="cant_hen" id="hen" class="form-control calc" value="0">
                            </div>
                            <div class="col-3">
                                <label class="lbl-ley">Rec. Nocturno</label>
                                <input type="number" name="cant_rn" id="rn" class="form-control calc" value="0">
                            </div>
                            <div class="col-3">
                                <label class="lbl-ley">Dom/Festivos</label>
                                <input type="number" name="cant_dom" id="dom" class="form-control calc" value="0">
                            </div>
                            
                            <div class="col-12">
                                <label class="lbl-ley">Otras Bonificaciones</label>
                                <input type="number" name="bonos" id="bonos" class="form-control calc" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5">
                        <h6 class="text-danger fw-bold mb-3">DEDUCCIONES DE LEY</h6>
                        <div class="mb-3">
                            <label class="lbl-ley">Salud (4%)</label>
                            <input type="number" name="salud" id="salud" class="form-control" value="<?= $sueldo_quincena * 0.04 ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="lbl-ley">Pensión (4%)</label>
                            <input type="number" name="pension" id="pension" class="form-control" value="<?= $sueldo_quincena * 0.04 ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="lbl-ley">Otros Descuentos</label>
                            <input type="number" name="descuentos" id="desc" class="form-control calc" value="0">
                        </div>
                    </div>
                </div>

                <div class="bg-total mt-4">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <small class="fw-bold">TOTAL DEVENGADO: </small> <span id="txt_devengado">$ 0</span><br>
                            <small class="fw-bold">TOTAL DEDUCCIONES: </small> <span id="txt_deduccion">$ 0</span>
                        </div>
                        <div class="col-md-6 text-end">
                            <h2 class="text-primary mb-0">Neto: <span id="txt_neto">$ 0</span></h2>
                            <input type="hidden" name="total_pagado" id="input_neto">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 mt-4 shadow">💾 GUARDAR Y PROCESAR NÓMINA</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
const inputs = document.querySelectorAll('.calc');
const vHora = parseFloat(document.getElementById('v_hora')?.value || 0);

function calcular() {
    // Ingresos
    let basico = parseFloat(document.getElementById('basico').value);
    let auxT   = parseFloat(document.getElementById('aux_transp').value);
    let hed    = (parseFloat(document.getElementById('hed').value) || 0) * (vHora * 1.25);
    let hen    = (parseFloat(document.getElementById('hen').value) || 0) * (vHora * 1.75);
    let rn     = (parseFloat(document.getElementById('rn').value) || 0) * (vHora * 0.35);
    let dom    = (parseFloat(document.getElementById('dom').value) || 0) * (vHora * 1.75);
    let bonos  = parseFloat(document.getElementById('bonos').value) || 0;

    let totalDevengado = basico + auxT + hed + hen + rn + dom + bonos;

    // Deducciones
    let salud   = parseFloat(document.getElementById('salud').value);
    let pension = parseFloat(document.getElementById('pension').value);
    let desc    = parseFloat(document.getElementById('desc').value) || 0;

    let totalDeduccion = salud + pension + desc;
    let neto = totalDevengado - totalDeduccion;

    // Mostrar
    document.getElementById('txt_devengado').innerText = "$" + totalDevengado.toLocaleString();
    document.getElementById('txt_deduccion').innerText = "$" + totalDeduccion.toLocaleString();
    document.getElementById('txt_neto').innerText = "$" + neto.toLocaleString();
    document.getElementById('input_neto').value = neto;
}

// Escuchar cambios
inputs.forEach(input => input.addEventListener('input', calcular));
document.addEventListener('DOMContentLoaded', calcular);
</script>

</body>
</html>