<?php
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

require_once("Conexion.php"); // $mysqli

$sql = "
    SELECT 
        t.CedulaNit AS Nit,
        t.Nombre,
        SUM(p.Monto) AS Saldo
    FROM terceros t
    INNER JOIN pagosproveedores p 
        ON p.Nit = t.CedulaNit
    WHERE
        t.Estado = 1
        AND p.Estado = '1'
        AND t.Nombre IS NOT NULL
        AND t.Nombre <> ''
    GROUP BY 
        t.CedulaNit, t.Nombre
    HAVING 
        SUM(p.Monto) <> 0
    ORDER BY 
        Saldo DESC
";

$res = $mysqli->query($sql);

$rows = '';
$labels = [];
$values = [];

$totalDeuda = 0;
$totalFavor = 0;

if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {

        $saldo = (float)$r['Saldo'];

        if ($saldo > 0) {
            $totalDeuda += $saldo;
        } else {
            $totalFavor += abs($saldo);
        }

        $labels[] = $r['Nombre'];
        $values[] = round($saldo, 2);

        $badge = $saldo > 0 
            ? "<span class='badge badge-danger'>Por pagar</span>" 
            : "<span class='badge badge-success'>A favor</span>";

        $rows .= "
        <tr>
            <td>{$r['Nit']}</td>
            <td>".htmlspecialchars($r['Nombre'])."</td>
            <td class='text-right'>$ ".number_format($saldo,2,',','.')."</td>
            <td class='text-center'>$badge</td>
        </tr>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe Gerencial - Proveedores</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body {
    font-family: "Segoe UI", Arial, sans-serif;
    background:#eef1f5;
    margin:0;
    padding:30px;
}
.report {
    background:#fff;
    padding:30px;
    border-radius:10px;
    max-width:1200px;
    margin:auto;
    box-shadow:0 4px 15px rgba(0,0,0,.08);
}
.header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:2px solid #dee2e6;
    padding-bottom:15px;
}
.header h2 {
    margin:0;
}
.date {
    color:#6c757d;
}
.kpis {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:20px;
    margin:30px 0;
}
.kpi {
    padding:20px;
    border-radius:8px;
    color:#fff;
}
.kpi.deuda { background:#dc3545; }
.kpi.favor { background:#28a745; }
.kpi.neto { background:#343a40; }

.kpi h4 {
    margin:0;
    font-size:14px;
    font-weight:normal;
    opacity:.9;
}
.kpi strong {
    font-size:24px;
}

table {
    width:100%;
    border-collapse:collapse;
    margin-top:30px;
}
th, td {
    padding:10px;
    border-bottom:1px solid #dee2e6;
}
th {
    background:#343a40;
    color:#fff;
    text-align:left;
}
.text-right { text-align:right; }
.text-center { text-align:center; }

.badge {
    padding:4px 10px;
    border-radius:12px;
    font-size:12px;
    color:#fff;
}
.badge-danger { background:#dc3545; }
.badge-success { background:#28a745; }

canvas {
    margin-top:50px;
}

.footer {
    margin-top:40px;
    font-size:13px;
    color:#6c757d;
    text-align:right;
}
</style>
</head>

<body>

<div class="report">

    <div class="header">
        <h2>📊 Informe Gerencial – Proveedores</h2>
        <div class="date"><?= date('d/m/Y') ?></div>
    </div>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi deuda">
            <h4>Total por Pagar</h4>
            <strong>$ <?= number_format($totalDeuda,2,',','.') ?></strong>
        </div>
        <div class="kpi favor">
            <h4>Total a Favor</h4>
            <strong>$ <?= number_format($totalFavor,2,',','.') ?></strong>
        </div>
        <div class="kpi neto">
            <h4>Saldo Neto</h4>
            <strong>$ <?= number_format($totalDeuda - $totalFavor,2,',','.') ?></strong>
        </div>
    </div>

    <!-- Tabla -->
    <table>
        <thead>
            <tr>
                <th>NIT</th>
                <th>Proveedor</th>
                <th>Saldo Neto</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?= $rows ?>
        </tbody>
    </table>

    <!-- Gráfica -->
    <canvas id="grafica"></canvas>

    <div class="footer">
        Informe generado automáticamente – Sistema Financiero
    </div>
</div>

<script>
new Chart(document.getElementById('grafica'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: 'Saldo Neto por Proveedor',
            data: <?= json_encode($values) ?>
        }]
    },
    options: {
        responsive:true,
        plugins:{
            legend:{display:false},
            tooltip:{
                callbacks:{
                    label: c =>
                        '$ ' + c.raw.toLocaleString('es-CO',{minimumFractionDigits:2})
                }
            }
        },
        scales:{
            y:{ beginAtZero:true }
        }
    }
});
</script>

</body>
</html>
