<?php
require('Conexion.php');

// Consulta SQL: proveedores con saldo distinto de cero
$sql = "
SELECT 
    t.Nit,
    t.Nombre,
    SUM(p.Monto) AS Saldo
FROM 
    terceros t
INNER JOIN 
    pagosproveedores p ON t.Nit = p.Nit 
WHERE
    t.Estado = '1' AND p.estado='1' AND t.Nombre IS NOT NULL AND t.Nombre != ''
GROUP BY 
    t.Nit, t.Nombre
HAVING 
    SUM(p.Monto) != 0
ORDER BY 
    Saldo DESC
";

$result = $mysqli->query($sql);

$proveedores = [];
$montos = [];
$nits = [];
$totalSaldo = 0;

if($result->num_rows > 0){
    while($row = $result->fetch_assoc()){
        $nits[] = $row['Nit'];
        $proveedores[] = $row['Nombre'];
        $montos[] = $row['Saldo'];
        $totalSaldo += $row['Saldo'];
    }
}
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Proveedores con Saldo</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<style>
body {
    font-family: Arial, sans-serif;
    margin: 30px;
    background-color: #f9f9f9;
    text-align: center;
}
h2 {
    color: #333;
}
.table-container {
    overflow-x: auto;
    margin: 0 auto 40px;
    max-width: 800px;
}
table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 40px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    background-color: white;
}
th, td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: left;
}
th {
    background-color: #4CAF50;
    color: white;
    position: sticky;
    top: 0;
}
tr:nth-child(even){background-color: #f2f2f2;}
tr:hover {background-color: #e0f7fa;}
tr.total-row {
    background-color: #ffc107;
    font-weight: bold;
}
/* Botón cerrar */
.btn-cerrar {
    display: inline-block;
    margin-bottom: 20px;
    padding: 10px 25px;
    background-color: #f44336;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    font-weight: bold;
}
.btn-cerrar:hover {
    background-color: #d32f2f;
    box-shadow: 0 6px 10px rgba(0,0,0,0.3);
    transform: translateY(-2px);
}
/* Contenedor del gráfico */
.chart-container {
    width: 100%;
    max-width: 1100px;
    height: 600px;
    margin: 0 auto 50px;
}
</style>
</head>
<body>

<h2>Proveedores con Saldo</h2>

<!-- Botón Cerrar -->
<a href="#" class="btn-cerrar" onclick="window.close();">× Cerrar</a>

<?php if(count($proveedores) > 0): ?>
<div class="table-container">
<table>
    <tr>
        <th>Nit</th>
        <th>Proveedor</th>
        <th>Saldo</th>
    </tr>
    <?php foreach($proveedores as $i => $nombre): ?>
    <tr>
        <td><?= htmlspecialchars($nits[$i]) ?></td>
        <td><?= htmlspecialchars($nombre) ?></td>
        <td>$<?= number_format($montos[$i], 2, ',', '.') ?></td>
    </tr>
    <?php endforeach; ?>
    <!-- Fila Total -->
    <tr class="total-row">
        <td colspan="2">TOTAL</td>
        <td>$<?= number_format($totalSaldo, 2, ',', '.') ?></td>
    </tr>
</table>
</div>

<div class="chart-container">
    <canvas id="saldoChart"></canvas>
</div>

<script>
// Generar colores aleatorios
function generarColores(n) {
    let colores = [];
    for (let i = 0; i < n; i++) {
        colores.push('hsl(' + (i * 360 / n) + ', 70%, 50%)');
    }
    return colores;
}

const labels = <?= json_encode(array_map(function($i, $n){ return $i.' - '.$n; }, $nits, $proveedores)) ?>;
const data = <?= json_encode($montos) ?>;
const colores = generarColores(data.length);

const ctx = document.getElementById('saldoChart').getContext('2d');
const saldoChart = new Chart(ctx, {
    type: 'bar', // vertical
    data: {
        labels: labels,
        datasets: [{
            label: 'Saldo por Proveedor',
            data: data,
            backgroundColor: colores,
            borderColor: colores,
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                color: '#000',
                font: { weight: 'bold', size: 12 },
                formatter: (value) => '$' + value.toLocaleString('es-CO', {minimumFractionDigits:2})
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.raw.toLocaleString('es-CO', {style: 'currency', currency: 'COP'});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString('es-CO');
                    }
                }
            },
            x: {
                ticks: {
                    autoSkip: false,
                    maxRotation: 45,
                    minRotation: 0
                }
            }
        }
    },
    plugins: [ChartDataLabels]
});
</script>

<?php else: ?>
<p>No hay proveedores con saldo.</p>
<?php endif; ?>

</body>
</html>
