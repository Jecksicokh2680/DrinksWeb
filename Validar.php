<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$session_timeout   = 3600;
$inactive_timeout  = 1800;

require 'Conexion.php';
require 'helpers.php';

$cedula   = limpiar($_POST['CedulaNit'] ?? '');
$nit      = limpiar($_POST['NitEmpresa'] ?? '');
$sucursal = limpiar($_POST['NroSucursal'] ?? '');
$pass     = $_POST['Password'] ?? '';

if ($cedula=="" || $nit=="" || $sucursal=="" || $pass=="") {
    header("Location: Login.php?msg=Faltan datos");
    exit;
}

$stmt = $mysqli->prepare("
    SELECT u.PasswordHash, u.Bloqueado, u.DebeCambiarClave,
           e.Estado AS EmpresaActiva,
           s.Estado AS SucursalActiva
    FROM usuarios_Seguridad u
    INNER JOIN empresa e 
        ON u.NitEmpresa = e.Nit
    INNER JOIN empresa_sucursal s 
        ON u.NitEmpresa = s.Nit 
        AND u.NroSucursal = s.NroSucursal
    WHERE u.CedulaNit=? AND u.NitEmpresa=? AND u.NroSucursal=?
");

$stmt->bind_param("sss", $cedula, $nit, $sucursal);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    if ($row['EmpresaActiva'] != 1) {
        header("Location: login.php?msg=Empresa inactiva");
        exit;
    }

    if ($row['SucursalActiva'] != 1) {
        header("Location: login.php?msg=Sucursal inactiva");
        exit;
    }

    if ($row['Bloqueado']) {
        header("Location: login.php?msg=Usuario bloqueado");
        exit;
    }

    if (password_verify($pass, $row['PasswordHash'])) {

        $_SESSION['Usuario']     = $cedula;
        $_SESSION['NitEmpresa']  = $nit;
        $_SESSION['NroSucursal'] = $sucursal;

        actualizarUltimoIngreso($cedula, $nit, $sucursal);

        header("Location: Panel.php");
        exit;

    } else {
        registrarIntentoFallido($cedula, $nit, $sucursal);
        header("Location: Login.php?msg=Usuario o contraseña incorrectos");
        exit;
    }

} else {
    header("Location: Login.php?msg=Usuario no encontrado");
    exit;
}
