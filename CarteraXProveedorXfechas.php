<?php
require 'Conexion.php';
session_start();
if (empty($_SESSION['Usuario'])) { header("Location: Login.php"); exit; }
date_default_timezone_set('America/Bogota');

// -------------------------------------------------
// VARIABLES Y FILTROS
// -------------------------------------------------
$nit = $_GET['proveedor'] ?? '';
$tipo_filtro = $_GET['tipo_filtro'] ?? 'TODOS';
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$filtro_sql = "";

if ($nit && isset($_GET['consultar'])) {
    $f_ini_clean = str_replace('-', '', $f_inicio);
    $f_fin_clean = str_replace('-', '', $f_fin);
    $filtro_sql = " AND F_Creacion BETWEEN '$f_ini_clean' AND '$f_fin_clean'";
    if ($tipo_filtro !== 'TODOS') $filtro_sql .= " AND TipoMonto = '$tipo_filtro'";
}

// -------------------------------------------------
// ACCIONES (Exportar, Grabar, Editar, Borrar)
// -------------------------------------------------
if (isset($_GET['exportar']) && $nit) {
    header("Content-Type: application/vnd.ms-excel; charset=iso-8859-1");
    header("Content-Disposition: attachment; filename=Reporte_Cartera_$nit.xls");
    $resExp = $mysqli->query("SELECT * FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' $filtro_sql ORDER BY F_Creacion DESC");
    echo "<table border='1'><tr><th>Fecha</th><th>Hora</th><th>Tipo</th><th>Monto</th><th>Descripción</th></tr>";
    while ($row = $resExp->fetch_assoc()) {
        echo "<tr><td>{$row['F_Creacion']}</td><td>{$row['H_Creacion']}</td><td>{$row['TipoMonto']}</td><td>{$row['Monto']}</td><td>".utf8_decode($row['Descripcion'])."</td></tr>";
    }
    echo "</table>"; exit;
}

if (isset($_POST['grabar'])) {
    $m = floatval(str_replace('.', '', $_POST['monto'])) * ($_POST['tipo'] === 'F' ? -1 : 1);
    $mysqli->query("INSERT INTO pagosproveedores (Nit,F_Creacion,H_Creacion,Monto,TipoMonto,Descripcion,Estado) VALUES ('".$_POST['proveedor']."','".str_replace('-', '', $_POST['fecha'])."','".date("H:i:s")."','$m','".$_POST['tipo']."','".$mysqli->real_escape_string(strtoupper($_POST['descripcion']))."','1')");
    header("Location: ?proveedor=".$_POST['proveedor']."&f_inicio=$f_inicio&f_fin=$f_fin&tipo_filtro=$tipo_filtro&consultar=1"); exit;
}

if (isset($_POST['editar'])) {
    $m = floatval(str_replace('.', '', $_POST['monto'])) * ($_POST['tipo'] === 'F' ? -1 : 1);
    $mysqli->query("UPDATE pagosproveedores SET Monto='$m', TipoMonto='".$_POST['tipo']."', Descripcion='".$mysqli->real_escape_string(strtoupper($_POST['descripcion']))."' WHERE Nit='".$_POST['nit']."' AND F_Creacion='".$_POST['fecha']."' AND H_Creacion='".$_POST['hora']."'");
    header("Location: ?proveedor=".$_POST['nit']."&f_inicio=$f_inicio&f_fin=$f_fin&tipo_filtro=$tipo_filtro&consultar=1"); exit;
}

if (isset($_GET['borrar'])) {
    $mysqli->query("UPDATE pagosproveedores SET Estado='0' WHERE Nit='".$_GET['nit']."' AND F_Creacion='".$_GET['f']."' AND H_Creacion='".$_GET['h']."'");
    header("Location: ?proveedor=".$_GET['nit']."&f_inicio=$f_inicio&f_fin=$f_fin&tipo_filtro=$tipo_filtro&consultar=1"); exit;
}

$proveedores = $mysqli->query("SELECT CedulaNit AS Nit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre");
$abonos = []; $saldo_total = 0;
if ($nit && isset($_GET['consultar'])) {
    $res = $mysqli->query("SELECT * FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' $filtro_sql ORDER BY F_Creacion DESC, H_Creacion DESC");
    while ($r = $res->fetch_assoc()) { $abonos[] = $r; $saldo_total += $r['Monto']; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartera Proveedores</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 10px; }
        .box { background: #fff; padding: 15px; border-radius: 8px; max-width: 1000px; margin: auto; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; align-items: center; }
        .row input, .row select, .row button { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .table-container { width: 100%; overflow-x: auto; }
        table { width: 100%; min-width: 600px; border-collapse: collapse; }
        th { background: #007bff; color: #fff; padding: 10px; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        .btn-excel { background: #198754; color: #fff; border: none; cursor: pointer; }
        .btn-print { background: #6c757d; color: #fff; border: none; cursor: pointer; }
        @media print { .row, .no-print { display: none !important; } .box { box-shadow: none; width: 100%; } }
    </style>
</head>
<body>
<div class="box">
    <h2>Cartera por Proveedor</h2>
    <form method="GET" class="row">
        <select name="proveedor" style="flex: 2; min-width: 150px;" required>
            <option value="">Seleccione Proveedor</option>
            <?php while($p=$proveedores->fetch_assoc()): ?>
                <option value="<?=$p['Nit']?>" <?=$p['Nit']==$nit?'selected':''?>><?=$p['Nombre']?></option>
            <?php endwhile; ?>
        </select>
        <input type="date" name="f_inicio" value="<?=$f_inicio?>" style="flex: 1;">
        <input type="date" name="f_fin" value="<?=$f_fin?>" style="flex: 1;">
        <select name="tipo_filtro" style="flex: 1;">
            <option value="TODOS" <?=$tipo_filtro=='TODOS'?'selected':''?>>Todos</option>
            <option value="F" <?=$tipo_filtro=='F'?'selected':''?>>Facturas</option>
            <option value="P" <?=$tipo_filtro=='P'?'selected':''?>>Pagos</option>
        </select>
        <button name="consultar" type="submit">Consultar</button>
        <?php if($nit): ?>
            <button type="submit" name="exportar" value="1" class="btn-excel">Excel</button>
            <button type="button" class="btn-print" onclick="window.print()">Imprimir</button>
        <?php endif; ?>
    </form>

    <?php if($nit): ?>
    <div class="table-container">
        <table>
            <tr><th>Fecha</th><th>Hora</th><th>Tipo</th><th>Monto</th><th>Descripción</th><th class="no-print">Acciones</th></tr>
            <?php foreach($abonos as $a): ?>
            <form method="POST">
                <tr>
                    <td><?=$a['F_Creacion']?></td><td><?=$a['H_Creacion']?></td>
                    <td><select name="tipo"><option value="F" <?=$a['TipoMonto']=='F'?'selected':''?>>F</option><option value="P" <?=$a['TipoMonto']=='P'?'selected':''?>>P</option></select></td>
                    <td><input name="monto" value="<?=number_format(abs($a['Monto']),0,'','')?>"></td>
                    <td><input name="descripcion" value="<?=$a['Descripcion']?>"></td>
                    <td class="no-print">
                        <input type="hidden" name="nit" value="<?=$a['Nit']?>">
                        <input type="hidden" name="fecha" value="<?=$a['F_Creacion']?>">
                        <input type="hidden" name="hora" value="<?=$a['H_Creacion']?>">
                        <button name="editar">💾</button>
                        <a href="?proveedor=<?=$nit?>&f_inicio=<?=$f_inicio?>&f_fin=<?=$f_fin?>&tipo_filtro=<?=$tipo_filtro?>&consultar=1&borrar=1&nit=<?=$a['Nit']?>&f=<?=$a['F_Creacion']?>&h=<?=$a['H_Creacion']?>" onclick="return confirm('¿Borrar?')">🗑️</a>
                    </td>
                </tr>
            </form>
            <?php endforeach; ?>
            <tr style="background:#eee; font-weight:bold;"><td colspan="3" style="text-align:right">SALDO TOTAL:</td><td colspan="3"><?=number_format($saldo_total, 0, ',', '.')?></td></tr>
        </table>
    </div>
    <?php endif; ?>
</div>
</body>
</html>