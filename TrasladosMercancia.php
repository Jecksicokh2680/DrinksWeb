<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

/* ============================================================
    CONFIGURACIÓN DE EMPRESAS Y SEGURIDAD
============================================================ */
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');
define('SUC_DEFAULT', '001');

session_start();
$UsuarioSesion   = $_SESSION['Usuario']    ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

// Función de Autorización
function Autorizacion($User, $Solicitud) {
    global $mysqli; // Conexión de Conexion.php
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

$aut_0003 = Autorizacion($UsuarioSesion, '0003'); 
$aut_9999 = Autorizacion($UsuarioSesion, '9999');

if ($aut_0003 !== "SI" && $aut_9999 !== "SI") {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No tiene autorización para acceder a esta página</h2>");
}

/* ============================================================
    LÓGICA CORE: MOVER INVENTARIO + MARCADO DE CATEGORÍA
============================================================ */
function moverInventario($barcode, $cantidad, $origen, $destino, $observacion, $mysqliCentral, $mysqliDrinks, $mysqliWeb) {
    if($cantidad <= 0) return ['error'=>'La cantidad debe ser mayor a cero']; 
    if($origen === $destino) return ['error'=>'El origen y destino deben ser diferentes'];

    $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitOrig = ($origen == "Central") ? NIT_CENTRAL : NIT_DRINKS;
    $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
    $nitDest = ($destino == "Central") ? NIT_CENTRAL : NIT_DRINKS;

    // Buscar en origen
    $stmtO = $dbOrig->prepare("SELECT p.idproducto FROM productos p WHERE p.barcode=?");
    $stmtO->bind_param("s", $barcode);
    $stmtO->execute();
    $dataO = $stmtO->get_result()->fetch_assoc();
    if(!$dataO) return ['error'=>'Producto no existe en origen'];

    // Buscar en destino
    $stmtD = $dbDest->prepare("SELECT idproducto FROM productos WHERE barcode=?");
    $stmtD->bind_param("s", $barcode);
    $stmtD->execute();
    $idDest = $stmtD->get_result()->fetch_assoc()['idproducto'] ?? null;
    if(!$idDest) return ['error'=>'El producto no existe en el destino'];

    try {
        $mysqliCentral->begin_transaction();
        $mysqliDrinks->begin_transaction();
        $mysqliWeb->begin_transaction();

        // 1. Descontar de Origen
        $upO = $dbOrig->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE idproducto = ?");
        $upO->bind_param("di", $cantidad, $dataO['idproducto']);
        $upO->execute();

        // 2. Aumentar en Destino
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

        // 3. Registrar Log Movimiento
        $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado) VALUES (?, ?, ?, 'SALE', ?, ?, ?, ?, ?, 1)";
        $suc = SUC_DEFAULT;
        $usu = $_SESSION['Usuario'] ?? 'SISTEMA';
        $stmtLog = $mysqliWeb->prepare($sqlLog);
        $stmtLog->bind_param("ssssddss", $nitOrig, $suc, $usu, $barcode, $cantidad, $nitDest, $suc, $observacion);
        $stmtLog->execute();

        // 4. NUEVO: Marcar categoría en catproductos -> categorias
        $resCat = $mysqliWeb->prepare("SELECT CodCat FROM catproductos WHERE sku = ? LIMIT 1");
        $resCat->bind_param("s", $barcode);
        $resCat->execute();
        $infoCat = $resCat->get_result()->fetch_assoc();

        if ($infoCat) {
            $codCat = $infoCat['CodCat'];
            $updCat = $mysqliWeb->prepare("UPDATE categorias SET SegWebT = '1' WHERE CodCat = ?");
            $updCat->bind_param("s", $codCat);
            $updCat->execute();
        }

        $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
        return ['ok'=>true];
    } catch(Exception $e) {
        $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
        return ['error'=>$e->getMessage()];
    }
}

/* ============================================================
    POST: MOVIMIENTOS Y REVERSIONES
============================================================ */
if($_SERVER['REQUEST_METHOD']=='POST'){
    if(isset($_POST['mover'])){
        $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual";
        $res = moverInventario($_POST['barcode'], (float)$_POST['cantidad'], $_POST['origen'], $_POST['destino'], $obs, $mysqliCentral, $mysqliDrinks, $mysqliWeb);
        $msg = isset($res['error']) ? "<div class='alert err'>❌ {$res['error']}</div>" : "<div class='alert ok'>✅ Movimiento registrado y categoría actualizada</div>";
    }
    
    if(isset($_POST['aprobar']) && $aut_9999=="SI"){
        $id = (int)$_POST['idMov'];
        $res = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE idMov=$id")->fetch_assoc();
        if($res && $res['Aprobado'] == 1){
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
    FILTROS Y CONSULTAS
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term = $_GET['term'] ?? '';
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$limit = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*$limit;

$cats=[]; $resCat = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1' ORDER BY CodCat ASC");
while($c=$resCat->fetch_assoc()) $cats[$c['CodCat']]=$c['Nombre'];

$prodCat=[]; $resPC = $mysqliWeb->query("SELECT sku,CodCat FROM catproductos");
while($r=$resPC->fetch_assoc()) $prodCat[$r['sku']]=$r['CodCat'];

$barcodes = []; $central = []; $drinks = [];
if($term !== '' || $categoria !== ''){
    $like = "%$term%";
    $where = "p.estado='1' AND (p.descripcion LIKE '$like' OR p.barcode LIKE '$like')";
    if($categoria){
        $resBC = $mysqliWeb->query("SELECT sku FROM catproductos WHERE CodCat='$categoria'");
        $skus = []; while($r=$resBC->fetch_assoc()) $skus[]="'".$r['sku']."'";
        if($skus) $where .= " AND p.barcode IN (".implode(',',$skus).")"; else $where .= " AND 1=0";
    }
    
    $sql = "SELECT p.barcode, p.descripcion, IFNULL(i.cantidad,0) cantidad FROM productos p LEFT JOIN inventario i ON p.idproducto=i.idproducto WHERE $where LIMIT $limit OFFSET $offset";
    $rc = $mysqliCentral->query($sql); while($r=$rc->fetch_assoc()) $central[$r['barcode']]=$r;
    $rd = $mysqliDrinks->query($sql);  while($r=$rd->fetch_assoc()) $drinks[$r['barcode']]=$r;
    $barcodes = array_unique(array_merge(array_keys($central), array_keys($drinks)));
}

$resMov = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE DATE(fecha) BETWEEN '$f_inicio' AND '$f_fin' ORDER BY fecha DESC LIMIT 500");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Inventario Pro</title>
    <style>
        body{font-family:'Segoe UI', Tahoma, sans-serif; background:#f4f7f6; padding:20px; font-size:13px;}
        .container{max-width:1400px; margin:auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.1);}
        .alert{padding:15px; margin-bottom:20px; border-radius:5px; font-weight:bold;}
        .ok{background:#d4edda; color:#155724; border:1px solid #c3e6cb;}
        .err{background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;}
        table{width:100%; border-collapse:collapse; margin-top:20px;}
        th{background:#34495e; color:white; padding:12px;}
        td{border-bottom:1px solid #eee; padding:10px; text-align:center;}
        .btn-move{background:#27ae60; color:white; border:none; padding:8px 15px; border-radius:5px; cursor:pointer; font-weight:bold;}
        .input-s{padding:8px; border:1px solid #ddd; border-radius:5px;}
        .modal{display:<?= isset($_GET['f_inicio']) ? 'block' : 'none' ?>; position:fixed; z-index:999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter: blur(3px);}
        .modal-content{background:#fff; margin:40px auto; padding:25px; border-radius:10px; width:90%; max-height:80vh; overflow-y:auto; position:relative;}
        .close{position:absolute; right:20px; top:15px; font-size:28px; cursor:pointer; color:#999;}
    </style>
</head>
<body>

<div class="container">
    <h2>📦 Gestión de Inventario (Traslados con Marcado Web)</h2>
    
    <?php if(isset($msg)) echo $msg; ?>

    <form method="GET" style="display:flex; gap:12px; margin-bottom:25px; background:#f9f9f9; padding:20px; border-radius:8px; border:1px solid #eee;">
        <select name="categoria" class="input-s" style="width:250px;">
            <option value="">-- Todas las Categorías --</option>
            <?php foreach($cats as $k=>$v) echo "<option value='$k' ".($categoria==$k?'selected':'').">$k - $v</option>"; ?>
        </select>
        <input type="text" name="term" placeholder="Buscar por barcode o descripción..." value="<?= htmlspecialchars($term) ?>" class="input-s" style="flex-grow:1">
        <button type="submit" class="btn-move" style="background:#2980b9">🔍 Buscar</button>
        <button type="button" class="btn-move" style="background:#f39c12" onclick="document.getElementById('modal').style.display='block'">📅 Ver Historial</button>
        <a href="?" style="padding:10px; color:#777; text-decoration:none;">Limpiar</a>
    </form>

    <?php if($barcodes): ?>
    <table>
        <thead>
            <tr>
                <th>Categoría</th><th>Barcode</th><th style="text-align:left">Producto</th>
                <th>Sede Drinks</th><th>Sede Central</th><th>Operación de Traslado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($barcodes as $b): 
                $vC = $central[$b]['cantidad'] ?? 0;
                $vD = $drinks[$b]['cantidad'] ?? 0;
            ?>
            <tr>
                <td><span style="background:#eee; padding:3px 8px; border-radius:4px;"><?= $prodCat[$b] ?? '---' ?></span></td>
                <td><code><?= $b ?></code></td>
                <td style="text-align:left"><?= htmlspecialchars($central[$b]['descripcion'] ?? $drinks[$b]['descripcion'] ?? 'N/A') ?></td>
                <td style="color:#2980b9; font-weight:bold; font-size:15px;"><?= number_format($vD,2) ?></td>
                <td style="color:#27ae60; font-weight:bold; font-size:15px;"><?= number_format($vC,2) ?></td>
                <td>
                    <form method="POST" style="display:flex; gap:8px; justify-content:center;" onsubmit="return confirm('¿Confirmar traslado?')">
                        <input type="hidden" name="barcode" value="<?= $b ?>">
                        <input type="number" name="cantidad" step="0.001" style="width:80px" class="input-s" placeholder="Cant." required>
                        <select name="origen" class="input-s"><option value="Central">Cen</option><option value="Drinks">Dri</option></select>
                        <span style="align-self:center;">➡️</span>
                        <select name="destino" class="input-s"><option value="Drinks">Dri</option><option value="Central">Cen</option></select>
                        <input type="text" name="observacion" placeholder="Motivo..." class="input-s" style="width:150px">
                        <button type="submit" name="mover" class="btn-move">Mover</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif($term || $categoria): ?>
        <p style="text-align:center; padding:50px; color:#888;">No se encontraron productos con los filtros aplicados.</p>
    <?php endif; ?>
</div>

<div id="modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('modal').style.display='none'">&times;</span>
        <h3>🗓 Historial de Movimientos y Traspasos</h3>
        
        <form method="GET" style="background:#f1f1f1; padding:15px; border-radius:8px; display:flex; gap:15px; align-items:flex-end; margin-bottom:20px;">
            <input type="hidden" name="categoria" value="<?= $categoria ?>">
            <input type="hidden" name="term" value="<?= $term ?>">
            <div>
                <label style="display:block; font-size:11px; font-weight:bold; margin-bottom:5px;">DESDE:</label>
                <input type="date" name="f_inicio" value="<?= $f_inicio ?>" class="input-s">
            </div>
            <div>
                <label style="display:block; font-size:11px; font-weight:bold; margin-bottom:5px;">HASTA:</label>
                <input type="date" name="f_fin" value="<?= $f_fin ?>" class="input-s">
            </div>
            <button type="submit" class="btn-move" style="background:#34495e">Filtrar Historial</button>
            <button type="button" onclick="printMovimientos()" class="btn-move" style="background:#2980b9">🖨 Imprimir</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Fecha / Hora</th><th>Usuario</th><th>Barcode</th><th>Cant.</th><th>Origen</th><th>Destino</th><th>Observación</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php while($r = $resMov->fetch_assoc()): ?>
                <tr>
                    <td style="font-size:11px;"><?= $r['fecha'] ?></td>
                    <td><?= $r['usuario_Orig'] ?></td>
                    <td><code><?= $r['barcode'] ?></code></td>
                    <td style="font-weight:bold;"><?= number_format($r['cant'],2) ?></td>
                    <td><small><?= $r['NitEmpresa_Orig'] ?></small></td>
                    <td><small><?= $r['NitEmpresa_Dest'] ?></small></td>
                    <td style="font-style:italic;"><?= htmlspecialchars($r['Observacion']) ?></td>
                    <td>
                        <?php if($aut_9999=="SI" && $r['Aprobado']==1): ?>
                            <form method="POST" onsubmit="return confirm('¿Desea reversar este movimiento? Los stocks volverán a su estado anterior.')">
                                <input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
                                <button type="submit" name="aprobar" style="cursor:pointer; background:none; border:none; font-size:18px;" title="Reversar">✅</button>
                            </form>
                        <?php else: echo $r['Aprobado']==1 ? '✅' : '<span style="color:red;">❌ Reversado</span>'; endif; ?>
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
    var win = window.open('', '', 'height=800,width=1000');
    win.document.write('<html><head><title>Reporte de Movimientos</title>');
    win.document.write('<style>table{width:100%;border-collapse:collapse;font-family:sans-serif;} th,td{border:1px solid #ddd;padding:8px;font-size:11px;} th{background:#eee;}</style></head><body>');
    win.document.write('<h2>Reporte de Traslados de Mercancía</h2>' + div);
    win.document.write('</body></html>');
    win.document.close();
    win.print();
}

// Cerrar modal al hacer clic fuera
window.onclick = function(event) {
    if (event.target == document.getElementById('modal')) {
        document.getElementById('modal').style.display = 'none';
    }
}
</script>

</body>
</html>