<?php
// --- Parámetros de Conexión ---
$host    = "52.15.192.69";
$usuario = "aws_user";
$pass    = "root";
$db      = "empresa001";
$puerto  = 3307;

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