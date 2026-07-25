<?php
// Configuración de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Ejecutar el script de Python con IMAP
$script_python = __DIR__ . '/procesar_facturas_imap.py'; 
$comando = escapeshellcmd("python3 " . $script_python);
$output = shell_exec($comando);

// 2. Leer los datos desde el archivo JSON local generado por Python
$json_path = __DIR__ . '/facturas.json';
$facturas = [];

if (file_exists($json_path)) {
    $contenido = file_get_contents($json_path);
    $facturas = json_decode($contenido, true) ?: [];
}

// Ordenar por fecha descendente
usort($facturas, function($a, $b) {
    return strcmp($b['issue_date'] ?? '', $a['issue_date'] ?? '');
});
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Facturas IMAP - JSON</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f6f9; color: #333; }
        h1 { color: #2c3e50; }
        .log-box { background: #2d3436; color: #dfe6e9; padding: 15px; border-radius: 5px; font-family: monospace; margin-bottom: 20px; white-space: pre-wrap; max-height: 150px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 3px 6px rgba(0,0,0,0.08); border-radius: 4px; overflow: hidden; }
        th, td { border: 1px solid #dfe6e9; padding: 10px 12px; text-align: left; font-size: 13px; }
        th { background-color: #2c3e50; color: #fff; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .text-right { text-align: right; }
    </style>
</head>
<body>

    <h1>Sincronización y Control de Facturas IMAP</h1>

    <h3>Salida de Ejecución del Script Python:</h3>
    <div class="log-box"><?= htmlspecialchars($output ?: "Sin salida de consola.") ?></div>

    <h3>Últimos Registros Guardados:</h3>
    <table>
        <thead>
            <tr>
                <th>Nro Factura</th>
                <th>Fecha</th>
                <th class="text-right">Valor Factura</th>
                <th>NIT Proveedor</th>
                <th>Vehículo / Ruta</th>
                <th>Observación</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($facturas)): ?>
                <?php foreach(array_slice($facturas, 0, 50) as $row): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['invoice_number'] ?? '') ?></strong></td>
                        <td><?= htmlspecialchars($row['issue_date'] ?? '') ?></td>
                        <td class="text-right">$<?= number_format(($row['ValorFact'] ?? 0) * 1000, 2) ?></td>
                        <td><?= htmlspecialchars($row['nit_proveedor'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['ruta_o_zona'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['observacion'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center;">No hay registros en el archivo local.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>