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

// Verificar si el usuario actual tiene el permiso 9999
$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 

// 4. Determinar qué cédula se va a consultar
$cedula_a_consultar = $UsuarioSesion; 

if (isset($_GET['cedula']) && !empty($_GET['cedula'])) {
    if ($permiso9999 === 'SI') {
        $cedula_a_consultar = $_GET['cedula'];
    } else {
        die("Acceso denegado: No cuentas con la autorización 9999 para ver perfiles de otros usuarios.");
    }
}

// 5. Obtener la cédula de forma segura
$cedula_sesion = $mysqli->real_escape_string($cedula_a_consultar);

// 6. Consulta con JOIN a la tabla 'terceros' para obtener el Nombre y Apellidos
$query = "SELECT c.*, t.Nombre AS NombreColaborador 
          FROM colaborador c 
          INNER JOIN terceros t ON c.CedulaNit = t.CedulaNit 
          WHERE c.CedulaNit = '$cedula_sesion' LIMIT 1";
          
$resultado = $mysqli->query($query);

if (!$resultado) {
    die("Error en la consulta SQL: " . $mysqli->error);
}

$c = $resultado->fetch_assoc();

if (!$c) {
    die("No se encontró información del colaborador en el sistema para la cédula: " . htmlspecialchars($cedula_sesion));
}

// Función auxiliar para convertir a mayúsculas de forma segura (manejando UTF-8)
function toUpper($texto) {
    return mb_strtoupper($texto ?? '', 'UTF-8');
}

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
    </style>
</head>
<body class="bg-light">

<div class="container my-5 ficha-container" style="max-width: 900px;">

    <!-- Barra superior para administradores con permiso 9999 -->
    <?php if ($permiso9999 === 'SI'): ?>
    <div class="card mb-3 border-secondary admin-controls">
        <div class="card-body py-2 bg-white d-flex justify-content-between align-items-center">
            <span class="text-muted small">⚙️ <strong>MODO SUPERVISOR:</strong> PUEDES CONSULTAR CUALQUIER FICHA.</span>
            <form method="GET" action="" class="d-flex gap-2 mb-0">
                <input type="text" name="cedula" class="form-control form-control-sm" placeholder="INGRESE CÉDULA" value="<?= htmlspecialchars($cedula_a_consultar) ?>" required>
                <button type="submit" class="btn btn-sm btn-primary">VER FICHA</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-dark shadow-sm">
        
        <!-- Encabezado -->
        <div class="card-header bg-primary text-white text-center py-2">
            <h4 class="mb-0 fw-bold">FICHA COMPLETA DE EMPLEADO</h4>
            <small class="text-uppercase" style="font-size: 0.75rem;">SISTEMA DE GESTIÓN SEGURIDAD Y SALUD EN EL TRABAJO DE RECURSOS HUMANOS</small>
        </div>

        <div class="card-body p-0">
            <table class="table table-bordered mb-0 align-middle" style="font-size: 0.85rem; border-color: #000;">
                
                <!-- SECCIÓN 1: DATOS GENERALES Y LABORALES -->
                <tr class="table-primary">
                    <td colspan="4" class="fw-bold text-dark py-1">1. DATOS GENERALES Y LABORALES</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light" style="width: 20%;">CÉDULA DE CIUDADANÍA:</td>
                    <td style="width: 35%;"><?= toUpper($c['CedulaNit'] ?? '') ?></td>
                    <td colspan="2" rowspan="5" class="text-center align-middle bg-light" style="width: 45%;">
                        <?php if (!empty($c['url_foto']) && file_exists($c['url_foto'])): ?>
                            <img src="<?= htmlspecialchars($c['url_foto']) ?>" alt="Foto" style="width: 110px; height: 130px; object-fit: cover;" class="border shadow-sm">
                        <?php else: ?>
                            <div class="border p-4 text-muted bg-white d-inline-block">
                                <small>[ ESPACIO FOTO ]<br>3 x 4 CM</small>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">NOMBRES Y APELLIDOS:</td>
                    <td><?= toUpper($c['NombreColaborador'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">CARGO:</td>
                    <td><?= toUpper($c['cargo'] ?? 'NO ASIGNADO') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">DIRECCIÓN:</td>
                    <td><?= toUpper($c['direccion'] ?? 'NO REGISTRADA') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">EMPRESA (NIT):</td>
                    <td><?= toUpper($c['NitEmpresa'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">FECHA DE INGRESO:</td>
                    <td><?= toUpper($c['fecha_ingreso'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light" style="width: 20%;">SALARIO:</td>
                    <td>$<?= number_format($c['salario'] ?? 0, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">FECHA DE NACIMIENTO:</td>
                    <td><?= toUpper($c['fecha_nacimiento'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">ESTADO CIVIL:</td>
                    <td><?= toUpper($c['estado_civil'] ?? 'SOLTERO') ?></td>
                </tr>

                <!-- SECCIÓN 2: PAGO Y DATOS BANCARIOS -->
                <tr class="table-primary">
                    <td colspan="4" class="fw-bold text-dark py-1">2. PAGO Y DATOS BANCARIOS</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">NÚMERO DE CUENTA:</td>
                    <td><?= toUpper($c['numero_cuenta'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">LLAVE PAYMENT:</td>
                    <td><?= toUpper($c['llave_payment'] ?? 'N/A') ?></td>
                </tr>

                <!-- SECCIÓN 3: CONTACTO DE EMERGENCIA Y GRUPO FAMILIAR -->
                <tr class="table-primary">
                    <td colspan="4" class="fw-bold text-dark py-1">3. CONTACTO DE EMERGENCIA Y GRUPO FAMILIAR</td>
                </tr>
                <tr>
                    <td colspan="4" class="p-0">
                        <table class="table table-sm table-bordered mb-0 text-center">
                            <thead class="table-light">
                                <tr>
                                    <th>CONTACTO EMERGENCIA</th>
                                    <th>PARENTESCO</th>
                                    <th>CELULAR 1</th>
                                    <th>CELULAR 2</th>
                                    <th>PRINCIPAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $resEm = $mysqli->query("SELECT * FROM colaborador_emergencia WHERE colaborador_id = $colaborador_id_real");
                                if ($resEm && $resEm->num_rows > 0):
                                    while ($em = $resEm->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="text-start"><?= toUpper($em['nombre_contacto']) ?></td>
                                    <td><?= toUpper($em['parentesco'] ?? '-') ?></td>
                                    <td><?= toUpper($em['celular_1']) ?></td>
                                    <td><?= toUpper($em['celular_2'] ?? '-') ?></td>
                                    <td><?= (intval($em['es_principal'] ?? 0) === 1) ? 'SÍ' : 'NO' ?></td>
                                </tr>
                                <?php endwhile; 
                                else: ?>
                                <tr>
                                    <td colspan="5" class="text-muted py-1">SIN CONTACTOS DE EMERGENCIA REGISTRADOS.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <!-- SECCIÓN 4: SEGURIDAD SOCIAL, SALUD Y SST -->
                <tr class="table-primary">
                    <td colspan="4" class="fw-bold text-dark py-1">4. SEGURIDAD SOCIAL, SALUD Y SALUD OCUPACIONAL (SST)</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">EPS:</td>
                    <td><?= toUpper($c['eps'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">ARL:</td>
                    <td><?= toUpper($c['arl'] ?? 'N/A') ?> (NIV. <?= toUpper($c['nivel_arl'] ?? '1') ?>)</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">GRUPO SANGUÍNEO / RH:</td>
                    <td><?= toUpper($c['grupo_sanguineo'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">PENSIONES / CESANTÍAS:</td>
                    <td><?= toUpper($c['fondo_pensiones'] ?? 'N/A') ?> / <?= toUpper($c['fondo_cesantias'] ?? 'N/A') ?></td>
                </tr>

            </table>
        </div>
    </div>
    
    <!-- Botón de impresión -->
    <div class="text-end mt-3 btn-print">
        <button onclick="window.print();" class="btn btn-dark fw-bold">🖨️ IMPRIMIR FICHA EN PDF</button>
    </div>
</div>

</body>
</html>