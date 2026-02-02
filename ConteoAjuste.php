<?php
require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");  
require_once("Conexion.php");    

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

$usuarioActual = $_SESSION['Usuario'];
$hoy = date("Y-m-d");
$mensaje = "";

/* ============================================================
   1. PROCESAMIENTO DEL AJUSTE
   ============================================================ */
if (isset($_POST['accion']) && $_POST['accion'] === 'ajustar') {
    $idConteo = (int)$_POST['id_conteo'];
    
    // Obtenemos los datos clave del conteo (incluyendo el stock que el sistema tenía al contar)
    $stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa, stock_sistema FROM conteoweb WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $conteo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conteo) {
        $nitFila         = trim($conteo['NitEmpresa']);
        $CodCat          = $conteo['CodCat'];
        $difTotal        = (float)$conteo['diferencia'];
        $stockAnterior   = (float)$conteo['stock_sistema'];
        $stockNuevo      = $stockAnterior + $difTotal; // Calculamos el valor final proyectado

        $dbDestino = ($nitFila === '901724534-7') ? $mysqliDrinks : $mysqliCentral;

        // 1. Obtener los SKUs de la categoría
        $skus = [];
        $st = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=?");
        $st->bind_param("s", $CodCat);
        $st->execute();
        $resSKU = $st->get_result();
        while ($row = $resSKU->fetch_assoc()) $skus[] = $row['Sku'];
        $st->close();

        if (!empty($skus)) {
            $ph = implode(",", array_fill(0, count($skus), '?'));
            $sql = "SELECT p.idproducto, i.cantidad FROM productos p 
                    INNER JOIN inventario i ON i.idproducto = p.idproducto 
                    WHERE p.barcode IN ($ph) AND i.idalmacen = 1";
            
            $stmt = $dbDestino->prepare($sql);
            $stmt->bind_param(str_repeat("s", count($skus)), ...$skus);
            $stmt->execute();
            $resProd = $stmt->get_result();

            $productosActuales = [];
            while ($r = $resProd->fetch_assoc()) $productosActuales[] = $r;
            $stmt->close();

            if (!empty($productosActuales)) {
                $difPorUnidad = $difTotal / count($productosActuales);
                
                $dbDestino->begin_transaction();
                try {
                    foreach ($productosActuales as $p) {
                        $idp = $p['idproducto'];
                        $nuevaCant = $p['cantidad'] + $difPorUnidad;
                        
                        // Actualizar Inventario
                        $updInv = $dbDestino->prepare("UPDATE inventario SET cantidad=?, localchange=1, syncweb=0, sincalmacenes='AJUSTE_WEB' WHERE idproducto=? AND idalmacen=1");
                        $updInv->bind_param("di", $nuevaCant, $idp);
                        $updInv->execute();
                        
                        // Marcar producto para sincronización
                        $dbDestino->query("UPDATE productos SET localchange=1, syncweb=0 WHERE idproducto=$idp");
                    }

                    // 2. Cerrar el conteo en la web
                    $mysqli->query("UPDATE conteoweb SET estado='C' WHERE id=$idConteo");
                    
                    // 3. GUARDAR HISTORIAL CON VALORES ANTES/DESPUÉS
                    $log = $mysqli->prepare("INSERT INTO historial_ajustes 
                        (id_conteo, usuario, nit_empresa, categoria, stock_anterior, diferencia_aplicada, stock_nuevo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $log->bind_param("isssddd", $idConteo, $usuarioActual, $nitFila, $CodCat, $stockAnterior, $difTotal, $stockNuevo);
                    $log->execute();

                    $dbDestino->commit();
                    $mensaje = "✅ Ajuste procesado: Sistema anterior: $stockAnterior | Nuevo: $stockNuevo";
                } catch (Exception $e) {
                    $dbDestino->rollback();
                    $mensaje = "❌ Error crítico: " . $e->getMessage();
                }
            }
        }
    }
}

/* ============================================================
   2. CONSULTA DE DATOS PENDIENTES (HOY)
   ============================================================ */
$res = $mysqli->query("SELECT c.*, cat.Nombre 
                       FROM conteoweb c 
                       INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                       WHERE c.estado = 'A' 
                       AND ABS(c.diferencia) > 0.2 
                       AND DATE(c.fecha_conteo) = '$hoy'
                       ORDER BY c.id DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Ajustes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card { border: none; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1); }
        .table thead { background: #343a40; color: white; }
        .badge-delta { font-size: 0.9em; padding: 5px 10px; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h4 class="mb-0 text-primary">📊 Ajustes Pendientes (Fecha: <?= date("d/m/Y") ?>)</h4>
            <button onclick="location.reload()" class="btn btn-outline-secondary btn-sm">🔄 Recargar</button>
        </div>
        <div class="card-body">
            
            <?php if($mensaje): ?>
                <div class="alert alert-info border-0 shadow-sm mb-4"><?= $mensaje ?></div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>SEDE</th>
                            <th>CATEGORÍA</th>
                            <th class="text-end">EN SISTEMA</th>
                            <th class="text-end">FISICO</th>
                            <th class="text-center">DIFERENCIA</th>
                            <th class="text-center">ACCION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($res && $res->num_rows > 0): ?>
                            <?php while($r = $res->fetch_assoc()): 
                                $sede = ($r['NitEmpresa'] === '901724534-7') ? "DRINKS" : "CENTRAL";
                            ?>
                            <tr>
                                <td><span class="fw-bold text-secondary"><?= $sede ?></span></td>
                                <td><small class="text-muted d-block"><?= $r['CodCat'] ?></small><?= $r['Nombre'] ?></td>
                                <td class="text-end fw-bold text-muted"><?= number_format($r['stock_sistema'], 2) ?></td>
                                <td class="text-end fw-bold text-dark"><?= number_format($r['stock_fisico'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-delta <?= ($r['diferencia'] < 0) ? 'bg-danger' : 'bg-success' ?>">
                                        <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <form method="POST">
                                        <input type="hidden" name="id_conteo" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="accion" value="ajustar">
                                        <button type="submit" class="btn btn-primary btn-sm px-3 shadow-sm" onclick="return confirm('¿Aplicar ajuste y guardar historial?')">Ajustar Sistema</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron diferencias mayores a 0.2 para procesar hoy.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>