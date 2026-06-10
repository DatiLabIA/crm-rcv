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
if (in_array($action, array('create_batch', 'create_batch_from_filter', 'cancel_batch', 'process', 'cancel'))) {
	whatsappdatiCheckCSRFToken();
}

// Release the PHP session lock immediately so the rest of the CRM
// remains responsive while this long-running request executes.
// We've already authenticated and validated CSRF above — session is no longer needed.
session_write_close();

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
	// CREATE_BATCH_FROM_FILTER: Build queue entries directly from patient filters.
	// Avoids loading 20k recipients into the browser — the server queries and
	// inserts them all, then the browser only polls progress.
	// --------------------------------------------------
	case 'create_batch_from_filter':
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

		$templateId   = (int) ($input['template_id'] ?? 0);
		$templateName = dol_string_nohtmltag(trim($input['template_name'] ?? ''));
		$params       = is_array($input['params'] ?? null) ? $input['params'] : array();
		$lineId       = (int) ($input['line_id'] ?? 0);

		if (empty($templateName)) {
			echo json_encode(array('success' => false, 'error' => $langs->trans('ErrorTemplateRequired')));
			exit;
		}

		// Parse filter arrays
		$toInts = function($v) { return array_values(array_filter(array_map('intval', (array) $v))); };
		$f_programa    = $toInts($input['f_programa']    ?? array());
		$f_medicamento = $toInts($input['f_medicamento'] ?? array());
		$f_eps         = $toInts($input['f_eps']         ?? array());
		$f_operador    = $toInts($input['f_operador']    ?? array());
		$f_medico      = $toInts($input['f_medico']      ?? array());
		$f_estado      = $toInts($input['f_estado']      ?? array());
		$f_estadovital = $toInts($input['f_estadovital'] ?? array());
		$f_regimen     = $toInts($input['f_regimen']     ?? array());
		$f_biologico   = (int) ($input['f_biologico']    ?? 0);

		$joins = ' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields ef ON ef.fk_object = s.rowid';
		$where = " WHERE s.canvas = 'patient@cabinetmed' AND s.entity IN (".getEntity('societe').')';
		$where .= ' AND s.status = 1';
		$where .= " AND (s.phone != '' OR s.phone_mobile != '' OR s.fax != '')";
		if (!empty($f_programa))    $where .= ' AND ef.programa IN ('.implode(',', $f_programa).')';
		if (!empty($f_medicamento)) $where .= ' AND ef.medicamento IN ('.implode(',', $f_medicamento).')';
		if (!empty($f_eps))         $where .= ' AND ef.eps IN ('.implode(',', $f_eps).')';
		if (!empty($f_operador))    $where .= ' AND ef.operador_logistico IN ('.implode(',', $f_operador).')';
		if (!empty($f_medico))      $where .= ' AND ef.medico_tratante IN ('.implode(',', $f_medico).')';
		if (!empty($f_estado))      $where .= ' AND ef.estado_del_paciente IN ('.implode(',', $f_estado).')';
		if (!empty($f_estadovital)) $where .= ' AND ef.estado_vital IN ('.implode(',', $f_estadovital).')';
		if (!empty($f_regimen))     $where .= ' AND ef.regimen IN ('.implode(',', $f_regimen).')';
		if ($f_biologico == 1)      $where .= ' AND ef.biologico = 1';
		if ($f_biologico == -1)     $where .= ' AND (ef.biologico = 0 OR ef.biologico IS NULL)';

		$sql = 'SELECT DISTINCT s.rowid, s.nom, s.phone, s.phone_mobile, s.fax'
			.' FROM '.MAIN_DB_PREFIX.'societe s'.$joins.$where
			.' ORDER BY s.nom ASC';

		@set_time_limit(300); // Up to 5 min to insert large batches
		$resql = $db->query($sql);
		if (!$resql) {
			echo json_encode(array('success' => false, 'error' => $db->lasterror()));
			exit;
		}

		// Phone validation helper: strip non-digits, require at least 7 digits
		$isValidPhone = function($num) {
			if (empty($num)) return false;
			$digits = preg_replace('/[^0-9]/', '', $num);
			return strlen($digits) >= 7;
		};

		$recipients = array();
		$skipped = 0;
		while ($obj = $db->fetch_object($resql)) {
			// Priority: celular (phone_mobile) → teléfono (phone) → teléfono responsable (fax)
			$phone = null;
			$phoneType = null;
			foreach (array(
				array('mobile', $obj->phone_mobile),
				array('phone',  $obj->phone),
				array('fax',    $obj->fax),
			) as $candidate) {
				if ($isValidPhone($candidate[1])) {
					$phone = $candidate[1];
					$phoneType = $candidate[0];
					break;
				}
			}
			if (empty($phone)) { $skipped++; continue; }
			$recipients[] = array(
				'phone'  => $phone,
				'name'   => $obj->nom,
				'fk_soc' => (int) $obj->rowid,
			);
		}

		if (empty($recipients)) {
			echo json_encode(array('success' => false, 'error' => $langs->trans('ErrorNoRecipients')));
			exit;
		}

		$result = $queue->createBulkBatch($user, $templateId, $templateName, $recipients, $params, 0, $lineId);

		echo json_encode(array(
			'success'  => true,
			'batch_id' => $result['batch_id'],
			'total'    => $result['total'],
			'created'  => $result['created'],
			'skipped'  => $skipped,
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
			$limit = 50; // Process 50 at a time
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

	// --------------------------------------------------
	// GET_ERRORS: Return failed items with error messages
	// --------------------------------------------------
	case 'get_errors':
		$batchId = GETPOST('batch_id', 'alphanohtml');
		if (empty($batchId)) {
			echo json_encode(array('success' => false, 'error' => 'Batch ID required'));
			exit;
		}
		$sql = "SELECT phone_number, contact_name, error_message"
			." FROM ".MAIN_DB_PREFIX."whatsapp_queue"
			." WHERE batch_id = '".$db->escape($batchId)."'"
			." AND status = 'failed'"
			." AND entity = ".(int) $conf->entity
			." ORDER BY rowid DESC LIMIT 20";
		$resql = $db->query($sql);
		$errors = array();
		while ($resql && $obj = $db->fetch_object($resql)) {
			$errors[] = array(
				'phone'   => $obj->phone_number,
				'name'    => $obj->contact_name,
				'error'   => $obj->error_message,
			);
		}
		echo json_encode(array('success' => true, 'errors' => $errors));
		break;

	default:
		echo json_encode(array('success' => false, 'error' => 'Unknown action'));
		break;
}
