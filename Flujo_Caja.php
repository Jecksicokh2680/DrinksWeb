<?php
/* ============================================================
    CONFIGURACIÓN DE TIEMPO Y CONEXIONES
============================================================ */
date_default_timezone_set('America/Bogota');
session_start();

require("ConnCentral.php"); 
require("Conexion.php"); // Define $mysqli y $mysqliWeb
require("ConnDrinks.php"); 

// Asegurarnos de que la conexión web esté disponible
$db_conexion = $mysqliWeb ?? $mysqli ?? null;

$fecha_ini_input = $_GET['fecha_ini'] ?? date('Y-m-d');
$fecha_fin_input = $_GET['fecha_fin'] ?? date('Y-m-d');

$f_ini_db = str_replace('-', '', $fecha_ini_input);
$f_fin_db = str_replace('-', '', $fecha_fin_input);

$mensaje_exito = "";

/* ============================================================
    PROCESAR ACCIONES MANUALES (INSERTAR, EDITAR, ELIMINAR)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_conexion && $db_conexion instanceof mysqli) {
    
    // 1. ELIMINAR MOVIMIENTO MANUAL
    if (isset($_POST['accion_eliminar'])) {
        $id_transaccion = (int)$_POST['id_transaccion'];
        $del_sql = "DELETE FROM flujo_efectivo WHERE id_transaccion = $id_transaccion AND id_origen IS NULL";
        if ($db_conexion->query($del_sql)) {
            $mensaje_exito = "¡Movimiento manual eliminado con éxito!";
        } else {
            $mensaje_exito = "❌ Error al eliminar: " . $db_conexion->error;
        }
    }

    // 2. EDITAR MOVIMIENTO MANUAL
    if (isset($_POST['accion_editar'])) {
        $id_transaccion = (int)$_POST['id_transaccion'];
        $sede_manual = $db_conexion->real_escape_string($_POST['sede_manual']);
        
        $tipo_form = $_POST['tipo_manual'];
        $tipo_manual = ($tipo_form === 'GASTO') ? 'PAGO' : $db_conexion->real_escape_string($tipo_form);

        $fecha_manual = $db_conexion->real_escape_string($_POST['fecha_manual']);
        $tercero_manual = $db_conexion->real_escape_string($_POST['tercero_manual']);
        $motivo_manual = $db_conexion->real_escape_string($_POST['motivo_manual']);
        $valor_manual = (float)$_POST['valor_manual'];

        if ($valor_manual > 0 && !empty($motivo_manual)) {
            $update_sql = "UPDATE flujo_efectivo SET 
                            sede = '$sede_manual', 
                            tipo = '$tipo_manual', 
                            fecha = '$fecha_manual', 
                            nombre_tercero = '$tercero_manual', 
                            motivo = '$motivo_manual', 
                            valor = $valor_manual 
                           WHERE id_transaccion = $id_transaccion AND id_origen IS NULL";
            
            if ($db_conexion->query($update_sql)) {
                $mensaje_exito = "¡Movimiento manual actualizado con éxito!";
            } else {
                $mensaje_exito = "❌ Error al actualizar: " . $db_conexion->error;
            }
        } else {
            $mensaje_exito = "❌ Error: Complete todos los campos obligatorios y un valor mayor a 0.";
        }
    }

    // 3. NUEVO MOVIMIENTO MANUAL
    if (isset($_POST['accion_manual'])) {
        $sede_manual = $db_conexion->real_escape_string($_POST['sede_manual']);
        
        $tipo_form = $_POST['tipo_manual'];
        $tipo_manual = ($tipo_form === 'GASTO') ? 'PAGO' : $db_conexion->real_escape_string($tipo_form);

        $fecha_manual = $db_conexion->real_escape_string($_POST['fecha_manual']);
        $tercero_manual = $db_conexion->real_escape_string($_POST['tercero_manual']);
        $motivo_manual = $db_conexion->real_escape_string($_POST['motivo_manual']);
        $valor_manual = (float)$_POST['valor_manual'];
        $pc_manual = "MANUAL_WEB";

        if ($valor_manual > 0 && !empty($motivo_manual)) {
            $insert_manual = "INSERT INTO flujo_efectivo 
                (sede, tipo, fecha, nombre_tercero, motivo, valor, nombre_pc, id_origen) 
               VALUES 
                ('$sede_manual', '$tipo_manual', '$fecha_manual', '$tercero_manual', '$motivo_manual', $valor_manual, '$pc_manual', NULL)";
            
            if ($db_conexion->query($insert_manual)) {
                $mensaje_exito = "¡Movimiento manual registrado con éxito!";
            } else {
                $mensaje_exito = "❌ Error en SQL: " . $db_conexion->error;
            }
        } else {
            $mensaje_exito = "❌ Error: Complete todos los campos obligatorios y un valor mayor a 0.";
        }
    }
}

/* ============================================================
    CÁLCULO DEL ARRANQUE DE LA OPERACIÓN (Saldo Día Anterior)
============================================================ */
$arranque_operacion = 0;
if ($db_conexion) {
    $fecha_ayer = date('Y-m-d', strtotime($fecha_ini_input . ' - 1 day'));

    $sql_arranque = "SELECT 
        SUM(CASE WHEN tipo = 'INGRESO' THEN valor ELSE 0 END) - 
        SUM(CASE WHEN tipo = 'PAGO' THEN valor ELSE 0 END) AS saldo_dia_anterior 
        FROM flujo_efectivo 
        WHERE fecha = '$fecha_ayer'";
        
    $res_arranque = $db_conexion->query($sql_arranque);
    if ($res_arranque && $row_arr = $res_arranque->fetch_assoc()) {
        $arranque_operacion = (float)($row_arr['saldo_dia_anterior'] ?? 0);
    }
}

/* ============================================================
    LÓGICA DE CONSULTA MULTI-SEDE Y CONSOLIDACIÓN
============================================================ */
$sedes = [
    'CENTRAL' => $mysqliCentral,
    'DRINKS'  => $mysqliDrinks
];

$reporte = [];
$resumen_cajeros = [];
$resumen_sedes = ['CENTRAL' => 0, 'DRINKS' => 0];
$total_ingresos = 0;
$total_egresos = 0;
$gran_total = $arranque_operacion; 

// 1. Cargar desde las tablas automáticas (SALIDASCAJA) -> Cuentan como Ingresos
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
              AND (UPPER(S1.MOTIVO) LIKE '%ENTREGA%' OR UPPER(S1.MOTIVO) LIKE '%EFECTIVO%' OR UPPER(S1.MOTIVO) LIKE '%MONEDA%')
              ORDER BY USUA ASC, S1.FECHA ASC";

    $res = $conexion_sede->query($query);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['SEDE_ORIGEN'] = $nombre_sede;
            $row['ES_MANUAL'] = false;
            $cajero = $row['USUA'];
            
            $reporte[$cajero][] = $row;
            
            $val = (float)$row['VALOR'];
            $resumen_cajeros[$cajero] = ($resumen_cajeros[$cajero] ?? 0) + $val;
            $resumen_sedes[$nombre_sede] += $val;
            
            $total_ingresos += $val;
            $gran_total += $val;

            // Sincronizar en flujo_efectivo
            if ($db_conexion) {
                $sede_db = $nombre_sede;
                $tipo_transaccion = 'INGRESO';
                $f_original = $row['FECHA'];
                $fecha_mysql = substr($f_original,0,4)."-".substr($f_original,4,2)."-".substr($f_original,6,2);
                
                $id_tercero = (int)$row['IDTERCERO'];
                $nit_tercero = $conexion_sede->real_escape_string($row['NIT']);
                $nombre_tercero = $conexion_sede->real_escape_string($cajero);
                $motivo = $conexion_sede->real_escape_string($row['MOTIVO']);
                $valor = (float)$row['VALOR'];
                $nombre_pc = $conexion_sede->real_escape_string($row['NOMBREPC']);
                $id_origen = (int)$row['IDSALIDA'];

                $check_sql = "SELECT id_transaccion FROM flujo_efectivo WHERE sede = '$sede_db' AND id_origen = $id_origen LIMIT 1";
                $check_res = $db_conexion->query($check_sql);

                if ($check_res && $check_res->num_rows == 0) {
                    $insert_sql = "INSERT INTO flujo_efectivo 
                                    (sede, tipo, fecha, id_tercero, nit_tercero, nombre_tercero, motivo, valor, nombre_pc, id_origen) 
                                   VALUES 
                                    ('$sede_db', '$tipo_transaccion', '$fecha_mysql', $id_tercero, '$nit_tercero', '$nombre_tercero', '$motivo', $valor, '$nombre_pc', $id_origen)";
                    $db_conexion->query($insert_sql);
                }
            }
        }
    }
}

// 2. Cargar los movimientos manuales guardados en la tabla flujo_efectivo dentro del rango
if ($db_conexion) {
    $query_manuales = "SELECT * FROM flujo_efectivo WHERE id_origen IS NULL AND fecha BETWEEN '$fecha_ini_input' AND '$fecha_fin_input'";
    $res_m = $db_conexion->query($query_manuales);
    if ($res_m) {
        while ($row_m = $res_m->fetch_assoc()) {
            $cajero = $row_m['nombre_tercero'] ?? 'MANUAL';
            $valor_m = (float)$row_m['valor'];
            
            $tipo_real = $row_m['tipo']; // 'INGRESO' o 'PAGO'
            
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
                'IDSALIDA' => 'MANUAL-' . $row_m['id_transaccion'],
                'ID_TRANSACCION_REAL' => $row_m['id_transaccion'],
                'NOMBREPC' => $row_m['nombre_pc'],
                'MOTIVO' => '[' . $tipo_real . '] ' . $row_m['motivo'],
                'MOTIVO_PURO' => $row_m['motivo'],
                'TIPO_PURO' => $tipo_real,
                'VALOR' => $valor_neto,
                'VALOR_REAL' => (float)$row_m['valor'],
                'NIT' => $row_m['nit_tercero'] ?? 'N/A',
                'ES_MANUAL' => true
            ];

            $reporte[$cajero][] = $row_format;
            $resumen_cajeros[$cajero] = ($resumen_cajeros[$cajero] ?? 0) + $valor_neto;
            $resumen_sedes[$row_m['sede']] += $valor_neto;
            $gran_total += $valor_neto;
        }
    }
}

ksort($reporte);
ksort($resumen_cajeros);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }
function formatFecha($f){ return substr($f,0,4)."-".substr($f,4,2)."-".substr($f,6,2); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Consolidado de Entregas y Flujo</title>
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
        .btn-modal:hover { background: #219653; }
        
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
        <div style="background: <?= strpos($mensaje_exito, '❌') !== false ? '#f8d7da' : '#d4edda' ?>; color: <?= strpos($mensaje_exito, '❌') !== false ? '#721c24' : '#155724' ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold;"><?= $mensaje_exito ?></div>
    <?php endif; ?>

    <div class="no-print">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <label>Desde: <input type="date" name="fecha_ini" value="<?= $fecha_ini_input ?>"></label>
            <label>Hasta: <input type="date" name="fecha_fin" value="<?= $fecha_fin_input ?>"></label>
            <button type="submit" style="background:#2c3e50; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Actualizar</button>
        </form>
        <div>
            <button type="button" onclick="abrirFormManual()" style="background:#27ae60; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">➕ Nuevo Movimiento</button>
            <button type="button" onclick="window.print()" style="padding:10px 15px; cursor:pointer;">🖨️ Imprimir</button>
        </div>
    </div>

    <!-- Modal Formulario Movimiento Manual (Nuevo) -->
    <div id="modalFormManual" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2c3e50;">➕ Registrar Ingreso o Gasto Manual</h3>
            <form method="POST">
                <input type="hidden" name="accion_manual" value="1">
                <div style="margin-bottom: 12px;">
                    <label>Tipo:</label>
                    <select name="tipo_manual" style="width:100%; padding:8px; margin-top:5px;">
                        <option value="INGRESO">Ingreso</option>
                        <option value="GASTO">Gasto (Pago)</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Sede:</label>
                    <select name="sede_manual" style="width:100%; padding:8px; margin-top:5px;">
                        <option value="CENTRAL">CENTRAL</option>
                        <option value="DRINKS">DRINKS</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Fecha:</label>
                    <input type="date" name="fecha_manual" value="<?= date('Y-m-d') ?>" style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Responsable / Tercero:</label>
                    <input type="text" name="tercero_manual" placeholder="Nombre" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Descripción del Motivo:</label>
                    <input type="text" name="motivo_manual" placeholder="¿De qué es?" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label>Valor ($):</label>
                    <input type="number" step="any" name="valor_manual" placeholder="0" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarFormManual()" style="background:#95a5a6; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Cancelar</button>
                    <button type="submit" style="background:#27ae60; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Formulario Movimiento Manual (Editar) -->
    <div id="modalFormEditar" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2c3e50;">✏️ Editar Movimiento Manual</h3>
            <form method="POST">
                <input type="hidden" name="accion_editar" value="1">
                <input type="hidden" name="id_transaccion" id="edit_id">
                <div style="margin-bottom: 12px;">
                    <label>Tipo:</label>
                    <select name="tipo_manual" id="edit_tipo" style="width:100%; padding:8px; margin-top:5px;">
                        <option value="INGRESO">Ingreso</option>
                        <option value="GASTO">Gasto (Pago)</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Sede:</label>
                    <select name="sede_manual" id="edit_sede" style="width:100%; padding:8px; margin-top:5px;">
                        <option value="CENTRAL">CENTRAL</option>
                        <option value="DRINKS">DRINKS</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Fecha:</label>
                    <input type="date" name="fecha_manual" id="edit_fecha" style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Responsable / Tercero:</label>
                    <input type="text" name="tercero_manual" id="edit_tercero" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Descripción del Motivo:</label>
                    <input type="text" name="motivo_manual" id="edit_motivo" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label>Valor ($):</label>
                    <input type="number" step="any" name="valor_manual" id="edit_valor" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarFormEditar()" style="background:#95a5a6; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Cancelar</button>
                    <button type="submit" style="background:#f39c12; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <h3>👥 Totalizado por Tercero / Cajero</h3>
            <table class="resumen-table">
                <?php if(empty($resumen_cajeros)): ?>
                    <tr><td colspan="2" style="text-align:center; color:#95a5a6;">No hay movimientos en este rango.</td></tr>
                <?php else: ?>
                    <?php foreach($resumen_cajeros as $nom => $valor): ?>
                    <tr>
                        <td><strong><?= strtoupper($nom) ?></strong></td>
                        <td align="right" style="color:<?= $valor >= 0 ? '#27ae60' : '#c0392b' ?>; font-weight:bold;">$ <?= money($valor) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>

        <div class="summary-card">
            <h3>🏢 Resumen Financiero</h3>
            <table class="resumen-table">
                <tr>
                    <td>Arranque de Operación (<?= date('d/m/Y', strtotime($fecha_ini_input . ' - 1 day')) ?>):</td>
                    <td align="right" style="color: <?= $arranque_operacion >= 0 ? '#27ae60' : '#c0392b' ?>; font-weight:bold;">$ <?= money($arranque_operacion) ?></td>
                </tr>
                <tr>
                    <td>(+) Total Ingresos (Rango):</td>
                    <td align="right" style="color:#27ae60; font-weight:bold;">$ <?= money($total_ingresos) ?></td>
                </tr>
                <tr>
                    <td>(-) Total Egresos / Pagos (Rango):</td>
                    <td align="right" style="color:#c0392b; font-weight:bold;">$ <?= money($total_egresos) ?></td>
                </tr>
                <tr>
                    <td>Recaudo Sede Central:</td>
                    <td align="right">$ <?= money($resumen_sedes['CENTRAL']) ?></td>
                </tr>
                <tr>
                    <td>Recaudo Sede Drinks:</td>
                    <td align="right">$ <?= money($resumen_sedes['DRINKS']) ?></td>
                </tr>
                <tr style="border-top: 2px solid #2c3e50; font-weight:bold;">
                    <td>TOTAL GENERAL:</td>
                    <td align="right" style="font-size:1.2em; color: <?= $gran_total >= 0 ? '#27ae60' : '#c0392b' ?>;">$ <?= money($gran_total) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <hr style="border:0; border-top:1px solid #eee; margin-bottom:40px;">

    <h2 style="color:#2c3e50; text-align:center;">📋 Listado de Responsables (Detalles en Modal)</h2>
    
    <?php if (empty($reporte)): ?>
        <div style="text-align:center; padding:30px; color:#95a5a6;"><h3>No hay detalles para mostrar.</h3></div>
    <?php else: ?>
        <?php foreach ($reporte as $cajero => $movs): $sub = 0; foreach ($movs as $m) { $sub += $m['VALOR']; } ?>
            <div class="cajero-card">
                <div class="cajero-header">
                    <div>
                        <span style="font-size: 1.1em;">👤 <?= strtoupper($cajero) ?></span>
                        <small style="margin-left: 15px; opacity: 0.8;">NIT: <?= $movs[0]['NIT'] ?? 'N/A' ?></small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <span style="font-size: 1.1em; font-weight:bold; color: <?= $sub >= 0 ? '#2ecc71' : '#e74c3c' ?>;">$ <?= money($sub) ?></span>
                        <button class="btn-modal" onclick="abrirModal('modal-<?= md5($cajero) ?>')">Ver Detalle (<?= count($movs) ?>)</button>
                    </div>
                </div>
            </div>

            <!-- Modal de Detalles Individual -->
            <div id="modal-<?= md5($cajero) ?>" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <span>Detalle de Movimientos: <?= strtoupper($cajero) ?></span>
                        <button class="close-btn" onclick="cerrarModal('modal-<?= md5($cajero) ?>')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <table>
                            <thead>
                                <tr>
                                    <th>Sede</th>
                                    <th>Fecha</th>
                                    <th>ID</th>
                                    <th>PC</th>
                                    <th>Motivo / Descripción</th>
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
                                        <?php if (!empty($m['ES_MANUAL'])): ?>
                                            <button class="btn-accion btn-editar" onclick="abrirEditar(
                                                '<?= $m['ID_TRANSACCION_REAL'] ?>',
                                                '<?= $m['SEDE_ORIGEN'] ?>',
                                                '<?= $m['TIPO_PURO'] === 'PAGO' ? 'GASTO' : $m['TIPO_PURO'] ?>',
                                                '<?= formatFecha($m['FECHA']) ?>',
                                                '<?= addslashes($cajero) ?>',
                                                '<?= addslashes($m['MOTIVO_PURO']) ?>',
                                                '<?= $m['VALOR_REAL'] ?>'
                                            )">✏️</button>
                                            
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar este movimiento manual?');">
                                                <input type="hidden" name="accion_eliminar" value="1">
                                                <input type="hidden" name="id_transaccion" value="<?= $m['ID_TRANSACCION_REAL'] ?>">
                                                <button type="submit" class="btn-accion btn-eliminar">🗑️</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#bdc3c7; font-size: 0.8em;" title="Sincronizado de caja automática">Automático</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="gran-total-banner">
        <small style="font-size: 0.4em; display: block; opacity: 0.8;">BALANCE TOTAL (INCLUYE ARRANQUE DÍA ANTERIOR Y RANGO)</small>
        $ <?= money($gran_total) ?>
    </div>
</div>

<script>
    function abrirModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function cerrarModal(id) {
        document.getElementById(id).style.display = 'none';
    }
    function abrirFormManual() {
        document.getElementById('modalFormManual').style.display = 'flex';
    }
    function cerrarFormManual() {
        document.getElementById('modalFormManual').style.display = 'none';
    }
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

    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay') || event.target.classList.contains('form-overlay')) {
            event.target.style.display = "none";
        }
    }
</script>

</body>
</html>