<?php
require_once("ConnCentral.php");   // $mysqliCentral
require_once("ConnDrinks.php");    // $mysqliDrinks
require_once("Conexion.php");      // $mysqliWeb

date_default_timezone_set('America/Bogota'); 
session_start();

// Configuración de IDs de empresa
define('NIT_CENTRAL', '86057267-8');
define('NIT_DRINKS',  '901724534-7');
define('SUC_DEFAULT', '001');

$mysqli = $mysqliWeb; 
$UsuarioSesion = $_SESSION['Usuario'] ?? 'SISTEMA';

// Mapeo de nombres de sedes (PHP)
$nombresSedesMap = [
    '86057267-8'  => 'CENTRAL',
    '86057267'    => 'CENTRAL',
    '901724534'   => 'DRINK',
    '901724534-7' => 'DRINK'
];

/* ============================================================
    SEGURIDAD Y PERMISOS
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
    return $permiso;
}

$aut_0003 = Autorizacion($UsuarioSesion, '0003'); 
$aut_9999 = Autorizacion($UsuarioSesion, '9999');

if ($aut_0003 !== "SI" && $aut_9999 !== "SI") {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No tiene autorización</h2>");
}

/* ============================================================
    POST: MOVIMIENTOS Y REVERSIONES
============================================================ */
if($_SERVER['REQUEST_METHOD']=='POST'){
    if(isset($_POST['mover'])){
        $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual";
        $barcode = $_POST['barcode'];
        $cantidad = (float)$_POST['cantidad'];
        $origen = $_POST['origen'];
        $destino = $_POST['destino'];

        if($cantidad <= 0 || $origen === $destino) {
            $msg = "<div class='alert err'>❌ Datos inválidos</div>";
        } else {
            $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
            $nitOrig = ($origen == "Central") ? NIT_CENTRAL : NIT_DRINKS;
            $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
            $nitDest = ($destino == "Central") ? NIT_CENTRAL : NIT_DRINKS;

            $idO = $dbOrig->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;
            $idD = $dbDest->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;

            if($idO && $idD) {
                try {
                    $mysqliCentral->begin_transaction(); $mysqliDrinks->begin_transaction(); $mysqliWeb->begin_transaction();
                    $dbOrig->query("UPDATE inventario SET cantidad = cantidad - $cantidad WHERE idproducto = $idO");
                    $dbDest->query("UPDATE inventario SET cantidad = cantidad + $cantidad WHERE idproducto = $idD");
                    
                    $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado, fecha) VALUES (?, '001', ?, 'SALE', ?, ?, ?, '001', ?, 1, NOW())";
                    $stmtLog = $mysqliWeb->prepare($sqlLog);
                    $stmtLog->bind_param("sssdss", $nitOrig, $UsuarioSesion, $barcode, $cantidad, $nitDest, $obs);
                    $stmtLog->execute();
                    
                    $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
                    $msg = "<div class='alert ok'>✅ Traslado exitoso</div>";
                } catch(Exception $e) {
                    $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
                    $msg = "<div class='alert err'>❌ Error: ".$e->getMessage()."</div>";
                }
            } else {
                $msg = "<div class='alert err'>❌ Producto no encontrado en sedes</div>";
            }
        }
    }

    if(isset($_POST['aprobar']) && $aut_9999=="SI"){
        $id = (int)$_POST['idMov'];
        $res = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE idMov=$id AND Aprobado=1")->fetch_assoc();
        if($res){
            $origDb = ($res['NitEmpresa_Orig']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;
            $destDb = ($res['NitEmpresa_Dest']==NIT_CENTRAL)?$mysqliCentral:$mysqliDrinks;
            $bc = $res['barcode']; $cant = $res['cant'];

            $idO = $origDb->query("SELECT idproducto FROM productos WHERE barcode='$bc'")->fetch_assoc()['idproducto'];
            $idD = $destDb->query("SELECT idproducto FROM productos WHERE barcode='$bc'")->fetch_assoc()['idproducto'];

            $origDb->query("UPDATE inventario SET cantidad=cantidad+$cant WHERE idproducto=$idO");
            $destDb->query("UPDATE inventario SET cantidad=cantidad-$cant WHERE idproducto=$idD");
            $mysqliWeb->query("UPDATE inventario_movimientos SET Aprobado=0 WHERE idMov=$id");
            $msg = "<div class='alert ok'>✅ Movimiento reversado</div>";
        }
    }
}

/* ============================================================
    CONSULTAS
============================================================ */
$categoria = $_GET['categoria'] ?? '';
$term = $_GET['term'] ?? '';
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');

$cats=[]; $resC = $mysqliWeb->query("SELECT CodCat, Nombre FROM categorias WHERE Estado='1'");
while($c=$resC->fetch_assoc()) $cats[$c['CodCat']]=$c['Nombre'];

$nombresGlobales = [];
$rNom = $mysqliCentral->query("SELECT barcode, descripcion FROM productos");
while($rn = $rNom->fetch_assoc()) $nombresGlobales[$rn['barcode']] = $rn['descripcion'];

$barcodes = []; $central = []; $drinks = [];
if($term !== '' || $categoria !== ''){
    $where = "p.estado='1' AND (p.descripcion LIKE '%$term%' OR p.barcode LIKE '%$term%')";
    if($categoria){
        $resBC = $mysqliWeb->query("SELECT sku FROM catproductos WHERE CodCat='$categoria'");
        $skus = []; while($r=$resBC->fetch_assoc()) $skus[]="'".$r['sku']."'";
        $where .= $skus ? " AND p.barcode IN (".implode(',',$skus).")" : " AND 1=0";
    }
    $sql = "SELECT p.barcode, p.descripcion, IFNULL(i.cantidad,0) cantidad FROM productos p LEFT JOIN inventario i ON p.idproducto=i.idproducto WHERE $where LIMIT 50";
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
    <title>Traslados de Mercancía</title>
    <style>
        body{font-family:sans-serif; background:#f4f4f4; padding:20px; font-size:13px;}
        .card{background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); max-width:1400px; margin:auto;}
        .alert{padding:15px; margin-bottom:15px; border-radius:4px; font-weight:bold; text-align:center;}
        .ok{background:#d4edda; color:#155724;} .err{background:#f8d7da; color:#721c24;}
        table{width:100%; border-collapse:collapse; margin-top:20px;}
        th{background:#2c3e50; color:#fff; padding:10px;}
        td{padding:10px; border-bottom:1px solid #eee; text-align:center;}
        .input-s{padding:7px; border:1px solid #ccc; border-radius:4px;}
        .btn{background:#27ae60; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer; font-weight:bold;}
        .modal{display:<?= (isset($_GET['f_inicio']) || isset($msg)) ? 'block' : 'none' ?>; position:fixed; z-index:100; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);}
        .modal-content{background:#fff; margin:2% auto; padding:20px; width:90%; border-radius:8px; max-height:90vh; overflow-y:auto;}
    </style>
</head>
<body>

<div class="card">
    <h2>📦 Traslados Central ⟷ Drinks</h2>
    <?php if(isset($msg)) echo $msg; ?>

    <form method="GET" style="display:flex; gap:10px; margin-bottom:20px;">
        <select name="categoria" class="input-s">
            <option value="">-- Categorías --</option>
            <?php foreach($cats as $k=>$v) echo "<option value='$k' ".($categoria==$k?'selected':'').">$v</option>"; ?>
        </select>
        <input type="text" name="term" placeholder="Buscar producto..." value="<?= htmlspecialchars($term) ?>" class="input-s" style="flex-grow:1">
        <button type="submit" class="btn" style="background:#2980b9">🔍 Buscar</button>
        <button type="button" class="btn" style="background:#f39c12" onclick="document.getElementById('m').style.display='block'">📅 Historial</button>
        <a href="?" style="padding:10px; text-decoration:none; color:#666;">Limpiar</a>
    </form>

    <?php if($barcodes): ?>
    <table>
        <thead>
            <tr><th>Barcode</th><th>Producto</th><th>Drinks</th><th>Central</th><th>Operación</th></tr>
        </thead>
        <tbody>
            <?php foreach($barcodes as $b): 
                $vC = $central[$b]['cantidad'] ?? 0;
                $vD = $drinks[$b]['cantidad'] ?? 0;
            ?>
            <tr>
                <td><code><?= $b ?></code></td>
                <td style="text-align:left;"><?= htmlspecialchars($central[$b]['descripcion'] ?? $drinks[$b]['descripcion'] ?? 'N/A') ?></td>
                <td style="color:#2980b9; font-weight:bold;"><?= number_format($vD,1) ?></td>
                <td style="color:#27ae60; font-weight:bold;"><?= number_format($vC,1) ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('¿Confirmar traslado?')">
                        <input type="hidden" name="barcode" value="<?= $b ?>">
                        <input type="number" name="cantidad" step="0.1" style="width:70px" class="input-s" required>
                        <select name="origen" class="input-s"><option value="Central">Cen</option><option value="Drinks">Dri</option></select>
                        <span>➡️</span>
                        <select name="destino" class="input-s"><option value="Drinks">Dri</option><option value="Central">Cen</option></select>
                        <button type="submit" name="mover" class="btn">Ok</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div id="m" class="modal">
    <div class="modal-content">
        <span onclick="this.parentElement.parentElement.style.display='none'" style="float:right; cursor:pointer; font-size:24px;">&times;</span>
        <h3>🗓 Historial de Movimientos</h3>
        
        <form method="GET" style="display:flex; gap:10px; margin-bottom:15px;">
            <input type="date" name="f_inicio" value="<?= $f_inicio ?>" class="input-s">
            <input type="date" name="f_fin" value="<?= $f_fin ?>" class="input-s">
            <button type="submit" class="btn" style="background:#34495e">Filtrar</button>
            <button type="button" onclick="printMovimientos()" class="btn" style="background:#2980b9">🖨 Imprimir Historial</button>
        </form>

        <table id="tableHistory">
            <thead>
                <tr>
                    <th>Fecha / Hora</th><th>Usuario</th><th>Barcode</th><th>Cant.</th><th>Origen</th><th>Destino</th><th>Obs</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php while($r = $resMov->fetch_assoc()): 
                    $nomProd = $nombresGlobales[$r['barcode']] ?? 'Desconocido';
                    $origName = $nombresSedesMap[trim($r['NitEmpresa_Orig'])] ?? $r['NitEmpresa_Orig'];
                    $destName = $nombresSedesMap[trim($r['NitEmpresa_Dest'])] ?? $r['NitEmpresa_Dest'];
                ?>
                <tr style="<?= $r['Aprobado'] == 0 ? 'background:#fff0f0; color:#999;' : '' ?>">
                    <td><?= $r['fecha'] ?></td>
                    <td><?= $r['usuario_Orig'] ?></td>
                    <td data-nombre="<?= htmlspecialchars($nomProd) ?>"><code><?= $r['barcode'] ?></code></td>
                    <td style="font-weight:bold;"><?= number_format($r['cant'], 1) ?></td>
                    <td><?= $origName ?></td>
                    <td><?= $destName ?></td>
                    <td style="font-size:11px;"><?= $r['Observacion'] ?></td>
                    <td>
                        <?php if($aut_9999=="SI" && $r['Aprobado']==1): ?>
                            <form method="POST">
                                <input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
                                <button type="submit" name="aprobar" style="background:none; border:none; cursor:pointer; font-size:16px;">🔄</button>
                            </form>
                        <?php else: echo $r['Aprobado']==1 ? '✅' : '❌'; endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function printMovimientos(){
    const tableClone = document.getElementById('tableHistory').cloneNode(true);
    const header = tableClone.querySelector('thead tr');
    const rows = tableClone.querySelectorAll('tbody tr');
    
    const nombresSedes = {
        '86057267-8': 'CENTRAL',
        '86057267': 'CENTRAL',
        '901724534': 'DRINK',
        '901724534-7': 'DRINK'
    };

    rows.forEach(row => {
        // 1. Hora limpia (Negrita)
        const fullDateTime = row.cells[0].innerText;
        const timeOnly = fullDateTime.split(' ')[1] ? fullDateTime.split(' ')[1].substring(0, 5) : fullDateTime;
        row.cells[0].innerText = timeOnly;

        // 2. Formato de Producto (Multilínea para que no corte)
        const celdaBarcode = row.cells[2];
        const nombreProd = celdaBarcode.getAttribute('data-nombre');
        celdaBarcode.innerHTML = `
            <div style="font-size:11px; font-weight:900; line-height:1; word-wrap: break-word;">${nombreProd}</div>
            <div style="font-size:9px; font-family:monospace;">[${celdaBarcode.innerText}]</div>
        `;

        // 3. Traducción de Sedes
        row.cells[4].innerText = nombresSedes[row.cells[4].innerText.trim()] || row.cells[4].innerText;
        row.cells[5].innerText = nombresSedes[row.cells[5].innerText.trim()] || row.cells[5].innerText;

        // 4. Limpieza de columnas innecesarias para ahorrar espacio horizontal
        row.deleteCell(7); // Estado
        row.deleteCell(6); // Obs
        row.deleteCell(1); // Usuario
    });

    header.deleteCell(7);
    header.deleteCell(6);
    header.deleteCell(1);

    const fechaFiltro = document.getElementsByName('f_inicio')[0].value;
    const win = window.open('', '', 'height=700,width=900');
    
    win.document.write(`
        <html>
        <head>
            <title>TR_${fechaFiltro}</title>
            <style>
                @media print {
                    @page { size: portrait; margin: 0.3cm; }
                    body { margin: 0; padding: 0; }
                }
                body { 
                    font-family: 'Courier New', Courier, monospace; 
                    width: 100%; 
                    color: #000 !important;
                }
                h2 { text-align: center; font-size: 16px; margin-bottom: 2px; text-transform: uppercase; }
                .header-info { text-align: center; font-size: 11px; margin-bottom: 10px; border-bottom: 1px dashed #000; padding-bottom: 5px; }
                
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    table-layout: fixed; /* Esto evita que la tabla se estire fuera del papel */
                }
                th, td { 
                    border: 1px solid #000; 
                    padding: 4px 2px; 
                    font-size: 11px; 
                    text-align: center; 
                    word-wrap: break-word; /* Fuerza el salto de línea si el texto es largo */
                    overflow: hidden;
                }
                th { background: #000 !important; color: #fff !important; font-size: 10px; }

                /* Ajuste de anchos de columna (Total 100%) */
                th:nth-child(1), td:nth-child(1) { width: 15%; } /* Hora */
                th:nth-child(2), td:nth-child(2) { width: 45%; text-align: left; } /* Producto */
                th:nth-child(3), td:nth-child(3) { width: 12%; font-size: 13px; } /* Cant */
                th:nth-child(4), td:nth-child(4) { width: 14%; } /* Origen */
                th:nth-child(5), td:nth-child(5) { width: 14%; } /* Destino */

                .footer-firmas { margin-top: 30px; display: flex; justify-content: space-between; }
                .firma-box { border-top: 1px solid #000; width: 45%; text-align: center; font-size: 9px; padding-top: 5px; }
            </style>
        </head>
        <body>
            <h2>Reporte Traslados</h2>
            <div class="header-info">FECHA: ${fechaFiltro} | GEN: <?= date('d/m H:i') ?></div>
            ${tableClone.outerHTML}
            <div class="footer-firmas">
                <div class="firma-box">ENTREGA</div>
                <div class="firma-box">RECIBE</div>
            </div>
        </body>
        </html>
    `);
    
    win.document.close();
    setTimeout(() => { 
        win.focus(); 
        win.print(); 
        win.close(); 
    }, 500);
}
</script>
</body>
</html>