<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       schedule.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Scheduled Messages management page
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
require_once './class/whatsappschedule.class.php';
require_once './class/whatsapptemplate.class.php';
require_once './class/whatsappconfig.class.php';

// Translations
$langs->loadLangs(array("whatsappdati@whatsappdati"));

// Access control
if (!$user->rights->whatsappdati->message->send) {
	accessforbidden();
}

$form = new Form($db);

// Load templates for selector
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

$title = $langs->trans('ScheduleTitle');
llxHeader('', $title, '', '', 0, 0, '', array('/custom/whatsappdati/css/whatsappdati.css'));

print load_fiche_titre($title, '', 'whatsappdati@whatsappdati');

// CSRF token for AJAX calls
print '<input type="hidden" name="token" id="csrf-token" value="' . newToken() . '">';

// Stats bar
print '<div class="schedule-stats" id="schedule-stats">';
print '<div class="schedule-stat-item"><span class="schedule-stat-number" id="stat-pending">-</span><span class="schedule-stat-label">' . $langs->trans('SchedulePending') . '</span></div>';
print '<div class="schedule-stat-item"><span class="schedule-stat-number" id="stat-recurring">-</span><span class="schedule-stat-label">' . $langs->trans('ScheduleRecurring') . '</span></div>';
print '<div class="schedule-stat-item"><span class="schedule-stat-number" id="stat-sent">-</span><span class="schedule-stat-label">' . $langs->trans('ScheduleSent') . '</span></div>';
print '<div class="schedule-stat-item"><span class="schedule-stat-number" id="stat-failed">-</span><span class="schedule-stat-label">' . $langs->trans('ScheduleFailed') . '</span></div>';
print '</div>';

// Toolbar
print '<div class="schedule-toolbar">';
print '<a href="javascript:void(0)" class="butAction btn-schedule-add">' . $langs->trans('ScheduleNewMessage') . '</a>';

// Filter tabs
print '<div class="schedule-filters">';
print '<a href="javascript:void(0)" class="schedule-filter active" data-filter="all">' . $langs->trans('All') . '</a>';
print '<a href="javascript:void(0)" class="schedule-filter" data-filter="pending">' . $langs->trans('SchedulePending') . '</a>';
print '<a href="javascript:void(0)" class="schedule-filter" data-filter="recurring">' . $langs->trans('ScheduleRecurring') . '</a>';
print '<a href="javascript:void(0)" class="schedule-filter" data-filter="sent">' . $langs->trans('ScheduleSent') . '</a>';
print '<a href="javascript:void(0)" class="schedule-filter" data-filter="failed">' . $langs->trans('ScheduleFailed') . '</a>';
print '</div>';
print '</div>';

print '<br>';

// Schedule table
print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="schedule-table">';
print '<thead>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans('ScheduleRecipient') . '</th>';
print '<th>' . $langs->trans('ScheduleMessageType') . '</th>';
print '<th>' . $langs->trans('ScheduleContent') . '</th>';
print '<th class="center">' . $langs->trans('ScheduleDate') . '</th>';
print '<th class="center">' . $langs->trans('ScheduleRecurrence') . '</th>';
print '<th class="center">' . $langs->trans('ScheduleNextExec') . '</th>';
print '<th class="center">' . $langs->trans('Status') . '</th>';
print '<th class="center">' . $langs->trans('ScheduleExecCount') . '</th>';
print '<th class="center">' . $langs->trans('Actions') . '</th>';
print '</tr>';
print '</thead>';
print '<tbody id="schedule-body">';
print '<tr><td colspan="9" class="center">' . $langs->trans('Loading') . '...</td></tr>';
print '</tbody>';
print '</table>';
print '</div>';

// ======================================
// Modal: Create/Edit Schedule
// ======================================
print '<!-- Schedule Modal -->';
print '<div id="schedule-modal" class="schedule-modal" style="display:none;">';
print '<div class="schedule-modal-content">';
print '<div class="schedule-modal-header">';
print '<h3 id="schedule-modal-title">' . $langs->trans('ScheduleNewMessage') . '</h3>';
print '<span class="schedule-modal-close">&times;</span>';
print '</div>';
print '<div class="schedule-modal-body">';
print '<form id="schedule-form">';
print '<input type="hidden" id="schedule-id" value="">';

// Recipient section
print '<fieldset class="schedule-fieldset"><legend>' . $langs->trans('ScheduleRecipient') . '</legend>';

// Phone number
print '<div class="schedule-form-group">';
print '<label for="schedule-phone">' . $langs->trans('Phone') . ' <span class="required">*</span></label>';
print '<input type="text" id="schedule-phone" class="flat minwidth200" placeholder="+34612345678" required>';
print '</div>';

// Contact name
print '<div class="schedule-form-group">';
print '<label for="schedule-contact-name">' . $langs->trans('ContactName') . '</label>';
print '<input type="text" id="schedule-contact-name" class="flat minwidth200">';
print '</div>';

print '</fieldset>';

// Line selector
$schedConfigObj = new WhatsAppConfig($db);
$schedActiveLines = $schedConfigObj->fetchActiveLines();
if (count($schedActiveLines) > 1) {
	print '<fieldset class="schedule-fieldset"><legend>' . $langs->trans('WhatsAppLine') . '</legend>';
	print '<div class="schedule-form-group">';
	print '<label for="schedule-line">' . $langs->trans('WhatsAppLine') . '</label>';
	print '<select id="schedule-line" class="flat minwidth200">';
	foreach ($schedActiveLines as $sLineObj) {
		print '<option value="' . $sLineObj->id . '">' . dol_escape_htmltag($sLineObj->label) . '</option>';
	}
	print '</select>';
	print '</div>';
	print '</fieldset>';
} elseif (count($schedActiveLines) == 1) {
	print '<input type="hidden" id="schedule-line" value="' . $schedActiveLines[0]->id . '">';
}

// Message section
print '<fieldset class="schedule-fieldset"><legend>' . $langs->trans('Message') . '</legend>';

// Message type
print '<div class="schedule-form-group">';
print '<label for="schedule-msg-type">' . $langs->trans('ScheduleMessageType') . '</label>';
print '<select id="schedule-msg-type" class="flat minwidth200">';
print '<option value="text">' . $langs->trans('ScheduleMsgText') . '</option>';
print '<option value="template">' . $langs->trans('ScheduleMsgTemplate') . '</option>';
print '</select>';
print '</div>';

// Text content
print '<div class="schedule-form-group" id="schedule-text-group">';
print '<label for="schedule-content">' . $langs->trans('ScheduleContent') . ' <span class="required">*</span></label>';
print '<textarea id="schedule-content" class="flat" rows="4" style="width:100%;"></textarea>';
print '<small class="schedule-help">' . $langs->trans('ScheduleTextHelp') . '</small>';
print '</div>';

// Template selector
print '<div class="schedule-form-group" id="schedule-template-group" style="display:none;">';
print '<label for="schedule-template-name">' . $langs->trans('ScheduleTemplate') . '</label>';
print '<select id="schedule-template-name" class="flat minwidth300">';
print '<option value="">' . $langs->trans('Select') . '</option>';
foreach ($templateOptions as $tName => $tLabel) {
	print '<option value="' . dol_escape_htmltag($tName) . '">' . dol_escape_htmltag($tLabel) . '</option>';
}
print '</select>';
print '</div>';

print '</fieldset>';

// Schedule section
print '<fieldset class="schedule-fieldset"><legend>' . $langs->trans('ScheduleDateTime') . '</legend>';

// Scheduled date
print '<div class="schedule-form-group">';
print '<label for="schedule-date">' . $langs->trans('ScheduleDate') . ' <span class="required">*</span></label>';
print '<input type="datetime-local" id="schedule-date" class="flat minwidth250" required>';
print '</div>';

// Recurrence type
print '<div class="schedule-form-group">';
print '<label for="schedule-recurrence">' . $langs->trans('ScheduleRecurrence') . '</label>';
print '<select id="schedule-recurrence" class="flat minwidth200">';
print '<option value="once">' . $langs->trans('ScheduleOnce') . '</option>';
print '<option value="daily">' . $langs->trans('ScheduleDaily') . '</option>';
print '<option value="weekly">' . $langs->trans('ScheduleWeekly') . '</option>';
print '<option value="monthly">' . $langs->trans('ScheduleMonthly') . '</option>';
print '</select>';
print '</div>';

// Recurrence end date
print '<div class="schedule-form-group" id="schedule-recurrence-end-group" style="display:none;">';
print '<label for="schedule-recurrence-end">' . $langs->trans('ScheduleRecurrenceEnd') . '</label>';
print '<input type="datetime-local" id="schedule-recurrence-end" class="flat minwidth250">';
print '<small class="schedule-help">' . $langs->trans('ScheduleRecurrenceEndHelp') . '</small>';
print '</div>';

print '</fieldset>';

// Note
print '<div class="schedule-form-group">';
print '<label for="schedule-note">' . $langs->trans('Note') . '</label>';
print '<input type="text" id="schedule-note" class="flat" style="width:100%;" maxlength="500" placeholder="' . $langs->trans('ScheduleNoteHelp') . '">';
print '</div>';

print '</form>';
print '</div>'; // modal-body
print '<div class="schedule-modal-footer">';
print '<button type="button" class="button" id="btn-schedule-save">' . $langs->trans('Save') . '</button>';
print '<button type="button" class="button whatsapp-button-cancel" id="btn-schedule-cancel">' . $langs->trans('Cancel') . '</button>';
print '</div>';
print '</div>'; // modal-content
print '</div>'; // modal

?>
<script>
var ScheduleAdmin = {
	ajaxUrl: '<?php echo dol_buildpath("/custom/whatsappdati/ajax/schedule.php", 1); ?>',
	csrfToken: $('input[name="token"]').val(),
	currentFilter: 'all',

	init: function() {
		this.loadSchedules();
		this.bindEvents();
		this.setMinDate();
	},

	bindEvents: function() {
		var self = this;

		// Add button
		$(document).on('click', '.btn-schedule-add', function() {
			self.openModal();
		});

		// Close modal
		$(document).on('click', '.schedule-modal-close, #btn-schedule-cancel', function() {
			$('.schedule-modal').hide();
		});
		$(document).on('click', '.schedule-modal', function(e) {
			if ($(e.target).hasClass('schedule-modal')) $(this).hide();
		});

		// Save
		$(document).on('click', '#btn-schedule-save', function() {
			self.saveSchedule();
		});

		// Filter tabs
		$(document).on('click', '.schedule-filter', function() {
			$('.schedule-filter').removeClass('active');
			$(this).addClass('active');
			self.currentFilter = $(this).data('filter');
			self.loadSchedules();
		});

		// Message type toggle
		$(document).on('change', '#schedule-msg-type', function() {
			var type = $(this).val();
			$('#schedule-text-group').toggle(type === 'text');
			$('#schedule-template-group').toggle(type === 'template');
		});

		// Recurrence toggle
		$(document).on('change', '#schedule-recurrence', function() {
			$('#schedule-recurrence-end-group').toggle($(this).val() !== 'once');
		});

		// Edit
		$(document).on('click', '.btn-schedule-edit', function() {
			self.editSchedule($(this).data('id'));
		});

		// Delete
		$(document).on('click', '.btn-schedule-delete', function() {
			if (confirm('<?php echo $langs->trans("ScheduleConfirmDelete"); ?>')) {
				self.deleteSchedule($(this).data('id'));
			}
		});

		// Cancel
		$(document).on('click', '.btn-schedule-cancel-item', function() {
			if (confirm('<?php echo $langs->trans("ScheduleConfirmCancel"); ?>')) {
				self.cancelSchedule($(this).data('id'));
			}
		});

		// Pause/Resume
		$(document).on('click', '.btn-schedule-pause', function() {
			self.pauseSchedule($(this).data('id'));
		});

		// Send now
		$(document).on('click', '.btn-schedule-sendnow', function() {
			if (confirm('<?php echo $langs->trans("ScheduleConfirmSendNow"); ?>')) {
				self.sendNow($(this).data('id'));
			}
		});
	},

	setMinDate: function() {
		// Set min to current date/time
		var now = new Date();
		now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
		var minDate = now.toISOString().slice(0, 16);
		$('#schedule-date').attr('min', minDate);
	},

	loadSchedules: function() {
		var self = this;
		$.ajax({
			url: this.ajaxUrl,
			data: { action: 'list', filter: this.currentFilter },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.renderSchedules(data.schedules);
					self.renderStats(data.stats);
				} else {
					$('#schedule-body').html('<tr><td colspan="9" class="center warning">' + (data.error || 'Error') + '</td></tr>');
				}
			},
			error: function() {
				$('#schedule-body').html('<tr><td colspan="9" class="center warning">Error loading schedules</td></tr>');
			}
		});
	},

	renderStats: function(stats) {
		if (!stats) return;
		$('#stat-pending').text(stats.pending || 0);
		$('#stat-recurring').text(stats.recurring || 0);
		$('#stat-sent').text(stats.sent || 0);
		$('#stat-failed').text(stats.failed || 0);
	},

	renderSchedules: function(schedules) {
		var self = this;
		var tbody = $('#schedule-body');
		tbody.empty();

		if (!schedules || schedules.length === 0) {
			tbody.html('<tr><td colspan="9" class="center opacitymedium"><?php echo $langs->trans("ScheduleNoMessages"); ?></td></tr>');
			return;
		}

		var recLabels = {
			'once': '<?php echo $langs->trans("ScheduleOnce"); ?>',
			'daily': '<?php echo $langs->trans("ScheduleDaily"); ?>',
			'weekly': '<?php echo $langs->trans("ScheduleWeekly"); ?>',
			'monthly': '<?php echo $langs->trans("ScheduleMonthly"); ?>'
		};

		var statusBadges = {
			'pending': '<span class="badge badge-status4"><?php echo $langs->trans("SchedulePending"); ?></span>',
			'sent': '<span class="badge badge-status6"><?php echo $langs->trans("ScheduleSent"); ?></span>',
			'failed': '<span class="badge badge-status8"><?php echo $langs->trans("ScheduleFailed"); ?></span>',
			'cancelled': '<span class="badge badge-status9"><?php echo $langs->trans("Cancelled"); ?></span>',
			'paused': '<span class="badge badge-status1"><?php echo $langs->trans("SchedulePaused"); ?></span>'
		};

		for (var i = 0; i < schedules.length; i++) {
			var s = schedules[i];

			// Recipient
			var recipientHtml = '<strong>' + self.escapeHtml(s.phone_number) + '</strong>';
			if (s.contact_name) recipientHtml += '<br><small class="opacitymedium">' + self.escapeHtml(s.contact_name) + '</small>';

			// Message type
			var typeHtml = s.message_type === 'template'
				? '<span class="badge whatsapp-badge-info">📋 Template</span>'
				: '<span class="badge schedule-badge-text">💬 Texto</span>';

			// Content preview
			var contentPreview = '';
			if (s.message_type === 'template') {
				contentPreview = s.template_name || '';
			} else {
				contentPreview = (s.message_content || '').substring(0, 60);
				if ((s.message_content || '').length > 60) contentPreview += '...';
			}
			if (s.note) {
				contentPreview += '<br><small class="opacitymedium" title="' + self.escapeHtml(s.note) + '">📝 ' + self.escapeHtml(s.note.substring(0, 30)) + '</small>';
			}

			// Recurrence badge
			var recHtml = recLabels[s.recurrence_type] || s.recurrence_type;
			if (s.recurrence_type !== 'once') {
				recHtml = '<span class="badge schedule-badge-recurring">' + recHtml + '</span>';
			}

			// Error tooltip
			var statusHtml = statusBadges[s.status] || s.status;
			if (s.status === 'failed' && s.error_message) {
				statusHtml += '<br><small class="warning" title="' + self.escapeHtml(s.error_message) + '">⚠️ Error</small>';
			}

			// Actions
			var actions = '';
			if (s.status === 'pending' || s.status === 'paused' || s.status === 'failed') {
				actions += '<a href="javascript:void(0)" class="btn-schedule-edit editfielda" data-id="' + s.rowid + '" title="<?php echo $langs->trans("Edit"); ?>"><span class="fas fa-pencil-alt"></span></a> ';
				actions += '<a href="javascript:void(0)" class="btn-schedule-sendnow" data-id="' + s.rowid + '" title="<?php echo $langs->trans("ScheduleSendNow"); ?>"><span class="fas fa-paper-plane" style="color:#25d366;"></span></a> ';
			}
			if (s.status === 'pending' && s.recurrence_type !== 'once') {
				actions += '<a href="javascript:void(0)" class="btn-schedule-pause" data-id="' + s.rowid + '" title="<?php echo $langs->trans("SchedulePause"); ?>"><span class="fas fa-pause" style="color:#f0ad4e;"></span></a> ';
			}
			if (s.status === 'paused') {
				actions += '<a href="javascript:void(0)" class="btn-schedule-pause" data-id="' + s.rowid + '" title="<?php echo $langs->trans("ScheduleResume"); ?>"><span class="fas fa-play" style="color:#25d366;"></span></a> ';
			}
			if (s.status === 'pending' || s.status === 'paused') {
				actions += '<a href="javascript:void(0)" class="btn-schedule-cancel-item" data-id="' + s.rowid + '" title="<?php echo $langs->trans("Cancel"); ?>"><span class="fas fa-ban" style="color:#d9534f;"></span></a> ';
			}
			actions += '<a href="javascript:void(0)" class="btn-schedule-delete" data-id="' + s.rowid + '" title="<?php echo $langs->trans("Delete"); ?>"><span class="fas fa-trash" style="color:#a00;"></span></a>';

			var tr = '<tr class="oddeven">';
			tr += '<td>' + recipientHtml + '</td>';
			tr += '<td>' + typeHtml + '</td>';
			tr += '<td>' + contentPreview + '</td>';
			tr += '<td class="center nowraponall">' + (s.scheduled_date_formatted || '') + '</td>';
			tr += '<td class="center">' + recHtml + '</td>';
			tr += '<td class="center nowraponall">' + (s.next_execution_formatted || '-') + '</td>';
			tr += '<td class="center">' + statusHtml + '</td>';
			tr += '<td class="center">' + (s.execution_count || 0) + '</td>';
			tr += '<td class="center nowraponall">' + actions + '</td>';
			tr += '</tr>';
			tbody.append(tr);
		}
	},

	openModal: function(data) {
		$('#schedule-id').val('');
		$('#schedule-phone').val('');
		$('#schedule-contact-name').val('');
		$('#schedule-msg-type').val('text');
		$('#schedule-content').val('');
		$('#schedule-template-name').val('');
		$('#schedule-date').val('');
		$('#schedule-recurrence').val('once');
		$('#schedule-recurrence-end').val('');
		$('#schedule-note').val('');
		$('#schedule-text-group').show();
		$('#schedule-template-group').hide();
		$('#schedule-recurrence-end-group').hide();
		$('#schedule-modal-title').text('<?php echo $langs->trans("ScheduleNewMessage"); ?>');
		if ($('#schedule-line').length) $('#schedule-line').val($('#schedule-line option:first').val());

		if (data) {
			$('#schedule-id').val(data.id);
			$('#schedule-phone').val(data.phone_number);
			$('#schedule-contact-name').val(data.contact_name || '');
			$('#schedule-msg-type').val(data.message_type);
			$('#schedule-content').val(data.message_content || '');
			$('#schedule-template-name').val(data.template_name || '');
			$('#schedule-recurrence').val(data.recurrence_type || 'once');
			$('#schedule-note').val(data.note || '');
			$('#schedule-modal-title').text('<?php echo $langs->trans("ScheduleEditMessage"); ?>');

			// Set line if available
			if (data.fk_line && $('#schedule-line').length) {
				$('#schedule-line').val(data.fk_line);
			}

			// Parse datetime for input
			if (data.scheduled_date) {
				var dt = new Date(data.scheduled_date);
				if (!isNaN(dt.getTime())) {
					dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
					$('#schedule-date').val(dt.toISOString().slice(0, 16));
				}
			}
			if (data.recurrence_end_date) {
				var rdt = new Date(data.recurrence_end_date);
				if (!isNaN(rdt.getTime())) {
					rdt.setMinutes(rdt.getMinutes() - rdt.getTimezoneOffset());
					$('#schedule-recurrence-end').val(rdt.toISOString().slice(0, 16));
				}
			}

			$('#schedule-msg-type').trigger('change');
			$('#schedule-recurrence').trigger('change');
		}

		this.setMinDate();
		$('#schedule-modal').show();
		$('#schedule-phone').focus();
	},

	editSchedule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl,
			data: { action: 'fetch', id: id },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.openModal(data.schedule);
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	saveSchedule: function() {
		var self = this;
		var id = $('#schedule-id').val();

		var payload = {
			phone_number: $('#schedule-phone').val().trim(),
			contact_name: $('#schedule-contact-name').val().trim(),
			message_type: $('#schedule-msg-type').val(),
			message_content: $('#schedule-content').val(),
			template_name: $('#schedule-template-name').val(),
			template_params: '',
			scheduled_date: $('#schedule-date').val(),
			recurrence_type: $('#schedule-recurrence').val(),
			recurrence_end_date: $('#schedule-recurrence-end').val() || '',
			note: $('#schedule-note').val().trim(),
			fk_line: $('#schedule-line').length ? parseInt($('#schedule-line').val()) || 0 : 0
		};

		if (!payload.phone_number) {
			alert('<?php echo $langs->trans("ErrorPhoneRequired"); ?>');
			return;
		}
		if (!payload.scheduled_date) {
			alert('<?php echo $langs->trans("ErrorScheduledDateRequired"); ?>');
			return;
		}
		if (payload.message_type === 'text' && !payload.message_content.trim()) {
			alert('<?php echo $langs->trans("ErrorMessageContentRequired"); ?>');
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
					$('#schedule-modal').hide();
					self.loadSchedules();
				} else {
					alert(data.error || 'Error');
				}
			},
			error: function() {
				alert('Error saving schedule');
			}
		});
	},

	deleteSchedule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=delete&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) self.loadSchedules();
				else alert(data.error || 'Error');
			}
		});
	},

	cancelSchedule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=cancel&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) self.loadSchedules();
				else alert(data.error || 'Error');
			}
		});
	},

	pauseSchedule: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=pause&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) self.loadSchedules();
				else alert(data.error || 'Error');
			}
		});
	},

	sendNow: function(id) {
		var self = this;
		$.ajax({
			url: this.ajaxUrl + '?action=send_now&token=' + encodeURIComponent(this.csrfToken),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ id: id }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					alert('<?php echo $langs->trans("ScheduleSentSuccess"); ?>');
					self.loadSchedules();
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	escapeHtml: function(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}
};

$(document).ready(function() {
	ScheduleAdmin.init();
});
</script>
<?php

llxFooter();
$db->close();
