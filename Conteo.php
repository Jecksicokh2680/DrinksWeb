<?php
/* ============================================================
   1. CONEXIONES Y CONFIGURACIÓN
============================================================ */
require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks
require_once("Conexion.php");    // ADM ($mysqli) -> Log de conteos

session_start();

// Definición de las sedes (NITs proporcionados)
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

/* ============================================
   LÓGICA DE CAMBIO DE SEDE MANUAL
============================================ */
if (isset($_GET['cambiar_nit'])) {
    $nuevo_nit = $_GET['cambiar_nit'];
    if ($nuevo_nit == NIT_CENTRAL || $nuevo_nit == NIT_DRINKS) {
        $_SESSION['NitEmpresa'] = $nuevo_nit;
        $_SESSION['NroSucursal'] = '001';
        unset($_SESSION['Autorizaciones']); // Limpiar caché de permisos
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); // Limpiar URL
        exit;
    }
}

if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida. Por favor inicie sesión.");
}

date_default_timezone_set('America/Bogota');

$usuario    = $_SESSION['Usuario'] ?? 'SISTEMA';
$nitSesion  = $_SESSION['NitEmpresa'] ?? NIT_CENTRAL; // Central por defecto
$sucursal   = $_SESSION['NroSucursal'] ?? '001';
$idalmacen  = 1; 

// Selección dinámica de conexión según la sede activa
$mysqliSede = ($nitSesion == NIT_DRINKS) ? $mysqliDrinks : $mysqliCentral;
$nombreSede = ($nitSesion == NIT_DRINKS) ? "DRINKS" : "CENTRAL";

$categoriaSel = $_POST['categoria'] ?? '';
$mensaje = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================
   AUTORIZACIONES
============================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    return ($row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

$AUT_BORRAR   = Autorizacion($usuario, '1810');
$AUT_CORREGIR = Autorizacion($usuario, '9999');
$AUT_SEMAFORO = Autorizacion($usuario, '1800');
$AUT_VERSTOCK = Autorizacion($usuario, '1801');

/* ============================================
   BORRAR CONTEO
============================================ */
if (isset($_POST['borrar_conteo']) && $AUT_BORRAR === 'SI') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("CSRF inválido");
    $idConteo = intval($_POST['id_conteo']);
    $stmt = $mysqli->prepare("UPDATE conteoweb SET estado='X' WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $mensaje = "🗑️ Conteo anulado correctamente";
}

/* ============================================
   LISTADO DE CATEGORÍAS (Y validación de contadas)
============================================ */
$contados = [];
$resCont = $mysqli->prepare("SELECT DISTINCT CodCat FROM conteoweb WHERE DATE(fecha_conteo)=CURDATE() AND estado='A' AND NitEmpresa=?");
$resCont->bind_param("s", $nitSesion);
$resCont->execute();
$resResult = $resCont->get_result();
while ($r = $resResult->fetch_assoc()) $contados[] = $r['CodCat'];

$categorias = [];
$res = $mysqli->query("SELECT CodCat, Nombre, unicaja FROM categorias WHERE Estado='1' AND (SegWebT+SegWebF)>=1 ORDER BY CodCat");
while ($r = $res->fetch_assoc()) $categorias[$r['CodCat']] = $r;

/* ============================================
   CÁLCULO STOCK SISTEMA (Sede actual)
============================================ */
$totalCategoria = 0;
$unicajaSel = 0;

if ($categoriaSel && isset($categorias[$categoriaSel])) {
    $unicajaSel = $categorias[$categoriaSel]['unicaja'];
    $stmt = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=? AND Estado='1'");
    $stmt->bind_param("s", $categoriaSel);
    $stmt->execute();
    $resSkus = $stmt->get_result();
    $skus = [];
    while ($r = $resSkus->fetch_assoc()) $skus[] = $r['Sku'];

    if ($skus) {
        $ph = implode(',', array_fill(0, count($skus), '?'));
        $tp = str_repeat('s', count($skus));
        $stmtStock = $mysqliSede->prepare("SELECT IFNULL(SUM(i.cantidad),0) as total FROM productos p LEFT JOIN inventario i ON i.idproducto=p.idproducto AND i.idalmacen=? WHERE p.barcode IN ($ph)");
        $stmtStock->bind_param("i".$tp, $idalmacen, ...$skus);
        $stmtStock->execute();
        $totalCategoria = $stmtStock->get_result()->fetch_assoc()['total'] ?? 0;
    }
}

/* ============================================
   GUARDAR CONTEO
============================================ */
if (isset($_POST['guardar_conteo'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Acción no autorizada");
    
    $codCat        = $_POST['CodCat'];
    $cajas         = floatval($_POST['cajas']);
    $unidades      = floatval($_POST['unidades']);
    $unicaja       = floatval($_POST['unicaja']);
    $stockSistema  = floatval($_POST['stock_sistema']);

    $stockFisico = $cajas + ($unicaja > 0 ? $unidades / $unicaja : 0);
    $diferencia  = $stockFisico - $stockSistema;

    $stmt = $mysqli->prepare("INSERT INTO conteoweb (CodCat, stock_sistema, stock_fisico, diferencia, NitEmpresa, NroSucursal, usuario, estado) VALUES (?,?,?,?,?,?,?,'A')");
    $stmt->bind_param("sdddsss", $codCat, $stockSistema, $stockFisico, $diferencia, $nitSesion, $sucursal, $usuario);

    if ($stmt->execute()) {
        $mensaje = "✅ Conteo guardado en $nombreSede";
        $categoriaSel = ""; // Limpiar
    }
}

/* ============================================
   HISTORIAL DEL DÍA
============================================ */
$conteos = [];
$res = $mysqli->prepare("SELECT c.*, cat.Nombre, DATE_FORMAT(c.fecha_conteo,'%H:%i:%s') AS hora FROM conteoweb c INNER JOIN categorias cat ON cat.CodCat=c.CodCat WHERE c.NitEmpresa=? AND c.estado='A' AND DATE(c.fecha_conteo)=CURDATE() ORDER BY c.fecha_conteo DESC");
$res->bind_param("s", $nitSesion);
$res->execute();
$resultConteos = $res->get_result();
while ($r = $resultConteos->fetch_assoc()) $conteos[] = $r;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conteo - <?= $nombreSede ?></title>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f4f7f6; margin:0; padding:15px;}
        .card{max-width:800px; margin:auto; background:#fff; padding:25px; border-radius:15px; box-shadow:0 10px 25px rgba(0,0,0,0.05);}
        .sede-selector { display:flex; gap:10px; margin-bottom:20px; background:#e9ecef; padding:10px; border-radius:10px; align-items:center; }
        .sede-btn { text-decoration:none; padding:8px 15px; border-radius:8px; font-weight:bold; font-size:13px; color:#555; background:#ddd; }
        .sede-btn.active { background:#2c3e50; color:#fff; }
        .select-categoria{ width:100%; padding:15px; font-size:18px; border-radius:8px; border:1px solid #ddd; margin-bottom:20px; cursor:pointer;}
        .grid{display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;}
        label{display:block; font-weight:bold; margin-bottom:5px; color:#444;}
        input[type="number"] { width:100%; padding:15px; font-size:28px; border-radius:10px; border:2px solid #eee; text-align:center; box-sizing:border-box;}
        input[type="number"]:focus{ border-color:#28a745; outline:none; background:#f9fff9;}
        .btn-save { width:100%; background:#28a745; color:white; padding:18px; border:none; border-radius:10px; font-size:22px; cursor:pointer; font-weight:bold; transition:0.2s;}
        .btn-save:hover{ background:#218838; transform:scale(1.01); }
        table{width:100%; border-collapse:collapse; margin-top:30px;}
        th,td{padding:12px; border-bottom:1px solid #f0f0f0; text-align:left;}
        th{background:#f8f9fa; color:#666; font-size:12px; text-transform:uppercase;}
        .semaforo{width:12px; height:12px; border-radius:50%; display:inline-block;}
        .verde{background:#28a745;} .rojo{background:#dc3545;} .amarillo{background:#ffc107;}
        .btn-del{background:#ff4757; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer;}
    </style>
</head>
<body>

<div class="card">
    <div class="sede-selector">
        <span style="font-size:12px; font-weight:bold; color:#777;">📍 SEDE ACTUAL:</span>
        <a href="?cambiar_nit=<?= NIT_CENTRAL ?>" class="sede-btn <?= ($nitSesion == NIT_CENTRAL)?'active':'' ?>">CENTRAL</a>
        <a href="?cambiar_nit=<?= NIT_DRINKS ?>" class="sede-btn <?= ($nitSesion == NIT_DRINKS)?'active':'' ?>">DRINKS</a>
    </div>

    <h3>Inventario Físico: <?= $nombreSede ?></h3>
    
    <?php if($mensaje): ?>
        <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #28a745;">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <label>Seleccionar Categoría:</label>
        <select name="categoria" class="select-categoria" onchange="this.form.submit()">
            <option value="">-- Buscar Categoría --</option>
            <?php foreach($categorias as $c): 
                $ya = in_array($c['CodCat'], $contados);
            ?>
            <option value="<?= $c['CodCat'] ?>" <?= $categoriaSel==$c['CodCat']?'selected':'' ?> <?= $ya?'disabled style="color:#ccc;"':'' ?>>
                <?= $c['CodCat'].' - '.$c['Nombre'] ?><?= $ya?' (YA CONTADA)':'' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if($categoriaSel): ?>
        <div style="background:#f8f9fa; padding:20px; border-radius:12px; border:1px dashed #ccc;">
            <?php if($AUT_VERSTOCK==='SI'): ?>
                <p style="margin:0 0 15px 0; font-size:18px;">📋 Stock en Sistema: <strong><?= number_format($totalCategoria,2) ?></strong></p>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="CodCat" value="<?= $categoriaSel ?>">
                <input type="hidden" name="unicaja" value="<?= $unicajaSel ?>">
                <input type="hidden" name="stock_sistema" value="<?= $totalCategoria ?>">

                <div class="grid">
                    <div>
                        <label>Cajas</label>
                        <input type="number" step="0.001" name="cajas" placeholder="0" required autofocus>
                    </div>
                    <div>
                        <label>Unidades</label>
                        <input type="number" step="0.001" name="unidades" placeholder="0" required>
                    </div>
                </div>
                <button type="submit" name="guardar_conteo" class="btn-save">REGISTRAR CONTEO</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if($conteos): ?>
        <div style="margin-top:40px;">
            <h4 style="margin-bottom:10px; color:#2c3e50; border-bottom:2px solid #eee; padding-bottom:5px;">Resumen de Hoy</h4>
            <table>
                <thead>
                    <tr>
                        <th>Hora</th><th>Categoría</th><th>Físico</th><th>Estado</th>
                        <?php if($AUT_CORREGIR==='SI'): ?><th>Dif</th><?php endif; ?>
                        <?php if($AUT_BORRAR==='SI'): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($conteos as $c): 
                        $dif = (float)$c['diferencia'];
                        $color = ($dif == 0) ? 'verde' : (($dif < 0) ? 'rojo' : 'amarillo');
                    ?>
                    <tr>
                        <td style="color:#999; font-size:11px;"><?= $c['hora'] ?></td>
                        <td><strong><?= $c['CodCat'] ?></strong></td>
                        <td style="font-size:15px;"><strong><?= number_format($c['stock_fisico'],2) ?></strong></td>
                        <td align="center"><span class="semaforo <?= $color ?>"></span></td>
                        <?php if($AUT_CORREGIR==='SI'): ?>
                            <td style="color:<?= $dif<0?'#dc3545':'#28a745' ?>; font-weight:bold;">
                                <?= ($dif > 0 ? '+': '') . number_format($dif,2) ?>
                            </td>
                        <?php endif; ?>
                        <?php if($AUT_BORRAR==='SI'): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('¿Anular este conteo?')">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="id_conteo" value="<?= $c['id'] ?>">
                                <button name="borrar_conteo" class="btn-del">🗑</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</body>
</html>