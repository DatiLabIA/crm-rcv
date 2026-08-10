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
require_once DOL_DOCUMENT_ROOT . '/custom/cabinetmed_calendar/lib/cabinetmed_calendar.lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(array('success' => false, 'error' => 'Method not allowed')); exit;
}

if (!isModEnabled('cabinetmedcalendar')) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Module not enabled')); exit;
}

$perm_read = !empty($user->rights->cabinetmed_calendar->read);
if (!$perm_read) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Access denied')); exit;
}

// CSRF
$token = GETPOST('token', 'alpha');
if (empty($token) || empty($_SESSION['newtoken']) || $token !== $_SESSION['newtoken']) {
    http_response_code(403); echo json_encode(array('success' => false, 'error' => 'Invalid CSRF token')); exit;
}

$id     = GETPOST('id',     'int');
$color  = GETPOST('color',  'alpha');
$remove = GETPOST('remove', 'int');

if (empty($id) || $id <= 0) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'Invalid ID')); exit;
}

// Verificar existencia
$sql_c = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons"
       . " WHERE rowid = " . (int)$id . " AND entity IN (" . getEntity('consultation') . ")";
$res_c = $db->query($sql_c);
if (!$res_c || $db->num_rows($res_c) === 0) {
    http_response_code(404); echo json_encode(array('success' => false, 'error' => 'Consultation not found')); exit;
}
$db->free($res_c);

// Eliminar color
if ($remove == 1) {
    $sql_del = "DELETE FROM " . MAIN_DB_PREFIX . "cabinetmed_calendar_colors"
             . " WHERE fk_extcons = " . (int)$id . " AND fk_user = " . (int)$user->id;
    if (!$db->query($sql_del)) {
        http_response_code(500); echo json_encode(array('success' => false, 'error' => $db->lasterror())); exit;
    }
    echo json_encode(array('success' => true, 'color' => null, 'removed' => true));
    exit;
}

// Validar y normalizar color hex
if ($color && $color[0] !== '#') $color = '#' . $color;
if (!cabinetmed_calendar_is_valid_hex_color($color)) {
    http_response_code(400); echo json_encode(array('success' => false, 'error' => 'Invalid hex color')); exit;
}
if (strlen($color) === 4) $color = '#' . $color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
$color = strtoupper($color);

// UPSERT
$now_db = $db->idate(dol_now());
$sql_u  = "INSERT INTO " . MAIN_DB_PREFIX . "cabinetmed_calendar_colors (fk_extcons, fk_user, color, datec)";
$sql_u .= " VALUES (" . (int)$id . ", " . (int)$user->id . ", '" . $db->escape($color) . "', '" . $db->escape($now_db) . "')";
$sql_u .= " ON DUPLICATE KEY UPDATE color = '" . $db->escape($color) . "', tms = NOW()";

if (!$db->query($sql_u)) {
    http_response_code(500); echo json_encode(array('success' => false, 'error' => $db->lasterror())); exit;
}
echo json_encode(array('success' => true, 'color' => $color, 'id' => (int)$id));
exit;
