<?php
// ValidarSesion.php

// Asegurar que la sesión esté iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que existan las variables básicas de sesión
if (!isset($_SESSION['CedulaNit']) || !isset($_SESSION['NitEmpresa'])) {
    session_unset();
    session_destroy();
    header("Location: Login.php?msg=Debe iniciar sesion");
    exit;
}

// Requerir conexión a la base de datos (asumiendo que $mysqli está definido aquí)
require_once 'Conexion.php';

// Comprobar si el session_id actual sigue registrado en la base de datos
$id_actual = session_id();
$stmtCheck = $mysqli->prepare("SELECT 1 FROM sesiones_activas WHERE id_sesion = ?");
if ($stmtCheck) {
    $stmtCheck->bind_param("s", $id_actual);
    $stmtCheck->execute();
    $stmtCheck->store_result();

    if ($stmtCheck->num_rows === 0) {
        // Si el administrador lo expulsó o la sesión fue borrada de la BD
        $stmtCheck->close();
        session_unset();
        session_destroy();
        header("Location: Login.php?msg=Sesion cerrada por el administrador");
        exit;
    }
    $stmtCheck->close();
}