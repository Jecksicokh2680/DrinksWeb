<?php
// auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['Usuario'])) {
    // Si se accede desde un iframe, esto lo rompe y redirige la ventana completa
    echo "<script>window.top.location.href='Login.php?msg=Su sesión ha expirado';</script>";
    exit;
}
?>