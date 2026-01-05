<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

/* =======================
   SEGURIDAD
======================= */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* =======================
   CONSTANTES
======================= */
const TIPOS_CATEGORIA = [
    "Cerveza","Gaseosa","Agua","Jugo","Hidratante",
    "Energizante","Dulceria","Maltas","Papeleria",
    "Suero","Aseo","Elementos"
];

/* =======================
   FUNCIONES
======================= */
function check_csrf($p,$s){
    if(!$p || !hash_equals($s,$p)) die("CSRF inválido");
}

function get_tipo_map(){
    $m=[];
    foreach(TIPOS_CATEGORIA as $t) $m[substr($t,0,2)]=$t;
    return $m;
}

function collect_category_data($create=false){
    return [
        'CodCat'    => $create ? strtoupper($_POST['CodCat']) : $_POST['CodCat'],
        'Nombre'    => trim($_POST['Nombre']),
        'IdEmpresa' => $_POST['IdEmpresa']!=='' ? intval($_POST['IdEmpresa']) : null,
        'SegWebF'   => isset($_POST['SegWebF'])?'1':'0',
        'SegWebT'   => isset($_POST['SegWebT'])?'1':'0',
        'Unicaja'   => intval($_POST['Unicaja'] ?? 1),
        'Estado'    => $_POST['Estado']=='1'?'1':'0',
        'Tipo'      => substr($_POST['Tipo'],0,2)
    ];
}

/* =======================
   EMPRESAS
======================= */
$empresas=[];
$res=$mysqli->query("SELECT IdEmpresa,Nombre FROM empresas_productoras ORDER BY Nombre");
while($e=$res->fetch_assoc()) $empresas[]=$e;

/* =======================
   PROCESOS
======================= */
$mensaje="";

if(isset($_POST['crear'])){
    check_csrf($_POST['csrf_token'],$csrf_token);
    $d=collect_category_data(true);
    $st=$mysqli->prepare("
        INSERT INTO categorias
        (CodCat,Nombre,IdEmpresa,SegWebF,SegWebT,Unicaja,Estado,Tipo)
        VALUES(?,?,?,?,?,?,?,?)
    ");
    $st->bind_param("ssississ",$d['CodCat'],$d['Nombre'],$d['IdEmpresa'],
        $d['SegWebF'],$d['SegWebT'],$d['Unicaja'],$d['Estado'],$d['Tipo']);
    $st->execute();
    $mensaje="Categoría creada correctamente";
}

if(isset($_POST['actualizar'])){
    check_csrf($_POST['csrf_token'],$csrf_token);
    $d=collect_category_data();
    $st=$mysqli->prepare("
        UPDATE categorias SET
        Nombre=?,IdEmpresa=?,SegWebF=?,SegWebT=?,Unicaja=?,Estado=?,Tipo=?
        WHERE CodCat=?
    ");
    $st->bind_param("sississs",$d['Nombre'],$d['IdEmpresa'],$d['SegWebF'],
        $d['SegWebT'],$d['Unicaja'],$d['Estado'],$d['Tipo'],$d['CodCat']);
    $st->execute();
    $mensaje="Categoría actualizada";
}

/* =======================
   LISTADO
======================= */
$tipo_map=get_tipo_map();
$categorias=[];
$sql="
SELECT c.*, e.Nombre Empresa
FROM categorias c
LEFT JOIN empresas_productoras e ON e.IdEmpresa=c.IdEmpresa
ORDER BY c.CodCat";
$res=$mysqli->query($sql);
while($r=$res->fetch_assoc()){
    $r['Tipo_Completo']=$tipo_map[$r['Tipo']]??$r['Tipo'];
    $categorias[]=$r;
}
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
--card:#fff;
--pri:#1f3c88;
--sec:#6c757d;
--ok:#198754;
--danger:#dc3545;
--line:#e5e7eb;
}
*{box-sizing:border-box}
body{
margin:0;
font-family:Segoe UI,Roboto;
background:var(--bg);
color:#111;
}
.container{max-width:1200px;margin:auto;padding:20px}
.card{
background:var(--card);
border-radius:14px;
padding:20px;
box-shadow:0 6px 20px rgba(0,0,0,.05);
margin-bottom:25px;
}
h2{
margin:0 0 15px;
font-size:20px;
color:var(--pri);
}
.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
gap:12px;
}
input,select,button{
width:100%;
padding:10px;
border:1px solid var(--line);
border-radius:8px;
font-size:14px;
}
button{
background:var(--pri);
color:#fff;
border:none;
cursor:pointer;
}
button.sec{background:var(--ok)}
table{
width:100%;
border-collapse:collapse;
margin-top:10px;
}
th,td{
padding:10px;
border-bottom:1px solid var(--line);
font-size:14px;
text-align:center;
}
th{
background:#111827;
color:#fff;
}
.badge{
padding:4px 8px;
border-radius:6px;
font-size:12px;
}
.badge.on{background:#d1fae5;color:#065f46}
.badge.off{background:#fee2e2;color:#7f1d1d}
@media(max-width:768px){
table thead{display:none}
table tr{display:block;margin-bottom:15px}
table td{
display:flex;
justify-content:space-between;
padding:8px;
}
table td::before{
content:attr(data-label);
font-weight:600;
}
}
</style>
</head>

<body>
<div class="container">

<div class="card">
<h2>➕ Nueva Categoría</h2>
<?php if($mensaje) echo "<b>$mensaje</b>"; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<div class="grid">
<input name="CodCat" placeholder="Código" required>
<input name="Nombre" placeholder="Nombre" required>

<select name="IdEmpresa">
<option value="">Empresa</option>
<?php foreach($empresas as $e): ?>
<option value="<?= $e['IdEmpresa'] ?>"><?= $e['Nombre'] ?></option>
<?php endforeach; ?>
</select>

<select name="Tipo" required>
<?php foreach(TIPOS_CATEGORIA as $t): ?>
<option value="<?= $t ?>"><?= $t ?></option>
<?php endforeach; ?>
</select>

<input type="number" name="Unicaja" value="1">
<select name="Estado"><option value="1">Activo</option><option value="0">Inactivo</option></select>
<label><input type="checkbox" name="SegWebF"> SegF</label>
<label><input type="checkbox" name="SegWebT"> SegT</label>
<button name="crear">Crear</button>
</div>
</form>
</div>

<div class="card">
<h2>📋 Categorías</h2>

<table>
<thead>
<tr>
<th>Código</th><th>Nombre</th><th>Empresa</th><th>Tipo</th>
<th>SegF</th><th>SegT</th><th>Unicaja</th><th>Estado</th><th></th>
</tr>
</thead>
<tbody>
<?php foreach($categorias as $c): ?>
<tr>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="hidden" name="CodCat" value="<?= $c['CodCat'] ?>">

<td data-label="Código"><?= $c['CodCat'] ?></td>
<td data-label="Nombre"><input name="Nombre" value="<?= $c['Nombre'] ?>"></td>
<td data-label="Empresa">
<select name="IdEmpresa">
<option value="">—</option>
<?php foreach($empresas as $e): ?>
<option value="<?= $e['IdEmpresa'] ?>" <?= $e['IdEmpresa']==$c['IdEmpresa']?'selected':'' ?>>
<?= $e['Nombre'] ?>
</option>
<?php endforeach; ?>
</select>
</td>
<td data-label="Tipo"><?= $c['Tipo_Completo'] ?></td>
<td data-label="SegF"><?= $c['SegWebF']=='1'?'✔':'' ?></td>
<td data-label="SegT"><?= $c['SegWebT']=='1'?'✔':'' ?></td>
<td data-label="Unicaja"><input type="number" name="Unicaja" value="<?= $c['Unicaja'] ?>"></td>
<td data-label="Estado">
<select name="Estado">
<option value="1" <?= $c['Estado']=='1'?'selected':'' ?>>Activo</option>
<option value="0" <?= $c['Estado']=='0'?'selected':'' ?>>Inactivo</option>
</select>
</td>
<td><button class="sec" name="actualizar">Guardar</button></td>
</form>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>
</body>
</html>
