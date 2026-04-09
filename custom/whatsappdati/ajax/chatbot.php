<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/chatbot.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for chatbot rule management
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
dol_include_once('/whatsappdati/class/whatsappchatbot.class.php');
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
if (in_array($action, array('create', 'update', 'delete', 'toggle'))) {
	whatsappdatiCheckCSRFToken();
}

$chatbot = new WhatsAppChatbot($db);

// ============================================
// ACTION: list - Get all chatbot rules
// ============================================
if ($action === 'list') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$results = $chatbot->fetchAll('all');

	if (is_array($results)) {
		// Enrich with stats
		foreach ($results as &$rule) {
			$rule->stats = $chatbot->getRuleStats($rule->rowid);
		}
		unset($rule);
		echo json_encode(array('success' => true, 'rules' => $results));
	} else {
		echo json_encode(array('success' => false, 'error' => $chatbot->error));
	}
	exit;
}

// ============================================
// ACTION: fetch - Get a single rule
// ============================================
if ($action === 'fetch') {
	$id = GETPOST('id', 'int');
	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $chatbot->fetch($id);
	if ($ret > 0) {
		echo json_encode(array(
			'success' => true,
			'rule' => array(
				'id' => $chatbot->id,
				'name' => $chatbot->name,
				'trigger_type' => $chatbot->trigger_type,
				'trigger_value' => $chatbot->trigger_value,
				'response_type' => $chatbot->response_type,
				'response_text' => $chatbot->response_text,
				'response_template_name' => $chatbot->response_template_name,
				'response_template_params' => $chatbot->response_template_params,
				'delay_seconds' => $chatbot->delay_seconds,
				'condition_type' => $chatbot->condition_type,
				'work_hours_start' => $chatbot->work_hours_start,
				'work_hours_end' => $chatbot->work_hours_end,
				'max_triggers_per_conv' => $chatbot->max_triggers_per_conv,
				'priority' => $chatbot->priority,
				'stop_on_match' => $chatbot->stop_on_match,
				'active' => $chatbot->active,
				'fk_line' => $chatbot->fk_line,
			),
			'stats' => $chatbot->getRuleStats($id),
		));
	} else {
		echo json_encode(array('success' => false, 'error' => 'Rule not found'));
	}
	exit;
}

// ============================================
// ACTION: create - Create a new rule
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

	$chatbot->name = dol_string_nohtmltag(trim($input['name'] ?? ''));
	$chatbot->trigger_type = dol_string_nohtmltag(trim($input['trigger_type'] ?? 'contains'));
	$chatbot->trigger_value = dol_string_nohtmltag(trim($input['trigger_value'] ?? ''));
	$chatbot->response_type = dol_string_nohtmltag(trim($input['response_type'] ?? 'text'));
	$chatbot->response_text = dol_string_nohtmltag(trim($input['response_text'] ?? ''));
	$chatbot->response_template_name = dol_string_nohtmltag(trim($input['response_template_name'] ?? ''));
	$chatbot->response_template_params = dol_string_nohtmltag(trim($input['response_template_params'] ?? ''));
	$chatbot->delay_seconds = (int) ($input['delay_seconds'] ?? 0);
	$chatbot->condition_type = dol_string_nohtmltag(trim($input['condition_type'] ?? 'always'));
	$chatbot->work_hours_start = dol_string_nohtmltag(trim($input['work_hours_start'] ?? '09:00:00'));
	$chatbot->work_hours_end = dol_string_nohtmltag(trim($input['work_hours_end'] ?? '18:00:00'));
	$chatbot->max_triggers_per_conv = (int) ($input['max_triggers_per_conv'] ?? 0);
	$chatbot->priority = (int) ($input['priority'] ?? 10);
	$chatbot->stop_on_match = (int) ($input['stop_on_match'] ?? 1);
	$chatbot->fk_line = isset($input['fk_line']) ? (int) $input['fk_line'] : 0;

	// Validate regex pattern if trigger_type is regex
	if ($chatbot->trigger_type === 'regex' && !empty($chatbot->trigger_value)) {
		$testPattern = $chatbot->trigger_value;
		if (substr($testPattern, 0, 1) !== '/') {
			$testPattern = '/' . $testPattern . '/iu';
		}
		if (@preg_match($testPattern, '') === false) {
			echo json_encode(array('success' => false, 'error' => 'Invalid regex pattern: ' . preg_last_error_msg()));
			exit;
		}
	}

	$result = $chatbot->create($user);

	if ($result > 0) {
		echo json_encode(array('success' => true, 'id' => $result));
	} else {
		echo json_encode(array('success' => false, 'error' => $chatbot->error));
	}
	exit;
}

// ============================================
// ACTION: update - Update a rule
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

	$ret = $chatbot->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Rule not found'));
		exit;
	}

	if (isset($input['name'])) $chatbot->name = dol_string_nohtmltag(trim($input['name']));
	if (isset($input['trigger_type'])) $chatbot->trigger_type = dol_string_nohtmltag(trim($input['trigger_type']));
	if (isset($input['trigger_value'])) $chatbot->trigger_value = dol_string_nohtmltag(trim($input['trigger_value']));
	if (isset($input['response_type'])) $chatbot->response_type = dol_string_nohtmltag(trim($input['response_type']));
	if (isset($input['response_text'])) $chatbot->response_text = dol_string_nohtmltag(trim($input['response_text']));
	if (isset($input['response_template_name'])) $chatbot->response_template_name = dol_string_nohtmltag(trim($input['response_template_name']));
	if (isset($input['response_template_params'])) $chatbot->response_template_params = dol_string_nohtmltag(trim($input['response_template_params']));
	if (isset($input['delay_seconds'])) $chatbot->delay_seconds = (int) $input['delay_seconds'];
	if (isset($input['condition_type'])) $chatbot->condition_type = dol_string_nohtmltag(trim($input['condition_type']));
	if (isset($input['work_hours_start'])) $chatbot->work_hours_start = dol_string_nohtmltag(trim($input['work_hours_start']));
	if (isset($input['work_hours_end'])) $chatbot->work_hours_end = dol_string_nohtmltag(trim($input['work_hours_end']));
	if (isset($input['max_triggers_per_conv'])) $chatbot->max_triggers_per_conv = (int) $input['max_triggers_per_conv'];
	if (isset($input['priority'])) $chatbot->priority = (int) $input['priority'];
	if (isset($input['stop_on_match'])) $chatbot->stop_on_match = (int) $input['stop_on_match'];
	if (isset($input['active'])) $chatbot->active = (int) $input['active'];
	if (isset($input['fk_line'])) $chatbot->fk_line = (int) $input['fk_line'];

	// Validate regex pattern if trigger_type is regex
	if ($chatbot->trigger_type === 'regex' && !empty($chatbot->trigger_value)) {
		$testPattern = $chatbot->trigger_value;
		if (substr($testPattern, 0, 1) !== '/') {
			$testPattern = '/' . $testPattern . '/iu';
		}
		if (@preg_match($testPattern, '') === false) {
			echo json_encode(array('success' => false, 'error' => 'Invalid regex pattern: ' . preg_last_error_msg()));
			exit;
		}
	}

	$result = $chatbot->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $chatbot->error));
	}
	exit;
}

// ============================================
// ACTION: delete - Delete a rule
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

	$chatbot->id = $id;
	$result = $chatbot->delete();

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $chatbot->error));
	}
	exit;
}

// ============================================
// ACTION: toggle - Toggle rule active/inactive
// ============================================
if ($action === 'toggle') {
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

	$ret = $chatbot->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Rule not found'));
		exit;
	}

	$chatbot->active = $chatbot->active ? 0 : 1;
	$result = $chatbot->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true, 'active' => $chatbot->active));
	} else {
		echo json_encode(array('success' => false, 'error' => $chatbot->error));
	}
	exit;
}

// ============================================
// ACTION: test - Test a message against rules
// ============================================
if ($action === 'test') {
	if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	$testMessage = $input['message'] ?? '';
	$isNew = !empty($input['is_new_conversation']);

	if (empty($testMessage)) {
		echo json_encode(array('success' => false, 'error' => 'Message text required'));
		exit;
	}

	// Temporarily enable chatbot for testing
	$origSetting = $conf->global->WHATSAPPDATI_CHATBOT_ENABLED;
	$conf->global->WHATSAPPDATI_CHATBOT_ENABLED = '1';

	$matches = $chatbot->findMatchingRules(0, $testMessage, 'text', $isNew);

	$conf->global->WHATSAPPDATI_CHATBOT_ENABLED = $origSetting;

	$matchedInfo = array();
	foreach ($matches as $rule) {
		$matchedInfo[] = array(
			'id' => $rule->rowid,
			'name' => $rule->name,
			'trigger_type' => $rule->trigger_type,
			'trigger_value' => $rule->trigger_value,
			'response_type' => $rule->response_type,
			'response_text' => mb_substr($rule->response_text, 0, 100),
			'priority' => $rule->priority,
		);
	}

	echo json_encode(array('success' => true, 'matches' => $matchedInfo, 'count' => count($matchedInfo)));
	exit;
}

// Unknown action
echo json_encode(array('success' => false, 'error' => 'Unknown action: ' . $action));
