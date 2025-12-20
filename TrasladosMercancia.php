<?php
/**
 * Script de Movimientos de Inventario por Categoría
 */

// Iniciar sesión
session_start();

// Incluir conexiones a bases de datos
require_once("Conexion.php");      // ADM
require_once("ConnCentral.php");   // POS

// --- CONFIGURACIÓN Y VALIDACIÓN INICIAL ---
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

// Validar sesión del usuario

if (empty($UsuarioSesion)) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

$mensaje        = "";
$categoriaSel   = $_POST['categoria'] ?? '';
$barcodeSel     = $_POST['barcode'] ?? '';
$stockActual    = null; 

// Token Anti-Doble Registro (Anti-CSRF simple)
if (!isset($_SESSION['token_mov'])) {
    $_SESSION['token_mov'] = bin2hex(random_bytes(16));
}

// --- FUNCIONES CORE ---

function Autorizacion(string $user, string $solicitud): string {
    global $mysqli;
    
    $stmt = $mysqli->prepare("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE Nit = ? AND Nro_Auto = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $user, $solicitud);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $swich = 'NO';
    if ($res && $r = $res->fetch_assoc()) {
        $swich = $r['Swich'];
    }
    $stmt->close();
    return $swich;
}

// --- AUTORIZACIÓN Y CONFIGURACIÓN ---

$AUT_AJUSTE   = Autorizacion($usuario, '9999');
$mostrarStock = ($AUT_AJUSTE === 'SI'); 


/* ============================================
 * CARGA DE DATOS DE LA DB
 * ============================================ */

// 1. CATEGORÍAS (ADM DB)
$categorias = [];
$res = $mysqli->query("
    SELECT CodCat, Nombre
    FROM categorias
    WHERE Estado='1'
    ORDER BY CodCat
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $categorias[] = $r;
    }
    $res->free();
}


// 2. PRODUCTOS POR CATEGORÍA (ADM + POS DB)
$productos = [];

if ($categoriaSel != '') {
    // 2a. Obtener SKUs (barcodes) desde ADM
    $stmt = $mysqli->prepare("
        SELECT Sku
        FROM catproductos
        WHERE CodCat=? AND Estado='1'
    ");
    $stmt->bind_param("s", $categoriaSel);
    $stmt->execute();
    $res = $stmt->get_result();

    $skus = [];
    while ($r = $res->fetch_assoc()) {
        $skus[] = $r['Sku'];
    }
    $stmt->close();

    // 2b. Obtener detalles y stock desde POS
    if (!empty($skus)) {
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $bindTypes = str_repeat('s', count($skus));
        
        $stmt = $mysqliPos->prepare("
            SELECT p.barcode, p.descripcion, IFNULL(i.cantidad,0) as stock
            FROM productos p
            LEFT JOIN inventario i
              ON i.idproducto = p.idproducto
             AND i.idalmacen = ?
            WHERE p.barcode IN ($placeholders)
              AND p.estado = 1
            ORDER BY p.barcode
        ");
        
        $bindParams = array_merge([$stmt, "i".$bindTypes, $idalmacen], $skus);
        call_user_func_array('mysqli_stmt_bind_param', $bindParams);
        
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $productos[] = $r;
        }
        $stmt->close();
    }
}

// 3. STOCK ACTUAL DEL PRODUCTO SELECCIONADO (POS DB)
if ($barcodeSel != '') {
    $stmt = $mysqliPos->prepare("
        SELECT IFNULL(i.cantidad,0)
        FROM inventario i
        INNER JOIN productos p ON p.idproducto = i.idproducto
        WHERE p.barcode = ? AND i.idalmacen = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $barcodeSel, $idalmacen);
    $stmt->execute();
    $stmt->bind_result($stockActual);
    $stmt->fetch();
    $stmt->close();
}


// --- LÓGICA DE MOVIMIENTO (omitiendo por brevedad, es la misma) ---
if (isset($_POST['registrar'])) {

    if (($_POST['token'] ?? '') !== $_SESSION['token_mov']) {
        $mensaje = "❌ Registro duplicado detectado (token inválido)";
        goto FIN_REGISTRO;
    }
    $_SESSION['token_mov'] = bin2hex(random_bytes(16));

    $tipo = $_POST['tipo'] ?? '';
    $cant = floatval($_POST['cant'] ?? 0); 
    $ref  = trim($_POST['referencia'] ?? '');

    if ($barcodeSel == '' || $cant <= 0 || !in_array($tipo, ['REM_ENT', 'REM_SAL'])) {
        $mensaje = "❌ Datos incompletos o inválidos (producto, cantidad o tipo)";
        goto FIN_REGISTRO;
    }

    if (
        stripos($ref, 'Ajuste por conteo distribuido') !== false
        && $AUT_AJUSTE !== 'SI'
    ) {
        $mensaje = "❌ No tiene autorización (código 9999) para realizar este tipo de ajuste.";
        goto FIN_REGISTRO;
    }

    $antes = $stockActual ?? 0; 
    $despues = ($tipo === 'REM_ENT')
        ? $antes + $cant
        : $antes - $cant;

    // 6. Registro en ADM (Histórico)
    $stmt = $mysqli->prepare("
        INSERT INTO inventario_movimientos
        (barcode, tipo, cant, stock_antes, stock_despues, Nit, NroSucursal, usuario, referencia)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "ssdddssss",
        $barcodeSel, $tipo, $cant, $antes, $despues,
        $nit, $sucursal, $usuario, $ref
    );
    $stmt->execute();
    $stmt->close();

    // 7. Actualización en POS (Inventario Actual)
    $stmt = $mysqliPos->prepare("
        INSERT INTO inventario (idproducto, idalmacen, cantidad)
        SELECT idproducto, ?, ?
        FROM productos
        WHERE barcode = ?
        ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)
    ");
    $stmt->bind_param("ids", $idalmacen, $despues, $barcodeSel);
    $stmt->execute();
    $stmt->close();

    $stockActual = $despues; 
    $mensaje = "✅ Movimiento registrado correctamente. Stock final: " . number_format($stockActual, 3);

    FIN_REGISTRO:
}

/* ============================================
 * MOVIMIENTOS DEL DÍA (ADM DB + JOIN a POS DB)
 * ============================================ */
$movimientos = [];

// Paso 1: Obtener movimientos de ADM
$sql_movimientos = "
    SELECT fecha, barcode, tipo, cant, stock_antes, stock_despues, usuario, referencia
    FROM inventario_movimientos
    WHERE DATE(fecha) = CURDATE()
      AND Nit = ?
      AND NroSucursal = ?
    ORDER BY fecha DESC
";

$stmt = $mysqli->prepare($sql_movimientos);
$stmt->bind_param("ss", $nit, $sucursal);
$stmt->execute();
$res = $stmt->get_result();

$movimientos_raw = [];
$barcodes_hoy = [];
while ($r = $res->fetch_assoc()) {
    $movimientos_raw[] = $r;
    $barcodes_hoy[] = $r['barcode'];
}
$stmt->close();

// Paso 2: Obtener la descripción de los productos únicos de POS
$descripciones = [];
if (!empty($barcodes_hoy)) {
    $barcodes_unicos = array_unique($barcodes_hoy);
    $placeholders = implode(',', array_fill(0, count($barcodes_unicos), '?'));
    $bindTypes = str_repeat('s', count($barcodes_unicos));

    $sql_descripciones = "
        SELECT barcode, descripcion
        FROM productos
        WHERE barcode IN ($placeholders)
    ";

    $stmt_pos = $mysqliPos->prepare($sql_descripciones);
    $bindParams = array_merge([$stmt_pos, $bindTypes], $barcodes_unicos);
    call_user_func_array('mysqli_stmt_bind_param', $bindParams);
    $stmt_pos->execute();
    $res_pos = $stmt_pos->get_result();

    while ($r_pos = $res_pos->fetch_assoc()) {
        $descripciones[$r_pos['barcode']] = $r_pos['descripcion'];
    }
    $stmt_pos->close();
}

// Paso 3: Combinar y filtrar resultados
foreach ($movimientos_raw as $m) {
    if (
        stripos($m['referencia'], 'Ajuste por conteo distribuido') !== false
        && $AUT_AJUSTE !== 'SI'
    ) {
        continue;
    }
    
    $m['descripcion'] = $descripciones[$m['barcode']] ?? 'Producto Desconocido';
    $movimientos[] = $m;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Movimientos por Categoría</title>
<style>
/* Estilos CSS (Sin cambios significativos para la vista web) */
:root {
    --color-primary: #007bff;
    --color-success: #198754;
    --color-danger: #dc3545;
    --color-warning: #ffc107;
    --color-bg: #eef2f7;
    --color-card-bg: #fff;
    --color-border: #e4e8f0;
    --color-header: #343a40;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--color-bg);
    padding: 20px;
}

.card {
    max-width: 1100px;
    margin: 25px auto;
    background: var(--color-card-bg);
    padding: 25px;
    border-radius: 14px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

h3 {
    color: var(--color-header);
    border-bottom: 2px solid var(--color-border);
    padding-bottom: 10px;
    margin-top: 25px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--color-header);
    margin-bottom: 5px;
}

select, input[type="text"], input[type="number"] {
    width: 100%;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    box-sizing: border-box;
    font-size: 14px;
}

.stock {
    background: #e9f2ff;
    text-align: center;
    padding: 15px;
    border-radius: 10px;
    border: 1px solid var(--color-primary);
}

.stock b {
    font-size: 24px;
    display: block;
    margin-top: 5px;
    color: var(--color-primary);
}

button.btn-registro { 
    margin-top: 12px;
    padding: 12px 15px;
    border: none;
    border-radius: 10px;
    background: var(--color-success);
    color: #fff;
    width: 100%;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: background-color 0.3s;
}

.btn-imprimir {
    padding: 8px 15px;
    margin-left: 10px;
    border: 1px solid #6c757d;
    border-radius: 5px;
    background: #6c757d; 
    color: white;
    font-size: 14px;
    cursor: pointer;
    transition: background-color 0.3s;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-size: 14px;
    border-radius: 8px;
    overflow: hidden;
}

th, td {
    padding: 10px;
    border-bottom: 1px solid var(--color-border);
    text-align: left;
}

th {
    background: #f1f4f9;
    color: var(--color-header);
    font-weight: 700;
}

.ent {
    color: var(--color-success);
    font-weight: bold;
}

.sal {
    color: var(--color-danger);
    font-weight: bold;
}

td:nth-child(5), td:nth-child(6), td:nth-child(7) {
    text-align: right;
}

td:nth-child(4) {
    text-align: center;
}

.col-producto {
    width: 25%; 
}

@media print {
    body {
        background: none;
    }
    .card, form, .grid, .stock, h3:first-of-type, .msg, .btn-imprimir {
        display: none;
    }
    /* Estilos de impresión para la tabla temporal, si la usáramos */
    #print-area {
        display: block !important;
        width: 100%;
        margin: 0;
        padding: 0;
        font-size: 10pt;
    }
    #print-area table {
        border-collapse: collapse;
        width: 100%;
    }
    #print-area th, #print-area td {
        border: 1px solid black;
        padding: 5px;
    }
    #print-area th {
        background: #ccc;
    }
}
</style>

<script>
    // Variables globales para la impresión (Obtenidas de PHP)
    const printData = {
        nit: '<?= htmlspecialchars($datos_impresion['nit']) ?>',
        sucursal: '<?= htmlspecialchars($datos_impresion['sucursal']) ?>',
        usuario: '<?= htmlspecialchars($datos_impresion['usuario']) ?>'
    };

    function validarMovimiento(event) {
        const form = event.currentTarget;
        const tipoSelect = form.querySelector('[name="tipo"]');
        const cantInput = form.querySelector('[name="cant"]');
        const stockDisplay = document.querySelector('.stock b').innerText;
        
        const stockActual = parseFloat(stockDisplay.replace(/[^\d.-]/g, ''));
        const cantidad = parseFloat(cantInput.value);
        const tipo = tipoSelect.value;
        
        if (tipo === 'REM_SAL' && !isNaN(stockActual) && !isNaN(cantidad) && stockActual < cantidad) {
            if (!confirm(`⚠️ ADVERTENCIA: La cantidad de salida (${cantidad}) es mayor al stock actual (${stockActual.toFixed(3)}).\n\n¿Desea continuar con el registro?`)) {
                event.preventDefault(); 
                return false;
            }
        }
        return true;
    }

    /**
     * Crea una tabla temporal en una nueva ventana con solo los campos
     * "Código y Producto", "Tipo" y "Cantidad Movida" e inicia la impresión.
     * Se ajustan los anchos para ocupar el 100%.
     */
    function imprimirHistorial() {
        const tablaOriginal = document.getElementById('tabla-movimientos');
        if (!tablaOriginal) return;

        const horaImpresion = new Date().toLocaleString('es-ES', { 
            year: 'numeric', month: 'numeric', day: 'numeric', 
            hour: '2-digit', minute: '2-digit', second: '2-digit' 
        });

        const encabezadoContexto = `
            <div style="margin-bottom: 20px;">
                <h2 style="margin-top: 0; text-align: center; font-size: 14pt;">REPORTE MOVIMIENTOS DEL DÍA</h2>
                <p style="font-size: 10pt; margin: 2px 0;">
                    <strong>NIT:</strong> ${printData.nit} | 
                    <strong>Sucursal:</strong> ${printData.sucursal} | 
                    <strong>Usuario:</strong> ${printData.usuario}
                </p>
                <p style="font-size: 10pt; margin: 2px 0;">
                    <strong>Fecha/Hora Impresión:</strong> ${horaImpresion}
                </p>
            </div>
        `;

        // -----------------------------------------------------------
        // Ajuste de anchos para ocupar el 100%
        // Columna 1 (Producto): 60%
        // Columna 2 (Tipo): 15%
        // Columna 3 (Cantidad): 25% (Alineación derecha)
        // -----------------------------------------------------------
        let contenidoTabla = `
            <table border="1" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 60%;">Código y Producto</th>
                        <th style="width: 15%; text-align: center;">Tipo</th>
                        <th style="width: 25%; text-align: right;">Cantidad Movida</th>
                    </tr>
                </thead>
                <tbody>
        `;

        tablaOriginal.querySelectorAll('tbody tr').forEach(fila => {
            const celdas = fila.querySelectorAll('td');
            
            const producto = celdas[1] ? celdas[1].innerHTML : '';
            const tipo = celdas[2] ? celdas[2].innerText : '';
            const cantidad = celdas[4] ? celdas[4].innerText : '';

            contenidoTabla += `
                <tr>
                    <td style="width: 60%;">${producto}</td>
                    <td style="width: 15%; text-align: center;">${tipo}</td>
                    <td style="width: 25%; text-align: right;">${cantidad}</td>
                </tr>
            `;
        });

        contenidoTabla += `
                </tbody>
            </table>
        `;
        
        // Abrir una nueva ventana y escribir el contenido
        const ventanaImpresion = window.open('', '_blank');
        ventanaImpresion.document.write(`
            <html>
                <head>
                    <title>Reporte de Movimientos</title>
                    <style>
                        /* ------------------------------------------- */
                        /* AJUSTE PARA IMPRESIÓN MÁS OSCURA           */
                        /* ------------------------------------------- */
                        body { 
                            font-family: Arial, sans-serif; 
                            padding: 10px; 
                            margin: 0; 
                            color: #000; /* Texto principal en negro puro */
                        }
                        
                        table { 
                            width: 100%; 
                            border-collapse: collapse; 
                            margin-top: 10px; 
                        }
                        th, td { 
                            border: 1px solid #000; /* Bordes en negro puro */
                            padding: 8px; 
                            font-size: 10pt; 
                            box-sizing: border-box;
                            color: #000; /* Asegurar que el texto de las celdas sea negro */
                        }
                        th { 
                            background-color: #ccc; /* Fondo de encabezado más oscuro */
                            color: #000; /* Texto del encabezado en negro puro */
                        }
                        /* Asegurar que todos los elementos de texto clave sean oscuros */
                        h2, p, strong {
                            color: #000 !important;
                        }
                        small { 
                            display: block; 
                            font-size: 80%; 
                            color: #333; /* Dejar las notas pequeñas en gris oscuro para contraste */
                        }
                    </style>
                </head>
                <body>
                    ${encabezadoContexto}
                    ${contenidoTabla}
                </body>
            </html>
        `);
        ventanaImpresion.document.close();
        // Esperar un momento antes de imprimir para asegurar que el contenido se cargue (opcional, pero útil)
        setTimeout(() => {
             ventanaImpresion.print();
             ventanaImpresion.close();
        }, 300);
    }
</script>
</head>
<body>

<div class="card">
<h3>Movimientos de Inventario</h3>

<?php 
if ($mensaje) {
    $clase_mensaje = strpos($mensaje, '✅') !== false ? 'success' : 'error';
    echo "<div class=\"msg $clase_mensaje\">$mensaje</div>";
}
?>

<form method="POST" onsubmit="return validarMovimiento(event);">
<input type="hidden" name="token" value="<?= $_SESSION['token_mov'] ?>">

<div class="grid">
    <div>
        <label for="categoria">Categoría</label>
        <select name="categoria" id="categoria" onchange="this.form.submit()">
            <option value="">-- Seleccione Categoría --</option>
            <?php foreach($categorias as $c): ?>
            <option value="<?= htmlspecialchars($c['CodCat']) ?>" <?= $categoriaSel==$c['CodCat']?'selected':'' ?>>
                <?= htmlspecialchars($c['Nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="barcode">Producto (Barcode)</label>
        <select name="barcode" id="barcode" onchange="this.form.submit()">
            <option value="">-- Seleccione Producto --</option>
            <?php foreach($productos as $p): ?>
            <option value="<?= htmlspecialchars($p['barcode']) ?>" <?= $barcodeSel==$p['barcode']?'selected':'' ?>>
                <?= htmlspecialchars($p['barcode']) ?> - <?= htmlspecialchars($p['descripcion']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="stock">
        Stock actual<br>
        <b><?= $mostrarStock ? number_format($stockActual ?? 0, 3, '.', ',') : '*******' ?></b>
    </div>

    <div>
        <label for="tipo">Tipo de Movimiento</label>
        <select name="tipo" id="tipo">
            <option value="REM_ENT">➕ Entrada (Suma)</option>
            <option value="REM_SAL">➖ Salida (Resta)</option>
        </select>
    </div>

    <div>
        <label for="cant">Cantidad</label>
        <input type="number" step="0.001" name="cant" id="cant" min="0.001" required placeholder="Ej: 1.500">
    </div>

    <div>
        <label for="referencia">Referencia / Observación</label>
        <input type="text" name="referencia" id="referencia" placeholder="Motivo del movimiento" maxlength="255">
    </div>
    
    <div style="grid-column:1/-1">
        <button name="registrar" class="btn-registro">Registrar Movimiento</button>
    </div>

</div>
</form>

<div style="display: flex; justify-content: space-between; align-items: center;">
    <h3>Historial de Movimientos del Día</h3>
    <button onclick="imprimirHistorial()" class="btn-imprimir">🖨️ Imprimir Historial (Producto, Tipo, Cantidad)</button>
</div>

<table id="tabla-movimientos"> 
    <thead>
        <tr>
            <th>Fecha</th>
            <th class="col-producto">Código y Producto</th> 
            <th>Tipo</th>
            <th>Stock Antes</th>
            <th>Cantidad Movida</th>
            <th>Stock Después</th>
            <th>Usuario</th>
            <th>Referencia</th>
        </tr>
    </thead>
    <tbody>
        <?php if(empty($movimientos)): ?>
        <tr><td colspan="8" style="text-align:center;">No hay movimientos registrados hoy para esta sucursal.</td></tr>
        <?php endif; ?>

        <?php foreach($movimientos as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['fecha']) ?></td>
            <td class="col-producto">
                <strong><?= htmlspecialchars($m['barcode']) ?></strong><br>
                <small><?= htmlspecialchars($m['descripcion']) ?></small>
            </td>
            <td class="<?= $m['tipo']=='REM_ENT'?'ent':'sal' ?>">
                <?= $m['tipo']=='REM_ENT'?'Entrada':'Salida' ?>
            </td>
            <td><?= number_format($m['stock_antes'], 3, '.', ',') ?></td>
            <td><?= number_format($m['cant'], 3, '.', ',') ?></td>
            <td><?= number_format($m['stock_despues'], 3, '.', ',') ?></td>
            <td><?= htmlspecialchars($m['usuario']) ?></td>
            <td><?= htmlspecialchars($m['referencia']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>
</body>
</html>