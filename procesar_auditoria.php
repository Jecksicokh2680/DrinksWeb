<?php
session_start();
require('Conexion.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_SESSION['Usuario'] ?? 'Desconocido';

    // 1. Auditoría de Items (Checkbox)
    if (isset($_POST['item'])) {
        $data = explode('|', $_POST['item']);
        if ($_POST['estado'] == 1) {
            // Intentamos insertar
            $sql = "INSERT INTO auditoria_Pedido (sede, nro_pedido, barcode, facturador, cantidad_facturada, estado_check, usuario_verificador) 
                    VALUES (?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE estado_check = 1, usuario_verificador = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssdss", $data[0], $data[1], $data[2], $data[3], $_POST['cantidad'], $usuario, $usuario);
            $stmt->execute();
        } else {
            $sql = "DELETE FROM auditoria_Pedido WHERE sede=? AND nro_pedido=? AND barcode=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sss", $data[0], $data[1], $data[2]);
            $stmt->execute();
        }
        echo $usuario;
    }

    // 2. Estado del Pedido (Entregado/Anulado)
    if (isset($_POST['tipo']) && $_POST['tipo'] === 'estado_pedido') {
        // FORMA MÁS SEGURA: Primero intentamos actualizar
        $sql_upd = "UPDATE auditoria_Pedido SET estado_pedido = ? WHERE sede = ? AND nro_pedido = ?";
        $stmt = $mysqli->prepare($sql_upd);
        $stmt->bind_param("sss", $_POST['estado'], $_POST['sede'], $_POST['nro']);
        $stmt->execute();

        // Si no se actualizó ninguna fila, insertamos (esto ocurre si el pedido aún no ha sido auditado)
        if ($stmt->affected_rows === 0) {
            $sql_ins = "INSERT INTO auditoria_Pedido (sede, nro_pedido, estado_pedido) VALUES (?, ?, ?)";
            $stmt_ins = $mysqli->prepare($sql_ins);
            $stmt_ins->bind_param("sss", $_POST['sede'], $_POST['nro'], $_POST['estado']);
            $stmt_ins->execute();
        }
    }
}
?>