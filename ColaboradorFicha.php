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
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

// Consultamos ambos permisos de supervisor
$permiso9999 = Autorizacion($UsuarioSesion, '9999');
$permiso6666 = Autorizacion($UsuarioSesion, '6666');

// Determinamos si es supervisor con cualquiera de los dos permisos
$es_supervisor = ($permiso9999 === 'SI' || $permiso6666 === 'SI');

function toUpper($texto) {
    return mb_strtoupper(trim((string)($texto ?? '')), 'UTF-8');
}

// 4. Determinar qué cédula se va a consultar
$cedula_a_consultar = $UsuarioSesion;
if (isset($_GET['cedula']) && !empty($_GET['cedula'])) {
    if ($es_supervisor) {
        $cedula_a_consultar = $_GET['cedula'];
    } else {
        die("Acceso denegado: No cuentas con la autorización requerida (9999 o 6666).");
    }
}

$cedula_sesion = $mysqli->real_escape_string($cedula_a_consultar);

// 5. Consulta con JOIN a 'terceros' y 'empresa'
$query = "SELECT c.*, t.Nombre AS NombreColaborador, e.RazonSocial, e.NombreComercial
          FROM colaborador c
          INNER JOIN terceros t ON c.CedulaNit = t.CedulaNit
          LEFT JOIN empresa e ON c.NitEmpresa = e.Nit
          WHERE c.CedulaNit = '$cedula_sesion' LIMIT 1";

$resultado = $mysqli->query($query);
if (!$resultado) die("Error en la consulta SQL: " . $mysqli->error);

$c = $resultado->fetch_assoc();
$colaborador_encontrado = !empty($c);

if (!$colaborador_encontrado) {
    $c = [
        'id' => 0,
        'CedulaNit' => $cedula_sesion,
        'NombreColaborador' => '',
        'cargo' => '',
        'direccion' => '',
        'NitEmpresa' => '',
        'RazonSocial' => '',
        'NombreComercial' => '',
        'fecha_ingreso' => '',
        'fecha_nacimiento' => '',
        'salario' => 0,
        'numero_cuenta' => '',
        'llave_payment' => '',
        'eps' => '',
        'arl' => '',
        'nivel_arl' => '1',
        'grupo_sanguineo' => '',
        'fondo_pensiones' => '',
        'fondo_cesantias' => '',
        'correo' => '',
        'tipo_contrato' => '',
        'jornada_laboral' => '',
        'genero' => '',
        'estado_civil' => '',
        'nivel_educativo' => '',
        'estrato' => '',
        'url_foto' => ''
    ];
}

$colaborador_id_real = $colaborador_encontrado ? intval($c['id']) : 0;
$emp_nombre = toUpper($c['NombreColaborador'] ?? 'COLABORADOR');

// Manejo seguro de la foto
$ruta_foto_bd = trim((string)($c['url_foto'] ?? ''));
$ruta_limpia = ltrim($ruta_foto_bd, './\\');
$ruta_fisica = __DIR__ . '/' . $ruta_limpia;
$tiene_foto = (!empty($ruta_limpia) && file_exists($ruta_fisica));
$url_publica = $tiene_foto ? ('/' . $ruta_limpia) : '';

// Cargar lista de empresas para el selector del modal
$empresas_modal = [];
$resEmpModal = $mysqli->query("SELECT Nit, RazonSocial, NombreComercial FROM empresa ORDER BY NombreComercial ASC, RazonSocial ASC");
if ($resEmpModal) {
    while ($emRow = $resEmpModal->fetch_assoc()) {
        $empresas_modal[] = $emRow;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FICHA DE EMPLEADO - <?= htmlspecialchars($emp_nombre !== '' ? $emp_nombre : 'USUARIO') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-primary { background-color: #0d6efd !important; color: white !important; }
        .label-cell { background-color: #f8f9fa; font-weight: bold; width: 20%; }

        .formato-blanco-hoja {
            background: #ffffff;
            border: 1px solid #222;
            padding: 20px;
            color: #111;
            font-size: 0.82rem;
        }
        .formato-blanco-hoja .sec-title {
            background: #e9ecef;
            border: 1px solid #222;
            font-weight: 700;
            padding: 4px 8px;
            font-size: 0.78rem;
            text-transform: uppercase;
            margin-top: 10px;
        }
        .formato-blanco-hoja table {
            width: 100%;
            border-collapse: collapse;
        }
        .formato-blanco-hoja th,
        .formato-blanco-hoja td {
            border: 1px solid #222;
            padding: 6px 8px;
            vertical-align: middle;
        }
        .linea-firma {
            border-top: 1px solid #222;
            width: 240px;
            margin: 36px auto 4px auto;
            padding-top: 4px;
            text-align: center;
            font-size: 0.78rem;
        }

        @media (max-width: 575.98px) {
            .label-cell {
                width: 38%;
                font-size: 0.75rem;
            }
        }

        /* Impresión cuando se activa el formato en blanco */
        body.imprimiendo-formato * {
            visibility: hidden !important;
        }
        body.imprimiendo-formato #formatoBlancoPrintArea,
        body.imprimiendo-formato #formatoBlancoPrintArea * {
            visibility: visible !important;
        }
        body.imprimiendo-formato #formatoBlancoPrintArea {
            position: fixed !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 12px !important;
            background: #fff !important;
            z-index: 99999 !important;
        }
        body.imprimiendo-formato .no-print-formato {
            display: none !important;
        }

        /* Impresión normal de la ficha */
        @media print {
            body:not(.imprimiendo-formato) * { visibility: hidden; }
            body:not(.imprimiendo-formato) .ficha-container,
            body:not(.imprimiendo-formato) .ficha-container * { visibility: visible; }
            body:not(.imprimiendo-formato) .ficha-container { position: absolute; left: 0; top: 0; width: 100%; }
            .btn-print, .admin-controls, .topbar-acciones, .modal { display: none !important; }
        }
    </style>
</head>
<body class="bg-light">

<div class="container my-3 my-md-5 ficha-container" style="max-width: 900px;">

    <!-- BARRA SUPERIOR VISIBLE CON BOTÓN DE FORMATO EN BLANCO -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 bg-white p-2 px-3 rounded border shadow-sm topbar-acciones">
        <span class="fw-bold text-dark small">📋 GESTIÓN DE FICHA DE COLABORADOR</span>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#modalFormatoBlanco">
                📄 GENERAR FORMATO EN BLANCO
            </button>
            <?php if ($es_supervisor): ?>
            <button type="button" class="btn btn-sm btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#modalEditarComplementos">
                ✏️ EDITAR COMPLEMENTOS
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($es_supervisor): ?>
    <div class="card mb-3 border-secondary admin-controls">
        <div class="card-body py-2 bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span class="text-muted small">⚙️ <strong>MODO SUPERVISOR</strong></span>
            <form method="GET" action="" class="d-flex gap-2 mb-0 align-items-center flex-grow-1 justify-content-end" style="max-width: 520px;">
                <select name="cedula" class="form-select form-select-sm" required>
                    <option value="">-- SELECCIONE UN COLABORADOR --</option>
                    <?php
                    $sql_lista = "SELECT c.CedulaNit, t.Nombre
                                  FROM colaborador c
                                  INNER JOIN terceros t ON c.CedulaNit = t.CedulaNit
                                  ORDER BY t.Nombre ASC";
                    $res_lista = $mysqli->query($sql_lista);
                    if ($res_lista && $res_lista->num_rows > 0) {
                        while ($collab = $res_lista->fetch_assoc()) {
                            $selected = ($collab['CedulaNit'] === $cedula_a_consultar) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($collab['CedulaNit']) . '" ' . $selected . '>'
                                 . htmlspecialchars(mb_strtoupper($collab['Nombre'], 'UTF-8')) . ' (' . htmlspecialchars($collab['CedulaNit']) . ')'
                                 . '</option>';
                        }
                    }
                    ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">VER</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-dark shadow-sm">
        <div class="card-header bg-primary text-white text-center py-2">
            <h4 class="mb-0 fw-bold fs-6 fs-md-4">FICHA COMPLETA DE EMPLEADO</h4>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle" style="font-size: 0.85rem; border-color: #000;">

                    <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">1. DATOS GENERALES Y LABORALES</td></tr>
                    <tr>
                        <td class="label-cell">CÉDULA:</td>
                        <td><?= htmlspecialchars(toUpper($c['CedulaNit'] ?? '')) ?></td>
                        <td rowspan="6" colspan="2" class="text-center bg-white align-middle">
                            <?php if ($tiene_foto): ?>
                                <img src="<?= htmlspecialchars($url_publica) ?>" style="width: 160px; height: 200px; object-fit: cover;" class="border shadow-sm">
                            <?php else: ?>
                                <div class="border p-4 text-muted">SIN FOTO</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td class="label-cell">NOMBRES:</td><td><?= htmlspecialchars($emp_nombre) ?></td></tr>
                    <tr><td class="label-cell">CARGO:</td><td><?= htmlspecialchars(toUpper($c['cargo'] ?? 'N/A')) ?></td></tr>
                    <tr><td class="label-cell">DIRECCIÓN:</td><td><?= htmlspecialchars(toUpper($c['direccion'] ?? 'N/A')) ?></td></tr>
                    <tr>
                        <td class="label-cell">EMPRESA:</td>
                        <td>
                            <?= htmlspecialchars(toUpper($c['NitEmpresa'] ?? '')) ?>
                            <?php if (!empty($c['RazonSocial'])): ?>
                                <br><strong><?= htmlspecialchars(toUpper($c['RazonSocial'])) ?></strong>
                                <?php if (!empty($c['NombreComercial'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars(toUpper($c['NombreComercial'])) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td class="label-cell">INGRESO:</td><td><?= htmlspecialchars(toUpper($c['fecha_ingreso'] ?? 'N/A')) ?></td></tr>
                    <tr>
                        <td class="label-cell">NACIMIENTO:</td><td><?= htmlspecialchars(toUpper($c['fecha_nacimiento'] ?? 'N/A')) ?></td>
                        <td class="label-cell">SALARIO:</td><td>$<?= number_format((float)($c['salario'] ?? 0), 0, ',', '.') ?></td>
                    </tr>

                    <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">2. PAGO Y DATOS BANCARIOS</td></tr>
                    <tr>
                        <td class="label-cell">CUENTA:</td><td><?= htmlspecialchars(toUpper($c['numero_cuenta'] ?? 'N/A')) ?></td>
                        <td class="label-cell">LLAVE PAYMENT:</td><td><?= htmlspecialchars(toUpper($c['llave_payment'] ?? 'N/A')) ?></td>
                    </tr>

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
                                            <td><?= htmlspecialchars(toUpper($em['nombre_contacto'])) ?></td>
                                            <td><?= htmlspecialchars(toUpper($em['parentesco'] ?? '-')) ?></td>
                                            <td><?= htmlspecialchars(toUpper($em['celular_1'])) ?></td>
                                            <td><?= htmlspecialchars(toUpper($em['celular_2'] ?? '-')) ?></td>
                                            <td><?= (intval($em['es_principal'] ?? 0) === 1) ? 'SÍ' : 'NO' ?></td>
                                        </tr>
                                        <?php endwhile; else: ?>
                                        <tr><td colspan="5">SIN CONTACTOS REGISTRADOS.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>

                    <tr class="table-primary"><td colspan="4" class="fw-bold text-dark py-1">4. SEGURIDAD SOCIAL Y SST</td></tr>
                    <tr>
                        <td class="label-cell">EPS:</td><td><?= htmlspecialchars(toUpper($c['eps'] ?? 'N/A')) ?></td>
                        <td class="label-cell">ARL:</td><td><?= htmlspecialchars(toUpper($c['arl'] ?? 'N/A')) ?> (NIV. <?= htmlspecialchars(toUpper($c['nivel_arl'] ?? '1')) ?>)</td>
                    </tr>
                    <tr>
                        <td class="label-cell">RH:</td><td><?= htmlspecialchars(toUpper($c['grupo_sanguineo'] ?? 'N/A')) ?></td>
                        <td class="label-cell">PENSIONES:</td><td><?= htmlspecialchars(toUpper($c['fondo_pensiones'] ?? 'N/A')) ?> / <?= htmlspecialchars(toUpper($c['fondo_cesantias'] ?? 'N/A')) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-end gap-2 mt-3 btn-print">
        <button type="button" class="btn btn-success fw-bold w-100 w-sm-auto" data-bs-toggle="modal" data-bs-target="#modalFormatoBlanco">
            📄 GENERAR FORMATO EN BLANCO
        </button>
        <?php if ($es_supervisor): ?>
            <button type="button" class="btn btn-primary fw-bold w-100 w-sm-auto" data-bs-toggle="modal" data-bs-target="#modalEditarComplementos">
                ✏️ EDITAR COMPLEMENTOS
            </button>
        <?php endif; ?>
        <button onclick="window.print();" class="btn btn-dark fw-bold w-100 w-sm-auto">🖨️ IMPRIMIR FICHA</button>
    </div>
</div>

<!-- MODAL FORMATO EN BLANCO PARA COLABORADORES (CON IMPRESIÓN / GUARDAR PDF) -->
<div class="modal fade" id="modalFormatoBlanco" tabindex="-1" aria-labelledby="modalFormatoBlancoLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white no-print-formato">
                <h5 class="modal-title fw-bold fs-6 fs-md-5" id="modalFormatoBlancoLabel">
                    📄 Formato en Blanco de Datos del Colaborador
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body p-2 p-md-4 bg-light" id="formatoBlancoPrintArea">
                <div class="d-flex flex-wrap justify-content-end gap-2 mb-3 no-print-formato">
                    <button type="button" onclick="imprimirFormatoBlanco();" class="btn btn-dark btn-sm fw-bold">
                        🖨️ Imprimir Formato
                    </button>
                    <button type="button" onclick="imprimirFormatoBlanco();" class="btn btn-outline-primary btn-sm fw-bold">
                        ⬇️ Guardar como PDF
                    </button>
                </div>

                <div class="formato-blanco-hoja">
                    <div class="d-flex justify-content-between align-items-center border-bottom border-dark pb-2 mb-2">
                        <div>
                            <h5 class="fw-bold mb-0">FORMATO DE REGISTRO Y ACTUALIZACIÓN DE DATOS DEL COLABORADOR</h5>
                            <small class="text-muted">Favor diligenciar en letra imprenta legible y anexar copia de documento de identidad.</small>
                        </div>
                        <div class="text-end small">
                            <div><strong>Fecha:</strong> ____ / ____ / ________</div>
                        </div>
                    </div>

                    <div class="sec-title">1. Datos Generales y Personales</div>
                    <table>
                        <tr>
                            <td style="width: 22%;"><strong>Nombres y Apellidos:</strong></td>
                            <td style="width: 38%;"></td>
                            <td style="width: 18%;"><strong>Cédula / NIT:</strong></td>
                            <td style="width: 22%;"></td>
                        </tr>
                        <tr>
                            <td><strong>Fecha de Nacimiento:</strong></td>
                            <td>____ / ____ / ________</td>
                            <td><strong>Género:</strong></td>
                            <td>M [ ] &nbsp; F [ ] &nbsp; Otro [ ]</td>
                        </tr>
                        <tr>
                            <td><strong>Estado Civil:</strong></td>
                            <td></td>
                            <td><strong>Grupo Sanguíneo (RH):</strong></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Nivel Educativo:</strong></td>
                            <td></td>
                            <td><strong>Estrato:</strong></td>
                            <td>1 [ ] 2 [ ] 3 [ ] 4 [ ] 5 [ ] 6 [ ]</td>
                        </tr>
                        <tr>
                            <td><strong>Correo Electrónico:</strong></td>
                            <td></td>
                            <td><strong>Celular Principal:</strong></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Dirección Vivienda:</strong></td>
                            <td colspan="3"></td>
                        </tr>
                    </table>

                    <div class="sec-title">2. Información Laboral</div>
                    <table>
                        <tr>
                            <td style="width: 22%;"><strong>Empresa / Sede:</strong></td>
                            <td style="width: 38%;"></td>
                            <td style="width: 18%;"><strong>Fecha Ingreso:</strong></td>
                            <td style="width: 22%;">____ / ____ / ________</td>
                        </tr>
                        <tr>
                            <td><strong>Cargo:</strong></td>
                            <td></td>
                            <td><strong>Tipo Contrato:</strong></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Jornada Laboral:</strong></td>
                            <td></td>
                            <td><strong>Salario Acordado:</strong></td>
                            <td>$ ____________________</td>
                        </tr>
                    </table>

                    <div class="sec-title">3. Pago y Datos Bancarios</div>
                    <table>
                        <tr>
                            <td style="width: 22%;"><strong>Banco / Entidad:</strong></td>
                            <td style="width: 28%;"></td>
                            <td style="width: 22%;"><strong>Número de Cuenta:</strong></td>
                            <td style="width: 28%;"></td>
                        </tr>
                        <tr>
                            <td><strong>Tipo de Cuenta:</strong></td>
                            <td>Ahorros [ ] &nbsp; Corriente [ ]</td>
                            <td><strong>Llave Payment:</strong></td>
                            <td></td>
                        </tr>
                    </table>

                    <div class="sec-title">4. Seguridad Social y SST</div>
                    <table>
                        <tr>
                            <td style="width: 16%;"><strong>EPS:</strong></td>
                            <td style="width: 34%;"></td>
                            <td style="width: 16%;"><strong>ARL:</strong></td>
                            <td style="width: 34%;"></td>
                        </tr>
                        <tr>
                            <td><strong>Fondo Pensión:</strong></td>
                            <td></td>
                            <td><strong>Fondo Cesantías:</strong></td>
                            <td></td>
                        </tr>
                    </table>

                    <div class="sec-title">5. Contactos de Emergencia</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre y Apellido</th>
                                <th>Parentesco</th>
                                <th>Celular 1</th>
                                <th>Celular 2</th>
                                <th>Principal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="height: 28px;">
                                <td></td><td></td><td></td><td></td><td class="text-center">Sí [ ] No [ ]</td>
                            </tr>
                            <tr style="height: 28px;">
                                <td></td><td></td><td></td><td></td><td class="text-center">Sí [ ] No [ ]</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="linea-firma">
                                Firma del Colaborador<br>
                                <small>C.C. No.: ________________________</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white d-flex flex-wrap justify-content-end gap-2 no-print-formato">
                <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" onclick="imprimirFormatoBlanco();" class="btn btn-dark fw-bold w-100 w-sm-auto">
                    🖨️ Imprimir Formato
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EDITAR COMPLEMENTOS (100% RESPONSIVE) -->
<div class="modal fade" id="modalEditarComplementos" tabindex="-1" aria-labelledby="modalEditarComplementosLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold fs-6 fs-md-5" id="modalEditarComplementosLabel">
                    ✏️ Editar Complementos: <?= htmlspecialchars($emp_nombre) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form id="formEditarComplementos" action="guardar_complementos.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body p-3 p-md-4 bg-light">
                    <input type="hidden" name="colaborador_id" value="<?= $colaborador_id_real ?>">
                    <input type="hidden" name="cedula" value="<?= htmlspecialchars($c['CedulaNit'] ?? '') ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="comp_empresa" class="form-label fw-bold small mb-1">Empresa / Sede Asociada</label>
                            <select id="comp_empresa" name="nit_empresa" class="form-select">
                                <option value="">-- Seleccione empresa / sede --</option>
                                <?php foreach ($empresas_modal as $em):
                                    $nit = (string)$em['Nit'];
                                    $textoEmp = trim((string)($em['NombreComercial'] ?: $em['RazonSocial']));
                                    $selected = (($c['NitEmpresa'] ?? '') === $nit) ? 'selected' : '';
                                ?>
                                    <option value="<?= htmlspecialchars($nit) ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($textoEmp . ' (NIT: ' . $nit . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="card border bg-white">
                                <div class="card-body p-3">
                                    <div class="row g-3 align-items-center">
                                        <div class="col-12 col-sm-auto text-center">
                                            <img
                                                id="previewFotoComplementos"
                                                src="<?= htmlspecialchars($url_publica) ?>"
                                                alt="Foto actual almacenada"
                                                class="rounded-circle border shadow-sm <?= !$tiene_foto ? 'd-none' : '' ?>"
                                                style="width: 84px; height: 84px; object-fit: cover;"
                                            >
                                            <div id="sinFotoComplementos" class="text-muted small fst-italic <?= $tiene_foto ? 'd-none' : '' ?>">
                                                Sin foto
                                            </div>
                                            <div class="small text-muted mt-1">Foto actual almacenada</div>
                                        </div>
                                        <div class="col-12 col-sm">
                                            <label for="comp_foto" class="form-label fw-bold small mb-1">Actualizar Fotografía (Opcional)</label>
                                            <input type="file" id="comp_foto" name="foto" accept="image/*" class="form-control form-control-sm">
                                            <div class="form-text small">Formatos recomendados: JPG o PNG. Si no eliges archivo, se conserva la foto actual.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="comp_cargo" class="form-label fw-bold small mb-1">Cargo</label>
                            <input type="text" id="comp_cargo" name="cargo" class="form-control" value="<?= htmlspecialchars($c['cargo'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="comp_salario" class="form-label fw-bold small mb-1">Salario Mensual</label>
                            <input type="number" step="0.01" id="comp_salario" name="salario" class="form-control" value="<?= htmlspecialchars((string)($c['salario'] ?? '0')) ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="comp_correo" class="form-label fw-bold small mb-1">Correo Electrónico</label>
                            <input type="email" id="comp_correo" name="correo" class="form-control" value="<?= htmlspecialchars($c['correo'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="comp_contrato" class="form-label fw-bold small mb-1">Tipo de Contrato</label>
                            <?php $contratoActual = $c['tipo_contrato'] ?? ''; ?>
                            <select id="comp_contrato" name="tipo_contrato" class="form-select">
                                <option value="">-- Seleccione --</option>
                                <option value="Fijo" <?= $contratoActual === 'Fijo' ? 'selected' : '' ?>>Fijo</option>
                                <option value="Indefinido" <?= $contratoActual === 'Indefinido' ? 'selected' : '' ?>>Indefinido</option>
                                <option value="Obra o Labor" <?= $contratoActual === 'Obra o Labor' ? 'selected' : '' ?>>Obra o Labor</option>
                                <option value="Prestación de Servicios" <?= $contratoActual === 'Prestación de Servicios' ? 'selected' : '' ?>>Prestación de Servicios</option>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_jornada" class="form-label fw-bold small mb-1">Jornada Laboral</label>
                            <?php $jornadaActual = $c['jornada_laboral'] ?? ''; ?>
                            <select id="comp_jornada" name="jornada_laboral" class="form-select">
                                <option value="">-- Seleccione --</option>
                                <option value="Diurna" <?= $jornadaActual === 'Diurna' ? 'selected' : '' ?>>Diurna</option>
                                <option value="Nocturna" <?= $jornadaActual === 'Nocturna' ? 'selected' : '' ?>>Nocturna</option>
                                <option value="Mixta" <?= $jornadaActual === 'Mixta' ? 'selected' : '' ?>>Mixta</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_nivel_arl" class="form-label fw-bold small mb-1">Nivel ARL</label>
                            <input type="text" id="comp_nivel_arl" name="nivel_arl" class="form-control" value="<?= htmlspecialchars($c['nivel_arl'] ?? '1') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_fecha_nac" class="form-label fw-bold small mb-1">Fecha de Nacimiento</label>
                            <input type="date" id="comp_fecha_nac" name="fecha_nacimiento" class="form-control" value="<?= htmlspecialchars($c['fecha_nacimiento'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_genero" class="form-label fw-bold small mb-1">Género</label>
                            <?php $generoActual = $c['genero'] ?? ''; ?>
                            <select id="comp_genero" name="genero" class="form-select">
                                <option value="">-- Seleccione --</option>
                                <option value="Masculino" <?= $generoActual === 'Masculino' ? 'selected' : '' ?>>Masculino</option>
                                <option value="Femenino" <?= $generoActual === 'Femenino' ? 'selected' : '' ?>>Femenino</option>
                                <option value="Otro" <?= $generoActual === 'Otro' ? 'selected' : '' ?>>Otro</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_estado_civil" class="form-label fw-bold small mb-1">Estado Civil</label>
                            <?php $civilActual = $c['estado_civil'] ?? ''; ?>
                            <select id="comp_estado_civil" name="estado_civil" class="form-select">
                                <option value="">-- Seleccione --</option>
                                <option value="Soltero(a)" <?= $civilActual === 'Soltero(a)' ? 'selected' : '' ?>>Soltero(a)</option>
                                <option value="Casado(a)" <?= $civilActual === 'Casado(a)' ? 'selected' : '' ?>>Casado(a)</option>
                                <option value="Unión Libre" <?= $civilActual === 'Unión Libre' ? 'selected' : '' ?>>Unión Libre</option>
                                <option value="Divorciado(a)" <?= $civilActual === 'Divorciado(a)' ? 'selected' : '' ?>>Divorciado(a)</option>
                                <option value="Viudo(a)" <?= $civilActual === 'Viudo(a)' ? 'selected' : '' ?>>Viudo(a)</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_rh" class="form-label fw-bold small mb-1">Grupo Sanguíneo</label>
                            <input type="text" id="comp_rh" name="grupo_sanguineo" class="form-control" placeholder="Ej: O+, A+" value="<?= htmlspecialchars($c['grupo_sanguineo'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_educacion" class="form-label fw-bold small mb-1">Nivel Educativo</label>
                            <input type="text" id="comp_educacion" name="nivel_educativo" class="form-control" value="<?= htmlspecialchars($c['nivel_educativo'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_estrato" class="form-label fw-bold small mb-1">Estrato Socioeconómico</label>
                            <input type="number" min="1" max="6" id="comp_estrato" name="estrato" class="form-control" value="<?= htmlspecialchars($c['estrato'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_direccion" class="form-label fw-bold small mb-1">Dirección de Vivienda</label>
                            <input type="text" id="comp_direccion" name="direccion" class="form-control" value="<?= htmlspecialchars($c['direccion'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="comp_cuenta" class="form-label fw-bold small mb-1">Número de Cuenta</label>
                            <input type="text" id="comp_cuenta" name="numero_cuenta" class="form-control" value="<?= htmlspecialchars($c['numero_cuenta'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="comp_llave" class="form-label fw-bold small mb-1">Llave Payment</label>
                            <input type="text" id="comp_llave" name="llave_payment" class="form-control" value="<?= htmlspecialchars($c['llave_payment'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_eps" class="form-label fw-bold small mb-1">EPS</label>
                            <input type="text" id="comp_eps" name="eps" class="form-control" value="<?= htmlspecialchars($c['eps'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_pensiones" class="form-label fw-bold small mb-1">Fondo de Pensiones</label>
                            <input type="text" id="comp_pensiones" name="fondo_pensiones" class="form-control" value="<?= htmlspecialchars($c['fondo_pensiones'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label for="comp_cesantias" class="form-label fw-bold small mb-1">Fondo de Cesantías</label>
                            <input type="text" id="comp_cesantias" name="fondo_cesantias" class="form-control" value="<?= htmlspecialchars($c['fondo_cesantias'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-white d-flex flex-wrap justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold w-100 w-sm-auto">💾 Guardar Complementos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('comp_foto')?.addEventListener('change', function () {
    const archivo = this.files && this.files[0];
    const img = document.getElementById('previewFotoComplementos');
    const sinFoto = document.getElementById('sinFotoComplementos');
    if (!archivo || !img) return;

    const lector = new FileReader();
    lector.onload = function (e) {
        img.src = e.target.result;
        img.classList.remove('d-none');
        if (sinFoto) sinFoto.classList.add('d-none');
    };
    lector.readAsDataURL(archivo);
});

function imprimirFormatoBlanco() {
    document.body.classList.add('imprimiendo-formato');
    window.print();
    setTimeout(() => {
        document.body.classList.remove('imprimiendo-formato');
    }, 500);
}

window.addEventListener('afterprint', () => {
    document.body.classList.remove('imprimiendo-formato');
});
</script>
</body>
</html>