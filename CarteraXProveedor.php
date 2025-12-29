<?php
require 'Conexion.php';
session_start();

// -------------------------------------------------
// VALIDAR SESIÓN
// -------------------------------------------------
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}

// -------------------------------------------------
// PROVEEDORES
// -------------------------------------------------
$proveedores = $mysqli->query("
    SELECT CedulaNit AS Nit, Nombre
    FROM terceros
    WHERE Estado = 1
    ORDER BY Nombre
");

// -------------------------------------------------
// INSERTAR
// -------------------------------------------------
if (isset($_POST['grabar'])) {

    date_default_timezone_set('America/Bogota');

    $nit   = $_POST['proveedor'];
    $fecha = str_replace('-', '', $_POST['fecha']);
    $hora  = date("H:i:s");
    $tipo  = $_POST['tipo'];

    // QUITAR SEPARADORES
    $monto = floatval(str_replace('.', '', $_POST['monto']));

    $desc  = strtoupper($mysqli->real_escape_string($_POST['descripcion']));

    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("
        INSERT INTO pagosproveedores
        (Nit,F_Creacion,H_Creacion,Monto,TipoMonto,Descripcion,Estado)
        VALUES
        ('$nit','$fecha','$hora','$monto','$tipo','$desc','1')
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// EDITAR
// -------------------------------------------------
if (isset($_POST['editar'])) {

    $nit   = $_POST['nit'];
    $fecha = $_POST['fecha'];
    $hora  = $_POST['hora'];
    $tipo  = $_POST['tipo'];

    $monto = floatval(str_replace('.', '', $_POST['monto']));
    $desc  = strtoupper($mysqli->real_escape_string($_POST['descripcion']));

    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("
        UPDATE pagosproveedores
        SET Monto='$monto',
            TipoMonto='$tipo',
            Descripcion='$desc'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// BORRAR (LÓGICO)
// -------------------------------------------------
if (isset($_GET['borrar'])) {

    $nit   = $_GET['nit'];
    $fecha = $_GET['f'];
    $hora  = $_GET['h'];

    $mysqli->query("
        UPDATE pagosproveedores
        SET Estado='0'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// PAGINACIÓN
// -------------------------------------------------
$porPagina = 10;
$pagina = max(1, intval($_GET['page'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

// -------------------------------------------------
// CONSULTA
// -------------------------------------------------
$abonos = [];
$total = 0;
$nit = '';

if (isset($_GET['consultar'], $_GET['proveedor'])) {

    $nit = $_GET['proveedor'];

    $resTotal = $mysqli->query("
        SELECT IFNULL(SUM(Monto),0) total, COUNT(*) cant
        FROM pagosproveedores
        WHERE Nit='$nit' AND Estado='1'
    ");
    $rowT = $resTotal->fetch_assoc();
    $total = $rowT['total'];
    $totalPaginas = max(1, ceil($rowT['cant'] / $porPagina));

    $res = $mysqli->query("
        SELECT *
        FROM pagosproveedores
        WHERE Nit='$nit' AND Estado='1'
        ORDER BY F_Creacion DESC, H_Creacion DESC
        LIMIT $offset,$porPagina
    ");

    while ($r = $res->fetch_assoc()) {
        $abonos[] = $r;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Cartera Proveedores</title>
<style>
body{font-family:Arial;background:#f4f4f4}
.box{background:#fff;padding:15px;border-radius:10px;max-width:1150px;margin:auto}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.row input,.row select,.row button{padding:7px;border-radius:6px;border:1px solid #ccc}
table{width:100%;border-collapse:collapse;margin-top:10px}
th{background:#007bff;color:#fff;padding:6px}
td{padding:6px;border-bottom:1px solid #ddd}
.neg{background:#fde2e2}
.pos{background:#e6fffa}
.total{background:#eef;padding:8px;border-radius:6px;margin:10px 0;font-weight:bold}
a{text-decoration:none;font-weight:bold}
.btn-del{color:#dc3545}
.btn-save{color:#198754}
.paginacion{text-align:center;margin-top:10px}
</style>
</head>
<body>

<div class="box">
<h2>Cartera por Proveedor</h2>

<form method="GET" class="row">
<select name="proveedor" required>
<option value="">Proveedor</option>
<?php while($p=$proveedores->fetch_assoc()): ?>
<option value="<?= $p['Nit'] ?>" <?= $p['Nit']==$nit?'selected':'' ?>>
<?= $p['Nombre'] ?>
</option>
<?php endwhile; ?>
</select>
<button name="consultar">Consultar</button>
</form>

<?php if($nit): ?>
<form method="POST" class="row">
<input type="hidden" name="proveedor" value="<?= $nit ?>">
<input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
<select name="tipo">
<option value="F">Factura</option>
<option value="P">Pago</option>
</select>
<input name="monto" class="monto" placeholder="Monto" required>
<input name="descripcion" placeholder="Descripción">
<button name="grabar">Grabar</button>
</form>
<?php endif; ?>

<?php if($abonos): ?>
<div class="total">Saldo Pte: <?= number_format($total,0,',','.') ?></div>

<table>
<tr>
<th>Fecha</th>
<th>Hora</th>
<th>Tipo</th>
<th>Monto</th>
<th>Descripción</th>
<th>Acciones</th>
</tr>

<?php foreach($abonos as $a): ?>
<form method="POST">
<tr class="<?= $a['Monto']<0?'neg':'pos' ?>">
<td><?= $a['F_Creacion'] ?></td>
<td><?= $a['H_Creacion'] ?></td>
<td>
<select name="tipo">
<option value="F" <?= $a['TipoMonto']=='F'?'selected':'' ?>>F</option>
<option value="P" <?= $a['TipoMonto']=='P'?'selected':'' ?>>P</option>
</select>
</td>
<td>
<input name="monto" class="monto"
       value="<?= number_format(abs($a['Monto']),0,',','.') ?>">
</td>
<td style="width:40%">
    <input name="descripcion"
           value="<?= htmlspecialchars($a['Descripcion']) ?>"
           style="width:100%">
</td>
<td>
<input type="hidden" name="nit" value="<?= $a['Nit'] ?>">
<input type="hidden" name="fecha" value="<?= $a['F_Creacion'] ?>">
<input type="hidden" name="hora" value="<?= $a['H_Creacion'] ?>">
<button class="btn-save" name="editar">💾</button>
<a class="btn-del"
   href="?proveedor=<?= $nit ?>&consultar=1&borrar=1
         &nit=<?= $a['Nit'] ?>&f=<?= $a['F_Creacion'] ?>&h=<?= $a['H_Creacion'] ?>"
   onclick="return confirm('¿Eliminar este registro?')">🗑️</a>
</td>
</tr>
</form>
<?php endforeach; ?>
</table>

<div class="paginacion">
<?php if($pagina>1): ?>
<a href="?proveedor=<?= $nit ?>&consultar=1&page=<?= $pagina-1 ?>">⬅</a>
<?php endif; ?>
 Página <?= $pagina ?>
<?php if($pagina<$totalPaginas): ?>
<a href="?proveedor=<?= $nit ?>&consultar=1&page=<?= $pagina+1 ?>">➡</a>
<?php endif; ?>
</div>

<?php endif; ?>

</div>

<script>
// FORMATEAR MILES EN INPUT
document.querySelectorAll('.monto').forEach(input => {
    input.addEventListener('input', () => {
        let v = input.value.replace(/\D/g,'');
        input.value = v.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    });
});
</script>

</body>
</html>
