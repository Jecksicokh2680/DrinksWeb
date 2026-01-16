<?php
/* ============================================================
   LIQUIDACIÓN DE NÓMINA - COLOMBIA
   Autor: ChatGPT
   Descripción: Página PHP básica para liquidar nómina bajo
   parámetros legales de Colombia (2025 aprox.)
============================================================ */

// ---------------- CONFIGURACIÓN LEGAL ----------------
$SMMLV = 1300000;          // Salario mínimo mensual legal vigente (ajustable)
$AUX_TRANSPORTE = 162000;  // Auxilio de transporte (ajustable)

// Porcentajes legales
$SALUD_EMP = 0.085;   // Empleador
$SALUD_TRAB = 0.04;   // Trabajador
$PENSION_EMP = 0.12;
$PENSION_TRAB = 0.04;
$ARL_NIVEL_1 = 0.00522; // Riesgo I

// Prestaciones sociales
$CESANTIAS = 0.0833;
$INTERESES_CESANTIAS = 0.01;
$PRIMA = 0.0833;
$VACACIONES = 0.0417;

// ---------------- PROCESAMIENTO ----------------
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $salario = floatval($_POST['salario']);
    $dias = intval($_POST['dias']);

    $salario_base = ($salario / 30) * $dias;

    // Auxilio transporte
    $aux_transp = ($salario <= 2 * $SMMLV) ? ($AUX_TRANSPORTE / 30) * $dias : 0;

    // Deducciones trabajador
    $salud_trab = $salario_base * $SALUD_TRAB;
    $pension_trab = $salario_base * $PENSION_TRAB;

    // Aportes empleador
    $salud_emp = $salario_base * $SALUD_EMP;
    $pension_emp = $salario_base * $PENSION_EMP;
    $arl = $salario_base * $ARL_NIVEL_1;

    // Prestaciones
    $cesantias = ($salario_base + $aux_transp) * $CESANTIAS;
    $int_cesantias = $cesantias * $INTERESES_CESANTIAS;
    $prima = ($salario_base + $aux_transp) * $PRIMA;
    $vacaciones = $salario_base * $VACACIONES;

    $total_deducciones = $salud_trab + $pension_trab;
    $neto_pagar = $salario_base + $aux_transp - $total_deducciones;

    $resultado = compact(
        'salario_base','aux_transp','salud_trab','pension_trab',
        'salud_emp','pension_emp','arl','cesantias','int_cesantias',
        'prima','vacaciones','total_deducciones','neto_pagar'
    );
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Liquidación de Nómina - Colombia</title>
    <style>
        body{font-family:Arial;background:#f4f6f8;padding:20px}
        .card{background:#fff;padding:20px;border-radius:8px;max-width:900px;margin:auto}
        h2{margin-top:0}
        label{display:block;margin-top:10px}
        input{width:100%;padding:8px;margin-top:4px}
        button{margin-top:15px;padding:10px 15px}
        table{width:100%;border-collapse:collapse;margin-top:20px}
        th,td{border:1px solid #ccc;padding:8px;text-align:right}
        th{text-align:left;background:#eee}
        .total{font-weight:bold;background:#f0f0f0}
    </style>
</head>
<body>

<div class="card">
    <h2>Liquidación de Nómina (Colombia)</h2>

    <form method="post">
        <label>Salario Mensual
            <input type="number" name="salario" required step="0.01">
        </label>
        <label>Días Trabajados
            <input type="number" name="dias" required value="30">
        </label>
        <button type="submit">Liquidar</button>
    </form>

<?php if ($resultado): ?>
    <table>
        <tr><th colspan="2">Devengado</th></tr>
        <tr><td>Salario</td><td><?= number_format($salario_base,2) ?></td></tr>
        <tr><td>Auxilio Transporte</td><td><?= number_format($aux_transp,2) ?></td></tr>

        <tr><th colspan="2">Deducciones Trabajador</th></tr>
        <tr><td>Salud (4%)</td><td><?= number_format($salud_trab,2) ?></td></tr>
        <tr><td>Pensión (4%)</td><td><?= number_format($pension_trab,2) ?></td></tr>
        <tr class="total"><td>Total Deducciones</td><td><?= number_format($total_deducciones,2) ?></td></tr>

        <tr class="total"><td>Neto a Pagar</td><td><?= number_format($neto_pagar,2) ?></td></tr>

        <tr><th colspan="2">Prestaciones Sociales (Empresa)</th></tr>
        <tr><td>Cesantías</td><td><?= number_format($cesantias,2) ?></td></tr>
        <tr><td>Intereses Cesantías</td><td><?= number_format($int_cesantias,2) ?></td></tr>
        <tr><td>Prima</td><td><?= number_format($prima,2) ?></td></tr>
        <tr><td>Vacaciones</td><td><?= number_format($vacaciones,2) ?></td></tr>

        <tr><th colspan="2">Aportes Empleador</th></tr>
        <tr><td>Salud (8.5%)</td><td><?= number_format($salud_emp,2) ?></td></tr>
        <tr><td>Pensión (12%)</td><td><?= number_format($pension_emp,2) ?></td></tr>
        <tr><td>ARL</td><td><?= number_format($arl,2) ?></td></tr>
    </table>
<?php endif; ?>
</div>

</body>
</html>
