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
<title>Drinks Depot - Distribuidora de Bebidas Central | Iniciar Sesión </title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<style>
/* Estilos Globales Basados en el Ejemplo */
html, body { 
    background-color: #f8f9fa;
    height: 100vh;
    font-family: 'Inter', sans-serif; /* Usamos una fuente sans-serif limpia */
    overflow: hidden; 
}
.container-fluid, .login-row {
    height: 100vh;
}

/* Panel Derecho (Contenido Promocional y de Pago) */
.brand-panel {
    background-color: #fcfcfc; /* Un fondo claro como en el ejemplo */
    color: #333;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 2rem 3rem;
    height: 100vh;
}
@media (min-width: 992px) {
    .brand-panel { padding: 4rem; }
}

/* Imagen de pago Bre-B y la información promocional, reemplaza a partner-logos y product-card-preview */
.payment-info-img {
    max-width: 100%;
    height: auto;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

/* Texto promocional Bre-B al estilo del ejemplo */
.promo-title {
    color: #1c3879; /* Usamos un color corporativo azul profundo similar al ejemplo */
    font-weight: 700;
    font-size: 2.5rem;
    line-height: 1.2;
    margin-bottom: 1rem;
}
.promo-subtitle {
    color: #555;
    font-weight: 500;
    font-size: 1.2rem;
    margin-bottom: 2rem;
}
.promo-text {
    color: #777;
    font-weight: 400;
    font-size: 1rem;
    line-height: 1.6;
}
.promo-key-info {
    color: #1c3879; /* Usamos un color corporativo azul profundo similar al ejemplo */
    font-weight: 600;
    font-size: 1rem;
}

/* Panel Izquierdo (Formulario de Login) */
.col-form-container {
    height: 100vh;
    overflow-y: auto; 
    background-color: #ffffff; /* Fondo blanco para el formulario */
}
/* Oculta la barra de scroll visualmente en navegadores */
.col-form-container::-webkit-scrollbar {
    display: none;
}
.col-form-container {
    -ms-overflow-style: none;  
    scrollbar-width: none;  
}

/* Estilizado de inputs y componentes del formulario */
.input-group {
    background-color: #ffffff;
    border: 1px solid #ddd; /* Bordes claros como en el ejemplo */
    border-radius: 8px;
    overflow: hidden;
}
.input-group-text {
    background-color: transparent; /* Fondo transparente para los iconos */
    color: #6c757d;
    border: none;
    padding-left: 1rem;
}
.form-control, .form-select {
    border: none;
    font-size: 1rem;
    padding: 0.75rem 1rem;
}
.form-control:focus, .form-select:focus {
    border: none;
    box-shadow: none;
    background-color: transparent;
}
.input-group:focus-within {
    border-color: #1c3879; /* Borde azul corporativo similar al ejemplo al enfocar */
    box-shadow: 0 0 0 0.2rem rgba(28, 56, 121, 0.25);
}

/* Botón Sign In estilizado */
.btn-primary {
    background-color: #111; /* Negro como en el ejemplo */
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    padding: 0.75rem 2rem;
}
.btn-primary:hover {
    background-color: #333;
}

/* Logo Corporativo Estilizado */
#logoDinamico {
    transition: all 0.3s ease-in-out;
    max-height: 60px; /* Redimensionado para mayor nitidez */
    width: auto;
}

/* Estilo para los mensajes de error al estilo del ejemplo */
.alert-danger {
    background-color: transparent;
    color: #dc3545;
    border: none;
    font-weight: 500;
    font-size: 0.9rem;
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
        
        <div class="col-12 col-md-6 col-lg-5 d-flex align-items-center justify-content-center py-4 py-md-5 col-form-container">
            <div class="w-100 px-3 px-sm-4 px-xl-5" style="max-width: 460px;">

                <div class="text-start mb-5">
                    <div class="mb-4">
                        <img id="logoDinamico" src="LogoDBC.png" height="60" class="img-fluid object-fit-contain d-inline-block" alt="Logo Corporativo">
                    </div>
                    <h1 class="fw-bold text-dark promo-title mb-1">Welcome back</h1>
                    <p class="text-muted promo-subtitle mb-4">Please enter your details</p>
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
                            <input type="text" name="CedulaNit" class="form-control" placeholder="Ej. 12345678" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Empresa</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
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
                            <select name="NroSucursal" id="NroSucursal" class="form-select" required>
                                <option value="">Seleccione una empresa primero</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4 promo-subtitle">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold text-secondary mb-0 promo-text">Contraseña</label>
                            <a href="#" class="text-decoration-none small text-muted promo-text">Forgot password</a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="Password" class="form-control" placeholder="••••••••" required>
                        </div>
                    </div>

                    <button class="btn btn-primary w-100 py-3 mt-4 promo-key-info">Sign in</button>
                </form>

                <div class="text-center mt-5">
                    <p class="small text-muted mb-0 promo-subtitle">Don't have an account? <a href="#" class="text-decoration-none fw-semibold promo-key-info">Sign up</a></p>
                </div>

            </div>
        </div>

        <div class="col-md-6 col-lg-7 d-none d-md-flex brand-panel promo-text">
            <div style="max-width: 550px;" class="w-100">
                
                <img src="ment_promo_breb.png" alt="Información de Pago Bre-B" class="payment-info-img">
                
                

            </div>
        </div>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>