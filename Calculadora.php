<?php

// CONFIGURACIÓN DE TIEMPO DE SESIÓN
$session_timeout   = 3600;  // duración total de la sesión (1 hora)
$inactive_timeout  = 1800;  // inactividad máxima (30 min)

// Control de inactividad
if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > $inactive_timeout)) {
    session_unset();
    session_destroy();
    header("Location: login.php"); // redirige al login si la sesión expira
    exit;
}
$_SESSION['ultimo_acceso'] = time();

// Variables de sesión
$UsuarioSesion   = $_SESSION['Usuario']     ?? '';
$NitSesion       = $_SESSION['NitEmpresa']  ?? '';
$SucursalSesion  = $_SESSION['NroSucursal'] ?? '';
$fechaActual     = date("Y-m-d H:i");

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conteo de Billetes</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background-color: #f4f4f8; padding: 20px; display: flex; flex-direction: column; align-items: center; color: #333; }
    h2 { margin-bottom: 10px; color: #333; text-align: center; }
    table { width: 100%; max-width: 360px; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; margin-bottom: 10px; }
    th { background-color: #007bff; color: white; padding: 6px; font-size: 13px; }
    td { text-align: center; padding: 5px; }
    input[type="number"], input[type="text"] { width: 70px; padding: 3px; border: 1px solid #ccc; border-radius: 4px; text-align: right; font-size: 13px; }
    input[readonly] { background-color: #f0f0f0; font-weight: bold; }
    .total-label { text-align: right; padding-right: 8px; font-weight: bold; background: #f9f9f9; }
    .total-input { background: #e2f0d9; color: #000; font-weight: bold; }
    .info { margin-top: 10px; text-align: center; font-size: 13px; color: #555; }
    .solo-impresion { display: none; }
    .buttons { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
    button { background-color: #007bff; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: background-color 0.3s; }
    button:hover { background-color: #0056b3; }
    @media (max-width: 400px) { table { max-width: 100%; font-size: 12px; } input[type="number"], input[type="text"] { width: 60px; font-size: 12px; } }
    @media print {
      body { background: white !important; color: #000 !important; font-size: 9pt !important; font-weight: bold !important; zoom: 80%; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      table { max-width: 100%; box-shadow: none; }
      h2::before { content: "Drinks Depot.SAS"; display: block; font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 6px; }
      th, td { padding: 2px; font-size: 9pt; color: #000 !important; font-weight: bold !important; }
      input[type="number"], input[type="text"] { font-size: 9pt; width: 60px; padding: 1px; border: none; background: transparent; color: #000 !important; font-weight: bold !important; }
      .info { font-size: 9pt; color: #000 !important; font-weight: bold !important; }
      .buttons, button { display: none !important; }
      .solo-impresion { display: block !important; text-align: center; font-size: 9pt; margin-top: 5px; }
    }
  </style>
</head>
<body>

  <h2>Conteo de Billetes</h2>

  <table>
    <thead>
      <tr>
        <th>Valor</th>
        <th>Cantidad</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody id="billeteTable"></tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="total-label">TOTAL</td>
        <td><input type="text" id="total" class="total-input" readonly value="0"></td>
      </tr>
    </tfoot>
  </table>

  <div class="info">
    <h4>Usuario: <?= $UsuarioSesion ?></h4>
    <div class="solo-impresion">Fecha: <strong><?= $fechaActual; ?></strong></div>
  </div>

  <div class="firma solo-impresion">
    <div class="firma-linea" style="border-top: 1px solid #000; width: 180px; margin: 30px auto 10px;"></div>
    <div>Firma</div>
  </div>

  <div class="buttons">
    <button onclick="limpiar()">Limpiar</button>
    <button onclick="window.print()">Imprimir</button>
    <button onclick="window.close()">Salir</button>
  </div>

  <script>
    const denominaciones = [
      { valor: 100000, etiqueta: "100 mil" },
      { valor: 50000, etiqueta: "50 mil" },
      { valor: 20000, etiqueta: "20 mil" },
      { valor: 10000, etiqueta: "10 mil" },
      { valor: 5000, etiqueta: "5 mil" },
      { valor: 2000, etiqueta: "2 mil" },
      { valor: 1000, etiqueta: "1 mil" },
      { valor: 500, etiqueta: "500" },
      { valor: 200, etiqueta: "200" },
      { valor: 100, etiqueta: "100" },
      { valor: 50, etiqueta: "50" }
    ];

    const tabla = document.getElementById("billeteTable");

    denominaciones.forEach((den, i) => {
      const fila = document.createElement("tr");
      fila.innerHTML = `
        <td><input type="text" value="${den.etiqueta}" readonly></td>
        <td><input type="number" min="0" value="0" oninput="calcular(${i})" id="cant_${i}" aria-label="Cantidad de ${den.etiqueta}"></td>
        <td><input type="text" value="0" readonly id="val_${i}"></td>
      `;
      tabla.appendChild(fila);
    });

    function calcular(i) {
      const cantidad = parseInt(document.getElementById(`cant_${i}`).value) || 0;
      const valor = denominaciones[i].valor;
      const totalFila = cantidad * valor;
      document.getElementById(`val_${i}`).value = totalFila.toLocaleString();

      let total = 0;
      denominaciones.forEach((_, j) => {
        const cant = parseInt(document.getElementById(`cant_${j}`).value) || 0;
        total += cant * denominaciones[j].valor;
      });
      document.getElementById("total").value = total.toLocaleString();
    }

    function limpiar() {
      denominaciones.forEach((_, i) => {
        document.getElementById(`cant_${i}`).value = 0;
        document.getElementById(`val_${i}`).value = 0;
      });
      document.getElementById("total").value = 0;
    }
  </script>

</body>
</html>
