<?php
session_start();
$_SESSION = array(); // Limpiar variables
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"]);
}
session_destroy();
?>
<!DOCTYPE html>
<html>
<body>
    <script>
        alert("Sesión cerrada correctamente.");
        // window.close(); // Intenta cerrar la ventana
        window.location.href = "login.php";
    </script>
</body>
</html>