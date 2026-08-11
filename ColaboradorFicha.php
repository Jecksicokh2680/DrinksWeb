<?php
// 1. Iniciar sesión y requerir tu conexión
session_start();
require_once 'Conexion.php'; 

// 2. Validar que la variable de conexión exista y esté activa
if (!isset($mysqliWeb) || $mysqliWeb->connect_error) {
    die("Error de conexión a la base de datos: " . ($mysqliWeb->connect_error ?? 'La variable $mysqliWeb no está definida en Conexion.php'));
}

$mysqli = $mysqliWeb; 

// 3. Verificar sesión basada en CedulaNit
if (!isset($_SESSION['CedulaNit'])) {
    die("Acceso denegado: Por favor inicie sesión primero.");
}

// 4. Obtener la cédula de la sesión de forma segura
$cedula_sesion = $mysqli->real_escape_string($_SESSION['CedulaNit']);

// 5. Consulta utilizando CedulaNit en la tabla 'colaborador'
$query = "SELECT * FROM colaborador WHERE CedulaNit = '$cedula_sesion' LIMIT 1";
$resultado = $mysqli->query($query);

if (!$resultado) {
    die("Error en la consulta SQL: " . $mysqli->error);
}

$c = $resultado->fetch_assoc();

if (!$c) {
    die("No se encontró información del colaborador en el sistema para la cédula: " . htmlspecialchars($cedula_sesion));
}

$colaborador_id_real = intval($c['id']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha de Empleado - <?= htmlspecialchars($c['CedulaNit'] ?? 'Usuario') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body * { visibility: hidden; }
            .ficha-container, .ficha-container * { visibility: visible; }
            .ficha-container { position: absolute; left: 0; top: 0; width: 100%; }
            .btn-print { display: none; }
        }
        .table-primary { background-color: #0d6efd !important; color: white !important; }
    </style>
</head>
<body class="bg-light">

<div class="container my-5 ficha-container" style="max-width: 900px;">
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
                    <td class="fw-bold bg-light" style="width: 20%;">Cédula de Ciudadanía:</td>
                    <td style="width: 35%;"><?= htmlspecialchars($c['CedulaNit'] ?? '') ?></td>
                    <td colspan="2" rowspan="4" class="text-center align-middle bg-light" style="width: 45%;">
                        <?php if (!empty($c['url_foto']) && file_exists($c['url_foto'])): ?>
                            <img src="<?= htmlspecialchars($c['url_foto']) ?>" alt="Foto" style="width: 110px; height: 130px; object-fit: cover;" class="border shadow-sm">
                        <?php else: ?>
                            <div class="border p-4 text-muted bg-white d-inline-block">
                                <small>[ ESPACIO FOTO ]<br>3 x 4 cm</small>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Cargo:</td>
                    <td><?= htmlspecialchars($c['cargo'] ?? 'No asignado') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Dirección:</td>
                    <td><?= htmlspecialchars($c['direccion'] ?? 'No registrada') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Empresa (NIT):</td>
                    <td><?= htmlspecialchars($c['NitEmpresa'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Fecha de Ingreso:</td>
                    <td><?= htmlspecialchars($c['fecha_ingreso'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light" style="width: 20%;">Salario:</td>
                    <td>$<?= number_format($c['salario'] ?? 0, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Fecha de Nacimiento:</td>
                    <td><?= htmlspecialchars($c['fecha_nacimiento'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">Estado Civil:</td>
                    <td><?= htmlspecialchars($c['estado_civil'] ?? 'SOLTERO') ?></td>
                </tr>

                <!-- SECCIÓN 2: PAGO Y DATOS BANCARIOS -->
                <tr class="table-primary">
                    <td colspan="4" class="fw-bold text-dark py-1">2. PAGO Y DATOS BANCARIOS</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Número de Cuenta:</td>
                    <td><?= htmlspecialchars($c['numero_cuenta'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">Llave Payment:</td>
                    <td><?= htmlspecialchars($c['llave_payment'] ?? 'N/A') ?></td>
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
                                    <th>Contacto Emergencia</th>
                                    <th>Parentesco</th>
                                    <th>Celular 1</th>
                                    <th>Celular 2</th>
                                    <th>Principal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $resEm = $mysqli->query("SELECT * FROM colaborador_emergencia WHERE colaborador_id = $colaborador_id_real");
                                if ($resEm && $resEm->num_rows > 0):
                                    while ($em = $resEm->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="text-start"><?= htmlspecialchars($em['nombre_contacto']) ?></td>
                                    <td><?= htmlspecialchars($em['parentesco'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($em['celular_1']) ?></td>
                                    <td><?= htmlspecialchars($em['celular_2'] ?? '-') ?></td>
                                    <td><?= ($em['es_principal'] ?? 0) ? 'Sí' : 'No' ?></td>
                                </tr>
                                <?php endwhile; 
                                else: ?>
                                <tr>
                                    <td colspan="5" class="text-muted py-1">Sin contactos de emergencia registrados.</td>
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
                    <td><?= htmlspecialchars($c['eps'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">ARL:</td>
                    <td><?= htmlspecialchars($c['arl'] ?? 'N/A') ?> (Niv. <?= htmlspecialchars($c['nivel_arl'] ?? '1') ?>)</td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Grupo Sanguíneo / RH:</td>
                    <td><?= htmlspecialchars($c['grupo_sanguineo'] ?? 'N/A') ?></td>
                    <td class="fw-bold bg-light">Pensiones / Cesantías:</td>
                    <td><?= htmlspecialchars($c['fondo_pensiones'] ?? 'N/A') ?> / <?= htmlspecialchars($c['fondo_cesantias'] ?? 'N/A') ?></td>
                </tr>

            </table>
        </div>
    </div>
    
    <!-- Botón de impresión -->
    <div class="text-end mt-3 btn-print">
        <button onclick="window.print();" class="btn btn-dark fw-bold">🖨️ Imprimir Ficha en PDF</button>
    </div>
</div>

</body>
</html>