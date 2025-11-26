<?php
require 'Conexion.php';
$mensaje = '';

// ================================
// FILTROS DE BÚSQUEDA
// ================================
$empresaFilter = $_GET['empresa'] ?? '';
$sucursalFilter = $_GET['sucursal'] ?? '';
$estadoFilter = $_GET['estado'] ?? '';

// Construir WHERE dinámico
$where = [];
if ($empresaFilter) $where[] = "NitEmpresa = '".$mysqli->real_escape_string($empresaFilter)."'";
if ($sucursalFilter) $where[] = "NroSucursal = '".$mysqli->real_escape_string($sucursalFilter)."'";
if ($estadoFilter !== '') {
    if ($estadoFilter == 'activo') $where[] = "Estado = 1";
    if ($estadoFilter == 'inactivo') $where[] = "Estado = 0";
    if ($estadoFilter == 'bloqueado') $where[] = "Bloqueado = 1";
}
$whereSQL = $where ? "WHERE ".implode(' AND ', $where) : "";

// ================================
// ACCIONES: REGISTRAR / BLOQUEAR / DESBLOQUEAR / FORZAR CAMBIO / EDITAR / CAMBIAR CONTRASEÑA / CAMBIAR ESTADO
// ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Registro
    if (isset($_POST['registrar'])) {
        $cedula      = trim($_POST['cedula'] ?? '');
        $nitEmpresa  = trim($_POST['nit_empresa'] ?? '');
        $nroSucursal = trim($_POST['nro_sucursal'] ?? '');
        $password    = $_POST['password'] ?? '';

        if ($cedula && $nitEmpresa && $nroSucursal && $password) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $cedula = $mysqli->real_escape_string($cedula);
            $nitEmpresa = $mysqli->real_escape_string($nitEmpresa);
            $nroSucursal = $mysqli->real_escape_string($nroSucursal);

            $sql = "INSERT INTO usuarios_Seguridad 
                    (CedulaNit, NitEmpresa, NroSucursal, PasswordHash) 
                    VALUES ('$cedula','$nitEmpresa','$nroSucursal','$passwordHash')";
            if ($mysqli->query($sql)) $mensaje = "Usuario registrado correctamente.";
            else $mensaje = ($mysqli->errno===1062)?"El usuario ya existe.":"Error: ".$mysqli->error;
        } else $mensaje = "Todos los campos son obligatorios.";
    }

    // Editar usuario
    if (isset($_POST['editar'])) {
        $cedula = $mysqli->real_escape_string($_POST['cedula']);
        $nitEmpresa = $mysqli->real_escape_string($_POST['nit_empresa']);
        $nroSucursal = $mysqli->real_escape_string($_POST['nro_sucursal']);
        $nuevoSucursal = $mysqli->real_escape_string($_POST['nuevo_sucursal']);

        $mysqli->query("UPDATE usuarios_Seguridad 
                        SET NroSucursal='$nuevoSucursal' 
                        WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'");
        $mensaje = "Usuario actualizado.";
    }

    // Cambiar contraseña
    if (isset($_POST['cambiar_clave'])) {
        $cedula = $mysqli->real_escape_string($_POST['cedula']);
        $nitEmpresa = $mysqli->real_escape_string($_POST['nit_empresa']);
        $nroSucursal = $mysqli->real_escape_string($_POST['nro_sucursal']);
        $password = $_POST['password'];
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $mysqli->query("UPDATE usuarios_Seguridad SET PasswordHash='$passwordHash', DebeCambiarClave=0 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'");
        $mensaje = "Contraseña actualizada correctamente.";
    }
}

// Acciones rápidas por GET
if (isset($_GET['accion']) && isset($_GET['cedula']) && isset($_GET['empresa']) && isset($_GET['sucursal'])) {
    $cedula = $mysqli->real_escape_string($_GET['cedula']);
    $nitEmpresa = $mysqli->real_escape_string($_GET['empresa']);
    $nroSucursal = $mysqli->real_escape_string($_GET['sucursal']);
    $accion = $_GET['accion'];

    switch($accion){
        case 'bloquear': $mysqli->query("UPDATE usuarios_Seguridad SET Bloqueado=1 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'"); break;
        case 'desbloquear': $mysqli->query("UPDATE usuarios_Seguridad SET Bloqueado=0,IntentosFallidos=0 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'"); break;
        case 'forzar_cambio': $mysqli->query("UPDATE usuarios_Seguridad SET DebeCambiarClave=1 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'"); break;
        case 'activar': $mysqli->query("UPDATE usuarios_Seguridad SET Estado=1 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'"); break;
        case 'desactivar': $mysqli->query("UPDATE usuarios_Seguridad SET Estado=0 WHERE CedulaNit='$cedula' AND NitEmpresa='$nitEmpresa' AND NroSucursal='$nroSucursal'"); break;
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit; // Recargar para ver cambios
}

// ================================
// LISTADO DE USUARIOS
// ================================
$result = $mysqli->query("SELECT * FROM usuarios_Seguridad $whereSQL ORDER BY FechaCreacion DESC");
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel Avanzado de Usuarios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
<h2>Panel Avanzado de Usuarios</h2>

<?php if($mensaje): ?>
<div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<!-- FILTROS -->
<div class="card mb-4">
<div class="card-header">Filtrar Usuarios</div>
<div class="card-body">
<form method="get" class="row g-2">
    <div class="col">
        <input type="text" name="empresa" placeholder="NIT Empresa" value="<?= htmlspecialchars($empresaFilter) ?>" class="form-control">
    </div>
    <div class="col">
        <input type="text" name="sucursal" placeholder="Sucursal" value="<?= htmlspecialchars($sucursalFilter) ?>" class="form-control">
    </div>
    <div class="col">
        <select name="estado" class="form-control">
            <option value="">Todos</option>
            <option value="activo" <?= $estadoFilter==='activo'?'selected':'' ?>>Activo</option>
            <option value="inactivo" <?= $estadoFilter==='inactivo'?'selected':'' ?>>Inactivo</option>
            <option value="bloqueado" <?= $estadoFilter==='bloqueado'?'selected':'' ?>>Bloqueado</option>
        </select>
    </div>
    <div class="col">
        <button class="btn btn-primary">Filtrar</button>
    </div>
</form>
</div>
</div>

<!-- FORMULARIO REGISTRO -->
<div class="card mb-4">
<div class="card-header">Registrar Nuevo Usuario</div>
<div class="card-body">
<form method="post">
<input type="hidden" name="registrar" value="1">
<div class="row mb-3">
<div class="col"><input type="text" name="cedula" placeholder="Cédula / NIT" class="form-control" maxlength="15" required></div>
<div class="col"><input type="text" name="nit_empresa" placeholder="NIT Empresa" class="form-control" maxlength="15" required></div>
<div class="col"><input type="text" name="nro_sucursal" placeholder="Sucursal" class="form-control" maxlength="2" required></div>
</div>
<div class="mb-3"><input type="password" name="password" placeholder="Contraseña" class="form-control" required></div>
<button class="btn btn-primary">Registrar</button>
</form>
</div>
</div>

<!-- LISTADO DE USUARIOS -->
<div class="card">
<div class="card-header">Usuarios Registrados</div>
<div class="card-body table-responsive">
<table class="table table-bordered table-striped">
<thead>
<tr>
<th>Cédula</th><th>NIT Empresa</th><th>Sucursal</th><th>Bloqueado</th><th>Debe Cambiar Clave</th><th>Estado</th><th>Fecha Creación</th><th>Acciones</th>
</tr>
</thead>
<tbody>
<?php while($row = $result->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($row['CedulaNit']) ?></td>
<td><?= htmlspecialchars($row['NitEmpresa']) ?></td>
<td><?= htmlspecialchars($row['NroSucursal']) ?></td>
<td><?= $row['Bloqueado'] ? 'Sí' : 'No' ?></td>
<td><?= $row['DebeCambiarClave'] ? 'Sí' : 'No' ?></td>
<td><?= $row['Estado'] ? 'Activo' : 'Inactivo' ?></td>
<td><?= $row['FechaCreacion'] ?></td>
<td>
<!-- Acciones rápidas -->
<?php if(!$row['Bloqueado']): ?>
<a href="?accion=bloquear&cedula=<?= $row['CedulaNit'] ?>&empresa=<?= $row['NitEmpresa'] ?>&sucursal=<?= $row['NroSucursal'] ?>" class="btn btn-sm btn-warning">Bloquear</a>
<?php else: ?>
<a href="?accion=desbloquear&cedula=<?= $row['CedulaNit'] ?>&empresa=<?= $row['NitEmpresa'] ?>&sucursal=<?= $row['NroSucursal'] ?>" class="btn btn-sm btn-success">Desbloquear</a>
<?php endif; ?>
<a href="?accion=forzar_cambio&cedula=<?= $row['CedulaNit'] ?>&empresa=<?= $row['NitEmpresa'] ?>&sucursal=<?= $row['NroSucursal'] ?>" class="btn btn-sm btn-info">Forzar Cambio</a>
<?php if($row['Estado']): ?>
<a href="?accion=desactivar&cedula=<?= $row['CedulaNit'] ?>&empresa=<?= $row['NitEmpresa'] ?>&sucursal=<?= $row['NroSucursal'] ?>" class="btn btn-sm btn-secondary">Desactivar</a>
<?php else: ?>
<a href="?accion=activar&cedula=<?= $row['CedulaNit'] ?>&empresa=<?= $row['NitEmpresa'] ?>&sucursal=<?= $row['NroSucursal'] ?>" class="btn btn-sm btn-primary">Activar</a>
<?php endif; ?>
<!-- Editar / Cambiar contraseña -->
<form method="post" style="display:inline-block;margin-top:2px;">
<input type="hidden" name="editar" value="1">
<input type="hidden" name="cedula" value="<?= $row['CedulaNit'] ?>">
<input type="hidden" name="nit_empresa" value="<?= $row['NitEmpresa'] ?>">
<input type="hidden" name="nro_sucursal" value="<?= $row['NroSucursal'] ?>">
<input type="text" name="nuevo_sucursal" placeholder="Nueva sucursal" class="form-control form-control-sm mb-1">
<button class="btn btn-sm btn-outline-primary">Actualizar</button>
</form>
<form method="post" style="display:inline-block;margin-top:2px;">
<input type="hidden" name="cambiar_clave" value="1">
<input type="hidden" name="cedula" value="<?= $row['CedulaNit'] ?>">
<input type="hidden" name="nit_empresa" value="<?= $row['NitEmpresa'] ?>">
<input type="hidden" name="nro_sucursal" value="<?= $row['NroSucursal'] ?>">
<input type="password" name="password" placeholder="Nueva contraseña" class="form-control form-control-sm mb-1">
<button class="btn btn-sm btn-outline-success">Cambiar Clave</button>
</form>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>
</body>
</html>
