<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$UsuarioSesion = $_SESSION['Usuario'];

/* ======================================================
   FUNCIÓN: CARGAR PERMISOS (CON CACHE EN SESIÓN)
====================================================== */
function PermisosUsuario($user){
    global $mysqli;

    if (isset($_SESSION['PERMISOS'])) {
        return $_SESSION['PERMISOS'];
    }

    $permisos = [];

    $stmt = $mysqli->prepare("
        SELECT Nro_Auto
        FROM autorizacion_tercero
        WHERE CedulaNit = ? AND Swich = 'SI'
    ");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $permisos[] = $r['Nro_Auto'];
    }

    $stmt->close();

    $_SESSION['PERMISOS'] = $permisos;
    return $permisos;
}

$PERMISOS = PermisosUsuario($UsuarioSesion);

/* ======================================================
   FUNCIÓN HELPER: VALIDAR PERMISO
====================================================== */
function TienePermiso($codigo){
    return in_array($codigo, $_SESSION['PERMISOS'] ?? []);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel Principal</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
body{
    margin:0;
    display:flex;
    min-height:100vh;
    overflow:hidden;
    font-family:Arial, sans-serif;
}
.sidebar{
    width:260px;
    background:#0d6efd;
    color:#fff;
    display:flex;
    flex-direction:column;
}
.sidebar a{
    color:#fff;
    text-decoration:none;
}
.sidebar .accordion-button{
    background:#0d6efd;
    color:#fff;
    font-weight:bold;
}
.sidebar .accordion-button:not(.collapsed){
    background:#084298;
}
.sidebar .nav-link{
    padding-left:2.5rem;
}
.sidebar .nav-link.active{
    background:#084298;
}
.content-frame{
    flex-grow:1;
    border:none;
    width:100%;
    height:100vh;
}
@media(max-width:768px){
    .sidebar{
        position:fixed;
        left:-260px;
        top:0;
        height:100%;
        z-index:1000;
        transition:.3s;
    }
    .sidebar.show{
        left:0;
    }
}
</style>
</head>

<body>

<!-- BOTÓN MENÚ MÓVIL -->
<button id="btnMenu" class="btn btn-primary d-md-none"
style="position:fixed;top:10px;left:10px;z-index:1100">
☰ Menú
</button>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">

<div class="p-3 fw-bold fs-5 border-bottom">
<i class="bi bi-speedometer2"></i> Mi App
</div>

<div class="accordion accordion-flush" id="menuAccordion">

<!-- OPERACIONES -->
<div class="accordion-item bg-primary border-0">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#operaciones">
<i class="bi bi-box-seam me-2"></i> Operaciones
</button>
</h2>
<div id="operaciones" class="accordion-collapse collapse">
<div class="accordion-body p-0">
<a class="nav-link" href="Transfers.php" target="contentFrame">Transferencias</a>
<a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
<a class="nav-link" href="Conteo.php" target="contentFrame">Conteo Web</a>
</div>
</div>
</div>

<!-- CONSULTAS -->
<div class="accordion-item bg-primary border-0">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#consultas">
<i class="bi bi-clipboard-data me-2"></i> Consultas
</button>
</h2>
<div id="consultas" class="accordion-collapse collapse">
<div class="accordion-body p-0">
<a class="nav-link" href="ResumenVtas.php" target="contentFrame">Resumen Ventas</a>
<a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">Cartera Proveedores</a>
<a class="nav-link" href="TransferDiaDia.php" target="contentFrame">Transfers Día</a>
</div>
</div>
</div>

<!-- MAESTROS -->
<div class="accordion-item bg-primary border-0">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#maestros">
<i class="bi bi-database me-2"></i> Maestros
</button>
</h2>
<div id="maestros" class="accordion-collapse collapse">
<div class="accordion-body p-0">
<a class="nav-link" href="Productos.php" target="contentFrame">Productos</a>
<a class="nav-link" href="Categorias.php" target="contentFrame">Categorías</a>
</div>
</div>
</div>

<?php if (TienePermiso('0001')): ?>
<!-- ADMINISTRACIÓN -->
<div class="accordion-item bg-primary border-0">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#admin">
<i class="bi bi-gear me-2"></i> Administración
</button>
</h2>
<div id="admin" class="accordion-collapse collapse">
<div class="accordion-body p-0">
<a class="nav-link" href="CrearUsuarios.php" target="contentFrame">Usuarios</a>
<a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">Autorizaciones</a>
<a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">Auto. por Usuario</a>
</div>
</div>
</div>

<!-- DASHBOARDS -->
<div class="accordion-item bg-primary border-0">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#dash">
<i class="bi bi-bar-chart me-2"></i> Dashboards
</button>
</h2>
<div id="dash" class="accordion-collapse collapse">
<div class="accordion-body p-0">
<a class="nav-link" href="DashBoard1.php" target="contentFrame">Control Central</a>
<a class="nav-link" href="DashBoard2.php" target="contentFrame">Control Drinks</a>
<a class="nav-link" href="BnmaTotal.php" target="contentFrame">Control Ventas</a>
</div>
</div>
</div>
<?php endif; ?>

</div>

<!-- FOOTER -->
<div class="mt-auto p-3 border-top">
<div class="small">
<i class="bi bi-person-circle"></i> <?= htmlspecialchars($UsuarioSesion) ?>
</div>
<div class="small opacity-75">
Rol: <?= TienePermiso('0001') ? 'Administrador' : 'Usuario' ?>
</div>
<a href="Logout.php" class="btn btn-outline-light btn-sm w-100 mt-2">
<i class="bi bi-box-arrow-right"></i> Cerrar sesión
</a>
</div>

</div>

<!-- CONTENIDO -->
<iframe src="DashBoard1.php" name="contentFrame" class="content-frame"></iframe>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('sidebar');
document.getElementById('btnMenu').onclick = () => sidebar.classList.toggle('show');

document.querySelectorAll('.nav-link').forEach(link=>{
    link.addEventListener('click',()=>{
        document.querySelectorAll('.nav-link').forEach(l=>l.classList.remove('active'));
        link.classList.add('active');
        if(window.innerWidth<768) sidebar.classList.remove('show');
    });
});
</script>

</body>
</html>
