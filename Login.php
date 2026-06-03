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
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { 
    background-color: #f8f9fa;
    min-height: 100vh;
}
.login-row {
    min-height: 100vh;
}
/* Panel lateral adaptativo con la foto de tu bodega */
.brand-panel {
    background: linear-gradient(135deg, rgba(15, 32, 67, 0.92) 0%, rgba(28, 56, 121, 0.88) 100%), 
                url('bodega.jpg') no-repeat center center;
    background-size: cover;
    color: white;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 2rem 3rem;
}
@media (min-width: 992px) {
    .brand-panel { padding: 4rem; }
}
/* Logos aliados en el panel lateral */
.partner-logos img {
    height: 60px;
    object-fit: contain;
    background: white;
    padding: 6px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.partner-logos img:hover {
    transform: scale(1.05);
}
/* Estilizado e iconos de inputs */
.input-group-text {
    background-color: #f8f9fa;
    color: #6c757d;
    border-right: none;
}
.form-control, .form-select {
    border-left: none;
}
.input-group:focus-within .input-group-text {
    border-color: #1c3879;
    color: #1c3879;
}
.form-control:focus, .form-select:focus {
    border-color: #1c3879;
    box-shadow: none;
}
.btn-primary {
    background-color: #1c3879;
    border-color: #1c3879;
}
.btn-primary:hover {
    background-color: #0f2043;
    border-color: #0f2043;
}
#logoDinamico {
    transition: all 0.3s ease-in-out;
    max-height: 75px;
}
</style>

<script>
function cargarSucursales() {
    let selectEmpresa = document.getElementById("NitEmpresa");
    let nit = selectEmpresa.value;
    let imgLogo = document.getElementById("logoDinamico");
    
    if (nit === "") {
        document.getElementById("NroSucursal").innerHTML = "<option value=''>Seleccione una empresa primero</option>";
        imgLogo.src = "LogoDBC.png"; 
        return;
    }

    // Intercambio dinámico de logo en desktop según selección
    let textoEmpresa = selectEmpresa.options[selectEmpresa.selectedIndex].text.toLowerCase();
    if(textoEmpresa.includes("drinks")) {
        imgLogo.src = "logoDrinks.png";
    } else {
        imgLogo.src = "LogoDBC.png";
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
            <div style="max-width: 550px;" class="w-100">
                <span class="badge bg-warning text-dark mb-4 fw-bold px-3 py-2 text-uppercase tracking-wider">Infraestructura Logística</span>
                
                <div id="carouselPublicidad" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-inner">
                        <div class="carousel-item active" data-bs-interval="5000">
                            <h1 class="display-5 fw-bold mb-3 text-white">Todo en Bebidas y Confitería</h1>
                            <p class="lead text-white-50 mb-5">Abastece tu negocio de forma rápida, segura y con un soporte corporativo diseñado para crecer contigo.</p>
                        </div>
                        <div class="carousel-item" data-bs-interval="5000">
                            <h1 class="display-5 fw-bold mb-3 text-warning">Grandes Volúmenes, Listos</h1>
                            <p class="lead text-white-50 mb-5">Operamos desde nuestro propio complejo logístico. Garantizamos stock permanente y despachos inmediatos a cada una de tus sucursales.</p>
                        </div>
                        <div class="carousel-item" data-bs-interval="5000">
                            <h1 class="display-5 fw-bold mb-3 text-white">Gestión Integrada de Egresos</h1>
                            <p class="lead text-white-50 mb-5">Controla las finanzas de tus empresas asociadas de manera centralizada. Ahora puedes gestionar las sesiones de egresos al instante.</p>
                        </div>
                    </div>
                </div>
                
                <div class="pt-4 border-top border-secondary border-opacity-50 mt-4">
                    <p class="small text-uppercase fw-bold text-warning mb-3">Marcas Distribuidas Oficiales</p>
                    <div class="d-flex gap-3 partner-logos">
                        <img src="LogoDBC.png" alt="Distribuidora de Bebidas Central">
                        <img src="logoDrinks.png" alt="Drinks Depot">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-5 d-flex align-items-center justify-content-center bg-white py-4 py-md-5">
            <div class="w-100 px-3 px-sm-4 px-xl-5" style="max-width: 460px;">
                
                <div class="text-center mb-4">
                    <div class="mb-3">
                        <img id="logoDinamico" src="LogoDBC.png" height="75" class="img-fluid object-fit-contain d-none d-md-inline-block" alt="Logo Corporativo">
                        
                        <div class="d-flex justify-content-center gap-2 d-md-none">
                            <img src="LogoDBC.png" height="45" class="rounded border bg-white p-1" alt="Logo DBC">
                            <img src="logoDrinks.png" height="45" class="rounded border bg-white p-1" alt="Logo Drinks">
                        </div>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">¡Bienvenido al Portal!</h4>
                    <p class="text-muted small">Selecciona tu empresa e introduce tus datos de acceso</p>
                </div>

                <?php if ($msg != ""): ?>
                    <div class="alert alert-danger text-center py-2 small">
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="Validar.php">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Cédula / NIT Usuario</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                            <input type="text" name="CedulaNit" class="form-control form-control-lg fs-6" placeholder="Ej. 12345678" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Empresa</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <select name="NitEmpresa" id="NitEmpresa" class="form-select form-select-lg fs-6" onchange="cargarSucursales()" required>
                                <option value="">Seleccione la empresa</option>
                                <?php while ($e = $empresas->fetch_assoc()): ?>
                                    <option value="<?= $e['Nit'] ?>">
                                        <?= $e['Nit'] ?> - <?= $e['RazonSocial'] ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Sucursal</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                            <select name="NroSucursal" id="NroSucursal" class="form-select form-select-lg fs-6" required>
                                <option value="">Seleccione una empresa primero</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold text-secondary mb-0">Contraseña</label>
                            <a href="#" class="text-decoration-none small text-muted">¿La olvidaste?</a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="Password" class="form-control form-control-lg fs-6" placeholder="••••••••" required>
                        </div>
                    </div>

                    <button class="btn btn-primary btn-lg w-100 fs-6 fw-semibold shadow-sm py-2">Entrar al Sistema</button>
                </form>

                <div class="text-center mt-5">
                    <p class="small text-muted mb-0">¿Inconvenientes técnico-operativos? <a href="#" class="text-decoration-none fw-semibold" style="color: #1c3879;">Centro de Soporte</a></p>
                </div>

            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>