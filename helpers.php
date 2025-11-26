<?php
session_start();

function limpiar($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function estaLogueado() {
    return !empty($_SESSION['Usuario']);
}
?>
