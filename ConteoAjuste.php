<?php
/* ============================================================
   1. CONFIGURACIÓN Y CONEXIONES
============================================================ */
require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks
require_once("Conexion.php");    // ADM ($mysqli)

session_start();
if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida. Por favor, vuelva a iniciar sesión.");
}

$usuarioActual = $_SESSION['Usuario'];
$hoy = date("Y-m-d");
$mensaje = "";

/* ============================================================
   2. PROCESAMIENTO DE ACCIONES (AJUSTAR O BORRAR/RECHAZAR)
============================================================ */
if (isset($_POST['accion'])) {
    $idConteo = (int)$_POST['id_conteo'];

    // Consultar los datos del conteo pendiente
    $stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa, stock_sistema FROM conteoweb WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $conteo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conteo) {
        $nitFila       = trim($conteo['NitEmpresa']);
        $CodCat        = $conteo['CodCat'];
        
        // ---------------------------------------------------------
        // ACCIÓN: AJUSTAR (Aplicar cambios y cambiar estado a 'C' y categoría)
        // ---------------------------------------------------------
        if ($_POST['accion'] === 'ajustar') {
            $difTotal      = (float)$conteo['diferencia'];
            $stockAnterior = (float)$conteo['stock_sistema'];
            $stockNuevo    = $stockAnterior + $difTotal;

            // Seleccionar base de datos de la sede
            $dbDestino = ($nitFila === '901724534-7') ? $mysqliDrinks : $mysqliCentral;

            // 1. Obtener SKUs de la categoría
            $skus = [];
            $st = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=? AND Estado='1'");
            $st->bind_param("s", $CodCat);
            $st->execute();
            $resSKU = $st->get_result();
            while ($row = $resSKU->fetch_assoc()) $skus[] = $row['Sku'];
            $st->close();

            if (!empty($skus)) {
                $ph = implode(",", array_fill(0, count($skus), '?'));
                $sql = "SELECT p.idproducto, i.cantidad FROM productos p 
                        INNER JOIN inventario i ON i.idproducto = p.idproducto 
                        WHERE p.barcode IN ($ph) AND i.idalmacen = 1 AND p.estado='1'";
                
                $stmtP = $dbDestino->prepare($sql);
                $stmtP->bind_param(str_repeat("s", count($skus)), ...$skus);
                $stmtP->execute();
                $resProd = $stmtP->get_result();

                $productosActuales = [];
                while ($r = $resProd->fetch_assoc()) $productosActuales[] = $r;
                $stmtP->close();

                if (!empty($productosActuales)) {
                    $difPorUnidad = $difTotal / count($productosActuales);
                    
                    $dbDestino->begin_transaction();
                    $mysqli->begin_transaction();

                    try {
                        foreach ($productosActuales as $p) {
                            $idp = $p['idproducto'];
                            $nuevaCant = $p['cantidad'] + $difPorUnidad;
                            
                            // Actualizar Inventario en Sede
                            $updInv = $dbDestino->prepare("UPDATE inventario SET cantidad=?, localchange=1, syncweb=0, sincalmacenes='AJUSTE_WEB' WHERE idproducto=? AND idalmacen=1");
                            $updInv->bind_param("di", $nuevaCant, $idp);
                            $updInv->execute();
                            $updInv->close();
                            
                            // Marcar producto para sincronización general
                            $dbDestino->query("UPDATE productos SET localchange=1, syncweb=0 WHERE idproducto=$idp");
                        }

                        // 2. Cerrar el conteo (Estado 'C' de Cerrado/Completado)
                        $mysqli->query("UPDATE conteoweb SET estado='C' WHERE id=$idConteo");
                        
                        // 3. CAMBIAR ESTADO DE LA CATEGORÍA (Ejemplo: Estado = '0' o el valor que requieras para inhabilitarla/actualizarla)
                        // Ajusta 'Estado' y el valor '0' según la estructura de tu tabla 'categorias' o 'catproductos'
                        $updCat = $mysqli->prepare("UPDATE categorias SET Estado = '0' WHERE CodCat = ?");
                        $updCat->bind_param("s", $CodCat);
                        $updCat->execute();
                        $updCat->close();

                        // 4. Registrar en Historial de Ajustes
                        $log = $mysqli->prepare("INSERT INTO historial_ajustes 
                            (id_conteo, usuario, nit_empresa, categoria, stock_anterior, diferencia_aplicada, stock_nuevo) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $log->bind_param("isssddd", $idConteo, $usuarioActual, $nitFila, $CodCat, $stockAnterior, $difTotal, $stockNuevo);
                        $log->execute();
                        $log->close();

                        $dbDestino->commit();
                        $mysqli->commit();
                        $mensaje = "✅ Ajuste exitoso. Categoría $CodCat actualizada y estado modificado.";
                    } catch (Exception $e) {
                        $dbDestino->rollback();
                        $mysqli->rollback();
                        $mensaje = "❌ Error crítico: " . $e->getMessage();
                    }
                } else {
                    $mensaje = "⚠️ No hay productos con stock activo para esta categoría en la sede.";
                }
            }
        }
        
        // ---------------------------------------------------------
        // ACCIÓN: BORRAR / RECHAZAR (Marcar conteo como eliminado/cancelado y cambiar estado de categoría)
        // ---------------------------------------------------------
        elseif ($_POST['accion'] === 'borrar') {
            $mysqli->begin_transaction();
            try {
                // Cambiar estado del conteo a 'X' (Cancelado/Borrado) o el que prefieras
                $mysqli->query("UPDATE conteoweb SET estado='X' WHERE id=$idConteo");

                // Cambiar estado de la categoría (ejemplo: Estado = '0')
                $updCat = $mysqli->prepare("UPDATE categorias SET Estado = '0' WHERE CodCat = ?");
                $updCat->bind_param("s", $CodCat);
                $updCat->execute();
                $updCat->close();

                $mysqli->commit();
                $mensaje = "🗑️ El conteo fue descartado y el estado de la categoría $CodCat fue actualizado.";
            } catch (Exception $e) {
                $mysqli->rollback();
                $mensaje = "❌ Error al borrar el registro: " . $e->getMessage();
            }
        }
    }
}

/* ============================================================
   3. CONSULTA DE PENDIENTES
   ============================================================ */
$res = $mysqli->query("SELECT c.*, cat.Nombre 
                        FROM conteoweb c 
                        INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                        WHERE c.estado = 'A' 
                        AND DATE(c.fecha_conteo) = '$hoy'
                        ORDER BY c.fecha_conteo DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Ajustes de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 15px; border: none; }
        .table thead { background: #2c3e50; color: white; }
        .badge-delta { font-size: 0.85em; width: 80px; display: inline-block; }
        .btn-accion { border-radius: 20px; font-weight: 600; transition: 0.3s; }
        .btn-accion:hover { transform: scale(1.05); }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="card shadow">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h4 class="mb-0 text-dark">📦 Ajustes Pendientes de Hoy</h4>
            <span class="badge bg-primary px-3 py-2"><?= date("d/m/Y") ?></span>
        </div>
        
        <div class="card-body">
            <?php if($mensaje): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?= $mensaje ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Sede</th>
                            <th>Categoría</th>
                            <th class="text-center">Sistema</th>
                            <th class="text-center">Físico</th>
                            <th class="text-center">Diferencia</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($res && $res->num_rows > 0): ?>
                            <?php while($r = $res->fetch_assoc()): 
                                $sedeNombre = ($r['NitEmpresa'] === '901724534-7') ? "DRINKS" : "CENTRAL";
                                $dif = (float)$r['diferencia'];
                            ?>
                            <tr>
                                <td><span class="fw-bold text-muted"><?= $sedeNombre ?></span></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= $r['Nombre'] ?></div>
                                    <small class="text-muted">Cód: <?= $r['CodCat'] ?></small>
                                </td>
                                <td class="text-center"><?= number_format($r['stock_sistema'], 2) ?></td>
                                <td class="text-center fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-delta <?= ($dif < 0) ? 'bg-danger' : 'bg-success' ?>">
                                        <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <!-- Formulario para Aplicar Ajuste -->
                                        <form method="POST" onsubmit="return confirm('¿Confirmas que deseas ajustar el stock y cambiar el estado de la categoría?')">
                                            <input type="hidden" name="id_conteo" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="accion" value="ajustar">
                                            <button type="submit" class="btn btn-primary btn-sm btn-accion px-3">
                                                Aplicar Ajuste
                                            </button>
                                        </form>

                                        <!-- Formulario para Borrar / Rechazar -->
                                        <form method="POST" onsubmit="return confirm('¿Deseas descartar este conteo y cambiar el estado de la categoría?')">
                                            <input type="hidden" name="id_conteo" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="accion" value="borrar">
                                            <button type="submit" class="btn btn-outline-danger btn-sm btn-accion px-3">
                                                Borrar
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <img src="https://cdn-icons-png.flaticon.com/512/5058/5058432.png" width="80" class="mb-3 opacity-25"><br>
                                    <span class="text-muted">No hay conteos pendientes por ajustar el día de hoy.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="text-center mt-4">
        <a href="InventarioFisico.php" class="text-decoration-none text-secondary small">← Volver al Panel de Conteos</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>