<?php
session_start();
require 'Conexion.php';    // Base Local ($mysqli)
include 'ConnCentral.php'; // Base Central ($mysqliPos)

// 1. VARIABLES DE SESIÓN
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? '';
$Usuario     = $_SESSION['Usuario'] ?? '';
$NroSucursal = $_SESSION['NroSucursal'] ?? '';

// --- 2. LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $mysqli->query("DELETE FROM nomina_pagos WHERE id = $id AND NitEmpresa = '$NitEmpresa'");
    // Redirigir de vuelta a la página principal de generación
    header("Location: NominaGenerar.php");
    exit();
}

// --- 3. LÓGICA DE GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['CedulaNit'])) {
    $cedula  = $_POST['CedulaNit'];
    $periodo = "Quincena " . date('m-Y');
    
    // Preparar valores numéricos para evitar errores de SQL
    $basico = floatval($_POST['basico']);
    $aux_transp = floatval($_POST['aux_transp']);
    $bonos = floatval($_POST['bonos']);
    $salud = floatval($_POST['salud']);
    $pension = floatval($_POST['pension']);
    $descuentos = floatval($_POST['descuentos']);
    $total_pagado = floatval($_POST['total_pagado']);
    $dias = intval($_POST['dias_laborados']);
    $hed = floatval($_POST['cant_hed']);
    $hen = floatval($_POST['cant_hen']);

    $sql = "INSERT INTO nomina_pagos (
        CedulaNit, NitEmpresa, NroSucursal, fecha_pago, periodo, 
        salario_base, auxilio_transporte, bonificaciones, salud, 
        pension, descuentos, total_pagado, UsuarioRegistra, 
        dias_laborados, cant_hed, cant_hen
    ) VALUES (
        '$cedula', '$NitEmpresa', '$NroSucursal', NOW(), '$periodo',
        '$basico', '$aux_transp', '$bonos',
        '$salud', '$pension', '$descuentos',
        '$total_pagado', '$Usuario', '$dias',
        '$hed', '$hen'
    )";

    $mysqli->query($sql);
    // Redirigir de vuelta a la página principal con mensaje de éxito
    header("Location: NominaGenerar.php?success=1");
    exit();
}

// --- 4. CONSULTA DE EMPLEADO SELECCIONADO ---
$colaborador = null;
$nombreEmpleado = "";
if (isset($_GET['cedula']) && !empty($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $colaborador = $res->fetch_assoc();
    
    $resNom = $mysqliPos->query("SELECT nombres FROM terceros WHERE nit = '$ced' LIMIT 1");
    $nombreEmpleado = ($resNom && $resNom->num_rows > 0) ? ($resNom->fetch_assoc())['nombres'] : 'No encontrado';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nómina Pro 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card-nomina { border-top: 6px solid #198754; border-radius: 12px; }
        .lbl-ley { font-size: 0.72rem; color: #4b5563; font-weight: 700; text-transform: uppercase; }
        .val-dinero { background-color: #f1f5f9 !important; font-weight: 700; color: #2563eb; text-align: right; }
        .bg-resumen { background: #111827; color: #f9fafb; border-radius: 12px; padding: 20px; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 1100px;">
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show fw-bold text-center shadow-sm" role="alert">
            ✅ PAGO REGISTRADO CORRECTAMENTE
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-muted">LIQUIDACIÓN DE NÓMINA - NIT: <?= $NitEmpresa ?></h6>
                <button type="button" class="btn btn-dark btn-sm fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalProcesados">
                    📋 HISTORIAL Y EXCEL
                </button>
            </div>
            <form method="GET" action="NominaGenerar.php" class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="fw-bold small">BUSCAR EMPLEADO ACTIVO:</label>
                    <select name="cedula" class="form-select border-success">
                        <option value="">-- Seleccione un trabajador --</option>
                        <?php
                        $list = $mysqli->query("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = '$NitEmpresa' AND estado = 'ACTIVO'");
                        while($row = $list->fetch_assoc()){
                            $sel = (isset($_GET['cedula']) && $_GET['cedula'] == $row['CedulaNit']) ? 'selected' : '';
                            echo "<option value='{$row['CedulaNit']}' $sel>{$row['CedulaNit']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100 fw-bold py-2">CARGAR DATOS</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($colaborador): 
        $sueldo_quincena = $colaborador['salario'] / 2;
        $valor_hora = $colaborador['salario'] / 220; 
        $aux_transp_q = 124547.5; 
    ?>
    <div class="card shadow-lg card-nomina border-0">
        <form action="NominaGenerar.php" method="POST">
            <input type="hidden" id="v_hora" value="<?= $valor_hora ?>">
            <input type="hidden" name="CedulaNit" value="<?= $colaborador['CedulaNit'] ?>">
            <input type="hidden" name="total_pagado" id="input_neto">

            <div class="card-body p-4 p-md-5">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2 class="fw-bold mb-0 text-dark"><?= $nombreEmpleado ?></h2>
                        <span class="badge bg-primary px-3 py-2">CÉDULA: <?= $colaborador['CedulaNit'] ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <small class="text-muted d-block">Sueldo Base Mensual</small>
                        <h4 class="fw-bold">$<?= number_format($colaborador['salario']) ?></h4>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <h6 class="fw-bold text-success border-bottom pb-2 mb-4">01. INGRESOS</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="lbl-ley">Días a Pagar</label>
                                <input type="number" name="dias_laborados" class="form-control fw-bold border-success" value="15">
                            </div>
                            <div class="col-md-4">
                                <label class="lbl-ley">Sueldo Quincenal</label>
                                <input type="number" name="basico" id="basico" class="form-control bg-light fw-bold" value="<?= $sueldo_quincena ?>" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="lbl-ley">Aux. Transporte</label>
                                <input type="number" step="0.01" name="aux_transp" id="aux_transp" class="form-control calc fw-bold" value="<?= $aux_transp_q ?>">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="lbl-ley">H.E. Diurnas (1.25)</label>
                                <input type="number" name="cant_hed" id="hed" class="form-control calc" value="0">
                                <input type="text" id="val_hed" class="form-control mt-1 val-dinero form-control-sm" readonly value="$ 0">
                            </div>
                            <div class="col-md-4">
                                <label class="lbl-ley">H.E. Nocturnas (1.75)</label>
                                <input type="number" name="cant_hen" id="hen" class="form-control calc" value="0">
                                <input type="text" id="val_hen" class="form-control mt-1 val-dinero form-control-sm" readonly value="$ 0">
                            </div>
                            <div class="col-md-4">
                                <label class="lbl-ley">Otros Bonos</label>
                                <input type="number" name="bonos" id="bonos" class="form-control calc" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 class="fw-bold text-danger border-bottom pb-2 mb-4">02. DEDUCCIONES</h6>
                        <div class="mb-4">
                            <label class="lbl-ley">Salud (4%)</label>
                            <input type="number" name="salud" id="salud" class="form-control bg-light fw-bold text-danger" readonly>
                        </div>
                        <div class="mb-4">
                            <label class="lbl-ley">Pensión (4%)</label>
                            <input type="number" name="pension" id="pension" class="form-control bg-light fw-bold text-danger" readonly>
                        </div>
                        <div class="mb-4">
                            <label class="lbl-ley">Préstamos / Otros</label>
                            <input type="number" name="descuentos" id="desc" class="form-control calc border-warning" value="0">
                        </div>
                    </div>
                </div>

                <div class="bg-resumen mt-4 shadow">
                    <div class="row align-items-center">
                        <div class="col-md-7 border-md-end border-secondary">
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary small fw-bold">TOTAL DEVENGADO</span>
                                <span id="txt_devengado" class="text-info fw-bold mb-0"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary small fw-bold">DEDUCCIONES</span>
                                <span id="txt_deduccion" class="text-warning fw-bold mb-0"></span>
                            </div>
                        </div>
                        <div class="col-md-5 text-md-end">
                            <h2 class="mb-0 fw-bold">NETO: <span id="txt_neto" class="text-white"></span></h2>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 mt-4 fw-bold shadow py-3">✅ REGISTRAR Y FINALIZAR</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalProcesados" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title fw-bold mb-0">Registro de Pagos Procesados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle" id="tblNomina">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Identificación</th>
                                <th>Periodo</th>
                                <th class="text-end">Salario Base</th>
                                <th class="text-end">Deducciones</th>
                                <th class="text-end">Neto Pagado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $res_p = $mysqli->query("SELECT * FROM nomina_pagos WHERE NitEmpresa = '$NitEmpresa' ORDER BY id DESC");
                            while ($p = $res_p->fetch_assoc()) {
                                $dtos = $p['salud'] + $p['pension'] + $p['descuentos'];
                                echo "<tr>
                                    <td class='fw-bold ps-3'>{$p['CedulaNit']}</td>
                                    <td>{$p['periodo']}</td>
                                    <td class='text-end'>$".number_format($p['salario_base'])."</td>
                                    <td class='text-end text-danger'>$".number_format($dtos)."</td>
                                    <td class='text-end fw-bold text-primary'>$".number_format($p['total_pagado'])."</td>
                                    <td class='text-center'>
                                        <a href='NominaGenerar.php?eliminar={$p['id']}' class='btn btn-sm btn-outline-danger' onclick='return confirm(\"¿Borrar registro?\")'>🗑</a>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// (Aquí se mantiene el mismo script de cálculo que ya tenías)
const inputs = document.querySelectorAll('.calc');
const vHora = parseFloat(document.getElementById('v_hora')?.value || 0);

function formatMoney(amount) {
    return "$" + Math.round(amount).toLocaleString('es-CO');
}

function calcular() {
    if(!document.getElementById('basico')) return;
    let basico = parseFloat(document.getElementById('basico').value) || 0;
    let auxT   = parseFloat(document.getElementById('aux_transp').value) || 0;
    let c_hed  = (parseFloat(document.getElementById('hed').value) || 0) * (vHora * 1.25);
    let c_hen  = (parseFloat(document.getElementById('hen').value) || 0) * (vHora * 1.75);
    let bonos  = parseFloat(document.getElementById('bonos').value) || 0;

    document.getElementById('val_hed').value = formatMoney(c_hed);
    document.getElementById('val_hen').value = formatMoney(c_hen);

    let ibc = basico + c_hed + c_hen + bonos;
    let salud = Math.round(ibc * 0.04);
    let pension = Math.round(ibc * 0.04);
    document.getElementById('salud').value = salud;
    document.getElementById('pension').value = pension;

    let totalDev = ibc + auxT;
    let totalDed = salud + pension + (parseFloat(document.getElementById('desc').value) || 0);
    let neto = totalDev - totalDed;

    document.getElementById('txt_devengado').innerText = formatMoney(totalDev);
    document.getElementById('txt_deduccion').innerText = formatMoney(totalDed);
    document.getElementById('txt_neto').innerText      = formatMoney(neto);
    document.getElementById('input_neto').value        = Math.round(neto);
}

inputs.forEach(input => input.addEventListener('input', calcular));
document.addEventListener('DOMContentLoaded', calcular);
</script>
</body>
</html>