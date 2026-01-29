<?php
require 'Conexion.php';
// require 'helpers.php'; // Asegúrate de que este archivo exista o comenta si no es necesario

session_start();

/* ============================================================
   SEGURIDAD / SESIÓN
============================================================ */
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

/* ============================================================
   CSRF TOKEN
============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ============================================================
   TIPOS DE CATEGORÍA
============================================================ */
const TIPOS_CATEGORIA = [
    "Cerveza", "Gaseosa", "Agua", "Jugo", "Hidratante",
    "Energizante", "Dulceria", "Maltas", "Papeleria",
    "Suero", "Aseo", "Elementos"
];

function get_tipo_map(): array {
    $map = [];
    foreach (TIPOS_CATEGORIA as $t) {
        $map[substr($t, 0, 2)] = $t; 
    }
    return $map;
}

/* ============================================================
   FUNCIONES DE PROCESAMIENTO
============================================================ */
function check_csrf(string $posted, string $session) {
    if (!$posted || !hash_equals($session, $posted)) {
        http_response_code(403);
        die("Token CSRF inválido");
    }
}

function collect_category_data(bool $is_creation = false): array {
    return [
        'CodCat'     => $is_creation ? strtoupper(trim($_POST['CodCat'])) : trim($_POST['CodCat']),
        'Nombre'     => trim($_POST['Nombre']),
        'IdEmpresa'  => ($_POST['IdEmpresa'] ?? '') !== '' ? intval($_POST['IdEmpresa']) : null,
        'SegWebF'    => isset($_POST['SegWebF']) ? '1' : '0',
        'SegWebT'    => isset($_POST['SegWebT']) ? '1' : '0',
        'Unicaja'    => intval($_POST['Unicaja'] ?? 1),
        'Estado'     => ($_POST['Estado'] ?? '1') === '1' ? '1' : '0',
        'Tipo'       => substr(trim($_POST['Tipo']), 0, 2)
    ];
}

/* ============================================================
   ACCIONES (CREAR / ACTUALIZAR)
============================================================ */
$mensaje = "";

if (isset($_POST['crear'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    $d = collect_category_data(true);

    $stmt = $mysqli->prepare("
        INSERT INTO categorias (CodCat, Nombre, IdEmpresa, SegWebF, SegWebT, Unicaja, Estado, Tipo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssississ", $d['CodCat'], $d['Nombre'], $d['IdEmpresa'], $d['SegWebF'], $d['SegWebT'], $d['Unicaja'], $d['Estado'], $d['Tipo']);
    
    $mensaje = $stmt->execute() ? "Categoría {$d['CodCat']} creada" : "Error: " . $stmt->error;
    $stmt->close();
}

if (isset($_POST['actualizar'])) {
    check_csrf($_POST['csrf_token'], $csrf_token);
    $d = collect_category_data();

    $stmt = $mysqli->prepare("
        UPDATE categorias SET Nombre=?, IdEmpresa=?, SegWebF=?, SegWebT=?, Unicaja=?, Estado=?, Tipo=?
        WHERE CodCat=?
    ");
    $stmt->bind_param("sississs", $d['Nombre'], $d['IdEmpresa'], $d['SegWebF'], $d['SegWebT'], $d['Unicaja'], $d['Estado'], $d['Tipo'], $d['CodCat']);
    $stmt->execute();
    $stmt->close();
    $mensaje = "Categoría {$d['CodCat']} actualizada";
}

/* ============================================================
   DATOS PARA LA VISTA
============================================================ */
$empresas = [];
$resEmp = $mysqli->query("SELECT IdEmpresa, Nombre FROM empresas_productoras ORDER BY Nombre");
while ($e = $resEmp->fetch_assoc()) $empresas[] = $e;

$categorias = [];
$res = $mysqli->query("SELECT c.*, e.Nombre AS Empresa FROM categorias c LEFT JOIN empresas_productoras e ON e.IdEmpresa = c.IdEmpresa ORDER BY c.CodCat");
while ($r = $res->fetch_assoc()) $categorias[] = $r;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Gestión de Categorías</title>
    <style>
        body { font-family: 'Segoe UI', Arial; background: #f3f6fb; padding: 20px; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 12px; max-width: 1300px; margin: 0 auto 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 15px; }
        h2 { margin: 0; color: #0f2a44; }
        .form-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 11px; font-weight: bold; color: #667; text-transform: uppercase; }
        input, select, button { padding: 8px; border-radius: 6px; border: 1px solid #ddd; outline: none; }
        button { background: #1e5aa8; color: white; border: none; cursor: pointer; font-weight: bold; }
        button:hover { background: #154581; }
        
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #0f2a44; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #eee; background: #fff; }
        .fila-inactiva td { background: #f9f9f9; color: #999; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; }
        .activo { background: #e7f6ec; color: #198754; }
        .inactivo { background: #fdeaea; color: #dc3545; }
        
        .filtros-container { display: flex; gap: 10px; }
        .search-input { width: 250px; }
    </style>

    <script>
        function ejecutarFiltros() {
            const texto = document.getElementById("txtBuscar").value.toLowerCase();
            const tipo = document.getElementById("selFiltroTipo").value.toLowerCase();
            const filas = document.querySelectorAll("#tablaCategorias tbody tr");

            filas.forEach(tr => {
                const contenido = tr.innerText.toLowerCase();
                const tipoFila = tr.getAttribute("data-tipo").toLowerCase();
                
                const coincideTexto = contenido.includes(texto);
                const coincideTipo = (tipo === "" || tipoFila === tipo);

                tr.style.display = (coincideTexto && coincideTipo) ? "" : "none";
            });
        }
    </script>
</head>
<body>

<div class="card">
    <h2>Nueva Categoría</h2>
    <?php if($mensaje): ?> <div style="color: #1e5aa8; font-weight: bold; margin: 10px 0;"><?= $mensaje ?></div> <?php endif; ?>
    
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <div class="form-group">
            <label>Código</label>
            <input name="CodCat" maxlength="4" placeholder="EJ: CERV" required style="width: 70px;">
        </div>
        <div class="form-group">
            <label>Nombre</label>
            <input name="Nombre" placeholder="Nombre de categoría" required>
        </div>
        <div class="form-group">
            <label>Empresa</label>
            <select name="IdEmpresa">
                <option value="">-- Ninguna --</option>
                <?php foreach($empresas as $e): ?>
                    <option value="<?= $e['IdEmpresa'] ?>"><?= $e['Nombre'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Tipo</label>
            <select name="Tipo">
                <?php foreach(TIPOS_CATEGORIA as $t): ?>
                    <option value="<?= substr($t,0,2) ?>"><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Unicaja</label>
            <input type="number" name="Unicaja" value="1" min="1" style="width: 60px;">
        </div>
        <div class="form-group" style="flex-direction: row; gap: 10px; padding-bottom: 10px;">
            <label><input type="checkbox" name="SegWebF"> SegF</label>
            <label><input type="checkbox" name="SegWebT"> SegT</label>
        </div>
        <button name="crear" style="height: 35px;">+ Crear</button>
    </form>
</div>

<div class="card">
    <div class="header">
        <h2>Listado</h2>
        <div class="filtros-container">
            <select id="selFiltroTipo" onchange="ejecutarFiltros()">
                <option value="">-- Todos los Tipos --</option>
                <?php foreach(TIPOS_CATEGORIA as $t): ?>
                    <option value="<?= substr($t,0,2) ?>"><?= $t ?></option>
                <?php endforeach; ?>
            </select>
            <input id="txtBuscar" class="search-input" placeholder="Buscar por nombre o código..." onkeyup="ejecutarFiltros()">
        </div>
    </div>

    <table id="tablaCategorias">
        <thead>
            <tr>
                <th>Cód</th>
                <th>Nombre Categoría</th>
                <th>Empresa Productora</th>
                <th>Tipo</th>
                <th>SegF</th>
                <th>SegT</th>
                <th>Caja</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($categorias as $c): ?>
            <tr class="<?= $c['Estado']=='0'?'fila-inactiva':'' ?>" data-tipo="<?= $c['Tipo'] ?>">
                <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="CodCat" value="<?= $c['CodCat'] ?>">
                
                <td><strong><?= $c['CodCat'] ?></strong></td>
                <td><input name="Nombre" value="<?= htmlspecialchars($c['Nombre']) ?>" style="width: 100%;"></td>
                <td>
                    <select name="IdEmpresa" style="width: 100%;">
                        <option value="">-- Ninguna --</option>
                        <?php foreach($empresas as $e): ?>
                            <option value="<?= $e['IdEmpresa'] ?>" <?= $e['IdEmpresa']==$c['IdEmpresa']?'selected':'' ?>><?= $e['Nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="Tipo">
                        <?php foreach(TIPOS_CATEGORIA as $t): ?>
                            <option value="<?= substr($t,0,2) ?>" <?= $c['Tipo']==substr($t,0,2)?'selected':'' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="checkbox" name="SegWebF" <?= $c['SegWebF']=='1'?'checked':'' ?>></td>
                <td><input type="checkbox" name="SegWebT" <?= $c['SegWebT']=='1'?'checked':'' ?>></td>
                <td><input type="number" name="Unicaja" value="<?= $c['Unicaja'] ?>" style="width: 45px;"></td>
                <td>
                    <select name="Estado" style="font-size: 11px;">
                        <option value="1" <?= $c['Estado']=='1'?'selected':'' ?>>Activo</option>
                        <option value="0" <?= $c['Estado']=='0'?'selected':'' ?>>Inactivo</option>
                    </select>
                </td>
                <td><button name="actualizar">💾</button></td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>