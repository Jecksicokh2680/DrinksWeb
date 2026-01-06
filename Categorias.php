<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

/* ============================================================
   SEGURIDAD / SESIÓN
============================================================ */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

/* ============================================================
   CSRF TOKEN
============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ============================================================
   CONSTANTES
============================================================ */
const TIPOS_CATEGORIA = [
    "Cerveza", "Gaseosa", "Agua", "Jugo", "Hidratante",
    "Energizante", "Dulceria", "Maltas", "Papeleria",
    "Suero", "Aseo", "Elementos"
];

/* ============================================================
   FUNCIONES
============================================================ */
function check_csrf(string $posted, string $session) {
    if (!$posted || !hash_equals($session, $posted)) {
        http_response_code(403);
        die("Token CSRF inválido");
    }
}

function get_tipo_map(): array {
    $map = [];
    foreach (TIPOS_CATEGORIA as $t) {
        $map[substr($t, 0, 2)] = $t;
    }
    return $map;
}

function collect_category_data(bool $is_creation = false): array {
    return [
        'CodCat'     => $is_creation ? strtoupper(trim($_POST['CodCat'])) : trim($_POST['CodCat']),
        'Nombre'     => trim($_POST['Nombre']),
        'IdEmpresa'  => ($_POST['IdEmpresa'] ?? '') !== '' ? intval($_POST['IdEmpresa']) : null,
        'SegWebF'    => isset($_POST['SegWebF']) ? '1' : '0',
        'SegWebT'    => isset($_POST['SegWebT']) ? '1' : '0',
        'Unicaja'    => intval($_POST['Unicaja'] ?? 1),
        'Estado'     => ($_POST['Estado'] ?? '1') === '1' ? '1' : '0',
        'Tipo'       => substr(trim($_POST['Tipo']), 0, 2)
    ];
}

/* ============================================================
   CARGAR EMPRESAS PRODUCTORAS
============================================================ */
$empresas = [];
$resEmp = $mysqli->query("SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre");
while ($e = $resEmp->fetch_assoc()) {
    $empresas[] = $e;
}

/* ============================================================
   PROCESAR CREACIÓN
============================================================ */
$mensaje = "";

if (isset($_POST['crear'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    $d = collect_category_data(true);

    $stmt = $mysqli->prepare("
        INSERT INTO categorias
        (CodCat, Nombre, IdEmpresa, SegWebF, SegWebT, Unicaja, Estado, Tipo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssississ",
        $d['CodCat'], $d['Nombre'], $d['IdEmpresa'],
        $d['SegWebF'], $d['SegWebT'],
        $d['Unicaja'], $d['Estado'], $d['Tipo']
    );

    if ($stmt->execute()) {
        $mensaje = "✅ Categoría {$d['CodCat']} creada correctamente";
    } else {
        $mensaje = "❌ Error: " . $stmt->error;
    }
    $stmt->close();
}

/* ============================================================
   PROCESAR ACTUALIZACIÓN
============================================================ */
if (isset($_POST['actualizar'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    $d = collect_category_data();

    $stmt = $mysqli->prepare("
        UPDATE categorias SET
            Nombre=?,
            IdEmpresa=?,
            SegWebF=?,
            SegWebT=?,
            Unicaja=?,
            Estado=?,
            Tipo=?
        WHERE CodCat=?
    ");

    $stmt->bind_param(
        "sississs",
        $d['Nombre'], $d['IdEmpresa'],
        $d['SegWebF'], $d['SegWebT'],
        $d['Unicaja'], $d['Estado'],
        $d['Tipo'], $d['CodCat']
    );

    $stmt->execute();
    $stmt->close();
    $mensaje = "✏️ Categoría {$d['CodCat']} actualizada";
}

/* ============================================================
   LISTADO
============================================================ */
$categorias = [];
$tipo_map = get_tipo_map();

$sql = "
SELECT
    c.CodCat,
    c.Nombre,
    c.IdEmpresa,
    e.Nombre AS Empresa,
    c.SegWebF,
    c.SegWebT,
    c.Unicaja,
    c.Estado,
    c.Tipo
FROM categorias c
LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa
ORDER BY c.CodCat
";

$res = $mysqli->query($sql);
while ($r = $res->fetch_assoc()) {
    $r['Tipo_Completo'] = $tipo_map[$r['Tipo']] ?? $r['Tipo'];
    $categorias[] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Gestión de Categorías</title>
<style>
body{font-family:Segoe UI;background:#f4f6f9;padding:20px}
.card{background:#fff;padding:20px;border-radius:10px;max-width:1200px;margin:auto}
table{width:100%;border-collapse:collapse;margin-top:20px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:center}
th{background:#343a40;color:#fff}
.fila-inactiva{background:#ffe6e6}
</style>
<script>
function filtrar(){
    let f=document.getElementById("filtro").value.toLowerCase();
    document.querySelectorAll("#tabla tbody tr").forEach(tr=>{
        tr.style.display=tr.innerText.toLowerCase().includes(f)?"":"none";
    });
}
</script>
</head>
<body>

<div class="card">
<h2>➕ Crear Categoría</h2>
<?php if ($mensaje) echo "<p><b>$mensaje</b></p>"; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

Código <input name="CodCat" maxlength="4" required>
Nombre <input name="Nombre" required>

Empresa
<select name="IdEmpresa">
    <option value="">-- Sin empresa --</option>
    <?php foreach($empresas as $e): ?>
        <option value="<?= $e['IdEmpresa'] ?>"><?= $e['Nombre'] ?></option>
    <?php endforeach; ?>
</select>

Tipo
<select name="Tipo" required>
<?php foreach(TIPOS_CATEGORIA as $t): ?>
    <option value="<?= $t ?>"><?= $t ?></option>
<?php endforeach; ?>
</select>

Unicaja <input type="number" name="Unicaja" value="1" min="1">
SegF <input type="checkbox" name="SegWebF">
SegT <input type="checkbox" name="SegWebT">

Estado
<select name="Estado">
    <option value="1">Activo</option>
    <option value="0">Inactivo</option>
</select>

<button name="crear">Crear</button>
</form>
</div>

<div class="card">
<h2>📋 Categorías</h2>
<input id="filtro" placeholder="Buscar..." onkeyup="filtrar()">

<table id="tabla">
<thead>
<tr>
<th>Código</th><th>Nombre</th><th>Empresa</th><th>Tipo</th>
<th>SegF</th><th>SegT</th><th>Unicaja</th><th>Estado</th><th>Acción</th>
</tr>
</thead>
<tbody>
<?php foreach($categorias as $c): ?>
<tr class="<?= $c['Estado']=='0'?'fila-inactiva':'' ?>">
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="hidden" name="CodCat" value="<?= $c['CodCat'] ?>">

<td><?= $c['CodCat'] ?></td>
<td><input name="Nombre" value="<?= $c['Nombre'] ?>"></td>

<td>
<select name="IdEmpresa">
<option value="">-- Sin empresa --</option>
<?php foreach($empresas as $e): ?>
<option value="<?= $e['IdEmpresa'] ?>" <?= $e['IdEmpresa']==$c['IdEmpresa']?'selected':'' ?>>
<?= $e['Nombre'] ?>
</option>
<?php endforeach; ?>
</select>
</td>

<td><?= $c['Tipo_Completo'] ?></td>
<td><input type="checkbox" name="SegWebF" <?= $c['SegWebF']=='1'?'checked':'' ?>></td>
<td><input type="checkbox" name="SegWebT" <?= $c['SegWebT']=='1'?'checked':'' ?>></td>
<td><input type="number" name="Unicaja" value="<?= $c['Unicaja'] ?>"></td>

<td>
<select name="Estado">
<option value="1" <?= $c['Estado']=='1'?'selected':'' ?>>Activo</option>
<option value="0" <?= $c['Estado']=='0'?'selected':'' ?>>Inactivo</option>
</select>
</td>

<td><button name="actualizar">Guardar</button></td>
</form>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</body>
</html>
