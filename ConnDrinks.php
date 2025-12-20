<?php
// --- Parámetros de Conexión ---
$host    = "52.15.192.69";
$usuario = "aws_user";
$pass    = "root";
$db      = "empresa001";
$puerto  = 3308;
global $mysqliPos;  // empresa001
$mysqliPos = new mysqli($host, $usuario, $pass, $db, $puerto);
if ($mysqliPos->connect_error) {
    $conn_error = "❌ La conexión a la base de datos (empresa001) falló: " . $mysqliPos->connect_error;
   } else {
    // $mysqliPos->set_charset("utf8mb4");
}
// Ahora, el script que incluye este archivo puede revisar la variable $conn_error
?>