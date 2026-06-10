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
// Inject rate limit (ms per message) and batch size so JS can calculate time estimates
print '<script>var BulkRateLimitMs = '.((int) getDolGlobalInt('WHATSAPPDATI_RATE_LIMIT_MS', 100)).'; var BulkBatchSize = 50;</script>'."\n";

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

	// Load filter options for Step 2 (patient group filters) — only needed for the new form
	$bulkOptPrograma    = array(); $bulkOptMedicamento = array(); $bulkOptEps     = array();
	$bulkOptOperador    = array(); $bulkOptMedico      = array();
	$_bulkLoadOpts = function($table, $valCol, $labelCol) use ($db) {
		$opts = array();
		$sql = 'SELECT '.$valCol.', '.$labelCol.' FROM '.MAIN_DB_PREFIX.$table;
		$sql .= ' WHERE entity IN ('.getEntity('societe').') ORDER BY '.$labelCol.' ASC';
		$res = $db->query($sql);
		while ($res && $r = $db->fetch_object($res)) {
			$opts[] = array('id' => (int) $r->$valCol, 'label' => $r->$labelCol);
		}
		return $opts;
	};
	$bulkOptPrograma    = $_bulkLoadOpts('gestion_programa',    'rowid', 'nombre');
	$bulkOptMedicamento = $_bulkLoadOpts('gestion_medicamento', 'rowid', 'etiqueta');
	$bulkOptEps         = $_bulkLoadOpts('gestion_eps',         'rowid', 'descripcion');
	$bulkOptOperador    = $_bulkLoadOpts('gestion_operador',    'rowid', 'nombre');
	$bulkOptMedico      = $_bulkLoadOpts('gestion_medico',      'rowid', 'nombre');
	$bulkOptEstado = array(
		array('id'=>1,'label'=>'En Tránsito'), array('id'=>2,'label'=>'En Proceso'),
		array('id'=>3,'label'=>'Activo en Tratamiento'), array('id'=>4,'label'=>'Activo Independiente'),
		array('id'=>5,'label'=>'Activo Por El Programa'), array('id'=>6,'label'=>'Reactivado'),
		array('id'=>7,'label'=>'Suspendido'), array('id'=>8,'label'=>'No trazable'),
		array('id'=>9,'label'=>'NAP'), array('id'=>10,'label'=>'Inactivo'),
	);
	$bulkOptEstadoVital = array(array('id'=>1,'label'=>'Vivo'), array('id'=>2,'label'=>'Muerto'));
	$bulkOptRegimen = array(
		array('id'=>1,'label'=>'Contributivo'), array('id'=>2,'label'=>'Subsidiado'),
		array('id'=>3,'label'=>'Especial'), array('id'=>4,'label'=>'Particular'),
		array('id'=>5,'label'=>'Por confirmar'),
	);
	print '<script>var BulkFilterOpts = '.json_encode(array(
		'programa'    => $bulkOptPrograma,
		'medicamento' => $bulkOptMedicamento,
		'eps'         => $bulkOptEps,
		'operador'    => $bulkOptOperador,
		'medico'      => $bulkOptMedico,
		'estado'      => $bulkOptEstado,
		'estadovital' => $bulkOptEstadoVital,
		'regimen'     => $bulkOptRegimen,
	), JSON_UNESCAPED_UNICODE).';</script>'."\n";

	// Step 1: Template selection
	print '<div class="bulk-send-form" id="bulk-send-form">';
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

	// Mode tabs
	print '<div class="bulk-mode-tabs">';
	print '<button type="button" class="bulk-mode-tab active" data-mode="search">🔍 '.$langs->trans("Search").'</button>';
	print '<button type="button" class="bulk-mode-tab" data-mode="phones">📋 '.$langs->trans("BulkDirectNumbers").'</button>';
	print '<button type="button" class="bulk-mode-tab" data-mode="filter">⚙️ '.$langs->trans("BulkFilterGroup").'</button>';
	print '</div>';

	// ── Mode: text search ──
	print '<div class="bulk-mode-panel" id="bulk-mode-search">';
	print '<div class="bulk-recipient-search-row">';
	print '<input type="text" class="flat minwidth300" id="bulk-recipient-search" placeholder="'.$langs->trans("SearchRecipients").'" />';
	print '<button type="button" class="butAction small" id="bulk-search-btn">'.img_picto('', 'search_icon.png@whatsappdati', '', 0, 0, 0, '', 'pictofixedwidth').$langs->trans("Search").'</button>';
	print '<span class="bulk-recipient-count" id="bulk-recipient-count"></span>';
	print '</div>';
	print '<div class="bulk-search-results" id="bulk-search-results" style="display:none;">';
	print '<div class="bulk-search-results-actions">';
	print '<button type="button" class="butAction small" id="bulk-select-all-btn">'.$langs->trans("SelectAll").'</button>';
	print '</div>';
	print '<div class="bulk-search-results-list" id="bulk-search-results-list"></div>';
	print '</div>';
	print '</div>';

	// ── Mode: direct phone numbers ──
	print '<div class="bulk-mode-panel" id="bulk-mode-phones" style="display:none;">';
	print '<p class="opacitymedium" style="margin:0 0 8px 0;">'.$langs->trans("BulkDirectNumbersHelp").'</p>';
	print '<textarea id="bulk-phones-input" class="flat" style="width:100%;min-height:100px;font-family:monospace;" placeholder="3001234567, 3119876543&#10;3201112233"></textarea>';
	print '<div style="margin-top:8px;">';
	print '<button type="button" class="butAction small" id="bulk-phones-add-btn">'.$langs->trans("BulkAddNumbers").'</button>';
	print '</div>';
	print '</div>';

	// ── Mode: filter by group ──
	print '<div class="bulk-mode-panel" id="bulk-mode-filter" style="display:none;">';
	print '<p style="font-size:12px;color:#667781;margin:0 0 10px 0;">'.
		'<strong>Combina filtros:</strong> marca uno o varios valores en cada campo. '.
		'Diferentes campos se combinan con AND; múltiples valores del mismo campo se combinan con OR.'.
	'</p>';
	print '<div id="bulk-filter-form" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 16px;margin-bottom:12px;">';

	$filterFields = array(
		'f_programa'    => array('label' => $langs->trans("Programa"),          'opts' => $bulkOptPrograma),
		'f_medicamento' => array('label' => $langs->trans("Medicamento"),        'opts' => $bulkOptMedicamento),
		'f_eps'         => array('label' => $langs->trans("EPS"),                'opts' => $bulkOptEps),
		'f_operador'    => array('label' => $langs->trans("OperadorLogistico"),  'opts' => $bulkOptOperador),
		'f_medico'      => array('label' => $langs->trans("MedicoTratante"),     'opts' => $bulkOptMedico),
		'f_estado'      => array('label' => $langs->trans("EstadoPaciente"),     'opts' => $bulkOptEstado),
		'f_estadovital' => array('label' => $langs->trans("EstadoVital"),        'opts' => $bulkOptEstadoVital),
		'f_regimen'     => array('label' => $langs->trans("Regimen"),            'opts' => $bulkOptRegimen),
	);
	foreach ($filterFields as $fieldId => $fieldCfg) {
		print '<div class="bulk-filter-group">';
		print '<div class="bulk-filter-group-label">'.dol_escape_htmltag($fieldCfg['label']).'</div>';
		print '<div class="bulk-filter-checks" id="bulk-filter-'.$fieldId.'">';
		if (empty($fieldCfg['opts'])) {
			print '<span style="font-size:11px;color:#aaa;font-style:italic;">Sin opciones</span>';
		}
		foreach ($fieldCfg['opts'] as $opt) {
			$eid = 'bfc_'.$fieldId.'_'.(int)$opt['id'];
			print '<label class="bulk-filter-check-item" for="'.$eid.'">';
			print '<input type="checkbox" id="'.$eid.'" value="'.((int)$opt['id']).'"> ';
			print dol_escape_htmltag($opt['label']);
			print '</label>';
		}
		print '</div>';
		print '</div>';
	}
	// Biológico (single value, keep as select)
	print '<div class="bulk-filter-group">';
	print '<div class="bulk-filter-group-label">'.$langs->trans("TieneBiologico").'</div>';
	print '<div class="bulk-filter-checks" style="padding:6px 8px;">';
	print '<select class="flat" id="bulk-filter-f_biologico" style="width:100%;font-size:12px;">';
	print '<option value="0">'.$langs->trans("All").'</option>';
	print '<option value="1">'.$langs->trans("Yes").'</option>';
	print '<option value="-1">'.$langs->trans("No").'</option>';
	print '</select>';
	print '</div>';
	print '</div>';
	print '</div>';

	print '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
	print '<button type="button" class="butAction small" id="bulk-filter-count-btn">📊 '.$langs->trans("BulkCountPatients").'</button>';
	print '<button type="button" class="button small" id="bulk-filter-clear-btn" style="background:none;border:1px solid #ccc;color:#555;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px;">✕ Limpiar filtros</button>';
	print '<span id="bulk-filter-count-result" style="font-size:13px;"></span>';
	print '</div>';

	// Direct batch launch (shown after counting — bypasses the chip list for large volumes)
	print '<div id="bulk-filter-direct-launch" style="display:none;margin-top:14px;padding:14px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">';
	print '<div id="bulk-filter-time-estimate" style="font-size:13px;margin-bottom:10px;line-height:1.6;"></div>';
	print '<button type="button" class="butAction" id="bulk-filter-direct-send-btn">🚀 '.$langs->trans("BulkStartDirectSend").'</button>';
	print ' <span style="font-size:12px;color:#888;">'.$langs->trans("BulkDirectSendNote").'</span>';
	print '</div>';

	print '<div style="margin-top:10px;">';
	print '<button type="button" class="butAction small" id="bulk-filter-load-btn" style="display:none;">⬇️ '.$langs->trans("BulkLoadRecipients").'</button>';
	print ' <span style="font-size:12px;color:#888;" id="bulk-filter-load-note"></span>';
	print '</div>';
	print '<p style="font-size:12px;color:#888;margin-top:6px;">'.$langs->trans("BulkFilterNote").'</p>';
	print '</div>';

	// Selected recipients (shared across all modes)
	print '<div class="bulk-selected-recipients" id="bulk-selected-recipients" style="margin-top:12px;">';
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
	// Stats row
	print '<div class="bulk-progress-stats" id="bulk-progress-stats">';
	print '<span class="bulk-stat"><strong>'.$langs->trans("Total").':</strong> <span id="bulk-stat-total">0</span></span>';
	print '<span class="bulk-stat bulk-stat-sent"><strong>'.$langs->trans("Sent").':</strong> <span id="bulk-stat-sent">0</span></span>';
	print '<span class="bulk-stat bulk-stat-failed"><strong>'.$langs->trans("Failed").':</strong> <span id="bulk-stat-failed">0</span></span> ';
	print '<button type="button" class="bulk-show-errors-btn" id="bulk-show-errors-btn" style="display:none;" title="Ver errores">⚠️ Ver errores</button>';
	print '<span class="bulk-stat bulk-stat-pending"><strong>'.$langs->trans("BulkPending").':</strong> <span id="bulk-stat-pending">0</span></span>';
	print '</div>';
	// Cancel button — visible WHILE sending
	print '<div class="bulk-progress-sending-actions" id="bulk-progress-sending-actions" style="margin-top:10px;">';
	print '<button type="button" class="butActionDelete" id="bulk-abort-btn">⛔ Cancelar envío ahora</button>';
	print '</div>';
	// Error detail panel (hidden until toggled)
	print '<div id="bulk-errors-panel" style="display:none;margin-top:12px;padding:12px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;">';
	print '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
	print '<strong style="font-size:13px;">⚠️ Mensajes fallidos (últimos 20)</strong>';
	print '<button type="button" id="bulk-refresh-errors-btn" style="font-size:12px;background:none;border:1px solid #fca5a5;border-radius:4px;padding:2px 8px;cursor:pointer;">🔄 Actualizar</button>';
	print '</div>';
	print '<div id="bulk-errors-list" style="font-size:12px;max-height:200px;overflow-y:auto;"></div>';
	print '</div>';
	// Post-completion actions
	print '<div class="bulk-progress-actions" id="bulk-progress-actions" style="display:none;margin-top:10px;">';
	print '<button type="button" class="butAction" id="bulk-cancel-btn">'.$langs->trans("CancelPending").'</button>';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?view=history">'.$langs->trans("ViewHistory").'</a>';
	print '</div>';
	print '</div>';

	print '</div>'; // bulk-send-form

	// Inline script: tab switching independent of external JS load order
	print '<script>
(function() {
	function initBulkTabs() {
		var tabs = document.querySelectorAll(".bulk-mode-tab");
		tabs.forEach(function(btn) {
			btn.addEventListener("click", function() {
				tabs.forEach(function(t) { t.classList.remove("active"); });
				btn.classList.add("active");
				document.querySelectorAll(".bulk-mode-panel").forEach(function(p) { p.style.display = "none"; });
				var panel = document.getElementById("bulk-mode-" + btn.getAttribute("data-mode"));
				if (panel) panel.style.display = "";
			});
		});
	}
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initBulkTabs);
	} else {
		initBulkTabs();
	}
})();
</script>'."\n";
}

print '</div>'; // tabBar

llxFooter();
$db->close();
