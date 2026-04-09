<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       chatbot.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Chatbot rules management page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once './class/whatsappchatbot.class.php';
require_once './class/whatsapptemplate.class.php';

// Translations
$langs->loadLangs(array("whatsappdati@whatsappdati"));

// Access control
if (!$user->admin && empty($user->rights->whatsappdati->config->write)) {
	accessforbidden();
}

$form = new Form($db);

// Load chatbot global enable setting
$chatbotEnabled = !empty($conf->global->WHATSAPPDATI_CHATBOT_ENABLED);

// Handle toggle action (server-side)
$action = GETPOST('action', 'aZ09');

if ($action === 'toggle_chatbot') {
	$newVal = $chatbotEnabled ? '0' : '1';
	dolibarr_set_const($db, 'WHATSAPPDATI_CHATBOT_ENABLED', $newVal, 'chaine', 0, 'Enable chatbot auto-replies', $conf->entity);
	$chatbotEnabled = ($newVal === '1');
	setEventMessages($langs->trans($chatbotEnabled ? 'ChatbotEnabled' : 'ChatbotDisabled'), null, 'mesgs');
}

// Load templates for template selector
$templateObj = new WhatsAppTemplate($db);
$templates = $templateObj->fetchAll('approved');
$templateOptions = array();
if (is_array($templates)) {
	foreach ($templates as $t) {
		$templateOptions[$t->name] = $t->name . ' (' . $t->language . ')';
	}
}

/*
 * View
 */

$title = $langs->trans('ChatbotTitle');
llxHeader('', $title, '', '', 0, 0, '', array('/custom/whatsappdati/css/whatsappdati.css'));

print load_fiche_titre($title, '', 'whatsappdati@whatsappdati');

// Global enable/disable toggle
print '<div class="chatbot-global-toggle">';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="toggle_chatbot">';
$statusClass = $chatbotEnabled ? 'badge-status4' : 'badge-status8';
$statusLabel = $chatbotEnabled ? $langs->trans('Enabled') : $langs->trans('Disabled');
print '<span class="badge ' . $statusClass . '">' . $statusLabel . '</span> ';
print '<input type="submit" class="button' . ($chatbotEnabled ? ' button-warning' : ' button-save') . '" value="' . ($chatbotEnabled ? $langs->trans('DisableChatbot') : $langs->trans('EnableChatbot')) . '">';
print '</form>';
print '</div>';

print '<br>';

// Toolbar
print '<div class="chatbot-toolbar">';
print '<a href="javascript:void(0)" class="btn-chatbot-add butAction">' . $langs->trans('ChatbotAddRule') . '</a>';
print '<a href="javascript:void(0)" class="btn-chatbot-test butActionDefault" style="margin-left:10px;">' . $langs->trans('ChatbotTestMessage') . '</a>';
print '</div>';

print '<br>';

// Rules table
print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="chatbot-rules-table">';
print '<thead>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans('ChatbotRuleName') . '</th>';
print '<th>' . $langs->trans('ChatbotTriggerType') . '</th>';
print '<th>' . $langs->trans('ChatbotTriggerValue') . '</th>';
print '<th>' . $langs->trans('ChatbotResponseType') . '</th>';
print '<th>' . $langs->trans('ChatbotResponse') . '</th>';
print '<th>' . $langs->trans('ChatbotCondition') . '</th>';
print '<th class="center">' . $langs->trans('Priority') . '</th>';
print '<th class="center">' . $langs->trans('Status') . '</th>';
print '<th class="center">' . $langs->trans('ChatbotStats') . '</th>';
print '<th class="center">' . $langs->trans('Actions') . '</th>';
print '</tr>';
print '</thead>';
print '<tbody id="chatbot-rules-body">';
print '<tr><td colspan="10" class="center">' . $langs->trans('Loading') . '...</td></tr>';
print '</tbody>';
print '</table>';
print '</div>';

// ======================================
// Modal: Add/Edit Rule
// ======================================
print '<!-- Chatbot Rule Modal -->';
print '<div id="chatbot-rule-modal" class="chatbot-modal" style="display:none;">';
print '<div class="chatbot-modal-content">';
print '<div class="chatbot-modal-header">';
print '<h3 id="chatbot-modal-title">' . $langs->trans('ChatbotAddRule') . '</h3>';
print '<span class="chatbot-modal-close">&times;</span>';
print '</div>';
print '<div class="chatbot-modal-body">';
print '<form id="chatbot-rule-form">';
print '<input type="hidden" id="chatbot-rule-id" value="">';

// Name
print '<div class="chatbot-form-group">';
print '<label for="chatbot-rule-name">' . $langs->trans('ChatbotRuleName') . ' <span class="required">*</span></label>';
print '<input type="text" id="chatbot-rule-name" class="flat minwidth300" maxlength="255" required>';
print '</div>';

// Trigger type
print '<div class="chatbot-form-group">';
print '<label for="chatbot-trigger-type">' . $langs->trans('ChatbotTriggerType') . '</label>';
print '<select id="chatbot-trigger-type" class="flat minwidth200">';
print '<option value="contains">' . $langs->trans('ChatbotTriggerContains') . '</option>';
print '<option value="exact">' . $langs->trans('ChatbotTriggerExact') . '</option>';
print '<option value="starts_with">' . $langs->trans('ChatbotTriggerStartsWith') . '</option>';
print '<option value="regex">' . $langs->trans('ChatbotTriggerRegex') . '</option>';
print '<option value="new_conversation">' . $langs->trans('ChatbotTriggerNewConv') . '</option>';
print '<option value="default">' . $langs->trans('ChatbotTriggerDefault') . '</option>';
print '</select>';
print '</div>';

// Trigger value
print '<div class="chatbot-form-group" id="chatbot-trigger-value-group">';
print '<label for="chatbot-trigger-value">' . $langs->trans('ChatbotTriggerValue') . '</label>';
print '<input type="text" id="chatbot-trigger-value" class="flat minwidth400" placeholder="' . $langs->trans('ChatbotTriggerValueHelp') . '">';
print '<small class="chatbot-help-text">' . $langs->trans('ChatbotTriggerContainsHelp') . '</small>';
print '</div>';

// Response type
print '<div class="chatbot-form-group">';
print '<label for="chatbot-response-type">' . $langs->trans('ChatbotResponseType') . '</label>';
print '<select id="chatbot-response-type" class="flat minwidth200">';
print '<option value="text">' . $langs->trans('ChatbotResponseText') . '</option>';
print '<option value="template">' . $langs->trans('ChatbotResponseTemplate') . '</option>';
print '</select>';
print '</div>';

// Response text
print '<div class="chatbot-form-group" id="chatbot-response-text-group">';
print '<label for="chatbot-response-text">' . $langs->trans('ChatbotResponseTextLabel') . '</label>';
print '<textarea id="chatbot-response-text" class="flat" rows="4" style="width:100%;"></textarea>';
print '<small class="chatbot-help-text">' . $langs->trans('ChatbotVariablesHelp') . '</small>';
print '</div>';

// Template selector
print '<div class="chatbot-form-group" id="chatbot-template-group" style="display:none;">';
print '<label for="chatbot-template-name">' . $langs->trans('ChatbotTemplateName') . '</label>';
print '<select id="chatbot-template-name" class="flat minwidth300">';
print '<option value="">' . $langs->trans('Select') . '</option>';
foreach ($templateOptions as $tName => $tLabel) {
	print '<option value="' . dol_escape_htmltag($tName) . '">' . dol_escape_htmltag($tLabel) . '</option>';
}
print '</select>';
print '</div>';

// Condition type
print '<div class="chatbot-form-group">';
print '<label for="chatbot-condition-type">' . $langs->trans('ChatbotCondition') . '</label>';
print '<select id="chatbot-condition-type" class="flat minwidth200">';
print '<option value="always">' . $langs->trans('ChatbotCondAlways') . '</option>';
print '<option value="outside_hours">' . $langs->trans('ChatbotCondOutsideHours') . '</option>';
print '<option value="unassigned">' . $langs->trans('ChatbotCondUnassigned') . '</option>';
print '</select>';
print '</div>';

// Work hours (shown when condition = outside_hours)
print '<div class="chatbot-form-group chatbot-work-hours" id="chatbot-work-hours-group" style="display:none;">';
print '<label>' . $langs->trans('ChatbotWorkHours') . '</label>';
print '<div>';
print '<input type="time" id="chatbot-work-start" class="flat" value="09:00"> ';
print $langs->trans('To') . ' ';
print '<input type="time" id="chatbot-work-end" class="flat" value="18:00">';
print '</div>';
print '</div>';

// Advanced settings row
print '<div class="chatbot-form-row">';

// Priority
print '<div class="chatbot-form-group chatbot-form-inline">';
print '<label for="chatbot-priority">' . $langs->trans('Priority') . '</label>';
print '<input type="number" id="chatbot-priority" class="flat" value="10" min="1" max="100" style="width:80px;">';
print '</div>';

// Delay
print '<div class="chatbot-form-group chatbot-form-inline">';
print '<label for="chatbot-delay">' . $langs->trans('ChatbotDelay') . '</label>';
print '<input type="number" id="chatbot-delay" class="flat" value="0" min="0" max="300" style="width:80px;"> <small>seg.</small>';
print '</div>';

// Max triggers
print '<div class="chatbot-form-group chatbot-form-inline">';
print '<label for="chatbot-max-triggers">' . $langs->trans('ChatbotMaxTriggers') . '</label>';
print '<input type="number" id="chatbot-max-triggers" class="flat" value="0" min="0" style="width:80px;">';
print '<small class="chatbot-help-text">' . $langs->trans('ChatbotMaxTriggersHelp') . '</small>';
print '</div>';

print '</div>'; // End form-row

// Stop on match
print '<div class="chatbot-form-group">';
print '<label>';
print '<input type="checkbox" id="chatbot-stop-on-match" checked> ';
print $langs->trans('ChatbotStopOnMatch');
print '</label>';
print '<small class="chatbot-help-text">' . $langs->trans('ChatbotStopOnMatchHelp') . '</small>';
print '</div>';

print '</form>';
print '</div>'; // modal-body
print '<div class="chatbot-modal-footer">';
print '<button type="button" class="button" id="btn-chatbot-save">' . $langs->trans('Save') . '</button>';
print '<button type="button" class="button whatsapp-button-cancel" id="btn-chatbot-cancel">' . $langs->trans('Cancel') . '</button>';
print '</div>';
print '</div>'; // modal-content
print '</div>'; // modal

// ======================================
// Modal: Test Message
// ======================================
print '<!-- Test Message Modal -->';
print '<div id="chatbot-test-modal" class="chatbot-modal" style="display:none;">';
print '<div class="chatbot-modal-content chatbot-modal-small">';
print '<div class="chatbot-modal-header">';
print '<h3>' . $langs->trans('ChatbotTestMessage') . '</h3>';
print '<span class="chatbot-modal-close">&times;</span>';
print '</div>';
print '<div class="chatbot-modal-body">';
print '<div class="chatbot-form-group">';
print '<label for="chatbot-test-input">' . $langs->trans('ChatbotTestInput') . '</label>';
print '<input type="text" id="chatbot-test-input" class="flat minwidth400" placeholder="' . $langs->trans('ChatbotTestPlaceholder') . '">';
print '</div>';
print '<div class="chatbot-form-group">';
print '<label><input type="checkbox" id="chatbot-test-newconv"> ' . $langs->trans('ChatbotTestNewConv') . '</label>';
print '</div>';
print '<button type="button" class="button" id="btn-chatbot-run-test">' . $langs->trans('ChatbotRunTest') . '</button>';
print '<div id="chatbot-test-results" style="margin-top:15px;display:none;"></div>';
print '</div>';
print '</div>';
print '</div>';

?>
<script>
var ChatbotAdmin = {
	ajaxUrl: '<?php echo dol_buildpath("/custom/whatsappdati/ajax/chatbot.php", 1); ?>',
	csrfToken: $('input[name="token"]').val(),

	init: function() {
		this.loadRules();
		this.bindEvents();
	},

	bindEvents: function() {
		var self = this;

		// Add rule button
		$(document).on('click', '.btn-chatbot-add', function() {
			self.openRuleModal();
		});

		// Test button
		$(document).on('click', '.btn-chatbot-test', function() {
			$('#chatbot-test-modal').show();
			$('#chatbot-test-input').focus();
		});

		// Close modals
		$(document).on('click', '.chatbot-modal-close, #btn-chatbot-cancel', function() {
			$('.chatbot-modal').hide();
		});

		// Close modal on outside click
		$(document).on('click', '.chatbot-modal', function(e) {
			if ($(e.target).hasClass('chatbot-modal')) {
				$(this).hide();
			}
		});

		// Save rule
		$(document).on('click', '#btn-chatbot-save', function() {
			self.saveRule();
		});

		// Edit rule
		$(document).on('click', '.btn-chatbot-edit', function() {
			var id = $(this).data('id');
			self.editRule(id);
		});

		// Delete rule
		$(document).on('click', '.btn-chatbot-delete', function() {
			var id = $(this).data('id');
			var name = $(this).data('name');
			if (confirm('<?php echo $langs->trans("ChatbotConfirmDelete"); ?>: ' + name + '?')) {
				self.deleteRule(id);
			}
		});

		// Toggle active
		$(document).on('click', '.btn-chatbot-toggle', function() {
			var id = $(this).data('id');
			self.toggleRule(id);
		});

		// Trigger type change
		$(document).on('change', '#chatbot-trigger-type', function() {
			self.updateTriggerFields();
		});

		// Response type change
		$(document).on('change', '#chatbot-response-type', function() {
			self.updateResponseFields();
		});

		// Condition type change
		$(document).on('change', '#chatbot-condition-type', function() {
			var v = $(this).val();
			$('#chatbot-work-hours-group').toggle(v === 'outside_hours');
		});

		// Run test
		$(document).on('click', '#btn-chatbot-run-test', function() {
			self.runTest();
		});

		// Enter key in test input
		$(document).on('keypress', '#chatbot-test-input', function(e) {
			if (e.which === 13) {
				self.runTest();
			}
		});
	},

	loadRules: function() {
		var self = this;
		$.ajax({
			url: this.ajaxUrl,
			data: { action: 'list' },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.renderRules(data.rules);
				} else {
					$('#chatbot-rules-body').html('<tr><td colspan="10" class="center warning">' + (data.error || 'Error') + '</td></tr>');
				}
			},
			error: function() {
				$('#chatbot-rules-body').html('<tr><td colspan="10" class="center warning">Error loading rules</td></tr>');
			}
		});
	},

	renderRules: function(rules) {
		var self = this;
		var tbody = $('#chatbot-rules-body');
		tbody.empty();

		if (!rules || rules.length === 0) {
			tbody.html('<tr><td colspan="10" class="center opacitymedium"><?php echo $langs->trans("ChatbotNoRules"); ?></td></tr>');
			return;
		}

		var triggerLabels = {
			'exact': '<?php echo $langs->trans("ChatbotTriggerExact"); ?>',
			'contains': '<?php echo $langs->trans("ChatbotTriggerContains"); ?>',
			'starts_with': '<?php echo $langs->trans("ChatbotTriggerStartsWith"); ?>',
			'regex': '<?php echo $langs->trans("ChatbotTriggerRegex"); ?>',
			'default': '<?php echo $langs->trans("ChatbotTriggerDefault"); ?>',
			'new_conversation': '<?php echo $langs->trans("ChatbotTriggerNewConv"); ?>'
		};

		var condLabels = {
			'always': '<?php echo $langs->trans("ChatbotCondAlways"); ?>',
			'outside_hours': '<?php echo $langs->trans("ChatbotCondOutsideHours"); ?>',
			'unassigned': '<?php echo $langs->trans("ChatbotCondUnassigned"); ?>'
		};

		for (var i = 0; i < rules.length; i++) {
			var r = rules[i];
			var statusBadge = r.active == 1
				? '<span class="badge badge-status4"><?php echo $langs->trans("Enabled"); ?></span>'
				: '<span class="badge badge-status8"><?php echo $langs->trans("Disabled"); ?></span>';

			var triggerVal = r.trigger_value || '-';
			if (triggerVal.length > 40) triggerVal = triggerVal.substring(0, 40) + '...';

			var responsePreview = '';
			if (r.response_type === 'template') {
				responsePreview = '<span class="badge whatsapp-badge-info">' + (r.response_template_name || '') + '</span>';
			} else {
				responsePreview = (r.response_text || '').substring(0, 60);
				if ((r.response_text || '').length > 60) responsePreview += '...';
			}

			var statsHtml = '';
			if (r.stats) {
				statsHtml = '<span title="Total">' + r.stats.total + '</span>';
				if (r.stats.today > 0) {
					statsHtml += ' <small>(hoy: ' + r.stats.today + ')</small>';
				}
			}

			var tr = '<tr class="oddeven">';
			tr += '<td><strong>' + self.escapeHtml(r.name) + '</strong></td>';
			tr += '<td><span class="badge chatbot-trigger-badge chatbot-trigger-' + r.trigger_type + '">' + (triggerLabels[r.trigger_type] || r.trigger_type) + '</span></td>';
			tr += '<td><code>' + self.escapeHtml(triggerVal) + '</code></td>';
			tr += '<td>' + (r.response_type === 'template' ? '📋 Template' : '💬 Texto') + '</td>';
			tr += '<td>' + responsePreview + '</td>';
			tr += '<td>' + (condLabels[r.condition_type] || r.condition_type) + '</td>';
			tr += '<td class="center">' + r.priority + '</td>';
			tr += '<td class="center">' + statusBadge + '</td>';
			tr += '<td class="center">' + statsHtml + '</td>';
			tr += '<td class="center nowraponall">';
			tr += '<a href="javascript:void(0)" class="btn-chatbot-edit editfielda" data-id="' + r.rowid + '" title="<?php echo $langs->trans("Edit"); ?>"><span class="fas fa-pencil-alt"></span></a> ';
			tr += '<a href="javascript:void(0)" class="btn-chatbot-toggle" data-id="' + r.rowid + '" title="<?php echo $langs->trans("Toggle"); ?>"><span class="fas fa-power-off' + (r.active == 1 ? ' chatbot-active-icon' : ' chatbot-inactive-icon') + '"></span></a> ';
			tr += '<a href="javascript:void(0)" class="btn-chatbot-delete" data-id="' + r.rowid + '" data-name="' + self.escapeHtml(r.name) + '" title="<?php echo $langs->trans("Delete"); ?>"><span class="fas fa-trash" style="color:#a00;"></span></a>';
			tr += '</td>';
			tr += '</tr>';
			tbody.append(tr);
		}
	},

	openRuleModal: function(data) {
		$('#chatbot-rule-id').val('');
		$('#chatbot-rule-name').val('');
		$('#chatbot-trigger-type').val('contains');
		$('#chatbot-trigger-value').val('');
		$('#chatbot-response-type').val('text');
		$('#chatbot-response-text').val('');
		$('#chatbot-template-name').val('');
		$('#chatbot-condition-type').val('always');
		$('#chatbot-work-start').val('09:00');
		$('#chatbot-work-end').val('18:00');
		$('#chatbot-priority').val(10);
		$('#chatbot-delay').val(0);
		$('#chatbot-max-triggers').val(0);
		$('#chatbot-stop-on-match').prop('checked', true);
		$('#chatbot-modal-title').text('<?php echo $langs->trans("ChatbotAddRule"); ?>');

		if (data) {
			$('#chatbot-rule-id').val(data.id);
			$('#chatbot-rule-name').val(data.name);
			$('#chatbot-trigger-type').val(data.trigger_type);
			$('#chatbot-trigger-value').val(data.trigger_value || '');
			$('#chatbot-response-type').val(data.response_type);
			$('#chatbot-response-text').val(data.response_text || '');
			$('#chatbot-template-name').val(data.response_template_name || '');
			$('#chatbot-condition-type').val(data.condition_type);
			if (data.work_hours_start) $('#chatbot-work-start').val(data.work_hours_start.substring(0, 5));
			if (data.work_hours_end) $('#chatbot-work-end').val(data.work_hours_end.substring(0, 5));
			$('#chatbot-priority').val(data.priority);
			$('#chatbot-delay').val(data.delay_seconds || 0);
			$('#chatbot-max-triggers').val(data.max_triggers_per_conv || 0);
			$('#chatbot-stop-on-match').prop('checked', data.stop_on_match == 1);
			$('#chatbot-modal-title').text('<?php echo $langs->trans("ChatbotEditRule"); ?>');
		}

		this.updateTriggerFields();
		this.updateResponseFields();
		$('#chatbot-condition-type').trigger('change');
		$('#chatbot-rule-modal').show();
		$('#chatbot-rule-name').focus();
	},

	editRule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl,
			data: { action: 'fetch', id: id },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.openRuleModal(data.rule);
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	saveRule: function() {
		var self = this;
		var id = $('#chatbot-rule-id').val();
		var payload = {
			name: $('#chatbot-rule-name').val(),
			trigger_type: $('#chatbot-trigger-type').val(),
			trigger_value: $('#chatbot-trigger-value').val(),
			response_type: $('#chatbot-response-type').val(),
			response_text: $('#chatbot-response-text').val(),
			response_template_name: $('#chatbot-template-name').val(),
			response_template_params: '',
			delay_seconds: parseInt($('#chatbot-delay').val()) || 0,
			condition_type: $('#chatbot-condition-type').val(),
			work_hours_start: $('#chatbot-work-start').val() + ':00',
			work_hours_end: $('#chatbot-work-end').val() + ':00',
			max_triggers_per_conv: parseInt($('#chatbot-max-triggers').val()) || 0,
			priority: parseInt($('#chatbot-priority').val()) || 10,
			stop_on_match: $('#chatbot-stop-on-match').is(':checked') ? 1 : 0
		};

		if (!payload.name) {
			alert('<?php echo $langs->trans("ErrorNameRequired"); ?>');
			return;
		}

		var action = 'create';
		if (id) {
			action = 'update';
			payload.id = parseInt(id);
		}

		$.ajax({
			url: this.ajaxUrl + '?action=' + action + '&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify(payload),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#chatbot-rule-modal').hide();
					self.loadRules();
				} else {
					alert(data.error || 'Error');
				}
			},
			error: function() {
				alert('Error saving rule');
			}
		});
	},

	deleteRule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=delete&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.loadRules();
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	toggleRule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=toggle&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.loadRules();
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	runTest: function() {
		var message = $('#chatbot-test-input').val().trim();
		if (!message) return;

		var isNew = $('#chatbot-test-newconv').is(':checked');
		var resultsDiv = $('#chatbot-test-results');

		resultsDiv.html('<em><?php echo $langs->trans("Loading"); ?>...</em>').show();

		$.ajax({
			url: this.ajaxUrl + '?action=test&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ message: message, is_new_conversation: isNew }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					if (data.count === 0) {
						resultsDiv.html('<div class="warning"><?php echo $langs->trans("ChatbotTestNoMatch"); ?></div>');
					} else {
						var html = '<div class="info"><?php echo $langs->trans("ChatbotTestMatched"); ?>: ' + data.count + '</div>';
						html += '<table class="tagtable liste" style="margin-top:8px;"><thead><tr class="liste_titre">';
						html += '<th><?php echo $langs->trans("ChatbotRuleName"); ?></th>';
						html += '<th><?php echo $langs->trans("ChatbotTriggerType"); ?></th>';
						html += '<th><?php echo $langs->trans("Priority"); ?></th>';
						html += '<th><?php echo $langs->trans("ChatbotResponse"); ?></th>';
						html += '</tr></thead><tbody>';
						for (var i = 0; i < data.matches.length; i++) {
							var m = data.matches[i];
							html += '<tr class="oddeven">';
							html += '<td>' + m.name + '</td>';
							html += '<td>' + m.trigger_type + '</td>';
							html += '<td class="center">' + m.priority + '</td>';
							html += '<td>' + (m.response_text || m.response_type) + '</td>';
							html += '</tr>';
						}
						html += '</tbody></table>';
						resultsDiv.html(html);
					}
				} else {
					resultsDiv.html('<div class="error">' + (data.error || 'Error') + '</div>');
				}
			}
		});
	},

	updateTriggerFields: function() {
		var type = $('#chatbot-trigger-type').val();
		var helpTexts = {
			'contains': '<?php echo $langs->trans("ChatbotTriggerContainsHelp"); ?>',
			'exact': '<?php echo $langs->trans("ChatbotTriggerExactHelp"); ?>',
			'starts_with': '<?php echo $langs->trans("ChatbotTriggerStartsWithHelp"); ?>',
			'regex': '<?php echo $langs->trans("ChatbotTriggerRegexHelp"); ?>',
			'new_conversation': '',
			'default': ''
		};

		if (type === 'new_conversation' || type === 'default') {
			$('#chatbot-trigger-value-group').hide();
		} else {
			$('#chatbot-trigger-value-group').show();
			$('#chatbot-trigger-value-group .chatbot-help-text').text(helpTexts[type] || '');
		}
	},

	updateResponseFields: function() {
		var type = $('#chatbot-response-type').val();
		if (type === 'template') {
			$('#chatbot-response-text-group').hide();
			$('#chatbot-template-group').show();
		} else {
			$('#chatbot-response-text-group').show();
			$('#chatbot-template-group').hide();
		}
	},

	escapeHtml: function(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}
};

$(document).ready(function() {
	ChatbotAdmin.init();
});
</script>
<?php

llxFooter();
$db->close();
