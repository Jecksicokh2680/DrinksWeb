<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once 'Conexion.php'; 

/* ============================================================
   ASIGNACIÓN DE VARIABLES DE SESIÓN (Basado en tus parámetros)
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa']  ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';

// Cambio de sede por parte del Gerente
if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: " . strtok($_SERVER["PHP_SELF"], '?'));
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
   PROCESAR ACCIONES (Grabar, Bodega, Gerencia)
============================================================ */
if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v = str_replace(['$', '.', ','], '', $_POST['ValorAnular']);
    
    if ($factura === $reemplazo) {
        header("Location: ?error=mismo_documento"); exit;
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
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

// Datos para la vista
$dbSede = conectarPOS($NroSucursal);
$fHoy   = date('Ymd');
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSede = ($NroSucursal == '002') ? 'SEDE DRINKS' : 'SEDE CENTRAL';

$queryDocs = "SELECT NUMERO, VALORTOTAL FROM FACTURAS WHERE ESTADO='0' AND fecha='$fHoy' UNION ALL SELECT NUMERO, VALORTOTAL FROM PEDIDOS WHERE ESTADO='0' AND fecha='$fHoy' ORDER BY NUMERO DESC";
$listaDocs = $dbSede->query($queryDocs);
$docsArray = [];
if($listaDocs) while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Anulaciones - <?= $nombreSede ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.75rem; }
        .header-bar { background: linear-gradient(90deg, #212529 0%, #343a40 100%); color: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        .badge-wait { font-size: 0.65rem; color: #dc3545; font-weight: bold; display: block; margin-top: 2px; }
        .search-box { max-width: 300px; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid px-4 py-3">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <?php 
                if($_GET['error'] == 'mismo_documento') echo "⚠️ El documento de reemplazo no puede ser igual al anulado.";
                if($_GET['error'] == 'no_anulado') echo "⚠️ Primero debes anular el documento en el POS físico.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info text-center">
                <small class="text-secondary d-block">USUARIO</small>
                <span class="fw-bold"><?= $Usuario ?></span>
            </div>
            <div class="header-info text-center">
                <small class="text-secondary d-block">SUCURSAL ACTUAL</small>
                <span class="badge bg-primary"><?= $nombreSede ?></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <?php if($esGerente): ?>
                <div>
                    <label class="small text-secondary d-block text-end">CAMBIAR SEDE:</label>
                    <select class="form-select form-select-sm" onchange="location.href='?setSede='+this.value">
                        <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                        <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
                    </select>
                </div>
            <?php endif; ?>
            <button class="btn btn-outline-light btn-sm mt-3" onclick="location.reload()">🔄</button>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white fw-bold">Nueva Solicitud de Anulación</div>
        <div class="card-body">
            <form method="post" id="formAnulacion">
                <input type="hidden" name="FactAnular" id="FactAnular">
                <input type="hidden" name="ValorAnular" id="ValorAnular">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">1. Documento a Anular</label>
                        <select class="form-select form-select-sm select-search" id="selAnular" required>
                            <option value="">-- Seleccione factura --</option>
                            <?php foreach($docsArray as $d): ?>
                                <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>">
                                    <?= $d['NUMERO'] ?> | $<?= number_format($d['VALORTOTAL'],0) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">2. Documento que Reemplaza</label>
                        <select name="NroFactReemplaza" id="selReemplaza" class="form-select form-select-sm" required>
                            <option value="">-- Seleccione factura --</option>
                            <?php foreach($docsArray as $d): ?>
                                <option value="<?= $d['NUMERO'] ?>">
                                    <?= $d['NUMERO'] ?> | $<?= number_format($d['VALORTOTAL'],0) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">3. Motivo Detallado</label>
                        <input type="text" name="motivo" class="form-control form-control-sm" required placeholder="Ej: Error en medio de pago">
                    </div>
                    <div class="col-md-2 align-self-end">
                        <button type="submit" name="grabar" class="btn btn-dark btn-sm w-100 fw-bold">REGISTRAR</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <span class="fw-bold text-uppercase">Solicitudes del Día - <?= $nombreSede ?></span>
            <input type="text" id="tablaFiltro" class="form-control form-control-sm search-box" placeholder="🔍 Buscar por factura o motivo...">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0" id="tablaDatos">
                <thead class="table-light">
                    <tr>
                        <th>HORA</th>
                        <th>FACT. ANULAR</th>
                        <th>VALOR</th>
                        <th class="table-warning">FACT. REEMPLAZA</th>
                        <th>MOTIVO</th>
                        <th>BODEGA</th>
                        <th>GERENCIA</th>
                        <th>ESTADO POS</th>
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
                        <td class="text-muted"><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0) ?></td>
                        <td class="fw-bold text-primary"><?= $r['NroFactReemplaza'] ?></td>
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
                                    <span class="badge-wait">PENDIENTE POS</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($anuladoPOS) ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                <?= ($anuladoPOS) ? 'ANULADO OK' : 'ACTIVO' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Filtro de búsqueda en la tabla
    document.getElementById('tablaFiltro').addEventListener('keyup', function() {
        const value = this.value.toLowerCase();
        const rows = document.querySelectorAll('#tablaDatos tbody tr');
        
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
        });
    });

    const selAnular = document.getElementById('selAnular');
    const selReemplaza = document.getElementById('selReemplaza');

    selAnular.addEventListener('change', function() {
        const o = this.options[this.selectedIndex];
        document.getElementById('FactAnular').value = o.value;
        document.getElementById('ValorAnular').value = o.dataset.valor || '0';
        if (selReemplaza.value === o.value && o.value !== "") {
            alert("⚠️ No puede ser el mismo documento.");
            this.value = "";
        }
    });

    selReemplaza.addEventListener('change', function() {
        if (this.value === selAnular.value && this.value !== "") {
            alert("⚠️ No puede ser el mismo documento.");
            this.value = "";
        }
    });

    function confirmar(tipo, fact) {
        if (confirm(`¿Desea autorizar la solicitud de la factura ${fact}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}`;
        } else {
            event.target.checked = false;
        }
    }
</script>

</body>
</html>