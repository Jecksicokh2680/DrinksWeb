<?php
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
<title>Iniciar Sesión | Portal de Distribución</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { 
    background-color: #f8f9fa;
    min-height: 100vh;
}
.login-row {
    min-height: 100vh;
}
/* Panel lateral publicitario e institucional */
.brand-panel {
    background: linear-gradient(135deg, rgba(15, 32, 67, 0.95) 0%, rgba(28, 56, 121, 0.9) 100%), 
                url('https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?q=80&w=1200') no-repeat center center;
    background-size: cover;
    color: white;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 4rem;
}
/* Contenedor de logos aliados en el panel lateral */
.partner-logos img {
    height: 70px;
    object-fit: contain;
    background: white;
    padding: 8px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.partner-logos img:hover {
    transform: scale(1.05);
}
/* Estilizado de inputs */
.form-control:focus, .form-select:focus {
    border-color: #1c3879;
    box-shadow: 0 0 0 0.25rem rgba(28, 56, 121, 0.25);
}
.btn-primary {
    background-color: #1c3879;
    border-color: #1c3879;
}
.btn-primary:hover {
    background-color: #0f2043;
    border-color: #0f2043;
}
</style>

<script>
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    if (nit === "") {
        document.getElementById("NroSucursal").innerHTML =
            "<option value=''>Seleccione una empresa primero</option>";
        return;
    }
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
if (isset($_GET['loadSucursales'])) {
    $nit = $_GET['nit'] ?? '';
    if ($nit != "") {
        $sql = "SELECT NroSucursal, Direccion FROM empresa_sucursal WHERE Nit=? AND Estado=1 ORDER BY NroSucursal";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $nit);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo "<option value=''>No hay sucursales activas</option>";
        } else {
            while ($row = $result->fetch_assoc()) {
                echo "<option value='".$row['NroSucursal']."'>".$row['NroSucursal']." - ".$row['Direccion']."</option>";
            }
        }
    }
    exit;
}
?>

<div class="container-fluid">
    <div class="row login-row">
        
        <div class="col-md-6 col-lg-7 d-none d-md-flex brand-panel">
            <div style="max-width: 550px;">
                <span class="badge bg-warning text-dark mb-3 fw-bold px-3 py-2 text-uppercase tracking-wider">Portal Aliados</span>
                <h1 class="display-5 fw-bold mb-3 text-white">Todo en Bebidas y Confitería en un solo lugar</h1>
                <p class="lead text-white-50 mb-5">Abastece tu negocio de forma rápida, segura y con soporte personalizado las **24 horas**.</p>
                
                <div class="pt-4 border-top border-secondary border-opacity-50">
                    <p class="small text-uppercase fw-bold text-warning mb-3">Nuestras Marcas Principales</p>
                    <div class="d-flex gap-3 partner-logos">
                        <img src="LogoDBC.jpg" alt="Distribuidora de Bebidas Central">
                        <img src="logoDrinks.jpg" alt="Drinks Depot">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-5 d-flex align-items-center justify-content-center bg-white py-5">
            <div class="w-100 px-4 px-xl-5" style="max-width: 460px;">
                
                <div class="text-center mb-4">
                    <div class="d-flex justify-content-center gap-2 mb-3 d-md-none">
                        <img src="LogoDBC.png" height="50" class="rounded border p-1" alt="Logo">
                        <img src="logoDrinks.png" height="50" class="rounded border p-1" alt="Logo">
                    </div>
                    <h3 class="fw-bold text-dark mb-1">¡Bienvenido de nuevo!</h3>
                    <p class="text-muted small">Ingresa tus credenciales para acceder a la plataforma</p>
                </div>

                <?php if ($msg != ""): ?>
                    <div class="alert alert-danger text-center py-2 small">
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="Validar.php">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Cédula / NIT Usuario</label>
                        <input type="text" name="CedulaNit" class="form-control form-control-lg fs-6" placeholder="Ej. 12345678" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Empresa</label>
                        <select name="NitEmpresa" id="NitEmpresa" class="form-select form-select-lg fs-6" onchange="cargarSucursales()" required>
                            <option value="">Seleccione la empresa</option>
                            <?php while ($e = $empresas->fetch_assoc()): ?>
                                <option value="<?= $e['Nit'] ?>">
                                    <?= $e['Nit'] ?> - <?= $e['RazonSocial'] ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Sucursal</label>
                        <select name="NroSucursal" id="NroSucursal" class="form-select form-select-lg fs-6" required>
                            <option value="">Seleccione una empresa primero</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold text-secondary mb-0">Contraseña</label>
                            <a href="#" class="text-decoration-none small text-muted">¿La olvidaste?</a>
                        </div>
                        <input type="password" name="Password" class="form-control form-control-lg fs-6" placeholder="••••••••" required>
                    </div>

                    <button class="btn btn-primary btn-lg w-100 fs-6 fw-semibold shadow-sm py-25">Entrar al Sistema</button>
                </form>

                <div class="text-center mt-5">
                    <p class="small text-muted mb-0">¿Problemas para ingresar? <a href="#" class="text-decoration-none fw-semibold" style="color: #1c3879;">Centro de Soporte</a></p>
                </div>

            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>