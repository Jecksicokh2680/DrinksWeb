<?php
require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");  
require_once("Conexion.php");    

session_start();
if (!isset($_SESSION['Usuario'])) die("Sesión no válida");

// 1. LÓGICA DE PROCESAMIENTO
if (isset($_POST['accion']) && $_POST['accion'] === 'ajustar') {
    $idConteo = (int)$_POST['id_conteo'];
    
    $stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa FROM conteoweb WHERE id=? AND estado='A'");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $conteo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($conteo) {
        $nitFila = trim($conteo['NitEmpresa']);
        $CodCat = $conteo['CodCat'];
        $diferenciaTotal = (float)$conteo['diferencia'];

        $dbDestino = ($nitFila === '901724534-7') ? $mysqliDrinks : $mysqliCentral;
        $nombreDestino = ($nitFila === '901724534-7') ? "DRINKS" : "CENTRAL";

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

            $productos = [];
            while ($r = $resProd->fetch_assoc()) $productos[] = $r;
            $stmt->close();

            if (!empty($productos)) {
                $difPorUnidad = $diferenciaTotal / count($productos);
                foreach ($productos as $p) {
                    $nuevoStock = $p['cantidad'] + $difPorUnidad;
                    $idp = $p['idproducto'];
                    $updInv = $dbDestino->prepare("UPDATE inventario SET cantidad=?, localchange=1, syncweb=0, sincalmacenes='AJUSTE_WEB' WHERE idproducto=? AND idalmacen=1");
                    $updInv->bind_param("di", $nuevoStock, $idp);
                    $updInv->execute();
                    $updInv->close();
                    $dbDestino->query("UPDATE productos SET localchange=1, syncweb=0 WHERE idproducto=$idp");
                }
                $mysqli->query("UPDATE conteoweb SET estado='C' WHERE id=$idConteo");
                $mensaje = "✅ Ajuste aplicado exitosamente.";
            }
        }
    }
}

// 2. VISTA DE DATOS (Filtrado por diferencia mayor a 0.2 absoluto)
$res = $mysqli->query("SELECT c.*, cat.Nombre 
                       FROM conteoweb c 
                       INNER JOIN categorias cat ON cat.CodCat = c.CodCat 
                       WHERE c.estado = 'A' 
                       AND ABS(c.diferencia) > 0.18 
                       ORDER BY c.id DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajuste de Inventario</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 10px; margin: 0; }
        .container { max-width: 1100px; margin: auto; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .header h2 { font-size: 1.4rem; margin: 0; }

        .btn-refresh { background: #3498db; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; transition: 0.3s; font-size: 14px; }
        .btn-refresh:hover { background: #2980b9; }

        /* Contenedor para hacer la tabla responsive */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { text-align: left; background: #fafafa; padding: 12px; color: #666; font-size: 12px; border-bottom: 2px solid #eee; }
        td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 14px; }
        
        .btn-ajuste { background: #27ae60; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; max-width: 100px; }
        .diff { font-weight: bold; }
        .neg { color: #e74c3c; }
        .pos { color: #27ae60; }

        /* Media Queries para móviles */
        @media (max-width: 600px) {
            .container { padding: 15px; }
            .header { flex-direction: column; align-items: flex-start; }
            .header h2 { font-size: 1.1rem; }
            .btn-refresh { width: 100%; justify-content: center; }
            td { padding: 10px 8px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h2>📊 Ajustes Pendientes (> 0.2)</h2>
        <button onclick="window.location.reload();" class="btn-refresh" title="Recargar datos">
            🔄 Refrescar Lista
        </button>
    </div>
    
    <?php if(isset($mensaje)): ?>
        <div style="background:#d4edda; color:#155724; padding:15px; border-radius:8px; margin-bottom:20px; font-size: 14px;">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>SEDE</th>
                    <th>CATEGORÍA</th>
                    <th style="text-align:right">SISTEMA</th>
                    <th style="text-align:right">FISICO</th>
                    <th style="text-align:center">DIFERENCIA</th>
                    <th style="text-align:center">ACCION</th>
                </tr>
            </thead>
            <tbody>
                <?php if($res && $res->num_rows > 0): ?>
                    <?php while($r = $res->fetch_assoc()): 
                        $sede = ($r['NitEmpresa'] === '901724534-7') ? "DRINKS" : "CENTRAL";
                    ?>
                    <tr>
                        <td><strong><?= $sede ?></strong></td>
                        <td><?= $r['CodCat'] ?> - <small><?= $r['Nombre'] ?></small></td>
                        <td style="text-align:right"><?= number_format($r['stock_sistema'], 2) ?></td>
                        <td style="text-align:right"><?= number_format($r['stock_fisico'], 2) ?></td>
                        <td style="text-align:center" class="diff <?= ($r['diferencia'] < 0) ? 'neg' : 'pos' ?>">
                            <?= ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2) ?>
                        </td>
                        <td style="text-align:center">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="id_conteo" value="<?= $r['id'] ?>">
                                <input type="hidden" name="accion" value="ajustar">
                                <button type="submit" class="btn-ajuste" onclick="return confirm('¿Aplicar este ajuste?')">Ajustar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">No hay diferencias significativas pendientes.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>