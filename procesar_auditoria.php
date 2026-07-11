<?php
session_start();
require('Conexion.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_SESSION['Usuario'];

    // 1. Lógica para CAMBIAR ESTADO GLOBAL DEL PEDIDO
    if (isset($_POST['tipo']) && $_POST['tipo'] === 'estado_pedido') {
        $sede = $_POST['sede'];
        $nro = $_POST['nro'];
        $estado = $_POST['estado']; // 'entregado' o 'anulado'

        // Actualizamos todos los registros de ese pedido con el nuevo estado
        $sql = "UPDATE auditoria_Pedido SET estado_pedido = ? WHERE sede = ? AND nro_pedido = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sss", $estado, $sede, $nro);
        $res = $stmt->execute();
        
        echo json_encode(['success' => $res]);
        exit;
    }

    // 2. Lógica para AUDITORÍA DE ITEMS (tu código original ajustado)
    if (isset($_POST['item'])) {
        $itemData = explode('|', $_POST['item']);
        $sede = $itemData[0];
        $nro = $itemData[1];
        $barcode = $itemData[2];
        $facturador = $itemData[3];
        $estado_check = (int)$_POST['estado'];
        
        // Obtenemos la cantidad desde el POST o asumimos 0 si no llega
        $cantidad = (float)($_POST['cantidad'] ?? 0);

        if ($estado_check === 1) {
            $sql = "INSERT INTO auditoria_Pedido (sede, nro_pedido, barcode, facturador, cantidad_facturada, estado_check, usuario_verificador) 
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE 
                    estado_check = 1, 
                    usuario_verificador = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssdss", $sede, $nro, $barcode, $facturador, $cantidad, $usuario, $usuario);
        } else {
            // No borramos, ponemos estado_check en 0 para mantener el registro
            $sql = "UPDATE auditoria_Pedido SET estado_check = 0 WHERE sede=? AND nro_pedido=? AND barcode=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sss", $sede, $nro, $barcode);
        }

        $res = $stmt->execute();
        echo json_encode(['success' => $res, 'usuario_auditor' => $usuario]);
        exit;
    }
}