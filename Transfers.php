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
   FUNCIÓN AUTORIZACIÓN (CON CACHE)
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
   LÓGICA DE FECHA (REQUERIMIENTO 0013)
============================================================ */
date_default_timezone_set('America/Bogota');
$FechaHoy = date("Y-m-d");
$HoraHoy  = date("H:i");

// Verificar si tiene permiso 0013 para cambiar fecha de consulta
$puedeModificarFechaConsulta = Autorizacion($UsuarioSesion, "0013") === "SI";

// Si tiene el permiso y envió una fecha por POST, usamos esa. Si no, usamos Hoy.
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

    if (!$pAdmin) {
        $stmtCheck = $mysqli->prepare("SELECT IdTransfer FROM Relaciontransferencias WHERE IdTransfer=? AND CedulaNit=?");
        $stmtCheck->bind_param("is",$idBorrar,$UsuarioSesion);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows === 0) {
            $msg = "❌ No autorizado para eliminar.";
        } else {
            $mysqli->query("DELETE FROM Relaciontransferencias WHERE IdTransfer=$idBorrar");
        }
        $stmtCheck->close();
    } else {
        $mysqli->query("DELETE FROM Relaciontransferencias WHERE IdTransfer=$idBorrar");
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
   LISTAR TRANSFERENCIAS (FILTRADO POR FECHA DE CONSULTA)
============================================================ */
$where = " WHERE t.Fecha = '" . $mysqli->real_escape_string($fechaConsulta) . "'";

// Si no es admin (0003), solo ve las suyas de esa fecha
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
    $where
    ORDER BY t.Hora DESC";

$transferencias = $mysqli->query($consultaSQL);

// TOTAL MONTOS REVISADOS
$totalSQL = "SELECT SUM(Monto) AS Total FROM Relaciontransferencias t $where AND (RevisadoLogistica+RevisadoGerencia)>=1";
$totalMontos = $mysqli->query($totalSQL)->fetch_assoc()['Total'] ?? 0;

/* ============================================================
   LISTAS PARA MODAL
============================================================ */
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
        body { background-color: #f8f9fa; font-family: sans-serif; }
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .table thead { background-color: #343a40; color: white; }
        .btn-primary { background-color: #0d6efd; }
    </style>
</head>
<body>

<div class="container-fluid px-4 mt-4">

    <?php if ($msg != ""): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4 align-items-center">
        <!-- BOTÓN NUEVA -->
        <div class="col-md-4">
            <?php if (Autorizacion($UsuarioSesion, "0007") === "SI"): ?>
                <button class="btn btn-primary btn-lg px-4" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                    + Nueva Transferencia
                </button>
            <?php endif; ?>
        </div>

        <!-- FILTRO DE FECHA (REQUERIMIENTO 0013) -->
        <div class="col-md-8 mt-3 mt-md-0 d-flex justify-content-md-end">
            <div class="card p-3 w-100" style="max-width: 450px;">
                <form method="POST" class="row g-2 align-items-center">
                    <div class="col-auto">
                        <label class="fw-bold text-secondary small">CONSULTAR FECHA:</label>
                    </div>
                    <div class="col">
                        <?php if ($puedeModificarFechaConsulta): ?>
                            <!-- Si tiene 0013, sale el calendario editable -->
                            <input type="date" name="fechaConsulta" class="form-control" value="<?= $fechaConsulta ?>">
                        <?php else: ?>
                            <!-- Si NO tiene 0013, sale la fecha actual bloqueada -->
                            <input type="date" class="form-control bg-light" value="<?= $fechaConsulta ?>" readonly>
                        <?php endif; ?>
                    </div>
                    <?php if ($puedeModificarFechaConsulta): ?>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-dark">Filtrar</button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- TABLA DE RESULTADOS -->
    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="text-dark">Transferencias del día: <span class="text-primary"><?= date("d/m/Y", strtotime($fechaConsulta)) ?></span></h4>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle border">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Empresa / Sucursal</th>
                        <th>Tercero</th>
                        <th>Medio</th>
                        <th>Monto</th>
                        <th class="text-center">Logística</th>
                        <th class="text-center">Gerencia</th>
                        <th class="text-center">Acciones</th>
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
                            <td>
                                <strong><?= $row['NombreComercial'] ?></strong><br>
                                <small class="text-muted"><?= $row['Sucursal'] ?></small>
                            </td>
                            <td><?= $row['Tercero'] ?></td>
                            <td><span class="badge bg-secondary"><?= $row['Medio'] ?></span></td>
                            <td class="fw-bold text-end"><?= number_format($row['Monto'], 2) ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" style="scale:1.3" <?= $row['RevisadoLogistica'] ? 'checked' : '' ?> 
                                <?= $pLog?'':'disabled' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoLogistica',this)">
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" style="scale:1.3" <?= $row['RevisadoGerencia'] ? 'checked' : '' ?> 
                                <?= $pGer?'':'disabled' ?> onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoGerencia',this)">
                            </td>
                            <td class="text-center">
                                <?php if(Autorizacion($UsuarioSesion, "0003") === "SI" || $row['Tercero']==$UsuarioSesion): ?>
                                    <a href="?borrar=<?= $row['IdTransfer'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar registro?')">Eliminar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if ($count == 0): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No se encontraron transferencias para esta fecha.</td></tr>
                    <?php endif; ?>
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
</div>

<!-- MODAL REGISTRO -->
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content shadow-lg">
      <form method="POST">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Nueva Transferencia</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
            <input type="hidden" name="guardarTransferencia" value="1">
            
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Fecha Registro</label>
                    <input type="date" name="Fecha" class="form-control" value="<?= $FechaHoy ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Hora</label>
                    <input type="time" name="Hora" class="form-control" value="<?= $HoraHoy ?>" readonly>
                </div>
            </div>

            <?php $pAdmin = Autorizacion($UsuarioSesion, "0003")==="SI"; ?>
            
            <div class="mb-3">
                <label class="form-label fw-bold">Empresa</label>
                <?php if($pAdmin): ?>
                    <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($empresasArray as $e): ?>
                            <option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control bg-light" value="<?= $NitSesion ?>" readonly>
                    <input type="hidden" name="NitEmpresa" value="<?= $NitSesion ?>">
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Sucursal</label>
                <?php if($pAdmin): ?>
                    <select name="Sucursal" id="Sucursal" class="form-select" required>
                        <option value="">Primero seleccione empresa...</option>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control bg-light" value="<?= $SucursalSesion ?>" readonly>
                    <input type="hidden" name="Sucursal" value="<?= $SucursalSesion ?>">
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Tercero Responsable</label>
                <?php if($pAdmin): ?>
                    <select name="CedulaNit" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($tercerosArray as $t): ?>
                            <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control bg-light" value="<?= $UsuarioSesion ?>" readonly>
                    <input type="hidden" name="CedulaNit" value="<?= $UsuarioSesion ?>">
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Medio de Pago</label>
                    <select name="IdMedio" class="form-select" required>
                        <?php foreach($mediosArray as $m): ?>
                            <option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Monto a Registrar</label>
                    <input type="number" step="0.01" name="Monto" class="form-control form-control-lg border-primary" placeholder="0.00" required>
                </div>
            </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button type="submit" class="btn btn-primary px-5">Guardar Transferencia</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Carga dinámica de sucursales vía AJAX
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let sucSelect = document.getElementById("Sucursal");
    if (!nit) {
        sucSelect.innerHTML = '<option value="">Seleccione empresa...</option>';
        return;
    }
    fetch("?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res => res.json())
        .then(data => {
            sucSelect.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => {
                sucSelect.innerHTML += `<option value="${s.NroSucursal}">${s.Direccion}</option>`;
            });
        });
}

// Actualización de Checkboxes de revisión
function actualizarCheck(idTransfer, campo, checkbox) {
    let valor = checkbox.checked ? 1 : 0;
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=actualizar_check&idTransfer=${idTransfer}&campo=${campo}&valor=${valor}`
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== 'ok') {
            alert("Error: " + data.msg);
            checkbox.checked = !checkbox.checked; // Revertir si falla
        } else {
            // Recargar para actualizar el Total en el footer
            location.reload();
        }
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>