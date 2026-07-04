<?php
// nequi-webhook.php

// 1. Capturar los datos crudos enviados por Nequi (vienen en formato JSON)
$jsonRecibido = file_get_contents('php://input');
$datos = json_decode($jsonRecibido, true);

if (!$datos) {
    http_response_code(400);
    echo "Petición inválida";
    exit();
}

// 2. Extraer las variables clave del pago (La estructura exacta depende de si usas API directa o pasarela)
// Para este ejemplo asumimos la respuesta estándar de éxito de Nequi/Wompi:
$referenciaPago = $datos['data']['reference'] ?? $datos['reference1'] ?? null;
$estadoPago = $datos['data']['status'] ?? $datos['status'] ?? null; // Ej: "APPROVED", "SUCCESS"

// 3. Validar si el pago realmente pasó
if ($estadoPago === "APPROVED" || $estadoPago === "SUCCESS") {
    
    // CONEXIÓN A TU BASE DE DATOS
    // Aquí buscas el pedido usando la $referenciaPago
    // Ejemplo lógico:
    // UPDATE pedidos SET estado = 'Pagado' WHERE referencia = '$referenciaPago';
    
    // Responder a Nequi con un código 200 (OK) para decirle que recibiste el aviso
    http_response_code(200);
    echo json_encode(["status" => "received"]);
    
} else {
    // Si el pago falló o fue rechazado
    // UPDATE pedidos SET estado = 'Fallido' WHERE referencia = '$referenciaPago';
    
    http_response_code(200); // Igual respondes 200 para que Nequi no siga reintentando
}