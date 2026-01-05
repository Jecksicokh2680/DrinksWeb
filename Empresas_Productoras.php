<?php
require 'Conexion.php';
session_start();

/* =========================
   SEGURIDAD
========================= */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

/* =========================
   CONSULTA
========================= */
$sql = "
SELECT 
    c.CodCat,
    c.Nombre,
    e.Nombre AS Empresa,
    c.Tipo,
    c.SegWebF,
    c.SegWebT,
    c.Unicaja,
    c.Estado
FROM categorias c
LEFT JOIN empresas_productoras e ON c.IdEmpresa = e.IdEmpresa
ORDER BY c.Nombre
";

$res = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Categorías</title>

<style>
:root{
--bg:#f1f4f8;
--card:#ffffff;
--pri:#1f3c88;
--line:#e5e7eb;
--header:#0f172a;
}
*{box-sizing:border-box}

body{
margin:0;
font-family:Segoe UI,Roboto,Arial;
background:var(--bg);
color:#111;
}

/* ================= HEADER FIJO ================= */
.header{
position:fixed;
top:0; left:0; right:0;
height:64px;
background:var(--header);
color:#fff;
display:flex;
align-items:center;
padding:0 20px;
font-size:18px;
font-weight:600;
z-index:1000;
box-shadow:0 4px 12px rgba(0,0,0,.2);
}

/* ================= CONTENIDO ================= */
.container{
max-width:1200px;
margin:auto;
padding:90px 20px 20px;
}

.card{
background:var(--card);
border-radius:14px;
padding:20px;
box-shadow:0 8px 24px rgba(0,0,0,.06);
}

/* ================= TABLA ================= */
.table-wrap{
max-height:480px;
overflow:auto;
border-radius:12px;
}

table{
width:100%;
border-collapse:collapse;
white-space:nowrap;
}

thead th{
position:sticky;
top:0;
background:#111827;
color:#fff;
z-index:20;
font-size:13px;
text-transform:uppercase;
letter-spacing:.4px;
border-bottom:2px solid #000;
}

th,td{
padding:10px 12px;
border-bottom:1px solid var(--line);
text-align:left;
}

/* ================= RESPONSIVE ================= */
@media(max-width:640px){

.table-wrap{max-height:none}

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

<div class="header">
📊 Listado de Categorías
</div>

<div class="container">
<div class="card">

<div class="table-wrap">
<table>
<thead>
<tr>
<th>Código</th>
<th>Nombre</th>
<th>Empresa</th>
<th>Tipo</th>
<th>SegF</th>
<th>SegT</th>
<th>Unicaja</th>
<th>Estado</th>
</tr>
</thead>
<tbody>

<?php if ($res && $res->num_rows > 0): ?>
<?php while ($r = $res->fetch_assoc()): ?>
<tr>
<td data-label="Código"><?= htmlspecialchars($r['CodCat']) ?></td>
<td data-label="Nombre"><?= htmlspecialchars($r['Nombre']) ?></td>
<td data-label="Empresa"><?= htmlspecialchars($r['Empresa'] ?? '—') ?></td>
<td data-label="Tipo"><?= htmlspecialchars($r['Tipo']) ?></td>
<td data-label="SegF"><?= htmlspecialchars($r['SegWebF']) ?></td>
<td data-label="SegT"><?= htmlspecialchars($r['SegWebT']) ?></td>
<td data-label="Unicaja"><?= $r['Unicaja'] ?></td>
<td data-label="Estado"><?= $r['Estado'] ?></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="8">No hay categorías registradas</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>

</div>
</div>

</body>
</html>
