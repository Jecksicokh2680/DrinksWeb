<?php
require 'helpers.php';
if (!isset($_SESSION['CambiarClave'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Cambiar clave</title></head>
<body>
  <h2>Actualizar clave</h2>
  <form method="post" action="cambiar_clave_procesar.php" autocomplete="off">
    <label>Nueva clave (mín 8 caracteres)</label><br>
    <input type="password" name="ClaveNueva" minlength="8" required><br><br>
    <label>Confirmar</label><br>
    <input type="password" name="ClaveNueva2" minlength="8" required><br><br>
    <button type="submit">Actualizar</button>
  </form>
</body>
</html>
