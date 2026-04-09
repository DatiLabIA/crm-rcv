<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/sync_templates.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to sync templates from Meta
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once '../class/whatsapptemplate.class.php';
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

header('Content-Type: application/json');

// Access control
if (!$user->rights->whatsappdati->template->write && GETPOST('action', 'alpha') !== 'list') {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

// List templates (read-only, also allowed with conversation read permission)
$action = GETPOST('action', 'alpha');

// CSRF validation for mutation actions
if ($action === 'sync') {
	whatsappdatiCheckCSRFToken();
}
if ($action === 'list') {
	if (!$user->rights->whatsappdati->conversation->read && !$user->rights->whatsappdati->template->write) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}
	$lineId = GETPOSTINT('line_id');
	$template = new WhatsAppTemplate($db);
	$templates = $template->fetchAll('approved', $lineId);
	// Enrich with variables for modal rendering
	$enriched = array();
	foreach ($templates as $tpl) {
		$item = (array) $tpl;
		// variables may be stored in DB but not fetched by fetchAll, set empty if missing
		if (!isset($item['variables'])) {
			$item['variables'] = '[]';
		}
		$enriched[] = $item;
	}
	echo json_encode(array('success' => true, 'templates' => $enriched));
	$db->close();
	exit;
}

// Sync templates - require POST to prevent CSRF via GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(array('success' => false, 'error' => 'Method not allowed. Use POST.'));
	exit;
}

// Sync templates (per-line or first active)
$lineId = GETPOSTINT('line_id');
$template = new WhatsAppTemplate($db);
$result = $template->syncFromMeta($lineId);

if ($result > 0) {
	echo json_encode(array(
		'success' => true,
		'count' => $result,
		'message' => $result.' templates synchronized'
	));
} else {
	echo json_encode(array(
		'success' => false,
		'error' => 'Error synchronizing templates'
	));
}

$db->close();
