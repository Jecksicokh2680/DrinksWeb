<?php
date_default_timezone_set('America/Bogota');

require_once 'ConnCentral.php'; // $mysqliCentral
require_once 'ConnDrinks.php';  // $mysqliDrinks
require_once 'Conexion.php';    // $mysqli

$dbRel     = $mysqli;
$dbCentral = $mysqliCentral ?? null;
$dbDrinks  = $mysqliDrinks ?? null;

$action = $_POST['action'] ?? null;

/* --- LOGICA PRECIO PROMEDIO DE COMPRA (Últimas 3 compras, acotada a SKUs filtrados) --- */
function precioPromCompra(?mysqli $db, array $barcodes = []): array
{
    if (!$db || $db->connect_errno || empty($barcodes)) {
        return [];
    }

    $limpios = array_values(array_unique(array_filter(array_map('trim', $barcodes), fn($b) => $b !== '')));
    if (empty($limpios)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($limpios), '?'));
    $types = str_repeat('s', count($limpios));

    $sql = "SELECT P.Barcode, D.CANTIDAD, D.VALOR, D.descuento, D.porciva, D.ValICUIUni, C.idcompra, C.FECHA
            FROM compras C
            JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
            JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
            WHERE C.ESTADO = '0' AND P.Barcode IN ({$placeholders})
            ORDER BY P.Barcode, C.FECHA DESC, C.idcompra DESC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$limpios);
    $stmt->execute();
    $r = $stmt->get_result();

    $comprasPorProd = [];
    while ($r && $row = $r->fetch_assoc()) {
        $bc = (string)$row['Barcode'];
        if (!isset($comprasPorProd[$bc])) {
            $comprasPorProd[$bc] = [];
        }
        if (!isset($comprasPorProd[$bc][$row['idcompra']]) && count($comprasPorProd[$bc]) < 3) {
            $comprasPorProd[$bc][$row['idcompra']] = [];
        }
        if (isset($comprasPorProd[$bc][$row['idcompra']])) {
            $comprasPorProd[$bc][$row['idcompra']][] = $row;
        }
    }
    $stmt->close();

    $out = [];
    foreach ($comprasPorProd as $bc => $compras) {
        $acumuladoCostoPonderado = 0.0;
        $cantidadTotal = 0.0;

        foreach ($compras as $items) {
            foreach ($items as $row) {
                $cant = (double)$row['CANTIDAD'];
                if ($cant <= 0) {
                    continue;
                }
                $net = ((double)$row['VALOR'] - ((double)$row['descuento'] / $cant));
                $costoBruto = $net + ($net * (double)$row['porciva'] / 100.0) + (double)$row['ValICUIUni'];
                $acumuladoCostoPonderado += ($costoBruto * $cant);
                $cantidadTotal += $cant;
            }
        }

        $out[$bc] = ($cantidadTotal > 0) ? ($acumuladoCostoPonderado / $cantidadTotal) : 0.0;
    }

    return $out;
}

function consultarProductosSede(?mysqli $db, string $termino, string $q, array $skusPorCat): array
{
    if (!$db || $db->connect_errno) {
        return [];
    }

    $baseSelect = "SELECT p.barcode, p.descripcion, p.estado,
                          IFNULL(p.precioventa, 0) AS precioventa,
                          IFNULL(p.precioespecial1, 0) AS precioespecial1,
                          IFNULL(p.precioespecial2, 0) AS precioespecial2,
                          IFNULL(SUM(i.cantidad), 0) AS stock
                   FROM productos p
                   LEFT JOIN inventario i ON p.idproducto = i.idproducto";

    if ($termino === '') {
        $sqlProd = "{$baseSelect} GROUP BY p.barcode LIMIT 40";
        $stmtProd = $db->prepare($sqlProd);
    } elseif (!empty($skusPorCat)) {
        $placeholdersSku = implode(',', array_fill(0, count($skusPorCat), '?'));
        $sqlProd = "{$baseSelect}
                    WHERE (p.barcode LIKE ? OR p.descripcion LIKE ? OR p.barcode IN ({$placeholdersSku}))
                    GROUP BY p.barcode
                    LIMIT 40";
        $stmtProd = $db->prepare($sqlProd);
        if ($stmtProd) {
            $types = 'ss' . str_repeat('s', count($skusPorCat));
            $params = array_merge([$q, $q], $skusPorCat);
            $stmtProd->bind_param($types, ...$params);
        }
    } else {
        $sqlProd = "{$baseSelect}
                    WHERE (p.barcode LIKE ? OR p.descripcion LIKE ?)
                    GROUP BY p.barcode
                    LIMIT 40";
        $stmtProd = $db->prepare($sqlProd);
        if ($stmtProd) {
            $stmtProd->bind_param("ss", $q, $q);
        }
    }

    if (!$stmtProd) {
        return [];
    }

    $stmtProd->execute();
    $rows = $stmtProd->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtProd->close();

    $map = [];
    foreach ($rows as $r) {
        $map[(string)$r['barcode']] = $r;
    }
    return $map;
}

function formatVarHtml(float $val, string $claseExtra = ''): string
{
    $clase = ($val >= 0) ? 'var-pos' : 'var-neg';
    $signo = ($val > 0) ? '+' : '';
    $texto = $signo . number_format($val, 2, ',', '.') . '%';
    return "<span class='variacion-val {$clase} {$claseExtra}'>{$texto}</span>";
}

function actualizarPrecioEnAmbasSedes(?mysqli $dbCentral, ?mysqli $dbDrinks, string $campo, array $barcodes, float $precio): bool
{
    $columnasPermitidas = [
        'precioventa'     => 'precioventa',
        'precioespecial1' => 'precioespecial1',
        'precioespecial2' => 'precioespecial2',
    ];

    if (!isset($columnasPermitidas[$campo])) {
        return false;
    }

    $skus = array_values(array_unique(array_filter(array_map('trim', $barcodes), fn($b) => $b !== '')));
    if (empty($skus)) {
        return false;
    }

    $col = $columnasPermitidas[$campo];
    $conexiones = array_filter([$dbCentral, $dbDrinks], fn($db) => $db instanceof mysqli && !$db->connect_errno);
    if (empty($conexiones)) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $types = 'd' . str_repeat('s', count($skus));
    $params = array_merge([$precio], $skus);

    foreach ($conexiones as $db) {
        $db->begin_transaction();
    }

    try {
        foreach ($conexiones as $db) {
            $stmt = $db->prepare("UPDATE productos SET {$col} = ? WHERE barcode IN ({$placeholders})");
            if (!$stmt) {
                throw new Exception("Error preparando consulta");
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception("Error ejecutando actualización");
            }
            $stmt->close();
        }

        foreach ($conexiones as $db) {
            $db->commit();
        }
        return true;
    } catch (Throwable $e) {
        foreach ($conexiones as $db) {
            $db->rollback();
        }
        return false;
    }
}

function actualizarStockSede(?mysqli $db, string $barcode, float $nuevoStock): bool
{
    if (!$db || $db->connect_errno || $barcode === '' || $nuevoStock < 0) {
        return false;
    }

    $stmtProd = $db->prepare("SELECT idproducto FROM productos WHERE barcode = ? LIMIT 1");
    if (!$stmtProd) {
        return false;
    }
    $stmtProd->bind_param("s", $barcode);
    $stmtProd->execute();
    $resProd = $stmtProd->get_result()->fetch_assoc();
    $stmtProd->close();

    if (!$resProd) {
        return false;
    }

    $idProducto = (int)$resProd['idproducto'];
    $db->begin_transaction();

    try {
        $stmtInv = $db->prepare("SELECT idinventario FROM inventario WHERE idproducto = ? ORDER BY idinventario ASC");
        if (!$stmtInv) {
            throw new Exception("Error consultando inventario");
        }
        $stmtInv->bind_param("i", $idProducto);
        $stmtInv->execute();
        $resInv = $stmtInv->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtInv->close();

        if (!empty($resInv)) {
            $primerId = (int)$resInv[0]['idinventario'];
            $stmtUpd = $db->prepare("UPDATE inventario SET cantidad = ? WHERE idinventario = ?");
            if (!$stmtUpd) {
                throw new Exception("Error preparando actualización de inventario");
            }
            $stmtUpd->bind_param("di", $nuevoStock, $primerId);
            if (!$stmtUpd->execute()) {
                $stmtUpd->close();
                throw new Exception("Error ejecutando actualización de inventario");
            }
            $stmtUpd->close();

            if (count($resInv) > 1) {
                $cero = 0.0;
                for ($i = 1; $i < count($resInv); $i++) {
                    $extraId = (int)$resInv[$i]['idinventario'];
                    $stmtZero = $db->prepare("UPDATE inventario SET cantidad = ? WHERE idinventario = ?");
                    if (!$stmtZero) {
                        throw new Exception("Error limpiando inventario duplicado");
                    }
                    $stmtZero->bind_param("di", $cero, $extraId);
                    if (!$stmtZero->execute()) {
                        $stmtZero->close();
                        throw new Exception("Error ejecutando limpieza de inventario");
                    }
                    $stmtZero->close();
                }
            }
        } else {
            $stmtIns = $db->prepare("INSERT INTO inventario (idproducto, cantidad) VALUES (?, ?)");
            if (!$stmtIns) {
                throw new Exception("Error preparando inserción de inventario");
            }
            $stmtIns->bind_param("id", $idProducto, $nuevoStock);
            if (!$stmtIns->execute()) {
                $stmtIns->close();
                throw new Exception("Error ejecutando inserción de inventario");
            }
            $stmtIns->close();
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        return false;
    }
}

// Mapa de categorías activas (CodCat => Nombre)
$categoriasMap = [];
if ($dbRel && !$dbRel->connect_errno) {
    $resCat = $dbRel->query("SELECT CodCat, Nombre FROM categorias WHERE Estado = '1'");
    if ($resCat) {
        while ($c = $resCat->fetch_assoc()) {
            $categoriasMap[(string)$c['CodCat']] = (string)$c['Nombre'];
        }
    }
}

// ====================================================================
// 1. FILTRAR PRODUCTOS EN AMBAS SEDES (CENTRAL Y DRINKS)
// ====================================================================
if ($action === 'filter') {
    header('Content-Type: application/json; charset=utf-8');

    $sedeFiltro = strtolower(trim($_POST['sede'] ?? 'todas'));
    if (!in_array($sedeFiltro, ['todas', 'central', 'drinks'], true)) {
        $sedeFiltro = 'todas';
    }

    $termino = trim($_POST['buscar'] ?? '');
    $q = "%{$termino}%";

    $codCatsCoincidentes = [];
    if ($termino !== '') {
        foreach ($categoriasMap as $codCat => $nomCat) {
            if (
                mb_stripos($codCat, $termino, 0, 'UTF-8') !== false ||
                mb_stripos($nomCat, $termino, 0, 'UTF-8') !== false
            ) {
                $codCatsCoincidentes[] = (string)$codCat;
            }
        }
    }

    $skusPorCat = [];
    if ($termino !== '' && $dbRel && !$dbRel->connect_errno) {
        if (!empty($codCatsCoincidentes)) {
            $placeholdersCat = implode(',', array_fill(0, count($codCatsCoincidentes), '?'));
            $typesCat = str_repeat('s', count($codCatsCoincidentes)) . 's';
            $paramsCat = array_merge($codCatsCoincidentes, [$q]);

            $stmtCat = $dbRel->prepare("SELECT DISTINCT Sku FROM catproductos WHERE CodCat IN ({$placeholdersCat}) OR CodCat LIKE ?");
            if ($stmtCat) {
                $stmtCat->bind_param($typesCat, ...$paramsCat);
                $stmtCat->execute();
                $resCatSku = $stmtCat->get_result();
                while ($r = $resCatSku->fetch_assoc()) {
                    $sku = trim((string)$r['Sku']);
                    if ($sku !== '') {
                        $skusPorCat[] = $sku;
                    }
                }
                $stmtCat->close();
            }
        } else {
            $stmtCat = $dbRel->prepare("SELECT DISTINCT Sku FROM catproductos WHERE CodCat LIKE ?");
            if ($stmtCat) {
                $stmtCat->bind_param("s", $q);
                $stmtCat->execute();
                $resCatSku = $stmtCat->get_result();
                while ($r = $resCatSku->fetch_assoc()) {
                    $sku = trim((string)$r['Sku']);
                    if ($sku !== '') {
                        $skusPorCat[] = $sku;
                    }
                }
                $stmtCat->close();
            }
        }
    }

    $mapCentral = consultarProductosSede($dbCentral, $termino, $q, $skusPorCat);
    $mapDrinks  = consultarProductosSede($dbDrinks, $termino, $q, $skusPorCat);

    $barcodes = array_values(array_unique(array_merge(array_keys($mapCentral), array_keys($mapDrinks))));
    $barcodes = array_slice($barcodes, 0, 50);

    if (empty($barcodes)) {
        echo json_encode([
            'html' => "<tr><td colspan='14'>No se encontraron productos en las sedes consultadas</td></tr>"
        ]);
        exit;
    }

    $promCentral = precioPromCompra($dbCentral, $barcodes);
    $promDrinks  = precioPromCompra($dbDrinks, $barcodes);

    $relaciones = [];
    if ($dbRel && !$dbRel->connect_errno && !empty($barcodes)) {
        $placeholdersRel = implode(',', array_fill(0, count($barcodes), '?'));
        $typesRel = str_repeat('s', count($barcodes));
        $stmtRel = $dbRel->prepare("SELECT Sku, CodCat FROM catproductos WHERE Sku IN ({$placeholdersRel})");
        if ($stmtRel) {
            $stmtRel->bind_param($typesRel, ...$barcodes);
            $stmtRel->execute();
            $resRel = $stmtRel->get_result();
            while ($r = $resRel->fetch_assoc()) {
                $relaciones[(string)$r['Sku']] = (string)$r['CodCat'];
            }
            $stmtRel->close();
        }
    }

    $html = '';
    foreach ($barcodes as $bcRaw) {
        $bcRaw = (string)$bcRaw;
        $cData = $mapCentral[$bcRaw] ?? null;
        $dData = $mapDrinks[$bcRaw] ?? null;

        $codActualRaw = $relaciones[$bcRaw] ?? '';
        $nombreCatRaw = $categoriasMap[$codActualRaw] ?? 'Sin categoría';

        $codActual = htmlspecialchars($codActualRaw, ENT_QUOTES, 'UTF-8');
        $nombreCat = htmlspecialchars($nombreCatRaw, ENT_QUOTES, 'UTF-8');
        $bc = htmlspecialchars($bcRaw, ENT_QUOTES, 'UTF-8');
        $descRaw = $cData['descripcion'] ?? ($dData['descripcion'] ?? '');
        $desc = htmlspecialchars((string)$descRaw, ENT_QUOTES, 'UTF-8');

        $sedesARenderizar = [];
        if (($sedeFiltro === 'todas' || $sedeFiltro === 'central') && $cData !== null) {
            $sedesARenderizar[] = ['sede' => 'central', 'etiqueta' => 'Central', 'clase' => 'bg-central', 'data' => $cData, 'prom' => (float)($promCentral[$bcRaw] ?? 0.0)];
        }
        if (($sedeFiltro === 'todas' || $sedeFiltro === 'drinks') && $dData !== null) {
            $sedesARenderizar[] = ['sede' => 'drinks', 'etiqueta' => 'Drinks', 'clase' => 'bg-drinks', 'data' => $dData, 'prom' => (float)($promDrinks[$bcRaw] ?? 0.0)];
        }

        foreach ($sedesARenderizar as $item) {
            $sedeKey   = $item['sede'];
            $etiqueta  = $item['etiqueta'];
            $badgeCls  = $item['clase'];
            $rowProd   = $item['data'];
            $pc        = $item['prom'];

            $estadoInt     = ((int)($rowProd['estado'] ?? 0) === 1) ? 1 : 0;
            $estadoChecked = ($estadoInt === 1) ? 'checked' : '';
            $stockVal      = (float)($rowProd['stock'] ?? 0);

            $pv  = (float)$rowProd['precioventa'];
            $pe1 = (float)$rowProd['precioespecial1'];
            $pe2 = (float)$rowProd['precioespecial2'];

            $varVenta = ($pc > 0) ? (($pv - $pc) / $pc) * 100.0 : 0.0;
            $varEsp1  = ($pc > 0) ? (($pe1 - $pc) / $pc) * 100.0 : 0.0;
            $varEsp2  = ($pc > 0) ? (($pe2 - $pc) / $pc) * 100.0 : 0.0;

            $html .= "<tr class='row-sede row-{$sedeKey}' data-bc='{$bc}' data-sede='{$sedeKey}' data-codcat='{$codActual}' data-compra='{$pc}' data-stock='{$stockVal}' data-estado='{$estadoInt}'>
                <td data-label='CodCat'><b>" . ($codActual !== '' ? $codActual : '-') . "</b></td>
                <td data-label='Nombre Categoría' class='text-left'><span class='cat-label'>{$nombreCat}</span></td>
                <td data-label='Sede'><span class='badge-sede {$badgeCls}'>{$etiqueta}</span></td>
                <td data-label='Barcode'><b>{$bc}</b></td>
                <td data-label='Descripción' class='text-left'>{$desc}</td>
                <td data-label='Precio Prom'><span class='compra-val'>$" . number_format($pc, 0, ',', '.') . "</span></td>
                <td data-label='Precio Venta'>
                    <input type='number' step='any' min='0' class='price-input in-pv' value='{$pv}' data-field='precioventa' aria-label='Precio Venta {$etiqueta}'>
                </td>
                <td data-label='Precio Especial 1'>
                    <input type='number' step='any' min='0' class='price-input in-pe1' value='{$pe1}' data-field='precioespecial1' aria-label='Precio Especial 1 {$etiqueta}'>
                </td>
                <td data-label='Precio Especial 2'>
                    <input type='number' step='any' min='0' class='price-input in-pe2' value='{$pe2}' data-field='precioespecial2' aria-label='Precio Especial 2 {$etiqueta}'>
                </td>
                <td data-label='% Var.(Vta)'>" . formatVarHtml($varVenta, 'var-venta') . "</td>
                <td data-label='% Var.1)'>" . formatVarHtml($varEsp1, 'var-esp1') . "</td>
                <td data-label='% Var.2)'>" . formatVarHtml($varEsp2, 'var-esp2') . "</td>
                <td data-label='Stock'>
                    <input type='number' step='any' min='0' class='stock-input' value='{$stockVal}' aria-label='Stock {$etiqueta}'>
                </td>
                <td data-label='Estado'>
                    <label class='switch' title='Cambiar estado en {$etiqueta}'>
                        <input type='checkbox' class='toggle-estado' {$estadoChecked}>
                        <span class='slider'></span>
                    </label>
                </td>
            </tr>";
        }
    }

    echo json_encode([
        'html' => $html ?: "<tr><td colspan='14'>No se encontraron productos para la sede seleccionada</td></tr>"
    ]);
    exit;
}

// ====================================================================
// 2. ACTUALIZAR PRECIO (INDIVIDUAL O MASIVO POR CATEGORÍA) EN AMBAS SEDES
// ====================================================================
if ($action === 'update_price') {
    header('Content-Type: application/json; charset=utf-8');

    $campo  = trim($_POST['campo'] ?? 'precioventa');
    $precio = (float)($_POST['precio'] ?? 0);

    $barcodes = [];
    if (isset($_POST['barcodes']) && is_array($_POST['barcodes'])) {
        $barcodes = $_POST['barcodes'];
    } elseif (!empty($_POST['barcode'])) {
        $barcodes = [$_POST['barcode']];
    }

    if (empty($barcodes) || $precio < 0) {
        echo json_encode(['success' => false, 'msg' => 'Datos de precio inválidos']);
        exit;
    }

    $ok = actualizarPrecioEnAmbasSedes($dbCentral, $dbDrinks, $campo, $barcodes, $precio);
    echo json_encode([
        'success' => $ok,
        'count'   => count($barcodes),
        'msg'     => $ok ? 'Precio actualizado en ambas sedes' : 'Error al actualizar el precio'
    ]);
    exit;
}

// ====================================================================
// 3. ACTUALIZAR STOCK DE PRODUCTO EN LA SEDE SELECCIONADA
// ====================================================================
if ($action === 'update_stock') {
    header('Content-Type: application/json; charset=utf-8');

    $bc    = trim($_POST['barcode'] ?? '');
    $sede  = strtolower(trim($_POST['sede'] ?? 'central'));
    $stock = (float)($_POST['stock'] ?? 0);

    $dbTarget = ($sede === 'drinks') ? $dbDrinks : $dbCentral;
    if ($bc === '' || $stock < 0 || !$dbTarget || $dbTarget->connect_errno) {
        echo json_encode(['success' => false, 'msg' => 'Datos o conexión no válidos']);
        exit;
    }

    $ok = actualizarStockSede($dbTarget, $bc, $stock);
    echo json_encode([
        'success' => $ok,
        'msg'     => $ok ? 'Stock actualizado' : 'No se pudo actualizar el stock'
    ]);
    exit;
}

// ====================================================================
// 4. ACTUALIZAR ESTADO DE PRODUCTO EN LA SEDE SELECCIONADA
// ====================================================================
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');

    $bc     = trim($_POST['barcode'] ?? '');
    $sede   = strtolower(trim($_POST['sede'] ?? 'central'));
    $estado = ((int)($_POST['estado'] ?? 0) === 1) ? 1 : 0;

    $dbTarget = ($sede === 'drinks') ? $dbDrinks : $dbCentral;
    if ($bc === '' || !$dbTarget || $dbTarget->connect_errno) {
        echo json_encode(['success' => false, 'msg' => 'Conexión o barcode no válido']);
        exit;
    }

    $stmt = $dbTarget->prepare("UPDATE productos SET estado = ? WHERE barcode = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'msg' => 'Error al preparar actualización de estado']);
        exit;
    }

    $stmt->bind_param("is", $estado, $bc);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'success' => $ok,
        'msg'     => $ok ? 'Estado actualizado' : 'No se pudo actualizar el estado'
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relación Categoría - Producto y Precios (Central y Drinks)</title>
    <style>
        :root {
            --bg-page: #f4f6f9;
            --bg-card: #ffffff;
            --primary: #1a2a6c;
            --border: #e5e7eb;
            --text: #1f2937;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-page);
            color: var(--text);
            margin: 0;
            padding: 10px;
        }

        .container {
            max-width: 1650px;
            width: 100%;
            margin: 0 auto;
            background: var(--bg-card);
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        .header-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        h2 {
            margin: 0;
            font-size: clamp(1.1rem, 2.2vw, 1.5rem);
            color: var(--primary);
        }

        .stock-summary-pill {
            background: #e0e7ff;
            color: #1e1b4b;
            border: 1px solid #c7d2fe;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: stretch;
        }

        #sede {
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            min-width: 170px;
        }

        #search {
            flex: 1 1 260px;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
        }

        .bulk-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s, border-color 0.2s;
        }

        .bulk-toggle.active {
            background: #eff6ff;
            border-color: #2563eb;
            color: #1d4ed8;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            margin-top: 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1260px;
        }

        th, td {
            border-bottom: 1px solid var(--border);
            border-right: 1px solid #f1f5f9;
            padding: 8px;
            text-align: center;
            font-size: 13px;
            vertical-align: middle;
        }

        th:last-child, td:last-child { border-right: none; }

        th {
            background: var(--primary);
            color: #fff;
            white-space: nowrap;
            font-weight: 600;
        }

        .row-central { background: #f8fbff; }
        .row-drinks  { background: #fffdf7; }

        .total-row {
            background: #e2e8f0;
            font-weight: 700;
            color: #0f172a;
        }

        .badge-sede {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 4px;
            color: #fff;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .bg-central { background: #2563eb; }
        .bg-drinks  { background: #d97706; }

        .text-left { text-align: left; }

        .cat-label {
            font-weight: 600;
            color: #334155;
            display: inline-block;
        }

        .stock-input {
            width: 88px;
            max-width: 100%;
            padding: 6px;
            border-radius: 5px;
            border: 1px solid #cbd5e1;
            text-align: right;
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
            background: #f1f5f9;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }

        .stock-input:focus {
            outline: none;
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
            vertical-align: middle;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5e1;
            transition: 0.25s;
            border-radius: 20px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: #fff;
            transition: 0.25s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #16a34a;
        }

        input:checked + .slider:before {
            transform: translateX(18px);
        }

        .price-input {
            width: 95px;
            max-width: 100%;
            padding: 6px;
            border-radius: 5px;
            border: 1px solid #cbd5e1;
            text-align: right;
            font-weight: 700;
            font-size: 13px;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }

        .price-input:focus {
            outline: none;
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .in-pv  { color: #166534; background: #f0fdf4; }
        .in-pe1 { color: #1e40af; background: #eff6ff; }
        .in-pe2 { color: #5b21b6; background: #f5f3ff; }

        .price-saved {
            background-color: #dcfce7 !important;
            border-color: #16a34a !important;
        }

        .compra-val {
            font-weight: 700;
            color: #b45309;
            background: #fef3c7;
            padding: 4px 8px;
            border-radius: 5px;
            display: inline-block;
            white-space: nowrap;
        }

        .variacion-val {
            font-weight: 700;
            padding: 4px 7px;
            border-radius: 5px;
            display: inline-block;
            white-space: nowrap;
            font-size: 12px;
        }

        .var-pos { color: #166534; background: #dcfce7; }
        .var-neg { color: #991b1b; background: #fee2e2; }

        @media (max-width: 768px) {
            body { padding: 8px; }
            .container { padding: 12px; border-radius: 8px; }
            .header-top { flex-direction: column; align-items: stretch; }
            .stock-summary-pill { text-align: center; white-space: normal; }
            #sede, .bulk-toggle { width: 100%; justify-content: center; }

            .table-wrap {
                border: none;
                overflow-x: visible;
            }

            table, thead, tbody, tfoot, th, td, tr {
                display: block;
                min-width: 0;
                width: 100%;
            }

            thead { display: none; }

            tbody tr.row-sede {
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                margin-bottom: 12px;
                padding: 8px 10px;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            }

            tbody tr.row-central { background: #f8fbff; }
            tbody tr.row-drinks  { background: #fffdf7; }

            tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
                text-align: right;
                border: none;
                border-bottom: 1px dashed #e5e7eb;
                padding: 7px 2px;
                font-size: 13px;
                word-break: break-word;
            }

            tbody td:last-child { border-bottom: none; }

            tbody td::before {
                content: attr(data-label);
                font-weight: 700;
                color: #475569;
                text-align: left;
                flex: 0 0 44%;
            }

            tbody td.text-left { text-align: right; }

            .price-input, .stock-input { width: 110px; }

            tfoot tr.total-row {
                border: 1px solid #94a3b8;
                border-radius: 8px;
                padding: 10px 12px;
                background: #e2e8f0;
            }

            tfoot tr.total-row td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border: none;
                padding: 2px 0;
                text-align: right !important;
            }

            tfoot tr.total-row td:first-child {
                padding-right: 0 !important;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-top">
        <h2>Relación Categoría - Producto y Precios (Central y Drinks)</h2>
        <div class="stock-summary-pill">
            Stock visible (activos): <span id="stock-pill-val">0,00</span>
        </div>
    </div>

    <div class="toolbar">
        <select id="sede" aria-label="Seleccionar sede">
            <option value="todas">Sedes: Central y Drinks</option>
            <option value="central">Solo Sede: Central</option>
            <option value="drinks">Solo Sede: Drinks</option>
        </select>
        <input type="text" id="search" placeholder="Buscar por código o nombre de categoría, código de barras o descripción...">
        <label class="bulk-toggle" id="bulk-label" title="Replica el precio modificado en todos los productos visibles de la misma categoría">
            <input type="checkbox" id="apply-category">
            Aplicar cambio a toda la categoría
        </label>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>CodCat</th>
                    <th class="text-left">Nombre Categoría</th>
                    <th>Sede</th>
                    <th>Barcode</th>
                    <th class="text-left">Descripción</th>
                    <th>Precio Prom</th>
                    <th>Precio Venta</th>
                    <th>Precio Especial 1</th>
                    <th>Precio Especial 2</th>
                    <th>% Var. (Venta)</th>
                    <th>% Var. (Esp. 1)</th>
                    <th>% Var. (Esp. 2)</th>
                    <th>Stock</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody id="rel-tbody">
                <tr><td colspan="14">Cargando...</td></tr>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="12" style="text-align: right; padding-right: 14px;">TOTAL STOCK VISIBLE (ACTIVOS):</td>
                    <td id="total-stock-visible" style="font-size: 14px; color: #1a2a6c;">0,00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let debounceTimer;
    let activeRequest = null;

    function recalcularStockVisible() {
        let total = 0;
        $('#rel-tbody tr.row-sede:visible').each(function() {
            const estado = String($(this).attr('data-estado') || '0');
            if (estado === '1') {
                const val = parseFloat($(this).attr('data-stock')) || 0;
                total += val;
            }
        });

        const formatted = total.toLocaleString('es-CO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        $('#total-stock-visible').text(formatted);
        $('#stock-pill-val').text(formatted);
    }

    function cargarRelaciones() {
        const sede = $('#sede').val();
        const buscar = $('#search').val();

        if (activeRequest && activeRequest.readyState !== 4) {
            activeRequest.abort();
        }

        activeRequest = $.post('', { action: 'filter', sede: sede, buscar: buscar }, function(res) {
            $('#rel-tbody').html(res.html);
            recalcularStockVisible();
        }, 'json');
    }

    function formatearVar(precio, compra) {
        if (!compra || compra <= 0) return '+0,00%';
        const pct = ((precio - compra) / compra) * 100;
        const signo = pct > 0 ? '+' : '';
        return signo + pct.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }

    function aplicarEstiloVar($el, precio, compra) {
        const pct = compra > 0 ? ((precio - compra) / compra) * 100 : 0;
        $el.text(formatearVar(precio, compra))
           .removeClass('var-pos var-neg')
           .addClass(pct >= 0 ? 'var-pos' : 'var-neg');
    }

    function recalcularFila($fila) {
        const compra = parseFloat($fila.data('compra')) || 0;
        const pv  = parseFloat($fila.find('.in-pv').val())  || 0;
        const pe1 = parseFloat($fila.find('.in-pe1').val()) || 0;
        const pe2 = parseFloat($fila.find('.in-pe2').val()) || 0;

        aplicarEstiloVar($fila.find('.var-venta'), pv, compra);
        aplicarEstiloVar($fila.find('.var-esp1'), pe1, compra);
        aplicarEstiloVar($fila.find('.var-esp2'), pe2, compra);
    }

    $('#apply-category').on('change', function() {
        $('#bulk-label').toggleClass('active', $(this).is(':checked'));
    });

    $('#sede').on('change', function() {
        cargarRelaciones();
    });

    $('#search').on('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => cargarRelaciones(), 220);
    });

    // Actualizar stock editable por sede
    $(document).on('change', '.stock-input', function() {
        const $inp  = $(this);
        const $fila = $inp.closest('tr');
        const bc    = String($fila.attr('data-bc') || '');
        const sede  = String($fila.attr('data-sede') || 'central');
        const stock = parseFloat($inp.val());

        if (isNaN(stock) || stock < 0) {
            alert('Ingrese un stock válido mayor o igual a 0.');
            $inp.val($fila.attr('data-stock') || 0);
            return;
        }

        $fila.attr('data-stock', String(stock));
        recalcularStockVisible();

        $.post('', {
            action: 'update_stock',
            barcode: bc,
            sede: sede,
            stock: stock
        }, function(res) {
            if (res.success) {
                $inp.addClass('price-saved');
                setTimeout(() => $inp.removeClass('price-saved'), 750);
            } else {
                alert(res.msg || 'No se pudo actualizar el stock');
            }
        }, 'json').fail(function() {
            alert('Error de comunicación al actualizar el stock');
        });
    });

    $(document).on('change', '.toggle-estado', function() {
        const $chk   = $(this);
        const $fila  = $chk.closest('tr');
        const bc     = String($fila.attr('data-bc') || '');
        const sede   = String($fila.attr('data-sede') || 'central');
        const estado = $chk.is(':checked') ? 1 : 0;

        $chk.prop('disabled', true);

        $.post('', {
            action: 'toggle_status',
            barcode: bc,
            sede: sede,
            estado: estado
        }, function(res) {
            $chk.prop('disabled', false);
            if (res.success) {
                $fila.attr('data-estado', String(estado));
                recalcularStockVisible();
            } else {
                alert(res.msg || 'No se pudo cambiar el estado');
                $chk.prop('checked', !estado);
            }
        }, 'json').fail(function() {
            $chk.prop('disabled', false);
            alert('Error de comunicación al cambiar el estado');
            $chk.prop('checked', !estado);
        });
    });

    $(document).on('input', '.price-input', function() {
        const $input = $(this);
        const $fila  = $input.closest('tr');
        const bc     = String($fila.attr('data-bc') || '');
        const campo  = $input.data('field');
        const valor  = $input.val();
        const codCat = String($fila.attr('data-codcat') || '');
        const aplicarCat = $('#apply-category').is(':checked') && codCat !== '';

        const selector = aplicarCat
            ? `#rel-tbody tr[data-codcat="${CSS.escape(codCat)}"]`
            : `#rel-tbody tr[data-bc="${CSS.escape(bc)}"]`;

        $(selector).each(function() {
            const $tr = $(this);
            $tr.find(`.price-input[data-field="${campo}"]`).val(valor);
            recalcularFila($tr);
        });
    });

    $(document).on('change', '.price-input', function() {
        const $input = $(this);
        const $fila  = $input.closest('tr');
        const bc     = String($fila.attr('data-bc') || '');
        const codCat = String($fila.attr('data-codcat') || '');
        const campo  = $input.data('field');
        const precio = parseFloat($input.val());

        if (isNaN(precio) || precio < 0) {
            alert('Ingrese un precio válido mayor o igual a 0.');
            return;
        }

        const aplicarCat = $('#apply-category').is(':checked') && codCat !== '';
        const selector = aplicarCat
            ? `#rel-tbody tr[data-codcat="${CSS.escape(codCat)}"]`
            : `#rel-tbody tr[data-bc="${CSS.escape(bc)}"]`;

        const setBarcodes = new Set();
        const listaInputs = [];

        $(selector).each(function() {
            const $tr = $(this);
            const skuFila = String($tr.attr('data-bc') || '');
            if (skuFila !== '') {
                setBarcodes.add(skuFila);
            }
            const $inp = $tr.find(`.price-input[data-field="${campo}"]`);
            $inp.val(precio);
            recalcularFila($tr);
            listaInputs.push($inp[0]);
        });

        const barcodes = Array.from(setBarcodes);
        const $inputsAfectados = $(listaInputs);

        $.post('', {
            action: 'update_price',
            barcodes: barcodes,
            campo: campo,
            precio: precio
        }, function(res) {
            if (res.success) {
                $inputsAfectados.addClass('price-saved');
                setTimeout(() => $inputsAfectados.removeClass('price-saved'), 750);
            } else {
                alert(res.msg || 'No se pudo actualizar el precio');
            }
        }, 'json').fail(function() {
            alert('Error de comunicación al actualizar el precio');
        });
    });

    cargarRelaciones();
</script>

</body>
</html>