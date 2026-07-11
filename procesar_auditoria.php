<?php
require('Conexion.php');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item'])) {
    $item = $_POST['item'];
    $estado = $_POST['estado'];
    $usuario = $_SESSION['Usuario'];

    // Desglosar
    list($sede, $nro, $barcode, $facturador) = explode('|', $item);

    if ($estado == 1) {
        $sql = "INSERT IGNORE INTO auditoria_Pedido 
                (sede, nro_pedido, barcode, facturador, estado_check, usuario_verificador) 
                VALUES (?, ?, ?, ?, 1, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssss", $sede, $nro, $barcode, $facturador, $usuario);
    } else {
        // Si desmarca, borramos el registro (para mantener sincronía)
        $sql = "DELETE FROM auditoria_Pedido 
                WHERE sede=? AND nro_pedido=? AND barcode=?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sss", $sede, $nro, $barcode);
    }

    $resultado = $stmt->execute();
    echo json_encode(['success' => $resultado]);
    exit;
}
?>