<?php
require 'Conexion.php';
session_start();

// -------------------------------------------------
// VALIDAR SESIÓN
// -------------------------------------------------
if (empty($_SESSION['Usuario'])) {
    header("Location: Login.php");
    exit;
}
date_default_timezone_set('America/Bogota');

// -------------------------------------------------
// LÓGICA AJAX: GUARDADO AUTOMÁTICO DESDE LA TABLA
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_save') {
    header('Content-Type: application/json');
    
    $nit        = $mysqli->real_escape_string($_POST['nit']);
    $fecha      = $mysqli->real_escape_string($_POST['fecha']);
    $hora       = $mysqli->real_escape_string($_POST['hora']);
    
    $tipo       = $mysqli->real_escape_string($_POST['tipo']);
    $monto      = floatval(str_replace('.', '', $_POST['monto']));
    $num_fact   = strtoupper($mysqli->real_escape_string($_POST['numfact_proveedor']));
    $id_pago    = intval($_POST['id_pago']);
    $verificado = intval($_POST['verificado']); 
    $desc       = strtoupper($mysqli->real_escape_string($_POST['descripcion']));

    if ($tipo === 'F') $monto *= -1;

    $update = $mysqli->query("
        UPDATE pagosproveedores
        SET Monto='$monto',
            TipoMonto='$tipo',
            Descripcion='$desc',
            NumfactProveedor='$num_fact',
            idPago='$id_pago',
            Verificado='$verificado'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    echo json_encode(['success' => $update]);
    exit;
}

// -------------------------------------------------
// ACCIÓN: EXPORTAR A EXCEL (Respetando filtros y fechas)
// -------------------------------------------------
if (isset($_GET['consultar'], $_GET['proveedor'], $_GET['exportar']) && $_GET['exportar'] == 1) {
    $nit = $mysqli->real_escape_string($_GET['proveedor']);
    $filtroTipo = $_GET['filtro_tipo'] ?? '';
    $filtroRevision = $_GET['filtro_revision'] ?? '';
    $fechaIni = str_replace('-', '', $_GET['fecha_ini'] ?? '');
    $fechaFin = str_replace('-', '', $_GET['fecha_fin'] ?? '');

    $whereClause = "Nit='$nit' AND Estado='1'";
    if ($filtroTipo === 'F' || $filtroTipo === 'P') {
        $whereClause .= " AND TipoMonto='$filtroTipo'";
    }
    if ($filtroRevision === '1' || $filtroRevision === '0') {
        $whereClause .= " AND idPago='$filtroRevision'";
    }
    if (!empty($fechaIni) && !empty($fechaFin)) {
        $whereClause .= " AND F_Creacion BETWEEN '$fechaIni' AND '$fechaFin'";
    } elseif (!empty($fechaIni)) {
        $whereClause .= " AND F_Creacion >= '$fechaIni'";
    } elseif (!empty($fechaFin)) {
        $whereClause .= " AND F_Creacion <= '$fechaFin'";
    }

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Reporte_Cartera_Proveedor_$nit.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $resExcel = $mysqli->query("
        SELECT F_Creacion, H_Creacion, TipoMonto, Monto, Descripcion, NumfactProveedor, idPago, Verificado 
        FROM pagosproveedores 
        WHERE $whereClause 
        ORDER BY F_Creacion DESC, H_Creacion DESC
    ");
    ?>
    <table border="1">
        <thead>
            <tr style="background-color: #1e293b; color: #ffffff; font-weight: bold;">
                <th>Fecha</th>
                <th>Hora</th>
                <th>Tipo</th>
                <th>Facturas (-)</th>
                <th>Pagos (+)</th>
                <th>Nro Factura Prov</th>
                <th>idPago</th>
                <th>Verificado</th>
                <th>Descripcion</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($r = $resExcel->fetch_assoc()): 
                $esFactura = ($r['TipoMonto'] == 'F');
                $valorAbs  = abs((float)$r['Monto']);
            ?>
                <tr>
                    <td><?= $r['F_Creacion'] ?></td>
                    <td><?= $r['H_Creacion'] ?></td>
                    <td><?= $esFactura ? 'Factura' : 'Pago' ?></td>
                    <td><?= $esFactura ? $valorAbs : 0 ?></td>
                    <td><?= !$esFactura ? $valorAbs : 0 ?></td>
                    <td><?= htmlspecialchars($r['NumfactProveedor']) ?></td>
                    <td><?= $r['idPago'] == 1 ? 'SI' : 'NO' ?></td>
                    <td><?= $r['Verificado'] == 1 ? 'SI' : 'NO' ?></td>
                    <td><?= htmlspecialchars($r['Descripcion']) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <?php
    exit; 
}

// -------------------------------------------------
// PROVEEDORES
// -------------------------------------------------
$proveedores = $mysqli->query("
    SELECT CedulaNit AS Nit, Nombre
    FROM terceros
    WHERE Estado = 1
    ORDER BY Nombre
");

// -------------------------------------------------
// INSERTAR NUEVO REGISTRO
// -------------------------------------------------
if (isset($_POST['grabar'])) {
    $nit        = $_POST['proveedor'];
    $fecha      = str_replace('-', '', $_POST['fecha']);
    $hora       = date("H:i:s");
    $tipo       = $_POST['tipo'];
    $monto      = floatval(str_replace('.', '', $_POST['monto']));
    $desc       = strtoupper($mysqli->real_escape_string($_POST['descripcion']));
    $num_fact   = strtoupper($mysqli->real_escape_string($_POST['numfact_proveedor']));
    $id_pago    = isset($_POST['id_pago']) ? 1 : 0;
    $verificado = isset($_POST['verificado']) ? 1 : 0;

    if ($tipo === 'F') $monto *= -1;

    $mysqli->query("
        INSERT INTO pagosproveedores
        (Nit,F_Creacion,H_Creacion,Monto,TipoMonto,Descripcion,NumfactProveedor,idPago,Verificado,Estado)
        VALUES
        ('$nit','$fecha','$hora','$monto','$tipo','$desc','$num_fact','$id_pago','$verificado','1')
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// BORRAR (LÓGICO)
// -------------------------------------------------
if (isset($_GET['borrar'])) {
    $nit   = $_GET['nit'];
    $fecha = $_GET['f'];
    $hora  = $_GET['h'];

    $mysqli->query("
        UPDATE pagosproveedores
        SET Estado='0'
        WHERE Nit='$nit'
          AND F_Creacion='$fecha'
          AND H_Creacion='$hora'
    ");

    header("Location: ?proveedor=$nit&consultar=1");
    exit;
}

// -------------------------------------------------
// PAGINACIÓN Y CONSULTA CON FILTROS Y FECHAS
// -------------------------------------------------
$porPagina = 30;
$pagina = max(1, intval($_GET['page'] ?? 1));
$offset = ($pagina - 1) * $porPagina;
$abonos = [];
$total = 0;
$totalPaginas = 1;
$nit = '';
$nombreProveedorActual = '';

$filtroTipo = $_GET['filtro_tipo'] ?? '';
$filtroRevision = $_GET['filtro_revision'] ?? '';
$fechaIni = $_GET['fecha_ini'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';
$fechaIniSql = str_replace('-', '', $fechaIni);
$fechaFinSql = str_replace('-', '', $fechaFin);

if (isset($_GET['consultar'], $_GET['proveedor'])) {
    $nit = $_GET['proveedor'];
    
    $resNom = $mysqli->query("SELECT Nombre FROM terceros WHERE CedulaNit='$nit' LIMIT 1");
    if($rNom = $resNom->fetch_assoc()) {
        $nombreProveedorActual = $rNom['Nombre'];
    }

    $whereClause = "Nit='$nit' AND Estado='1'";
    if ($filtroTipo === 'F' || $filtroTipo === 'P') {
        $whereClause .= " AND TipoMonto='$filtroTipo'";
    }
    if ($filtroRevision === '1' || $filtroRevision === '0') {
        $whereClause .= " AND idPago='$filtroRevision'";
    }
    if (!empty($fechaIniSql) && !empty($fechaFinSql)) {
        $whereClause .= " AND F_Creacion BETWEEN '$fechaIniSql' AND '$fechaFinSql'";
    } elseif (!empty($fechaIniSql)) {
        $whereClause .= " AND F_Creacion >= '$fechaIniSql'";
    } elseif (!empty($fechaFinSql)) {
        $whereClause .= " AND F_Creacion <= '$fechaFinSql'";
    }

    $resTotal = $mysqli->query("
        SELECT 
            IFNULL(SUM(Monto),0) total,
            COUNT(*) cant 
        FROM pagosproveedores 
        WHERE $whereClause
    ");
    $rowT = $resTotal->fetch_assoc();
    $total = $rowT['total'];
    $totalPaginas = max(1, ceil($rowT['cant'] / $porPagina));

    $res = $mysqli->query("SELECT * FROM pagosproveedores WHERE $whereClause ORDER BY F_Creacion DESC, H_Creacion DESC LIMIT $offset,$porPagina");
    if ($res) {
        while ($r = $res->fetch_assoc()) { 
            $abonos[] = $r; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Control Gerencial de Cartera - Proveedores</title>
<style>
:root {
    --primary: #1e293b;
    --primary-light: #334155;
    --accent: #0284c7;
    --success: #059669;
    --danger: #dc2626;
    --warning: #d97706;
    --bg-main: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--bg-main);
    color: var(--text-main);
    margin: 0;
    padding: 20px;
}

.container-fluid {
    max-width: 1450px;
    margin: auto;
}

.card-executive {
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    border: 1px solid var(--border-color);
    padding: 24px;
    margin-bottom: 24px;
}

.header-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 16px;
    margin-bottom: 20px;
}

.header-title h2 {
    margin: 0;
    font-size: 22px;
    color: var(--primary);
    font-weight: 700;
    letter-spacing: -0.5px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
}

.filter-grid select, .filter-grid input, .filter-grid button {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background-color: #fff;
    font-size: 14px;
    color: var(--text-main);
    box-sizing: border-box;
}

.filter-grid button {
    background-color: var(--accent);
    color: white;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: background 0.2s;
}

.filter-grid button:hover {
    background-color: #0369a1;
}

/* Cabecera del Formulario con Saldo Integrado */
.form-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}

.saldo-badge {
    background: #f1f5f9;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
    border: 1px solid var(--border-color);
}

/* Formulario de Registro Rápido */
.form-registro {
    background: #f8fafc;
    border: 1px dashed var(--border-color);
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.form-registro .row-reg {
    display: grid;
    grid-template-columns: 120px 100px 120px 145px 1fr 90px 90px 100px;
    gap: 8px;
    align-items: center;
}

@media(max-width: 1100px) {
    .form-registro .row-reg {
        grid-template-columns: 1fr;
    }
}

.form-registro input, .form-registro select {
    padding: 8px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    font-size: 13px;
    width: 100%;
    box-sizing: border-box;
}

.form-registro button {
    background-color: var(--success);
    color: white;
    font-weight: 600;
    border: none;
    padding: 8px;
    border-radius: 6px;
    cursor: pointer;
}

/* Tabla Ejecutiva - Doble columna Factura (Izq) vs Pago (Der) */
.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    table-layout: fixed;
    min-width: 1020px;
}

th {
    background: var(--primary);
    color: #fff;
    padding: 12px 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-align: center;
}

th.th-factura {
    background: #991b1b;
    border-left: 2px solid #fca5a5;
}

th.th-pago {
    background: #065f46;
    border-left: 2px solid #6ee7b7;
    border-right: 2px solid #6ee7b7;
}

td {
    padding: 9px 8px;
    border-bottom: 1px solid var(--border-color);
    text-align: center;
    vertical-align: middle;
    font-size: 13px;
}

tr.neg {
    background-color: #fffafa;
}

tr.pos {
    background-color: #f9fefb;
}

td.col-factura {
    border-left: 2px solid #fecaca;
    background-color: rgba(254, 226, 226, 0.35);
}

td.col-pago {
    border-left: 2px solid #bbf7d0;
    border-right: 2px solid #bbf7d0;
    background-color: rgba(220, 252, 231, 0.35);
}

.monto-factura {
    color: var(--danger) !important;
    font-weight: 700 !important;
    text-align: right;
    background-color: #ffffff !important;
    border-color: #fca5a5 !important;
}

.monto-pago {
    color: var(--success) !important;
    font-weight: 700 !important;
    text-align: right;
    background-color: #ffffff !important;
    border-color: #6ee7b7 !important;
}

.placeholder-vacio {
    color: #cbd5e1;
    font-weight: 700;
    user-select: none;
}

table input, table select {
    width: 100%;
    box-sizing: border-box;
    padding: 6px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 13px;
}

.saving { background-color: #fef3c7 !important; transition: background 0.3s; }

/* SWITCH */
.switch { position: relative; display: inline-block; width: 36px; height: 20px; vertical-align: middle; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 20px; }
.slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
input:checked + .slider { background-color: var(--accent); }
input:checked + .slider:before { transform: translateX(16px); }

.btn-excel {
    background-color: var(--success);
    color: white !important;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: background 0.2s;
}
.btn-excel:hover { background-color: #047857; }

.btn-del { color: var(--danger); font-size: 16px; cursor: pointer; text-decoration: none; }
.paginacion { text-align: center; margin-top: 20px; font-weight: 600; color: var(--text-muted); }
.paginacion a { color: var(--accent); margin: 0 8px; }
</style>
</head>
<body>

<div class="container-fluid">
    
    <!-- TARJETA PRINCIPAL DE FILTROS Y CONTROL -->
    <div class="card-executive">
        <div class="header-title">
            <h2>🏢 Control Gerencial de Cartera de Proveedores</h2>
            <?php if($nit): ?>
                <a href="?proveedor=<?= $nit ?>&consultar=1&filtro_tipo=<?= $filtroTipo ?>&filtro_revision=<?= $filtroRevision ?>&fecha_ini=<?= $fechaIni ?>&fecha_fin=<?= $fechaFin ?>&exportar=1" class="btn-excel">
                    📊 Exportar Reporte Ejecutivo
                </a>
            <?php endif; ?>
        </div>

        <!-- FORMULARIO DE FILTRADO -->
        <form method="GET" class="filter-grid" id="formFiltros">
            <input type="hidden" name="consultar" value="1">
            <select name="proveedor" required onchange="this.form.submit()">
                <option value="">-- Seleccionar Proveedor --</option>
                <?php while($p = $proveedores->fetch_assoc()): ?>
                <option value="<?= $p['Nit'] ?>" <?= $p['Nit'] == $nit ? 'selected' : '' ?>>
                    <?= $p['Nombre'] ?>
                </option>
                <?php endwhile; ?>
            </select>

            <select name="filtro_tipo" onchange="this.form.submit()">
                <option value="">-- Todos los Movimientos --</option>
                <option value="F" <?= $filtroTipo == 'F' ? 'selected' : '' ?>>Solo Facturas (Pasivos)</option>
                <option value="P" <?= $filtroTipo == 'P' ? 'selected' : '' ?>>Solo Pagos (Abonos)</option>
            </select>

            <select name="filtro_revision" onchange="this.form.submit()">
                <option value="">-- Estado de Revisión (idPago) --</option>
                <option value="1" <?= $filtroRevision == '1' ? 'selected' : '' ?>>Revisados (Sí)</option>
                <option value="0" <?= $filtroRevision == '0' ? 'selected' : '' ?>>Pendientes (No)</option>
            </select>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:12px; font-weight:600; color:var(--text-muted);">Desde:</label>
                <input type="date" name="fecha_ini" value="<?= htmlspecialchars($fechaIni) ?>" onchange="this.form.submit()">
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-size:12px; font-weight:600; color:var(--text-muted);">Hasta:</label>
                <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>" onchange="this.form.submit()">
            </div>

            <button name="consultar" value="1">Aplicar Filtros</button>
        </form>
    </div>

    <?php if($nit && isset($_GET['consultar'])): ?>
        
        <!-- FORMULARIO DE NUEVO REGISTRO CON SALDO PTE AL LADO -->
        <div class="card-executive">
            <div class="form-header-row">
                <h4 style="margin:0; font-size:15px; color:var(--primary);">➕ Registrar Nuevo Movimiento para: <strong><?= htmlspecialchars($nombreProveedorActual) ?></strong></h4>
                <div class="saldo-badge">
                    Saldo Pte: <span style="color: <?= $total < 0 ? 'var(--danger)' : 'var(--success)' ?>;">$ <?= number_format($total, 0, ',', '.') ?></span>
                </div>
            </div>

            <form method="POST" class="form-registro" style="margin-bottom: 0;">
                <input type="hidden" name="proveedor" value="<?= $nit ?>">
                <div class="row-reg">
                    <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
                    <select name="tipo">
                        <option value="F">Factura</option>
                        <option value="P">Pago</option>
                    </select>
                    <input name="monto" class="monto-mask" placeholder="Monto ($)" required>
                    <input name="numfact_proveedor" placeholder="Nro Factura Prov.">
                    <input name="descripcion" placeholder="Descripción u observaciones...">
                    <label style="display:flex; align-items:center; gap:4px; font-size:13px; font-weight:600;">
                        <input type="checkbox" name="id_pago" value="1"> idPago
                    </label>
                    <label style="display:flex; align-items:center; gap:4px; font-size:13px; font-weight:600;">
                        <input type="checkbox" name="verificado" value="1"> Verif.
                    </label>
                    <button name="grabar">Registrar</button>
                </div>
            </form>
        </div>

        <!-- TABLA DE DETALLE DE CARTERA (FACTURA A LA IZQUIERDA | PAGO A LA DERECHA) -->
        <div class="card-executive">
            <div class="table-responsive">
                <table>
                <colgroup>
                    <col style="width: 8%;">
                    <col style="width: 7%;">
                    <col style="width: 6%;">
                    <col style="width: 13%;">
                    <col style="width: 13%;">
                    <col style="width: 11%;">
                    <col style="width: 7%;">
                    <col style="width: 7%;">
                    <col style="width: 25%;">
                    <col style="width: 3%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Tipo</th>
                        <th class="th-factura">⬅ Factura (-)</th>
                        <th class="th-pago">Pago (+) ➡</th>
                        <th>Nro Factura</th>
                        <th>idPago</th>
                        <th>Verificado</th>
                        <th>Descripción</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($abonos)): ?>
                    <tr>
                        <td colspan="10" style="padding: 30px; text-align: center; color: var(--text-muted);">No se encontraron registros de cartera bajo los criterios seleccionados.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($abonos as $a): 
                        $rowId = "row_" . $a['F_Creacion'] . "_" . str_replace(':', '', $a['H_Creacion']);
                        $esFactura = ($a['TipoMonto'] == 'F');
                        $valorFormateado = number_format(abs($a['Monto']), 0, ',', '.');
                    ?>
                    <tr id="<?= $rowId ?>" class="<?= $esFactura ? 'neg' : 'pos' ?>">
                        <td><?= $a['F_Creacion'] ?></td>
                        <td><?= $a['H_Creacion'] ?></td>
                        <td>
                            <select class="auto-input" data-field="tipo" data-row="<?= $rowId ?>">
                                <option value="F" <?= $esFactura ? 'selected' : '' ?>>F</option>
                                <option value="P" <?= !$esFactura ? 'selected' : '' ?>>P</option>
                            </select>
                        </td>

                        <!-- LADO IZQUIERDO: FACTURA -->
                        <td class="col-factura">
                            <input class="monto-mask auto-input monto-factura" data-field="monto_factura" data-row="<?= $rowId ?>"
                                   style="<?= $esFactura ? '' : 'display:none;' ?>"
                                   value="<?= $esFactura ? $valorFormateado : '' ?>">
                            <span class="placeholder-vacio placeholder-factura" style="<?= $esFactura ? 'display:none;' : '' ?>">—</span>
                        </td>

                        <!-- LADO DERECHO: PAGO -->
                        <td class="col-pago">
                            <input class="monto-mask auto-input monto-pago" data-field="monto_pago" data-row="<?= $rowId ?>"
                                   style="<?= !$esFactura ? '' : 'display:none;' ?>"
                                   value="<?= !$esFactura ? $valorFormateado : '' ?>">
                            <span class="placeholder-vacio placeholder-pago" style="<?= !$esFactura ? 'display:none;' : '' ?>">—</span>
                        </td>

                        <td>
                            <input class="auto-input" data-field="numfact_proveedor" style="text-align:center;"
                                   value="<?= htmlspecialchars($a['NumfactProveedor'] ?? '') ?>">
                        </td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" class="auto-input" data-field="id_pago" data-row="<?= $rowId ?>" value="1" <?= $a['idPago'] == 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" class="auto-input" data-field="verificado" data-row="<?= $rowId ?>" value="1" <?= (isset($a['Verificado']) && $a['Verificado'] == 1) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <input class="auto-input" data-field="descripcion" style="text-align:left;"
                                   value="<?= htmlspecialchars($a['Descripcion']) ?>">
                        </td>
                        <td>
                            <input type="hidden" class="key-nit" value="<?= $a['Nit'] ?>">
                            <input type="hidden" class="key-fecha" value="<?= $a['F_Creacion'] ?>">
                            <input type="hidden" class="key-hora" value="<?= $a['H_Creacion'] ?>">
                            <input type="hidden" class="valor-actual" value="<?= $valorFormateado ?>">
                            
                            <a class="btn-del"
                               href="?proveedor=<?= $nit ?>&consultar=1&filtro_tipo=<?= $filtroTipo ?>&filtro_revision=<?= $filtroRevision ?>&fecha_ini=<?= $fechaIni ?>&fecha_fin=<?= $fechaFin ?>&borrar=1&nit=<?= $a['Nit'] ?>&f=<?= $a['F_Creacion'] ?>&h=<?= $a['H_Creacion'] ?>"
                               onclick="return confirm('¿Está seguro de eliminar este registro de cartera?')" title="Eliminar registro">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                </table>
            </div>

            <!-- PAGINACIÓN -->
            <?php if($totalPaginas > 1): ?>
            <div class="paginacion">
                <?php if($pagina > 1): ?>
                    <a href="?proveedor=<?= $nit ?>&consultar=1&filtro_tipo=<?= $filtroTipo ?>&filtro_revision=<?= $filtroRevision ?>&fecha_ini=<?= $fechaIni ?>&fecha_fin=<?= $fechaFin ?>&page=<?= $pagina - 1 ?>">⬅ Anterior</a>
                <?php endif; ?>
                 Página <?= $pagina ?> de <?= $totalPaginas ?> 
                <?php if($pagina < $totalPaginas): ?>
                    <a href="?proveedor=<?= $nit ?>&consultar=1&filtro_tipo=<?= $filtroTipo ?>&filtro_revision=<?= $filtroRevision ?>&fecha_ini=<?= $fechaIni ?>&fecha_fin=<?= $fechaFin ?>&page=<?= $pagina + 1 ?>">Siguiente ➡</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<script>
// MÁSCARA DE MILES
function aplicarMascara(input) {
    let v = input.value.replace(/\D/g,'');
    input.value = v.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
}

document.querySelectorAll('.monto-mask').forEach(input => {
    input.addEventListener('input', () => aplicarMascara(input));
});

// GUARDADO AUTOMÁTICO VÍA AJAX CON FEEDBACK VISUAL GERENCIAL
document.querySelectorAll('.auto-input').forEach(input => {
    input.addEventListener('change', function() {
        const tr = this.closest('tr');
        tr.classList.add('saving');

        const nit   = tr.querySelector('.key-nit').value;
        const fecha = tr.querySelector('.key-fecha').value;
        const hora  = tr.querySelector('.key-hora').value;
        
        const tipoSelect  = tr.querySelector('[data-field="tipo"]');
        const tipo        = tipoSelect.value;
        const inpFactura  = tr.querySelector('[data-field="monto_factura"]');
        const inpPago     = tr.querySelector('[data-field="monto_pago"]');
        const phFactura   = tr.querySelector('.placeholder-factura');
        const phPago      = tr.querySelector('.placeholder-pago');
        const valorHidden = tr.querySelector('.valor-actual');

        // Si cambió el tipo (F <-> P), mover visualmente el valor entre izquierda y derecha
        if (this.dataset.field === 'tipo') {
            const montoActual = valorHidden.value || inpFactura.value || inpPago.value || '0';
            if (tipo === 'F') {
                inpFactura.value = montoActual;
                inpFactura.style.display = '';
                phFactura.style.display = 'none';

                inpPago.value = '';
                inpPago.style.display = 'none';
                phPago.style.display = '';
            } else {
                inpPago.value = montoActual;
                inpPago.style.display = '';
                phPago.style.display = 'none';

                inpFactura.value = '';
                inpFactura.style.display = 'none';
                phFactura.style.display = '';
            }
        }

        const monto = (tipo === 'F') ? inpFactura.value : inpPago.value;
        valorHidden.value = monto;

        const numfact     = tr.querySelector('[data-field="numfact_proveedor"]').value;
        const idPago      = tr.querySelector('[data-field="id_pago"]').checked ? 1 : 0;
        const verificado  = tr.querySelector('[data-field="verificado"]').checked ? 1 : 0;
        const descripcion = tr.querySelector('[data-field="descripcion"]').value;

        const formData = new FormData();
        formData.append('action', 'auto_save');
        formData.append('nit', nit);
        formData.append('fecha', fecha);
        formData.append('hora', hora);
        formData.append('tipo', tipo);
        formData.append('monto', monto);
        formData.append('numfact_proveedor', numfact);
        formData.append('id_pago', idPago);
        formData.append('verificado', verificado);
        formData.append('descripcion', descripcion);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            tr.classList.remove('saving');
            if(!data.success) {
                alert('Aviso: No se pudo sincronizar el cambio automáticamente.');
            } else {
                tr.className = (tipo === 'F') ? 'neg' : 'pos';
            }
        })
        .catch(() => {
            tr.classList.remove('saving');
            alert('Error de red al intentar actualizar el registro.');
        });
    });
});
</script>

</body>
</html>