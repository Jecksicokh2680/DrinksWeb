<?php
session_start();
if (!isset($_SESSION['Usuario'])) {
    header("Location: login.php");
    exit;
}
?>

<h2>Bienvenido: <?php echo $_SESSION['Usuario']; ?></h2>
<a href="logout.php">Cerrar sesión</a>
<?php
require 'helpers.php';
if (!isset($_SESSION['Usuario'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Panel</title></head>
<body>
  <h2>Bienvenido, <?= e($_SESSION['Usuario']) ?></h2>
  <p><a href="logout.php">Cerrar sesión</a></p>
  <hr>
  <!-- Contenido del panel -->
</body>
</html>
