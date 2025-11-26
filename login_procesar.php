<?php
require 'helpers.php';
$mysqli = conectarDB();

$cedula = limpiar($_POST['CedulaNit']);
$nit = limpiar($_POST['NitEmpresa']);
$sucursal = limpiar($_POST['NroSucursal']);
$pass = $_POST['Password'];

// Verificar usuario + empresa + sucursal
$stmt = $mysqli->prepare("
    SELECT u.PasswordHash, u.Bloqueado, u.DebeCambiarClave, 
           e.Estado AS EmpresaActiva,
           s.Estado AS SucursalActiva
    FROM usuarios_Seguridad u
    INNER JOIN empresa e ON u.NitEmpresa = e.Nit
    INNER JOIN empresa_sucursal s ON u.NitEmpresa = s.Nit AND u.NroSucursal = s.NroSucursal
    WHERE u.CedulaNit=? AND u.NitEmpresa=? AND u.NroSucursal=?
");
$stmt->bind_param("sss", $cedula, $nit, $sucursal);
$stmt->execute();
$result = $stmt->get_result();

if($row = $result->fetch_assoc()) {

    if($row['EmpresaActiva'] != 1) {
        header("Location: login.php?msg=Empresa inactiva");
        exit;
    }

    if($row['SucursalActiva'] != 1) {
        header("Location: login.php?msg=Sucursal inactiva");
        exit;
    }

    if($row['Bloqueado']) {
        header("Location: login.php?msg=Usuario bloqueado");
        exit;
    }

    if(password_verify($pass, $row['PasswordHash'])) {
        $_SESSION['Usuario'] = $cedula;
        actualizarUltimoIngreso($mysqli, $cedula, $nit, $sucursal);

        if($row['DebeCambiarClave']) {
            header("Location: cambiar_clave.php");
            exit;
        }

        header("Location: panel.php");
        exit;
    } else {
        registrarIntentoFallido($mysqli, $cedula, $nit, $sucursal);
        header("Location: login.php?msg=Usuario o contraseña incorrectos");
        exit;
    }

} else {
    header("Location: login.php?msg=Usuario no encontrado");
    exit;
}
