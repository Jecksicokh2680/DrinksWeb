<?php
/* ============================================================
    CONFIGURACIÓN Y CONEXIONES
============================================================ */
$session_timeout = 3600;
session_start();
date_default_timezone_set('America/Bogota'); 
require("ConnCentral.php"); 
require("Conexion.php");    
require("ConnDrinks.php");  

$UsuarioSesion = $_SESSION['Usuario'] ?? '';
if ($UsuarioSesion === '') { die("Debe iniciar sesión."); }

function Autorizacion($User, $Solicitud) {
    global $mysqli; 
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}
$permiso9999 = Autorizacion($UsuarioSesion, '9999'); 

$fecha_input = $_GET['fecha'] ?? date('Y-m-d');
$fecha_esc   = str_replace('-', '', $fecha_input);

function money($v){ return number_format(round((float)$v), 0, ',', '.'); }

$sedes = [
    ['conn' => $mysqliCentral, 'nombre' => 'CENTRAL', 'id' => 'central'],
    ['conn' => $mysqliDrinks,  'nombre' => 'DRINKS (AWS)', 'id' => 'drinks']
];

$globalVentas = 0; 
$globalEgresos = 0; 
$globalTransf = 0; 
$globalFisico = 0;
$globalEfectivoEntregado = 0; 

$dataConsolidada = [];
$egresosAgrupados = [];

foreach ($sedes as $s) {
    $mysqliActiva = $s['conn'];
    $nombreSede   = $s['nombre'];
    $idSede       = $s['id'];

    $qryCajeros = "SELECT NIT, NOMBRE FROM (
        SELECT T1.NIT, CONCAT_WS(' ', T1.nombres, T1.apellidos) AS NOMBRE FROM FACTURAS F 
        INNER JOIN TERCEROS T1 ON T1.IDTERCERO = F.IDVENDEDOR WHERE F.FECHA = '$fecha_esc'
        UNION 
        SELECT V.NIT, CONCAT_WS(' ', V.nombres, V.apellidos) AS NOMBRE FROM PEDIDOS P 
        INNER JOIN USUVENDEDOR UV ON UV.IDUSUARIO = P.IDUSUARIO 
        INNER JOIN TERCEROS V ON V.IDTERCERO = UV.IDTERCERO WHERE P.FECHA = '$fecha_esc'
    ) X GROUP BY NIT ORDER BY NOMBRE ASC";

    $resCajeros = $mysqliActiva->query($qryCajeros);

    if ($resCajeros) {
        while ($c = $resCajeros->fetch_assoc()) {
            $nit = $c['NIT'];
            $nombreCajero = $c['NOMBRE'];

            $cierreCajero = false;
            $qryCheck = "SELECT T2.NIT FROM ARQUEO AS A1
                         INNER JOIN USUVENDEDOR AS V1 ON V1.IDUSUARIO = A1.IDUSUARIO
                         INNER JOIN TERCEROS AS T2 ON T2.IDTERCERO = V1.IDTERCERO
                         WHERE DATE_FORMAT(A1.fechacie, '%Y-%m-%d') = '$fecha_input' 
                         AND T2.NIT = '$nit' LIMIT 1";
            $resCheck = $mysqliActiva->query($qryCheck);
            if ($resCheck && $resCheck->num_rows > 0) { $cierreCajero = true; }

            $qV = "SELECT SUM(VAL) AS TOTAL FROM (
                SELECT SUM(DF.CANTIDAD*DF.VALORPROD) AS VAL FROM FACTURAS F 
                INNER JOIN DETFACTURAS DF ON DF.IDFACTURA=F.IDFACTURA WHERE F.ESTADO='0' AND F.FECHA='$fecha_esc' AND F.IDVENDEDOR IN (SELECT IDTERCERO FROM TERCEROS WHERE NIT='$nit')
                UNION ALL 
                SELECT SUM(DP.CANTIDAD*DP.VALORPROD) FROM PEDIDOS P 
                INNER JOIN DETPEDIDOS DP ON DP.IDPEDIDO=P.IDPEDIDO WHERE P.ESTADO='0' AND P.FECHA='$fecha_esc' AND P.IDUSUARIO IN (SELECT IDUSUARIO FROM USUVENDEDOR UV INNER JOIN TERCEROS T ON T.IDTERCERO=UV.IDTERCERO WHERE T.NIT='$nit')
            ) AS X";
            $vts = (float)($mysqliActiva->query($qV)->fetch_assoc()['TOTAL'] ?? 0);

            $qE = "SELECT S1.IDSALIDA, S1.MOTIVO, S1.VALOR FROM SALIDASCAJA S1 
                   INNER JOIN USUVENDEDOR V1 ON V1.IDUSUARIO=S1.IDUSUARIO INNER JOIN TERCEROS T1 ON T1.IDTERCERO=V1.IDTERCERO 
                   WHERE S1.FECHA='$fecha_esc' AND T1.NIT='$nit'";
            $resE = $mysqliActiva->query($qE);
            
            $egrTotalCajero = 0;
            $efectivoCajero = 0;
            $tieneTransferenciaEnEgresos = false;

            if($resE->num_rows > 0){
                if(!isset($egresosAgrupados[$nit])){
                    $egresosAgrupados[$nit] = ['nombre' => $nombreCajero, 'sede' => $nombreSede, 'id_sede' => $idSede, 'detalles' => [], 'total' => 0];
                }
                while($eg = $resE->fetch_assoc()){
                    $motivo = $eg['MOTIVO'];
                    $valor  = (float)$eg['VALOR'];

                    $egresosAgrupados[$nit]['detalles'][] = $eg;
                    $egresosAgrupados[$nit]['total'] += $valor;
                    $egrTotalCajero += $valor;

                    if (stripos($motivo, 'TRANSF') !== false) { $tieneTransferenciaEnEgresos = true; }
                    if (stripos($motivo, 'ENTREGA') !== false || stripos($motivo, 'EFECTIVO') !== false || stripos($motivo, 'MONEDA') !== false) {
                        $efectivoCajero += $valor;
                        $globalEfectivoEntregado += $valor;
                    }
                }
            }

            $qT = "SELECT SUM(Monto) AS TOTAL FROM Relaciontransferencias WHERE Fecha='$fecha_esc' AND CedulaNit='$nit'";
            $trf_auto = (float)($mysqli->query($qT)->fetch_assoc()['TOTAL'] ?? 0);
            
            $trf_a_operar = ($tieneTransferenciaEnEgresos) ? 0 : $trf_auto;
            $diferencia = ($egrTotalCajero + $trf_a_operar) - $vts;
            
            $leyenda = "CUADRADO";
            if($diferencia > 0) $leyenda = "SOBRA";
            if($diferencia < 0) $leyenda = "FALTA";

            $dataConsolidada[] = [
                'sede' => $nombreSede, 'nombre' => $nombreCajero,
                'ventas' => $vts, 'egr' => $egrTotalCajero, 'trf' => $trf_auto, 'efectivo' => $efectivoCajero,
                'diferencia' => $diferencia, 'cerrado' => $cierreCajero, 'leyenda' => $leyenda
            ];

            $globalVentas += $vts; $globalEgresos += $egrTotalCajero; $globalTransf += $trf_auto; $globalFisico += $diferencia;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría Consolidada</title>
    <style>
        :root { --primary: #2c3e50; --secondary: #1f2d3d; --accent: #f39c12; --success: #27ae60; --danger: #e74c3c; --bg: #f4f7f6; --info: #3498db; }
        
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); margin: 0; padding: 10px; color: #333; }
        
        /* HEADER RESPONSIVE */
        .header-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 25px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; }
        .header-box h2 { margin: 0; font-size: clamp(1.2rem, 4vw, 1.8rem); }

        /* GRID UNIVERSAL RESPONSIVE */
        .universal-grid { display: grid; gap: 20px; margin-bottom: 30px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }

        /* ESTILO ÚNICO DE TARJETA */
        .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 5px solid var(--primary); display: flex; flex-direction: column; height: 100%; transition: transform 0.2s; }
        .card:hover { transform: translateY(-3px); }
        .card-egreso { border-top: 5px solid var(--danger); } /* Mismo tamaño, diferente color de tope */

        .sede-label { font-size: 10px; font-weight: bold; color: #aaa; text-transform: uppercase; letter-spacing: 1px; }
        .cajero-name { margin: 5px 0 15px 0; color: var(--primary); font-size: 1.1rem; }

        .row-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .row-item:last-child { border-bottom: none; }

        /* CAJAS DE RESULTADO */
        .total-box { margin-top: auto; padding: 12px; border-radius: 8px; display: flex; justify-content: space-between; font-weight: bold; font-size: 15px; }
        .bg-sobra { background: #d4edda; color: #155724; } 
        .bg-falta { background: #f8d7da; color: #721c24; } 
        .bg-ok { background: #e3f2fd; color: #0d47a1; }

        /* BADGES */
        .status-badge { margin-top: 15px; padding: 8px; border-radius: 6px; text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-open { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .status-closed { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        /* INPUTS Y BOTONES */
        .input-edit { width: 100%; border: 1px solid #ddd; border-radius: 6px; padding: 8px; font-size: 13px; margin-bottom: 5px; background: #fafafa; }
        .btn-save { background: var(--success); color: white; border: none; border-radius: 6px; cursor: pointer; padding: 8px 15px; font-size: 14px; width: 100%; transition: 0.3s; }
        .btn-save:hover { background: #219150; }

        /* FOOTER RESPONSIVE */
        .footer-summary { background: var(--secondary); color: white; padding: 25px; border-radius: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 20px; text-align: center; margin-top: 40px; }
        .footer-item b { display: block; font-size: 1.2rem; margin-top: 5px; }
        .neto-destaque { background: rgba(255,255,255,0.1); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.2); }

        #timer { background: var(--accent); color: white; padding: 8px 16px; border-radius: 8px; font-weight: bold; font-family: monospace; }

        @media (max-width: 600px) {
            .universal-grid { grid-template-columns: 1fr; }
            .header-box { justify-content: center; text-align: center; }
        }
    </style>
</head>
<body>

<div class="header-box">
    <h2>🚀 Panel de Auditoría</h2>
    <div style="display:flex; align-items:center; gap:12px;">
        <input type="date" value="<?= $fecha_input ?>" onchange="location.href='?fecha='+this.value" style="padding: 8px; border-radius: 6px; border: 1px solid #ddd;">
        <div id="timer">03:00</div>
    </div>
</div>

<div class="universal-grid">
    <?php foreach($dataConsolidada as $item): 
        $claseFisico = "bg-ok";
        if($item['diferencia'] > 0) $claseFisico = "bg-sobra";
        if($item['diferencia'] < 0) $claseFisico = "bg-falta";
    ?>
    <div class="card">
        <span class="sede-label"><?= $item['sede'] ?></span>
        <h4 class="cajero-name"><?= htmlspecialchars($item['nombre']) ?></h4>
        
        <div class="row-item"><span>Ventas:</span> <b>$<?= money($item['ventas']) ?></b></div>
        <div class="row-item"><span>Total Egresos:</span> <b style="color:var(--danger);">$<?= money($item['egr']) ?></b></div>
        <div class="row-item"><span>Efectivo Entregado:</span> <b style="color:var(--info);">$<?= money($item['efectivo']) ?></b></div>
        <div class="row-item"><span>Transf (Informativo):</span> <b style="color:blue;">$<?= money($item['trf']) ?></b></div>
        
        <div class="total-box <?= $claseFisico ?>">
            <span><?= $item['leyenda'] ?>:</span>
            <span>$<?= money(abs($item['diferencia'])) ?></span>
        </div>

        <div class="status-badge <?= $item['cerrado'] ? 'status-closed' : 'status-open' ?>">
            <?= $item['cerrado'] ? '🔒 SESIÓN CERRADA' : '🔓 SESIÓN ABIERTA' ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h3 style="color: var(--danger); border-left: 5px solid var(--danger); padding-left: 15px; margin: 40px 0 20px 0;">💸 Gestión de Egresos</h3>

<div class="universal-grid">
    <?php if(count($egresosAgrupados) > 0): foreach($egresosAgrupados as $nit => $egAg): ?>
    <div class="card card-egreso">
        <span class="sede-label"><?= $egAg['sede'] ?></span>
        <h4 class="cajero-name"><?= htmlspecialchars($egAg['nombre']) ?></h4>
        
        <div style="flex-grow: 1; overflow-y: auto; max-height: 250px; margin-bottom: 15px; padding-right: 5px;">
            <?php foreach($egAg['detalles'] as $det): $idE = $det['IDSALIDA']; ?>
            <div style="margin-bottom: 15px; border-bottom: 1px dashed #eee; padding-bottom: 10px;">
                <?php if($permiso9999 === 'SI'): ?>
                    <label style="font-size: 11px; color: #888;">Motivo:</label>
                    <input type="text" id="motivo_<?= $idE ?>" class="input-edit" value="<?= htmlspecialchars($det['MOTIVO']) ?>">
                    <label style="font-size: 11px; color: #888;">Valor:</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" id="valor_<?= $idE ?>" class="input-edit" value="<?= $det['VALOR'] ?>">
                        <button class="btn-save" style="width: 50px;" onclick="guardarEgreso(<?= $idE ?>, '<?= $egAg['id_sede'] ?>')">💾</button>
                    </div>
                <?php else: ?>
                    <div class="row-item">
                        <span><?= htmlspecialchars($det['MOTIVO']) ?></span>
                        <b style="color: var(--danger);">$<?= money($det['VALOR']) ?></b>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="total-box bg-ok" style="background: #f8f9fa; border: 1px solid #eee; color: var(--danger);">
            <span>TOTAL EGRESOS:</span>
            <span>$<?= money($egAg['total']) ?></span>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<div class="footer-summary">
    <div class="footer-item"><span>VENTAS</span><b>$<?= money($globalVentas) ?></b></div>
    <div class="footer-item"><span>EGRESOS</span><b>$<?= money($globalEgresos) ?></b></div>
    <div class="footer-item">
        <span style="color: #00d4ff;">EFECTIVO</span>
        <b style="color: #00d4ff;">$<?= money($globalEfectivoEntregado) ?></b>
    </div>
    <div class="footer-item"><span>TRANSF</span><b>$<?= money($globalTransf) ?></b></div>
    <div class="footer-item neto-destaque">
        <span>NETO TOTAL</span>
        <b>$<?= money($globalFisico) ?></b>
    </div>
</div>

<script>
    function guardarEgreso(id, sede){
        const mot = document.getElementById('motivo_'+id).value;
        const val = document.getElementById('valor_'+id).value;
        if(!confirm('¿Actualizar este egreso?')) return;
        fetch('update_egreso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${id}&motivo=${encodeURIComponent(mot)}&valor=${encodeURIComponent(val)}&sede=${sede}`
        }).then(r => r.text()).then(t => { alert(t); location.reload(); });
    }

    (function() {
        let timeLeft = 180; 
        const timerElement = document.getElementById('timer');
        const countdown = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(countdown);
                window.location.reload(true);
            } else {
                timeLeft--;
                let m = Math.floor(timeLeft / 60);
                let s = timeLeft % 60;
                timerElement.innerText = `${m}:${s < 10 ? '0' : ''}${s}`;
            }
        }, 1000);
    })();
</script>
</body>
</html>