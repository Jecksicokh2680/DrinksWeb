<?php
session_start();
require 'Conexion.php';

// Redirige si ya está logueado
if (!empty($_SESSION['Usuario'])) {
    header('Location: Panel.php');
    exit;
}

// Mensaje enviado por validar.php
$msg = $_GET['msg'] ?? "";

// Cargar empresas activas
$empresas = $mysqli->query("SELECT Nit, RazonSocial 
                            FROM empresa 
                            WHERE Estado = 1 
                            ORDER BY RazonSocial");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background: #f5f5f5; }
.card { border-radius: 10px; }
</style>

<script>
// ======================================================
// Cargar sucursales vía AJAX SIN ARCHIVOS EXTERNOS
// ======================================================
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;

    if (nit === "") {
        document.getElementById("NroSucursal").innerHTML =
            "<option value=''>Seleccione una empresa primero</option>";
        return;
    }

    // Petición AJAX dentro del mismo archivo
    fetch("Login.php?loadSucursales=1&nit=" + nit)
        .then(res => res.text())
        .then(data => {
            document.getElementById("NroSucursal").innerHTML = data;
        });
}
</script>

</head>
<body>

<?php
// ======================================================
// Cargar sucursales si AJAX lo solicita
// ======================================================
if (isset($_GET['loadSucursales'])) {

    $nit = $_GET['nit'] ?? '';

    if ($nit != "") {

        $sql = "SELECT NroSucursal, Ciudad 
                FROM empresa_sucursal 
                WHERE Nit=? AND Estado=1 
                ORDER BY NroSucursal";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $nit);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo "<option value=''>No hay sucursales activas</option>";
        } else {
            while ($row = $result->fetch_assoc()) {
                echo "<option value='".$row['NroSucursal']."'>
                        ".$row['NroSucursal']." - ".$row['Ciudad']."
                      </option>";
            }
        }
    }
    exit; // No continuar con la página completa
}
?>

<div class="container mt-5">
    <div class="col-md-4 offset-md-4">
        <div class="card shadow">

            <div class="card-header bg-primary text-white text-center">
                <h4 class="mb-0">Inicio de Sesión</h4>
            </div>

            <div class="card-body">

                <?php if ($msg != ""): ?>
                    <div class="alert alert-warning text-center">
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="Validar.php">

                    <!-- Usuario -->
                    <div class="mb-3">
                        <label class="form-label">Cédula / NIT Usuario</label>
                        <input type="text" name="CedulaNit" class="form-control" required>
                    </div>

                    <!-- Empresa -->
                    <div class="mb-3">
                        <label class="form-label">Empresa</label>
                        <select name="NitEmpresa" id="NitEmpresa"
                                class="form-select" onchange="cargarSucursales()" required>
                            <option value="">Seleccione la empresa</option>

                            <?php while ($e = $empresas->fetch_assoc()): ?>
                                <option value="<?= $e['Nit'] ?>">
                                    <?= $e['Nit'] ?> - <?= $e['RazonSocial'] ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <!-- Sucursal -->
                    <div class="mb-3">
                        <label class="form-label">Sucursal</label>
                        <select name="NroSucursal" id="NroSucursal" class="form-select" required>
                            <option value="">Seleccione una empresa primero</option>
                        </select>
                    </div>

                    <!-- Contraseña -->
                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="Password" class="form-control" required>
                    </div>

                    <!-- Botón -->
                    <button class="btn btn-primary w-100">Ingresar</button>

                </form>

            </div>
        </div>
    </div>
</div>

</body>
</html>
