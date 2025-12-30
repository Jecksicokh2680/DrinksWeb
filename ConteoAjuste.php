<?php
require_once("ConnCentral.php"); // POS ($mysqliPos) -> Stock real
require_once("Conexion.php");    // ADM ($mysqli)    -> Log de conteos
session_start();

// Variables de Sesión

/* ============================================
   VALIDAR SESIÓN
============================================ */
if (!isset($_SESSION['Usuario'])) {
    die("Sesión no válida");
}

$usuario   = $_SESSION['Usuario'] ?? 'SISTEMA';
$nit       = $_SESSION['NitEmpresa'] ?? '';
$sucursal  = $_SESSION['NroSucursal'] ?? '';
$idalmacen = 1; // ID del almacén que se está auditando (puedes dinamizarlo)
date_default_timezone_set('America/Bogota');

$mensaje   = "";

/* ============================================
   APLICAR AJUSTE Y GUARDAR MOVIMIENTO
   Distribuyendo diferencia entre todos los productos
============================================ */
if (isset($_POST['accion']) && $_POST['accion'] === 'ajustar') {

    $idConteo = (int)$_POST['id_conteo'];

    // 1️⃣ Traer el conteo
    $stmt = $mysqli->prepare("
        SELECT CodCat, diferencia
        FROM conteoweb
        WHERE id=? AND estado='A'
    ");
    $stmt->bind_param("i", $idConteo);
    $stmt->execute();
    $conteo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$conteo) {
        // $mensaje = "❌ Conteo no válido o ya cerrado";
    } else {

        $CodCat     = $conteo['CodCat'];
        $diferencia = (float)$conteo['diferencia'];

        // 2️⃣ Traer los SKUs de la categoría
        $skus = [];
        $stmt = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=?");
        $stmt->bind_param("s", $CodCat);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $skus[] = $row['Sku'];
        $stmt->close();

        if (empty($skus)) {
            $mensaje = "❌ Categoría sin productos";
        } else {

            // 3️⃣ Traer inventario POS de todos los SKUs
            $ph = implode(",", array_fill(0, count($skus), '?'));
            $types = str_repeat("s", count($skus));

            $sql = "
                SELECT p.idproducto, p.barcode, i.cantidad
                FROM productos p
                INNER JOIN inventario i ON i.idproducto=p.idproducto
                WHERE p.barcode IN ($ph) AND i.idalmacen=?
            ";
            $stmt = $mysqliPos->prepare($sql);
            $params = array_merge($skus, [$idalmacen]);
            $stmt->bind_param($types . "i", ...$params);
            $stmt->execute();
            $res = $stmt->get_result();

            $productos = [];
            while ($r = $res->fetch_assoc()) $productos[] = $r;
            $stmt->close();

            if (empty($productos)) {
                $mensaje = "❌ No se encontró inventario para los SKUs";
            } else {

                $numProductos = count($productos);
                $difPorProducto = $diferencia / $numProductos;

                foreach ($productos as $prod) {

                    $idproducto = (int)$prod['idproducto'];
                    $barcode    = $prod['barcode'];
                    $antes      = (float)$prod['cantidad'];
                    $despues    = $antes + $difPorProducto;

                    // 4️⃣ Actualizar inventario
                    $stmt = $mysqliPos->prepare("
                        UPDATE inventario
                        SET cantidad=?, localchange=1
                        WHERE idproducto=? AND idalmacen=?
                    ");
                    $stmt->bind_param("dii", $despues, $idproducto, $idalmacen);
                    $stmt->execute();
                    $stmt->close();

                    // 5️⃣ Guardar en inventario_movimientos
                    $tipo = $difPorProducto >= 0 ? 'REM_ENT' : 'REM_SAL';
                    $cant = abs($difPorProducto);
                    $referencia = "Ajuste por conteo distribuido";

                    $stmt = $mysqli->prepare("
                        INSERT INTO inventario_movimientos
                        (idproducto, barcode, tipo, cant, stock_antes, stock_despues, Nit, NroSucursal, usuario, referencia)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->bind_param(
                        "issdddsiss",
                        $idproducto,
                        $barcode,
                        $tipo,
                        $cant,
                        $antes,
                        $despues,
                        $nit,
                        $sucursal,
                        $usuario,
                        $referencia
                    );
                    $stmt->execute();
                    $stmt->close();
                }

                // 6️⃣ Cerrar el conteo
                $stmt = $mysqli->prepare("UPDATE conteoweb SET estado='C' WHERE id=?");
                $stmt->bind_param("i", $idConteo);
                $stmt->execute();
                $stmt->close();

                $mensaje = "✅ Inventario ajustado correctamente (diferencia distribuida entre {$numProductos} productos)";
            }
        }
    }
}

/* ============================================
   CONTEOS ACTIVOS
============================================ */
$res = $mysqli->query("
    SELECT c.*, cat.Nombre
    FROM conteoweb c
    INNER JOIN categorias cat ON cat.CodCat=c.CodCat
    WHERE c.estado='A' AND DATE(fecha_conteo)=CURDATE()
    AND c.diferencia != 0
    order by c.diferencia desc
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ajuste por Categoría</title>
<style>
body{font-family:Segoe UI;background:#eef2f7}
.card{max-width:1000px;margin:20px auto;background:#fff;padding:20px;border-radius:12px}
table{width:100%;border-collapse:collapse}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:center}
th{background:#f1f4f9}
button{background:#198754;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer}
.msg{margin-bottom:10px;padding:8px;border-radius:6px;background:#e7f3ff}
.diferencia-neg{color:#dc3545;font-weight:bold;}
</style>
</head>
<body>

<div class="card">
<h3>AJUSTE AL INVENTARIO POR DISTRIBUCION</h3>
<div class="card">
    <div style="margin:15px 0">
    <button type="button" onclick="location.reload()" style="font-size:16px;padding:8px 14px">
        🔄 Refrescar
    </button>
</div>
<?php if($mensaje): ?>
<div class="msg"><?= $mensaje ?></div>
<?php endif; ?>

<table>
<tr>
    <th>Categoría</th>
    <th>Diferencia</th>
    <th>Acción</th>
</tr>
<?php while($c = $res->fetch_assoc()): ?>
<tr>
    <td><?= $c['CodCat'].' - '.$c['Nombre'] ?></td>
    <td align="right" class="<?= $c['diferencia']<0?'diferencia-neg':'' ?>">
        <?= number_format($c['diferencia'],3) ?>
    </td>
    <td align="center">
        <form method="POST"
              onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').innerText='Ajustando...';">
            <input type="hidden" name="id_conteo" value="<?= $c['id'] ?>">
            <input type="hidden" name="accion" value="ajustar">
            <button type="submit">Ajustar</button>
        </form>
    </td>
</tr>
<?php endwhile; ?>
</table>
</div>

</body>
</html>
