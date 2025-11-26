<?php
require 'Conexion.php';

$sql = "SELECT Nit, RazonSocial, NombreComercial, Alias, Regimen, Email, WebSite, FechaCreacion, Estado 
        FROM empresa ORDER BY RazonSocial ASC";

$result = $mysqli->query($sql);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Empresas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h3 class="mb-3">Listado de Empresas</h3>

    <table class="table table-striped table-bordered">
        <thead class="table-dark">
            <tr>
                <th>NIT</th>
                <th>Razón Social</th>
                <th>Nombre Comercial</th>
                <th>Alias</th>
                <th>Régimen</th>
                <th>Email</th>
                <th>Website</th>
                <th>Fecha Creación</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['Nit'] ?></td>
                        <td><?= $row['RazonSocial'] ?></td>
                        <td><?= $row['NombreComercial'] ?></td>
                        <td><?= $row['Alias'] ?></td>
                        <td><?= $row['Regimen'] ?></td>
                        <td><?= $row['Email'] ?></td>
                        <td><?= $row['WebSite'] ?></td>
                        <td><?= $row['FechaCreacion'] ?></td>
                        <td><?= $row['Estado'] == 1 ? "Activa" : "Inactiva" ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="9" class="text-center">No hay empresas registradas.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>
</body>
</html>
