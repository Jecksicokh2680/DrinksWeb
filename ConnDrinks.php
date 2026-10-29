<?php
// --- Cargar el archivo .env si existe de forma manual ---
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

// --- Parámetros de Conexión ---
$host    = getenv('DB_HOST') ?: "52.15.192.69";
$usuario = getenv('DB_USER') ?: "aws_user";
$pass    = getenv('DB_PASS') ?: "root";
$db      = "empresa001";
$puerto  = (int)(getenv('DB_PORT_DRINKS') ?: 3308);

global $mysqliPos;  // empresa001
global $mysqliDrinks;

$mysqliPos = new mysqli($host, $usuario, $pass, $db, $puerto);

if ($mysqliPos->connect_error) {
    $conn_error = "❌ La conexión a la base de datos (empresa001) falló: " . $mysqliPos->connect_error;
} else {
    // $mysqliPos->set_charset("utf8mb4");
}

// Ahora, el script que incluye este archivo puede revisar la variable $conn_error
$mysqliDrinks = $mysqliPos;
?>