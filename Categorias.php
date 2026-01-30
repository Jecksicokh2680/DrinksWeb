<?php
require 'Conexion.php';
require 'helpers.php';
session_start();

/* ============================================================
   SEGURIDAD Y SESIÓN
============================================================ */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

const TIPOS_CATEGORIA = ["Cerveza", "Gaseosa", "Agua", "Jugo", "Hidratante", "Energizante", "Dulceria", "Maltas", "Papeleria", "Suero", "Aseo", "Elementos"];

function check_csrf($posted, $session) {
    if (!$posted || !hash_equals($session, $posted)) {
        http_response_code(403);
        die("Token CSRF inválido");
    }
}

/* ============================================================
   PROCESAR ACTUALIZACIÓN AUTOMÁTICA (AJAX)
============================================================ */
if (isset($_POST['auto_update'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    
    $cod   = $_POST['CodCat'];
    $valor = $_POST['valor'];
    $campo = $_POST['campo'];
    
    $camposPermitidos = ['SegWebF', 'SegWebT', 'Estado', 'Tipo'];
    
    if (in_array($campo, $camposPermitidos)) {
        $stmt = $mysqli->prepare("UPDATE categorias SET $campo = ? WHERE CodCat = ?");
        $stmt->bind_param("ss", $valor, $cod);
        if ($stmt->execute()) {
            http_response_code(200);
            exit;
        }
    }
    http_response_code(400);
    exit;
}

/* ============================================================
   PROCESAR CREACIÓN Y ACTUALIZACIÓN MANUAL
============================================================ */
$mensaje = "";

if (isset($_POST['crear']) || isset($_POST['actualizar'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    
    $d = [
        'CodCat'          => isset($_POST['crear']) ? strtoupper(trim($_POST['CodCat'])) : trim($_POST['CodCat']),
        'Nombre'          => trim($_POST['Nombre']),
        'IdEmpresa'       => ($_POST['IdEmpresa'] ?? '') !== '' ? intval($_POST['IdEmpresa']) : null,
        'SegWebF'         => isset($_POST['SegWebF']) ? '1' : '0',
        'SegWebT'         => isset($_POST['SegWebT']) ? '1' : '0',
        'Unicaja'         => intval($_POST['Unicaja'] ?? 1),
        'Estado'          => ($_POST['Estado'] ?? '1') === '1' ? '1' : '0',
        'Tipo'            => substr(trim($_POST['Tipo']), 0, 2),
        'PrecioVtaNevera' => floatval($_POST['PrecioVtaNevera'] ?? 0)
    ];

    if (isset($_POST['crear'])) {
        $stmt = $mysqli->prepare("INSERT INTO categorias (CodCat, Nombre, IdEmpresa, SegWebF, SegWebT, Unicaja, Estado, Tipo, PrecioVtaNevera) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssississd", $d['CodCat'], $d['Nombre'], $d['IdEmpresa'], $d['SegWebF'], $d['SegWebT'], $d['Unicaja'], $d['Estado'], $d['Tipo'], $d['PrecioVtaNevera']);
    } else {
        $stmt = $mysqli->prepare("UPDATE categorias SET Nombre=?, IdEmpresa=?, SegWebF=?, SegWebT=?, Unicaja=?, Estado=?, Tipo=?, PrecioVtaNevera=? WHERE CodCat=?");
        $stmt->bind_param("sississds", $d['Nombre'], $d['IdEmpresa'], $d['SegWebF'], $d['SegWebT'], $d['Unicaja'], $d['Estado'], $d['Tipo'], $d['PrecioVtaNevera'], $d['CodCat']);
    }

    $mensaje = $stmt->execute() ? "✅ Registro procesado correctamente" : "❌ Error: " . $stmt->error;
    $stmt->close();
}

/* ============================================================
   CARGA DE DATOS PARA VISTA
============================================================ */
$empresas = $mysqli->query("SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre")->fetch_all(MYSQLI_ASSOC);
$categorias = $mysqli->query("SELECT c.*, e.Nombre AS Empresa FROM categorias c LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa ORDER BY c.CodCat")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ERP | Gestión de Categorías</title>
    <style>
        :root { --primary: #0f2a44; --accent: #1e5aa8; --bg: #f8fafc; --text: #334155; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 25px; }
        .container { max-width: 1500px; margin: auto; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; margin-bottom: 20px; padding-bottom: 10px; }
        h2 { color: var(--primary); margin: 0; font-size: 1.1rem; text-transform: uppercase; }
        .grid-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; align-items: end; }
        label { display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 4px; }
        input, select { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; text-align: left; padding: 12px 8px; color: #475569; font-size: 12px; border-bottom: 2px solid #e2e8f0; }
        td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; }
        .fila-inactiva { background: #fff1f2; opacity: 0.7; }
        .btn { background: var(--accent); color: #fff; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn-save { padding: 5px 10px; background: #059669; }
        #filtro { width: 300px; border-radius: 20px; padding-left: 15px; }
        .msg { background: #f0fdf4; color: #166534; padding: 10px; border-radius: 6px; border: 1px solid #bbf7d0; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="card-header"><h2>➕ Nueva Categoría</h2></div>
        <?php if($mensaje) echo "<div class='msg'>$mensaje</div>"; ?>
        <form method="post" class="grid-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div><label>Código</label><input name="CodCat" maxlength="4" required></div>
            <div><label>Nombre</label><input name="Nombre" required></div>
            <div><label>Empresa</label>
                <select name="IdEmpresa">
                    <option value="">-- Seleccione --</option>
                    <?php foreach($empresas as $e): ?><option value="<?= $e['IdEmpresa'] ?>"><?= $e['Nombre'] ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label>Tipo</label>
                <select name="Tipo">
                    <?php foreach(TIPOS_CATEGORIA as $t): ?><option value="<?= substr($t,0,2) ?>"><?= $t ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label>Precio Esp.</label><input type="number" step="0.01" name="PrecioVtaNevera" value="0"></div>
            <button name="crear" class="btn">Registrar</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>📋 Gestión de Catálogo</h2>
            <input id="filtro" placeholder="🔍 Filtrar registros..." onkeyup="filtrar()">
        </div>
        <table id="tabla">
            <thead>
                <tr>
                    <th>Cod</th>
                    <th>Nombre Categoría</th>
                    <th>Empresa</th>
                    <th>Tipo</th>
                    <th>Unicaja</th>
                    <th>Precio Esp.</th>
                    <th>Web (Seg)</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($categorias as $c): ?>
                <tr class="<?= $c['Estado']=='0'?'fila-inactiva':'' ?>">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="CodCat" value="<?= $c['CodCat'] ?>">
                        <td style="font-weight:bold;"><?= $c['CodCat'] ?></td>
                        <td><input name="Nombre" value="<?= $c['Nombre'] ?>"></td>
                        <td>
                            <select name="IdEmpresa">
                                <option value="">-- N/A --</option>
                                <?php foreach($empresas as $e): ?>
                                    <option value="<?= $e['IdEmpresa'] ?>" <?= $e['IdEmpresa']==$c['IdEmpresa']?'selected':'' ?>><?= $e['Nombre'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select onchange="updateRow(this, '<?= $c['CodCat'] ?>', 'Tipo')">
                                <?php foreach(TIPOS_CATEGORIA as $t): ?>
                                    <option value="<?= substr($t,0,2) ?>" <?= $c['Tipo']==substr($t,0,2)?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="Unicaja" value="<?= $c['Unicaja'] ?>" style="width:50px"></td>
                        <td><input type="number" step="0.01" name="PrecioVtaNevera" value="<?= $c['PrecioVtaNevera'] ?>" style="width:80px"></td>
                        <td>
                            <label style="font-size:10px"><input type="checkbox" <?= $c['SegWebF']=='1'?'checked':'' ?> onchange="updateRow(this, '<?= $c['CodCat'] ?>', 'SegWebF')"> F</label>
                            <label style="font-size:10px"><input type="checkbox" <?= $c['SegWebT']=='1'?'checked':'' ?> onchange="updateRow(this, '<?= $c['CodCat'] ?>', 'SegWebT')"> T</label>
                        </td>
                        <td>
                            <select onchange="updateRow(this, '<?= $c['CodCat'] ?>', 'Estado')" style="width:85px; font-weight:bold;">
                                <option value="1" <?= $c['Estado']=='1'?'selected':'' ?>>Activo</option>
                                <option value="0" <?= $c['Estado']=='0'?'selected':'' ?>>Inactivo</option>
                            </select>
                        </td>
                        <td><button name="actualizar" class="btn btn-save">OK</button></td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function updateRow(element, codCat, campo) {
    const valor = (element.type === 'checkbox') ? (element.checked ? '1' : '0') : element.value;
    const parentTd = element.closest('td');
    const formData = new FormData();
    formData.append('auto_update', '1');
    formData.append('CodCat', codCat);
    formData.append('campo', campo);
    formData.append('valor', valor);
    formData.append('csrf_token', '<?= $csrf_token ?>');

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        if (response.ok) {
            parentTd.style.background = "#dcfce7";
            if(campo === 'Estado') location.reload();
            setTimeout(() => parentTd.style.background = "transparent", 600);
        }
    } catch (e) { console.error(e); }
}

function filtrar(){
    let f=document.getElementById("filtro").value.toLowerCase();
    document.querySelectorAll("#tabla tbody tr").forEach(tr=>{
        tr.style.display=tr.innerText.toLowerCase().includes(f)?"":"none";
    });
}
</script>
</body>
</html>