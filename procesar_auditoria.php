<?php
session_start();
require('Conexion.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Manejo de la auditoría de items (CHECKBOXES)
    if (isset($_POST['item'])) {
        $itemData = explode('|', $_POST['item']);
        $sede = $itemData[0];
        $nro = $itemData[1];
        $barcode = $itemData[2];
        $facturador = $itemData[3];
        $estado = (int)$_POST['estado'];
        $cantidad = (float)$_POST['cantidad'];
        $usuario = $_SESSION['Usuario'] ?? 'Desconocido';

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
        $stmt->execute();
        
        // Devolvemos el nombre del usuario como texto plano para el JavaScript
        echo $usuario;
        exit;
    }

    // Manejo del estado del pedido (ENTREGADO/ANULADO)
    if (isset($_POST['tipo']) && $_POST['tipo'] === 'estado_pedido') {
        $sede = $_POST['sede'];
        $nro = $_POST['nro'];
        $estado = $_POST['estado'];
        
        $sql = "UPDATE auditoria_Pedido SET estado_pedido = ? WHERE sede = ? AND nro_pedido = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sss", $estado, $sede, $nro);
        $stmt->execute();
        exit;
    }
}
?>