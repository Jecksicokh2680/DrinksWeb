<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Carga de las dos conexiones
require 'Conexion.php';    // Define $mysqli (Base Local - Tabla colaborador)
include 'ConnCentral.php'; // Define $mysqliPos (Base Central - Tabla terceros)

$NitEmpresa = $_SESSION['NitEmpresa'] ?? '';
$Usuario    = $_SESSION['Usuario'] ?? '';
$mensaje    = "";

/* ============================================================
   PROCESO DE GUARDADO (En Base LOCAL)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_colaborador'])) {
    
    // Usamos $mysqli (Conexión Local)
    $cedula   = $mysqli->real_escape_string($_POST['nit_seleccionado']);
    $salario  = $_POST['salario'];
    $tipo_con = $_POST['tipo_contrato'];
    $arl      = $_POST['nivel_arl'];
    $cargo    = $mysqli->real_escape_string($_POST['cargo']);
    $fecha    = $_POST['fecha_ingreso'];

    $sqlInsert = "INSERT INTO colaborador (CedulaNit, NitEmpresa, salario, tipo_contrato, nivel_arl, cargo, fecha_ingreso, estado) 
                  VALUES ('$cedula', '$NitEmpresa', $salario, '$tipo_con', $arl, '$cargo', '$fecha', 'ACTIVO')";

    if ($mysqli->query($sqlInsert)) {
        $mensaje = "<div class='alert alert-success shadow-sm'>✅ Colaborador guardado en base local correctamente.</div>";
    } else {
        $mensaje = "<div class='alert alert-danger shadow-sm'>❌ Error en base local: " . $mysqli->error . "</div>";
    }
}

/* ============================================================
   CONSULTA DE TERCEROS (En Base CENTRAL)
   ============================================================ */
// Usamos $mysqliPos (Conexión Central)
$resTerceros = $mysqliPos->query("SELECT nit, nombres FROM terceros WHERE inactivo = 0 ORDER BY nombres ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Colaborador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; }
        .card-nomina { border-top: 5px solid #0d6efd; border-radius: 10px; }
    </style>
</head>
<body class="p-3 p-md-5">

<div class="container" style="max-width: 750px;">
    <div class="card shadow card-nomina">
        <div class="card-body p-4">
            <h4 class="text-primary mb-0">💼 Registro de Colaborador</h4>
            <hr>
            <?= $mensaje ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="form-label fw-bold">Seleccionar Persona (Desde Base Central):</label>
                    <select name="nit_seleccionado" class="form-select border-primary" required>
                        <option value="">-- Seleccione un Tercero --</option>
                        <?php 
                        if ($resTerceros) {
                            while ($t = $resTerceros->fetch_assoc()) {
                                echo "<option value='{$t['nit']}'>".htmlspecialchars($t['nombres'])." ({$t['nit']})</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Cargo</label>
                        <input type="text" name="cargo" class="form-control" required placeholder="Ej: Administrador">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Salario Mensual</label>
                        <input type="number" name="salario" class="form-control" required placeholder="1300000">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Tipo de Contrato</label>
                        <select name="tipo_contrato" class="form-select">
                            <option value="Indefinido">Indefinido</option>
                            <option value="Fijo">Fijo</option>
                            <option value="Prestación">Prestación</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Nivel ARL</label>
                        <input type="number" name="nivel_arl" class="form-control" value="1">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold">Fecha de Ingreso</label>
                    <input type="date" name="fecha_ingreso" class="form-control" value="2026-01-16">
                </div>

                <button type="submit" name="guardar_colaborador" class="btn btn-primary w-100 btn-lg shadow">
                    🚀 Registrar Colaborador
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>