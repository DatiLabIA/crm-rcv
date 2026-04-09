<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/template_detail.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to get template details (body, variables, preview)
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

header('Content-Type: application/json');

// Access control - allow if user can read conversations or manage templates
if (!$user->rights->whatsappdati->conversation->read && !$user->rights->whatsappdati->template->write) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

$template_id = GETPOST('id', 'int');

if (empty($template_id)) {
	echo json_encode(array('success' => false, 'error' => 'Missing template ID'));
	exit;
}

$template = new WhatsAppTemplate($db);
if ($template->fetch($template_id) <= 0) {
	echo json_encode(array('success' => false, 'error' => 'Template not found'));
	exit;
}

// Parse variables - stored as JSON array like ["1","2","3"]
$variables = array();
if (!empty($template->variables)) {
	$decoded = json_decode($template->variables, true);
	if (is_array($decoded)) {
		$variables = $decoded;
	}
}

// Parse variable_mapping - stored as JSON object
$variableMapping = array();
if (!empty($template->variable_mapping)) {
	$decoded = json_decode($template->variable_mapping, true);
	if (is_array($decoded)) {
		$variableMapping = $decoded;
	}
}

echo json_encode(array(
	'success' => true,
	'template' => array(
		'id' => $template->id,
		'name' => $template->name,
		'language' => $template->language,
		'category' => $template->category,
		'status' => $template->status,
		'header_type' => $template->header_type,
		'header_content' => $template->header_content,
		'header_image_mode' => $template->header_image_mode ?: 'on_send',
		'header_media_url' => $template->header_media_url,
		'body_text' => $template->body_text,
		'footer_text' => $template->footer_text,
		'buttons' => $template->buttons,
		'variables' => $variables,
		'variable_mapping' => $variableMapping
	)
));

$db->close();
