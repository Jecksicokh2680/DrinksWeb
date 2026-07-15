<?php
require 'Conexion.php';
session_start();

if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- PROCESAR ACCIONES (Crear o Actualizar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit("Token inválido");
    }

    // 1. CREAR
    if (isset($_POST['accion']) && $_POST['accion'] === 'crear') {
        $nombre = trim(mb_strtoupper($_POST['Nombre'], 'UTF-8'));
        $stmt = $mysqli->prepare("INSERT INTO empresas_productoras (Nombre) VALUES (?)");
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 2. ACTUALIZAR (AJAX)
    if (isset($_POST['update_nombre'])) {
        $id = $_POST['IdEmpresa'];
        $nombre = trim(mb_strtoupper($_POST['valor'], 'UTF-8'));
        $stmt = $mysqli->prepare("UPDATE empresas_productoras SET Nombre = ? WHERE IdEmpresa = ?");
        $stmt->bind_param("si", $nombre, $id);
        if ($stmt->execute()) {
            echo $nombre; // Devuelve el nombre ya en mayúsculas
        }
        exit;
    }
}

$empresas = $mysqli->query("SELECT * FROM empresas_productoras ORDER BY Nombre ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Empresas</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f1f5f9; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; max-width: 800px; margin-left: auto; margin-right: auto; }
        h3 { color: #1e293b; margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        .input-text { padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 300px; text-transform: uppercase; }
        button { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { background: #2563eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
        .editable { border: none; background: transparent; width: 100%; font-size: 14px; text-transform: uppercase; cursor: pointer; }
        .editable:focus { background: #eff6ff; outline: none; border-radius: 4px; }
    </style>
</head>
<body>

<div class="card">
    <h3>➕ Nueva Empresa Productora</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="accion" value="crear">
        <input type="text" name="Nombre" class="input-text" placeholder="NOMBRE DE LA EMPRESA" required oninput="this.value = this.value.toUpperCase()">
        <button type="submit">Guardar Empresa</button>
    </form>
</div>

<div class="card">
    <h3>📋 Catálogo de Empresas</h3>
    <table>
        <?php foreach($empresas as $e): ?>
        <tr>
            <td style="color: #64748b; width: 50px;">#<?= $e['IdEmpresa'] ?></td>
            <td>
                <input type="text" class="editable" value="<?= htmlspecialchars($e['Nombre']) ?>" 
                       oninput="this.value = this.value.toUpperCase()"
                       onblur="updateEmpresa(<?= $e['IdEmpresa'] ?>, this)">
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<script>
async function updateEmpresa(id, el) {
    const fd = new FormData();
    fd.append('update_nombre', '1');
    fd.append('IdEmpresa', id);
    fd.append('valor', el.value);
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
    
    const res = await fetch(window.location.href, { method: 'POST', body: fd });
    if (!res.ok) alert("Error al actualizar");
}
</script>
</body>
</html>