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

/* ============================================================
    FUNCIÓN DE PERMISOS
============================================================ */
function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 
$permiso7777 = Autorizacion($UsuarioSesion, '7777'); 
$permiso0003 = Autorizacion($UsuarioSesion, '0003'); 

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha       = str_replace('-', '', $fecha_input); 
$UsuarioFact = trim($_GET['nit'] ?? '');

if($permiso9999 !== 'SI' && $permiso0003 !== 'SI') {
    $UsuarioFact = $UsuarioSesion;
}

$fecha_esc       = $mysqliActiva->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliActiva->real_escape_string($UsuarioFact);

/* ============================================================
    QUERIES DE DATOS
============================================================ */
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
$yaExisteTransferEnEgresos = false;

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

    $resE = $mysqliActiva->query("SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
        INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
        WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'");
    if($resE){ 
        while($eg=$resE->fetch_assoc()){ 
            $totalEgresos += (float)$eg['VALOR']; 
            $listaEgresos[] = $eg; 
            if (stripos($eg['MOTIVO'], 'TRANSFERENCIA') !== false) {
                $yaExisteTransferEnEgresos = true;
            }
        } 
    }

    $resT = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$UsuarioFact_esc'");
    $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);
}

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

$efectivo_sin_transfer =  $totalEgresos-$totalVentas ; 

if ($yaExisteTransferEnEgresos) {
    $efectivo_neto_final = $efectivo_sin_transfer; 
} else {
    $efectivo_neto_final = $efectivo_sin_transfer - $totalTransfer;
}

$ocultarValores = ($permiso0003 !== 'SI' && $permiso9999 !== 'SI');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Corte de Caja - <?= $nombre_sede_display ?></title>
    <style>
        body{font-family:"Segoe UI",sans-serif; margin:20px; background:#eef3f7; color:#333;}
        .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
        .table{width:100%; border-collapse:collapse;}
        .table td, .table th{padding:10px; border-bottom:1px solid #eee; text-align: left;}
        .button{padding:10px 20px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold;}
        .btn-save{background:#0b63a3; color:#fff; border:none; padding:8px 12px; border-radius:4px; cursor:pointer;}
        .text-end{ text-align: right; }
        .input-edit { width: 90%; padding: 5px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); }
        .modal-content { background: white; margin: 2% auto; padding: 15px; width: 98%; max-width: 420px; border-radius: 12px; }

        /* AJUSTES DE IMPRESIÓN REFORZADOS */
        @media print {
            @page { margin: 0; }
            body * { visibility: hidden !important; }
            #modalVoucher, #modalVoucher * { visibility: visible !important; }
            #modalVoucher { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; background: #fff !important; display: block !important; }
            
            #printArea { 
                width: 95% !important; 
                margin: 0 !important; 
                padding: 1mm 2mm !important; 
                color: #000 !important; 
                font-family: Arial, sans-serif !important; 
                font-weight: 900 !important; /* Negrita máxima */
                line-height: 1.2 !important;
            }
            .ticket-header h2 { font-size: 18px !important; margin: 0 !important; padding-bottom: 2px !important; font-weight: 900 !important; text-transform: uppercase; }
            .ticket-header p { font-size: 12px !important; margin: 2px 0 !important; font-weight: 900 !important; }
            
            .ticket-table { width: 100% !important; border-collapse: collapse !important; font-size: 13px !important; }
            .ticket-table td { padding: 3px 0 !important; font-weight: 900 !important; color: #000 !important; }
            
            hr { border: none !important; border-top: 2px solid #000 !important; margin: 5px 0 !important; opacity: 1 !important; }
            .no-print { display: none !important; }
            b { font-weight: 900 !important; }
        }
    </style>
</head>
<body>

<div class="panel no-print">
    <form method="GET" style="display:flex; flex-wrap:wrap; gap:15px; align-items:center;">
        <div>Sede: <select name="sede" onchange="this.form.submit()" style="padding:5px;">
            <option value="central" <?= ($sede_actual==='central'?'selected':'') ?>>CENTRAL</option>
            <option value="drinks" <?= ($sede_actual==='drinks'?'selected':'') ?>>DRINKS (AWS)</option>
        </select></div>
        <div>Fecha: <input type="date" name="fecha" value="<?= $fecha_input ?>" style="padding:4px;"></div>
        <div>Facturador: <select name="nit" style="padding:5px; min-width:200px;">
            <?php if($ocultarValores): ?>
                <option value="<?= $UsuarioSesion ?>"><?= $UsuarioSesion ?> (Yo)</option>
            <?php else: ?>
                <option value="">-- Seleccione Usuario --</option>
                <?php if($factList): while($f=$factList->fetch_assoc()): ?>
                    <option value="<?= $f['FACTURADOR_NIT'] ?>" <?= ($f['FACTURADOR_NIT']===$UsuarioFact)?'selected':'' ?>><?= $f['FACTURADOR'] ?></option>
                <?php endwhile; endif; ?>
            <?php endif; ?>
        </select></div>
        <button class="button" type="submit">Consultar Caja</button>
    </form>
</div>

<?php if($UsuarioFact !== ''): ?>
    <div class="panel no-print">
        <h3>📊 Resumen: <?= htmlspecialchars($nombreCompleto) ?></h3>
        <table class="table" style="max-width: 500px;">
            <tr><td>(+) Ventas Brutas:</td><td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($totalVentas) ?></b></td></tr>
            <tr><td>(-) Egresos:</td><td class="text-end" style="color:red;">$ <?= money($totalEgresos) ?></td></tr>
            <tr style="background:#f9f9f9;"><td><b>(=) EFECTIVO SIN TRANSF:</b></td><td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($efectivo_sin_transfer) ?></b></td></tr>
            <tr><td>(-) Transferencias (Ref):</td><td class="text-end" style="color:blue;">$ <?= money($totalTransfer) ?></td></tr>
            <tr style="font-size:1.4em; border-top:2px solid #333; background:#fff3cd;">
                <td><b>TOTAL FÍSICO EN CAJA:</b></td>
                <td class="text-end"><b><?= $ocultarValores ? '***' : '$ '.money($efectivo_neto_final) ?></b></td>
            </tr>
            <?php if($yaExisteTransferEnEgresos): ?>
                <tr><td colspan="2" style="font-size:10px; color:green;">* Transferencias ya incluidas en Egresos.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel no-print">
        <h3>💸 Egresos de Caja</h3>
        <table class="table">
            <thead><tr style="background:#f1f1f1;"><th>ID</th><th>Motivo</th><th class="text-end">Valor</th><th>Acción</th></tr></thead>
            <tbody>
                <?php foreach($listaEgresos as $eg): $idE = $eg['IDSALIDA']; ?>
                <tr>
                    <td><?= $idE ?></td>
                    <td><?= ($permiso9999 === 'SI') ? "<input type='text' id='motivo_$idE' class='input-edit' value='".htmlspecialchars($eg['MOTIVO'])."'>" : $eg['MOTIVO'] ?></td>
                    <td class="text-end"><?= ($permiso9999 === 'SI') ? "<input type='number' id='valor_$idE' class='input-edit text-end' value='{$eg['VALOR']}'>" : "$".money($eg['VALOR']) ?></td>
                    <td style="text-align:center;"><?= ($permiso9999 === 'SI') ? "<button class='btn-save' onclick='guardarEgreso($idE)'>💾</button>" : "-" ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel no-print" style="text-align:center;">
        <button class="button" style="background:#f39c12;" onclick="mostrarVoucher('precierre')">📋 Ver Precierre</button>
        <button class="button" style="background:#d32f2f;" onclick="mostrarVoucher('cierre')">🔒 Cierre Definitivo</button>
    </div>

    <div id="modalVoucher" class="modal"><div class="modal-content" id="printArea"></div></div>
<?php endif; ?>

<script>
    function mostrarVoucher(tipo) {
        if(tipo === 'cierre' && '<?= $permiso7777 ?>' !== 'SI' && '<?= $permiso9999 ?>' !== 'SI') {
            alert('ACCESO DENEGADO PARA CIERRE DEFINITIVO'); return;
        }
        let egresosHtml = "";
        <?php foreach($listaEgresos as $e): ?>
            egresosHtml += `<tr><td style="padding:1px; max-width:140px; overflow:hidden;">- <?= strtoupper(substr($e['MOTIVO'],0,20)) ?></td><td style="text-align:right;"><b>$<?= money($e['VALOR']) ?></b></td></tr>`;
        <?php endforeach; ?>

        const titulo = (tipo === 'precierre') ? 'PRECIERRE' : 'CIERRE FINAL';
        const horaImpresion = '<?= date("h:i a") ?>';
        
        let html = `
            <div class="ticket-header" style="text-align:center;">
                <h2 style="margin:0;"><b>${titulo}</b></h2>
                <p style="margin:0;"><b>SEDE: <?= strtoupper($nombre_sede_display) ?></b></p>
                <p style="margin:0;">FECHA: <?= $fecha_input ?> | ${horaImpresion}</p>
                <p style="margin:0;">CAJERO: <?= strtoupper(substr($nombreCompleto, 0, 25)) ?></p>
                <hr>
            </div>
            <table class="ticket-table">
                <tr><td>VENTAS BRUTAS:</td><td style="text-align:right;"><b>$<?= $ocultarValores ? '***' : money($totalVentas) ?></b></td></tr>
                <tr><td>(-) EGRESOS:</td><td style="text-align:right;"><b>$<?= money($totalEgresos) ?></b></td></tr>
                <tr style="border-top:1px dashed #000;">
                    <td>EFECT. BRUTO:</td>
                    <td style="text-align:right;"><b>$<?= $ocultarValores ? '***' : money($efectivo_sin_transfer) ?></b></td>
                </tr>
                <tr><td>(-) TRANSFER:</td><td style="text-align:right;"><b>$<?= money($totalTransfer) ?></b></td></tr>
                <tr><td colspan="2"><hr></td></tr>
                <tr style="font-size:16px;">
                    <td><b>TOTAL FÍSICO:</b></td>
                    <td style="text-align:right;"><b>$<?= $ocultarValores ? '***' : money($efectivo_neto_final) ?></b></td>
                </tr>
            </table>
            <div style="margin-top:10px; font-size:12px; font-weight:900; border-bottom:2px solid #000; text-transform: uppercase;">Detalle Egresos</div>
            <table class="ticket-table" style="font-size:11px;">${egresosHtml}</table>
            
            <div style="margin-top:40px; display:flex; justify-content:space-between; font-size:11px;">
                <div style="border-top:2px solid #000; width:45%; text-align:center; padding-top:4px;"><b>FIRMA CAJERO</b></div>
                <div style="border-top:2px solid #000; width:45%; text-align:center; padding-top:4px;"><b>SUPERVISOR</b></div>
            </div>

            <div class="no-print" style="margin-top:20px;">
                <button class="button" style="background:#2ecc71; width:100%; font-size:18px;" onclick="window.print()">🖨 IMPRIMIR</button>
                <button class="button" style="background:#7f8c8d; width:100%; margin-top:10px;" onclick="document.getElementById('modalVoucher').style.display='none'">Cerrar</button>
            </div>
        `;
        document.getElementById('printArea').innerHTML = html;
        document.getElementById('modalVoucher').style.display = 'block';
    }

    function guardarEgreso(id){
        const mot = document.getElementById('motivo_'+id).value;
        const val = document.getElementById('valor_'+id).value;
        if(!confirm('¿Desea actualizar este egreso?')) return;
        fetch('update_egreso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}&motivo=${encodeURIComponent(mot)}&valor=${encodeURIComponent(val)}&sede=<?= $sede_actual ?>`
        }).then(r => r.text()).then(t => { alert(t); location.reload(); });
    }
</script>
</body>
</html>