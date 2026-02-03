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
    FUNCIÓN AUTORIZACIÓN
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    if (!isset($_SESSION['Autorizaciones'])) {
        $_SESSION['Autorizaciones'] = [];
    }
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) {
        return $_SESSION['Autorizaciones'][$key];
    }
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
    LÓGICA DE FECHA
============================================================ */
date_default_timezone_set('America/Bogota');
$FechaHoy = date("Y-m-d");
$HoraHoy  = date("H:i");

$puedeModificarFechaConsulta = Autorizacion($UsuarioSesion, "0013") === "SI";

if ($puedeModificarFechaConsulta && isset($_POST['fechaConsulta'])) {
    $fechaConsulta = $_POST['fechaConsulta'];
} else {
    $fechaConsulta = $FechaHoy;
}

/* ============================================================
    AJAX — SUCURSALES
============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === "sucursales") {
    header("Content-Type: application/json; charset=utf-8");
    $nit = $_GET['nit'] ?? '';
    $stmt = $mysqli->prepare("SELECT NroSucursal, Direccion FROM empresa_sucursal WHERE Nit = ? AND Estado = 1 ORDER BY Direccion");
    $stmt->bind_param("s", $nit);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    echo json_encode($data);
    $stmt->close();
    exit;
}

/* ============================================================
    AJAX — ACTUALIZAR CHECKBOX
============================================================ */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'actualizar_check') {
    header('Content-Type: application/json');
    $idTransfer = intval($_POST['idTransfer'] ?? 0);
    $campo      = $_POST['campo'] ?? '';
    $valor      = isset($_POST['valor']) && $_POST['valor'] == "1" ? 1 : 0;
    
    $pLog = Autorizacion($UsuarioSesion, "0009") === "SI";
    $pGer = Autorizacion($UsuarioSesion, "0010") === "SI";

    if (!in_array($campo, ['RevisadoLogistica','RevisadoGerencia']) || $idTransfer <= 0) {
        echo json_encode(['status'=>'error','msg'=>'Campo inválido']); exit;
    }
    if (($campo === 'RevisadoLogistica' && !$pLog) || ($campo === 'RevisadoGerencia'  && !$pGer)) {
        echo json_encode(['status'=>'error','msg'=>'No autorizado']); exit;
    }

    $stmt = $mysqli->prepare("UPDATE Relaciontransferencias SET $campo=? WHERE IdTransfer=?");
    $stmt->bind_param("ii",$valor,$idTransfer);
    $stmt->execute();
    echo json_encode(['status'=>'ok']);
    exit;
}

$msg = "";

/* ============================================================
    ELIMINAR TRANSFERENCIA
============================================================ */
if (isset($_GET['borrar'])) {
    $idBorrar = intval($_GET['borrar']);
    $pAdmin = Autorizacion($UsuarioSesion, "0003") === "SI";
    if ($pAdmin) {
        $mysqli->query("DELETE FROM Relaciontransferencias WHERE IdTransfer=$idBorrar");
    } else {
        $stmtCheck = $mysqli->prepare("SELECT IdTransfer FROM Relaciontransferencias WHERE IdTransfer=? AND CedulaNit=?");
        $stmtCheck->bind_param("is",$idBorrar,$UsuarioSesion);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) {
            $mysqli->query("DELETE FROM Relaciontransferencias WHERE IdTransfer=$idBorrar");
        } else { $msg = "❌ No autorizado."; }
    }
}

/* ============================================================
    REGISTRAR TRANSFERENCIA
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarTransferencia'])) {
    if (Autorizacion($UsuarioSesion, "0007") !== "SI") {
        $msg = "❌ Sin permiso de registro.";
    } else {
        $Fecha      = $_POST['Fecha'];
        $Hora       = $_POST['Hora'];
        $NitEmpresa = $_POST['NitEmpresa'];
        $Sucursal   = $_POST['Sucursal'];
        $CedulaNit  = $_POST['CedulaNit'];
        $IdMedio    = intval($_POST['IdMedio']);
        $Monto      = floatval($_POST['Monto']);

        $stmt = $mysqli->prepare("INSERT INTO Relaciontransferencias (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssid",$Fecha,$Hora,$NitEmpresa,$Sucursal,$CedulaNit,$IdMedio,$Monto);
        if($stmt->execute()) $msg = "✓ Registrada."; else $msg = "❌ Error.";
        $stmt->close();
    }
}

/* ============================================================
    LISTAR TRANSFERENCIAS Y RESÚMENES
============================================================ */
$safeFecha = $mysqli->real_escape_string($fechaConsulta);
$where = " WHERE t.Fecha = '$safeFecha'";

if (Autorizacion($UsuarioSesion, "0003") === "NO") {
    $where .= " AND t.CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}

$consultaSQL = "
    SELECT t.IdTransfer, t.Fecha, t.Hora, e.NombreComercial, s.Direccion AS Sucursal,
           tr.Nombre AS Tercero, m.Nombre AS Medio, t.Monto, t.RevisadoLogistica, t.RevisadoGerencia
    FROM Relaciontransferencias t
    INNER JOIN empresa e ON e.Nit = t.NitEmpresa
    INNER JOIN empresa_sucursal s ON s.Nit = t.NitEmpresa AND s.NroSucursal = t.Sucursal
    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
    INNER JOIN mediopago m ON m.IdMedio = t.IdMedio
    $where ORDER BY t.Hora DESC";
$transferencias = $mysqli->query($consultaSQL);

// RESUMEN POR CAJERO
$resumenSQL = "
    SELECT tr.Nombre AS Cajero, SUM(t.Monto) AS TotalCajero, COUNT(*) AS Cantidad
    FROM Relaciontransferencias t
    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
    $where GROUP BY t.CedulaNit ORDER BY TotalCajero DESC";
$resumenPorCajero = $mysqli->query($resumenSQL);

// RESUMEN POR MEDIO DE PAGO (SOLICITADO)
$resumenMedioSQL = "
    SELECT m.Nombre AS Medio, SUM(t.Monto) AS TotalMedio, COUNT(*) AS Cantidad
    FROM Relaciontransferencias t
    INNER JOIN mediopago m ON m.IdMedio = t.IdMedio
    $where GROUP BY t.IdMedio ORDER BY TotalMedio DESC";
$resumenPorMedio = $mysqli->query($resumenMedioSQL);

// TOTAL REVISADO
$totalSQL = "SELECT SUM(Monto) AS Total FROM Relaciontransferencias t $where AND (RevisadoLogistica+RevisadoGerencia)>=1";
$totalMontos = $mysqli->query($totalSQL)->fetch_assoc()['Total'] ?? 0;

$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado=1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);
$tercerosArray = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$mediosArray   = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transferencias</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .card { border-radius: 10px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table thead { background-color: #212529; color: white; }
        .bg-resumen { background-color: #f8f9fa; }
    </style>
</head>
<body>

<div class="container-fluid px-4 mt-4">

    <?php if ($msg != ""): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4 align-items-center">
        <div class="col-md-4">
            <?php if (Autorizacion($UsuarioSesion, "0007") === "SI"): ?>
                <button class="btn btn-primary btn-lg px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                    + Nueva Transferencia
                </button>
            <?php endif; ?>
        </div>
        <div class="col-md-8 d-flex justify-content-md-end mt-3 mt-md-0">
            <div class="card p-2 shadow-sm border">
                <form method="POST" class="row g-2 align-items-center">
                    <div class="col-auto"><label class="small fw-bold">FECHA:</label></div>
                    <div class="col">
                        <input type="date" name="fechaConsulta" class="form-control" value="<?= $fechaConsulta ?>" <?= $puedeModificarFechaConsulta?'':'readonly' ?>>
                    </div>
                    <?php if ($puedeModificarFechaConsulta): ?>
                    <div class="col-auto"><button type="submit" class="btn btn-dark">Filtrar</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4">
        <h4 class="mb-3">Detalle del día: <span class="text-primary"><?= date("d/m/Y", strtotime($fechaConsulta)) ?></span></h4>
        <div class="table-responsive">
            <table class="table table-hover align-middle border">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Empresa / Sucursal</th>
                        <th>Cajero</th>
                        <th>Medio</th>
                        <th>Monto</th>
                        <th class="text-center">Logística</th>
                        <th class="text-center">Gerencia</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $pLog = Autorizacion($UsuarioSesion, "0009")==="SI";
                    $pGer = Autorizacion($UsuarioSesion, "0010")==="SI";
                    $count = 0;
                    while ($row = $transferencias->fetch_assoc()): $count++; ?>
                        <tr>
                            <td><?= $row['Hora'] ?></td>
                            <td><strong><?= $row['NombreComercial'] ?></strong><br><small><?= $row['Sucursal'] ?></small></td>
                            <td><?= $row['Tercero'] ?></td>
                            <td><span class="badge bg-secondary"><?= $row['Medio'] ?></span></td>
                            <td class="fw-bold text-end"><?= number_format($row['Monto'], 2) ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" <?= $row['RevisadoLogistica']?'checked':'' ?> <?= $pLog?'':'disabled' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoLogistica',this)">
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" <?= $row['RevisadoGerencia']?'checked':'' ?> <?= $pGer?'':'disabled' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoGerencia',this)">
                            </td>
                            <td class="text-center">
                                <?php if(Autorizacion($UsuarioSesion, "0003") === "SI"): ?>
                                    <a href="?borrar=<?= $row['IdTransfer'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar?')">Borrar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="4" class="text-end">TOTAL REVISADO:</th>
                        <th class="text-end text-success fs-5"><?= number_format($totalMontos, 2) ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="row mb-5 g-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">Resumen por Cajero</div>
                <div class="card-body bg-resumen">
                    <table class="table table-sm table-bordered bg-white">
                        <thead class="table-secondary">
                            <tr><th>Nombre</th><th class="text-center">Cant.</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php $gtC = 0; while ($r = $resumenPorCajero->fetch_assoc()): $gtC += $r['TotalCajero']; ?>
                                <tr>
                                    <td><?= $r['Cajero'] ?></td>
                                    <td class="text-center"><?= $r['Cantidad'] ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['TotalCajero'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="table-secondary fw-bold">
                            <tr><td colspan="2" class="text-end">TOTAL:</td><td class="text-end"><?= number_format($gtC, 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-primary">
                <div class="card-header bg-primary text-white fw-bold">Resumen por Medio de Pago</div>
                <div class="card-body" style="background-color: #f0f7ff;">
                    <table class="table table-sm table-bordered bg-white">
                        <thead class="table-primary">
                            <tr><th>Medio</th><th class="text-center">Cant.</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php $gtM = 0; while ($rm = $resumenPorMedio->fetch_assoc()): $gtM += $rm['TotalMedio']; ?>
                                <tr>
                                    <td><span class="badge bg-info text-dark"><?= $rm['Medio'] ?></span></td>
                                    <td class="text-center"><?= $rm['Cantidad'] ?></td>
                                    <td class="text-end fw-bold"><?= number_format($rm['TotalMedio'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="table-primary fw-bold">
                            <tr><td colspan="2" class="text-end">TOTAL:</td><td class="text-end"><?= number_format($gtM, 2) ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTransferencia" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-primary text-white"><h5>Nueva Transferencia</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4">
            <input type="hidden" name="guardarTransferencia" value="1">
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Fecha</label><input type="date" name="Fecha" class="form-control" value="<?= $FechaHoy ?>" readonly></div>
                <div class="col-md-6"><label class="form-label">Hora</label><input type="time" name="Hora" class="form-control" value="<?= $HoraHoy ?>" readonly></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Empresa</label>
                <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
                    <option value="">Seleccione...</option>
                    <?php foreach($empresasArray as $e): ?><option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Sucursal</label>
                <select name="Sucursal" id="Sucursal" class="form-select" required><option value="">Empresa primero...</option></select>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Medio Pago</label><select name="IdMedio" class="form-select">
                    <?php foreach($mediosArray as $m): ?><option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-6"><label class="form-label">Monto</label><input type="number" step="0.01" name="Monto" class="form-control border-primary" required></div>
            </div>
            <input type="hidden" name="CedulaNit" value="<?= $UsuarioSesion ?>">
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary px-4">Guardar</button></div>
      </form>
    </div>
  </div>
</div>

<script>
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let sucSelect = document.getElementById("Sucursal");
    if (!nit) return;
    fetch("?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res => res.json())
        .then(data => {
            sucSelect.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => { sucSelect.innerHTML += `<option value="${s.NroSucursal}">${s.Direccion}</option>`; });
        });
}
function actualizarCheck(idTransfer, campo, checkbox) {
    let valor = checkbox.checked ? 1 : 0;
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=actualizar_check&idTransfer=${idTransfer}&campo=${campo}&valor=${valor}`
    }).then(() => location.reload());
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>