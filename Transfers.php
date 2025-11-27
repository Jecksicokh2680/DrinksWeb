<?php
session_start();
require 'Conexion.php';

/* ============================================================
   CARGAR VARIABLES DE SESIÓN
   ============================================================ */
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

/* ============================================================
   FECHA Y HORA DE BOGOTÁ (AUTOMÁTICAS)
   ============================================================ */
date_default_timezone_set('America/Bogota');

$FechaHoy = date("Y-m-d");
$HoraHoy  = date("H:i");   // <-- Formato correcto para input type="time"

/* ============================================================
   AJAX: LISTAR SUCURSALES
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] == 'sucursales' && isset($_GET['nit'])) {

    $nit = $mysqli->real_escape_string($_GET['nit']);

    $sql = "SELECT NroSucursal, Direccion 
            FROM empresa_sucursal 
            WHERE Nit = '$nit' AND Estado = 1";

    $res = $mysqli->query($sql);
    $data = [];

    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ============================================================
   PROCESAR FORMULARIO
   ============================================================ */
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $Fecha      = $_POST['Fecha'];
    $Hora       = $_POST['Hora'];
    $NitEmpresa = $_POST['NitEmpresa'];
    $Sucursal   = $_POST['Sucursal'];
    $CedulaNit  = $_POST['CedulaNit'];
    $IdMedio    = $_POST['IdMedio'];
    $Monto      = $_POST['Monto'];

    $stmt = $mysqli->prepare("
        INSERT INTO Relaciontransferencias
        (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("sssssid",
        $Fecha, $Hora, $NitEmpresa, $Sucursal, $CedulaNit, $IdMedio, $Monto
    );

    if ($stmt->execute()) {
        $msg = "✓ Transferencia registrada correctamente.";
    } else {
        $msg = "❌ Error: " . $stmt->error;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Registrar Transferencia</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<script>
// ============================================================
// Cargar sucursales por AJAX y seleccionar automáticamente
// ============================================================
function cargarSucursales() {
    let nit = document.getElementById("NitEmpresa").value;
    let suc = document.getElementById("Sucursal");
    suc.innerHTML = '<option>Cargando...</option>';
    fetch("Transfers.php?ajax=sucursales&nit=" + nit)
        .then(res => res.json())
        .then(data => {
            suc.innerHTML = '<option value="">Seleccione...</option>';
            data.forEach(s => {
                let selected = (s.NroSucursal === "<?= $SucursalSesion ?>") ? "selected" : "";
                suc.innerHTML += `<option value="${s.NroSucursal}" ${selected}>${s.Direccion}</option>`;
            });
        });
}
</script>

</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow p-4">

        <h3 class="mb-3">Registrar Transferencia</h3>

        <?php if ($msg != ""): ?>
            <div class="alert alert-info"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">

            <!-- FECHA AUTOMÁTICA -->
            <div class="mb-3">
                <label class="form-label">Fecha</label>
                <input type="date" name="Fecha" class="form-control" value="<?= $FechaHoy ?>" readonly>
            </div>

            <!-- HORA AUTOMÁTICA -->
            <div class="mb-3">
                <label class="form-label">Hora</label>
                <input type="time" name="Hora" class="form-control" value="<?= $HoraHoy ?>" readonly>
            </div>

            <!-- EMPRESA -->
            <div class="mb-3">
                <label class="form-label">Empresa</label>
                <select name="NitEmpresa" id="NitEmpresa" onchange="cargarSucursales()" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php
                    $r = $mysqli->query("SELECT Nit, NombreComercial FROM empresa WHERE Estado = 1 ORDER BY NombreComercial");
                    while ($e = $r->fetch_assoc()):
                    ?>
                        <option value="<?= $e['Nit'] ?>"
                            <?= ($e['Nit'] == $NitSesion ? 'selected' : '') ?>>
                            <?= $e['NombreComercial'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- SUCURSAL -->
            <div class="mb-3">
                <label class="form-label">Sucursal</label>
                <select name="Sucursal" id="Sucursal" class="form-select" required>
                    <option value="">Seleccione una empresa primero...</option>
                </select>

                <script>
                <?php if ($NitSesion != ""): ?>
                    cargarSucursales();
                <?php endif; ?>
                </script>
            </div>

            <!-- TERCERO -->
            <div class="mb-3">
                <label class="form-label">Tercero</label>
                <select name="CedulaNit" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php
                    $t = $mysqli->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado=1 ORDER BY Nombre");
                    while ($ter = $t->fetch_assoc()):
                    ?>
                        <option value="<?= $ter['CedulaNit'] ?>"
                            <?= ($ter['CedulaNit'] == $UsuarioSesion ? 'selected' : '') ?>>
                            <?= $ter['Nombre'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- MEDIO DE PAGO -->
            <div class="mb-3">
                <label class="form-label">Medio de Pago</label>
                <select name="IdMedio" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php
                    $m = $mysqli->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado='1'");
                    while ($med = $m->fetch_assoc()):
                    ?>
                        <option value="<?= $med['IdMedio'] ?>"><?= $med['Nombre'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- MONTO -->
            <div class="mb-3">
                <label class="form-label">Monto</label>
                <input type="number" step="0.01" name="Monto" class="form-control" required>
            </div>

            <button class="btn btn-primary w-100">Guardar Transferencia</button>

        </form>

    </div>
</div>

</body>
</html>
