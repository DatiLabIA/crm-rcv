<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       conversations.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Conversations page
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
require_once './class/whatsappconversation.class.php';
require_once './class/whatsappmessage.class.php';
require_once './class/whatsappconfig.class.php';

// Ensure connection supports emojis (utf8mb4)
$db->query("SET NAMES utf8mb4");

// Translations
$langs->loadLangs(array("whatsappdati@whatsappdati"));

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$conversation_id = GETPOST('id', 'int');

/*
 * View
 */

$title = $langs->trans("Conversations");
// Cache busting: append filemtime to force reload when files change
$_jsPath = '/custom/whatsappdati/js/whatsappdati.js';
$_cssPath = '/custom/whatsappdati/css/whatsappdati.css';
$_jsPhys = dol_buildpath($_jsPath, 0);
$_cssPhys = dol_buildpath($_cssPath, 0);
$_jsV = @filemtime($_jsPhys);
$_cssV = @filemtime($_cssPhys);
if (!$_jsV) $_jsV = time();
if (!$_cssV) $_cssV = time();
llxHeader('', $title, '', '', 0, 0, array($_jsPath.'?v='.$_jsV), array($_cssPath.'?v='.$_cssV));


print load_fiche_titre($title, '<span class="whatsapp-connection-status status-connecting" id="whatsapp-connection-status">⏳ '.$langs->trans("Connecting").'...</span>', 'whatsappdati@whatsappdati');

// CSRF token for AJAX calls
print '<input type="hidden" name="token" id="csrf-token" value="'.newToken().'">';

// H34: Inject i18n translations for JS
$jsTranslations = array(
	'SelectTemplate' => $langs->trans('JsSelectTemplate'),
	'ErrorPrefix' => $langs->trans('JsErrorPrefix'),
	'NetworkErrorSend' => $langs->trans('JsNetworkErrorSend'),
	'ErrorLoadingTemplate' => $langs->trans('JsErrorLoadingTemplate'),
	'ConnectionError' => $langs->trans('JsConnectionError'),
	'Sending' => $langs->trans('JsSending'),
	'Send' => $langs->trans('JsSend'),
	'NoConversations' => $langs->trans('JsNoConversations'),
	'NoMessages' => $langs->trans('JsNoMessages'),
	'ImagePlaceholder' => $langs->trans('JsImagePlaceholder'),
	'BrowserNoVideo' => $langs->trans('JsBrowserNoVideo'),
	'VideoPlaceholder' => $langs->trans('JsVideoPlaceholder'),
	'BrowserNoAudio' => $langs->trans('JsBrowserNoAudio'),
	'AudioPlaceholder' => $langs->trans('JsAudioPlaceholder'),
	'Document' => $langs->trans('JsDocument'),
	'Download' => $langs->trans('JsDownload'),
	'TemplateVariables' => $langs->trans('JsTemplateVariables'),
	'ValueForVar' => $langs->trans('JsValueForVar'),
	'NoVariables' => $langs->trans('JsNoVariables'),
	'NewMessage' => $langs->trans('JsNewMessage'),
	'StatusRealtime' => $langs->trans('JsStatusRealtime'),
	'StatusConnecting' => $langs->trans('JsStatusConnecting'),
	'StatusReconnecting' => $langs->trans('JsStatusReconnecting'),
	'StatusPolling' => $langs->trans('JsStatusPolling'),
	'StatusDisconnected' => $langs->trans('JsStatusDisconnected'),
	'TooltipSSE' => $langs->trans('JsTooltipSSE'),
	'TooltipPolling' => $langs->trans('JsTooltipPolling'),
	'StatusSent' => $langs->trans('JsStatusSent'),
	'StatusDelivered' => $langs->trans('JsStatusDelivered'),
	'StatusRead' => $langs->trans('JsStatusRead'),
	'StatusFailed' => $langs->trans('JsStatusFailed'),
	'NoTagsCreate' => $langs->trans('JsNoTagsCreate'),
	'UnknownError' => $langs->trans('JsUnknownError'),
	'BulkSync' => $langs->trans('JsBulkSync'),
	'BulkNoVars' => $langs->trans('JsBulkNoVars'),
	'BulkSearching' => $langs->trans('JsBulkSearching'),
	'BulkNoContacts' => $langs->trans('JsBulkNoContacts'),
	'BulkSelected' => $langs->trans('JsBulkSelected'),
	'BulkStartSend' => $langs->trans('JsBulkStartSend'),
	'CrmSearching' => $langs->trans('JsCrmSearching'),
	'CrmNoResults' => $langs->trans('JsCrmNoResults'),
	'CrmViaContact' => $langs->trans('JsCrmViaContact'),
	'CrmNameRequired' => $langs->trans('JsCrmNameRequired'),
	'CrmLeadPrefix' => $langs->trans('JsCrmLeadPrefix'),
	'CrmThirdParty' => $langs->trans('JsCrmThirdParty'),
	'CrmTitleRequired' => $langs->trans('JsCrmTitleRequired'),
	'CrmOpenLead' => $langs->trans('JsCrmOpenLead'),
	'ErrorLoadConversations' => $langs->trans('JsErrorLoadConversations'),
	'ErrorLoadMessages' => $langs->trans('JsErrorLoadMessages'),
	'Retry' => $langs->trans('JsRetry'),
	'AllLines' => $langs->trans('AllLines'),
	'Line' => $langs->trans('Line'),
	'VoiceMessage' => $langs->trans('JsVoiceMessage'),
	'Recording' => $langs->trans('JsRecording'),
	'MicNotAllowed' => $langs->trans('JsMicNotAllowed'),
	'MicNotSupported' => $langs->trans('JsMicNotSupported'),
	'VoiceRecording' => $langs->trans('JsVoiceRecording'),
	'VoiceTooShort' => $langs->trans('JsVoiceTooShort'),
	'SendingVoice' => $langs->trans('JsSendingVoice'),
	'NewConversation' => $langs->trans('NewConversation'),
	'SearchContactOrPhone' => $langs->trans('SearchContactOrPhone'),
	'OrEnterPhone' => $langs->trans('OrEnterPhone'),
	'PhoneNumber' => $langs->trans('PhoneNumber'),
	'ContactName' => $langs->trans('ContactName'),
	'Optional' => $langs->trans('Optional'),
	'StartConversation' => $langs->trans('StartConversation'),
	'SelectRecipientOrPhone' => $langs->trans('SelectRecipientOrPhone'),
	'MessageSent' => $langs->trans('MessageSent'),
	'MessageFailed' => $langs->trans('MessageFailed'),
	'NoResultsFound' => $langs->trans('NoResultsFound'),
	'Searching' => $langs->trans('Searching'),
	'VarTypeContactName' => $langs->trans('VarTypeContactName'),
	'VarTypeOperatorName' => $langs->trans('VarTypeOperatorName'),
	'VarTypeCompanyName' => $langs->trans('VarTypeCompanyName'),
	'VarTypePhone' => $langs->trans('VarTypePhone'),
	'VarTypeDateToday' => $langs->trans('VarTypeDateToday'),
	'VarTypeFreeText' => $langs->trans('VarTypeFreeText'),
	'VarTypeUrl' => $langs->trans('VarTypeUrl'),
	'VarTypeFixedText' => $langs->trans('VarTypeFixedText'),
	'AutoResolved' => $langs->trans('AutoResolved'),
	'Auto' => $langs->trans('Auto'),
	'HeaderImage' => $langs->trans('HeaderImage'),
	'HeaderImageOnSendHelp' => $langs->trans('HeaderImageOnSendHelp'),
	'NoTemplatesAvailable' => $langs->trans('JsNoTemplatesAvailable'),
	'DateToday' => $langs->trans('Today'),
	'DateYesterday' => $langs->trans('Yesterday'),
	'LoadMoreMessages' => $langs->trans('LoadMoreMessages'),
	'ClaimConversation' => $langs->trans('ClaimConversation'),
	'ClaimConversationConfirm' => $langs->trans('ClaimConversationConfirm'),
	'ConversationClaimed' => $langs->trans('ConversationClaimed'),
);
print '<script>var WhatsAppLang = '.json_encode($jsTranslations).';</script>'."\n";
// L5: Inject AJAX base URL so JS doesn't rely on relative paths
print '<script>var WhatsAppAjaxBase = "'.dol_escape_htmltag(dol_buildpath('/custom/whatsappdati/', 1)).'";</script>'."\n";
// Pass realtime mode to JS so it knows whether to attempt SSE
print '<script>var WhatsAppRealtimeMode = "'.dol_escape_htmltag(getDolGlobalString('WHATSAPPDATI_REALTIME_MODE', 'polling')).'";</script>'."\n";

// Multi-line: Inject available lines for JS line selector
$configObj = new WhatsAppConfig($db);
$allLines = $configObj->fetchActiveLines();
$linesData = array();
foreach ($allLines as $lineObj) {
	$linesData[] = array('id' => (int) $lineObj->id, 'label' => $lineObj->label);
}
print '<script>var WhatsAppLines = '.json_encode($linesData).';</script>'."\n";
print '<script>var WhatsAppCurrentUserId = '.(int) $user->id.';</script>'."\n";
print '<script>var WhatsAppCurrentUserName = '.json_encode(trim($user->firstname.' '.$user->lastname) ?: $user->login, JSON_UNESCAPED_UNICODE).';</script>'."\n";

print '<div class="whatsapp-chat-container" id="whatsapp-chat-container">';

// Left side - Conversations list
print '<div class="whatsapp-sidebar" id="whatsapp-sidebar">';

// Line filter (multi-line support)
print '<div class="whatsapp-line-filter" id="whatsapp-line-filter">';
print '<select class="flat whatsapp-line-filter-select" id="whatsapp-line-filter-select">';
print '<option value="0">'.$langs->trans("AllLines").'</option>';
print '</select>';
print '<button type="button" class="whatsapp-new-conv-btn" id="whatsapp-new-conv-btn" title="'.$langs->trans("NewConversation").'">+ '.$langs->trans("NewConversation").'</button>';
print '</div>';

// Tag filter bar + Mine filter toggle on the same row
print '<div class="whatsapp-filter-row">';
print '<div class="whatsapp-tag-filter" id="whatsapp-tag-filter">';
print '<select class="flat whatsapp-tag-filter-select" id="whatsapp-tag-filter-select">';
print '<option value="0">'.$langs->trans("AllConversations").'</option>';
print '</select>';
print '</div>';
print '<button type="button" class="whatsapp-mine-filter-btn" id="whatsapp-filter-mine-btn" title="'.$langs->trans("ShowOnlyMine").'">&#128100; '.$langs->trans("Mine").'</button>';
print '<button type="button" class="whatsapp-mine-filter-btn" id="whatsapp-filter-unread-btn" title="'.$langs->trans("ShowOnlyUnread").'">&#128172; '.$langs->trans("Unread").'</button>';
print '</div>';

// Search bar
print '<div class="whatsapp-search-bar">';
print '<div class="whatsapp-search-wrapper">';
print '<span class="whatsapp-search-icon">&#128269;</span>';
print '<input type="text" class="whatsapp-search-input" id="whatsapp-search-input" placeholder="'.$langs->trans("SearchConversations").'" autocomplete="off">';
print '</div>';
print '</div>';

print '<div class="whatsapp-conversations-list" id="whatsapp-conversations-list"></div>';
print '</div>';

// Right side - Chat area
print '<div class="whatsapp-chat-area">';

// Chat header
print '<div class="whatsapp-chat-header" id="whatsapp-chat-header">';
print '<div class="whatsapp-chat-header-left">';
print '<button type="button" class="whatsapp-mobile-back-btn" id="whatsapp-mobile-back-btn" title="'.$langs->trans("Back").'">&#8592;</button>';
print '<div class="whatsapp-chat-title" id="whatsapp-chat-title">'.$langs->trans("SelectConversation").'</div>';
print '<div class="whatsapp-chat-subtitle" id="whatsapp-chat-subtitle"></div>';
print '</div>';
print '<div class="whatsapp-chat-header-right" id="whatsapp-chat-header-actions" style="display:none;">';
print '<div class="whatsapp-assign-area" style="position:relative;">';
print '<label class="whatsapp-assign-label">'.img_picto('', 'user').' '.$langs->trans("AssignedTo").':</label>';
print '<div id="whatsapp-assigned-agents"></div>';
print '<select class="flat" id="whatsapp-assign-select" style="display:none;">';
print '<option value="0">'.$langs->trans("Unassigned").'</option>';
print '</select>';
print '<button type="button" class="whatsapp-assign-to-me-btn" id="btn-assign-to-me" title="'.$langs->trans("AssignToMe").'">&#128100;+</button>';
print '<button type="button" id="btn-multi-agent-picker" title="'.$langs->trans("ManageAgents").'">&#128101;</button>';
print '<div id="whatsapp-multi-agent-dropdown"></div>';
print '</div>';
print '<button type="button" class="whatsapp-claim-btn" id="whatsapp-claim-btn" style="display:none;" title="'.$langs->trans("ClaimConversation").'">&#x1F4E5; '.$langs->trans("ClaimConversation").'</button>';
print '<div class="whatsapp-tags-area" id="whatsapp-tags-area">';
print '<label class="whatsapp-assign-label">🏷️ '.$langs->trans("Tags").':</label>';
print '<div class="whatsapp-tags-list" id="whatsapp-conversation-tags"></div>';
print '<button class="whatsapp-tag-add-btn" id="whatsapp-tag-add-btn" title="'.$langs->trans("AddTag").'">+</button>';
print '</div>';
// CRM area
print '<div class="whatsapp-crm-area" id="whatsapp-crm-area">';
print '<div class="whatsapp-crm-linked" id="whatsapp-crm-linked" style="display:none;">';
print '<span class="whatsapp-crm-badge" id="whatsapp-crm-badge"></span>';
print '<a href="javascript:void(0)" class="whatsapp-crm-unlink" id="whatsapp-crm-unlink" title="'.$langs->trans("CrmUnlink").'">&times;</a>';
print '</div>';
print '<div class="whatsapp-crm-actions" id="whatsapp-crm-actions">';
print '<a href="javascript:void(0)" class="whatsapp-crm-btn" id="btn-crm-link" title="'.$langs->trans("CrmLinkThirdParty").'">🔗 '.$langs->trans("CrmLink").'</a>';
print '<a href="javascript:void(0)" class="whatsapp-crm-btn whatsapp-crm-btn-lead" id="btn-crm-lead" title="'.$langs->trans("CrmCreateLead").'" style="display:none;">📊 '.$langs->trans("CrmLead").'</a>';
print '</div>';
print '</div>';
// Transfer & Close area
print '<div class="whatsapp-conv-actions-area" id="whatsapp-conv-actions-area">';
print '<button type="button" class="whatsapp-action-btn whatsapp-transfer-btn" id="btn-transfer-conversation" title="'.$langs->trans("TransferConversation").'">🔄 '.$langs->trans("Transfer").'</button>';
print '<button type="button" class="whatsapp-action-btn whatsapp-close-btn" id="btn-close-conversation" title="'.$langs->trans("CloseConversation").'">🔒 '.$langs->trans("CloseConv").'</button>';
print '<span class="whatsapp-csat-info" id="whatsapp-csat-info" style="display:none;"></span>';
print '</div>';
print '</div>';
print '</div>';

// Messages area
print '<div class="whatsapp-messages-area" id="whatsapp-messages-area">';
print '<div class="whatsapp-empty-state">';
print '<div class="whatsapp-empty-state-icon">💬</div>';
print '<div class="whatsapp-empty-state-text">'.$langs->trans("SelectConversation").'</div>';
print '</div>';
print '</div>';

// Window expired warning (hidden by default)
print '<div class="whatsapp-window-expired" id="whatsapp-window-warning" style="display:none;">';
print img_warning().' '.$langs->trans("MessageWindowExpired");
print '</div>';

// Input area
print '<div class="whatsapp-input-area" id="whatsapp-input-area" style="display:none;">';
print '<input type="file" id="whatsapp-file-input" style="display:none;" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" />';
print '<button class="whatsapp-attach-button" id="whatsapp-attach-btn" title="'.$langs->trans("AttachFile").'">📎</button>';
print '<button type="button" class="whatsapp-emoji-button" id="whatsapp-emoji-btn" title="'.$langs->trans("InsertEmoji").'">😊</button>';
print '<button class="whatsapp-quick-reply-button" id="whatsapp-quick-reply-btn" title="'.$langs->trans("QuickReplies").'">⚡</button>';
print '<button type="button" class="whatsapp-template-inline-button" id="whatsapp-tpl-picker-btn" title="'.$langs->trans("SendWithTemplate").'"><span class="fas fa-file-alt"></span></button>';
print '<input type="text" class="whatsapp-input-field" id="whatsapp-message-input" placeholder="'.$langs->trans("TypeYourMessage").'" />';
print '<button type="button" class="whatsapp-voice-button" id="whatsapp-voice-btn" title="'.$langs->trans("VoiceMessage").'">🎙️</button>';
print '<button class="whatsapp-send-button" id="whatsapp-send-btn">'.$langs->trans("Send").'</button>';
print '</div>';

// Voice recording overlay (hidden by default)
print '<div class="whatsapp-voice-recording" id="whatsapp-voice-recording" style="display:none;">';
print '<button type="button" class="whatsapp-voice-cancel" id="whatsapp-voice-cancel" title="'.$langs->trans("Cancel").'">&times;</button>';
print '<div class="whatsapp-voice-indicator">';
print '<span class="whatsapp-voice-dot"></span>';
print '<span class="whatsapp-voice-label" id="whatsapp-voice-timer">0:00</span>';
print '</div>';
print '<button type="button" class="whatsapp-voice-send" id="whatsapp-voice-send" title="'.$langs->trans("Send").'">'.$langs->trans("Send").'</button>';
print '</div>';

// File preview area (hidden by default)
print '<div class="whatsapp-file-preview" id="whatsapp-file-preview" style="display:none;">';
print '<div class="whatsapp-file-preview-content">';
print '<div class="whatsapp-file-preview-icon" id="whatsapp-file-preview-icon"></div>';
print '<div class="whatsapp-file-preview-info">';
print '<span class="whatsapp-file-preview-name" id="whatsapp-file-preview-name"></span>';
print '<span class="whatsapp-file-preview-size" id="whatsapp-file-preview-size"></span>';
print '</div>';
print '<button class="whatsapp-file-preview-remove" id="whatsapp-file-remove-btn">&times;</button>';
print '</div>';
print '<div class="whatsapp-file-caption-area">';
print '<input type="text" class="whatsapp-input-field" id="whatsapp-caption-input" placeholder="'.$langs->trans("AddCaption").'" />';
print '<button class="whatsapp-send-button" id="whatsapp-send-file-btn">'.$langs->trans("Send").'</button>';
print '</div>';
print '</div>';

// Template selector (hidden by default)
print '<div class="whatsapp-template-selector" id="whatsapp-template-selector" style="display:none;">';
print '<select class="whatsapp-template-select" id="whatsapp-template-select">';
print '<option value="">'.$langs->trans("SelectTemplate").'</option>';
print '</select>';
print '<button class="whatsapp-send-button" id="whatsapp-send-template-btn">'.$langs->trans("SendWithTemplate").'</button>';
print '</div>';

// Inline template picker (floating, shown on 📋 click)
print '<div class="whatsapp-tpl-picker" id="whatsapp-tpl-picker" style="display:none;">';
print '<div class="whatsapp-tpl-picker-header">'.$langs->trans("SelectTemplate").'</div>';
print '<div class="whatsapp-tpl-picker-list" id="whatsapp-tpl-picker-list"></div>';
print '</div>';

print '</div>'; // End chat area

print '</div>'; // End container

// Tag Picker Dropdown (floating, shown on + click)
print '<div class="whatsapp-tag-picker" id="whatsapp-tag-picker" style="display:none;">';
print '<div class="whatsapp-tag-picker-header">';
print '<input type="text" class="whatsapp-tag-picker-search" id="whatsapp-tag-picker-search" placeholder="'.$langs->trans("SearchOrCreateTag").'" />';
print '</div>';
print '<div class="whatsapp-tag-picker-list" id="whatsapp-tag-picker-list"></div>';
print '<div class="whatsapp-tag-picker-create" id="whatsapp-tag-picker-create" style="display:none;">';
print '<div class="whatsapp-tag-picker-colors" id="whatsapp-tag-picker-colors"></div>';
print '<button class="whatsapp-tag-picker-create-btn" id="whatsapp-tag-picker-create-btn">'.$langs->trans("CreateTag").'</button>';
print '</div>';
print '</div>';

// Emoji Picker (floating, shown on 😊 click)
print '<div class="whatsapp-emoji-picker" id="whatsapp-emoji-picker" style="display:none;">';
print '<div class="whatsapp-emoji-picker-header">';
print '<div class="whatsapp-emoji-categories" id="whatsapp-emoji-categories"></div>';
print '<input type="text" class="whatsapp-emoji-search" id="whatsapp-emoji-search" placeholder="'.$langs->trans("SearchEmoji").'" autocomplete="off" />';
print '</div>';
print '<div class="whatsapp-emoji-grid" id="whatsapp-emoji-grid"></div>';
print '</div>';

// Quick Reply Picker (floating, shown on ⚡ click or / shortcut)
print '<div class="whatsapp-qr-picker" id="whatsapp-qr-picker" style="display:none;">';
print '<div class="whatsapp-qr-picker-header">';
print '<input type="text" class="whatsapp-qr-picker-search" id="whatsapp-qr-picker-search" placeholder="'.$langs->trans("SearchQuickReplies").'" />';
print '</div>';
print '<div class="whatsapp-qr-picker-list" id="whatsapp-qr-picker-list"></div>';
print '<div class="whatsapp-qr-picker-empty" id="whatsapp-qr-picker-empty" style="display:none;">'.$langs->trans("NoQuickRepliesYet").'</div>';
print '</div>';
print '<div class="whatsapp-modal-overlay" id="whatsapp-template-modal" style="display:none;">';
print '<div class="whatsapp-modal">';
print '<div class="whatsapp-modal-header">';
print '<h3 id="whatsapp-modal-title">'.$langs->trans("SendWithTemplate").'</h3>';
print '<button class="whatsapp-modal-close" id="whatsapp-modal-close">&times;</button>';
print '</div>';
print '<div class="whatsapp-modal-body">';
// Error area (hidden by default, shown when send fails)
print '<div id="whatsapp-modal-error" style="display:none;margin-bottom:10px;padding:8px 10px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:4px;font-size:12px;">';
print '<strong id="whatsapp-modal-error-msg"></strong>';
print '<pre id="whatsapp-modal-error-debug" style="margin:6px 0 0;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:11px;background:#fff3f3;padding:6px;border-radius:3px;display:none;"></pre>';
print '</div>';
// Preview area
print '<div class="whatsapp-template-preview" id="whatsapp-template-preview">';
print '<div class="whatsapp-template-preview-header" id="whatsapp-preview-header"></div>';
print '<div class="whatsapp-template-preview-body" id="whatsapp-preview-body"></div>';
print '<div class="whatsapp-template-preview-footer" id="whatsapp-preview-footer"></div>';
print '</div>';
// Variable fields container
print '<div class="whatsapp-template-variables" id="whatsapp-template-variables"></div>';
print '</div>';
print '<div class="whatsapp-modal-footer">';
print '<button class="whatsapp-send-button" id="whatsapp-modal-send">'.$langs->trans("Send").'</button>';
print '<button class="whatsapp-modal-cancel" id="whatsapp-modal-cancel">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</div>';
print '</div>';

// CRM Modal: Search & Link Third Party
print '<div class="whatsapp-crm-modal" id="crm-link-modal" style="display:none;">';
print '<div class="whatsapp-crm-modal-content">';
print '<div class="whatsapp-crm-modal-header">';
print '<h3>'.$langs->trans("CrmLinkThirdParty").'</h3>';
print '<span class="whatsapp-crm-modal-close crm-modal-close">&times;</span>';
print '</div>';
print '<div class="whatsapp-crm-modal-body">';
print '<div class="crm-search-box">';
print '<input type="text" id="crm-search-input" class="flat" style="width:100%;" placeholder="'.$langs->trans("CrmSearchPlaceholder").'" />';
print '<small class="crm-search-help">'.$langs->trans("CrmSearchHelp").'</small>';
print '</div>';
print '<div class="crm-search-results" id="crm-search-results"></div>';
print '<hr>';
print '<h4>'.$langs->trans("CrmCreateNewThirdParty").'</h4>';
print '<div class="crm-create-form">';
print '<div class="crm-form-group"><label>'.$langs->trans("ThirdPartyName").' <span class="required">*</span></label><input type="text" id="crm-new-name" class="flat" style="width:100%;" /></div>';
print '<div class="crm-form-row">';
print '<div class="crm-form-group crm-form-inline"><label>'.$langs->trans("Phone").'</label><input type="text" id="crm-new-phone" class="flat" style="width:100%;" /></div>';
print '<div class="crm-form-group crm-form-inline"><label>'.$langs->trans("Email").'</label><input type="text" id="crm-new-email" class="flat" style="width:100%;" /></div>';
print '</div>';
print '<div class="crm-form-group"><label>'.$langs->trans("CrmClientType").'</label>';
print '<select id="crm-new-client-type" class="flat">';
print '<option value="2">'.$langs->trans("Prospect").'</option>';
print '<option value="1">'.$langs->trans("Customer").'</option>';
print '<option value="3">'.$langs->trans("ProspectCustomer").'</option>';
print '<option value="0">'.$langs->trans("Other").'</option>';
print '</select></div>';
print '<button type="button" class="button" id="btn-crm-create-soc">'.$langs->trans("CrmCreateAndLink").'</button>';
print '</div>';
print '</div>';
print '</div>';
print '</div>';

// CRM Modal: Create Lead / Opportunity
print '<div class="whatsapp-crm-modal" id="crm-lead-modal" style="display:none;">';
print '<div class="whatsapp-crm-modal-content">';
print '<div class="whatsapp-crm-modal-header whatsapp-crm-modal-header-lead">';
print '<h3>'.$langs->trans("CrmCreateLead").'</h3>';
print '<span class="whatsapp-crm-modal-close crm-modal-close">&times;</span>';
print '</div>';
print '<div class="whatsapp-crm-modal-body">';
print '<div class="crm-form-group"><label>'.$langs->trans("CrmLeadTitle").' <span class="required">*</span></label><input type="text" id="crm-lead-title" class="flat" style="width:100%;" /></div>';
print '<div class="crm-form-group"><label>'.$langs->trans("Description").'</label><textarea id="crm-lead-description" class="flat" rows="3" style="width:100%;"></textarea></div>';
print '<div class="crm-form-row">';
print '<div class="crm-form-group crm-form-inline"><label>'.$langs->trans("CrmOppAmount").'</label><input type="number" id="crm-lead-amount" class="flat" style="width:100%;" step="0.01" min="0" value="0" /></div>';
print '<div class="crm-form-group crm-form-inline"><label>'.$langs->trans("CrmOppPercent").'</label><input type="number" id="crm-lead-percent" class="flat" style="width:100%;" min="0" max="100" value="10" /></div>';
print '</div>';
print '<div class="crm-form-group"><span class="crm-lead-thirdparty-info" id="crm-lead-soc-info"></span></div>';
print '</div>';
print '<div class="whatsapp-crm-modal-footer">';
print '<button type="button" class="button" id="btn-crm-create-lead">'.$langs->trans("CrmCreateLead").'</button>';
print '<button type="button" class="button whatsapp-button-cancel crm-modal-close">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</div>';
print '</div>';

// Transfer Conversation Modal
print '<div class="whatsapp-modal-overlay" id="whatsapp-transfer-modal" style="display:none;">';
print '<div class="whatsapp-modal whatsapp-transfer-modal-dialog">';
print '<div class="whatsapp-modal-header">';
print '<h3>🔄 '.$langs->trans("TransferConversation").'</h3>';
print '<button class="whatsapp-modal-close whatsapp-transfer-modal-close">&times;</button>';
print '</div>';
print '<div class="whatsapp-modal-body">';
print '<div class="whatsapp-transfer-form">';
print '<div class="whatsapp-form-group">';
print '<label>'.$langs->trans("TransferTo").'</label>';
print '<select class="flat whatsapp-transfer-select" id="whatsapp-transfer-agent-select">';
print '<option value="">'.$langs->trans("SelectAgent").'</option>';
print '</select>';
print '</div>';
print '<div class="whatsapp-form-group">';
print '<label>'.$langs->trans("TransferNote").'</label>';
print '<textarea class="flat whatsapp-transfer-note" id="whatsapp-transfer-note" rows="3" placeholder="'.$langs->trans("TransferNotePlaceholder").'"></textarea>';
print '</div>';
print '<div class="whatsapp-transfer-history" id="whatsapp-transfer-history" style="display:none;">';
print '<h4>'.$langs->trans("TransferHistory").'</h4>';
print '<div class="whatsapp-transfer-history-list" id="whatsapp-transfer-history-list"></div>';
print '</div>';
print '</div>';
print '</div>';
print '<div class="whatsapp-modal-footer">';
print '<button class="whatsapp-send-button" id="whatsapp-transfer-submit">'.$langs->trans("TransferConversation").'</button>';
print '<button class="whatsapp-modal-cancel whatsapp-transfer-modal-close">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</div>';
print '</div>';

// Close Conversation Confirmation Modal
print '<div class="whatsapp-modal-overlay" id="whatsapp-close-modal" style="display:none;">';
print '<div class="whatsapp-modal whatsapp-close-modal-dialog">';
print '<div class="whatsapp-modal-header">';
print '<h3>🔒 '.$langs->trans("CloseConversation").'</h3>';
print '<button class="whatsapp-modal-close whatsapp-close-modal-close">&times;</button>';
print '</div>';
print '<div class="whatsapp-modal-body">';
print '<p>'.$langs->trans("ConfirmCloseConversation").'</p>';
print '<div class="whatsapp-form-group" id="whatsapp-close-csat-option">';
print '<label>';
print '<input type="checkbox" id="whatsapp-close-send-csat" checked /> ';
print $langs->trans("SendCSATSurveyOnClose");
print '</label>';
print '</div>';
print '</div>';
print '<div class="whatsapp-modal-footer">';
print '<button class="whatsapp-send-button" id="whatsapp-close-submit">'.$langs->trans("CloseConversation").'</button>';
print '<button class="whatsapp-modal-cancel whatsapp-close-modal-close">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</div>';
print '</div>';

// New Conversation Modal
print '<div class="whatsapp-modal-overlay" id="whatsapp-newconv-modal" style="display:none;">';
print '<div class="whatsapp-modal whatsapp-newconv-modal-dialog">';
print '<div class="whatsapp-modal-header">';
print '<h3>💬 '.$langs->trans("NewConversation").'</h3>';
print '<button class="whatsapp-modal-close whatsapp-newconv-modal-close">&times;</button>';
print '</div>';
print '<div class="whatsapp-modal-body">';
// Recipient search
print '<div class="whatsapp-form-group">';
print '<label>'.$langs->trans("SearchRecipient").'</label>';
print '<input type="text" class="flat whatsapp-newconv-search" id="whatsapp-newconv-search" placeholder="'.$langs->trans("SearchContactOrPhone").'" autocomplete="off" style="width:100%;" />';
print '<div class="whatsapp-newconv-results" id="whatsapp-newconv-results" style="display:none;"></div>';
print '</div>';
// Selected recipient display
print '<div class="whatsapp-newconv-selected" id="whatsapp-newconv-selected" style="display:none;">';
print '<div class="whatsapp-newconv-selected-info">';
print '<strong id="whatsapp-newconv-sel-name"></strong>';
print '<span id="whatsapp-newconv-sel-phone"></span>';
print '</div>';
print '<button type="button" class="whatsapp-newconv-sel-remove" id="whatsapp-newconv-sel-remove">&times;</button>';
print '</div>';
// Manual phone entry
print '<div class="whatsapp-form-group" id="whatsapp-newconv-manual-group">';
print '<label>'.$langs->trans("OrEnterPhone").'</label>';
print '<input type="text" class="flat" id="whatsapp-newconv-phone" placeholder="'.$langs->trans("PhoneNumber").'" style="width:100%;" />';
print '<input type="text" class="flat" id="whatsapp-newconv-name" placeholder="'.$langs->trans("ContactName").' ('.$langs->trans("Optional").')" style="width:100%; margin-top:6px;" />';
print '</div>';
// Line selector
if (count($allLines) > 1) {
	print '<div class="whatsapp-form-group">';
	print '<label>'.$langs->trans("SelectLine").'</label>';
	print '<select class="flat" id="whatsapp-newconv-line" style="width:100%;">';
	foreach ($allLines as $lineObj) {
		print '<option value="'.(int)$lineObj->id.'">'.dol_escape_htmltag($lineObj->label ?: $lineObj->phone_number_id).'</option>';
	}
	print '</select>';
	print '</div>';
} else {
	print '<input type="hidden" id="whatsapp-newconv-line" value="'.(int)$allLines[0]->id.'">';
}
// Template selector
print '<div class="whatsapp-form-group">';
print '<label>'.$langs->trans("SelectTemplate").'</label>';
print '<select class="flat" id="whatsapp-newconv-template" style="width:100%;">';
print '<option value="">'.$langs->trans("SelectTemplate").'</option>';
print '</select>';
print '</div>';
// Template preview area
print '<div id="whatsapp-newconv-tpl-preview" style="display:none;">';
print '<div class="whatsapp-hook-preview">';
print '<div class="whatsapp-hook-preview-body" id="whatsapp-newconv-tpl-body"></div>';
print '</div>';
print '<div id="whatsapp-newconv-tpl-vars"></div>';
print '</div>';
// Status
print '<div id="whatsapp-newconv-status" style="display:none; margin-top:8px; padding:8px 12px; border-radius:4px;"></div>';
print '</div>';
print '<div class="whatsapp-modal-footer">';
print '<button class="whatsapp-send-button" id="whatsapp-newconv-send" disabled>'.$langs->trans("StartConversation").'</button>';
print '<button class="whatsapp-modal-cancel whatsapp-newconv-modal-close">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</div>';
print '</div>';

// End of page
llxFooter();
$db->close();
