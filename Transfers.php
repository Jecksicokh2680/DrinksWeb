<?php
require 'Conexion.php';
require 'helpers.php';

session_start();

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
   FUNCIÓN AUTORIZACIÓN
   ============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli;

    $stmt = $mysqli->prepare("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE CedulaNit = ? AND Nro_Auto = ?
    ");
    if (!$stmt) return "NO";

    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['Swich'] ?? "NO";
    }
    return "NO";
}

/* ============================================================
   AJAX — DEVOLVER SUCURSALES POR NIT
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === "sucursales") {
    header("Content-Type: application/json; charset=utf-8");

    $nit = $_GET['nit'] ?? '';

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
   ELIMINAR TRANSFERENCIA — SOLO SI 0002 = SI
   ============================================================ */
if (isset($_GET['borrar'])) {

    if (Autorizacion($UsuarioSesion, "0002") !== "SI") {
        $msg = "❌ No tiene autorización para eliminar transferencias.";
    } else {

        $idBorrar = intval($_GET['borrar']);
        $del = $mysqli->prepare("DELETE FROM Relaciontransferencias WHERE IdTransfer = ?");
        $del->bind_param("i", $idBorrar);

        if ($del->execute()) {
            // $msg = "✓ Transferencia eliminada correctamente.";
        } else {
            $msg = "❌ Error al eliminar: " . $del->error;
        }
        $del->close();
    }
}

/* ============================================================
   REGISTRAR TRANSFERENCIA — SOLO SI 0002 = SI
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardarTransferencia'])) {

    if (Autorizacion($UsuarioSesion, "0002") !== "SI") {
        $msg = "❌ No tiene autorización para registrar transferencias.";
    } else {

        // Sanitizar inputs
        $Fecha      = limpiar($_POST['Fecha'] ?? '');
        $Hora       = limpiar($_POST['Hora'] ?? '');
        $NitEmpresa = limpiar($_POST['NitEmpresa'] ?? '');
        $Sucursal   = limpiar($_POST['Sucursal'] ?? '');
        $CedulaNit  = limpiar($_POST['CedulaNit'] ?? '');
        $IdMedio    = intval($_POST['IdMedio'] ?? 0);
        $Monto      = floatval($_POST['Monto'] ?? 0);

        // Si NO tiene autorización, forzar valores sesión
        if (Autorizacion($UsuarioSesion, "0002") == "NO") {
            $NitEmpresa = $NitSesion;
            $Sucursal   = $SucursalSesion;
            $CedulaNit  = $UsuarioSesion;
        }

        if ($Fecha === "" || $Hora === "" || 
            $NitEmpresa === "" || $Sucursal === "" || $CedulaNit === "" || 
            $IdMedio <= 0 || $Monto <= 0) {

            $msg = "❌ Faltan datos obligatorios.";
        } else {

            $stmt = $mysqli->prepare("
                INSERT INTO Relaciontransferencias
                (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssid",
                $Fecha, $Hora, $NitEmpresa, $Sucursal, $CedulaNit, $IdMedio, $Monto
            );

            if ($stmt->execute()) {
               // $msg = "✓ Transferencia registrada correctamente.";
            } else {
                $msg = "❌ Error: " . $stmt->error;
            }

            $stmt->close();
        }
    }
}

/* ============================================================
   LISTAR TRANSFERENCIAS
   ============================================================ */
$transferencias = $mysqli->query("
    SELECT t.IdTransfer, t.Fecha, t.Hora,
           e.NombreComercial,
           s.Direccion AS Sucursal,
           tr.Nombre AS Tercero,
           m.Nombre AS Medio,
           t.Monto
    FROM Relaciontransferencias t
    INNER JOIN empresa e ON e.Nit = t.NitEmpresa
    INNER JOIN empresa_sucursal s ON s.Nit = t.NitEmpresa AND s.NroSucursal = t.Sucursal
    INNER JOIN terceros tr ON tr.CedulaNit = t.CedulaNit
    INNER JOIN mediopago m ON m.IdMedio = t.IdMedio
    ORDER BY t.Fecha DESC, t.Hora DESC
");

/* ============================================================
   CARGA DE LISTAS
   ============================================================ */
$empresas = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado = 1 ORDER BY NombreComercial");
$terceros = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado = 1 ORDER BY Nombre");
$medios   = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado = 1 ORDER BY Nombre");

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
.modal-header { background-color: #0d6efd; color: white; }
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
</script>
</head>

<body>
<div class="container mt-5">

    <div class="d-flex justify-content-between mb-3">
        <h3>Registrar Transferencias</h3>
        <a href="Panel.php" class="btn btn-secondary">← Volver al Panel</a>
    </div>

    <?php if ($msg != ""): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="mb-4">
        <?php if (Autorizacion($UsuarioSesion, "0002") === "SI"): ?>
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
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
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
                                <?php if (Autorizacion($UsuarioSesion, "0002") === "SI"): ?>
                                    <a href="?borrar=<?= intval($row['IdTransfer']) ?>"
                                       onclick="return confirm('¿Eliminar esta transferencia?')"
                                       class="btn btn-danger btn-sm">🗑</a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm" disabled>🚫</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    <?php if ($transferencias->num_rows == 0): ?>
                        <tr><td colspan="8" class="text-center">No hay transferencias registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL NUEVA TRANSFERENCIA
=============================================================== -->
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Nueva Transferencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

            <input type="hidden" name="guardarTransferencia" value="1">

            <div class="mb-3">
                <label class="form-label">Fecha</label>
                <input type="date" name="Fecha" class="form-control" value="<?= $FechaHoy ?>" readonly>
            </div>

            <div class="mb-3">
                <label class="form-label">Hora</label>
                <input type="time" name="Hora" class="form-control" value="<?= $HoraHoy ?>" readonly>
            </div>

            <?php $puedeCambiar = Autorizacion($UsuarioSesion, "0002") === "SI"; ?>

            <!-- EMPRESA -->
            <div class="mb-3">
                <label class="form-label">Empresa</label>

                <?php if ($puedeCambiar): ?>
                    <select name="NitEmpresa" id="NitEmpresa" class="form-select" onchange="cargarSucursales()" required>
                        <option value="">Seleccione...</option>
                        <?php while ($e = $empresas->fetch_assoc()): ?>
                            <option value="<?= $e['Nit'] ?>">
                                <?= $e['NombreComercial'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                <?php else: ?>
                    <input type="text" class="form-control" value="<?= $NitSesion ?>" readonly>
                    <input type="hidden" name="NitEmpresa" value="<?= $NitSesion ?>">
                <?php endif; ?>
            </div>

            <!-- SUCURSAL -->
            <div class="mb-3">
                <label class="form-label">Sucursal</label>

                <?php if ($puedeCambiar): ?>
                    <select name="Sucursal" id="Sucursal" class="form-select" required>
                        <option value="">Seleccione una empresa...</option>
                    </select>

                <?php else: ?>
                    <input type="text" class="form-control" value="<?= $SucursalSesion ?>" readonly>
                    <input type="hidden" name="Sucursal" value="<?= $SucursalSesion ?>">
                <?php endif; ?>
            </div>

            <!-- TERCERO -->
            <div class="mb-3">
                <label class="form-label">Tercero</label>

                <?php if ($puedeCambiar): ?>
                    <select name="CedulaNit" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php while ($t = $terceros->fetch_assoc()): ?>
                            <option value="<?= $t['CedulaNit'] ?>">
                                <?= $t['Nombre'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                <?php else: ?>
                    <input type="text" class="form-control" value="<?= $UsuarioSesion ?>" readonly>
                    <input type="hidden" name="CedulaNit" value="<?= $UsuarioSesion ?>">
                <?php endif; ?>

            </div>

            <!-- MEDIO PAGO -->
            <div class="mb-3">
                <label class="form-label">Medio de Pago</label>
                <select name="IdMedio" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php while ($m = $medios->fetch_assoc()): ?>
                        <option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- MONTO -->
            <div class="mb-3">
                <label class="form-label">Monto</label>
                <input type="number" step="0.01" name="Monto" class="form-control" required>
            </div>
        </div>

        <div class="modal-footer">
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
