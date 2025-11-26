<?php
require 'helpers.php';
if (!empty($_SESSION['Usuario'])) {
    header('Location: panel.php');
    exit;
}
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card p-3 shadow-sm">
                <h4 class="text-center mb-3">Login</h4>
                <?php if($msg): ?>
                    <div class="alert alert-warning"><?= $msg ?></div>
                <?php endif; ?>
                <form method="post" action="validar.php">
                    <div class="mb-2">
                        <input type="text" name="CedulaNit" class="form-control" placeholder="Cédula/NIT" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="NitEmpresa" class="form-control" placeholder="NIT Empresa" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="NroSucursal" class="form-control" placeholder="Sucursal" required>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="Password" class="form-control" placeholder="Contraseña" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
