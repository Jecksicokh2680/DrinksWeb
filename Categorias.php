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
   TIPOS DE CATEGORÍA
============================================================ */
const TIPOS_CATEGORIA = [
    "Cerveza", "Gaseosa", "Agua", "Jugo", "Hidratante",
    "Energizante", "Dulceria", "Maltas", "Papeleria",
    "Suero", "Aseo", "Elementos"
];

function get_tipo_map(): array {
    $map = [];
    foreach (TIPOS_CATEGORIA as $t) {
        $map[substr($t,0,2)] = $t; // Código => Nombre completo
    }
    return $map;
}

/* ============================================================
   FUNCIONES
============================================================ */
function check_csrf(string $posted, string $session) {
    if (!$posted || !hash_equals($session, $posted)) {
        http_response_code(403);
        die("Token CSRF inválido");
    }
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
        'Tipo'       => substr(trim($_POST['Tipo']), 0, 2) // Guardar solo 2 letras
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

    $mensaje = $stmt->execute()
        ? "Categoría {$d['CodCat']} creada correctamente"
        : "Error: ".$stmt->error;

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
    $mensaje = "Categoría {$d['CodCat']} actualizada";
}

/* ============================================================
   LISTADO DE CATEGORÍAS
============================================================ */
$categorias = [];
$tipo_map = get_tipo_map(); // Código => Nombre completo

$res = $mysqli->query("
SELECT c.*, e.Nombre AS Empresa
FROM categorias c
LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa
ORDER BY c.CodCat
");

while ($r = $res->fetch_assoc()) {
    $r['Tipo_Completo'] = $tipo_map[$r['Tipo']] ?? $r['Tipo'];
    $categorias[] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Gestión Gerencial de Categorías</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f3f6fb;padding:25px}
.card{background:#fff;padding:22px;border-radius:16px;max-width:1400px;margin:0 auto 28px;box-shadow:0 12px 30px rgba(0,0,0,.08);}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;}
h2{margin:0;color:#0f2a44;font-weight:600;}
.sub{font-size:13px;color:#6b7280;}
input,select,button{padding:8px 10px;border-radius:8px;border:1px solid #e5e7eb;font-size:13px;}
button{background:#1e5aa8;color:#fff;border:none;cursor:pointer;padding:9px 14px;}
button:hover{opacity:.9}
table{width:100%;border-collapse:collapse;margin-top:15px;font-size:13px;}
th{background:#0f2a44;color:#fff;padding:10px;text-transform:uppercase;font-size:12px;}
td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;}
td:nth-child(2),td:nth-child(3),td:nth-child(4){text-align:left}
.badge{padding:4px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.activo{background:#e7f6ec;color:#198754}
.inactivo{background:#fdeaea;color:#dc3545}
.fila-inactiva{opacity:.55}
#filtro{width:260px;}
.mensaje{margin:10px 0;padding:10px;border-left:4px solid #1e5aa8;background:#eef3fb;border-radius:8px;}
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
<div class="header">
<div>
<h2>Gestión Gerencial de Categorías</h2>
<div class="sub">Administración estratégica de clasificación de productos</div>
</div>
</div>

<?= $mensaje ? "<div class='mensaje'>$mensaje</div>" : "" ?>

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
<select name="Tipo">
<?php foreach(TIPOS_CATEGORIA as $t): ?>
<option value="<?= substr($t,0,2) ?>"><?= $t ?></option>
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

<button name="crear">Crear Categoría</button>
</form>
</div>

<div class="card">
<div class="header">
<h2>Listado de Categorías</h2>
<input id="filtro" placeholder="Buscar..." onkeyup="filtrar()">
</div>

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

<!-- Tipo editable: mostrar nombre completo, guardar 2 letras -->
<td>
<select name="Tipo">
<?php foreach(TIPOS_CATEGORIA as $t): ?>
<option value="<?= substr($t,0,2) ?>" <?= $c['Tipo']==substr($t,0,2)?'selected':'' ?>><?= $t ?></option>
<?php endforeach; ?>
</select>
</td>

<td><input type="checkbox" name="SegWebF" <?= $c['SegWebF']=='1'?'checked':'' ?>></td>
<td><input type="checkbox" name="SegWebT" <?= $c['SegWebT']=='1'?'checked':'' ?>></td>
<td><input type="number" name="Unicaja" value="<?= $c['Unicaja'] ?>"></td>

<td>
<span class="badge <?= $c['Estado']=='1'?'activo':'inactivo' ?>">
<?= $c['Estado']=='1'?'Activo':'Inactivo' ?>
</span>
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
