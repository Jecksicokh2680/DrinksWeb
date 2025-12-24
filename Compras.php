<?php
session_start();

/* =========================
   CONEXIONES
========================= */
require('Conexion.php');      // $mysqli  → BnmaWeb
require('ConnCentral.php');   // $mysqliCentral → empresa001

/* =========================
   VALIDAR SESIÓN
========================= */
$User = trim($_SESSION['Usuario'] ?? '');
if ($User === '') {
    header("Location: Login.php");
    exit;
}

/* =========================
   AUTORIZACIÓN (BnmaWeb)
========================= */
function Autorizacion($User, $Solicitud) {
    global $mysqli;

    $stmt = $mysqli->prepare("
        SELECT Swich
        FROM autorizacion_tercero
        WHERE CedulaNit = ? AND Nro_Auto = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc()['Swich'];
    }
    return "NO";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Consulta Compras</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body{font-family:Arial;margin:20px;font-size:14px}
table{border-collapse:collapse;width:100%;min-width:980px}
th,td{border:1px solid #ccc;padding:8px;white-space:nowrap}
th{background:#f5f5f5;position:sticky;top:0}
.totales{background:#f0fff4;font-weight:800}
.porc-pos{background:#e8f5e9;color:#20702a;font-weight:700;padding:4px 6px;border-radius:4px}
.porc-neg{background:#fdecea;color:#8b1f1f;font-weight:700;padding:4px 6px;border-radius:4px}
.nav-proveedores{margin:15px 0;display:flex;flex-wrap:wrap;gap:8px}
.nav-proveedores a{padding:6px 12px;border-radius:20px;background:#e3f2fd;color:#0d47a1;text-decoration:none;border:1px solid #bbdefb}
.nav-proveedores a.active{background:#0d47a1;color:#fff;font-weight:600}
</style>
</head>

<body>

<a href="menu.php">⟵ Volver al menú</a>
<h2>Consulta de Compras por Fecha</h2>

<form method="GET">
    <label>Fecha:</label>
    <input type="date" name="Fecha" required
        value="<?= htmlspecialchars($_GET['Fecha'] ?? date('Y-m-d')) ?>">
    <button type="submit">Consultar</button>
</form>

<?php
/* =========================
   PROMEDIO ÚLTIMOS 30 DÍAS
   (empresa001)
========================= */
$sqlProm = "
SELECT 
    TRIM(Q.Barcode) AS Barcode,
    SUM(Q.CANTIDAD * Q.VALORPROD) / NULLIF(SUM(Q.CANTIDAD),0) AS Precio_Promedio
FROM (
    SELECT P.Barcode, D.CANTIDAD, D.VALORPROD
    FROM DETPEDIDOS D
    INNER JOIN PEDIDOS PE ON PE.IDPEDIDO = D.IDPEDIDO
    INNER JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
    WHERE PE.ESTADO='0'
      AND PE.FECHA BETWEEN DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 30 DAY),'%Y%m%d')
                       AND DATE_FORMAT(CURDATE(),'%Y%m%d')

    UNION ALL

    SELECT P.Barcode, D.CANTIDAD, D.VALORPROD
    FROM FACTURAS F
    INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
    INNER JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
    WHERE F.ESTADO='0'
      AND F.IDFACTURA NOT IN (SELECT IDFACTURA FROM DEVVENTAS)
      AND F.FECHA BETWEEN DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 30 DAY),'%Y%m%d')
                       AND DATE_FORMAT(CURDATE(),'%Y%m%d')
) Q
GROUP BY Q.Barcode";

$ventasPromedio = [];
$resProm = $mysqliCentral->query($sqlProm);
if ($resProm) {
    while ($r = $resProm->fetch_assoc()) {
        $ventasPromedio[$r['Barcode']] = (float)$r['Precio_Promedio'];
    }
}

/* =========================
   PROCESAR FECHA
========================= */
if (!empty($_GET['Fecha'])) {

    $dt = DateTime::createFromFormat('Y-m-d', $_GET['Fecha']);
    if (!$dt) die('Fecha inválida');

    $FechaSQL    = $dt->format('Ymd');
    $ProveedorSel = preg_replace('/[^0-9]/', '', $_GET['prov'] ?? '');

    /* =========================
       PROVEEDORES (BnmaWeb)
    ========================= */
    $sqlProv = "
        SELECT DISTINCT T.NIT, CONCAT(T.nombres,' ',T.apellidos) AS FACT
        FROM compras C
        INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
        WHERE C.FECHA = '$FechaSQL'
          AND C.ESTADO = '0'
        ORDER BY T.NIT";

    $resProv = $mysqliCentral->query($sqlProv);
    if ($resProv && $resProv->num_rows > 0) {
        echo "<div class='nav-proveedores'>";
        while ($p = $resProv->fetch_assoc()) {
            $act = ($ProveedorSel == $p['NIT']) ? "active" : "";
            echo "<a class='$act' href='?Fecha={$_GET['Fecha']}&prov={$p['NIT']}'>"
                .htmlspecialchars($p['FACT'])."</a>";
        }
        echo "</div>";
    }

    if ($ProveedorSel !== '') {

        $Swich1 = Autorizacion($User, '9999');

        /* =========================
           CONSULTA PRINCIPAL
           (empresa001)
        ========================= */
        $sql = "
        SELECT 
            C.idcompra,
            P.Barcode,
            P.descripcion,
            D.CANTIDAD,
            ROUND(D.VALOR) AS VALOR,
            D.ValICUIUni,
            D.descuento,
            D.porciva
        FROM compras C
        INNER JOIN TERCEROS T ON T.IDTERCERO = C.IDTERCERO
        INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        INNER JOIN PRODUCTOS P ON P.IDPRODUCTO = D.IDPRODUCTO
        WHERE C.FECHA = '$FechaSQL'
          AND C.ESTADO = '0'
          AND T.NIT = '$ProveedorSel'
        ORDER BY C.idcompra, P.Barcode";

        $res = $mysqliCentral->query($sql);

        if ($res && $res->num_rows > 0) {

            echo "<table><thead><tr>
                <th>ID</th><th>Sku</th><th>Descripción</th><th>Cant</th>
                <th>Unit</th><th>Dcto</th><th>Neto</th><th>Iva</th>
                <th>IBua</th><th>Total</th><th>P.Compra</th>";

            if ($Swich1 === "SI") {
                echo "<th>P.Venta</th><th>Util</th><th>% Util</th>";
            }

            echo "</tr></thead><tbody>";

            $sumTotal = $sumUtil = $sumCosto = $sumVenta = 0;

            while ($r = $res->fetch_assoc()) {

                $cant  = (float)$r['CANTIDAD'];
                $unit  = (float)$r['VALOR'];
                $dcto  = (float)$r['descuento'] / max($cant,1);
                $neto  = $unit - $dcto;
                $iva   = $neto * ((float)$r['porciva'] / 100);
                $costo = $neto + $iva + (float)$r['ValICUIUni'];
                $total = $costo * $cant;

                $pv   = $ventasPromedio[$r['Barcode']] ?? 0;
                $util = ($pv - $costo) * $cant;
                $porc = ($costo > 0) ? (($pv - $costo) / $costo) * 100 : 0;

                $sumTotal += $total;
                $sumUtil  += $util;
                $sumCosto += $costo * $cant;
                $sumVenta += $pv * $cant;

                echo "<tr>
                    <td>{$r['idcompra']}</td>
                    <td>".htmlspecialchars($r['Barcode'])."</td>
                    <td>".htmlspecialchars($r['descripcion'])."</td>
                    <td>$cant</td>
                    <td>$unit</td>
                    <td>$dcto</td>
                    <td>$neto</td>
                    <td>$iva</td>
                    <td>{$r['ValICUIUni']}</td>
                    <td>$total</td>
                    <td>$costo</td>";

                if ($Swich1 === "SI") {
                    $cls = ($porc >= 0) ? 'porc-pos' : 'porc-neg';
                    echo "<td>$pv</td><td>$util</td>
                          <td><span class='$cls'>".number_format($porc,1)."%</span></td>";
                }

                echo "</tr>";
            }

            $porcTot = ($sumCosto > 0) ? ($sumUtil / $sumCosto) * 100 : 0;
            $clsTot  = ($porcTot >= 0) ? 'porc-pos' : 'porc-neg';

            echo "<tr class='totales'>
                <td colspan='9'>TOTAL GENERAL</td>
                <td>$sumTotal</td>
                <td></td>";

            if ($Swich1 === "SI") {
                echo "<td>$sumVenta</td><td>$sumUtil</td>
                      <td><span class='$clsTot'>".number_format($porcTot,1)."%</span></td>";
            }

            echo "</tr></tbody></table>";
        }
    }
}
?>
</body>
</html>
