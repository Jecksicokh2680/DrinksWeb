<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("ConnCentral.php"); 
require_once("ConnDrinks.php");
require_once("Conexion.php");

echo "Testing Connection to BnmaWeb (\$mysqli)...\n";
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
echo "Connection OK.\n";

$sql = "SELECT c.*, cat.Nombre FROM conteoweb c INNER JOIN categorias cat ON cat.CodCat=c.CodCat WHERE c.estado='A' ORDER BY c.id DESC";
echo "Testing Query: $sql\n";

$res = $mysqli->query($sql);

if ($res === false) {
    echo "Query FAILED!\n";
    echo "Error: " . $mysqli->error . "\n";
} else {
    echo "Query OK. Rows: " . $res->num_rows . "\n";
    while($row = $res->fetch_assoc()) {
        echo "Found row: " . $row['id'] . " - " . $row['Nombre'] . "\n";
    }
}
?>
