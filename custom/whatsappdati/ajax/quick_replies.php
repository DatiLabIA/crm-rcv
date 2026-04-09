<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/quick_replies.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for quick reply management
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
dol_include_once('/whatsappdati/class/whatsappquickreply.class.php');
dol_include_once('/whatsappdati/lib/whatsappdati_ajax.lib.php');

// Security check
if (empty($user->rights->whatsappdati->conversation->read)) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

$action = GETPOST('action', 'alpha');

// CSRF validation for mutation actions
if (in_array($action, array('create', 'update', 'delete'))) {
	whatsappdatiCheckCSRFToken();
}

$quickReply = new WhatsAppQuickReply($db);

// ============================================
// ACTION: list - Get all active quick replies
// ============================================
if ($action === 'list') {
	$search = GETPOST('search', 'alpha');
	$category = GETPOST('category', 'alpha');

	$results = $quickReply->fetchAll('active', $category, $search);

	if (is_array($results)) {
		echo json_encode(array('success' => true, 'quick_replies' => $results));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// ============================================
// ACTION: list_admin - Get all quick replies (including inactive) for admin
// ============================================
if ($action === 'list_admin') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$results = $quickReply->fetchAll('all');

	if (is_array($results)) {
		echo json_encode(array('success' => true, 'quick_replies' => $results));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// ============================================
// ACTION: categories - Get distinct categories
// ============================================
if ($action === 'categories') {
	$categories = $quickReply->getCategories();

	if (is_array($categories)) {
		echo json_encode(array('success' => true, 'categories' => $categories));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// ============================================
// ACTION: create - Create a new quick reply
// ============================================
if ($action === 'create') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input)) {
		$input = $_POST;
	}

	$quickReply->shortcut = dol_string_nohtmltag(trim($input['shortcut'] ?? ''));
	$quickReply->title = dol_string_nohtmltag(trim($input['title'] ?? ''));
	$quickReply->content = dol_string_nohtmltag(trim($input['content'] ?? ''));
	$quickReply->category = dol_string_nohtmltag(trim($input['category'] ?? ''));
	$quickReply->position = (int) ($input['position'] ?? 0);

	$result = $quickReply->create($user);

	if ($result > 0) {
		echo json_encode(array('success' => true, 'id' => $result));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// ============================================
// ACTION: update - Update an existing quick reply
// ============================================
if ($action === 'update') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input)) {
		$input = $_POST;
	}

	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));
	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $quickReply->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Quick reply not found'));
		exit;
	}

	if (isset($input['shortcut'])) $quickReply->shortcut = dol_string_nohtmltag(trim($input['shortcut']));
	if (isset($input['title'])) $quickReply->title = dol_string_nohtmltag(trim($input['title']));
	if (isset($input['content'])) $quickReply->content = dol_string_nohtmltag(trim($input['content']));
	if (isset($input['category'])) $quickReply->category = dol_string_nohtmltag(trim($input['category']));
	if (isset($input['position'])) $quickReply->position = (int) $input['position'];
	if (isset($input['active'])) $quickReply->active = (int) $input['active'];

	$result = $quickReply->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// ============================================
// ACTION: delete - Delete a quick reply
// ============================================
if ($action === 'delete') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));

	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$quickReply->id = $id;
	$result = $quickReply->delete();

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $quickReply->error));
	}
	exit;
}

// Unknown action
echo json_encode(array('success' => false, 'error' => 'Unknown action: ' . $action));
