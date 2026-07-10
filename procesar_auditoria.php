<?php
// procesar_auditoria.php
require('Conexion.php'); // O la conexión donde esté alojada la tabla auditoria_Pedido
session_start();

$UsuarioSesion = $_SESSION['Usuario'] ?? 'Sistema';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['productos'])) {
    
    // Si estamos en celular, filtramos para guardar únicamente los productos del documento activo en pantalla
    $pedidoCelular = $_POST['pedido_activo_celular'] ?? '';

    // Preparamos la consulta con duplicados controlados por tu UNIQUE KEY (sede, nro_pedido, barcode)
    $sql = "INSERT INTO auditoria_Pedido 
            (sede, nro_pedido, facturador, barcode, cantidad_facturada, cantidad_auditada, estado_check, usuario_verificador, observaciones) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                cantidad_auditada = VALUES(cantidad_auditada),
                estado_check = VALUES(estado_check),
                usuario_verificador = VALUES(usuario_verificador),
                facturador = VALUES(facturador)";
                
    $stmt = $mysqli->prepare($sql);

    if ($stmt) {
        foreach ($_POST['productos'] as $prod) {
            
            // Si la navegación móvil está activa y el producto no pertenece al pedido en pantalla, lo saltamos
            if (!empty($pedidoCelular) && $prod['nro_pedido'] !== $pedidoCelular) {
                continue; 
            }

            $sede = $prod['sede'];
            $nro_pedido = $prod['nro_pedido'];
            $facturador = $prod['facturador'];
            $barcode = $prod['barcode'];
            $cant_fac = floatval($prod['cantidad_facturada']);
            
            // Evaluamos si el checkbox vino marcado o no
            $isChecked = isset($prod['checked']) ? 1 : 0;
            
            // Definición de negocio: Si está checkeado, lo auditado es igual a lo facturado. Si no, es 0.
            $cant_aud = $isChecked ? $cant_fac : 0.00;
            $observaciones = ""; 

            $stmt->bind_param(
                "ssssddiss", 
                $sede, 
                $nro_pedido, 
                $facturador, 
                $barcode, 
                $cant_fac, 
                $cant_aud, 
                $isChecked, 
                $UsuarioSesion, 
                $observaciones
            );
            $stmt->execute();
        }
        $stmt->close();
    }
    
    // Redirecciona de vuelta a la página de auditorías manteniendo los filtros si se requiere
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
} else {
    echo "No se recibieron datos para procesar.";
}
?>