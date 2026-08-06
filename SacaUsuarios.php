<?php
require('Conexion.php'); // $mysqliWeb / $mysqli

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

date_default_timezone_set('America/Bogota');

/* =====================================================
    1. PROCESAR CIERRE DE SESIÓN FORZADO
===================================================== */
$mensajeAlerta = '';
if (isset($_POST['accion']) && $_POST['accion'] === 'cerrar_sesion') {
    $idSesionCerrar = $_POST['id_sesion'] ?? '';    
    if (!empty($idSesionCerrar)) {
        $stmtDel = $mysqli->prepare("DELETE FROM sesiones_activas WHERE id_sesion = ?");
        if ($stmtDel) {
            $stmtDel->bind_param("s", $idSesionCerrar);
            if ($stmtDel->execute()) {
                $mensajeAlerta = "Sesión cerrada correctamente.";
            } else {
                $mensajeAlerta = "Error al intentar cerrar la sesión.";
            }
            $stmtDel->close();
        }
    }
}

/* =====================================================
    2. OBTENER LISTA DE DÍAS DISPONIBLES
===================================================== */
$sqlDias = "SELECT DISTINCT DATE(fecha_ingreso) as dia FROM sesiones_activas ORDER BY dia DESC";
$resultDias = $mysqli->query($sqlDias);
$diasDisponibles = [];
if ($resultDias) {
    while ($rowDia = $resultDias->fetch_assoc()) {
        $diasDisponibles[] = $rowDia['dia'];
    }
}

// Definir el día seleccionado (por defecto el más reciente o el que envíe el usuario)
$diaSeleccionado = $_GET['dia'] ?? ($diasDisponibles[0] ?? date('Y-m-d'));

/* =====================================================
    3. OBTENER USUARIOS DEL DÍA SELECCIONADO
===================================================== */
$sqlSesiones = "
    SELECT 
        sa.id_sesion,
        sa.CedulaNit,
        t.Nombre,
        t.NombreCom,
        sa.NitEmpresa,
        sa.NroSucursal,
        sa.fecha_ingreso
    FROM sesiones_activas sa
    INNER JOIN terceros t ON sa.CedulaNit = t.CedulaNit
    WHERE DATE(sa.fecha_ingreso) = ?
    ORDER BY sa.NitEmpresa ASC, sa.fecha_ingreso DESC
";

$stmtSesiones = $mysqli->prepare($sqlSesiones);
$usuariosActivos = [];

if ($stmtSesiones) {
    $stmtSesiones->bind_param("s", $diaSeleccionado);
    $stmtSesiones->execute();
    $resultSesiones = $stmtSesiones->get_result();

    while ($row = $resultSesiones->fetch_assoc()) {
        $nitEmpresa = $row['NitEmpresa'];
        
        // Determinar la sede según el NIT
        if ($nitEmpresa === '901724534-7') {
            $nombreSede = "DRINKS (AWS)";
        } else {
            $nombreSede = "CENTRAL";
        }

        $usuariosActivos[] = [
            'id_sesion'     => $row['id_sesion'],
            'cedula'        => $row['CedulaNit'],
            'nombre'        => $row['NombreCom'] ?: $row['Nombre'] ?: 'Sin Nombre',
            'sede'          => $nombreSede,
            'sucursal'      => $row['NroSucursal'],
            'fecha_ingreso' => $row['fecha_ingreso']
        ];
    }
    $stmtSesiones->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Sesiones Activas por Día</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; margin: 20px; color: #333; }
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); max-width: 1000px; margin: auto; }
        .header-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h2 { margin: 0; color: #263238; }
        .alert { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .pagination-days { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .pagination-days span { font-weight: bold; color: #455a64; font-size: 13px; margin-right: 5px; }
        .btn-day { background: #eceff1; color: #455a64; padding: 6px 12px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: bold; border: 1px solid #cfd8dc; }
        .btn-day.active { background: #0288d1; color: white; border-color: #0277bd; }
        .btn-day:hover { background: #cfd8dc; }
        .btn-day.active:hover { background: #0277bd; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
        th { background: #eceff1; text-transform: uppercase; font-size: 11px; color: #455a64; font-weight: bold; }
        .badge-sede { background: #0288d1; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .btn-danger { background: #c62828; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-refresh { background: #0288d1; color: white; border: none; padding: 8px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-refresh:hover { background: #0277bd; }
        .text-center { text-align: center; }
        .text-muted { color: #777; }
    </style>
</head>
<body>

<div class="card">
    <div class="header-container">
        <h2>Control de Sesiones Activas</h2>
        <a href="?dia=<?= htmlspecialchars($diaSeleccionado) ?>" class="btn-refresh">🔄 Refrescar</a>
    </div>
    
    <?php if (!empty($mensajeAlerta)): ?>
        <div class="alert"><?= htmlspecialchars($mensajeAlerta) ?></div>
    <?php endif; ?>

    <!-- Paginación / Selector por Días -->
    <?php if (!empty($diasDisponibles)): ?>
        <div class="pagination-days">
            <span>Días:</span>
            <?php foreach ($diasDisponibles as $dia): ?>
                <a href="?dia=<?= $dia ?>" class="btn-day <?= ($dia === $diaSeleccionado) ? 'active' : '' ?>">
                    <?= $dia ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($usuariosActivos)): ?>
        <p class="text-center text-muted" style="padding: 30px 0;">No hay usuarios con sesiones activas registradas para el día <?= htmlspecialchars($diaSeleccionado) ?>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Sede / Sucursal</th>
                    <th>Cédula / NIT</th>
                    <th>Nombre de Usuario</th>
                    <th>Ingreso</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuariosActivos as $user): ?>
                    <tr>
                        <td>
                            <span class="badge-sede"><?= htmlspecialchars($user['sede']) ?></span>
                            <div style="font-size: 11px; color: #666; margin-top: 3px;">Sucursal: <?= htmlspecialchars($user['sucursal']) ?></div>
                        </td>
                        <td><code><?= htmlspecialchars($user['cedula']) ?></code></td>
                        <td><strong><?= htmlspecialchars($user['nombre']) ?></strong></td>
                        <td><span style="font-size: 12px; color: #555;"><?= htmlspecialchars($user['fecha_ingreso']) ?></span></td>
                        <td class="text-center">
                            <form method="POST" onsubmit="return confirm('¿Está seguro de cerrar la sesión de este usuario?');" style="margin:0;">
                                <input type="hidden" name="accion" value="cerrar_sesion">
                                <input type="hidden" name="id_sesion" value="<?= htmlspecialchars($user['id_sesion']) ?>">
                                <button type="submit" class="btn-danger">🔒 Cerrar Sesión</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>