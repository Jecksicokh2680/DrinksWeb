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

/* Agente IA flotante (tema oscuro tipo Lifo) */
.ai-agent-wrap{
    position:fixed;
    right:16px;
    bottom:16px;
    z-index:10050;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
}
.ai-agent-toggle{
    width:56px;
    height:56px;
    border-radius:50%;
    border:none;
    background:#0d6efd;
    color:#fff;
    font-size:22px;
    cursor:pointer;
    box-shadow:0 4px 20px rgba(13,110,253,.45);
    display:flex;
    align-items:center;
    justify-content:center;
    transition:transform .15s, background .15s;
}
.ai-agent-toggle:hover{ background:#0b5ed7; transform:scale(1.04); }
.ai-agent-toggle:focus-visible{ outline:3px solid #9ec5fe; outline-offset:2px; }
.ai-agent-panel{
    display:none;
    position:absolute;
    right:0;
    bottom:68px;
    width:min(380px, calc(100vw - 32px));
    height:min(520px, calc(100vh - 100px));
    background:#0f172a;
    border-radius:16px;
    box-shadow:0 12px 48px rgba(0,0,0,.45);
    flex-direction:column;
    overflow:hidden;
    border:1px solid #1e293b;
}
.ai-agent-panel.open{ display:flex; }
.ai-agent-head{
    background:#020617;
    color:#f8fafc;
    padding:12px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-shrink:0;
    border-bottom:1px solid #1e293b;
}
.ai-agent-head-brand{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
}
.ai-agent-head-icon{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#0d6efd;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    flex-shrink:0;
}
.ai-agent-head-text{ min-width:0; }
.ai-agent-head-text .title{ font-weight:700; font-size:16px; line-height:1.2; }
.ai-agent-head-text .sub{ font-size:12px; color:#9ec5fe; margin-top:2px; }
.ai-agent-head-actions{
    display:flex;
    align-items:center;
    gap:4px;
}
.ai-agent-head .ai-agent-icon{
    background:transparent;
    border:none;
    color:#94a3b8;
    width:36px;
    height:36px;
    border-radius:8px;
    cursor:pointer;
    font-size:14px;
    line-height:1;
    display:flex;
    align-items:center;
    justify-content:center;
}
.ai-agent-head .ai-agent-icon:hover{ color:#e2e8f0; background:#1e293b; }
.ai-agent-head .ai-agent-new:disabled,
.ai-agent-head .ai-agent-close:disabled{ opacity:.4; cursor:not-allowed; }
.ai-agent-messages{
    flex:1;
    overflow-y:auto;
    padding:14px;
    background:#0f172a;
    font-size:14px;
    line-height:1.5;
}
.ai-agent-msg{ margin-bottom:14px; max-width:100%; word-wrap:break-word; }
.ai-agent-msg.user{
    display:flex;
    justify-content:flex-end;
}
.ai-agent-msg.user .ai-bubble{
    background:#0d6efd;
    color:#fff;
    padding:10px 14px;
    border-radius:14px 14px 4px 14px;
    max-width:88%;
    font-size:14px;
}
.ai-agent-msg.assistant{
    display:flex;
    justify-content:flex-start;
    align-items:flex-start;
    gap:8px;
}
.ai-agent-msg.assistant .ai-avatar{
    width:28px;
    height:28px;
    border-radius:8px;
    background:#0b5ed7;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:14px;
    flex-shrink:0;
    margin-top:2px;
}
.ai-agent-msg.assistant .ai-bubble{
    background:#1e293b;
    color:#e2e8f0;
    padding:10px 14px;
    border-radius:14px 14px 14px 4px;
    max-width:calc(100% - 36px);
    border:1px solid #334155;
}
.ai-agent-msg.assistant .ai-md-body{ font-size:14px; }
.ai-agent-msg.assistant .ai-md-body p{ margin:0 0 .6em; }
.ai-agent-msg.assistant .ai-md-body p:last-child{ margin-bottom:0; }
.ai-agent-msg.assistant .ai-md-body strong{ font-weight:700; color:#fff; }
.ai-agent-msg.assistant .ai-md-body ul,.ai-agent-msg.assistant .ai-md-body ol{ margin:.4em 0 .6em; padding-left:1.2em; }
.ai-agent-msg.assistant .ai-md-body code{ background:#0f172a; padding:.1em .35em; border-radius:4px; font-size:0.9em; }
.ai-agent-msg.assistant .ai-md-body pre{ background:#020617; padding:10px; border-radius:8px; overflow:auto; margin:.5em 0; }
.ai-agent-msg.assistant .ai-md-body a{ color:#93c5fd; }
.ai-agent-thinking{
    display:flex;
    align-items:center;
    gap:8px;
    color:#94a3b8;
    font-size:13px;
    padding:6px 0 4px 36px;
}
.ai-agent-thinking .dots{ display:inline-flex; gap:4px; }
.ai-agent-thinking .dots span{
    width:6px;
    height:6px;
    border-radius:50%;
    background:#0d6efd;
    animation:aiDot 1.2s ease-in-out infinite;
}
.ai-agent-thinking .dots span:nth-child(2){ animation-delay:.2s; }
.ai-agent-thinking .dots span:nth-child(3){ animation-delay:.4s; }
@keyframes aiDot{
    0%,80%,100%{ opacity:.25; transform:scale(.85); }
    40%{ opacity:1; transform:scale(1); }
}
.ai-agent-foot{
    padding:12px;
    border-top:1px solid #1e293b;
    background:#020617;
    display:flex;
    gap:8px;
    align-items:stretch;
}
.ai-agent-foot input{
    flex:1;
    padding:12px 14px;
    border:1px solid #334155;
    border-radius:10px;
    font-size:14px;
    background:#0f172a;
    color:#f1f5f9;
}
.ai-agent-foot input::placeholder{ color:#64748b; }
.ai-agent-foot input:focus{ outline:none; border-color:#0d6efd; box-shadow:0 0 0 1px #0d6efd; }
.ai-agent-foot button.send{
    width:48px;
    min-width:48px;
    padding:0;
    background:#0d6efd;
    color:#fff;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
    font-size:18px;
    line-height:1;
    display:flex;
    align-items:center;
    justify-content:center;
}
.ai-agent-foot button.send:hover{ background:#0b5ed7; }
.ai-agent-foot button.send:disabled{ opacity:.45; cursor:not-allowed; }
.ai-agent-err{
    color:#f87171;
    font-size:12px;
    padding:0 14px 8px;
    background:#0f172a;
}
.ai-agent-suggestions{
    padding:10px 12px 6px;
    border-top:1px solid #1e293b;
    background:#0b1220;
    flex-shrink:0;
    max-height:120px;
    overflow-y:auto;
}
.ai-agent-suggestions .hint{
    font-size:11px;
    color:#64748b;
    margin:0 0 8px;
    letter-spacing:.02em;
}
.ai-agent-suggestions .chips{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
.ai-suggestion-chip{
    font-size:12px;
    line-height:1.3;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid #334155;
    background:#1e293b;
    color:#e2e8f0;
    cursor:pointer;
    text-align:left;
    max-width:100%;
}
.ai-suggestion-chip:hover{
    border-color:#0d6efd;
    background:#1e3a5f;
    color:#fff;
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
                <button type="button" class="ai-agent-icon ai-agent-new" id="aiAgentNewChat" title="Nuevo chat" aria-label="Nuevo chat">🗑</button>
                <button type="button" class="ai-agent-icon ai-agent-close" id="aiAgentClose" aria-label="Cerrar asistente">✕</button>
            </div>
        </div>
        <div class="ai-agent-messages" id="aiAgentMessages" tabindex="0"></div>
        <div class="ai-agent-suggestions" id="aiAgentSuggestions" aria-label="Ejemplos de preguntas">
            <p class="hint">¿No sabes qué preguntar? Prueba una de estas:</p>
            <div class="chips">
                <button type="button" class="ai-suggestion-chip" data-q="¿Cuánto llevamos vendido hoy en total entre Central y Drinks, y cuál es el neto del día?">Ventas y neto de hoy</button>
                <button type="button" class="ai-suggestion-chip" data-q="¿Qué productos están por debajo del stock mínimo en Central y en Drinks?">Stock bajo (mínimo)</button>
                <button type="button" class="ai-suggestion-chip" data-q="Según ventas recientes e inventario, ¿qué productos podrían agotarse en los próximos días?">Riesgo de agotarse</button>
                <button type="button" class="ai-suggestion-chip" data-q="Resume la cartera a proveedores: total y principales deudores.">Cartera proveedores</button>
                <button type="button" class="ai-suggestion-chip" data-q="¿Cuáles son los productos más vendidos hoy y los del mes?">Top vendido hoy y mes</button>
                <button type="button" class="ai-suggestion-chip" data-q="¿Cuántas facturas y pedidos van hoy en Central y Drinks, y qué rango de consecutivos tienen?">Facturas y pedidos hoy</button>
                <button type="button" class="ai-suggestion-chip" data-q="¿Cuánto suman las compras del día y las transferencias entre cuentas en los últimos 7 días?">Compras y transferencias</button>
            </div>
        </div>
        <div class="ai-agent-err" id="aiAgentErr" hidden></div>
        <form class="ai-agent-foot" id="aiAgentForm">
            <label for="aiAgentInput" class="visually-hidden">Mensaje</label>
            <input type="text" id="aiAgentInput" name="msg" autocomplete="off" placeholder="Pregúntame algo…" maxlength="4000">
            <button type="submit" class="send" id="aiAgentSend" aria-label="Enviar" title="Enviar">➤</button>
        </form>
    </div>
    <button type="button" class="ai-agent-toggle" id="aiAgentToggle" aria-expanded="false" aria-controls="aiAgentPanel" title="Abrir asistente">💬</button>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js"></script>
<script>
document.getElementById('toggleMenu').onclick = () => {
    document.getElementById('sidebar').classList.toggle('show');
};

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
    var errEl = document.getElementById('aiAgentErr');
    var history = [];
    var fetchAbort = null;

    function setOpen(open) {
        panel.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open ? '✕' : '💬';
        toggle.setAttribute('title', open ? 'Cerrar asistente' : 'Abrir asistente');
        if (open) {
            input.focus();
        }
    }

    function showErr(msg) {
        errEl.textContent = msg || '';
        errEl.hidden = !msg;
    }

    function renderMarkdown(md) {
        var src = String(md || '');
        if (typeof marked !== 'undefined' && typeof marked.parse === 'function') {
            try {
                marked.setOptions({ breaks: true, gfm: true });
                var html = marked.parse(src);
                if (typeof DOMPurify !== 'undefined' && typeof DOMPurify.sanitize === 'function') {
                    return DOMPurify.sanitize(html, {
                        ALLOWED_TAGS: ['p','br','strong','em','b','i','u','ul','ol','li','code','pre','blockquote','a','h1','h2','h3','h4','hr'],
                        ALLOWED_ATTR: ['href','target','rel']
                    });
                }
                return html;
            } catch (e) {}
        }
        var div = document.createElement('div');
        div.textContent = src;
        var esc = div.innerHTML;
        esc = esc.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        esc = esc.replace(/\n/g, '<br>');
        return esc;
    }

    function appendUserBubble(text) {
        var row = document.createElement('div');
        row.className = 'ai-agent-msg user';
        var bubble = document.createElement('div');
        bubble.className = 'ai-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendAssistantBubble(mdText) {
        var row = document.createElement('div');
        row.className = 'ai-agent-msg assistant';
        var av = document.createElement('div');
        av.className = 'ai-avatar';
        av.setAttribute('aria-hidden', 'true');
        av.textContent = '💡';
        var bubble = document.createElement('div');
        bubble.className = 'ai-bubble';
        var inner = document.createElement('div');
        inner.className = 'ai-md-body';
        inner.innerHTML = renderMarkdown(mdText);
        bubble.appendChild(inner);
        row.appendChild(av);
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    var thinkingEl = null;
    function showThinking() {
        removeThinking();
        thinkingEl = document.createElement('div');
        thinkingEl.className = 'ai-agent-thinking';
        thinkingEl.setAttribute('role', 'status');
        thinkingEl.setAttribute('aria-live', 'polite');
        thinkingEl.innerHTML = '<span>Pensando</span><span class="dots" aria-hidden="true"><span></span><span></span><span></span></span>';
        messagesEl.appendChild(thinkingEl);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
    function removeThinking() {
        if (thinkingEl && thinkingEl.parentNode) {
            thinkingEl.parentNode.removeChild(thinkingEl);
        }
        thinkingEl = null;
    }

    function nuevoChat() {
        if (fetchAbort) {
            try { fetchAbort.abort(); } catch (e) {}
            fetchAbort = null;
        }
        history = [];
        messagesEl.innerHTML = '';
        thinkingEl = null;
        showErr('');
        sendBtn.disabled = false;
        input.focus();
    }

    toggle.addEventListener('click', function () {
        setOpen(!panel.classList.contains('open'));
    });
    closeBtn.addEventListener('click', function () {
        setOpen(false);
    });
    newChatBtn.addEventListener('click', function () {
        nuevoChat();
    });

    var sugg = document.getElementById('aiAgentSuggestions');
    if (sugg) {
        sugg.addEventListener('click', function (ev) {
            var btn = ev.target && ev.target.closest ? ev.target.closest('.ai-suggestion-chip') : null;
            if (!btn || !form) return;
            var q = btn.getAttribute('data-q') || '';
            if (!q) return;
            input.value = q;
            setOpen(true);
            form.requestSubmit();
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = (input.value || '').trim();
        if (!text) return;
        showErr('');
        appendUserBubble(text);
        input.value = '';
        history.push({ role: 'user', content: text });
        sendBtn.disabled = true;
        showThinking();

        fetchAbort = new AbortController();

        fetch('agente_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: history }),
            credentials: 'same-origin',
            signal: fetchAbort.signal
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('El servidor no devolvió JSON. Revisa agente_chat.php o los logs de PHP.');
                    }
                    if (!r.ok) {
                        throw new Error((data && data.error) || ('Error ' + r.status));
                    }
                    return data;
                });
            })
            .then(function (data) {
                removeThinking();
                var reply = data.reply || '';
                history.push({ role: 'assistant', content: reply });
                appendAssistantBubble(reply);
            })
            .catch(function (err) {
                removeThinking();
                if (err.name === 'AbortError') {
                    return;
                }
                showErr(err.message || 'No se pudo enviar el mensaje.');
                history.pop();
                if (messagesEl.lastChild) {
                    messagesEl.removeChild(messagesEl.lastChild);
                }
            })
            .finally(function () {
                removeThinking();
                fetchAbort = null;
                sendBtn.disabled = false;
                input.focus();
            });
    });
})();
<?php endif; ?>
</script>

</body>
</html>