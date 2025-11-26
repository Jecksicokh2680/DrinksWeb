<?php
// =====================================================
// CREAR USUARIOS – FORMULARIO Y PROCESAMIENTO
// =====================================================
session_start();
require 'Conexion.php';

// -----------------------------------------------------
// SI SE ENVÍA EL FORMULARIO
// -----------------------------------------------------
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $CedulaNit    = trim($_POST['CedulaNit']);
    $NitEmpresa   = trim($_POST['NitEmpresa']);
    $NroSucursal  = trim($_POST['NroSucursal']);
    $Password     = trim($_POST['Password']);

    if ($CedulaNit == "" || $NitEmpresa == "" || $NroSucursal == "" || $Password == "") {
        $msg = "⚠ Todos los campos son obligatorios.";
    } else {

        // Encriptar contraseña
        $PasswordHash = password_hash($Password, PASSWORD_DEFAULT);

        // Consulta preparada
        $sql = "INSERT INTO usuarios_Seguridad 
                (CedulaNit, NitEmpresa, NroSucursal, PasswordHash, FechaCreacion, Estado) 
                VALUES (?, ?, ?, ?, NOW(), 1)";

        $stm = $mysqli->prepare($sql);
        $stm->bind_param("ssss", $CedulaNit, $NitEmpresa, $NroSucursal, $PasswordHash);

        if ($stm->execute()) {
            $msg = "✅ Usuario creado correctamente.";
        } else {
            $msg = "❌ Error: " . $stm->error;
        }

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
</head>

<body class="bg-light">

<div class="container mt-5">
    <div class="col-md-6 offset-md-3">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Registrar Usuario</h4>
            </div>
            <div class="card-body">

                <?php if ($msg != ""): ?>
                    <div class="alert alert-info"><?php echo $msg; ?></div>
                <?php endif; ?>

                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label">Cédula / NIT Usuario</label>
                        <input type="text" name="CedulaNit" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">NIT Empresa</label>
                        <input type="text" name="NitEmpresa" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Número de Sucursal</label>
                        <input type="text" name="NroSucursal" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="Password" class="form-control" required>
                    </div>

                    <button class="btn btn-success w-100">Crear Usuario</button>

                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
