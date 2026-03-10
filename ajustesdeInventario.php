<?php
require_once("ConnCentral.php");   
require_once("ConnDrinks.php");    
require_once("Conexion.php");      
date_default_timezone_set('America/Bogota'); 

session_start();
$UsuarioSesion = $_SESSION['Usuario'] ?? 'SISTEMA';

$sedes = [
    'Central' => ['db' => $mysqliCentral, 'nit' => '86057267-8'],
    'Drinks'  => ['db' => $mysqliDrinks,  'nit' => '901724534-7']
];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ejecutar_ajuste'])) {
    $barcode = $_POST['barcode'];
    $cantidadVal = (float)$_POST['cantidad'];
    $sedeKey = $_POST['sede'];
    $obs = !empty($_POST['observacion']) ? substr($_POST['observacion'], 0, 140) : "Ajuste manual";

    if (isset($sedes[$sedeKey]) && $cantidadVal != 0) {
        $db = $sedes[$sedeKey]['db'];
        $nit = $sedes[$sedeKey]['nit'];
        
        // Eliminamos 'precio_costo' de la consulta para evitar el error de columna desconocida
        $resP = $db->query("SELECT idproducto FROM productos WHERE barcode='$barcode'")->fetch_assoc();

        if ($resP) {
            $idp = $resP['idproducto'];
            $db->begin_transaction();
            $mysqliWeb->begin_transaction();

            try {
                // Obtenemos idalmacen para cumplir con la llave foránea
                $resAlm = $db->query("SELECT idalmacen FROM almacenes LIMIT 1")->fetch_assoc();
                $idalmacen = $resAlm['idalmacen'] ?? 1;

                // Parámetros para el Kardex según tu CREATE TABLE
                $tipoM = ($cantidadVal > 0) ? '+' : '-';
                $cantAbs = abs($cantidadVal);
                $fechaCorta = date('Y-m-d');
                $numDoc = "AJU-" . time();

                // El Trigger se encargará del stock gracias a 'kardexsinc = 1'
                $sqlKardex = "INSERT INTO kardex (
                    numdocumento, tipodoc, idproducto, idalmacen, detallemov, 
                    fechamov, cantidad, tipomov, kardexsinc, fechakardex
                ) VALUES (
                    '$numDoc', 'AJUSTE', $idp, $idalmacen, '$obs', 
                    '$fechaCorta', $cantAbs, '$tipoM', 1, '$fechaCorta'
                )";

                if (!$db->query($sqlKardex)) {
                    throw new Exception($db->error);
                }

                // Log en la tabla de movimientos web
                $tipoLog = ($cantidadVal > 0) ? 'ENTRA' : 'SALE'; 
                $sqlLog = "INSERT INTO inventario_movimientos (
                    NitEmpresa_Orig, NroSucursal_Orig, usuario_Orig, tipo, barcode, 
                    cant, NitEmpresa_Dest, NroSucursal_Dest, Observacion, Aprobado, fecha, Estado
                ) VALUES (
                    '$nit', '001', '$UsuarioSesion', '$tipoLog', '$barcode', 
                    $cantidadVal, '$nit', '001', '$obs', 1, NOW(), 1
                )";
                $mysqliWeb->query($sqlLog);

                $db->commit();
                $mysqliWeb->commit();
                $msg = "<div class='alert ok'>✅ Ajuste procesado correctamente.</div>";
            } catch (Exception $e) {
                $db->rollback();
                $mysqliWeb->rollback();
                $msg = "<div class='alert err'>❌ Error en la operación: " . $e->getMessage() . "</div>";
            }
        }
    }
}

// Búsqueda de productos
$term = $_GET['term'] ?? '';
$resultados = [];
if ($term !== '') {
    foreach ($sedes as $key => $s) {
        $sql = "SELECT p.barcode, p.descripcion, IFNULL(i.cantidad,0) as stock 
                FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
                WHERE p.barcode = '$term' OR p.descripcion LIKE '%$term%' LIMIT 10";
        $res = $s['db']->query($sql);
        if($res){
            while ($r = $res->fetch_assoc()) {
                $resultados[$r['barcode']]['desc'] = $r['descripcion'];
                $resultados[$r['barcode']]['stock_'.$key] = $r['stock'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ajuste de Inventario</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .container { background: #fff; max-width: 800px; margin: auto; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: center; }
        th { background: #222; color: #fff; }
        .alert { padding: 15px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .ok { background: #dff0d8; color: #3c763d; }
        .err { background: #f2dede; color: #a94442; }
        .btn { padding: 8px 12px; cursor: pointer; border: none; border-radius: 4px; }
        .btn-blue { background: #007bff; color: white; }
        .btn-green { background: #28a745; color: white; }
    </style>
</head>
<body>
<div class="container">
    <h2>🛠 Ajuste Manual</h2>
    <?php if(isset($msg)) echo $msg; ?>
    <form method="GET" style="display:flex; gap:10px;">
        <input type="text" name="term" placeholder="Buscar..." value="<?= htmlspecialchars($term) ?>" style="flex-grow:1; padding:8px;">
        <button type="submit" class="btn btn-blue">Buscar</button>
    </form>
    <?php if($resultados): ?>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>DRI</th>
                <th>CEN</th>
                <th>Ajuste</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($resultados as $bc => $data): ?>
            <tr>
                <td style="text-align:left;"><?= $data['desc'] ?><br><small><?= $bc ?></small></td>
                <td><b><?= number_format($data['stock_Drinks'] ?? 0, 1) ?></b></td>
                <td><b><?= number_format($data['stock_Central'] ?? 0, 1) ?></b></td>
                <td>
                    <form method="POST" style="display:flex; gap:5px;">
                        <input type="hidden" name="barcode" value="<?= $bc ?>">
                        <input type="number" name="cantidad" step="0.01" style="width:60px;" required>
                        <select name="sede">
                            <option value="Drinks">DRI</option>
                            <option value="Central">CEN</option>
                        </select>
                        <button type="submit" name="ejecutar_ajuste" class="btn btn-green">OK</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>