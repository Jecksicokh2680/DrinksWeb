<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
   CONFIGURACIÓN DE EMPRESAS (NITs Proporcionados)
============================================================ */
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');
define('SUC_DEFAULT', '001');

session_start();
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

$aut_0003 = Autorizacion($UsuarioSesion, '0003'); // Retorna "SI" o "NO"
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
    if($cantidad <= 0) return ['error'=>'Cantidad inválida'];
    if($origen === $destino) return ['error'=>'El origen y destino deben ser diferentes'];

    $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitOrig = ($origen == "Central") ? NIT_CENTRAL : NIT_DRINKS;
    $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitDest = ($destino == "Central") ? NIT_CENTRAL : NIT_DRINKS;

    $stmtO = $dbOrig->prepare("SELECT p.idproducto, IFNULL(i.cantidad,0) as stock FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto WHERE p.barcode=?");
    $stmtO->bind_param("s", $barcode);
    $stmtO->execute();
    $dataO = $stmtO->get_result()->fetch_assoc();
    if(!$dataO) return ['error'=>'Producto no existe en origen'];
    if($dataO['stock'] < $cantidad) return ['error'=>'Stock insuficiente (Disp: '.$dataO['stock'].')'];

    $stmtD = $dbDest->prepare("SELECT idproducto FROM productos WHERE barcode=?");
    $stmtD->bind_param("s", $barcode);
    $stmtD->execute();
    $idDest = $stmtD->get_result()->fetch_assoc()['idproducto'] ?? null;
    if(!$idDest) return ['error'=>'El producto no existe en el destino'];

    try {
        $mysqliCentral->begin_transaction();
        $mysqliDrinks->begin_transaction();
        $mysqliWeb->begin_transaction();

        // 1. Restar Origen
        $upO = $dbOrig->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE idproducto = ?");
        $upO->bind_param("di", $cantidad, $dataO['idproducto']);
        $upO->execute();

        // 2. Sumar Destino
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

        // 3. Log
        $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado) VALUES (?, ?, 'ADMIN', 'SALE', ?, ?, ?, ?, ?, 1)";
        $suc = SUC_DEFAULT;
        $stmtLog = $mysqliWeb->prepare($sqlLog);
        $stmtLog->bind_param("sssddss", $nitOrig, $suc, $barcode, $cantidad, $nitDest, $suc, $observacion);
        $stmtLog->execute();

        $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
        return ['ok'=>true];
    } catch(Exception $e) {
        $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
        return ['error'=>$e->getMessage()];
    }
}

/* ============================================================
   POST: Movimiento
============================================================ */
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['mover'])){
    $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual web";
    $res = moverInventario($_POST['barcode'], $_POST['cantidad'], $_POST['origen'], $_POST['destino'], $obs, $mysqliCentral, $mysqliDrinks, $mysqliWeb);
    $msg = isset($res['error']) ? "<p style='color:red; font-weight:bold;'>❌ {$res['error']}</p>" : "<p style='color:green; font-weight:bold;'>✅ Movimiento registrado correctamente</p>";
}

/* ============================================================
   CONSULTA DE PRODUCTOS (Solo al buscar)
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term = $_GET['term'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page-1)*$limit;
$like = "%$term%";

// Categorías
$cats=[]; $resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while($c=$resCat->fetch_assoc()) $cats[$c['CodCat']]=$c['Nombre'];

// Relación SKU -> Categoría
$prodCat=[]; $resPC = $mysqliWeb->query("SELECT sku,CodCat FROM catproductos");
while($r=$resPC->fetch_assoc()) $prodCat[$r['sku']]=$r['CodCat'];

// Barcodes de categoría
$barcodesCat=[];
if($categoria){
    $stmtCat = $mysqliWeb->prepare("SELECT sku FROM catproductos WHERE CodCat=?");
    $stmtCat->bind_param("s",$categoria);
    $stmtCat->execute();
    $resBC = $stmtCat->get_result();
    while($r=$resBC->fetch_assoc()) $barcodesCat[]=$r['sku'];
    if(!$barcodesCat) $barcodesCat=['__NONE__'];
}

// Solo ejecutar consulta si hay término o categoría
$central = [];
$drinks  = [];
$barcodes = [];
$totalPages = 1;

if($term !== '' || $categoria !== ''){
    $where = "p.estado='1' AND (p.descripcion LIKE ? OR p.barcode LIKE ?)";
    $params = [$like, $like];
    $types = "ss";

    if(!empty($barcodesCat)){
        $placeholders = implode(',', array_fill(0, count($barcodesCat), '?'));
        $where .= " AND p.barcode IN ($placeholders)";
        foreach($barcodesCat as $b){ $params[]=$b; $types.="s"; }
    }

    $sqlCount = "SELECT COUNT(DISTINCT p.barcode) total FROM productos p WHERE $where";
    $stmtCnt = $mysqliCentral->prepare($sqlCount);
    $stmtCnt->bind_param($types, ...$params);
    $stmtCnt->execute();
    $totalRows = $stmtCnt->get_result()->fetch_assoc()['total'];
    $totalPages = max(1, ceil($totalRows/$limit));

    $sql = "SELECT p.barcode, p.descripcion, IFNULL(SUM(i.cantidad),0) cantidad 
            FROM productos p LEFT JOIN inventario i ON p.idproducto=i.idproducto 
            WHERE $where 
            GROUP BY p.barcode,p.descripcion 
            ORDER BY p.barcode ASC 
            LIMIT $limit OFFSET $offset";

    function fetchResults($db, $sql, $params, $types){
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $data=[];
        foreach($stmt->get_result() as $r) $data[$r['barcode']]=$r;
        return $data;
    }

    $central = fetchResults($mysqliCentral, $sql, $params, $types);
    $drinks  = fetchResults($mysqliDrinks, $sql, $params, $types);
    $barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
    usort($barcodes, function($a,$b) use($prodCat){ return ($prodCat[$a]??'SIN')<=>($prodCat[$b]??'SIN'); });
}

/* ============================================================
   POST: Actualizar Aprobado / Reversión
============================================================ */
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['aprobar']) && $aut_9999=="SI"){
    $id = (int)$_POST['idMov'];
    $nuevo = (int)$_POST['Aprobado'];

    $res = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE idMov=$id")->fetch_assoc();
    if($res){
        $origDb = ($res['NitEmpresa_Orig']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;
        $destDb = ($res['NitEmpresa_Dest']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;

        $cantidad = $res['cant'];
        $idOrig = $origDb->query("SELECT idproducto FROM productos WHERE barcode='{$res['barcode']}'")->fetch_assoc()['idproducto'];
        $idDest = $destDb->query("SELECT idproducto FROM productos WHERE barcode='{$res['barcode']}'")->fetch_assoc()['idproducto'];

        if($nuevo==1){
            $mysqliWeb->query("UPDATE inventario_movimientos SET Aprobado=1 WHERE idMov=$id");
        }else{
            $origDb->query("UPDATE inventario SET cantidad=cantidad+$cantidad WHERE idproducto=$idOrig");
            $destDb->query("UPDATE inventario SET cantidad=cantidad-$cantidad WHERE idproducto=$idDest");
            $mysqliWeb->query("UPDATE inventario_movimientos SET Aprobado=0 WHERE idMov=$id");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inventario Consolidado</title>
<style>
/* === Estilos base === */
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:#f4f7f6; padding:20px;}
.container{max-width:1500px; margin:auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);}
table{width:100%; border-collapse:collapse; margin-top:20px;}
th{background:#2c3e50; color:white; padding:12px; font-size:13px; text-transform: uppercase;}
td{border-bottom:1px solid #eee; padding:8px; text-align:center; font-size:13px;}
.subtotal{background:#f8f9fa; font-weight:bold; color: #34495e;}
.btn-move{background:#27ae60; color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:bold;}
.btn-move:hover{background:#219150;}
.input-s{padding:5px; border:1px solid #ccc; border-radius:4px; font-size:12px;}
.total-col{background:#ebf5fb; font-weight:bold;}
/* Modal */
.modal{display:none; position:fixed; z-index:999; left:0; top:0; width:100%; height:100%; overflow:auto; background:rgba(0,0,0,0.5);}
.modal-content{background:#fff; margin:50px auto; padding:20px; border-radius:8px; max-width:95%; max-height:80%; overflow:auto;}
.close{float:right; font-size:22px; font-weight:bold; cursor:pointer;}
.scroll-table{overflow:auto; max-height:500px;}
</style>
</head>
<body>
<div class="container">
<h2>📦 Gestión de Inventario: Central + Drinks</h2>
<?php if(isset($msg)) echo $msg; ?>

<form method="GET" style="display:flex; gap:10px; margin-bottom:20px; background: #eee; padding: 15px; border-radius: 5px;">
<select name="categoria" class="input-s">
<option value="">Todas las categorías</option>
<?php foreach ($cats as $k=>$v): ?>
<option value="<?= $k ?>" <?= $categoria==$k?'selected':'' ?>><?= "$k - $v" ?></option>
<?php endforeach; ?>
</select>
<input type="text" name="term" placeholder="Buscar por nombre o barcode..." value="<?= htmlspecialchars($term) ?>" class="input-s" style="flex-grow:1">
<button type="submit" class="btn-move" style="background:#2980b9">🔍 Buscar</button>
<a href="?" style="text-decoration:none; color:#666; padding:7px;">Limpiar</a>
<button type="button" class="btn-move" style="background:#f39c12" onclick="document.getElementById('modal').style.display='block'">Ver Movimientos</button>
</form>

<?php if(!empty($barcodes)): ?>
<table>
<thead>
<tr>
<th>CodCat</th>
<th>Nombre Categoría</th>
<th>Barcode</th>
<th style="text-align:left">Producto</th>
<th>Drinks</th>
<th>Central</th>
<th class="total-col">Total</th>
<th>Traspaso / Observación</th>
</tr>
</thead>
<tbody>
<?php
$lastCat = ''; $sD = $sC = $sT = 0;
foreach($barcodes as $b):
    $cC = $central[$b] ?? null;
    $cD = $drinks[$b] ?? null;
    $vC = $cC['cantidad']??0;
    $vD = $cD['cantidad']??0;
    $vT = $vC+$vD;
    $cat = $prodCat[$b]??'SIN';
    $catNom = $cats[$cat]??'Sin Categoría';

    if($lastCat!=='' && $lastCat!==$cat){
        echo "<tr class='subtotal'><td colspan='4' style='text-align:right'>SUBTOTAL {$lastCat} ({$cats[$lastCat]}) →</td><td>".number_format($sD,2)."</td><td>".number_format($sC,2)."</td><td class='total-col'>".number_format($sT,2)."</td><td></td></tr>";
        $sD=$sC=$sT=0;
    }
    $sD+=$vD; $sC+=$vC; $sT+=$vT; $lastCat=$cat;
?>
<tr>
<td><?= $cat ?></td>
<td><?= $catNom ?></td>
<td><code><?= $b ?></code></td>
<td style="text-align:left"><?= htmlspecialchars($cC['descripcion']??$cD['descripcion']??'N/A') ?></td>
<td style="color:blue; font-weight:bold"><?= number_format($vD,2) ?></td>
<td style="color:green; font-weight:bold"><?= number_format($vC,2) ?></td>
<td class="total-col"><?= number_format($vT,2) ?></td>
<td>
<form method="POST" style="display:flex; gap:5px; justify-content:center; align-items:center;">
<input type="hidden" name="barcode" value="<?= $b ?>">
<input type="number" name="cantidad" step="0.001" style="width:60px" class="input-s" placeholder="Cant." required>
<select name="origen" class="input-s"><option value="Central">Cen</option><option value="Drinks">Dri</option></select>
<span>→</span>
<select name="destino" class="input-s"><option value="Drinks">Dri</option><option value="Central">Cen</option></select>
<input type="text" name="observacion" placeholder="Motivo..." class="input-s" style="width:180px">
<button type="submit" name="mover" class="btn-move">Mover</button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php if($lastCat!==''): ?>
<tr class="subtotal"><td colspan="4" style="text-align:right">SUBTOTAL <?= $lastCat ?> (<?= $cats[$lastCat] ?>) →</td><td><?= number_format($sD,2) ?></td><td><?= number_format($sC,2) ?></td><td class="total-col"><?= number_format($sT,2) ?></td><td></td></tr>
<?php endif; ?>
</tbody>
</table>

<!-- Paginación -->
<div style="margin-top:25px; text-align:center;">
<?php for($i=1;$i<=$totalPages;$i++): ?>
<a href="?page=<?=$i?>&categoria=<?=$categoria?>&term=<?=$term?>" style="padding:8px 12px; border:1px solid #2980b9; text-decoration:none; color:<?=$i==$page?'#fff':'#2980b9'?>; background:<?=$i==$page?'#2980b9':'#fff'?>; border-radius:4px; margin:0 2px;"><?=$i?></a>
<?php endfor; ?>
</div>
<?php endif; ?>
</div>

<!-- Modal Movimientos -->
<div id="modal" class="modal">
<div class="modal-content">
<span class="close" onclick="document.getElementById('modal').style.display='none'">&times;</span>
<h3>Histórico de Movimientos</h3>
<button onclick="printMovimientos()" class="btn-move" style="background:#2980b9; margin-bottom:10px;">🖨 Imprimir Movimientos</button>
<div class="scroll-table">
<table border="1" style="width:100%; border-collapse:collapse;">
<thead>
<tr>
<th>ID</th>
<th>Usuario</th>
<th>Barcode</th>
<th>Cant</th>
<th>Tipo</th>
<th>Origen</th>
<th>Destino</th>
<th>Obs.</th>
<th>Aprobado</th>
<th>Fecha</th>
</tr>
</thead>
<tbody>
<?php
$resMov = $mysqliWeb->query("SELECT * FROM inventario_movimientos ORDER BY fecha DESC LIMIT 500");
while($r=$resMov->fetch_assoc()):
?>
<tr>
<td><?= $r['idMov'] ?></td>
<td><?= $r['usuario_Orig'] ?></td>
<td><?= $r['barcode'] ?></td>
<td><?= number_format($r['cant'],2) ?></td>
<td><?= $r['tipo'] ?></td>
<td><?= $r['NitEmpresa_Orig'] ?></td>
<td><?= $r['NitEmpresa_Dest'] ?></td>
<td><?= htmlspecialchars($r['Observacion']) ?></td>
<td>
<?php if($aut_9999=="SI"): 
$disabled = ($r['Aprobado']==0) ? "disabled style='background:#eee; cursor:not-allowed' title='Movimiento reversado'" : "";
?>
<form method="POST" style="margin:0;">
<input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
<select name="Aprobado" onchange="this.form.submit()" <?= $disabled ?>>
<option value="1" <?= $r['Aprobado']==1?'selected':'' ?>>✔</option>
<option value="0" <?= $r['Aprobado']==0?'selected':'' ?>>✖</option>
</select>
<input type="hidden" name="aprobar">
</form>
<?php else: ?>
<?= $r['Aprobado']==1?'✔':'✖' ?>
<?php endif; ?>
</td>
<td><?= $r['fecha'] ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<script>
// Cerrar modal al clic fuera
window.onclick = function(event){
    if(event.target==document.getElementById('modal')) document.getElementById('modal').style.display="none";
}

// Imprimir movimientos con encabezado usuario/fecha-hora
function printMovimientos(){
    var contenido = document.querySelector('#modal .scroll-table').innerHTML;
    var usuario = "<?= htmlspecialchars($UsuarioSesion) ?>";
    var fechaHora = new Date().toLocaleString('es-CO', {hour12:false});

    var vent = window.open('', 'Imprimir Movimientos', 'width=900,height=600');
    vent.document.write('<html><head><title>Movimientos</title>');
    vent.document.write('<style>');
    vent.document.write('body{font-family:Segoe UI, Tahoma, Geneva, Verdana, sans-serif;}');
    vent.document.write('table{width:100%;border-collapse:collapse;}');
    vent.document.write('th,td{border:1px solid #000;padding:5px;text-align:center;font-size:12px;}');
    vent.document.write('th{background:#ccc;}');
    vent.document.write('.encabezado{margin-bottom:15px;}');
    vent.document.write('.encabezado span{display:inline-block;margin-right:15px;font-weight:bold;}');
    vent.document.write('</style>');
    vent.document.write('</head><body>');
    vent.document.write('<div class="encabezado">');
    vent.document.write('<span>Usuario: ' + usuario + '</span>');
    vent.document.write('<span>Fecha/Hora: ' + fechaHora + '</span>');
    vent.document.write('</div>');
    vent.document.write(contenido);
    vent.document.write('</body></html>');
    vent.document.close();
    vent.print();
}
</script>
</body>
</html>
