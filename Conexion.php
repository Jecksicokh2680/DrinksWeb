<?php
$host    = "52.15.192.69";
$usuario = "root";
$pass    = "root";
$db      = "BnmaWeb";
$puerto  = 32768;

$mysqli = new mysqli($host, $usuario, $pass, $db, $puerto);

if ($mysqli->connect_error) {
    die("Conexión fallidaaaaa: " . $mysqli->connect_error);
} else {
     //echo "✓ Conexión exitosa a la base de datosss\n";
}

$mysqli->set_charset("utf8mb4");

?>
