<?php
require('ConnCentral.php');
require('ConnDrinks.php');
require('Conexion.php');

session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if (!$UsuarioSesion) { header("Location: Login.php"); exit; }

// --- Funciones de soporte ---
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

if (Autorizacion($UsuarioSesion, '0003') !== "SI" && Autorizacion($UsuarioSesion, '9999') !== "SI") {
    die("<h2 style='color:red; text-align:center; margin-top:50px;'>❌ No autorizado</h2>");
}

function obtenerDatos($cnx, $nombreSucursal, $f_ini, $f_fin, $busqProd, $f_fac) {
    if (!$cnx || $cnx->connect_error) return [];
    $extraCond = ($busqProd != "") ? " AND (PRODUCTOS.Descripcion LIKE '%".$cnx->real_escape_string($busqProd)."%' OR PRODUCTOS.Barcode LIKE '%".$cnx->real_escape_string($busqProd)."%') " : "";
    $condFactura = $extraCond . ($f_fac != "" ? " AND T1.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");
    $condPedido  = $extraCond . ($f_fac != "" ? " AND T2.NOMBRES = '".$cnx->real_escape_string($f_fac)."' " : "");

    // Los más recientes aparecen primero gracias al DESC
    $sql = "SELECT '$nombreSucursal' AS SUCURSAL, FACTURAS.FECHA, FACTURAS.HORA, T1.NOMBRES AS FACTURADOR, FACTURAS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETFACTURAS.CANTIDAD, DETFACTURAS.VALORPROD FROM FACTURAS INNER JOIN DETFACTURAS ON DETFACTURAS.IDFACTURA=FACTURAS.IDFACTURA INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETFACTURAS.IDPRODUCTO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=FACTURAS.IDVENDEDOR WHERE FACTURAS.ESTADO='0' AND FACTURAS.FECHA BETWEEN ? AND ? $condFactura UNION ALL SELECT '$nombreSucursal' AS SUCURSAL, PEDIDOS.FECHA, PEDIDOS.HORA, T2.NOMBRES AS FACTURADOR, PEDIDOS.NUMERO AS DOCUMENTO, PRODUCTOS.Barcode, PRODUCTOS.Descripcion AS PRODUCTO, DETPEDIDOS.CANTIDAD, DETPEDIDOS.VALORPROD FROM PEDIDOS INNER JOIN DETPEDIDOS ON PEDIDOS.IDPEDIDO=DETPEDIDOS.IDPEDIDO INNER JOIN PRODUCTOS ON PRODUCTOS.IDPRODUCTO=DETPEDIDOS.IDPRODUCTO INNER JOIN USUVENDEDOR V ON V.IDUSUARIO=PEDIDOS.IDUSUARIO INNER JOIN TERCEROS T2 ON T2.IDTERCERO=V.IDTERCERO WHERE PEDIDOS.ESTADO='0' AND PEDIDOS.FECHA BETWEEN ? AND ? $condPedido ORDER BY FECHA DESC, HORA DESC, DOCUMENTO DESC";

    $stmt = $cnx->prepare($sql);
    $stmt->bind_param("ssss", $f_ini, $f_fin, $f_ini, $f_fin);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// --- Obtención de datos ---
$f_ini = str_replace('-', '', $_GET['fecha_ini'] ?? date('Y-m-d'));
$f_fin = str_replace('-', '', $_GET['fecha_fin'] ?? date('Y-m-d'));
$fSuc = $_GET['sucursal'] ?? '';
$rows = [];
if ($fSuc == '' || $fSuc == 'CENTRAL') $rows = array_merge($rows, obtenerDatos($mysqliCentral, 'CENTRAL', $f_ini, $f_fin, $_GET['filtro_prod'] ?? '', $_GET['facturador'] ?? ''));
if ($fSuc == '' || $fSuc == 'DRINKS') $rows = array_merge($rows, obtenerDatos($mysqliDrinks, 'DRINKS', $f_ini, $f_fin, $_GET['filtro_prod'] ?? '', $_GET['facturador'] ?? ''));

// --- Agrupar y Calcular ---
$pedidos = [];
$skus = array_unique(array_column($rows, 'Barcode'));
$unicaja = [];
if ($skus && isset($mysqliWeb)) {
    $listaSkus = "'" . implode("','", array_map(array($mysqliWeb, 'real_escape_string'), $skus)) . "'";
    $q = $mysqliWeb->query("SELECT cp.Sku, cat.Unicaja FROM catproductos cp INNER JOIN categorias cat ON cp.CodCat = cat.CodCat WHERE cp.Sku IN ($listaSkus)");
    while ($u = $q->fetch_assoc()) $unicaja[$u['Sku']] = $u['Unicaja'];
}

foreach ($rows as $r) {
    $doc = $r['DOCUMENTO'];
    if (!isset($pedidos[$doc])) $pedidos[$doc] = ['SUCURSAL'=>$r['SUCURSAL'], 'FACTURADOR'=>$r['FACTURADOR'], 'HORA'=>$r['HORA'], 'ITEMS'=>[], 'TOTAL'=>0];
    $uni = $unicaja[$r['Barcode']] ?? 1;
    $cajas = floor($r['CANTIDAD']);
    $unds = round(($r['CANTIDAD'] - $cajas) * $uni);
    $pedidos[$doc]['ITEMS'][] = ['PROD'=>$r['PRODUCTO'], 'C'=>$cajas, 'U'=>$unds, 'VAL'=>$r['CANTIDAD'] * $r['VALORPROD']];
    $pedidos[$doc]['TOTAL'] += ($r['CANTIDAD'] * $r['VALORPROD']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría de Pedidos</title>
    <style>
        *{ box-sizing:border-box; }

        body{
            margin:0;
            font-family:'Segoe UI',sans-serif;
            background:#f4f7f6;
            padding:15px;
        }

        /*==========================
            FILTROS
        ==========================*/
        .filtros{
            margin-bottom:20px;
            background:#fff;
            padding:15px;
            border-radius:10px;
            box-shadow:0 2px 6px rgba(0,0,0,.08);
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
        }

        .filtros input, .filtros select, .filtros button{
            padding:10px;
            border-radius:6px;
            border:1px solid #CCC;
            font-size:15px;
        }

        .filtros button{
            background:#f57c00;
            color:white;
            font-weight:bold;
            cursor:pointer;
        }

        /*==========================
            TARJETAS
        ==========================*/
        .grid-container{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(420px,1fr));
            gap:20px;
        }

        .card{
            background:white;
            border-radius:12px;
            padding:15px;
            box-shadow:0 5px 12px rgba(0,0,0,.08);
            border-top:5px solid #f57c00;
            display:flex;
            flex-direction:column;
        }

        .card-header{
            font-size:14px;
            font-weight:bold;
            border-bottom:2px solid #eee;
            padding-bottom:10px;
            margin-bottom:10px;
        }

        .item-row{
            display:grid;
            grid-template-columns: 30px 1fr 50px 50px 85px;
            align-items:center;
            padding:8px 0;
            border-bottom:1px solid #f4f4f4;
            cursor:pointer;
            transition:.2s;
        }

        .item-row:hover{ background:#fff8e1; }
        .item-row:has(input:checked){ background:#fff3e0; font-weight:bold; }
        .item-row span{ text-align:center; font-size:13px; }
        .item-row span:nth-child(2){ text-align:left; padding-left:8px; }
        .item-row span:last-child{ text-align:right; font-weight:600; }

        .total{
            margin-top:15px;
            text-align:right;
            color:#2e7d32;
            font-size:18px;
            font-weight:bold;
        }

        .btn-audit{
            margin-top:25px;
            width:100%;
            background:#f57c00;
            color:white;
            border:none;
            border-radius:8px;
            padding:16px;
            font-size:18px;
            font-weight:bold;
            cursor:pointer;
        }

        .movil-nav {
            display: none;
        }

        /*==========================
                CELULAR
        ==========================*/
        @media (max-width:768px){
            body{ padding:8px; }
            .grid-container{ display:block; }

            .card{
                display: none;
                width:100%;
                min-height:calc(100vh - 240px);
                padding:15px;
                border-radius:0;
                border-top:8px solid #f57c00;
                margin-bottom: 15px;
            }
            
            .card.active {
                display: flex;
            }

            .card-header{ font-size:16px; }
            .item-row{ grid-template-columns: 28px 1fr 45px 45px 70px; font-size:14px; padding:12px 0; }
            .item-row span{ font-size:14px; }
            .total{ margin-top:auto; font-size:22px; padding-top:20px; }
            
            .btn-audit{ 
                position:sticky; 
                bottom:10px; 
                font-size:18px; 
                margin-top: 15px; 
            }
            
            .filtros{ flex-direction:column; align-items:stretch; }
            .filtros input, .filtros select, .filtros button{ width:100%; }

            .movil-nav {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                padding: 12px;
                border-radius: 8px;
                margin-top: 5px;
                margin-bottom: 15px;
                box-shadow: 0 2px 6px rgba(0,0,0,.08);
            }
            .movil-nav button {
                background: #333;
                color: white;
                border: none;
                padding: 12px 20px;
                font-size: 15px;
                font-weight: bold;
                border-radius: 6px;
                cursor: pointer;
            }
            .movil-nav button:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            .movil-nav span {
                font-size: 15px;
                font-weight: bold;
                color: #555;
            }
        }
    </style>
</head>
<body>
    <form method="GET" class="filtros">
        Desde: <input type="date" name="fecha_ini" value="<?= $_GET['fecha_ini'] ?? date('Y-m-d') ?>">
        Hasta: <input type="date" name="fecha_fin" value="<?= $_GET['fecha_fin'] ?? date('Y-m-d') ?>">
        Sucursal: 
        <select name="sucursal">
            <option value="">Todas</option>
            <option value="CENTRAL" <?= $fSuc=='CENTRAL'?'selected':'' ?>>CENTRAL</option>
            <option value="DRINKS" <?= $fSuc=='DRINKS'?'selected':'' ?>>DRINKS</option>
        </select>
        <button type="submit">Filtrar</button>
    </form>

    <form action="procesar_auditoria.php" method="POST">
        <div class="grid-container" id="contenedorTarjetas">
            <?php foreach($pedidos as $nro => $d): ?>
            <div class="card">
                <div class="card-header">Doc: <?= $nro ?> | <?= $d['SUCURSAL'] ?> | <?= $d['FACTURADOR'] ?> (<?= date("g:i a", strtotime($d['HORA'])) ?>)</div>
                <div class="item-row" style="font-weight:bold; color:#f57c00; border-bottom:2px solid #ddd; cursor:default;">
                    <span></span><span>Producto</span><span>Caj</span><span>Und</span><span>Total</span>
                </div>
                <?php foreach($d['ITEMS'] as $idx => $i): ?>
                    <label class="item-row">
                        <input type="checkbox" name="audit[]" value="<?= $nro ?>_<?= $idx ?>">
                        <span><?= htmlspecialchars($i['PROD']) ?></span>
                        <span><?= $i['C'] ?></span>
                        <span><?= $i['U'] ?></span>
                        <span>$<?= number_format($i['VAL'],0) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="total">Total: $<?= number_format($d['TOTAL'], 0) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="movil-nav">
            <button type="button" id="btnPrev" onclick="cambiarTarjeta(-1)">◀ Atrás</button>
            <span id="infoPaginacion">Pedido 0 de 0</span>
            <button type="button" id="btnNext" onclick="cambiarTarjeta(1)">Sig. ▶</button>
        </div>

        <button type="submit" class="btn-audit">✅ Guardar Auditoría Seleccionada</button>
    </form>

    <script>
        let tarjetas = document.querySelectorAll('.card');
        // El indexActual arranca en 0 porque el primer panel ($pedidos[0]) es el más reciente de todos.
        let indexActual = 0;
        let esPrimeraCarga = true;

        function inicializarPaginacion() {
            if (window.innerWidth <= 768 && tarjetas.length > 0) {
                if (esPrimeraCarga) {
                    indexActual = 0; // Garantiza iniciar en el primero (el más reciente)
                    esPrimeraCarga = false;
                }
                tarjetas.forEach(t => t.classList.remove('active'));
                tarjetas[indexActual].classList.add('active');
                actualizarControles();
            } else {
                tarjetas.forEach(t => t.style.display = '');
            }
        }

        function cambiarTarjeta(direccion) {
            tarjetas[indexActual].classList.remove('active');
            indexActual += direccion;
            
            if (indexActual < 0) indexActual = 0;
            if (indexActual >= tarjetas.length) indexActual = tarjetas.length - 1;
            
            tarjetas[indexActual].classList.add('active');
            actualizarControles();
        }

        function actualizarControles() {
            if (tarjetas.length === 0) {
                document.getElementById('infoPaginacion').innerText = "Sin registros";
                document.getElementById('btnPrev').disabled = true;
                document.getElementById('btnNext').disabled = true;
                return;
            }
            
            document.getElementById('infoPaginacion').innerText = `Pedido ${indexActual + 1} de ${tarjetas.length}`;
            document.getElementById('btnPrev').disabled = (indexActual === 0);
            document.getElementById('btnNext').disabled = (indexActual === tarjetas.length - 1);
        }

        window.addEventListener('DOMContentLoaded', inicializarPaginacion);
        window.addEventListener('resize', inicializarPaginacion);
    </script>
</body>
</html>