<?php
// 1. Inclusión exclusiva de las conexiones que contienen la tabla productos
require("ConnCentral.php"); 
require("ConnDrinks.php");  

// Arreglo para consolidar los productos con precio $0 de ambas bases de datos
$alertas_precios_cero = [];

// --- CONSULTA EN BASE CENTRAL ($mysqliCentral) ---
if (isset($mysqliCentral) && !$mysqliCentral->connect_error) {
    $sql_central = "SELECT codigo, barcode, descripcion, 'Central' AS origen 
                    FROM productos 
                    WHERE estado = 1 AND precioventa = 0.00";
    
    if ($res_central = $mysqliCentral->query($sql_central)) {
        while ($row = $res_central->fetch_assoc()) {
            $alertas_precios_cero[] = $row;
        }
    }
}

// --- CONSULTA EN BASE DRINKS ($mysqliDrinks) ---
if (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) {
    $sql_drinks = "SELECT codigo, barcode, descripcion, 'Drinks' AS origen 
                   FROM productos 
                   WHERE estado = 1 AND precioventa = 0.00";
    
    if ($res_drinks = $mysqliDrinks->query($sql_drinks)) {
        while ($row = $res_drinks->fetch_assoc()) {
            $alertas_precios_cero[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría de Precios en Cero</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-danger shadow-sm">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">⚠️ Control de Precios Críticos</span>
            <span class="text-white-50">Total Alertas: <strong><?php echo count($alertas_precios_cero); ?></strong></span>
        </div>
    </nav>

    <div class="container my-5">
        
        <div class="card border-danger shadow">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Productos Activos con Precio Venta = $0</h5>
                <span class="badge bg-danger fs-6"><?php echo count($alertas_precios_cero); ?> Afectados</span>
            </div>
            <div class="card-body bg-white p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th style="width: 15%;">Origen BD</th>
                                <th style="width: 15%;">Código</th>
                                <th style="width: 20%;">Código de Barras</th>
                                <th>Descripción del Producto</th>
                                <th style="width: 15%;" class="text-end text-nowrap">Precio Venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($alertas_precios_cero) > 0): ?>
                                <?php foreach ($alertas_precios_cero as $prod): ?>
                                    <tr>
                                        <td>
                                            <span class="badge <?php echo $prod['origen'] === 'Central' ? 'bg-primary' : 'bg-warning text-dark'; ?> w-100 py-2">
                                                <?php echo $prod['origen']; ?>
                                            </span>
                                        </td>
                                        <td><code><?php echo $prod['codigo']; ?></code></td>
                                        <td><?php echo !empty($prod['barcode']) ? $prod['barcode'] : '<span class="text-muted">N/A</span>'; ?></td>
                                        <td class="fw-semibold text-secondary"><?php echo $prod['descripcion']; ?></td>
                                        <td class="text-end text-danger fw-bold fs-5">$ 0.00</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-success fw-bold py-5 fs-5">
                                        🎉 ¡Todo en orden! No se detectaron productos activos con precio $0 en Central ni en Drinks.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row mt-4 text-center text-muted small">
            <div class="col-6">
                Conexión Central: <?php echo (isset($mysqliCentral) && !$mysqliCentral->connect_error) ? '<span class="text-success">● Conectado (3307)</span>' : '<span class="text-danger">○ Error</span>'; ?>
            </div>
            <div class="col-6">
                Conexión Drinks: <?php echo (isset($mysqliDrinks) && !$mysqliDrinks->connect_error) ? '<span class="text-success">● Conectado (3308)</span>' : '<span class="text-danger">○ Error</span>'; ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>