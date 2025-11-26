<?php
$host    = "52.15.192.69";
$usuario = "phpuser";
$pass    = "root";
$db      = "BnmaWeb";
$puerto  = 32768;
///usr/share/nginx/html/drinksWeb/
//echo "prueba";

$mysqli = new mysqli($host, $usuario, $pass, $base, $puerto);

if ($mysqli->connect_error) {
    die("Conexión fallida: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");

?>
