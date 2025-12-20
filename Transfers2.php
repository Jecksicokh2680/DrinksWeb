<?php
// ============================================
// transferencias.php
// ============================================

require 'Conexion.php';
session_start();
session_regenerate_id(true);

date_default_timezone_set('America/Bogota');

// ===================== SESIÓN ======================
if (!isset($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

// ====================================================
//   GUARDAR TRANSFERENCIA
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $Fecha      = $_POST['Fecha'];
    $Hora       = $_POST['Hora'];
    $NitEmpresa = $_POST['NitEmpresa'];
    $Sucursal   = $_POST['Sucursal'];
    $CedulaNit  = $_POST['CedulaNit'];
    $IdMedio    = $_POST['IdMedio'];
    $Monto      = $_POST['Monto'];

    $sql = "INSERT INTO Relaciontransferencias 
            (Fecha, Hora, NitEmpresa, Sucursal, CedulaNit, IdMedio, Monto)
            VALUES (?,?,?,?,?,?,?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$Fecha, $Hora, $NitEmpresa, $Sucursal, $CedulaNit, $IdMedio, $Monto]);

    $msg = "Transferencia registrada correctamente";
}

// ====================================================
//   CONSULTAS PARA EL FORMULARIO
// ====================================================

// Empresas
$empresas = $pdo->query("SELECT Nit, RazonSocial FROM empresa WHERE Estado = 1")->fetchAll(PDO::FETCH_ASSOC);

// Medios de Pago
$medios = $pdo->query("SELECT IdMedio, Nombre FROM mediopago WHERE Estado = 1")->fetchAll(PDO::FETCH_ASSOC);

// Terceros
$terceros = $pdo->query("SELECT CedulaNit, Nombre FROM terceros WHERE Estado = 1")->fetchAll(PDO::FETCH_ASSOC);

// Sucursales (las traemos todas; en tu proyecto puedes filtrarlas por empresa con AJAX si quieres)
$sucursales = $pdo->query("SELECT Nit, NroSucursal FROM empresa_sucursal WHERE Estado = 1")->fetchAll(PDO::FETCH_ASSOC);

// ====================================================
//   CONSULTA PRINCIPAL (LISTADO)
// ====================================================
$sqlLista = "
    SELECT 
        t.IdTransfer, t.Fecha, t.Hora, t.Monto,
        mp.Nombre AS MedioPago,
        tc.Nombre AS TerceroNombre,
        em.RazonSocial,
        es.NroSucursal
    FROM Relaciontransferencias t
    INNER JOIN mediopago mp ON t.IdMedio = mp.IdMedio
    INNER JOIN terceros tc ON t.CedulaNit = tc.CedulaNit
    INNER JOIN empresa em ON t.NitEmpresa = em.Nit
    INNER JOIN empresa_sucursal es ON es.Nit = t.NitEmpresa AND es.NroSucursal = t.Sucursal
    ORDER BY t.Fecha DESC, t.Hora DESC
";

$lista = $pdo->query($sqlLista)->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Transferencias</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        input, select { padding: 5px; margin-bottom: 8px; width: 250px; }
        table { border-collapse: collapse; width: 100%; margin-top: 30px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #eee; }
        .msg { padding: 10px; margin: 10px 0; background: #c6ffcf; border: 1px solid #63bd6e; }
    </style>
</head>

<body>

<h2>Registrar Transferencia</h2>

<?php if (isset($msg)): ?>
    <div class="msg">✔ <?= $msg ?></div>
<?php endif; ?>

<form method="POST">

    Fecha:<br>
    <input type="date" name="Fecha" required><br>

    Hora:<br>
    <input type="time" name="Hora" required><br>

    Empresa:<br>
    <select name="NitEmpresa" required>
        <option value="">Seleccione</option>
        <?php foreach ($empresas as $e): ?>
            <option value="<?= $e['Nit'] ?>"><?= $e['RazonSocial'] ?></option>
        <?php endforeach; ?>
    </select><br>

    Sucursal:<br>
    <select name="Sucursal" required>
        <?php foreach ($sucursales as $s): ?>
            <option value="<?= $s['NroSucursal'] ?>">
                <?= $s['Nit'] ?> - Sucursal <?= $s['NroSucursal'] ?>
            </option>
        <?php endforeach; ?>
    </select><br>

    Tercero:<br>
    <select name="CedulaNit" required>
        <?php foreach ($terceros as $t): ?>
            <option value="<?= $t['CedulaNit'] ?>">
                <?= $t['CedulaNit'] ?> - <?= $t['Nombre'] ?>
            </option>
        <?php endforeach; ?>
    </select><br>

    Medio de Pago:<br>
    <select name="IdMedio" required>
        <?php foreach ($medios as $m): ?>
            <option value="<?= $m['IdMedio'] ?>"><?= $m['Nombre'] ?></option>
        <?php endforeach; ?>
    </select><br>

    Monto:<br>
    <input type="number" step="0.01" name="Monto" required><br><br>

    <button type="submit">Guardar Transferencia</button>

</form>

<hr>

<h2>Listado de Transferencias</h2>

<table>
    <tr>
        <th>ID</th>
        <th>Fecha</th>
        <th>Hora</th>
        <th>Monto</th>
        <th>Medio Pago</th>
        <th>Tercero</th>
        <th>Empresa</th>
        <th>Sucursal</th>
    </tr>

    <?php foreach ($lista as $row): ?>
        <tr>
            <td><?= $row['IdTransfer'] ?></td>
            <td><?= $row['Fecha'] ?></td>
            <td><?= $row['Hora'] ?></td>
            <td><?= number_format($row['Monto'], 2) ?></td>
            <td><?= $row['MedioPago'] ?></td>
            <td><?= $row['TerceroNombre'] ?></td>
            <td><?= $row['RazonSocial'] ?></td>
            <td><?= $row['NroSucursal'] ?></td>
        </tr>
    <?php endforeach; ?>

</table>

</body>
</html>
