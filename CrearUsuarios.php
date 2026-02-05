<?php
require 'Conexion.php';     // Base Local  ($mysqli)
require 'ConnCentral.php';  // Base Central ($mysqliPos)
session_start();

/* ============================================================
   RESPUESTAS AJAX
============================================================ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'usuarios') {
        $term = "%" . ($_GET['term'] ?? '') . "%";
        $stmt = $mysqliPos->prepare("SELECT nit, nombres, apellidos FROM terceros WHERE inactivo = 0 AND (nit LIKE ? OR nombres LIKE ? OR apellidos LIKE ?) LIMIT 10");
        $stmt->bind_param("sss", $term, $term, $term);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) {
            $data[] = ['nit' => $r['nit'], 'nombre' => trim($r['nombres'] . ' ' . $r['apellidos'])];
        }
        echo json_encode($data);
        exit;
    }
    if ($_GET['ajax'] === 'sucursales') {
        $nit = $_GET['nit'] ?? '';
        $stmt = $mysqli->prepare("SELECT NroSucursal, Direccion FROM empresa_sucursal WHERE Nit = ? AND Estado = 1");
        $stmt->bind_param("s", $nit);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        exit;
    }
}

/* ============================================================
   PROCESAMIENTO FORMULARIO (LOGICA MULTI-EMPRESA)
============================================================ */
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarUsuario'])) {
    $CedulaNit   = trim($_POST['CedulaNit'] ?? '');
    $NitEmpresa  = trim($_POST['NitEmpresa'] ?? '');
    $NroSucursal = trim($_POST['NroSucursal'] ?? '');
    $Password    = trim($_POST['Password'] ?? '');

    if ($CedulaNit === '' || $NitEmpresa === '' || $NroSucursal === '') {
        $msg = "⚠ Debe seleccionar un tercero, empresa y sucursal.";
    } else {
        // 1. Verificar si ya existe este acceso específico (Cédula + Empresa + Sucursal)
        $stmtU = $mysqli->prepare("SELECT COUNT(*) FROM usuarios_Seguridad WHERE CedulaNit = ? AND NitEmpresa = ? AND NroSucursal = ?");
        $stmtU->bind_param("sss", $CedulaNit, $NitEmpresa, $NroSucursal);
        $stmtU->execute();
        $stmtU->bind_result($existe);
        $stmtU->fetch();
        $stmtU->close();

        if ($existe > 0) {
            // ACTUALIZAR ACCESO EXISTENTE
            if ($Password !== '') {
                $hash = password_hash($Password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE usuarios_Seguridad SET PasswordHash=? WHERE CedulaNit=? AND NitEmpresa=? AND NroSucursal=?");
                $stmt->bind_param("ssss", $hash, $CedulaNit, $NitEmpresa, $NroSucursal);
            } else {
                // Si no hay clave nueva, solo confirmamos que ya existe (o podrías actualizar el Estado)
                $msg = "ℹ El acceso ya existe para este usuario en esta sucursal.";
                $stmt = null;
            }
            
            if($stmt){
                $msg = $stmt->execute() ? "🔄 Contraseña actualizada correctamente." : "❌ Error: " . $stmt->error;
                $stmt->close();
            }
        } else {
            // CREAR NUEVO ACCESO (Permite misma cédula en otra empresa o sucursal)
            if ($Password === '') {
                $msg = "⚠ Debe asignar contraseña para este nuevo acceso.";
            } else {
                $hash = password_hash($Password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO usuarios_Seguridad (CedulaNit, NitEmpresa, NroSucursal, PasswordHash, FechaCreacion, Estado) VALUES (?, ?, ?, ?, NOW(), 1)");
                $stmt->bind_param("ssss", $CedulaNit, $NitEmpresa, $NroSucursal, $hash);
                $msg = $stmt->execute() ? "✅ Acceso creado correctamente." : "❌ Error: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

// DATOS PARA TABLA
$empresas = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado = 1")->fetch_all(MYSQLI_ASSOC);
$usuariosLocal = $mysqli->query("SELECT u.CedulaNit, u.NitEmpresa, u.NroSucursal, u.Estado, e.NombreComercial FROM usuarios_Seguridad u LEFT JOIN empresa e ON u.NitEmpresa = e.Nit")->fetch_all(MYSQLI_ASSOC);

foreach ($usuariosLocal as $k => $u) {
    $stmt = $mysqliPos->prepare("SELECT nombres, apellidos FROM terceros WHERE nit = ? LIMIT 1");
    $stmt->bind_param("s", $u['CedulaNit']);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $usuariosLocal[$k]['NombreCompleto'] = $r ? trim($r['nombres'] . ' ' . $r['apellidos']) : "No encontrado";
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Gestión de Seguridad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.card-header{background:#1a2a3a;color:#fff} .list-group-item:hover{cursor:pointer;background:#f8f9fa}</style>
</head>
<body class="bg-light p-4">

<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= $msg ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>USUARIOS Y ACCESOS POR EMPRESA</strong>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalUsuario">➕ NUEVO ACCESO</button>
        </div>
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>ID</th><th>Nombre</th><th>Empresa/Sucursal</th><th>Estado</th><th>Acción</th></tr>
            </thead>
            <tbody>
                <?php foreach ($usuariosLocal as $u): ?>
                <tr>
                    <td><?= $u['CedulaNit'] ?></td>
                    <td><?= $u['NombreCompleto'] ?></td>
                    <td><?= $u['NombreComercial'] ?> (S-<?= $u['NroSucursal'] ?>)</td>
                    <td><span class="badge <?= $u['Estado']==1?'bg-success':'bg-danger' ?>"><?= $u['Estado']==1?'Activo':'Inactivo' ?></span></td>
                    <td><button class="btn btn-outline-primary btn-sm" onclick="editarUser('<?= $u['CedulaNit'] ?>','<?= $u['NitEmpresa'] ?>','<?= $u['NroSucursal'] ?>')">Editar</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalUsuario">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-dark text-white"><h5>Acceso de Seguridad</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="fw-bold">Buscar Tercero</label>
                <input type="text" id="busquedaTercero" class="form-control" onkeyup="buscarEnCentral(this.value)">
                <ul id="resultadosBusqueda" class="list-group position-absolute w-100 shadow" style="display:none;z-index:999"></ul>
                <input type="hidden" name="CedulaNit" id="CedulaNitModal">
                <div id="terceroSeleccionado" class="small fw-bold text-primary mt-1"></div>
                <hr>
                <select name="NitEmpresa" id="NitEmpresaModal" class="form-select mb-2" onchange="cargarSucursales(this.value)" required>
                    <option value="">Empresa...</option>
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="NroSucursal" id="NroSucursalModal" class="form-select mb-2" required><option value="">Sucursal...</option></select>
                <input type="password" name="Password" class="form-control" placeholder="Contraseña">
                <input type="hidden" name="guardarUsuario" value="1">
            </div>
            <div class="modal-footer"><button class="btn btn-dark w-100 fw-bold">GUARDAR ACCESO</button></div>
        </form>
    </div>
</div>

<script>
function buscarEnCentral(val){
    if(val.length<2){ document.getElementById('resultadosBusqueda').style.display='none'; return; }
    fetch('?ajax=usuarios&term='+val).then(r=>r.json()).then(d=>{
        let ul=document.getElementById('resultadosBusqueda'); ul.innerHTML='';
        d.forEach(i=>{
            let li=document.createElement('li'); li.className='list-group-item small'; li.innerHTML=`<strong>${i.nit}</strong> - ${i.nombre}`;
            li.onclick=()=>{
                document.getElementById('CedulaNitModal').value=i.nit;
                document.getElementById('busquedaTercero').value=i.nit;
                document.getElementById('terceroSeleccionado').innerText='Seleccionado: '+i.nombre;
                ul.style.display='none';
            };
            ul.appendChild(li);
        });
        ul.style.display='block';
    });
}
function cargarSucursales(nit, preseleccionar = ''){
    fetch('?ajax=sucursales&nit='+nit).then(r=>r.json()).then(d=>{
        let s=document.getElementById('NroSucursalModal');
        s.innerHTML='<option value="">Sucursal...</option>';
        d.forEach(i=>{
            let sel = (i.NroSucursal == preseleccionar) ? 'selected' : '';
            s.innerHTML+=`<option value="${i.NroSucursal}" ${sel}>${i.Direccion}</option>`;
        });
    });
}
function editarUser(nit, nitEmpresa, sucursal){
    document.getElementById('busquedaTercero').value = nit;
    document.getElementById('CedulaNitModal').value = nit;
    document.getElementById('NitEmpresaModal').value = nitEmpresa;
    cargarSucursales(nitEmpresa, sucursal);
    document.getElementById('terceroSeleccionado').innerText = 'Editando acceso: ' + nit;
    new bootstrap.Modal(document.getElementById('modalUsuario')).show();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>