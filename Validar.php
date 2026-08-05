<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$session_timeout    = 3600;
$inactive_timeout   = 2700;

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
    SELECT u.PasswordHash, u.Bloqueado, u.DebeCambiarClave, u.Estado AS UsuarioActivo,
           e.Estado AS EmpresaActiva,
           s.Estado AS SucursalActiva,
           t.Nombre, t.NombreCom
    FROM usuarios_Seguridad u
    INNER JOIN empresa e 
        ON u.NitEmpresa = e.Nit
    INNER JOIN empresa_sucursal s 
        ON u.NitEmpresa = s.Nit 
        AND u.NroSucursal = s.NroSucursal
    LEFT JOIN terceros t 
        ON u.CedulaNit = t.CedulaNit
    WHERE u.CedulaNit=? AND u.NitEmpresa=? AND u.NroSucursal=?
");

$stmt->bind_param("sss", $cedula, $nit, $sucursal);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    if ($row['UsuarioActivo'] != 1) {
        header("Location: login.php?msg=Usuario inactivo");
        exit;
    }

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

        $nombreUsuario = $row['NombreCom'] ?: $row['Nombre'] ?: 'Usuario';

        // Guardado estructurado de variables de sesión
        $_SESSION['Usuario']        = $nombreUsuario; 
        $_SESSION['CedulaNit']      = $cedula;        
        $_SESSION['NitEmpresa']     = $nit;           
        $_SESSION['NroSucursal']    = $sucursal;      
        
        // Asignar sede predeterminada según el NIT de inicio de sesión
        if ($nit === '901724534-7') {
            $_SESSION['SedeActual'] = 'drinks';
        } else {
            $_SESSION['SedeActual'] = 'central';
        }

        // Registrar la sesión activa en la base de datos
        $id_sesion = session_id();
        $stmtSesion = $mysqli->prepare("
            REPLACE INTO sesiones_activas (id_sesion, CedulaNit, NitEmpresa, NroSucursal, fecha_ingreso) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($stmtSesion) {
            $stmtSesion->bind_param("ssss", $id_sesion, $cedula, $nit, $sucursal);
            $stmtSesion->execute();
            $stmtSesion->close();
        }

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