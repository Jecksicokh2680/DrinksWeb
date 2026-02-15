<?php
session_start();
require("Conexion.php"); 

// Asegurar zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha  = $_POST['fecha'];
    $nit    = $_POST['nit'];
    $monto  = (float)$_POST['monto'];
    $sede   = $_POST['sede'];
    $usuario_sesion = $_SESSION['Usuario'] ?? 'SISTEMA';
    
    // Generar fecha y hora actual de Bogotá
    $fecha_registro_bogota = date('Y-m-d H:i:s');

    // INSERT IGNORE para evitar duplicados
    $stmt = $mysqli->prepare("INSERT IGNORE INTO cierres_caja 
        (FechaCorte, NitFacturador, MontoFinal, Sede, UsuarioCierre, FechaRegistro) 
        VALUES (?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("ssdsss", $fecha, $nit, $monto, $sede, $usuario_sesion, $fecha_registro_bogota);
    
    if($stmt->execute()){
        echo "Cierre registrado con hora de Bogotá: " . $fecha_registro_bogota;
    } else {
        echo "Error al registrar";
    }
}