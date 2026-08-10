<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * Endpoint AJAX: Devuelve las concentraciones asociadas a un medicamento.
 * Llamado desde medtriggers.js cuando el usuario cambia el select de medicamento.
 *
 * Parámetros POST:
 *   medicamento_id (int) - ID del medicamento seleccionado
 *
 * Respuesta: JSON array de { id, label }
 */

// Carga el entorno de Dolibarr
// Ajusta la profundidad según la ubicación: htdocs/custom/medtriggers/ajax/
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
// Para instalación en custom/
if (!$res && file_exists("../../../htdocs/main.inc.php")) $res = @include "../../../htdocs/main.inc.php";
if (!$res) {
    die('Error: No se pudo cargar main.inc.php');
}

// Seguridad: verificar que el usuario está logueado
if (!isset($user) || !is_object($user) || empty($user->id)) {
    http_response_code(403);
    echo json_encode(array('error' => 'No autorizado'));
    exit;
}

// Cabeceras de respuesta
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// Obtener y validar el parámetro
$medicamento_id = GETPOST('medicamento_id', 'int');

if (empty($medicamento_id) || $medicamento_id < 0) {
    echo json_encode(array());
    exit;
}

// Consulta preparada para evitar SQL injection
// Usa las tablas del módulo Gestion (gestion_medicamento_det)
$sql = "SELECT d.rowid, COALESCE(d.concentracion_display, d.concentracion) as concentracion";
$sql .= " FROM ".MAIN_DB_PREFIX."gestion_medicamento_det d";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."gestion_medicamento m ON m.rowid = d.fk_medicamento";
$sql .= " WHERE d.fk_medicamento = ".((int) $medicamento_id);
$sql .= " AND m.estado = 1";
$sql .= " ORDER BY d.concentracion ASC";

$result = array();

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $result[] = array(
            'id'    => (int) $obj->rowid,
            'label' => $obj->concentracion
        );
    }
    $db->free($resql);
} else {
    dol_syslog("MedTriggers AJAX error: ".$db->lasterror(), LOG_ERR);
    http_response_code(500);
    echo json_encode(array('error' => 'Error de consulta'));
    exit;
}

echo json_encode($result);
