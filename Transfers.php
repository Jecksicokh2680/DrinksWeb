<?php
require 'Conexion.php';
require 'helpers.php';

session_start();
session_regenerate_id(true); // Previene secuestro de sesión

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

    if (!isset($_SESSION['Autorizaciones'])) {
        $_SESSION['Autorizaciones'] = [];
    }

    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) {
        return $_SESSION['Autorizaciones'][$key];
    }

    $stmt = $mysqli->prepare("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE CedulaNit = ? AND Nro_Auto = ?
    ");
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
    if ($nit === '') {
        echo json_encode([]);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT NroSucursal, Direccion
        FROM empresa_sucursal
        WHERE Nit = ? AND Estado = 1
        ORDER BY Direccion
    ");
    $stmt->bind_param("s", $nit);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

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
    $campo      = $_POST['campo'] ?? '';
    $valor      = isset($_POST['valor']) && $_POST['valor'] == "1" ? 1 : 0;

    $puedeRevisarLogistica = Autorizacion($UsuarioSesion, "0009") === "SI";
    $puedeRevisarGerencia  = Autorizacion($UsuarioSesion, "0010") === "SI";

    if (!in_array($campo, ['RevisadoLogistica','RevisadoGerencia']) || $idTransfer <= 0) {
        echo json_encode(['status'=>'error','msg'=>'Campo inválido']);
        exit;
    }

    if (($campo === 'RevisadoLogistica' && !$puedeRevisarLogistica) ||
        ($campo === 'RevisadoGerencia'  && !$puedeRevisarGerencia)) {
        echo json_encode(['status'=>'error','msg'=>'No autorizado']);
        exit;
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
            if ($stmt->execute()) {
                $msg = "✓ Transferencia registrada correctamente.";
            } else {
                $msg = "❌ Error al registrar transferencia: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

/* ============================================================
   AUTORIZACIÓN 0003
============================================================ */
$aut0003 = Autorizacion($UsuarioSesion, "0003") === "SI";

/* ============================================================
   FILTROS DE TERCEROS
============================================================ */
$filtroTerceroTabla = '';
if (Autorizacion($UsuarioSesion, "0012") === "SI") {
    $filtroTerceroTabla = $_GET['filtroTercero'] ?? '';
}

// Para el modal de nueva transferencia (lista independiente)
$tercerosModal = $mysqli->query("
    SELECT CedulaNit, Nombre
    FROM terceros
    WHERE Estado=1
    ORDER BY Nombre
")->fetch_all(MYSQLI_ASSOC);

/* ============================================================
   CARGA DE LISTAS DE EMPRESAS Y MEDIOS DE PAGO
============================================================ */
$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado=1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);
$mediosArray   = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);

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
           t.RevisadoGerencia,
           t.CedulaNit
    FROM Relaciontransferencias t
    INNER JOIN empresa e ON e.Nit = t.NitEmpresa
    INNER JOIN empresa_sucursal s ON s.Nit = t.NitEmpresa AND s.NroSucursal = t.Sucursal
    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
    INNER JOIN mediopago m ON m.IdMedio = t.IdMedio
";

$where = [];
if (!$aut0003) {
    $where[] = "t.CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}

if ($filtroTerceroTabla !== '') {
    $where[] = "t.CedulaNit = '".$mysqli->real_escape_string($filtroTerceroTabla)."'";
}

if (count($where) > 0) {
    $consultaSQL .= " WHERE " . implode(" AND ", $where);
}

$consultaSQL .= " ORDER BY t.Fecha DESC, t.Hora asc";
$transferencias = $mysqli->query($consultaSQL);

/* ============================================================
   TOTAL MONTOS
============================================================ */
$totalSQL = "SELECT SUM(Monto) AS Total FROM Relaciontransferencias WHERE (RevisadoLogistica+RevisadoGerencia)>=1";

if (!$aut0003) {
    $totalSQL .= " AND CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}
if ($filtroTerceroTabla !== '') {
    $totalSQL .= " AND CedulaNit = '".$mysqli->real_escape_string($filtroTerceroTabla)."'";
}

$totalMontos = $mysqli->query($totalSQL)->fetch_assoc()['Total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrar Transferencias</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background-color: #f3f4f6; }
.card { border-radius: 1rem; }
.table thead { background-color: #343a40; color: #fff; }
.table-responsive { overflow-x:auto; }
</style>
<script>
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let suc = document.getElementById("Sucursal");

    if (!nit) {
        suc.innerHTML = '<option value="">Seleccione una empresa...</option>';
        return;
    }

    suc.innerHTML = '<option>Cargando...</option>';

    fetch("Transfers.php?ajax=sucursales&nit=" + encodeURIComponent(nit))
        .then(res => res.json())
        .then(data => {
            suc.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => {
                suc.innerHTML += `<option value="${s.NroSucursal}">${s.Direccion}</option>`;
            });
        });
}

function actualizarCheck(idTransfer, campo, checkbox) {
    fetch('Transfers.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax=actualizar_check&idTransfer=' + idTransfer + '&campo=' + campo + '&valor=' + (checkbox.checked ? 1 : 0)
    }).then(res => res.json()).then(data => {
        if(data.status!=='ok') alert(data.msg);
        location.reload();
    });
}

function filtrarTercero(select) {
    window.location.href = 'Transfers.php?filtroTercero=' + select.value;
}
</script>
</head>
<body>
<div class="container mt-5">

    <?php if ($msg != ""): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="mb-4">
        <?php if (Autorizacion($UsuarioSesion, "0007") === "SI"): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                ➕ Nueva Transferencia
            </button>
        <?php else: ?>
            <button class="btn btn-secondary" disabled>🚫 No autorizado</button>
        <?php endif; ?>
    </div>

    <?php if (Autorizacion($UsuarioSesion, "0012") === "SI"): ?>
        <div class="mb-4">
            <label>Filtrar tabla por Tercero:</label>
            <select class="form-select" onchange="filtrarTercero(this)">
                <option value="">Todos</option>
                <?php
                $tercerosTabla = $mysqli->query("
                    SELECT DISTINCT t.CedulaNit, tr.Nombre
                    FROM Relaciontransferencias t
                    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
                    ORDER BY tr.Nombre
                ")->fetch_all(MYSQLI_ASSOC);
                foreach($tercerosTabla as $t): ?>
                    <option value="<?= $t['CedulaNit'] ?>" <?= ($t['CedulaNit']==$filtroTerceroTabla)?'selected':'' ?>>
                        <?= $t['Nombre'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="card shadow p-4 mb-4">
        <h4 class="mb-3">Transferencias Registradas</h4>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Empresa</th>
                        <th>Sucursal</th>
                        <th>Tercero</th>
                        <th>Medio</th>
                        <th>Monto</th>
                        <th>RevisadoLogistica</th>
                        <th>RevisadoGerencia</th>
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
                                <input type="checkbox" <?= $row['RevisadoLogistica'] ? 'checked' : '' ?> 
                                <?= $puedeRevisarLogistica?'':'disabled' ?> 
                                onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoLogistica',this)">
                            </td>
                            <td class="text-center">
                                <input type="checkbox" <?= $row['RevisadoGerencia'] ? 'checked' : '' ?> 
                                <?= $puedeRevisarGerencia?'':'disabled' ?> 
                                onchange="actualizarCheck(<?= $row['IdTransfer'] ?>,'RevisadoGerencia',this)">
                            </td>
                            <td class="text-center">
                                <?php
                                    $puedeBorrar = Autorizacion($UsuarioSesion, "0006") === "SI";
                                    if($puedeBorrar || $row['CedulaNit']==$UsuarioSesion): ?>
                                    <a href="?borrar=<?= intval($row['IdTransfer']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar esta transferencia?')">🗑</a>
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
<?php
$NitModal    = $aut0003 ? '' : $NitSesion;
$SucursalModal = $aut0003 ? '' : $SucursalSesion;
$TerceroModal = $aut0003 ? '' : $UsuarioSesion;
?>
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Registrar Nueva Transferencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
            <div class="col-md-3">
                <label>Fecha</label>
                <input type="date" class="form-control" name="Fecha" value="<?= $FechaHoy ?>" required>
            </div>
            <div class="col-md-3">
                <label>Hora</label>
                <input type="time" class="form-control" name="Hora" value="<?= $HoraHoy ?>" required>
            </div>
            <div class="col-md-3">
                <label>Empresa</label>
                <select class="form-select" name="NitEmpresa" id="NitEmpresa"
                    <?= $aut0003 ? 'onchange="cargarSucursales()"' : 'disabled' ?> required>
                    <?php foreach($empresasArray as $e): ?>
                        <option value="<?= $e['Nit'] ?>" <?= ($e['Nit']==$NitModal)?'selected':'' ?>>
                            <?= $e['NombreComercial'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Sucursal</label>
                <select class="form-select" name="Sucursal" id="Sucursal" <?= $aut0003?'':'disabled' ?> required>
                    <?php if(!$aut0003): ?>
                        <option value="<?= $SucursalSesion ?>"><?= $SucursalSesion ?></option>
                    <?php else: ?>
                        <option value="">Seleccione empresa primero...</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label>Tercero</label>
                <select class="form-select" name="CedulaNit" <?= $aut0003?'':'disabled' ?> required>
                    <?php if(!$aut0003): ?>
                        <option value="<?= $UsuarioSesion ?>"><?= $UsuarioSesion ?></option>
                    <?php else: ?>
                        <option value="">Seleccione...</option>
                        <?php foreach($tercerosModal as $t): ?>
                            <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Medio</label>
                <select class="form-select" name="IdMedio" required>
                    <option value="">Seleccione...</option>
                    <?php foreach($mediosArray as $m): ?>
                        <option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label>Monto</label>
                <input type="number" step="0.01" class="form-control" name="Monto" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="guardarTransferencia" class="btn btn-success">Guardar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
