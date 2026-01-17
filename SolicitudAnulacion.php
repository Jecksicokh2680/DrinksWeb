<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once 'Conexion.php'; 

/* ============================================================
   ASIGNACIÓN DE VARIABLES DE SESIÓN
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa']  ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';

// Cambio de sede (Abierto para todos los usuarios)
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
    
    if ($factura === $reemplazo) {
        header("Location: ?error=mismo_documento"); exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $fh = date('Y-m-d'); 
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fh, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $_POST['motivo'], $reemplazo);
    $stmt->execute();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura'])) {
    $factTarget = $_GET['factura'];
    $fechaRef   = $_GET['f'] ?? date('Y-m-d');
    $ahora      = date('Y-m-d H:i:s');
    $sedeAccion = $_GET['s'] ?? $NroSucursal; // Sede donde se originó la factura
    
    if ($_GET['accion'] == 'bodega' && Autorizacion($Usuario, '0004') === 'SI') {
        $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET JefeBodCheck='1', NitJefeBod=?, FH_JefeBodCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
        $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $sedeAccion);
        $stmt->execute();
    }
    
    if ($_GET['accion'] == 'gerencia' && Autorizacion($Usuario, '2010') === 'SI') {
        if (VerificarAnulacion($factTarget, $sedeAccion) == 1) {
            $stmt = $mysqli->prepare("UPDATE solicitud_anulacion SET GerenteCheck='1', NitGerente=?, FH_GerenteCheck=? WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
            $stmt->bind_param("sssss", $Usuario, $ahora, $factTarget, $fechaRef, $sedeAccion);
            $stmt->execute();
        } else {
            header("Location: ?error=no_anulado"); exit;
        }
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
}

// Datos para la vista
$dbSede = conectarPOS($NroSucursal);
$fHoy   = date('Ymd');
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSedeActual = ($NroSucursal == '002') ? 'DRINKS' : 'CENTRAL';

$queryDocs = "SELECT NUMERO, VALORTOTAL FROM FACTURAS WHERE ESTADO='0' AND fecha='$fHoy' UNION ALL SELECT NUMERO, VALORTOTAL FROM PEDIDOS WHERE ESTADO='0' AND fecha='$fHoy' ORDER BY NUMERO DESC";
$listaDocs = $dbSede->query($queryDocs);
$docsArray = [];
if($listaDocs) while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Anulaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.75rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        .badge-sede { font-size: 0.7rem; padding: 4px 8px; }
        .badge-wait { font-size: 0.6rem; display: block; color: #dc3545; font-weight: bold; margin-top: 3px; }
        .search-container { max-width: 350px; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <?php 
                if($_GET['error'] == 'mismo_documento') echo "⚠️ Los números de documento no pueden ser idénticos.";
                if($_GET['error'] == 'no_anulado') echo "⚠️ El documento debe ser anulado primero en el sistema POS.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info"><small class="text-secondary d-block">USUARIO CONECTADO</small><span class="fw-bold text-info"><?= $Usuario ?></span></div>
            <div class="header-info"><small class="text-secondary d-block">TRABAJANDO EN</small><span class="fw-bold"><?= $nombreSedeActual ?></span></div>
        </div>
        <div class="text-end">
            <small class="text-secondary d-block">CAMBIAR DE SEDE</small>
            <select class="form-select form-select-sm bg-dark text-white border-secondary" onchange="location.href='?setSede='+this.value">
                <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>001 - CENTRAL</option>
                <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>002 - DRINKS</option>
            </select>
        </div>
    </div>

    <div class="card p-3 shadow-sm border-0 mb-4">
        <form method="post" id="formAnulacion">
            <input type="hidden" name="FactAnular" id="FactAnular">
            <input type="hidden" name="ValorAnular" id="ValorAnular">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="small fw-bold">Doc. a Anular (Sede <?= $nombreSedeActual ?>)</label>
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
                    <label class="small fw-bold">Doc. Reemplaza</label>
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
                    <input type="text" name="motivo" class="form-control form-control-sm" required placeholder="¿Por qué se anula?">
                </div>
                <div class="col-md-2 align-self-end">
                    <button type="submit" name="grabar" class="btn btn-primary btn-sm w-100 fw-bold">GRABAR</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">LISTADO GLOBAL DE SOLICITUDES - HOY</h6>
            <div class="search-container">
                <input type="text" id="inputFiltro" class="form-control form-control-sm" placeholder="🔍 Buscar sede, factura o motivo...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0" id="tablaPrincipal">
                <thead class="table-dark">
                    <tr>
                        <th>HORA</th>
                        <th>SEDE</th>
                        <th>DOC. ANULAR</th>
                        <th>VALOR</th>
                        <th class="table-warning text-dark">DOC. REEMPLAZA</th>
                        <th>MOTIVO</th>
                        <th>BODEGA</th>
                        <th>GERENCIA</th>
                        <th>ESTADO POS</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $fH = date('Y-m-d');
                    // Consulta todas las sedes para que la tabla sea informativa
                    $resH = $mysqli->query("SELECT * FROM solicitud_anulacion WHERE F_Creacion = '$fH' ORDER BY FH_CajeroCheck DESC");
                    while ($r = $resH->fetch_assoc()): 
                        $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                        $txtSede = ($r['NroSucursal'] == '002') ? 'DRINKS' : 'CENTRAL';
                        $colorSede = ($r['NroSucursal'] == '002') ? 'bg-purple text-white' : 'bg-info text-dark';
                    ?>
                    <tr>
                        <td class="text-muted"><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td><span class="badge badge-sede <?= ($r['NroSucursal']=='002')?'bg-dark':'bg-secondary' ?>"><?= $txtSede ?></span></td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0) ?></td>
                        <td class="fw-bold text-primary"><?= $r['NroFactReemplaza'] ?></td>
                        <td class="text-start"><?= $r['MotivoAnulacion'] ?></td>
                        <td>
                            <input class="form-check-input" type="checkbox" 
                                <?= ($r['JefeBodCheck']=='1') ? 'checked disabled' : 
                                    (($esBodega) ? "onclick=\"confirmar('bodega', '{$r['NroFactAnular']}', '{$r['NroSucursal']}')\"" : 'disabled') ?>>
                        </td>
                        <td>
                            <?php if ($r['GerenteCheck'] == '1'): ?>
                                <input class="form-check-input" type="checkbox" checked disabled>
                            <?php elseif ($esGerente && $anuladoPOS == 1): ?>
                                <input class="form-check-input border-primary" type="checkbox" onclick="confirmar('gerencia', '<?= $r['NroFactAnular'] ?>', '<?= $r['NroSucursal'] ?>')">
                            <?php else: ?>
                                <input class="form-check-input" type="checkbox" disabled>
                                <?php if($esGerente && $anuladoPOS == 0): ?>
                                    <span class="badge-wait">ESPERANDO POS</span>
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
    // Filtro de búsqueda global
    document.getElementById('inputFiltro').addEventListener('keyup', function() {
        let texto = this.value.toLowerCase();
        let filas = document.querySelectorAll('#tablaPrincipal tbody tr');
        filas.forEach(fila => {
            fila.style.display = fila.textContent.toLowerCase().includes(texto) ? '' : 'none';
        });
    });

    const selAnular = document.getElementById('selAnular');
    const selReemplaza = document.getElementById('selReemplaza');

    selAnular.addEventListener('change', function() {
        const o = this.options[this.selectedIndex];
        document.getElementById('FactAnular').value = o.value;
        document.getElementById('ValorAnular').value = o.dataset.valor || '0';
        if (selReemplaza.value === o.value && o.value !== "") {
            alert("⚠️ No pueden ser iguales.");
            this.value = "";
        }
    });

    selReemplaza.addEventListener('change', function() {
        if (this.value === selAnular.value && this.value !== "") {
            alert("⚠️ No pueden ser iguales.");
            this.value = "";
        }
    });

    function confirmar(tipo, fact, sede) {
        if (confirm(`¿Autorizar ${tipo} para la factura ${fact} en sede ${sede}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}&s=${sede}`;
        } else {
            event.target.checked = false;
        }
    }
</script>
</body>
</html>