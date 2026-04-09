<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       bulk_send.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Bulk Send page - send templates to multiple recipients
 */

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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once './class/whatsapptemplate.class.php';
require_once './class/whatsappqueue.class.php';
require_once './class/whatsappconfig.class.php';

// Ensure connection supports emojis (utf8mb4)
$db->query("SET NAMES utf8mb4");

// Translations
$langs->loadLangs(array("whatsappdati@whatsappdati"));

// Access control
if (!$user->rights->whatsappdati->message->send) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$view = GETPOST('view', 'alpha');
if (empty($view)) {
	$view = 'new'; // new, history
}

$form = new Form($db);
$queue = new WhatsAppQueue($db);

/*
 * Actions
 */

// Handle cancel batch action
if ($action == 'cancel_batch') {
	$batchId = GETPOST('batch_id', 'alpha');
	if (!empty($batchId)) {
		$cancelled = $queue->cancelBatch($batchId);
		setEventMessages($langs->trans("BatchCancelled", $cancelled), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?view=history");
		exit;
	}
}

/*
 * View
 */

$title = $langs->trans("BulkSend");
$jsFile = '/custom/whatsappdati/js/whatsappdati.js';
$cssFile = '/custom/whatsappdati/css/whatsappdati.css';
$jsFilePath = dol_buildpath($jsFile, 0);
$cssFilePath = dol_buildpath($cssFile, 0);
$jsVersion = file_exists($jsFilePath) ? filemtime($jsFilePath) : dol_now();
$cssVersion = file_exists($cssFilePath) ? filemtime($cssFilePath) : dol_now();
$morejs = array($jsFile.'?v='.$jsVersion);
$morecss = array($cssFile.'?v='.$cssVersion);

llxHeader('', $title, '', '', 0, 0, $morejs, $morecss);

print load_fiche_titre($title, '', 'whatsappdati@whatsappdati');

// Sub-tabs for New / History
$head = array();
$head[] = array(
	dol_buildpath('/custom/whatsappdati/bulk_send.php', 1).'?view=new',
	$langs->trans("NewBulkSend"),
	'new',
	'',
	'',
	'',
	($view == 'new') ? 1 : 0
);
$head[] = array(
	dol_buildpath('/custom/whatsappdati/bulk_send.php', 1).'?view=history',
	$langs->trans("BulkSendHistory"),
	'history',
	'',
	'',
	'',
	($view == 'history') ? 1 : 0
);

print '<div class="tabs" data-role="controlgroup" data-type="horizontal">';
foreach ($head as $tab) {
	$active = !empty($tab[6]) ? ' class="tabactive"' : '';
	print '<div class="inline-block tabsElem"'.$active.'>';
	print '<a class="tab inline-block" href="'.$tab[0].'">'.$tab[1].'</a>';
	print '</div>';
}
print '</div>';
print '<div class="tabBar">';

// CSRF token for AJAX calls
print '<input type="hidden" name="token" id="csrf-token" value="'.newToken().'">';

// H34: Inject i18n translations for JS (same as conversations.php)
$jsTranslations = array(
	'SelectTemplate' => $langs->trans('JsSelectTemplate'),
	'ErrorPrefix' => $langs->trans('JsErrorPrefix'),
	'ConnectionError' => $langs->trans('JsConnectionError'),
	'Sending' => $langs->trans('JsSending'),
	'Send' => $langs->trans('JsSend'),
	'UnknownError' => $langs->trans('JsUnknownError'),
	'BulkSync' => $langs->trans('JsBulkSync'),
	'BulkNoVars' => $langs->trans('JsBulkNoVars'),
	'BulkSearching' => $langs->trans('JsBulkSearching'),
	'BulkNoContacts' => $langs->trans('JsBulkNoContacts'),
	'BulkSelected' => $langs->trans('JsBulkSelected'),
	'BulkStartSend' => $langs->trans('JsBulkStartSend'),
	'ValueForVar' => $langs->trans('JsValueForVar'),
	'Auto' => $langs->trans('Auto'),
	'VarTypeContactName' => $langs->trans('VarTypeContactName'),
	'VarTypeOperatorName' => $langs->trans('VarTypeOperatorName'),
	'VarTypeCompanyName' => $langs->trans('VarTypeCompanyName'),
	'VarTypePhone' => $langs->trans('VarTypePhone'),
	'VarTypeDateToday' => $langs->trans('VarTypeDateToday'),
	'VarTypeFreeText' => $langs->trans('VarTypeFreeText'),
	'VarTypeUrl' => $langs->trans('VarTypeUrl'),
	'VarTypeFixedText' => $langs->trans('VarTypeFixedText'),
);
print '<script>var WhatsAppLang = WhatsAppLang || '.json_encode($jsTranslations).';</script>'."\n";
// Inject current user name for variable auto-resolve
print '<script>var WhatsAppCurrentUserName = WhatsAppCurrentUserName || '.json_encode(trim($user->firstname.' '.$user->lastname) ?: $user->login, JSON_UNESCAPED_UNICODE).';</script>'."\n";
// L5: Inject AJAX base URL so JS doesn't rely on relative paths
print '<script>var WhatsAppAjaxBase = WhatsAppAjaxBase || "'.dol_escape_htmltag(dol_buildpath('/custom/whatsappdati/', 1)).'";</script>'."\n";

// Multi-line: Inject available lines for JS
$configObj = new WhatsAppConfig($db);
$bulkLines = $configObj->fetchActiveLines();
$bulkLinesData = array();
foreach ($bulkLines as $lineObj) {
	$bulkLinesData[] = array('id' => (int) $lineObj->id, 'label' => $lineObj->label);
}
print '<script>var WhatsAppLines = (typeof WhatsAppLines !== "undefined") ? WhatsAppLines : '.json_encode($bulkLinesData).';</script>'."\n";

if ($view == 'history') {
	// ============================================================
	// BATCH HISTORY VIEW
	// ============================================================
	$batches = $queue->fetchBatches(50);

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("BatchID").'</th>';
	print '<th>'.$langs->trans("TemplateName").'</th>';
	print '<th class="center">'.$langs->trans("Total").'</th>';
	print '<th class="center">'.$langs->trans("Sent").'</th>';
	print '<th class="center">'.$langs->trans("BulkPending").'</th>';
	print '<th class="center">'.$langs->trans("Failed").'</th>';
	print '<th class="center">'.$langs->trans("Cancelled").'</th>';
	print '<th>'.$langs->trans("DateCreation").'</th>';
	print '<th class="center">'.$langs->trans("Actions").'</th>';
	print '</tr>';

	if (empty($batches)) {
		print '<tr><td colspan="9" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
	} else {
		foreach ($batches as $batch) {
			$stats = $queue->getBatchStats($batch->batch_id);
			$allDone = ($stats['pending'] == 0 && $stats['processing'] == 0);
			$progressPct = $stats['total'] > 0 ? round(($stats['sent'] + $stats['failed'] + $stats['cancelled']) / $stats['total'] * 100) : 0;

			print '<tr class="oddeven">';

			// Batch ID
			print '<td><span class="opacitymedium">'.dol_escape_htmltag(dol_trunc($batch->batch_id, 25)).'</span></td>';

			// Template
			print '<td><strong>'.dol_escape_htmltag($batch->template_name).'</strong></td>';

			// Total
			print '<td class="center">'.$stats['total'].'</td>';

			// Sent
			print '<td class="center">';
			if ($stats['sent'] > 0) {
				print '<span class="badge badge-status4">'.$stats['sent'].'</span>';
			} else {
				print '0';
			}
			print '</td>';

			// Pending
			print '<td class="center">';
			if ($stats['pending'] > 0) {
				print '<span class="badge badge-status1">'.$stats['pending'].'</span>';
			} else {
				print '0';
			}
			print '</td>';

			// Failed
			print '<td class="center">';
			if ($stats['failed'] > 0) {
				print '<span class="badge badge-status8">'.$stats['failed'].'</span>';
			} else {
				print '0';
			}
			print '</td>';

			// Cancelled
			print '<td class="center">';
			if ($stats['cancelled'] > 0) {
				print '<span class="badge badge-status9">'.$stats['cancelled'].'</span>';
			} else {
				print '0';
			}
			print '</td>';

			// Date
			print '<td>'.dol_print_date($db->jdate($batch->date_creation), 'dayhour').'</td>';

			// Actions
			print '<td class="center nowraponall">';
			if ($stats['pending'] > 0) {
				print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?action=cancel_batch&batch_id='.urlencode($batch->batch_id).'&token='.newToken().'&view=history" title="'.$langs->trans("CancelPending").'">';
				print img_picto($langs->trans("CancelPending"), 'delete');
				print '</a>';
			}
			// Progress bar
			print '<div class="bulk-progress-mini" title="'.$progressPct.'%">';
			print '<div class="bulk-progress-mini-bar" style="width:'.$progressPct.'%"></div>';
			print '</div>';
			print '</td>';

			print '</tr>';
		}
	}

	print '</table>';
	print '</div>';
} else {
	// ============================================================
	// NEW BULK SEND VIEW
	// ============================================================

	// Step 1: Template selection
	print '<div class="bulk-send-form" id="bulk-send-form">';

	// Template selection
	print '<div class="bulk-send-section">';
	print '<h3>'.img_picto('', 'object_list').' '.$langs->trans("Step1SelectTemplate").'</h3>';

	// Line selector (if multiple lines exist)
	if (count($bulkLines) > 1) {
		print '<div class="bulk-send-line-row marginbottomonly">';
		print '<label for="bulk-line-select">'.$langs->trans("WhatsAppLine").': </label>';
		print '<select class="flat minwidth200" id="bulk-line-select">';
		foreach ($bulkLines as $lineObj) {
			print '<option value="'.$lineObj->id.'">'.dol_escape_htmltag($lineObj->label).'</option>';
		}
		print '</select>';
		print '</div>';
	} elseif (count($bulkLines) == 1) {
		print '<input type="hidden" id="bulk-line-select" value="'.$bulkLines[0]->id.'">';
	}

	print '<div class="bulk-send-template-row">';
	print '<select class="flat minwidth300" id="bulk-template-select">';
	print '<option value="">'.$langs->trans("SelectTemplate").'</option>';
	print '</select>';
	print ' <button type="button" class="butAction small" id="bulk-sync-templates-btn">'.$langs->trans("SyncTemplates").'</button>';
	print '</div>';
	// Template preview
	print '<div class="bulk-template-preview" id="bulk-template-preview" style="display:none;">';
	print '<div class="bulk-template-preview-header" id="bulk-preview-header"></div>';
	print '<div class="bulk-template-preview-body" id="bulk-preview-body"></div>';
	print '<div class="bulk-template-preview-footer" id="bulk-preview-footer"></div>';
	print '</div>';
	// Template variables
	print '<div class="bulk-template-variables" id="bulk-template-variables" style="display:none;"></div>';
	print '</div>';

	// Step 2: Recipients
	print '<div class="bulk-send-section">';
	print '<h3>'.img_picto('', 'object_contact').' '.$langs->trans("Step2SelectRecipients").'</h3>';
	print '<div class="bulk-recipient-search-row">';
	print '<input type="text" class="flat minwidth300" id="bulk-recipient-search" placeholder="'.$langs->trans("SearchRecipients").'" />';
	print '<button type="button" class="butAction small" id="bulk-search-btn">'.img_picto('', 'search_icon.png@whatsappdati', '', 0, 0, 0, '', 'pictofixedwidth').$langs->trans("Search").'</button>';
	print '<span class="bulk-recipient-count" id="bulk-recipient-count"></span>';
	print '</div>';
	// Search results
	print '<div class="bulk-search-results" id="bulk-search-results" style="display:none;">';
	print '<div class="bulk-search-results-actions">';
	print '<button type="button" class="butAction small" id="bulk-select-all-btn">'.$langs->trans("SelectAll").'</button>';
	print '</div>';
	print '<div class="bulk-search-results-list" id="bulk-search-results-list"></div>';
	print '</div>';
	// Selected recipients
	print '<div class="bulk-selected-recipients" id="bulk-selected-recipients">';
	print '<div class="bulk-recipients-chips" id="bulk-recipients-chips"></div>';
	print '</div>';
	print '</div>';

	// Step 3: Confirmation & Send
	print '<div class="bulk-send-section">';
	print '<h3>'.img_picto('', 'object_action').' '.$langs->trans("Step3ConfirmAndSend").'</h3>';
	print '<div class="bulk-send-summary" id="bulk-send-summary" style="display:none;">';
	print '<table class="noborder centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans("TemplateName").'</td><td id="bulk-summary-template">-</td></tr>';
	print '<tr><td>'.$langs->trans("Recipients").'</td><td id="bulk-summary-recipients">0</td></tr>';
	print '<tr><td>'.$langs->trans("TemplateVariables").'</td><td id="bulk-summary-variables">-</td></tr>';
	print '</table>';
	print '</div>';
	print '<div class="bulk-send-actions">';
	print '<button type="button" class="butAction" id="bulk-send-btn" disabled>'.$langs->trans("StartBulkSend").'</button>';
	print '</div>';
	print '</div>';

	// Progress area (hidden until send starts)
	print '<div class="bulk-send-progress" id="bulk-send-progress" style="display:none;">';
	print '<h3>'.img_picto('', 'object_calendarweek').' '.$langs->trans("BulkSendProgress").'</h3>';
	print '<div class="bulk-progress-bar-container">';
	print '<div class="bulk-progress-bar" id="bulk-progress-bar"><span id="bulk-progress-text">0%</span></div>';
	print '</div>';
	print '<div class="bulk-progress-stats" id="bulk-progress-stats">';
	print '<span class="bulk-stat"><strong>'.$langs->trans("Total").':</strong> <span id="bulk-stat-total">0</span></span>';
	print '<span class="bulk-stat bulk-stat-sent"><strong>'.$langs->trans("Sent").':</strong> <span id="bulk-stat-sent">0</span></span>';
	print '<span class="bulk-stat bulk-stat-failed"><strong>'.$langs->trans("Failed").':</strong> <span id="bulk-stat-failed">0</span></span>';
	print '<span class="bulk-stat bulk-stat-pending"><strong>'.$langs->trans("BulkPending").':</strong> <span id="bulk-stat-pending">0</span></span>';
	print '</div>';
	print '<div class="bulk-progress-actions" id="bulk-progress-actions" style="display:none;">';
	print '<button type="button" class="butAction" id="bulk-cancel-btn">'.$langs->trans("CancelPending").'</button>';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?view=history">'.$langs->trans("ViewHistory").'</a>';
	print '</div>';
	print '</div>';

	print '</div>'; // bulk-send-form
}

print '</div>'; // tabBar

llxFooter();
$db->close();
