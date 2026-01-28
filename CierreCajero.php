<?php
/* ============================================================
    CONFIGURACIÓN DE SESIÓN Y CONEXIONES
============================================================ */
$session_timeout  = 3600;
$inactive_timeout = 2400;
date_default_timezone_set('America/Bogota');
ini_set('session.gc_maxlifetime', $session_timeout);
session_set_cookie_params($session_timeout);

session_start();
session_regenerate_id(true);

// --- Carga de Conexiones ---
require("ConnCentral.php"); // Provee $mysqliCentral
require("Conexion.php");    // Provee $mysqli (para autorizaciones)
require("ConnDrinks.php");  // Provee $mysqliDrinks (AWS)

/* ============================================================
    SELECCIÓN DE SEDE ACTIVA
============================================================ */
$sede_actual = $_GET['sede'] ?? 'central';

if ($sede_actual === 'drinks') {
    if ($mysqliDrinks->connect_error) {
        die("Error conectando a Sede Drinks: " . $mysqliDrinks->connect_error);
    }
    $mysqliActiva = $mysqliDrinks;
    $nombre_sede_display = "DRINKS (AWS)";
} else {
    $mysqliActiva = $mysqliCentral;
    $nombre_sede_display = "CENTRAL";
}

/* ============================================================
    CONTROL DE ACCESO Y AUTORIZACIÓN
============================================================ */
if (isset($_SESSION['ultimo_acceso'])) {
    if (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout) {
        session_unset(); session_destroy();
        header("Location: Login.php?msg=Sesion expirada"); exit;
    }
}
$_SESSION['ultimo_acceso'] = time();

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if ($UsuarioSesion === '') {
    header("Location: Login.php?msg=Debe iniciar sesion"); exit;
}

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    if (!isset($_SESSION['Autorizaciones'])) $_SESSION['Autorizaciones'] = [];
    $key = $User . '_' . $Solicitud;
    if (isset($_SESSION['Autorizaciones'][$key])) return $_SESSION['Autorizaciones'][$key];
    
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 
$permiso1700 = Autorizacion($UsuarioSesion, '1700'); 

/* ============================================================
    FILTROS Y PROCESAMIENTO
============================================================ */
$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha       = str_replace('-', '', $fecha_input); 
$UsuarioFact = trim($_GET['nit'] ?? '');

if($permiso9999 !== 'SI') {
    $UsuarioFact = $UsuarioSesion;
}

$fecha_esc       = $mysqliActiva->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliActiva->real_escape_string($UsuarioFact);

// Query de Facturadores
$qryFacturadores = "
SELECT DISTINCT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.nombre2, T1.apellidos, T1.apellido2) AS FACTURADOR
    FROM FACTURAS F
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
    WHERE F.ESTADO = '0' AND DV.IDFACTURA IS NULL AND F.FECHA = '$fecha_esc'
    UNION
    SELECT V.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', V.nombres, V.nombre2, V.apellidos, V.apellido2) AS FACTURADOR
    FROM PEDIDOS P
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO
    INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO
    WHERE P.ESTADO = '0' AND P.FECHA = '$fecha_esc'
) X
ORDER BY FACTURADOR;
";
$factList = $mysqliActiva->query($qryFacturadores);

$totalVentas = 0; $nombreCompleto = ""; $totalEgresos = 0; $totalTransfer = 0;

if($UsuarioFact !== ''){
    // Ventas
    $qryV = "SELECT SUM(T) AS TOTAL, NOM FROM (
        SELECT (DF.CANTIDAD*DF.VALORPROD) AS T, CONCAT_WS(' ', T1.nombres, T1.nombre2, T1.apellidos, T1.apellido2) AS NOM 
        FROM FACTURAS F 
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA 
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA
        WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc' 
        UNION ALL 
        SELECT (DP.CANTIDAD*DP.VALORPROD), CONCAT_WS(' ', V.nombres, V.nombre2, V.apellidos, V.apellido2) 
        FROM PEDIDOS P 
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO 
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO 
        WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$UsuarioFact_esc'
    ) X GROUP BY NOM";
    
    $resV = $mysqliActiva->query($qryV);
    if($vRow = $resV->fetch_assoc()){ 
        $totalVentas = (float)$vRow['TOTAL']; 
        $nombreCompleto = $vRow['NOM']; 
    }

    // Egresos
    $qryE = "SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
             INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO 
             INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
             WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'";
    $resEgresos = $mysqliActiva->query($qryE);
    if($resEgresos){ 
        while($eg=$resEgresos->fetch_assoc()){ $totalEgresos += (float)$eg['VALOR']; } 
        $resEgresos->data_seek(0); 
    }

    // Transferencias (Base Local siempre)
    $resT = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$UsuarioFact_esc'");
    $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);
}

function money($v){ return number_format((float)$v, 0, ',', '.'); }

// Lógica de Saldo Corregida: Lo que entró menos lo que salió (Egresos y Transferencias)
$saldo_efectivo =($totalEgresos - $totalTransfer)-$totalVentas;
$saldo_color = ($saldo_efectivo >= 0) ? '#0abf53' : '#d93025';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte - <?=$nombre_sede_display?></title>
    <style>
        body{font-family:"Segoe UI",sans-serif; margin:20px; background:#eef3f7;}
        .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
        .table{width:100%; border-collapse:collapse;}
        .table th{background:#1f2d3d; color:#fff; padding:8px; text-align:left;}
        .table td{padding:8px; border-bottom:1px solid #eee;}
        .button{padding:8px 12px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer;}
        .input-edit{width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;}
        .btn-save{background:#0b63a3; color:#fff; border:none; padding:5px 8px; border-radius:4px; cursor:pointer;}
        .badge-sede{ background: #0b63a3; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8em;}
        .text-end{ text-align: right; }
        @media print { .no-print { display: none; } }
    </style>
    <script>
        function guardarEgreso(id){
            const mot=encodeURIComponent(document.getElementById('motivo_'+id).value);
            const val=encodeURIComponent(document.getElementById('valor_'+id).value);
            if(!confirm('¿Guardar cambios?')) return;
            fetch('update_egreso.php',{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'id='+id+'&motivo='+mot+'&valor='+val+'&sede=<?=$sede_actual?>'
            })
            .then(r=>r.text()).then(t=>{alert(t); location.reload();});
        }
    </script>
</head>
<body>

<div class="panel no-print">
    <form method="GET">
        Sede:
        <select name="sede" onchange="this.form.submit()" style="font-weight: bold; color: #0b63a3;">
            <option value="central" <?=($sede_actual==='central'?'selected':'')?>>Sede Central</option>
            <option value="drinks" <?=($sede_actual==='drinks'?'selected':'')?>>Sede Drinks (AWS)</option>
        </select>
        &nbsp;&nbsp;
        Fecha: <input type="date" name="fecha" value="<?=$fecha_input?>">
        &nbsp;&nbsp;
        Facturador: 
        <select name="nit">
            <option value="">-- Seleccione --</option>
            <?php if($factList): $factList->data_seek(0); while($f=$factList->fetch_assoc()): ?>
                <option value="<?=$f['FACTURADOR_NIT']?>" <?=($f['FACTURADOR_NIT']===$UsuarioFact)?'selected':''?>><?=$f['FACTURADOR_NIT']." - ".$f['FACTURADOR']?></option>
            <?php endwhile; endif; ?>
        </select>
        <button class="button" type="submit">Consultar</button>
    </form>
</div>

<?php if($UsuarioFact !== ''): ?>
<div class="panel">
    <h3>💰 Ventas - <?=htmlspecialchars($nombreCompleto)?> <span class="badge-sede"><?=$nombre_sede_display?></span></h3>
    <?php if($permiso9999==='SI'): ?>
        <table class="table">
            <tr><th>NIT</th><th>Nombre</th><th class="text-end">Total</th></tr>
            <tr>
                <td><?=$UsuarioFact?></td>
                <td><?=htmlspecialchars($nombreCompleto)?></td>
                <td class="text-end"><b>$<?=money($totalVentas)?></b></td>
            </tr>
        </table>
    <?php else: ?>
        <p style="color:#888;">Información de ventas oculta por permisos.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>📄 Egresos <?=($permiso1700==='SI'?'(Editable)':'')?></h3>
    <table class="table">
        <thead>
            <tr><th>ID</th><th>Motivo</th><th class="text-end">Valor</th><th>Acción</th></tr>
        </thead>
        <tbody>
            <?php 
            if($resEgresos && $resEgresos->num_rows > 0): 
                while($eg = $resEgresos->fetch_assoc()): 
                    $id = $eg['IDSALIDA'];
                    $vEg = (float)$eg['VALOR'];
            ?>
                <tr>
                    <td><?=$id?></td>
                    <td>
                        <?php if($permiso1700==='SI'): ?>
                            <input id="motivo_<?=$id?>" class="input-edit" value="<?=htmlspecialchars($eg['MOTIVO'])?>">
                        <?php else: echo htmlspecialchars($eg['MOTIVO']); endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if($permiso1700==='SI'): ?>
                            <input id="valor_<?=$id?>" class="input-edit text-end" type="number" value="<?=$vEg?>">
                        <?php else: echo "$".money($vEg); endif; ?>
                    </td>
                    <td>
                        <?php if($permiso1700==='SI'): ?>
                            <button class="btn-save" onclick="guardarEgreso(<?=$id?>)">Guardar</button>
                        <?php else: echo "-"; endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="4" style="text-align:center; color: #999;">No hay egresos registrados</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h3>📊 Resumen de Caja</h3>
    <table class="table" style="max-width: 450px;">
        <tr>
            <td>(+) Ventas Brutas:</td>
            <td class="text-end"><b>$<?=money($totalVentas)?></b></td>
        </tr>
        <tr>
            <td>(-) Total Egresos Pagados:</td>
            <td class="text-end" style="color: #d93025;">$<?=money($totalEgresos)?></td>
        </tr>
        <tr>
            <td>(-) Ventas por Transferencia:</td>
            <td class="text-end" style="color: #0b63a3;">$<?=money($totalTransfer)?></td>
        </tr>
        <tr style="border-top: 2px solid #333; font-size: 1.3em;">
            <td><b>TOTAL EFECTIVO EN CAJA:</b></td>
            <td class="text-end"><b style="color:<?=$saldo_color?>">$<?=money($saldo_efectivo)?></b></td>
        </tr>
    </table>
</div>
<?php endif; ?>

</body>
</html>