<?php
session_start();
require_once 'Conexion.php';

// 1. Validar conexión a base de datos
if (!isset($mysqliWeb) || $mysqliWeb->connect_error) {
    die("Error de conexión a la base de datos: " . ($mysqliWeb->connect_error ?? 'La variable $mysqliWeb no está definida en Conexion.php'));
}
$mysqli = $mysqliWeb;

// 2. Verificar sesión
if (!isset($_SESSION['CedulaNit'])) {
    die("Acceso denegado: Por favor inicie sesión primero.");
}
$UsuarioSesion = $_SESSION['CedulaNit'];

// 3. Validar permisos de supervisor
function Autorizacion($User, $Solicitud) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT Swich FROM autorizacion_tercero WHERE CedulaNit=? AND Nro_Auto=?");
    if (!$stmt) return "NO";
    $stmt->bind_param("ss", $User, $Solicitud);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? ($row['Swich'] ?? "NO") : "NO";
}

$es_supervisor = (Autorizacion($UsuarioSesion, '9999') === 'SI' || Autorizacion($UsuarioSesion, '6666') === 'SI');
if (!$es_supervisor) {
    die("Acceso denegado: Se requiere perfil de supervisor para visualizar el informe gerencial.");
}

// 4. Parámetros legales Colombia 2026
define('SMMLV_2026', 1750905);
define('AUX_TRANSPORTE_2026', 249095);
define('TOPE_AUXILIO_2026', SMMLV_2026 * 2); // $3.501.810

// 5. Filtros GET
$fecha_corte_str = $_GET['fecha_corte'] ?? date('Y-m-d');
$fecha_corte = DateTime::createFromFormat('Y-m-d', $fecha_corte_str) ?: new DateTime();
$fecha_corte_str = $fecha_corte->format('Y-m-d');

$empresa_filtro = trim($_GET['empresa'] ?? '');

// 6. Funciones auxiliares
function toUpper($texto): string {
    return mb_strtoupper(trim((string)($texto ?? '')), 'UTF-8');
}

function obtenerNombreCompleto(array $fila): string {
    $nombre = trim((string)($fila['Nombre'] ?? ''));
    $nombres = trim((string)($fila['Nombres'] ?? ''));
    $apellidos = trim((string)($fila['Apellidos'] ?? ''));

    if ($nombres !== '' || $apellidos !== '') {
        $combinado = trim($nombres . ' ' . $apellidos);
        if ($combinado !== '' && mb_stripos($nombre, $combinado, 0, 'UTF-8') === false) {
            return toUpper($combinado);
        }
    }
    return toUpper($nombre !== '' ? $nombre : 'NO SINCRONIZADO');
}

function calcularDiasComerciales(DateTime $inicio, DateTime $fin): int {
    if ($inicio > $fin) return 0;
    $d1 = min((int)$inicio->format('d'), 30);
    $d2 = min((int)$fin->format('d'), 30);
    $m1 = (int)$inicio->format('m');
    $m2 = (int)$fin->format('m');
    $y1 = (int)$inicio->format('Y');
    $y2 = (int)$fin->format('Y');
    return (($y2 - $y1) * 360) + (($m2 - $m1) * 30) + ($d2 - $d1) + 1;
}

function calcularPreliquidacion(float $salario, string $fechaIngresoStr, DateTime $fechaCorte): array {
    $cero = [
        'valido' => false,
        'aux' => 0,
        'dias_anio' => 0,
        'dias_sem' => 0,
        'dias_vac' => 0,
        'cesantias' => 0,
        'int_cesantias' => 0,
        'prima' => 0,
        'vacaciones' => 0,
        'total' => 0
    ];

    if ($salario <= 0 || empty($fechaIngresoStr) || $fechaIngresoStr === '0000-00-00') {
        return $cero;
    }

    $ingreso = DateTime::createFromFormat('Y-m-d', substr($fechaIngresoStr, 0, 10));
    if (!$ingreso || $ingreso > $fechaCorte) {
        return $cero;
    }

    $anio = (int)$fechaCorte->format('Y');
    $mes  = (int)$fechaCorte->format('m');

    $inicio_anio = new DateTime("$anio-01-01");
    $inicio_sem  = ($mes <= 6) ? new DateTime("$anio-01-01") : new DateTime("$anio-07-01");

    $dias_anio = calcularDiasComerciales(($ingreso > $inicio_anio) ? $ingreso : $inicio_anio, $fechaCorte);
    $dias_sem  = calcularDiasComerciales(($ingreso > $inicio_sem) ? $ingreso : $inicio_sem, $fechaCorte);
    $dias_vac  = calcularDiasComerciales($ingreso, $fechaCorte);

    $aux  = ($salario <= TOPE_AUXILIO_2026) ? AUX_TRANSPORTE_2026 : 0;
    $base = $salario + $aux;

    $cesantias     = ($base * $dias_anio) / 360;
    $int_cesantias = ($cesantias * $dias_anio * 0.12) / 360;
    $prima         = ($base * $dias_sem) / 360;
    $vacaciones    = ($salario * $dias_vac) / 720;
    $total         = $cesantias + $int_cesantias + $prima + $vacaciones;

    return [
        'valido' => true,
        'aux' => $aux,
        'dias_anio' => $dias_anio,
        'dias_sem' => $dias_sem,
        'dias_vac' => $dias_vac,
        'cesantias' => $cesantias,
        'int_cesantias' => $int_cesantias,
        'prima' => $prima,
        'vacaciones' => $vacaciones,
        'total' => $total
    ];
}

// 7. Lista de empresas para filtro gerencial
$empresas_lista = [];
$res_emp = $mysqli->query("SELECT DISTINCT e.Nit, COALESCE(NULLIF(e.NombreComercial,''), e.RazonSocial, e.Nit) AS NombreEmpresa
                           FROM colaborador c
                           LEFT JOIN empresa e ON c.NitEmpresa = e.Nit
                           WHERE e.Nit IS NOT NULL AND e.Nit <> ''
                           ORDER BY NombreEmpresa ASC");
if ($res_emp) {
    while ($emp = $res_emp->fetch_assoc()) {
        $empresas_lista[] = $emp;
    }
}

// 8. Consulta ÚNICAMENTE de colaboradores ACTIVOS
$where_sql = " WHERE UPPER(TRIM(COALESCE(c.estado, 'ACTIVO'))) NOT IN ('INACTIVO', '0', 'NO', 'RETIRADO', 'BAJA') ";
if ($empresa_filtro !== '') {
    $where_sql .= " AND c.NitEmpresa = '" . $mysqli->real_escape_string($empresa_filtro) . "' ";
}

$sql = "SELECT c.*, t.Nombre, e.NombreComercial, e.RazonSocial
        FROM colaborador c
        INNER JOIN terceros t ON c.CedulaNit = t.CedulaNit
        LEFT JOIN empresa e ON c.NitEmpresa = e.Nit
        $where_sql
        ORDER BY t.Nombre ASC";

$res = $mysqli->query($sql);
if (!$res) {
    die("Error en consulta SQL: " . $mysqli->error);
}

$activos = [];
$totales = [
    'count' => 0,
    'salario' => 0,
    'aux' => 0,
    'cesantias' => 0,
    'int_cesantias' => 0,
    'prima' => 0,
    'vacaciones' => 0,
    'total' => 0
];

while ($row = $res->fetch_assoc()) {
    $salario = (float)($row['salario'] ?? 0);
    $liq = calcularPreliquidacion($salario, (string)($row['fecha_ingreso'] ?? ''), $fecha_corte);
    $empresaNombre = toUpper($row['NombreComercial'] ?: ($row['RazonSocial'] ?: ($row['NitEmpresa'] ?? 'N/A')));

    $activos[] = [
        'cedula' => trim((string)($row['CedulaNit'] ?? '')),
        'nombre' => obtenerNombreCompleto($row),
        'empresa' => $empresaNombre,
        'cargo' => toUpper($row['cargo'] ?? 'N/A'),
        'ingreso' => trim((string)($row['fecha_ingreso'] ?? '')),
        'eps' => toUpper($row['eps'] ?? 'N/A'),
        'arl' => toUpper($row['arl'] ?? 'N/A'),
        'pensiones' => toUpper($row['fondo_pensiones'] ?? 'N/A'),
        'salario' => $salario,
        'liq' => $liq
    ];

    $totales['count']++;
    $totales['salario'] += $salario;
    $totales['aux'] += $liq['aux'];
    $totales['cesantias'] += $liq['cesantias'];
    $totales['int_cesantias'] += $liq['int_cesantias'];
    $totales['prima'] += $liq['prima'];
    $totales['vacaciones'] += $liq['vacaciones'];
    $totales['total'] += $liq['total'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Informe Gerencial de Nómina y Preliquidación - <?= htmlspecialchars($fecha_corte_str) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
        }
        .exec-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            color: #fff;
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
        }
        .kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #1e3a8a;
            border-radius: 8px;
            padding: 0.9rem 1.1rem;
            height: 100%;
        }
        .kpi-card.kpi-accent { border-left-color: #0d9488; }
        .kpi-card.kpi-warn { border-left-color: #d97706; }
        .kpi-card.kpi-dark { border-left-color: #0f172a; }
        .kpi-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
        }
        .kpi-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
        }
        .table-exec thead th {
            background-color: #0f172a !important;
            color: #ffffff !important;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            vertical-align: middle;
            border-color: #1e293b;
            white-space: nowrap;
        }
        .table-exec tbody td {
            font-size: 0.8rem;
            vertical-align: middle;
        }
        .table-exec tfoot td {
            background-color: #e2e8f0 !important;
            font-weight: 800;
            font-size: 0.82rem;
            border-top: 2px solid #0f172a;
        }
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }
            body {
                background: #fff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .exec-header {
                background: #0f172a !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                padding: 0.8rem 1rem;
            }
            .kpi-card {
                border: 1px solid #cbd5e1 !important;
                padding: 0.5rem 0.75rem;
            }
            .table-exec thead th {
                background-color: #0f172a !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table-responsive {
                overflow: visible !important;
            }
            .table-exec {
                font-size: 0.7rem !important;
            }
        }
    </style>
</head>
<body class="p-3 p-md-4">

<div class="container-fluid">

    <!-- ENCABEZADO GERENCIAL -->
    <div class="exec-header shadow-sm mb-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="small text-uppercase opacity-75 fw-semibold">Reporte Ejecutivo de Talento Humano y Pasivo Laboral</div>
            <h4 class="fw-bold mb-1">Personal Activo y Preliquidación de Prestaciones Sociales</h4>
            <div class="small opacity-75">
                Fecha de corte: <strong><?= htmlspecialchars($fecha_corte_str) ?></strong> &nbsp;|&nbsp;
                SMMLV 2026: <strong>$<?= number_format(SMMLV_2026, 0, ',', '.') ?></strong> &nbsp;|&nbsp;
                Aux. Transporte: <strong>$<?= number_format(AUX_TRANSPORTE_2026, 0, ',', '.') ?></strong>
            </div>
        </div>
        <div class="d-flex gap-2 no-print">
            <button type="button" onclick="exportarExcel()" class="btn btn-success fw-bold shadow-sm">
                📊 Exportar a Excel
            </button>
            <button type="button" onclick="window.print()" class="btn btn-light fw-bold shadow-sm">
                🖨️ Imprimir Informe
            </button>
        </div>
    </div>

    <!-- BARRA DE FILTROS -->
    <div class="card border-0 shadow-sm mb-3 no-print">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Empresa</label>
                    <select name="empresa" class="form-select form-select-sm">
                        <option value="">-- Todas las empresas --</option>
                        <?php foreach ($empresas_lista as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['Nit']) ?>" <?= ($empresa_filtro === $emp['Nit']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(toUpper($emp['NombreEmpresa'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Fecha de Corte</label>
                    <input type="date" name="fecha_corte" value="<?= htmlspecialchars($fecha_corte_str) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Búsqueda rápida (Nombre, Cédula o Cargo)</label>
                    <input type="text" id="buscadorRapido" class="form-control form-control-sm" placeholder="Escriba para filtrar en pantalla...">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary fw-bold flex-grow-1">Aplicar Corte</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- TARJETAS KPI GERENCIALES -->
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="kpi-card shadow-sm">
                <div class="kpi-label">Colaboradores Activos</div>
                <div class="kpi-value"><?= number_format($totales['count'], 0, ',', '.') ?></div>
                <div class="small text-muted">Planta activa vigente</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-accent shadow-sm">
                <div class="kpi-label">Nómina Mensual + Auxilio</div>
                <div class="kpi-value">$<?= number_format($totales['salario'] + $totales['aux'], 0, ',', '.') ?></div>
                <div class="small text-muted">Básico: $<?= number_format($totales['salario'], 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-warn shadow-sm">
                <div class="kpi-label">Cesantías + Int. (12%)</div>
                <div class="kpi-value">$<?= number_format($totales['cesantias'] + $totales['int_cesantias'], 0, ',', '.') ?></div>
                <div class="small text-muted">Intereses: $<?= number_format($totales['int_cesantias'], 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card kpi-dark shadow-sm">
                <div class="kpi-label">Total Pasivo Preliquidación</div>
                <div class="kpi-value text-success">$<?= number_format($totales['total'], 0, ',', '.') ?></div>
                <div class="small text-muted">Corte al <?= htmlspecialchars($fecha_corte_str) ?></div>
            </div>
        </div>
    </div>

    <!-- TABLA GERENCIAL DE COLABORADORES ACTIVOS -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaGerencialActivos" class="table table-bordered table-hover table-sm table-exec mb-0">
                    <thead class="text-center">
                        <tr>
                            <th>#</th>
                            <th>Identificación</th>
                            <th>Nombres y Apellidos</th>
                            <th>Empresa</th>
                            <th>Cargo</th>
                            <th>Ingreso</th>
                            <th>Salario Básico</th>
                            <th>Aux. Transp.</th>
                            <th>Días Año</th>
                            <th>Cesantías</th>
                            <th>Int. Cesantías</th>
                            <th>Prima Sem.</th>
                            <th>Vacaciones</th>
                            <th>Total Liquidación</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($activos)): ?>
                        <tr>
                            <td colspan="14" class="text-center text-muted py-4">No hay colaboradores activos con los filtros seleccionados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activos as $idx => $a):
                            $l = $a['liq'];
                        ?>
                        <tr>
                            <td class="text-center text-muted"><?= $idx + 1 ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($a['cedula']) ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($a['nombre']) ?></td>
                            <td><?= htmlspecialchars($a['empresa']) ?></td>
                            <td><?= htmlspecialchars($a['cargo']) ?></td>
                            <td class="text-center text-nowrap"><?= htmlspecialchars($a['ingreso'] ?: 'N/A') ?></td>
                            <td class="text-end" data-num="<?= round($a['salario']) ?>">$<?= number_format($a['salario'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($l['aux']) ?>">$<?= number_format($l['aux'], 0, ',', '.') ?></td>
                            <td class="text-center"><?= $l['valido'] ? $l['dias_anio'] : '-' ?></td>
                            <td class="text-end" data-num="<?= round($l['cesantias']) ?>">$<?= number_format($l['cesantias'], 0, ',', '.') ?></td>
                            <td class="text-end fw-semibold" data-num="<?= round($l['int_cesantias']) ?>">$<?= number_format($l['int_cesantias'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($l['prima']) ?>">$<?= number_format($l['prima'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($l['vacaciones']) ?>">$<?= number_format($l['vacaciones'], 0, ',', '.') ?></td>
                            <td class="text-end fw-bold table-success" data-num="<?= round($l['total']) ?>">$<?= number_format($l['total'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-start">TOTALES GENERALES (<?= $totales['count'] ?> ACTIVOS)</td>
                            <td class="text-end" data-num="<?= round($totales['salario']) ?>">$<?= number_format($totales['salario'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($totales['aux']) ?>">$<?= number_format($totales['aux'], 0, ',', '.') ?></td>
                            <td class="text-center">-</td>
                            <td class="text-end" data-num="<?= round($totales['cesantias']) ?>">$<?= number_format($totales['cesantias'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($totales['int_cesantias']) ?>">$<?= number_format($totales['int_cesantias'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($totales['prima']) ?>">$<?= number_format($totales['prima'], 0, ',', '.') ?></td>
                            <td class="text-end" data-num="<?= round($totales['vacaciones']) ?>">$<?= number_format($totales['vacaciones'], 0, ',', '.') ?></td>
                            <td class="text-end text-success" data-num="<?= round($totales['total']) ?>">$<?= number_format($totales['total'], 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-2 d-flex justify-content-between text-muted small">
        <span>Generado el <?= date('Y-m-d H:i') ?> | Normativa Laboral Colombia 2026 (Base comercial 360 días).</span>
        <span>Documento de uso gerencial interno.</span>
    </div>

</div>

<script>
// Buscador rápido en tiempo real sobre la tabla
document.getElementById('buscadorRapido')?.addEventListener('input', function () {
    const filtro = this.value.toLowerCase().trim();
    const filas = document.querySelectorAll('#tablaGerencialActivos tbody tr');
    filas.forEach(tr => {
        const texto = tr.textContent.toLowerCase();
        tr.style.display = texto.includes(filtro) ? '' : 'none';
    });
});

// Exportar tabla visible a archivo Excel (.xls) preservando números limpios
function exportarExcel() {
    const tablaOriginal = document.getElementById('tablaGerencialActivos');
    const clon = tablaOriginal.cloneNode(true);

    // Eliminar filas ocultas por el buscador rápido
    clon.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.style.display === 'none') tr.remove();
    });

    // Convertir celdas monetarias a número puro para que Excel permita sumarlas directamente
    clon.querySelectorAll('[data-num]').forEach(td => {
        td.textContent = td.getAttribute('data-num');
    });

    const titulo = 'Informe Gerencial Activos y Preliquidacion - Corte <?= htmlspecialchars($fecha_corte_str) ?>';
    const htmlExcel = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office"
              xmlns:x="urn:schemas-microsoft-com:office:excel"
              xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="UTF-8">
            <style>
                table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11pt; }
                th { background-color: #0f172a; color: #ffffff; font-weight: bold; border: 1px solid #334155; padding: 6px; }
                td { border: 1px solid #cbd5e1; padding: 5px; }
                tfoot td { background-color: #e2e8f0; font-weight: bold; }
            </style>
        </head>
        <body>
            <h3>${titulo}</h3>
            ${clon.outerHTML}
        </body>
        </html>
    `;

    const blob = new Blob(['\ufeff', htmlExcel], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const enlace = document.createElement('a');
    enlace.href = url;
    enlace.download = 'Preliquidacion_Activos_<?= htmlspecialchars($fecha_corte_str) ?>.xls';
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>