<?php
session_start();

// Enlaces a tus conexiones existentes
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php"); 

date_default_timezone_set('America/Bogota');

// --- Función Auxiliar para Formato de Moneda ---
function moneda($v){
    return '$' . number_format((float)$v, 0, ',', '.');
}

// --- Variables de Filtro (Mes y Año) ---
$mesSel = isset($_GET['mes_filtro']) ? $_GET['mes_filtro'] : date('m');
$anioSel = isset($_GET['anio_filtro']) ? $_GET['anio_filtro'] : date('Y');
$ultimoDiaMes = cal_days_in_month(CAL_GREGORIAN, (int)$mesSel, (int)$anioSel);
$anioMes = $anioSel . $mesSel;

// --- Preparación de Etiquetas para el Eje X (Días del Mes) ---
$labelsDias = [];
$diasSemanaCorto = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
for ($i = 1; $i <= $ultimoDiaMes; $i++) {
    $fechaLabel = "$anioSel-$mesSel-" . str_pad($i, 2, "0", STR_PAD_LEFT);
    $nombreDia = $diasSemanaCorto[date('w', strtotime($fechaLabel))];
    $labelsDias[] = str_pad($i, 2, "0", STR_PAD_LEFT) . " " . $nombreDia;
}

// --- Lógica: Obtener Ventas Mensuales para Gráfica ---
function obtenerVentasMensuales($db) {
    global $anioMes, $ultimoDiaMes;
    $ventas = array_fill(1, (int)$ultimoDiaMes, 0);
    if (!$db) return $ventas;
    
    $q = "SELECT FECHA, SUM(total) as total_dia FROM (
            SELECT F.FECHA, D.CANTIDAD * D.VALORPROD as total 
            FROM FACTURAS F 
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA 
            WHERE F.ESTADO = '0' AND F.FECHA LIKE '$anioMes%'
            UNION ALL
            SELECT P.FECHA, DP.CANTIDAD * DP.VALORPROD 
            FROM PEDIDOS P 
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO 
            WHERE P.ESTADO = '0' AND P.FECHA LIKE '$anioMes%'
          ) X GROUP BY FECHA";
          
    $res = $db->query($q);
    if($res){
        while($r = $res->fetch_assoc()){
            $dia = (int)substr($r['FECHA'], 6, 2);
            if($dia >= 1 && $dia <= $ultimoDiaMes) $ventas[$dia] = (float)$r['total_dia'];
        }
    }
    return $ventas;
}

// --- Lógica: Comparativa de 4 Semanas ---
function obtenerComparativoSemanas($dbCentral, $dbDrinks) {
    $nombresDias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $comparativo = [
        'dias' => [], 
        'total_actual' => 0, 
        'total_anterior' => 0, 
        'total_antepasada' => 0,
        'total_hace_mes' => 0 
    ];
    
    // Anclamos al lunes de la semana actual
    $lunesEstaSemana = date('Y-m-d', strtotime('monday this week'));
    
    for ($i = 0; $i < 7; $i++) {
        $fActual      = date('Ymd', strtotime("$lunesEstaSemana +$i days"));
        $fPasada      = date('Ymd', strtotime("$lunesEstaSemana +$i days -7 days"));
        $fAntepasada  = date('Ymd', strtotime("$lunesEstaSemana +$i days -14 days"));
        $fHaceMes     = date('Ymd', strtotime("$lunesEstaSemana +$i days -21 days"));
        
        $qBase = "SELECT SUM(total) as total FROM (
                    SELECT D.CANTIDAD * D.VALORPROD as total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA = '?'
                    UNION ALL
                    SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA = '?'
                  ) X";

        $vAct = 0; $vAnt = 0; $vAnte = 0; $vMes = 0;
        foreach([$dbCentral, $dbDrinks] as $db) {
            if(!$db) continue;
            $resA = $db->query(str_replace('?', $fActual, $qBase))->fetch_assoc();
            $resP = $db->query(str_replace('?', $fPasada, $qBase))->fetch_assoc();
            $resAt = $db->query(str_replace('?', $fAntepasada, $qBase))->fetch_assoc();
            $resM = $db->query(str_replace('?', $fHaceMes, $qBase))->fetch_assoc();
            
            $vAct  += (float)$resA['total'];
            $vAnt  += (float)$resP['total'];
            $vAnte += (float)$resAt['total'];
            $vMes  += (float)$resM['total'];
        }

        $comparativo['dias'][] = [
            'dia' => $nombresDias[$i], 
            'actual' => $vAct, 
            'anterior' => $vAnt, 
            'antepasada' => $vAnte,
            'hace_mes' => $vMes
        ];
        
        $comparativo['total_actual'] += $vAct;
        $comparativo['total_anterior'] += $vAnt;
        $comparativo['total_antepasada'] += $vAnte;
        $comparativo['total_hace_mes'] += $vMes;
    }
    return $comparativo;
}

// Ejecución de Consultas
$ventasCentral = obtenerVentasMensuales($mysqliCentral);
$ventasDrinks = obtenerVentasMensuales($mysqliDrinks);
$datosSemanales = obtenerComparativoSemanas($mysqliCentral, $mysqliDrinks);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Ventas</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f9; margin: 20px; color: #334155; }
        .container { max-width: 1200px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        
        /* Header y Filtros */
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .title { font-size: 1.25rem; font-weight: bold; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .filters select { padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; background: #fff; }
        .btn-ver { background: #2563eb; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 500; transition: background 0.2s; }
        .btn-ver:hover { background: #1d4ed8; }

        /* Gráfica */
        .chart-container { position: relative; height: 350px; margin-bottom: 40px; padding: 15px; border: 1px solid #f1f5f9; border-radius: 10px; }

        /* Tabla */
        .table-title { text-align: center; font-weight: bold; margin-bottom: 15px; color: #475569; }
        table { width: 100%; border-collapse: collapse; border-radius: 8px; overflow: hidden; }
        th { background: #1e293b; color: white; text-align: left; padding: 14px; font-size: 0.85rem; text-transform: uppercase; }
        td { padding: 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .total-row { background: #f1f5f9 !important; font-weight: bold; color: #1e40af; border-top: 2px solid #cbd5e1; }
        
        /* Tendencias */
        .trend-up { color: #10b981; font-weight: bold; }
        .trend-down { color: #ef4444; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <!-- Sección superior: Título y Filtros -->
    <div class="header-flex">
        <div class="title">📈 Evolución de Ventas Diarias</div>
        <form method="GET" class="filters">
            <select name="mes_filtro">
                <?php
                $meses = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
                foreach($meses as $num => $nombre) {
                    $sel = ($num == $mesSel) ? 'selected' : '';
                    echo "<option value='$num' $sel>$nombre</option>";
                }
                ?>
            </select>
            <select name="anio_filtro">
                <option value="2026" <?= $anioSel == '2026' ? 'selected' : '' ?>>2026</option>
                <option value="2025" <?= $anioSel == '2025' ? 'selected' : '' ?>>2025</option>
            </select>
            <button type="submit" class="btn-ver">Ver Mes</button>
            <a href="?" style="font-size: 0.8rem; color: #94a3b8; text-decoration: none; margin-left: 10px;">Restablecer</a>
        </form>
    </div>

    <!-- Gráfica de Barras Apiladas -->
    <div class="chart-container">
        <canvas id="ventasChart"></canvas>
    </div>

    <!-- Tabla Comparativa de 4 Semanas -->
    <div class="table-title">📊 Comparativa 4 Semanas (Lunes a Domingo)</div>
    <table>
        <thead>
            <tr>
                <th>Día</th>
                <th style="text-align:right">Hace 1 Mes</th>
                <th style="text-align:right">Sem. Antepasada</th>
                <th style="text-align:right">Sem. Anterior</th>
                <th style="text-align:right">Semana Actual</th>
                <th style="text-align:center">Tendencia</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($datosSemanales['dias'] as $d): 
                $dif = $d['actual'] - $d['anterior'];
            ?>
            <tr>
                <td><strong><?= $d['dia'] ?></strong></td>
                <td style="text-align:right; color: #cbd5e1;"><?= moneda($d['hace_mes']) ?></td>
                <td style="text-align:right; color: #94a3b8;"><?= moneda($d['antepasada']) ?></td>
                <td style="text-align:right; color: #64748b;"><?= moneda($d['anterior']) ?></td>
                <td style="text-align:right; font-weight: bold;"><?= moneda($d['actual']) ?></td>
                <td style="text-align:center;">
                    <span class="<?= $dif >= 0 ? 'trend-up' : 'trend-down' ?>" style="font-size: 1.2rem;">
                        <?= $dif >= 0 ? '▲' : '▼' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td>TOTALES</td>
                <td style="text-align:right"><?= moneda($datosSemanales['total_hace_mes']) ?></td>
                <td style="text-align:right"><?= moneda($datosSemanales['total_antepasada']) ?></td>
                <td style="text-align:right"><?= moneda($datosSemanales['total_anterior']) ?></td>
                <td style="text-align:right; font-size: 1.1rem;"><?= moneda($datosSemanales['total_actual']) ?></td>
                <td style="text-align:center">🚀</td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
const ctx = document.getElementById('ventasChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labelsDias) ?>,
        datasets: [
            {
                label: 'Central',
                data: <?= json_encode(array_values($ventasCentral)) ?>,
                backgroundColor: '#2563eb', // Azul
                borderRadius: 5
            },
            {
                label: 'Drinks',
                data: <?= json_encode(array_values($ventasDrinks)) ?>,
                backgroundColor: '#10b981', // Verde
                borderRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { 
                stacked: true, 
                grid: { display: false } 
            },
            y: { 
                stacked: true, 
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: {
                    callback: function(value) {
                        if (value >= 1000000) return '$' + (value / 1000000) + 'M';
                        return '$' + value.toLocaleString();
                    }
                }
            }
        },
        plugins: {
            legend: { 
                position: 'top', 
                align: 'end',
                labels: { usePointStyle: true, padding: 20 }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': $' + context.raw.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

</body>
</html>