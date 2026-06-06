<?php
session_start();

// Aseguramos que PHP use estrictamente la zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

/* ==========================================
   CONEXIONES, AUTORIZACIÓN Y FUNCIONES BASE
========================================== */
require('Conexion.php');
require('ConnCentral.php');
require('ConnDrinks.php');

$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') {
    header("Location: Login.php");
    exit;
}

// ---------------------------------------------------------
// PROCESAMIENTO AJAX: GUARDAR FACTURA EN NEGATIVO CON HORA DE BOGOTÁ
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
        $tipoMonto   = 'E'; // 'E' de Egreso
        
        $nit = substr(trim($input['nit']), 0, 10);
        $idCompra = trim($input['idcompra']);
        
        // El backend guarda estrictamente en negativo para el egreso
        $monto = -abs((double)$input['monto']);
        $descripcion = substr("Compra Gerencial - Prov: " . $input['nombre'] . " | Factura: " . $idCompra, 0, 100);

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

function Autorizacion($User, $Solicitud) {
    global $mysqliWeb;
    $stmt = $mysqliWeb->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=? LIMIT 1");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $r = $stmt->get_result();
    return ($r && $r->num_rows) ? $r->fetch_assoc()['Swich'] : "NO";
}

// Función de formato estándar (Positivos)
function fmoneda($v) { 
    return number_format($v, 0, ',', '.'); 
}

// Función de formato para Egresos (Negativos)
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
            --dark: #212529;
            --gray-bg: #f4f6f8;
            --border-color: #ddd;
        }

        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            margin: 0; 
            padding: 15px; 
            background: var(--gray-bg); 
            font-size: 15px; 
            color: #333;
        }

        .card { 
            background: #fff; 
            padding: 20px; 
            border-radius: 14px; 
            box-shadow: 0 4px 12px rgba(0,0,0,.08); 
            margin-bottom: 20px; 
        }

        h2 { margin-top: 0; color: #111; font-size: 1.5rem; }
        h3.resumen-title { margin-top: 35px; margin-bottom: 15px; color: #222; font-size: 1.25rem; }

        /* Sistema de Filtros Responsivo */
        .filters { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 12px; 
            margin-bottom: 15px; 
        }

        @media (min-width: 1200px) {
            .filters { grid-template-columns: repeat(5, 1fr) auto; }
        }

        label { font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px; color: #555; }
        select, input, button { 
            width: 100%; 
            padding: 10px 12px; 
            border-radius: 8px; 
            border: 1px solid #ccc; 
            font-size: 15px; 
            box-sizing: border-box;
            transition: all 0.2s;
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
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        button:hover { background: #0b5ed7; }

        .btn-container { display: flex; align-items: flex-end; }

        /* Contenedores de Tablas Responsivos */
        .table-container { 
            max-height: 65vh; 
            overflow: auto; 
            border-radius: 12px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 20px; 
            background: #fff;
        }
        
        table { border-collapse: collapse; width: 100%; min-width: 1000px; font-size: 14px; }
        thead th { 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            background: #f8f9fa; 
            font-weight: 800; 
            text-align: center;
            padding: 12px 10px;
            border-bottom: 2px solid var(--border-color);
        }
        
        th, td { border: 1px solid #eee; padding: 10px; text-align: right; white-space: nowrap; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 14px; color: #fff; font-size: 12px; font-weight: 700; display: inline-block; }
        .central { background: var(--primary); } 
        .drinks { background: var(--success); }
        
        /* Estilos de Filas Especiales */
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
            padding: 6px 12px; 
            border-radius: 6px; 
            font-size: 13px; 
            font-weight: bold; 
            cursor: pointer; 
            width: auto; 
            display: inline-block;
        }
        .btn-grabar-row:hover { background: #bb2d3b; }

        /* Utilidades para ocultar filas dinámicamente */
        .hidden-row { display: none !important; }

        /* Ajustes específicos para móviles */
        @media (max-width: 768px) {
            body { padding: 8px; }
            .card { padding: 15px; border-radius: 10px; }
            .btn-container { align-items: stretch; margin-top: 5px; }
            h2 { font-size: 1.3rem; }
            .table-container { max-height: 50vh; }
        }
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales</h2>

    <form method="GET" class="filters">
        <div>
            <label>Desde Fecha</label>
            <input type="date" name="FechaDesde" value="<?=htmlspecialchars($_GET['FechaDesde'] ?? date('Y-m-d'))?>">
        </div>

        <div>
            <label>Hasta Fecha</label>
            <input type="date" name="FechaHasta" value="<?=htmlspecialchars($_GET['FechaHasta'] ?? date('Y-m-d'))?>">
        </div>

        <div>
            <label>Sucursal</label>
            <select name="Sucursal">
                <option value="AMBAS">Ambas</option>
                <option value="CENTRAL" <?=($_GET['Sucursal']??'')=='CENTRAL'?'selected':''?>>Central</option>
                <option value="DRINKS" <?=($_GET['Sucursal']??'')=='DRINKS'?'selected':''?>>Drinks</option>
            </select>
        </div>

        <div>
            <label>ID Compra (Global)</label>
            <input type="number" name="IDCompra" placeholder="Busca sin fecha..." value="<?=htmlspecialchars($_GET['IDCompra']??'')?>">
        </div>

        <div>
            <label>Proveedor</label>
            <select name="Proveedor">
                <option value="">Todos</option>
                <?php
                if (!empty($_GET['FechaDesde']) && !empty($_GET['FechaHasta'])) {
                    $FechaDesdeSQL = DateTime::createFromFormat('Y-m-d', $_GET['FechaDesde'])->format('Ymd');
                    $FechaHastaSQL = DateTime::createFromFormat('Y-m-d', $_GET['FechaHasta'])->format('Ymd');
                    $SucursalSel = $_GET['Sucursal'] ?? 'AMBAS';
                    
                    function provs($mysqli, $fDesde, $fHasta){
                        return $mysqli->query("SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov 
                                               FROM compras C 
                                               JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO 
                                               WHERE C.FECHA BETWEEN '$fDesde' AND '$fHasta' AND C.ESTADO='0' 
                                               ORDER BY prov");
                    }
                    
                    $pList = [];
                    if($SucursalSel != 'DRINKS' && isset($mysqliCentral)){ 
                        $r = provs($mysqliCentral, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $pList[$p['NIT']] = $p['prov']; 
                    }
                    if($SucursalSel != 'CENTRAL' && isset($mysqliDrinks)){ 
                        $r = provs($mysqliDrinks, $FechaDesdeSQL, $FechaHastaSQL); 
                        while($r && $p = $r->fetch_assoc()) $pList[$p['NIT']] = $p['prov']; 
                    }
                    
                    foreach($pList as $n => $nm){ 
                        $sel = ($_GET['Proveedor'] ?? '') == $n ? 'selected' : ''; 
                        echo "<option value='$n' $sel>$nm</option>"; 
                    }
                }
                ?>
            </select>
        </div>

        <div class="btn-container">
            <button type="submit">Consultar</button>
        </div>
    </form>

    <div style="margin-bottom: 20px; background: #eef2f7; padding: 12px; border-radius: 8px;">
        <label style="color: #0b5ed7;">🔍 Filtrar sobre el resultado actual (Producto o Sku)</label>
        <input type="text" id="filtroProductoInput" placeholder="Escribe el nombre o Sku del producto para buscar al instante..." onkeyup="filtrarPorProductoHtml()">
    </div>

<?php
$FechaDesdeGet = $_GET['FechaDesde'] ?? '';
$FechaHastaGet = $_GET['FechaHasta'] ?? '';
$IDCompraGet = preg_replace('/[^0-9]/', '', $_GET['IDCompra'] ?? '');
$ProvGet = preg_replace('/[^0-9]/', '', $_GET['Proveedor'] ?? '');
$SucursalGet = $_GET['Sucursal'] ?? 'AMBAS';

if ((!empty($FechaDesdeGet) && !empty($FechaHastaGet)) || !empty($IDCompraGet)) {

    $FechaDesdeSQL = !empty($FechaDesdeGet) ? DateTime::createFromFormat('Y-m-d', $FechaDesdeGet)->format('Ymd') : '';
    $FechaHastaSQL = !empty($FechaHastaGet) ? DateTime::createFromFormat('Y-m-d', $FechaHastaGet)->format('Ymd') : '';

    /* --- LOGICA PRECIO PROMEDIO VENTA --- */
    function precioProm($mysqli){
        $sql = "SELECT Q.Barcode, SUM(Q.CANTIDAD*Q.VALORPROD)/NULLIF(SUM(Q.CANTIDAD),0) pv FROM(
                    SELECT P.Barcode, D.CANTIDAD, D.VALORPROD FROM DETPEDIDOS D JOIN PEDIDOS PE ON PE.IDPEDIDO=D.IDPEDIDO JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE PE.ESTADO='0' AND STR_TO_DATE(PE.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
                    UNION ALL
                    SELECT P.Barcode, D.CANTIDAD, D.VALORPROD FROM FACTURAS F JOIN DETFACTURAS D ON D.IDFACTURA=F.IDFACTURA JOIN PRODUCTOS P ON P.IDPRODUCTO=D.IDPRODUCTO WHERE F.ESTADO='0' AND STR_TO_DATE(F.FECHA,'%Y%m%d') >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
                ) Q GROUP BY Q.Barcode";
        $out = []; 
        $r = $mysqli->query($sql);
        while($r && $x = $r->fetch_assoc()) $out[$x['Barcode']] = $x['pv'];
        return $out;
    }

    $pvC = ($SucursalGet != 'DRINKS') ? precioProm($mysqliCentral) : [];
    $pvD = ($SucursalGet != 'CENTRAL') ? precioProm($mysqliDrinks) : [];

    /* --- CONSULTA DE COMPRAS EN RANGO --- */
    function consultarCompras($mysqli, $suc, $fDesde, $fHasta, $p, $id){
        $cond = " WHERE C.ESTADO='0' ";
        if(!empty($id)){
            $cond .= " AND C.idcompra = '$id' ";
        } else {
            $cond .= " AND C.FECHA BETWEEN '$fDesde' AND '$fHasta' ";
        }
        if(!empty($p)) $cond .= " AND T.NIT = '$p' ";

        return $mysqli->query("
            SELECT '$suc' sucursal, C.idcompra, T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov,
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
    if($SucursalGet != 'DRINKS') $resultados[] = consultarCompras($mysqliCentral, 'Central', $FechaDesdeSQL, $FechaHastaSQL, $ProvGet, $IDCompraGet);
    if($SucursalGet != 'CENTRAL') $resultados[] = consultarCompras($mysqliDrinks, 'Drinks', $FechaDesdeSQL, $FechaHastaSQL, $ProvGet, $IDCompraGet);

    /* --- RENDERIZADO DE TABLA PRINCIPAL --- */
    echo "<div class='table-container'><table id='tablaPrincipalCompras'><thead><tr>
          <th>Suc</th><th>ID</th><th>Proveedor</th><th>Sku</th><th>Producto</th>
          <th>Cant</th><th>Costo</th><th>Total</th>";
    $PuedeVerUtil = (Autorizacion($User, '9999') === 'SI');
    if($PuedeVerUtil) echo "<th>P.Venta</th><th>Util</th><th>%</th>";
    echo "</tr></thead><tbody>";

    $provAnt = ''; 
    $idCompraAnt = '';
    $sucursalAnt = '';
    $nitAnt = '';
    
    $subCompra = 0;   
    $subProveedor = 0; 
    $gran = 0;         
    $hayRegistros = false;

    $resumenFacturas = [];

    foreach($resultados as $res){
        while($res && $x = $res->fetch_assoc()){
            $hayRegistros = true;
            
            $cant = $x['CANTIDAD'];
            $net = ($x['VALOR'] - ($x['descuento'] / max($cant, 1)));
            $costo = $net + ($net * $x['porciva'] / 100) + $x['ValICUIUni'];
            $totalItem = $costo * $cant;

            $pv = ($x['sucursal'] == 'Central') ? ($pvC[$x['Barcode']] ?? 0) : ($pvD[$x['Barcode']] ?? 0);
            $util = ($pv - $costo) * $cant;
            $porc = $costo > 0 ? (($pv - $costo) / $costo) * 100 : 0;

            if (($idCompraAnt && $idCompraAnt != $x['idcompra']) || ($provAnt && $provAnt != $x['prov'])) {
                echo "<tr class='row-total-compra' data-compra-id='$idCompraAnt' style='background:#fdfdfe; font-style:italic;'><td colspan='7' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
                if($PuedeVerUtil) echo "<td colspan='3'></td>";
                echo "</tr>";
                
                $resumenFacturas[] = [
                    'prov' => $provAnt,
                    'nit' => $nitAnt,
                    'sucursal' => $sucursalAnt,
                    'idcompra' => $idCompraAnt,
                    'total' => $subCompra
                ];
                $subCompra = 0;
            }

            if ($provAnt && $provAnt != $x['prov']) {
                echo "<tr class='subtotal row-total-prov'><td colspan='7'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
                if($PuedeVerUtil) echo "<td colspan='3'></td>";
                echo "</tr>"; 
                $subProveedor = 0;
            }

            $subCompra += $totalItem;
            $subProveedor += $totalItem;
            $gran += $totalItem;
            
            $provAnt = $x['prov'];
            $nitAnt = $x['NIT'];
            $idCompraAnt = $x['idcompra'];
            $sucursalAnt = $x['sucursal'];
            
            $cls = $x['sucursal'] == 'Central' ? 'central' : 'drinks';
            $clsP = $porc >= 0 ? 'porc-pos' : 'porc-neg';

            // Marcamos las filas de datos con una clase y atributos data para el buscador dinámico
            echo "<tr class='data-row' data-sku='".htmlspecialchars($x['Barcode'])."' data-descripcion='".htmlspecialchars(strtolower($x['descripcion']))."'>
                <td><span class='badge $cls'>{$x['sucursal']}</span></td>
                <td>{$x['idcompra']}</td>
                <td class='text-left'>{$x['prov']}</td>
                <td class='text-left'>{$x['Barcode']}</td>
                <td class='text-left'>{$x['descripcion']}</td>
                <td>".number_format($cant, 0)."</td>
                <td>".fmoneda($costo)."</td><td>".fmoneda($totalItem)."</td>";
            if($PuedeVerUtil){
                echo "<td>".fmoneda($pv)."</td><td>".fmoneda($util)."</td><td class='$clsP'>".number_format($porc, 1)."%</td>";
            }
            echo "</tr>";
        }
    }

    if($hayRegistros){
        echo "<tr class='row-total-compra' data-compra-id='$idCompraAnt' style='background:#fdfdfe; font-style:italic;'><td colspan='7' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        
        $resumenFacturas[] = [
            'prov' => $provAnt,
            'nit' => $nitAnt,
            'sucursal' => $sucursalAnt,
            'idcompra' => $idCompraAnt,
            'total' => $subCompra
        ];

        echo "<tr class='subtotal row-total-prov'><td colspan='7'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        
        echo "<tr class='total row-total-general'><td colspan='7'>TOTAL GENERAL DE COMPRAS</td><td>".fmoneda($gran)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        echo "</tbody></table></div>";

        /* =========================================================
           TABLA RESUMEN CON VALORES NEGATIVOS (EGRESOS)
           ========================================================= */
        usort($resumenFacturas, function($a, $b) {
            return strcmp($a['prov'], $b['prov']);
        });

        echo "<h3 class='resumen-title'>📋 Resumen de Egresos por Proveedor (Valores de Facturas en Negativo)</h3>";

        echo "<div class='table-container' style='max-height: 45vh;'><table><thead><tr>";
        echo "<th class='text-left'>Sede / Sucursal</th>";
        echo "<th class='text-center'>ID Compra / Factura</th>";
        echo "<th>Total Comprado</th>";
        echo "<th class='text-center' style='width:140px;'>Acción</th>";
        echo "</tr></thead><tbody>";

        $provAntResumen = '';
        $subTotalProvResumen = 0;

        foreach($resumenFacturas as $rf){
            if ($provAntResumen && $provAntResumen != $rf['prov']) {
                echo "<tr class='subtotal' style='background:#f1f3f5;'>";
                echo "<td colspan='2' class='text-left'>Acumulado Total Egreso: $provAntResumen</td>";
                echo "<td class='negativo'>".fmonedaNegativa($subTotalProvResumen)."</td>";
                echo "<td></td>";
                echo "</tr>";
                $subTotalProvResumen = 0;
            }

            if ($provAntResumen != $rf['prov']) {
                echo "<tr><td colspan='4' class='bg-prov'>🏢 Proveedor: {$rf['prov']} (NIT: {$rf['nit']})</td></tr>";
                $provAntResumen = $rf['prov'];
            }

            $subTotalProvResumen += $rf['total'];
            $cls = $rf['sucursal'] == 'Central' ? 'central' : 'drinks';

            echo "<tr>";
            echo "<td class='text-left'><span class='badge $cls'>{$rf['sucursal']}</span></td>";
            echo "<td class='text-center'><strong>{$rf['idcompra']}</strong></td>";
            echo "<td class='negativo'>".fmonedaNegativa($rf['total'])."</td>";
            echo "<td class='text-center'>
                    <button type='button' class='btn-grabar-row' onclick='grabarFactura(\"{$rf['nit']}\", \"{$rf['prov']}\", \"{$rf['idcompra']}\", {$rf['total']})'>💾 Grabar Egreso</button>
                  </td>";
            echo "</tr>";
        }
        
        if($provAntResumen != '') {
            echo "<tr class='subtotal' style='background:#f1f3f5;'>";
            echo "<td colspan='2' class='text-left'>Acumulado Total Egreso: $provAntResumen</td>";
            echo "<td class='negativo'>".fmonedaNegativa($subTotalProvResumen)."</td>";
            echo "<td></td>";
            echo "</tr>";
        }
        
        echo "<tr class='total'><td colspan='2' class='text-left'>TOTAL GENERAL DEL RESUMEN (EGRESOS)</td><td class='negativo'>".fmonedaNegativa($gran)."</td><td></td></tr>";
        echo "</tbody></table></div>";

    } else {
        echo "<tr><td colspan='11' style='text-align:center;padding:20px;'>No se encontraron registros para la consulta actual.</td></tr>";
        echo "</tbody></table></div>";
    }
}
?>
</div>

<script>
// Filtrado instantáneo por producto sobre el DOM cargado
function filtrarPorProductoHtml() {
    const input = document.getElementById('filtroProductoInput');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tablaPrincipalCompras .data-row');
    const subTotalsCompra = document.querySelectorAll('#tablaPrincipalCompras .row-total-compra');
    const subTotalsProv = document.querySelectorAll('#tablaPrincipalCompras .row-total-prov');
    const totalGeneral = document.querySelectorAll('#tablaPrincipalCompras .row-total-general');

    if (filter === '') {
        // Mostrar absolutamente todo si el input de búsqueda está vacío
        rows.forEach(r => r.classList.remove('hidden-row'));
        subTotalsCompra.forEach(s => s.classList.remove('hidden-row'));
        subTotalsProv.forEach(p => p.classList.remove('hidden-row'));
        totalGeneral.forEach(g => g.classList.remove('hidden-row'));
        return;
    }

    // Filtrar filas de datos
    rows.forEach(row => {
        const sku = row.getAttribute('data-sku') || '';
        const descripcion = row.getAttribute('data-descripcion') || '';
        
        if (sku.includes(filter) || descripcion.includes(filter)) {
            row.classList.remove('hidden-row');
        } else {
            row.classList.add('hidden-row');
        }
    });

    // Ocultar barras informativas de totales internos/proveedor para evitar confusión visual al buscar
    subTotalsCompra.forEach(s => s.classList.add('hidden-row'));
    subTotalsProv.forEach(p => p.classList.add('hidden-row'));
    totalGeneral.forEach(g => g.classList.add('hidden-row'));
}

function grabarFactura(nitProv, nombreProv, idCompra, montoFactura) {
    var montoNegativoStr = '-' + montoFactura.toLocaleString('es-CO');
    if (!confirm('¿Deseas grabar el egreso de la factura ID: ' + idCompra + ' por valor de $' + montoNegativoStr + ' para ' + nombreProv + '?')) {
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
</script>

</body>
</html>