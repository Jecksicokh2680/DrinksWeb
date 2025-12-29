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
   FUNCIÓN AUTORIZACIÓN (SE MANTIENE)
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
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['Swich'];
    }
    return "NO";
}

/* ============================================
   VALIDAR SI ES ADMIN (0001)
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
    overflow-x: hidden;
    font-family: Arial, sans-serif;
}
.sidebar {
    width: 220px;
    background-color: #0d6efd;
    color: white;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}
.sidebar .nav-link {
    color: white;
    padding: 6px 12px;
}
.sidebar .nav-link:hover {
    background-color: #084298;
}
.sidebar .navbar-brand {
    padding: 1rem;
    font-weight: bold;
    font-size: 1.2rem;
}
.content-frame {
    flex-grow: 1;
    border: none;
    width: 100%;
    height: 100vh;
}
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        left: -250px;
        top: 0;
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
    <a class="navbar-brand text-white" href="#">Mi App</a>

    <nav class="nav flex-column px-2">

        <!-- OPCIONES BASICAS (TODOS) -->
        <a class="nav-link" href="Transfers.php" target="contentFrame">➕ Transferencia</a>        
        <a class="nav-link" href="Calculadora.php" target="contentFrame">📄 Calculadora</a>
       
        <a class="nav-link" href="Conteo.php" target="contentFrame">🧮 Conteo Web</a>

        <!-- OPCIONES SOLO ADMIN -->
        <?php if ($EsAdmin): ?>
            <hr class="text-white">
             <a class="nav-link" href="ResumenVtas.php" target="contentFrame">📊Ventas Bnma</a>
             <a class="nav-link" href="ValorInventario.php" target="contentFrame">💰 Estado Bod BNMA</a>
             <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">📦 Traslados Bnma</a>
            <a class="nav-link" href="StockCentral.php" target="contentFrame">🗂️ Stock Bnma</a>
            <a class="nav-link" href="Precios.php" target="contentFrame">🗂️ Precios Bnma </a>
            <a class="nav-link" href="Compras.php" target="contentFrame">📊Compras Bnma</a>
            <a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">💰 Cartera Proveedores</a>
            <a class="nav-link" href="CarteraXProveedorBnma.php" target="contentFrame">💰 Cartera Bnma</a>
            
            <a class="nav-link" href="CrearUsuarios.php" target="contentFrame">👥 Usuarios</a>
            <a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">📋 Autorizaciones</a>
            <a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">🗂️ Auto. por Usuario</a>

            <a class="nav-link" href="TransferDiaDia.php" target="contentFrame">📄 Transfers Día</a>


            <a class="nav-link" href="Categorias.php" target="contentFrame">🗂️ Categorías</a>
            
            <a class="nav-link" href="DashBoard1.php" target="contentFrame">📈 Control Central</a>
            <a class="nav-link" href="DashBoard2.php" target="contentFrame">📈 Control Drinks</a>
            <a class="nav-link" href="BnmaTotal.php" target="contentFrame">📈 Control Ventas</a>
        <?php endif; ?>

    </nav>

    <div class="mt-auto p-3 border-top">
        <div>Bienvenido,<br><strong><?= htmlspecialchars($UsuarioSesion) ?></strong></div>
        <a href="Logout.php" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
    </div>
</div>

<!-- ================= CONTENIDO ================= -->
<iframe name="contentFrame" class="content-frame"></iframe>

<!-- BOTÓN MÓVIL -->
<button id="toggleMenu" class="btn btn-primary d-md-none"
        style="position:fixed;top:10px;left:10px;z-index:1000;">☰</button>

<script>
const sidebar = document.getElementById('sidebar');
document.getElementById('toggleMenu').onclick = () => {
    sidebar.classList.toggle('show');
};
</script>

</body>
</html>
