<?php
/* ============================================================
   1. CONEXIONES Y CONFIGURACIÓN
============================================================ */
require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks
require_once("Conexion.php");    // ADM ($mysqli)

session_start();

// Definición de las sedes
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');

/* ============================================
   LÓGICA AJAX: CONSULTAR PRODUCTOS POR CATEGORÍA
============================================ */
if (isset($_POST['action']) && $_POST['action'] === 'ver_productos') {
    $cat = $_POST['cod_cat'];
    $nit = $_POST['nit'];
    $dbSede = ($nit == NIT_DRINKS) ? $mysqliDrinks : $mysqliCentral;
    
    $stmt = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=? AND Estado='1'");
    $stmt->bind_param("s", $cat);
    $stmt->execute();
    $res = $stmt->get_result();
    $skus = [];
    while($r = $res->fetch_assoc()) $skus[] = $r['Sku'];

    $html = "<table style='width:100%; border-collapse:collapse; font-size:13px;'>
            <thead><tr style='background:#f4f4f4;'>
                <th style='padding:8px; border:1px solid #ddd;'>Barcode</th>
                <th style='padding:8px; border:1px solid #ddd;'>Descripción</th>
                <th style='padding:8px; border:1px solid #ddd;'>Stock</th>
            </tr></thead><tbody>";
    
    if ($skus) {
        $ph = implode(',', array_fill(0, count($skus), '?'));
        $tp = str_repeat('s', count($skus));
        
        $sql = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) as stock 
                FROM productos p 
                LEFT JOIN inventario i ON i.idproducto=p.idproducto AND i.idalmacen=1 
                WHERE p.barcode IN ($ph) and p.estado='1'
                GROUP BY p.barcode ORDER BY p.descripcion ASC";
        
        $stmtP = $dbSede->prepare($sql);
        $stmtP->bind_param($tp, ...$skus);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        
        while($p = $resP->fetch_assoc()){
            $html .= "<tr>
                        <td style='padding:8px; border:1px solid #ddd;'>{$p['barcode']}</td>
                        <td style='padding:8px; border:1px solid #ddd;'>".htmlspecialchars($p['descripcion'])."</td>
                        <td style='padding:8px; border:1px solid #ddd;' align='right'><strong>".number_format($p['stock'],2)."</strong></td>
                      </tr>";
        }
    } else {
        $html .= "<tr><td colspan='3' style='padding:20px; text-align:center;'>No hay productos vinculados a esta categoría.</td></tr>";
    }
    $html .= "</tbody></table>";
    echo $html;
    exit;
}

/* ============================================
   LÓGICA DE CAMBIO DE SEDE MANUAL
============================================ */
if (isset($_GET['cambiar_nit'])) {
    $nuevo_nit = $_GET['cambiar_nit'];
    if ($nuevo_nit == NIT_CENTRAL || $nuevo_nit == NIT_DRINKS) {
        $_SESSION['NitEmpresa'] = $nuevo_nit;
        $_SESSION['NroSucursal'] = '001';
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }
}

if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida. Por favor inicie sesión.");
}

date_default_timezone_set('America/Bogota');

$usuario    = $_SESSION['Usuario'] ?? 'SISTEMA';
$nitSesion  = $_SESSION['NitEmpresa'] ?? NIT_CENTRAL; 
$sucursal   = $_SESSION['NroSucursal'] ?? '001';
$idalmacen  = 1; 

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
$AUT_VERSTOCK = Autorizacion($usuario, '1801'); 

// Borrar Conteo
if (isset($_POST['borrar_conteo'])) {
    $idConteo = intval($_POST['id_conteo']);
    $stmt = $mysqli->prepare("UPDATE conteoweb SET estado='X' WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $mensaje = "🗑️ Conteo anulado correctamente";
}

// Cargar Categorías Contadas Hoy
$contados = [];
$resCont = $mysqli->prepare("SELECT DISTINCT CodCat FROM conteoweb WHERE DATE(fecha_conteo)=CURDATE() AND estado='A' AND NitEmpresa=?");
$resCont->bind_param("s", $nitSesion);
$resCont->execute();
$resResult = $resCont->get_result();
while ($r = $resResult->fetch_assoc()) $contados[] = $r['CodCat'];

// Cargar Todas las Categorías Disponibles
$categorias = [];
$res = $mysqli->query("SELECT CodCat, Nombre, unicaja FROM categorias WHERE Estado='1' AND (SegWebT+SegWebF)>=1 ORDER BY CodCat");
while ($r = $res->fetch_assoc()) $categorias[$r['CodCat']] = $r;

// Cálculo Stock Sistema
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

// Guardar Conteo
if (isset($_POST['guardar_conteo'])) {
    $codCat        = $_POST['CodCat'];
    $cajas         = floatval($_POST['cajas']);
    $unidades      = floatval($_POST['unidades']);
    $unicaja       = floatval($_POST['unicaja']);
    $stockSistema  = floatval($_POST['stock_sistema']);
    $stockFisico   = $cajas + ($unicaja > 0 ? $unidades / $unicaja : 0);
    $diferencia    = $stockFisico - $stockSistema;

    $stmt = $mysqli->prepare("INSERT INTO conteoweb (CodCat, stock_sistema, stock_fisico, diferencia, NitEmpresa, NroSucursal, usuario, estado) VALUES (?,?,?,?,?,?,?,'A')");
    $stmt->bind_param("sdddsss", $codCat, $stockSistema, $stockFisico, $diferencia, $nitSesion, $sucursal, $usuario);
    if ($stmt->execute()) {
        $mensaje = "✅ Conteo guardado correctamente";
        $categoriaSel = "";
    }
}

// Historial del día
$conteos = [];
$resH = $mysqli->prepare("SELECT c.*, cat.Nombre, DATE_FORMAT(c.fecha_conteo,'%H:%i:%s') AS hora FROM conteoweb c INNER JOIN categorias cat ON cat.CodCat=c.CodCat WHERE c.NitEmpresa=? AND c.estado='A' AND DATE(c.fecha_conteo)=CURDATE() ORDER BY c.fecha_conteo DESC");
$resH->bind_param("s", $nitSesion);
$resH->execute();
$resultConteos = $resH->get_result();
while ($r = $resultConteos->fetch_assoc()) $conteos[] = $r;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIA | Inventario <?= $nombreSede ?></title>
    <style>
        body{font-family:'Segoe UI', sans-serif; background:#f4f7f6; margin:0; padding:15px; color:#333;}
        .card{max-width:800px; margin:auto; background:#fff; padding:25px; border-radius:15px; box-shadow:0 10px 25px rgba(0,0,0,0.05);}
        .sede-selector { display:flex; gap:10px; margin-bottom:20px; background:#e9ecef; padding:10px; border-radius:10px; align-items:center; }
        .sede-btn { text-decoration:none; padding:8px 15px; border-radius:8px; font-weight:bold; font-size:13px; color:#555; background:#ddd; transition: 0.3s; }
        .sede-btn.active { background:#2c3e50; color:#fff; }
        .btn-refresh { 
            text-decoration:none; padding:8px; border-radius:8px; background:#fff; 
            border:1px solid #ccc; cursor:pointer; font-size:16px; margin-left: auto;
        }
        .select-categoria{ width:100%; padding:15px; font-size:18px; border-radius:8px; border:1px solid #ddd; margin-bottom:10px; background:#fff;}
        .grid{display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;}
        label{display:block; font-weight:bold; margin-bottom:5px; font-size: 14px;}
        input[type="number"] { width:100%; padding:15px; font-size:28px; border-radius:10px; border:2px solid #eee; text-align:center; box-sizing:border-box;}
        .btn-save { width:100%; background:#28a745; color:white; padding:18px; border:none; border-radius:10px; font-size:20px; cursor:pointer; font-weight:bold;}
        .btn-info { background:#17a2b8; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-size:13px; margin-bottom:15px; display:inline-block;}
        table{width:100%; border-collapse:collapse; margin-top:20px;}
        th,td{padding:10px; border-bottom:1px solid #f0f0f0; text-align:left;}
        th{background:#f8f9fa; color:#666; font-size:11px; text-transform:uppercase;}
        .semaforo{width:12px; height:12px; border-radius:50%; display:inline-block;}
        .verde{background:#28a745;} .rojo{background:#dc3545;}
        .modal { display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter: blur(2px); }
        .modal-content { background:#fff; margin:5% auto; padding:25px; width:95%; max-width:700px; border-radius:15px; max-height:85vh; overflow-y:auto; position:relative;}
        .close-modal { position:absolute; right:20px; top:15px; font-size:30px; cursor:pointer; color:#999; }
    </style>
</head>
<body>

<div class="card">
    <div class="sede-selector">
        <span style="font-size:11px; font-weight:bold; color:#777;">📍 SEDE:</span>
        <a href="?cambiar_nit=<?= NIT_CENTRAL ?>" class="sede-btn <?= ($nitSesion == NIT_CENTRAL)?'active':'' ?>">CENTRAL</a>
        <a href="?cambiar_nit=<?= NIT_DRINKS ?>" class="sede-btn <?= ($nitSesion == NIT_DRINKS)?'active':'' ?>">DRINKS</a>
        <button type="button" class="btn-refresh" onclick="window.location.reload()">🔄</button>
    </div>

    <h3 style="margin:0 0 20px 0;">Inventario Físico <span style="color:#17a2b8;">#<?= $nombreSede ?></span></h3>
    
    <?php if($mensaje): ?>
        <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #28a745;">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="form-main">
        <label>Seleccionar Categoría:</label>
        <select name="categoria" class="select-categoria" onchange="this.form.submit()">
            <option value="">-- Buscar Categoría --</option>
            <?php foreach($categorias as $c): 
                $ya = in_array($c['CodCat'], $contados);
            ?>
            <option value="<?= $c['CodCat'] ?>" <?= $categoriaSel==$c['CodCat']?'selected':'' ?> <?= $ya?'disabled':'' ?>>
                <?= $c['CodCat'].' - '.$c['Nombre'] ?><?= $ya?' (CONTEO REALIZADO)':'' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if($categoriaSel): ?>
        <button type="button" class="btn-info" onclick="verDetalleProductos('<?= $categoriaSel ?>')">🔍 Ver Productos de <?= $categoriaSel ?></button>

        <div style="background:#f8f9fa; padding:25px; border-radius:15px; border:1px solid #e9ecef;">
            
            <?php if($AUT_VERSTOCK==='SI' || $AUT_CORREGIR==='SI'): ?>
                <div style="display:flex; justify-content:space-between; margin-bottom:20px; background:#fff; padding:15px; border-radius:10px; border:1px solid #eee;">
                    <span>📖 Stock Teórico (Sistema):</span>
                    <strong style="font-size:18px; color:#2c3e50;"><?= number_format($totalCategoria,2) ?></strong>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="CodCat" value="<?= $categoriaSel ?>">
                <input type="hidden" name="unicaja" value="<?= $unicajaSel ?>">
                <input type="hidden" name="stock_sistema" value="<?= $totalCategoria ?>">

                <div class="grid">
                    <div>
                        <label>📥 Cajas</label>
                        <input type="number" step="0.001" name="cajas" placeholder="0" required autofocus>
                    </div>
                    <div>
                        <label>📦 Unidades Sueltas</label>
                        <input type="number" step="0.001" name="unidades" placeholder="0" required>
                    </div>
                </div>
                <button type="submit" name="guardar_conteo" class="btn-save">FINALIZAR CONTEO</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if($conteos): ?>
        <div style="margin-top:40px;">
            <h4 style="margin-bottom:15px; color:#666; border-bottom:1px solid #eee; padding-bottom:8px;">HISTORIAL DE HOY</h4>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Categoría</th>
                            <?php if($AUT_CORREGIR==='SI' || $AUT_VERSTOCK==='SI'): ?>
                                <th>Sis.</th>
                                <th>Dif.</th>
                            <?php endif; ?>
                            <th>Físico</th>
                            <th>Edo.</th>
                            <?php if($AUT_BORRAR==='SI'): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($conteos as $c): 
                            $dif = (float)$c['diferencia'];
                            // Lógica corregida: SOLO ROJO SI ES NEGATIVO (Falta mercancía)
                            $color = ($dif < 0) ? 'rojo' : 'verde';
                        ?>
                        <tr>
                            <td style="color:#999; font-size:10px;"><?= $c['hora'] ?></td>
                            <td style="font-size:13px;"><strong><?= $c['CodCat'] ?></strong><br><small><?= $c['Nombre'] ?></small></td>
                            
                            <?php if($AUT_CORREGIR==='SI' || $AUT_VERSTOCK==='SI'): ?>
                                <td style="color:#666; font-size:13px;"><?= number_format($c['stock_sistema'],2) ?></td>
                                <td style="font-size:13px; font-weight:bold; color: <?= ($dif < 0) ? '#dc3545' : '#28a745' ?>;">
                                    <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                                </td>
                            <?php endif; ?>

                            <td style="font-size:15px;"><strong><?= number_format($c['stock_fisico'],2) ?></strong></td>
                            <td align="center"><span class="semaforo <?= $color ?>" title="Diferencia: <?= $dif ?>"></span></td>
                            
                            <?php if($AUT_BORRAR==='SI'): ?>
                            <td>
                                <form method="POST" onsubmit="return confirm('¿Anular registro?')">
                                    <input type="hidden" name="id_conteo" value="<?= $c['id'] ?>">
                                    <button name="borrar_conteo" style="background:none; border:none; cursor:pointer; font-size:16px;">🗑️</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="modalProductos" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="cerrarModal()">&times;</span>
        <h3 id="modal-titulo" style="border-bottom:2px solid #17a2b8; padding-bottom:10px;">Detalle de Productos</h3>
        <div id="tabla-productos">Cargando...</div>
    </div>
</div>

<script>
function verDetalleProductos(codCat) {
    const modal = document.getElementById('modalProductos');
    const contenedor = document.getElementById('tabla-productos');
    modal.style.display = 'block';
    contenedor.innerHTML = '<div style="text-align:center; padding:30px;">⏳ Consultando...</div>';

    const formData = new FormData();
    formData.append('action', 'ver_productos');
    formData.append('cod_cat', codCat);
    formData.append('nit', '<?= $nitSesion ?>');

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.text()).then(html => { contenedor.innerHTML = html; })
    .catch(() => { contenedor.innerHTML = 'Error de conexión'; });
}
function cerrarModal() { document.getElementById('modalProductos').style.display = 'none'; }
window.onclick = e => { if (e.target.className === 'modal') cerrarModal(); }
</script>

</body>
</html>