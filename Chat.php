<?php
session_start();

// 1. Validar sesión
if (!isset($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit();
}

// 2. Incluir conexión
require_once 'Conexion.php';
if (isset($conn_error)) {
    die("Error en la conexión de base de datos: " . $conn_error);
}

$cedula = $_SESSION['Usuario'];

// ==========================================
// LÓGICA: Registrar o actualizar mi actividad activa
// ==========================================
$stmtUser = $mysqli->prepare("SELECT Nombre FROM terceros WHERE CedulaNit = ? LIMIT 1");
$stmtUser->bind_param("s", $cedula);
$stmtUser->execute();
$resultadoUser = $stmtUser->get_result();
$nombre_actual = ($rowUser = $resultadoUser->fetch_assoc()) ? $rowUser['Nombre'] : "Usuario (" . $cedula . ")";
$stmtUser->close();

// Insertar o actualizar la marca de tiempo de presencia
$stmtPresencia = $mysqli->prepare("INSERT INTO chat_usuarios_activos (cedula, nombre_usuario, ultima_actividad) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE nombre_usuario = ?, ultima_actividad = CURRENT_TIMESTAMP");
$stmtPresencia->bind_param("sss", $cedula, $nombre_actual, $nombre_actual);
$stmtPresencia->execute();
$stmtPresencia->close();


// ==========================================
// API: Obtener mensajes y lista de usuarios en tiempo real (AJAX GET)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Volver a actualizar actividad en la petición de Polling
    $mysqli->query("UPDATE chat_usuarios_activos SET ultima_actividad = CURRENT_TIMESTAMP WHERE cedula = '$cedula'");

    $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

    // 1. Traer mensajes nuevos
    $stmt = $mysqli->prepare("SELECT id, cedula, nombre_usuario, mensaje, fecha_hora FROM chat_mensajes WHERE id > ? ORDER BY id ASC LIMIT 50");
    $stmt->bind_param("i", $lastId);
    $stmt->execute();
    $result = $stmt->get_result();
    $mensajes = [];
    while ($row = $result->fetch_assoc()) {
        $mensajes[] = [
            'id'              => (int)$row['id'],
            'cedula'          => $row['cedula'],
            'nombre_usuario'  => $row['nombre_usuario'],
            'mensaje'         => $row['mensaje'],
            'fecha_hora'      => $row['fecha_hora']
        ];
    }
    $stmt->close();

    // 2. Traer todos los usuarios registrados con su estado (Conectado si interactuó hace < 12 segundos)
    $resUsers = $mysqli->query("SELECT cedula, nombre_usuario, (CASE WHEN ultima_actividad >= NOW() - INTERVAL 12 SECOND THEN 1 ELSE 0 END) as en_linea FROM chat_usuarios_activos ORDER BY en_linea DESC, nombre_usuario ASC");
    $listaUsuarios = [];
    while($u = $resUsers->fetch_assoc()) {
        $listaUsuarios[] = [
            'cedula' => $u['cedula'],
            'nombre' => $u['nombre_usuario'],
            'online' => (int)$u['en_linea']
        ];
    }

    echo json_encode([
        'status' => 'success', 
        'mensajes' => $mensajes,
        'usuarios' => $listaUsuarios
    ]);
    exit();
}

// ==========================================
// API: Enviar mensaje (AJAX POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mensaje'])) {
    header('Content-Type: application/json; charset=utf-8');
    $mensaje = htmlspecialchars(trim($_POST['mensaje']));

    if ($mensaje !== '') {
        $stmtChat = $mysqli->prepare("INSERT INTO chat_mensajes (cedula, nombre_usuario, mensaje) VALUES (?, ?, ?)");
        $stmtChat->bind_param("sss", $cedula, $nombre_actual, $mensaje);
        $stmtChat->execute();
        $insertId = $mysqli->insert_id;
        $stmtChat->close();

        echo json_encode(['status' => 'success', 'id' => $insertId]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Mensaje vacío']);
    }
    exit();
}

// Cargar historial inicial de mensajes (últimos 50)
$resultado = $mysqli->query("SELECT * FROM (SELECT id, cedula, nombre_usuario, mensaje, fecha_hora FROM chat_mensajes ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Chat Interno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="data:,">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e3e7e9; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; height: 100vh; height: -webkit-fill-available; display: flex; align-items: center; justify-content: center; }

        /* Contenedor Principal */
        .app-container {
            width: 100%;
            max-width: 1200px;
            height: 100vh;
            background: #fff;
            display: flex;
            overflow: hidden;
            box-shadow: 0 2px 24px rgba(0,0,0,0.1);
        }

        /* BARRA LATERAL (Usuarios) */
        .sidebar {
            width: 30%;
            min-width: 280px;
            background: #fff;
            border-right: 1px solid #e9edef;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }
        .sidebar-header {
            background: #f0f2f5;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e9edef;
            font-size: 14px;
            font-weight: 600;
        }
        .user-list {
            flex: 1;
            overflow-y: auto;
            background: #fff;
        }
        .user-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f2f5;
            transition: background 0.2s;
            cursor: pointer;
        }
        .user-item:hover { background: #f0f2f5; }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #e9edef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #54656f;
            margin-right: 12px;
            position: relative;
            flex-shrink: 0;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-name { font-size: 14px; font-weight: 500; color: #111b21; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-status { font-size: 12px; color: #667781; }

        /* AREA DE CHAT */
        .chat-area {
            width: 70%;
            display: flex;
            flex-direction: column;
            background: #efeae2; 
            height: 100%;
            transition: all 0.3s ease;
        }
        .chat-header {
            background: #f0f2f5;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e9edef;
            flex-shrink: 0;
            gap: 10px;
        }
        .chat-header h6 { margin: 0; font-size: 15px; font-weight: 600; color: #111b21; }

        #chat-box {
            flex: 1;
            overflow-y: auto;
            padding: 16px 24px;
        }

        /* Burbujas */
        .mensaje {
            margin-bottom: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            max-width: 75%;
            word-wrap: break-word;
            clear: both;
            box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
            position: relative;
        }
        .recibido {
            background: #fff;
            float: left;
            border-radius: 0px 8px 8px 8px;
        }
        .enviado {
            background: #d9fdd3; 
            color: #111b21;
            float: right;
            border-radius: 8px 0px 8px 8px;
        }
        .meta-info { font-size: 11px; color: #008069; display: block; font-weight: 600; margin-bottom: 2px; }
        .enviado .meta-info { color: #0b5ed7; display: none; } 
        .msg-time { font-size: 10px; color: #667781; text-align: right; margin-top: 4px; }

        /* Footer */
        .chat-footer {
            padding: 10px 16px;
            background: #f0f2f5;
            border-top: 1px solid #e9edef;
            flex-shrink: 0;
        }
        .chat-footer form { display: flex; gap: 12px; }
        .chat-footer input {
            flex: 1;
            padding: 9px 12px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            outline: none;
            background: #fff;
        }
        .chat-footer button {
            background: #00a884;
            border: none;
            color: #fff;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            padding: 8px 16px;
            transition: background 0.2s;
        }
        .chat-footer button:hover { background: #008f70; }

        .clearfix::after { content: ''; display: block; clear: both; }

        /* Puntos de estado */
        .badge-status { width: 10px; height: 10px; border-radius: 50%; position: absolute; bottom: 0; right: 0; border: 2px solid #fff; }
        .status-online { background: #1fa952; }
        .status-offline { background: #8696a0; }

        /* Botón de regreso para móviles */
        .btn-back {
            background: none;
            border: none;
            font-size: 20px;
            color: #54656f;
            cursor: pointer;
            padding: 0 8px;
            display: none;
        }

        /* ==========================================
           RESPONSIVIDAD MÓVIL (MÁXIMO 767px)
           ========================================== */
        @media (max-width: 767.98px) {
            body { height: 100vh; }
            .app-container { height: 100vh; }
            
            /* Vista por defecto: se muestra lista, se oculta chat */
            .sidebar { width: 100%; display: flex; border-right: none; }
            .chat-area { width: 100%; display: none; }

            /* Clase activa controlada por JS para cambiar de vista */
            .show-chat .sidebar { display: none; }
            .show-chat .chat-area { display: flex; }
            .show-chat .btn-back { display: block; }

            #chat-box { padding: 12px 16px; }
            .mensaje { max-width: 85%; }
        }

        @media (min-width: 768px) {
            .app-container { height: calc(100vh - 40px); border-radius: 8px; }
        }
    </style>
</head>
<body>

<div class="app-container" id="app-wrapper">
    <div class="sidebar">
        <div class="sidebar-header">
            <span>👥 Personal / Usuarios</span>
            <span class="text-muted" style="font-size:11px;">Mi ID: <?php echo htmlspecialchars($cedula); ?></span>
        </div>
        <div class="user-list" id="lista-usuarios">
            <div class="p-3 text-center text-muted small">Cargando personal...</div>
        </div>
    </div>

    <div class="chat-area">
        <div class="chat-header">
            <button class="btn-back" id="btn-back-to-list" title="Volver a la lista">←</button>
            <h6>💬 Sala de Conversación General</h6>
        </div>

        <div id="chat-box">
            <?php
            $lastId = 0;
            if ($resultado):
                while ($row = $resultado->fetch_assoc()):
                    $esMio = ($row['cedula'] == $cedula);
                    $clase = $esMio ? 'enviado' : 'recibido';
                    $lastId = (int)$row['id'];
                    $hora = !empty($row['fecha_hora']) ? date('h:i a', strtotime($row['fecha_hora'])) : '';
            ?>
                    <div class="mensaje <?php echo $clase; ?>">
                        <span class="meta-info"><?php echo htmlspecialchars($row['nombre_usuario']); ?></span>
                        <div><?php echo htmlspecialchars($row['mensaje']); ?></div>
                        <?php if ($hora): ?><div class="msg-time"><?php echo $hora; ?></div><?php endif; ?>
                    </div>
            <?php
                endwhile;
            endif;
            ?>
            <div class="clearfix"></div>
        </div>

        <div class="chat-footer">
            <form id="form-chat">
                <input type="text" id="input-mensaje" placeholder="Escribe un mensaje..." required autocomplete="off">
                <button type="submit">Enviar</button>
            </form>
        </div>
    </div>
</div>

<script>
    const miCedula = "<?php echo htmlspecialchars($cedula, ENT_QUOTES); ?>";
    const chatBox  = document.getElementById('chat-box');
    const containerUsuarios = document.getElementById('lista-usuarios');
    const appWrapper = document.getElementById('app-wrapper');
    const btnBack = document.getElementById('btn-back-to-list');
    let lastId     = <?php echo $lastId; ?>;

    // Manejo de navegación responsiva (clics en lista/regresar)
    btnBack.addEventListener('click', () => {
        appWrapper.classList.remove('show-chat');
    });

    function scrollToBottom() { chatBox.scrollTop = chatBox.scrollHeight; }
    scrollToBottom();

    function formatTime(fechaStr) {
        if (!fechaStr) return '';
        const d = new Date(fechaStr.replace(' ', 'T'));
        if (isNaN(d)) return '';
        let h = d.getHours(), m = d.getMinutes();
        const ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    }

    function appendMessage(data) {
        const esMio = (data.cedula == miCedula);
        const clase = esMio ? 'enviado' : 'recibido';
        const hora  = formatTime(data.fecha_hora);

        const clearfix = chatBox.querySelector('.clearfix');
        if (clearfix) clearfix.remove();

        const div = document.createElement('div');
        div.className = 'mensaje ' + clase;
        div.innerHTML = `
            <span class="meta-info">${escapeHtml(data.nombre_usuario)}</span>
            <div>${escapeHtml(data.mensaje)}</div>
            ${hora ? '<div class="msg-time">' + hora + '</div>' : ''}
        `;
        chatBox.appendChild(div);

        const cf = document.createElement('div');
        cf.className = 'clearfix';
        chatBox.appendChild(cf);

        scrollToBottom();
    }

    function escapeHtml(text) {
        const div = document.createElement('div'); div.textContent = text; return div.innerHTML;
    }

    function renderUsuarios(usuarios) {
        containerUsuarios.innerHTML = '';
        if(usuarios.length === 0) {
            containerUsuarios.innerHTML = '<div class="p-3 text-center text-muted small">Sin usuarios registrados</div>';
            return;
        }

        usuarios.forEach(u => {
            const inicial = u.nombre.charAt(0).toUpperCase();
            const statusClass = u.online === 1 ? 'status-online' : 'status-offline';
            const statusText = u.online === 1 ? 'En línea' : 'Ausente';

            const item = document.createElement('div');
            item.className = 'user-item';
            // Evento para abrir el chat en dispositivos móviles al tocar al usuario
            item.addEventListener('click', () => {
                if(window.innerWidth < 768) {
                    appWrapper.classList.add('show-chat');
                    scrollToBottom();
                }
            });

            item.innerHTML = `
                <div class="user-avatar">
                    ${inicial}
                    <span class="badge-status ${statusClass}"></span>
                </div>
                <div class="user-info">
                    <div class="user-name">${escapeHtml(u.nombre)}</div>
                    <div class="user-status">${statusText}</div>
                </div>
                <div class="d-md-none text-muted small px-2">👉 Ver Chat</div>
            `;
            containerUsuarios.appendChild(item);
        });
    }

    function playNotificationSound() {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain();
            osc.type = 'sine'; osc.frequency.setValueAtTime(600, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.08, audioCtx.currentTime); gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.15);
            osc.connect(gain); gain.connect(audioCtx.destination);
            osc.start(); osc.stop(audioCtx.currentTime + 0.15);
        } catch (e) {}
    }

    // Polling Unificado
    async function fetchUpdates() {
        try {
            const resp = await fetch('?action=fetch&last_id=' + lastId);
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();

            if (json.status === 'success') {
                if(json.usuarios) renderUsuarios(json.usuarios);

                if (json.mensajes && json.mensajes.length > 0) {
                    let sonar = false;
                    json.mensajes.forEach(msg => {
                        appendMessage(msg);
                        lastId = msg.id;
                        if (msg.cedula != miCedula) sonar = true;
                    });
                    if (sonar) playNotificationSound();
                }
            }
        } catch (e) {
            console.warn('Error en comunicación remota:', e);
        }
    }

    fetchUpdates();
    setInterval(fetchUpdates, 3000);

    // Enviar formulario
    document.getElementById('form-chat').addEventListener('submit', async function(e) {
        e.preventDefault();
        const input = document.getElementById('input-mensaje');
        const mensaje = input.value.trim();
        if (!mensaje) return;

        input.value = '';
        input.focus();

        try {
            const resp = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'mensaje=' + encodeURIComponent(mensaje)
            });
            const json = await resp.json();
            if (json.status === 'success') {
                await fetchUpdates();
            }
        } catch (e) {
            alert('Error de red al enviar.');
        }
    });

    document.getElementById('input-mensaje').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('form-chat').dispatchEvent(new Event('submit'));
        }
    });
</script>
</body>
</html>