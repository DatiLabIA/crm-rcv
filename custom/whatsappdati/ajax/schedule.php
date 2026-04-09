<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/schedule.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for scheduled message management
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
dol_include_once('/whatsappdati/class/whatsappschedule.class.php');

// Security check
if (!$user->rights->whatsappdati->message->send) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json');

require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

$action = GETPOST('action', 'alpha');

// CSRF validation for mutation actions
if (in_array($action, array('create', 'update', 'delete', 'pause', 'resume', 'cancel', 'send_now'))) {
	whatsappdatiCheckCSRFToken();
}

$schedule = new WhatsAppSchedule($db);

// ============================================
// ACTION: list - Get scheduled messages
// ============================================
if ($action === 'list') {
	$filter = GETPOST('filter', 'alpha');
	if (empty($filter)) {
		$filter = 'all';
	}
	$lineId = GETPOST('line_id', 'int');

	$results = $schedule->fetchAll($filter, 100, 0, $lineId);

	if (is_array($results)) {
		// Enrich with user name
		$userCache = array();
		foreach ($results as &$item) {
			if (!empty($item->fk_user_creat) && empty($userCache[$item->fk_user_creat])) {
				$tmpUser = new User($db);
				if ($tmpUser->fetch($item->fk_user_creat) > 0) {
					$userCache[$item->fk_user_creat] = $tmpUser->getFullName($langs);
				}
			}
			$item->user_name = $userCache[$item->fk_user_creat] ?? '';
			$item->scheduled_date_formatted = dol_print_date($db->jdate($item->scheduled_date), 'dayhour');
			$item->next_execution_formatted = !empty($item->next_execution) ? dol_print_date($db->jdate($item->next_execution), 'dayhour') : '';
			$item->last_execution_formatted = !empty($item->last_execution) ? dol_print_date($db->jdate($item->last_execution), 'dayhour') : '';
		}
		unset($item);

		echo json_encode(array('success' => true, 'schedules' => $results, 'stats' => $schedule->getStats()));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: fetch - Get a single scheduled message
// ============================================
if ($action === 'fetch') {
	$id = GETPOST('id', 'int');
	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $schedule->fetch($id);
	if ($ret > 0) {
		echo json_encode(array(
			'success' => true,
			'schedule' => array(
				'id' => $schedule->id,
				'phone_number' => $schedule->phone_number,
				'contact_name' => $schedule->contact_name,
				'fk_soc' => $schedule->fk_soc,
				'fk_line' => (int) $schedule->fk_line,
				'message_type' => $schedule->message_type,
				'message_content' => $schedule->message_content,
				'template_name' => $schedule->template_name,
				'template_params' => $schedule->template_params,
				'scheduled_date' => dol_print_date($schedule->scheduled_date, 'dayhourrfc'),
				'recurrence_type' => $schedule->recurrence_type,
				'recurrence_end_date' => $schedule->recurrence_end_date ? dol_print_date($schedule->recurrence_end_date, 'dayhourrfc') : '',
				'status' => $schedule->status,
				'execution_count' => $schedule->execution_count,
				'note' => $schedule->note,
			),
		));
	} else {
		echo json_encode(array('success' => false, 'error' => 'Scheduled message not found'));
	}
	exit;
}

// ============================================
// ACTION: create - Create a scheduled message
// ============================================
if ($action === 'create') {
	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input)) {
		$input = $_POST;
	}

	$schedule->phone_number = dol_string_nohtmltag(trim($input['phone_number'] ?? ''));
	$schedule->contact_name = dol_string_nohtmltag(trim($input['contact_name'] ?? ''));
	$schedule->fk_soc = (int) ($input['fk_soc'] ?? 0);
	$schedule->fk_line = (int) ($input['fk_line'] ?? 0);
	$schedule->message_type = dol_string_nohtmltag(trim($input['message_type'] ?? 'text'));
	$schedule->message_content = dol_string_nohtmltag(trim($input['message_content'] ?? ''));
	$schedule->template_name = dol_string_nohtmltag(trim($input['template_name'] ?? ''));
	$schedule->template_params = dol_string_nohtmltag(trim($input['template_params'] ?? ''));
	$schedule->note = dol_string_nohtmltag(trim($input['note'] ?? ''));
	$schedule->recurrence_type = dol_string_nohtmltag(trim($input['recurrence_type'] ?? 'once'));

	// Parse scheduled date
	$scheduledDateStr = $input['scheduled_date'] ?? '';
	if (!empty($scheduledDateStr)) {
		$schedule->scheduled_date = strtotime($scheduledDateStr);
	}

	// Parse recurrence end date
	$recEndStr = $input['recurrence_end_date'] ?? '';
	if (!empty($recEndStr)) {
		$schedule->recurrence_end_date = strtotime($recEndStr);
	}

	// Validate scheduled date is in the future
	if (!empty($schedule->scheduled_date) && $schedule->scheduled_date < dol_now()) {
		echo json_encode(array('success' => false, 'error' => 'ScheduleDateMustBeFuture'));
		exit;
	}

	$result = $schedule->create($user);

	if ($result > 0) {
		echo json_encode(array('success' => true, 'id' => $result));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: update - Update a scheduled message
// ============================================
if ($action === 'update') {
	$input = json_decode(file_get_contents('php://input'), true);
	if (empty($input)) {
		$input = $_POST;
	}

	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));
	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $schedule->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Not found'));
		exit;
	}

	// Only allow editing pending/paused messages
	if (!in_array($schedule->status, array('pending', 'paused', 'failed'))) {
		echo json_encode(array('success' => false, 'error' => 'CannotEditSentMessage'));
		exit;
	}

	if (isset($input['phone_number'])) $schedule->phone_number = dol_string_nohtmltag(trim($input['phone_number']));
	if (isset($input['contact_name'])) $schedule->contact_name = dol_string_nohtmltag(trim($input['contact_name']));
	if (isset($input['fk_soc'])) $schedule->fk_soc = (int) $input['fk_soc'];
	if (isset($input['fk_line'])) $schedule->fk_line = (int) $input['fk_line'];
	if (isset($input['message_type'])) $schedule->message_type = dol_string_nohtmltag(trim($input['message_type']));
	if (isset($input['message_content'])) $schedule->message_content = dol_string_nohtmltag(trim($input['message_content']));
	if (isset($input['template_name'])) $schedule->template_name = dol_string_nohtmltag(trim($input['template_name']));
	if (isset($input['template_params'])) $schedule->template_params = dol_string_nohtmltag(trim($input['template_params']));
	if (isset($input['note'])) $schedule->note = dol_string_nohtmltag(trim($input['note']));
	if (isset($input['recurrence_type'])) $schedule->recurrence_type = dol_string_nohtmltag(trim($input['recurrence_type']));

	if (!empty($input['scheduled_date'])) {
		$schedule->scheduled_date = strtotime($input['scheduled_date']);
		$schedule->next_execution = $schedule->scheduled_date;
	}
	if (isset($input['recurrence_end_date'])) {
		$schedule->recurrence_end_date = !empty($input['recurrence_end_date']) ? strtotime($input['recurrence_end_date']) : null;
	}

	// Reset retry on edit of failed messages
	if ($schedule->status === 'failed') {
		$schedule->status = 'pending';
		$schedule->retry_count = 0;
		$schedule->error_message = null;
	}

	$result = $schedule->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: delete - Delete a scheduled message
// ============================================
if ($action === 'delete') {
	$input = json_decode(file_get_contents('php://input'), true);
	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));

	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$schedule->id = $id;
	$result = $schedule->delete();

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: cancel - Cancel a pending scheduled message
// ============================================
if ($action === 'cancel') {
	$input = json_decode(file_get_contents('php://input'), true);
	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));

	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $schedule->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Not found'));
		exit;
	}

	$schedule->status = 'cancelled';
	$result = $schedule->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: pause - Pause a recurring scheduled message
// ============================================
if ($action === 'pause') {
	$input = json_decode(file_get_contents('php://input'), true);
	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));

	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $schedule->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Not found'));
		exit;
	}

	if ($schedule->status === 'paused') {
		// Resume
		$schedule->status = 'pending';
	} else {
		$schedule->status = 'paused';
	}
	$result = $schedule->update($user);

	if ($result > 0) {
		echo json_encode(array('success' => true, 'status' => $schedule->status));
	} else {
		echo json_encode(array('success' => false, 'error' => $schedule->error));
	}
	exit;
}

// ============================================
// ACTION: send_now - Send a scheduled message immediately
// ============================================
if ($action === 'send_now') {
	$input = json_decode(file_get_contents('php://input'), true);
	$id = (int) ($input['id'] ?? GETPOST('id', 'int'));

	if ($id <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid ID'));
		exit;
	}

	$ret = $schedule->fetch($id);
	if ($ret <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Not found'));
		exit;
	}

	if (!in_array($schedule->status, array('pending', 'paused', 'failed'))) {
		echo json_encode(array('success' => false, 'error' => 'Cannot send: status is ' . $schedule->status));
		exit;
	}

	$result = $schedule->execute();
	echo json_encode($result);
	exit;
}

// Unknown action
echo json_encode(array('success' => false, 'error' => 'Unknown action: ' . $action));
