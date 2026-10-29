<?php
// Cargar el archivo .env si existe de forma manual (para entorno local con túnel SSH)
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

// Variables dinámicas (si hay .env usa el túnel local; si no, usa la IP de producción)
$host    = getenv('DB_HOST') ?: "52.15.192.69";
$usuario = getenv('DB_USER') ?: "root";
$pass    = getenv('DB_PASS') ?: "root";
$db      = "BnmaWeb";
$puerto  = (int)(getenv('DB_PORT_BNMA') ?: 32768);

$mysqli = new mysqli($host, $usuario, $pass, $db, $puerto);
if ($mysqli->connect_error) {
    $conn_error = "❌ La conexión a la base de datos (BnmaWeb) falló: " . $mysqli->connect_error;
} else {
    // $mysqli->set_charset("utf8mb4");
}

// Ahora, el script que incluye este archivo puede revisar la variable $conn_error
global $mysqliWeb;  // BnmaWeb
$mysqliWeb = $mysqli;
?>