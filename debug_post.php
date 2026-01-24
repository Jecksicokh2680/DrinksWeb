<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php");

$idConteo = 586; // Example ID from previous run
echo "Testing POST action logic for ID: $idConteo\n";

// 1. Fetch conteo
echo "Fetching conteo record...\n";
$stmt = $mysqli->prepare("SELECT CodCat, diferencia, NitEmpresa FROM conteoweb WHERE id=? AND estado='A'");
if (!$stmt) die("Prepare failed for conteoweb: " . $mysqli->error);

$stmt->bind_param("i", $idConteo);
$stmt->execute();
$conteo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$conteo) {
    die("No active conteo record found for ID $idConteo\n");
}
echo "Found conteo. CodCat: " . $conteo['CodCat'] . ", Nit: " . $conteo['NitEmpresa'] . "\n";

$nitFila = trim($conteo['NitEmpresa']);
$CodCat = $conteo['CodCat'];

// 2. Select DB
if ($nitFila === '901724534-7') { 
    $dbDestino = $mysqliDrinks; 
    $nombreDestino = "DRINKS"; 
} else { 
    $dbDestino = $mysqliCentral; 
    $nombreDestino = "CENTRAL"; 
}
echo "Selected DB: $nombreDestino\n";

if ($dbDestino->connect_error) {
    die("Destination DB connection error: " . $dbDestino->connect_error);
}

// 3. Get SKUs
echo "Fetching SKUs...\n";
$skus = [];
$stmt = $mysqli->prepare("SELECT Sku FROM catproductos WHERE CodCat=?");
if (!$stmt) die("Prepare failed for catproductos: " . $mysqli->error);

$stmt->bind_param("s", $CodCat);
$stmt->execute();
$resSKU = $stmt->get_result();
while ($row = $resSKU->fetch_assoc()) $skus[] = $row['Sku'];
$stmt->close();

echo "Found " . count($skus) . " SKUs.\n";

if (!empty($skus)) {
    $ph = implode(",", array_fill(0, count($skus), '?'));
    
    $sql = "SELECT p.idproducto, i.cantidad, i.sincalmacenes 
            FROM productos p 
            INNER JOIN inventario i ON i.idproducto = p.idproducto 
            WHERE p.barcode IN ($ph) AND i.idalmacen = ?";
    
    echo "Preparing destination query ($nombreDestino)...\n";
    // echo "SQL: $sql\n"; // Can be long
    
    $stmt = $dbDestino->prepare($sql);
    if (!$stmt) {
        die("Prepare failed for destination query! Error: " . $dbDestino->error . "\n");
    }
    
    $types = str_repeat("s", count($skus)) . "i";
    $params = array_merge($skus, [1]);
    
    echo "Binding params...\n";
    // For bind_param with variable args in PHP < 8.1 (or simple custom call) we can use ...
    $stmt->bind_param($types, ...$params);
    
    echo "Executing query...\n";
    $success = $stmt->execute();
    if (!$success) {
        die("Execute failed: " . $stmt->error . "\n");
    }
    
    $resProd = $stmt->get_result();
    echo "Query OK. Found products: " . $resProd->num_rows . "\n";
    $stmt->close();
} else {
    echo "No SKUs to check.\n";
}
?>
