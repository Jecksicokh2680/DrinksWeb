<?php
session_start();
require 'Conexion.php';

// Cargar empresas
$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado = 1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);

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

// AJAX: usuarios para filtro dinámico
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

// Procesar formulario
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $CedulaNit   = trim($_POST['CedulaNit']);
    $NitEmpresa  = trim($_POST['NitEmpresa']);
    $NroSucursal = trim($_POST['NroSucursal']);
    $Password    = trim($_POST['Password']);
    if ($CedulaNit == "" || $NitEmpresa == "" || $NroSucursal == "" || $Password == "") {
        $msg = "⚠ Todos los campos son obligatorios.";
    } else {
        $PasswordHash = password_hash($Password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO usuarios_Seguridad (CedulaNit, NitEmpresa, NroSucursal, PasswordHash, FechaCreacion, Estado) 
                VALUES (?, ?, ?, ?, NOW(), 1)";
        $stm = $mysqli->prepare($sql);
        $stm->bind_param("ssss", $CedulaNit, $NitEmpresa, $NroSucursal, $PasswordHash);
        $msg = $stm->execute() ? "✅ Usuario creado correctamente." : "❌ Error: " . $stm->error;
        $stm->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Crear Usuario</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
// Cargar sucursales según empresa
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let suc = document.getElementById("NroSucursal");
    if (!nit) { suc.innerHTML='<option>Seleccione empresa primero</option>'; return; }
    fetch("?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res=>res.json())
        .then(data=>{
            suc.innerHTML='<option value="">Seleccione...</option>';
            data.forEach(s=>{suc.innerHTML+=`<option value="${s.NroSucursal}">${s.Direccion}</option>`;});
        });
}

// Filtrar usuarios dinámicamente
function filtrarUsuarios() {
    let input = document.getElementById("NombreUsuario").value;
    let hidden = document.getElementById("CedulaNit");
    fetch("?ajax=usuarios&term=" + encodeURIComponent(input))
        .then(res=>res.json())
        .then(data=>{
            let list = document.getElementById("ListaUsuarios");
            list.innerHTML = "";
            data.forEach(u=>{
                let option = document.createElement("option");
                option.value = u.CedulaNit;
                option.textContent = u.Nombre + " ("+u.CedulaNit+")";
                list.appendChild(option);
            });
            // Si coincide exactamente con una opción, guardar en hidden
            let exact = data.find(u => u.Nombre.toLowerCase()===input.toLowerCase() || u.CedulaNit===input);
            hidden.value = exact ? exact.CedulaNit : input;
        });
}
</script>
</head>
<body class="bg-light">
<div class="container mt-5">
<div class="col-md-6 offset-md-3">
<div class="card shadow">
<div class="card-header bg-primary text-white"><h4 class="mb-0">Registrar Usuario</h4></div>
<div class="card-body">
<?php if($msg!=""): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>

<form method="POST">

<!-- Usuario / Cédula con filtro dinámico -->
<div class="mb-3">
    <label class="form-label">Cédula / NIT Usuario</label>
    <input list="ListaUsuarios" id="NombreUsuario" class="form-control" oninput="filtrarUsuarios()" required placeholder="Escriba nombre o cédula">
    <datalist id="ListaUsuarios"></datalist>
    <input type="hidden" name="CedulaNit" id="CedulaNit">
</div>

<!-- Empresa y Sucursal en línea -->
<div class="mb-3 row g-2 align-items-end">
<div class="col">
    <label class="form-label">Empresa</label>
    <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
        <option value="">Seleccione empresa...</option>
        <?php foreach($empresasArray as $e): ?>
        <option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col">
    <label class="form-label">Sucursal</label>
    <select name="NroSucursal" id="NroSucursal" class="form-select" required>
        <option value="">Seleccione empresa primero...</option>
    </select>
</div>
</div>

<!-- Contraseña -->
<div class="mb-3">
    <label class="form-label">Contraseña</label>
    <input type="password" name="Password" class="form-control" required>
</div>

<button class="btn btn-success w-100">Crear Usuario</button>
</form>
</div></div></div></div>
</body>
</html>
