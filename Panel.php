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
$EsAgenteAdmin = (Autorizacion($UsuarioSesion, '9999') === 'SI');
$EsJefeBodega = (Autorizacion($UsuarioSesion, '0004') === 'SI');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Usuario - 2026</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* ============================================
   ESTILO DEGRADADO DINÁMICO
============================================ */
body { 
    margin: 0; 
    display: flex; 
    min-height: 100vh; 
    font-family: 'Segoe UI', Arial, sans-serif; 
    background: #f8fafc;
}

.sidebar { 
    width: 260px; 
    background: linear-gradient(180deg, #0d6efd 0%, #063f99 100%); 
    color: #fff; 
    display: flex; 
    flex-direction: column; 
    flex-shrink: 0; 
    box-shadow: 4px 0 15px rgba(0,0,0,0.1);
    overflow-y: auto; /* Permite scroll interno si el menú es largo */
}

.navbar-brand { 
    padding: 1.5rem 1rem; 
    font-weight: 800; 
    text-align: center; 
    letter-spacing: 1px;
    font-size: 1.2rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar .nav-link { 
    color: rgba(255, 255, 255, 0.85); 
    padding: 8px 14px; 
    font-size: 14px; 
    margin: 3px 10px;
    border-radius: 8px;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    text-decoration: none;
}

.sidebar .nav-link:hover { 
    background: rgba(255, 255, 255, 0.15); 
    color: #fff; 
    padding-left: 20px; 
}

/* Ajustes de Acordeones */
.accordion-item { 
    background: transparent; 
    border: none; 
}

.accordion-body { 
    padding: 4px 0; 
}

.accordion-button { 
    padding: 12px 14px; 
    font-size: 14px; 
    background: transparent; 
    color: #fff; 
    box-shadow: none !important;
}

.accordion-button:not(.collapsed) { 
    background: rgba(255, 255, 255, 0.1); 
    color: #fff; 
}

.accordion-button::after {
    filter: brightness(0) invert(1); 
}

/* Sub-acordeones internos */
.sub-accordion .accordion-button { 
    background: transparent; 
    font-size: 13px; 
    padding: 8px 18px; 
    color: rgba(255, 255, 255, 0.9);
}

.sub-accordion .accordion-button:not(.collapsed) { 
    background: rgba(255, 255, 255, 0.05); 
}

.content-frame { 
    flex-grow: 1; 
    border: none; 
    width: 100%; 
    height: 100vh; 
}

@media (max-width:768px) { 
    .sidebar { 
        position: fixed; 
        left: -270px; 
        top: 0; 
        height: 100%; 
        z-index: 1040; /* Por debajo del botón pero encima del contenido */
        transition: left .3s ease; 
    } 
    .sidebar.show { left: 0; } 
    
    /* Evita que el botón tape el contenido superior en el iframe en móviles */
    .content-frame {
        padding-top: 50px; 
    }
}

/* Estilos Agente IA preservados */
.ai-agent-wrap{ position:fixed; right:16px; bottom:16px; z-index:10050; font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
.ai-agent-toggle{ width:56px; height:56px; border-radius:50%; border:none; background:#0d6efd; color:#fff; font-size:22px; cursor:pointer; box-shadow:0 4px 20px rgba(13,110,253,.45); display:flex; align-items:center; justify-content:center; transition:transform .15s, background .15s; }
.ai-agent-panel{ display:none; position:absolute; right:0; bottom:68px; width:min(380px, calc(100vw - 32px)); height:min(520px, calc(100vh - 100px)); background:#0f172a; border-radius:16px; box-shadow:0 12px 48px rgba(0,0,0,.45); flex-direction:column; overflow:hidden; border:1px solid #1e293b; }
.ai-agent-panel.open{ display:flex; }
.ai-agent-head{ background:#020617; color:#f8fafc; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-shrink:0; border-bottom:1px solid #1e293b; }
.ai-agent-messages{ flex:1; overflow-y:auto; padding:14px; background:#0f172a; font-size:14px; line-height:1.5; }
.ai-agent-msg.user .ai-bubble{ background:#0d6efd; color:#fff; padding:10px 14px; border-radius:14px 14px 4px 14px; max-width:88%; }
.ai-agent-msg.assistant .ai-bubble{ background:#1e293b; color:#e2e8f0; padding:10px 14px; border-radius:14px 14px 14px 4px; max-width:calc(100% - 36px); border:1px solid #334155; }
.ai-agent-foot{ padding:12px; border-top:1px solid #1e293b; background:#020617; display:flex; gap:8px; }
.ai-agent-foot input{ flex:1; padding:12px 14px; border:1px solid #334155; border-radius:10px; background:#0f172a; color:#f1f5f9; }
.ai-agent-foot button.send{ width:48px; background:#0d6efd; color:#fff; border:none; border-radius:10px; cursor:pointer; }
</style>
</head>
<body>

<button id="toggleMenu" class="btn btn-primary d-md-none" style="position:fixed;top:10px;left:10px;z-index:1050;">☰ Módulos</button>

<div class="sidebar" id="sidebar">
    <div class="navbar-brand">SISTEMA BNMA</div>
    <div class="accordion accordion-flush px-2" id="menuPrincipal">

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#basico">
                    🔓 Opciones básicas
                </button>
            </h2>
            <div id="basico" class="accordion-collapse collapse show">
                <div class="accordion-body">
                    
                    <h6 class="text-muted ps-2 pt-2 pb-1 border-bottom" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        💰 Módulo Cajeros
                    </h6>
                    <div class="menu-seccion ps-2 mb-3">
                        <a class="nav-link" href="Transfers.php" target="contentFrame">➕ Grabar Transferencia</a>
                        <a class="nav-link" href="SolicitudAnulacion.php" target="contentFrame">🧮 Grabar Solicitud Anulación</a>                  
                        <a class="nav-link" href="ListaFactDia.php" target="contentFrame">🧮 Listado Facturas del Día</a>
                        <a class="nav-link" href="Calculadora.php" target="contentFrame">📄 Calculadora</a>
                        <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">🧮 Grabar Traslados Coord </a>                    
                        <a class="nav-link" href="buscaprecioventacero.php" target="contentFrame">🧮 Busca Precio Venta Cero</a>                    
                        <a class="nav-link" href="CierreDef.php" target="contentFrame">🧮 Cierre Diario Cajero </a>   
                        <a class="nav-link" href="minequi.php" 
                        onclick="const w = 450; const h = screen.availHeight; const l = screen.availWidth - w; window.open(this.href, 'Transfers Banco', `width=${w},height=${h},top=0,left=${l},scrollbars=yes,resizable=yes`); return false;">
                        🧮 Ver Transferencias Bre-B desde el Banco
                        </a>
                        <a class="nav-link" href="LectorEmailFacturas.php" target="contentFrame">🧮 Ver Que llega Hoy</a>   
                        <a class="nav-link" href="AuditoriaPedido.php" target="contentFrame"> 🧮 Auditoria Pedidos</a>                 
                    </div>

                    <?php if ($EsJefeBodega): ?>
                    <h6 class="text-muted ps-2 pt-2 pb-1 border-bottom" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        📦 Jefes de Bodega (JB)
                    </h6>
                    <div class="menu-seccion ps-2 mb-3">
                        <a class="nav-link" href="aprobacionanulacionJb.php" target="contentFrame">✅ Aprobación Anulación JB</a>
                        <a class="nav-link" href="TrasladosparaVerificar.php" target="contentFrame">✅ Aprobar Traslados JB</a>
                    </div>
                    <?php endif; ?>
                    
                    <h6 class="text-muted ps-2 pt-2 pb-1 border-bottom" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        ⚙️ General / Compartido
                    </h6>
                    <div class="menu-seccion ps-2">
                        <a class="nav-link" href="Conteo.php" target="contentFrame">🧮 Grabar Conteo Web</a>
                        <a class="nav-link" href="vertunel.php" target="contentFrame">🧮 Ver Tunel</a>                    
                        <a class="nav-link" href="#" onclick="window.open('Chat.php', 'ChatInterno', 'width=450,height=300,scrollbars=yes,resizable=No'); return false;">🧮 Chat Interno </a>
                    </div>

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
                                    <a class="nav-link" href="Compras.php" target="contentFrame">Ver Compras BNMA</a>
                                    <a class="nav-link" href="CierreCajerosTodos.php" target="contentFrame">Resumen de Cierres Bnma </a>
                                    
                                    <a class="nav-link" href="ValorInventariox.php" target="contentFrame">Dashboard Historico</a>    
                                    <a class="nav-link" href="DashBoard3.php" target="contentFrame">DashBoard Compras Vs Ventas</a>                                                                                                                
                                    <a class="nav-link" href="CierreCajeroBnma.php" target="contentFrame">Recaudo en Efectivo dia </a>
                                    <a class="nav-link" href="Promociones.php" target="contentFrame">Promociones</a>
                                    <a class="nav-link" href="ResumenVtas.php" target="contentFrame">Ventas BNMA</a>
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
                                    <a class="nav-link" href="Precios.php" target="contentFrame">Precios</a>
                                    <a class="nav-link" href="StockCentral.php" target="contentFrame">Stock Bnma</a>
                                    <a class="nav-link" href="TrasladosMercancia.php" target="contentFrame">Traslados</a>
                                    <a class="nav-link" href="ajustesdeInventario.php" target="contentFrame">Ajustes de Inventario</a>
                                    <a class="nav-link" href="Ver_ajustes_conteos.php" target="contentFrame">Ver Ajustes Conteos</a>
                                    <a class="nav-link" href="historicoConteos.php" target="contentFrame">Ver Historico de Conteos</a>
                                    <a class="nav-link" href="HistoricoConteoDiaaDia.php" target="contentFrame">Historico Conteos Día a Día</a>                                    
                                    <a class="nav-link" href="Busca_DuplicadosXNombre.php" target="contentFrame">Busca Duplicados X Nombre</a>
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
                                    <a class="nav-link" href="CarteraGraficarango.php" target="contentFrame">Grafica por Proveedor y Rango</a>
                                    <a class="nav-link" href="CarteraXProveedor.php" target="contentFrame">Cartera Proveedores</a>
                                    <a class="nav-link" href="CarteraXProveedorXfechas.php" target="contentFrame">Cartera por rango de fechas </a>
                                    <a class="nav-link" href="CarteraXProveedordiaadia.php" target="contentFrame">Cartera Proveedores por dia</a>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#adminEstad">
                                📈 Estadísticas
                            </button>
                            <div id="adminEstad" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <a class="nav-link" href="Estadistica_Vtas_mas.php" target="contentFrame">Lo más Vendido</a>
                                    <a class="nav-link" href="Estadistica_Vtas_Cajero.php" target="contentFrame">Lo más Vendido por Cajero</a>
                                    <a class="nav-link" href="Estadistica_Vtas_mas_tamaño.php" target="contentFrame">Lo más Vendido por tamaño</a>
                                    <a class="nav-link" href="Estadistica_Vtas_MesaMes.php" target="contentFrame">Lo más Vendido por Mes/Mes</a>
                                    <a class="nav-link" href="VtaDiaDiaXtamaño.php" target="contentFrame">Lo más Vendido por tamaño</a>
                                    <a class="nav-link" href="Estadistica_Vtas_mas_Empresa.php" target="contentFrame">Lo más Vendido por Empresa</a>
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
                                    <a class="nav-link" href="Productos.php" target="contentFrame">Lista Productos</a>
                                    <a class="nav-link" href="CategoriaProducto.php" target="contentFrame">Categoria Producto</a>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="mt-auto p-3 text-center" style="border-top: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.1);">
        <div class="text-white-50" style="font-size: 13px;">Bienvenido</div>
        <div class="text-white font-weight-bold"><strong><?=htmlspecialchars($UsuarioSesion)?></strong></div>
        <a href="Logout.php" target="_top" class="btn btn-outline-light btn-sm mt-2 w-100" style="border-color: rgba(255,255,255,0.4);">Cerrar sesión</a>
    </div>
</div>

<iframe name="contentFrame" class="content-frame" srcdoc="
    <html style='height:100%; margin:0; padding:0;'>
    <body style='margin:0; padding:0; height:100%; width:100%; overflow:hidden;'>
        <img src='ment_promo_breb.png' style='width:100%; height:100%; object-fit:cover;'>
    </body>
    </html>
"></iframe>


<?php if ($EsAgenteAdmin): ?>
<div class="ai-agent-wrap" id="aiAgentWrap">
    <div class="ai-agent-panel" id="aiAgentPanel" role="region" aria-label="Asistente Drinks">
        <div class="ai-agent-head">
            <div class="ai-agent-head-brand">
                <div class="ai-agent-head-icon" aria-hidden="true">💡</div>
                <div class="ai-agent-head-text">
                    <div class="title">Drinks</div>
                    <div class="sub">● Activo ahora</div>
                </div>
            </div>
            <div class="ai-agent-head-actions">
                <button type="button" class="ai-agent-icon ai-agent-new" id="aiAgentNewChat" title="Nuevo chat">🗑</button>
                <button type="button" class="ai-agent-icon ai-agent-close" id="aiAgentClose">✕</button>
            </div>
        </div>
        <div class="ai-agent-messages" id="aiAgentMessages"></div>
        <div class="ai-agent-suggestions" id="aiAgentSuggestions">
            <p class="hint">¿No sabes qué preguntar? Prueba una de estas:</p>
            <div class="chips">
                <button type="button" class="ai-suggestion-chip" data-q="¿Cuánto llevamos vendido hoy?">Ventas de hoy</button>
                <button type="button" class="ai-suggestion-chip" data-q="¿Qué productos tienen stock bajo?">Stock bajo</button>
                <button type="button" class="ai-suggestion-chip" data-q="Resume la cartera a proveedores.">Cartera proveedores</button>
            </div>
        </div>
        <form class="ai-agent-foot" id="aiAgentForm">
            <input type="text" id="aiAgentInput" placeholder="Pregúntame algo…">
            <button type="submit" class="send" id="aiAgentSend">➤</button>
        </form>
    </div>
    <button type="button" class="ai-agent-toggle" id="aiAgentToggle">💬</button>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js"></script>
<script>
// Toggle de apertura y cierre manual del sidebar (Móviles)
document.getElementById('toggleMenu').onclick = () => {
    document.getElementById('sidebar').classList.toggle('show');
};

// Cierra automáticamente el menú en celular al elegir una opción del menú
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
        }
    });
});

<?php if ($EsAgenteAdmin): ?>
(function () {
    var panel = document.getElementById('aiAgentPanel');
    var toggle = document.getElementById('aiAgentToggle');
    var closeBtn = document.getElementById('aiAgentClose');
    var newChatBtn = document.getElementById('aiAgentNewChat');
    var messagesEl = document.getElementById('aiAgentMessages');
    var form = document.getElementById('aiAgentForm');
    var input = document.getElementById('aiAgentInput');
    var sendBtn = document.getElementById('aiAgentSend');

    function setOpen(open) {
        panel.classList.toggle('open', open);
        toggle.textContent = open ? '✕' : '💬';
        if (open) input.focus();
    }

    toggle.addEventListener('click', () => setOpen(!panel.classList.contains('open')));
    closeBtn.addEventListener('click', () => setOpen(false));
    
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = (input.value || '').trim();
        if (!text) return;
        input.value = '';
    });
})();
<?php endif; ?>
</script>
</body>
</html>