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

require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

$sede_actual = $_GET['sede'] ?? 'central';
if ($sede_actual === 'drinks') {
    if ($mysqliDrinks->connect_error) die("Error Sede Drinks: " . $mysqliDrinks->connect_error);
    $mysqliActiva = $mysqliDrinks;
    $nombre_sede_display = "DRINKS (AWS)";
} else {
    $mysqliActiva = $mysqliCentral;
    $nombre_sede_display = "CENTRAL";
}

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout)) {
    session_unset(); session_destroy();
    header("Location: Login.php?msg=Sesion expirada"); exit;
}
$_SESSION['ultimo_acceso'] = time();

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if ($UsuarioSesion === '') { header("Location: Login.php?msg=Debe iniciar sesion"); exit; }

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 
$permiso1700 = Autorizacion($UsuarioSesion, '1700'); 

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha       = str_replace('-', '', $fecha_input); 
$UsuarioFact = trim($_GET['nit'] ?? '');
if($permiso9999 !== 'SI') $UsuarioFact = $UsuarioSesion;

$fecha_esc       = $mysqliActiva->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliActiva->real_escape_string($UsuarioFact);

// Query de Facturadores
$qryFacturadores = "SELECT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS FACTURADOR FROM FACTURAS F 
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
    UNION 
    SELECT V.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS FACTURADOR FROM PEDIDOS P 
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
) X GROUP BY FACTURADOR_NIT ORDER BY FACTURADOR ASC";
$factList = $mysqliActiva->query($qryFacturadores);

$totalVentas = 0; $nombreCompleto = ""; $totalEgresos = 0; $totalTransfer = 0;
$listaEgresos = [];

if($UsuarioFact !== ''){
    $qryV = "SELECT SUM(T) AS TOTAL, NOM FROM (
        SELECT (DF.CANTIDAD*DF.VALORPROD) AS T, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOM FROM FACTURAS F 
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc' 
        UNION ALL 
        SELECT (DP.CANTIDAD*DP.VALORPROD), CONCAT_WS(' ', V.nombres, V.apellidos) FROM PEDIDOS P 
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$UsuarioFact_esc'
    ) X GROUP BY NOM";
    $resV = $mysqliActiva->query($qryV);
    if($vRow = $resV->fetch_assoc()){ $totalVentas = (float)$vRow['TOTAL']; $nombreCompleto = $vRow['NOM']; }

    $resEgresos = $mysqliActiva->query("SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
        WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'");
    if($resEgresos){ while($eg=$resEgresos->fetch_assoc()){ $totalEgresos += (float)$eg['VALOR']; $listaEgresos[] = $eg; } $resEgresos->data_seek(0); }

    $resT = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$UsuarioFact_esc'");
    $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);
}

function money($v){ return number_format((float)$v, 0, ',', '.'); }
$saldo_efectivo = $totalVentas - ($totalEgresos + $totalTransfer);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte - <?=$nombre_sede_display?></title>
    <style>
        body{font-family:"Segoe UI",sans-serif; margin:20px; background:#eef3f7; color:#333;}
        .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
        .table{width:100%; border-collapse:collapse;}
        .table th{background:#1f2d3d; color:#fff; padding:8px; text-align:left;}
        .table td{padding:8px; border-bottom:1px solid #eee;}
        .button{padding:10px 15px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold;}
        .text-end{ text-align: right; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); overflow: auto; }
        .modal-content { background: white; margin: 2% auto; padding: 25px; width: 90%; max-width: 500px; border-radius: 12px; }
        
        .firmas-container { margin-top: 40px; display: flex; justify-content: space-between; }
        .firma-box { border-top: 1.5px solid #000; width: 45%; text-align: center; padding-top: 5px; font-size: 0.9em; font-weight: bold; }

        @media print {
            @page { margin: 0; }
            body { margin: 0; padding: 0; background: #fff; }
            body * { visibility: hidden; }
            .print-area, .print-area * { visibility: visible; color: #000 !important; }
            .print-area { 
                position: absolute; 
                left: -15px !important; 
                top: 0; 
                width: 270px; 
                margin: 0;
                padding: 5px;
                font-size: 10px; 
                font-family: Arial, sans-serif;
            }
            .table { width: 100% !important; margin-left: 0 !important; }
            .table td, .table th { border-bottom: 1px solid #000 !important; color: #000 !important; padding: 3px 0; }
            .no-print { display: none !important; }
        }
    </style>
    <script>
        function abrirModal(id) { document.getElementById(id).style.display = 'block'; }
        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
        function ejecutarCierreDefinitivo() {
            if('<?=$permiso9999?>' !== 'SI') {
                alert('ACCESO DENEGADO: Solo usuarios con autorización 9999 pueden realizar el cierre definitivo.');
                return;
            }
            abrirModal('modalCierre');
        }
    </script>
</head>
<body>

<div class="panel no-print">
    <form method="GET">
        Sede: <select name="sede" onchange="this.form.submit()">
            <option value="central" <?=($sede_actual==='central'?'selected':'')?>>Sede Central</option>
            <option value="drinks" <?=($sede_actual==='drinks'?'selected':'')?>>Sede Drinks (AWS)</option>
        </select>
        &nbsp; Fecha: <input type="date" name="fecha" value="<?=$fecha_input?>">
        &nbsp; Facturador: <select name="nit">
            <option value="">-- Seleccione --</option>
            <?php if($factList): while($f=$factList->fetch_assoc()): ?>
                <option value="<?=$f['FACTURADOR_NIT']?>" <?=($f['FACTURADOR_NIT']===$UsuarioFact)?'selected':''?>><?=$f['FACTURADOR']?></option>
            <?php endwhile; endif; ?>
        </select>
        <button class="button" type="submit">Consultar</button>
    </form>
</div>

<?php if($UsuarioFact !== ''): ?>
    <div class="panel no-print">
        <h3>💰 Ventas - <?=htmlspecialchars($nombreCompleto)?></h3>
        <table class="table">
            <tr><th>NIT</th><th>Nombre</th><th class="text-end">Total</th></tr>
            <tr>
                <td><?=$UsuarioFact?></td>
                <td><?=htmlspecialchars($nombreCompleto)?></td>
                <td class="text-end"><b><?= ($permiso9999 === 'SI') ? "$".money($totalVentas) : "[OCULTO]" ?></b></td>
            </tr>
        </table>
    </div>

    <div class="panel no-print">
        <h3>📄 Egresos</h3>
        <table class="table">
            <thead><tr><th>ID</th><th>Motivo</th><th class="text-end">Valor</th></tr></thead>
            <tbody>
                <?php foreach($listaEgresos as $e): ?>
                    <tr><td><?=$e['IDSALIDA']?></td><td><?=$e['MOTIVO']?></td><td class="text-end">$<?=money($e['VALOR'])?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel no-print">
        <h3>📊 Resumen de Caja</h3>
        <table class="table" style="max-width: 400px;">
            <tr><td>(+) Ventas Brutas:</td><td class="text-end"><?= ($permiso9999 === 'SI') ? "$".money($totalVentas) : "[OCULTO]" ?></td></tr>
            <tr><td>(-) Egresos:</td><td class="text-end" style="color:red;">$<?=money($totalEgresos)?></td></tr>
            <tr><td>(-) Transferencias:</td><td class="text-end" style="color:blue;">$<?=money($totalTransfer)?></td></tr>
            <tr style="font-size:1.2em; border-top:2px solid #333;">
                <td><b>TOTAL EFECTIVO:</b></td>
                <td class="text-end"><b><?= ($permiso9999 === 'SI') ? "$".money($saldo_efectivo) : "[OCULTO]" ?></b></td>
            </tr>
        </table>
    </div>

    <div class="panel no-print">
        <button class="button" style="background:#f39c12;" onclick="abrirModal('modalPrecierre')">📋 Generar Precierre</button>
        <button class="button" style="background:#d32f2f;" onclick="ejecutarCierreDefinitivo()">🔒 Cierre Definitivo</button>
    </div>

    <div id="modalPrecierre" class="modal">
        <div class="modal-content print-area">
            <div style="text-align:center; border-bottom:1px dashed #000; padding-bottom:10px;">
                <h2 style="margin:5px;">VOUCHER DE PRECIERRE</h2>
                <p style="margin:2px;"><b>Sede:</b> <?=$nombre_sede_display?> | <b>Fecha:</b> <?=$fecha_input?></p>
                <p style="margin:2px;"><b>Facturador:</b> <?=$nombreCompleto?></p>
            </div>
            <h4 style="margin-top:15px;">📊 RESUMEN</h4>
            <table class="table">
                <tr><td>Ventas Brutas:</td><td class="text-end"><b><?= ($permiso9999 === 'SI') ? "$".money($totalVentas) : "[OCULTO]" ?></b></td></tr>
                <tr><td>Total Egresos:</td><td class="text-end">-$<?=money($totalEgresos)?></td></tr>
                <tr><td>Transferencias:</td><td class="text-end">-$<?=money($totalTransfer)?></td></tr>
                <tr style="font-size:1.2em; border-top:1px solid #000;"><td><b>EFECTIVO EN CAJA:</b></td><td class="text-end"><b>$<?=money($saldo_efectivo)?></b></td></tr>
            </table>
            
            <div class="firmas-container">
                <div class="firma-box">Firma del Cajero</div>
                <div class="firma-box">Firma del Supervisor</div>
            </div>

            <div class="no-print" style="margin-top:20px; text-align:right;">
                <button class="button" style="background:#2ecc71;" onclick="window.print()">🖨️ Imprimir</button>
                <button class="button" style="background:#7f8c8d;" onclick="cerrarModal('modalPrecierre')">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="modalCierre" class="modal">
        <div class="modal-content print-area">
            <div style="text-align:center; border-bottom:2px solid #d32f2f; padding-bottom:10px;">
                <h2 style="margin:5px; color:#d32f2f;">CIERRE DEFINITIVO DE CAJA</h2>
                <p style="margin:2px;"><b>Sede:</b> <?=$nombre_sede_display?> | <b>Fecha:</b> <?=$fecha_input?></p>
                <p style="margin:2px;"><b>Facturador:</b> <?=$nombreCompleto?></p>
            </div>
            <h4 style="margin-top:15px;">📊 RESUMEN FINAL</h4>
            <table class="table">
                <tr><td>Ventas Brutas:</td><td class="text-end"><b>$<?=money($totalVentas)?></b></td></tr>
                <tr><td>Total Egresos:</td><td class="text-end">-$<?=money($totalEgresos)?></td></tr>
                <tr><td>Transferencias:</td><td class="text-end">-$<?=money($totalTransfer)?></td></tr>
                <tr style="font-size:1.2em; border-top:1px solid #000; background:#eee;"><td><b>EFECTIVO A ENTREGAR:</b></td><td class="text-end"><b>$<?=money($saldo_efectivo)?></b></td></tr>
            </table>

            <div class="firmas-container">
                <div class="firma-box">Firma del Cajero</div>
                <div class="firma-box">Firma del Supervisor</div>
            </div>

            <div class="no-print" style="margin-top:20px; text-align:right;">
                <button class="button" style="background:#2ecc71;" onclick="window.print()">🖨️ Confirmar e Imprimir</button>
                <button class="button" style="background:#7f8c8d;" onclick="cerrarModal('modalCierre')">Cancelar</button>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>