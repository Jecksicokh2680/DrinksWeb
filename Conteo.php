<?php
/* ============================================================
   1. ConexionES Y CONFIGURACIÓN
============================================================ */
require_once("ConnCentral.php"); // POS ($mysqliPos) -> Stock real
require_once("Conexion.php");    // ADM ($mysqli)    -> Log de conteos
session_start();

// Variables de Sesión

/* ============================================
   VALIDAR SESIÓN
============================================ */
if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida");
}
date_default_timezone_set('America/Bogota');
$usuario   = $_SESSION['Usuario'] ?? 'SISTEMA';
$nit       = $_SESSION['NitEmpresa'] ?? '';
$sucursal  = $_SESSION['NroSucursal'] ?? '';
$idalmacen = 1; // ID del almacén que se está auditando (puedes dinamizarlo)


$FechaActual = date('Y-m-d');

$categoriaSel = $_POST['categoria'] ?? '';
$mensaje = "";

/* ============================================
   CSRF TOKEN (UNO POR SESIÓN)
============================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================
   CATEGORÍAS YA CONTADAS HOY
============================================ */
$contados = [];
$resCont = $mysqli->query("
    SELECT DISTINCT CodCat
    FROM conteoweb
    WHERE DATE(fecha_conteo)=CURDATE()
      AND estado='A'
");
while ($r = $resCont->fetch_assoc()) {
    $contados[] = $r['CodCat'];
}

/* ============================================
   FUNCIÓN AUTORIZACIÓN
============================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $res = $mysqli->query("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE cedulaNit='$User' AND Nro_Auto='$Solicitud'
    ");
    return ($res && $row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

/* ============================================
   AUTORIZACIONES
============================================ */
$AUT_STOCK    = Autorizacion($usuario, '1800');
$AUT_CORREGIR = Autorizacion($usuario, '9999');
$AUT_BORRAR   = Autorizacion($usuario, '1810');
$AUT_SEMAFORO = Autorizacion($usuario, '1800');
$AUT_VERSTOCK = Autorizacion($usuario, '1801');

/* ============================================
   BORRAR CONTEO
============================================ */
if (isset($_POST['borrar_conteo']) && $AUT_BORRAR === 'SI') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("CSRF inválido");
    }

    $idConteo = intval($_POST['id_conteo']);

    $stmt = $mysqli->prepare("
        UPDATE conteoweb
        SET estado='X'
        WHERE id=?  AND estado='A'
    ");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $stmt->close();

     // $mensaje = "🗑️ Conteo anulado correctamente";
}

/* ============================================
   CATEGORÍAS
============================================ */
$categorias = [];
$res = $mysqli->query("
    SELECT CodCat, Nombre, unicaja
    FROM categorias
    WHERE Estado='1' AND (SegWebT+SegWebF)>=1
    ORDER BY CodCat
");
while ($r = $res->fetch_assoc()) {
    $categorias[$r['CodCat']] = $r;
}

/* ============================================
   STOCK SISTEMA
============================================ */
$totalCategoria = 0;
$unicajaSel = 0;

if ($categoriaSel && isset($categorias[$categoriaSel])) {

    $unicajaSel = $categorias[$categoriaSel]['unicaja'];

    $stmt = $mysqli->prepare("
        SELECT Sku FROM catproductos
        WHERE CodCat=? AND Estado='1'
    ");
    $stmt->bind_param("s", $categoriaSel);
    $stmt->execute();
    $res = $stmt->get_result();

    $skus = [];
    while ($r = $res->fetch_assoc()) $skus[] = $r['Sku'];
    $stmt->close();

    if ($skus) {
        $ph = implode(',', array_fill(0, count($skus), '?'));
        $tp = str_repeat('s', count($skus));

        $stmt = $mysqliPos->prepare("
            SELECT IFNULL(i.cantidad,0) stock
            FROM productos p
            LEFT JOIN inventario i
              ON i.idproducto=p.idproducto
             AND i.idalmacen=?
            WHERE p.barcode IN ($ph)
        ");
        $stmt->bind_param("i".$tp, $idalmacen, ...$skus);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $totalCategoria += $r['stock'];
        }
        $stmt->close();
    }
}

/* ============================================
   GUARDAR CONTEO
============================================ */
/* ============================================
   GUARDAR CONTEO
============================================ */
if (isset($_POST['guardar_conteo'])) {

    // Validar CSRF
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Acción no autorizada");
    }

    $codCat        = $_POST['CodCat'];
    $cajas         = floatval($_POST['cajas']);
    $unidades      = floatval($_POST['unidades']);
    $unicaja       = floatval($_POST['unicaja']);
    $stockSistema  = floatval($_POST['stock_sistema']); // siempre se envía calculado

    // Calcular stock físico y diferencia
    $stockFisico = $cajas + ($unicaja > 0 ? $unidades / $unicaja : 0);
    $diferencia  = $stockFisico - $stockSistema;

    // Verificar si ya se contó hoy
    $stmt = $mysqli->prepare("
        SELECT id 
        FROM conteoweb
        WHERE CodCat=? AND NitEmpresa=? AND NroSucursal=? 
          AND estado='A' AND DATE(fecha_conteo)=CURDATE()
        LIMIT 1
    ");
    $stmt->bind_param("sss", $codCat, $nit, $sucursal);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // $mensaje = "⚠️ Esta categoría ya fue contada hoy";
        $stmt->close();
    } else {
        $stmt->close();

        // Insertar el conteo
        $stmt = $mysqli->prepare("
            INSERT INTO conteoweb
            (CodCat, stock_sistema, stock_fisico, diferencia,
             NitEmpresa, NroSucursal, usuario, estado)
            VALUES (?,?,?,?,?,?,?,'A')
        ");
        $stmt->bind_param(
            "sdddsss",
            $codCat,
            $stockSistema,
            $stockFisico,
            $diferencia,
            $nit,
            $sucursal,
            $usuario
        );

        if ($stmt->execute()) {
           // $mensaje = "✅ Conteo guardado correctamente";
        } else {
            $mensaje = "❌ Error al guardar el conteo: " . $stmt->error;
        }

        $stmt->close();
    }
}

/* ============================================
   CONTEOS DEL DÍA
============================================ */
$conteos = [];
$res = $mysqli->query("
    SELECT c.*, cat.Nombre,DATE_FORMAT(c.fecha_conteo,'%H:%i:%s') AS hora
    FROM conteoweb c
    INNER JOIN categorias cat ON cat.CodCat=c.CodCat
    WHERE 
      -- c.NitEmpresa='$nit'
      -- AND c.NroSucursal='$sucursal' AND
       c.estado='A'
      AND DATE(c.fecha_conteo)=CURDATE()
    ORDER BY c.fecha_conteo DESC
");
while ($r = $res->fetch_assoc()) {
    $conteos[] = $r;
}
?>


<!DOCTYPE html>
<html lang=\"es\">
<head>
<meta charset=\"utf-8\">
<title>Conteo por Categoría</title>
<style>
    .select-categoria{
    width:100%;
    padding:14px;
    font-size:18px;
    border-radius:10px;
    border:1px solid #cfd6e0;
    background:#fff;
}

/* Más grande en móvil */
@media(max-width:700px){
    .select-categoria{
        font-size:22px;
        padding:16px;
    }
}

.conteo-grande{
    font-size: 20px;
    font-weight: 600;
    letter-spacing: .5px;
}

input[name="cajas"], input[name="unidades"] {
    width: 100%;          /* Ocupan todo el ancho disponible */
    padding: 24px;        /* Más alto */
    font-size: 26px;      /* Texto grande */
    border-radius: 12px;  /* Bordes redondeados */
    border: 2px solid #cfd6e0; /* Borde visible */
    box-sizing: border-box;
    transition: all 0.3s;
}

/* Cambio de color y sombra al enfocar */
input[name="cajas"]:focus,
input[name="unidades"]:focus {
    border-color: #28a745;
    outline: none;
    box-shadow: 0 0 8px rgba(40,167,69,0.6);
}

/* Responsive en móvil */
@media(max-width:700px){
    input[name="cajas"], input[name="unidades"] {
        padding: 28px;
        font-size: 28px;
    }
}



body{font-family:Segoe UI;background:#eef2f7}
.card{max-width:1100px;margin:25px auto;background:#fff;padding:20px;border-radius:14px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px;border-bottom:1px solid #e4e8f0}
th{background:#f1f4f9}
.btn-del{background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:6px}
.msg{background:#e7f3ff;padding:10px;border-radius:8px;margin-bottom:10px}
.semaforo{width:14px;height:14px;border-radius:50%;display:inline-block}
.verde{background:#28a745}
.rojo{background:#dc3545}
.amarillo{background:#ffc107}
</style>
</head>
<body>

<div class="card">
<h3>Conteo físico por categoría</h3>

<?php if($mensaje): ?><div class="msg"><?= $mensaje ?></div><?php endif; ?>

<form method="POST">
<select name="categoria" class="select-categoria" onchange="this.form.submit()">

<option value="">Seleccione categoría</option>
<?php foreach($categorias as $c):
    $ya = in_array($c['CodCat'], $contados);
?>
<option value="<?= $c['CodCat'] ?>" <?= $categoriaSel==$c['CodCat']?'selected':'' ?> <?= $ya?'disabled':'' ?>>
<?= $c['CodCat'].' - '.$c['Nombre'] ?><?= $ya?' (YA CONTADA)':'' ?>
</option>
<?php endforeach; ?>
</select>
</form>

<?php if($categoriaSel): ?>
<?php if($AUT_VERSTOCK==='SI'): ?>
    <p><b>Stock sistema:</b> <?= number_format($totalCategoria,3) ?></p>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<input type="hidden" name="CodCat" value="<?= $categoriaSel ?>">
<input type="hidden" name="unicaja" value="<?= $unicajaSel ?>">
<input type="hidden" name="stock_sistema" value="<?= $totalCategoria ?>">

<div class="grid">
<input type="number" step="0.001" name="cajas" placeholder="Cajas" required>
<input type="number" step="0.001" name="unidades" placeholder="Unidades" required>
</div>
<button name="guardar_conteo" 
        style="font-size:24px; padding:16px 32px; border-radius:12px; background:#28a745; color:#fff; border:none; cursor:pointer;">
    Guardar conteo
</button>
</form>
<?php endif; ?>
</div>

<?php if($conteos): ?>
<div class="card">
    <div style="margin:15px 0">
    <button type="button" onclick="location.reload()" style="font-size:16px;padding:8px 14px">
        🔄 Refrescar
    </button>
</div>
<h3>Conteos del día</h3>
<table>
<tr>
<th>Fecha</th><th>Usuario</th><th>Categoría</th><th>Conteo</th><th>Semáforo</th>
<?php if($AUT_CORREGIR==='SI'): ?> <th>Stock</th><th>Dif</th><?php endif; ?>
<?php if($AUT_BORRAR ==='SI'): ?><th>Acción</th><?php endif; ?>
</tr>

<?php foreach($conteos as $c):
$color = ($AUT_SEMAFORO==='SI')
    ? (($c['diferencia'] < 0) ? 'rojo' : 'verde')
    : 'amarillo';
?>
<tr>
<td><?= $c['hora'] ?></td>
<td><?= $c['usuario'] ?></td>
<td class="conteo-grande"><?= $c['CodCat'].' - '.$c['Nombre'] ?></td>
<td align="right" class="conteo-grande" ><?= number_format($c['stock_fisico'],3) ?></td>
<td align="center"><span class="semaforo <?= $color ?>"></span></td>
<?php if($AUT_CORREGIR==='SI'): ?>
<td><?= number_format($c['stock_sistema'],3) ?></td>
<td class="conteo-grande"><?= number_format($c['diferencia'],3) ?></td>
<?php endif; ?>

<?php if($AUT_BORRAR==='SI'): ?>
<td>
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
<input type="hidden" name="id_conteo" value="<?= $c['id'] ?>">
<button name="borrar_conteo" class="btn-del">🗑</button>
</form>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

</body>
</html>
