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
   ACTUALIZAR CHECKBOX RevisadoLogistica / RevisadoGerencia
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Autorizacion($UsuarioSesion, "0002") === "SI") {
        if (isset($_POST['updateLogistica'])) {
            $id = intval($_POST['id'] ?? 0);
            $valor = ($_POST['valor'] ?? 0) ? 1 : 0;
            $stmt = $mysqli->prepare("UPDATE Relaciontransferencias SET RevisadoLogistica=? WHERE IdTransfer=?");
            $stmt->bind_param("ii", $valor, $id);
            $stmt->execute();
            $stmt->close();
            exit;
        }

        if (isset($_POST['updateGerencia'])) {
            $id = intval($_POST['id'] ?? 0);
            $valor = ($_POST['valor'] ?? 0) ? 1 : 0;
            $stmt = $mysqli->prepare("UPDATE Relaciontransferencias SET RevisadoGerencia=? WHERE IdTransfer=?");
            $stmt->bind_param("ii", $valor, $id);
            $stmt->execute();
            $stmt->close();
            exit;
        }

        if (isset($_POST['guardarTransferencia'])) {
            // ================== REGISTRAR NUEVA TRANSFERENCIA ==================
            $Fecha      = limpiar($_POST['Fecha'] ?? '');
            $Hora       = limpiar($_POST['Hora'] ?? '');
            $NitEmpresa = limpiar($_POST['NitEmpresa'] ?? '');
            $Sucursal   = limpiar($_POST['Sucursal'] ?? '');
            $CedulaNit  = limpiar($_POST['CedulaNit'] ?? '');
            $IdMedio    = intval($_POST['IdMedio'] ?? 0);
            $Monto      = floatval($_POST['Monto'] ?? 0);

            if ($Fecha === "" || $Hora === "" || $NitEmpresa === "" || $Sucursal === "" || $CedulaNit === "" || $IdMedio <= 0 || $Monto <= 0) {
                $msg = "❌ Faltan datos obligatorios.";
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO Relaciontransferencias
                    (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssssid", $Fecha, $Hora, $NitEmpresa, $Sucursal, $CedulaNit, $IdMedio, $Monto);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

/* ============================================================
   FECHA Y HORA (BOGOTÁ)
============================================================ */
date_default_timezone_set('America/Bogota');
$FechaHoy = date("Y-m-d");
$HoraHoy  = date("H:i");

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

if (Autorizacion($UsuarioSesion, "0002") === "NO") {
    $consultaSQL .= " WHERE t.CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}

$consultaSQL .= " ORDER BY t.Fecha DESC, t.Hora DESC";
$transferencias = $mysqli->query($consultaSQL);

/* ============================================================
   TOTAL MONTO DE TRANSFERENCIAS REVISADAS
============================================================ */
$totalSQL = "
    SELECT SUM(Monto) AS Total
    FROM Relaciontransferencias
    WHERE (RevisadoLogistica + RevisadoGerencia) >= 1
";
if (Autorizacion($UsuarioSesion, "0002") === "NO") {
    $totalSQL .= " AND CedulaNit = '".$mysqli->real_escape_string($UsuarioSesion)."'";
}
$totalMontos = $mysqli->query($totalSQL)->fetch_assoc()['Total'] ?? 0;

/* ============================================================
   CARGA DE LISTAS
============================================================ */
$empresasArray = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado=1 ORDER BY NombreComercial")->fetch_all(MYSQLI_ASSOC);
$tercerosArray = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$mediosArray   = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado=1 ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$puedeCambiar = Autorizacion($UsuarioSesion, "0002") === "SI";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transferencias</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background-color: #f3f4f6; }
.table thead { background-color: #343a40; color: #fff; }
.table-responsive { overflow-x:auto; }
.modal-header { background-color: #0d6efd; color: #fff; }
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".revisadoLogistica").forEach(cb => {
        cb.addEventListener("change", function() {
            fetch("", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "updateLogistica=1&id=" + this.dataset.id + "&valor=" + (this.checked ? 1 : 0)
            }).then(()=>location.reload());
        });
    });
    document.querySelectorAll(".revisadoGerencia").forEach(cb => {
        cb.addEventListener("change", function() {
            fetch("", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "updateGerencia=1&id=" + this.dataset.id + "&valor=" + (this.checked ? 1 : 0)
            }).then(()=>location.reload());
        });
    });
});

function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let suc = document.getElementById("Sucursal");
    if (!nit) { suc.innerHTML='<option value="">Seleccione empresa</option>'; return; }
    suc.innerHTML='<option>Cargando...</option>';
    fetch("Transfers.php?ajax=sucursales&nit="+encodeURIComponent(nit))
        .then(res=>res.json())
        .then(data=>{
            suc.innerHTML='<option value="">Seleccione...</option>';
            data.forEach(s=>suc.innerHTML+=`<option value="${s.NroSucursal}">${s.Direccion}</option>`);
        });
}
</script>
</head>
<body>
<div class="container mt-5">

    <div class="mb-4">
        <?php if (Autorizacion($UsuarioSesion, "0007")==="SI"): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                ➕ Nueva Transferencia
            </button>
        <?php else: ?>
            <button class="btn btn-secondary" disabled>🚫 No autorizado</button>
        <?php endif; ?>
    </div>

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
                        <th>Revisado Logistica</th>
                        <th>Revisado Gerencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row=$transferencias->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['Fecha'] ?></td>
                            <td><?= $row['Hora'] ?></td>
                            <td><?= $row['NombreComercial'] ?></td>
                            <td><?= $row['Sucursal'] ?></td>
                            <td><?= $row['Tercero'] ?></td>
                            <td><?= $row['Medio'] ?></td>
                            <td class="text-end"><?= number_format($row['Monto'],2) ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="revisadoLogistica" data-id="<?= $row['IdTransfer'] ?>"
                                    <?= $row['RevisadoLogistica']?'checked':'' ?>
                                    <?= $puedeCambiar?'':'disabled' ?>>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="revisadoGerencia" data-id="<?= $row['IdTransfer'] ?>"
                                    <?= $row['RevisadoGerencia']?'checked':'' ?>
                                    <?= $puedeCambiar?'':'disabled' ?>>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if($transferencias->num_rows==0): ?>
                        <tr><td colspan="9" class="text-center">No hay transferencias registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" class="text-end">Total Revisadas:</th>
                        <th class="text-end"><?= number_format($totalMontos,2) ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- MODAL NUEVA TRANSFERENCIA -->
<div class="modal fade" id="modalTransferencia" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Nueva Transferencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="guardarTransferencia" value="1">

            <div class="row g-2 mb-3">
                <div class="col">
                    <label>Fecha</label>
                    <input type="date" name="Fecha" class="form-control" value="<?= $FechaHoy ?>" readonly>
                </div>
                <div class="col">
                    <label>Hora</label>
                    <input type="time" name="Hora" class="form-control" value="<?= $HoraHoy ?>" readonly>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col">
                    <label>Empresa</label>
                    <?php if($puedeCambiar): ?>
                        <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
                            <option value="">Seleccione...</option>
                            <?php foreach($empresasArray as $e): ?>
                                <option value="<?= $e['Nit'] ?>"><?= $e['NombreComercial'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= $NitSesion ?>" readonly>
                        <input type="hidden" name="NitEmpresa" value="<?= $NitSesion ?>">
                    <?php endif; ?>
                </div>
                <div class="col">
                    <label>Sucursal</label>
                    <?php if($puedeCambiar): ?>
                        <select name="Sucursal" id="Sucursal" class="form-select" required>
                            <option value="">Seleccione empresa...</option>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= $SucursalSesion ?>" readonly>
                        <input type="hidden" name="Sucursal" value="<?= $SucursalSesion ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label>Tercero</label>
                <?php if($puedeCambiar): ?>
                    <select name="CedulaNit" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($tercerosArray as $t): ?>
                            <option value="<?= $t['CedulaNit'] ?>"><?= $t['Nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="form-control" value="<?= $UsuarioSesion ?>" readonly>
                    <input type="hidden" name="CedulaNit" value="<?= $UsuarioSesion ?>">
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label>Medio de Pago</label>
                <select name="IdMedio" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php foreach($mediosArray as $m): ?>
                        <option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label>Monto</label>
                <input type="number" step="0.01" min="0.01" name="Monto" class="form-control" placeholder="0.00" required>
            </div>

        </div>
        <div class="modal-footer d-flex justify-content-end">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Transferencia</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
