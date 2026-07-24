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
require 'auth_check.php';
session_regenerate_id(true);

require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

$sede_actual = $_GET['sede'] ?? 'central';

if ($sede_actual === 'drinks') {
    if ($mysqliDrinks->connect_error) die("Error Sede Drinks: " . $mysqliDrinks->connect_error);
    $mysqliActiva = $mysqliDrinks;
    $nombre_sede_display = "DRINKS (AWS)";
    $nit_empresa_filtro = "901724534-7"; 
} else {
    $mysqliActiva = $mysqliCentral;
    $nombre_sede_display = "CENTRAL";
    $nit_empresa_filtro = "86057267-8";  
}

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout)) {
    header("Location: logout.php?msg=Sesion expirada");
    exit;
}
$_SESSION['ultimo_acceso'] = time();

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if ($UsuarioSesion === '') { 
    header("Location: logout.php?msg=Sesion expirada");
    exit; 
}

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
$fecha_esc   = $mysqliActiva->real_escape_string($fecha);

/* ============================================================
    QUERIES DE DATOS: OBTENER TODOS LOS FACTURADORES DEL DÍA
============================================================ */
$qryFacturadores = "SELECT FACTURADOR_NIT, FACTURADOR FROM (
    SELECT T1.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS FACTURADOR FROM FACTURAS F 
    INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
    UNION 
    SELECT V.NIT AS FACTURADOR_NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS FACTURADOR FROM PEDIDOS P 
    INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
) X GROUP BY FACTURADOR_NIT ORDER BY FACTURADOR ASC";
$resFactList = $mysqliActiva->query($qryFacturadores);

$listadoCajeros = [];
if ($resFactList) {
    while ($rowF = $resFactList->fetch_assoc()) {
        $nitCajero = $rowF['FACTURADOR_NIT'];
        $nombreCajero = $rowF['FACTURADOR'];
        $nitCajero_esc = $mysqliActiva->real_escape_string($nitCajero);

        // 1. Validar Cierre (Arqueo) para este cajero
        $cierreRealizado = false;
        $qryCheckCierre = "SELECT T2.NIT FROM ARQUEO AS A1
                            INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = A1.IDUSUARIO
                            INNER JOIN TERCEROS AS T2 ON T2.IDTERCERO = V1.IDTERCERO
                            WHERE DATE_FORMAT(A1.fechacie, '%Y-%m-%d') = '$fecha_input' 
                            AND T2.NIT = '$nitCajero_esc' LIMIT 1";
        $resCheck = $mysqliActiva->query($qryCheckCierre);
        if ($resCheck && $resCheck->num_rows > 0) { $cierreRealizado = true; }

        // 2. Ventas
        $totalVentas = 0;
        $qryV = "SELECT SUM(T) AS TOTAL FROM (
            SELECT (DF.CANTIDAD*DF.VALORPROD) AS T FROM FACTURAS F 
            INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA INNER JOIN TERCEROS T1 ON T1.IDTERCERO=F.IDVENDEDOR 
            LEFT JOIN DEVVENTAS DV ON DV.IDFACTURA = F.IDFACTURA WHERE F.ESTADO='0' AND DV.IDFACTURA IS NULL AND F.FECHA='$fecha_esc' AND T1.NIT='$nitCajero_esc' 
            UNION ALL 
            SELECT (DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
            INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO=P.IDUSUARIO 
            INNER JOIN TERCEROS V ON V.IDTERCERO=UV.IDTERCERO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND V.NIT='$nitCajero_esc'
        ) X";
        $resV = $mysqliActiva->query($qryV);
        if($vRow = $resV->fetch_assoc()){ $totalVentas = (float)($vRow['TOTAL'] ?? 0); }

        // 3. Egresos
        $totalEgresos = 0;
        $listaEgresos = [];
        $yaExisteTransferEnEgresos = false;
        $resE = $mysqliActiva->query("SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
            INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
            WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nitCajero_esc'");
        if($resE){ 
            while($eg = $resE->fetch_assoc()){ 
                $totalEgresos += (float)$eg['VALOR']; 
                $listaEgresos[] = $eg; 
                if (stripos($eg['MOTIVO'], 'TRANSFERENCIA') !== false || stripos($eg['MOTIVO'], 'TRANSFER') !== false) {
                    $yaExisteTransferEnEgresos = true;
                }
            } 
        }

        // 4. Transferencias Manuales
        $stmtT = $mysqli->prepare("SELECT SUM(Monto) AS total FROM Relaciontransferencias 
                                 WHERE Fecha = ? AND CedulaNit = ? AND NitEmpresa = ?");
        $stmtT->bind_param("sss", $fecha_input, $nitCajero, $nit_empresa_filtro);
        $stmtT->execute();
        $resT = $stmtT->get_result();
        $totalTransfer = (float)($resT->fetch_assoc()['total'] ?? 0);

        // 5. Transferencias Automáticas
        $stmtTA = $mysqli->prepare("SELECT SUM(n.monto) AS total_auto 
                                  FROM control_checks_nequi c
                                  INNER JOIN notificaciones_nequi n ON c.id_transferencia = n.id
                                  WHERE DATE(c.fecha_hora_check) = ? 
                                  AND c.usuario_cedula = ? 
                                  AND c.nit_empresa = ?");
        $stmtTA->bind_param("sss", $fecha_input, $nitCajero, $nit_empresa_filtro);
        $stmtTA->execute();
        $resTA = $stmtTA->get_result();
        $totalTransferAuto = (float)($resTA->fetch_assoc()['total_auto'] ?? 0);

        // Cálculo Total Físico
        if ($yaExisteTransferEnEgresos) {
            $efectivo_neto_final = $totalEgresos - $totalVentas;
        } else {
            $efectivo_neto_final = ($totalEgresos + $totalTransfer + $totalTransferAuto) - $totalVentas;
        }

        $ocultarValores = ($permiso0003 !== 'SI' && $permiso9999 !== 'SI' && !$cierreRealizado);

        $listadoCajeros[] = [
            'nit' => $nitCajero,
            'nombre' => $nombreCajero,
            'totalVentas' => $totalVentas,
            'totalEgresos' => $totalEgresos,
            'totalTransfer' => $totalTransfer,
            'totalTransferAuto' => $totalTransferAuto,
            'efectivo_neto_final' => $efectivo_neto_final,
            'listaEgresos' => $listaEgresos,
            'cierreRealizado' => $cierreRealizado,
            'ocultarValores' => $ocultarValores
        ];
    }
}

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja Global</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", sans-serif; margin: 15px; background: #eef3f7; color: #333; }
        .panel { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 15px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        .form-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .form-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 5px; }
        .form-group select, .form-group input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .row-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .table-responsive { width: 100%; overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table td, .table th { padding: 8px; border-bottom: 1px solid #eee; text-align: left; font-size: 13px; }
        .button { padding: 10px 20px; background: #1f2d3d; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-save { background: #0b63a3; color: #fff; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; }
        .text-end { text-align: right; }
        .input-edit { width: 100%; padding: 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); overflow-y: auto; padding: 10px; }
        .modal-content { background: white; margin: 20px auto; padding: 15px; width: 100%; max-width: 420px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        @media (max-width: 576px) {
            .form-group { min-width: 100%; }
            .button { width: 100%; }
        }
    </style>
</head>
<body>

<div class="panel no-print">
    <form method="GET" class="form-grid">
        <div class="form-group">
            <label>Sede:</label>
            <select name="sede" onchange="this.form.submit()">
                <option value="central" <?= ($sede_actual==='central'?'selected':'') ?>>CENTRAL</option>
                <option value="drinks" <?= ($sede_actual==='drinks'?'selected':'') ?>>DRINKS (AWS)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Fecha:</label>
            <input type="date" name="fecha" value="<?= $fecha_input ?>">
        </div>
        <button class="button" type="submit">Consultar</button>
    </form>
</div>

<div class="row-grid">
    <?php if(empty($listadoCajeros)): ?>
        <div class="panel" style="grid-column: 1 / -1; text-align: center;">
            <p>No se encontraron registros de facturadores para la fecha seleccionada.</p>
        </div>
    <?php else: foreach($listadoCajeros as $cajero): ?>
        <div class="panel">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 8px; margin-bottom: 10px;">
                <h3 style="margin: 0; font-size: 1.1em;">📊 <?= htmlspecialchars($cajero['nombre']) ?></h3>
                <div>
                    <?php if($cajero['cierreRealizado']): ?>
                        <span style="color: #d32f2f; font-weight: bold; font-size: 0.9em;">🔒 CERRADA</span>
                    <?php else: ?>
                        <span style="color: #2e7d32; font-weight: bold; font-size: 0.9em;">🔓 ABIERTA</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <tr><td>(+) Ventas Brutas:</td><td class="text-end"><b><?= $cajero['ocultarValores'] ? '***' : '$ '.money($cajero['totalVentas']) ?></b></td></tr>
                    <tr><td>(-) Egresos:</td><td class="text-end" style="color:red;">$ <?= money($cajero['totalEgresos']) ?></td></tr>
                    <tr><td>(-) Transfer. Manuales:</td><td class="text-end" style="color:blue;">$ <?= money($cajero['totalTransfer']) ?></td></tr>
                    <tr><td>(-) Transfer. Automáticas:</td><td class="text-end" style="color:purple;">$ <?= money($cajero['totalTransferAuto']) ?></td></tr>
                    <tr style="font-size:1.2em; border-top:2px solid #333; background:#fff3cd;">
                        <td><b>TOTAL FÍSICO:</b></td>
                        <td class="text-end"><b><?= $cajero['ocultarValores'] ? '***' : '$ '.money($cajero['efectivo_neto_final']) ?></b></td>
                    </tr>
                </table>
            </div>

            <h4 style="margin: 10px 0 5px 0; font-size: 0.95em;">💸 Egresos de Caja</h4>
            <div class="table-responsive" style="max-height: 150px; overflow-y: auto;">
                <table class="table">
                    <thead><tr style="background:#f1f1f1;"><th>ID</th><th>Motivo</th><th class="text-end">Valor</th><th>Acción</th></tr></thead>
                    <tbody>
                        <?php if(empty($cajero['listaEgresos'])): ?>
                            <tr><td colspan="4" style="text-align:center; color:#777;">Sin egresos registrados</td></tr>
                        <?php else: foreach($cajero['listaEgresos'] as $eg): $idE = $eg['IDSALIDA']; ?>
                        <tr>
                            <td><?= $idE ?></td>
                            <td><?= ($permiso9999 === 'SI') ? "<input type='text' id='motivo_$idE' class='input-edit' value='".htmlspecialchars($eg['MOTIVO'])."'>" : $eg['MOTIVO'] ?></td>
                            <td class="text-end"><?= ($permiso9999 === 'SI') ? "<input type='number' id='valor_$idE' class='input-edit text-end' value='{$eg['VALOR']}'>" : "$".money($eg['VALOR']) ?></td>
                            <td style="text-align:center;"><?= ($permiso9999 === 'SI') ? "<button class='btn-save' onclick='guardarEgreso($idE)'>💾</button>" : "-" ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 15px; display: flex; gap: 8px; justify-content: center;">
                <button class="button" style="background:#f39c12; font-size:12px; padding:6px 12px;" onclick='mostrarVoucher("precierre", <?= json_encode($cajero) ?>)'>📋 Precierre</button>
                <?php if($cajero['cierreRealizado']): ?>
                    <button class="button" style="background:#2ecc71; font-size:12px; padding:6px 12px;" onclick='mostrarVoucher("cierre", <?= json_encode($cajero) ?>)'>🖨️ Cierre</button>
                <?php else: ?>
                    <button class="button" style="background:#d32f2f; font-size:12px; padding:6px 12px;" onclick='mostrarVoucher("cierre", <?= json_encode($cajero) ?>)'>🔒 Cierre Def.</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<div id="modalVoucher" class="modal">
    <div class="modal-content" id="printArea"></div>
</div>

<script>
    function mostrarVoucher(tipo, cajero) {
        const p9999 = '<?= $permiso9999 ?>';
        const p7777 = '<?= $permiso7777 ?>';
        const p0003 = '<?= $permiso0003 ?>';
        const cierreYaHecho = cajero.cierreRealizado;

        if(tipo === 'cierre' && !cierreYaHecho && p7777 !== 'SI' && p9999 !== 'SI' && p0003 !== 'SI') {
            alert('ACCESO DENEGADO: Requiere permiso de supervisor para realizar el cierre.'); 
            return;
        }

        let egresosHtml = "";
        cajero.listaEgresos.forEach(e => {
            egresosHtml += `<tr><td style="padding:1px; max-width:140px; overflow:hidden;">- ${e.MOTIVO.substring(0,20).toUpperCase()}</td><td style="text-align:right;"><b>$${Number(e.VALOR).toLocaleString()}</b></td></tr>`;
        });

        const titulo = (tipo === 'precierre') ? 'PRECIERRE' : 'CIERRE FINAL';
        const horaImpresion = '<?= date("h:i a") ?>';
        const estadoSesion = cierreYaHecho ? "SESIÓN CERRADA" : "SESIÓN ABIERTA";
        
        const vVentas = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$' + Number(cajero.totalVentas).toLocaleString() : '***';
        const vTotal = (cierreYaHecho || p9999 === 'SI' || p0003 === 'SI') ? '$' + Number(cajero.efectivo_neto_final).toLocaleString() : '***';

        let html = `
            <div style="text-align:center;">
                <h2 style="margin:0;"><b>${titulo}</b></h2>
                <p style="margin:0;"><b>SEDE: <?= strtoupper($nombre_sede_display) ?></b></p>
                <p style="margin:0;">FECHA: <?= $fecha_input ?> | ${horaImpresion}</p>
                <p style="margin:0;">CAJERO: ${cajero.nombre.substring(0, 25).toUpperCase()}</p>
                <p style="margin:0;"><b>ESTADO: ${estadoSesion}</b></p>
                <hr>
            </div>
            <table style="width:100%; border-collapse:collapse;">
                <tr><td>VENTAS BRUTAS:</td><td style="text-align:right;"><b>${vVentas}</b></td></tr>
                <tr><td>(-) EGRESOS:</td><td style="text-align:right;"><b>$${Number(cajero.totalEgresos).toLocaleString()}</b></td></tr>
                <tr><td>(-) TRANSFER:</td><td style="text-align:right;"><b>$${Number(cajero.totalTransfer).toLocaleString()}</b></td></tr>
                <tr><td>(-) TRANS. AUTO:</td><td style="text-align:right;"><b>$${Number(cajero.totalTransferAuto).toLocaleString()}</b></td></tr>
                <tr><td colspan="2"><hr></td></tr>
                <tr style="font-size:16px;">
                    <td><b>TOTAL FÍSICO:</b></td>
                    <td style="text-align:right;"><b>${vTotal}</b></td>
                </tr>
            </table>
            <div style="margin-top:10px; font-size:12px; font-weight:900; border-bottom:2px solid #000; text-transform: uppercase;">Detalle Egresos</div>
            <table style="width:100%; font-size:11px;">${egresosHtml}</table>
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