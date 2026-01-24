<?php
$host    = "52.15.192.69";
$usuario = "root";
$pass    = "root";
$db      = "BnmaWeb";
$puerto  = 32768;

$mysqli = new mysqli($host, $usuario, $pass, $db, $puerto);
if ($mysqli->connect_error) {
    $conn_error = "❌ La conexión a la base de datos (empresa001) falló: " . $mysqli->connect_error;
   } else {
    // $mysqliPos->set_charset("utf8mb4");
}
// Ahora, el script que incluye este archivo puede revisar la variable $conn_error
global $mysqliWeb;  // empresa001
$mysqliWeb = $mysqli;
?>