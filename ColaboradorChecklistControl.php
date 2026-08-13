<?php
// Mostrar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir tu archivo de conexión real
require_once 'Conexion.php'; 

if (!isset($mysqliWeb) || $mysqliWeb->connect_errno) {
    die("Error crítico: No se pudo establecer la conexión a la base de datos con \$mysqliWeb.");
}

$cedulaSeleccionada = $_GET['CedulaNit'] ?? '';
$nitSeleccionado = $_GET['NitEmpresa'] ?? '';

// 1. Obtener la lista de colaboradores activos cruzando con la tabla 'terceros' para obtener el Nombre
$colaboradores = [];
$sqlColab = "SELECT c.CedulaNit, c.NitEmpresa, c.cargo, c.url_foto, t.Nombre 
             FROM colaborador c 
             LEFT JOIN terceros t ON c.CedulaNit = t.CedulaNit 
             WHERE c.estado = 'ACTIVO'";
$resColab = $mysqliWeb->query($sqlColab);
if ($resColab) {
    while ($row = $resColab->fetch_assoc()) {
        $colaboradores[] = $row;
    }
}

// Si no viene por GET pero hay colaboradores, seleccionamos el primero por defecto
if (empty($cedulaSeleccionada) && !empty($colaboradores)) {
    $cedulaSeleccionada = $colaboradores[0]['CedulaNit'];
    $nitSeleccionado = $colaboradores[0]['NitEmpresa'];
}

// 2. Auto-inicializar requisitos: Si el colaborador seleccionado no tiene registros en el detalle, se los copiamos de requisitos_empresa
if (!empty($cedulaSeleccionada) && !empty($nitSeleccionado)) {
    $stmtCheckCount = $mysqliWeb->prepare("SELECT COUNT(*) as total FROM colaborador_requisito_detalle WHERE CedulaNit = ? AND NitEmpresa = ?");
    $stmtCheckCount->bind_param("ss", $cedulaSeleccionada, $nitSeleccionado);
    $stmtCheckCount->execute();
    $resCount = $stmtCheckCount->get_result()->fetch_assoc();
    $stmtCheckCount->close();

    if ($resCount['total'] == 0) {
        $stmtReqGen = $mysqliWeb->prepare("SELECT id FROM requisitos_empresa WHERE NitEmpresa = ?");
        $stmtReqGen->bind_param("s", $nitSeleccionado);
        $stmtReqGen->execute();
        $resReqGen = $stmtReqGen->get_result();

        while ($req = $resReqGen->fetch_assoc()) {
            $reqId = $req['id'];
            $stmtInsert = $mysqliWeb->prepare("INSERT INTO colaborador_requisito_detalle (CedulaNit, NitEmpresa, requisito_id, entregado) VALUES (?, ?, ?, 0)");
            $stmtInsert->bind_param("ssi", $cedulaSeleccionada, $nitSeleccionado, $reqId);
            $stmtInsert->execute();
            $stmtInsert->close();
        }
        $stmtReqGen->close();
    }
}

// 3. Procesar la actualización en tiempo real cuando se hace clic en un checkbox
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_estado') {
    $detalleId = intval($_POST['detalle_id'] ?? 0);
    $entregado = isset($_POST['entregado']) ? 1 : 0;
    $fechaEntrega = ($entregado == 1) ? date('Y-m-d') : null;

    if ($detalleId > 0) {
        $stmtUpdate = $mysqliWeb->prepare("UPDATE colaborador_requisito_detalle SET entregado = ?, fecha_entrega = ? WHERE id = ?");
        if ($stmtUpdate) {
            $stmtUpdate->bind_param("isi", $entregado, $fechaEntrega, $detalleId);
            $stmtUpdate->execute();
            $stmtUpdate->close();
            
            header("Location: ColaboradorChecklistControl.php?CedulaNit=" . urlencode($cedulaSeleccionada) . "&NitEmpresa=" . urlencode($nitSeleccionado));
            exit();
        }
    }
}

// 4. Obtener la información del colaborador actual
$colaboradorActual = null;
foreach ($colaboradores as $c) {
    if ($c['CedulaNit'] === $cedulaSeleccionada && $c['NitEmpresa'] === $nitSeleccionado) {
        $colaboradorActual = $c;
        break;
    }
}

// 5. Obtener el detalle del checklist para el colaborador seleccionado
$checklistColaborador = [];
$progresoCompletado = 0;
$progresoTotal = 0;

if (!empty($cedulaSeleccionada) && !empty($nitSeleccionado)) {
    $sqlChecklist = "SELECT d.id, d.entregado, d.fecha_entrega, r.nombre_requisito, r.descripcion, r.obligatorio 
                     FROM colaborador_requisito_detalle d 
                     INNER JOIN requisitos_empresa r ON d.requisito_id = r.id 
                     WHERE d.CedulaNit = ? AND d.NitEmpresa = ?";
    
    $stmtCheck = $mysqliWeb->prepare($sqlChecklist);
    if ($stmtCheck) {
        $stmtCheck->bind_param("ss", $cedulaSeleccionada, $nitSeleccionado);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        while ($row = $resCheck->fetch_assoc()) {
            $checklistColaborador[] = $row;
            $progresoTotal++;
            if ($row['entregado'] == 1) {
                $progresoCompletado++;
            }
        }
        $stmtCheck->close();
    }
}

$porcentajeAvance = ($progresoTotal > 0) ? round(($progresoCompletado / $progresoTotal) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Checklists de Colaboradores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Configuración estricta para hoja tamaño Carta vertical al imprimir */
        @page {
            size: letter portrait;
            margin: 8mm 10mm;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background-color: #fff !important;
                font-size: 11pt;
                color: #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .container {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .card {
                border: 1px solid #dee2e6 !important;
                box-shadow: none !important;
                margin-bottom: 0.5rem !important;
            }
            .card-body {
                padding: 0.6rem !important;
            }
            .table th, .table td {
                padding: 0.35rem 0.5rem !important;
                font-size: 0.9rem;
            }
            .table-responsive {
                overflow: visible !important;
            }
            h2 {
                font-size: 1.25rem !important;
                margin-bottom: 0.2rem !important;
            }
            .print-foto {
                width: 60px !important;
                height: 60px !important;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4 mb-4" style="max-width: 950px;">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h4 mb-0">Control de Documentos (Hoja de Vida)</h2>
            <div class="no-print">
                <?php if (!empty($cedulaSeleccionada)): ?>
                    <button onclick="window.print()" class="btn btn-primary btn-sm me-2">🖨️ Imprimir Checklist</button>
                <?php endif; ?>
                <a href="ColaboradorRequisitos.php" class="btn btn-outline-secondary btn-sm">← Gestionar Requisitos</a>
            </div>
        </div>

        <!-- Selector de Colaborador y Perfil con Foto -->
        <div class="card shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" action="" class="row g-2 align-items-center">
                    
                    <!-- Foto del colaborador -->
                    <div class="col-md-2 text-center">
                        <?php 
                            $fotoPerfil = (!empty($colaboradorActual['url_foto'])) ? $colaboradorActual['url_foto'] : '';
                        ?>
                        <img src="<?php echo htmlspecialchars($fotoPerfil); ?>" alt="Foto Colaborador" 
                             class="rounded-circle border shadow-sm print-foto" 
                             style="width: 70px; height: 70px; object-fit: cover;"
                             onerror="this.src='https://via.placeholder.com/70?text=Sin+Foto';">
                    </div>

                    <!-- Selector e Info -->
                    <div class="col-md-7">
                        <label for="colaborador_select" class="form-label fw-bold mb-1 no-print" style="font-size: 0.85rem;">Seleccionar Colaborador:</label>
                        <select name="CedulaNit" id="colaborador_select" class="form-select form-select-sm no-print" onchange="this.form.submit()">
                            <option value="">-- Seleccione un empleado --</option>
                            <?php foreach ($colaboradores as $c): ?>
                                <?php 
                                    $isSelected = ($c['CedulaNit'] === $cedulaSeleccionada && $c['NitEmpresa'] === $nitSeleccionado);
                                    $nombreColab = !empty($c['Nombre']) ? $c['Nombre'] : 'Sin registrar en Terceros';
                                ?>
                                <option value="<?php echo htmlspecialchars($c['CedulaNit']); ?>" 
                                        data-nit="<?php echo htmlspecialchars($c['NitEmpresa']); ?>"
                                        <?php if ($isSelected) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($c['CedulaNit']); ?> — <?php echo htmlspecialchars($nombreColab); ?> (Cargo: <?php echo htmlspecialchars($c['cargo'] ?? 'No asignado'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="NitEmpresa" value="<?php echo htmlspecialchars($nitSeleccionado); ?>">
                        
                        <!-- Datos generales fijos -->
                        <div class="mt-1">
                            <h5 class="mb-0 text-dark"><strong><?php echo htmlspecialchars($colaboradorActual['Nombre'] ?? 'Colaborador no seleccionado'); ?></strong></h5>
                            <small class="text-muted" style="font-size: 0.82rem;">
                                Cédula: <strong><?php echo htmlspecialchars($cedulaSeleccionada); ?></strong> | 
                                Cargo: <strong><?php echo htmlspecialchars($colaboradorActual['cargo'] ?? 'No especificado'); ?></strong> | 
                                NIT Empresa: <?php echo htmlspecialchars($nitSeleccionado); ?>
                            </small>
                        </div>
                    </div>

                    <!-- Progreso -->
                    <div class="col-md-3 text-md-end">
                        <span class="text-muted d-block" style="font-size: 0.8rem;">Avance general:</span>
                        <h4 class="text-success mb-0 fw-bold"><?php echo $porcentajeAvance; ?>%</h4>
                    </div>
                </form>
                
                <!-- Barra de progreso visual (Pantalla) -->
                <div class="progress mt-2 no-print" style="height: 6px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $porcentajeAvance; ?>%;" aria-valuenow="<?php echo $porcentajeAvance; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>

        <!-- Tabla Interactiva de Documentos / Checklist -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white py-2">
                <h6 class="mb-0 fs-6">Documentos Físicos en Hoja de Vida</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle" style="font-size: 0.9rem;">
                        <thead class="table-secondary">
                            <tr>
                                <th style="width: 12%; text-align: center;">¿Tiene Físico?</th>
                                <th style="width: 30%;">Documento / Requisito</th>
                                <th style="width: 38%;">Descripción</th>
                                <th style="width: 20%; text-align: center;">Fecha de Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($checklistColaborador)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">
                                        <?php echo empty($cedulaSeleccionada) ? 'Seleccione un colaborador arriba para desplegar su lista de requisitos.' : 'No hay requisitos generales creados para la empresa.'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($checklistColaborador as $item): ?>
                                    <tr>
                                        <td class="text-center">
                                            <!-- Interactivo en Pantalla -->
                                            <div class="no-print">
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="accion" value="actualizar_estado">
                                                    <input type="hidden" name="detalle_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="CedulaNit" value="<?php echo htmlspecialchars($cedulaSeleccionada); ?>">
                                                    <input type="hidden" name="NitEmpresa" value="<?php echo htmlspecialchars($nitSeleccionado); ?>">
                                                    
                                                    <input type="checkbox" name="entregado" value="1" 
                                                           class="form-check-input" style="width: 1.3em; height: 1.3em; cursor: pointer;"
                                                           onchange="this.form.submit()" 
                                                           <?php if ($item['entregado'] == 1) echo 'checked'; ?>
                                                           title="Haz clic para marcar o desmarcar">
                                                </form>
                                            </div>
                                            <!-- Estático en Impresión / PDF -->
                                            <div class="d-none d-print-block fw-bold">
                                                <?php echo ($item['entregado'] == 1) ? '[ X ] Sí' : '[   ] No'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold"><?php echo htmlspecialchars($item['nombre_requisito']); ?></span>
                                            <?php if ($item['obligatorio'] == 1): ?>
                                                <span class="badge bg-danger ms-1 no-print" style="font-size: 0.6rem;">Obligatorio</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary ms-1 no-print" style="font-size: 0.6rem;">Opcional</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted" style="font-size: 0.82rem;"><?php echo htmlspecialchars($item['descripcion'] ?? 'Sin descripción'); ?></small></td>
                                        <td class="text-center">
                                            <?php if (!empty($item['fecha_entrega'])): ?>
                                                <span class="badge bg-success" style="font-size: 0.75rem;"><?php echo $item['fecha_entrega']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.8rem;">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const selectColab = document.getElementById('colaborador_select');
        if (selectColab) {
            selectColab.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const nit = selectedOption.getAttribute('data-nit');
                if (nit) {
                    document.querySelector('input[name="NitEmpresa"]').value = nit;
                }
            });
        }
    </script>
</body>
</html>