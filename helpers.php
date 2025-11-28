<?php

function limpiar($dato) {
    return htmlspecialchars(trim($dato), ENT_QUOTES, 'UTF-8');
}

function actualizarUltimoIngreso($cedula, $nit, $sucursal) {
    global $mysqli;
    $stmt = $mysqli->prepare("
        UPDATE usuarios_Seguridad 
        SET FechaUltimoIngreso = NOW(), IntentosFallidos = 0 
        WHERE CedulaNit=? AND NitEmpresa=? AND NroSucursal=?
    ");
    $stmt->bind_param("sss", $cedula, $nit, $sucursal);
    $stmt->execute();
}

function registrarIntentoFallido($cedula, $nit, $sucursal) {
    global $mysqli;
    $stmt = $mysqli->prepare("
        UPDATE usuarios_Seguridad 
        SET IntentosFallidos = IntentosFallidos + 1 
        WHERE CedulaNit=? AND NitEmpresa=? AND NroSucursal=?
    ");
    $stmt->bind_param("sss", $cedula, $nit, $sucursal);
    $stmt->execute();
}
