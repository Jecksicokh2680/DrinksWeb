<?php
// Mostrar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir tu archivo de conexión real
require_once 'Conexion.php'; 

// Validar que la conexión $mysqliWeb esté disponible y sin errores
if (!isset($mysqliWeb) || $mysqliWeb->connect_errno) {
    die("Error crítico: No se pudo establecer la conexión a la base de datos con \$mysqliWeb.");
}

$mensaje = "";
$tipoAlerta = "";

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_requisito') {
    $nitEmpresa = trim($_POST['NitEmpresa'] ?? '');
    $nombreRequisito = trim($_POST['nombre_requisito'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;

    if (!empty($nitEmpresa) && !empty($nombreRequisito)) {
        // Iniciar transacción con MySQLi
        $mysqliWeb->begin_transaction();

        try {
            // 1. Insertar el nuevo requisito en la tabla maestra usando prepared statements
            $stmt = $mysqliWeb->prepare("INSERT INTO requisitos_empresa (NitEmpresa, nombre_requisito, descripcion, obligatorio) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Error en prepare (requisitos): " . $mysqliWeb->error);
            }
            $stmt->bind_param("sssi", $nitEmpresa, $nombreRequisito, $descripcion, $obligatorio);
            $stmt->execute();
            
            $requisitoId = $mysqliWeb->insert_id;
            $stmt->close();

            // 2. Buscar a todos los colaboradores activos de esa empresa
            $stmtColab = $mysqliWeb->prepare("SELECT CedulaNit FROM colaborador WHERE NitEmpresa = ? AND estado = 'ACTIVO'");
            if (!$stmtColab) {
                throw new Exception("Error en prepare (colaborador): " . $mysqliWeb->error);
            }
            $stmtColab->bind_param("s", $nitEmpresa);
            $stmtColab->execute();
            $resultadoColab = $stmtColab->get_result();
            
            $colaboradores = [];
            while ($row = $resultadoColab->fetch_assoc()) {
                $colaboradores[] = $row;
            }
            $stmtColab->close();

            // 3. Crear el detalle pendiente para cada colaborador existente
            if (!empty($colaboradores)) {
                $stmtDetalle = $mysqliWeb->prepare("INSERT INTO colaborador_requisito_detalle (CedulaNit, NitEmpresa, requisito_id, entregado) VALUES (?, ?, ?, 0)");
                if (!$stmtDetalle) {
                    throw new Exception("Error en prepare (detalle): " . $mysqliWeb->error);
                }

                foreach ($colaboradores as $colab) {
                    $cedula = $colab['CedulaNit'];
                    $stmtDetalle->bind_param("ssi", $cedula, $nitEmpresa, $requisitoId);
                    $stmtDetalle->execute();
                }
                $stmtDetalle->close();
            }

            // Si todo sale bien, confirmamos la transacción
            $mysqliWeb->commit();
            $mensaje = "¡Requisito creado y asignado exitosamente a los colaboradores activos!";
            $tipoAlerta = "success";

        } catch (Exception $e) {
            // Si algo falla, revertimos los cambios
            $mysqliWeb->rollback();
            $mensaje = "Error al guardar: " . $e->getMessage();
            $tipoAlerta = "danger";
        }
    } else {
        $mensaje = "Por favor, completa los campos obligatorios (NIT y Nombre del Requisito).";
        $tipoAlerta = "warning";
    }
}

// Consultar la lista de empresas de forma segura
$empresas = [];
$resultadoEmpresas = $mysqliWeb->query("SELECT Nit, RazonSocial FROM empresa WHERE Estado = 1");
if ($resultadoEmpresas) {
    while ($row = $resultadoEmpresas->fetch_assoc()) {
        $empresas[] = $row;
    }
}

// Consultar los requisitos existentes en la base de datos para listarlos
$requisitosListado = [];
$resultadoReq = $mysqliWeb->query("SELECT r.*, e.RazonSocial FROM requisitos_empresa r INNER JOIN empresa e ON r.NitEmpresa = e.Nit ORDER BY r.id DESC");
if ($resultadoReq) {
    while ($row = $resultadoReq->fetch_assoc()) {
        $requisitosListado[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Requisitos de Empresa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5 mb-5" style="max-width: 1000px;">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Gestión de Requisitos de Colaboradores</h2>
            <!-- Botón para activar el Modal -->
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoRequisito">
                + Nuevo Requisito
            </button>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?php echo $tipoAlerta; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($mensaje); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Tabla que lista los requisitos creados -->
        <div class="card shadow">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">Requisitos Configurados en el Sistema</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-secondary">
                            <tr>
                                <th>ID</th>
                                <th>Empresa</th>
                                <th>Requisito</th>
                                <th>Descripción</th>
                                <th>Obligatorio</th>
                                <th>Fecha Creación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requisitosListado)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No hay requisitos registrados todavía.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requisitosListado as $req): ?>
                                    <tr>
                                        <td><?php echo $req['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($req['RazonSocial']); ?></strong><br><small class="text-muted"><?php echo $req['NitEmpresa']; ?></small></td>
                                        <td><?php echo htmlspecialchars($req['nombre_requisito']); ?></td>
                                        <td><?php echo htmlspecialchars($req['descripcion'] ?? 'Sin descripción'); ?></td>
                                        <td>
                                            <?php if ($req['obligatorio'] == 1): ?>
                                                <span class="badge bg-danger">Obligatorio</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Opcional</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo $req['created_at']; ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- MODAL PARA CREAR NUEVO REQUISITO -->
    <div class="modal fade" id="modalNuevoRequisito" tabindex="-1" aria-labelledby="modalNuevoRequisitoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="accion" value="crear_requisito">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalNuevoRequisitoLabel">Crear Nuevo Requisito</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="NitEmpresa" class="form-label">Seleccionar Empresa:</label>
                            <select name="NitEmpresa" id="NitEmpresa" class="form-select" required>
                                <option value="">-- Seleccione una empresa --</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <option value="<?php echo htmlspecialchars($emp['Nit']); ?>">
                                        <?php echo htmlspecialchars($emp['RazonSocial'] . " (NIT: " . $emp['Nit'] . ")"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="nombre_requisito" class="form-label">Nombre del Requisito (Documento):</label>
                            <input type="text" name="nombre_requisito" id="nombre_requisito" class="form-control" placeholder="Ej: Curso de Alturas, Licencia de Conducir" required>
                        </div>

                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción o Instrucciones (Opcional):</label>
                            <textarea name="descripcion" id="descripcion" class="form-control" rows="3" placeholder="Detalles de vigencia o dónde solicitarlo..."></textarea>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" name="obligatorio" id="obligatorio" class="form-check-input" value="1" checked>
                            <label class="form-check-label" for="obligatorio">¿Es un documento obligatorio?</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Guardar y Asignar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Script de Bootstrap necesario para que funcione el modal -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>