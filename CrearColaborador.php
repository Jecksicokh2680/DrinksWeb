<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// 1. CONEXIONES
require 'Conexion.php';       // Base Local ($mysqli)
include 'ConnCentral.php';    // Base Central ($mysqliPos)
include 'ConnDrinks.php';     // Base Drinks ($mysqliDrinks)

// Empresa por defecto en sesión si existe
$NitEmpresaSession = $_SESSION['NitEmpresa'] ?? '';
$IdSucursal = $_SESSION['IdSucursal'] ?? 1;

// Carpeta y creación automática para las fotos de los colaboradores
$directorio_fotos = "uploads/colaboradores/";
if (!file_exists($directorio_fotos)) {
    mkdir($directorio_fotos, 0777, true);
}

/* ============================================================
    LÓGICA AJAX: CONSULTAR SI EL COLABORADOR YA EXISTE
   ============================================================ */
if (isset($_GET['ajax_consultar'])) {
    header('Content-Type: application/json');
    $nitEmp = $_GET['nit_empresa'] ?? '';
    $cedTercero = $_GET['cedula'] ?? '';

    $stmtAjax = $mysqli->prepare("SELECT * FROM colaborador WHERE CedulaNit = ? AND NitEmpresa = ? AND (estado = 'ACTIVO' OR estado IS NULL) LIMIT 1");
    $stmtAjax->bind_param("ss", $cedTercero, $nitEmp);
    $stmtAjax->execute();
    $resAjax = $stmtAjax->get_result();

    if ($rowAjax = $resAjax->fetch_assoc()) {
        echo json_encode(['existe' => true, 'datos' => $rowAjax]);
    } else {
        echo json_encode(['existe' => false]);
    }
    exit;
}

/* ============================================================
    LÓGICA 1: IMPORTACIÓN MASIVA DE TERCEROS (CENTRAL Y DRINKS)
   ============================================================ */
if (isset($_POST['importar_terceros'])) {
    $importados = 0;

    if (isset($mysqliPos) && $mysqliPos) {
        $resCen = $mysqliPos->query("SELECT nit, CONCAT(nombres, ' ', COALESCE(nombre2, ''), ' ', apellidos, ' ', COALESCE(apellido2, '')) as nombres, nomcomercial, email FROM terceros WHERE inactivo = 0");
        if ($resCen) {
            while ($tc = $resCen->fetch_assoc()) {
                $nit  = $mysqli->real_escape_string($tc['nit']);
                $nom  = $mysqli->real_escape_string($tc['nombres']);
                $com  = $mysqli->real_escape_string($tc['nomcomercial'] ?? '');
                $mail = $mysqli->real_escape_string($tc['email'] ?? '');
                
                $sqlSync = "INSERT INTO terceros (IdTercero, CedulaNit, Nombre, NombreCom, Email, Estado) 
                            VALUES ('$nit', '$nit', '$nom', '$com', '$mail', 1)
                            ON DUPLICATE KEY UPDATE 
                            Nombre = VALUES(Nombre), 
                            NombreCom = VALUES(NombreCom), 
                            Email = VALUES(Email), 
                            Estado = 1";
                            
                if ($mysqli->query($sqlSync) && $mysqli->affected_rows > 0) {
                    $importados++;
                }
            }
            $resCen->free();
        }
    }

    if (isset($mysqliDrinks) && $mysqliDrinks) {
        $resDrk = $mysqliDrinks->query("SELECT nit, CONCAT(nombres, ' ', COALESCE(nombre2, ''), ' ', apellidos, ' ', COALESCE(apellido2, '')) as nombres, nomcomercial, email FROM terceros WHERE inactivo = 0");
        if ($resDrk) {
            while ($td = $resDrk->fetch_assoc()) {
                $nit  = $mysqli->real_escape_string($td['nit']);
                $nom  = $mysqli->real_escape_string($td['nombres']);
                $com  = $mysqli->real_escape_string($td['nomcomercial'] ?? '');
                $mail = $mysqli->real_escape_string($td['email'] ?? '');
                
                $sqlSyncD = "INSERT INTO terceros (IdTercero, CedulaNit, Nombre, Email, Estado) 
                             VALUES ('$nit', '$nit', '$nom', '$mail', 1)
                             ON DUPLICATE KEY UPDATE 
                             Nombre = VALUES(Nombre), 
                             Email = VALUES(Email), 
                             Estado = 1";
                             
                if ($mysqli->query($sqlSyncD) && $mysqli->affected_rows > 0) {
                    $importados++;
                }
            }
            $resDrk->free();
        }
    }

    $mensaje = "<div class='alert alert-info fw-bold shadow-sm mb-3'>🔄 Se importaron/actualizaron $importados terceros desde las bases Central y Drinks.</div>";
}
/* ============================================================
    LÓGICA 2: ELIMINACIÓN FISICA DE COLABORADOR
   ============================================================ */
if (isset($_GET['eliminar_id'])) {
    $id_a_borrar = intval($_GET['eliminar_id']);
    
    $resOld = $mysqli->query("SELECT url_foto FROM colaborador WHERE id = $id_a_borrar");
    if ($rowOld = $resOld->fetch_assoc()) {
        if (!empty($rowOld['url_foto']) && file_exists($rowOld['url_foto'])) {
            unlink($rowOld['url_foto']);
        }
    }

    $stmtDel = $mysqli->prepare("DELETE FROM colaborador WHERE id = ?");
    $stmtDel->bind_param("i", $id_a_borrar);
    
    if ($stmtDel->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=deleted");
        exit();
    }
    $stmtDel->close();
}

/* ============================================================
    LÓGICA NUEVA: RETIRAR COLABORADOR (CAMBIO DE ESTADO)
   ============================================================ */
if (isset($_GET['retirar_id'])) {
    $id_a_retirar = intval($_GET['retirar_id']);
    $fecha_hoy = date('Y-m-d');
    
    $stmtRet = $mysqli->prepare("UPDATE colaborador SET estado = 'INACTIVO', fecha_retiro = ? WHERE id = ?");
    $stmtRet->bind_param("si", $fecha_hoy, $id_a_retirar);
    
    if ($stmtRet->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=retired");
        exit();
    }
    $stmtRet->close();
}

/* ============================================================
    LÓGICA 3: GUARDAR O ACTUALIZAR AUTOMÁTICAMENTE SI YA EXISTE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_colaborador'])) {
    $NitEmpresa = $_POST['nit_empresa_seleccionada'] ?? $NitEmpresaSession;
    $cedula     = $_POST['nit_seleccionado'];
    $salario    = floatval($_POST['salario']);
    $tipo_con   = $_POST['tipo_contrato'];
    $arl_num    = intval($_POST['nivel_arl']);
    $cargo      = $_POST['cargo'];
    $fecha      = $_POST['fecha_ingreso'];
    $email      = $_POST['email'] ?? null;

    $jornada          = $_POST['jornada_laboral'] ?? 'DIURNA';
    $fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
    $genero           = !empty($_POST['genero']) ? $_POST['genero'] : null;
    $estado_civil     = $_POST['estado_civil'] ?? 'SOLTERO';
    $grupo_sanguineo  = $_POST['grupo_sanguineo'] ?? null;
    $nivel_educativo  = $_POST['nivel_educativo'] ?? null;
    $estrato          = !empty($_POST['estrato_socioeconomico']) ? intval($_POST['estrato_socioeconomico']) : null;
    $direccion        = $_POST['direccion'] ?? null;
    $numero_cuenta    = $_POST['numero_cuenta'] ?? null;
    $llave_payment    = $_POST['llave_payment'] ?? null;
    $eps              = $_POST['eps'] ?? null;
    $fondo_pensiones  = $_POST['fondo_pensiones'] ?? null;
    $fondo_cesantias  = $_POST['fondo_cesantias'] ?? null;
    $arl_txt          = $_POST['arl'] ?? null;

    if (empty($NitEmpresa)) {
        $mensaje = "<div class='alert alert-danger fw-bold shadow-sm mb-3'>⚠️ Error: Debe seleccionar una empresa obligatoriamente.</div>";
    } else {
        $ruta_foto = null;

        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            $permitidas = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array($ext, $permitidas)) {
                $nombre_archivo = "collab_" . $cedula . "_" . time() . "." . $ext;
                $ruta_foto = $directorio_fotos . $nombre_archivo;
                move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_foto);
            }
        }

        // Sincronizar Tercero si no existe localmente
        $checkT = $mysqli->query("SELECT CedulaNit FROM terceros WHERE CedulaNit = '$cedula'");
        if ($checkT->num_rows == 0) {
            $encontradoTercero = false;
            
            if (isset($mysqliPos) && $mysqliPos) {
                $resC = $mysqliPos->query("SELECT nit, nombres, email FROM terceros WHERE nit = '$cedula' LIMIT 1");
                if ($resC && $rowC = $resC->fetch_assoc()) {
                    $nomC = $mysqli->real_escape_string($rowC['nombres']);
                    $emC  = $mysqli->real_escape_string($rowC['email'] ?? '');
                    $mysqli->query("INSERT INTO terceros (IdTercero, CedulaNit, Nombre, Email, Estado) VALUES ('$cedula', '$cedula', '$nomC', '$emC', 1)");
                    $encontradoTercero = true;
                }
            }

            if (!$encontradoTercero && isset($mysqliDrinks) && $mysqliDrinks) {
                $resD = $mysqliDrinks->query("SELECT nit, nombres, email FROM terceros WHERE nit = '$cedula' LIMIT 1");
                if ($resD && $rowD = $resD->fetch_assoc()) {
                    $nomD = $mysqli->real_escape_string($rowD['nombres']);
                    $emD  = $mysqli->real_escape_string($rowD['email'] ?? '');
                    $mysqli->query("INSERT INTO terceros (IdTercero, CedulaNit, Nombre, Email, Estado) VALUES ('$cedula', '$cedula', '$nomD', '$emD', 1)");
                }
            }
        }

        $stmtCheck = $mysqli->prepare("SELECT id FROM colaborador WHERE CedulaNit = ? AND NitEmpresa = ? AND (estado = 'ACTIVO' OR estado IS NULL)");
        $stmtCheck->bind_param("ss", $cedula, $NitEmpresa);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck->num_rows > 0) {
            $rowExisting = $resCheck->fetch_assoc();
            $idExistente = $rowExisting['id'];
            $stmtCheck->close();

            if (!empty($ruta_foto)) {
                $resOld = $mysqli->query("SELECT url_foto FROM colaborador WHERE id = $idExistente");
                if ($rowOld = $resOld->fetch_assoc()) {
                    if (!empty($rowOld['url_foto']) && file_exists($rowOld['url_foto'])) {
                        unlink($rowOld['url_foto']);
                    }
                }

                $sqlUpdAuto = "UPDATE colaborador SET 
                    salario = ?, tipo_contrato = ?, nivel_arl = ?, cargo = ?, 
                    jornada_laboral = ?, fecha_ingreso = ?, fecha_nacimiento = ?, genero = ?, estado_civil = ?, 
                    grupo_sanguineo = ?, nivel_educativo = ?, estrato_socioeconomico = ?, direccion = ?, numero_cuenta = ?, 
                    llave_payment = ?, eps = ?, fondo_pensiones = ?, fondo_cesantias = ?, arl = ?, email = ?, url_foto = ? 
                    WHERE id = ?";

                $stmtUpdAuto = $mysqli->prepare($sqlUpdAuto);
                // 22 tipos exactos para 22 variables
                $stmtUpdAuto->bind_param(
                    "dsissssssssisssssssssi",
                    $salario, $tipo_con, $arl_num, $cargo, 
                    $jornada, $fecha, $fecha_nacimiento, $genero, $estado_civil, 
                    $grupo_sanguineo, $nivel_educativo, $estrato, $direccion, $numero_cuenta, 
                    $llave_payment, $eps, $fondo_pensiones, $fondo_cesantias, $arl_txt, $email,
                    $ruta_foto, $idExistente
                );
            } else {
                $sqlUpdAuto = "UPDATE colaborador SET 
                    salario = ?, tipo_contrato = ?, nivel_arl = ?, cargo = ?, 
                    jornada_laboral = ?, fecha_ingreso = ?, fecha_nacimiento = ?, genero = ?, estado_civil = ?, 
                    grupo_sanguineo = ?, nivel_educativo = ?, estrato_socioeconomico = ?, direccion = ?, numero_cuenta = ?, 
                    llave_payment = ?, eps = ?, fondo_pensiones = ?, fondo_cesantias = ?, arl = ?, email = ? 
                    WHERE id = ?";

                $stmtUpdAuto = $mysqli->prepare($sqlUpdAuto);
                // 21 tipos exactos para 21 variables
                $stmtUpdAuto->bind_param(
                    "dsissssssssissssssssi",
                    $salario, $tipo_con, $arl_num, $cargo, 
                    $jornada, $fecha, $fecha_nacimiento, $genero, $estado_civil, 
                    $grupo_sanguineo, $nivel_educativo, $estrato, $direccion, $numero_cuenta, 
                    $llave_payment, $eps, $fondo_pensiones, $fondo_cesantias, $arl_txt, $email,
                    $idExistente
                );
            }

            if ($stmtUpdAuto->execute()) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=updated");
                exit();
            }
            $stmtUpdAuto->close();

        } else {
            $stmtCheck->close();

            $stmtIns = $mysqli->prepare("INSERT INTO colaborador (
                CedulaNit, NitEmpresa, IdSucursal, salario, tipo_contrato, nivel_arl, cargo, 
                jornada_laboral, fecha_ingreso, estado, fecha_nacimiento, genero, estado_civil, 
                grupo_sanguineo, nivel_educativo, estrato_socioeconomico, direccion, numero_cuenta, 
                llave_payment, eps, fondo_pensiones, fondo_cesantias, arl, url_foto, email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmtIns->bind_param(
                "ssidsissssssssisssssssss",
                $cedula, $NitEmpresa, $IdSucursal, $salario, $tipo_con, $arl_num, $cargo, 
                $jornada, $fecha, $fecha_nacimiento, $genero, $estado_civil, 
                $grupo_sanguineo, $nivel_educativo, $estrato, $direccion, $numero_cuenta, 
                $llave_payment, $eps, $fondo_pensiones, $fondo_cesantias, $arl_txt, $ruta_foto, $email
            );

            if ($stmtIns->execute()) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=success");
                exit();
            }
            $stmtIns->close();
        }
    }
}

/* ============================================================
    LÓGICA 4: ACTUALIZAR DATOS COMPLEMENTARIOS (MODAL EDITAR)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_complementarios'])) {
    $id_colaborador    = intval($_POST['id_colaborador']);
    $nuevo_nit_empresa = $_POST['edit_nit_empresa'];
    
    $salario          = floatval($_POST['edit_salario']);
    $tipo_con         = $_POST['edit_tipo_contrato'];
    $cargo            = $_POST['edit_cargo'];
    $jornada          = $_POST['edit_jornada_laboral'] ?? 'DIURNA';
    $arl_num          = intval($_POST['edit_nivel_arl']);
    $fecha_nacimiento = !empty($_POST['edit_fecha_nacimiento']) ? $_POST['edit_fecha_nacimiento'] : null;
    $genero           = !empty($_POST['edit_genero']) ? $_POST['edit_genero'] : null;
    $estado_civil     = $_POST['edit_estado_civil'] ?? 'SOLTERO';
    $grupo_sanguineo  = $_POST['edit_grupo_sanguineo'] ?? null;
    $nivel_educativo  = $_POST['edit_nivel_educativo'] ?? null;
    $estrato          = !empty($_POST['edit_estrato_socioeconomico']) ? intval($_POST['edit_estrato_socioeconomico']) : null;
    $direccion        = $_POST['edit_direccion'] ?? null;
    $numero_cuenta    = $_POST['edit_numero_cuenta'] ?? null;
    $llave_payment    = $_POST['edit_llave_payment'] ?? null;
    $eps              = $_POST['edit_eps'] ?? null;
    $fondo_pensiones  = $_POST['edit_fondo_pensiones'] ?? null;
    $fondo_cesantias  = $_POST['edit_fondo_cesantias'] ?? null;
    $arl_txt          = $_POST['edit_arl'] ?? null;
    $email            = $_POST['edit_email'] ?? null;

    $ruta_nueva = null;
    
    if (isset($_FILES['edit_foto']) && $_FILES['edit_foto']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['edit_foto']['name'], PATHINFO_EXTENSION));
        $permitidas = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($ext, $permitidas)) {
            $resOld = $mysqli->query("SELECT url_foto FROM colaborador WHERE id = $id_colaborador");
            if ($rowOld = $resOld->fetch_assoc()) {
                if (!empty($rowOld['url_foto']) && file_exists($rowOld['url_foto'])) {
                    unlink($rowOld['url_foto']);
                }
            }

            $nombre_archivo = "collab_" . $id_colaborador . "_" . time() . "." . $ext;
            $ruta_nueva = $directorio_fotos . $nombre_archivo;
            move_uploaded_file($_FILES['edit_foto']['tmp_name'], $ruta_nueva);
        }
    }

    if (!empty($ruta_nueva)) {
        $sqlUpd = "UPDATE colaborador SET 
            NitEmpresa = ?, salario = ?, tipo_contrato = ?, cargo = ?, jornada_laboral = ?, nivel_arl = ?, 
            fecha_nacimiento = ?, genero = ?, estado_civil = ?, grupo_sanguineo = ?, 
            nivel_educativo = ?, estrato_socioeconomico = ?, direccion = ?, numero_cuenta = ?, 
            llave_payment = ?, eps = ?, fondo_pensiones = ?, fondo_cesantias = ?, arl = ?, email = ?, url_foto = ? 
            WHERE id = ?";

        $stmtUpd = $mysqli->prepare($sqlUpd);
        $stmtUpd->bind_param(
            "sdsssisssssissssssssssi",
            $nuevo_nit_empresa, $salario, $tipo_con, $cargo, $jornada, $arl_num, 
            $fecha_nacimiento, $genero, $estado_civil, $grupo_sanguineo, 
            $nivel_educativo, $estrato, $direccion, $numero_cuenta, 
            $llave_payment, $eps, $fondo_pensiones, $fondo_cesantias, $arl_txt, $email,
            $ruta_nueva, $id_colaborador
        );
    } else {
        $sqlUpd = "UPDATE colaborador SET 
            NitEmpresa = ?, salario = ?, tipo_contrato = ?, cargo = ?, jornada_laboral = ?, nivel_arl = ?, 
            fecha_nacimiento = ?, genero = ?, estado_civil = ?, grupo_sanguineo = ?, 
            nivel_educativo = ?, estrato_socioeconomico = ?, direccion = ?, numero_cuenta = ?, 
            llave_payment = ?, eps = ?, fondo_pensiones = ?, fondo_cesantias = ?, arl = ?, email = ? 
            WHERE id = ?";

        $stmtUpd = $mysqli->prepare($sqlUpd);
        $stmtUpd->bind_param(
            "sdsssisssssissssssssi",
            $nuevo_nit_empresa, $salario, $tipo_con, $cargo, $jornada, $arl_num, 
            $fecha_nacimiento, $genero, $estado_civil, $grupo_sanguineo, 
            $nivel_educativo, $estrato, $direccion, $numero_cuenta, 
            $llave_payment, $eps, $fondo_pensiones, $fondo_cesantias, $arl_txt, $email,
            $id_colaborador
        );
    }

    if ($stmtUpd->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=updated");
        exit();
    }
    $stmtUpd->close();
}

/* ============================================================
    LÓGICA 5: GESTIÓN DE CONTACTOS DE EMERGENCIA
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_emergencia'])) {
    $colaborador_id  = intval($_POST['colaborador_id']);
    $nombre_contacto = $_POST['nombre_contacto'];
    $parentesco      = $_POST['parentesco'] ?? null;
    $celular_1       = $_POST['celular_1'];
    $celular_2       = $_POST['celular_2'] ?? null;
    $es_principal    = isset($_POST['es_principal']) ? 1 : 0;

    $stmtEmerg = $mysqli->prepare("INSERT INTO colaborador_emergencia (colaborador_id, nombre_contacto, parentesco, celular_1, celular_2, es_principal) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtEmerg->bind_param("issssi", $colaborador_id, $nombre_contacto, $parentesco, $celular_1, $celular_2, $es_principal);
    
    if ($stmtEmerg->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=emergencia_saved");
        exit();
    }
    $stmtEmerg->close();
}

if (isset($_GET['eliminar_emergencia_id'])) {
    $id_emergencia = intval($_GET['eliminar_emergencia_id']);
    $stmtDelEm = $mysqli->prepare("DELETE FROM colaborador_emergencia WHERE id = ?");
    $stmtDelEm->bind_param("i", $id_emergencia);
    if ($stmtDelEm->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=emergencia_deleted");
        exit();
    }
    $stmtDelEm->close();
}

// Mensajes del sistema
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $mensaje = "<div class='alert alert-warning fw-bold mb-3'>🗑️ Registro eliminado correctamente.</div>";
    if ($_GET['msg'] == 'success') $mensaje = "<div class='alert alert-success fw-bold mb-3'>✅ Colaborador guardado correctamente.</div>";
    if ($_GET['msg'] == 'retired') $mensaje = "<div class='alert alert-info fw-bold mb-3'>🚪 Colaborador marcado como INACTIVO (Retirado) exitosamente.</div>";
    if ($_GET['msg'] == 'updated') $mensaje = "<div class='alert alert-success fw-bold mb-3'>✏️ Datos del colaborador actualizados correctamente.</div>";
    if ($_GET['msg'] == 'emergencia_saved') $mensaje = "<div class='alert alert-success fw-bold mb-3'>🚨 Contacto de emergencia guardado correctamente.</div>";
    if ($_GET['msg'] == 'emergencia_deleted') $mensaje = "<div class='alert alert-warning fw-bold mb-3'>🗑️ Contacto de emergencia eliminado.</div>";
}

$resEmpresas = $mysqli->query("SELECT Nit, RazonSocial FROM empresa WHERE Estado = 1");

$resTerceros = $mysqli->query("SELECT CedulaNit as nit, Nombre as nombres FROM terceros WHERE Estado = 1 ORDER BY Nombre ASC");
if (!$resTerceros || $resTerceros->num_rows == 0) {
    $resTerceros = $mysqliPos->query("SELECT nit, nombres FROM terceros WHERE inactivo = 0 ORDER BY nombres ASC");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Colaboradores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body, html { height: 100%; background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .full-screen-container { min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; }
        .card-custom { border: none; border-radius: 15px; border-top: 6px solid #0d6efd; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body class="py-3 px-2 px-md-4">

<div class="container-fluid full-screen-container max-w-100" style="max-width: 1400px; margin: 0 auto;">
    
    <div>
        <?= $mensaje ?? '' ?>

        <!-- BARRA SUPERIOR DE SINCRONIZACIÓN -->
        <div class="card mb-3 shadow-sm border-0 rounded-4">
            <div class="card-body py-2 px-3 d-flex flex-column flex-sm-row justify-content-between align-items-center bg-white rounded-4 gap-2">
                <div>
                    <h6 class="mb-0 fw-bold text-primary">Sincronización de Datos</h6>
                    <small class="text-muted">Actualiza terceros desde las bases Central y Drinks</small>
                </div>
                <form method="POST" class="w-100 w-sm-auto text-end">
                    <button type="submit" name="importar_terceros" class="btn btn-outline-primary btn-sm fw-bold w-100 w-sm-auto">
                        📥 IMPORTAR DESDE CENTRAL Y DRINKS
                    </button>
                </form>
            </div>
        </div>

        <!-- CONTENEDOR PRINCIPAL / FORMULARIO COMPACTO -->
        <div class="card shadow card-custom mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mb-3 gap-2">
                    <h4 class="fw-bold text-dark mb-0 fs-5 fs-md-4">💼 Registro / Actualización de Colaborador</h4>
                    <button type="button" class="btn btn-dark btn-sm fw-bold w-100 w-sm-auto" data-bs-toggle="modal" data-bs-target="#modalListado">
                        📋 VER REGISTRADOS
                    </button>
                </div>
                
                <form method="POST" enctype="multipart/form-data" id="formColaborador">
                    <!-- Selector de Empresa y Tercero en Fila -->
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold small mb-1">Empresa / Sede a la que se vincula:</label>
                            <select name="nit_empresa_seleccionada" id="nit_empresa_seleccionada" class="form-select form-select-sm" required>
                                <option value="">-- Seleccione la Empresa --</option>
                                <?php 
                                if ($resEmpresas) {
                                    $resEmpresas->data_seek(0);
                                    while ($emp = $resEmpresas->fetch_assoc()): 
                                ?>
                                    <option value="<?= $emp['Nit'] ?>" <?= ($NitEmpresaSession == $emp['Nit']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['RazonSocial']) ?> (NIT: <?= $emp['Nit'] ?>)
                                    </option>
                                <?php 
                                    endwhile; 
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold small mb-1">Seleccionar Empleado (Terceros Sincronizados):</label>
                            <select name="nit_seleccionado" id="nit_seleccionado" class="form-select form-select-sm" required>
                                <option value="">-- Seleccione un Tercero --</option>
                                <?php 
                                if ($resTerceros) {
                                    while ($t = $resTerceros->fetch_assoc()): 
                                ?>
                                    <option value="<?= $t['nit'] ?>">
                                        <?= htmlspecialchars(trim(preg_replace('/\s+/', ' ', $t['nombres']))) ?> (<?= $t['nit'] ?>)
                                    </option>
                                <?php 
                                    endwhile; 
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div id="alertaExistente" class="alert alert-warning d-none py-2 small fw-bold mb-3">
                        ⚠️ Este colaborador ya cuenta con un registro activo en esta empresa. Los datos y su fotografía actual han sido cargados para su edición automática.
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12 text-center">
                            <div id="contenedorFotoActual" class="d-none mb-1">
                                <img id="imgFotoActual" src="" alt="Foto actual" width="70" height="70" class="rounded-circle object-fit-cover shadow-sm border border-2 border-primary"><br>
                                <small class="text-muted fw-bold">Fotografía registrada actualmente</small>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold mb-1">Fotografía del Colaborador (Subir nueva para reemplazar)</label>
                            <input type="file" name="foto" class="form-control form-control-sm" accept=".jpg, .jpeg, .png, .webp">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Cargo</label>
                            <input type="text" name="cargo" id="cargo" class="form-control form-control-sm" placeholder="Ej: Administrador" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Salario Mensual</label>
                            <input type="number" step="0.01" name="salario" id="salario" class="form-control form-control-sm" required placeholder="1750905">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Correo Electrónico</label>
                            <input type="email" name="email" id="email" class="form-control form-control-sm" placeholder="ejemplo@correo.com">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Tipo de Contrato</label>
                            <select name="tipo_contrato" id="tipo_contrato" class="form-select form-select-sm">
                                <option value="INDEFINIDO">Indefinido</option>
                                <option value="FIJO">Fijo</option>
                                <option value="OBRA">Obra o Labor</option>
                                <option value="SERVICIOS">Servicios</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Jornada Laboral</label>
                            <select name="jornada_laboral" id="jornada_laboral" class="form-select form-select-sm">
                                <option value="DIURNA">Diurna</option>
                                <option value="NOCTURNA">Nocturna</option>
                                <option value="MIXTA">Mixta</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Nivel ARL (1 al 5)</label>
                            <input type="number" name="nivel_arl" id="nivel_arl" class="form-control form-control-sm" value="1" min="1" max="5">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Fecha de Ingreso</label>
                            <input type="date" name="fecha_ingreso" id="fecha_ingreso" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Género</label>
                            <select name="genero" id="genero" class="form-select form-select-sm">
                                <option value="">-- Seleccione --</option>
                                <option value="MASCULINO">Masculino</option>
                                <option value="FEMENINO">Femenino</option>
                                <option value="OTRO">Otro</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Estado Civil</label>
                            <select name="estado_civil" id="estado_civil" class="form-select form-select-sm">
                                <option value="SOLTERO">Soltero(a)</option>
                                <option value="CASADO">Casado(a)</option>
                                <option value="UNION LIBRE">Unión Libre</option>
                                <option value="OTRO">Otro</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold mb-1">Grupo Sanguíneo</label>
                            <input type="text" name="grupo_sanguineo" id="grupo_sanguineo" class="form-control form-control-sm" placeholder="Ej: O+">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Nivel Educativo</label>
                            <input type="text" name="nivel_educativo" id="nivel_educativo" class="form-control form-control-sm" placeholder="Ej: Profesional">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Estrato Socioeconómico</label>
                            <input type="number" name="estrato_socioeconomico" id="estrato_socioeconomico" class="form-control form-control-sm" min="1" max="6">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Dirección de Vivienda</label>
                            <input type="text" name="direccion" id="direccion" class="form-control form-control-sm" maxlength="150" placeholder="Ej: Calle 100 # 15-20">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Número de Cuenta</label>
                            <input type="text" name="numero_cuenta" id="numero_cuenta" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Llave Payment</label>
                            <input type="text" name="llave_payment" id="llave_payment" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">EPS</label>
                            <input type="text" name="eps" id="eps" class="form-control form-control-sm" placeholder="Ej: Sura, Sanitas">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Fondo de Pensiones</label>
                            <input type="text" name="fondo_pensiones" id="fondo_pensiones" class="form-control form-control-sm" placeholder="Ej: Porvenir">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">Fondo de Cesantías</label>
                            <input type="text" name="fondo_cesantias" id="fondo_cesantias" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold mb-1">ARL (Entidad)</label>
                            <input type="text" name="arl" id="arl" class="form-control form-control-sm" placeholder="Ej: Sura, Positiva">
                        </div>
                    </div>

                    <button type="submit" name="guardar_colaborador" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                        💾 GUARDAR / ACTUALIZAR EN NÓMINA LOCAL
                    </button>
                </form>
            </div>
        </div>
    </div>

    <footer class="text-center py-2 text-muted small">
        &copy; <?= date('Y') ?> Gestión de Nómina y Colaboradores. Todos los derechos reservados.
    </footer>
</div>

<!-- MODAL LISTADO Y GESTIÓN DE COLABORADORES -->
<div class="modal fade" id="modalListado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header bg-dark text-white p-3">
                <h5 class="modal-title fw-bold fs-5">📋 Personal Registrado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-nowrap">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Foto</th>
                                <th>Identificación</th>
                                <th>Nombre Completo</th>
                                <th>Empresa (NIT)</th>
                                <th>Cargo</th>
                                <th>Salario</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sqlL = "SELECT c.*, t.Nombre, e.RazonSocial FROM colaborador c 
                                     LEFT JOIN terceros t ON c.CedulaNit = t.CedulaNit 
                                     LEFT JOIN empresa e ON c.NitEmpresa = e.Nit 
                                     ORDER BY c.id DESC";
                            $resL = $mysqli->query($sqlL);
                            while ($c = $resL->fetch_assoc()): 
                                $esActivo = (strtoupper($c['estado'] ?? 'ACTIVO') === 'ACTIVO');
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($c['url_foto']) && file_exists($c['url_foto'])): ?>
                                        <img src="<?= $c['url_foto'] ?>" alt="Foto" width="35" height="35" class="rounded-circle object-fit-cover">
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Sin foto</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?= $c['CedulaNit'] ?></td>
                                <td><?= htmlspecialchars($c['Nombre'] ?? 'No sincronizado') ?></td>
                                <td><small class="fw-bold text-muted"><?= htmlspecialchars($c['RazonSocial'] ?? $c['NitEmpresa']) ?></small></td>
                                <td><?= htmlspecialchars($c['cargo']) ?></td>
                                <td class="fw-bold">$<?= number_format($c['salario'], 0) ?></td>
                                <td class="text-center">
                                    <?php if ($esActivo): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" title="Retirado el: <?= $c['fecha_retiro'] ?? 'N/A' ?>">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-link text-primary p-0 me-2" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $c['id'] ?>" title="Editar Datos Complementarios">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-pencil-square" viewBox="0 0 16 16">
                                            <path d="M15.502 1.94a.5.5 0 0 1 0 .706L14.459 3.69l-2-2L13.502.646a.5.5 0 0 1 .707 0l1.293 1.293zm-1.75 2.456-2-2L4.939 9.21a.5.5 0 0 0-.121.196l-.805 2.414a.25.25 0 0 0 .316.316l2.414-.805a.5.5 0 0 0 .196-.12l6.813-6.814z"/>
                                            <path fill-rule="evenodd" d="M1 13.5A1.5 1.5 0 0 0 2.5 15h11a1.5 1.5 0 0 0 1.5-1.5v-6a.5.5 0 0 0-1 0v6a.5.5 0 0 1-.5.5h-11a.5.5 0 0 1-.5-.5v-11a.5.5 0 0 1 .5-.5H9a.5.5 0 0 0 0-1H2.5A1.5 1.5 0 0 0 1 2.5v11z"/>
                                        </svg>
                                    </button>

                                    <button type="button" class="btn btn-link text-danger p-0 me-2" data-bs-toggle="modal" data-bs-target="#modalEmergencia<?= $c['id'] ?>" title="Contactos de Emergencia">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-heart-pulse-fill" viewBox="0 0 16 16">
                                            <path d="M1.475 9C2.702 10.84 4.779 12.871 8 15c3.221-2.129 5.298-4.16 6.525-6H12a.5.5 0 0 1-.464-.314l-1.457-3.642-1.598 5.593a.5.5 0 0 1-.945.049L7.021 3.205 5.365 7.487A.5.5 0 0 1 4.9 8H1.475z"/>
                                            <path d="M3.229 1.741C2.556 2.378 2 3.352 2 4.65v.001c0 .54.148 1.055.409 1.5H1.475C1.103 5.485 1 5.08 1 4.65 1 2.94 1.83 1.77 2.729.98c.5-.44 1.1-.81 1.771-1.09-.27.46-.77 1.25-1.27 1.85z"/>
                                        </svg>
                                    </button>

                                    <?php if ($esActivo): ?>
                                        <a href="?retirar_id=<?= $c['id'] ?>" class="btn btn-link text-warning p-0 me-2" onclick="return confirm('¿Está seguro de registrar el RETIRO de este colaborador?');" title="Retirar Colaborador">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-person-x-fill" viewBox="0 0 16 16">
                                                <path fill-rule="evenodd" d="M1 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m6.146-2.854a.5.5 0 0 1 .708 0L14 6.293l1.146-1.147a.5.5 0 0 1 .708.708L14.707 7l1.146 1.147a.5.5 0 0 1-.708.708L14 7.707l-1.146 1.147a.5.5 0 0 1-.708-.708L14 7.707l-1.146 1.146a.5.5 0 0 1 0-.708"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <a href="?eliminar_id=<?= $c['id'] ?>" class="btn btn-link text-danger p-0" onclick="return confirm('¿Eliminar por completo este registro?');" title="Eliminar Registro">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-trash-fill" viewBox="0 0 16 16">
                                            <path d="M2.5 1a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4h.5a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1zm3 4a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 .5-.5M8 5a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7A.5.5 0 0 1 8 5m3 .5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 1 0"/>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODALES DINÁMICOS DE EDICIÓN Y EMERGENCIA -->
<?php
$resL->data_seek(0);
while ($c = $resL->fetch_assoc()):
    $resEm = $mysqli->query("SELECT * FROM colaborador_emergencia WHERE colaborador_id = " . intval($c['id']));
?>

<!-- MODAL EDITAR COMPLEMENTARIOS -->
<div class="modal fade" id="modalEditar<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header bg-primary text-white p-3">
                <h5 class="modal-title fw-bold fs-5">✏️ Editar Complementos: <?= htmlspecialchars($c['Nombre'] ?? $c['CedulaNit']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-3">
                    <input type="hidden" name="id_colaborador" value="<?= $c['id'] ?>">
                    
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small fw-bold">Empresa / Sede Asociada</label>
                            <select name="edit_nit_empresa" class="form-select form-select-sm" required>
                                <?php 
                                if ($resEmpresas) {
                                    $resEmpresas->data_seek(0);
                                    while ($emp = $resEmpresas->fetch_assoc()): 
                                ?>
                                    <option value="<?= $emp['Nit'] ?>" <?= ($c['NitEmpresa'] == $emp['Nit']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['RazonSocial']) ?> (NIT: <?= $emp['Nit'] ?>)
                                    </option>
                                <?php 
                                    endwhile; 
                                }
                                ?>
                            </select>
                        </div>

                        <div class="col-12 text-center my-1">
                            <?php if (!empty($c['url_foto']) && file_exists($c['url_foto'])): ?>
                                <img src="<?= $c['url_foto'] ?>" alt="Foto actual" width="70" height="70" class="rounded-circle object-fit-cover shadow-sm mb-1"><br>
                                <small class="text-muted">Foto actual almacenada</small>
                            <?php else: ?>
                                <span class="badge bg-secondary mb-1">Sin fotografía registrada</span><br>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Actualizar Fotografía (Opcional)</label>
                            <input type="file" name="edit_foto" class="form-control form-control-sm" accept=".jpg, .jpeg, .png, .webp">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Cargo</label>
                            <input type="text" name="edit_cargo" class="form-control form-control-sm" value="<?= htmlspecialchars($c['cargo'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Salario Mensual</label>
                            <input type="number" step="0.01" name="edit_salario" class="form-control form-control-sm" value="<?= $c['salario'] ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Correo Electrónico</label>
                            <input type="email" name="edit_email" class="form-control form-control-sm" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Tipo de Contrato</label>
                            <select name="edit_tipo_contrato" class="form-select form-select-sm">
                                <option value="INDEFINIDO" <?= ($c['tipo_contrato'] == 'INDEFINIDO') ? 'selected' : '' ?>>Indefinido</option>
                                <option value="FIJO" <?= ($c['tipo_contrato'] == 'FIJO') ? 'selected' : '' ?>>Fijo</option>
                                <option value="OBRA" <?= ($c['tipo_contrato'] == 'OBRA') ? 'selected' : '' ?>>Obra o Labor</option>
                                <option value="SERVICIOS" <?= ($c['tipo_contrato'] == 'SERVICIOS') ? 'selected' : '' ?>>Servicios</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Jornada Laboral</label>
                            <select name="edit_jornada_laboral" class="form-select form-select-sm">
                                <option value="DIURNA" <?= ($c['jornada_laboral'] == 'DIURNA') ? 'selected' : '' ?>>Diurna</option>
                                <option value="NOCTURNA" <?= ($c['jornada_laboral'] == 'NOCTURNA') ? 'selected' : '' ?>>Nocturna</option>
                                <option value="MIXTA" <?= ($c['jornada_laboral'] == 'MIXTA') ? 'selected' : '' ?>>Mixta</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Nivel ARL</label>
                            <input type="number" name="edit_nivel_arl" class="form-control form-control-sm" value="<?= $c['nivel_arl'] ?>" min="1" max="5">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Fecha de Nacimiento</label>
                            <input type="date" name="edit_fecha_nacimiento" class="form-control form-control-sm" value="<?= $c['fecha_nacimiento'] ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Género</label>
                            <select name="edit_genero" class="form-select form-select-sm">
                                <option value="">-- Seleccione --</option>
                                <option value="MASCULINO" <?= ($c['genero'] == 'MASCULINO') ? 'selected' : '' ?>>Masculino</option>
                                <option value="FEMENINO" <?= ($c['genero'] == 'FEMENINO') ? 'selected' : '' ?>>Femenino</option>
                                <option value="OTRO" <?= ($c['genero'] == 'OTRO') ? 'selected' : '' ?>>Otro</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Estado Civil</label>
                            <select name="edit_estado_civil" class="form-select form-select-sm">
                                <option value="SOLTERO" <?= ($c['estado_civil'] == 'SOLTERO') ? 'selected' : '' ?>>Soltero(a)</option>
                                <option value="CASADO" <?= ($c['estado_civil'] == 'CASADO') ? 'selected' : '' ?>>Casado(a)</option>
                                <option value="UNION LIBRE" <?= ($c['estado_civil'] == 'UNION LIBRE') ? 'selected' : '' ?>>Unión Libre</option>
                                <option value="OTRO" <?= ($c['estado_civil'] == 'OTRO') ? 'selected' : '' ?>>Otro</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Grupo Sanguíneo</label>
                            <input type="text" name="edit_grupo_sanguineo" class="form-control form-control-sm" value="<?= htmlspecialchars($c['grupo_sanguineo'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Nivel Educativo</label>
                            <input type="text" name="edit_nivel_educativo" class="form-control form-control-sm" value="<?= htmlspecialchars($c['nivel_educativo'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Estrato Socioeconómico</label>
                            <input type="number" name="edit_estrato_socioeconomico" class="form-control form-control-sm" value="<?= $c['estrato_socioeconomico'] ?>" min="1" max="6">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Dirección de Vivienda</label>
                            <input type="text" name="edit_direccion" class="form-control form-control-sm" value="<?= htmlspecialchars($c['direccion'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Número de Cuenta</label>
                            <input type="text" name="edit_numero_cuenta" class="form-control form-control-sm" value="<?= htmlspecialchars($c['numero_cuenta'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Llave Payment</label>
                            <input type="text" name="edit_llave_payment" class="form-control form-control-sm" value="<?= htmlspecialchars($c['llave_payment'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">EPS</label>
                            <input type="text" name="edit_eps" class="form-control form-control-sm" value="<?= htmlspecialchars($c['eps'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Fondo de Pensiones</label>
                            <input type="text" name="edit_fondo_pensiones" class="form-control form-control-sm" value="<?= htmlspecialchars($c['fondo_pensiones'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Fondo de Cesantías</label>
                            <input type="text" name="edit_fondo_cesantias" class="form-control form-control-sm" value="<?= htmlspecialchars($c['fondo_cesantias'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-bold">ARL (Entidad)</label>
                            <input type="text" name="edit_arl" class="form-control form-control-sm" value="<?= htmlspecialchars($c['arl'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <!-- BOTÓN POPUP AL LADO DE ARL EN EL MODAL DE EDICIÓN -->
                            <button type="button" class="btn btn-success btn-sm fw-bold text-white shadow-sm w-100 py-1" onclick="abrirPopupEmergencia(<?= $c['id'] ?>)" title="Crear Contacto de Emergencia vía Popup">
                                ➕ Emergencia (Popup)
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="actualizar_complementarios" class="btn btn-primary btn-sm fw-bold">💾 Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL CONTACTOS DE EMERGENCIA -->
<div class="modal fade" id="modalEmergencia<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header bg-danger text-white p-3">
                <h5 class="modal-title fw-bold fs-5">🚨 Contactos de Emergencia: <?= htmlspecialchars($c['Nombre'] ?? $c['CedulaNit']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                
                <h6 class="fw-bold text-secondary mb-2">Contactos Registrados</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle text-nowrap">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre</th>
                                <th>Parentesco</th>
                                <th>Celular 1</th>
                                <th>Celular 2</th>
                                <th class="text-center">Principal</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resEm->num_rows > 0): ?>
                                <?php while ($em = $resEm->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($em['nombre_contacto']) ?></td>
                                    <td><?= htmlspecialchars($em['parentesco'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($em['celular_1']) ?></td>
                                    <td><?= htmlspecialchars($em['celular_2'] ?? '-') ?></td>
                                    <td class="text-center">
                                        <?= $em['es_principal'] ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="?eliminar_emergencia_id=<?= $em['id'] ?>" class="text-danger" onclick="return confirm('¿Eliminar este contacto de emergencia?');">🗑️</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-2">No hay contactos de emergencia registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <hr class="my-2">
                <h6 class="fw-bold text-primary mb-2">Agregar Nuevo Contacto</h6>
                <form method="POST">
                    <input type="hidden" name="colaborador_id" value="<?= $c['id'] ?>">
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Nombre del Contacto</label>
                            <input type="text" name="nombre_contacto" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold">Parentesco</label>
                            <input type="text" name="parentesco" class="form-control form-control-sm" placeholder="Ej: Esposo(a), Madre">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Celular Principal</label>
                            <input type="text" name="celular_1" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold">Celular Opcional</label>
                            <input type="text" name="celular_2" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="es_principal" value="1" id="principal<?= $c['id'] ?>">
                                <label class="form-check-label small fw-bold" for="principal<?= $c['id'] ?>">
                                    ¿Es contacto principal?
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 text-end">
                        <button type="submit" name="guardar_emergencia" class="btn btn-danger btn-sm fw-bold">➕ Añadir Contacto</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php endwhile; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const selEmpresa = document.getElementById("nit_empresa_seleccionada");
    const selTercero = document.getElementById("nit_seleccionado");
    const alerta = document.getElementById("alertaExistente");
    const contenedorFoto = document.getElementById("contenedorFotoActual");
    const imgFotoActual = document.getElementById("imgFotoActual");

    function verificarColaboradorExistente() {
        const nitEmpresa = selEmpresa.value;
        const cedula = selTercero.value;

        if (nitEmpresa && cedula) {
            fetch(`?ajax_consultar=1&nit_empresa=${nitEmpresa}&cedula=${cedula}`)
                .then(response => response.json())
                .then(data => {
                    if (data.existe) {
                        const d = data.datos;
                        
                        document.getElementById("cargo").value = d.cargo || '';
                        document.getElementById("salario").value = d.salario || '';
                        document.getElementById("email").value = d.email || '';
                        document.getElementById("tipo_contrato").value = d.tipo_contrato || 'INDEFINIDO';
                        document.getElementById("jornada_laboral").value = d.jornada_laboral || 'DIURNA';
                        document.getElementById("nivel_arl").value = d.nivel_arl || 1;
                        document.getElementById("fecha_ingreso").value = d.fecha_ingreso || '';
                        document.getElementById("fecha_nacimiento").value = d.fecha_nacimiento || '';
                        document.getElementById("genero").value = d.genero || '';
                        document.getElementById("estado_civil").value = d.estado_civil || 'SOLTERO';
                        document.getElementById("grupo_sanguineo").value = d.grupo_sanguineo || '';
                        document.getElementById("nivel_educativo").value = d.nivel_educativo || '';
                        document.getElementById("estrato_socioeconomico").value = d.estrato_socioeconomico || '';
                        document.getElementById("direccion").value = d.direccion || '';
                        document.getElementById("numero_cuenta").value = d.numero_cuenta || '';
                        document.getElementById("llave_payment").value = d.llave_payment || '';
                        document.getElementById("eps").value = d.eps || '';
                        document.getElementById("fondo_pensiones").value = d.fondo_pensiones || '';
                        document.getElementById("fondo_cesantias").value = d.fondo_cesantias || '';
                        document.getElementById("arl").value = d.arl || '';

                        if (d.url_foto && d.url_foto.trim() !== "") {
                            imgFotoActual.src = d.url_foto;
                            contenedorFoto.classList.remove("d-none");
                        } else {
                            contenedorFoto.classList.add("d-none");
                        }

                        alerta.classList.remove("d-none");
                    } else {
                        alerta.classList.add("d-none");
                        contenedorFoto.classList.add("d-none");
                        imgFotoActual.src = "";
                        document.getElementById("email").value = "";
                        document.getElementById("direccion").value = "";
                    }
                })
                .catch(error => console.error("Error al consultar colaborador:", error));
        } else {
            alerta.classList.add("d-none");
            contenedorFoto.classList.add("d-none");
        }
    }

    selEmpresa.addEventListener("change", verificarColaboradorExistente);
    selTercero.addEventListener("change", verificarColaboradorExistente);
});

// Función para abrir la ventana emergente tipo Popup (CrearColaboradorEmergencia.php)
function abrirPopupEmergencia(idColaborador) {
    const ancho = 600;
    const alto = 700;
    const x = (screen.width - ancho) / 2;
    const y = (screen.height - alto) / 2;
    
    window.open(
        `CrearColaboradorEmergencia.php?id=${idColaborador}`,
        'PopupEmergencia',
        `width=${ancho},height=${alto},left=${x},top=${y},resizable=yes,scrollbars=yes,status=yes`
    );
}
</script>
</body>
</html>