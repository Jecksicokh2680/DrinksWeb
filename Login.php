<?php
require 'helpers.php';
if (estaLogueado()) {
    header("Location: panel.php");
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
        <div class="col-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Login usuarios</h4>
                    <?php if($msg): ?>
                        <div class="alert alert-danger"><?= $msg ?></div>
                    <?php endif; ?>
                    <form action="validar_login.php" method="post">
                        <div class="mb-3">
                            <label for="usuario" class="form-label">Usuario</label>
                            <input type="text" name="usuario" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
