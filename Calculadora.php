<?php
// AJUSTE DE ZONA HORARIA BOGOTÁ
date_default_timezone_set('America/Bogota');

// CONFIGURACIÓN DE TIEMPO DE SESIÓN
$session_timeout   = 3600; 
$inactive_timeout  = 1800; 

if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout)) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['ultimo_acceso'] = time();

$UsuarioSesion   = $_SESSION['Usuario']     ?? 'CAJERO';
$fechaActual     = date("Y-m-d H:i");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conteo de Billetes | BNMA</title>
  <style>
    /* --- ESTILOS PARA PANTALLA --- */
    body { font-family: 'Segoe UI', sans-serif; background-color: #f0f2f5; padding: 20px; display: flex; flex-direction: column; align-items: center; color: #1c1e21; margin: 0; }
    h2 { margin-bottom: 15px; color: #000; text-transform: uppercase; letter-spacing: 1px; }
    .card { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    th { background-color: #1a1a1a; color: white; padding: 10px; font-size: 13px; text-transform: uppercase; }
    td { text-align: center; padding: 8px; border-bottom: 1px solid #edf0f2; font-weight: 600; }
    input[type="number"], input[type="text"] { width: 90px; padding: 6px; border: 1px solid #ddd; border-radius: 6px; text-align: right; font-size: 14px; outline: none; }
    input[readonly] { background-color: #f8f9fa; border: none; font-weight: bold; }
    .total-input { background: #d4edda !important; color: #155724 !important; font-size: 18px !important; width: 110px !important; }
    .buttons { margin-top: 20px; display: flex; gap: 12px; width: 100%; max-width: 400px; }
    button { flex: 1; background-color: #1a1a1a; color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; font-weight: bold; }
    button.btn-clear { background-color: #dc3545; }
    .solo-impresion { display: none; }

    /* --- AJUSTE PARA IMPRESIÓN (MÁS PEQUEÑA Y EN NEGRITA) --- */
    @media print {
      @page { margin: 0; }
      body { background: white !important; width: 100%; margin: 0; padding: 3px; font-family: 'Courier New', Courier, monospace; }
      * { color: #000 !important; font-weight: 900 !important; -webkit-text-stroke: 0.2px black; }
      .card { box-shadow: none; padding: 0; width: 100%; }
      h2 { font-size: 12pt; margin: 5px 0 2px 0; text-align: center; }
      h2::before { content: "BNMA COORPORACION"; display: block; font-size: 13pt; border-bottom: 2px solid #000; margin-bottom: 3px; }
      
      /* Usuario visible en impresión */
      .user-info-print { display: block !important; text-align: center; font-size: 9pt; margin-bottom: 5px; }
      
      table { width: 98% !important; border: 1px solid #000; }
      th { background: #000 !important; color: #fff !important; font-size: 9pt; -webkit-print-color-adjust: exact; padding: 2px !important; }
      td { padding: 2px !important; font-size: 9pt; border: 1px solid #000 !important; }
      input { border: none !important; background: transparent !important; font-size: 9pt !important; width: 100% !important; }
      
      .buttons { display: none !important; }
      .solo-impresion { display: block !important; text-align: center; margin-top: 5px; font-size: 8pt; }
      .firma-linea { border-top: 1.5px solid #000 !important; width: 70%; margin: 35px auto 5px; }
    }
  </style>
</head>
<body>

  <div class="card">
    <h2>Conteo Billetes</h2>
    
    <div class="user-info-print solo-impresion">USUARIO: <?= $UsuarioSesion ?></div>

    <table>
      <thead>
        <tr>
          <th style="width: 35%;">Valor</th>
          <th style="width: 25%;">Cant.</th>
          <th style="width: 40%;">Total</th>
        </tr>
      </thead>
      <tbody id="billeteTable"></tbody>
      <tfoot>
        <tr style="background: #f8f9fa;">
          <td colspan="2" style="text-align: right; font-weight: bold; padding-right: 15px;">TOTAL:</td>
          <td><input type="text" id="total" class="total-input" readonly value="0"></td>
        </tr>
      </tfoot>
    </table>

    <div style="text-align: center; font-size: 13px; font-weight: bold;" class="no-print">
      Usuario: <?= $UsuarioSesion ?>
    </div>

    <div class="solo-impresion">
      <div style="text-align: center;">Fecha: <?= $fechaActual; ?></div>
      <div class="firma-linea"></div>
      <div style="text-align: center; font-weight: bold; font-size: 9pt;">FIRMA RESPONSABLE</div>
    </div>
  </div>

  <div class="buttons">
    <button class="btn-clear" onclick="limpiar()">Limpiar</button>
    <button onclick="window.print()">Imprimir Ticket</button>
  </div>

  <script>
    const denominaciones = [
      { valor: 100000, etiqueta: "100.000" },
      { valor: 50000, etiqueta: "50.000" },
      { valor: 20000, etiqueta: "20.000" },
      { valor: 10000, etiqueta: "10.000" },
      { valor: 5000, etiqueta: "5.000" },
      { valor: 2000, etiqueta: "2.000" },
      { valor: 1000, etiqueta: "1.000" },
      { valor: 500, etiqueta: "500" },
      { valor: 200, etiqueta: "200" },
      { valor: 100, etiqueta: "100" }
    ];

    const tabla = document.getElementById("billeteTable");

    denominaciones.forEach((den, i) => {
      const fila = document.createElement("tr");
      fila.innerHTML = `
        <td style="text-align: left; padding-left: 10px;">${den.etiqueta}</td>
        <td><input type="number" min="0" value="0" oninput="calcular()" id="cant_${i}"></td>
        <td><input type="text" value="0" readonly id="val_${i}"></td>
      `;
      tabla.appendChild(fila);
    });

    function calcular() {
      let granTotal = 0;
      denominaciones.forEach((den, i) => {
        const cant = parseInt(document.getElementById(`cant_${i}`).value) || 0;
        const subtotal = cant * den.valor;
        document.getElementById(`val_${i}`).value = subtotal.toLocaleString('es-CO');
        granTotal += subtotal;
      });
      document.getElementById("total").value = granTotal.toLocaleString('es-CO');
    }

    function limpiar() {
      if(confirm("¿Desea limpiar el conteo actual?")) {
        denominaciones.forEach((_, i) => {
          document.getElementById(`cant_${i}`).value = 0;
          document.getElementById(`val_${i}`).value = 0;
        });
        document.getElementById("total").value = 0;
      }
    }
  </script>
</body>
</html>