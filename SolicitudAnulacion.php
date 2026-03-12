<?php
session_start();
date_default_timezone_set('America/Bogota');

// 1. CARGA DE CONEXIONES
require_once 'Conexion.php';    
require_once 'ConnCentral.php'; 
require_once 'ConnDrinks.php';  

/* ============================================================
    ASIGNACIÓN DE VARIABLES DE SESIÓN
============================================================ */
$Usuario     = $_SESSION['Usuario']     ?? 'INVITADO'; 
$NitEmpresa  = $_SESSION['NitEmpresa'] ?? 'SIN_NIT';
$NroSucursal = $_SESSION['NroSucursal'] ?? '001';

if ($Usuario == 'INVITADO' || $NitEmpresa == 'SIN_NIT') {
    die("<h3 style='color:red;text-align:center;'>Error: Sesión no válida.</h3>");
}

/* ============================================================
    FUNCIONES DE CONEXIÓN CORREGIDAS
============================================================ */
function conectarPOS($sede) {
    if ($sede == '002') {
        global $mysqliDrinks;
        return $mysqliDrinks;
    } else {
        // Probamos con ambos nombres posibles según tus archivos
        global $mysqliPos, $mysqliCentral;
        return (!empty($mysqliCentral)) ? $mysqliCentral : $mysqliPos;
    }
}
function VerificarAnulacion($NroDoc, $sede) {
    $db = conectarPOS($sede);
    if (!$db) return 0;
    
    $NroDoc = trim($NroDoc);
    
    // 1. BUSCAR EN FACTURAS
    // Revisamos estado y campos de anulación según tu CREATE TABLE
    $sqlF = "SELECT estado, fechaanul FROM facturas WHERE numero = ? LIMIT 1";
    $stmtF = $db->prepare($sqlF);
    $stmtF->bind_param("s", $NroDoc);
    $stmtF->execute();
    $res = $stmtF->get_result()->fetch_assoc();
    
    // 2. SI NO ESTÁ, BUSCAR EN PEDIDOS
    if (!$res) {
        $sqlP = "SELECT estado, fechaanul FROM pedidos WHERE numero = ? LIMIT 1";
        $stmtP = $db->prepare($sqlP);
        $stmtP->bind_param("s", $NroDoc);
        $stmtP->execute();
        $res = $stmtP->get_result()->fetch_assoc();
    }

    if ($res) {
        $estado = (int)$res['estado'];
        // Si fechaanul NO está vacío, o el estado es diferente de 1 o 0
        // (En muchos sistemas de este tipo, 1 es Activo, 2 es Procesado, y 0 o >3 es Anulado)
        if (!empty($res['fechaanul']) || ($estado !== 1 && $estado !== 0)) {
            return 1; // ANULADO (Verde)
        }
    }
    
    return 0; // ACTIVO (Amarillo)
}
/* ============================================================
    LÓGICA DE PROCESAMIENTO
============================================================ */
$fechaConsulta = (isset($_GET['fConsulta'])) ? $_GET['fConsulta'] : date('Y-m-d');

if (isset($_GET['setSede'])) {
    $_SESSION['NroSucursal'] = $_GET['setSede'];
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_GET['accion']) && $_GET['accion'] == 'borrar') {
    $stmt = $mysqli->prepare("DELETE FROM solicitud_anulacion WHERE NroFactAnular=? AND F_Creacion=? AND NroSucursal=?");
    $stmt->bind_param("sss", $_GET['factura'], $fechaConsulta, $NroSucursal);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

if (isset($_POST['grabar'])) {
    $factura   = trim($_POST['FactAnular']);
    $reemplazo = trim($_POST['NroFactReemplaza']); 
    $v = (float)str_replace(['$', ' ', ','], '', $_POST['ValorAnular']);
    $motivo = mb_strtoupper(trim($_POST['motivo']), 'UTF-8');
    
    $stmt = $mysqli->prepare("INSERT INTO solicitud_anulacion (F_Creacion, Nit_Empresa, NroSucursal, NitCajero, FH_CajeroCheck, NroFactAnular, ValorFactAnular, MotivoAnulacion, NroFactReemplaza, Estado) VALUES (?,?,?,?,?,?,?,?,?, '1')");
    $ft = date('Y-m-d H:i:s');
    $stmt->bind_param("ssssssdss", $fechaConsulta, $NitEmpresa, $NroSucursal, $Usuario, $ft, $factura, $v, $motivo, $reemplazo);
    $stmt->execute();
    header("Location: ?fConsulta=" . $fechaConsulta); exit;
}

/* ============================================================
    OBTENCIÓN DE DATOS - CON DEPURACIÓN
============================================================ */
$dbSede = conectarPOS($NroSucursal);
$nombreSedeActual = ($NroSucursal == '002') ? 'DRINKS' : 'CENTRAL';
$docsArray = [];
$error_db = "";

if (!$dbSede) {
    $error_db = "Error: La variable de conexión para $nombreSedeActual está vacía o no existe.";
} elseif ($dbSede->connect_error) {
    $error_db = "Error de conexión a $nombreSedeActual: " . $dbSede->connect_error;
} else {
    $fQuery = str_replace('-', '', $fechaConsulta);
    $sqlDocs = "SELECT HORA, TRIM(numero) AS NUMERO, valortotal AS VALORTOTAL FROM facturas WHERE fecha = '$fQuery' AND estado IN ('0','1')
                UNION ALL 
                SELECT HORA, TRIM(numero) AS NUMERO, valortotal AS VALORTOTAL FROM pedidos WHERE fecha = '$fQuery' AND estado IN ('0','1')
                ORDER BY HORA DESC";
    
    $resDocs = $dbSede->query($sqlDocs);
    if($resDocs) { 
        while($r = $resDocs->fetch_assoc()) { $docsArray[] = $r; } 
    } else {
        $error_db = "Error en la consulta SQL: " . $dbSede->error;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Anulaciones - <?= $nombreSedeActual ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-size: 13px; }
        .header-bar { background: #1a202c; color: white; padding: 12px 20px; border-radius: 0 0 10px 10px; margin-bottom: 20px; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .mayus { text-transform: uppercase; }
        .table-xs { font-size: 0.8rem; }
    </style>
</head>
<body>

<div class="container-fluid px-4">
    <div class="header-bar shadow d-flex justify-content-between align-items-center">
        <div><strong>SEDE:</strong> <?= $nombreSedeActual ?> | <strong>CAJERO:</strong> <?= $Usuario ?></div>
        <div class="d-flex gap-2">
            <input type="date" class="form-control form-control-sm" value="<?= $fechaConsulta ?>" onchange="location.href='?fConsulta='+this.value">
            <select class="form-select form-select-sm" onchange="location.href='?setSede='+this.value+'&fConsulta=<?= $fechaConsulta ?>'">
                <option value="001" <?= ($NroSucursal=='001')?'selected':'' ?>>CENTRAL</option>
                <option value="002" <?= ($NroSucursal=='002')?'selected':'' ?>>DRINKS</option>
            </select>
        </div>
    </div>

    <?php if(!empty($error_db)): ?>
        <div class="alert alert-warning py-2 shadow-sm border-start border-4 border-warning">
            <strong>Atención:</strong> <?= $error_db ?><br>
            <small>Verifica que <code>ConnCentral.php</code> defina la variable <code>$mysqliCentral</code> o <code>$mysqliPos</code>.</small>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <form method="post" id="formAnulacion" onsubmit="return validarTodo()">
            <input type="hidden" name="FactAnular" id="FactAnular">
            <input type="hidden" name="ValorAnular" id="ValorAnular">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">1. Documento a Anular (<?= count($docsArray) ?> encontrados)</label>
                    <select class="form-select border-primary" id="selAnular" required>
                        <option value="">-- HORA | FACTURA | VALOR --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>" data-valor="<?= $d['VALORTOTAL'] ?>">
                                <?= date('H:i', strtotime($d['HORA'])) ?> | #<?= $d['NUMERO'] ?> | $<?= number_format($d['VALORTOTAL'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">2. Factura que Reemplaza</label>
                    <select name="NroFactReemplaza" id="selReemplaza" class="form-select" required>
                        <option value="N/A">-- NO LLEVA REEMPLAZO --</option>
                        <?php foreach($docsArray as $d): ?>
                            <option value="<?= $d['NUMERO'] ?>">
                                <?= date('H:i', strtotime($d['HORA'])) ?> | #<?= $d['NUMERO'] ?> | $<?= number_format($d['VALORTOTAL'], 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">3. Motivo (MAYÚSCULAS)</label>
                    <input type="text" name="motivo" id="motivo" class="form-control mayus" 
                           placeholder="MOTIVO..." required 
                           oninput="this.value = this.value.toUpperCase()">
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" name="grabar" class="btn btn-primary w-100 fw-bold">GRABAR</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card shadow-sm overflow-hidden">
        <table class="table table-hover table-xs mb-0 text-center">
            <thead class="table-dark">
                <tr>
                    <th>HORA SOL.</th>
                    <th>DOC. ANULAR</th>
                    <th>VALOR</th>
                    <th>REEMPLAZO</th>
                    <th class="text-start">MOTIVO</th>
                    <th>ESTADO POS</th>
                    <th>ELIMINAR</th>
                </tr>
            </thead>
            <tbody class="bg-white">
                <?php
                $sqlH = "SELECT * FROM solicitud_anulacion WHERE F_Creacion = ? AND NroSucursal = ? ORDER BY FH_CajeroCheck DESC";
                $stmtH = $mysqli->prepare($sqlH);
                $stmtH->bind_param("ss", $fechaConsulta, $NroSucursal);
                $stmtH->execute();
                $resH = $stmtH->get_result();
                if($resH->num_rows == 0) echo "<tr><td colspan='7' class='py-3 text-muted'>No hay solicitudes para esta fecha.</td></tr>";
                while ($r = $resH->fetch_assoc()): 
                    $anulada = VerificarAnulacion($r['NroFactAnular'], $r['NroSucursal']);
                ?>
                <tr>
                    <td class="text-muted"><?= date('H:i', strtotime($r['FH_CajeroCheck'])) ?></td>
                    <td class="fw-bold text-danger"><?= $r['NroFactAnular'] ?></td>
                    <td class="fw-bold">$<?= number_format($r['ValorFactAnular'], 0)?></td>
                    <td><?= $r['NroFactReemplaza'] ?></td>
                    <td class="text-start"><?= $r['MotivoAnulacion'] ?></td>
                    <td><span class="badge <?= $anulada?'bg-success':'bg-warning text-dark' ?>"><?= $anulada?'ANULADO':'ACTIVO' ?></span></td>
                    <td><a href="?accion=borrar&factura=<?= $r['NroFactAnular'] ?>&fConsulta=<?= $fechaConsulta ?>" onclick="return confirm('¿Eliminar?')" class="text-danger fw-bold" style="text-decoration:none">×</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function validarTodo() {
    const anular = document.getElementById('selAnular');
    const reemplaza = document.getElementById('selReemplaza').value;
    const motivo = document.getElementById('motivo').value.trim();
    
    if (anular.value === "") { alert("Seleccione factura a anular."); return false; }
    if (anular.value === reemplaza) { alert("La factura de reemplazo no puede ser la misma."); return false; }
    if (motivo.length < 5) { alert("Escriba un motivo válido."); return false; }

    const opt = anular.options[anular.selectedIndex];
    document.getElementById('FactAnular').value = opt.value;
    document.getElementById('ValorAnular').value = opt.dataset.valor;
    return true;
}
</script>
</body>
</html>