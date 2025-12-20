<?php
$host    = "52.15.192.69";
$usuario = "aws_user";
$pass    = "root";
$db      = "Empresa001";
$puerto  = 3307;

$mysqliPos = new mysqliPos($host, $usuario, $pass, $db, $puerto);
if ($mysqliPos->connect_error) {
    die("Conexión fallidaaaaa: " . $mysqli->connect_error);
} else {
     //echo "✓ Conexión exitosa a la base de datosss\n";
}
$mysqliPos->set_charset("utf8mb4");
?>
