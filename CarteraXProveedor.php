<?php
require 'Conexion.php';
session_start();

// -------------------------------------------------
// VALIDAR SESIÓN
// -------------------------------------------------
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}
date_default_timezone_set('America/Bogota');

// -------------------------------------------------
// LÓGICA AJAX: GUARDADO AUTOMÁTICO DESDE LA TABLA
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_save') {
    header('Content-Type: application/json');
    
    $nit   = $mysqli->real_escape_string($_POST['nit']);
    $fecha = $mysqli->real_escape_string($_POST['fecha']);
    $hora  = $mysqli->real_escape_string($_POST['hora']);
    
    $tipo     = $mysqli->real_escape_string($_POST['tipo']);
    $monto    = floatval(str_replace('.', '', $_POST['monto']));
    $num_fact = strtoupper($mysqli->real_escape_string($_POST['numfact_proveedor']));
    $id_pago  = intval($_POST['id_pago']);
    $desc     = strtoupper($mysqli->real_escape_string($_POST['descripcion']));

    if ($tipo === 'F') $monto *= -1;

    $update = $mysqli->query("
        UPDATE pagosproveedores
        SET Monto='$monto',
            TipoMonto='$tipo',
            Descripcion='$desc',
            NumfactProveedor='$num_fact',
            idPago='$id_pago'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    echo json_encode(['success' => $update]);
    exit;
}

// -------------------------------------------------
// ACCIÓN: EXPORTAR A EXCEL
// -------------------------------------------------
if (isset($_GET['consultar'], $_GET['proveedor'], $_GET['exportar']) && $_GET['exportar'] == 1) {
    $nit = $mysqli->real_escape_string($_GET['proveedor']);
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Reporte_Proveedor_$nit.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $resExcel = $mysqli->query("
        SELECT F_Creacion, H_Creacion, TipoMonto, Monto, Descripcion, NumfactProveedor, idPago 
        FROM pagosproveedores 
        WHERE Nit='$nit' AND Estado='1' 
        ORDER BY F_Creacion DESC, H_Creacion DESC
    ");
    ?>
    <table border="1">
        <thead>
            <tr style="background-color: #007bff; color: #ffffff; font-weight: bold;">
                <th>Fecha</th>
                <th>Hora</th>
                <th>Tipo</th>
                <th>Monto</th>
                <th>Nro Factura Prov</th>
                <th>Revisado (idPago)</th>
                <th>Descripcion</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($r = $resExcel->fetch_assoc()): ?>
                <tr>
                    <td><?= $r['F_Creacion'] ?></td>
                    <td><?= $r['H_Creacion'] ?></td>
                    <td><?= $r['TipoMonto'] == 'F' ? 'Factura' : 'Pago' ?></td>
                    <td><?= $r['Monto'] ?></td>
                    <td><?= htmlspecialchars($r['NumfactProveedor']) ?></td>
                    <td><?= $r['idPago'] == 1 ? 'SI' : 'NO' ?></td>
                    <td><?= htmlspecialchars($r['Descripcion']) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <?php
    exit; 
}

// -------------------------------------------------
// PROVEEDORES
// -------------------------------------------------
$proveedores = $mysqli->query("
    SELECT CedulaNit AS Nit, Nombre
    FROM terceros
    WHERE Estado = 1
    ORDER BY Nombre
");

// -------------------------------------------------
// INSERTAR NUEVO REGISTRO
// -------------------------------------------------
if (isset($_POST['grabar'])) {
    $nit   = $_POST['proveedor'];
    $fecha = str_replace('-', '', $_POST['fecha']);
    $hora  = date("H:i:s");
    $tipo  = $_POST['tipo'];
    $monto = floatval(str_replace('.', '', $_POST['monto']));
    $desc  = strtoupper($mysqli->real_escape_string($_POST['descripcion']));
    $num_fact = strtoupper($mysqli->real_escape_string($_POST['numfact_proveedor']));
    $id_pago  = isset($_POST['id_pago']) ? 1 : 0;

    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("
        INSERT INTO pagosproveedores
        (Nit,F_Creacion,H_Creacion,Monto,TipoMonto,Descripcion,NumfactProveedor,idPago,Estado)
        VALUES
        ('$nit','$fecha','$hora','$monto','$tipo','$desc','$num_fact','$id_pago','1')
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// BORRAR (LÓGICO)
// -------------------------------------------------
if (isset($_GET['borrar'])) {
    $nit   = $_GET['nit'];
    $fecha = $_GET['f'];
    $hora  = $_GET['h'];

    $mysqli->query("
        UPDATE pagosproveedores
        SET Estado='0'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// PAGINACIÓN Y CONSULTA (AJUSTADO A 15 LÍNEAS)
// -------------------------------------------------
$porPagina = 13; // <--- AQUÍ SE MODIFICÓ A 15
$pagina = max(1, intval($_GET['page'] ?? 1));
$offset = ($pagina - 1) * $porPagina;
$abonos = [];
$total = 0;
$nit = '';

if (isset($_GET['consultar'], $_GET['proveedor'])) {
    $nit = $_GET['proveedor'];
    $resTotal = $mysqli->query("SELECT IFNULL(SUM(Monto),0) total, COUNT(*) cant FROM pagosproveedores WHERE Nit='$nit' AND Estado='1'");
    $rowT = $resTotal->fetch_assoc();
    $total = $rowT['total'];
    $totalPaginas = max(1, ceil($rowT['cant'] / $porPagina));

    $res = $mysqli->query("SELECT * FROM pagosproveedores WHERE Nit='$nit' AND Estado='1' ORDER BY F_Creacion DESC, H_Creacion DESC LIMIT $offset,$porPagina");
    while ($r = $res->fetch_assoc()) { $abonos[] = $r; }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Cartera Proveedores</title>
<style>
body{font-family:Arial;background:#f4f4f4}
.box{background:#fff;padding:15px;border-radius:10px;max-width:1250px;margin:auto}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center}
.row input,.row select,.row button{padding:7px;border-radius:6px;border:1px solid #ccc}
table{width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed;}
th{background:#007bff;color:#fff;padding:8px;font-size:14px}
td{padding:6px;border-bottom:1px solid #ddd; text-align:center;vertical-align:middle;}
.neg{background:#fde2e2}
.pos{background:#e6fffa}
.total{background:#eef;padding:8px;border-radius:6px;margin:10px 0;font-weight:bold}
a{text-decoration:none;font-weight:bold}
.btn-del{color:#dc3545;font-size:16px;}
.paginacion{text-align:center;margin-top:10px}

table input, table select {
    width: 100%;
    box-sizing: border-box;
    padding: 5px;
    border: 1px solid #ccc;
    border-radius: 4px;
}
.saving { background-color: #ffeeba !important; transition: background 0.3s; }

/* SWITCH */
.switch { position: relative; display: inline-block; width: 34px; height: 18px; vertical-align: middle; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 18px; }
.slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
input:checked + .slider { background-color: #2196F3; }
input:checked + .slider:before { transform: translateX(16px); }

.btn-excel { background-color: #198754; color: white !important; padding: 6px 12px; border-radius: 6px; font-size: 14px; display: inline-block; }
.btn-excel:hover { background-color: #146c43; }
</style>
</head>
<body>

<div class="box">
<h2>Cartera por Proveedor</h2>

<form method="GET" class="row">
<select name="proveedor" required>
<option value="">Proveedor</option>
<?php while($p=$proveedores->fetch_assoc()): ?>
<option value="<?= $p['Nit'] ?>" <?= $p['Nit']==$nit?'selected':'' ?>>
<?= $p['Nombre'] ?>
</option>
<?php endwhile; ?>
</select>
<button name="consultar">Consultar</button>
</form>

<?php if($nit): ?>
<form method="POST" class="row">
<input type="hidden" name="proveedor" value="<?= $nit ?>">
<input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
<select name="tipo">
<option value="F">Factura</option>
<option value="P">Pago</option>
</select>
<input name="monto" class="monto-mask" placeholder="Monto" required style="width:120px;">
<input name="numfact_proveedor" placeholder="Nro Factura" style="width:130px;">
<input name="descripcion" placeholder="Descripción" style="flex:1; min-width:180px;">
<label style="display:flex; align-items:center; gap:5px; cursor:pointer; font-size:14px;">
    <input type="checkbox" name="id_pago" value="1"> ¿Revisado?
</label>
<button name="grabar">Grabar</button>
</form>
<?php endif; ?>

<?php if($abonos): ?>
<div class="total" style="display: flex; justify-content: space-between; align-items: center;">
    <span>Saldo Pte: <?= number_format($total,0,',','.') ?> (Recarga la página para actualizar saldo global)</span>
    <a href="?proveedor=<?= $nit ?>&consultar=1&exportar=1" class="btn-excel">📊 Exportar a Excel</a>
</div>

<table>
<colgroup>
    <col style="width: 10%;">
    <col style="width: 9%;">
    <col style="width: 8%;">
    <col style="width: 14%;">
    <col style="width: 14%;">
    <col style="width: 8%;">
    <col style="width: 32%;">
    <col style="width: 5%;">
</colgroup>
<tr>
<th>Fecha</th>
<th>Hora</th>
<th>Tipo</th>
<th>Monto</th>
<th>Nro Factura Prov</th>
<th>idPago</th>
<th>Descripción</th>
<th></th>
</tr>

<?php foreach($abonos as $a): 
    $rowId = "row_" . $a['F_Creacion'] . "_" . str_replace(':', '', $a['H_Creacion']);
?>
<tr id="<?= $rowId ?>" class="<?= $a['Monto']<0?'neg':'pos' ?>">
<td><?= $a['F_Creacion'] ?></td>
<td><?= $a['H_Creacion'] ?></td>
<td>
    <select class="auto-input" data-field="tipo" data-row="<?= $rowId ?>">
        <option value="F" <?= $a['TipoMonto']=='F'?'selected':'' ?>>F</option>
        <option value="P" <?= $a['TipoMonto']=='P'?'selected':'' ?>>P</option>
    </select>
</td>
<td>
    <input class="monto-mask auto-input" data-field="monto" data-row="<?= $rowId ?>" style="text-align:right; font-weight:bold;"
           value="<?= number_format(abs($a['Monto']),0,',','.') ?>">
</td>
<td>
    <input class="auto-input" data-field="numfact_proveedor" style="text-align:center;"
           value="<?= htmlspecialchars($a['NumfactProveedor'] ?? '') ?>">
</td>
<td>
    <label class="switch">
        <input type="checkbox" class="auto-input" data-field="id_pago" data-row="<?= $rowId ?>" value="1" <?= $a['idPago']==1?'checked':'' ?>>
        <span class="slider"></span>
    </label>
</td>
<td>
    <input class="auto-input" data-field="descripcion" style="text-align:left;"
           value="<?= htmlspecialchars($a['Descripcion']) ?>">
</td>
<td>
    <input type="hidden" class="key-nit" value="<?= $a['Nit'] ?>">
    <input type="hidden" class="key-fecha" value="<?= $a['F_Creacion'] ?>">
    <input type="hidden" class="key-hora" value="<?= $a['H_Creacion'] ?>">
    
    <a class="btn-del"
       href="?proveedor=<?= $nit ?>&consultar=1&borrar=1&nit=<?= $a['Nit'] ?>&f=<?= $a['F_Creacion'] ?>&h=<?= $a['H_Creacion'] ?>"
       onclick="return confirm('¿Eliminar este registro?')">🗑️</a>
</td>
</tr>
<?php endforeach; ?>
</table>

<div class="paginacion">
<?php if($pagina>1): ?>
<a href="?proveedor=<?= $nit ?>&consultar=1&page=<?= $pagina-1 ?>">⬅</a>
<?php endif; ?>
 Página <?= $pagina ?>
<?php if($pagina<$totalPaginas): ?>
<a href="?proveedor=<?= $nit ?>&consultar=1&page=<?= $pagina+1 ?>">➡</a>
<?php endif; ?>
</div>

<?php endif; ?>

</div>

<script>
// MÁSCARA DE MILES
function aplicarMascara(input) {
    let v = input.value.replace(/\D/g,'');
    input.value = v.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
}

document.querySelectorAll('.monto-mask').forEach(input => {
    input.addEventListener('input', () => aplicarMascara(input));
});

// ESCUCHAR CAMBIOS PARA GUARDADO AUTOMÁTICO
document.querySelectorAll('.auto-input').forEach(input => {
    input.addEventListener('change', function() {
        const tr = this.closest('tr');
        tr.classList.add('saving');

        const nit = tr.querySelector('.key-nit').value;
        const fecha = tr.querySelector('.key-fecha').value;
        const hora = tr.querySelector('.key-hora').value;
        
        const tipo = tr.querySelector('[data-field="tipo"]').value;
        const monto = tr.querySelector('[data-field="monto"]').value;
        const numfact = tr.querySelector('[data-field="numfact_proveedor"]').value;
        const idPago = tr.querySelector('[data-field="id_pago"]').checked ? 1 : 0;
        const descripcion = tr.querySelector('[data-field="descripcion"]').value;

        const formData = new FormData();
        formData.append('action', 'auto_save');
        formData.append('nit', nit);
        formData.append('fecha', fecha);
        formData.append('hora', hora);
        formData.append('tipo', tipo);
        formData.append('monto', monto);
        formData.append('numfact_proveedor', numfact);
        formData.append('id_pago', idPago);
        formData.append('descripcion', descripcion);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            tr.classList.remove('saving');
            if(!data.success) {
                alert('Error al guardar automáticamente los cambios.');
            } else {
                if(tipo === 'F') {
                    tr.className = 'neg';
                } else {
                    tr.className = 'pos';
                }
            }
        })
        .catch(err => {
            tr.classList.remove('saving');
            alert('Error de conexión.');
        });
    });
});
</script>

</body>
</html>