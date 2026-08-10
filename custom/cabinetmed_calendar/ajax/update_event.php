<?php
/* Copyright (C) 2024 DatiLab - Módulo Calendario de Consultas CabinetMed */

define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$res = 0;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/main.inc.php')) { $res = @include $dir . '/main.inc.php'; break; }
}
if (!$res) { http_response_code(500); die(json_encode(array('success' => false, 'error' => 'Cannot load Dolibarr environment'))); }

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(array('success' => false, 'error' => 'Method not allowed')); exit;
}

if (!isModEnabled('cabinetmedcalendar')) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Module not enabled')); exit;
}

$perm_write = !empty($user->rights->cabinetmed_calendar->write);
if (!$perm_write) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Access denied')); exit;
}

// CSRF
$token = GETPOST('token', 'alpha');
if (empty($token) || empty($_SESSION['newtoken']) || $token !== $_SESSION['newtoken']) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Invalid CSRF token')); exit;
}

$id         = GETPOST('id', 'int');
$date_start = GETPOST('date_start', 'alpha');
$date_end   = GETPOST('date_end',   'alpha');

if (empty($id) || $id <= 0) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'Invalid ID')); exit;
}
if (empty($date_start)) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'date_start required')); exit;
}

$ts_start = strtotime($date_start);
$ts_end   = !empty($date_end) ? strtotime($date_end) : null;

if (!$ts_start) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'Invalid date_start')); exit;
}
if ($ts_end !== null && $ts_end < $ts_start) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'date_end before date_start')); exit;
}

// Verificar existencia y entidad
$sql = "SELECT rowid, fk_user FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons"
     . " WHERE rowid = " . (int)$id . " AND entity IN (" . getEntity('consultation') . ")";
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) === 0) {
    http_response_code(404); echo json_encode(array('success' => false, 'error' => 'Consultation not found')); exit;
}
$consultation = $db->fetch_object($resql);
$db->free($resql);


// Actualizar
$db->begin();
$sql_upd  = "UPDATE " . MAIN_DB_PREFIX . "cabinetmed_extcons";
$sql_upd .= " SET date_start = '" . $db->escape($db->idate($ts_start)) . "'";
$sql_upd .= ", date_end = " . ($ts_end !== null ? "'" . $db->escape($db->idate($ts_end)) . "'" : "NULL");
$sql_upd .= ", tms = NOW()";
$sql_upd .= " WHERE rowid = " . (int)$id . " AND entity IN (" . getEntity('consultation') . ")";

if (!$db->query($sql_upd)) {
    $db->rollback();
    http_response_code(500); echo json_encode(array('success' => false, 'error' => 'DB error: ' . $db->lasterror())); exit;
}
$db->commit();

dol_syslog("cabinetmed_calendar: consultation #" . $id . " moved by user #" . $user->id, LOG_INFO);
echo json_encode(array('success' => true, 'id' => (int)$id));
exit;
