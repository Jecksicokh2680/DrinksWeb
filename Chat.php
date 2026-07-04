<?php
session_start();

// 1. Validar sesión
if (!isset($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit();
}

// 2. Incluir conexión (nombre correcto del archivo)
require_once 'Conexion.php';
if (isset($conn_error)) {
    die("Error en la conexión de base de datos: " . $conn_error);
}

$cedula = $_SESSION['Usuario'];

// ==========================================
// API: Obtener mensajes nuevos (AJAX GET)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

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
    echo json_encode(['status' => 'success', 'mensajes' => $mensajes]);
    exit();
}

// ==========================================
// API: Enviar mensaje (AJAX POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mensaje'])) {
    header('Content-Type: application/json; charset=utf-8');
    $mensaje = htmlspecialchars(trim($_POST['mensaje']));

    if ($mensaje !== '') {
        // BUSQUEDA CORREGIDA: Usa 'CedulaNit' que es el campo real de tu tabla terceros
        $stmtUser = $mysqli->prepare("SELECT Nombre FROM terceros WHERE CedulaNit = ? LIMIT 1");
        if ($stmtUser === false) {
            echo json_encode(['status' => 'error', 'msg' => 'Error en la consulta de terceros: ' . $mysqli->error]);
            exit();
        }
        
        $stmtUser->bind_param("s", $cedula);
        $stmtUser->execute();
        $resultadoUser = $stmtUser->get_result();

        if ($rowUser = $resultadoUser->fetch_assoc()) {
            $nombre_usuario = $rowUser['Nombre'];
        } else {
            $nombre_usuario = "Usuario (" . $cedula . ")";
        }
        $stmtUser->close();

        // Guardar mensaje en la tabla del chat
        $stmtChat = $mysqli->prepare("INSERT INTO chat_mensajes (cedula, nombre_usuario, mensaje) VALUES (?, ?, ?)");
        if ($stmtChat === false) {
            echo json_encode(['status' => 'error', 'msg' => 'Error al preparar inserción: ' . $mysqli->error]);
            exit();
        }
        
        $stmtChat->bind_param("sss", $cedula, $nombre_usuario, $mensaje);
        $stmtChat->execute();
        $insertId = $mysqli->insert_id;
        $stmtChat->close();

        echo json_encode([
            'status' => 'success',
            'id'     => $insertId
        ]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Mensaje vacío']);
    }
    exit();
}

// ==========================================
// VISTA: Cargar historial inicial (últimos 50)
// ==========================================
$resultado = $mysqli->query("SELECT * FROM (SELECT id, cedula, nombre_usuario, mensaje, fecha_hora FROM chat_mensajes ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Interno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="data:,">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f0f2f5; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }

        .chat-container {
            max-width: 720px;
            margin: 20px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 40px);
            overflow: hidden;
        }

        .chat-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .chat-header h6 { margin: 0; font-size: 16px; font-weight: 600; }
        .chat-header .badge { background: rgba(255,255,255,.15); font-size: 12px; padding: 4px 10px; border-radius: 20px; }

        #chat-box {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            background: #f0f2f5;
        }
        #chat-box::after {
            content: '';
            display: block;
            clear: both;
        }

        .mensaje {
            margin-bottom: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            max-width: 75%;
            word-wrap: break-word;
            position: relative;
            clear: both;
        }
        .recibido {
            background: #fff;
            float: left;
            border: 1px solid #e5e7eb;
            border-radius: 4px 12px 12px 12px;
        }
        .enviado {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            float: right;
            border-radius: 12px 4px 12px 12px;
        }
        .meta-info {
            font-size: 11px;
            color: #6b7280;
            display: block;
            margin-bottom: 3px;
            font-weight: 600;
        }
        .enviado .meta-info { color: rgba(255,255,255,.75); }
        .msg-time {
            font-size: 10px;
            color: #9ca3af;
            text-align: right;
            margin-top: 4px;
        }
        .enviado .msg-time { color: rgba(255,255,255,.6); }

        .chat-footer {
            padding: 14px 20px;
            border-top: 1px solid #e5e7eb;
            background: #fff;
            flex-shrink: 0;
        }
        .chat-footer form {
            display: flex;
            gap: 10px;
        }
        .chat-footer input {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #d1d5db;
            border-radius: 24px;
            font-size: 14px;
            outline: none;
            transition: border-color .2s;
        }
        .chat-footer input:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
        .chat-footer button {
            padding: 10px 20px;
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: #fff;
            border: none;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform .1s, box-shadow .2s;
        }
        .chat-footer button:hover { transform: scale(1.03); box-shadow: 0 4px 12px rgba(13,110,253,.3); }
        .chat-footer button:active { transform: scale(.97); }

        .clearfix::after { content: ''; display: block; clear: both; }

        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .status-online { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.5); }
        .status-offline { background: #ef4444; }
    </style>
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <h6><span class="status-dot status-online" id="statusDot"></span> 💬 Chat Interno</h6>
        <span class="badge">👤 <?php echo htmlspecialchars($cedula); ?></span>
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

<script>
    const miCedula = "<?php echo htmlspecialchars($cedula, ENT_QUOTES); ?>";
    const chatBox  = document.getElementById('chat-box');
    let lastId     = <?php echo $lastId; ?>;

    function scrollToBottom() {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
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
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Polling dinámico al mismo archivo
    async function fetchNewMessages() {
        try {
            const resp = await fetch('?action=fetch&last_id=' + lastId);
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();

            if (json.status === 'success' && json.mensajes.length > 0) {
                json.mensajes.forEach(msg => {
                    appendMessage(msg);
                    lastId = msg.id;
                });
            }
            document.getElementById('statusDot').className = 'status-dot status-online';
        } catch (e) {
            console.warn('Error al obtener mensajes:', e);
            document.getElementById('statusDot').className = 'status-dot status-offline';
        }
    }

    setInterval(fetchNewMessages, 3000);

    // Enviar mensaje de manera asíncrona
    document.getElementById('form-chat').addEventListener('submit', async function(e) {
        e.preventDefault();
        const input   = document.getElementById('input-mensaje');
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
                await fetchNewMessages();
            } else if (json.status === 'error') {
                alert('Error: ' + json.msg);
            }
        } catch (e) {
            console.error('Error al enviar mensaje:', e);
            alert('No se pudo enviar el mensaje. Intenta de nuevo.');
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