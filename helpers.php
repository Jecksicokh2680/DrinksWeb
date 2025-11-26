<?php
session_start();

function limpiar($dato) {
    return htmlspecialchars(trim($dato), ENT_QUOTES, 'UTF-8');
}

function conectarDB() {
    $mysqli = new mysqli("localhost", "tu_usuario", "tu_password", "tu_base");
    if ($mysqli->connect_errno) {
        die("Error de conexión: " . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

function actualizarUltimoIngreso($mysqli, $cedula, $nit, $sucursal) {
    $stmt = $mysqli->prepare("UPDATE usuarios_Seguridad SET FechaUltimoIngreso = NOW(), IntentosFallidos = 0 WHERE CedulaNit=? AND NitEmpresa=? AND NroSucursal=?");
    $stmt->bind_param("sss", $cedula, $nit, $sucursal);
    $stmt->execute();
}

function registrarIntentoFallido($mysqli, $cedula, $nit, $sucursal) {
    $stmt = $mysqli->prepare("UPDATE usuarios_Seguridad SET IntentosFallidos = IntentosFallidos + 1 WHERE CedulaNit=? AND NitEmpresa=? AND NroSucursal=?");
    $stmt->bind_param("sss", $cedula, $nit, $sucursal);
    $stmt->execute();
}
?>
