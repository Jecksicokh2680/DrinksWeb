<?php
require_once("ConnCentral.php");  
require_once("ConnDrinks.php");   
require_once("Conexion.php");     

date_default_timezone_set('America/Bogota');
session_start();

$mysqli = $mysqliWeb; 
$UsuarioSesion = $_SESSION['Usuario'] ?? 'SISTEMA';

/* ============================================================
    POST: TRASLADO INMEDIATO Y MARCACIÓN INFORMATIVA
============================================================ */
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    
    // 1. EJECUTAR TRASLADO (Afecta inventario al instante)
    if(isset($_POST['mover'])){
        $barcode = $_POST['barcode'];
        $cantidad = (float)$_POST['cantidad'];
        $origen = $_POST['origen'];
        $destino = $_POST['destino'];
        $obs = !empty($_POST['observacion']) ? $_POST['observacion'] : "Traspaso manual";

        $dbOrig = ($origen == "Central") ? $mysqliCentral : $mysqliDrinks;
        $nitOrig = ($origen == "Central") ? '86057267-8' : '901724534-7';
        $dbDest = ($destino == "Central") ? $mysqliCentral : $mysqliDrinks;
        $nitDest = ($destino == "Central") ? '86057267-8' : '901724534-7';

        $idO = $dbOrig->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;
        $idD = $dbDest->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc()['idproducto'] ?? null;

        if($idO && $idD) {
            try {
                $mysqliCentral->begin_transaction(); $mysqliDrinks->begin_transaction(); $mysqliWeb->begin_transaction();
                
                $dbOrig->query("UPDATE inventario SET cantidad = cantidad - $cantidad WHERE idproducto = $idO");
                $dbDest->query("UPDATE inventario SET cantidad = cantidad + $cantidad WHERE idproducto = $idD");
                
                // Se guarda con Aprobado=1 (Ya aplicado) y revisado_bodega=0 (Pendiente de ver)
                $sqlLog = "INSERT INTO inventario_movimientos (NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado, fecha) 
                           VALUES (?, '001', ?, 'SALE', ?, ?, ?, '001', ?, 1, NOW())";
                $stmtLog = $mysqliWeb->prepare($sqlLog);
                $stmtLog->bind_param("sssdss", $nitOrig, $UsuarioSesion, $barcode, $cantidad, $nitDest, $obs);
                $stmtLog->execute();
                
                $mysqliCentral->commit(); $mysqliDrinks->commit(); $mysqliWeb->commit();
                $msg = "<div class='alert ok'>✅ Traslado ejecutado e inventario actualizado</div>";
            } catch(Exception $e) {
                $mysqliCentral->rollback(); $mysqliDrinks->rollback(); $mysqliWeb->rollback();
                $msg = "<div class='alert err'>❌ Error: ".$e->getMessage()."</div>";
            }
        }
    }

    // 2. CHECK INFORMATIVO (Solo para marcar como "Revisado")
    if(isset($_POST['marcar_revisado'])){
        $idMov = (int)$_POST['idMov'];
        // Usamos la columna 'Aprobado' o una nueva. Aquí actualizamos una marca visual.
        $mysqliWeb->query("UPDATE inventario_movimientos SET Observacion = CONCAT(Observacion, ' [REVISADO POR JB]') WHERE idMov=$idMov");
        $msg = "<div class='alert ok'>🔔 Movimiento marcado como revisado</div>";
    }
}

// CONSULTAS
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$resMov = $mysqliWeb->query("SELECT * FROM inventario_movimientos WHERE DATE(fecha) BETWEEN '$f_inicio' AND '$f_fin' ORDER BY fecha DESC");

$nombresGlobales = [];
$rNom = $mysqliCentral->query("SELECT barcode, descripcion FROM productos");
while($rn = $rNom->fetch_assoc()) $nombresGlobales[$rn['barcode']] = $rn['descripcion'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Traslados con Verificación</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 1200px; margin: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #2c3e50; color: #fff; padding: 10px; }
        td { padding: 10px; border-bottom: 1px solid #eee; text-align: center; }
        .alert { padding: 10px; margin-bottom: 10px; border-radius: 4px; text-align: center; font-weight: bold; }
        .ok { background: #d4edda; color: #155724; }
        .btn-rev { background: #f39c12; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
        .revisado { color: #27ae60; font-weight: bold; font-size: 12px; }
    </style>
</head>
<body>

<div class="card">
    <h2>📦 Historial de Traslados (Verificación de Bodega)</h2>
    <?php if(isset($msg)) echo $msg; ?>

    <form method="GET" style="margin-bottom:20px;">
        <input type="date" name="f_inicio" value="<?= $f_inicio ?>">
        <input type="date" name="f_fin" value="<?= $f_fin ?>">
        <button type="submit">Filtrar</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Origen ➔ Destino</th>
                <th>Observaciones</th>
                <th>Visto Bueno Bodega</th>
            </tr>
        </thead>
        <tbody>
            <?php while($r = $resMov->fetch_assoc()): 
                $nom = $nombresGlobales[$r['barcode']] ?? 'N/A';
                $revisado = (strpos($r['Observacion'], '[REVISADO POR BODEGA]') !== false);
            ?>
            <tr>
                <td><?= date("H:i", strtotime($r['fecha'])) ?></td>
                <td style="text-align:left;"><b><?= $nom ?></b><br><small><?= $r['barcode'] ?></small></td>
                <td><b><?= number_format($r['cant'], 1) ?></b></td>
                <td><small><?= $r['NitEmpresa_Orig'] ?> ➔ <?= $r['NitEmpresa_Dest'] ?></small></td>
                <td><?= $r['Observacion'] ?></td>
                <td>
                    <?php if(!$revisado): ?>
                        <form method="POST">
                            <input type="hidden" name="idMov" value="<?= $r['idMov'] ?>">
                            <button type="submit" name="marcar_revisado" class="btn-rev">Marcar Revisión</button>
                        </form>
                    <?php else: ?>
                        <span class="revisado">✔️ REVISADO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>