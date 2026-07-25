<?php
/* ============================================================
    PANEL DE PROGRESO Y ESTADO DE CONTEO - DOBLE VISTA RESPONSIVE
============================================================ */
require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks
require_once("Conexion.php");    // ADM ($mysqli)

session_start();
date_default_timezone_set('America/Bogota');

// Definición de sedes
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida. Por favor inicie sesión.");
}

/* ============================================================
    FUNCIÓN PARA OBTENER MÉTRICAS POR SEDE
============================================================ */
function obtenerMetricasSede($mysqli, $nitSede) {
    // 1. Total de categorías habilitadas para conteo web
    $stmtCatTotal = $mysqli->prepare("
        SELECT COUNT(*) as total 
        FROM categorias c 
        WHERE c.Estado='1' AND (c.SegWebT+c.SegWebF)>=1
    ");
    $stmtCatTotal->execute();
    $totalCategoriasHabilitadas = $stmtCatTotal->get_result()->fetch_assoc()['total'] ?? 0;

    // 2. Categorías contadas hoy en la sede
    $stmtCatContadas = $mysqli->prepare("
        SELECT COUNT(DISTINCT CodCat) as total 
        FROM conteoweb 
        WHERE DATE(fecha_conteo) = CURDATE() 
          AND estado = 'A' 
          AND TRIM(NitEmpresa) = TRIM(?)
    ");
    $stmtCatContadas->bind_param("s", $nitSede);
    $stmtCatContadas->execute();
    $totalContadasHoy = $stmtCatContadas->get_result()->fetch_assoc()['total'] ?? 0;

    // Porcentaje de avance
    $porcentajeAvance = ($totalCategoriasHabilitadas > 0) ? ($totalContadasHoy / $totalCategoriasHabilitadas) * 100 : 0;

    // 3. Resumen detallado por Familia (Ordenado de menor a mayor cantidad total_cat)
    $familiasResumen = [];
    $sqlFamilias = "
        SELECT 
            f.id AS id_familia,
            f.nombre AS nombre_familia,
            COUNT(c.CodCat) AS total_cat,
            SUM(CASE WHEN c.CodCat IN (
                SELECT DISTINCT CodCat FROM conteoweb WHERE DATE(fecha_conteo) = CURDATE() AND estado = 'A' AND TRIM(NitEmpresa) = TRIM(?)
            ) THEN 1 ELSE 0 END) AS contadas_cat
        FROM familias f
        INNER JOIN categorias c ON c.Tipo = f.id
        WHERE c.Estado='1' AND (c.SegWebT+c.SegWebF)>=1
        GROUP BY f.id, f.nombre
        ORDER BY total_cat ASC, f.nombre ASC
    ";
    $stmtFam = $mysqli->prepare($sqlFamilias);
    $stmtFam->bind_param("s", $nitSede);
    $stmtFam->execute();
    $resFam = $stmtFam->get_result();
    while ($row = $resFam->fetch_assoc()) {
        $familiasResumen[] = $row;
    }

    return [
        'total_categorias' => $totalCategoriasHabilitadas,
        'contadas_hoy' => $totalContadasHoy,
        'pendientes' => max(0, $totalCategoriasHabilitadas - $totalContadasHoy),
        'porcentaje' => $porcentajeAvance,
        'familias' => $familiasResumen
    ];
}

// Obtener datos para ambas sedes
$datosCentral = obtenerMetricasSede($mysqli, NIT_CENTRAL);
$datosDrinks  = obtenerMetricasSede($mysqli, NIT_DRINKS);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Panel de Avance | Central y Drinks</title>
    <style>
        * { box-sizing: border-box; }
        html, body { 
            font-family: 'Segoe UI', sans-serif; 
            background: #f4f7f6; 
            margin: 0; 
            padding: 0; 
            color: #333; 
            width: 100%;
            min-height: 100vh;
        }
        
        .main-container { 
            width: 100%; 
            max-width: 100%;
            margin: 0 auto; 
            padding: 10px; 
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .nav-links { 
            margin-bottom: 10px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: #fff; 
            padding: 10px 15px; 
            border-radius: 8px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
            flex-wrap: wrap; 
            gap: 10px; 
        }
        .nav-links a { color: #007bff; text-decoration: none; font-size: 13px; font-weight: bold; }

        /* Contenedor adaptativo flexible y fluido */
        .panels-wrapper { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); 
            gap: 15px; 
            width: 100%;
            flex: 1;
        }
        
        .card { 
            background: #fff; 
            padding: 15px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
            display: flex;
            flex-direction: column;
            width: 100%;
            overflow: hidden;
        }
        
        .btn-refresh { 
            text-decoration: none; 
            padding: 6px 12px; 
            border-radius: 6px; 
            background: #fff; 
            border: 1px solid #ccc; 
            cursor: pointer; 
            font-size: 13px; 
        }

        .metrics-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 8px; 
            margin-bottom: 12px; 
        }
        .metric-box { 
            background: #f8f9fa; 
            padding: 10px 5px; 
            border-radius: 8px; 
            border: 1px solid #e9ecef; 
            text-align: center; 
        }
        .metric-box h3 { margin: 0 0 4px 0; font-size: 10px; color: #666; text-transform: uppercase; }
        .metric-box span { font-size: 18px; font-weight: bold; color: #2c3e50; }

        /* Barra de Progreso */
        .progress-container { background: #e9ecef; border-radius: 10px; height: 16px; width: 100%; overflow: hidden; margin-bottom: 12px; position: relative; }
        .progress-bar { background: #28a745; height: 100%; width: 0%; transition: width 0.6s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px; font-weight: bold; }

        .table-responsive { 
            width: 100%; 
            overflow-x: auto; 
            overflow-y: auto;
            max-height: 65vh; /* Altura aumentada para mostrar más filas sin requerir tanto desplazamiento */
            min-height: 400px; /* Altura mínima expandida */
            -webkit-overflow-scrolling: touch;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            margin-top: 5px;
            flex: 1;
        }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #f0f0f0; text-align: left; font-size: 11px; }
        th { background: #f8f9fa; color: #666; text-transform: uppercase; font-size: 10px; position: sticky; top: 0; z-index: 1; }

        .badge-status { padding: 3px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; }
        .badge-complete { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }

        /* Breakpoints optimizados para 100% de adaptabilidad móvil y tablets */
        @media (max-width: 768px) {
            .panels-wrapper { grid-template-columns: 1fr; }
            .main-container { padding: 5px; }
            .card { padding: 12px; }
            .metric-box span { font-size: 16px; }
            .table-responsive { max-height: 50vh; min-height: 320px; }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="nav-links">
        <a href="index.php">⬅️ Volver al Conteo Principal</a>
        <span style="font-size:11px; color:#888;">Servidor PHP 8.3.6 activo en puerto 8000</span>
        <button type="button" class="btn-refresh" onclick="window.location.reload()">🔄 Actualizar Todo</button>
    </div>

    <div class="panels-wrapper">

        <!-- ================= PANEL CENTRAL ================= -->
        <div class="card">
            <h2 style="margin:0 0 3px 0; color:#2c3e50; font-size: 16px;">📊 Sede CENTRAL</h2>
            <p style="margin:0 0 10px 0; color:#666; font-size:11px;">Progreso de conteo físico para hoy.</p>

            <div class="metrics-grid">
                <div class="metric-box">
                    <h3>Total Cat.</h3>
                    <span><?= $datosCentral['total_categorias'] ?></span>
                </div>
                <div class="metric-box">
                    <h3>Contadas</h3>
                    <span style="color: #28a745;"><?= $datosCentral['contadas_hoy'] ?></span>
                </div>
                <div class="metric-box">
                    <h3>Pendientes</h3>
                    <span style="color: #dc3545;"><?= $datosCentral['pendientes'] ?></span>
                </div>
            </div>

            <label style="font-weight:bold; font-size:11px; margin-bottom:4px; display:block;">Progreso: <?= number_format($datosCentral['porcentaje'], 1) ?>%</label>
            <div class="progress-container">
                <div class="progress-bar" style="width: <?= $datosCentral['porcentaje'] ?>%;">
                    <?= number_format($datosCentral['porcentaje'], 1) ?>%
                </div>
            </div>

            <h3 style="margin:10px 0 5px 0; font-size:11px; color:#444;">Desglose por Familias (Central)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Familia</th>
                            <th style="text-align:center;">Total</th>
                            <th style="text-align:center;">Cont.</th>
                            <th style="text-align:center;">Pend.</th>
                            <th style="text-align:center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($datosCentral['familias'] as $f): 
                            $pendientes = $f['total_cat'] - $f['contadas_cat'];
                            $completadoFam = ($f['total_cat'] > 0) ? ($f['contadas_cat'] / $f['total_cat']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['nombre_familia']) ?></strong></td>
                            <td align="center"><?= $f['total_cat'] ?></td>
                            <td align="center" style="color: #28a745; font-weight: bold;"><?= $f['contadas_cat'] ?></td>
                            <td align="center" style="color: #dc3545; font-weight: bold;"><?= $pendientes ?></td>
                            <td align="center">
                                <?php if($pendientes == 0): ?>
                                    <span class="badge-status badge-complete">Listo</span>
                                <?php else: ?>
                                    <span class="badge-status badge-pending"><?= number_format($completadoFam, 0) ?>%</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= PANEL DRINKS ================= -->
        <div class="card">
            <h2 style="margin:0 0 3px 0; color:#2c3e50; font-size: 16px;">📊 Sede DRINKS</h2>
            <p style="margin:0 0 10px 0; color:#666; font-size:11px;">Progreso de conteo físico para hoy.</p>

            <div class="metrics-grid">
                <div class="metric-box">
                    <h3>Total Cat.</h3>
                    <span><?= $datosDrinks['total_categorias'] ?></span>
                </div>
                <div class="metric-box">
                    <h3>Contadas</h3>
                    <span style="color: #28a745;"><?= $datosDrinks['contadas_hoy'] ?></span>
                </div>
                <div class="metric-box">
                    <h3>Pendientes</h3>
                    <span style="color: #dc3545;"><?= $datosDrinks['pendientes'] ?></span>
                </div>
            </div>

            <label style="font-weight:bold; font-size:11px; margin-bottom:4px; display:block;">Progreso: <?= number_format($datosDrinks['porcentaje'], 1) ?>%</label>
            <div class="progress-container">
                <div class="progress-bar" style="width: <?= $datosDrinks['porcentaje'] ?>%;">
                    <?= number_format($datosDrinks['porcentaje'], 1) ?>%
                </div>
            </div>

            <h3 style="margin:10px 0 5px 0; font-size:11px; color:#444;">Desglose por Familias (Drinks)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Familia</th>
                            <th style="text-align:center;">Total</th>
                            <th style="text-align:center;">Cont.</th>
                            <th style="text-align:center;">Pend.</th>
                            <th style="text-align:center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($datosDrinks['familias'] as $f): 
                            $pendientes = $f['total_cat'] - $f['contadas_cat'];
                            $completadoFam = ($f['total_cat'] > 0) ? ($f['contadas_cat'] / $f['total_cat']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['nombre_familia']) ?></strong></td>
                            <td align="center"><?= $f['total_cat'] ?></td>
                            <td align="center" style="color: #28a745; font-weight: bold;"><?= $f['contadas_cat'] ?></td>
                            <td align="center" style="color: #dc3545; font-weight: bold;"><?= $pendientes ?></td>
                            <td align="center">
                                <?php if($pendientes == 0): ?>
                                    <span class="badge-status badge-complete">Listo</span>
                                <?php else: ?>
                                    <span class="badge-status badge-pending"><?= number_format($completadoFam, 0) ?>%</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    // Auto-recargar el panel cada 60 segundos para mantenerlo actualizado en pantallas de supervisión
    setTimeout(() => {
        window.location.reload();
    }, 60000);
</script>

</body>
</html>