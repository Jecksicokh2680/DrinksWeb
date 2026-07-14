<?php
session_start();
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php");

if (empty($_SESSION['Usuario'])) { die("Acceso denegado."); }

$usuario_actual = $_SESSION['Usuario'];
$nit_empresa    = $_SESSION['NitEmpresa'];
$nro_sucursal   = $_SESSION['NroSucursal'];

// PROCESAMIENTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    global $mysqliWeb, $mysqliCentral, $mysqliDrinks;

    $bc      = $mysqliWeb->real_escape_string($_POST['barcode']);
    $nit     = $mysqliWeb->real_escape_string($_POST['nit']);
    $sede    = $mysqliWeb->real_escape_string($_POST['sede']);
    $cant    = floatval($_POST['cantidad']);
    $id_edit = isset($_POST['id_registro']) ? intval($_POST['id_registro']) : 0;

    $mysqliWeb->begin_transaction();
    try {
        // 1. Si es edición, anular el anterior
        if ($id_edit > 0) {
            $res = $mysqliWeb->query("SELECT * FROM historial_recibos WHERE id = $id_edit");
            $old = $res->fetch_assoc();
            
            if ($old) {
                $mysqliWeb->query("UPDATE historial_recibos SET estado = 'anulado' WHERE id = $id_edit");
                $db_old = ($old['sede'] === 'Central') ? $mysqliCentral : $mysqliDrinks;
                $db_old->query("UPDATE inventario SET cantidad = cantidad - {$old['cantidad']} 
                                WHERE idproducto = (SELECT idproducto FROM productos WHERE barcode = '{$old['barcode']}')");
            }
        }

        // 2. Insertar nuevo registro
        $stmt = $mysqliWeb->prepare("INSERT INTO historial_recibos (barcode, nit_proveedor, sede, cantidad, usuario, nit_empresa, sucursal, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'activo')");
        $stmt->bind_param("ssddsii", $bc, $nit, $sede, $cant, $usuario_actual, $nit_empresa, $nro_sucursal);
        $stmt->execute();

        // 3. Actualizar inventario
        $db = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;
        $db->query("INSERT INTO inventario (idproducto, cantidad) VALUES ((SELECT idproducto FROM productos WHERE barcode = '$bc'), $cant) 
                    ON DUPLICATE KEY UPDATE cantidad = cantidad + $cant");

        $mysqliWeb->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $mysqliWeb->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Consultas para la UI
$proveedores = $mysqliWeb->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre");
$productos   = $mysqliCentral->query("SELECT barcode, descripcion FROM productos ORDER BY descripcion ASC");
$historial   = $mysqliWeb->query("SELECT * FROM historial_recibos ORDER BY fecha DESC LIMIT 20");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Recibos</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { background: white; padding: 25px; border-radius: 12px; max-width: 800px; margin: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .anulado { background-color: #fee2e2; color: #991b1b; text-decoration: line-through; }
    </style>
</head>
<body>
<div class="box">
    <h2>📦 Gestión de Recibos</h2>
    <input type="hidden" id="id_registro">
    
    <select id="nit" class="select2"><?php while($p=$proveedores->fetch_assoc()): ?><option value="<?= $p['CedulaNit'] ?>"><?= $p['Nombre'] ?></option><?php endwhile; ?></select>
    <select id="barcode" class="select2"><?php while($prod=$productos->fetch_assoc()): ?><option value="<?= $prod['barcode'] ?>"><?= $prod['descripcion'] ?></option><?php endwhile; ?></select>
    <input type="number" id="cantidad" placeholder="Cantidad">
    <select id="sede"><option value="Central">Central</option><option value="Drinks">Drinks</option></select>
    
    <button onclick="guardar()">Guardar Registro</button>

    <table>
        <thead><tr><th>Fecha</th><th>Prod</th><th>Cant</th><th>Sede</th><th>Estado</th><th>Acción</th></tr></thead>
        <tbody>
            <?php while($row = $historial->fetch_assoc()): ?>
            <tr class="<?= $row['estado'] === 'anulado' ? 'anulado' : '' ?>">
                <td><?= $row['fecha'] ?></td>
                <td><?= $row['barcode'] ?></td>
                <td><?= $row['cantidad'] ?></td>
                <td><?= $row['sede'] ?></td>
                <td><?= $row['estado'] ?></td>
                <td>
                    <?php if($row['estado'] === 'activo'): ?>
                    <button onclick="cargarEdicion(<?= $row['id'] ?>, '<?= $row['barcode'] ?>', <?= $row['cantidad'] ?>, '<?= $row['sede'] ?>')">Editar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
$(document).ready(function() { $('.select2').select2(); });

function cargarEdicion(id, bc, cant, sede) {
    $('#id_registro').val(id);
    $('#barcode').val(bc).trigger('change');
    $('#cantidad').val(cant);
    $('#sede').val(sede);
}

function guardar() {
    $.post('', {
        action: 'registrar',
        id_registro: $('#id_registro').val(),
        nit: $('#nit').val(),
        barcode: $('#barcode').val(),
        cantidad: $('#cantidad').val(),
        sede: $('#sede').val()
    }, function(res) {
        if(res.success) { location.reload(); } else { alert(res.message); }
    }, 'json');
}
</script>
</body>
</html>