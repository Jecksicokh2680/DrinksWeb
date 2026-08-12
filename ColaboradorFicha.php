<?php
// 1. Iniciar sesión y requerir tu conexión
session_start();
require_once 'Conexion.php'; 

// 2. Validar que la variable de conexión exista y esté activa
if (!isset($mysqliWeb) || $mysqliWeb->connect_error) {
    die("Error de conexión a la base de datos: " . ($mysqliWeb->connect_error ?? 'La variable $mysqliWeb no está definida en Conexion.php'));
}

$mysqli = $mysqliWeb; 

// 3. Verificar sesión base
if (!isset($_SESSION['CedulaNit'])) {
    die("Acceso denegado: Por favor inicie sesión primero.");
}

$UsuarioSesion = $_SESSION['CedulaNit'];

// --- FUNCIÓN DE AUTORIZACIÓN ---
function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 

// 4. Determinar qué cédula se va a consultar
$cedula_a_consultar = $UsuarioSesion; 
if (isset($_GET['cedula']) && !empty($_GET['cedula'])) {
    if ($permiso9999 === 'SI') {
        $cedula_a_consultar = $_GET['cedula'];
    } else {
        die("Acceso denegado: No cuentas con la autorización 9999.");
    }
}

$cedula_sesion = $mysqli->real_escape_string($cedula_a_consultar);

// 6. Consulta con JOIN a 'terceros' y 'empresa'
$query = "SELECT c.*, t.Nombre AS NombreColaborador, e.RazonSocial, e.NombreComercial 
          FROM colaborador c 
          INNER JOIN terceros t ON c.CedulaNit = t.CedulaNit 
          LEFT JOIN empresa e ON c.NitEmpresa = e.Nit 
          WHERE c.CedulaNit = '$cedula_sesion' LIMIT 1";
          
$resultado = $mysqli->query($query);
if (!$resultado) die("Error en la consulta SQL: " . $mysqli->error);
$c = $resultado->fetch_assoc();
if (!$c) die("No se encontró información del colaborador.");

function toUpper($texto) { return mb_strtoupper($texto ?? '', 'UTF-8'); }
$colaborador_id_real = intval($c['id']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>FICHA DE EMPLEADO - <?= toUpper($c['NombreColaborador'] ?? 'USUARIO') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body * { visibility: hidden; }
            .ficha-container, .ficha-container * { visibility: visible; }
            .ficha-container { position: absolute; left: 0; top: 0; width: 100%; }
            .btn-print, .admin-controls { display: none; }
        }
        .table-primary { background-color: #0d6efd !important; color: white !important; }
        .label-cell { background-color: #f8f9fa; font-weight: bold; width: 20%; }
    </style>
</head>
<body class="bg-light">

<div class="container my-5 ficha-container" style="max-width: 900px;">

    <?php if ($permiso9999 === 'SI'): ?>
    <div class="card mb-3 border-secondary admin-controls">
        <div class="card-body py-2 bg-white d-flex justify-content-between align-items-center">
            <span class="text-muted small">⚙️ <strong>MODO SUPERVISOR</strong></span>
            <form method="GET" action="" class="d-flex gap-2 mb-0">
                <input type="text" name="cedula" class="form-control form-control-sm" placeholder="CÉDULA" value="<?= htmlspecialchars($cedula_a_consultar) ?>" required>
                <button type="submit" class="btn btn-sm btn-primary">VER</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-dark shadow-sm">
        <div class="card-header bg-primary text-white text-center py-2">
            <h4 class="mb-0 fw-bold">FICHA COMPLETA DE EMPLEADO</h4>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered mb-0 align-middle" style="font-size: 0.85rem; border-color: #000;">
                
                <!-- SECCIÓN 1 -->
                <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">1. DATOS GENERALES Y LABORALES</td></tr>
                <tr>
                    <td class="label-cell">CÉDULA:</td>
                    <td><?= toUpper($c['CedulaNit'] ?? '') ?></td>
                    <td rowspan="6" colspan="2" class="text-center bg-white align-middle">
                        <?php if (!empty($c['url_foto']) && file_exists($c['url_foto'])): ?>
                            <img src="<?= htmlspecialchars($c['url_foto']) ?>" style="width: 150px; height: 180px; object-fit: cover;" class="border shadow-sm">
                        <?php else: ?>
                            <div class="border p-4 text-muted">SIN FOTO</div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="label-cell">NOMBRES:</td><td><?= toUpper($c['NombreColaborador'] ?? '') ?></td></tr>
                <tr><td class="label-cell">CARGO:</td><td><?= toUpper($c['cargo'] ?? 'N/A') ?></td></tr>
                <tr><td class="label-cell">DIRECCIÓN:</td><td><?= toUpper($c['direccion'] ?? 'N/A') ?></td></tr>
                <tr>
                    <td class="label-cell">EMPRESA:</td>
                    <td>
                        <?= toUpper($c['NitEmpresa'] ?? '') ?>
                        <?php if (!empty($c['RazonSocial'])): ?>
                            <br><strong><?= toUpper($c['RazonSocial']) ?></strong>
                            <?php if (!empty($c['NombreComercial'])): ?>
                                <br><small class="text-muted"><?= toUpper($c['NombreComercial']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="label-cell">INGRESO:</td><td><?= toUpper($c['fecha_ingreso'] ?? 'N/A') ?></td></tr>
                <tr>
                    <td class="label-cell">NACIMIENTO:</td><td><?= toUpper($c['fecha_nacimiento'] ?? 'N/A') ?></td>
                    <td class="label-cell">SALARIO:</td><td>$<?= number_format($c['salario'] ?? 0, 0, ',', '.') ?></td>
                </tr>

                <!-- SECCIÓN 2 -->
                <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">2. PAGO Y DATOS BANCARIOS</td></tr>
                <tr>
                    <td class="label-cell">CUENTA:</td><td><?= toUpper($c['numero_cuenta'] ?? 'N/A') ?></td>
                    <td class="label-cell">LLAVE PAYMENT:</td><td><?= toUpper($c['llave_payment'] ?? 'N/A') ?></td>
                </tr>

                <!-- SECCIÓN 3 -->
                <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">3. CONTACTOS EMERGENCIA</td></tr>
                <tr>
                    <td colspan="4" class="p-0">
                        <table class="table table-sm table-bordered mb-0 text-center">
                            <thead class="table-light"><tr><th>NOMBRE</th><th>PARENTESCO</th><th>CELULAR 1</th><th>CELULAR 2</th><th>PRINCIPAL</th></tr></thead>
                            <tbody>
                                <?php
                                $resEm = $mysqli->query("SELECT * FROM colaborador_emergencia WHERE colaborador_id = $colaborador_id_real");
                                if ($resEm && $resEm->num_rows > 0):
                                    while ($em = $resEm->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= toUpper($em['nombre_contacto']) ?></td>
                                        <td><?= toUpper($em['parentesco'] ?? '-') ?></td>
                                        <td><?= toUpper($em['celular_1']) ?></td>
                                        <td><?= toUpper($em['celular_2'] ?? '-') ?></td>
                                        <td><?= (intval($em['es_principal'] ?? 0) === 1) ? 'SÍ' : 'NO' ?></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="5">SIN CONTACTOS REGISTRADOS.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <!-- SECCIÓN 4 -->
                <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">4. SEGURIDAD SOCIAL Y SST</td></tr>
                <tr>
                    <td class="label-cell">EPS:</td><td><?= toUpper($c['eps'] ?? 'N/A') ?></td>
                    <td class="label-cell">ARL:</td><td><?= toUpper($c['arl'] ?? 'N/A') ?> (NIV. <?= toUpper($c['nivel_arl'] ?? '1') ?>)</td>
                </tr>
                <tr>
                    <td class="label-cell">RH:</td><td><?= toUpper($c['grupo_sanguineo'] ?? 'N/A') ?></td>
                    <td class="label-cell">PENSIONES:</td><td><?= toUpper($c['fondo_pensiones'] ?? 'N/A') ?> / <?= toUpper($c['fondo_cesantias'] ?? 'N/A') ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="text-end mt-3 btn-print">
        <button onclick="window.print();" class="btn btn-dark fw-bold">🖨️ IMPRIMIR FICHA</button>
    </div>
</div>
</body>
</html>