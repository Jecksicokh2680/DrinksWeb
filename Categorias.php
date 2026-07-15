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
$csrf_token = $_SESSION['csrf_token'];

// --- PROCESAR ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit("Token inválido");
    }

    // 1. CREAR CATEGORÍA
    if (isset($_POST['accion']) && $_POST['accion'] === 'crear') {
        $stmt = $mysqli->prepare("INSERT INTO categorias (CodCat, Nombre, Tipo, IdEmpresa, Unicaja, PrecioVtaNevera) VALUES (?, ?, ?, ?, ?, ?)");
        $tipo = !empty($_POST['Tipo']) ? $_POST['Tipo'] : null;
        $emp  = !empty($_POST['IdEmpresa']) ? $_POST['IdEmpresa'] : null;
        $nombre = strtoupper($_POST['Nombre']); 
        $stmt->bind_param("ssiiid", $_POST['CodCat'], $nombre, $tipo, $emp, $_POST['Unicaja'], $_POST['PrecioVtaNevera']);
        $stmt->execute();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 2. ACTUALIZAR CATEGORÍA (AJAX)
    if (isset($_POST['auto_update'])) {
        $cod = $_POST['CodCat'];
        $campo = $_POST['campo'];
        $val = ($_POST['valor'] === '') ? null : $_POST['valor'];

        if ($campo === 'Nombre' && !is_null($val)) {
            $val = strtoupper($val);
        }

        $permitidos = ['SegWebF', 'SegWebT', 'Estado', 'Tipo', 'Unicaja', 'PrecioVtaNevera', 'IdEmpresa', 'Nombre'];
        
        if (in_array($campo, $permitidos)) {
            $stmt = $mysqli->prepare("UPDATE categorias SET $campo = ? WHERE CodCat = ?");
            $stmt->bind_param("ss", $val, $cod);
            if ($stmt->execute()) {
                echo $val; 
            } else { 
                http_response_code(500); 
                echo $mysqli->error; 
            }
        }
        exit;
    }
}

$empresas = $mysqli->query("SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$familias = $mysqli->query("SELECT id, nombre FROM familias ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$categorias = $mysqli->query("SELECT * FROM categorias ORDER BY CodCat")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ERP | Gestión de Categorías</title>
    <style>
        body { font-family: sans-serif; background: #f8fafc; padding: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
        input, select { padding: 5px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .label-group { display: inline-block; margin-right: 10px; }
        .label-group label { display: block; font-size: 12px; font-weight: bold; color: #64748b; margin-bottom: 2px; }
        tr.retirado { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<div class="card">
    <h3>➕ Crear nueva categoría</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="accion" value="crear">
        <div class="label-group"><label>Cod (4)</label><input type="text" name="CodCat" maxlength="4" required style="width:60px;"></div>
        <div class="label-group"><label>Nombre</label><input type="text" name="Nombre" required></div>
        <div class="label-group"><label>Familia</label>
            <select name="Tipo"><option value="">-- Seleccionar --</option><?php foreach($familias as $f): ?><option value="<?= $f['id'] ?>"><?= $f['nombre'] ?></option><?php endforeach; ?></select>
        </div>
        <div class="label-group"><label>Empresa</label>
            <select name="IdEmpresa"><option value="">-- Seleccionar --</option><?php foreach($empresas as $e): ?><option value="<?= $e['IdEmpresa'] ?>"><?= $e['Nombre'] ?></option><?php endforeach; ?></select>
        </div>
        <div class="label-group"><label>Unicaja</label><input type="number" name="Unicaja" value="1" style="width:50px;"></div>
        <div class="label-group"><label>P. Nevera</label><input type="number" name="PrecioVtaNevera" step="0.01" value="0.00" style="width:80px;"></div>
        <button type="submit" style="padding: 6px 15px; margin-top: 18px; cursor:pointer;">Guardar</button>
    </form>
</div>

<div class="card">
    <h2>📋 GESTIÓN DE CATÁLOGO</h2>
    <div style="margin-bottom: 15px; display: flex; gap: 10px;">
        <input type="text" id="buscador" onkeyup="filtrarTabla()" placeholder="🔍 Buscar por nombre o código..." style="flex-grow: 1;">
        <select id="filtroEstado" onchange="filtrarTabla()">
            <option value="todos">Todos</option>
            <option value="1">Solo Activos</option>
            <option value="0">Solo Retirados</option>
        </select>
    </div>
    
    <table id="tablaCategorias">
        <thead><tr><th>Cod</th><th>Nombre</th><th>Empresa</th><th>Familia</th><th>Unicaja</th><th>P. Nevera</th><th>Web(F/T)</th><th>Estado</th></tr></thead>
        <tbody>
            <?php foreach($categorias as $c): ?>
            <tr class="<?= $c['Estado'] == '0' ? 'retirado' : '' ?>">
                <td><strong><?= $c['CodCat'] ?></strong></td>
                <td><input value="<?= htmlspecialchars($c['Nombre']) ?>" onblur="update('<?= $c['CodCat'] ?>', 'Nombre', this)"></td>
                <td>
                    <select onchange="update('<?= $c['CodCat'] ?>', 'IdEmpresa', this)">
                        <option value="">-- N/A --</option>
                        <?php foreach($empresas as $e): ?><option value="<?= $e['IdEmpresa'] ?>" <?= $e['IdEmpresa']==$c['IdEmpresa']?'selected':'' ?>><?= $e['Nombre'] ?></option><?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select onchange="update('<?= $c['CodCat'] ?>', 'Tipo', this)">
                        <option value="">-- Seleccione --</option>
                        <?php foreach($familias as $f): ?><option value="<?= $f['id'] ?>" <?= $c['Tipo']==$f['id']?'selected':'' ?>><?= $f['nombre'] ?></option><?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" value="<?= $c['Unicaja'] ?>" onblur="update('<?= $c['CodCat'] ?>', 'Unicaja', this)" style="width:50px;"></td>
                <td><input type="number" step="0.01" value="<?= $c['PrecioVtaNevera'] ?>" onblur="update('<?= $c['CodCat'] ?>', 'PrecioVtaNevera', this)" style="width:70px;"></td>
                <td>
                    F <input type="checkbox" <?= $c['SegWebF']=='1'?'checked':'' ?> onchange="update('<?= $c['CodCat'] ?>', 'SegWebF', this)">
                    T <input type="checkbox" <?= $c['SegWebT']=='1'?'checked':'' ?> onchange="update('<?= $c['CodCat'] ?>', 'SegWebT', this)">
                </td>
                <td><input type="checkbox" <?= $c['Estado']=='1'?'checked':'' ?> onchange="update('<?= $c['CodCat'] ?>', 'Estado', this); this.closest('tr').classList.toggle('retirado', !this.checked)"></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function filtrarTabla() {
    let filtroTexto = document.getElementById("buscador").value.toLowerCase();
    let filtroEstado = document.getElementById("filtroEstado").value;
    let filas = document.getElementById("tablaCategorias").getElementsByTagName("tr");

    for (let i = 1; i < filas.length; i++) {
        let fila = filas[i];
        let cod = fila.cells[0].textContent.toLowerCase();
        let nombre = fila.querySelector('input[onblur*="Nombre"]').value.toLowerCase();
        let checkbox = fila.querySelector('input[type="checkbox"][onchange*="Estado"]');
        let estadoFila = checkbox.checked ? "1" : "0";
        
        let coincideTexto = (cod.indexOf(filtroTexto) > -1 || nombre.indexOf(filtroTexto) > -1);
        let coincideEstado = (filtroEstado === "todos" || estadoFila === filtroEstado);

        fila.style.display = (coincideTexto && coincideEstado) ? "" : "none";
    }
}

async function update(cod, campo, el) {
    const val = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
    const fd = new FormData();
    fd.append('auto_update', '1');
    fd.append('CodCat', cod);
    fd.append('campo', campo);
    fd.append('valor', val);
    fd.append('csrf_token', '<?= $csrf_token ?>');
    
    const res = await fetch(window.location.href, { method: 'POST', body: fd });
    if (res.ok) {
        if (campo === 'Nombre') {
            el.value = await res.text();
        }
    } else {
        alert("Error al guardar");
    }
}
</script>
</body>
</html>