<?php
session_start();
session_destroy();
header("Location: Login.php?msg=Sesión cerrada");
exit;
