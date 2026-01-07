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

/* ============================================================
   CONEXIONES
============================================================ */
require("ConnCentral.php"); // $mysqliPos
require("Conexion.php");    // $mysqli

/* ============================================================
   CONTROL DE INACTIVIDAD
============================================================ */
if (isset($_SESSION['ultimo_acceso'])) {
    if (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout) {
        session_unset();
        session_destroy();
        header("Location: Login.php?msg=Sesión expirada por inactividad");
        exit;
    }
}
$_SESSION['ultimo_acceso'] = time();

/* ============================================================
   VARIABLES DE SESIÓN
============================================================ */
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';

if ($UsuarioSesion === '') {
    header("Location: Login.php?msg=Debe iniciar sesión");
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
    $stmt->bind_param("ss",$User,$Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    $permiso = ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
    $_SESSION['Autorizaciones'][$key] = $permiso;
    $stmt->close();
    return $permiso;
}
$permiso9999 = Autorizacion($UsuarioSesion,'9999');

/* ============================================================
   FILTROS
============================================================ */
$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha       = str_replace('-','',$fecha_input); // YYYYMMDD
$UsuarioFact = trim($_GET['nit'] ?? '');
if($permiso9999!=='SI') $UsuarioFact = $UsuarioSesion; // si no tiene autorización, solo su facturador

/* ============================================================
   ESCAPES
============================================================ */
$fecha_esc       = $mysqliPos->real_escape_string($fecha);
$UsuarioFact_esc = $mysqliPos->real_escape_string($UsuarioFact);

/* ============================================================
   FACTURADORES CON MOVIMIENTO
============================================================ */
$qryFacturadores = "
SELECT DISTINCT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, T1.NOMBRES AS FACTURADOR
    FROM FACTURAS F
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR
    LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA=F.IDFACTURA
    WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc'
    UNION
    SELECT V.NIT, V.NOMBRES
    FROM PEDIDOS P
    INNER JOIN USUVENDEDOR U ON U.IDUSUARIO=P.IDUSUARIO
    INNER JOIN TERCEROS V ON V.IDTERCERO=U.IDTERCERO
    WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc'
) X
ORDER BY FACTURADOR
";
$factList = $mysqliPos->query($qryFacturadores) or die($mysqliPos->error);

/* ============================================================
   VENTAS DEL FACTURADOR
============================================================ */
$ventasRow=null;
$totalVentas = 0;
if($UsuarioFact!==''){
    $qryVentas = "
    SELECT FACTURADOR_NIT, FACTURADOR, SUM(TOTAL_LINEA) AS TOTAL
    FROM (
        SELECT 
            T1.NIT AS FACTURADOR_NIT,
            T1.NOMBRES AS FACTURADOR,
            (DF.CANTIDAD*DF.VALORPROD) AS TOTAL_LINEA
        FROM FACTURAS F
        INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR
        LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA=F.IDFACTURA
        WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'
        UNION ALL
        SELECT 
            V.NIT AS FACTURADOR_NIT,
            V.NOMBRES AS FACTURADOR,
            (DP.CANTIDAD*DP.VALORPROD) AS TOTAL_LINEA
        FROM PEDIDOS P
        INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO
        INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO
        WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$UsuarioFact_esc'
    ) X
    GROUP BY FACTURADOR_NIT, FACTURADOR
    ORDER BY FACTURADOR;
    ";
    $resV = $mysqliPos->query($qryVentas) or die($mysqliPos->error);
    $ventasRow = $resV->fetch_assoc();
    $totalVentas = $ventasRow ? (float)$ventasRow['TOTAL'] : 0;
}

/* ============================================================
   EGRESOS DEL FACTURADOR
============================================================ */
$resEgresos = null;
$totalEgresos=0;
if($UsuarioFact!==''){
    $qryEgresos = "
    SELECT S1.IDSALIDA, NOMBREPC, T1.NIT,
        CONCAT(T1.nombres,' ',T1.apellidos) AS USUARIO,
        MOTIVO, VALOR, TIPO
    FROM SALIDASCAJA S1
    INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO
    WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$UsuarioFact_esc'
    ORDER BY S1.IDSALIDA ASC
    ";
    $resEgresos = $mysqliPos->query($qryEgresos) or die($mysqliPos->error);
    if($resEgresos){
        while($eg=$resEgresos->fetch_assoc()){
            $totalEgresos += (float)$eg['VALOR'];
        }
        $resEgresos->data_seek(0); // reset pointer para mostrar tabla
    }
}

/* ============================================================
   TRANSFERENCIAS
============================================================ */
$totalTransfer=0;
if($UsuarioFact!==''){
    $fecha_esc_m = $mysqli->real_escape_string($fecha);
    $UsuarioFact_esc_m = $mysqli->real_escape_string($UsuarioFact);
    $qryTrans = "
        SELECT SUM(Monto) AS total_transfer
        FROM Relaciontransferencias
        WHERE Fecha='$fecha_esc_m' AND CedulaNit='$UsuarioFact_esc_m'
    ";
    $resT = $mysqli->query($qryTrans);
    if($resT!==false){
        $r = $resT->fetch_assoc();
        $totalTransfer = (float)($r['total_transfer']??0);
    }
}

/* ============================================================
   FUNCIONES
============================================================ */
function money($v){ return number_format((float)$v,0,',','.'); }

$saldo = $totalVentas - $totalEgresos - $totalTransfer; // ✅ saldo corregido
$saldo_color = ($saldo>=0)?'#0abf53':'#d93025';
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Corte por Facturador</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:"Segoe UI",Arial,sans-serif;margin:0;padding:20px;background:#eef3f7;}
.panel{background:#fff;padding:15px;border-radius:8px;margin-bottom:15px;box-shadow:0 2px 6px rgba(0,0,0,0.1);}
.table{width:100%;border-collapse:collapse;}
.table th{background:#1f2d3d;color:#fff;padding:8px;text-align:left;}
.table td{padding:8px;border-bottom:1px solid #eee;}
.button{padding:8px 12px;background:#1f2d3d;color:#fff;border:none;border-radius:6px;cursor:pointer;margin-right:6px;}
.input-edit{width:100%;padding:5px;border:1px solid #ccc;border-radius:4px;}
.btn-save{background:#0b63a3;color:#fff;border:none;padding:5px 8px;border-radius:4px;cursor:pointer;}
</style>
<script>
function toUpperCaseInput(ev){ ev.target.value=ev.target.value.toUpperCase(); }
function guardarEgreso(id){
    const motivo=encodeURIComponent(document.getElementById('motivo_'+id).value);
    const valor=encodeURIComponent(document.getElementById('valor_'+id).value);
    if(!confirm('Guardar cambios ID '+id)) return;
    fetch('update_egreso.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'id='+id+'&motivo='+motivo+'&valor='+valor
    }).then(r=>r.text()).then(t=>{alert(t);location.reload();});
}

// MODAL PRECierre
function mostrarModalPrecierre(ventas, egresos, transfer, permiso){
    let txtVentas = permiso==='SI' ? "$"+ventas.toLocaleString('es-CO') : "XXXX";
    let saldo = ventas - egresos - transfer;
    document.getElementById('modal-ventas').innerText = txtVentas;
    document.getElementById('modal-egresos').innerText = "$"+egresos.toLocaleString('es-CO');
    document.getElementById('modal-transfer').innerText = "$"+transfer.toLocaleString('es-CO');
    if(saldo!==null){
        document.getElementById('modal-saldo').innerText = "$"+saldo.toLocaleString('es-CO');
        document.getElementById('modal-saldo').style.backgroundColor = (saldo>=0)?'#0abf53':'#d93025';
        document.getElementById('modal-saldo').style.color='white';
    }else{
        document.getElementById('modal-saldo').innerText = "--";
        document.getElementById('modal-saldo').style.backgroundColor='#999';
        document.getElementById('modal-saldo').style.color='white';
    }
    document.getElementById('modal-precierre').style.display='block';
}
function cerrarModal(){ document.getElementById('modal-precierre').style.display='none'; }
function imprimirModal(){
    const content=document.getElementById('modal-precierre-content').innerHTML;
    const original=document.body.innerHTML;
    document.body.innerHTML=content;
    window.print();
    document.body.innerHTML=original;
    cerrarModal();
}
</script>
</head>
<body>

<div class="panel">
<form method="GET">
<label>Fecha:</label>
<input type="date" name="fecha" value="<?=htmlspecialchars($fecha_input)?>">
<label>Facturador:</label>
<select name="nit">
<option value="">-- Seleccione --</option>
<?php while($f=$factList->fetch_assoc()): 
$optVal=htmlspecialchars($f['FACTURADOR_NIT']);
$optLab=htmlspecialchars($f['FACTURADOR_NIT']." - ".$f['FACTURADOR']);
$sel=($optVal===$UsuarioFact)?'selected':'';
?>
<option value="<?=$optVal?>" <?=$sel?>><?=$optLab?></option>
<?php endwhile; ?>
</select>
<button class="button" type="submit">Consultar</button>
</form>
</div>

<?php if($UsuarioFact!==''): ?>
<div class="panel">
<button class="button" onclick="mostrarModalPrecierre(<?=$totalVentas?>,<?=$totalEgresos?>,<?=$totalTransfer?>,'<?=$permiso9999?>')">📝 Ver Precierre</button>
<button class="button" onclick="window.print()">✅ Imprimir Cierre Definitivo</button>
</div>

<!-- VENTAS -->
<?php if($permiso9999==='SI'): ?>
<div class="panel">
<h3>💰 Ventas</h3>
<table class="table">
<tr><th>NIT</th><th>Facturador</th><th>Total</th></tr>
<tr>
<td><?=htmlspecialchars($ventasRow['FACTURADOR_NIT']??'')?></td>
<td><?=htmlspecialchars($ventasRow['FACTURADOR']??'')?></td>
<td><b>$<?=money($totalVentas)?></b></td>
</tr>
</table>
</div>
<?php else: ?>
<div class="panel">
<h3>💰 Ventas</h3>
<div style="color:#888">No tiene autorización para ver las ventas acumuladas.</div>
</div>
<?php endif; ?>

<!-- EGRESOS -->
<div class="panel">
<h3>📄 Egresos <?=($permiso9999==='SI')?'(editable)':''?></h3>
<table class="table">
<tr><th>ID</th><th>Motivo</th><th>Valor</th><th>Acción</th></tr>
<?php
if($resEgresos && $resEgresos->num_rows>0){
while($eg=$resEgresos->fetch_assoc()){
    $id=(int)$eg['IDSALIDA'];
    $mot=htmlspecialchars($eg['MOTIVO']);
    $val=(float)$eg['VALOR'];
    $motivoUpper=strtoupper($eg['MOTIVO']);
    $rowStyle="";
    if(strpos($motivoUpper,'TRANSFER')!==false || strpos($motivoUpper,'TRANSF')!==false) $rowStyle="background:#fff7a8;";
    elseif(strpos($motivoUpper,'ENTREGA')!==false || strpos($motivoUpper,'EFECTIVO')!==false) $rowStyle="background:#c9f7c9;";
    echo "<tr style='$rowStyle'>";
    if($permiso9999==='SI'){
        echo "<td>$id</td>";
        echo "<td><input id='motivo_$id' class='input-edit' value=\"$mot\" oninput='toUpperCaseInput(event)'></td>";
        echo "<td><input id='valor_$id' class='input-edit' value=\"$val\"></td>";
        echo "<td><button class='btn-save' onclick='guardarEgreso($id); return false;'>Guardar</button></td>";
    }else{
        echo "<td>$id</td><td>$mot</td><td>$".money($val)."</td><td>-</td>";
    }
    echo "</tr>";
}
}else{
    echo "<tr><td colspan='4' style='color:#888'>No hay egresos</td></tr>";
}
?>
</table>
</div>

<!-- TRANSFERENCIAS -->
<div class="panel">
<h3>💳 Total Transferencias</h3>
<div><b>$<?=money($totalTransfer)?></b></div>
</div>

<!-- SALDO FINAL -->
<div class="panel">
<h3>📊 Resumen y Saldo</h3>
<div>Saldo final: <b style="color:<?=$saldo_color?>">$<?=money($saldo)?></b></div>
</div>

<?php endif; ?>

<!-- MODAL PRECierre -->
<div id="modal-precierre" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);">
  <div id="modal-precierre-content" style="background:#fff;margin:10% auto;padding:20px;border-radius:10px;max-width:400px;position:relative;">
    <span style="position:absolute;top:10px;right:14px;font-size:22px;font-weight:bold;cursor:pointer;" onclick="cerrarModal()">&times;</span>
    <h2>📝 Precierre</h2>
    <table style="width:100%;border-collapse:collapse;margin-top:10px;">
      <tr><td>Ventas</td><td id="modal-ventas">0</td></tr>
      <tr><td>Egresos</td><td id="modal-egresos">0</td></tr>
      <tr><td>Transferencias</td><td id="modal-transfer">0</td></tr>
      <tr><th>Saldo</th><th id="modal-saldo">0</th></tr>
    </table>
    <div style="margin-top:12px;">
      <button onclick="imprimirModal()">🖨 Imprimir Precierre</button>
      <button onclick="cerrarModal()">❌ Cerrar</button>
    </div>
  </div>
</div>

</body>
</html>
