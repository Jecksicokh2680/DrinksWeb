<?php
session_start();

/* =====================================================
   VALIDAR SESIÓN
===================================================== */
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

if (empty($UsuarioSesion)) {
    header("Location: Login.php?msg=Debe iniciar sesión");
    exit;
}

/* =====================================================
   CONEXIONES
===================================================== */
require_once("ConnCentral.php"); // $mysqliCentral
require_once("ConnDrinks.php");  // $mysqliDrinks

/* =====================================================
   ZONA HORARIA BOGOTÁ
===================================================== */
date_default_timezone_set('America/Bogota');

/* =====================================================
   FECHAS
===================================================== */
$FechaHoy  = date('Y-m-d');
$FechaHora = date('Y-m-d H:i:s');
$Mes       = date('m');
$Anio      = date('Y');

/* =====================================================
   FUNCIÓN: CALCULAR INDICADORES POR SUCURSAL
===================================================== */
function calcularSucursal($mysqli, $FechaHoy, $Mes, $Anio) {

    /* ---------------- VALOR BODEGA ---------------- */
    $sqlBodega = "
        SELECT IFNULL(SUM(I.cantidad * P.costo),0) AS total
        FROM inventario I
        INNER JOIN productos P ON P.idproducto = I.idproducto
        WHERE I.estado = '1'
    ";
    $valorBodega = (float)($mysqli->query($sqlBodega)->fetch_assoc()['total'] ?? 0);

    /* ---------------- VENTA DEL DÍA ---------------- */
    $sqlVentaDia = "
        SELECT IFNULL(SUM(valor),0) AS total FROM (
            SELECT D.CANTIDAD * D.VALORPROD AS valor
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            WHERE F.ESTADO='0'
              AND DATE(F.FECHA)='$FechaHoy'

            UNION ALL

            SELECT DP.CANTIDAD * DP.VALORPROD
            FROM PEDIDOS P
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = P.IDPEDIDO
            WHERE P.ESTADO='0'
              AND DATE(P.FECHA)='$FechaHoy'
        ) X
    ";
    $ventaDia = (float)($mysqli->query($sqlVentaDia)->fetch_assoc()['total'] ?? 0);

    /* ---------------- VENTA Y UTILIDAD MES ---------------- */
    $sqlMes = "
        SELECT
            IFNULL(SUM(venta),0)    AS venta_mes,
            IFNULL(SUM(utilidad),0) AS utilidad_mes
        FROM (
            SELECT
                D.CANTIDAD * D.VALORPROD AS venta,
                (D.CANTIDAD * D.VALORPROD) - (D.CANTIDAD * P.costo) AS utilidad
            FROM FACTURAS F
            INNER JOIN DETFACTURAS D ON D.IDFACTURA = F.IDFACTURA
            INNER JOIN productos P ON P.idproducto = D.IDPRODUCTO
            WHERE F.ESTADO='0'
              AND MONTH(F.FECHA)='$Mes'
              AND YEAR(F.FECHA)='$Anio'

            UNION ALL

            SELECT
                DP.CANTIDAD * DP.VALORPROD,
                (DP.CANTIDAD * DP.VALORPROD) - (DP.CANTIDAD * P.costo)
            FROM PEDIDOS PE
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO = PE.IDPEDIDO
            INNER JOIN productos P ON P.idproducto = DP.IDPRODUCTO
            WHERE PE.ESTADO='0'
              AND MONTH(PE.FECHA)='$Mes'
              AND YEAR(PE.FECHA)='$Anio'
        ) T
    ";
    $rMes = $mysqli->query($sqlMes)->fetch_assoc();

    return [
        'bodega'   => (float)$valorBodega,
        'ventaDia' => (float)$ventaDia,
        'ventaMes' => (float)$rMes['venta_mes'],
        'utilMes'  => (float)$rMes['utilidad_mes']
    ];
}

/* =====================================================
   CALCULAR CENTRAL Y DRINKS
===================================================== */
$central = calcularSucursal($mysqliCentral, $FechaHoy, $Mes, $Anio);
$drinks  = calcularSucursal($mysqliDrinks,  $FechaHoy, $Mes, $Anio);

/* =====================================================
   TOTALES CONSOLIDADOS
===================================================== */
$total = [
    'bodega'   => $central['bodega']   + $drinks['bodega'],
    'ventaDia' => $central['ventaDia'] + $drinks['ventaDia'],
    'ventaMes' => $central['ventaMes'] + $drinks['ventaMes'],
    'utilMes'  => $central['utilMes']  + $drinks['utilMes']
];

/* =====================================================
   FUNCIÓN INSERTAR REGISTRO
===================================================== */
function insertarSnapshot($mysqli, $FechaHoy, $Nit, $Sucursal, $data, $FechaHora) {

    $chk = $mysqli->query("
        SELECT 1
        FROM fechainventariofisico
        WHERE fecha='$FechaHoy'
          AND sucursal='$Sucursal'
        LIMIT 1
    ");
    if ($chk->num_rows > 0) {
        return;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO fechainventariofisico
        (fecha, Nit_Empresa, sucursal, valor_bodega, venta_dia, venta_mes, utilidad_mes, created_at)
        VALUES (?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "sssdddds",
        $FechaHoy,
        $Nit,
        $Sucursal,
        $data['bodega'],
        $data['ventaDia'],
        $data['ventaMes'],
        $data['utilMes'],
        $FechaHora
    );

    $stmt->execute();
    $stmt->close();
}

/* =====================================================
   INSERTAR CENTRAL, DRINKS Y TOTAL
===================================================== */
insertarSnapshot($mysqliCentral, $FechaHoy, $NitSesion, 'CENTRAL', $central, $FechaHora);
insertarSnapshot($mysqliDrinks,  $FechaHoy, $NitSesion, 'DRINKS',  $drinks,  $FechaHora);
insertarSnapshot($mysqliCentral, $FechaHoy, $NitSesion, 'TOTAL',   $total,   $FechaHora);

/* =====================================================
   SALIDA
===================================================== */
echo "<h3>✔ Inventario físico consolidado</h3>";
echo "Fecha: $FechaHoy<br>";
echo "Hora Bogotá: $FechaHora<br><br>";

foreach (['CENTRAL'=>$central,'DRINKS'=>$drinks,'TOTAL'=>$total] as $s=>$v) {
    echo "<b>$s</b><br>";
    echo "Valor bodega: $".number_format($v['bodega'],0,',','.')."<br>";
    echo "Venta día: $".number_format($v['ventaDia'],0,',','.')."<br>";
    echo "Venta mes: $".number_format($v['ventaMes'],0,',','.')."<br>";
    echo "Utilidad mes: $".number_format($v['utilMes'],0,',','.')."<br><br>";
}
