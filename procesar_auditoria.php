<?php
session_start();
require('Conexion.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemData = explode('|', $_POST['item']);
    $sede = $itemData[0];
    $nro = $itemData[1];
    $barcode = $itemData[2];
    $facturador = $itemData[3];
    $estado = (int)$_POST['estado'];
    $cantidad = (float)$_POST['cantidad'];
    $usuario = $_SESSION['Usuario'];

    if ($estado === 1) {
        $sql = "INSERT INTO auditoria_Pedido (sede, nro_pedido, barcode, facturador, cantidad_facturada, estado_check, usuario_verificador) 
                VALUES (?, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE 
                estado_check = 1, 
                cantidad_facturada = VALUES(cantidad_facturada),
                usuario_verificador = VALUES(usuario_verificador)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssssds", $sede, $nro, $barcode, $facturador, $cantidad, $usuario);
    } else {
        $sql = "DELETE FROM auditoria_Pedido WHERE sede=? AND nro_pedido=? AND barcode=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sss", $sede, $nro, $barcode);
    }

    $res = $stmt->execute();
    echo json_encode(['success' => $res, 'usuario_auditor' => $usuario]);
}
?>