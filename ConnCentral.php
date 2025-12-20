<?php
// --- Parámetros de Conexión ---
$host    = "52.15.192.69";
$usuario = "aws_user";
$pass    = "root";
$db      = "empresa001";
$puerto  = 3307;

// Variable de conexión
$mysqliPos = new mysqli($host, $usuario, $pass, $db, $puerto);

// --- Verificación de Conexión y Detención si falla ---
if ($mysqliPos->connect_error) {
    // Definimos una variable de error para usarla en los scripts principales
    $conn_error = "❌ Conexión a la base de datos (empresa001) fallida: " . $mysqliPos->connect_error;
    // Detenemos la ejecución aquí, el resto del código no se ejecuta
    // Usaremos die() solo en este archivo si la conexión es crítica.
    // Para scripts de producción, a veces se usa return o un manejo más suave, 
    // pero para empezar, die() es el más seguro.
    die($conn_error); 
} 

// --- Configuración Post-Conexión ---
$mysqliPos->set_charset("utf8mb4");

// Si la conexión fue exitosa, $mysqliPos está definida.
$conn_error = null;

?>