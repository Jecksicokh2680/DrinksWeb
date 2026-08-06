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
    PROCESAR ACCIONES MANUALES Y DE ARRANQUE
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_conexion && $db_conexion instanceof mysqli) {
    
    // 0. GUARDAR O ACTUALIZAR ARRANQUE FÍSICO DIARIO
    if (isset($_POST['accion_guardar_arranque'])) {
        $fecha_arranque = $db_conexion->real_escape_string($_POST['fecha_arranque']);
        $valor_arranque = (float)$_POST['valor_arranque'];
        $sede_arranque = $db_conexion->real_escape_string($_POST['sede_arranque']);

        // Verificar si ya existe un arranque para esa fecha y sede
        $check_arr = "SELECT id_transaccion FROM flujo_efectivo WHERE fecha = '$fecha_arranque' AND sede = '$sede_arranque' AND motivo = '[ARRANQUE DE CAJA]' LIMIT 1";
        $res_check = $db_conexion->query($check_arr);

        if ($res_check && $res_check->num_rows > 0) {
            $row_ex = $res_check->fetch_assoc();
            $id_t = $row_ex['id_transaccion'];
            $up_arr = "UPDATE flujo_efectivo SET valor = $valor_arranque WHERE id_transaccion = $id_t";
            $db_conexion->query($up_arr);
            $mensaje_exito = "¡Arranque del día $fecha_arranque actualizado con éxito!";
        } else {
            $ins_arr = "INSERT INTO flujo_efectivo (sede, tipo, fecha, nombre_tercero, motivo, valor, nombre_pc, id_origen) 
                        VALUES ('$sede_arranque', 'INGRESO', '$fecha_arranque', 'SISTEMA', '[ARRANQUE DE CAJA]', $valor_arranque, 'MANUAL_WEB', NULL)";
            $db_conexion->query($ins_arr);
            $mensaje_exito = "¡Arranque del día $fecha_arranque registrado con éxito como base física!";
        }
    }

    // 1. PURGAR / BORRAR REGISTROS ANTERIORES A UNA FECHA
    if (isset($_POST['accion_purgar'])) {
        $fecha_limite = $db_conexion->real_escape_string($_POST['fecha_limite']);
        if (!empty($fecha_limite)) {
            $pur_sql = "DELETE FROM flujo_efectivo WHERE fecha < '$fecha_limite'";
            if ($db_conexion->query($pur_sql)) {
                $registros_borrados = $db_conexion->affected_rows;
                $mensaje_exito = "¡Se han eliminado $registros_borrados registros anteriores a $fecha_limite con éxito!";
            } else {
                $mensaje_exito = "❌ Error al purgar la tabla: " . $db_conexion->error;
            }
        } else {
            $mensaje_exito = "❌ Error: Seleccione una fecha límite válida.";
        }
    }

    // 2. ELIMINAR MOVIMIENTO MANUAL
    if (isset($_POST['accion_eliminar'])) {
        $id_transaccion = (int)$_POST['id_transaccion'];
        $del_sql = "DELETE FROM flujo_efectivo WHERE id_transaccion = $id_transaccion AND id_origen IS NULL";
        if ($db_conexion->query($del_sql)) {
            $mensaje_exito = "¡Movimiento manual eliminado con éxito!";
        } else {
            $mensaje_exito = "❌ Error al eliminar: " . $db_conexion->error;
        }
    }

    // 3. EDITAR MOVIMIENTO MANUAL
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

    // 4. NUEVO MOVIMIENTO MANUAL
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
    LEER EL ARRANQUE DESDE EL REGISTRO FÍSICO DE LA BASE DE DATOS
============================================================ */
$arranque_operacion = 0;
if ($db_conexion) {
    $fecha_ayer = date('Y-m-d', strtotime($fecha_ini_input . ' - 1 day'));

    // Buscamos si existe un registro físico con el motivo [ARRANQUE DE CAJA] para el día anterior
    $sql_arranque = "SELECT SUM(CASE WHEN tipo = 'INGRESO' THEN valor ELSE -valor END) AS total_arranque 
                     FROM flujo_efectivo 
                     WHERE fecha = '$fecha_ayer' AND motivo = '[ARRANQUE DE CAJA]'";
                     
    $res_arranque = $db_conexion->query($sql_arranque);
    if ($res_arranque && $row_arr = $res_arranque->fetch_assoc()) {
        $arranque_operacion = (float)($row_arr['total_arranque'] ?? 0);
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
              ORDER BY S1.VALOR DESC";

    $res = $conexion_sede->query($query);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['SEDE_ORIGEN'] = $nombre_sede;
            $row['ES_MANUAL'] = false;
            $cajero = trim($row['USUA']);
            
            if(empty($cajero) || stripos($cajero, 'FACT') !== false || stripos($cajero, 'INICIO') !== false) {
                continue;
            }
            
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
    $query_manuales = "SELECT * FROM flujo_efectivo WHERE id_origen IS NULL AND fecha BETWEEN '$fecha_ini_input' AND '$fecha_fin_input' order by valor desc";
    $res_m = $db_conexion->query($query_manuales);
    if ($res_m) {
        while ($row_m = $res_m->fetch_assoc()) {
            // Omitir los registros de arranque de la lista de cajeros común para que no aparezcan como empleados o terceros normales
            if ($row_m['motivo'] === '[ARRANQUE DE CAJA]') {
                continue; 
            }

            $cajero = trim($row_m['nombre_tercero'] ?? 'MANUAL');
            if(empty($cajero)) $cajero = 'MANUAL';
            
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
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" onclick="fijarTotalComoArranque('<?= $gran_total ?>', '<?= $fecha_ini_input ?>')" style="background:#e67e22; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer; font-weight:bold;">⚡ Guardar Total General como Arranque</button>
            <button type="button" onclick="abrirFormArranque()" style="background:#2980b9; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">⚙️ Fijar Arranque Diario</button>
            <button type="button" onclick="abrirFormManual()" style="background:#27ae60; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">➕ Nuevo Movimiento</button>
            <button type="button" onclick="abrirFormPurgar()" style="background:#c0392b; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">🗑️ Limpiar Historial</button>
            <button type="button" onclick="window.print()" style="padding:10px 15px; cursor:pointer; border: 1px solid #ccc; border-radius:5px; background:#fff;">🖨️ Imprimir</button>
        </div>
    </div>

    <!-- Modal Fijar Arranque Físico -->
    <div id="modalFormArranque" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#2980b9;">⚙️ Guardar Arranque Físico Diario</h3>
            <p style="font-size: 0.85em; color: #555;">Esto creará o actualizará un registro fijo de arranque para la fecha seleccionada en la base de datos.</p>
            <form method="POST">
                <input type="hidden" name="accion_guardar_arranque" value="1">
                <div style="margin-bottom: 12px;">
                    <label>Fecha a la que corresponde el Arranque:</label>
                    <input type="date" name="fecha_arranque" value="<?= $fecha_ini_input ?>" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label>Sede:</label>
                    <select name="sede_arranque" style="width:100%; padding:8px; margin-top:5px;">
                        <option value="CENTRAL">CENTRAL</option>
                        <option value="DRINKS">DRINKS</option>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label>Valor del Arranque ($):</label>
                    <input type="number" step="any" name="valor_arranque" placeholder="0" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarFormArranque()" style="background:#95a5a6; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Cancelar</button>
                    <button type="submit" style="background:#2980b9; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Guardar Arranque</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Formulario Purgar -->
    <div id="modalFormPurgar" class="form-overlay">
        <div class="form-box">
            <h3 style="margin-top:0; color:#c0392b;">🗑️ Purgar Registros Antiguos</h3>
            <p style="font-size: 0.9em; color: #555;">Esto eliminará permanentemente todos los registros de la tabla <code>flujo_efectivo</code> con fecha anterior a la seleccionada.</p>
            <form method="POST" onsubmit="return confirm('¿Estás COMPLETAMENTE SEGURO de eliminar los registros anteriores a esta fecha?');">
                <input type="hidden" name="accion_purgar" value="1">
                <div style="margin-bottom: 15px;">
                    <label>Borrar todo lo anterior a:</label>
                    <input type="date" name="fecha_limite" value="2026-07-31" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarFormPurgar()" style="background:#95a5a6; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Cancelar</button>
                    <button type="submit" style="background:#c0392b; color:white; border:none; padding:10px 15px; border-radius:5px; cursor:pointer;">Eliminar</button>
                </div>
            </form>
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
                    <input type="date" name="fecha_manual" value="<?= $fecha_ini_input ?>" style="width:100%; padding:8px; margin-top:5px;">
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
            <h3>👥 Totalizado por Cajeros (Automáticos)</h3>
            <table class="resumen-table">
                <?php 
                $cajeros_auto_totales = [];
                foreach($reporte as $nom => $movs) {
                    $suma_auto_pers = 0;
                    $tiene_auto = false;
                    foreach($movs as $m) {
                        if(empty($m['ES_MANUAL'])) {
                            $suma_auto_pers += $m['VALOR'];
                            $tiene_auto = true;
                        }
                    }
                    if($tiene_auto) {
                        $cajeros_auto_totales[$nom] = $suma_auto_pers;
                    }
                }
                
                if(empty($cajeros_auto_totales)): ?>
                    <tr><td colspan="2" style="text-align:center; color:#95a5a6;">No hay cajeros automáticos en este rango.</td></tr>
                <?php else: ?>
                    <?php foreach($cajeros_auto_totales as $nom => $valor): ?>
                    <tr>
                        <td><strong><?= strtoupper($nom) ?></strong></td>
                        <td align="right" style="color:<?= $valor >= 0 ? '#27ae60' : '#c0392b' ?>; font-weight:bold;">$ <?= money($valor) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>

            <h3 style="margin-top: 25px; border-bottom: 2px solid #e67e22;">✏️ Movimientos y Facturas Manuales</h3>
            <table class="resumen-table">
                <?php 
                $cajeros_man_totales = [];
                foreach($reporte as $nom => $movs) {
                    $suma_man_pers = 0;
                    $tiene_man = false;
                    foreach($movs as $m) {
                        if(!empty($m['ES_MANUAL'])) {
                            $suma_man_pers += $m['VALOR'];
                            $tiene_man = true;
                        }
                    }
                    if($tiene_man) {
                        $cajeros_man_totales[$nom] = $suma_man_pers;
                    }
                }
                
                if(empty($cajeros_man_totales)): ?>
                    <tr><td colspan="2" style="text-align:center; color:#95a5a6;">No hay registros manuales en este rango.</td></tr>
                <?php else: ?>
                    <?php foreach($cajeros_man_totales as $nom => $valor): ?>
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
        <?php foreach ($reporte as $cajero => $movs): 
            $sub = 0; foreach ($movs as $m) { $sub += $m['VALOR']; } 
            
            $movs_auto = [];
            $movs_man = [];
            $sub_auto = 0;
            $sub_man = 0;

            foreach($movs as $m) {
                if(empty($m['ES_MANUAL'])) {
                    $movs_auto[] = $m;
                    $sub_auto += $m['VALOR'];
                } else {
                    $movs_man[] = $m;
                    $sub_man += $m['VALOR'];
                }
            }
        ?>
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
                        
                        <!-- SECCIÓN 1: FLUJOS AUTOMÁTICOS -->
                        <h3 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px; margin-top: 0;">⚡ Flujos Automáticos</h3>
                        <?php if(empty($movs_auto)): ?>
                            <p style="color: #95a5a6; font-size: 0.9em;">No registra movimientos automáticos en este rango.</p>
                        <?php else: ?>
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
                                    <?php foreach ($movs_auto as $m): ?>
                                    <tr>
                                        <td><span class="badge-sede badge-<?= $m['SEDE_ORIGEN'] ?>"><?= $m['SEDE_ORIGEN'] ?></span></td>
                                        <td><?= formatFecha($m['FECHA']) ?></td>
                                        <td>#<?= $m['IDSALIDA'] ?></td>
                                        <td><small><?= $m['NOMBREPC'] ?></small></td>
                                        <td><?= $m['MOTIVO'] ?></td>
                                        <td align="right" style="color: #27ae60"><strong>$ <?= money($m['VALOR']) ?></strong></td>
                                        <td align="center" class="acciones-col">
                                            <span style="color:#bdc3c7; font-size: 0.8em;" title="Sincronizado de caja automática">Automático</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="text-align: right; margin: 10px 0 25px 0; font-weight: bold; color: #2c3e50;">
                                Subtotal Automáticos: <span style="color: #27ae60;">$ <?= money($sub_auto) ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- SECCIÓN 2: FLUJOS MANUALES -->
                        <h3 style="color: #2c3e50; border-bottom: 2px solid #e67e22; padding-bottom: 5px; margin-top: 25px;">✏️ Flujos Manuales</h3>
                        <?php if(empty($movs_man)): ?>
                            <p style="color: #95a5a6; font-size: 0.9em;">No registra movimientos manuales en este rango.</p>
                        <?php else: ?>
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
                                    <?php foreach ($movs_man as $m): ?>
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
                                            
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar este movimiento manual?');">
                                                <input type="hidden" name="accion_eliminar" value="1">
                                                <input type="hidden" name="id_transaccion" value="<?= $m['ID_TRANSACCION_REAL'] ?>">
                                                <button type="submit" class="btn-accion btn-eliminar">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="text-align: right; margin: 10px 0 10px 0; font-weight: bold; color: #2c3e50;">
                                Subtotal Manuales: <span style="color: <?= $sub_man >= 0 ? '#27ae60' : '#c0392b' ?>;">$ <?= money($sub_man) ?></span>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="gran-total-banner">
        <small style="font-size: 0.4em; display: block; opacity: 0.8;">BALANCE TOTAL (INCLUYE ARRANQUE FÍSICO DÍA ANTERIOR Y RANGO)</small>
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
    function abrirFormArranque() {
        document.getElementById('modalFormArranque').style.display = 'flex';
    }
    function cerrarFormArranque() {
        document.getElementById('modalFormArranque').style.display = 'none';
    }
    function abrirFormPurgar() {
        document.getElementById('modalFormPurgar').style.display = 'flex';
    }
    function cerrarFormPurgar() {
        document.getElementById('modalFormPurgar').style.display = 'none';
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

    // Inyecta dinámicamente el Gran Total y la fecha del campo "Desde" al abrir el modal de arranque
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