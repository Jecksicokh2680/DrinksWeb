<?php
// /home/milo/DrinksWeb/cron_backup.php
require_once("ConnCentral.php");
require_once("ConnDrinks.php");
require_once("Conexion.php");

$prodCat = [];
$resPC = $mysqliWeb->query("SELECT sku, LPAD(CodCat, 4, '0') AS CodCat FROM catproductos");
while ($r = $resPC->fetch_assoc()) {
    $prodCat[$r['sku']] = $r['CodCat'];
}

$fecha_actual = date("Y-m-d");

$sqlAll = "SELECT p.barcode, IFNULL(SUM(i.cantidad),0) as cant 
           FROM productos p LEFT JOIN inventario i ON p.idproducto = i.idproducto 
           WHERE p.estado='1' GROUP BY p.barcode";

$resC_all = $mysqliCentral->query($sqlAll);
$dataC = [];
while($r = $resC_all->fetch_assoc()){ $dataC[$r['barcode']] = $r; }

$resD_all = $mysqliDrinks->query($sqlAll);
$dataD = [];
while($r = $resD_all->fetch_assoc()){ $dataD[$r['barcode']] = $r; }

$todos_barcodes = array_unique(array_merge(array_keys($dataC), array_keys($dataD)));

$sqlUpsert = "INSERT INTO historial_stock 
              (barcode, codcat, stock_central, stock_drinks, stock_total, fecha_registro) 
              VALUES (?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE 
              stock_central = VALUES(stock_central),
              stock_drinks  = VALUES(stock_drinks),
              stock_total   = VALUES(stock_total),
              codcat        = VALUES(codcat)";

$stmtIns = $mysqliWeb->prepare($sqlUpsert);
if (!$stmtIns) {
    exit("Error en prepare: " . $mysqliWeb->error);
}

$total_registros_guardados = 0;
foreach($todos_barcodes as $bc){
    $cat_ins  = $prodCat[$bc] ?? 'SIN';
    $c_ins    = (float)($dataC[$bc]['cant'] ?? 0);
    $d_ins    = (float)($dataD[$bc]['cant'] ?? 0);
    $t_ins    = (float)($c_ins + $d_ins);
    
    $stmtIns->bind_param("ssddds", $bc, $cat_ins, $c_ins, $d_ins, $t_ins, $fecha_actual);
    $stmtIns->execute();
    $total_registros_guardados++;
}

echo "[$fecha_actual 18:00:00] Respaldo automático exitoso. Total procesados: $total_registros_guardados\n";