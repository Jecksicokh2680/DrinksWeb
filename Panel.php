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
$EsAgenteAdmin = (Autorizacion($UsuarioSesion, '9999') === 'SI');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Usuario - 2026</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root { --sidebar-w: 260px; }
body { margin:0; display:flex; min-height:100vh; font-family:Arial,sans-serif; overflow-x:hidden; background:#f8f9fa; }

/* Sidebar Responsive */
.sidebar {
    width: var(--sidebar-w);
    background: #0d6efd;
    color: #fff;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    transition: all 0.3s ease;
    z-index: 1050;
}
.sidebar .nav-link { color: #fff; padding: 6px 14px; font-size: 14px; }
.sidebar .nav-link:hover { background: #084298; }
.navbar-brand { padding: 1rem; font-weight: bold; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }

/* Contenedor Principal */
.main-container { flex-grow: 1; display: flex; flex-direction: column; width: 100%; min-width: 0; }
.content-frame { border: none; width: 100%; height: 100vh; flex-grow: 1; }

/* Acordeones */
.accordion-button { padding: 8px 14px; font-size: 14px; background: #0d6efd; color: #fff; box-shadow: none !important; }
.accordion-button:not(.collapsed) { background: #084298; color: #fff; }
.accordion-item { background: transparent; border: none; }
.sub-accordion .accordion-button { background: #0b5ed7; font-size: 13px; padding: 6px 18px; }

/* Mobile Logic */
@media (max-width: 768px) {
    .sidebar { position: fixed; left: calc(-1 * var(--sidebar-w)); top: 0; height: 100%; }
    .sidebar.show { left: 0; }
    .mobile-header { display: flex !important; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1040; }
    .sidebar-overlay.show { display: block; }
}
.mobile-header { display: none; background: #0d6efd; color: white; padding: 8px; align-items: center; }

/* Estilos Agente IA (Simplificados para el ejemplo pero manteniendo tu estética) */
.ai-agent-wrap { position: fixed; right: 16px; bottom: 16px; z-index: 10050; }
.ai-agent-toggle { width: 56px; height: 56px; border-radius: 50%; border: none; background: #0d6efd; color: #fff; font-size: 22px; cursor: pointer; box-shadow: 0 4px 20px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; }
.ai-agent-panel { display: none; position: absolute; right: 0; bottom: 68px; width: min(380px, calc(100vw - 32px)); height: min(520px, calc(100vh - 100px)); background: #0f172a; border-radius: 16px; flex-direction: column; overflow: hidden; border: 1px solid #1e293b; color: white; }
.ai-agent-panel.open { display: flex; }
.ai-agent-messages { flex: 1; overflow-y: auto; padding: 14px; }
.ai-agent-foot { padding: 12px; border-top: 1px solid #1e293b; display: flex; gap: 8px; }
.ai-agent-foot input { flex: 1; background: #1e293b; border: 1px solid #334155; color: #fff; padding: 8px; border-radius: 8px; }
</style>
</head>
<body>

<div class="sidebar-overlay" id="overlay"></div>

<div class="sidebar" id="sidebar">
    <div class="navbar-brand">SISTEMA DRINKS</div>
    
    <div class="accordion accordion-flush px-2" id="menuPrincipal">
        
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#basico">🔓 Opciones básicas</button>
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
                    <a class="nav-link" href="TrasladosparaVerificar.php" target="contentFrame">🧮 Traslados Jefe-Bodega</a>
                </div>
            </div>
        </div>

        <?php if ($EsAdmin): ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#admin">🔐 Administración</button>
            </h2>
            <div id="admin" class="accordion-collapse collapse">
                <div class="accordion-body">
                    <div class="accordion sub-accordion">
                        
                        <div class="accordion-item">
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminOp">📊 Operación</button>
                            <div id="adminOp" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <a class="nav-link" href="ValorInventario.php" target="contentFrame">Dashboard BNMA</a>
                                    <a class="nav-link" href="CierreCajerosTodos.php" target="contentFrame">Resumen de Cierres Bnma </a>
                                    <a class="nav-link" href="CierreCajeroBnma.php" target="contentFrame">Recaudo en Efectivo dia </a>
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
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminInv">📦 Inventario</button>
                            <div id="adminInv" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <a class="nav-link" href="StockCentral.php" target="contentFrame">Stock Bnma</a>
                                    <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
                                    <a class="nav-link" href="ajustesdeInventario.php" target="contentFrame">Ajustes de Inventario</a>
                                    <a class="nav-link" href="Ver_ajustes_conteos.php" target="contentFrame">Ver Ajustes Conteos</a>
                                    <a class="nav-link" href="historicoConteos.php" target="contentFrame">Ver Historico de Conteos</a>
                                    <a class="nav-link" href="HistoricoConteoDiaaDia.php" target="contentFrame">Historico Conteos Día a Día</a>
                                    <a class="nav-link" href="Precios.php" target="contentFrame">Precios</a>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminFin">💰 Finanzas</button>
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
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminNomina">💼 Nómina</button>
                            <div id="adminNomina" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <a class="nav-link" href="CrearColaborador.php" target="contentFrame">Crear Colaborador</a>
                                    <a class="nav-link" href="NominaGenerar.php" target="contentFrame">Liquidación Quincenal</a>
                                    <a class="nav-link" href="HistorialPagos.php" target="contentFrame">Historial Pagos</a>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminConf">⚙️ Configuración</button>
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
            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#estadisticas">📈 Estadísticas</button>
            <div id="estadisticas" class="accordion-collapse collapse">
                <div class="accordion-body">
                    <a class="nav-link" href="Estadistica_Vtas_mas.php" target="contentFrame">Lo más Vendido</a>
                    <a class="nav-link" href="Estadistica_Vtas_Cajero.php" target="contentFrame">Lo más Vendido por Cajero</a>
                    <a class="nav-link" href="Estadistica_Vtas_mas_tamaño.php" target="contentFrame">Lo más Vendido por tamaño</a>
                    <a class="nav-link" href="Estadistica_Vtas_mas_Empresa.php" target="contentFrame">Lo más Vendido por Empresa</a>
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

<div class="main-container">
    <div class="mobile-header">
        <button class="btn btn-primary" id="btnToggle">☰</button>
        <div class="ms-2 fw-bold">PANEL DRINKS</div>
    </div>
    <iframe name="contentFrame" class="content-frame"></iframe>
</div>

<?php if ($EsAgenteAdmin): ?>
<div class="ai-agent-wrap">
    <div class="ai-agent-panel" id="aiPanel">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <span>💡 Asistente Drinks</span>
            <button class="btn btn-sm btn-outline-light" id="aiClose">✕</button>
        </div>
        <div class="ai-agent-messages" id="aiMessages"></div>
        <form class="ai-agent-foot" id="aiForm">
            <input type="text" placeholder="Escribe aquí...">
            <button type="submit" class="btn btn-primary btn-sm">➤</button>
        </form>
    </div>
    <button class="ai-agent-toggle" id="aiToggle">💬</button>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const btnToggle = document.getElementById('btnToggle');

    function toggleMenu() {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    }

    if(btnToggle) btnToggle.onclick = toggleMenu;
    overlay.onclick = toggleMenu;

    // Cerrar menú al hacer clic en un link en móvil
    document.querySelectorAll('.nav-link').forEach(link => {
        link.onclick = () => { if(window.innerWidth <= 768) toggleMenu(); };
    });

    <?php if ($EsAgenteAdmin): ?>
    const aiPanel = document.getElementById('aiPanel');
    document.getElementById('aiToggle').onclick = () => aiPanel.classList.toggle('open');
    document.getElementById('aiClose').onclick = () => aiPanel.classList.remove('open');
    <?php endif; ?>
</script>
</body>
</html>