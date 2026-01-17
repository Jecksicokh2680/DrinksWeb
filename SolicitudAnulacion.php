<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once 'Conexion.php'; 

/* ============================================================
   ASIGNACIÓN DE VARIABLES DE SESIÓN
============================================================ */
$Usuario     = $_SESSION['Usuario']    ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';
date_default_timezone_set('America/Bogota');
if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("Error: No se detectó una sesión activa válida.");
}

/* ============================================================
   CONEXIÓN DINÁMICA A POS
============================================================ */
function conectarPOS($sede) {
    if ($sede == '002') {
        include 'ConnDrinks.php'; 
        return $mysqliDrinks; 
    } else {
        include 'ConnCentral.php'; 
        return $mysqliPos; 
    }
}

function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $res = $mysqli->query("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit='$User' AND Nro_Auto='$Solicitud'");
    return ($res && $row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

function VerificarAnulacion($NroDoc, $sede) {
    $db = conectarPOS($sede);
    if (!$db) return 0;
    $resP = $db->query("SELECT estado FROM PEDIDOS WHERE numero = '$NroDoc'");
    if ($resP && $rowP = $resP->fetch_assoc()) {
        if ($rowP['estado'] != '0') return 1; 
    }
    $resF = $db->query("SELECT F.numero FROM FACTURAS F INNER JOIN DEVVENTAS D ON F.IDFACTURA = D.IDFACTURA WHERE F.numero = '$NroDoc'");
    return ($resF && $resF->num_rows > 0) ? 1 : 0;
}

/* ============================================================
   PROCESAR ACCIONES
============================================================ */
if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v = str_replace(['$', '.', ','], '', $_POST['ValorAnular']);
    
    // VALIDACIÓN SERVIDOR: No pueden ser iguales
    if ($factura === $reemplazo) {
        header("Location: ?error=mismo_documento"); 
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $fh = date('Y-m-d'); 
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fh, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $_POST['motivo'], $reemplazo);
    $stmt->execute();
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura'])) {
    $factTarget = $_GET['factura'];
    $fechaRef   = $_GET['f'] ?? date('Y-m-d');
    $ahora      = date('Y-m-d H:i:s');
    
    if ($_GET['accion'] == 'bodega' && Autorizacion($Usuario, '0004') === 'SI') {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET JefeBodCheck='1', NitJefeBod=?, FH_JefeBodCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $NroSucursal);
        $stmt->execute();
    }
    
    if ($_GET['accion'] == 'gerencia' && Autorizacion($Usuario, '2010') === 'SI') {
        if (VerificarAnulacion($factTarget, $NroSucursal) == 1) {
            $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET GerenteCheck='1', NitGerente=?, FH_GerenteCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
            $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $NroSucursal);
            $stmt->execute();
        } else {
            header("Location: ?error=no_anulado"); exit;
        }
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
}

$dbSede = conectarPOS($NroSucursal);
$fHoy   = date('Ymd');
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSede = ($NroSucursal == '002') ? 'SEDE DRINKS' : 'SEDE CENTRAL';

$queryDocs = "SELECT NUMERO, VALORTOTAL FROM FACTURAS WHERE ESTADO='0' AND fecha='$fHoy' UNION ALL SELECT NUMERO, VALORTOTAL FROM PEDIDOS WHERE ESTADO='0' AND fecha='$fHoy' ORDER BY NUMERO DESC";
$listaDocs = $dbSede->query($queryDocs);
$docsArray = [];
while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Anulaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.75rem; }
        .header-bar { background-color: #212529; color: #fff; padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        .form-check-input { width: 1.3em; height: 1.3em; cursor: pointer; }
        .badge-wait { font-size: 0.6rem; display: block; color: #dc3545; font-weight: bold; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm">
            <?php 
                if($_GET['error'] == 'mismo_documento') echo "⚠️ <strong>Error:</strong> El documento que reemplaza no puede ser el mismo que se va a anular.";
                if($_GET['error'] == 'no_anulado') echo "⚠️ <strong>Aviso:</strong> Primero debe anular el documento en el POS.";
            ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex">
            <div class="header-info"><small class="text-secondary d-block">EMPRESA</small><span class="fw-bold"><?= $NitEmpresa ?></span></div>
            <div class="header-info"><small class="text-secondary d-block">USUARIO</small><span class="fw-bold"><?= $Usuario ?></span></div>
        </div>
        <div>
            <small class="text-secondary d-block">SEDE: <?= $nombreSede ?></small>
            <?php if($esGerente): ?>
                <select class="form-select form-select-sm" onchange="location.href='?setSede='+this.value">
                    <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                    <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
                </select>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" class="card p-3 shadow-sm border-0 mb-4" id="formAnulacion">
        <input type="hidden" name="FactAnular" id="FactAnular">
        <input type="hidden" name="ValorAnular" id="ValorAnular">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="small fw-bold">Documento a Anular</label>
                <select class="form-select form-select-sm" id="selAnular" required>
                    <option value="">-- Seleccione --</option>
                    <?php foreach($docsArray as $d): ?>
                        <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>">
                            <?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Documento Reemplaza</label>
                <select name="NroFactReemplaza" id="selReemplaza" class="form-select form-select-sm" required>
                    <option value="">-- Seleccione --</option>
                    <?php foreach($docsArray as $d): ?>
                        <option value="<?= $d['NUMERO'] ?>">
                            <?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="small fw-bold">Motivo</label>
                <input type="text" name="motivo" class="form-control form-control-sm" required placeholder="Motivo...">
            </div>
            <div class="col-md-2 align-self-end">
                <button type="submit" name="grabar" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm">GRABAR</button>
            </div>
        </div>
    </form>

    <div class="table-responsive shadow-sm bg-white rounded">
        <table class="table table-hover table-xs align-middle text-center mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Hora</th>
                    <th>Sede</th>
                    <th>Doc. Anular</th>
                    <th>Valor</th>
                    <th class="table-warning text-dark">Fact. Reemplaza</th>
                    <th>Motivo</th>
                    <th>Bodega</th>
                    <th>Gerencia</th>
                    <th>Estado POS</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $fH = date('Y-m-d');
                $resH = $mysqli->query("SELECT * FROM solicitud_anulacion WHERE F_Creacion = '$fH' AND NroSucursal = '$NroSucursal' ORDER BY FH_CajeroCheck DESC");
                while ($r = $resH->fetch_assoc()): 
                    $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $NroSucursal);
                ?>
                <tr>
                    <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                    <td><?= $r['NroSucursal'] ?></td>
                    <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                    <td class="text-end fw-bold">$<?= number_format($r['ValorFactAnular'], 0) ?></td>
                    <td class="fw-bold text-primary bg-light"><?= $r['NroFactReemplaza'] ?></td>
                    <td class="text-start"><?= $r['MotivoAnulacion'] ?></td>
                    <td>
                        <input class="form-check-input" type="checkbox" 
                            <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : 
                                (($esBodega) ? 'onclick="confirmar(\'bodega\', \''.$r['NroFactAnular'].'\')"' : 'disabled') ?>>
                    </td>
                    <td>
                        <?php if ($r['GerenteCheck'] == '1'): ?>
                            <input class="form-check-input" type="checkbox" checked disabled>
                        <?php elseif ($esGerente && $anuladoPOS == 1): ?>
                            <input class="form-check-input border-primary" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>')">
                        <?php else: ?>
                            <input class="form-check-input" type="checkbox" disabled>
                            <?php if($esGerente && $anuladoPOS == 0): ?>
                                <span class="badge-wait">ESPERANDO POS</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= ($anuladoPOS) ? 'bg-success' : 'bg-secondary' ?>">
                            <?= ($anuladoPOS) ? 'ANULADO OK' : 'ACTIVO' ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const selAnular = document.getElementById('selAnular');
    const selReemplaza = document.getElementById('selReemplaza');

    // Validación al cambiar el documento a anular
    selAnular.addEventListener('change', function() {
        const o = this.options[this.selectedIndex];
        document.getElementById('FactAnular').value = o.value;
        document.getElementById('ValorAnular').value = o.dataset.valor || '0';
        
        // Si ya hay algo en reemplaza, validar que no sean iguales
        if (selReemplaza.value === o.value && o.value !== "") {
            alert("⚠️ El documento a anular no puede ser el mismo que el de reemplazo.");
            this.value = "";
            document.getElementById('FactAnular').value = "";
        }
    });

    // Validación al cambiar el documento de reemplazo
    selReemplaza.addEventListener('change', function() {
        if (this.value === selAnular.value && this.value !== "") {
            alert("⚠️ El documento que reemplaza no puede ser el mismo que se va a anular.");
            this.value = "";
        }
    });

    function confirmar(tipo, fact) {
        if (confirm('¿Autorizar este movimiento?')) {
            window.location.href = `?accion=${tipo}&factura=${fact}`;
        } else {
            event.target.checked = false;
        }
    }
</script>
</body>
</html>