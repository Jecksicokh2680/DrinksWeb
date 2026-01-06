<?php
require 'Conexion.php';
session_start();

/* =====================================================
   SEGURIDAD
===================================================== */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

/* =====================================================
   CSRF
===================================================== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* =====================================================
   FUNCIONES
===================================================== */
function check_csrf($p, $s) {
    if (!$p || !hash_equals($s, $p)) {
        http_response_code(403);
        die("CSRF inválido");
    }
}

/* =====================================================
   PROCESAR CREACIÓN
===================================================== */
$mensaje = "";

if (isset($_POST['crear'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);

    $nombre = trim($_POST['Nombre']);

    if ($nombre === '') {
        $mensaje = "❌ El nombre es obligatorio";
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO empresas_productoras (Nombre)
            VALUES (?)
        ");
        $stmt->bind_param("s", $nombre);

        if ($stmt->execute()) {
            $mensaje = "✅ Empresa creada correctamente";
        } else {
            $mensaje = "❌ Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

/* =====================================================
   LISTADO
===================================================== */
$empresas = [];
$res = $mysqli->query("
    SELECT IdEmpresa, Nombre
    FROM empresas_productoras
    ORDER BY Nombre
");

while ($r = $res->fetch_assoc()) {
    $empresas[] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Empresas Productoras</title>

<style>
:root{
--bg:#f1f4f8;
--card:#ffffff;
--pri:#1f3c88;
--line:#e5e7eb;
--ok:#198754;
--err:#dc3545;
}
*{box-sizing:border-box}
body{
margin:0;
font-family:Segoe UI,Roboto,Arial;
background:var(--bg);
color:#111;
}
.container{
max-width:900px;
margin:auto;
padding:20px;
}
.card{
background:var(--card);
border-radius:14px;
padding:20px;
box-shadow:0 8px 24px rgba(0,0,0,.06);
margin-bottom:25px;
}
h2{
margin:0 0 15px;
color:var(--pri);
font-size:20px;
}
form{
display:grid;
grid-template-columns:1fr auto;
gap:12px;
}
input,button{
padding:12px;
font-size:15px;
border-radius:10px;
border:1px solid var(--line);
}
button{
background:var(--pri);
color:#fff;
border:none;
cursor:pointer;
}
.mensaje{
margin-bottom:15px;
font-weight:600;
}
.mensaje.ok{color:var(--ok)}
.mensaje.err{color:var(--err)}

table{
width:100%;
border-collapse:collapse;
}
th,td{
padding:12px;
border-bottom:1px solid var(--line);
text-align:left;
}
th{
background:#111827;
color:#fff;
font-size:14px;
}
@media(max-width:640px){
form{
grid-template-columns:1fr;
}
table thead{display:none}
table tr{
display:block;
margin-bottom:12px;
border:1px solid var(--line);
border-radius:10px;
padding:10px;
}
table td{
display:flex;
justify-content:space-between;
padding:6px 0;
border:none;
}
table td::before{
content:attr(data-label);
font-weight:600;
color:#374151;
}
}
</style>
</head>

<body>
<div class="container">

<div class="card">
<h2>🏢 Crear Empresa Productora</h2>

<?php if ($mensaje): ?>
<p class="mensaje <?= strpos($mensaje,'✅')!==false?'ok':'err' ?>">
    <?= htmlspecialchars($mensaje) ?>
</p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="text" name="Nombre" placeholder="Nombre de la empresa" required>
<button name="crear">Crear</button>
</form>
</div>

<div class="card">
<h2>📋 Empresas Registradas</h2>

<table>
<thead>
<tr>
<th>ID</th>
<th>Empresa</th>
</tr>
</thead>
<tbody>
<?php foreach ($empresas as $e): ?>
<tr>
<td data-label="ID"><?= $e['IdEmpresa'] ?></td>
<td data-label="Empresa"><?= htmlspecialchars($e['Nombre']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if (empty($empresas)): ?>
<p>No hay empresas registradas.</p>
<?php endif; ?>

</div>

</div>
</body>
</html>
