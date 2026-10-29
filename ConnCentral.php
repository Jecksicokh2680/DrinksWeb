<?php
// --- Llamana .env nisqamanta lloqsiqkunata maskanapaq ---
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

// --- Parámetros de Conexión (.env nisqamanta otaq chaylla kasqanmanta) ---
$host    = getenv('DB_HOST') ?: "52.15.192.69";
$usuario = getenv('DB_USER') ?: "aws_user";
$pass    = getenv('DB_PASS') ?: "root";
$db      = "empresa001";
$puerto  = (int)(getenv('DB_PORT_CENTRAL') ?: 3307);

global $mysqliPos;  
global $mysqliCentral;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    
    $mysqliPos = new mysqli($host, $usuario, $pass, $db, $puerto);
    
    // Cambiado de utf8mb4 a utf8 (compatible con versiones anteriores de MySQL)
    $mysqliPos->set_charset("utf8");
    
    $mysqliCentral = $mysqliPos;
    

} catch (mysqli_sql_exception $e) {
    die("❌ Error de conexión a la base de datos (empresa001): " . $e->getMessage());
}
?>