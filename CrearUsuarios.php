<?php
session_start();
require 'Conexion.php';

// Procesar creación de usuario
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarUsuario'])) {
    $CedulaNit   = trim($_POST['CedulaNit']);
    $NitEmpresa  = trim($_POST['NitEmpresa']);
    $NroSucursal = trim($_POST['NroSucursal']);
    $Password    = trim($_POST['Password']);

    if ($CedulaNit == "" || $NitEmpresa == "" || $NroSucursal == "" || $Password == "") {
        $msg = "⚠ Todos los campos son obligatorios.";
    } else {
        // Validar que CedulaNit exista en terceros
        $stmtCheck = $mysqli->prepare("SELECT COUNT(*) FROM terceros WHERE CedulaNit=? AND Estado=1");
        $stmtCheck->bind_param("s", $CedulaNit);
        $stmtCheck->execute();
        $stmtCheck->bind_result($count);
        $stmtCheck->fetch();
        $stmtCheck->close();

        if($count == 0){
            $msg = "⚠ El usuario seleccionado no es válido.";
        } else {
            $PasswordHash = password_hash($Password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuarios_Seguridad (CedulaNit, NitEmpresa, NroSucursal, PasswordHash, FechaCreacion, Estado) 
                    VALUES (?, ?, ?, ?, NOW(), 1)";
            $stm = $mysqli->prepare($sql);
            if (!$stm) {
                $msg = "❌ Error de preparación: " . $mysqli->error;
            } else {
                $stm->bind_param("ssss", $CedulaNit, $NitEmpresa, $NroSucursal, $PasswordHash);
                $msg = $stm->execute() ? "✅ Usuario creado correctamente." : "❌ Error al insertar: " . $stm->error;
                $stm->close();
            }
        }
    }
}

// Procesar activar/desactivar usuario
if (isset($_GET['toggle_user'])) {
    $cedula = $_GET['toggle_user'];
    $stmt = $mysqli->prepare("UPDATE usuarios_Seguridad SET Estado = IF(Estado=1,0,1) WHERE CedulaNit=?");
    $stmt->bind_param("s", $cedula);
    $stmt->execute();
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// AJAX: sucursales
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sucursales') {
    header('Content-Type: application/json; charset=utf-8');
    $nit = $_GET['nit'] ?? '';
    if ($nit === '') { echo json_encode([]); exit; }
    $stmt = $mysqli->prepare("SELECT NroSucursal, Direccion FROM empresa_sucursal WHERE Nit=? AND Estado=1 ORDER BY Direccion");
    $stmt->bind_param("s", $nit);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = []; while ($row = $result->fetch_assoc()) $data[] = $row;
    echo json_encode($data); $stmt->close(); exit;
}

// AJAX: usuarios
if (isset($_GET['ajax']) && $_GET['ajax'] === 'usuarios') {
    header('Content-Type: application/json; charset=utf-8');
    $term = $_GET['term'] ?? '';
    $stmt = $mysqli->prepare("SELECT CedulaNit, Nombre FROM terceros WHERE Estado=1 AND (CedulaNit LIKE ? OR Nombre LIKE ?) ORDER BY Nombre");
    $like = "%$term%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = []; while ($row = $result->fetch_assoc()) $data[] = $row;
    echo json_encode($data); $stmt->close(); exit;
}

// Cargar empresas
$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado = 1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);

// Obtener usuarios
$usuarios = $mysqli->query("
    SELECT u.CedulaNit, t.Nombre, e.NombreComercial, s.Direccion, u.FechaCreacion, u.Estado
    FROM usuarios_Seguridad u
    LEFT JOIN terceros t ON u.CedulaNit = t.CedulaNit
    LEFT JOIN empresa e ON u.NitEmpresa = e.Nit
    LEFT JOIN empresa_sucursal s ON u.NitEmpresa = s.Nit AND u.NroSucursal = s.NroSucursal
    ORDER BY u.CedulaNit asc
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Gestión de Usuarios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
function cargarSucursalesModal() {
    let nitSelect = document.getElementById("NitEmpresaModal");
    let sucSelect = document.getElementById("NroSucursalModal");
    let nit = nitSelect.value;
    if (!nit) { sucSelect.innerHTML='<option>Seleccione empresa primero</option>'; return; }
    fetch("?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res=>res.json())
        .then(data=>{
            sucSelect.innerHTML='<option value="">Seleccione...</option>';
            data.forEach(s=>{sucSelect.innerHTML+=`<option value="${s.NroSucursal}">${s.Direccion}</option>`;});
        });
}

function filtrarUsuariosModal() {
    let input = document.getElementById("NombreUsuarioModal").value;
    let hidden = document.getElementById("CedulaNitModal");
    fetch("?ajax=usuarios&term=" + encodeURIComponent(input))
        .then(res=>res.json())
        .then(data=>{
            let list = document.getElementById("ListaUsuariosModal");
            list.innerHTML = "";
            data.forEach(u=>{
                let option = document.createElement("option");
                option.value = u.CedulaNit;
                option.textContent = u.Nombre + " ("+u.CedulaNit+")";
                list.appendChild(option);
            });
            let exact = data.find(u => u.Nombre.toLowerCase()===input.toLowerCase() || u.CedulaNit===input);
            hidden.value = exact ? exact.CedulaNit : "";
        });
}

// Resetear modal al abrir
var modalUsuario = document.getElementById('modalUsuario');
modalUsuario.addEventListener('show.bs.modal', function () {
    document.getElementById("NombreUsuarioModal").value = "";
    document.getElementById("CedulaNitModal").value = "";
    document.getElementById("NitEmpresaModal").selectedIndex = 0;
    document.getElementById("NroSucursalModal").innerHTML='<option value="">Seleccione empresa primero...</option>';
});
</script>
</head>
<body class="bg-light">
<div class="container mt-5">

<?php if($msg!=""): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <h2>Usuarios Registrados</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUsuario">➕ Crear Usuario</button>
</div>

<div class="table-responsive">
<table class="table table-bordered table-striped">
    <thead class="table-dark">
        <tr>
            <th>Cédula/NIT</th>
            <th>Nombre</th>
            <th>Empresa</th>
            <th>Sucursal</th>
            <th>Fecha Creación</th>
            <th>Estado</th>
            <th>Acción</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($usuarios as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['CedulaNit']) ?></td>
            <td><?= htmlspecialchars($u['Nombre']) ?></td>
            <td><?= htmlspecialchars($u['NombreComercial']) ?></td>
            <td><?= htmlspecialchars($u['Direccion']) ?></td>
            <td><?= $u['FechaCreacion'] ?></td>
            <td><?= $u['Estado']==1 ? 'Activo' : 'Inactivo' ?></td>
            <td>
                <a href="?toggle_user=<?= $u['CedulaNit'] ?>" class="btn btn-sm <?= $u['Estado']==1 ? 'btn-warning' : 'btn-success' ?>">
                    <?= $u['Estado']==1 ? 'Desactivar' : 'Activar' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(count($usuarios)==0): ?>
            <tr><td colspan="7" class="text-center">No hay usuarios registrados.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>

<!-- Modal -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Crear Usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Cédula / NIT Usuario</label>
                <input list="ListaUsuariosModal" id="NombreUsuarioModal" class="form-control" oninput="filtrarUsuariosModal()" required placeholder="Escriba nombre o cédula">
                <datalist id="ListaUsuariosModal"></datalist>
                <input type="hidden" name="CedulaNit" id="CedulaNitModal">
            </div>

            <div class="mb-3 row g-2 align-items-end">
                <div class="col">
                    <label class="form-label">Empresa</label>
                    <select name="NitEmpresa" id="NitEmpresaModal" class="form-select" onchange="cargarSucursalesModal()" required>
                        <option value="">Seleccione empresa...</option>
                        <?php foreach($empresasArray as $e): ?>
                        <option value="<?= $e['Nit'] ?>"><?= htmlspecialchars($e['NombreComercial']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label class="form-label">Sucursal</label>
                    <select name="NroSucursal" id="NroSucursalModal" class="form-select" required>
                        <option value="">Seleccione empresa primero...</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" name="Password" class="form-control" required>
            </div>

            <input type="hidden" name="guardarUsuario" value="1">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">Crear Usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
