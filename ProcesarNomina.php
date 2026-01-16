<?php
session_start();
require 'Conexion.php';    // Base Local ($mysqli)
include 'ConnCentral.php'; // Base Central ($mysqliPos)

// 1. VARIABLES DE SESIÓN (Según tus instrucciones de configuración)
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? '';
$Usuario     = $_SESSION['Usuario'] ?? '';
$NroSucursal = $_SESSION['NroSucursal'] ?? '';

// --- 2. LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    // Aseguramos que solo borre registros de su propia empresa
    $mysqli->query("DELETE FROM nomina_pagos WHERE id = $id AND NitEmpresa = '$NitEmpresa'");
    header("Location: ProcesarNomina.php"); 
    exit();
}

// --- 3. LÓGICA DE GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['CedulaNit'])) {
    $cedula  = $mysqli->real_escape_string($_POST['CedulaNit']);
    $periodo = "Quincena " . date('m-Y');
    
    // Preparar valores numéricos
    $vHora          = floatval($_POST['v_hora_hidden'] ?? 0);
    $basico         = floatval($_POST['basico'] ?? 0);
    $aux_transp     = floatval($_POST['aux_transp'] ?? 0);
    $bonos          = floatval($_POST['bonos'] ?? 0);
    $salud          = floatval($_POST['salud'] ?? 0);
    $pension        = floatval($_POST['pension'] ?? 0);
    $descuentos     = floatval($_POST['descuentos'] ?? 0);
    $total_pagado   = floatval($_POST['total_pagado'] ?? 0);
    $dias           = intval($_POST['dias_laborados'] ?? 0);

    // Cantidades de Horas (Se agregaron las faltantes)
    $hed  = floatval($_POST['cant_hed'] ?? 0);
    $hen  = floatval($_POST['cant_hen'] ?? 0);
    $rn   = floatval($_POST['cant_rn'] ?? 0);
    $dom  = floatval($_POST['cant_dom'] ?? 0);
    $rndf = floatval($_POST['cant_rndf'] ?? 0);

    // Calcular valor total de extras para el historial
    $valor_extras = ($hed * ($vHora * 1.25)) + 
                    ($hen * ($vHora * 1.75)) + 
                    ($rn  * ($vHora * 0.35)) + 
                    ($dom * ($vHora * 1.75)) + 
                    ($rndf * ($vHora * 2.10));

    $sql = "INSERT INTO nomina_pagos (
        CedulaNit, NitEmpresa, NroSucursal, fecha_pago, periodo, 
        salario_base, auxilio_transporte, bonificaciones, salud, 
        pension, descuentos, total_pagado, UsuarioRegistra, 
        dias_laborados, cant_hed, cant_hen, cant_rn, cant_dom, cant_rndf, valor_extras_total
    ) VALUES (
        '$cedula', '$NitEmpresa', '$NroSucursal', NOW(), '$periodo',
        '$basico', '$aux_transp', '$bonos',
        '$salud', '$pension', '$descuentos',
        '$total_pagado', '$Usuario', '$dias',
        '$hed', '$hen', '$rn', '$dom', '$rndf', '$valor_extras'
    )";

    if($mysqli->query($sql)){
        header("Location: ProcesarNomina.php?success=1");
    } else {
        die("Error al guardar: " . $mysqli->error);
    }
    exit();
}

// --- 4. CONSULTA DE EMPLEADO SELECCIONADO ---
$colaborador = null;
$nombreEmpleado = "";
if (isset($_GET['cedula']) && !empty($_GET['cedula'])) {
    $ced = $mysqli->real_escape_string($_GET['cedula']);
    $res = $mysqli->query("SELECT * FROM colaborador WHERE CedulaNit = '$ced' AND NitEmpresa = '$NitEmpresa' LIMIT 1");
    $colaborador = $res->fetch_assoc();
    
    if($colaborador) {
        $resNom = $mysqliPos->query("SELECT nombres FROM terceros WHERE nit = '$ced' LIMIT 1");
        $nombreEmpleado = ($resNom && $resNom->num_rows > 0) ? ($resNom->fetch_assoc())['nombres'] : 'No encontrado';
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
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .card-nomina { border-top: 6px solid #198754; border-radius: 12px; }
        .lbl-ley { font-size: 0.72rem; color: #4b5563; font-weight: 700; text-transform: uppercase; }
        .bg-resumen { background: #111827; color: #f9fafb; border-radius: 12px; padding: 25px; }
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
                <h6 class="fw-bold mb-0 text-muted">LIQUIDACIÓN - EMPRESA: <?= $NitEmpresa ?></h6>
                <button type="button" class="btn btn-dark btn-sm fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalProcesados">
                    📋 VER HISTORIAL DE PAGOS
                </button>
            </div>
            <form method="GET" action="ProcesarNomina.php" class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="fw-bold small">BUSCAR EMPLEADO ACTIVO:</label>
                    <select name="cedula" class="form-select border-success shadow-sm">
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
    ?>
    <div class="card shadow-lg card-nomina border-0">
        <form action="ProcesarNomina.php" method="POST">
            <input type="hidden" name="v_hora_hidden" id="v_hora" value="<?= $valor_hora ?>">
            <input type="hidden" name="CedulaNit" value="<?= $colaborador['CedulaNit'] ?>">
            <input type="hidden" name="total_pagado" id="input_neto">

            <div class="card-body p-4 p-md-5">
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h2 class="fw-bold mb-0 text-dark"><?= $nombreEmpleado ?></h2>
                        <span class="badge bg-primary px-3 py-2">CC: <?= $colaborador['CedulaNit'] ?></span>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <small class="text-muted d-block">Sueldo Base Mensual</small>
                        <h4 class="fw-bold text-success">$<?= number_format($colaborador['salario']) ?></h4>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-8 border-end">
                        <h6 class="fw-bold text-success border-bottom pb-2 mb-4">01. INGRESOS</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="lbl-ley">Días a Pagar</label>
                                <input type="number" name="dias_laborados" id="dias_lab" class="form-control fw-bold border-success" value="15">
                            </div>
                            <div class="col-md-4">
                                <label class="lbl-ley">Sueldo Quincenal</label>
                                <input type="number" name="basico" id="basico" class="form-control bg-light