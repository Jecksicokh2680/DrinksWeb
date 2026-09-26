<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sesionExpirada = false;
if (!isset($_SESSION['Usuario']) || empty($_SESSION['Usuario'])) {
    $sesionExpirada = true;
}
$UsuarioSesion = $_SESSION['Usuario'] ?? '';
?>
<?php if ($sesionExpirada): ?>
<script>
    window.addEventListener("DOMContentLoaded", function() {
        alert("La sesión ha expirado.");
        window.close();
    });
</script>
<?php 
    exit;
endif;

// Aseguramos que PHP use estrictamente la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

/* ==========================================
   CONEXIONES, AUTORIZACIÓN Y FUNCIONES BASE
========================================== */
require('Conexion.php');
require('ConnCentral.php');
require('ConnDrinks.php');

// ---------------------------------------------------------
// PROCESAMIENTO AJAX: GUARDAR FACTURA COMO EGRESO (CRÉDITO/PROVEEDOR)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'guardar_factura') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['nit']) || empty($input['idcompra']) || !isset($input['monto'])) {
        echo json_encode(['status' => 'error', 'message' => 'Datos insuficientes para procesar el guardado']);
        exit;
    }

    global $mysqliWeb; 
    $mysqliWeb->begin_transaction();

    try {
        $stmt = $mysqliWeb->prepare("INSERT INTO pagosproveedores (Nit, F_Creacion, H_Creacion, Monto, TipoMonto, Descripcion, Estado) VALUES (?, ?, ?, ?, ?, ?, '1')");
        
        $fechaActual = date('Ymd');
        $horaActual  = date('H:i:s'); 
        $tipoMonto   = 'F'; // 'F' de Factura
        
        $nit = substr(trim($input['nit']), 0, 10);
        $idCompra = trim($input['idcompra']);
        
        // El backend guarda estrictamente en negativo para el egreso
        $monto = -abs((double)$input['monto']);
        $descripcion = substr($input['nombre'] . " | Fact: " . $idCompra, 0, 100);

        if ($monto < 0 && !empty($nit)) {
            $stmt->bind_param("sssdss", $nit, $fechaActual, $horaActual, $monto, $tipoMonto, $descripcion);
            $stmt->execute();
            $mysqliWeb->commit();
            echo json_encode(['status' => 'success', 'message' => '¡Egreso grabado a las ' . $horaActual . ' (Hora Bogotá)!']);
        } else {
            throw new Exception("El monto procesado no es válido.");
        }
    } catch (Exception $e) {
        $mysqliWeb->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// PROCESAMIENTO AJAX: GUARDAR COMO GASTO (DIRECTO, FUERA DE CRÉDITO)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'guardar_gasto') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['nit']) || empty($input['idcompra']) || !isset($input['monto'])) {
        echo json_encode(['status' => 'error', 'message' => 'Datos insuficientes para procesar el gasto']);
        exit;
    }

    global $mysqliWeb; 
    $mysqliWeb->begin_transaction();

    try {
        $stmt = $mysqliWeb->prepare("INSERT INTO flujo_efectivo (sede, tipo, fecha, nit_tercero, nombre_tercero, motivo, valor, nombre_pc, id_origen) VALUES (?, 'PAGO', ?, ?, ?, ?, ?, ?, ?)");
        
        $fechaActual = date('Y-m-d');
        $horaActual  = date('H:i:s');
        $sede        = $input['sucursal'] ?? 'CENTRAL';
        $nit         = substr(trim($input['nit']), 0, 20);
        $nombre      = trim($input['nombre']);
        $idCompra    = trim($input['idcompra']);
        $motivo      = substr("Factura ID: " . trim($input['nombre']) . " ID Compra: " . $idCompra, 0, 255);
        
        $valor       = abs((double)$input['monto']);
        $nombrePc    = gethostname();
        $idOrigen    = (int)$idCompra;

        if ($valor > 0 && !empty($nit)) {
            $stmt->bind_param("sssssdsi", $sede, $fechaActual, $nit, $nombre, $motivo, $valor, $nombrePc, $idOrigen);
            $stmt->execute();
            $mysqliWeb->commit();
            echo json_encode(['status' => 'success', 'message' => '¡Gasto registrado correctamente a las ' . $horaActual . ' (Hora Bogotá)!']);
        } else {
            throw new Exception("El monto del gasto no es válido.");
        }
    } catch (Exception $e) {
        $mysqliWeb->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar el gasto: ' . $e->getMessage()]);
    }
    exit;
}

function Autorizacion($User, $Solicitud) {
    global $mysqliWeb;
    $stmt = $mysqliWeb->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? Nro_Auto=? LIMIT 1");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $r = $stmt->get_result();
    return ($r && $r->num_rows) ? $r->fetch_assoc()['Swich'] : "NO";
}

function fmoneda($v) { 
    return number_format($v, 0, ',', '.'); 
}

function fmonedaNegativa($v) {
    if ($v < 0) {
        return '-' . number_format(abs($v), 0, ',', '.');
    }
    return '-' . number_format($v, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras Gerenciales</title>
    <style>
        :root {
            --primary: #0d6efd;
            --success: #198754;
            --danger: #dc3545;
            --warning: #ffc107;
            --dark: #212529;
            --gray-bg: #f4f6f8;
            --border-color: #ddd;
        }

        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            margin: 0; 
            padding: 10px; 
            background: var(--gray-bg); 
            font-size: 14px; 
            color: #333;
        }

        .card { 
            background: #fff; 
            padding: 15px; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,.08); 
            margin-bottom: 20px; 
            overflow-x: hidden;
        }

        h2 { margin-top: 0; color: #111; font-size: 1.35rem; }
        h3.resumen-title { margin-top: 25px; margin-bottom: 12px; color: #222; font-size: 1.15rem; }

        .filters { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); 
            gap: 10px; 
            margin-bottom: 15px; 
        }

        @media (min-width: 1200px) {
            .filters { grid-template-columns: repeat(6, 1fr) auto; }
        }

        label { font-size: 12px; font-weight: 700; display: block; margin-bottom: 4px; color: #555; }
        select, input, button { 
            width: 100%; 
            padding: 8px 10px; 
            border-radius: 8px; 
            border: 1px solid #ccc; 
            font-size: 14px; 
            box-sizing: border-box;
            transition: all 0.2s;
            background: #fff;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
        }

        button { 
            background: var(--primary); 
            color: #fff; 
            font-weight: 700; 
            cursor: pointer; 
            border: none;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        button:hover { background: #0b5ed7; }

        .btn-container { display: flex; align-items: flex-end; }

        .table-container { 
            max-height: 65vh; 
            overflow-x: auto; 
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 10px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 20px; 
            background: #fff;
            position: relative;
        }
        
        table { border-collapse: collapse; width: 100%; min-width: 1050px; font-size: 13px; }
        thead th { 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            background: #f8f9fa; 
            font-weight: 800; 
            text-align: center;
            padding: 10px 8px;
            border-bottom: 2px solid var(--border-color);
        }
        
        th, td { border: 1px solid #eee; padding: 8px 10px; text-align: right; white-space: nowrap; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .badge { padding: 3px 8px; border-radius: 12px; color: #fff; font-size: 11px; font-weight: 700; display: inline-block; }
        .central { background: var(--primary); } 
        .drinks { background: var(--success); }
        
        .subtotal { background: #f4f8ff; font-weight: 700; color: #1e3a8a; }
        .total { background: #e6fffa; font-weight: 800; color: #065f46; }
        .porc-pos { color: #1b5e20; font-weight: 800; } 
        .porc-neg { color: #b71c1c; font-weight: 800; }
        .bg-prov { background: #f8f9fa; font-weight: 700; text-align: left; color: #1a252f; border-bottom: 2px solid var(--border-color); }
        .negativo { color: #b71c1c; font-weight: bold; }
        
        .btn-grabar-row { 
            background: var(--danger); 
            border: none; 
            color: white; 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-size: 12px; 
            font-weight: bold; 
            cursor: pointer; 
            width: auto; 
            display: inline-block;
            margin-bottom: 3px;
        }
        .btn-grabar-row:hover { background: #bb2d3b; }

        .btn-gasto-row { 
            background: #fd7e14; 
            border: none; 
            color: white; 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-size: 12px; 
            font-weight: bold; 
            cursor: pointer; 
            width: auto; 
            display: inline-block;
        }
        .btn-gasto-row:hover { background: #e8590c; }

        .hidden-row { display: none !important; }

        .scroll-hint {
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
            font-style: italic;
            display: none;
        }

        @media (max-width: 768px) {
            body { padding: 5px; }
            .card { padding: 10px; border-radius: 8px; }
            .scroll-hint { display: block; }
            h2 { font-size: 1.2rem; }
            .table-container { max-height: 55vh; }
        }
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales</h2>

    <?php
    $FechaDesdeGet = $_GET['FechaDesde'] ?? date('Y-m-d');
    $FechaHastaGet = $_GET['FechaHasta'] ?? date('Y-m-d');
    $IDCompraGet = preg_replace('/[^0-9]/', '', $_GET['IDCompra'] ?? '');
    $ProvGet = preg_replace('/[^0-9]/', '', $_GET['Proveedor'] ?? '');
    $ProdGet = $_GET['ProductoFiltro'] ?? '';
    $SucursalGet = $_GET['Sucursal'] ?? 'AMBAS';

    $FechaDesdeSQL = !empty($FechaDesdeGet) ? DateTime::createFromFormat('Y-m-d', $FechaDesdeGet)->format('Ymd') : '';
    $FechaHastaSQL = !empty($FechaHastaGet) ? DateTime::createFromFormat('Y-m-d', $FechaHastaGet)->format('Ymd') : '';
    ?>

    <form method="GET" class="filters">
        <div>
            <label>Desde Fecha</label>
            <input type="date" name="FechaDesde" value="<?=htmlspecialchars($FechaDesdeGet)?>">
        </div>

        <div>
            <label>Hasta Fecha</label>
            <input type="date" name="FechaHasta" value="<?=htmlspecialchars($FechaHastaGet)?>">
        </div>

        <div>
            <label>Sucursal</label>
            <select name="Sucursal">
                <option value="AMBAS">Ambas</option>
                <option value="CENTRAL" <?=$SucursalGet=='CENTRAL'?'selected':''?>>Central</option>
                <option value="DRINKS" <?=$SucursalGet=='DRINKS'?'selected':''?>>Drinks</option>
            </select>
        </div>

        <div>
            <label>ID Compra (Global)</label>
            <input type="number" name="IDCompra" placeholder="Busca sin fecha..." value="<?=htmlspecialchars($IDCompraGet)?>">
        </div>

        <div>
            <label>Proveedor</label>
            <select name="Proveedor">
                <option value="">Todos</option>
                <?php
                if (!empty($FechaDesdeSQL) && !empty($FechaHastaSQL)) {
                    function provs($mysqli, $fDesde, $fHasta){
                        return $mysqli->query("SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov 
                                               FROM compras C 
                                               JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO 
                                               WHERE C.FECHA BETWEEN '$fDesde' AND '$fHasta' AND C.ESTADO='0' 
                                               ORDER BY prov");
                    }
                    
                    $pList = [];
                    if($SucursalGet != 'DRINKS' && isset($mysqliCentral)){ 
                        $r = provs($mysqliCentral, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $pList[$p['NIT']] = $p['prov']; 
                    }
                    if($SucursalGet != 'CENTRAL' && isset($mysqliDrinks)){ 
                        $r = provs($mysqliDrinks, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $pList[$p['NIT']] = $p['prov']; 
                    }
                    
                    foreach($pList as $n => $nm){ 
                        $sel = ($ProvGet == $n) ? 'selected' : ''; 
                        echo "<option value='$n' $sel>$nm</option>"; 
                    }
                }
                ?>
            </select>
        </div>

        <div>
            <label>Producto Específico </label>
            <input type="text" name="ProductoFiltro" id="ProductoFiltroInput" value="<?=htmlspecialchars($ProdGet)?>" placeholder="Escribe o selecciona producto...">
            <datalist id="listaProductosFiltro">
                <?php
                if (!empty($FechaDesdeSQL) && !empty($FechaHastaSQL)) {
                    function prods($mysqli, $fDesde, $fHasta){
                        return $mysqli->query("SELECT DISTINCT P.IDPRODUCTO, P.descripcion 
                                               FROM compras C 
                                               JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
                                               JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
                                               WHERE C.FECHA BETWEEN '$fDesde' AND '$fHasta' AND C.ESTADO='0' 
                                               ORDER BY P.descripcion");
                    }
                    
                    $prodList = [];
                    if($SucursalGet != 'DRINKS' && isset($mysqliCentral)){ 
                        $r = prods($mysqliCentral, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $prodList[$p['IDPRODUCTO']] = $p['descripcion']; 
                    }
                    if($SucursalGet != 'CENTRAL' && isset($mysqliDrinks)){ 
                        $r = prods($mysqliDrinks, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $prodList[$p['IDPRODUCTO']] = $p['descripcion']; 
                    }
                    asort($prodList);
                    
                    foreach($prodList as $idProd => $nomProd){ 
                        echo "<option value=\"".htmlspecialchars($nomProd)."\">"; 
                    }
                }
                ?>
            </datalist>
            <script>
                document.getElementById('ProductoFiltroInput').setAttribute('list', 'listaProductosFiltro');
            </script>
        </div>

        <div class="btn-container">
            <button type="submit">Consultar</button>
        </div>
    </form>

    <div style="margin-bottom: 15px; background: #eef2f7; padding: 10px; border-radius: 8px;">
        <label style="color: #0b5ed7;">🔍 Filtrar sobre el resultado actual (Búsqueda rápida en pantalla)</label>
        <input type="text" id="filtroProductoInput" placeholder="Escribe el nombre o Sku del producto para buscar al instante..." onkeyup="filtrarPorProductoHtml()">
    </div>

<?php
if ((!empty($FechaDesdeGet) && !empty($FechaHastaGet)) || !empty($IDCompraGet)) {

    /* --- LOGICA PRECIO PROMEDIO VENTA --- */
    function precioProm($mysqli){
        $sql = "SELECT Q.Barcode, SUM(Q.CANTIDAD*Q.VALORPROD)/NULLIF(SUM(Q.CANTIDAD),0) pv FROM(
                SELECT P.Barcode, D.CANTIDAD, D.VALORPROD FROM DETPEDIDOS D JOIN PEDIDOS PE ON PE.IDPEDIDO=D.IDPEDIDO JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE PE.ESTADO='0' AND STR_TO_DATE(PE.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION ALL
                SELECT P.Barcode, D.CANTIDAD, D.VALORPROD FROM FACTURAS F JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE F.ESTADO='0' AND STR_TO_DATE(F.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                ) Q GROUP BY Q.Barcode";
        $out = []; 
        $r = $mysqli->query($sql);
        while($r && $x = $r->fetch_assoc()) $out[$x['Barcode']] = $x['pv'];
        return $out;
    }

    $pvC = ($SucursalGet != 'DRINKS') ? precioProm($mysqliCentral) : [];
    $pvD = ($SucursalGet != 'CENTRAL') ? precioProm($mysqliDrinks) : [];

    /* --- CONSULTA DE COMPRAS EN RANGO --- */
    function consultarCompras($mysqli, $suc, $fDesde, $fHasta, $p, $id, $prodFiltro){
        $cond = " WHERE C.ESTADO='0' ";
        if(!empty($id)){
            $cond .= " AND C.idcompra = '$id' ";
        } else {
            $cond .= " AND C.FECHA BETWEEN '$fDesde' AND '$fHasta' ";
        }
        if(!empty($p)) $cond .= " AND T.NIT = '$p' ";
        if(!empty($prodFiltro)) {
            $cond .= " AND (P.IDPRODUCTO = '$prodFiltro' OR P.descripcion LIKE '%$prodFiltro%') ";
        }

        return $mysqli->query("
            SELECT '$suc' sucursal, C.FECHA, C.idcompra, C.VALORTOTAL as totalFacturaCabecera, T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov,
                   P.Barcode, P.descripcion, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni
            FROM compras C
            JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO
            JOIN DETCOMPRAS D ON D.idcompra=C.idcompra
            JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO
            $cond
            ORDER BY prov, C.idcompra
        ");
    }

    $resultados = [];
    if($SucursalGet != 'DRINKS') $resultados[] = consultarCompras($mysqliCentral, 'Central', $FechaDesdeSQL, $FechaHastaSQL, $ProvGet, $IDCompraGet, $ProdGet);
    if($SucursalGet != 'CENTRAL') $resultados[] = consultarCompras($mysqliDrinks, 'Drinks', $FechaDesdeSQL, $FechaHastaSQL, $ProvGet, $IDCompraGet, $ProdGet);

    $filasCrudas = [];
    foreach($resultados as $res){
        while($res && $x = $res->fetch_assoc()){
            $filasCrudas[] = $x;
        }
    }

    /* --- RENDERIZADO DE TABLA PRINCIPAL --- */
    echo "<div class='scroll-hint'>↔️ Desliza horizontalmente para ver todos los campos de la tabla</div>";
    echo "<div class='table-container'><table id='tablaPrincipalCompras'><thead><tr>";
    echo "<th>Suc</th><th>Fecha</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>";
    echo "<th>Cant</th><th>Costo Unit.</th><th>Total Ítem</th><th>P. Venta</th><th>Util</th><th>% Util</th>";
    echo "</tr></thead><tbody>";

    $provAnt = ''; 
    $idCompraAnt = '';
    $sucursalAnt = '';
    $nitAnt = '';
    $fechaAnt = '';
    
    $subCompra = 0;   
    $subProveedor = 0; 
    $gran = 0;         
    $hayRegistros = false;

    $resumenFacturas = [];

    foreach($filasCrudas as $x){
        $hayRegistros = true;
        
        $cant = $x['CANTIDAD'];
        $net = ($x['VALOR'] - ($x['descuento'] / max($cant, 1)));
        $costoBruto = $net + ($net * $x['porciva'] / 100) + $x['ValICUIUni'];
        $totalItemBruto = $costoBruto * $cant;

        // Factor de ajuste fijo en 1 para mantener el costo real original de los ítems sin alteración
        $factorAjuste = 1;

        $costo = $costoBruto * $factorAjuste;
        $totalItem = $totalItemBruto * $factorAjuste;

        $pv = ($x['sucursal'] == 'Central') ? ($pvC[$x['Barcode']] ?? 0) : ($pvD[$x['Barcode']] ?? 0);
        $util = ($pv - $costo) * $cant;
        $porc = $costo > 0 ? (($pv - $costo) / $costo) * 100 : 0;

        if (($idCompraAnt && $idCompraAnt != $x['idcompra']) || ($provAnt && $provAnt != $x['prov'])) {
            echo "<tr class='row-total-compra' data-compra-id='$idCompraAnt' style='background:#fdfdfe; font-style:italic;'><td colspan='8' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
            echo "<td colspan='3'></td>";
            echo "</tr>";
            
            $resumenFacturas[] = [
                'prov' => $provAnt,
                'nit' => $nitAnt,
                'sucursal' => $sucursalAnt,
                'idcompra' => $idCompraAnt,
                'fecha' => $fechaAnt,
                'total' => $subCompra
            ];
            $subCompra = 0;
        }

        if ($provAnt && $provAnt != $x['prov']) {
            echo "<tr class='subtotal row-total-prov'><td colspan='8'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
            echo "<td colspan='3'></td>";
            echo "</tr>"; 
            $subProveedor = 0;
        }

        $subCompra += $totalItem;
        $subProveedor += $totalItem;
        $gran += $totalItem;
        
        $fechaRaw = $x['FECHA'];
        $fechaFormateada = (strlen($fechaRaw) == 8) ? substr($fechaRaw, 0, 4) . '-' . substr($fechaRaw, 4, 2) . '-' . substr($fechaRaw, 6, 2) : $fechaRaw;

        $provAnt = $x['prov'];
        $nitAnt = $x['NIT'];
        $idCompraAnt = $x['idcompra'];
        $sucursalAnt = $x['sucursal'];
        $fechaAnt = $fechaFormateada;
        
        $cls = $x['sucursal'] == 'Central' ? 'central' : 'drinks';
        $clsP = $porc >= 0 ? 'porc-pos' : 'porc-neg';

        echo "<tr class='data-row' data-sku='".htmlspecialchars($x['Barcode'])."' data-descripcion='".htmlspecialchars(strtolower($x['descripcion']))."'>
            <td><span class='badge $cls'>{$x['sucursal']}</span></td>
            <td>{$fechaFormateada}</td>
            <td>{$x['idcompra']}</td>
            <td class='text-left'>{$x['prov']}</td>
            <td class='text-left'>{$x['Barcode']}</td>
            <td class='text-left'>{$x['descripcion']}</td>
            <td>".number_format($cant, 0)."</td>
            <td>".fmoneda($costo)."</td>
            <td>".fmoneda($totalItem)."</td>
            <td>".fmoneda($pv)."</td>
            <td>".fmoneda($util)."</td>
            <td class='$clsP'>".number_format($porc, 1)."%</td>
        </tr>";
    }

    if($hayRegistros){
        echo "<tr class='row-total-compra' data-compra-id='$idCompraAnt' style='background:#fdfdfe; font-style:italic;'><td colspan='8' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
        echo "<td colspan='3'></td>";
        echo "</tr>";
        
        $resumenFacturas[] = [
            'prov' => $provAnt,
            'nit' => $nitAnt,
            'sucursal' => $sucursalAnt,
            'idcompra' => $idCompraAnt,
            'fecha' => $fechaAnt,
            'total' => $subCompra
        ];

        echo "<tr class='subtotal row-total-prov'><td colspan='8'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
        echo "<td colspan='3'></td>";
        echo "</tr>";
        
        echo "<tr class='total row-total-general'><td colspan='8'>TOTAL GENERAL DE COMPRAS</td><td>".fmoneda($gran)."</td>";
        echo "<td colspan='3'></td>";
        echo "</tr>";
        echo "</tbody></table></div>";

        /* =========================================================
           TABLA RESUMEN CON BOTONES DE CRÉDITO Y GASTO DIRECTO
           ========================================================= */
        usort($resumenFacturas, function($a, $b) {
            return strcmp($a['prov'], $b['prov']);
        });

        echo "<h3 class='resumen-title'>📋 Resumen de Egresos y Gastos por Proveedor</h3>";
        echo "<div class='scroll-hint'>↔️ Desliza horizontalmente para ver las acciones</div>";

        echo "<div class='table-container' style='max-height: 45vh;'><table><thead><tr>";
        echo "<th class='text-left'>Sede / Sucursal</th>";
        echo "<th class='text-center'>Fecha</th>";
        echo "<th class='text-center'>ID Compra / Factura</th>";
        echo "<th>Total Valor</th>";
        echo "<th class='text-center' style='width:220px;'>Acciones</th>";
        echo "</tr></thead><tbody>";

        $provAntResumen = '';
        $subTotalProvResumen = 0;

        foreach($resumenFacturas as $rf){
            if ($provAntResumen && $provAntResumen != $rf['prov']) {
                echo "<tr class='subtotal' style='background:#f1f3f5;'>";
                echo "<td colspan='3' class='text-left'>Acumulado Total: $provAntResumen</td>";
                echo "<td class='negativo'>".fmonedaNegativa($subTotalProvResumen)."</td>";
                echo "<td></td>";
                echo "</tr>";
                $subTotalProvResumen = 0;
            }

            if ($provAntResumen != $rf['prov']) {
                echo "<tr><td colspan='5' class='bg-prov'>🏢 Proveedor: {$rf['prov']} (NIT: {$rf['nit']})</td></tr>";
                $provAntResumen = $rf['prov'];
            }

            $subTotalProvResumen += $rf['total'];
            $cls = $rf['sucursal'] == 'Central' ? 'central' : 'drinks';

            echo "<tr>";
            echo "<td class='text-left'><span class='badge $cls'>{$rf['sucursal']}</span></td>";
            echo "<td class='text-center'>{$rf['fecha']}</td>";
            echo "<td class='text-center'><strong>{$rf['idcompra']}</strong></td>";
            echo "<td class='negativo'>".fmonedaNegativa($rf['total'])."</td>";
            echo "<td class='text-center'>
                    <button type='button' class='btn-grabar-row' onclick='grabarFactura(\"{$rf['nit']}\", \"{$rf['prov']}\", \"{$rf['idcompra']}\", {$rf['total']})'>💾 Grabar Crédito</button>
                    <button type='button' class='btn-gasto-row' onclick='grabarGasto(\"{$rf['nit']}\", \"{$rf['prov']}\", \"{$rf['idcompra']}\", {$rf['total']}, \"{$rf['sucursal']}\")'>💵 Grabar Gasto</button>
                  </td>";
            echo "</tr>";
        }
        
        if($provAntResumen != '') {
            echo "<tr class='subtotal' style='background:#f1f3f5;'>";
            echo "<td colspan='3' class='text-left'>Acumulado Total: $provAntResumen</td>";
            echo "<td class='negativo'>".fmonedaNegativa($subTotalProvResumen)."</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        
        echo "<tr class='total'><td colspan='3' class='text-left'>TOTAL GENERAL DEL RESUMEN</td><td class='negativo'>".fmonedaNegativa($gran)."</td><td></td></tr>";
        echo "</tbody></table></div>";

    } else {
        echo "<tr><td colspan='12' style='text-align:center;padding:20px;'>No se encontraron registros para la consulta actual.</td></tr>";
        echo "</tbody></table></div>";
    }
}
?>
</div>

<script>
function filtrarPorProductoHtml() {
    const input = document.getElementById('filtroProductoInput');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tablaPrincipalCompras .data-row');
    const subTotalsCompra = document.querySelectorAll('#tablaPrincipalCompras .row-total-compra');
    const subTotalsProv = document.querySelectorAll('#tablaPrincipalCompras .row-total-prov');
    const totalGeneral = document.querySelectorAll('#tablaPrincipalCompras .row-total-general');

    if (filter === '') {
        rows.forEach(r => r.classList.remove('hidden-row'));
        subTotalsCompra.forEach(s => s.classList.remove('hidden-row'));
        subTotalsProv.forEach(p => p.classList.remove('hidden-row'));
        totalGeneral.forEach(g => g.classList.remove('hidden-row'));
        return;
    }

    rows.forEach(row => {
        const sku = row.getAttribute('data-sku') || '';
        const descripcion = row.getAttribute('data-descripcion') || '';
        
        if (sku.includes(filter) || descripcion.includes(filter)) {
            row.classList.remove('hidden-row');
        } else {
            row.classList.add('hidden-row');
        }
    });

    subTotalsCompra.forEach(s => s.classList.add('hidden-row'));
    subTotalsProv.forEach(p => p.classList.add('hidden-row'));
    totalGeneral.forEach(g => g.classList.add('hidden-row'));
}

// Función para Grabar Credito / Proveedor (Pago a proveedor existente)
function grabarFactura(nitProv, nombreProv, idCompra, montoFactura) {
    var montoNegativoStr = '-' + montoFactura.toLocaleString('es-CO');
    if (!confirm('¿Deseas grabar como CRÉDITO la factura ID: ' + idCompra + ' por valor de $' + montoNegativoStr + ' para ' + nombreProv + '?')) {
        return;
    }

    fetch('?action=guardar_factura', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ 
            nit: nitProv,
            nombre: nombreProv,
            idcompra: idCompra,
            monto: montoFactura
        })
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Hubo un error al procesar el guardado de la factura.');
    });
}

// Nueva función para Grabar como Gasto Directo en Efectivo/Flujo
function grabarGasto(nitProv, nombreProv, idCompra, montoFactura, sucursal) {
    var montoPositivoStr = montoFactura.toLocaleString('es-CO');
    if (!confirm('¿Deseas registrar esto como un GASTO directo de caja (ID Factura: ' + idCompra + ') por valor de $' + montoPositivoStr + ' para ' + nombreProv + '?')) {
        return;
    }

    fetch('?action=guardar_gasto', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ 
            nit: nitProv,
            nombre: nombreProv,
            idcompra: idCompra,
            monto: montoFactura,
            sucursal: sucursal
        })
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Hubo un error al registrar el gasto.');
    });
}
</script>

</body>
</html>