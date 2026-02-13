<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once 'Conexion.php'; 

/* ============================================================
   ASIGNACIÓN DE VARIABLES DE SESIÓN Y SEGURIDAD
============================================================ */
$Usuario     = $_SESSION['Usuario']    ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("Error: No se detectó una sesión activa válida.");
}

/* ============================================================
   FUNCIONES DE AUTORIZACIÓN Y CONEXIÓN
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $res = $mysqli->query("SELECT Swich FROM autorizacion_tercero WHERE cedulaNit='$User' AND Nro_Auto='$Solicitud'");
    return ($res && $row = $res->fetch_assoc()) ? $row['Swich'] : 'NO';
}

$puedeCambiarFecha = (Autorizacion($Usuario, '9999') === 'SI');
$fechaConsulta = ($puedeCambiarFecha && isset($_GET['fConsulta'])) ? $_GET['fConsulta'] : (isset($_GET['fConsulta']) ? $_GET['fConsulta'] : date('Y-m-d'));
$fPosFormat    = date('Ymd', strtotime($fechaConsulta));

function conectarPOS($sede) {
    if ($sede == '002') {
        include 'ConnDrinks.php'; 
        return $mysqliDrinks; 
    } else {
        include 'ConnCentral.php'; 
        return $mysqliPos; 
    }
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
   PROCESAR ACCIONES (POST Y GET)
============================================================ */
if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: ?fConsulta=" . $fechaConsulta);
    exit;
}

if (isset($_POST['grabar'])) {
    $factura   = $_POST['FactAnular'];
    $reemplazo = $_POST['NroFactReemplaza']; 
    $v = str_replace(['$', ' ', ','], '', $_POST['ValorAnular']);
    $v = (float)$v; 
    
    // Si se selecciona "NO LLEVA NADA", el valor llega vacío o como string "null" dependiendo del manejo, 
    // aquí lo validamos contra el ID del documento original.
    if (!empty($reemplazo) && $factura === $reemplazo) {
        header("Location: ?error=mismo_documento&fConsulta=".$fechaConsulta); exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fechaConsulta, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $_POST['motivo'], $reemplazo);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && isset($_GET['factura'])) {
    $factTarget = $_GET['factura'];
    $fechaRef   = $_GET['f'] ?? $fechaConsulta;
    $ahora      = date('Y-m-d H:i:s');
    $sedeAccion = $_GET['s'] ?? $NroSucursal;
    
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
            header("Location: ?error=no_anulado&fConsulta=".$fechaConsulta); exit;
        }
    }
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

/* ============================================================
   DATOS PARA LA VISTA
============================================================ */
$dbSede = conectarPOS($NroSucursal);
$esGerente = (Autorizacion($Usuario, '2010') === 'SI');
$esBodega  = (Autorizacion($Usuario, '0004') === 'SI');
$nombreSedeActual = ($NroSucursal == '002') ? 'DRINKS' : 'CENTRAL';

$queryDocs = "SELECT NUMERO, VALORTOTAL FROM FACTURAS WHERE ESTADO='0' AND fecha='$fPosFormat' UNION ALL SELECT NUMERO, VALORTOTAL FROM PEDIDOS WHERE ESTADO='0' AND fecha='$fPosFormat' ORDER BY NUMERO DESC";
$listaDocs = $dbSede->query($queryDocs);
$docsArray = [];
if($listaDocs) while($row = $listaDocs->fetch_assoc()) { $docsArray[] = $row; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anulaciones - <?= $nombreSedeActual ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-xs { font-size: 0.75rem; }
        .header-bar { background: #1a1d20; color: #fff; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-bottom: 3px solid #0d6efd; }
        .header-info { border-right: 1px solid #495057; padding-right: 15px; margin-right: 15px; }
        .badge-wait { font-size: 0.6rem; display: block; color: #dc3545; font-weight: bold; }
        .bg-input-dark { background: #2b3035; color: white; border: 1px solid #495057; }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4">

    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= ($_GET['error'] == 'mismo_documento') ? "⚠️ No puedes usar el mismo documento como reemplazo." : "⚠️ El documento aún aparece ACTIVO en el POS." ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center">
            <div class="header-info"><small class="text-secondary d-block">USUARIO</small><span class="fw-bold text-info"><?= $Usuario ?></span></div>
            <div class="header-info"><small class="text-secondary d-block">SEDE ACTUAL</small><span class="fw-bold"><?= $nombreSedeActual ?></span></div>
            
            <?php if($puedeCambiarFecha): ?>
            <div>
                <small class="text-warning d-block fw-bold">FECHA DE TRABAJO (AUTORIZADA)</small>
                <input type="date" class="form-control form-control-sm bg-input-dark" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
            </div>
            <?php else: ?>
            <div><small class="text-secondary d-block">FECHA CONSULTA</small><span class="fw-bold"><?= $fechaConsulta ?></span></div>
            <?php endif; ?>
        </div>
        <div class="text-end">
            <small class="text-secondary d-block">CAMBIAR SEDE</small>
            <select class="form-select form-select-sm bg-dark text-white" onchange="location.href='?setSede='+this.value+'&fConsulta=<?= $fechaConsulta ?>'">
                <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
            </select>
        </div>
    </div>

    <div class="card p-3 shadow-sm border-0 mb-4">
        <h6 class="fw-bold text-muted mb-3 small">NUEVA SOLICITUD PARA EL DÍA: <?= $fechaConsulta ?></h6>
        <form method="post" id="formAnulacion">
            <input type="hidden" name="FactAnular" id="FactAnular">
            <input type="hidden" name="ValorAnular" id="ValorAnular">
            <div class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" id="selAnular" required>
                        <option value="">-- Seleccione a Anular --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>"><?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="NroFactReemplaza" id="selReemplaza" class="form-select form-select-sm" required>
                        <option value="">-- Doc. Reemplazo --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>"><?= $d['NUMERO'] ?> ($<?= number_format($d['VALORTOTAL'],0) ?>)</option>
                        <?php endforeach; ?>
                        <option value="N/A" class="fw-bold text-danger">-- NO LLEVA NADA --</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="motivo" class="form-control form-control-sm" required placeholder="Explique el motivo...">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="grabar" class="btn btn-primary btn-sm w-100 fw-bold">CREAR SOLICITUD</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h6 class="mb-0 fw-bold me-3">HISTORIAL DE SOLICITUDES</h6>
                <input type="date" class="form-control form-control-sm" style="width:150px" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
                <?php if($fechaConsulta != date('Y-m-d')): ?>
                    <a href="?" class="btn btn-sm btn-link text-decoration-none">Volver a Hoy</a>
                <?php endif; ?>
            </div>
            <input type="text" id="inputFiltro" class="form-control form-control-sm w-25" placeholder="🔍 Buscar en esta tabla...">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-xs align-middle text-center mb-0" id="tablaPrincipal">
                <thead class="table-dark">
                    <tr>
                        <th>HORA</th>
                        <th>SEDE</th>
                        <th>DOC. ANULAR</th>
                        <th>VALOR</th>
                        <th>REEMPLAZO</th>
                        <th>MOTIVO</th>
                        <th>BODEGA</th>
                        <th>GERENCIA</th>
                        <th>ESTADO POS</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php
                    $resH = $mysqli->query("SELECT * FROM solicitud_anulacion WHERE F_Creacion = '$fechaConsulta' ORDER BY FH_CajeroCheck DESC");
                    while ($r = $resH->fetch_assoc()): 
                        $anuladoPOS = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                        $txtSede = ($r['NroSucursal'] == '002') ? 'DRINKS' : 'CENTRAL';
                    ?>
                    <tr>
                        <td><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                        <td><span class="badge bg-secondary"><?= $txtSede ?></span></td>
                        <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                        <td>$<?= number_format($r['ValorFactAnular'], 0)?></td>
                        <td class="text-primary fw-bold"><?= $r['NroFactReemplaza'] ?></td>
                        <td class="text-start small"><?= $r['MotivoAnulacion'] ?></td>
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
                                <?php if($esGerente && $anuladoPOS == 0): ?><span class="badge-wait">ESPERANDO POS</span><?php endif; ?>
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
    // Buscador rápido
    document.getElementById('inputFiltro').addEventListener('keyup', function() {
        let texto = this.value.toLowerCase();
        document.querySelectorAll('#tablaPrincipal tbody tr').forEach(fila => {
            fila.style.display = fila.textContent.toLowerCase().includes(texto) ? '' : 'none';
        });
    });

    document.getElementById('formAnulacion').addEventListener('submit', function(e) {
        const sel = document.getElementById('selAnular');
        const selectedOption = sel.options[sel.selectedIndex];
        
        if (selectedOption.value !== "") {
            document.getElementById('FactAnular').value = selectedOption.value;
            document.getElementById('ValorAnular').value = selectedOption.dataset.valor || '0';
        }
    });

    function confirmar(tipo, fact, sede) {
        if (confirm(`¿Autorizar ${tipo} para la factura ${fact}?`)) {
            window.location.href = `?accion=${tipo}&factura=${fact}&s=${sede}&f=<?= $fechaConsulta ?>`;
        } else {
            event.target.checked = false;
        }
    }
</script>
</body>
</html>