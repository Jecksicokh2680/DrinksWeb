<?php
require 'Conexion.php';
require 'helpers.php';
session_start();
// Si no hay sesión, redirigimos al Login rompiendo cualquier iframe
if (empty($_SESSION['Usuario'])) {
    echo "<script>window.top.location.href='Login.php?msg=Debe iniciar sesión';</script>";
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

$EsAdmin = (Autorizacion($UsuarioSesion, '0001') === "SI");
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Usuario - 2026</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    margin:0;
    display:flex;
    min-height:100vh;
    font-family:Arial,sans-serif;
}
.sidebar{
    width:260px;
    background:#0d6efd;
    color:#fff;
    display:flex;
    flex-direction:column;
    flex-shrink: 0;
}
.sidebar .nav-link{
    color:#fff;
    padding:6px 14px;
    font-size:14px;
}
.sidebar .nav-link:hover{
    background:#084298;
}
.navbar-brand{
    padding:1rem;
    font-weight:bold;
    text-align:center;
}
.content-frame{
    flex-grow:1;
    border:none;
    width:100%;
    height:100vh;
}

/* Accordion */
.accordion-button{
    padding:6px 14px;
    font-size:14px;
    background:#0d6efd;
    color:#fff;
}
.accordion-button:not(.collapsed){
    background:#084298;
    color:#fff;
}
.accordion-item{
    background:transparent;
    border:none;
}
.accordion-body{
    padding:0;
}

/* Sub-acordeón */
.sub-accordion .accordion-button{
    background:#0b5ed7;
    font-size:13px;
    padding:6px 18px;
}
.sub-accordion .accordion-button:not(.collapsed){
    background:#063f99;
}

@media (max-width:768px){
    .sidebar{
        position:fixed;
        left:-270px;
        top:0;
        height:100%;
        z-index:999;
        transition:left .3s;
    }
    .sidebar.show{left:0;}
}
</style>
</head>

<body>

<div class="sidebar" id="sidebar">

<div class="navbar-brand">SISTEMA DRINKS</div>

<div class="accordion accordion-flush px-2" id="menuPrincipal">

<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#basico">
🔓 Opciones básicas
</button>
</h2>
<div id="basico" class="accordion-collapse collapse show">
<div class="accordion-body">
<a class="nav-link" href="Transfers.php" target="contentFrame">➕ Transferencia</a>
<a class="nav-link" href="Conteo.php" target="contentFrame">🧮 Conteo Web</a>
<a class="nav-link" href="SolicitudAnulacion.php" target="contentFrame">🧮 Solicitud Anulación</a>
<a class="nav-link" href="ListaFactDia.php" target="contentFrame">🧮 Listado de Facturas del Día</a>
<a class="nav-link" href="Calculadora.php" target="contentFrame">📄 Calculadora</a>
<a class="nav-link" href="CierreDef.php" target="contentFrame">🧮 Cierre Cajero Final</a>
<a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">🧮 Traslados de mercancia</a>
</div>
</div>
</div>

<?php if ($EsAdmin): ?>
<div class="accordion-item">
<h2 class="accordion-header">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#admin">
🔐 Administración
</button>
</h2>
<div id="admin" class="accordion-collapse collapse">
<div class="accordion-body">

<div class="accordion sub-accordion">

<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminOp">
📊 Operación
</button>
<div id="adminOp" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="ValorInventario.php" target="contentFrame">Dashboard BNMA</a>
<a class="nav-link" href="CierreCajerosTodos.php" target="contentFrame">Resumen de Cierres Bnma </a>
<a class="nav-link" href="CierreCajeroBnma.php" target="contentFrame">Recaudo en  Efectivo dia </a>
<a class="nav-link" href="ResumenVtas.php" target="contentFrame">Ventas BNMA</a>
<a class="nav-link" href="Compras.php" target="contentFrame">Compras BNMA</a>
<a class="nav-link" href="ComprasxProveedor.php" target="contentFrame">Compras por Proveedor</a>    
<a class="nav-link" href="ComprasXProveedorXmeses.php" target="contentFrame">Compras Graficas</a>    
<a class="nav-link" href="TransferDiaDia.php" target="contentFrame">Transfers Día</a>
<a class="nav-link" href="DashBoard1.php" target="contentFrame">Control Cierre Central</a>
<a class="nav-link" href="DashBoard2.php" target="contentFrame">Control Cierre Drinks</a>
<a class="nav-link" href="BnmaTotal.php" target="contentFrame">Control Bnma Ventas</a>
<a class="nav-link" href="Validador_NrosFacturas.php" target="contentFrame">Consecutivos de Facturas</a>
<a class="nav-link" href="listafactdiagrafica.php" target="contentFrame">Grafica de rangos de venta</a>

</div>
</div>
</div>

<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminInv">
📦 Inventario
</button>
<div id="adminInv" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="StockCentral.php" target="contentFrame">Stock Bnma</a>
<a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
<a class="nav-link" href="ajustesdeInventario.php" target="contentFrame">Ajustes de Inventario</a>
<!-- <a class="nav-link" href="ConteoAjuste.php" target="contentFrame">Conteo Ajuste</a> -->
<a class="nav-link" href="Ver_ajustes_conteos.php" target="contentFrame">Ver Ajustes Conteos</a>
<a class="nav-link" href="historicoConteos.php" target="contentFrame">Ver Historico de Conteos</a>
<a class="nav-link" href="HistoricoConteoDiaaDia.php" target="contentFrame">Historico Conteos Día a Día</a>
<a class="nav-link" href="Precios.php" target="contentFrame">Precios</a>
</div>
</div>
</div>
<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminFin">
💰 Finanzas
</button>
<div id="adminFin" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="CarteraXProveedorBnma.php" target="contentFrame">Cartera Grafica BNMA</a>
<a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">Cartera Proveedores</a>
<a class="nav-link" href="CarteraXProveedorXfechas.php" target="contentFrame">Cartera por rango de fechas </a>
<a class="nav-link" href="CarteraXProveedordiaadia.php" target="contentFrame">Cartera Proveedores por dia</a>

</div>
</div>
</div>

<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminNomina">
💼 Nómina
</button>
<div id="adminNomina" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="CrearColaborador.php" target="contentFrame">Crear Colaborador</a>
<a class="nav-link" href="NominaGenerar.php" target="contentFrame">Liquidación Quincenal</a>
<a class="nav-link" href="HistorialPagos.php" target="contentFrame">Historial Pagos</a>
</div>
</div>
</div>

<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminConf">
⚙️ Configuración
</button>
<div id="adminConf" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="CrearUsuarios.php" target="contentFrame">Usuarios</a>
<a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">Autorizaciones</a>
<a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">Auto por Usuario</a>
<a class="nav-link" href="Empresas_Productoras.php" target="contentFrame">Empresas Productoras</a>
<a class="nav-link" href="Categorias.php" target="contentFrame">Crear Categorías</a>
<a class="nav-link" href="CategoriaProducto.php" target="contentFrame">Categoria Producto</a>
</div>
</div>
</div>

</div>
</div>
</div>
</div>

<div class="accordion-item">
<button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#estadisticas">
📈 Estadísticas
</button>
<div id="estadisticas" class="accordion-collapse collapse">
<div class="accordion-body">
<a class="nav-link" href="Estadistica_Vtas_mas.php" target="contentFrame">
Lo más Vendido
</a>
<a class="nav-link" href="Estadistica_Vtas_Cajero.php" target="contentFrame">
Lo más Vendido por Cajero
</a>
<a class="nav-link" href="Estadistica_Vtas_mas_tamaño.php" target="contentFrame">
Lo más Vendido por tamaño
</a>
<a class="nav-link" href="Estadistica_Vtas_mas_Empresa.php" target="contentFrame">
Lo más Vendido por Empresa
</a>

</div>
</div>
</div>

<?php endif; ?>

</div>

<div class="mt-auto p-3 border-top text-center">
<div>Bienvenido<br><strong><?=htmlspecialchars($UsuarioSesion)?></strong></div>
<a href="Logout.php" target="_top" class="btn btn-outline-light btn-sm mt-2 w-100">Cerrar sesión</a>
</div>

</div>

<iframe name="contentFrame" class="content-frame"></iframe>

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