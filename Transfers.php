<?php
require 'Conexion.php';
require 'helpers.php';

session_start();
session_regenerate_id(true);

/* ============================================================
   CONFIGURACIÓN DE SESIÓN
============================================================ */
$session_timeout   = 3600;
$inactive_timeout  = 1800;

if (isset($_SESSION['ultimo_acceso'])) {
    if (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout) {
        session_unset();
        session_destroy();
        header("Location: Login.php?msg=Sesión expirada por inactividad");
        exit;
    }
}
$_SESSION['ultimo_acceso'] = time();
ini_set('session.gc_maxlifetime', $session_timeout);
session_set_cookie_params($session_timeout);

/* ============================================================
   VARIABLES DE SESIÓN
============================================================ */
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

if (empty($UsuarioSesion)) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

/* ============================================================
   FUNCIÓN AUTORIZACIÓN CON CACHE EN SESIÓN
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    if (!isset($_SESSION['Autorizaciones'])) $_SESSION['Autorizaciones'] = [];
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) return $_SESSION['Autorizaciones'][$key];
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit = ? AND Nro_Auto = ?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}

/* ============================================================
   AJAX — DEVOLVER SUCURSALES POR NIT
============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === "sucursales") {
    header("Content-Type: application/json; charset=utf-8");
    $nit = $_GET['nit'] ?? '';
    if ($nit === '') { echo json_encode([]); exit; }
    $stmt = $mysqli->prepare("SELECT NroSucursal, Direccion FROM empresa_sucursal WHERE Nit = ? AND Estado = 1 ORDER BY Direccion");
    $stmt->bind_param("s", $nit);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    echo json_encode($data);
    $stmt->close();
    exit;
}

/* ============================================================
   AJAX — ACTUALIZAR CHECKBOX REVISIONES
============================================================ */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'actualizar_check') {
    header('Content-Type: application/json');
    $idTransfer = intval($_POST['idTransfer'] ?? 0);
    $campo = $_POST['campo'] ?? '';
    $valor = isset($_POST['valor']) && $_POST['valor'] == "1" ? 1 : 0;
    $puedeRevisarLogistica = Autorizacion($UsuarioSesion, "0009") === "SI";
    $puedeRevisarGerencia  = Autorizacion($UsuarioSesion, "0010") === "SI";
    if (!in_array($campo, ['RevisadoLogistica','RevisadoGerencia']) || $idTransfer <= 0) {
        echo json_encode(['status'=>'error','msg'=>'Campo inválido']); exit;
    }
    if (($campo === 'RevisadoLogistica' && !$puedeRevisarLogistica) || ($campo === 'RevisadoGerencia'  && !$puedeRevisarGerencia)) {
        echo json_encode(['status'=>'error','msg'=>'No autorizado']); exit;
    }
    $stmt = $mysqli->prepare("UPDATE Relaciontransferencias SET $campo=? WHERE IdTransfer=?");
    $stmt->bind_param("ii",$valor,$idTransfer);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['status'=>'ok']);
    exit;
}

/* ============================================================
   FECHA Y HORA (BOGOTÁ)
============================================================ */
date_default_timezone_set('America/Bogota');
$FechaHoy = date("Y-m-d");
$HoraHoy  = date("H:i");

/* ============================================================
   MENSAJE
============================================================ */
$msg = "";

/* ============================================================
   ELIMINAR TRANSFERENCIA — SEGÚN AUTORIZACIÓN
============================================================ */
if (isset($_GET['borrar'])) {
    $idBorrar = intval($_GET['borrar']);
    $puedeBorrar = Autorizacion($UsuarioSesion, "0003") === "SI";
    if (!$puedeBorrar) {
        $stmtCheck = $mysqli->prepare("SELECT IdTransfer FROM Relaciontransferencias WHERE IdTransfer=? AND CedulaNit=?");
        $stmtCheck->bind_param("is",$idBorrar,$UsuarioSesion);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $stmtCheck->close();
        if ($resCheck->num_rows === 0) {
            $msg = "❌ No tiene autorización para eliminar esta transferencia.";
        } else {
            $stmtDel = $mysqli->prepare("DELETE FROM Relaciontransferencias WHERE IdTransfer=?");
            $stmtDel->bind_param("i",$idBorrar);
            $stmtDel->execute();
            $stmtDel->close();
        }
    } else {
        $stmtDel = $mysqli->prepare("DELETE FROM Relaciontransferencias WHERE IdTransfer=?");
        $stmtDel->bind_param("i",$idBorrar);
        $stmtDel->execute();
        $stmtDel->close();
    }
}

/* ============================================================
   REGISTRAR TRANSFERENCIA
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarTransferencia'])) {
    if (Autorizacion($UsuarioSesion, "0007") !== "SI") {
        $msg = "❌ No tiene autorización para registrar transferencias.";
    } else {
        $Fecha      = limpiar($_POST['Fecha'] ?? '');
        $Hora       = limpiar($_POST['Hora'] ?? '');
        $NitEmpresa = limpiar($_POST['NitEmpresa'] ?? '');
        $Sucursal   = limpiar($_POST['Sucursal'] ?? '');
        $CedulaNit  = limpiar($_POST['CedulaNit'] ?? '');
        $IdMedio    = intval($_POST['IdMedio'] ?? 0);
        $Monto      = floatval($_POST['Monto'] ?? 0);

        if (empty($Fecha) || empty($Hora) || empty($NitEmpresa) || empty($Sucursal) || empty($CedulaNit) || $IdMedio <= 0 || $Monto <= 0) {
            $msg = "❌ Faltan datos obligatorios.";
        } else {
            $stmt = $mysqli->prepare("INSERT INTO Relaciontransferencias (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssid",$Fecha,$Hora,$NitEmpresa,$Sucursal,$CedulaNit,$IdMedio,$Monto);
            if ($stmt->execute()) $msg = "✓ Transferencia registrada correctamente.";
            else $msg = "❌ Error al registrar transferencia: " . $stmt->error;
            $stmt->close();
        }
    }
}

/* ============================================================
   LISTAR TRANSFERENCIAS
============================================================ */
$consultaSQL = "
    SELECT t.IdTransfer, t.Fecha, t.Hora,
           e.NombreComercial,
           s.Direccion AS Sucursal,
           tr.Nombre AS Tercero,
           m.Nombre AS Medio,
           t.Monto,
           t.RevisadoLogistica,
           t.RevisadoGerencia
    FROM Relaciontransferencias t
    INNER JOIN empresa e ON e.Nit = t.NitEmpresa
    INNER JOIN empresa_sucursal s ON s.Nit = t.NitEmpresa AND s.NroSucursal = t.Sucursal
    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
    INNER JOIN mediopago m ON m.IdMedio = t.IdMedio
";
if (Autorizacion($UsuarioSesion, "0003") === "NO") {
    $consultaSQL .= " WHERE t.CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}
$consultaSQL .= " ORDER BY t.Fecha DESC, t.Hora DESC";
$transferencias = $mysqli->query($consultaSQL);

/* ============================================================
   LISTAS PARA SELECT
============================================================ */
$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado=1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);
$tercerosArray = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$mediosArray   = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);

/* ============================================================
   TOTAL MONTOS
============================================================ */
$totalSQL = "SELECT SUM(Monto) AS Total FROM Relaciontransferencias WHERE (RevisadoLogistica+RevisadoGerencia)>=1";
if (Autorizacion($UsuarioSesion, "0003") === "NO") $totalSQL .= " AND CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
$totalMontos = $mysqli->query($totalSQL)->fetch_assoc()['Total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transferencias</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
body { background-color: #f8f9fa; }
.card { border-radius: 0.75rem; }
.table thead { background-color: #343a40; color: #fff; }
.table-hover tbody tr:hover { background-color: #e9ecef; }
.badge-revisado { font-size: 0.8rem; padding: 0.25em 0.45em; border-radius: 0.25rem; }
.form-control-sm, .form-select-sm { height: calc(1.5em + .5rem + 2px); font-size: .85rem; padding: .25rem .5rem; }
.modal-sm-fields .row > div { margin-bottom: .5rem; }
</style>
<script>
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let suc = document.getElementById("Sucursal");
    if (!nit) { suc.innerHTML = '<option value="">Seleccione...</option>'; return; }
    suc.innerHTML = '<option>Cargando...</option>';
    fetch("Transfers.php?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res => res.json())
        .then(data => {
            suc.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => { suc.innerHTML += `<option value="${s.NroSucursal}">${s.Direccion}</option>`; });
        });
}

function actualizarCheck(idTransfer, campo, checkbox) {
    fetch('Transfers.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax=actualizar_check&idTransfer=' + idTransfer + '&campo=' + campo + '&valor=' + (checkbox.checked ? 1 : 0)
    }).then(res => res.json()).then(data => { if(data.status!=='ok') alert(data.msg); location.reload(); });
}
</script>
</head>
<body>
<div class="container-fluid mt-4">

<?php if ($msg != ""): ?>
<div class="alert alert-info shadow-sm"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="mb-3 d-flex flex-wrap gap-2">
<?php if (Autorizacion($UsuarioSesion, "0007") === "SI"): ?>
<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
<i class="bi bi-plus-circle me-1"></i>Nueva Transferencia
</button>
<?php else: ?>
<button class="btn btn-secondary btn-sm" disabled>🚫 No autorizado</button>
<?php endif; ?>
</div>

<div class="card shadow-sm p-3 mb-4">
<h5 class="mb-3">Transferencias Registradas</h5>
<div class="table-responsive">
<table class="table table-striped table-hover table-bordered align-middle mb-0">
<thead class="table-dark">
<tr>
<th>Fecha</th>
<th>Hora</th>
<th>Empresa</th>
<th>Sucursal</th>
<th>Tercero</th>
<th>Medio</th>
<th class="text-end">Monto</th>
<th class="text-center">Logística</th>
<th class="text-center">Gerencia</th>
<th class="text-center">Acción</th>
</tr>
</thead>
<tbody>
<?php 
$puedeRevisarLogistica = Autorizacion($UsuarioSesion, "0009")==="SI";
$puedeRevisarGerencia  = Autorizacion($UsuarioSesion, "0010")==="SI";
?>
<?php while ($row = $transferencias->fetch_assoc()): ?>
<tr>
<td><?= $row['Fecha'] ?></td>
<td><?= $row['Hora'] ?></td>
<td><?= $row['NombreComercial'] ?></td>
<td><?= $row['Sucursal'] ?></td>
<td><?= $row['Tercero'] ?></td>
<td><?= $row['Medio'] ?></td>
<td class="text-end"><?= number_format($row['Monto'],2) ?></td>
<td class="text-center">
<?php if($puedeRevisarLogistica): ?>
<input type="checkbox" <?= $row['RevisadoLogistica'] ? 'checked' : '' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoLogistica',this)">
<?php else: ?>
<span class="badge bg-<?= $row['RevisadoLogistica'] ? 'success' : 'secondary' ?> badge-revisado"><?= $row['RevisadoLogistica'] ? '✓' : '✗' ?></span>
<?php endif; ?>
</td>
<td class="text-center">
<?php if($puedeRevisarGerencia): ?>
<input type="checkbox" <?= $row['RevisadoGerencia'] ? 'checked' : '' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoGerencia',this)">
<?php else: ?>
<span class="badge bg-<?= $row['RevisadoGerencia'] ? 'success' : 'secondary' ?> badge-revisado"><?= $row['RevisadoGerencia'] ? '✓' : '✗' ?></span>
<?php endif; ?>
</td>
<td class="text-center">
<?php
$puedeBorrar = Autorizacion($UsuarioSesion, "0006") === "SI";
if($puedeBorrar || $row['Tercero']==$UsuarioSesion): ?>
<a href="?borrar=<?= intval($row['IdTransfer']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar esta transferencia?')"><i class="bi bi-trash"></i></a>
<?php else: ?>
<button class="btn btn-secondary btn-sm" disabled>🚫</button>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
<?php if ($transferencias->num_rows==0): ?>
<tr><td colspan="10" class="text-center">No hay transferencias registradas.</td></tr>
<?php endif; ?>
</tbody>
<tfoot>
<tr>
<th colspan="6" class="text-end">Total:</th>
<th class="text-end"><?= number_format($totalMontos,2) ?></th>
<th colspan="3"></th>
</tr>
</tfoot>
</table>
</div>
</div>
</div>

<!-- MODAL NUEVA TRANSFERENCIA -->
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
<div class="modal-content shadow-sm rounded-3">
<form method="POST">
<div class="modal-header bg-primary text-white border-0">
<h5 class="modal-title"><i class="bi bi-wallet2 me-2"></i>Nueva Transferencia</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-3 modal-sm-fields">
<input type="hidden" name="guardarTransferencia" value="1">

<div class="row g-2">
<div class="col-md-6">
<label class="form-label">Fecha</label>
<input type="date" name="Fecha" class="form-control form-control-sm" value="<?= $FechaHoy ?>" readonly>
</div>
<div class="col-md-6">
<label class="form-label">Hora</label>
<input type="time" name="Hora" class="form-control form-control-sm" value="<?= $HoraHoy ?>" readonly>
</div>
</div>

<?php $puedeCambiarEmpresa = Autorizacion($UsuarioSesion, "0003")==="SI"; ?>
<div class="row g-2 mt-2">
<div class="col-md-6">
<label class="form-label">Empresa</label>
<?php if($puedeCambiarEmpresa): ?>
<select name="NitEmpresa" id="NitEmpresa" class="form-select form-select-sm" onchange="cargarSucursales()" required>
<option value="">Seleccione...</option>
<?php foreach($empresasArray as $e): ?>
<option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option>
<?php endforeach; ?>
</select>
<?php else: ?>
<input type="text" class="form-control form-control-sm" value="<?= $NitSesion ?>" readonly>
<input type="hidden" name="NitEmpresa" value="<?= $NitSesion ?>">
<?php endif; ?>
</div>
<div class="col-md-6">
<label class="form-label">Sucursal</label>
<?php if($puedeCambiarEmpresa): ?>
<select name="Sucursal" id="Sucursal" class="form-select form-select-sm" required>
<option value="">Seleccione empresa...</option>
</select>
<?php else: ?>
<input type="text" class="form-control form-control-sm" value="<?= $SucursalSesion ?>" readonly>
<input type="hidden" name="Sucursal" value="<?= $SucursalSesion ?>">
<?php endif; ?>
</div>
</div>

<div class="mb-2 mt-2">
<label class="form-label">Tercero</label>
<?php if($puedeCambiarEmpresa): ?>
<select name="CedulaNit" class="form-select form-select-sm" required>
<option value="">Seleccione...</option>
<?php foreach($tercerosArray as $t): ?>
<option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?></option>
<?php endforeach; ?>
</select>
<?php else: ?>
<input type="text" class="form-control form-control-sm" value="<?= $UsuarioSesion ?>" readonly>
<input type="hidden" name="CedulaNit" value="<?= $UsuarioSesion ?>">
<?php endif; ?>
</div>

<div class="row g-2">
<div class="col-md-6">
<label class="form-label">Medio de Pago</label>
<select name="IdMedio" class="form-select form-select-sm" required>
<option value="">Seleccione...</option>
<?php foreach($mediosArray as $m): ?>
<option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Monto</label>
<input type="number" step="0.01" min="0.01" name="Monto" class="form-control form-control-sm" placeholder="0.00" required>
</div>
</div>

</div>
<div class="modal-footer justify-content-end border-0 mt-2">
<button type="submit" class="btn btn-success btn-sm"><i class="bi bi-save me-1"></i>Guardar</button>

</div>
</form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
