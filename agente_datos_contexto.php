<?php
/**
 * Datos de solo lectura para el asistente: resumen alineado con pantallas del menú
 * (dashboard, ventas, compras, cartera, transferencias, tops, consecutivos, stock, proyección agotamiento).
 * Conexiones: Central, Drinks (POS) y BnmaWeb (Conexion).
 */
declare(strict_types=1);

if (!function_exists('agente_ventas_por_rango')) {
    /**
     * @return array<string,float> clave FECHA YYYYMMDD => total
     */
    function agente_ventas_por_rango(?mysqli $db, string $f_ini, string $f_fin): array
    {
        if (!$db || $db->connect_error) {
            return [];
        }
        $sql = "SELECT FECHA, SUM(t) AS total FROM (
            SELECT F.FECHA AS FECHA, (DF.CANTIDAD * DF.VALORPROD) AS t
            FROM FACTURAS F
            INNER JOIN DETFACTURAS DF ON DF.IDFACTURA = F.IDFACTURA
            LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
            WHERE F.ESTADO = '0' AND DV.IDFACTURA IS NULL AND F.FECHA BETWEEN ? AND ?
            UNION ALL
            SELECT P.FECHA AS FECHA, (DP.CANTIDAD * DP.VALORPROD) AS t
            FROM PEDIDOS P
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
            WHERE P.ESTADO = '0' AND P.FECHA BETWEEN ? AND ?
        ) X GROUP BY FECHA ORDER BY FECHA";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ssss', $f_ini, $f_fin, $f_ini, $f_fin);
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(string) $row['FECHA']] = (float) $row['total'];
        }
        return $out;
    }
}

if (!function_exists('agente_stock_bajo_sede')) {
    /**
     * @return list<array{barcode:string,descripcion:string,stock:float,stockmin:float}>
     */
    function agente_stock_bajo_sede(?mysqli $db, int $limite = 20): array
    {
        if (!$db || $db->connect_error) {
            return [];
        }
        $limite = max(1, min(40, $limite));
        $sql = "SELECT p.barcode, p.descripcion,
                IFNULL(SUM(i.cantidad), 0) AS stock,
                IFNULL(p.stockmin, 0) AS stockmin
            FROM productos p
            LEFT JOIN inventario i ON i.idproducto = p.idproducto
            WHERE p.estado = '1'
            GROUP BY p.idproducto, p.barcode, p.descripcion, p.stockmin
            HAVING IFNULL(SUM(i.cantidad), 0) < IFNULL(p.stockmin, 0) AND IFNULL(p.stockmin, 0) > 0
            ORDER BY IFNULL(SUM(i.cantidad), 0) ASC
            LIMIT " . (int) $limite;
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'barcode' => (string) $row['barcode'],
                'descripcion' => (string) $row['descripcion'],
                'stock' => (float) $row['stock'],
                'stockmin' => (float) $row['stockmin'],
            ];
        }
        return $rows;
    }
}

if (!function_exists('agente_proyeccion_agotamiento_sede')) {
    /**
     * Riesgo de quiebre ~7 días: stock / (unidades_7d / 7) ≤ 7, o sin stock con ventas recientes.
     * Una sola consulta (evita miles de filas en PHP y N consultas de stock).
     *
     * @return list<array{barcode:string,descripcion:string,stock:float,stockmin:float,unidades_7d:float,prom_dia:float,dias_estimados:?float,nota:string}>
     */
    function agente_proyeccion_agotamiento_sede(?mysqli $db, string $f_ini, string $f_fin, int $limite = 22): array
    {
        if (!$db || $db->connect_error) {
            return [];
        }
        $limite = max(1, min(35, $limite));
        $sql = 'SELECT R.barcode, R.descripcion, R.stock, R.stockmin, R.unidades_7d, R.prom_dia, R.dias_estimados
            FROM (
                SELECT
                    p.barcode,
                    p.descripcion,
                    IFNULL(SUM(i.cantidad), 0) AS stock,
                    IFNULL(p.stockmin, 0) AS stockmin,
                    agg.u7 AS unidades_7d,
                    agg.u7 / 7.0 AS prom_dia,
                    CASE
                        WHEN IFNULL(SUM(i.cantidad), 0) <= 0 AND agg.u7 > 0.000001 THEN 0
                        WHEN agg.u7 / 7.0 > 0.000001 THEN IFNULL(SUM(i.cantidad), 0) / (agg.u7 / 7.0)
                        ELSE NULL
                    END AS dias_estimados
                FROM productos p
                LEFT JOIN inventario i ON i.idproducto = p.idproducto
                INNER JOIN (
                    SELECT idproducto, SUM(unidades) AS u7 FROM (
                        SELECT D.IDPRODUCTO AS idproducto, SUM(D.CANTIDAD) AS unidades
                        FROM FACTURAS F
                        INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
                        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
                        WHERE F.ESTADO = \'0\' AND DV.IDFACTURA IS NULL AND F.FECHA BETWEEN ? AND ?
                        GROUP BY D.IDPRODUCTO
                        UNION ALL
                        SELECT DP.IDPRODUCTO AS idproducto, SUM(DP.CANTIDAD) AS unidades
                        FROM PEDIDOS P
                        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
                        WHERE P.ESTADO = \'0\' AND P.FECHA BETWEEN ? AND ?
                        GROUP BY DP.IDPRODUCTO
                    ) T
                    GROUP BY idproducto
                ) agg ON agg.idproducto = p.idproducto
                WHERE p.estado = \'1\'
                GROUP BY p.idproducto, p.barcode, p.descripcion, p.stockmin, agg.u7
                HAVING (
                    (IFNULL(SUM(i.cantidad), 0) <= 0 AND agg.u7 > 0)
                    OR (
                        agg.u7 / 7.0 > 0.000001
                        AND IFNULL(SUM(i.cantidad), 0) / (agg.u7 / 7.0) <= 7
                    )
                )
            ) R
            ORDER BY (R.dias_estimados IS NULL), R.dias_estimados ASC
            LIMIT ' . (int) $limite;
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ssss', $f_ini, $f_fin, $f_ini, $f_fin);
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
        $out = [];
        while ($res && $row = $res->fetch_assoc()) {
            $stock = (float) $row['stock'];
            $u7 = (float) $row['unidades_7d'];
            $diasEst = $row['dias_estimados'];
            $diasFloat = is_numeric($diasEst) ? (float) $diasEst : null;
            $nota = ($stock <= 0 && $u7 > 0)
                ? 'sin stock; hubo ventas en la ventana'
                : 'consumo reciente vs stock';
            $out[] = [
                'barcode' => (string) $row['barcode'],
                'descripcion' => (string) $row['descripcion'],
                'stock' => $stock,
                'stockmin' => (float) $row['stockmin'],
                'unidades_7d' => $u7,
                'prom_dia' => (float) $row['prom_dia'],
                'dias_estimados' => $diasFloat,
                'nota' => $nota,
            ];
        }
        return $out;
    }
}

if (!function_exists('agente_linea_momento_bogota')) {
    function agente_linea_momento_bogota(): string
    {
        date_default_timezone_set('America/Bogota');
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $ts = time();
        return sprintf(
            '**Momento actual (Bogotá, Colombia — zona horaria America/Bogota):** %s %d de %s de %d, %s.',
            $dias[(int) date('w', $ts)],
            (int) date('j', $ts),
            $meses[(int) date('n', $ts)],
            (int) date('Y', $ts),
            date('H:i:s', $ts)
        );
    }
}

if (!function_exists('agente_productos_hoy_lista')) {
    /** @return array<string,array{barcode:string,producto:string,cant:float,total:float}> */
    function agente_productos_hoy_lista(?mysqli $db): array
    {
        if (!$db || $db->connect_error) {
            return [];
        }
        $hoy = date('Ymd');
        $out = [];
        $sql = "SELECT PR.barcode, PR.DESCRIPCION AS producto,
                round(SUM(D.CANTIDAD),1) cant,
                round(SUM(D.CANTIDAD * D.VALORPROD),1) total
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
            LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
            WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA = '$hoy'
            GROUP BY PR.barcode, PR.DESCRIPCION";
        $r = $db->query($sql);
        while ($r && $row = $r->fetch_assoc()) {
            $out[$row['barcode']] = [
                'barcode' => (string) $row['barcode'],
                'producto' => (string) $row['producto'],
                'cant' => (float) $row['cant'],
                'total' => (float) $row['total'],
            ];
        }
        $sqlPed = "SELECT PR.barcode, PR.DESCRIPCION AS producto,
                round(SUM(D.CANTIDAD),1) cant,
                round(SUM(D.CANTIDAD * D.VALORPROD),1) total
            FROM PEDIDOS P
            INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
            INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
            WHERE P.ESTADO='0' AND P.FECHA = '$hoy'
            GROUP BY PR.barcode, PR.DESCRIPCION";
        $rp = $db->query($sqlPed);
        while ($rp && $row = $rp->fetch_assoc()) {
            $bc = $row['barcode'];
            if (isset($out[$bc])) {
                $out[$bc]['cant'] += (float) $row['cant'];
                $out[$bc]['total'] += (float) $row['total'];
            } else {
                $out[$bc] = [
                    'barcode' => (string) $row['barcode'],
                    'producto' => (string) $row['producto'],
                    'cant' => (float) $row['cant'],
                    'total' => (float) $row['total'],
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('agente_top_desde_map')) {
    /** @param array<string,array{total:float,...}> $map */
    function agente_top_desde_map(array $map, int $n): array
    {
        $list = array_values($map);
        usort($list, static function ($a, $b) {
            return ($b['total'] <=> $a['total']);
        });
        return array_slice($list, 0, $n);
    }
}

if (!function_exists('agente_productos_mes_lista')) {
    /** @return array<string,array{barcode:string,producto:string,total:float}> */
    function agente_productos_mes_lista(?mysqli $db, string $anioMes): array
    {
        if (!$db || $db->connect_error) {
            return [];
        }
        $out = [];
        $sql = "SELECT PR.barcode, PR.DESCRIPCION AS producto,
                SUM(D.CANTIDAD * D.VALORPROD) AS total
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
            LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
            WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA LIKE '" . $db->real_escape_string($anioMes) . "%'
            GROUP BY PR.barcode, PR.DESCRIPCION";
        $r = $db->query($sql);
        while ($r && $row = $r->fetch_assoc()) {
            $out[$row['barcode']] = [
                'barcode' => (string) $row['barcode'],
                'producto' => (string) $row['producto'],
                'total' => (float) $row['total'],
            ];
        }
        $sqlP = "SELECT PR.barcode, PR.DESCRIPCION AS producto,
                SUM(D.CANTIDAD * D.VALORPROD) AS total
            FROM PEDIDOS P
            INNER JOIN DETPEDIDOS D ON D.IDPEDIDO = P.IDPEDIDO
            INNER JOIN PRODUCTOS PR ON PR.IDPRODUCTO = D.IDPRODUCTO
            WHERE P.ESTADO='0' AND P.FECHA LIKE '" . $db->real_escape_string($anioMes) . "%'
            GROUP BY PR.barcode, PR.DESCRIPCION";
        $rp = $db->query($sqlP);
        while ($rp && $row = $rp->fetch_assoc()) {
            $bc = $row['barcode'];
            if (isset($out[$bc])) {
                $out[$bc]['total'] += (float) $row['total'];
            } else {
                $out[$bc] = [
                    'barcode' => (string) $row['barcode'],
                    'producto' => (string) $row['producto'],
                    'total' => (float) $row['total'],
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('agente_merge_productos_totales')) {
    /**
     * @param array<string,array{barcode:string,producto?:string,total:float,...}> $a
     * @param array<string,array{barcode:string,producto?:string,total:float,...}> $b
     */
    function agente_merge_productos_totales(array $a, array $b): array
    {
        foreach ($b as $bc => $row) {
            if (!isset($a[$bc])) {
                $a[$bc] = $row;
            } else {
                $a[$bc]['total'] = ($a[$bc]['total'] ?? 0) + ($row['total'] ?? 0);
                if (isset($row['cant'])) {
                    $a[$bc]['cant'] = ($a[$bc]['cant'] ?? 0) + ($row['cant'] ?? 0);
                }
            }
        }
        return $a;
    }
}

if (!function_exists('agente_compras_total_dia')) {
    function agente_compras_total_dia(?mysqli $db, string $ymd): float
    {
        if (!$db || $db->connect_error) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(
            D.CANTIDAD * (D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))
            + ((D.VALOR - (D.descuento / NULLIF(D.CANTIDAD, 0))) * D.porciva / 100) + D.ValICUIUni)
        ), 0) AS total
        FROM compras C
        INNER JOIN DETCOMPRAS D ON D.idcompra = C.idcompra
        WHERE C.FECHA = ? AND C.ESTADO = '0'";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('s', $ymd);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (float) ($row['total'] ?? 0);
    }
}

if (!function_exists('agente_metricas_sede_dashboard')) {
    /**
     * Mismas ideas que ValorInventario::analizarSucursal (lectura).
     *
     * @return array{inventario:float,venta_dia:float,trans_dia:float,venta_mes:float,utilidad:float}
     */
    function agente_metricas_sede_dashboard(?mysqli $mysqli_conn, string $nombreSede, ?mysqli $mysqli_web): array
    {
        $vacío = ['inventario' => 0.0, 'venta_dia' => 0.0, 'trans_dia' => 0.0, 'venta_mes' => 0.0, 'utilidad' => 0.0];
        if (!$mysqli_conn || $mysqli_conn->connect_error) {
            return $vacío;
        }
        date_default_timezone_set('America/Bogota');
        $mes = date('m');
        $anio = date('Y');
        $fechaSQL = date('Y-m-d');
        $nitSedes = ['CENTRAL' => '86057267-8', 'DRINKS' => '901724534-7'];
        $nitEspecifico = $nitSedes[$nombreSede] ?? '';

        $inv = 0.0;
        $qi = $mysqli_conn->query(
            "SELECT SUM(I.cantidad * P.costo) AS total FROM inventario I INNER JOIN productos P ON P.idproducto = I.idproducto WHERE I.estado='0'"
        );
        if ($qi && $row = $qi->fetch_assoc()) {
            $inv = (float) ($row['total'] ?? 0);
        }

        $ventaDia = 0.0;
        $qv = $mysqli_conn->query(
            "SELECT SUM(total) AS venta_dia FROM (
                SELECT D.CANTIDAD * D.VALORPROD AS total FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DATE(F.FECHA)='$fechaSQL'
                UNION ALL
                SELECT DP.CANTIDAD * DP.VALORPROD FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO WHERE P.ESTADO='0' AND DATE(P.FECHA)='$fechaSQL'
                UNION ALL
                SELECT (DDV.CANTIDAD * DDV.VALORPROD) * -1 FROM DEVVENTAS DV INNER JOIN detdevventas DDV ON DV.iddevventas = DDV.iddevventas WHERE DATE(DV.fecha)='$fechaSQL'
            ) X"
        );
        if ($qv && $row = $qv->fetch_assoc()) {
            $ventaDia = (float) ($row['venta_dia'] ?? 0);
        }

        $transDia = 0.0;
        if ($nitEspecifico !== '' && $mysqli_web && !$mysqli_web->connect_error) {
            $tr_res = $mysqli_web->query(
                "SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha = '$fechaSQL' AND NitEmpresa = '" . $mysqli_web->real_escape_string($nitEspecifico) . "' AND Estado = 1"
            );
            $transDia = (float) (($tr_res && ($t = $tr_res->fetch_assoc())) ? $t['total'] : 0);
        }

        $r = null;
        $qm = $mysqli_conn->query(
            "SELECT SUM(venta) AS ventas, SUM(util) AS utilidad FROM (
                SELECT D.CANTIDAD * D.VALORPROD AS venta, (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS util
                FROM FACTURAS F INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO WHERE F.ESTADO='0' AND MONTH(F.FECHA)='$mes' AND YEAR(F.FECHA)='$anio'
                UNION ALL
                SELECT DP.CANTIDAD * DP.VALORPROD, (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo)
                FROM PEDIDOS PE INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO WHERE PE.ESTADO='0' AND MONTH(PE.FECHA)='$mes' AND YEAR(PE.FECHA)='$anio'
            ) T"
        );
        if ($qm && $row = $qm->fetch_assoc()) {
            $r = $row;
        }

        return [
            'inventario' => $inv,
            'venta_dia' => $ventaDia,
            'trans_dia' => $transDia,
            'venta_mes' => (float) (($r !== null) ? ($r['ventas'] ?? 0) : 0),
            'utilidad' => (float) (($r !== null) ? ($r['utilidad'] ?? 0) : 0),
        ];
    }
}

if (!function_exists('agente_facturas_pedidos_hoy')) {
    /** @return array{facturas:int,pedidos:int,suma_facturas:float,suma_pedidos:float} */
    function agente_facturas_pedidos_hoy(?mysqli $db, string $ymd): array
    {
        $out = ['facturas' => 0, 'pedidos' => 0, 'suma_facturas' => 0.0, 'suma_pedidos' => 0.0];
        if (!$db || $db->connect_error) {
            return $out;
        }
        $r = $db->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(F.VALORTOTAL),0) AS s FROM FACTURAS F WHERE F.ESTADO='0' AND F.FECHA = '" . $db->real_escape_string($ymd) . "'"
        );
        if ($r && $row = $r->fetch_assoc()) {
            $out['facturas'] = (int) $row['c'];
            $out['suma_facturas'] = (float) $row['s'];
        }
        $r2 = $db->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(P.VALORTOTAL),0) AS s FROM PEDIDOS P WHERE P.ESTADO='0' AND P.FECHA = '" . $db->real_escape_string($ymd) . "'"
        );
        if ($r2 && $row = $r2->fetch_assoc()) {
            $out['pedidos'] = (int) $row['c'];
            $out['suma_pedidos'] = (float) $row['s'];
        }
        return $out;
    }
}

if (!function_exists('agente_cartera_dias_antiguedad')) {
    /** Días desde la fecha del movimiento más antiguo (Bogotá, calendario civil). */
    function agente_cartera_dias_antiguedad(?string $fechaSql): ?int
    {
        if ($fechaSql === null || trim($fechaSql) === '') {
            return null;
        }
        date_default_timezone_set('America/Bogota');
        $s = trim($fechaSql);
        $fa = \DateTimeImmutable::createFromFormat('Y-m-d', $s)
            ?: \DateTimeImmutable::createFromFormat('Ymd', $s);
        if ($fa === false) {
            $ts = strtotime($s);
            if ($ts === false) {
                return null;
            }
            $fa = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('America/Bogota'));
        }
        $hoy = new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota'));
        if ($fa > $hoy) {
            return 0;
        }
        return (int) $fa->diff($hoy)->days;
    }
}

if (!function_exists('agente_cartera_resumen')) {
    /**
     * Misma consulta base que CarteraXProveedorBnma.php (terceros.Nombre, filtros y ORDER BY Saldo DESC).
     * Se añade MIN(F_Creacion) solo para antigüedad en el asistente.
     *
     * @return array{total:float,total_proveedores:int,proveedores:list<array{nit:string,nombre:string,saldo:float,antiguedad_dias:?int,fecha_mov_antigua:?string}>}
     */
    function agente_cartera_resumen(?mysqli $web, int $maxListado = 400): array
    {
        $out = ['total' => 0.0, 'total_proveedores' => 0, 'proveedores' => []];
        if (!$web || $web->connect_error) {
            return $out;
        }
        // Total y filas: mismo criterio que BNMA (excluye terceros sin Nombre en ficha)
        $r = $web->query(
            'SELECT SUM(Saldo) AS total FROM (
                SELECT SUM(p.Monto) AS Saldo
                FROM terceros t
                INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit
                WHERE t.Estado = 1
                    AND p.Estado = \'1\'
                    AND t.Nombre IS NOT NULL
                    AND t.Nombre <> \'\'
                GROUP BY t.CedulaNit, t.Nombre
                HAVING SUM(p.Monto) <> 0
            ) X'
        );
        if ($r && $row = $r->fetch_assoc()) {
            $out['total'] = (float) ($row['total'] ?? 0);
        }
        $rc = $web->query(
            'SELECT COUNT(*) AS c FROM (
                SELECT t.CedulaNit
                FROM terceros t
                INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit
                WHERE t.Estado = 1
                    AND p.Estado = \'1\'
                    AND t.Nombre IS NOT NULL
                    AND t.Nombre <> \'\'
                GROUP BY t.CedulaNit, t.Nombre
                HAVING SUM(p.Monto) <> 0
            ) Z'
        );
        if ($rc && $rowc = $rc->fetch_assoc()) {
            $out['total_proveedores'] = (int) ($rowc['c'] ?? 0);
        }
        $maxListado = max(1, min(500, $maxListado));
        $r2 = $web->query(
            "SELECT
                t.CedulaNit AS nit,
                t.Nombre AS nombre,
                SUM(p.Monto) AS saldo,
                MIN(p.F_Creacion) AS fecha_antigua
            FROM terceros t
            INNER JOIN pagosproveedores p ON p.Nit = t.CedulaNit
            WHERE t.Estado = 1
                AND p.Estado = '1'
                AND t.Nombre IS NOT NULL
                AND t.Nombre <> ''
            GROUP BY t.CedulaNit, t.Nombre
            HAVING SUM(p.Monto) <> 0
            ORDER BY Saldo DESC
            LIMIT $maxListado"
        );
        if ($r2) {
            while ($row = $r2->fetch_assoc()) {
                $fAnt = isset($row['fecha_antigua']) ? (string) $row['fecha_antigua'] : '';
                $nom = trim((string) ($row['nombre'] ?? ''));
                if ($nom === '') {
                    $nom = (string) $row['nit'];
                }
                $out['proveedores'][] = [
                    'nit' => (string) $row['nit'],
                    'nombre' => $nom,
                    'saldo' => (float) $row['saldo'],
                    'antiguedad_dias' => agente_cartera_dias_antiguedad($fAnt !== '' ? $fAnt : null),
                    'fecha_mov_antigua' => $fAnt !== '' ? $fAnt : null,
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('agente_transfers_suma_rango')) {
    function agente_transfers_suma_rango(?mysqli $web, string $desdeYmd, string $hastaYmd): float
    {
        if (!$web || $web->connect_error) {
            return 0.0;
        }
        $d = $desdeYmd;
        if (strlen($d) === 8) {
            $d = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
        }
        $h = $hastaYmd;
        if (strlen($h) === 8) {
            $h = substr($h, 0, 4) . '-' . substr($h, 4, 2) . '-' . substr($h, 6, 2);
        }
        $d = $web->real_escape_string($d);
        $h = $web->real_escape_string($h);
        $r = $web->query("SELECT COALESCE(SUM(Monto),0) AS t FROM Relaciontransferencias WHERE Estado = 1 AND Fecha BETWEEN '$d' AND '$h'");
        if ($r && $row = $r->fetch_assoc()) {
            return (float) $row['t'];
        }
        return 0.0;
    }
}

if (!function_exists('agente_consecutivos_hoy')) {
    /** @return array{factura:array{min:string,max:string,count:int},pedido:array{min:string,max:string,count:int}} */
    function agente_consecutivos_hoy(?mysqli $db, string $ymd): array
    {
        $empty = [
            'factura' => ['min' => '-', 'max' => '-', 'count' => 0],
            'pedido' => ['min' => '-', 'max' => '-', 'count' => 0],
        ];
        if (!$db || $db->connect_error) {
            return $empty;
        }
        $esc = $db->real_escape_string($ymd);
        $out = $empty;
        $r = $db->query("SELECT MIN(F.NUMERO) AS mn, MAX(F.NUMERO) AS mx, COUNT(*) AS c FROM FACTURAS F WHERE F.ESTADO='0' AND F.FECHA = '$esc'");
        if ($r && $row = $r->fetch_assoc()) {
            $out['factura'] = ['min' => (string) ($row['mn'] ?? '-'), 'max' => (string) ($row['mx'] ?? '-'), 'count' => (int) ($row['c'] ?? 0)];
        }
        $r2 = $db->query("SELECT MIN(P.NUMERO) AS mn, MAX(P.NUMERO) AS mx, COUNT(*) AS c FROM PEDIDOS P WHERE P.ESTADO='0' AND P.FECHA = '$esc'");
        if ($r2 && $row = $r2->fetch_assoc()) {
            $out['pedido'] = ['min' => (string) ($row['mn'] ?? '-'), 'max' => (string) ($row['mx'] ?? '-'), 'count' => (int) ($row['c'] ?? 0)];
        }
        return $out;
    }
}

if (!function_exists('agente_construir_bloque_datos')) {
    function agente_construir_bloque_datos(): string
    {
        date_default_timezone_set('America/Bogota');
        $hoy = date('Ymd');
        $desde7 = date('Ymd', strtotime('-7 days'));
        $anioMes = date('Ym');
        $mesIni = date('Y-m-01');
        $hoyIso = date('Y-m-d');

        mysqli_report(MYSQLI_REPORT_OFF);

        $central = null;
        $drinks = null;
        $web = null;
        if (is_readable(__DIR__ . '/ConnCentral.php')) {
            require_once __DIR__ . '/ConnCentral.php';
            $central = $GLOBALS['mysqliCentral'] ?? null;
        }
        if (is_readable(__DIR__ . '/ConnDrinks.php')) {
            require_once __DIR__ . '/ConnDrinks.php';
            $drinks = $GLOBALS['mysqliDrinks'] ?? null;
        }
        if (is_readable(__DIR__ . '/Conexion.php')) {
            require_once __DIR__ . '/Conexion.php';
            $web = $GLOBALS['mysqli'] ?? $GLOBALS['mysqliWeb'] ?? null;
        }

        $fmtM = static function (float $v): string {
            return '$' . number_format($v, 0, ',', '.');
        };

        $fmtDia = static function ($ymd): string {
            $s = (string) $ymd;
            if (strlen($s) !== 8) {
                return $s;
            }
            return substr($s, 6, 2) . '/' . substr($s, 4, 2) . '/' . substr($s, 0, 4);
        };

        $lineas = [];
        $lineas[] = agente_linea_momento_bogota();
        $lineas[] = 'Interpreta "hoy", "ahora" y cualquier referencia temporal usando **solo** esta hora de Bogotá. Los datos son lectura en tiempo real al enviar el mensaje.';
        $lineas[] = '';

        /* --- Dashboard tipo ValorInventario --- */
        $lineas[] = '### Resumen ejecutivo (Dashboard BNMA / consolidado)';
        $mC = agente_metricas_sede_dashboard($central, 'CENTRAL', $web);
        $mD = agente_metricas_sede_dashboard($drinks, 'DRINKS', $web);
        $lineas[] = '**Central:** venta hoy ' . $fmtM($mC['venta_dia']) . ' | transferencias hoy ' . $fmtM($mC['trans_dia']) . ' | neto día ' . $fmtM($mC['venta_dia'] - $mC['trans_dia'])
            . ' | venta mes ' . $fmtM($mC['venta_mes']) . ' | utilidad mes ' . $fmtM($mC['utilidad']) . ' | valor bodega ' . $fmtM($mC['inventario']);
        $lineas[] = '**Drinks:** venta hoy ' . $fmtM($mD['venta_dia']) . ' | transferencias hoy ' . $fmtM($mD['trans_dia']) . ' | neto día ' . $fmtM($mD['venta_dia'] - $mD['trans_dia'])
            . ' | venta mes ' . $fmtM($mD['venta_mes']) . ' | utilidad mes ' . $fmtM($mD['utilidad']) . ' | valor bodega ' . $fmtM($mD['inventario']);
        $lineas[] = '**Totales:** venta hoy ' . $fmtM($mC['venta_dia'] + $mD['venta_dia']) . ' | trans hoy ' . $fmtM($mC['trans_dia'] + $mD['trans_dia'])
            . ' | neto hoy ' . $fmtM(($mC['venta_dia'] + $mD['venta_dia']) - ($mC['trans_dia'] + $mD['trans_dia']))
            . ' | venta mes ' . $fmtM($mC['venta_mes'] + $mD['venta_mes']) . ' | util mes ' . $fmtM($mC['utilidad'] + $mD['utilidad']) . ' | bodega ' . $fmtM($mC['inventario'] + $mD['inventario']);

        $compC = agente_compras_total_dia($central, $hoy);
        $compD = agente_compras_total_dia($drinks, $hoy);
        $lineas[] = '**Compras del día (costo con IVA según sistema):** Central ' . $fmtM($compC) . ' | Drinks ' . $fmtM($compD) . ' | **Total compras hoy** ' . $fmtM($compC + $compD);

        $cartera = agente_cartera_resumen($web, 400);
        $nProv = (int) ($cartera['total_proveedores'] ?? 0);
        $nList = count($cartera['proveedores']);
        $lineas[] = '**Cartera proveedores** (misma base que **Cartera gráfica BNMA** / `CarteraXProveedorBnma.php`: `terceros.Nombre` rellenado, saldo ≠ 0): total ' . $fmtM($cartera['total'])
            . ' | **' . $nProv . ' proveedor(es)** con saldo distinto de cero.';
        $lineas[] = 'Detalle: nombre = campo **Nombre** del tercero; NIT al final. Antigüedad = días desde el movimiento más antiguo (extra del asistente). Orden **Saldo DESC** como en BNMA. **Usa esta lista para responder; no digas que el detalle solo está en el módulo.**';
        foreach ($cartera['proveedores'] as $p) {
            $ant = $p['antiguedad_dias'];
            $antTxt = $ant === null ? 'antigüedad n/d' : 'antigüedad ' . $ant . ' días';
            $lineas[] = '  - **' . $p['nombre'] . '** — saldo ' . $fmtM($p['saldo']) . ' | ' . $antTxt . ' · NIT ' . $p['nit'];
        }
        if ($nProv > $nList && $nList > 0) {
            $lineas[] = '  _(Listado truncado: se muestran ' . $nList . ' de ' . $nProv . ' proveedores; el resto tiene saldos de menor magnitud.)_';
        }

        if ($web && !$web->connect_error) {
            $trMes = agente_transfers_suma_rango($web, $mesIni, $hoyIso);
            $tr7 = agente_transfers_suma_rango($web, $desde7, $hoy);
            $lineas[] = '**Transferencias entre cuentas (Relaciontransferencias, Estado=1):** mes en curso ' . $fmtM($trMes) . ' | últimos 7 días ' . $fmtM($tr7);
        }

        /* --- Top productos --- */
        $lineas[] = '';
        $lineas[] = '### Lo más vendido hoy (facturas + pedidos, sin devol. en factura)';
        $phC = agente_productos_hoy_lista($central);
        $phD = agente_productos_hoy_lista($drinks);
        $mergeHoy = agente_merge_productos_totales($phC, $phD);
        $topHoy = agente_top_desde_map($mergeHoy, 15);
        foreach ($topHoy as $i => $p) {
            $lineas[] = ($i + 1) . '. ' . $p['barcode'] . ' — ' . ($p['producto'] ?? '') . ' | cant ' . number_format($p['cant'] ?? 0, 1, ',', '.') . ' | $ ' . number_format($p['total'], 0, ',', '.');

        }

        $lineas[] = '';
        $lineas[] = '### Lo más vendido mes actual (' . $anioMes . ')';
        $pmC = agente_productos_mes_lista($central, $anioMes);
        $pmD = agente_productos_mes_lista($drinks, $anioMes);
        $mergeMes = agente_merge_productos_totales($pmC, $pmD);
        $topMes = agente_top_desde_map($mergeMes, 15);
        foreach ($topMes as $i => $p) {
            $lineas[] = ($i + 1) . '. ' . $p['barcode'] . ' — ' . ($p['producto'] ?? '') . ' | $ ' . number_format($p['total'], 0, ',', '.');
        }

        /* --- Ventas 7 días --- */
        $lineas[] = '';
        $lineas[] = '### Ventas por día (últimos 7 días, facturas + pedidos)';
        $ventasC = agente_ventas_por_rango($central, $desde7, $hoy);
        $ventasD = agente_ventas_por_rango($drinks, $desde7, $hoy);
        if ($ventasC !== [] || $ventasD !== []) {
            $todasFechas = array_unique(array_merge(array_keys($ventasC), array_keys($ventasD)));
            sort($todasFechas);
            foreach ($todasFechas as $fecha) {
                $t = ($ventasC[$fecha] ?? 0) + ($ventasD[$fecha] ?? 0);
                $lineas[] = '- ' . $fmtDia($fecha) . ': ' . $fmtM($t) . ' (C ' . $fmtM($ventasC[$fecha] ?? 0) . ' + D ' . $fmtM($ventasD[$fecha] ?? 0) . ')';
            }
        } else {
            $lineas[] = '(Sin datos de ventas por día o sin conexión.)';
        }

        /* --- Facturas / pedidos documentos hoy --- */
        $lineas[] = '';
        $lineas[] = '### Documentos de venta hoy (conteo y total encabezado)';
        $fhC = agente_facturas_pedidos_hoy($central, $hoy);
        $fhD = agente_facturas_pedidos_hoy($drinks, $hoy);
        $lineas[] = '**Central:** facturas ' . $fhC['facturas'] . ' (' . $fmtM($fhC['suma_facturas']) . ') | pedidos ' . $fhC['pedidos'] . ' (' . $fmtM($fhC['suma_pedidos']) . ')';
        $lineas[] = '**Drinks:** facturas ' . $fhD['facturas'] . ' (' . $fmtM($fhD['suma_facturas']) . ') | pedidos ' . $fhD['pedidos'] . ' (' . $fmtM($fhD['suma_pedidos']) . ')';

        /* --- Consecutivos --- */
        $lineas[] = '';
        $lineas[] = '### Consecutivos hoy (min / max número de documento)';
        $coC = agente_consecutivos_hoy($central, $hoy);
        $coD = agente_consecutivos_hoy($drinks, $hoy);
        $lineas[] = '**Central:** FACTURA n=' . $coC['factura']['count'] . ' min ' . $coC['factura']['min'] . ' max ' . $coC['factura']['max']
            . ' | PEDIDO n=' . $coC['pedido']['count'] . ' min ' . $coC['pedido']['min'] . ' max ' . $coC['pedido']['max'];
        $lineas[] = '**Drinks:** FACTURA n=' . $coD['factura']['count'] . ' min ' . $coD['factura']['min'] . ' max ' . $coD['factura']['max']
            . ' | PEDIDO n=' . $coD['pedido']['count'] . ' min ' . $coD['pedido']['min'] . ' max ' . $coD['pedido']['max'];

        /* --- Stock bajo --- */
        $bajoC = agente_stock_bajo_sede($central, 12);
        $bajoD = agente_stock_bajo_sede($drinks, 12);
        $lineas[] = '';
        $lineas[] = '### Inventario bajo (stock por debajo del mínimo)';
        $lineas[] = '**Central:**';
        if ($bajoC === []) {
            $lineas[] = '- Ninguno o sin datos.';
        } else {
            foreach ($bajoC as $p) {
                $lineas[] = '- ' . $p['barcode'] . ' — ' . $p['descripcion'] . ' | stk ' . number_format($p['stock'], 2, ',', '.') . ' | mín ' . number_format($p['stockmin'], 2, ',', '.');
            }
        }
        $lineas[] = '**Drinks:**';
        if ($bajoD === []) {
            $lineas[] = '- Ninguno o sin datos.';
        } else {
            foreach ($bajoD as $p) {
                $lineas[] = '- ' . $p['barcode'] . ' — ' . $p['descripcion'] . ' | stk ' . number_format($p['stock'], 2, ',', '.') . ' | mín ' . number_format($p['stockmin'], 2, ',', '.');
            }
        }

        /* --- Proyección agotamiento (ritmo venta vs stock) --- */
        $lineas[] = '';
        $lineas[] = '### Proyección agotamiento (estimación, ~7 días)';
        $lineas[] = '**Metodología:** ventana = mismas fechas que "Ventas por día" (desde ' . $fmtDia($desde7) . ' hasta ' . $fmtDia($hoy) . '). '
            . 'Promedio diario ≈ unidades vendidas en esa ventana ÷ 7; días hasta agotar ≈ stock actual ÷ ese promedio (solo si hay ritmo de venta). '
            . 'Lista ítems con **riesgo de quiebre en ≤7 días** según ese ritmo, o **sin stock** con ventas en la ventana. '
            . 'No incluye compras/traslados futuros; es orientativo.';
        $riesgoC = agente_proyeccion_agotamiento_sede($central, $desde7, $hoy, 22);
        $riesgoD = agente_proyeccion_agotamiento_sede($drinks, $desde7, $hoy, 22);
        $lineas[] = '**Central:**';
        if ($riesgoC === []) {
            $lineas[] = '- Ninguno detectado con este criterio o sin datos.';
        } else {
            foreach ($riesgoC as $p) {
                $diasTxt = $p['dias_estimados'] === null ? 'n/d' : number_format((float) $p['dias_estimados'], 1, ',', '.');
                $lineas[] = '- ' . $p['barcode'] . ' — ' . $p['descripcion']
                    . ' | stk ' . number_format($p['stock'], 2, ',', '.')
                    . ' | u/7d ' . number_format($p['unidades_7d'], 1, ',', '.')
                    . ' | ~u/día ' . number_format($p['prom_dia'], 2, ',', '.')
                    . ' | ~días ' . $diasTxt
                    . ' | mín ' . number_format($p['stockmin'], 2, ',', '.')
                    . ' (' . $p['nota'] . ')';
            }
        }
        $lineas[] = '**Drinks:**';
        if ($riesgoD === []) {
            $lineas[] = '- Ninguno detectado con este criterio o sin datos.';
        } else {
            foreach ($riesgoD as $p) {
                $diasTxt = $p['dias_estimados'] === null ? 'n/d' : number_format((float) $p['dias_estimados'], 1, ',', '.');
                $lineas[] = '- ' . $p['barcode'] . ' — ' . $p['descripcion']
                    . ' | stk ' . number_format($p['stock'], 2, ',', '.')
                    . ' | u/7d ' . number_format($p['unidades_7d'], 1, ',', '.')
                    . ' | ~u/día ' . number_format($p['prom_dia'], 2, ',', '.')
                    . ' | ~días ' . $diasTxt
                    . ' | mín ' . number_format($p['stockmin'], 2, ',', '.')
                    . ' (' . $p['nota'] . ')';
            }
        }

        $lineas[] = '';
        $lineas[] = '### Módulos con más detalle en pantalla (no todo cabe aquí)';
        $lineas[] = 'Estadísticas por cajero/tamaño/empresa, traslados entre bodegas, nómina, usuarios, anulaciones: usar las pantallas del menú para tablas completas; aquí solo hay resúmenes agregados.';

        return implode("\n", $lineas);
    }
}
