<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
    CONFIGURACIÓN DE EMPRESAS
============================================================ */
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');
define('SUC_DEFAULT', '001');

session_start();
$UsuarioSesion   = $_SESSION['Usuario']    ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

$aut_0003 = Autorizacion($UsuarioSesion, '0003'); 
$aut_9999 = Autorizacion($UsuarioSesion, '9999');

if ($aut_0003 !== "SI" && $aut_9999 !== "SI") {
    die("<h2 style='color:red'>❌ No tiene autorización para acceder a esta página</h2>");
}

/* ============================================================
    FUNCIONES
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    if (!isset($_SESSION['Autorizaciones'])) $_SESSION['Autorizaciones'] = [];
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) return $_SESSION['Autorizaciones'][$key];

    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}

function moverInventario($barcode, $cantidad, $origen, $destino, $observacion, $mysqliCentral, $mysqliDrinks, $mysqliWeb) {
    if($cantidad == 0) return ['error'=>'La cantidad no puede ser cero']; 
    if($origen === $destino) return ['error'=>'El origen y destino deben ser diferentes'];

    $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitOrig = ($origen == "Central") ? NIT_CENTRAL : NIT_DRINKS;
    $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitDest = ($destino == "Central") ? NIT_CENTRAL : NIT_DRINKS;

    $stmtO = $dbOrig->prepare("SELECT p.idproducto FROM productos p WHERE p.barcode=?");
    $stmtO->bind_param("s", $barcode);
    $stmtO->execute();
    $dataO = $stmtO->get_result()->fetch_assoc();
    if(!$dataO) return ['error'=>'Producto no existe en origen'];

    $stmtD = $dbDest->prepare("SELECT idproducto FROM productos WHERE barcode=?");
    $stmtD->bind_param("s", $barcode);
    $stmtD->execute();
    $idDest = $stmtD->get_result()->fetch_assoc()['idproducto'] ?? null;
    if(!$idDest) return ['error'=>'El producto no existe en el destino'];

    try {
        $mysqliCentral->begin_transaction();
        $mysqliDrinks->begin_transaction();
        $mysqliWeb->begin_transaction();

        $upO = $dbOrig->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE idproducto = ?");
        $upO->bind_param("di", $cantidad, $dataO['idproducto']);
        $upO->execute();

        $checkD = $dbDest->prepare("SELECT idproducto FROM inventario WHERE idproducto = ?");
        $checkD->bind_param("i", $idDest);
        $checkD->execute();
        if($checkD->get_result()->num_rows > 0) {
            $upD = $dbDest->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE idproducto = ?");
        } else {
            $upD = $dbDest->prepare("INSERT INTO inventario (cantidad, idproducto) VALUES (?, ?)");
        }
        $upD->bind_param("di", $cantidad, $idDest);
        $upD->execute();

        $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado) VALUES (?, ?, ?, 'SALE', ?, ?, ?, ?, ?, 1)";
        $suc = SUC_DEFAULT;
        $stmtLog = $mysqliWeb->prepare($sqlLog);
        $stmtLog->bind_param("ssssddss", $nitOrig, $suc, $UsuarioSesion, $barcode, $cantidad, $nitDest, $suc, $observacion);
        $stmtLog->execute();

        $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
        return ['ok'=>true];
    } catch(Exception $e) {
        $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
        return ['error'=>$e->getMessage()];
    }
}

/* ============================================================
    POST: Movimiento y Aprobaciones
============================================================ */
if($_SERVER['REQUEST_METHOD']=='POST'){
    if(isset($_POST['mover'])){
        $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual";
        $res = moverInventario($_POST['barcode'], (float)$_POST['cantidad'], $_POST['origen'], $_POST['destino'], $obs, $mysqliCentral, $mysqliDrinks, $mysqliWeb);
        $msg = isset($res['error']) ? "<p style='color:red; font-weight:bold;'>❌ {$res['error']}</p>" : "<p style='color:green; font-weight:bold;'>✅ Movimiento registrado</p>";
    }
    
    if(isset($_POST['aprobar']) && $aut_9999=="SI"){
        $id = (int)$_POST['idMov'];
        $nuevo = (int)$_POST['Aprobado'];
        $res = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE idMov=$id")->fetch_assoc();
        if($res && $nuevo == 0 && $res['Aprobado'] == 1){
            // Lógica de reversión simple
            $origDb = ($res['NitEmpresa_Orig']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;
            $destDb = ($res['NitEmpresa_Dest']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;
            $idO = $origDb->query("SELECT idproducto FROM productos WHERE barcode='{$res['barcode']}'")->fetch_assoc()['idproducto'];
            $idD = $destDb->query("SELECT idproducto FROM productos WHERE barcode='{$res['barcode']}'")->fetch_assoc()['idproducto'];
            $origDb->query("UPDATE inventario SET cantidad=cantidad+{$res['cant']} WHERE idproducto=$idO");
            $destDb->query("UPDATE inventario SET cantidad=cantidad-{$res['cant']} WHERE idproducto=$idD");
            $mysqliWeb->query("UPDATE inventario_movimientos SET Aprobado=0 WHERE idMov=$id");
        }
    }
}

/* ============================================================
    CONSULTA DE PRODUCTOS Y TRASLADOS (FILTROS)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term = $_GET['term'] ?? '';
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page-1)*$limit;

// Categorías
$cats=[]; $resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while($c=$resCat->fetch_assoc()) $cats[$c['CodCat']]=$c['Nombre'];

// Relación Producto-Categoría
$prodCat=[]; $resPC = $mysqliWeb->query("SELECT sku,CodCat FROM catproductos");
while($r=$resPC->fetch_assoc()) $prodCat[$r['sku']]=$r['CodCat'];

// Consulta principal de productos (se mantiene igual a tu lógica original pero optimizada)
$barcodes = []; $central = []; $drinks = []; $totalPages = 1;
if($term !== '' || $categoria !== ''){
    $like = "%$term%";
    $where = "p.estado='1' AND (p.descripcion LIKE '$like' OR p.barcode LIKE '$like')";
    if($categoria){
        $resBC = $mysqliWeb->query("SELECT sku FROM catproductos WHERE CodCat='$categoria'");
        $skus = []; while($r=$resBC->fetch_assoc()) $skus[]="'".$r['sku']."'";
        if($skus) $where .= " AND p.barcode IN (".implode(',',$skus).")";
        else $where .= " AND 1=0";
    }
    
    $resCount = $mysqliCentral->query("SELECT COUNT(DISTINCT barcode) as total FROM productos p WHERE $where");
    $totalPages = ceil(($resCount->fetch_assoc()['total'] ?? 0) / $limit);

    $sql = "SELECT p.barcode, p.descripcion, IFNULL(i.cantidad,0) cantidad FROM productos p LEFT JOIN inventario i ON p.idproducto=i.idproducto WHERE $where LIMIT $limit OFFSET $offset";
    $rc = $mysqliCentral->query($sql); while($r=$rc->fetch_assoc()) $central[$r['barcode']]=$r;
    $rd = $mysqliDrinks->query($sql);  while($r=$rd->fetch_assoc()) $drinks[$r['barcode']]=$r;
    $barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
}

// Consulta de Movimientos para el Modal
$sqlMovs = "SELECT * FROM inventario_movimientos WHERE DATE(fecha) BETWEEN '$f_inicio' AND '$f_fin' ORDER BY fecha DESC LIMIT 500";
$resMov = $mysqliWeb->query($sqlMovs);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Inventario Pro</title>
<style>
    body{font-family:sans-serif; background:#f4f7f6; padding:20px; font-size: 13px;}
    .container{max-width:1500px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);}
    table{width:100%; border-collapse:collapse; margin-top:20px;}
    th{background:#2c3e50; color:white; padding:10px;}
    td{border-bottom:1px solid #eee; padding:8px; text-align:center;}
    .btn-move{background:#27ae60; color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:bold;}
    .input-s{padding:5px; border:1px solid #ccc; border-radius:4px;}
    .modal{display:<?= isset($_GET['f_inicio']) ? 'block' : 'none' ?>; position:fixed; z-index:999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);}
    .modal-content{background:#fff; margin:30px auto; padding:20px; border-radius:8px; width:90%; max-height:85vh; overflow-y:auto;}
    .close{float:right; font-size:24px; cursor:pointer; font-weight:bold;}
    .negativo{ color: red; font-weight: bold; }
</style>
</head>
<body>

<div class="container">
    <h2>📦 Gestión de Inventario (Traslados y Correcciones)</h2>
    <?php if(isset($msg)) echo $msg; ?>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:20px; background:#eee; padding:15px; border-radius:5px;">
        <select name="categoria" class="input-s">
            <option value="">Todas las categorías</option>
            <?php foreach($cats as $k=>$v) echo "<option value='$k' ".($categoria==$k?'selected':'').">$k - $v</option>"; ?>
        </select>
        <input type="text" name="term" placeholder="Buscar producto..." value="<?= htmlspecialchars($term) ?>" class="input-s" style="flex-grow:1">
        <button type="submit" class="btn-move" style="background:#2980b9">🔍 Buscar</button>
        <button type="button" class="btn-move" style="background:#f39c12" onclick="document.getElementById('modal').style.display='block'">📅 Ver Historial</button>
        <a href="?" style="padding:8px;">Limpiar</a>
    </form>

    <?php if($barcodes): ?>
    <table>
        <thead>
            <tr>
                <th>Categoría</th><th>Barcode</th><th style="text-align:left">Producto</th>
                <th>Drinks</th><th>Central</th><th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($barcodes as $b): 
                $vC = $central[$b]['cantidad'] ?? 0;
                $vD = $drinks[$b]['cantidad'] ?? 0;
            ?>
            <tr>
                <td><?= $prodCat[$b] ?? '---' ?></td>
                <td><code><?= $b ?></code></td>
                <td style="text-align:left"><?= htmlspecialchars($central[$b]['descripcion'] ?? $drinks[$b]['descripcion'] ?? 'N/A') ?></td>
                <td style="color:blue; font-weight:bold"><?= number_format($vD,2) ?></td>
                <td style="color:green; font-weight:bold"><?= number_format($vC,2) ?></td>
                <td>
                    <form method="POST" style="display:flex; gap:5px; justify-content:center;">
                        <input type="hidden" name="barcode" value="<?= $b ?>">
                        <input type="number" name="cantidad" step="0.001" style="width:70px" class="input-s" placeholder="Cant." required>
                        <select name="origen" class="input-s"><option value="Central">Cen</option><option value="Drinks">Dri</option></select>
                        <select name="destino" class="input-s"><option value="Drinks">Dri</option><option value="Central">Cen</option></select>
                        <input type="text" name="observacion" placeholder="Obs..." class="input-s" style="width:120px">
                        <button type="submit" name="mover" class="btn-move">Mover</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div id="modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('modal').style.display='none'">&times;</span>
        <h3>🗓 Historial de Movimientos</h3>
        
        <form method="GET" style="background:#f9f9f9; padding:15px; border:1px solid #ddd; display:flex; gap:15px; align-items:flex-end; margin-bottom:15px;">
            <input type="hidden" name="categoria" value="<?= $categoria ?>">
            <input type="hidden" name="term" value="<?= $term ?>">
            <div>
                <label style="display:block; font-size:11px;">Desde:</label>
                <input type="date" name="f_inicio" value="<?= $f_inicio ?>" class="input-s">
            </div>
            <div>
                <label style="display:block; font-size:11px;">Hasta:</label>
                <input type="date" name="f_fin" value="<?= $f_fin ?>" class="input-s">
            </div>
            <button type="submit" class="btn-move" style="background:#34495e">Filtrar Fecha</button>
            <button type="button" onclick="printMovimientos()" class="btn-move" style="background:#2980b9">🖨 Imprimir</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Fecha</th><th>Usuario</th><th>Barcode</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Observación</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php while($r = $resMov->fetch_assoc()): ?>
                <tr>
                    <td><?= $r['fecha'] ?></td>
                    <td><?= $r['usuario_Orig'] ?></td>
                    <td><?= $r['barcode'] ?></td>
                    <td class="<?= $r['cant'] < 0 ? 'negativo' : '' ?>"><?= number_format($r['cant'],2) ?></td>
                    <td><?= $r['NitEmpresa_Orig'] ?></td>
                    <td><?= $r['NitEmpresa_Dest'] ?></td>
                    <td><?= htmlspecialchars($r['Observacion']) ?></td>
                    <td>
                        <?php if($aut_9999=="SI" && $r['Aprobado']==1): ?>
                            <form method="POST" onsubmit="return confirm('¿Reversar movimiento?')">
                                <input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
                                <input type="hidden" name="Aprobado" value="0">
                                <button type="submit" name="aprobar" style="cursor:pointer; background:none; border:none;">✅</button>
                            </form>
                        <?php else: echo $r['Aprobado']==1 ? '✅' : '❌ Reversado'; endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function printMovimientos(){
    var div = document.querySelector("#modal table").outerHTML;
    var win = window.open('', '', 'height=700,width=900');
    win.document.write('<html><head><title>Reporte</title><style>table{width:100%;border-collapse:collapse;} th,td{border:1px solid #000;padding:5px;font-size:10px;}</style></head><body>');
    win.document.write('<h2>Reporte de Movimientos</h2>' + div);
    win.document.close();
    win.print();
}
</script>

</body>
</html>