<?php
/**
 * Proxy de chat hacia DeepSeek API (OpenAI-compatible).
 * Requiere sesión válida. La clave va en DEEPSEEK_API_KEY (entorno o .env).
 */
declare(strict_types=1);

// Evitar que avisos/deprecaciones de PHP ensucien la salida JSON
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
}

session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['Usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/Conexion.php';
$usuarioSesion = (string) ($_SESSION['Usuario'] ?? '');
$autorizadoAgente = false;
if ($usuarioSesion !== '' && isset($mysqli) && !$mysqli->connect_error) {
    $stmtAuth = $mysqli->prepare(
        'SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ? LIMIT 1'
    );
    if ($stmtAuth) {
        $nro9999 = '9999';
        $stmtAuth->bind_param('ss', $usuarioSesion, $nro9999);
        if ($stmtAuth->execute()) {
            $ra = $stmtAuth->get_result();
            if ($ra && $ra->num_rows > 0) {
                $rowAuth = $ra->fetch_assoc();
                $autorizadoAgente = (($rowAuth['Swich'] ?? '') === 'SI');
            }
        }
    }
}
if (!$autorizadoAgente) {
    http_response_code(403);
    echo json_encode(
        ['error' => 'Solo usuarios con autorización 9999 (administrador) pueden usar el asistente.'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

foreach ([__DIR__ . '/.env', __DIR__ . '/Bk/.env'] as $envPath) {
    if (!is_readable($envPath)) {
        continue;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

$apiKey = getenv('DEEPSEEK_API_KEY') ?: ($_ENV['DEEPSEEK_API_KEY'] ?? '');
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Servicio no configurado (DEEPSEEK_API_KEY)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$messages = $data['messages'] ?? null;
if (!is_array($messages) || $messages === []) {
    http_response_code(400);
    echo json_encode(['error' => 'Se requiere messages (array no vacío)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxMsgs = 40;
if (count($messages) > $maxMsgs) {
    $messages = array_slice($messages, -$maxMsgs);
}

$clean = [];
foreach ($messages as $m) {
    if (!is_array($m)) {
        continue;
    }
    $role = $m['role'] ?? '';
    $content = isset($m['content']) ? (string) $m['content'] : '';
    if (!in_array($role, ['user', 'assistant'], true)) {
        continue;
    }
    if ($content === '') {
        continue;
    }
    $maxChars = 12000;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($content, 'UTF-8') > $maxChars) {
            $content = mb_substr($content, 0, $maxChars, 'UTF-8');
        }
    } elseif (strlen($content) > 40000) {
        $content = substr($content, 0, 40000);
    }
    $clean[] = ['role' => $role, 'content' => $content];
}

if ($clean === []) {
    http_response_code(400);
    echo json_encode(['error' => 'No hay mensajes válidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bloque de datos + API pueden superar el límite por defecto (30s) del servidor
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}
if (function_exists('ini_set')) {
    @ini_set('max_execution_time', '120');
}

require_once __DIR__ . '/agente_datos_contexto.php';
$bloqueDatos = agente_construir_bloque_datos();
if (strlen($bloqueDatos) > 120000) {
    $bloqueDatos = substr($bloqueDatos, 0, 120000) . "\n…[truncado]";
}

$systemPrompt = 'Eres el asistente del **Sistema Drinks** (uso interno). '
    . 'Responde en **español**, claro y directo. '
    . 'La fecha y hora de referencia del negocio y del usuario son **siempre** las de **Bogotá (America/Bogota, Colombia)**; '
    . 'aparecen al inicio del bloque de datos en vivo: úsalas para "hoy", "ahora" o comparaciones de tiempo. '
    . 'El bloque incluye resúmenes tipo Dashboard BNMA, compras del día, cartera a proveedores, transferencias, tops de venta, documentos y consecutivos del día, '
    . 'inventario bajo, **proyección de agotamiento (~7 días)** según ritmo de venta vs stock, '
    . 'y **conteos de inventario por categoría** (tabla conteoweb: stock sistema vs físico, diferencia, sede, día a día y hoy), etc. '
    . 'Para **cualquier cifra de negocio** usa **solo** ese bloque; '
    . 'no inventes números. '
    . '**No** digas cosas como "ve al módulo X" o "abre el menú": el usuario quiere la información aquí, '
    . 'así que responde con los datos del bloque (totales, fechas, productos listados). '
    . 'Si el bloque indica fallo de conexión o no hay datos, dilo sin inventar. '
    . 'En **cartera a proveedores**, enumera cada acreedor **por nombre** (el texto en negrita del bloque); no encabeces filas solo con el NIT. '
    . 'Para preguntas que no dependan de la base (procedimientos generales), puedes orientar sin inventar cifras.';

$systemPrompt .= "\n\n---\n## Datos en vivo del sistema (solo lectura)\n\n" . $bloqueDatos;

$payloadMessages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $clean
);

$payload = [
    'model' => 'deepseek-chat',
    'messages' => $payloadMessages,
    'temperature' => 0.6,
];

$url = 'https://api.deepseek.com/v1/chat/completions';

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL no disponible en el servidor'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
]);

$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $responseBody === '') {
    http_response_code(502);
    echo json_encode(['error' => 'Error al contactar el servicio de IA'], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($responseBody, true);
if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode(['error' => 'Respuesta inválida del servicio'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode >= 400) {
    $msg = $decoded['error']['message'] ?? 'Error del proveedor';
    http_response_code(502);
    echo json_encode(['error' => is_string($msg) ? $msg : 'Error del proveedor'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply = $decoded['choices'][0]['message']['content'] ?? null;
if (!is_string($reply) || $reply === '') {
    http_response_code(502);
    echo json_encode(['error' => 'Sin contenido en la respuesta'], JSON_UNESCAPED_UNICODE);
    exit;
}

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
echo json_encode(['reply' => $reply], $jsonFlags);
