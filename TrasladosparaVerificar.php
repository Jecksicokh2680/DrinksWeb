<?php
require_once("ConnCentral.php");  
require_once("ConnDrinks.php");    
require_once("Conexion.php");    

date_default_timezone_set('America/Bogota');
session_start();

// Conexión principal para logs (mysqliWeb debe estar definida en Conexion.php)
$mysqli = $mysqliWeb; 
$UsuarioSesion = $_SESSION['Usuario'] ?? 'SISTEMA';

// Configuración de NITs de las sedes para los filtros
$nits = [
    'CENTRAL' => '86057267-8',
    'DRINKS'  => '901724534-7'
];

/* ============================================================
   LÓGICA POST: PROCESAMIENTO DE TRASLADOS Y REVISIÓN
============================================================ */
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    
    // 1. EJECUTAR NUEVO TRASLADO (Afecta inventarios de inmediato)
    if(isset($_POST['mover'])){
        $barcode = $_POST['barcode'];
        $cantidad = (float)$_POST['cantidad'];
        $origen = $_POST['origen']; 
        $destino = $_POST['destino'];
        $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual";

        $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
        $nitOrig = ($origen == "Central") ? $nits['CENTRAL'] : $nits['DRINKS'];
        $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
        $nitDest = ($destino == "Central") ? $nits['CENTRAL'] : $nits['DRINKS'];

        $idO = $dbOrig->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;
        $idD = $dbDest->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;

        if($idO && $idD) {
            try {
                $mysqliCentral->begin_transaction(); 
                $mysqliDrinks->begin_transaction(); 
                $mysqliWeb->begin_transaction();
                
                // Afectar inventarios en bases de datos locales
                $dbOrig->query("UPDATE inventario SET cantidad = cantidad - $cantidad WHERE idproducto = $idO");
                $dbDest->query("UPDATE inventario SET cantidad = cantidad + $cantidad WHERE idproducto = $idD");
                
                // Registrar el log en la tabla global con Aprobado = 1
                $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado, fecha) 
                            VALUES (?, '001', ?, 'SALE', ?, ?, ?, '001', ?, 1, NOW())";
                $stmtLog = $mysqliWeb->prepare($sqlLog);
                $stmtLog->bind_param("sssdss", $nitOrig, $UsuarioSesion, $barcode, $cantidad, $nitDest, $obs);
                $stmtLog->execute();
                
                $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
                $msg = "<div class='alert ok'>✅ Traslado realizado con éxito. Inventarios actualizados.</div>";
            } catch(Exception $e) {
                $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
                $msg = "<div class='alert err'>❌ Error en el proceso: ".$e->getMessage()."</div>";
            }
        } else {
            $msg = "<div class='alert err'>❌ Error: El producto con barcode '$barcode' no existe en una de las sedes.</div>";
        }
    }

    // 2. MARCAR COMO REVISADO POR BODEGA
    if(isset($_POST['marcar_revisado'])){
        $idMov = (int)$_POST['idMov'];
        $marca = " [REVISADO POR BODEGA]";
        $mysqliWeb->query("UPDATE inventario_movimientos SET Observacion = CONCAT(Observacion, '$marca') WHERE idMov=$idMov AND Observacion NOT LIKE '%$marca%'");
        $msg = "<div class='alert ok'>🔔 Movimiento marcado como revisado correctamente.</div>";
    }
}

/* ============================================================
   FILTROS Y CONSULTA DE DATOS
============================================================ */
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d');
$f_fin    = $_GET['f_fin']    ?? date('Y-m-d');
$sede_out = $_GET['sede_origen'] ?? 'TODAS';
$buscar   = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// 1. Obtener barcodes que coincidan con la descripción en la BD Central (si hay búsqueda por texto)
$barcodesFiltrados = [];
$busquedaPorTexto = false;

if(!empty($buscar)){
    $busquedaPorTexto = true;
    // Buscamos en productos tanto por barcode como por descripción
    $buscarEscaped = $mysqliCentral->real_escape_string($buscar);
    $rFiltroProd = $mysqliCentral->query("SELECT barcode FROM productos WHERE descripcion LIKE '%$buscarEscaped%' OR barcode LIKE '%$buscarEscaped%'");
    while($rf = $rFiltroProd->fetch_assoc()){
        $barcodesFiltrados[] = "'".$rf['barcode']."'";
    }
}

// 2. Construcción del WHERE dinámico
$whereSede = "";
if($sede_out !== 'TODAS'){
    $nitFiltro = ($sede_out == 'CENTRAL') ? $nits['CENTRAL'] : $nits['DRINKS'];
    $whereSede = " AND NitEmpresa_Orig = '$nitFiltro' ";
}

$whereProducto = "";
if($busquedaPorTexto){
    if(!empty($barcodesFiltrados)){
        $listaFiltroCodes = implode(",", $barcodesFiltrados);
        $whereProducto = " AND barcode IN ($listaFiltroCodes) ";
    } else {
        // Si buscó algo que no existe, forzamos a que no traiga resultados erróneos
        $whereProducto = " AND barcode = 'X-X-NO-MATCH-X-X' ";
    }
}

// Consulta final uniendo los filtros y exigiendo Aprobado = 1
$query = "SELECT * FROM inventario_movimientos 
          WHERE DATE(fecha) BETWEEN '$f_inicio' AND '$f_fin' 
          AND Aprobado = 1 
          $whereSede 
          $whereProducto
          ORDER BY fecha DESC";

$resMov = $mysqliWeb->query($query);

$movimientos = [];
$barcodesInPage = [];
while($r = $resMov->fetch_assoc()){
    $movimientos[] = $r;
    $barcodesInPage[] = "'".$r['barcode']."'";
}

// Obtener nombres de productos desde Central para visualización
$nombresGlobales = [];
if(!empty($barcodesInPage)){
    $listaCodes = implode(",", array_unique($barcodesInPage));
    $rNom = $mysqliCentral->query("SELECT barcode, descripcion FROM productos WHERE barcode IN ($listaCodes)");
    while($rn = $rNom->fetch_assoc()) $nombresGlobales[$rn['barcode']] = $rn['descripcion'];
}

// Función para traducir NIT a Nombre amigable
function nombreSede($nit) {
    if ($nit == '86057267-8') return 'CENTRAL';
    if ($nit == '901724534-7') return 'DRINKS';
    return $nit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Traslados | Drinks Depot & Central</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; padding: 20px; color: #333; margin: 0; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 1300px; margin: auto; }
        
        .filtros-bar { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #dee2e6; display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; }
        .f-group { display: flex; flex-direction: column; gap: 6px; flex: 1 1 200px; }
        .f-group label { font-size: 13px; font-weight: 700; color: #495057; }
        
        select, input { padding: 9px; border: 1px solid #ced4da; border-radius: 5px; outline: none; width: 100%; box-sizing: border-box; }
        .btn-filter { background: #0d6efd; color: white; border: none; padding: 10px 25px; border-radius: 5px; cursor: pointer; font-weight: bold; flex: 1 1 auto; height: 40px; }
        .btn-filter:hover { background: #0b5ed7; }

        /* Contenedor responsivo para la tabla */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }

        table { width: 100%; border-collapse: collapse; background: white; min-width: 800px; }
        th { background: #212529; color: #fff; padding: 14px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 14px; border-bottom: 1px solid #e9ecef; text-align: center; vertical-align: middle; }
        tr:hover { background-color: #fcfcfc; }

        .alert { padding: 15px; margin-bottom: 20px; border-radius: 6px; text-align: center; font-weight: bold; }
        .ok { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .err { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }

        .btn-check { background: #ffc107; color: #000; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.3s; white-space: nowrap; }
        .btn-check:hover { background: #e0a800; transform: translateY(-1px); }
        .revisado { color: #198754; font-weight: bold; font-size: 13px; white-space: nowrap; }
        
        .badge-sede { font-weight: bold; padding: 4px 8px; border-radius: 4px; font-size: 11px; white-space: nowrap; border: 1px solid transparent; }
        .badge-central { background: #cfe2ff; color: #084298; border-color: #b6d4fe; }
        .badge-drinks { background: #f8d7da; color: #842029; border-color: #f5c2c7; }

        /* Media Queries para pantallas pequeñas */
        @media (max-width: 768px) {
            body { padding: 10px; }
            .card { padding: 15px; }
            .filtros-bar { gap: 15px; padding: 15px; }
            .btn-filter { width: 100%; }
        }
    </style>
</head>
<body>

<div class="card">
    <h2 style="margin: 0 0 20px 0; color: #212529; font-size: 1.5rem;">📦 Aprobacion de Traslados de Mercancia Jefe de Bodega</h2>
    
    <?php if(isset($msg)) echo $msg; ?>

    <form method="GET" class="filtros-bar">
        <div class="f-group">
            <label>Filtrar Salida de Sede:</label>
            <select name="sede_origen">
                <option value="TODAS" <?= $sede_out == 'TODAS' ? 'selected' : '' ?>>-- Todas las Salidas --</option>
                <option value="CENTRAL" <?= $sede_out == 'CENTRAL' ? 'selected' : '' ?>>Salidas de CENTRAL</option>
                <option value="DRINKS" <?= $sede_out == 'DRINKS' ? 'selected' : '' ?>>Salidas de DRINKS</option>
            </select>
        </div>
        <div class="f-group">
            <label>Fecha Inicio:</label>
            <input type="date" name="f_inicio" value="<?= $f_inicio ?>">
        </div>
        <div class="f-group">
            <label>Fecha Fin:</label>
            <input type="date" name="f_fin" value="<?= $f_fin ?>">
        </div>
        <div class="f-group">
            <label>Producto / Barcode:</label>
            <input type="text" name="buscar" placeholder="Ej: Aguardiente o 770..." value="<?= htmlspecialchars($buscar) ?>">
        </div>
        <button type="submit" class="btn-filter">🔍 Aplicar Filtros</button>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Fecha / Hora</th>
                    <th>Producto / Barcode</th>
                    <th>Cant.</th>
                    <th>Flujo (Origen ➔ Destino)</th>
                    <th>Observaciones</th>
                    <th>Visto Bueno Bodega</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($movimientos)): ?>
                    <tr><td colspan="6" style="padding: 40px; color: #6c757d;">No hay registros activos para este filtro.</td></tr>
                <?php else: ?>
                    <?php foreach($movimientos as $r): 
                        $nom = $nombresGlobales[$r['barcode']] ?? 'Producto Desconocido';
                        $revisado = (strpos($r['Observacion'], '[REVISADO POR BODEGA]') !== false);
                        
                        $sedeOrigNombre = nombreSede($r['NitEmpresa_Orig']);
                        $sedeDestNombre = nombreSede($r['NitEmpresa_Dest']);
                        
                        $classOrig = ($sedeOrigNombre == 'CENTRAL') ? 'badge-central' : 'badge-drinks';
                        $classDest = ($sedeDestNombre == 'CENTRAL') ? 'badge-central' : 'badge-drinks';
                    ?>
                    <tr>
                        <td style="font-size: 13px; color: #6c757d; white-space: nowrap;"><?= date("d/m/Y H:i", strtotime($r['fecha'])) ?></td>
                        <td style="text-align: left;">
                            <div style="font-weight: bold;"><?= $nom ?></div>
                            <small style="color: #dc3545; font-family: monospace;"><?= $r['barcode'] ?></small>
                        </td>
                        <td><span style="font-size: 17px; font-weight: 800;"><?= number_format($r['cant'], 1) ?></span></td>
                        <td>
                            <span class="badge-sede <?= $classOrig ?>"><?= $sedeOrigNombre ?></span>
                            <span style="color: #adb5bd; margin: 0 5px;">➔</span>
                            <span class="badge-sede <?= $classDest ?>"><?= $sedeDestNombre ?></span>
                        </td>
                        <td style="font-size: 13px; font-style: italic; color: #495057;"><?= $r['Observacion'] ?></td>
                        <td>
                            <?php if(!$revisado): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
                                    <button type="submit" name="marcar_revisado" class="btn-check">Confirmar Recibo</button>
                                </form>
                            <?php else: ?>
                                <span class="revisado">✅ REVISADO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>