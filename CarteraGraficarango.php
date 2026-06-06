<?php
require 'Conexion.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}
date_default_timezone_set('America/Bogota');

// -------------------------------------------------
// VARIABLES DE FILTRO
// -------------------------------------------------
$nit = $_GET['proveedor'] ?? '';
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01'); // Por defecto inicio de mes
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$filtro_sql = "";

if ($nit && isset($_GET['consultar'])) {
    $f_ini_clean = str_replace('-', '', $f_inicio);
    $f_fin_clean = str_replace('-', '', $f_fin);
    $filtro_sql = " AND F_Creacion BETWEEN '$f_ini_clean' AND '$f_fin_clean'";
}

// -------------------------------------------------
// EXPORTAR A EXCEL (Debe ir antes de cualquier HTML)
// -------------------------------------------------
if (isset($_GET['exportar'])) {
    header("Content-Type: application/vnd.ms-excel; charset=iso-8859-1");
    header("Content-Disposition: attachment; filename=Reporte_Cartera_$nit.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $resExp = $mysqli->query("SELECT * FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' $filtro_sql ORDER BY F_Creacion DESC");
    
    echo "<table border='1'>
            <tr>
                <th style='background-color: #007bff; color: white;'>Fecha</th>
                <th style='background-color: #007bff; color: white;'>Hora</th>
                <th style='background-color: #007bff; color: white;'>Tipo</th>
                <th style='background-color: #007bff; color: white;'>Monto</th>
                <th style='background-color: #007bff; color: white;'>Descripcion</th>
            </tr>";
    while ($row = $resExp->fetch_assoc()) {
        echo "<tr>
                <td>{$row['F_Creacion']}</td>
                <td>{$row['H_Creacion']}</td>
                <td>" . ($row['TipoMonto'] == 'F' ? 'Factura' : 'Pago') . "</td>
                <td>{$row['Monto']}</td>
                <td>" . utf8_decode($row['Descripcion']) . "</td>
              </tr>";
    }
    echo "</table>";
    exit;
}

// -------------------------------------------------
// PROVEEDORES
// -------------------------------------------------
$proveedores = $mysqli->query("SELECT CedulaNit AS Nit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre");

// -------------------------------------------------
// INSERTAR / EDITAR / BORRAR
// -------------------------------------------------
if (isset($_POST['grabar'])) {
    $nit = $_POST['proveedor'];
    $fecha = str_replace('-', '', $_POST['fecha']);
    $hora = date("H:i:s");
    $tipo = $_POST['tipo'];
    $monto = floatval(str_replace('.', '', $_POST['monto']));
    $desc = strtoupper($mysqli->real_escape_string($_POST['descripcion']));
    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("INSERT INTO pagosproveedores (Nit,F_Creacion,H_Creacion,Monto,TipoMonto,Descripcion,Estado) VALUES ('$nit','$fecha','$hora','$monto','$tipo','$desc','1')");
    header("Location: ?proveedor=$nit&f_inicio=$f_inicio&f_fin=$f_fin&consultar=1");
    exit;
}

if (isset($_POST['editar'])) {
    $nit = $_POST['nit'];
    $fecha = $_POST['fecha'];
    $hora = $_POST['hora'];
    $tipo = $_POST['tipo'];
    $monto = floatval(str_replace('.', '', $_POST['monto']));
    $desc = strtoupper($mysqli->real_escape_string($_POST['descripcion']));
    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("UPDATE pagosproveedores SET Monto='$monto', TipoMonto='$tipo', Descripcion='$desc' WHERE Nit='$nit' AND F_Creacion='$fecha' AND H_Creacion='$hora'");
    header("Location: ?proveedor=$nit&f_inicio=$f_inicio&f_fin=$f_fin&consultar=1");
    exit;
}

if (isset($_GET['borrar'])) {
    $nit_b = $_GET['nit'];
    $fecha_b = $_GET['f'];
    $hora_b = $_GET['h'];
    $mysqli->query("UPDATE pagosproveedores SET Estado='0' WHERE Nit='$nit_b' AND F_Creacion='$fecha_b' AND H_Creacion='$hora_b'");
    header("Location: ?proveedor=$nit_b&f_inicio=$f_inicio&f_fin=$f_fin&consultar=1");
    exit;
}

// -------------------------------------------------
// PAGINACIÓN Y CONSULTA CON FILTRO
// -------------------------------------------------
$porPagina = 10;
$pagina = max(1, intval($_GET['page'] ?? 1));
$offset = ($pagina - 1) * $porPagina;
$abonos = [];
$total = 0;

// Arreglos para la gráfica mensualizada
$mesesGrafica = [];
$facturasGrafica = [];
$pagosGrafica = [];

if ($nit && isset($_GET['consultar'])) {
    $resTotal = $mysqli->query("SELECT IFNULL(SUM(Monto),0) total, COUNT(*) cant FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' $filtro_sql");
    $rowT = $resTotal->fetch_assoc();
    $total = $rowT['total'];
    $totalPaginas = max(1, ceil($rowT['cant'] / $porPagina));

    $res = $mysqli->query("SELECT * FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' $filtro_sql ORDER BY F_Creacion DESC, H_Creacion DESC LIMIT $offset,$porPagina");
    while ($r = $res->fetch_assoc()) { $abonos[] = $r; }

    /* =================================================
       AGRUPACIÓN CRONOLÓGICA MENSUAL PARA LA GRÁFICA
    ================================================= */
    $mesesNombres = ['01'=>'Ene', '02'=>'Feb', '03'=>'Mar', '04'=>'Abr', '05'=>'May', '06'=>'Jun', '07'=>'Jul', '08'=>'Ago', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Dic'];
    
    $resGrafica = $mysqli->query("
        SELECT 
            SUBSTRING(F_Creacion, 1, 6) AS AnioMes,
            SUM(CASE WHEN TipoMonto = 'F' THEN ABS(Monto) ELSE 0 END) AS TotalFacturas,
            SUM(CASE WHEN TipoMonto = 'P' THEN Monto ELSE 0 END) AS TotalPagos
        FROM pagosproveedores 
        WHERE Nit='$nit' AND Estado='1' $filtro_sql 
        GROUP BY AnioMes 
        ORDER BY AnioMes ASC
    ");

    while ($g = $resGrafica->fetch_assoc()) {
        $anio = substr($g['AnioMes'], 0, 4);
        $mesNum = substr($g['AnioMes'], 4, 2);
        $labelMes = ($mesesNombres[$mesNum] ?? $mesNum) . " " . $anio;

        $mesesGrafica[] = $labelMes;
        $facturasGrafica[] = (float)$g['TotalFacturas'];
        $pagosGrafica[] = (float)$g['TotalPagos'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Cartera Proveedores</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        body{font-family:Arial;background:#f4f4f4;font-size:14px}
        .box{background:#fff;padding:15px;border-radius:10px;max-width:1150px;margin:auto;box-shadow:0 2px 5px rgba(0,0,0,0.1)}
        .row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center}
        .row input,.row select,.row button{padding:7px;border-radius:6px;border:1px solid #ccc}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th{background:#007bff;color:#fff;padding:8px}
        td{padding:8px;border-bottom:1px solid #ddd}
        .neg{background:#fde2e2}
        .pos{background:#e6fffa}
        .total-box{display:flex; justify-content: space-between; background:#eef;padding:10px;border-radius:6px;margin:10px 0;font-weight:bold}
        .btn-excel{background:#198754;color:#fff;border:none;cursor:pointer}
        .paginacion{text-align:center;margin-top:10px}
        .btn-save{color:#198754; background:none; border:none; cursor:pointer; font-size:1.2em}
        .btn-del{color:#dc3545; margin-left:10px}
        
        /* Contenedor adaptativo del gráfico */
        .chart-container { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 15px 0; height: 340px; position: relative; }
    </style>
</head>
<body>

<div class="box">
    <h2>Cartera por Proveedor</h2>

    <form method="GET" class="row">
        <select name="proveedor" required>
            <option value="">Seleccione Proveedor</option>
            <?php while($p=$proveedores->fetch_assoc()): ?>
                <option value="<?= $p['Nit'] ?>" <?= $p['Nit']==$nit?'selected':'' ?>><?= $p['Nombre'] ?></option>
            <?php endwhile; ?>
        </select>
        <label>Desde:</label>
        <input type="date" name="f_inicio" value="<?= $f_inicio ?>">
        <label>Hasta:</label>
        <input type="date" name="f_fin" value="<?= $f_fin ?>">
        <button name="consultar" type="submit" style="background:#007bff; color:white">Consultar</button>
        
        <?php if($nit): ?>
            <button type="submit" name="exportar" value="1" class="btn-excel">Exportar Excel</button>
        <?php endif; ?>
    </form>

    <hr>

    <?php if($nit): ?>
    <form method="POST" class="row">
        <input type="hidden" name="proveedor" value="<?= $nit ?>">
        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
        <select name="tipo">
            <option value="F">Factura (-)</option>
            <option value="P">Pago (+)</option>
        </select>
        <input name="monto" class="monto" placeholder="Monto" required>
        <input name="descripcion" placeholder="Descripción" style="flex-grow:1">
        <button name="grabar" style="background:#198754; color:white">Grabar Registro</button>
    </form>
    <?php endif; ?>

    <?php if(!empty($mesesGrafica)): ?>
    <div class="chart-container">
        <canvas id="graficaProveedorMes"></canvas>
    </div>
    <?php endif; ?>

    <?php if($abonos): ?>
    <div class="total-box">
        <span>Saldo en Rango: <?= number_format($total,0,',','.') ?></span>
        <span>Mostrando registros del <?= $f_inicio ?> al <?= $f_fin ?></span>
    </div>

    <table>
        <tr>
            <th>Fecha</th>
            <th>Hora</th>
            <th>Tipo</th>
            <th>Monto</th>
            <th>Descripción</th>
            <th>Acciones</th>
        </tr>

        <?php foreach($abonos as $a): ?>
        <form method="POST">
            <tr class="<?= $a['Monto']<0?'neg':'pos' ?>">
                <td><?= $a['F_Creacion'] ?></td>
                <td><?= $a['H_Creacion'] ?></td>
                <td>
                    <select name="tipo">
                        <option value="F" <?= $a['TipoMonto']=='F'?'selected':'' ?>>F</option>
                        <option value="P" <?= $a['TipoMonto']=='P'?'selected':'' ?>>P</option>
                    </select>
                </td>
                <td>
                    <input name="monto" class="monto" value="<?= number_format(abs($a['Monto']),0,',','.') ?>" style="width:100px">
                </td>
                <td>
                    <input name="descripcion" value="<?= htmlspecialchars($a['Description'] ?? $a['Descripcion']) ?>" style="width:100%">
                </td>
                <td>
                    <input type="hidden" name="nit" value="<?= $a['Nit'] ?>">
                    <input type="hidden" name="fecha" value="<?= $a['F_Creacion'] ?>">
                    <input type="hidden" name="hora" value="<?= $a['H_Creacion'] ?>">
                    <button class="btn-save" name="editar" title="Guardar Cambios">💾</button>
                    <a class="btn-del" href="?proveedor=<?= $nit ?>&f_inicio=<?= $f_inicio ?>&f_fin=<?= $f_fin ?>&consultar=1&borrar=1&nit=<?= $a['Nit'] ?>&f=<?= $a['F_Creacion'] ?>&h=<?= $a['H_Creacion'] ?>" onclick="return confirm('¿Eliminar?')">🗑️</a>
                </td>
            </tr>
        </form>
        <?php endforeach; ?>
    </table>

    <div class="paginacion">
        <?php 
        $params = "&proveedor=$nit&f_inicio=$f_inicio&f_fin=$f_fin&consultar=1";
        if($pagina > 1): ?>
            <a href="?page=<?= $pagina-1 ?><?= $params ?>">⬅ Anterior</a>
        <?php endif; ?>
        &nbsp; Página <?= $pagina ?> de <?= $totalPaginas ?> &nbsp;
        <?php if($pagina < $totalPaginas): ?>
            <a href="?page=<?= $pagina+1 ?><?= $params ?>">Siguiente ➡</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Formateador dinámico de entrada monetaria para los inputs
document.querySelectorAll('.monto').forEach(input => {
    input.addEventListener('input', () => {
        let v = input.value.replace(/\D/g,'');
        input.value = v.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    });
});

// Construcción del Gráfico Mixto desde PHP
<?php if(!empty($mesesGrafica)): ?>
const ctx = document.getElementById('graficaProveedorMes').getContext('2d');
const labelsMeses = <?= json_encode($mesesGrafica) ?>;
const dataFacturas = <?= json_encode($facturasGrafica) ?>;
const dataPagos = <?= json_encode($pagosGrafica) ?>;

// Flujo neto mensual (Abonos - Cargos)
const dataNeto = dataPagos.map((pago, index) => pago - dataFacturas[index]);

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labelsMeses,
        datasets: [
            {
                type: 'line',
                label: 'Flujo Neto (Pagos - Facturas)',
                data: dataNeto,
                borderColor: '#ff9800',
                backgroundColor: 'transparent',
                borderWidth: 2.5,
                pointRadius: 4,
                tension: 0.2,
                datalabels: { display: false } // Línea limpia sin textos montados
            },
            {
                label: 'Facturado (Cargos)',
                data: dataFacturas,
                backgroundColor: '#dc3545',
                borderRadius: 4
            },
            {
                label: 'Pagado (Abonos)',
                data: dataPagos,
                backgroundColor: '#198754',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        // Formato del eje vertical expresado en Millones (M)
                        return '$' + (value / 1000000).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + 'M';
                    }
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        // El tooltip flotante muestra el valor completo real sin alteraciones
                        return ' ' + context.dataset.label + ': $' + context.parsed.y.toLocaleString('es-CO');
                    }
                }
            },
            datalabels: {
                anchor: 'center',  // Anclaje al centro geométrico interno de la barra
                align: 'center',   // Alineación del texto centrada por dentro
                font: { size: 10, weight: 'bold' },
                formatter: function(value) {
                    if (value === 0 || value < 10000) return null; // Ignora ceros o montos insignificantes
                    // Divide el valor por 1 millón para formatear en millones (M) con 1 decimal
                    return '$' + (value / 1000000).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + 'M';
                },
                color: '#ffffff' // Color de fuente blanco para contrastar adecuadamente con el verde y rojo de fondo
            }
        }
    },
    plugins: [ChartDataLabels]
});
<?php endif; ?>
</script>

</body>
</html>