<?php
session_start();
$UsuarioSesion = $_SESSION['Usuario'] ?? '';
$NitSesion = $_SESSION['NitEmpresa'] ?? '';
$SucursalSesion = $_SESSION['NroSucursal'] ?? '';

if (empty($UsuarioSesion)) {
	header("Location: Login.php?msg=Debe iniciar sesión");
	exit;
}

// Conexiones
require 'Conexion.php'; // Conexión principal (ADM_BNMA)
require 'ConnCentral.php'; // Conexión POS (empresa001)

// ---------------------------------------------------------
// FUNCIÓN DE OPTIMIZACIÓN: OBTENER TODOS LOS PRECIOS DE COMPRA RECIENTES
// ---------------------------------------------------------
// Ejecuta una sola consulta para obtener el precio de compra promedio agrupado
// por BARCODE para todos los productos que cumplen el filtro.
function getPreciosCompra($mysqliPos, $filtroBusquedaSQL, $mysqliAdm) {
	$preciosCompraMap = [];

	// Ejecutar una sola consulta que hace JOIN desde categorias->catproductos->PRODUCTOS->DETCOMPRAS->compras
	// Esto evita construir listas IN(...) grandes y permite al optimizador usar índices.
	$sqlCompraOptimizada = "
		SELECT 
			P.BARCODE,
			ROUND(
				SUM(D.BASE + D.IVAPROD + (D.ValICUIUni * D.CANTIDAD)) / NULLIF(SUM(D.CANTIDAD),0), 0
			) AS PrecioCompra
		FROM categorias
		INNER JOIN catproductos ON categorias.CodCat = catproductos.CodCat
		INNER JOIN PRODUCTOS P ON P.BARCODE = catproductos.Sku
		INNER JOIN DETCOMPRAS D ON D.IDPRODUCTO = P.IDPRODUCTO
		INNER JOIN compras C ON C.idcompra = D.idcompra
		WHERE 1=1 $filtroBusquedaSQL
		  AND C.ESTADO = '0'
		  AND C.FECHA >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
		GROUP BY P.BARCODE
	";

	$r = $mysqliPos->query($sqlCompraOptimizada);
	if ($r) {
		while ($row = $r->fetch_assoc()) {
			$preciosCompraMap[$row['BARCODE']] = $row['PrecioCompra'];
		}
		$r->free();
	}

	return $preciosCompraMap;
}

// ---------------------------------------------------------
// EXPORTAR EXCEL
// ---------------------------------------------------------
if (isset($_GET['exportar'])) {

	header("Content-Type: application/vnd.ms-excel; charset=utf-8");
	header("Content-Disposition: attachment; filename=lista_precios.xls");
	header("Pragma: no-cache");
	header("Expires: 0");

	echo "<table border='1'>";
	echo "<meta charset='UTF-8'>";

	echo "
		<tr>
			<th>CodCat</th>
			<th>Categoría</th>
			<th>Barcode</th>
			<th>Descripción</th>
			<th>Precio Compra</th>
			<th>Precio Venta</th>
			<th>Esp1</th>
			<th>Esp2</th>
			<th>% Utilidad</th>
		</tr>
	";

    // Filtro para SQL
    $filtroSQL = "";
    $busqueda = $_GET['buscar'] ?? '';
    if (!empty($busqueda)) {
        $busqueda = $mysqli->real_escape_string($busqueda);
        $filtroSQL = " AND (
            categorias.CodCat LIKE '%$busqueda%' OR
            categorias.Nombre LIKE '%$busqueda%' OR
            catproductos.Sku LIKE '%$busqueda%'
        )";
    }
    
    // OPTIMIZACIÓN: OBTENEMOS TODOS LOS PRECIOS DE COMPRA EN UN SOLO PASO
    $preciosCompraMap = getPreciosCompra($mysqliPos, $filtroSQL, $mysqli);

    // Consulta principal para CATEGORIAS y SKUs
    $sql = "
        SELECT categorias.CodCat, categorias.Nombre AS Categoria, catproductos.Sku 
        FROM categorias
        INNER JOIN catproductos ON categorias.CodCat = catproductos.CodCat
        WHERE 1=1 $filtroSQL
        ORDER BY categorias.CodCat ASC, catproductos.Sku ASC
    ";
    $r = $mysqli->query($sql);

    // Prepared para productos (Se sigue necesitando 1 por fila, pero es rápido)
    $stmtProd = $mysqliPos->prepare("
        SELECT barcode, descripcion, PRECIOVENTA, precioespecial1, precioespecial2
        FROM productos
        WHERE barcode = ? AND estado='1'
        LIMIT 1
    ");

    while ($row = $r->fetch_assoc()) {
        $sku = $row['Sku'];

        // --- Producto ---
        if (!$stmtProd->bind_param("s", $sku) || !$stmtProd->execute()) { continue; }
        $prod = $stmtProd->get_result()->fetch_assoc();
        $stmtProd->free_result();
        if (!$prod) continue;

        // --- Precio compra (OPTIMIZADO: del mapa) ---
        $precioCompra = $preciosCompraMap[$sku] ?? 0;

        // --- % Utilidad ---
        $utilidad = ($precioCompra > 0)
            ? round((($prod['PRECIOVENTA'] - $precioCompra) / $precioCompra) * 100, 1)
            : "–";

        echo "
        <tr>
            <td>{$row['CodCat']}</td>
            <td>{$row['Categoria']}</td>
            <td>{$prod['barcode']}</td>
            <td>{$prod['descripcion']}</td>
            <td>".number_format($precioCompra, 0, ',', '.')."</td>
            <td>".number_format($prod['PRECIOVENTA'], 0, ',', '.')."</td>
            <td>".number_format($prod['precioespecial1'], 0, ',', '.')."</td>
            <td>".number_format($prod['precioespecial2'], 0, ',', '.')."</td>
            <td>$utilidad%</td>
        </tr>";
    }
    
    if (isset($stmtProd)) $stmtProd->close();
    echo "</table>";
    exit();
}

// ---------------------------------------------------------
// ACTUALIZAR PRECIOS (AJAX SEGURO) + GUARDAR LOG DE CAMBIOS
// ---------------------------------------------------------
if (isset($_POST['update_price'])) {
    // NOTA: Esta sección no se modificó, ya que su lógica era correcta y segura.

    if (empty($_POST['barcode']) || empty($_POST['campo'])) {
        http_response_code(400);
        echo "Parámetros incompletos";
        exit();
    }

    $barcode = $_POST['barcode'];
    $campo   = $_POST['campo'];
    $rawValor = str_replace(['.',' '], ['', ''], $_POST['valor']); 
    $rawValor = str_replace(',', '.', $rawValor);
    $valorNuevo = floatval($rawValor);

    $usuario = $_SESSION["datos"]["Cedula"] ?? 'DESCONOCIDO';

    $permitidos = ["PRECIOVENTA", "precioespecial1", "precioespecial2"];
    if (!in_array($campo, $permitidos)) {
        http_response_code(403);
        echo "Campo no autorizado";
        exit();
    }

    $mysqliPos->begin_transaction();

    try {
        // 1) obtener valor anterior
        $sqlOld = "SELECT $campo AS valor FROM productos WHERE barcode = ?";
        if (!($stmtOld = $mysqliPos->prepare($sqlOld))) { throw new Exception("Prepare OLD failed: " . $mysqliPos->error); }
        $stmtOld->bind_param("s", $barcode);
        if (!$stmtOld->execute()) { throw new Exception("Execute OLD failed: " . $stmtOld->error); }
        $resOld = $stmtOld->get_result()->fetch_assoc();
        $valorAnterior = isset($resOld["valor"]) ? floatval($resOld["valor"]) : 0.00;
        $stmtOld->close();

        // 2) update
        $sqlUpdate = "UPDATE productos SET $campo = ? WHERE barcode = ?";
        if (!($stmtUpdate = $mysqliPos->prepare($sqlUpdate))) { throw new Exception("Prepare UPDATE failed: " . $mysqliPos->error); }
        $stmtUpdate->bind_param("ds", $valorNuevo, $barcode);
        if (!$stmtUpdate->execute()) { throw new Exception("Execute UPDATE failed: " . $stmtUpdate->error); }
        $stmtUpdate->close();

        // 3) insert log (usa $mysqli para la tabla log_cambios_precios)
        $sqlLog = "INSERT INTO log_cambios_precios (barcode, campo_modificado, valor_anterior, valor_nuevo, usuario) VALUES (?, ?, ?, ?, ?)";
        if (!($stmtLog = $mysqli->prepare($sqlLog))) { throw new Exception("Prepare LOG failed: " . $mysqli->error); }
        $stmtLog->bind_param("ssdds", $barcode, $campo, $valorAnterior, $valorNuevo, $usuario);
        if (!$stmtLog->execute()) { throw new Exception("Execute LOG failed: " . $stmtLog->error); }
        $stmtLog->close();

        $mysqliPos->commit();
        echo "OK";
        exit();

    } catch (Exception $e) {
        $mysqliPos->rollback();
        http_response_code(500);
        echo "ERROR: " . $e->getMessage();
        exit();
    }
}

// ---------------------------------------------------------
// FILTRO Y CONSULTA PRINCIPAL PARA RENDERIZADO HTML
// ---------------------------------------------------------
$filtroSQL = "";
$busqueda = "";

if (!empty($_GET['buscar'])) {
    $busqueda = $mysqli->real_escape_string($_GET['buscar']);
    $filtroSQL = " AND (
        categorias.CodCat LIKE '%$busqueda%' OR
        categorias.Nombre LIKE '%$busqueda%' OR
        catproductos.Sku LIKE '%$busqueda%'
    )";
}

// OPTIMIZACIÓN: OBTENEMOS TODOS LOS PRECIOS DE COMPRA EN UN SOLO PASO
$preciosCompraMap = getPreciosCompra($mysqliPos, $filtroSQL, $mysqli);

$sql = "
SELECT categorias.CodCat, categorias.Nombre AS Categoria, catproductos.Sku
FROM categorias
INNER JOIN catproductos ON categorias.CodCat = catproductos.CodCat
WHERE 1=1 $filtroSQL
ORDER BY categorias.CodCat ASC, catproductos.Sku ASC
";
$result = $mysqli->query($sql);

// Prepared statement para productos (Se mantiene)
$stmtProd = $mysqliPos->prepare("
    SELECT barcode, descripcion, PRECIOVENTA, precioespecial1, precioespecial2
    FROM productos
    WHERE barcode = ? AND estado='1'
    LIMIT 1
");

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Lista de Precios</title>

<style>
    body { font-family: Arial; margin:20px; background:#f0f0f0; }
    table { width:100%; border-collapse:collapse; margin-top:15px; background:white; }
    th { background:#333; color:white; padding:8px; }
    td { padding:7px; border:1px solid #ddd; }
    tr:nth-child(even){background:#f7f7f7;}
    input.edit { width:80px; padding:4px; text-align:right; border-radius:4px; }
    .card { padding:15px; background:white; border-radius:8px; margin-bottom:20px; }
</style>

<script>
function guardarPrecio(barcode, campo, input) {
    // 1. Limpieza y validación
    // Elimina separadores de miles (puntos)
    let valor = input.value.replace(/\./g,''); 
    // Reemplaza coma decimal por punto (para floatval en PHP)
    valor = valor.replace(',', '.');

    // Validación JS (solo números positivos o cero)
    if (isNaN(parseFloat(valor)) || parseFloat(valor) < 0 || valor.trim() === '') {
        alert("Valor inválido. Solo se permiten números positivos o cero.");
        input.style.background = "#ffd6d6";
        setTimeout(()=> input.style.background="white", 800);
        return;
    }

    // 2. Solicitud AJAX
    fetch("lista_precios.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "update_price=1&barcode=" + encodeURIComponent(barcode) + "&campo=" + encodeURIComponent(campo) + "&valor=" + encodeURIComponent(valor)
    })
    .then(async (r) => {
        const text = await r.text();
        if (!r.ok || text.trim() !== "OK") {
            console.error("Error guardando:", text);
            alert("Error guardando: " + text);
            input.style.background = "#ffd6d6";
            setTimeout(()=> input.style.background="white", 800);
            return;
        }
        
        // Éxito
        input.style.background = "#d4ffd4";
        setTimeout(()=> input.style.background="white", 400);

        // NOTA: Recargar la página es la forma más sencilla de ver la % Utilidad actualizada.
        window.location.reload(); 
    })
    .catch(err => {
        console.error("Error de red:", err);
        alert("Error de red: " + err.message);
        input.style.background = "#ffd6d6";
        setTimeout(()=> input.style.background="white", 800);
    });
}
</script>

</head>
<body>

<div class="card">
    <h2>Listado de Precios</h2>

    <form method="GET">
        <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar Barcode, Categoría o Descripción…" style="padding:6px;width:250px;">
        <button type="submit">Filtrar</button>
        <a href="?exportar=1&buscar=<?= urlencode($busqueda) ?>"><button type="button">Exportar Excel</button></a>
    </form>
</div>

<table>
<tr>
    <th>CodCat</th>
    <th>Categoría</th>
    <th>Barcode</th>
    <th>Descripción</th>
    <th>Precio Compra</th>
    <th>Precio Venta</th>
    <th>Esp1</th>
    <th>Esp2</th>
    <th>% Utilidad</th>
</tr>

<?php
while ($row = $result->fetch_assoc()) {

    $sku = $row['Sku'];

    // Producto
    if (!$stmtProd->bind_param("s", $sku) || !$stmtProd->execute()) {
        continue;
    }
    $prod = $stmtProd->get_result()->fetch_assoc();
    $stmtProd->free_result();
    if (!$prod) continue;

    // Precio compra (OPTIMIZADO: del mapa)
    $precioCompra = $preciosCompraMap[$sku] ?? 0;

    // % Utilidad
    $utilidad = ($precioCompra > 0)
        ? round((($prod['PRECIOVENTA'] - $precioCompra) / $precioCompra) * 100, 1)
        : "–"; // Muestra guion si el costo es cero

    echo "
        <tr>
            <td>".htmlspecialchars($row['CodCat'])."</td>
            <td>".htmlspecialchars($row['Categoria'])."</td>
            <td>".htmlspecialchars($prod['barcode'])."</td>
            <td>".htmlspecialchars($prod['descripcion'])."</td>
            <td>".number_format($precioCompra,0,',','.')."</td>

            <td>
                <input class='edit'
                    value='".number_format($prod['PRECIOVENTA'],0,',','.')."'
                    onchange=\"guardarPrecio('".addslashes($prod['barcode'])."', 'PRECIOVENTA', this)\">
            </td>

            <td>
                <input class='edit'
                    value='".number_format($prod['precioespecial1'],0,',','.')."'
                    onchange=\"guardarPrecio('".addslashes($prod['barcode'])."', 'precioespecial1', this)\">
            </td>

            <td>
                <input class='edit'
                    value='".number_format($prod['precioespecial2'],0,',','.')."'
                    onchange=\"guardarPrecio('".addslashes($prod['barcode'])."', 'precioespecial2', this)\">
            </td>

            <td>$utilidad%</td>
        </tr>
    ";
}
?>
</table>

<?php
// Cerrar las conexiones y prepared statements
if (isset($stmtProd)) $stmtProd->close();
if (isset($result)) $result->free();
if (isset($mysqli)) $mysqli->close();
if (isset($mysqliPos)) $mysqliPos->close();
?>

</body>
</html>