<?php
require 'Conexion.php';
require 'helpers.php';
session_start();
require 'auth_check.php';

if (empty($_SESSION['Usuario'])) {
    echo "<script>window.top.location.href='Login.php?msg=Debe iniciar sesión';</script>";
    exit;
}
$UsuarioSesion = $_SESSION['Usuario'];

function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ? LIMIT 1");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($res && $res->num_rows > 0) ? $res->fetch_assoc()['Swich'] : "NO";
}

$EsAdmin = (Autorizacion($UsuarioSesion, '0001') === "SI");
$EsAgenteAdmin = (Autorizacion($UsuarioSesion, '9999') === 'SI');
$EsJefeBodega = (Autorizacion($UsuarioSesion, '0004') === 'SI');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Usuario - 2026</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { margin: 0; display: flex; min-height: 100vh; font-family: 'Segoe UI', Arial, sans-serif; background: #f8fafc; }
.sidebar { width: 260px; background: linear-gradient(180deg, #0d6efd 0%, #063f99 100%); color: #fff; display: flex; flex-direction: column; overflow-y: auto; }
.navbar-brand { padding: 1.5rem 1rem; font-weight: 800; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar .nav-link { color: rgba(255, 255, 255, 0.85); padding: 8px 14px; font-size: 13px; margin: 2px 10px; border-radius: 6px; text-decoration: none; display: flex; align-items: center; }
.sidebar .nav-link:hover { background: rgba(255, 255, 255, 0.15); color: #fff; }
.accordion-item { background: transparent; border: none; }
.accordion-button { padding: 12px 14px; font-size: 14px; background: transparent; color: #fff; box-shadow: none !important; }
.accordion-button:not(.collapsed) { background: rgba(255, 255, 255, 0.15); color: #fff; }
.accordion-button::after { filter: brightness(0) invert(1); }
.sub-accordion .accordion-button { font-size: 13px; padding: 8px 18px; color: #fff; background: rgba(255,255,255,0.05); }
.content-frame { flex-grow: 1; border: none; width: 100%; height: 100vh; }
</style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="navbar-brand">SISTEMA BNMA</div>
    <div class="accordion accordion-flush px-2" id="menuPrincipal">

        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#mCajeros">💰 Módulo Cajeros</button></h2>
            <div id="mCajeros" class="accordion-collapse collapse"><div class="accordion-body">
                <a class="nav-link" href="Transfers.php" target="contentFrame">➕ Grabar Transferencia</a>
                <a class="nav-link" href="SolicitudAnulacion.php" target="contentFrame">🧮 Grabar Solicitud Anulación</a>
                <a class="nav-link" href="ListaFactDia.php" target="contentFrame">🧮 Listado Facturas del Día</a>
                <a class="nav-link" href="Calculadora.php" target="contentFrame">📄 Calculadora</a>
                <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">🧮 Grabar Traslados Coord</a>
                <a class="nav-link" href="buscaprecioventacero.php" target="contentFrame">🧮 Busca Precio Venta Cero</a>
                <a class="nav-link" href="CierreDef.php" target="contentFrame">🧮 Cierre Diario Cajero</a>
                <a class="nav-link" href="minequi.php" target="contentFrame">🧮 Ver Transferencias Banco</a>
                <a class="nav-link" href="LectorEmailFacturas.php" target="contentFrame">🧮 Ver Que llega Hoy</a>
            </div></div>
        </div>

        <?php if ($EsJefeBodega): ?>
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#mBodega">📦 Jefes de Bodega</button></h2>
            <div id="mBodega" class="accordion-collapse collapse"><div class="accordion-body">
                <a class="nav-link" href="aprobacionanulacionJb.php" target="contentFrame">✅ Aprobación Anulación JB</a>
                <a class="nav-link" href="TrasladosparaVerificar.php" target="contentFrame">✅ Aprobar Traslados JB</a>
            </div></div>
        </div>
        <?php endif; ?>

        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#mGeneral">⚙️ General / Compartido</button></h2>
            <div id="mGeneral" class="accordion-collapse collapse"><div class="accordion-body">
                <a class="nav-link" href="Conteo.php" target="contentFrame">🧮 Grabar Conteo Web</a>
                <a class="nav-link" href="AuditoriaPedido.php" target="contentFrame">🧮 Auditoria Pedidos</a>
                <a class="nav-link" href="vertunel.php" target="contentFrame">🧮 Ver Tunel</a>
                <a class="nav-link" href="recibir_mercancia.php" target="contentFrame">🧮 Recibir Mercancia</a>
                <a class="nav-link" href="#" onclick="window.open('Chat.php', 'ChatInterno', 'width=450,height=300'); return false;">🧮 Chat Interno</a>
            </div></div>
        </div>

        <?php if ($EsAdmin): ?>
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#admin">🔐 Administración</button></h2>
            <div id="admin" class="accordion-collapse collapse"><div class="accordion-body">
                <div class="accordion sub-accordion">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subOp">📊 Operación</button>
                    <div id="subOp" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="ValorInventario.php" target="contentFrame">Dashboard BNMA</a>
                        <a class="nav-link" href="Compras.php" target="contentFrame">Ver Compras BNMA</a>
                        <a class="nav-link" href="CierreCajerosTodos.php" target="contentFrame">Resumen de Cierres</a>
                        <a class="nav-link" href="ValorInventariox.php" target="contentFrame">Dashboard Historico</a>
                        <a class="nav-link" href="DashBoard3.php" target="contentFrame">DashBoard Compras Vs Ventas</a>
                        <a class="nav-link" href="CierreCajeroBnma.php" target="contentFrame">Recaudo en Efectivo</a>
                        <a class="nav-link" href="Promociones.php" target="contentFrame">Promociones</a>
                        <a class="nav-link" href="ResumenVtas.php" target="contentFrame">Ventas BNMA</a>
                        <a class="nav-link" href="ComprasxProveedor.php" target="contentFrame">Compras por Proveedor</a>
                        <a class="nav-link" href="ComprasXProveedorXmeses.php" target="contentFrame">Compras Graficas</a>
                        <a class="nav-link" href="TransferDiaDia.php" target="contentFrame">Transfers Día</a>
                        <a class="nav-link" href="DashBoard1.php" target="contentFrame">Control Cierre Central</a>
                        <a class="nav-link" href="DashBoard2.php" target="contentFrame">Control Cierre Drinks</a>
                        <a class="nav-link" href="BnmaTotal.php" target="contentFrame">Control Bnma Ventas</a>
                        <a class="nav-link" href="Validador_NrosFacturas.php" target="contentFrame">Consecutivos Facturas</a>
                        <a class="nav-link" href="listafactdiagrafica.php" target="contentFrame">Grafica rangos venta</a>
                    </div></div>

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subInv">📦 Inventario</button>
                    <div id="subInv" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="Precios.php" target="contentFrame">Precios</a>
                        <a class="nav-link" href="StockCentral.php" target="contentFrame">Stock Bnma</a>
                        <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
                        <a class="nav-link" href="ajustesdeInventario.php" target="contentFrame">Ajustes de Inventario</a>
                        <a class="nav-link" href="Ver_ajustes_conteos.php" target="contentFrame">Ver Ajustes Conteos</a>
                        <a class="nav-link" href="historicoConteos.php" target="contentFrame">Ver Historico Conteos</a>
                        <a class="nav-link" href="HistoricoConteoDiaaDia.php" target="contentFrame">Historico Día a Día</a>
                        <a class="nav-link" href="Busca_DuplicadosXNombre.php" target="contentFrame">Busca Duplicados</a>
                    </div></div>

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subFin">💰 Finanzas</button>
                    <div id="subFin" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="CarteraXProveedorBnma.php" target="contentFrame">Cartera Grafica BNMA</a>
                        <a class="nav-link" href="CarteraGraficarango.php" target="contentFrame">Grafica Prov/Rango</a>
                        <a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">Cartera Proveedores</a>
                        <a class="nav-link" href="CarteraXProveedorXfechas.php" target="contentFrame">Cartera Rango Fechas</a>
                        <a class="nav-link" href="CarteraXProveedordiaadia.php" target="contentFrame">Cartera x día</a>
                    </div></div>

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subEst">📈 Estadísticas</button>
                    <div id="subEst" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="Estadistica_Vtas_mas.php" target="contentFrame">Lo más Vendido</a>
                        <a class="nav-link" href="Estadistica_Vtas_Cajero.php" target="contentFrame">Ventas por Cajero</a>
                        <a class="nav-link" href="Estadistica_Vtas_mas_tamaño.php" target="contentFrame">Ventas por tamaño</a>
                        <a class="nav-link" href="Estadistica_Vtas_MesaMes.php" target="contentFrame">Ventas Mes a Mes</a>
                        <a class="nav-link" href="Estadistica_Vtas_mas_Empresa.php" target="contentFrame">Ventas por Empresa</a>
                    </div></div>

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subNom">💼 Nómina</button>
                    <div id="subNom" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="CrearColaborador.php" target="contentFrame">Crear Colaborador</a>
                        <a class="nav-link" href="NominaGenerar.php" target="contentFrame">Liquidación Quincenal</a>
                        <a class="nav-link" href="HistorialPagos.php" target="contentFrame">Historial Pagos</a>
                    </div></div>

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#subConf">⚙️ Configuración</button>
                    <div id="subConf" class="accordion-collapse collapse"><div class="accordion-body">
                        <a class="nav-link" href="CrearUsuarios.php" target="contentFrame">Usuarios</a>
                        <a class="nav-link" href="CrearAutorizaciones.php" target="contentFrame">Autorizaciones</a>
                        <a class="nav-link" href="CrearAutoTerceros.php" target="contentFrame">Auto por Usuario</a>
                        <a class="nav-link" href="Empresas_Productoras.php" target="contentFrame">Empresas Productoras</a>
                        <a class="nav-link" href="Categorias.php" target="contentFrame">Crear Categorías</a>
                        <a class="nav-link" href="Productos.php" target="contentFrame">Lista Productos</a>
                        <a class="nav-link" href="CategoriaProducto.php" target="contentFrame">Categoria Producto</a>
                    </div></div>
                </div>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<iframe name="contentFrame" class="content-frame" src="about:blank"></iframe>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>