<?php
/* ============================================================
    CONFIGURACIÓN DE TIEMPO Y CONEXIONES
============================================================ */
date_default_timezone_set('America/Bogota');
session_start();

require("ConnCentral.php"); 
require("Conexion.php"); 
require("ConnDrinks.php"); 

$db_conexion = $mysqliWeb ?? $mysqli ?? null;

$fecha_ini_input = $_GET['fecha_ini'] ?? date('Y-m-d');
$fecha_fin_input = $_GET['fecha_fin'] ?? date('Y-m-d');

$f_ini_db = str_replace('-', '', $fecha_ini_input);
$f_fin_db = str_replace('-', '', $fecha_fin_input);

$mensaje_exito = "";

/* ============================================================
    PROCESAR ACCIONES (EDITAR / ELIMINAR / MANUALES)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_conexion && $db_conexion instanceof mysqli) {
    
    if (isset($_POST['accion_guardar_arranque'])) {
        $fecha_arranque = $db_conexion->real_escape_string($_POST['fecha_arranque']);
        $valor_arranque = (float)$_POST['valor_arranque'];
        $sede_arranque = $db_conexion->real_escape_string($_POST['sede_arranque']);

        $check_arr = "SELECT id_transaccion FROM flujo_efectivo WHERE fecha = '$fecha_arranque' AND sede = '$sede_arranque' AND motivo = '[ARRANQUE DE CAJA]' LIMIT 1";
        $res_check = $db_conexion->query($check_arr);

        if ($res_check && $res_check->num_rows > 0) {
            $row_ex = $res_check->fetch_assoc();
            $id_t = $row_ex['id_transaccion'];
            $db_conexion->query("UPDATE flujo_efectivo SET valor = $valor_arranque WHERE id_transaccion = $id_t");
            $mensaje_exito = "¡Arranque del día $fecha_arranque actualizado con éxito!";
        } else {
            $db_conexion->query("INSERT INTO flujo_efectivo (sede, tipo, fecha, nombre_tercero, motivo, valor, nombre_pc, id_origen) 
                        VALUES ('$sede_arranque', 'INGRESO', '$fecha_arranque', 'SISTEMA', '[ARRANQUE DE CAJA]', $valor_arranque, 'MANUAL_WEB', NULL)");
            $mensaje_exito = "¡Arranque del día $fecha_arranque registrado con éxito!";
        }
    }

    if (isset($_POST['accion_eliminar_arranque'])) {
        $fecha_arranque = $db_conexion->real_escape_string($_POST['fecha_arranque']);
        $sede_arranque = $db_conexion->real_escape_string($_POST['sede_arranque']);
        $db_conexion->query("DELETE FROM flujo_efectivo WHERE fecha = '$fecha_arranque' AND sede = '$sede_arranque' AND motivo = '[ARRANQUE DE CAJA]'");
        $mensaje_exito = "¡Arranque eliminado con éxito!";
    }

    if (isset($_POST['accion_purgar'])) {
        $fecha_limite = $db_conexion->real_escape_string($_POST['fecha_limite']);
        if (!empty($fecha_limite)) {
            $db_conexion->query("DELETE FROM flujo_efectivo WHERE fecha < '$fecha_limite'");
            $mensaje_exito = "¡Registros anteriores a $fecha_limite eliminados!";
        }
    }

    if (isset($_POST['accion_eliminar'])) {
        $id_transaccion = (int)$_POST['id_transaccion'];
        $db_conexion->query("DELETE FROM flujo_efectivo WHERE id_transaccion = $id_transaccion");
        $mensaje_exito = "¡Movimiento eliminado con éxito!";
    }

    if (isset($_POST['accion_manual']) || isset($_POST['accion_editar'])) {
        $id_transaccion = (int)($_POST['id_transaccion'] ?? 0);
        $sede_manual = $db_conexion->real_escape_string($_POST['sede_manual']);
        $tipo_form = $_POST['tipo_manual'];
        $tipo_manual = ($tipo_form === 'GASTO') ? 'PAGO' : $db_conexion->real_escape_string($tipo_form);
        $fecha_manual = $db_conexion->real_escape_string($_POST['fecha_manual']);
        $tercero_manual = $db_conexion->real_escape_string($_POST['tercero_manual']);
        $motivo_manual = $db_conexion->real_escape_string($_POST['motivo_manual']);
        $valor_manual = (float)$_POST['valor_manual'];

        if ($valor_manual > 0 && !empty($motivo_manual)) {
            if (isset($_POST['accion_editar']) && $id_transaccion > 0) {
                $db_conexion->query("UPDATE flujo_efectivo SET sede='$sede_manual', tipo='$tipo_manual', fecha='$fecha_manual', nombre_tercero='$tercero_manual', motivo='$motivo_manual', valor=$valor_manual WHERE id_transaccion=$id_transaccion");
                $mensaje_exito = "¡Movimiento actualizado con éxito!";
            } else {
                $db_conexion->query("INSERT INTO flujo_efectivo (sede, tipo, fecha, nombre_tercero, motivo, valor, nombre_pc, id_origen) VALUES ('$sede_manual', '$tipo_manual', '$fecha_manual', '$tercero_manual', '$motivo_manual', $valor_manual, 'MANUAL_WEB', NULL)");
                $mensaje_exito = "¡Movimiento registrado con éxito!";
            }
        }
    }
}

/* ============================================================
    LEER ARRANQUE
============================================================ */
$arranque_operacion = 0;
if ($db_conexion) {
    $fecha_ayer = date('Y-m-d', strtotime($fecha_ini_input . ' - 1 day'));
    $res_arr = $db_conexion->query("SELECT SUM(CASE WHEN tipo = 'INGRESO' THEN valor ELSE -valor END) AS total FROM flujo_efectivo WHERE fecha = '$fecha_ayer' AND motivo = '[ARRANQUE DE CAJA]'");
    if ($res_arr && $row_arr = $res_arr->fetch_assoc()) {
        $arranque_operacion = (float)($row_arr['total'] ?? 0);
    }
}

/* ============================================================
    CONSULTA Y CONSOLIDACIÓN (TODO UNIFICADO A FLUJO_EFECTIVO)
============================================================ */
$sedes = [
    'CENTRAL' => $mysqliCentral,
    'DRINKS'  => $mysqliDrinks
];

// Sincronizar automáticos a flujo_efectivo temporalmente o leerlos e integrarlos con ID virtual editable
$reporte = [];
$resumen_sedes = ['CENTRAL' => 0, 'DRINKS' => 0];
$total_ingresos = 0;
$total_egresos = 0;
$gran_total = $arranque_operacion; 

// 1. Cargar Automáticos de Salidas de Caja y asegurar que tengan espejo o gestión editable
foreach ($sedes as $nombre_sede => $conexion_sede) {
    if (!$conexion_sede) continue;

    $query = "SELECT 
                S1.NOMBREPC, S1.FECHA, T1.NIT, V1.IDTERCERO,
                CONCAT(T1.nombres, ' ', T1.apellidos) AS USUA, 
                S1.MOTIVO, S1.VALOR, S1.IDSALIDA
              FROM SALIDASCAJA S1   
              INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = S1.IDUSUARIO
              INNER JOIN TERCEROS AS T1 ON T1.IDTERCERO = V1.IDTERCERO
              WHERE (S1.FECHA BETWEEN '$f_ini_db' AND '$f_fin_db')
              ORDER BY S1.VALOR DESC";

    $res = $conexion_sede->query($query);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $idsalida_orig = $row['IDSALIDA'];
            
            // Verificar si ya existe en flujo_efectivo como espejo, si no, crearlo automáticamente para que sea editable/borrable
            if ($db_conexion) {
                $check_espejo = $db_conexion->query("SELECT id_transaccion FROM flujo_efectivo WHERE id_origen = '$idsalida_orig' AND sede = '$nombre_sede' LIMIT 1");
                if ($check_espejo && $check_espejo->num_rows == 0) {
                    $f_formato = substr($row['FECHA'],0,4).'-'.substr($row['FECHA'],4,2).'-'.substr($row['FECHA'],6,2);
                    $cajero_sql = $db_conexion->real_escape_string(trim($row['USUA']) ?: 'CAJERO GENERAL');
                    $motivo_sql = $db_conexion->real_escape_string($row['MOTIVO']);
                    $pc_sql = $db_conexion->real_escape_string($row['NOMBREPC']);
                    $valor_sql = (float)$row['VALOR'];
                    
                    $db_conexion->query("INSERT INTO flujo_efectivo (sede, tipo, fecha, nombre_tercero, motivo, valor, nombre_pc, id_origen) 
                                         VALUES ('$nombre_sede', 'PAGO', '$f_formato', '$cajero_sql', '$motivo_sql', $valor_sql, '$pc_sql', '$idsalida_orig')");
                }
            }
        }
    }
}

// 2. Cargar Todo Directamente desde flujo_efectivo (Garantiza que TODO sea editable y borrable)
if ($db_conexion) {
    $query_manuales = "SELECT * FROM flujo_efectivo WHERE fecha BETWEEN '$fecha_ini_input' AND '$fecha_fin_input' ORDER BY valor DESC";
    $res_m = $db_conexion->query($query_manuales);
    if ($res_m) {
        while ($row_m = $res_m->fetch_assoc()) {
            if ($row_m['motivo'] === '[ARRANQUE DE CAJA]') continue; 

            $cajero = trim($row_m['nombre_tercero'] ?? 'GENERAL');
            if(empty($cajero)) $cajero = 'GENERAL';
            
            $valor_m = (float)$row_m['valor'];
            $tipo_real = $row_m['tipo']; 
            
            if ($tipo_real === 'PAGO') {
                $total_egresos += $valor_m;
                $valor_neto = -$valor_m; 
            } else {
                $total_ingresos += $valor_m;
                $valor_neto = $valor_m;
            }

            $row_format = [
                'SEDE_ORIGEN' => $row_m['sede'],
                'FECHA' => str_replace('-', '', $row_m['fecha']),
                'IDSALIDA' => $row_m['id_origen'] ? $row_m['id_origen'] : 'MANUAL-' . $row_m['id_transaccion'],
                'ID_TRANSACCION_REAL' => $row_m['id_transaccion'],
                'NOMBREPC' => $row_m['nombre_pc'],
                'MOTIVO' => '[' . $tipo_real . '] ' . $row_m['motivo'],
                'MOTIVO_PURO' => $row_m['motivo'],
                'TIPO_PURO' => $tipo_real,
                'VALOR' => $valor_neto,
                'VALOR_REAL' => (float)$row_m['valor'],
                'NIT' => $row_m['nit_tercero'] ?? 'N/A',
                'ES_MANUAL' => true // ¡Ahora TODOS tienen permisos de edición y borrado!
            ];

            $reporte[$cajero][] = $row_format;
            $resumen_sedes[$row_m['sede']] += $valor_neto;
            $gran_total += $valor_neto;
        }
    }
}

ksort($reporte);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }
function formatFecha($f){ return strlen($f)==8 ? substr($f,0,4)."-".substr($f,4,2)."-".substr($f,6,2) : $f; }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Consolidado de Recaudo y Flujo</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .wrapper { max-width: 1100px; margin: auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 25px; }
        .summary-grid { display: grid; grid-template-columns: 2fr 1.2fr; gap: 20px; margin-bottom: 40px; }
        .summary-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .summary-card h3 { margin-top: 0; color: #2c3e50; border-bottom: 2px solid #27ae60; padding-bottom: 8px; }
        .resumen-table { width: 100%; border-collapse: collapse; }
        .resumen-table td { padding: 8px 0; border-bottom: 1px dashed #eee; }
        .cajero-card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px; background: #fff; }
        .cajero-header { background: #2c3e50; color: white; padding: 12px 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0; }
        .btn-modal { background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: #fff; width: 90%; max-width: 850px; max-height: 85vh; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; }
        .modal-header { background: #2c3e50; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; overflow-y: auto; }
        .close-btn { background: none; border: none; color: white; font-size: 1.5em; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 0.9em; }
        th { background: #f8f9fa; color: #7f8c8d; text-transform: uppercase; font-size: 0.75em; }
        .badge-sede { padding: 3px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-CENTRAL { background: #d4edda; color: #155724; }
        .badge-DRINKS { background: #fff3cd; color: #856404; }
        .gran-total-banner { background: #27ae60; color: white; padding: 25px; border-radius: 10px; text-align: center; font-size: 2.2em; margin-top: 30px; }
        .no-print { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 10px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .form-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index: 1100; }
        .form-box { background: white; padding: 30px; border-radius: 10px; width: 90%; max-width: 500px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .btn-accion { padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer; font-size: 0.8em; color: white; font-weight: bold; }
        .btn-editar { background: #f39c12; }
        .btn-eliminar { background: #e74c3c; }
        @media print { .no-print, .btn-modal, .acciones-col { display: none; } .wrapper { box-shadow: none; width: 100%; } }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="header">
        <h1 style="margin:0;">REPORTE CONSOLIDADO DE RECAUDO Y FLUJO</h1>
        <p style="color:#7f8c8d;">Periodo: <?= $fecha_ini_input ?> al <?= $fecha_fin_input ?></p>
    </div>

    <?php if(!empty($mensaje_exito)): ?>
        <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold;"><?= $mensaje_exito ?></div>
    <?php endif; ?>

    <div class="no-print">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <label>Desde: <input type="date" name="fecha_ini" value="<?= $fecha_ini_input ?>"></label>
            <label>Hasta: <input type="date" name="fecha_fin" value="<?= $fecha_fin_input ?>"></label>
            <button type="submit" style="background:#2c3e50; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Actualizar</button>
        </form>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" onclick="fijarTotalComoArranque('<?= $gran_total ?>', '<?= $fecha_ini_input ?>')" style="background:#e67e22; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer; font-weight:bold;">⚡ Guardar Total General como Arranque</button>
            <button type="button" onclick="abrirFormArranque()" style="background:#2980b9; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">⚙️ Fijar Arranque Diario</button>
            <button type="button" onclick="abrirFormManual()" style="background:#27ae60; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">➕ Nuevo Movimiento</button>
            <button type="button" onclick="abrirFormPurgar()" style="background:#c0392b; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">🗑️ Limpiar Historial</button>
            <button type="button" onclick="window.print()" style="padding:10px 15px; cursor:pointer; border: 1px solid #ccc; border-radius:5px; background:#fff;">🖨️ Imprimir</button>
        </div>
    </div>

    <!-- Modales de Gestión -->
    <div id="modalFormArranque" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2980b9;">⚙️ Gestionar Arranque Físico Diario</h3>
            <form method="POST">
                <div style="margin-bottom: 12px;"><label>Fecha:</label><input type="date" name="fecha_arranque" value="<?= $fecha_ini_input ?>" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 12px;"><label>Sede:</label><select name="sede_arranque" style="width:100%; padding:8px; margin-top:5px;"><option value="CENTRAL">CENTRAL</option><option value="DRINKS">DRINKS</option></select></div>
                <div style="margin-bottom: 20px;"><label>Valor ($):</label><input type="number" step="any" name="valor_arranque" placeholder="0" style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="display: flex; justify-content: space-between;"><button type="button" onclick="cerrarFormArranque()" style="background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px;">Cancelar</button><div><button type="submit" name="accion_eliminar_arranque" value="1" style="background:#c0392b; color:white; border:none; padding:10px; border-radius:5px;">Eliminar</button> <button type="submit" name="accion_guardar_arranque" value="1" style="background:#2980b9; color:white; border:none; padding:10px; border-radius:5px;">Guardar</button></div></div>
            </form>
        </div>
    </div>

    <div id="modalFormPurgar" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#c0392b;">🗑️ Purgar Registros</h3>
            <form method="POST"><input type="hidden" name="accion_purgar" value="1"><div style="margin-bottom: 15px;"><label>Borrar anterior a:</label><input type="date" name="fecha_limite" value="2026-07-31" required style="width:100%; padding:8px; margin-top:5px;"></div><div style="display: flex; justify-content: flex-end; gap: 10px;"><button type="button" onclick="cerrarFormPurgar()" style="background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px;">Cancelar</button><button type="submit" style="background:#c0392b; color:white; border:none; padding:10px; border-radius:5px;">Eliminar</button></div></form>
        </div>
    </div>

    <div id="modalFormManual" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2c3e50;">➕ Registrar Movimiento</h3>
            <form method="POST"><input type="hidden" name="accion_manual" value="1">
                <div style="margin-bottom: 12px;"><label>Tipo:</label><select name="tipo_manual" style="width:100%; padding:8px; margin-top:5px;"><option value="INGRESO">Ingreso</option><option value="GASTO">Gasto (Pago)</option></select></div>
                <div style="margin-bottom: 12px;"><label>Sede:</label><select name="sede_manual" style="width:100%; padding:8px; margin-top:5px;"><option value="CENTRAL">CENTRAL</option><option value="DRINKS">DRINKS</option></select></div>
                <div style="margin-bottom: 12px;"><label>Fecha:</label><input type="date" name="fecha_manual" value="<?= $fecha_ini_input ?>" style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 12px;"><label>Tercero:</label><input type="text" name="tercero_manual" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 12px;"><label>Motivo:</label><input type="text" name="motivo_manual" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 20px;"><label>Valor ($):</label><input type="number" step="any" name="valor_manual" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;"><button type="button" onclick="cerrarFormManual()" style="background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px;">Cancelar</button><button type="submit" style="background:#27ae60; color:white; border:none; padding:10px; border-radius:5px;">Guardar</button></div>
            </form>
        </div>
    </div>

    <!-- Modal Formulario Editar -->
    <div id="modalFormEditar" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2c3e50;">✏️ Editar Movimiento</h3>
            <form method="POST">
                <input type="hidden" name="accion_editar" value="1">
                <input type="hidden" name="id_transaccion" id="edit_id">
                <div style="margin-bottom: 12px;"><label>Tipo:</label><select name="tipo_manual" id="edit_tipo" style="width:100%; padding:8px; margin-top:5px;"><option value="INGRESO">Ingreso</option><option value="GASTO">Gasto (Pago)</option></select></div>
                <div style="margin-bottom: 12px;"><label>Sede:</label><select name="sede_manual" id="edit_sede" style="width:100%; padding:8px; margin-top:5px;"><option value="CENTRAL">CENTRAL</option><option value="DRINKS">DRINKS</option></select></div>
                <div style="margin-bottom: 12px;"><label>Fecha:</label><input type="date" name="fecha_manual" id="edit_fecha" style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 12px;"><label>Tercero:</label><input type="text" name="tercero_manual" id="edit_tercero" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 12px;"><label>Motivo:</label><input type="text" name="motivo_manual" id="edit_motivo" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="margin-bottom: 20px;"><label>Valor ($):</label><input type="number" step="any" name="valor_manual" id="edit_valor" required style="width:100%; padding:8px; margin-top:5px;"></div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;"><button type="button" onclick="cerrarFormEditar()" style="background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px;">Cancelar</button><button type="submit" style="background:#f39c12; color:white; border:none; padding:10px; border-radius:5px;">Actualizar</button></div>
            </form>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <h3>📊 Resumen por Responsables</h3>
            <table class="resumen-table">
                <?php if(empty($reporte)): ?>
                    <tr><td colspan="2" style="text-align:center; color:#95a5a6;">No hay registros en este rango.</td></tr>
                <?php else: ?>
                    <?php foreach($reporte as $nom => $movs): 
                        $sub = 0; foreach($movs as $m) { $sub += $m['VALOR']; }
                    ?>
                    <tr>
                        <td><strong><?= strtoupper($nom) ?></strong></td>
                        <td align="right" style="color:<?= $sub >= 0 ? '#27ae60' : '#c0392b' ?>; font-weight:bold;">$ <?= money($sub) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>

        <div class="summary-card">
            <h3>🏢 Resumen Financiero</h3>
            <table class="resumen-table">
                <tr><td>Arranque de Operación:</td><td align="right" style="color: <?= $arranque_operacion >= 0 ? '#27ae60' : '#c0392b' ?>; font-weight:bold;">$ <?= money($arranque_operacion) ?></td></tr>
                <tr><td>(+) Total Ingresos:</td><td align="right" style="color:#27ae60; font-weight:bold;">$ <?= money($total_ingresos) ?></td></tr>
                <tr><td>(-) Total Egresos / Pagos:</td><td align="right" style="color:#c0392b; font-weight:bold;">$ <?= money($total_egresos) ?></td></tr>
                <tr><td>Recaudo Sede Central:</td><td align="right">$ <?= money($resumen_sedes['CENTRAL']) ?></td></tr>
                <tr><td>Recaudo Sede Drinks:</td><td align="right">$ <?= money($resumen_sedes['DRINKS']) ?></td></tr>
                <tr style="border-top: 2px solid #2c3e50; font-weight:bold;"><td>TOTAL GENERAL:</td><td align="right" style="font-size:1.2em; color: <?= $gran_total >= 0 ? '#27ae60' : '#c0392b' ?>;">$ <?= money($gran_total) ?></td></tr>
            </table>
        </div>
    </div>

    <h2 style="color:#2c3e50; text-align:center;">📋 Listado Detallado</h2>
    
    <?php foreach ($reporte as $cajero => $movs): 
        $sub = 0; foreach ($movs as $m) { $sub += $m['VALOR']; }
    ?>
        <div class="cajero-card">
            <div class="cajero-header">
                <div><span>👤 <?= strtoupper($cajero) ?></span></div>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <span style="font-weight:bold; color: <?= $sub >= 0 ? '#2ecc71' : '#e74c3c' ?>;">$ <?= money($sub) ?></span>
                    <button class="btn-modal" onclick="abrirModal('modal-<?= md5($cajero) ?>')">Ver Detalle (<?= count($movs) ?>)</button>
                </div>
            </div>
        </div>

        <div id="modal-<?= md5($cajero) ?>" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header"><span>Detalle: <?= strtoupper($cajero) ?></span><button class="close-btn" onclick="cerrarModal('modal-<?= md5($cajero) ?>')">&times;</button></div>
                <div class="modal-body">
                    <table>
                        <thead>
                            <tr>
                                <th>Sede</th>
                                <th>Fecha</th>
                                <th>ID</th>
                                <th>PC</th>
                                <th>Motivo</th>
                                <th align="right">Valor</th>
                                <th align="center" class="acciones-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movs as $m): ?>
                            <tr>
                                <td><span class="badge-sede badge-<?= $m['SEDE_ORIGEN'] ?>"><?= $m['SEDE_ORIGEN'] ?></span></td>
                                <td><?= formatFecha($m['FECHA']) ?></td>
                                <td>#<?= $m['IDSALIDA'] ?></td>
                                <td><small><?= $m['NOMBREPC'] ?></small></td>
                                <td><?= $m['MOTIVO'] ?></td>
                                <td align="right" style="color: <?= $m['VALOR'] < 0 ? '#c0392b' : '#27ae60' ?>"><strong>$ <?= money($m['VALOR']) ?></strong></td>
                                <td align="center" class="acciones-col">
                                    <button class="btn-accion btn-editar" onclick="abrirEditar(
                                        '<?= $m['ID_TRANSACCION_REAL'] ?>',
                                        '<?= $m['SEDE_ORIGEN'] ?>',
                                        '<?= $m['TIPO_PURO'] === 'PAGO' ? 'GASTO' : $m['TIPO_PURO'] ?>',
                                        '<?= formatFecha($m['FECHA']) ?>',
                                        '<?= addslashes($cajero) ?>',
                                        '<?= addslashes($m['MOTIVO_PURO']) ?>',
                                        '<?= $m['VALOR_REAL'] ?>'
                                    )">✏️</button>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar este movimiento?');">
                                        <input type="hidden" name="accion_eliminar" value="1">
                                        <input type="hidden" name="id_transaccion" value="<?= $m['ID_TRANSACCION_REAL'] ?>">
                                        <button type="submit" class="btn-accion btn-eliminar">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="gran-total-banner">
        <small style="font-size: 0.4em; display: block; opacity: 0.8;">BALANCE TOTAL GENERAL</small>
        $ <?= money($gran_total) ?>
    </div>
</div>

<script>
    function abrirModal(id) { document.getElementById(id).style.display = 'flex'; }
    function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
    function abrirFormManual() { document.getElementById('modalFormManual').style.display = 'flex'; }
    function cerrarFormManual() { document.getElementById('modalFormManual').style.display = 'none'; }
    function abrirFormArranque() { document.getElementById('modalFormArranque').style.display = 'flex'; }
    function cerrarFormArranque() { document.getElementById('modalFormArranque').style.display = 'none'; }
    function abrirFormPurgar() { document.getElementById('modalFormPurgar').style.display = 'flex'; }
    function cerrarFormPurgar() { document.getElementById('modalFormPurgar').style.display = 'none'; }
    
    function abrirEditar(id, sede, tipo, fecha, tercero, motivo, valor) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_sede').value = sede;
        document.getElementById('edit_tipo').value = tipo;
        document.getElementById('edit_fecha').value = fecha;
        document.getElementById('edit_tercero').value = tercero;
        document.getElementById('edit_motivo').value = motivo;
        document.getElementById('edit_valor').value = valor;
        document.getElementById('modalFormEditar').style.display = 'flex';
    }
    function cerrarFormEditar() {
        document.getElementById('modalFormEditar').style.display = 'none';
    }

    function fijarTotalComoArranque(montoTotal, fechaDesde) {
        abrirFormArranque();
        document.querySelector('#modalFormArranque input[name="valor_arranque"]').value = montoTotal;
        document.querySelector('#modalFormArranque input[name="fecha_arranque"]').value = fechaDesde;
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay') || event.target.classList.contains('form-overlay')) {
            event.target.style.display = "none";
        }
    }
</script>
</body>
</html>