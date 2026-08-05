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
    $idSesionCerrar = limpiar($_POST['id_sesion'] ?? '');    
    if (!empty($idSesionCerrar)) {
        // Asumiendo que manejas una tabla de sesiones activas con un ID o identificador único
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
    2. OBTENER USUARIOS CON SESIÓN ACTIVA
===================================================== */
// Consulta ajustada a una tabla de control de sesiones activas en la BD
$sqlSesiones = "
    SA.id_sesion,
    sa.CedulaNit,
    t.Nombre,
    t.NombreCom,
    sa.NitEmpresa,
    sa.NroSucursal,
    sa.fecha_ingreso
FROM sesiones_activas sa
INNER JOIN terceros t ON sa.CedulaNit = t.CedulaNit
ORDER BY sa.fecha_ingreso DESC
";

$resultSesiones = $mysqli->query($sqlSesiones);
$usuariosActivos = [];

if ($resultSesiones) {
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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Sesiones Activas</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; margin: 20px; color: #333; }
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); max-width: 1000px; margin: auto; }
        h2 { margin-top: 0; color: #263238; }
        .alert { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
        th { background: #eceff1; text-transform: uppercase; font-size: 11px; color: #455a64; font-weight: bold; }
        .badge-sede { background: #0288d1; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .btn-danger { background: #c62828; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .btn-danger:hover { background: #b71c1c; }
        .text-center { text-align: center; }
        .text-muted { color: #777; }
    </style>
</head>
<body>

<div class="card">
    <h2>Control de Sesiones Activas</h2>
    
    <?php if (!empty($mensajeAlerta)): ?>
        <div class="alert"><?= htmlspecialchars($mensajeAlerta) ?></div>
    <?php endif; ?>

    <?php if (empty($usuariosActivos)): ?>
        <p class="text-center text-muted" style="padding: 30px 0;">No hay usuarios con sesiones activas registradas en este momento.</p>
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