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

    $bc    = $mysqliWeb->real_escape_string($_POST['barcode']);
    $nit   = $mysqliWeb->real_escape_string($_POST['nit']);
    $sede  = $mysqliWeb->real_escape_string($_POST['sede']);
    $cant  = floatval($_POST['cantidad']);
    $id_edit = isset($_POST['id_registro']) ? intval($_POST['id_registro']) : 0;

    // 1. Si es edición, anular el anterior
    if ($id_edit > 0) {
        $old = $mysqliWeb->query("SELECT * FROM historial_recibos WHERE id = $id_edit")->fetch_assoc();
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
    
    if ($stmt->execute()) {
        $db = ($sede === 'Central') ? $mysqliCentral : $mysqliDrinks;
        $db->query("INSERT INTO inventario (idproducto, cantidad) VALUES ((SELECT idproducto FROM productos WHERE barcode = '$bc'), $cant) 
                    ON DUPLICATE KEY UPDATE cantidad = cantidad + $cant");
        echo json_encode(['success' => true]);
    }
    exit;
}

// Consultas para la UI
$proveedores = $mysqliWeb->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre");
$productos   = $mysqliCentral->query("SELECT barcode, descripcion FROM productos ORDER BY descripcion ASC");
$historial   = $mysqliWeb->query("SELECT * FROM historial_recibos WHERE estado = 'activo' AND usuario = '$usuario_actual' ORDER BY fecha DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Recibo</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { background: white; padding: 25px; border-radius: 12px; max-width: 600px; margin: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .input-group { margin-bottom: 15px; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; font-size: 0.8em; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .btn-edit { background: #f59e0b; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
<div class="box">
    <h2>📦 Gestión de Recibos</h2>
    <input type="hidden" id="id_registro">
    <div class="input-group">
        <label>Proveedor:</label>
        <select id="nit" class="select2"><?php while($p=$proveedores->fetch_assoc()): ?><option value="<?= $p['CedulaNit'] ?>"><?= $p['Nombre'] ?></option><?php endwhile; ?></select>
    </div>
    <div class="input-group">
        <label>Producto:</label>
        <select id="barcode" class="select2"><?php while($prod=$productos->fetch_assoc()): ?><option value="<?= $prod['barcode'] ?>"><?= $prod['descripcion'] ?></option><?php endwhile; ?></select>
    </div>
    <div class="input-group">
        <label>Cantidad:</label>
        <input type="number" id="cantidad">
    </div>
    <div class="input-group">
        <label>Sede:</label>
        <select id="sede"><option value="Central">Central</option><option value="Drinks">Drinks</option></select>
    </div>
    <button onclick="guardar()">Guardar Registro</button>

    <table id="tablaHistorial">
        <thead><tr><th>Prod</th><th>Cant</th><th>Sede</th><th>Acción</th></tr></thead>
        <tbody>
            <?php while($row = $historial->fetch_assoc()): ?>
            <tr>
                <td><?= $row['barcode'] ?></td>
                <td><?= $row['cantidad'] ?></td>
                <td><?= $row['sede'] ?></td>
                <td><button class="btn-edit" onclick="cargarEdicion(<?= $row['id'] ?>, '<?= $row['barcode'] ?>', <?= $row['cantidad'] ?>, '<?= $row['sede'] ?>')">Editar</button></td>
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
    window.scrollTo(0, 0);
}

function guardar() {
    const data = {
        action: 'registrar',
        id_registro: $('#id_registro').val(),
        nit: $('#nit').val(),
        barcode: $('#barcode').val(),
        cantidad: $('#cantidad').val(),
        sede: $('#sede').val()
    };
    $.post('', data, function(response) {
        if(response.success) {
            alert('Proceso exitoso');
            location.reload();
        }
    }, 'json');
}
</script>
</body>
</html>