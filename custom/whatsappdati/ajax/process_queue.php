<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/process_queue.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to create bulk batches and process/query queue status
 */

if (ob_get_level()) {
	ob_end_clean();
}

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
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
if (!$res) {
	die("Include of main fails");
}

require_once dol_buildpath('/whatsappdati/class/whatsappqueue.class.php', 0);
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

// Access control
if (!$user->rights->whatsappdati->message->send) {
	http_response_code(403);
	echo json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json; charset=UTF-8');

$action = GETPOST('action', 'aZ09');

// CSRF validation for mutation actions
if (in_array($action, array('create_batch', 'cancel_batch', 'process', 'cancel'))) {
	whatsappdatiCheckCSRFToken();
}

$queue = new WhatsAppQueue($db);

switch ($action) {
	// --------------------------------------------------
	// CREATE BATCH: Receives template + recipients, creates queue entries
	// --------------------------------------------------
	case 'create_batch':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			exit;
		}
		$inputJSON = file_get_contents('php://input');
		$input = json_decode($inputJSON, true);

		if (empty($input)) {
			echo json_encode(array('success' => false, 'error' => 'Invalid JSON input'));
			exit;
		}

		$templateId = (int) ($input['template_id'] ?? 0);
		$templateName = dol_string_nohtmltag(trim($input['template_name'] ?? ''));
		$recipients = is_array($input['recipients'] ?? null) ? $input['recipients'] : array();
		$params = is_array($input['params'] ?? null) ? $input['params'] : array();
		$lineId = (int) ($input['line_id'] ?? 0);

		// Sanitize each recipient
		foreach ($recipients as $k => $r) {
			$recipients[$k]['phone'] = dol_string_nohtmltag(trim($r['phone'] ?? ''));
			$recipients[$k]['name'] = dol_string_nohtmltag(trim($r['name'] ?? ''));
			$recipients[$k]['fk_soc'] = (int) ($r['fk_soc'] ?? 0);
		}

		if (empty($templateName)) {
			echo json_encode(array('success' => false, 'error' => $langs->trans("ErrorTemplateRequired")));
			exit;
		}
		if (empty($recipients)) {
			echo json_encode(array('success' => false, 'error' => $langs->trans("ErrorNoRecipients")));
			exit;
		}

		$result = $queue->createBulkBatch($user, $templateId, $templateName, $recipients, $params, 0, $lineId);

		echo json_encode(array(
			'success' => true,
			'batch_id' => $result['batch_id'],
			'total' => $result['total'],
			'created' => $result['created']
		));
		break;

	// --------------------------------------------------
	// PROCESS BATCH: Process pending items in a batch (called from browser)
	// --------------------------------------------------
	case 'process':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			exit;
		}
		$batchId = GETPOST('batch_id', 'alphanohtml');
		$limit = GETPOST('limit', 'int');
		if (empty($limit)) {
			$limit = 10; // Process 10 at a time to avoid timeouts
		}

		// Increase execution time for processing
		@set_time_limit(120);

		$result = $queue->processBatch($limit, $batchId);

		// Also return current stats
		$stats = array();
		if (!empty($batchId)) {
			$stats = $queue->getBatchStats($batchId);
		}

		echo json_encode(array(
			'success' => true,
			'processed' => $result['processed'],
			'sent' => $result['sent'],
			'failed' => $result['failed'],
			'stats' => $stats,
			'done' => ($stats['pending'] ?? 0) == 0 && ($stats['processing'] ?? 0) == 0
		));
		break;

	// --------------------------------------------------
	// BATCH STATUS: Get current stats for a batch
	// --------------------------------------------------
	case 'status':
		$batchId = GETPOST('batch_id', 'alphanohtml');
		if (empty($batchId)) {
			echo json_encode(array('success' => false, 'error' => 'Batch ID required'));
			exit;
		}

		$stats = $queue->getBatchStats($batchId);

		echo json_encode(array(
			'success' => true,
			'stats' => $stats,
			'done' => ($stats['pending'] == 0 && $stats['processing'] == 0)
		));
		break;

	// --------------------------------------------------
	// CANCEL BATCH: Cancel all pending items
	// --------------------------------------------------
	case 'cancel':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			exit;
		}
		$batchId = GETPOST('batch_id', 'alphanohtml');
		if (empty($batchId)) {
			echo json_encode(array('success' => false, 'error' => 'Batch ID required'));
			exit;
		}

		$cancelled = $queue->cancelBatch($batchId);

		echo json_encode(array(
			'success' => true,
			'cancelled' => $cancelled
		));
		break;

	default:
		echo json_encode(array('success' => false, 'error' => 'Unknown action'));
		break;
}
