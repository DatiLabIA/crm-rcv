<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       templates.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Templates management page
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once './class/whatsapptemplate.class.php';
require_once './class/whatsappconfig.class.php';

// Ensure connection supports emojis (utf8mb4 = 4 bytes) for both read and write
$db->query("SET NAMES utf8mb4");

$langs->loadLangs(array("whatsappdati@whatsappdati"));

if (!$user->rights->whatsappdati->template->write) {
	accessforbidden();
}

$action  = GETPOST('action', 'aZ09');
$id      = GETPOST('id', 'int');
$line_id = GETPOST('line_id', 'int');

$template = new WhatsAppTemplate($db);
$form     = new Form($db);

$configObj   = new WhatsAppConfig($db);
$activeLines = $configObj->fetchActiveLines();

$lineOptions = array(0 => '-- '.$langs->trans("SelectLine").' --');
foreach ($activeLines as $lineObj) {
	$lineOptions[$lineObj->id] = $lineObj->label;
}

$langOptions = array(
	'es' => 'es', 'es_ES' => 'es_ES', 'es_MX' => 'es_MX', 'es_AR' => 'es_AR',
	'es_CO' => 'es_CO', 'es_CL' => 'es_CL', 'es_PE' => 'es_PE', 'es_VE' => 'es_VE',
	'en_US' => 'en_US', 'en_GB' => 'en_GB', 'pt_BR' => 'pt_BR', 'pt_PT' => 'pt_PT',
	'fr' => 'fr', 'de' => 'de', 'it' => 'it', 'nl' => 'nl',
	'ru' => 'ru', 'zh_CN' => 'zh_CN', 'zh_TW' => 'zh_TW', 'ja' => 'ja',
	'ko' => 'ko', 'ar' => 'ar', 'tr' => 'tr',
);

$catOptions = array(
	'MARKETING'      => '🛍️ '.$langs->trans("MARKETING"),
	'UTILITY'        => '⚙️ '.$langs->trans("UTILITY"),
	'AUTHENTICATION' => '🔐 '.$langs->trans("AUTHENTICATION"),
);

$statusOptions = array(
	'pending'  => $langs->trans("pending"),
	'approved' => $langs->trans("approved"),
	'rejected' => $langs->trans("rejected"),
);

// =================== ACTIONS ===================

if ($action == 'push_to_meta' && $id > 0 && !GETPOST('cancel', 'alpha')) {
	if ($template->fetch($id) > 0) {
		$pushResult = $template->pushToMeta();
		if ($pushResult > 0) {
			setEventMessages($langs->trans("TemplateSentToMeta").' — '.$langs->trans("TemplateStatus").': '.$template->status, null, 'mesgs');
		} else {
			setEventMessages($langs->trans("ErrorPushingToMeta").': '.implode(', ', $template->errors), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans("ErrorTemplateNotFound"), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"].($line_id > 0 ? '?line_id='.$line_id : '')); exit;
}

if ($action == 'sync' && !GETPOST('cancel', 'alpha')) {
	$result = $template->syncFromMeta($line_id);
	if ($result >= 0) {
		setEventMessages($langs->trans("TemplatesSynced").' ('.$result.' '.$langs->trans("Templates").')', null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorSyncingTemplates"), null, 'errors');
	}
	$redirect = $_SERVER["PHP_SELF"];
	if ($line_id > 0) $redirect .= '?line_id='.$line_id;
	header("Location: ".$redirect); exit;
}

if (($action == 'save' || $action == 'update') && !GETPOST('cancel', 'alpha')) {
	// Ensure table columns support emojis (utf8mb4). Idempotent — harmless if already converted.
	$db->query("ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

	$error     = 0;
	$name      = trim(GETPOST('name', 'alphanohtml'));
	$language  = trim(GETPOST('language', 'alphanohtml'));
	$body_text = trim(GETPOST('body_text', 'restricthtml'));
	if (empty($name))      { setEventMessages($langs->trans("ErrorTemplateNameRequired"),     null, 'errors'); $error++; }
	if (empty($language))  { setEventMessages($langs->trans("ErrorTemplateLanguageRequired"), null, 'errors'); $error++; }
	if (empty($body_text)) { setEventMessages($langs->trans("ErrorTemplateBodyRequired"),     null, 'errors'); $error++; }

	$buttonsArr = array();
	$bjson = GETPOST('buttons_json', 'none');
	if (!empty($bjson)) { $dec = json_decode($bjson, true); if (is_array($dec)) $buttonsArr = $dec; }

	if (!$error) {
		if ($action == 'update' && $id > 0) {
			if ($template->fetch($id) <= 0) { setEventMessages($langs->trans("ErrorTemplateNotFound"), null, 'errors'); $error++; }
		}
		if (!$error) {
			$template->fk_line        = GETPOST('fk_line', 'int');
			$template->name           = $name;
			$template->language       = $language;
			$template->category       = GETPOST('category', 'aZ09');
			// Preserve existing status for templates already pushed/synced to Meta (have a template_id).
			// Only reset to 'draft' for new templates or if the user explicitly forced a re-push.
			if ($action == 'save' || empty($template->template_id)) {
				$template->status = 'draft';
			}
			// else: keep $template->status as fetched (approved, pending, etc.)
			$template->header_type    = GETPOST('header_type', 'aZ09') ?: null;
			$template->header_content = trim(GETPOST('header_content', 'restricthtml')) ?: null;
			$template->body_text      = $body_text;
			$template->footer_text    = trim(GETPOST('footer_text', 'restricthtml')) ?: null;
			$template->buttons        = !empty($buttonsArr) ? json_encode($buttonsArr) : null;
			preg_match_all('/\{\{(\d+)\}\}/', $body_text, $mx);
			$template->variables = json_encode(!empty($mx[1]) ? array_values(array_unique($mx[1])) : array());

			// Variable mapping
			$vmRaw = GETPOST('variable_mapping', 'none');
			if (!empty($vmRaw)) {
				$vmDec = json_decode($vmRaw, true);
				$template->variable_mapping = is_array($vmDec) ? json_encode($vmDec) : '{}';
			} else {
				$template->variable_mapping = '{}';
			}

			// Header image mode
			$template->header_image_mode = GETPOST('header_image_mode', 'aZ09') ?: 'on_send';

			// Handle header media file upload (as actual file for on_template, or sample for Meta on on_send)
			if (in_array($template->header_type, array('IMAGE','VIDEO','DOCUMENT')) && !empty($_FILES['header_media_file']['tmp_name'])) {
				$uploadDir = DOL_DATA_ROOT.'/whatsappdati/templates/';
				if (!is_dir($uploadDir)) dol_mkdir($uploadDir);
				$origName = dol_sanitizeFileName($_FILES['header_media_file']['name']);
				$destPath = $uploadDir.uniqid('tpl_').'_'.$origName;
				if (dol_move_uploaded_file($_FILES['header_media_file']['tmp_name'], $destPath, 1, 0, 0, 0, '') > 0) {
					$template->header_media_local = $destPath;
				}
			}

			if ($action == 'save') {
				$result = $template->create($user);
				if ($result > 0) {
					// Auto-push to Meta for review
					$pushResult = $template->pushToMeta();
					if ($pushResult > 0) {
						setEventMessages($langs->trans("TemplateCreated").' — '.$langs->trans("TemplateSentToMeta").' ('.$template->status.')', null, 'mesgs');
					} else {
						setEventMessages($langs->trans("TemplateCreated"), null, 'mesgs');
						setEventMessages($langs->trans("ErrorPushingToMeta").': '.implode(', ', $template->errors), null, 'warnings');
					}
					header("Location: ".$_SERVER["PHP_SELF"].($line_id > 0 ? '?line_id='.$line_id : '')); exit;
				} else {
					setEventMessages($langs->trans("Error").' '.implode(',', $template->errors), null, 'errors');
					$action = 'create';
				}
			} else {
				$result = $template->update($user);
				if ($result > 0) {
					// Auto-push to Meta for review (only if not yet synced or user forces)
					if (empty($template->template_id) || GETPOST('push_to_meta', 'alpha') === 'yes') {
						$pushResult = $template->pushToMeta();
						if ($pushResult > 0) {
							setEventMessages($langs->trans("TemplateUpdated").' — '.$langs->trans("TemplateSentToMeta").' ('.$template->status.')', null, 'mesgs');
						} else {
							setEventMessages($langs->trans("TemplateUpdated"), null, 'mesgs');
							setEventMessages($langs->trans("ErrorPushingToMeta").': '.implode(', ', $template->errors), null, 'warnings');
						}
					} else {
						setEventMessages($langs->trans("TemplateUpdated"), null, 'mesgs');
					}
					header("Location: ".$_SERVER["PHP_SELF"].($line_id > 0 ? '?line_id='.$line_id : '')); exit;
				} else {
					setEventMessages($langs->trans("Error").' '.implode(',', $template->errors), null, 'errors');
					$action = 'edit';
				}
			}
		}
	} else {
		$action = ($action == 'update') ? 'edit' : 'create';
	}
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes') {
	if ($template->fetch($id) > 0) {
		$r = $template->delete($user);
		if ($r > 0) setEventMessages($langs->trans("TemplateDeleted"), null, 'mesgs');
		else         setEventMessages($langs->trans("Error").' '.implode(',', $template->errors), null, 'errors');
	} else {
		setEventMessages($langs->trans("ErrorTemplateNotFound"), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"].($line_id > 0 ? '?line_id='.$line_id : '')); exit;
}

if ($action == 'edit' && $id > 0) {
	if ($template->fetch($id) <= 0) {
		setEventMessages($langs->trans("ErrorTemplateNotFound"), null, 'errors');
		$action = '';
	}
}

// =================== VIEW ===================

$title = $langs->trans("Templates");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-whatsappdati page-templates');

print load_fiche_titre($title, '', 'whatsappdati@whatsappdati');

// ---- CSS ----
print '<style>
.wa-card{background:var(--colorbackbody,#fff);border:1px solid var(--colorbordertitle,#dee2e6);border-radius:8px;overflow:hidden;margin-bottom:12px;}
.wa-card-header{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;cursor:pointer;user-select:none;border-bottom:1px solid transparent;transition:background .15s,border-color .15s;}
.wa-card-header:hover{background:var(--colorbacklinepairhover,#f8f9fa);}
.wa-card.wa-open .wa-card-header{border-bottom-color:var(--colorbordertitle,#dee2e6);background:var(--colorbacklinepair,#f9f9f9);}
.wa-card-header-left{display:flex;align-items:center;gap:10px;}
.wa-card-icon{font-size:18px;width:26px;text-align:center;flex-shrink:0;}
.wa-card-title{font-size:13px;font-weight:600;color:var(--colortexttitle,#333);}
.wa-card-subtitle{font-size:11px;color:var(--colortextother,#888);margin-top:1px;}
.wa-card-body{display:none;padding:16px;}
.wa-card.wa-open .wa-card-body{display:block;}
.wa-card-chevron{font-size:12px;color:var(--colortextother,#999);transition:transform .2s;margin-left:8px;}
.wa-card.wa-open .wa-card-chevron{transform:rotate(180deg);}
.wa-section-title{display:flex;align-items:center;gap:10px;margin:20px 0 14px;padding-bottom:8px;border-bottom:2px solid var(--colorbordertitle,#dee2e6);}
.wa-section-title h2{font-size:15px;font-weight:700;color:var(--colortexttitle,#333);margin:0;}
.wa-help-block{background:var(--colorbacklinepair,#f8f9fa);border-left:3px solid var(--colorbordertitle,#ccc);padding:6px 10px;margin-top:6px;font-size:11.5px;color:var(--colortextother,#666);border-radius:0 4px 4px 0;line-height:1.5;}
/* buttons */
.wa-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:background .15s;text-decoration:none;}
.wa-btn-primary{background:#00a884;color:#fff;}
.wa-btn-primary:hover{background:#009174;color:#fff;}
.wa-btn-secondary{background:var(--colorbacklinepair,#f0f0f0);color:var(--colortexttitle,#333);border:1px solid var(--colorbordertitle,#ccc);}
.wa-btn-secondary:hover{background:var(--colorbacklinepairhover,#e0e0e0);}
.wa-btn-danger{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;}
.wa-btn-danger:hover{background:#fecaca;}
.wa-btn-sm{padding:4px 10px;font-size:12px;}
/* form layout */
.wa-tpl-layout{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;}
@media(max-width:1100px){.wa-tpl-layout{grid-template-columns:1fr;}}
/* preview */
.wa-preview-panel{position:sticky;top:80px;}
.wa-preview-phone{background:#e5ddd5;border-radius:16px;padding:16px 12px;min-height:280px;font-family:"Segoe UI",Helvetica,Arial,sans-serif;}
.wa-preview-phone-bar{background:#075e54;color:#fff;border-radius:12px 12px 0 0;padding:10px 14px;margin:-16px -12px 12px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;}
.wa-preview-bubble{background:#fff;border-radius:0 8px 8px 8px;padding:8px 10px 6px;max-width:280px;box-shadow:0 1px 2px rgba(0,0,0,.15);font-size:13px;line-height:1.45;}
.wa-preview-header-img{width:100%;height:110px;background:#d1d5db;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:32px;margin-bottom:6px;}
.wa-preview-header-text{font-weight:700;font-size:14px;color:#111;margin-bottom:5px;}
.wa-preview-body{color:#111;white-space:pre-wrap;word-break:break-word;}
.wa-preview-footer{font-size:11px;color:#8e8e8e;margin-top:4px;}
.wa-preview-time{font-size:11px;color:#8e8e8e;text-align:right;margin-top:4px;}
.wa-preview-buttons{margin-top:4px;}
.wa-preview-btn{display:block;width:100%;text-align:center;padding:7px 4px;font-size:13px;color:#00a884;font-weight:500;border-top:1px solid #f0f0f0;cursor:default;}
/* var pills */
.wa-var-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
.wa-var-pill{background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;cursor:pointer;transition:background .12s;}
.wa-var-pill:hover{background:#bfdbfe;}
/* chars */
.wa-char-count{font-size:11px;color:var(--colortextother,#999);text-align:right;margin-top:2px;}
.wa-char-count.warn{color:#d97706;font-weight:600;}
.wa-char-count.over{color:#dc2626;font-weight:600;}
/* status / cat badges for list */
.wa-s-approved{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-s-pending{background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-s-rejected{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-s-draft{background:#e5e7eb;color:#374151;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-c-marketing{background:#fce7f3;color:#9d174d;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-c-utility{background:#e0f2fe;color:#075985;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
.wa-c-authentication{background:#f3e8ff;color:#6b21a8;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
/* field groups */
.wa-fg{margin-bottom:14px;}
.wa-fg label{display:block;font-size:12px;font-weight:600;color:var(--colortexttitle,#333);margin-bottom:4px;}
.wa-fg label.req::after{content:" *";color:#dc2626;}
.wa-fg input[type=text],.wa-fg input[type=url],.wa-fg input[type=tel],.wa-fg select,.wa-fg textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--colorbordertitle,#ccc);border-radius:6px;font-size:13px;background:var(--colorbackbody,#fff);color:var(--colortexttitle,#333);}
.wa-fg input:focus,.wa-fg select:focus,.wa-fg textarea:focus{border-color:#00a884;outline:none;box-shadow:0 0 0 2px rgba(0,168,132,.12);}
.wa-g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:700px){.wa-g2{grid-template-columns:1fr;}}
/* list action btns */
.wa-alink{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;transition:background .15s;}
.wa-alink-edit{background:#e0f2fe;color:#0369a1;} .wa-alink-edit:hover{background:#bae6fd;}
.wa-alink-del{background:#fee2e2;color:#b91c1c;}  .wa-alink-del:hover{background:#fecaca;}
.wa-tpl-body-preview{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--colortextother,#555);font-size:12px;}
/* button builder rows */
.wa-brow{display:grid;gap:8px;align-items:center;margin-bottom:8px;padding:10px 12px;background:var(--colorbacklinepair,#f8f9fa);border-radius:6px;border:1px solid var(--colorbordertitle,#eee);}
.wa-brow input[type=text]{box-sizing:border-box;width:100%;padding:6px 8px;border:1px solid var(--colorbordertitle,#ccc);border-radius:4px;font-size:12px;background:var(--colorbackbody,#fff);}
</style>';

// =====================
// FORM (CREATE / EDIT)
// =====================

if ($action == 'create' || $action == 'edit') {
	$isEdit = ($action == 'edit');

	$val = array(
		'fk_line'        => GETPOSTISSET('fk_line')        ? GETPOST('fk_line','int')               : ($isEdit ? $template->fk_line        : (count($activeLines)==1 ? $activeLines[0]->id : 0)),
		'name'           => GETPOSTISSET('name')           ? GETPOST('name','alphanohtml')           : ($isEdit ? $template->name           : ''),
		'language'       => GETPOSTISSET('language')       ? GETPOST('language','alphanohtml')       : ($isEdit ? $template->language       : 'es'),
		'category'       => GETPOSTISSET('category')       ? GETPOST('category','aZ09')              : ($isEdit ? $template->category       : 'MARKETING'),
		'status'         => GETPOSTISSET('status')         ? GETPOST('status','aZ09')                : ($isEdit ? $template->status         : 'pending'),
		'header_type'    => GETPOSTISSET('header_type')    ? GETPOST('header_type','aZ09')           : ($isEdit ? $template->header_type    : ''),
		'header_content' => GETPOSTISSET('header_content') ? GETPOST('header_content','restricthtml'): ($isEdit ? $template->header_content : ''),
		'body_text'      => GETPOSTISSET('body_text')      ? GETPOST('body_text','restricthtml')     : ($isEdit ? $template->body_text      : ''),
		'footer_text'    => GETPOSTISSET('footer_text')    ? GETPOST('footer_text','restricthtml')   : ($isEdit ? $template->footer_text    : ''),
		'buttons'        => $isEdit ? ($template->buttons ?: '[]') : '[]',
		'header_image_mode' => GETPOSTISSET('header_image_mode') ? GETPOST('header_image_mode','aZ09') : ($isEdit ? ($template->header_image_mode ?: 'on_send') : 'on_send'),
		'header_media_url'   => $isEdit ? ($template->header_media_url ?: '') : '',
		'header_media_local' => $isEdit ? ($template->header_media_local ?: '') : '',
		'variable_mapping'   => GETPOSTISSET('variable_mapping')  ? GETPOST('variable_mapping','none')  : ($isEdit ? ($template->variable_mapping ?: '{}') : '{}'),
	);
	if (GETPOSTISSET('buttons_json')) {
		$val['buttons'] = GETPOST('buttons_json','none') ?: '[]';
	}
	$btnsDecoded = @json_decode($val['buttons'], true);
	if (!is_array($btnsDecoded)) $btnsDecoded = array();
	$val['buttons'] = json_encode($btnsDecoded);

	$pageTitle  = $isEdit ? $langs->trans("EditTemplate") : $langs->trans("NewTemplate");
	$returnUrl  = $_SERVER["PHP_SELF"].($line_id > 0 ? '?line_id='.$line_id : '');

	print '<div class="wa-section-title"><span>'.($isEdit ? '✏️' : '➕').'</span><h2>'.$pageTitle.'</h2></div>';
	print '<form id="wa-tpl-form" method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token"        value="'.newToken().'">';
	print '<input type="hidden" name="action"       value="'.($isEdit ? 'update' : 'save').'">';
	print '<input type="hidden" name="id"           value="'.$id.'">';
	print '<input type="hidden" name="line_id"      value="'.$line_id.'">';
	print '<input type="hidden" name="buttons_json" id="buttons_json_field" value="'.dol_escape_htmltag($val['buttons']).'">';

	print '<div class="wa-tpl-layout">';
	print '<div id="wa-form-left">';

	/* ---- Card 1: Basic info ---- */
	print '<div class="wa-card wa-open">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">📋</span>
				<div><div class="wa-card-title">'.$langs->trans("BasicInfo").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("TemplateBasicInfoDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div class="wa-fg"><label class="req">'.$langs->trans("TemplateName").'</label>';
	print '<input type="text" name="name" id="tpl_name" maxlength="512" placeholder="ej: Bienvenida Cliente VIP" value="'.dol_escape_htmltag($val['name']).'" autocomplete="off" oninput="waUpdateSlug();">';
	print '<div id="tpl_slug_preview" style="margin-top:4px;padding:4px 10px;background:var(--colorbacklinepair,#f5f5f5);border-radius:4px;font-family:monospace;font-size:12px;color:var(--colortextother,#666);display:'.(!empty($val['name']) ? 'block' : 'none').';">Meta: <strong id="tpl_slug_text">'.dol_escape_htmltag(strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($val['name'])))).'</strong></div>';
	print '<div class="wa-help-block">'.$langs->trans("TemplateNameHelp").'</div></div>';

	print '<div class="wa-g2">';
	print '<div class="wa-fg"><label class="req">'.$langs->trans("TemplateLanguage").'</label><select name="language" id="tpl_language">';
	$extraLang = ($val['language'] && !array_key_exists($val['language'], $langOptions)) ? array($val['language'] => $val['language']) : array();
	foreach (array_merge($extraLang, $langOptions) as $k => $v) {
		print '<option value="'.dol_escape_htmltag($k).'"'.($val['language']==$k?' selected':'').'>'.dol_escape_htmltag($v).'</option>';
	}
	print '</select></div>';
	print '<div class="wa-fg"><label>'.$langs->trans("TemplateCategory").'</label><select name="category" id="tpl_category">';
	foreach ($catOptions as $k => $v) {
		print '<option value="'.$k.'"'.($val['category']==$k?' selected':'').'>'.dol_escape_htmltag($v).'</option>';
	}
	print '</select></div></div>';

	print '<div class="wa-g2">';
	print '<div class="wa-fg"><label>'.$langs->trans("WhatsAppLine").'</label><select name="fk_line">';
	foreach ($lineOptions as $k => $v) {
		print '<option value="'.$k.'"'.($val['fk_line']==$k?' selected':'').'>'.dol_escape_htmltag($v).'</option>';
	}
	print '</select></div>';
	$statusLabel = $val['status'] ?: 'draft';
	$statusClass = 'wa-s-'.($statusLabel == 'APPROVED' ? 'approved' : ($statusLabel == 'REJECTED' ? 'rejected' : 'pending'));
	print '<div class="wa-fg"><label>'.$langs->trans("TemplateStatus").'</label>';
	print '<div style="padding:8px 0;"><span class="'.$statusClass.'">'.dol_escape_htmltag($langs->trans(ucfirst(strtolower($statusLabel)))).'</span>';
	if ($isEdit && !empty($template->template_id)) {
		print ' <span style="font-size:11px;color:var(--colortextother,#888);margin-left:6px;">('.$langs->trans("ManagedByMeta").')</span>';
	}
	print '</div></div></div>';

	if ($isEdit && !empty($template->template_id)) {
		print '<div class="wa-fg"><label>'.$langs->trans("TemplateID").'</label>';
		print '<input type="text" value="'.dol_escape_htmltag($template->template_id).'" readonly style="background:var(--colorbacklinepair,#f5f5f5);font-family:monospace;cursor:default;">';
		print '</div>';
	}
	print '</div></div>'; // card-body, card

	/* ---- Card 2: Header ---- */
	$hdrOpen = !empty($val['header_type']) ? ' wa-open' : '';
	print '<div class="wa-card'.$hdrOpen.'">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">🖼️</span>
				<div><div class="wa-card-title">'.$langs->trans("TemplateHeader").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("TemplateHeaderDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div class="wa-fg"><label>'.$langs->trans("TemplateHeaderType").'</label>';
	print '<select name="header_type" id="header_type_sel" onchange="waUpdateHeaderUI();waUpdatePreview();">';
	$hdrOpts = array(''  => $langs->trans("HeaderTypeNone"), 'TEXT' => '🔤 '.$langs->trans("HeaderTypeText"),
		'IMAGE' => '🖼️ '.$langs->trans("HeaderTypeImage"), 'VIDEO' => '🎬 '.$langs->trans("HeaderTypeVideo"),
		'DOCUMENT' => '📄 '.$langs->trans("HeaderTypeDocument"));
	foreach ($hdrOpts as $k => $v) {
		print '<option value="'.$k.'"'.($val['header_type']==$k?' selected':'').'>'.dol_escape_htmltag($v).'</option>';
	}
	print '</select></div>';

	$showTxt  = ($val['header_type'] == 'TEXT') ? '' : ' style="display:none"';
	print '<div id="header_text_row"'.$showTxt.' class="wa-fg"><label>'.$langs->trans("TemplateHeaderContent").'</label>';
	print '<input type="text" name="header_content" id="header_content_input" maxlength="60" value="'.dol_escape_htmltag($val['header_content'] ?: '').'" oninput="waCharCount(this,60,\'hdr_cnt\');waUpdatePreview();" placeholder="'.$langs->trans("HeaderTextPlaceholder").'">';
	print '<div style="display:flex;justify-content:space-between;"><div class="wa-help-block" style="margin:2px 0 0;">'.$langs->trans("TemplateHeaderTextHelp").'</div><div class="wa-char-count" id="hdr_cnt">0 / 60</div></div>';
	print '</div>';

	$showMed = in_array($val['header_type'], array('IMAGE','VIDEO','DOCUMENT')) ? '' : ' style="display:none"';
	print '<div id="header_media_row"'.$showMed.'>';

	// Image mode toggle
	$himVal = !empty($val['header_image_mode']) ? $val['header_image_mode'] : 'on_send';
	print '<div class="wa-fg"><label>'.$langs->trans("HeaderImageMode").'</label>';
	print '<select name="header_image_mode" id="header_image_mode_sel" onchange="waUpdateHeaderMediaUI();">';
	print '<option value="on_send"'.($himVal == 'on_send' ? ' selected' : '').'>'.$langs->trans("HeaderImageOnSend").'</option>';
	print '<option value="on_template"'.($himVal == 'on_template' ? ' selected' : '').'>'.$langs->trans("HeaderImageOnTemplate").'</option>';
	print '</select>';
	print '<div class="wa-help-block">'.$langs->trans("HeaderImageModeHelp").'</div>';
	print '</div>';

	// Current media indicator (always visible when a file exists)
	$currentMedia = !empty($val['header_media_local']) ? basename($val['header_media_local']) : (!empty($val['header_media_url']) ? basename($val['header_media_url']) : '');
	if (!empty($currentMedia)) {
		print '<div style="margin:8px 0;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;">📎 '.$langs->trans("CurrentMedia").': <strong>'.dol_escape_htmltag($currentMedia).'</strong></div>';
	}

	// Upload area (only visible when mode = on_template)
	$showUpload = ($himVal == 'on_template') ? '' : ' style="display:none"';
	print '<div id="header_upload_area"'.$showUpload.'>';
	print '<div class="wa-fg"><label>'.$langs->trans("HeaderMediaFile").'</label>';
	print '<input type="file" name="header_media_file" id="header_media_file" accept="image/*,video/*,application/pdf" class="flat" style="padding:6px;" '.($himVal == 'on_send' ? 'disabled' : '').'>';
	print '</div>';
	print '</div>';

	print '<div id="header_on_send_note"'.($himVal == 'on_send' ? '' : ' style="display:none"').'>';
	print '<div class="wa-help-block">📎 '.$langs->trans("TemplateHeaderMediaOnSendHelp").'</div>';
	// Sample image upload — required so Meta creates the template with an IMAGE header component
	print '<div class="wa-fg" style="margin-top:8px;">';
	print '<label style="font-weight:600;">'.$langs->trans("HeaderSampleImage").' <span style="color:#dc3545;">*</span></label>';
	$hasSample = !empty($val['header_media_local']) && file_exists($val['header_media_local']);
	if ($hasSample) {
		print '<div style="margin-bottom:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:12px;color:#166534;">✅ '.dol_escape_htmltag(basename($val['header_media_local'])).' — '.$langs->trans("HeaderSampleImageSet").'</div>';
	}
	print '<input type="file" name="header_media_file" id="header_media_file_send" accept="image/*,video/*,application/pdf" class="flat" style="padding:6px;">';
	print '<div class="wa-help-block" style="margin-top:4px;color:#856404;">⚠️ '.$langs->trans("HeaderSampleImageHelp").'</div>';
	print '</div>';
	print '</div>';

	print '</div>';
	print '</div></div>'; // card

	/* ---- Card 3: Body ---- */
	print '<div class="wa-card wa-open">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">💬</span>
				<div><div class="wa-card-title">'.$langs->trans("TemplateBody").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("TemplateBodyDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div class="wa-fg"><label class="req">'.$langs->trans("TemplateBody").'</label>';
	print '<textarea name="body_text" id="tpl_body" rows="5" maxlength="1024" oninput="waCharCount(this,1024,\'body_cnt\');waDetectVars();waUpdatePreview();" placeholder="'.$langs->trans("TemplateBodyPlaceholder").'">'.htmlspecialchars($val['body_text'], ENT_QUOTES).'</textarea>';
	print '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;">';
	print '<div class="wa-help-block" style="margin:0;">'.$langs->trans("TemplateBodyHelp").'</div>';
	print '<div class="wa-char-count" id="body_cnt">0 / 1024</div></div>';
	print '</div>';

	print '<div style="margin-bottom:4px;"><span style="font-size:12px;font-weight:600;color:var(--colortextother,#666);margin-right:8px;">'.$langs->trans("InsertVariable").':</span>';
	for ($vi = 1; $vi <= 6; $vi++) {
		print '<span class="wa-var-pill" onclick="waInsertVar('.$vi.')">{{' . $vi . '}}</span> ';
	}
	print '</div>';

	print '<div id="vars_detected" style="display:none;margin-top:6px;">';
	print '<div style="font-size:12px;font-weight:600;margin-bottom:4px;">'.$langs->trans("DetectedVariables").':</div>';
	print '<div id="vars_pills" class="wa-var-pills"></div>';
	print '</div>';
	print '</div></div>'; // card

	/* ---- Card 3b: Variable Configuration ---- */
	$varMapJSON = !empty($val['variable_mapping']) ? $val['variable_mapping'] : '{}';
	$varMapOpen = ($varMapJSON !== '{}') ? ' wa-open' : '';
	print '<div class="wa-card'.$varMapOpen.'" id="card-varconfig">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">🔧</span>
				<div><div class="wa-card-title">'.$langs->trans("VariableConfiguration").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("VariableConfigurationDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div class="wa-help-block" style="margin-bottom:10px;">'.$langs->trans("VariableConfigurationHelp").'</div>';
	print '<div id="varconfig_container"></div>';
	print '<div id="varconfig_empty" style="color:var(--colortextother,#888);font-size:13px;padding:8px 0;">'.$langs->trans("NoVariablesDetected").'</div>';
	print '<input type="hidden" name="variable_mapping" id="variable_mapping_input" value="'.dol_escape_htmltag($varMapJSON).'">';
	print '</div></div>'; // card

	/* ---- Card 4: Footer ---- */
	$ftrOpen = !empty($val['footer_text']) ? ' wa-open' : '';
	print '<div class="wa-card'.$ftrOpen.'">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">📄</span>
				<div><div class="wa-card-title">'.$langs->trans("TemplateFooter").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("TemplateFooterDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div class="wa-fg"><label>'.$langs->trans("TemplateFooter").'</label>';
	print '<input type="text" name="footer_text" id="footer_input" maxlength="60" value="'.dol_escape_htmltag($val['footer_text'] ?: '').'" oninput="waCharCount(this,60,\'ftr_cnt\');waUpdatePreview();" placeholder="'.$langs->trans("TemplateFooterPlaceholder").'">';
	print '<div style="display:flex;justify-content:space-between;"><div class="wa-help-block" style="margin:2px 0 0;">'.$langs->trans("TemplateFooterHelp").'</div><div class="wa-char-count" id="ftr_cnt">0 / 60</div></div>';
	print '</div>';
	print '</div></div>'; // card

	/* ---- Card 5: Buttons ---- */
	$btnOpen = !empty($btnsDecoded) ? ' wa-open' : '';
	print '<div class="wa-card'.$btnOpen.'" id="card-buttons">
		<div class="wa-card-header">
			<div class="wa-card-header-left">
				<span class="wa-card-icon">🔘</span>
				<div><div class="wa-card-title">'.$langs->trans("TemplateButtons").'</div>
					<div class="wa-card-subtitle">'.$langs->trans("TemplateButtonsDesc").'</div></div>
			</div>
			<span class="wa-card-chevron fas fa-chevron-down"></span>
		</div>
		<div class="wa-card-body">';

	print '<div id="wa-buttons-container"></div>';
	print '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">';
	print '<button type="button" class="wa-btn wa-btn-secondary wa-btn-sm" onclick="waAddButton(\'QUICK_REPLY\')">＋ '.$langs->trans("BtnTypeQuickReply").'</button>';
	print '<button type="button" class="wa-btn wa-btn-secondary wa-btn-sm" onclick="waAddButton(\'URL\')">＋ '.$langs->trans("BtnTypeUrl").'</button>';
	print '<button type="button" class="wa-btn wa-btn-secondary wa-btn-sm" onclick="waAddButton(\'PHONE_NUMBER\')">＋ '.$langs->trans("BtnTypePhone").'</button>';
	print '</div>';
	print '<div class="wa-help-block" style="margin-top:10px;">'.$langs->trans("TemplateButtonsHelp").'</div>';
	print '</div></div>'; // card

	/* ---- Submit row ---- */
	print '<div style="margin-top:20px;display:flex;gap:10px;align-items:center;">';
	print '<button type="submit" class="wa-btn wa-btn-primary"><span class="fas fa-save"></span> '.$langs->trans("Save").'</button>';
	print '<a href="'.dol_escape_htmltag($returnUrl).'" class="wa-btn wa-btn-secondary"><span class="fas fa-times"></span> '.$langs->trans("Cancel").'</a>';
	print '</div>';

	print '</div>'; // wa-form-left

	/* ---- Right: Live preview ---- */
	print '<div>';
	print '<div class="wa-card wa-open">';
	print '<div class="wa-card-header"><div class="wa-card-header-left"><span class="wa-card-icon">👁️</span><div><div class="wa-card-title">'.$langs->trans("PreviewTemplate").'</div></div></div></div>';
	print '<div class="wa-card-body" style="padding:0 12px 12px;">';
	print '<div class="wa-preview-phone">';
	print '<div class="wa-preview-phone-bar">';
	print '<div style="width:32px;height:32px;background:#128c7e;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;">💬</div>';
	print '<div><div style="font-size:13px;font-weight:600;">WhatsApp Business</div>';
	print '<div style="font-size:10px;opacity:.7;">'.$langs->trans("PreviewNote").'</div></div>';
	print '</div>'; // bar
	print '<div id="wa-preview-bubble" class="wa-preview-bubble">';
	print '<div id="pv-header"></div>';
	print '<div id="pv-body" class="wa-preview-body"></div>';
	print '<div id="pv-footer" class="wa-preview-footer"></div>';
	print '<div class="wa-preview-time">'.dol_print_date(dol_now(),'%H:%M').'</div>';
	print '</div>';
	print '<div id="pv-buttons" class="wa-preview-buttons"></div>';
	print '</div>'; // phone
	print '</div></div>'; // card-body, card
	print '</div>'; // right col

	print '</div>'; // wa-tpl-layout
	print '</form>';

	/* ---- Inline JS ---- */
	print '<script>
var waButtons = '.json_encode($btnsDecoded, JSON_UNESCAPED_UNICODE).';
var waVarMapping = '.(!empty($val['variable_mapping']) ? $val['variable_mapping'] : '{}').';
var waVarSourceTypes = {
	"contact_name":  "'.dol_escape_js($langs->trans("VarTypeContactName")).'",
	"operator_name": "'.dol_escape_js($langs->trans("VarTypeOperatorName")).'",
	"company_name":  "'.dol_escape_js($langs->trans("VarTypeCompanyName")).'",
	"phone":         "'.dol_escape_js($langs->trans("VarTypePhone")).'",
	"date_today":    "'.dol_escape_js($langs->trans("VarTypeDateToday")).'",
	"free_text":     "'.dol_escape_js($langs->trans("VarTypeFreeText")).'",
	"url":           "'.dol_escape_js($langs->trans("VarTypeUrl")).'",
	"fixed_text":    "'.dol_escape_js($langs->trans("VarTypeFixedText")).'"
};

document.addEventListener("DOMContentLoaded", function() {
	// Card toggle
	document.querySelectorAll(".wa-card-header").forEach(function(h) {
		h.addEventListener("click", function(){ h.closest(".wa-card").classList.toggle("wa-open"); });
	});
	// Init
	waCharCount(document.getElementById("header_content_input"), 60,   "hdr_cnt");
	waCharCount(document.getElementById("footer_input"),          60,   "ftr_cnt");
	waCharCount(document.getElementById("tpl_body"),              1024, "body_cnt");
	waUpdateHeaderUI();
	waDetectVars();
	waRenderButtons();
	waUpdatePreview();
	waUpdateSlug();
	// Init variable config
	waDetectVars();
	// Serialize on submit
	document.getElementById("wa-tpl-form").addEventListener("submit", function(){ waSerializeButtons(); waSerializeVarMapping(); });
	// Sync buttons on any input inside builder 
	document.addEventListener("input", function(e) {
		if (e.target.closest && e.target.closest("#wa-buttons-container")) { waSerializeButtons(); }
	});
});

function waCharCount(el, max, cid) {
	if (!el) return;
	var cEl = document.getElementById(cid); if (!cEl) return;
	var len = el.value.length;
	cEl.textContent = len + " / " + max;
	cEl.className = "wa-char-count" + (len >= max ? " over" : (len > max * 0.85 ? " warn" : ""));
}
function waUpdateSlug() {
	var inp = document.getElementById("tpl_name");
	var prev = document.getElementById("tpl_slug_preview");
	var txt = document.getElementById("tpl_slug_text");
	if (!inp || !prev || !txt) return;
	var v = inp.value.trim();
	if (!v) { prev.style.display = "none"; return; }
	// Transliterate common accented chars
	var map = {"\u00e1":"a","\u00e9":"e","\u00ed":"i","\u00f3":"o","\u00fa":"u","\u00f1":"n","\u00fc":"u","\u00c1":"a","\u00c9":"e","\u00cd":"i","\u00d3":"o","\u00da":"u","\u00d1":"n","\u00e0":"a","\u00e8":"e","\u00ec":"i","\u00f2":"o","\u00f9":"u","\u00e2":"a","\u00ea":"e","\u00ee":"i","\u00f4":"o","\u00fb":"u","\u00e7":"c","\u00e4":"a","\u00f6":"o"};
	var slug = v.toLowerCase().split("").map(function(c){ return map[c] || c; }).join("");
	slug = slug.replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "").replace(/_+/g, "_");
	txt.textContent = slug;
	prev.style.display = "block";
}
function waUpdateHeaderUI() {
	var v = (document.getElementById("header_type_sel") || {value:""}).value;
	var tr = document.getElementById("header_text_row");
	var mr = document.getElementById("header_media_row");
	if (tr) tr.style.display = (v === "TEXT") ? "" : "none";
	if (mr) mr.style.display = (v === "IMAGE" || v === "VIDEO" || v === "DOCUMENT") ? "" : "none";
}
function waInsertVar(n) {
	var ta = document.getElementById("tpl_body"); if (!ta) return;
	var s = ta.selectionStart, e = ta.selectionEnd, ins = "{{"+n+"}}";
	ta.value = ta.value.substring(0,s) + ins + ta.value.substring(e);
	ta.selectionStart = ta.selectionEnd = s + ins.length;
	ta.focus();
	waCharCount(ta, 1024, "body_cnt");
	waDetectVars(); waUpdatePreview();
}
function waDetectVars() {
	var ta = document.getElementById("tpl_body"); if (!ta) return;
	var mx = ta.value.match(/\{\{(\d+)\}\}/g) || [];
	var nums = []; mx.forEach(function(m){ var n=m.replace(/\D/g,""); if(nums.indexOf(n)<0) nums.push(n); });
	nums.sort(function(a,b){return parseInt(a)-parseInt(b);});
	var c = document.getElementById("vars_detected"), p = document.getElementById("vars_pills");
	if (!c||!p) return;
	if (!nums.length) { c.style.display="none"; } else {
		c.style.display="";
		p.innerHTML = nums.map(function(n){ return "<span class=\"wa-var-pill\">{{"+n+"}}</span>"; }).join(" ");
	}
	waUpdateVarConfig(nums);
}
function waUpdatePreview() {
	var body   = (document.getElementById("tpl_body")              || {value:""}).value;
	var htype  = (document.getElementById("header_type_sel")       || {value:""}).value;
	var hcont  = (document.getElementById("header_content_input")  || {value:""}).value;
	var footer = (document.getElementById("footer_input")          || {value:""}).value;
	var pvH = document.getElementById("pv-header");
	if (pvH) {
		if      (htype==="TEXT"     && hcont) pvH.innerHTML = "<div class=\"wa-preview-header-text\">"+waEsc(hcont)+"</div>";
		else if (htype==="IMAGE")             pvH.innerHTML = "<div class=\"wa-preview-header-img\">🖼️</div>";
		else if (htype==="VIDEO")             pvH.innerHTML = "<div class=\"wa-preview-header-img\">🎬</div>";
		else if (htype==="DOCUMENT")          pvH.innerHTML = "<div class=\"wa-preview-header-img\">📄</div>";
		else                                  pvH.innerHTML = "";
	}
	var pvB = document.getElementById("pv-body");
	if (pvB) pvB.textContent = body || "...";
	var pvF = document.getElementById("pv-footer");
	if (pvF) pvF.textContent = footer;
	var pvBtns = document.getElementById("pv-buttons");
	if (pvBtns) {
		pvBtns.innerHTML = waButtons.length === 0 ? "" : waButtons.map(function(b){
			var icon = b.type==="QUICK_REPLY" ? "↩️" : (b.type==="URL" ? "🔗" : "📞");
			return "<div class=\"wa-preview-btn\">"+icon+" "+waEsc(b.text||"...")+  "</div>";
		}).join("");
	}
}
function waEsc(s){ return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;"); }

function waRenderButtons() {
	var c = document.getElementById("wa-buttons-container"); if (!c) return;
	c.innerHTML = "";
	if (!waButtons.length) {
		c.innerHTML = "<div class=\"opacitymedium\" style=\"font-size:12px;padding:6px 0;\">'.dol_escape_js($langs->trans("NoButtonsYet")).'</div>";
	}
	waButtons.forEach(function(btn, idx){ c.appendChild(waBuildBtnRow(btn, idx)); });
	waSerializeButtons();
}
function waBuildBtnRow(btn, idx) {
	var isUrl = btn.type==="URL", isPhone = btn.type==="PHONE_NUMBER", isQR = btn.type==="QUICK_REPLY";
	var lbl   = isQR ? "↩️ Rápida" : (isUrl ? "🔗 URL" : "📞 Teléfono");
	var clr   = isQR ? "background:#dcfce7;color:#166534" : (isUrl ? "background:#dbeafe;color:#1e40af" : "background:#fce7f3;color:#9d174d");
	var cols  = isUrl ? "110px 1fr 1fr 34px" : "110px 1fr 34px";
	var div   = document.createElement("div");
	div.className = "wa-brow";
	div.style.gridTemplateColumns = cols;
	var html  = "<div><span style=\""+clr+";padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;\">"+lbl+"</span></div>";
	html += "<div><input type=\"text\" placeholder=\"'.dol_escape_js($langs->trans("ButtonText")).' (25)\" maxlength=\"25\" value=\""+waEsc(btn.text||"")+"\" oninput=\"waButtons["+idx+"].text=this.value;waUpdatePreview();\"></div>";
	if (isUrl) {
		html += "<div><input type=\"text\" placeholder=\"https://...\" value=\""+waEsc(btn.url||"")+"\" oninput=\"waButtons["+idx+"].url=this.value;\"></div>";
	} else if (isPhone) {
		html += "<div><input type=\"text\" placeholder=\"+573001234567\" value=\""+waEsc(btn.phone_number||"")+"\" oninput=\"waButtons["+idx+"].phone_number=this.value;\"></div>";
	}
	html += "<div><button type=\"button\" style=\"background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:4px;padding:5px 8px;cursor:pointer;\" onclick=\"waRemoveButton("+idx+")\">✕</button></div>";
	div.innerHTML = html;
	return div;
}
function waAddButton(type) {
	if (waButtons.length >= 10) { alert("'.dol_escape_js($langs->trans("MaxButtonsReached")).'"); return; }
	var btn = {type:type, text:""};
	if (type==="URL")          btn.url = "";
	if (type==="PHONE_NUMBER") btn.phone_number = "";
	waButtons.push(btn);
	waRenderButtons(); waUpdatePreview();
	var card = document.getElementById("card-buttons");
	if (card && !card.classList.contains("wa-open")) card.classList.add("wa-open");
}
function waRemoveButton(idx) { waButtons.splice(idx,1); waRenderButtons(); waUpdatePreview(); }
function waSerializeButtons() {
	var f = document.getElementById("buttons_json_field");
	if (f) f.value = JSON.stringify(waButtons);
}
function waUpdateHeaderMediaUI() {
	var mode = (document.getElementById("header_image_mode_sel") || {value:"on_send"}).value;
	var upArea = document.getElementById("header_upload_area");
	var onSendNote = document.getElementById("header_on_send_note");
	var fileOnTemplate = document.getElementById("header_media_file");
	if (upArea) upArea.style.display = (mode === "on_template") ? "" : "none";
	if (onSendNote) onSendNote.style.display = (mode === "on_send") ? "" : "none";
	// Disable the on_template file input when on_send (so only the sample input submits)
	if (fileOnTemplate) fileOnTemplate.disabled = (mode === "on_send");
}
function waUpdateVarConfig(nums) {
	var container = document.getElementById("varconfig_container");
	var emptyMsg = document.getElementById("varconfig_empty");
	if (!container) return;
	if (!nums || !nums.length) {
		container.innerHTML = "";
		if (emptyMsg) emptyMsg.style.display = "";
		// Clean mapping for removed vars
		waVarMapping = {};
		waSerializeVarMapping();
		return;
	}
	if (emptyMsg) emptyMsg.style.display = "none";
	// Remove mapping keys that are no longer in body
	var validKeys = {};
	nums.forEach(function(n){ validKeys[n] = true; });
	Object.keys(waVarMapping).forEach(function(k){ if (!validKeys[k]) delete waVarMapping[k]; });

	container.innerHTML = "";
	nums.forEach(function(n) {
		var cfg = waVarMapping[n] || {type:"free_text", label:"", default_value:""};
		var row = document.createElement("div");
		row.className = "wa-varconfig-row";
		row.style.cssText = "display:grid;grid-template-columns:60px 1fr 1fr 1fr;gap:8px;align-items:center;margin-bottom:8px;padding:10px 12px;background:var(--colorbacklinepair,#f8f9fa);border-radius:6px;border:1px solid var(--colorbordertitle,#eee);";

		var html = "<div style=\"text-align:center;\"><span class=\"wa-var-pill\" style=\"cursor:default;\">{{"+n+"}}</span></div>";

		// Source type dropdown
		html += "<div><select class=\"flat\" data-var=\""+n+"\" data-field=\"type\" onchange=\"waVarConfigChange(this);\" style=\"width:100%;padding:6px 8px;border-radius:4px;font-size:12px;\">";
		Object.keys(waVarSourceTypes).forEach(function(k) {
			html += "<option value=\""+k+"\""+(cfg.type===k?" selected":"")+">"+waVarSourceTypes[k]+"</option>";
		});
		html += "</select></div>";

		// Label
		html += "<div><input type=\"text\" class=\"flat\" data-var=\""+n+"\" data-field=\"label\" placeholder=\"'.dol_escape_js($langs->trans("VarLabel")).'\" value=\""+waEsc(cfg.label||"")+"\" oninput=\"waVarConfigChange(this);\" style=\"width:100%;padding:6px 8px;border-radius:4px;font-size:12px;\"></div>";

		// Default value (only for free_text, url, fixed_text)
		var showDef = (cfg.type==="free_text"||cfg.type==="url"||cfg.type==="fixed_text");
		html += "<div class=\"wa-varconfig-default\" data-var-default=\""+n+"\" style=\""+(showDef?"":"display:none;")+"\"><input type=\"text\" class=\"flat\" data-var=\""+n+"\" data-field=\"default_value\" placeholder=\"'.dol_escape_js($langs->trans("VarDefaultValue")).'\" value=\""+waEsc(cfg.default_value||"")+"\" oninput=\"waVarConfigChange(this);\" style=\"width:100%;padding:6px 8px;border-radius:4px;font-size:12px;\"></div>";

		row.innerHTML = html;
		container.appendChild(row);
	});
	waSerializeVarMapping();

	// Open the card if it has items
	var card = document.getElementById("card-varconfig");
	if (card && nums.length > 0 && !card.classList.contains("wa-open")) card.classList.add("wa-open");
}
function waVarConfigChange(el) {
	var varNum = el.getAttribute("data-var");
	var field = el.getAttribute("data-field");
	if (!waVarMapping[varNum]) waVarMapping[varNum] = {type:"free_text", label:"", default_value:""};
	waVarMapping[varNum][field] = el.value;

	// Show/hide default value field
	if (field === "type") {
		var defEl = document.querySelector("[data-var-default=\""+varNum+"\"]");
		if (defEl) {
			var show = (el.value==="free_text"||el.value==="url"||el.value==="fixed_text");
			defEl.style.display = show ? "" : "none";
		}
	}
	waSerializeVarMapping();
}
function waSerializeVarMapping() {
	var f = document.getElementById("variable_mapping_input");
	if (f) f.value = JSON.stringify(waVarMapping);
}
</script>';

	llxFooter();
	$db->close();
	exit;
}

// =====================
// DELETE CONFIRM
// =====================
if ($action == 'delete' && $id > 0) {
	if ($template->fetch($id) > 0) {
		print $form->formconfirm(
			$_SERVER["PHP_SELF"].'?id='.$id.($line_id > 0 ? '&line_id='.$line_id : ''),
			$langs->trans("ConfirmDeleteTemplate"),
			$langs->trans("ConfirmDeleteTemplate").' "<strong>'.dol_escape_htmltag($template->name).'</strong>"?',
			'confirm_delete', array(), 0, 1
		);
	}
}

// =====================
// LIST VIEW
// =====================

print '<div class="wa-section-title"><span>📱</span><h2>'.$langs->trans("Templates").'</h2></div>';

// Line filter
if (count($activeLines) > 1) {
	print '<div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">';
	print '<label style="font-size:13px;font-weight:600;">'.$langs->trans("WhatsAppLine").':</label>';
	print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" style="margin:0;">';
	print '<select name="line_id" class="flat" onchange="this.form.submit();" style="padding:6px 10px;border-radius:6px;">';
	print '<option value="0">'.$langs->trans("AllLines").'</option>';
	foreach ($activeLines as $lo) {
		$sel = ($line_id == $lo->id) ? ' selected' : '';
		print '<option value="'.$lo->id.'"'.$sel.'>'.dol_escape_htmltag($lo->label).'</option>';
	}
	print '</select></form></div>';
}

// Action buttons
$newUrl  = $_SERVER["PHP_SELF"].'?action=create'.($line_id > 0 ? '&line_id='.$line_id : '');
$syncUrl = $_SERVER["PHP_SELF"].'?action=sync&token='.newToken().($line_id > 0 ? '&line_id='.$line_id : '');
print '<div style="margin-bottom:16px;display:flex;gap:10px;">';
print '<a href="'.dol_escape_htmltag($newUrl).'"  class="wa-btn wa-btn-primary"><span class="fas fa-plus"></span> '.$langs->trans("NewTemplate").'</a>';
print '<a href="'.dol_escape_htmltag($syncUrl).'" class="wa-btn wa-btn-secondary"><span class="fas fa-sync-alt"></span> '.$langs->trans("SyncTemplates").'</a>';
print '</div>';

// Templates table
$templates  = $template->fetchAll('', $line_id);
$lineLabels = array();
foreach ($activeLines as $lo) { $lineLabels[$lo->id] = $lo->label; }

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("TemplateName").'</th>';
print '<th>'.$langs->trans("WhatsAppLine").'</th>';
print '<th>'.$langs->trans("TemplateLanguage").'</th>';
print '<th>'.$langs->trans("TemplateCategory").'</th>';
print '<th>'.$langs->trans("TemplateStatus").'</th>';
print '<th>'.$langs->trans("TemplateBody").'</th>';
print '<th style="width:55px;text-align:center;">'.$langs->trans("TemplateButtons").'</th>';
print '<th style="width:80px;"></th>';
print '</tr>';

if (empty($templates)) {
	print '<tr><td colspan="8" style="padding:30px;text-align:center;">';
	print '<div style="font-size:40px;margin-bottom:8px;">📋</div>';
	print '<div class="opacitymedium">'.$langs->trans("NoRecordFound").'</div>';
	print '<a href="'.dol_escape_htmltag($newUrl).'" class="wa-btn wa-btn-primary" style="margin-top:14px;display:inline-flex;">'.$langs->trans("NewTemplate").'</a>';
	print '</td></tr>';
} else {
	foreach ($templates as $tpl) {
		$btnsA  = !empty($tpl->buttons) ? (@json_decode($tpl->buttons, true) ?: array()) : array();
		$btnsN  = count($btnsA);
		$sClass = 'wa-s-'.($tpl->status ?: 'draft');
		$cClass = 'wa-c-'.strtolower($tpl->category ?: 'utility');
		$editUrl   = $_SERVER["PHP_SELF"].'?action=edit&id='.$tpl->rowid.($line_id > 0 ? '&line_id='.$line_id : '');
		$deleteUrl = $_SERVER["PHP_SELF"].'?action=delete&id='.$tpl->rowid.'&token='.newToken().($line_id > 0 ? '&line_id='.$line_id : '');

		print '<tr class="oddeven">';
		print '<td style="padding:10px 8px;"><strong>'.dol_escape_htmltag($tpl->name).'</strong></td>';
		print '<td>';
		if (!empty($tpl->fk_line) && isset($lineLabels[$tpl->fk_line])) {
			print '<span class="badge badge-status4" style="font-size:11px;">'.dol_escape_htmltag($lineLabels[$tpl->fk_line]).'</span>';
		} else {
			print '<span class="opacitymedium">—</span>';
		}
		print '</td>';
		print '<td><span style="font-family:monospace;background:var(--colorbacklinepair,#f5f5f5);padding:2px 8px;border-radius:4px;font-size:12px;">'.dol_escape_htmltag($tpl->language).'</span></td>';
		print '<td>'.($tpl->category ? '<span class="'.$cClass.'">'.dol_escape_htmltag($langs->trans($tpl->category)).'</span>' : '').'</td>';
		print '<td><span class="'.$sClass.'">'.dol_escape_htmltag($langs->trans($tpl->status ?: 'pending')).'</span></td>';
		print '<td><div class="wa-tpl-body-preview" title="'.dol_escape_htmltag($tpl->body_text).'">'.dol_escape_htmltag(dol_trunc($tpl->body_text, 90)).'</div></td>';
		print '<td style="text-align:center;">'.($btnsN > 0 ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;">'.$btnsN.'</span>' : '<span class="opacitymedium">—</span>').'</td>';
		print '<td style="text-align:right;white-space:nowrap;">';
		if (empty($tpl->template_id)) {
			$pushUrl = $_SERVER["PHP_SELF"].'?action=push_to_meta&id='.$tpl->rowid.'&token='.newToken().($line_id > 0 ? '&line_id='.$line_id : '');
			print '<a href="'.dol_escape_htmltag($pushUrl).'" class="wa-alink" style="background:#fef3c7;color:#92400e;" title="'.$langs->trans("SendToMeta").'"><span class="fas fa-paper-plane"></span></a> ';
		}
		print '<a href="'.dol_escape_htmltag($editUrl).'"   class="wa-alink wa-alink-edit" title="'.$langs->trans("EditTemplate").'"><span class="fas fa-edit"></span></a> ';
		print '<a href="'.dol_escape_htmltag($deleteUrl).'" class="wa-alink wa-alink-del"  title="'.$langs->trans("Delete").'" onclick="return confirm(\''.dol_escape_js($langs->trans("ConfirmDeleteTemplate")).'\');"><span class="fas fa-trash"></span></a>';
		print '</td>';
		print '</tr>';
	}
}

print '</table></div>';

llxFooter();
$db->close();
