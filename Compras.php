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
    <title>Compras Gerenciales</title>
    <style>
        body{ font-family:Segoe UI,Arial; margin:15px; background:#f4f6f8; font-size:16px; }
        .card{ background:#fff; padding:20px; border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.10); margin-bottom: 20px; }
        .filters{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:15px }
        label{ font-size:14px; font-weight:700 }
        select,input,button{ width:100%; padding:10px 12px; border-radius:8px; border:1px solid #ccc; font-size:16px }
        button{ background:#0d6efd; color:#fff; font-weight:700; cursor:pointer }
        .table-container{ max-height:65vh; overflow:auto; border-radius:12px; border:1px solid #ddd; margin-bottom: 20px; }
        table{ border-collapse:collapse; width:100%; min-width:1200px; font-size:15px }
        th,td{ border:1px solid #ddd; padding:8px 10px; text-align:right; white-space:nowrap }
        .text-left{text-align:left}
        .text-center{text-align:center}
        thead th{ position:sticky; top:0; z-index:10; background:#f1f3f5; font-weight:800; text-align:center }
        .badge{ padding:4px 10px; border-radius:14px; color:#fff; font-size:13px; font-weight:700 }
        .central{background:#0d6efd} .drinks{background:#198754}
        .subtotal{ background:#eef6ff; font-weight:800; }
        .total{ background:#e6fffa; font-weight:900; }
        .porc-pos{color:#1b5e20;font-weight:800} .porc-neg覆{color:#b71c1c;font-weight:800}
        .porc-neg{color:#b71c1c;font-weight:800}
        .resumen-title { margin-top: 40px; margin-bottom: 15px; color: #333; }
        .table-resumen { min-width: 800px; max-width: 1000px; margin: 0; }
        .bg-prov { background:#f8f9fa; font-weight:700; text-align:left; color:#1a252f; border-bottom:2px solid #ddd; }
        .btn-grabar-row { background: #dc3545; border: none; color: white; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer; width: auto; }
        .btn-grabar-row:hover { background: #bb2d3b; }
        .negativo { color: #b71c1c; font-weight: bold; }
    </style>
</head>
<body>

<div class="card">
    <h2>📊 Compras Gerenciales</h2>

    <form method="GET" class="filters">
        <div>
            <label>Fecha</label>
            <input type="date" name="Fecha" value="<?=htmlspecialchars($_GET['Fecha'] ?? date('Y-m-d'))?>">
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
                if (!empty($_GET['Fecha'])) {
                    $FechaSQL = DateTime::createFromFormat('Y-m-d', $_GET['Fecha'])->format('Ymd');
                    $SucursalSel = $_GET['Sucursal'] ?? 'AMBAS';
                    
                    function provs($mysqli, $f){
                        return $mysqli->query("SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov 
                                               FROM compras C 
                                               JOIN TERCEROS T ON T.IDTERCERO=C.IDTERCERO 
                                               WHERE C.FECHA='$f' AND C.ESTADO='0' 
                                               ORDER BY prov");
                    }
                    
                    $pList = [];
                    if($SucursalSel != 'DRINKS' && isset($mysqliCentral)){ 
                        $r = provs($mysqliCentral, $FechaSQL); 
                        while($r && $p = $r->fetch_assoc()) $pList[$p['NIT']] = $p['prov']; 
                    }
                    if($SucursalSel != 'CENTRAL' && isset($mysqliDrinks)){ 
                        $r = provs($mysqliDrinks, $FechaSQL); 
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

        <div>
            <label>&nbsp;</label>
            <button type="submit">Consultar</button>
        </div>
    </form>

<?php
$FechaGet = $_GET['Fecha'] ?? '';
$IDCompraGet = preg_replace('/[^0-9]/', '', $_GET['IDCompra'] ?? '');
$ProvGet = preg_replace('/[^0-9]/', '', $_GET['Proveedor'] ?? '');
$SucursalGet = $_GET['Sucursal'] ?? 'AMBAS';

if (!empty($FechaGet) || !empty($IDCompraGet)) {

    $FechaSQL = !empty($FechaGet) ? DateTime::createFromFormat('Y-m-d', $FechaGet)->format('Ymd') : '';

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

    /* --- CONSULTA DE COMPRAS --- */
    function consultarCompras($mysqli, $suc, $f, $p, $id){
        $cond = " WHERE C.ESTADO='0' ";
        if(!empty($id)){
            $cond .= " AND C.idcompra = '$id' ";
        } else {
            $cond .= " AND C.FECHA = '$f' ";
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
    if($SucursalGet != 'DRINKS') $resultados[] = consultarCompras($mysqliCentral, 'Central', $FechaSQL, $ProvGet, $IDCompraGet);
    if($SucursalGet != 'CENTRAL') $resultados[] = consultarCompras($mysqliDrinks, 'Drinks', $FechaSQL, $ProvGet, $IDCompraGet);

    /* --- RENDERIZADO DE TABLA PRINCIPAL --- */
    echo "<div class='table-container'><table><thead><tr>
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
                echo "<tr style='background:#fdfdfe; font-style:italic;'><td colspan='7' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
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
                echo "<tr class='subtotal'><td colspan='7'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
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

            echo "<tr>
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
        echo "<tr style='background:#fdfdfe; font-style:italic;'><td colspan='7' style='text-align:right; color:#555;'>Total Compra ID $idCompraAnt</td><td>".fmoneda($subCompra)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        
        $resumenFacturas[] = [
            'prov' => $provAnt,
            'nit' => $nitAnt,
            'sucursal' => $sucursalAnt,
            'idcompra' => $idCompraAnt,
            'total' => $subCompra
        ];

        echo "<tr class='subtotal'><td colspan='7'>TOTAL PROVEEDOR: $provAnt</td><td>".fmoneda($subProveedor)."</td>";
        if($PuedeVerUtil) echo "<td colspan='3'></td>";
        echo "</tr>";
        
        echo "<tr class='total'><td colspan='7'>TOTAL GENERAL DE COMPRAS</td><td>".fmoneda($gran)."</td>";
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

        echo "<div class='table-container' style='max-height: 45vh;'><table class='table-resumen'><thead><tr>";
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