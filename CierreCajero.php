<?php
/* ============================================================
   CONFIGURACIÓN DE SESIÓN
============================================================ */
$session_timeout  = 3600;
$inactive_timeout = 1800;

ini_set('session.gc_maxlifetime', $session_timeout);
session_set_cookie_params($session_timeout);

session_start();
session_regenerate_id(true);

require("ConnCentral.php"); // $mysqliPos
require("Conexion.php");    // $mysqli

/* ============================================================
   CONTROL DE INACTIVIDAD
============================================================ */
if (isset($_SESSION['ultimo_acceso'])) {
    if (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout) {
        session_unset();
        session_destroy();
        header("Location: Login.php?msg=Sesion expirada");
        exit;
    }
}
$_SESSION['ultimo_acceso'] = time();

/* ============================================================
   VARIABLES DE SESIÓN
============================================================ */
$UsuarioSesion   = $_SESSION['Usuario']    ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

if ($UsuarioSesion === '') {
    header("Location: Login.php?msg=Debe iniciar sesion");
    exit;
}

/* ============================================================
   AUTORIZACIÓN
============================================================ */
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
   FILTROS Y TIEMPO
============================================================ */
$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$hora_actual = date('H:i:s'); // Hora de carga para el Cierre
$fecha       = str_replace('-', '', $fecha_input); 
$UsuarioFact = trim($_GET['nit'] ?? '');

if($permiso9999 !== 'SI') {
    $UsuarioFact = $UsuarioSesion;
}

$fecha_esc       = $mysqliPos->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliPos->real_escape_string($UsuarioFact);

/* ============================================================
   PROCESAMIENTO DE DATOS (VENTAS, EGRESOS, TRANSF)
============================================================ */
// ... (Se mantiene la lógica de consultas SQL igual a tu código base)
$qryFacturadores = "SELECT DISTINCT FACTURADOR_NIT, FACTURADOR FROM (SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.nombre2, T1.apellidos, T1.apellido2) AS FACTURADOR FROM FACTURAS F INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' UNION SELECT V.NIT, CONCAT_WS(' ', V.nombres, V.nombre2, V.apellidos, V.apellido2) FROM PEDIDOS P INNER JOIN USUVENDEDOR U ON U.IDUSUARIO=P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO=U.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc') X ORDER BY FACTURADOR";
$factList = $mysqliPos->query($qryFacturadores);

$totalVentas = 0; $nombreCompleto = ""; $totalEgresos = 0; $totalTransfer = 0;
if($UsuarioFact !== ''){
    $qryV = "SELECT SUM(T) AS TOTAL, NOM FROM (SELECT (DF.CANTIDAD*DF.VALORPROD) AS T, CONCAT_WS(' ', T1.nombres, T1.nombre2, T1.apellidos, T1.apellido2) AS NOM FROM FACTURAS F INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc' UNION ALL SELECT (DP.CANTIDAD*DP.VALORPROD), CONCAT_WS(' ', V.nombres, V.nombre2, V.apellidos, V.apellido2) FROM PEDIDOS P INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$UsuarioFact_esc') X GROUP BY NOM";
    $resV = $mysqliPos->query($qryV);
    if($vRow = $resV->fetch_assoc()){ $totalVentas = (float)$vRow['TOTAL']; $nombreCompleto = $vRow['NOM']; }
    $qryE = "SELECT S1.IDSALIDA, MOTIVO, VALOR FROM SALIDASCAJA S1 INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'";
    $resEgresos = $mysqliPos->query($qryE);
    if($resEgresos){ while($eg=$resEgresos->fetch_assoc()){ $totalEgresos += (float)$eg['VALOR']; } $resEgresos->data_seek(0); }
    $resT = $mysqli->query("SELECT SUM(Monto) AS total FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$UsuarioFact_esc'");
    $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);
}

function money($v){ return number_format((float)$v, 0, ',', '.'); }
$saldo = $totalVentas - $totalEgresos - $totalTransfer;
$saldo_color = ($saldo >= 0) ? '#0abf53' : '#d93025';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Corte por Facturador</title>
<style>
    body{font-family:"Segoe UI",sans-serif; margin:20px; background:#eef3f7;}
    .panel{background:#fff; padding:15px; border-radius:8px; margin-bottom:15px; box-shadow:0 2px 6px rgba(0,0,0,0.1);}
    .table{width:100%; border-collapse:collapse;}
    .table th{background:#1f2d3d; color:#fff; padding:8px; text-align:left;}
    .table td{padding:8px; border-bottom:1px solid #eee;}
    .button{padding:8px 12px; background:#1f2d3d; color:#fff; border:none; border-radius:6px; cursor:pointer;}
    .input-edit{width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;}
    .btn-save{background:#0b63a3; color:#fff; border:none; padding:5px 8px; border-radius:4px; cursor:pointer;}
    .stamp{font-size: 0.85em; color: #555; margin-bottom: 10px; font-style: italic;}
    @media print { .no-print { display: none; } }
</style>
<script>
function guardarEgreso(id){
    const mot=encodeURIComponent(document.getElementById('motivo_'+id).value);
    const val=encodeURIComponent(document.getElementById('valor_'+id).value);
    if(!confirm('¿Guardar cambios?')) return;
    fetch('update_egreso.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id+'&motivo='+mot+'&valor='+val})
    .then(r=>r.text()).then(t=>{alert(t); location.reload();});
}

function mostrarModalPrecierre(nombre, fechaMov, ventas, egresos, transfer, permiso){
    const ahora = new Date();
    const horaStr = ahora.getHours().toString().padStart(2, '0') + ':' + 
                    ahora.getMinutes().toString().padStart(2, '0') + ':' + 
                    ahora.getSeconds().toString().padStart(2, '0');
    
    document.getElementById('m-nombre').innerText = nombre;
    // Mostramos fecha del movimiento y hora actual del sistema
    document.getElementById('m-fecha').innerText = "Movimiento: " + fechaMov + " | Generado: " + horaStr;
    
    document.getElementById('m-v').innerText = (permiso === 'SI') ? "$" + ventas.toLocaleString('es-CO') : "XXXX";
    document.getElementById('m-e').innerText = "$" + egresos.toLocaleString('es-CO');
    document.getElementById('m-t').innerText = "$" + transfer.toLocaleString('es-CO');
    
    let s = ventas - egresos - transfer;
    let ds = document.getElementById('m-s');
    ds.innerText = "$" + s.toLocaleString('es-CO');
    ds.style.background = (s >= 0 ? '#0abf53' : '#d93025');
    ds.style.color = 'white';
    document.getElementById('modal-precierre').style.display='block';
}

function cerrarModal(){ document.getElementById('modal-precierre').style.display='none'; }
function imprimirModal(){
    const c=document.getElementById('modal-precierre-content').innerHTML;
    const o=document.body.innerHTML;
    document.body.innerHTML=c; window.print(); location.reload();
}
</script>
</head>
<body>

<div class="panel no-print">
    <form method="GET">
        Fecha: <input type="date" name="fecha" value="<?=$fecha_input?>">
        Facturador: 
        <select name="nit">
            <?php $factList->data_seek(0); while($f=$factList->fetch_assoc()): ?>
                <option value="<?=$f['FACTURADOR_NIT']?>" <?=($f['FACTURADOR_NIT']===$UsuarioFact)?'selected':''?>><?=$f['FACTURADOR_NIT']." - ".$f['FACTURADOR']?></option>
            <?php endwhile; ?>
        </select>
        <button class="button" type="submit">Consultar</button>
    </form>
</div>

<?php if($UsuarioFact !== ''): ?>
<div class="stamp">
    Cierre generado el: <?=date('Y-m-d')?> a las <?=$hora_actual?>
</div>

<div class="panel no-print">
    <button class="button" onclick="mostrarModalPrecierre('<?=addslashes($nombreCompleto)?>','<?=$fecha_input?>',<?=$totalVentas?>,<?=$totalEgresos?>,<?=$totalTransfer?>,'<?=$permiso9999?>')">📝 Ver Precierre</button>
    <button class="button" onclick="window.print()">✅ Imprimir Cierre</button>
</div>

<div class="panel">
    <h3>💰 Ventas - <?=htmlspecialchars($nombreCompleto)?></h3>
    <?php if($permiso9999==='SI'): ?>
        <table class="table">
            <tr><th>NIT</th><th>Nombre</th><th>Total</th></tr>
            <tr><td><?=$UsuarioFact?></td><td><?=htmlspecialchars($nombreCompleto)?></td><td><b>$<?=money($totalVentas)?></b></td></tr>
        </table>
    <?php else: ?>
        <p style="color:#888;">Información de ventas oculta.</p>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>📄 Egresos <?=($permiso1700==='SI'?'(Editable)':'')?></h3>
    <table class="table">
        <tr><th>ID</th><th>Motivo</th><th>Valor</th><th>Acción</th></tr>
        <?php if($resEgresos): while($eg=$resEgresos->fetch_assoc()): $id=$eg['IDSALIDA']; ?>
            <tr>
                <td><?=$id?></td>
                <td><?php if($permiso1700==='SI'): ?><input id="motivo_<?=$id?>" class="input-edit" value="<?=htmlspecialchars($eg['MOTIVO'])?>"><?php else: echo htmlspecialchars($eg['MOTIVO']); endif; ?></td>
                <td><?php if($permiso1700==='SI'): ?><input id="valor_<?=$id?>" class="input-edit" value="<?=$eg['VALOR']?>"><?php else: echo "$".money($eg['VALOR']); endif; ?></td>
                <td><?php if($permiso1700==='SI'): ?><button class="btn-save" onclick="guardarEgreso(<?=$id?>)">Guardar</button><?php else: echo "-"; endif; ?></td>
            </tr>
        <?php endwhile; endif; ?>
    </table>
</div>

<div class="panel">
    <h3>📊 Resumen</h3>
    <div>Transferencias: <b>$<?=money($totalTransfer)?></b></div>
    <div style="margin-top:10px; font-size:1.2em;">Saldo Total: <b style="color:<?=$saldo_color?>">$<?=money($saldo)?></b></div>
</div>
<?php endif; ?>

<div id="modal-precierre" class="no-print" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999;">
  <div id="modal-precierre-content" style="background:#fff;margin:10% auto;padding:20px;border-radius:10px;max-width:400px;position:relative;text-align:center;">
    <span style="position:absolute;top:10px;right:14px;font-size:22px;cursor:pointer;" onclick="cerrarModal()">&times;</span>
    <h2 style="margin-bottom:5px;">📝 Precierre</h2>
    <div id="m-fecha" style="font-size:0.85em;color:#d93025; font-weight:bold; margin-bottom:5px;"></div>
    <div id="m-nombre" style="font-weight:bold;margin:10px 0;padding-bottom:10px;border-bottom:1px solid #eee;text-transform:uppercase;"></div>
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="padding:8px;text-align:left;border-bottom:1px solid #eee;">Ventas</td><td id="m-v" style="padding:8px;text-align:right;border-bottom:1px solid #eee;">0</td></tr>
      <tr><td style="padding:8px;text-align:left;border-bottom:1px solid #eee;">Egresos de Caja</td><td id="m-e" style="padding:8px;text-align:right;border-bottom:1px solid #eee;">0</td></tr>
      <tr><td style="padding:8px;text-align:left;border-bottom:1px solid #eee;">Transferencias</td><td id="m-t" style="padding:8px;text-align:right;border-bottom:1px solid #eee;">0</td></tr>
      <tr><th style="padding:10px 8px;text-align:left;">SALDO A ENTREGAR</th><th id="m-s" style="padding:10px 8px;text-align:right;border-radius:4px;">0</th></tr>
    </table>
    <div style="margin-top:20px;">
      <button class="button" style="background:#0b63a3;" onclick="imprimirModal()">🖨 Imprimir</button>
      <button class="button" style="background:#888;" onclick="cerrarModal()">❌ Cerrar</button>
    </div>
  </div>
</div>

</body>
</html>