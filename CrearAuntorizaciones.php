<?php
require 'conexion.php';
require 'helpers.php';

$CedulaNit = trim($_POST['CedulaNit'] ?? '');
$Clave = $_POST['Clave'] ?? '';

if ($CedulaNit === '' || $Clave === '') {
    header('Location: login.php?msg=' . urlencode('Datos incompletos'));
    exit;
}

// Parámetros de política
$MAX_INTENTOS = 5;
$BLOQUEO_SEGUNDOS = 60 * 15; // 15 minutos (si implementas desbloqueo por tiempo)

// Buscamos usuario activo
$sql = "SELECT CedulaNit, PasswordHash, PasswordSalt, IntentosFallidos, Bloqueado, DebeCambiarClave
        FROM usuarios_acceso
        WHERE CedulaNit = ? AND Estado = 1
        LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $CedulaNit);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    // No revelar si existe o no → mensaje genérico
    header('Location: login.php?msg=' . urlencode('Usuario o clave incorrectos'));
    exit;
}

$user = $res->fetch_assoc();

// Si usuario marcado como bloqueado
if ((int)$user['Bloqueado'] === 1) {
    header('Location: login.php?msg=' . urlencode('Cuenta bloqueada. Contacte al administrador'));
    exit;
}

// Verificamos contraseña
// Nota: usamos password_hash()/password_verify() si es posible.
// Si tu tabla tiene PasswordSalt/PasswordHash creada por otro algoritmo,
// aquí chequeamos con password_verify() y, si falla y tienes salt + SHA256 antiguo, podríamos migrar.
// Para simplicidad: asumimos password_hash() en PasswordHash.
$hashBD = $user['PasswordHash'];

$pass_ok = false;
if (password_verify($Clave, $hashBD)) {
    $pass_ok = true;
} else {
    // Si necesitases compatibilidad con hash antiguo tipo SHA256(sal+pass), puedes
    // comprobar aquí y luego re-hashear con password_hash().
    $pass_ok = false;
}

if (!$pass_ok) {
    // Incrementar intentos
    $upd = $mysqli->prepare("UPDATE usuarios_acceso
                             SET IntentosFallidos = IntentosFallidos + 1,
                                 Bloqueado = IF(IntentosFallidos + 1 >= ?, 1, Bloqueado)
                             WHERE CedulaNit = ?");
    $upd->bind_param('is', $MAX_INTENTOS, $CedulaNit);
    $upd->execute();

    header('Location: login.php?msg=' . urlencode('Usuario o clave incorrectos'));
    exit;
}

// Login exitoso: resetear intentos y actualizar FechaUltimoIngreso
$upd2 = $mysqli->prepare("UPDATE usuarios_acceso
                          SET IntentosFallidos = 0,
                              FechaUltimoIngreso = NOW()
                          WHERE CedulaNit = ?");
$upd2->bind_param('s', $CedulaNit);
$upd2->execute();

// Re-hashear si el hash usa método antiguo
if (password_needs_rehash($hashBD, PASSWORD_DEFAULT)) {
    $newHash = password_hash($Clave, PASSWORD_DEFAULT);
    $upd3 = $mysqli->prepare("UPDATE usuarios_acceso SET PasswordHash = ?, PasswordSalt = NULL, FechaUltimoCambio = NOW() WHERE CedulaNit = ?");
    $upd3->bind_param('ss', $newHash, $CedulaNit);
    $upd3->execute();
}

// Crear sesión segura
secure_session_regenerate();
$_SESSION['Usuario'] = $CedulaNit;

// Si debe cambiar clave
if ((int)$user['DebeCambiarClave'] === 1) {
    $_SESSION['CambiarClave'] = $CedulaNit;
    header('Location: cambiar_clave.php');
    exit;
}

// Redirigir al panel
header('Location: panel.php');
exit;
