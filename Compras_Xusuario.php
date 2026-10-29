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
   CONEXIONES Y FUNCIONES BASE
========================================== */
require('Conexion.php');
require('ConnCentral.php');
require('ConnDrinks.php');

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

        /* Estilo para los paneles individuales por proveedor */
        .proveedor-panel {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,.04);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .proveedor-header {
            background: #eef2f7;
            padding: 12px 15px;
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e3a8a;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h2 { margin-top: 0; color: #111; font-size: 1.35rem; }

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
            max-height: 50vh; 
            overflow-x: auto; 
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            background: #fff;
            position: relative;
        }
        
        table { border-collapse: collapse; width: 100%; min-width: 750px; font-size: 13px; }
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

        .hidden-row { display: none !important; }
        .hidden-panel { display: none !important; }

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
            <label>Producto Específico</label>
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
        <input type="text" id="filtroProductoInput" placeholder="Escribe para buscar al instante..." onkeyup="filtrarPorProductoHtml()">
    </div>

<?php
if ((!empty($FechaDesdeGet) && !empty($FechaHastaGet)) || !empty($IDCompraGet)) {

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
            SELECT '$suc' sucursal, C.FECHA, C.idcompra, T.NIT, CONCAT(T.nombres,' ',T.apellidos) prov, 
                   P.Barcode, P.descripcion, D.CANTIDAD
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

    if(empty($filasCrudas)){
        echo "<div style='text-align:center;padding:20px;background:#fff;border-radius:8px;'>No se encontraron registros para la consulta actual.</div>";
    } else {
        // Agrupar filas por Proveedor
        $proveedoresAgrupados = [];
        foreach($filasCrudas as $x){
            $proveedorKey = $x['prov'] . ' (NIT: ' . $x['NIT'] . ')';
            $proveedoresAgrupados[$proveedorKey][] = $x;
        }

        echo "<div class='scroll-hint'>↔️ Desliza horizontalmente para ver los campos de las tablas</div>";

        // Renderizar un panel independiente por proveedor
        foreach($proveedoresAgrupados as $nombreProveedor => $filasProveedor){
            echo "<div class='proveedor-panel' data-proveedor-name='".htmlspecialchars(strtolower($nombreProveedor))."'>";
            echo "<div class='proveedor-header'>🏢 Proveedor: {$nombreProveedor}</div>";
            echo "<div class='table-container'><table><thead><tr>";
            echo "<th style='width: 70px;'>Suc</th><th style='width: 100px;'>Fecha</th><th style='width: 80px;'>ID</th><th class='text-left' style='width: 120px;'>Sku</th><th class='text-left'>Producto</th><th style='width: 80px;'>Cantidad</th>";
            echo "</tr></thead><tbody>";

            foreach($filasProveedor as $x){
                $fechaRaw = $x['FECHA'];
                $fechaFormateada = (strlen($fechaRaw) == 8) ? substr($fechaRaw, 0, 4) . '-' . substr($fechaRaw, 4, 2) . '-' . substr($fechaRaw, 6, 2) : $fechaRaw;
                $cls = $x['sucursal'] == 'Central' ? 'central' : 'drinks';

                echo "<tr class='data-row' data-rowtext='".htmlspecialchars(strtolower($x['idcompra'].' '.$fechaFormateada.' '.$x['sucursal'].' '.$x['Barcode'].' '.$x['descripcion']))."'>
                    <td><span class='badge $cls'>{$x['sucursal']}</span></td>
                    <td class='text-center'>{$fechaFormateada}</td>
                    <td class='text-center'>{$x['idcompra']}</td>
                    <td class='text-left'>{$x['Barcode']}</td>
                    <td class='text-left'>{$x['descripcion']}</td>
                    <td>".number_format($x['CANTIDAD'], 0)."</td>
                </tr>";
            }

            echo "</tbody></table></div>";
            echo "</div>";
        }
    }
}
?>
</div>

<script>
function filtrarPorProductoHtml() {
    const input = document.getElementById('filtroProductoInput');
    const filter = input.value.toLowerCase().trim();
    const paneles = document.querySelectorAll('.proveedor-panel');

    paneles.forEach(panel => {
        const provName = panel.getAttribute('data-proveedor-name') || '';
        const rows = panel.querySelectorAll('.data-row');
        let visibleRowsInPanel = 0;

        rows.forEach(row => {
            const rowText = row.getAttribute('data-rowtext') || '';
            
            if (filter === '' || provName.includes(filter) || rowText.includes(filter)) {
                row.classList.remove('hidden-row');
                visibleRowsInPanel++;
            } else {
                row.classList.add('hidden-row');
            }
        });

        // Ocultar todo el panel si ninguna fila coincide y el filtro no coincide con el proveedor
        if (visibleRowsInPanel > 0 || filter === '' || provName.includes(filter)) {
            panel.classList.remove('hidden-panel');
            if (provName.includes(filter)) {
                rows.forEach(r => r.classList.remove('hidden-row'));
            }
        } else {
            panel.classList.add('hidden-panel');
        }
    });
}
</script>

</body>
</html>