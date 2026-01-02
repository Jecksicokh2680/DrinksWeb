<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$UsuarioSesion = $_SESSION['Usuario'];

/* ============================================
   FUNCIÓN AUTORIZACIÓN
============================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("
        SELECT Swich 
        FROM autorizacion_tercero 
        WHERE CedulaNit = ? AND Nro_Auto = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res && $res->num_rows > 0) ? $res->fetch_assoc()['Swich'] : "NO";
}

/* ============================================
   VALIDAR ADMIN
============================================ */
$EsAdmin = (Autorizacion($UsuarioSesion, '0001') === "SI");
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Usuario</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    margin: 0;
    display: flex;
    min-height: 100vh;
    font-family: Arial, sans-serif;
}
.sidebar {
    width: 250px;
    background-color: #0d6efd;
    color: white;
    display: flex;
    flex-direction: column;
}
.sidebar .nav-link {
    color: white;
    padding: 6px 12px;
    font-size: 14px;
}
.sidebar .nav-link:hover {
    background-color: #084298;
}
.sidebar .navbar-brand {
    padding: 1rem;
    font-weight: bold;
}
.content-frame {
    flex-grow: 1;
    border: none;
    width: 100%;
    height: 100vh;
}
.accordion-button {
    padding: 6px 12px;
    font-size: 14px;
}
.accordion-button:not(.collapsed) {
    background-color: #084298;
    color: white;
}
.accordion-item {
    background: transparent;
    border: none;
}
.accordion-body {
    padding: 0;
}
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        left: -260px;
        height: 100%;
        z-index: 999;
        transition: left 0.3s;
    }
    .sidebar.show {
        left: 0;
    }
}
</style>
</head>

<body>

<!-- ================= SIDEBAR ================= -->
<div class="sidebar" id="sidebar">

    <div class="navbar-brand text-white text-center">
        Mi App
    </div>

    <div class="accordion accordion-flush px-2" id="menuAccordion">

        <!-- ================= SIN AUTORIZACIÓN ================= -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed text-white bg-primary"
                        type="button" data-bs-toggle="collapse"
                        data-bs-target="#menuBasico">
                    🔓 Opciones básicas
                </button>
            </h2>
            <div id="menuBasico" class="accordion-collapse collapse show">
                <div class="accordion-body">
                    <a class="nav-link" href="Transfers.php" target="contentFrame">➕ Transferencia</a>
                    <a class="nav-link" href="Calculadora.php" target="contentFrame">📄 Calculadora</a>
                    <a class="nav-link" href="Conteo.php" target="contentFrame">🧮 Conteo Web</a>
                </div>
            </div>
        </div>

        <!-- ================= SOLO ADMIN ================= -->
        <?php if ($EsAdmin): ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed text-white bg-primary"
                        type="button" data-bs-toggle="collapse"
                        data-bs-target="#menuAdmin">
                    🔐 Administración
                </button>
            </h2>
            <div id="menuAdmin" class="accordion-collapse collapse">
                <div class="accordion-body">

                    <!-- SUBGRUPO: OPERACIÓN -->
                    <strong class="text-white px-2 d-block mt-2">📊 Operación</strong>
                    <a class="nav-link" href="ResumenVtas.php" target="contentFrame">Ventas BNMA</a>
                    <a class="nav-link" href="Compras.php" target="contentFrame">Compras BNMA</a>
                    <a class="nav-link" href="TransferDiaDia.php" target="contentFrame">Transfers Día</a>
                    <a class="nav-link" href="ListaFactDia.php" target="contentFrame">Facturas del Día</a>

                    <!-- SUBGRUPO: INVENTARIO -->
                    <strong class="text-white px-2 d-block mt-2">📦 Inventario</strong>
                    <a class="nav-link" href="StockCentral.php" target="contentFrame">Stock Central</a>
                    <a class="nav-link" href="ValorInventario.php" target="contentFrame">Valor Inventario</a>
                    <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
                    <a class="nav-link" href="ConteoAjuste.php" target="contentFrame">Conteo Ajuste</a>
                    <a class="nav-link" href="Categorias.php" target="contentFrame">Categorías</a>
                    <a class="nav-link" href="Precios.php" target="contentFrame">Precios</a>

                    <!-- SUBGRUPO: FINANZAS -->
                    <strong class="text-white px-2 d-block mt-2">💰 Finanzas</strong>
                    <a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">Cartera Proveedores</a>
                    <a class="nav-link" href="CarteraXProveedorBnma.php" target="contentFrame">Cartera BNMA</a>

                    <!-- SUBGRUPO: CONTROL -->
                    <strong class="text-white px-2 d-block mt-2">📈 Control</strong>
                    <a class="nav-link" href="DashBoard1.php" target="contentFrame">Control Central</a>
                    <a class="nav-link" href="DashBoard2.php" target="contentFrame">Control Drinks</a>
                    <a class="nav-link" href="BnmaTotal.php" target="contentFrame">Control Ventas</a>

                    <!-- SUBGRUPO: CONFIGURACIÓN -->
                    <strong class="text-white px-2 d-block mt-2">⚙️ Configuración</strong>
                    <a class="nav-link" href="CrearUsuarios.php" target="contentFrame">Usuarios</a>
                    <a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">Autorizaciones</a>
                    <a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">Auto por Usuario</a>

                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ================= FOOTER ================= -->
    <div class="mt-auto p-3 border-top text-center">
        <div>Bienvenido<br><strong><?=htmlspecialchars($UsuarioSesion)?></strong></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<!-- ================= CONTENIDO ================= -->
<iframe name="contentFrame" class="content-frame"></iframe>

<!-- BOTÓN MÓVIL -->
<button id="toggleMenu" class="btn btn-primary d-md-none"
        style="position:fixed;top:10px;left:10px;z-index:1000;">☰</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('toggleMenu').onclick = () => {
    document.getElementById('sidebar').classList.toggle('show');
};
</script>

</body>
</html>
