<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       admin/setup.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp module setup page
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/whatsappdati.lib.php';
require_once '../class/whatsappconfig.class.php';
require_once '../class/whatsappmanager.class.php';
require_once '../class/whatsappassignment.class.php';
require_once '../class/whatsapptag.class.php';
require_once '../class/whatsappquickreply.class.php';

// Translations
$langs->loadLangs(array("admin", "whatsappdati@whatsappdati"));

// Access control - allow Dolibarr admins or users with config write permission
if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$editLineId = GETPOSTINT('line_id');

/*
 * Actions
 */

// ---- Line CRUD ----

if ($action == 'create_line' || $action == 'update_line') {
	$config = new WhatsAppConfig($db);

	if ($action == 'update_line' && $editLineId > 0) {
		$config->fetch($editLineId);
	}

	$config->label = GETPOST('line_label', 'alphanohtml');
	$config->app_id = GETPOST('app_id', 'alphanohtml');
	$config->phone_number_id = GETPOST('phone_number_id', 'alphanohtml');
	$config->business_account_id = GETPOST('business_account_id', 'alphanohtml');
	$config->webhook_verify_token = GETPOST('webhook_verify_token', 'alphanohtml');
	$config->country_code = GETPOST('country_code', 'aZ09');
	$config->assign_mode = GETPOST('assign_mode', 'aZ09');
	if (!in_array($config->assign_mode, array('manual', 'roundrobin', 'leastactive'))) {
		$config->assign_mode = 'manual';
	}
	$lineAgentIds = GETPOST('line_agents', 'array');
	$lineAgentIds = array_map('intval', $lineAgentIds);
	$lineAgentIds = array_filter($lineAgentIds, function($v) { return $v > 0; });
	// Keep fk_user_default_agent as first selected agent for backward compatibility
	$config->fk_user_default_agent = !empty($lineAgentIds) ? reset($lineAgentIds) : 0;
	$config->status = GETPOSTINT('line_active') ? 1 : 0;

	$access_token = GETPOST('access_token', 'restricthtml');
	if (!empty($access_token) && $access_token !== '••••••••') {
		$config->access_token = $access_token;
	}
	$app_secret = GETPOST('app_secret', 'restricthtml');
	if (!empty($app_secret) && $app_secret !== '••••••••') {
		$config->app_secret = $app_secret;
	}

	// Auto-generate webhook URL using actual server domain
	$webhookScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$webhookHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
	$webhookPath = dol_buildpath('/custom/whatsappdati/webhook.php', 1);
	$baseWebhookUrl = $webhookScheme.'://'.$webhookHost.$webhookPath;

	if ($action == 'update_line' && $config->id > 0) {
		$config->webhook_url = $baseWebhookUrl.'?line='.$config->id;
		$result = $config->update($user);
	} else {
		if (!$config->status) {
			$config->status = 1; // Default to active for new lines
		}
		$config->webhook_url = $baseWebhookUrl; // will update after create
		$result = $config->create($user);
		if ($result > 0) {
			// Now update webhook_url with the new line ID
			$config->webhook_url = $baseWebhookUrl.'?line='.$config->id;
			$config->update($user);
		}
	}

	if ($result > 0) {
		// Save multi-agent assignments for this line
		$config->setLineAgents($config->id, $lineAgentIds, $user);
		setEventMessages($langs->trans("ConfigurationSaved"), null, 'mesgs');
		header('Location: '.$_SERVER["PHP_SELF"]);
		exit;
	} else {
		$errDetail = !empty($config->errors) ? implode(', ', $config->errors) : $langs->trans("UnknownError");
		setEventMessages($langs->trans("ErrorSavingConfiguration").': '.$errDetail, null, 'errors');
	}
}

if ($action == 'confirm_delete_line') {
	$lineId = GETPOSTINT('line_id');
	if ($lineId > 0) {
		$config = new WhatsAppConfig($db);
		if ($config->fetch($lineId) > 0) {
			$result = $config->delete($user);
			if ($result > 0) {
				setEventMessages($langs->trans("LineDeleted"), null, 'mesgs');
			} else {
				setEventMessages(implode(', ', $config->errors), null, 'errors');
			}
		}
	}
	header('Location: '.$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'test_line') {
	$lineId = GETPOSTINT('line_id');
	$manager = new WhatsAppManager($db, $lineId > 0 ? $lineId : 0);
	$result = $manager->testConnection();

	if ($result['success']) {
		setEventMessages($langs->trans("ConnectionSuccessful").' (Line #'.$lineId.')', null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorTestingConnection").': '.$result['error'], null, 'errors');
	}
}

// ---- Global settings ----

if ($action == 'update_global') {
	$realtime_mode = GETPOST('realtime_mode', 'alpha');
	dolibarr_set_const($db, 'WHATSAPPDATI_REALTIME_MODE', $realtime_mode, 'chaine', 0, 'Real-time update mode', $conf->entity);

	$rate_limit_ms = GETPOSTINT('rate_limit_ms');
	if ($rate_limit_ms < 50) $rate_limit_ms = 50;
	if ($rate_limit_ms > 5000) $rate_limit_ms = 5000;
	dolibarr_set_const($db, 'WHATSAPPDATI_RATE_LIMIT_MS', $rate_limit_ms, 'chaine', 0, 'Rate limit in ms between sends', $conf->entity);

	// Business hours settings
	$bh_enabled = GETPOST('bh_enabled', 'int') ? '1' : '0';
	dolibarr_set_const($db, 'WHATSAPPDATI_BH_ENABLED', $bh_enabled, 'chaine', 0, 'Business hours enabled', $conf->entity);
	dolibarr_set_const($db, 'WHATSAPPDATI_BH_START', GETPOST('bh_start', 'alpha'), 'chaine', 0, 'Business hours start', $conf->entity);
	dolibarr_set_const($db, 'WHATSAPPDATI_BH_END', GETPOST('bh_end', 'alpha'), 'chaine', 0, 'Business hours end', $conf->entity);
	$bh_days = GETPOST('bh_days', 'array');
	dolibarr_set_const($db, 'WHATSAPPDATI_BH_DAYS', implode(',', array_map('intval', $bh_days)), 'chaine', 0, 'Business days', $conf->entity);
	dolibarr_set_const($db, 'WHATSAPPDATI_BH_TIMEZONE', GETPOST('bh_timezone', 'alpha'), 'chaine', 0, 'Business hours timezone', $conf->entity);
	$bh_message = GETPOST('bh_message', 'restricthtml');
	if (!empty($bh_message)) {
		dolibarr_set_const($db, 'WHATSAPPDATI_BH_MESSAGE', $bh_message, 'chaine', 0, 'Out of hours message', $conf->entity);
	}

	// CSAT settings
	$csat_enabled = GETPOST('csat_enabled', 'int') ? '1' : '0';
	dolibarr_set_const($db, 'WHATSAPPDATI_CSAT_ENABLED', $csat_enabled, 'chaine', 0, 'CSAT surveys enabled', $conf->entity);
	$csat_message = GETPOST('csat_message', 'restricthtml');
	if (!empty($csat_message)) {
		dolibarr_set_const($db, 'WHATSAPPDATI_CSAT_MESSAGE', $csat_message, 'chaine', 0, 'CSAT survey message', $conf->entity);
	}
	$csat_thanks = GETPOST('csat_thanks', 'restricthtml');
	if (!empty($csat_thanks)) {
		dolibarr_set_const($db, 'WHATSAPPDATI_CSAT_THANKS', $csat_thanks, 'chaine', 0, 'CSAT thanks message', $conf->entity);
	}

	setEventMessages($langs->trans("ConfigurationSaved"), null, 'mesgs');
}

// Tag actions
if ($action == 'create_tag') {
	$tagLabel = GETPOST('tag_label', 'alphanohtml');
	$tagColor = GETPOST('tag_color', 'alphanohtml');
	$tagDesc = GETPOST('tag_description', 'alphanohtml');

	if (!empty($tagLabel)) {
		$tag = new WhatsAppTag($db);
		$tag->label = $tagLabel;
		$tag->color = $tagColor ?: '#25D366';
		$tag->description = $tagDesc;
		$result = $tag->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans("TagCreated"), null, 'mesgs');
		} else {
			setEventMessages(implode(', ', $tag->errors), null, 'errors');
		}
	}
}

if ($action == 'delete_tag') {
	$tagId = GETPOST('tag_id', 'int');
	if ($tagId > 0) {
		$tag = new WhatsAppTag($db);
		if ($tag->fetch($tagId) > 0) {
			$result = $tag->delete($user);
			if ($result > 0) {
				setEventMessages($langs->trans("TagDeleted"), null, 'mesgs');
			} else {
				setEventMessages(implode(', ', $tag->errors), null, 'errors');
			}
		}
	}
}

// Quick reply actions
if ($action == 'create_quick_reply') {
	$qrShortcut = GETPOST('qr_shortcut', 'alphanohtml');
	$qrTitle = GETPOST('qr_title', 'alphanohtml');
	$qrContent = GETPOST('qr_content', 'restricthtml');
	$qrCategory = GETPOST('qr_category', 'alphanohtml');

	if (!empty($qrShortcut) && !empty($qrTitle) && !empty($qrContent)) {
		$qr = new WhatsAppQuickReply($db);
		$qr->shortcut = $qrShortcut;
		$qr->title = $qrTitle;
		$qr->content = $qrContent;
		$qr->category = $qrCategory;
		$result = $qr->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans("QuickReplyCreated"), null, 'mesgs');
		} else {
			setEventMessages($qr->error, null, 'errors');
		}
	} else {
		setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
	}
}

if ($action == 'delete_quick_reply') {
	$qrId = GETPOST('qr_id', 'int');
	if ($qrId > 0) {
		$qr = new WhatsAppQuickReply($db);
		$qr->id = $qrId;
		$result = $qr->delete();
		if ($result > 0) {
			setEventMessages($langs->trans("QuickReplyDeleted"), null, 'mesgs');
		} else {
			setEventMessages($qr->error, null, 'errors');
		}
	}
}

/*
 * View
 */

$page_name = "WhatsAppDatiSetup";
llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-whatsappdati page-admin-setup');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration tabs
$head = whatsappdatiAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500000Name"), -1, 'whatsappdati@whatsappdati');

// ==========================================
// Inline CSS for modern card layout
// ==========================================
print '<style>
.wa-setup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
@media (max-width: 900px) { .wa-setup-grid { grid-template-columns: 1fr; } }
.wa-card { background: var(--colorbackbody, #fff); border: 1px solid var(--colorbordertitle, #dee2e6); border-radius: 8px; overflow: hidden; transition: box-shadow 0.2s; }
.wa-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.07); }
.wa-card-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; cursor: pointer; user-select: none; border-bottom: 1px solid transparent; transition: background 0.15s, border-color 0.15s; }
.wa-card-header:hover { background: var(--colorbacklinepairhover, #f8f9fa); }
.wa-card.wa-open .wa-card-header { border-bottom-color: var(--colorbordertitle, #dee2e6); }
.wa-card-header-left { display: flex; align-items: center; gap: 10px; }
.wa-card-icon { font-size: 20px; width: 32px; text-align: center; flex-shrink: 0; }
.wa-card-title { font-size: 14px; font-weight: 600; color: var(--colortexttitle, #333); }
.wa-card-subtitle { font-size: 11px; color: var(--colortextother, #888); margin-top: 2px; }
.wa-card-badge { font-size: 11px; padding: 3px 10px; border-radius: 12px; font-weight: 600; white-space: nowrap; }
.wa-badge-on { background: #dcfce7; color: #166534; }
.wa-badge-off { background: #f3f4f6; color: #6b7280; }
.wa-badge-info { background: #dbeafe; color: #1e40af; }
.wa-card-body { display: none; padding: 16px; }
.wa-card.wa-open .wa-card-body { display: block; }
.wa-card-chevron { font-size: 13px; color: var(--colortextother, #999); transition: transform 0.2s; margin-left: 10px; }
.wa-card.wa-open .wa-card-chevron { transform: rotate(180deg); }
.wa-full-width { grid-column: 1 / -1; }
.wa-help-block { background: var(--colorbacklinepair, #f8f9fa); border-left: 3px solid var(--colorbordertitle, #ccc); padding: 8px 12px; margin-top: 8px; font-size: 12px; color: var(--colortextother, #666); border-radius: 0 4px 4px 0; line-height: 1.5; }
.wa-section-title { display: flex; align-items: center; gap: 10px; margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--colorbordertitle, #dee2e6); }
.wa-section-title h2 { font-size: 16px; font-weight: 700; color: var(--colortexttitle, #333); margin: 0; }
.wa-section-title .wa-section-icon { font-size: 22px; }
.wa-msg-preview { background: #e7f5e4; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #333; margin-top: 8px; border: 1px solid #c8e6c0; max-width: 400px; white-space: pre-line; line-height: 1.5; position: relative; }
.wa-msg-preview::before { content: "Vista previa"; font-size: 10px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px; }
.wa-toggle-label { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; }
.wa-toggle-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: #00a884; cursor: pointer; }
.wa-line-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #00a884; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.15s; }
.wa-line-btn:hover { background: #009174; }
.wa-line-btn-secondary { background: var(--colorbacklinepair, #f0f0f0); color: var(--colortexttitle, #333); border: 1px solid var(--colorbordertitle, #ccc); }
.wa-line-btn-secondary:hover { background: var(--colorbacklinepairhover, #e0e0e0); }
.wa-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-top: 8px; }
.wa-stat-box { background: var(--colorbacklinepair, #f8f9fa); border: 1px solid var(--colorbordertitle, #eee); padding: 12px; border-radius: 8px; text-align: center; }
.wa-stat-value { font-size: 22px; font-weight: 700; color: var(--colortexttitle, #333); }
.wa-stat-label { font-size: 11px; color: var(--colortextother, #888); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
.wa-dist-bar { display: flex; align-items: center; gap: 6px; margin: 3px 0; font-size: 12px; }
.wa-dist-bar-fill { height: 8px; background: #00a884; border-radius: 4px; transition: width 0.3s; }
.wa-dist-bar-track { flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
</style>';

// ==========================================
// JS for collapsible cards
// ==========================================
print '<script>
$(document).ready(function() {
	// Toggle card open/close
	$(document).on("click", ".wa-card-header", function() {
		$(this).closest(".wa-card").toggleClass("wa-open");
	});
	// Open card if it has errors or is being edited
	$(".wa-card.wa-auto-open").addClass("wa-open");
	// Line form toggle
	$(document).on("click", "#wa-btn-show-line-form", function() {
		var f = $("#wa-line-form-container");
		f.slideToggle(200);
		$(this).toggleClass("wa-line-btn-active");
	});
	// Auto-scroll to edit form when editing a line
	if ($("#wa-line-form-container").is(":visible") && $("#wa-line-form-container").length) {
		setTimeout(function() {
			$("html, body").animate({ scrollTop: $("#wa-line-form-container").offset().top - 80 }, 300);
		}, 100);
	}
});
</script>';

// =========================================
// SECTION 1: WhatsApp Lines
// =========================================

print '<div class="wa-section-title"><span class="wa-section-icon">📱</span><h2>'.$langs->trans("WhatsAppLines").'</h2></div>';

$configObj = new WhatsAppConfig($db);
$allLines = $configObj->fetchAllLines();
$config_tmp_agents = new WhatsAppConfig($db);

$editLine = null;
if ($action == 'edit_line' && $editLineId > 0) {
	$editLine = new WhatsAppConfig($db);
	$editLine->fetch($editLineId);
}

$assignObj = new WhatsAppAssignment($db);
$availableUsers = $assignObj->getAvailableUsers();

// Lines table
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("LineLabel").'</th>';
print '<th>'.$langs->trans("PhoneNumberID").'</th>';
print '<th>'.$langs->trans("BusinessAccountID").'</th>';
print '<th>'.$langs->trans("DefaultAgent").'</th>';
print '<th>'.$langs->trans("CountryCode").'</th>';
print '<th>'.$langs->trans("Status").'</th>';
print '<th class="center" width="120">'.$langs->trans("Actions").'</th>';
print '</tr>';

if (!empty($allLines)) {
	foreach ($allLines as $line) {
		print '<tr class="oddeven">';
		print '<td><strong>'.dol_escape_htmltag($line->label ?: 'Línea #'.$line->rowid).'</strong></td>';
		print '<td><span class="opacitymedium">'.dol_escape_htmltag($line->phone_number_id).'</span></td>';
		print '<td><span class="opacitymedium">'.dol_escape_htmltag($line->business_account_id).'</span></td>';
		print '<td>';
		$lineAgents = $config_tmp_agents->getLineAgents($line->rowid);
		if (!empty($lineAgents)) {
			$agentNames = array();
			foreach ($lineAgents as $agentId) {
				$agentUser = new User($db);
				if ($agentUser->fetch($agentId) > 0) {
					$agentNames[] = dol_escape_htmltag(trim($agentUser->firstname.' '.$agentUser->lastname) ?: $agentUser->login);
				}
			}
			if (!empty($agentNames)) {
				print img_picto('', 'user', 'class="pictofixedwidth"');
				print implode(', ', $agentNames);
			} else {
				print '<span class="opacitymedium">'.$langs->trans("AutoAssign").'</span>';
			}
		} elseif (!empty($line->fk_user_default_agent)) {
			$agentUser = new User($db);
			if ($agentUser->fetch($line->fk_user_default_agent) > 0) {
				print img_picto('', 'user', 'class="pictofixedwidth"').dol_escape_htmltag(trim($agentUser->firstname.' '.$agentUser->lastname) ?: $agentUser->login);
			} else {
				print '<span class="opacitymedium">ID: '.$line->fk_user_default_agent.'</span>';
			}
		} else {
			print '<span class="opacitymedium">'.$langs->trans("AutoAssign").'</span>';
		}
		print '</td>';
		print '<td>+'.dol_escape_htmltag($line->country_code ?: '57').'</td>';
		print '<td>';
		if (!empty($line->status)) {
			print '<span class="badge badge-status4 badge-status">'.$langs->trans("Active").'</span>';
		} else {
			print '<span class="badge badge-status8 badge-status">'.$langs->trans("Inactive").'</span>';
		}
		print '</td>';
		print '<td class="center nowraponall">';
		print '<a href="'.$_SERVER["PHP_SELF"].'?action=edit_line&line_id='.$line->rowid.'&token='.newToken().'" title="'.$langs->trans("Edit").'">'.img_picto($langs->trans("Edit"), 'edit').'</a> ';
		print '<a href="'.$_SERVER["PHP_SELF"].'?action=test_line&line_id='.$line->rowid.'&token='.newToken().'" title="'.$langs->trans("TestLine").'">'.img_picto($langs->trans("TestLine"), 'object_technic').'</a> ';
		print '<a href="'.$_SERVER["PHP_SELF"].'?action=confirm_delete_line&line_id='.$line->rowid.'&token='.newToken().'" onclick="return confirm(\''.$langs->transnoentities("ConfirmDeleteLine").'\');" title="'.$langs->trans("Delete").'">'.img_picto($langs->trans("Delete"), 'delete').'</a>';
		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans("NoLinesConfigured").'</td></tr>';
}
print '</table>';

// ---- Add/Edit Line Form ----
$isEdit = ($editLine && $editLine->id > 0);
$formTitle = $isEdit ? $langs->trans("EditLine").' : '.dol_escape_htmltag($editLine->label) : $langs->trans("AddNewLine");

if (!$isEdit) {
	print '<div style="margin: 12px 0;">';
	print '<button type="button" id="wa-btn-show-line-form" class="wa-line-btn">+ '.$langs->trans("AddNewLine").'</button>';
	print '</div>';
}

print '<div id="wa-line-form-container" '.($isEdit ? '' : 'style="display:none;"').'>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($isEdit ? 'update_line' : 'create_line').'">';
if ($isEdit) {
	print '<input type="hidden" name="line_id" value="'.$editLine->id.'">';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.($isEdit ? '✏️ ' : '➕ ').$formTitle.'</td></tr>';

// Label
print '<tr class="oddeven"><td class="titlefield"><span class="fieldrequired">'.$langs->trans("LineLabel").'</span></td>';
print '<td><input type="text" class="minwidth300" name="line_label" value="'.dol_escape_htmltag($isEdit ? $editLine->label : '').'" placeholder="'.$langs->trans("LineLabelPlaceholder").'" required></td></tr>';

// --- Meta API Credentials ---
print '<tr class="liste_titre"><td colspan="2">🔑 '.$langs->trans("MetaAPICredentials").'</td></tr>';

// Chicken-and-egg info banner
print '<tr class="oddeven"><td colspan="2">';
print '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;font-size:12px;color:#92400e;line-height:1.6;">';
print $langs->trans('AppIDChickenEggNotice');
print '</div>';
print '</td></tr>';

// App ID (Facebook Developers)
print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans("AppID").'</span></td>';
print '<td><input type="text" class="minwidth500" name="app_id" value="'.dol_escape_htmltag($isEdit ? $editLine->app_id : '').'" placeholder="'.$langs->trans("AppIDPlaceholder").'" required>';
print '<div class="wa-help-block">'.$langs->trans('AppIDHelp').'</div>';
print '</td></tr>';

// App Secret
print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans("AppSecret").'</span></td><td>';
$secretPlaceholder = ($isEdit && !empty($editLine->app_secret)) ? '••••••••' : '';
print '<input type="password" class="minwidth500" name="app_secret" value="'.$secretPlaceholder.'" placeholder="'.$langs->trans('EnterNewValue').'">';
if ($isEdit && !empty($editLine->app_secret)) {
	print ' <span class="badge badge-status4 badge-status" style="font-size:11px;">✓ '.$langs->trans('Configured').'</span>';
}
print '<div class="wa-help-block">'.$langs->trans('AppSecretHelp').'</div>';
print '</td></tr>';

// Phone Number ID
print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans("PhoneNumberID").'</span></td>';
print '<td><input type="text" class="minwidth500" name="phone_number_id" value="'.dol_escape_htmltag($isEdit ? $editLine->phone_number_id : '').'" placeholder="'.$langs->trans("PhoneNumberIDPlaceholder").'" required>';
print '<div class="wa-help-block">'.$langs->trans('PhoneNumberIDHelp').'</div>';
print '</td></tr>';

// Business Account ID
print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans("BusinessAccountID").'</span></td>';
print '<td><input type="text" class="minwidth500" name="business_account_id" value="'.dol_escape_htmltag($isEdit ? $editLine->business_account_id : '').'" placeholder="'.$langs->trans("BusinessAccountIDPlaceholder").'" required>';
print '<div class="wa-help-block">'.$langs->trans('BusinessAccountIDHelp').'</div>';
print '</td></tr>';

// Access Token
print '<tr class="oddeven"><td><span class="fieldrequired">'.$langs->trans("AccessToken").'</span></td><td>';
$tokenPlaceholder = ($isEdit && !empty($editLine->access_token)) ? '••••••••' : '';
print '<input type="password" class="minwidth500" name="access_token" value="'.$tokenPlaceholder.'" placeholder="'.$langs->trans('EnterNewValue').'">';
if ($isEdit && !empty($editLine->access_token)) {
	print ' <span class="badge badge-status4 badge-status" style="font-size:11px;">✓ '.$langs->trans('Configured').'</span>';
}
print '<div class="wa-help-block">'.$langs->trans('AccessTokenHelp').'</div>';
print '</td></tr>';

// --- Webhook (System Generated) ---
print '<tr class="liste_titre"><td colspan="2">🔗 '.$langs->trans("WebhookConfiguration").'</td></tr>';

// Webhook URL (read-only, shown only when editing)
$waScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$waHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$waWebhookBase = $waScheme.'://'.$waHost.dol_buildpath('/custom/whatsappdati/webhook.php', 1);

if ($isEdit) {
	$webhookUrl = $waWebhookBase.'?line='.$editLine->id;
	print '<tr class="oddeven"><td>'.$langs->trans("WebhookURL").'</td><td>';
	print '<div style="display:flex; align-items:center; gap:8px;">';
	print '<input type="text" class="minwidth500" id="wa-webhook-url" value="'.dol_escape_htmltag($webhookUrl).'" readonly style="background:var(--colorbacklinepair,#f5f5f5);">';
	print '<button type="button" class="wa-line-btn wa-line-btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById(\'wa-webhook-url\').value);this.textContent=\'✓ Copiado\';setTimeout(function(){document.querySelector(\'#wa-copy-btn\').textContent=\'📋 Copiar\';},2000);" id="wa-copy-btn">📋 Copiar</button>';
	print '</div>';
	print '<div class="wa-help-block">'.$langs->trans("WebhookURLHelp").'</div>';
	print '</td></tr>';
} else {
	$previewWebhookUrl = $waWebhookBase.'?line=[auto]';
	print '<tr class="oddeven"><td>'.$langs->trans("WebhookURL").'</td><td>';
	print '<div style="display:flex; align-items:center; gap:8px;">';
	print '<input type="text" class="minwidth500" value="'.dol_escape_htmltag($previewWebhookUrl).'" readonly style="background:var(--colorbacklinepair,#f5f5f5); opacity:0.7;">';
	print '</div>';
	print '<div class="wa-help-block">⚡ '.$langs->trans("WebhookURLAutoGeneratedPreview").'</div>';
	print '</td></tr>';
}

// Webhook Verify Token
print '<tr class="oddeven"><td>'.$langs->trans("WebhookVerifyToken").'</td><td>';
$defaultVerifyToken = $isEdit ? $editLine->webhook_verify_token : bin2hex(random_bytes(16));
print '<div style="display:flex; align-items:center; gap:8px;">';
print '<input type="text" class="minwidth500" name="webhook_verify_token" id="wa-verify-token" value="'.dol_escape_htmltag($defaultVerifyToken).'" readonly style="background:var(--colorbacklinepair,#f5f5f5);">';
print '<button type="button" class="wa-line-btn wa-line-btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById(\'wa-verify-token\').value);this.textContent=\'✓ Copiado\';setTimeout(function(){this.textContent=\'📋 Copiar\';}.bind(this),2000);">📋 Copiar</button>';
print '</div>';
print '<div class="wa-help-block">'.$langs->trans("WebhookVerifyTokenHelp").'</div>';
print '</td></tr>';

// --- Additional Settings ---
print '<tr class="liste_titre"><td colspan="2">📋 '.$langs->trans("AdditionalSettings").'</td></tr>';

// Country Code
print '<tr class="oddeven"><td>'.$langs->trans("CountryCode").'</td><td>';
print '<input type="text" class="maxwidth100" name="country_code" value="'.dol_escape_htmltag($isEdit ? ($editLine->country_code ?: '57') : '57').'" placeholder="57">';
print '<span class="opacitymedium" style="margin-left:8px;">'.$langs->trans("CountryCodeHelp").'</span>';
print '</td></tr>';

// Line Agents (multi-select checkboxes)
print '<tr class="oddeven"><td>'.$langs->trans("DefaultAgent").'</td><td>';
if (!empty($availableUsers)) {
	$editLineAgentIds = array();
	if ($isEdit) {
		$editLineAgentIds = $config_tmp_agents->getLineAgents($editLine->rowid);
		// Fallback: if no multi-agent records, use legacy single agent
		if (empty($editLineAgentIds) && !empty($editLine->fk_user_default_agent)) {
			$editLineAgentIds = array((int) $editLine->fk_user_default_agent);
		}
	}
	print '<div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--colorbordertitle,#ddd); padding: 8px; border-radius: 6px; background: var(--colorbacklinepair, #fafafa);">';
	foreach ($availableUsers as $u) {
		$name = trim($u->firstname.' '.$u->lastname);
		if (empty($name)) $name = $u->login;
		$checked = in_array((int) $u->rowid, $editLineAgentIds) ? ' checked' : '';
		print '<label style="display:flex; align-items:center; padding: 4px 0; gap: 6px; cursor:pointer;">';
		print '<input type="checkbox" name="line_agents[]" value="'.$u->rowid.'"'.$checked.' style="accent-color:#00a884;"> ';
		print dol_escape_htmltag($name).' <span class="opacitymedium">('.$u->login.')</span>';
		print '</label>';
	}
	print '</div>';
	print '<div class="wa-help-block">'.$langs->trans("LineAgentsHelp").'</div>';
} else {
	print '<span class="opacitymedium">'.$langs->trans("NoUsersFound").'</span>';
}
print '</td></tr>';

// Assignment Mode (per-line)
$assignModeLabels = array(
	'manual' => $langs->trans("AssignManualOnly"),
	'roundrobin' => $langs->trans("AssignRoundRobin"),
	'leastactive' => $langs->trans("AssignLeastActive"),
);
$currentLineMode = $isEdit ? ($editLine->assign_mode ?: 'manual') : 'manual';
print '<tr class="oddeven"><td>'.$langs->trans("AssignmentMode").'</td><td>';
print '<select name="assign_mode" class="flat minwidth200">';
foreach ($assignModeLabels as $modeVal => $modeLabel) {
	$selected = ($currentLineMode == $modeVal) ? ' selected' : '';
	print '<option value="'.$modeVal.'"'.$selected.'>'.$modeLabel.'</option>';
}
print '</select>';
print '<div class="wa-help-block">'.$langs->trans("AssignmentModeHelp").'</div>';
print '</td></tr>';

// Active
print '<tr class="oddeven"><td>'.$langs->trans("Active").'</td><td>';
$activeChecked = ($isEdit ? !empty($editLine->status) : true) ? ' checked' : '';
print '<label class="wa-toggle-label"><input type="checkbox" name="line_active" value="1"'.$activeChecked.'> '.$langs->trans("Active").'</label>';
print '</td></tr>';

print '</table>';

// ---- Connection test area ----
print '<div id="wa-test-area" style="margin: 16px 0;">';
print '<button type="button" id="wa-btn-test-connection" class="wa-line-btn wa-line-btn-secondary" style="gap:6px;">';
print '🔌 '.$langs->trans("TestConnectionBeforeSave");
print '</button>';
print '<div id="wa-test-results" style="display:none; margin-top:12px;"></div>';
print '</div>';

print '<div class="center tabsAction">';
print '<input type="submit" class="button button-save" value="'.($isEdit ? $langs->trans("Modify") : $langs->trans("CreateLine")).'">';
if ($isEdit) {
	print ' <a href="'.$_SERVER["PHP_SELF"].'" class="button button-cancel">'.$langs->trans("Cancel").'</a>';
}
print '</div>';
print '</form>';
print '</div>'; // End line form container

// ---- JS for inline connection test ----
$testAjaxUrl = dol_buildpath('/custom/whatsappdati/ajax/test_connection.php', 1);
print '<script>
$(document).ready(function() {
	$("#wa-btn-test-connection").on("click", function() {
		var btn = $(this);
		var results = $("#wa-test-results");

		// Gather form values
		var appId = $("input[name=app_id]").val() || "";
		var phoneNumberId = $("input[name=phone_number_id]").val() || "";
		var businessAccountId = $("input[name=business_account_id]").val() || "";
		var accessToken = $("input[name=access_token]").val() || "";
		var lineId = $("input[name=line_id]").val() || 0;

		// Basic client-side validation
		if (!phoneNumberId) {
			results.html(\'<div style="padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;">⚠️ Ingrese el ID del Número de Teléfono antes de probar.</div>\').show();
			return;
		}
		if (!accessToken || (accessToken === "••••••••" && !lineId)) {
			results.html(\'<div style="padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;">⚠️ Ingrese el Token de Acceso antes de probar.</div>\').show();
			return;
		}

		// Show loading state
		btn.prop("disabled", true).html("⏳ '.$langs->trans("TestingConnection").'...");
		results.html(\'<div style="padding:12px;background:var(--colorbacklinepair,#f8f9fa);border:1px solid var(--colorbordertitle,#dee2e6);border-radius:8px;text-align:center;color:#666;">⏳ Conectando con Meta API...</div>\').show();

		$.ajax({
			url: "'.dol_escape_js($testAjaxUrl).'",
			type: "POST",
			contentType: "application/json",
			data: JSON.stringify({
				app_id: appId,
				phone_number_id: phoneNumberId,
				business_account_id: businessAccountId,
				access_token: accessToken,
				line_id: parseInt(lineId) || 0
			}),
			dataType: "json",
			timeout: 30000
		}).done(function(resp) {
			var html = \'<div style="border:1px solid \' + (resp.success ? "#bbf7d0" : "#fecaca") + \';border-radius:8px;overflow:hidden;">\';

			// Header
			html += \'<div style="padding:10px 14px;background:\' + (resp.success ? "#dcfce7" : "#fef2f2") + \';font-weight:600;font-size:13px;">\';
			html += resp.success ? "✅ Conexión exitosa" : "❌ Se encontraron errores";
			html += \'</div>\';

			// Results table
			if (resp.results && resp.results.length > 0) {
				html += \'<table style="width:100%;border-collapse:collapse;">\';
				for (var i = 0; i < resp.results.length; i++) {
					var r = resp.results[i];
					var icon = r.status === "ok" ? "✅" : (r.status === "warning" ? "⚠️" : "❌");
					var rowBg = i % 2 === 0 ? "var(--colorbacklinepair,#fafafa)" : "transparent";
					html += \'<tr style="background:\' + rowBg + \'">\';
					html += \'<td style="padding:8px 14px;font-size:13px;white-space:nowrap;">\' + icon + \' <strong>\' + r.label + \'</strong></td>\';
					html += \'<td style="padding:8px 14px;font-size:13px;color:#555;">\' + (r.detail || "") + \'</td>\';
					html += \'</tr>\';

					// Extra details
					if (r.extra) {
						var extras = [];
						for (var k in r.extra) {
							if (r.extra[k] && r.extra[k] !== "N/A") extras.push(k + ": " + r.extra[k]);
						}
						if (extras.length > 0) {
							html += \'<tr style="background:\' + rowBg + \'">\';
							html += \'<td></td><td style="padding:0 14px 8px;font-size:11px;color:#888;">\' + extras.join(" · ") + \'</td>\';
							html += \'</tr>\';
						}
					}
				}
				html += \'</table>\';
			}
			html += \'</div>\';
			results.html(html).show();
		}).fail(function(xhr) {
			var errMsg = "Error de red";
			try { errMsg = JSON.parse(xhr.responseText).error || errMsg; } catch(e) {}
			results.html(\'<div style="padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;">❌ \' + errMsg + \'</div>\').show();
		}).always(function() {
			btn.prop("disabled", false).html("🔌 '.$langs->transnoentities("TestConnectionBeforeSave").'");
		});
	});
});
</script>';


// =========================================
// SECTION 2: Configuration Dashboard (Cards)
// =========================================

print '<div class="wa-section-title"><span class="wa-section-icon">⚙️</span><h2>'.$langs->trans("GlobalSettings").'</h2></div>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_global">';

// Read current config values
$currentRealtimeMode = getDolGlobalString('WHATSAPPDATI_REALTIME_MODE', 'polling');
$currentRateLimit = getDolGlobalInt('WHATSAPPDATI_RATE_LIMIT_MS', 100);
$bhEnabled  = getDolGlobalString('WHATSAPPDATI_BH_ENABLED');
$bhStart    = getDolGlobalString('WHATSAPPDATI_BH_START', '09:00');
$bhEnd      = getDolGlobalString('WHATSAPPDATI_BH_END', '18:00');
$bhDaysStr  = getDolGlobalString('WHATSAPPDATI_BH_DAYS', '1,2,3,4,5');
$bhDays     = array_map('intval', array_filter(explode(',', $bhDaysStr)));
$bhTimezone = getDolGlobalString('WHATSAPPDATI_BH_TIMEZONE', date_default_timezone_get());
$bhMessage  = getDolGlobalString('WHATSAPPDATI_BH_MESSAGE', 'Gracias por escribirnos. En este momento estamos fuera de nuestro horario de atención. Le responderemos lo antes posible.');
$csatEnabled = getDolGlobalString('WHATSAPPDATI_CSAT_ENABLED');
$csatMessage = getDolGlobalString('WHATSAPPDATI_CSAT_MESSAGE', "Gracias por contactarnos. Por favor califique su experiencia del 1 al 5:\n1 ⭐ Muy mala\n2 ⭐⭐ Mala\n3 ⭐⭐⭐ Regular\n4 ⭐⭐⭐⭐ Buena\n5 ⭐⭐⭐⭐⭐ Excelente");
$csatThanks  = getDolGlobalString('WHATSAPPDATI_CSAT_THANKS', '¡Gracias por su calificación! Su opinión nos ayuda a mejorar. 🙏');

print '<div class="wa-setup-grid">';

// ---- CARD 1: Real-time / SSE ----
$realtimeBadge = $currentRealtimeMode === 'sse'
	? '<span class="wa-card-badge wa-badge-on">⚡ SSE</span>'
	: '<span class="wa-card-badge wa-badge-info">⟳ Polling</span>';

print '<div class="wa-card">';
print '<div class="wa-card-header">';
print '<div class="wa-card-header-left"><span class="wa-card-icon">⚡</span><div><div class="wa-card-title">'.$langs->trans("RealtimeConfiguration").'</div><div class="wa-card-subtitle">'.$langs->trans("RealtimeModeHelp").'</div></div></div>';
print $realtimeBadge.'<span class="wa-card-chevron">▼</span>';
print '</div>';
print '<div class="wa-card-body">';

print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("RealtimeMode").'</td><td>';
print '<select name="realtime_mode" class="flat minwidth200">';
$realtimeModes = array('polling' => $langs->trans("RealtimePolling"), 'sse' => $langs->trans("RealtimeSSE"));
foreach ($realtimeModes as $modeVal => $modeLabel) {
	$selected = ($currentRealtimeMode == $modeVal) ? ' selected' : '';
	print '<option value="'.$modeVal.'"'.$selected.'>'.$modeLabel.'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("RateLimitMs").'</td><td>';
print '<input type="number" name="rate_limit_ms" value="'.$currentRateLimit.'" min="50" max="5000" step="10" class="flat width75"> ms';
print '<div class="wa-help-block">'.$langs->trans("RateLimitMsHelp").'</div>';
print '</td></tr>';
print '</table>';

print '</div>'; // card-body
print '</div>'; // card

// ---- CARD 3: Business Hours ----
$bhBadge = $bhEnabled
	? '<span class="wa-card-badge wa-badge-on">✓ '.$bhStart.' – '.$bhEnd.'</span>'
	: '<span class="wa-card-badge wa-badge-off">'.$langs->trans("Disabled").'</span>';

print '<div class="wa-card">';
print '<div class="wa-card-header">';
print '<div class="wa-card-header-left"><span class="wa-card-icon">🕐</span><div><div class="wa-card-title">'.$langs->trans("BusinessHoursTitle").'</div><div class="wa-card-subtitle">'.$langs->trans("BusinessHoursEnabledHelp").'</div></div></div>';
print $bhBadge.'<span class="wa-card-chevron">▼</span>';
print '</div>';
print '<div class="wa-card-body">';

print '<table class="noborder centpercent">';

// Enabled toggle
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("BusinessHoursEnabled").'</td><td>';
print '<label class="wa-toggle-label"><input type="checkbox" name="bh_enabled" value="1"'.($bhEnabled ? ' checked' : '').'> '.$langs->trans("Active").'</label>';
print '</td></tr>';

// Schedule
print '<tr class="oddeven"><td>'.$langs->trans("BusinessHoursSchedule").'</td><td>';
print '<div style="display:flex; align-items:center; gap:8px;">';
print '<input type="time" name="bh_start" value="'.dol_escape_htmltag($bhStart).'" class="flat" style="padding:6px 10px;">';
print '<span style="font-weight:600;">–</span>';
print '<input type="time" name="bh_end" value="'.dol_escape_htmltag($bhEnd).'" class="flat" style="padding:6px 10px;">';
print '</div>';
print '</td></tr>';

// Days
print '<tr class="oddeven"><td>'.$langs->trans("BusinessDays").'</td><td>';
print '<div style="display:flex; flex-wrap:wrap; gap:4px;">';
$dayNames = array(1 => $langs->trans("Monday"), 2 => $langs->trans("Tuesday"), 3 => $langs->trans("Wednesday"), 4 => $langs->trans("Thursday"), 5 => $langs->trans("Friday"), 6 => $langs->trans("Saturday"), 7 => $langs->trans("Sunday"));
$dayShort = array(1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D');
foreach ($dayNames as $dNum => $dName) {
	$checked = in_array($dNum, $bhDays);
	$bgColor = $checked ? '#00a884' : 'var(--colorbacklinepair, #f0f0f0)';
	$fgColor = $checked ? '#fff' : 'var(--colortextother, #666)';
	print '<label style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:'.$bgColor.'; color:'.$fgColor.'; cursor:pointer; font-size:12px; font-weight:600; border:1px solid '.($checked ? '#00a884' : '#ddd').'; transition: all 0.15s;" title="'.$dName.'">';
	print '<input type="checkbox" name="bh_days[]" value="'.$dNum.'"'.($checked ? ' checked' : '').' style="display:none;">';
	print $dayShort[$dNum];
	print '</label>';
}
print '</div>';
print '</td></tr>';

// Timezone
print '<tr class="oddeven"><td>'.$langs->trans("Timezone").'</td><td>';
print '<select name="bh_timezone" class="flat minwidth200">';
$timezones = DateTimeZone::listIdentifiers(DateTimeZone::AMERICA | DateTimeZone::EUROPE);
foreach ($timezones as $tz) {
	$selected = ($bhTimezone === $tz) ? ' selected' : '';
	print '<option value="'.$tz.'"'.$selected.'>'.$tz.'</option>';
}
print '</select></td></tr>';

// Auto-reply message
print '<tr class="oddeven"><td>'.$langs->trans("OutOfHoursMessage").'</td><td>';
print '<textarea name="bh_message" class="flat" rows="3" style="width:100%; max-width:500px; resize:vertical;">'.dol_escape_htmltag($bhMessage).'</textarea>';
print '<div class="wa-msg-preview">'.dol_escape_htmltag($bhMessage).'</div>';
print '</td></tr>';

print '</table>';
print '</div>'; // card-body
print '</div>'; // card

// ---- CARD 4: CSAT ----
require_once '../class/whatsappcsat.class.php';
$csatObj = new WhatsAppCSAT($db);
$csatStats = $csatObj->getStats();

$csatBadge = $csatEnabled
	? '<span class="wa-card-badge wa-badge-on">✓ '.$langs->trans("Active").'</span>'
	: '<span class="wa-card-badge wa-badge-off">'.$langs->trans("Disabled").'</span>';

print '<div class="wa-card">';
print '<div class="wa-card-header">';
print '<div class="wa-card-header-left"><span class="wa-card-icon">⭐</span><div><div class="wa-card-title">'.$langs->trans("CSATTitle").'</div><div class="wa-card-subtitle">'.$langs->trans("CSATEnabledHelp").'</div></div></div>';
print $csatBadge.'<span class="wa-card-chevron">▼</span>';
print '</div>';
print '<div class="wa-card-body">';

print '<table class="noborder centpercent">';

// Enabled toggle
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("CSATEnabled").'</td><td>';
print '<label class="wa-toggle-label"><input type="checkbox" name="csat_enabled" value="1"'.($csatEnabled ? ' checked' : '').'> '.$langs->trans("Active").'</label>';
print '</td></tr>';

// Survey message
print '<tr class="oddeven"><td>'.$langs->trans("CSATSurveyMessage").'</td><td>';
print '<textarea name="csat_message" class="flat" rows="3" style="width:100%; max-width:500px; resize:vertical;">'.dol_escape_htmltag($csatMessage).'</textarea>';
print '</td></tr>';

// Thanks message
print '<tr class="oddeven"><td>'.$langs->trans("CSATThanksMessage").'</td><td>';
print '<textarea name="csat_thanks" class="flat" rows="2" style="width:100%; max-width:500px; resize:vertical;">'.dol_escape_htmltag($csatThanks).'</textarea>';
print '</td></tr>';

print '</table>';

// CSAT Stats Dashboard
if ($csatStats['total_surveys'] > 0) {
	print '<div style="border-top: 1px solid var(--colorbordertitle, #eee); padding-top: 12px; margin-top: 12px;">';
	print '<div style="font-size:13px; font-weight:600; margin-bottom:10px;">📊 '.$langs->trans("CSATStatistics").'</div>';

	$responseRate = $csatStats['total_surveys'] > 0 ? round($csatStats['responded'] / $csatStats['total_surveys'] * 100) : 0;
	$avgStars = round($csatStats['avg_rating']);
	$avgDisplay = '';
	for ($si = 1; $si <= 5; $si++) {
		$avgDisplay .= $si <= $avgStars ? '⭐' : '☆';
	}

	print '<div class="wa-stats-grid">';
	print '<div class="wa-stat-box"><div class="wa-stat-value">'.$csatStats['total_surveys'].'</div><div class="wa-stat-label">'.$langs->trans("TotalSurveys").'</div></div>';
	print '<div class="wa-stat-box"><div class="wa-stat-value">'.$responseRate.'%</div><div class="wa-stat-label">'.$langs->trans("ResponseRate").'</div></div>';
	print '<div class="wa-stat-box"><div class="wa-stat-value">'.$avgDisplay.'</div><div class="wa-stat-label">'.$csatStats['avg_rating'].'/5</div></div>';
	print '</div>';

	// Distribution bars
	print '<div style="margin-top:12px;">';
	for ($ri = 5; $ri >= 1; $ri--) {
		$cnt = $csatStats['distribution'][$ri];
		$pct = $csatStats['responded'] > 0 ? round($cnt / $csatStats['responded'] * 100) : 0;
		print '<div class="wa-dist-bar">';
		print '<span style="width:20px; text-align:right;">'.$ri.'⭐</span>';
		print '<div class="wa-dist-bar-track"><div class="wa-dist-bar-fill" style="width:'.$pct.'%;"></div></div>';
		print '<span style="width:30px; text-align:right; color:#666;">'.$cnt.'</span>';
		print '</div>';
	}
	print '</div>';
	print '</div>';
}

print '</div>'; // card-body
print '</div>'; // card

print '</div>'; // End grid

// Save button for all global settings
print '<div class="center tabsAction">';
print '<input type="submit" class="button button-save" name="save" value="💾 '.$langs->trans("SaveConfiguration").'">';
print '</div>';

print '</form>';


// =========================================
// SECTION 3: Content Management (Tags + Quick Replies)
// =========================================

print '<div class="wa-section-title"><span class="wa-section-icon">📝</span><h2>'.$langs->trans("ManageTags").' & '.$langs->trans("QuickReplies").'</h2></div>';

print '<div class="wa-setup-grid">';

// ---- Tags Card ----
$tagObj = new WhatsAppTag($db);
$allTags = $tagObj->fetchAllAdmin();
$tagCount = is_array($allTags) ? count($allTags) : 0;

print '<div class="wa-card wa-auto-open">';
print '<div class="wa-card-header">';
print '<div class="wa-card-header-left"><span class="wa-card-icon">🏷️</span><div><div class="wa-card-title">'.$langs->trans("TagConfiguration").'</div><div class="wa-card-subtitle">'.$tagCount.' '.strtolower($langs->trans("Tags")).'</div></div></div>';
print '<span class="wa-card-badge wa-badge-info">'.$tagCount.'</span><span class="wa-card-chevron">▼</span>';
print '</div>';
print '<div class="wa-card-body">';

// Tags table
if (!empty($allTags)) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("TagLabel").'</th>';
	print '<th>'.$langs->trans("TagColor").'</th>';
	print '<th>'.$langs->trans("TagDescription").'</th>';
	print '<th class="center">'.$langs->trans("TagUsage").'</th>';
	print '<th class="center" width="50">'.$langs->trans("Actions").'</th>';
	print '</tr>';
	foreach ($allTags as $tag) {
		print '<tr class="oddeven">';
		print '<td><span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:'.dol_escape_htmltag($tag->color).'; margin-right:6px; vertical-align:middle;"></span>'.dol_escape_htmltag($tag->label).'</td>';
		print '<td><span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:'.dol_escape_htmltag($tag->color).';"></span> <span class="opacitymedium">'.dol_escape_htmltag($tag->color).'</span></td>';
		print '<td class="opacitymedium">'.dol_escape_htmltag($tag->description).'</td>';
		print '<td class="center"><span class="badge badge-status4 badge-status">'.(int) $tag->usage_count.'</span></td>';
		print '<td class="center"><a href="'.$_SERVER["PHP_SELF"].'?action=delete_tag&tag_id='.$tag->rowid.'&token='.newToken().'" onclick="return confirm(\''.$langs->transnoentities("ConfirmDeleteTag").'\');">'.img_picto($langs->trans("Delete"), 'delete').'</a></td>';
		print '</tr>';
	}
	print '</table>';
} else {
	print '<p class="opacitymedium" style="text-align:center; padding:12px;">'.$langs->trans("NoTagsYet").'</p>';
}

// Add tag form
print '<div style="border-top:1px solid var(--colorbordertitle,#eee); padding-top:12px; margin-top:12px;">';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_tag">';
print '<input type="text" name="tag_label" class="minwidth150 flat" placeholder="'.$langs->trans("TagLabel").'" required>';
print '<input type="color" name="tag_color" value="#25D366" title="'.$langs->trans("TagColor").'" style="width:36px; height:32px; padding:2px; border:1px solid #ccc; border-radius:6px; cursor:pointer;">';
print '<input type="text" name="tag_description" class="minwidth150 flat" placeholder="'.$langs->trans("TagDescription").'">';
print '<button type="submit" class="wa-line-btn" style="padding:6px 14px;">+ '.$langs->trans("CreateTag").'</button>';
print '</form>';
print '</div>';

print '</div>'; // card-body
print '</div>'; // card

// ---- Quick Replies Card ----
$qrHandler = new WhatsAppQuickReply($db);
$quickReplies = $qrHandler->fetchAll('all');
$qrCount = is_array($quickReplies) ? count($quickReplies) : 0;

print '<div class="wa-card wa-auto-open">';
print '<div class="wa-card-header">';
print '<div class="wa-card-header-left"><span class="wa-card-icon">⚡</span><div><div class="wa-card-title">'.$langs->trans("QuickReplyConfiguration").'</div><div class="wa-card-subtitle">'.$qrCount.' '.strtolower($langs->trans("QuickReplies")).'</div></div></div>';
print '<span class="wa-card-badge wa-badge-info">'.$qrCount.'</span><span class="wa-card-chevron">▼</span>';
print '</div>';
print '<div class="wa-card-body">';

if ($qrCount > 0) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("QuickReplyShortcut").'</th>';
	print '<th>'.$langs->trans("QuickReplyTitle").'</th>';
	print '<th>'.$langs->trans("QuickReplyContent").'</th>';
	print '<th>'.$langs->trans("QuickReplyCategory").'</th>';
	print '<th class="center" width="50">'.$langs->trans("Actions").'</th>';
	print '</tr>';
	foreach ($quickReplies as $qr) {
		print '<tr class="oddeven">';
		print '<td><code style="background:var(--colorbacklinepair,#f5f5f5); padding:2px 8px; border-radius:4px; font-size:12px;">'.dol_escape_htmltag($qr->shortcut).'</code></td>';
		print '<td>'.dol_escape_htmltag($qr->title).'</td>';
		print '<td class="small tdoverflowmax300" title="'.dol_escape_htmltag($qr->content).'">'.dol_escape_htmltag(dol_trunc($qr->content, 80)).'</td>';
		print '<td><span class="opacitymedium">'.dol_escape_htmltag($qr->category).'</span></td>';
		print '<td class="center"><a href="'.$_SERVER["PHP_SELF"].'?action=delete_quick_reply&qr_id='.$qr->rowid.'&token='.newToken().'" onclick="return confirm(\''.$langs->transnoentities("ConfirmDeleteQuickReply").'\');">'.img_picto($langs->trans("Delete"), 'delete').'</a></td>';
		print '</tr>';
	}
	print '</table>';
} else {
	print '<p class="opacitymedium" style="text-align:center; padding:12px;">'.$langs->trans("NoQuickRepliesYet").'</p>';
}

// Add quick reply form
print '<div style="border-top:1px solid var(--colorbordertitle,#eee); padding-top:12px; margin-top:12px;">';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:flex; align-items:flex-start; gap:8px; flex-wrap:wrap;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_quick_reply">';
print '<input type="text" name="qr_shortcut" class="minwidth100 flat" placeholder="'.$langs->trans("QuickReplyShortcutPlaceholder").'" required>';
print '<input type="text" name="qr_title" class="minwidth150 flat" placeholder="'.$langs->trans("QuickReplyTitle").'" required>';
print '<textarea name="qr_content" class="minwidth200 flat" rows="2" placeholder="'.$langs->trans("QuickReplyContentPlaceholder").'" required style="vertical-align:top; resize:vertical;"></textarea>';
print '<input type="text" name="qr_category" class="minwidth100 flat" placeholder="'.$langs->trans("QuickReplyCategory").'">';
print '<button type="submit" class="wa-line-btn" style="padding:6px 14px; margin-top:2px;">+ '.$langs->trans("CreateQuickReply").'</button>';
print '</form>';
print '</div>';

print '</div>'; // card-body
print '</div>'; // card

print '</div>'; // End grid

print dol_get_fiche_end();

// JS to update day-circle colors on toggle
print '<script>
$(document).ready(function() {
	$(document).on("change", "input[name=\'bh_days[]\']", function() {
		var label = $(this).closest("label");
		if ($(this).is(":checked")) {
			label.css({"background": "#00a884", "color": "#fff", "border-color": "#00a884"});
		} else {
			label.css({"background": "var(--colorbacklinepair, #f0f0f0)", "color": "var(--colortextother, #666)", "border-color": "#ddd"});
		}
	});
	// Live preview for OOH message
	$("textarea[name=bh_message]").on("input", function() {
		$(this).next(".wa-msg-preview").text($(this).val());
	});
});
</script>';

// End of page
llxFooter();
$db->close();
