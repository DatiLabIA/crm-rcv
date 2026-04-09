/**
 * WhatsApp Business Module - JavaScript
 * Copyright (C) 2024 DatiLab
 */

// L8: padStart polyfill for older browsers
if (!String.prototype.padStart) {
	String.prototype.padStart = function(targetLength, padString) {
		targetLength = targetLength >> 0;
		padString = String(typeof padString !== 'undefined' ? padString : ' ');
		if (this.length >= targetLength) return String(this);
		targetLength = targetLength - this.length;
		if (targetLength > padString.length) {
			padString += padString.repeat(targetLength / padString.length);
		}
		return padString.slice(0, targetLength) + String(this);
	};
}

// L5: Base URL for AJAX calls — set by PHP or default to relative
var WhatsAppAjaxBase = (typeof WhatsAppAjaxBase !== 'undefined') ? WhatsAppAjaxBase : '';

/**
 * Global i18n helper (H34) — reads from WhatsAppLang injected by PHP.
 * Supports %s placeholders: _wt('key', arg1, arg2)
 */
function _wt(key) {
	var str = (typeof WhatsAppLang !== 'undefined' && WhatsAppLang[key]) ? WhatsAppLang[key] : key;
	if (arguments.length > 1) {
		for (var i = 1; i < arguments.length; i++) {
			str = str.replace('%s', arguments[i]);
		}
	}
	return str;
}

var WhatsAppChat = {
	_initialized: false, // Guard against double init
	currentConversationId: null,
	pollingInterval: null,
	pollingDelay: 5000, // 5 seconds (used as fallback when SSE fails)
	templateCache: {}, // Cache template details by ID
	_templateCacheKeys: [], // L1: ordered keys for eviction
	_templateCacheMax: 50, // L1: max cached templates
	selectedFile: null, // Currently selected file for upload
	agentsList: [], // Cached list of agents for assignment
	conversationsData: {}, // Cache conversation data by ID
	_convCacheMax: 200, // L2: max cached conversations
	allTags: [], // All available tags
	filterTagId: 0, // Current tag filter (0 = all)
	quickReplies: [], // Cached quick replies
	qrPickerVisible: false, // Quick reply picker state
	// Real-time / SSE state
	sseSource: null, // EventSource instance
	sseConnected: false, // Whether SSE is connected
	sseReconnectTimer: null, // Timer for SSE reconnect
	sseReconnectDelay: 2000, // Start with 2s reconnect delay
	sseMaxReconnectDelay: 30000, // Max 30s between reconnects
	sseFailCount: 0, // Count SSE failures for fallback
	sseMaxRetries: 5, // Max retries before permanent fallback to polling
	realtimeMode: 'polling', // 'sse' or 'polling'
	// M11: Incremental loading timestamp
	lastConversationsTimestamp: 0,
	// M12: Loading guard flags
	isLoadingConversations: false,
	isLoadingMessages: false,
	_conversationsXhr: null,     // In-flight XHR for conversations (abortable)
	// M13: Debounce timer for loadConversations
	_loadConversationsTimer: null,
	// L9: guard to prevent duplicate event binding
	_eventsBound: false,
	// Multi-line: current line filter (0 = all)
	currentLineId: 0,
	// "Only mine" filter toggle
	filterMine: false,
	// "Only unread" filter toggle
	filterUnread: false,
	// Server-side search term
	_searchTerm: '',
	_searchDebounce: null,
	// Messages pagination
	messagesOffset: 0,
	messagesHasMore: false,
	// Audio notification
	_audioCtx: null,
	// Original page title (for unread badge in tab)
	_originalPageTitle: '',
	// Voice recording state
	_voiceRecorder: null,       // MediaRecorder instance
	_voiceChunks: [],           // Recorded audio chunks
	_voiceStream: null,         // MediaStream from microphone
	_voiceTimerInterval: null,  // Timer display interval
	_voiceStartTime: 0,         // Recording start timestamp
	_voiceRecording: false,     // Is currently recording

	/**
	 * Translate helper — delegates to global _wt() (H34)
	 */
	_t: function(key) {
		return _wt.apply(null, arguments);
	},

	/**
	 * Initialize chat
	 */
	init: function() {
		// Guard against double initialization (can happen with some Dolibarr themes)
		if (this._initialized) {
			console.warn('[WhatsAppDati] Already initialized, skipping duplicate init');
			return;
		}
		this._initialized = true;
		this._originalPageTitle = document.title;

		// M15: Global AJAX error handler for calls without explicit error callback
		$(document).ajaxError(function(event, jqXHR, settings, thrownError) {
			if (jqXHR.status === 403) {
				console.warn('[WhatsApp] Access denied: ' + settings.url);
			} else if (jqXHR.status >= 500) {
				console.error('[WhatsApp] Server error on ' + settings.url + ': ' + jqXHR.status);
			} else if (jqXHR.status === 0 && thrownError !== 'abort') {
				console.warn('[WhatsApp] Network error on ' + settings.url);
			}
		});

		this.loadConversations();
		this.loadTemplates();
		this.loadAgents();
		this.loadTags();
		this.loadQuickReplies();
		this.initRealtime();
		this.initLineFilter();
		this.bindEvents();

		console.log('[WhatsAppDati] Module initialized, v2.1 with emoji+media fixes');

		// L3: Cleanup SSE and polling on page unload
		var self = this;
		$(window).on('beforeunload', function() {
			self.disconnectRealtime();
		});
	},

	/**
	 * Bind event listeners
	 */
	bindEvents: function() {
		// L9: Prevent duplicate binding if init() called more than once
		if (this._eventsBound) return;
		this._eventsBound = true;

		var self = this;
		
		// Send message on button click
		$(document).on('click', '#whatsapp-send-btn', function() {
			self.sendMessage();
		});

		// Send message on Enter key
		$(document).on('keypress', '#whatsapp-message-input', function(e) {
			if (e.which === 13 && !e.shiftKey) {
				e.preventDefault();
				self.sendMessage();
			}
		});

		// Select conversation
		$(document).on('click', '.whatsapp-conversation-item', function() {
			var conversationId = $(this).data('conversation-id');
			self.selectConversation(conversationId);
		});

		// H35: Mobile back button — go back to conversation list
		$(document).on('click', '#whatsapp-mobile-back-btn', function() {
			$('#whatsapp-chat-container').removeClass('whatsapp-mobile-show-chat');
		});

		// Open template modal when clicking "Send with template"
		$(document).on('click', '#whatsapp-send-template-btn', function() {
			var templateId = $('#whatsapp-template-select').val();
			if (!templateId) {
				alert(WhatsAppChat._t('SelectTemplate'));
				return;
			}
			self.openTemplateModal(templateId);
		});

		// Inline template picker toggle (inside input area)
		$(document).on('click', '#whatsapp-tpl-picker-btn', function(e) {
			e.stopPropagation();
			var picker = $('#whatsapp-tpl-picker');
			if (picker.is(':visible')) {
				picker.hide();
				return;
			}
			// Build list from loaded templates
			var list = $('#whatsapp-tpl-picker-list');
			list.empty();
			var options = $('#whatsapp-template-select option').not(':first');
			if (options.length === 0) {
				list.html('<div class="whatsapp-tpl-picker-empty">' + WhatsAppChat._t('NoTemplatesAvailable') + '</div>');
			} else {
				options.each(function() {
					var $opt = $(this);
					list.append('<div class="whatsapp-tpl-picker-item" data-tpl-id="' + $opt.val() + '">' + WhatsAppChat.escapeHtml($opt.text()) + '</div>');
				});
			}
			// Position above the button
			var btn = $(this);
			var offset = btn.offset();
			picker.css({
				bottom: ($(window).height() - offset.top + 8) + 'px',
				left: Math.max(offset.left - 100, 10) + 'px'
			}).show();
		});

		// Select template from inline picker
		$(document).on('click', '.whatsapp-tpl-picker-item', function() {
			var tplId = $(this).data('tpl-id');
			$('#whatsapp-tpl-picker').hide();
			if (tplId) {
				self.openTemplateModal(tplId);
			}
		});

		// Close picker on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#whatsapp-tpl-picker, #whatsapp-tpl-picker-btn').length) {
				$('#whatsapp-tpl-picker').hide();
			}
		});

		// Close modal
		$(document).on('click', '#whatsapp-modal-close, #whatsapp-modal-cancel', function() {
			self.closeTemplateModal();
		});

		// Close modal on overlay click
		$(document).on('click', '#whatsapp-template-modal', function(e) {
			if ($(e.target).is('#whatsapp-template-modal')) {
				self.closeTemplateModal();
			}
		});

		// Close modal on Escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape') {
				self.closeTemplateModal();
			}
		});

		// Send template from modal
		$(document).on('click', '#whatsapp-modal-send', function() {
			self.sendTemplateFromModal();
		});

		// Live preview update as user types in variable fields
		$(document).on('input', '.whatsapp-var-input', function() {
			self.updateTemplatePreview();
		});

		// ---- File upload events ----
		
		// Attach button opens file picker
		$(document).on('click', '#whatsapp-attach-btn', function() {
			$('#whatsapp-file-input').trigger('click');
		});

		// File selected
		$(document).on('change', '#whatsapp-file-input', function() {
			var file = this.files[0];
			if (file) {
				self.showFilePreview(file);
			}
		});

		// Remove selected file
		$(document).on('click', '#whatsapp-file-remove-btn', function() {
			self.clearFilePreview();
		});

		// Send file
		$(document).on('click', '#whatsapp-send-file-btn', function() {
			self.sendMediaMessage();
		});

		// Send file on Enter in caption field
		$(document).on('keypress', '#whatsapp-caption-input', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				self.sendMediaMessage();
			}
		});

		// Drag & drop on messages area
		$(document).on('dragover', '#whatsapp-messages-area', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).addClass('whatsapp-drag-over');
		});

		$(document).on('dragleave', '#whatsapp-messages-area', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).removeClass('whatsapp-drag-over');
		});

		$(document).on('drop', '#whatsapp-messages-area', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).removeClass('whatsapp-drag-over');
			
			if (!self.currentConversationId) return;
			
			var files = e.originalEvent.dataTransfer.files;
			if (files.length > 0) {
				self.showFilePreview(files[0]);
			}
		});

		// ---- Assignment events ----
		$(document).on('change', '#whatsapp-assign-select', function() {
			var agentId = $(this).val();
			if (self.currentConversationId) {
				self.assignConversation(self.currentConversationId, agentId);
			}
		});

		// Multi-agent picker toggle
		$(document).on('click', '#btn-multi-agent-picker', function(e) {
			e.stopPropagation();
			self.toggleMultiAgentPicker();
		});
		$(document).on('click', '.whatsapp-multi-agent-item', function(e) {
			e.stopPropagation();
			var agentId = parseInt($(this).data('agent-id'));
			var checked = $(this).find('input[type="checkbox"]').prop('checked');
			$(this).find('input[type="checkbox"]').prop('checked', !checked);
			if (!checked) {
				self.addAgentToConversation(self.currentConversationId, agentId);
			} else {
				self.removeAgentFromConversation(self.currentConversationId, agentId);
			}
		});
		$(document).on('click', '.whatsapp-multi-agent-item input[type="checkbox"]', function(e) {
			e.stopPropagation();
		});
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#whatsapp-multi-agent-dropdown, #btn-multi-agent-picker').length) {
				$('#whatsapp-multi-agent-dropdown').hide();
			}
		});
		// Remove agent from header badge
		$(document).on('click', '.whatsapp-remove-agent', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var agentId = parseInt($(this).data('agent-id'));
			if (self.currentConversationId && agentId > 0) {
				self.removeAgentFromConversation(self.currentConversationId, agentId);
			}
		});

		// ---- Voice recording events ----
		$(document).on('click', '#whatsapp-voice-btn', function() {
			self.startVoiceRecording();
		});
		$(document).on('click', '#whatsapp-voice-cancel', function() {
			self.cancelVoiceRecording();
		});
		$(document).on('click', '#whatsapp-voice-send', function() {
			self.stopAndSendVoiceRecording();
		});

		// ---- Transfer & Close events ----
		$(document).on('click', '#btn-transfer-conversation', function() {
			self.openTransferModal();
		});
		$(document).on('click', '#whatsapp-transfer-submit', function() {
			self.doTransfer();
		});
		$(document).on('click', '.whatsapp-transfer-modal-close', function() {
			$('#whatsapp-transfer-modal').hide();
		});
		$(document).on('click', '#btn-close-conversation', function() {
			self.openCloseModal();
		});
		$(document).on('click', '#whatsapp-close-submit', function() {
			self.doCloseConversation();
		});
		$(document).on('click', '.whatsapp-close-modal-close', function() {
			$('#whatsapp-close-modal').hide();
		});

		// ---- New Conversation ----
		$(document).on('click', '#whatsapp-new-conv-btn', function() {
			self.openNewConversationModal();
		});
		$(document).on('click', '.whatsapp-newconv-modal-close', function() {
			self.closeNewConversationModal();
		});
		$(document).on('click', '#whatsapp-newconv-modal', function(e) {
			if ($(e.target).is('#whatsapp-newconv-modal')) {
				self.closeNewConversationModal();
			}
		});
		$(document).on('click', '#whatsapp-newconv-send', function() {
			self.sendNewConversation();
		});

		// ---- Line filter ----
		$(document).on('change', '#whatsapp-line-filter-select', function() {
			self.currentLineId = parseInt($(this).val()) || 0;
			self.loadConversations(true);
			self.loadTemplates();
		});

		// ---- Tag events ----
		// Tag filter dropdown
		$(document).on('change', '#whatsapp-tag-filter-select', function() {
			self.filterTagId = parseInt($(this).val()) || 0;
			self.loadConversations();
		});

		// ---- Mine filter toggle ----
		$(document).on('click', '#whatsapp-filter-mine-btn', function() {
			self.filterMine = !self.filterMine;
			$(this).toggleClass('active', self.filterMine);
			self.loadConversations(true);
		});

		// ---- Unread filter toggle ----
		$(document).on('click', '#whatsapp-filter-unread-btn', function() {
			self.filterUnread = !self.filterUnread;
			$(this).toggleClass('active', self.filterUnread);
			self.loadConversations(true);
		});

		// ---- Assign to me ----
		$(document).on('click', '#btn-assign-to-me', function() {
			self.assignToMe();
		});

		// ---- Claim conversation ----
		$(document).on('click', '#whatsapp-claim-btn', function() {
			self.claimConversation();
		});

		// ---- Load more messages ----
		$(document).on('click', '#whatsapp-load-more-btn', function() {
			self.loadMoreMessages();
		});

		// ---- Search bar ----
		// Instant client-side DOM filter for immediate feedback,
		// then debounced server request after 400ms for full search
		$(document).on('input', '#whatsapp-search-input', function() {
			var term = $(this).val();
			self._filterConversationsBySearch(term); // instant visual feedback
			clearTimeout(self._searchDebounce);
			self._searchDebounce = setTimeout(function() {
				var newTerm = (term || '').trim();
				if (newTerm !== self._searchTerm) {
					self._searchTerm = newTerm;
					self.loadConversations(true);
				}
			}, 400);
		});

		// Open tag picker
		$(document).on('click', '#whatsapp-tag-add-btn', function(e) {
			e.stopPropagation();
			self.openTagPicker();
		});

		// Close tag picker on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#whatsapp-tag-picker, #whatsapp-tag-add-btn').length) {
				$('#whatsapp-tag-picker').hide();
			}
		});

		// Tag picker search
		$(document).on('input', '#whatsapp-tag-picker-search', function() {
			self.filterTagPicker($(this).val());
		});

		// Select tag from picker
		$(document).on('click', '.whatsapp-tag-picker-item', function() {
			var tagId = $(this).data('tag-id');
			self.assignTag(tagId);
		});

		// Create new tag from picker
		$(document).on('click', '#whatsapp-tag-picker-create-btn', function() {
			self.createAndAssignTag();
		});

		// Select color in picker
		$(document).on('click', '.whatsapp-tag-color-option', function() {
			$('.whatsapp-tag-color-option').removeClass('selected');
			$(this).addClass('selected');
		});

		// Remove tag from conversation (click X on tag pill)
		$(document).on('click', '.whatsapp-conv-tag-remove', function(e) {
			e.stopPropagation();
			var tagId = $(this).data('tag-id');
			self.unassignTag(tagId);
		});

		// Enter key in tag picker search to create
		$(document).on('keypress', '#whatsapp-tag-picker-search', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				var createArea = $('#whatsapp-tag-picker-create');
				if (createArea.is(':visible')) {
					self.createAndAssignTag();
				}
			}
		});

		// ---- Emoji Picker events ----
		// Toggle emoji picker on 😊 button
		$(document).on('click', '#whatsapp-emoji-btn', function(e) {
			e.preventDefault();
			e.stopPropagation();
			self.toggleEmojiPicker();
		});

		// Close emoji picker on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#whatsapp-emoji-picker, #whatsapp-emoji-btn').length) {
				self.hideEmojiPicker();
			}
		});

		// Search emojis
		$(document).on('input', '#whatsapp-emoji-search', function() {
			self.filterEmojis($(this).val());
		});

		// Select emoji
		$(document).on('click', '.whatsapp-emoji-item', function() {
			var emoji = $(this).data('emoji');
			self.insertEmoji(emoji);
		});

		// Emoji category click
		$(document).on('click', '.whatsapp-emoji-cat-btn', function() {
			var category = $(this).data('category');
			self.selectEmojiCategory(category);
		});

		// ---- Quick Reply events ----
		// Toggle quick reply picker on ⚡ button
		$(document).on('click', '#whatsapp-quick-reply-btn', function(e) {
			e.stopPropagation();
			self.toggleQuickReplyPicker();
		});

		// Close quick reply picker on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#whatsapp-qr-picker, #whatsapp-quick-reply-btn').length) {
				self.hideQuickReplyPicker();
			}
		});

		// Search in quick reply picker
		$(document).on('input', '#whatsapp-qr-picker-search', function() {
			self.filterQuickReplyPicker($(this).val());
		});

		// Select quick reply from picker
		$(document).on('click', '.whatsapp-qr-picker-item', function() {
			var content = $(this).data('content');
			self.insertQuickReply(content);
		});

		// Detect /shortcut in message input
		$(document).on('input', '#whatsapp-message-input', function() {
			var val = $(this).val();
			if (val.indexOf('/') === 0 && val.length > 1) {
				self.showQuickReplyPicker();
				self.filterQuickReplyPicker(val);
			} else if (self.qrPickerVisible && val.indexOf('/') !== 0) {
				self.hideQuickReplyPicker();
			}
		});
	},

	/**
	 * Load conversations list (debounced — M13)
	 * Calls are coalesced within 300ms to prevent parallel requests from SSE/polling/actions
	 */
	/**
	 * Initialize line filter dropdown from WhatsAppLines global
	 */
	initLineFilter: function() {
		var select = $('#whatsapp-line-filter-select');
		if (!select.length) return;
		if (typeof WhatsAppLines === 'undefined' || !WhatsAppLines.length) {
			// No lines — hide only the select, keep new-conv button visible
			select.hide();
			return;
		}
		if (WhatsAppLines.length <= 1) {
			// Single line — hide select, auto-set line
			select.hide();
			if (WhatsAppLines.length === 1) {
				this.currentLineId = WhatsAppLines[0].id;
			}
			return;
		}
		select.find('option:not(:first)').remove();
		for (var i = 0; i < WhatsAppLines.length; i++) {
			select.append('<option value="' + WhatsAppLines[i].id + '">' + this.escapeHtml(WhatsAppLines[i].label) + '</option>');
		}
	},

	loadConversations: function(forceFullReload) {
		var self = this;
		if (this._loadConversationsTimer) {
			clearTimeout(this._loadConversationsTimer);
		}
		this._loadConversationsTimer = setTimeout(function() {
			self._doLoadConversations(forceFullReload);
		}, 300);
	},

	/**
	 * Internal: actually load conversations from server
	 */
	_doLoadConversations: function(forceFullReload) {
		// M12: Prevent concurrent loads, but honour explicit filter/reload requests.
		// forceFullReload=true (e.g. Mine/Unread button) must always go through
		// even when a polling request is already in flight; abort that request.
		if (this.isLoadingConversations) {
			if (!forceFullReload) return;
			// Abort the flying XHR so it cannot overwrite our filtered results
			if (this._conversationsXhr) {
				this._conversationsXhr.abort();
				this._conversationsXhr = null;
			}
			this.isLoadingConversations = false;
		}
		this.isLoadingConversations = true;

		var self = this;
		var data = {};
		if (this.filterTagId > 0) {
			data.tag_id = this.filterTagId;
		}
		if (this.currentLineId > 0) {
			data.line_id = this.currentLineId;
		}
		if (this.filterMine && typeof WhatsAppCurrentUserId !== 'undefined' && WhatsAppCurrentUserId > 0) {
			data.user_id = WhatsAppCurrentUserId;
		}
		if (this.filterUnread) {
			data.unread_only = 1;
		}
		if (this._searchTerm) {
			data.search = this._searchTerm;
		}
		this._conversationsXhr = $.ajax({
			url: WhatsAppAjaxBase + 'ajax/conversations.php',
			method: 'GET',
			data: data,
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.renderConversations(data.conversations);
					self.lastConversationsTimestamp = Math.floor(Date.now() / 1000);
				} else {
					console.warn('[WhatsApp] conversations.php returned success:false', data.error || '');
				}
			},
			error: function(xhr) {
				// Aborted requests are not real errors — skip UI notification
				if (xhr.statusText === 'abort') return;
				console.error('[WhatsApp] Failed to load conversations. Status:', xhr.status, (xhr.responseText || '').substring(0, 300));
				// H33: Show inline retry message
				$('#whatsapp-conversations-list').html(
					'<div class="whatsapp-error-state">' +
					'<p>' + WhatsAppChat._t('ErrorLoadConversations') + '</p>' +
					'<button class="whatsapp-retry-btn" onclick="WhatsAppChat.loadConversations()">' + WhatsAppChat._t('Retry') + '</button>' +
					'</div>'
				);
			},
			complete: function() {
				self.isLoadingConversations = false;
				self._conversationsXhr = null;
			}
		});
	},

	/**
	 * Render conversations list — M14: incremental DOM updates
	 * Instead of full .html() rebuild, updates only changed items.
	 */
	renderConversations: function(conversations) {
		var container = $('#whatsapp-conversations-list');

		// Always remove the initial server-rendered loading placeholder
		container.find('.whatsapp-loading').remove();

		if (conversations.length === 0) {
			container.html('<div class="whatsapp-empty-state"><p>' + WhatsAppChat._t('NoConversations') + '</p></div>');
			return;
		}

		// Build a map of new conversation IDs for quick lookup
		var newIds = {};
		conversations.forEach(function(conv) {
			newIds[conv.rowid] = true;
		});

		// Remove items no longer in the list
		container.find('.whatsapp-conversation-item').each(function() {
			var id = $(this).data('conversation-id');
			if (!newIds[id]) {
				$(this).remove();
			}
		});

		// Remove empty state if present
		container.find('.whatsapp-empty-state').remove();

		// Update or insert each conversation
		var self = this;
		conversations.forEach(function(conv, index) {
			// Cache conversation data
			self.conversationsData[conv.rowid] = conv;

			var existing = container.find('.whatsapp-conversation-item[data-conversation-id="' + conv.rowid + '"]');
			var itemHtml = self._buildConversationItemHtml(conv);

			if (existing.length) {
				// Update only if content changed (compare inner HTML signature)
				var currentSig = existing.data('sig') || '';
				var newSig = conv.unread_count + '|' + (conv.last_message_date || '') + '|' + ((conv.assigned_agents || []).map(function(a){return a.id;}).join(',') || conv.agent_name || '') + '|' + ((conv.tags || []).length);
				if (currentSig !== newSig) {
					existing.replaceWith(itemHtml);
				} else {
					// Just update active state
					existing.toggleClass('active', conv.rowid == self.currentConversationId);
				}
			} else {
				// New conversation — insert at correct position
				var items = container.find('.whatsapp-conversation-item');
				if (index < items.length) {
					$(items[index]).before(itemHtml);
				} else {
					container.append(itemHtml);
				}
			}
		});

		// L2: Evict stale conversation cache entries beyond max limit
		var cachedIds = Object.keys(self.conversationsData);
		if (cachedIds.length > self._convCacheMax) {
			// Keep only the IDs present in current conversations + selected
			var keepIds = {};
			conversations.forEach(function(c) { keepIds[c.rowid] = true; });
			if (self.currentConversationId) keepIds[self.currentConversationId] = true;
			cachedIds.forEach(function(id) {
				if (!keepIds[id]) delete self.conversationsData[id];
			});
		}

		// Update data-sig attributes for change detection
		conversations.forEach(function(conv) {
			var el = container.find('.whatsapp-conversation-item[data-conversation-id="' + conv.rowid + '"]');
			var sig = conv.unread_count + '|' + (conv.last_message_date || '') + '|' + ((conv.assigned_agents || []).map(function(a){return a.id;}).join(',') || conv.agent_name || '') + '|' + ((conv.tags || []).length);
			el.data('sig', sig);
		});
	},

	/**
	 * Build HTML for a single conversation item
	 */
	_buildConversationItemHtml: function(conv) {
		var activeClass = (conv.rowid == this.currentConversationId) ? 'active' : '';
		var unreadBadge = conv.unread_count > 0 ?
			'<span class="whatsapp-unread-badge">' + conv.unread_count + '</span>' : '';

		// Multi-agent badges
		var agentBadge = '';
		if (conv.assigned_agents && conv.assigned_agents.length > 0) {
			var agentNames = conv.assigned_agents.map(function(a) { return a.name; });
			var displayName = agentNames.length <= 2 ? agentNames.join(', ') : agentNames[0] + ' +' + (agentNames.length - 1);
			agentBadge = '<span class="whatsapp-agent-badge" title="' + this.escapeHtml(agentNames.join(', ')) + '">&#x1F464; ' + this.escapeHtml(displayName) + '</span>';
		} else if (conv.agent_name) {
			agentBadge = '<span class="whatsapp-agent-badge" title="' + this.escapeHtml(conv.agent_name) + '">&#x1F464; ' + this.escapeHtml(conv.agent_name) + '</span>';
		}

		// Multi-line: show line label badge if multiple lines exist
		var lineBadge = '';
		if (typeof WhatsAppLines !== 'undefined' && WhatsAppLines.length > 1 && conv.fk_line) {
			for (var li = 0; li < WhatsAppLines.length; li++) {
				if (WhatsAppLines[li].id == conv.fk_line) {
					lineBadge = '<span class="whatsapp-line-badge" title="' + this.escapeHtml(WhatsAppLines[li].label) + '">' + this.escapeHtml(WhatsAppLines[li].label) + '</span>';
					break;
				}
			}
		}

		var tagPills = '';
		if (conv.tags && conv.tags.length > 0) {
			conv.tags.forEach(function(tag) {
				var isDark = WhatsAppChat.isLightColor(tag.color) ? '1' : '0';
				tagPills += '<span class="whatsapp-tag-pill" data-tag-dark="' + isDark + '" style="background:' + WhatsAppChat.escapeHtml(tag.color) + '20; color:' + WhatsAppChat.escapeHtml(tag.color) + '; border-color:' + WhatsAppChat.escapeHtml(tag.color) + '40;">' + WhatsAppChat.escapeHtml(tag.label) + '</span>';
			});
		}

		// Build avatar with initials
		var displayName = conv.contact_name || conv.phone_number || '?';
		var initials = this._getInitials(displayName);
		var avatarColor = this._hashAvatarColor(displayName);

		// Window expiry warning (< 2 hours left)
		var expiryWarning = '';
		if (conv.window_expires_at) {
			var now = Math.floor(Date.now() / 1000);
			var secsLeft = conv.window_expires_at - now;
			if (secsLeft > 0 && secsLeft < 7200) {
				var minsLeft = Math.floor(secsLeft / 60);
				expiryWarning = '<span class="whatsapp-expiry-warning" title="' + this._t('WindowExpiresIn') + ' ' + minsLeft + ' min">⏰</span>';
			}
		}

		var sig = (conv.unread_count || 0) + '|' + (conv.last_message_date || '') + '|' + ((conv.assigned_agents || []).map(function(a){return a.id;}).join(',') || conv.agent_name || '') + '|' + ((conv.tags || []).length);
		var html = '<div class="whatsapp-conversation-item ' + activeClass + '" data-conversation-id="' + conv.rowid + '" data-sig="' + this.escapeHtml(sig) + '">';
		html += '  <div class="whatsapp-avatar avatar-color-' + avatarColor + '">' + this.escapeHtml(initials) + '</div>';
		html += '  <div class="whatsapp-conversation-content">';
		html += '    <div class="whatsapp-conversation-header">';
		html += '      <span class="whatsapp-conversation-name">' + this.escapeHtml(conv.contact_name || conv.phone_number) + unreadBadge + '</span>';
		html += '      <span class="whatsapp-conversation-time">' + expiryWarning + this.formatDateLabel(conv.last_message_date) + '</span>';
		html += '    </div>';
		if (tagPills) {
			html += '    <div class="whatsapp-conversation-tags">' + tagPills + '</div>';
		}
		html += '    <div class="whatsapp-conversation-preview">';
		html += lineBadge + agentBadge + this.escapeHtml(conv.last_message_preview || '');
		html += '    </div>';
		html += '  </div>';
		html += '</div>';
		return html;
	},

	/**
	 * Select conversation
	 */
	selectConversation: function(conversationId) {
		this.currentConversationId = conversationId;
		$('.whatsapp-conversation-item').removeClass('active');
		$('.whatsapp-conversation-item[data-conversation-id="' + conversationId + '"]').addClass('active');
		// Instantly clear unread badge in cache and DOM (before server confirms)
		if (this.conversationsData[conversationId]) {
			this.conversationsData[conversationId].unread_count = 0;
		}
		$('.whatsapp-conversation-item[data-conversation-id="' + conversationId + '"] .whatsapp-unread-badge').remove();
		$('#whatsapp-chat-header-actions').show();
		// Reset message pagination for this conversation
		this.messagesOffset = 0;
		this.messagesHasMore = false;
		// H35: On mobile, switch to chat panel
		$('#whatsapp-chat-container').addClass('whatsapp-mobile-show-chat');
		this.loadMessages(conversationId, true); // always scroll to bottom when switching conversations
		// Reload templates for the conversation's line
		this.loadTemplates();
		// Load CSAT info for this conversation
		this.loadCSATInfo(conversationId);
	},

	/**
	 * Load messages for conversation
	 */
	loadMessages: function(conversationId, forceScroll) {
		// M12: Prevent concurrent message loads
		if (this.isLoadingMessages) return;
		this.isLoadingMessages = true;
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/messages.php',
			method: 'GET',
			data: { conversation_id: conversationId, offset: this.messagesOffset },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					WhatsAppChat.messagesHasMore = data.has_more || false;
					WhatsAppChat.renderMessages(data.messages, forceScroll !== false);
					WhatsAppChat.checkWindowStatus(data.conversation);
				}
			},
			error: function() {
				console.warn('[WhatsApp] Failed to load messages');
				// H33: Show inline retry message
				$('#whatsapp-messages-area').html(
					'<div class="whatsapp-error-state">' +
					'<p>' + WhatsAppChat._t('ErrorLoadMessages') + '</p>' +
					'<button class="whatsapp-retry-btn" onclick="WhatsAppChat.loadMessages(' + self.currentConversationId + ')">' + WhatsAppChat._t('Retry') + '</button>' +
					'</div>'
				);
			},
			complete: function() {
				self.isLoadingMessages = false;
			}
		});
	},

	/**
	 * Render messages
	 */
	renderMessages: function(messages, forceScroll) {
		var html = '';
		
		if (messages.length === 0) {
			html = '<div class="whatsapp-empty-state"><p>' + WhatsAppChat._t('NoMessages') + '</p></div>';
		} else {
			var lastDateKey = '';
			messages.forEach(function(msg) {
				// Insert date separator when the day changes
				var dateKey = WhatsAppChat._getDateKey(msg.timestamp);
				if (dateKey && dateKey !== lastDateKey) {
					lastDateKey = dateKey;
					html += '<div class="whatsapp-date-separator"><span>' + WhatsAppChat._getDateLabel(msg.timestamp) + '</span></div>';
				}
				var direction = msg.direction;
				var statusIcon = WhatsAppChat.getStatusIcon(msg.status);

				// System messages (transfer, close, etc.) — render as centered notification
				if (direction === 'system') {
					var sysText = WhatsAppChat.escapeHtml(msg.content || '').replace(/\n/g, '<br>');
					html += '<div class="whatsapp-message system">';
					html += '  <div class="whatsapp-system-message">';
					html += '    <span class="whatsapp-system-text">' + sysText + '</span>';
					html += '    <span class="whatsapp-system-time">' + WhatsAppChat.formatTime(msg.timestamp) + '</span>';
					html += '  </div>';
					html += '</div>';
					return; // skip normal bubble rendering
				}
				
				html += '<div class="whatsapp-message ' + direction + '">';
				html += '  <div class="whatsapp-message-bubble">';
				
				// Render media content
				html += WhatsAppChat.renderMediaContent(msg);
				
				// Text content (caption for media, or full content for text)
				var textContent = msg.content || '';
				if (msg.message_type === 'image' || msg.message_type === 'video') {
					// For outbound media, strip the preview prefix (e.g. "📷 Imagen: ")
					if (direction === 'outbound' && textContent) {
						var colonIdx = textContent.indexOf(': ');
						if (colonIdx >= 0 && colonIdx < 15) {
							textContent = textContent.substring(colonIdx + 2);
						}
					}
					// For image/video, content is caption - show below media
					if (textContent) {
						html += '<p class="whatsapp-message-text">' + WhatsAppChat.escapeHtml(textContent) + '</p>';
					}
				} else if (msg.message_type === 'audio') {
					// Audio has no text content
				} else if (msg.message_type === 'document') {
					// Document shows filename in the document widget
				} else {
					// Text / template messages
					html += '<p class="whatsapp-message-text">' + WhatsAppChat.escapeHtml(textContent) + '</p>';
				}
				
				html += '    <div class="whatsapp-message-meta">';
				html += '      <span class="whatsapp-message-time">' + WhatsAppChat.formatTime(msg.timestamp) + '</span>';
				if (direction === 'outbound') {
					var statusLabel = WhatsAppChat.getStatusLabel(msg.status);
					var errorTitle = (msg.status === 'failed' && msg.error_message) ? ' title="' + WhatsAppChat.escapeAttr(msg.error_message) + '"' : '';
					html += '      <span class="whatsapp-message-status ' + msg.status + '" aria-label="' + statusLabel + '"' + errorTitle + '>' + statusIcon + '</span>';
				}
				html += '    </div>';
				html += '  </div>';
				html += '</div>';
			});
		}
		
		$('#whatsapp-messages-area').html(
			(this.messagesHasMore ? '<div class="whatsapp-load-more-container"><button id="whatsapp-load-more-btn" class="whatsapp-load-more-btn">' + this._t('LoadMoreMessages') + '</button></div>' : '') +
			html
		);
		if (forceScroll !== false) {
			this.scrollToBottom();
		} else {
			this._scrollToBottomIfAtBottom();
		}
	},

	/**
	 * Render media content for a message
	 * @param {object} msg Message object
	 * @return {string} HTML
	 */
	renderMediaContent: function(msg) {
		if (!msg.message_type || msg.message_type === 'text') {
			return '';
		}

		// Template messages with media — render image
		if (msg.message_type === 'template') {
			if (msg.media_serve_url) {
				var html = '<div class="whatsapp-media-image">';
				html += '<img src="' + WhatsAppChat.escapeAttr(msg.media_serve_url) + '" alt="Template image" loading="lazy" onclick="window.open(this.src, \'_blank\')" onerror="WhatsAppChat.handleMediaError(this)" />';
				html += '</div>';
				return html;
			}
			return '';
		}

		// Contacts messages
		if (msg.message_type === 'contacts') {
			var html = '<div class="whatsapp-media-contacts">';
			try {
				var contacts = JSON.parse(msg.content || '[]');
				for (var i = 0; i < contacts.length; i++) {
					var c = contacts[i];
					var name = (c.name && c.name.formatted_name) ? c.name.formatted_name : 'Contact';
					html += '<div class="whatsapp-contact-card">';
					html += '<span class="whatsapp-contact-icon">&#128100;</span>';
					html += '<div class="whatsapp-contact-info">';
					html += '<strong>' + WhatsAppChat.escapeHtml(name) + '</strong>';
					if (c.phones && c.phones.length) {
						for (var j = 0; j < c.phones.length; j++) {
							html += '<div class="whatsapp-contact-phone">&#128222; ' + WhatsAppChat.escapeHtml(c.phones[j].phone || c.phones[j].wa_id || '') + '</div>';
						}
					}
					html += '</div></div>';
				}
			} catch (e) {
				html += '<span>&#128100; ' + WhatsAppChat.escapeHtml(msg.content || '') + '</span>';
			}
			html += '</div>';
			return html;
		}

		// Location messages
		if (msg.message_type === 'location') {
			var html = '<div class="whatsapp-media-location">';
			try {
				var loc = JSON.parse(msg.content || '{}');
				var lat = loc.latitude || '';
				var lon = loc.longitude || '';
				var locName = loc.name || '';
				var locAddr = loc.address || '';
				if (lat && lon) {
					var mapsUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon);
					html += '<a href="' + WhatsAppChat.escapeAttr(mapsUrl) + '" target="_blank" class="whatsapp-location-link">';
					html += '<span class="whatsapp-location-icon">&#128205;</span>';
					html += '<div class="whatsapp-location-details">';
					if (locName) html += '<strong>' + WhatsAppChat.escapeHtml(locName) + '</strong>';
					if (locAddr) html += '<div>' + WhatsAppChat.escapeHtml(locAddr) + '</div>';
					html += '<div class="whatsapp-location-coords">' + WhatsAppChat.escapeHtml(lat + ', ' + lon) + '</div>';
					html += '</div></a>';
				}
			} catch (e) {
				html += '<span>&#128205; ' + WhatsAppChat.escapeHtml(msg.content || '') + '</span>';
			}
			html += '</div>';
			return html;
		}

		var html = '';
		var serveUrl = WhatsAppChat.escapeAttr(msg.media_serve_url || '');
		var downloadUrl = WhatsAppChat.escapeAttr(msg.media_download_url || '');
		var filename = msg.media_filename || '';

		switch (msg.message_type) {
			case 'image':
				if (serveUrl) {
					html += '<div class="whatsapp-media-image">';
					html += '<img src="' + serveUrl + '" alt="' + WhatsAppChat.escapeHtml(filename) + '" loading="lazy" onclick="window.open(this.src, \'_blank\')" onerror="WhatsAppChat.handleMediaError(this)" />';
					html += '</div>';
				} else {
					console.warn('[WhatsAppDati] No media_serve_url for image message rowid:', msg.rowid, 'direction:', msg.direction);
					html += '<div class="whatsapp-media-placeholder">[Image not available]</div>';
				}
				break;

			case 'video':
				if (serveUrl) {
					html += '<div class="whatsapp-media-video">';
					html += '<video controls preload="metadata">';
					html += '<source src="' + serveUrl + '" type="' + (msg.media_mime_type || 'video/mp4') + '">';
					html += WhatsAppChat._t('BrowserNoVideo');
					html += '</video>';
					html += '</div>';
				} else {
					html += '<div class="whatsapp-media-placeholder">' + WhatsAppChat._t('VideoPlaceholder') + '</div>';
				}
				break;

			case 'audio':
				if (serveUrl) {
					html += '<div class="whatsapp-media-audio">';
					html += '<audio controls preload="metadata">';
					html += '<source src="' + serveUrl + '" type="' + (msg.media_mime_type || 'audio/ogg') + '">';
					html += WhatsAppChat._t('BrowserNoAudio');
					html += '</audio>';
					html += '</div>';
				} else {
					html += '<div class="whatsapp-media-placeholder">' + WhatsAppChat._t('AudioPlaceholder') + '</div>';
				}
				break;

			case 'document':
				html += '<div class="whatsapp-media-document">';
				html += '<div class="whatsapp-media-doc-icon">' + WhatsAppChat.getDocIcon(msg.media_mime_type) + '</div>';
				html += '<div class="whatsapp-media-doc-info">';
				html += '<span class="whatsapp-media-doc-name">' + WhatsAppChat.escapeHtml(filename || WhatsAppChat._t('Document')) + '</span>';
				html += '</div>';
				if (downloadUrl) {
					html += '<a href="' + downloadUrl + '" class="whatsapp-media-doc-download" title="' + WhatsAppChat._t('Download') + '">⬇</a>';
				}
				html += '</div>';
				break;
		}

		return html;
	},

	/**
	 * Get document icon by MIME type
	 */
	getDocIcon: function(mimeType) {
		if (!mimeType) return '📄';
		if (mimeType.indexOf('pdf') >= 0) return '📕';
		if (mimeType.indexOf('word') >= 0 || mimeType.indexOf('msword') >= 0) return '📘';
		if (mimeType.indexOf('excel') >= 0 || mimeType.indexOf('spreadsheet') >= 0) return '📗';
		if (mimeType.indexOf('powerpoint') >= 0 || mimeType.indexOf('presentation') >= 0) return '📙';
		return '📄';
	},

	/**
	 * Handle media loading error (image onerror callback)
	 * @param {HTMLElement} imgEl The img element that failed to load
	 */
	handleMediaError: function(imgEl) {
		var src = imgEl.src || '';
		console.error('[WhatsAppDati] Media load FAILED:', src);
		// Try to get HTTP status for debugging
		if (src) {
			var xhr = new XMLHttpRequest();
			xhr.open('HEAD', src, true);
			xhr.onload = function() {
				console.error('[WhatsAppDati] Media HEAD response:', xhr.status, xhr.statusText);
			};
			xhr.onerror = function() {
				console.error('[WhatsAppDati] Media HEAD request failed (network error)');
			};
			xhr.send();
		}
		// Replace with placeholder
		if (imgEl.parentNode) {
			imgEl.parentNode.innerHTML = '<div class="whatsapp-media-placeholder">[Image not available]</div>';
		}
	},

	/**
	 * Send text message
	 */
	sendMessage: function() {
		var message = $('#whatsapp-message-input').val().trim();
		
		if (!message || !this.currentConversationId) {
			return;
		}

		$('#whatsapp-send-btn').prop('disabled', true);
		$('#whatsapp-message-input').prop('disabled', true);

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/send_message.php',
			method: 'POST',
			data: {
				conversation_id: this.currentConversationId,
				message: message,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#whatsapp-message-input').val('');
					WhatsAppChat.loadMessages(WhatsAppChat.currentConversationId);
				} else {
					var errMsg = WhatsAppChat._t('ErrorPrefix') + data.error;
					if (data.debug) errMsg += "\n\nDEBUG:\n" + JSON.stringify(data.debug, null, 2);
					alert(errMsg);
				}
			},
			error: function(jqXHR) {
				alert(WhatsAppChat._t('NetworkErrorSend') + ' (' + jqXHR.status + ')');
			},
			complete: function() {
				$('#whatsapp-send-btn').prop('disabled', false);
				$('#whatsapp-message-input').prop('disabled', false).focus();
			}
		});
	},

	/**
	 * Check conversation window status
	 */
	checkWindowStatus: function(conversation) {
		var now = Math.floor(Date.now() / 1000);
		var windowExpires = conversation.window_expires_at;

		// Update header
		$('#whatsapp-chat-title').text(conversation.contact_name || conversation.phone_number);
		$('#whatsapp-chat-subtitle').text(conversation.phone_number);

		// Update assign dropdown (legacy single-select)
		if (conversation.fk_user_assigned) {
			$('#whatsapp-assign-select').val(conversation.fk_user_assigned);
		} else {
			$('#whatsapp-assign-select').val('0');
		}

		// Render multi-agent badges in header
		this.renderAssignedAgents(conversation.assigned_agents || []);

		// Show/hide claim button for unassigned conversations
		if (!conversation.fk_user_assigned) {
			$('#whatsapp-claim-btn').show();
			$('.whatsapp-assign-area').hide();
		} else {
			$('#whatsapp-claim-btn').hide();
			$('.whatsapp-assign-area').show();
		}

		// Update conversation tags in header
		this.renderConversationTags(conversation.tags || []);

		// Update CRM area
		WhatsAppCRM.updateCrmState(conversation);
		
		if (windowExpires && windowExpires < now) {
			$('#whatsapp-window-warning').show();
			$('#whatsapp-input-area').hide();
			$('#whatsapp-template-selector').show();
		} else {
			$('#whatsapp-window-warning').hide();
			$('#whatsapp-input-area').show();
			$('#whatsapp-template-selector').hide();
		}
	},

	// ==========================================
	// Template Modal
	// ==========================================

	/**
	 * Open the template variables modal
	 * @param {int} templateId
	 */
	openTemplateModal: function(templateId) {
		var self = this;

		// Check cache first
		if (this.templateCache[templateId]) {
			this._renderModal(this.templateCache[templateId]);
			return;
		}

		// L1: Evict oldest cache entry if limit reached
		if (this._templateCacheKeys.length >= this._templateCacheMax) {
			var oldest = this._templateCacheKeys.shift();
			delete this.templateCache[oldest];
		}

		// Fetch template detail
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/template_detail.php',
			method: 'GET',
			data: { id: templateId },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.templateCache[templateId] = data.template;
					self._templateCacheKeys.push(templateId); // L1: track insertion order
					self._renderModal(data.template);
				} else {
					var errMsg = WhatsAppChat._t('ErrorPrefix') + data.error;
					if (data.debug) errMsg += "\n\nDEBUG:\n" + JSON.stringify(data.debug, null, 2);
					alert(errMsg);
				}
			},
			error: function() {
				alert(WhatsAppChat._t('ErrorLoadingTemplate'));
			}
		});
	},

	/**
	 * Render the modal content with template data
	 * @param {object} template
	 */
	_renderModal: function(template) {
		// Set title
		$('#whatsapp-modal-title').text(template.name + ' (' + template.language + ')');

		// Set preview
		if (template.header_content) {
			$('#whatsapp-preview-header').text(template.header_content).show();
		} else {
			$('#whatsapp-preview-header').hide();
		}

		$('#whatsapp-preview-body').text(template.body_text || '');

		if (template.footer_text) {
			$('#whatsapp-preview-footer').text(template.footer_text).show();
		} else {
			$('#whatsapp-preview-footer').hide();
		}

		// Build variable input fields with auto-resolve support
		var variablesHtml = '';
		var variables = template.variables || [];
		var varMapping = template.variable_mapping || {};
		var conv = this.conversationsData[this.currentConversationId] || {};
		var operatorName = (typeof WhatsAppCurrentUserName !== 'undefined') ? WhatsAppCurrentUserName : '';
		var today = new Date().toLocaleDateString();

		if (variables.length > 0) {
			variablesHtml += '<div class="whatsapp-var-section-title">' + WhatsAppChat._t('TemplateVariables') + '</div>';
			for (var i = 0; i < variables.length; i++) {
				var varNum = variables[i];
				var cfg = varMapping[varNum] || { type: 'free_text', label: '', default_value: '' };
				var autoValue = '';
				var isAutoResolved = false;
				var sourceLabel = cfg.label || '';

				// Auto-resolve based on type
				switch (cfg.type) {
					case 'contact_name':
						autoValue = conv.contact_name || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeContactName') || 'Contact Name';
						break;
					case 'operator_name':
						autoValue = operatorName;
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeOperatorName') || 'Operator Name';
						break;
					case 'company_name':
						autoValue = conv.company_name || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeCompanyName') || 'Company Name';
						break;
					case 'phone':
						autoValue = conv.phone_number || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypePhone') || 'Phone';
						break;
					case 'date_today':
						autoValue = today;
						isAutoResolved = true;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeDateToday') || 'Date';
						break;
					case 'fixed_text':
						autoValue = cfg.default_value || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeFixedText') || 'Fixed Text';
						break;
					case 'url':
						autoValue = cfg.default_value || '';
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeUrl') || 'URL';
						break;
					case 'free_text':
					default:
						autoValue = cfg.default_value || '';
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeFreeText') || 'Text';
						break;
				}

				variablesHtml += '<div class="whatsapp-var-field">';
				variablesHtml += '  <label class="whatsapp-var-label">{{' + varNum + '}} <span style="font-weight:normal;color:#888;font-size:11px;">(' + this.escapeHtml(sourceLabel) + ')</span></label>';

				if (isAutoResolved && cfg.type !== 'free_text' && cfg.type !== 'url') {
					// Auto-resolved: show as read-only with a badge
					variablesHtml += '  <div style="display:flex;align-items:center;gap:8px;">';
					variablesHtml += '    <input type="text" class="whatsapp-var-input" data-var-num="' + varNum + '" data-auto-resolved="1" value="' + this.escapeHtml(autoValue) + '" readonly style="background:#f0fdf4;border-color:#86efac;color:#166534;" />';
					variablesHtml += '    <span style="font-size:11px;color:#16a34a;white-space:nowrap;" title="' + WhatsAppChat._t('AutoResolved') + '">✓ ' + WhatsAppChat._t('Auto') + '</span>';
					variablesHtml += '  </div>';
				} else {
					// Manual input needed
					variablesHtml += '  <input type="text" class="whatsapp-var-input" data-var-num="' + varNum + '" value="' + this.escapeHtml(autoValue) + '" placeholder="' + WhatsAppChat._t('ValueForVar', varNum) + '" />';
				}

				variablesHtml += '</div>';
			}
		} else {
			variablesHtml = '<p class="whatsapp-var-no-vars">' + WhatsAppChat._t('NoVariables') + '</p>';
		}

		// Header image upload (when header_image_mode is 'on_send' and header_type is IMAGE/VIDEO/DOCUMENT)
		var headerUploadHtml = '';
		if (['IMAGE', 'VIDEO', 'DOCUMENT'].indexOf(template.header_type) !== -1 && template.header_image_mode === 'on_send') {
			headerUploadHtml += '<div class="whatsapp-header-upload" style="margin-bottom:12px;padding:10px;background:#f0f9ff;border:1px dashed #7dd3fc;border-radius:8px;">';
			headerUploadHtml += '  <label style="display:block;font-size:12px;font-weight:600;color:#0369a1;margin-bottom:6px;">📷 ' + WhatsAppChat._t('HeaderImage') + '</label>';
			headerUploadHtml += '  <input type="file" id="whatsapp-header-image-input" accept="image/*,video/*,application/pdf" style="font-size:12px;" />';
			headerUploadHtml += '  <div style="font-size:11px;color:#888;margin-top:4px;">' + WhatsAppChat._t('HeaderImageOnSendHelp') + '</div>';
			headerUploadHtml += '</div>';
		}

		$('#whatsapp-template-variables').html(headerUploadHtml + variablesHtml);

		// Store current template ID on the modal
		$('#whatsapp-template-modal').data('template-id', template.id);
		$('#whatsapp-template-modal').data('template-name', template.name);
		$('#whatsapp-template-modal').data('template-body', template.body_text);
		$('#whatsapp-template-modal').data('template-language', template.language);

		// Show modal
		$('#whatsapp-template-modal').fadeIn(200);

		// Focus first variable field
		setTimeout(function() {
			$('.whatsapp-var-input:first').focus();
		}, 250);
	},

	/**
	 * Update preview with current variable values (live)
	 */
	updateTemplatePreview: function() {
		var body = $('#whatsapp-template-modal').data('template-body') || '';
		
		$('.whatsapp-var-input').each(function() {
			var varNum = $(this).data('var-num');
			var value = $(this).val();
			if (value) {
				body = body.replace(new RegExp('\\{\\{' + varNum + '\\}\\}', 'g'), value);
			}
		});

		$('#whatsapp-preview-body').text(body);
	},

	/**
	 * Close the template modal
	 */
	closeTemplateModal: function() {
		$('#whatsapp-template-modal').fadeOut(200);
		$('#whatsapp-modal-error').hide();
		$('#whatsapp-modal-error-debug').hide().text('');
	},

	_showModalError: function(errText, debugObj) {
		$('#whatsapp-modal-error-msg').text(errText);
		if (debugObj) {
			$('#whatsapp-modal-error-debug').text(JSON.stringify(debugObj, null, 2)).show();
		} else {
			$('#whatsapp-modal-error-debug').hide().text('');
		}
		$('#whatsapp-modal-error').show();
		$('#whatsapp-modal-send').prop('disabled', false).text(WhatsAppChat._t('Send'));
	},

	/**
	 * Send the template with variables from the modal
	 */
	sendTemplateFromModal: function() {
		var templateId = $('#whatsapp-template-modal').data('template-id');
		
		if (!templateId || !this.currentConversationId) {
			return;
		}

		// Collect variable values in order
		var params = [];
		var valid = true;
		$('.whatsapp-var-input').each(function() {
			var val = $(this).val().trim();
			if (!val) {
				$(this).addClass('whatsapp-var-error');
				$(this).attr('aria-invalid', 'true');
				valid = false;
			} else {
				$(this).removeClass('whatsapp-var-error');
				$(this).removeAttr('aria-invalid');
			}
			params.push(val);
		});

		// If no variables exist, params stays empty (that's fine)
		if (!valid) {
			return;
		}

		// Disable send button
		$('#whatsapp-modal-send').prop('disabled', true).text(WhatsAppChat._t('Sending'));

		// Check for header image upload
		var headerImageFile = null;
		var fileInput = document.getElementById('whatsapp-header-image-input');
		if (fileInput && fileInput.files && fileInput.files.length > 0) {
			headerImageFile = fileInput.files[0];
		}

		// Use FormData if we have a header image to upload
		if (headerImageFile) {
			var formData = new FormData();
			formData.append('conversation_id', this.currentConversationId);
			formData.append('template_id', templateId);
			formData.append('template_params', JSON.stringify(params));
			formData.append('token', $('input[name="token"]').val());
			formData.append('header_image', headerImageFile);

			$.ajax({
				url: WhatsAppAjaxBase + 'ajax/send_message.php',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						WhatsAppChat.closeTemplateModal();
						$('#whatsapp-template-select').val('');
						WhatsAppChat.loadMessages(WhatsAppChat.currentConversationId);
						WhatsAppChat.loadConversations();
					} else {
						WhatsAppChat._showModalError(data.error, data.debug || null);
					}
				},
				error: function() {
					WhatsAppChat._showModalError(WhatsAppChat._t('ConnectionError'), null);
				},
				complete: function() { }
			});
		} else {
			$.ajax({
				url: WhatsAppAjaxBase + 'ajax/send_message.php',
				method: 'POST',
				data: {
					conversation_id: this.currentConversationId,
					template_id: templateId,
					template_params: JSON.stringify(params),
					token: $('input[name="token"]').val()
				},
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						WhatsAppChat.closeTemplateModal();
						$('#whatsapp-template-select').val('');
						WhatsAppChat.loadMessages(WhatsAppChat.currentConversationId);
						WhatsAppChat.loadConversations();
					} else {
						WhatsAppChat._showModalError(data.error, data.debug || null);
					}
				},
				error: function() {
					WhatsAppChat._showModalError(WhatsAppChat._t('ConnectionError'), null);
				},
				complete: function() { }
			});
		}
	},

	// ==========================================
	// File Upload & Media
	// ==========================================

	/**
	 * Show file preview before sending
	 * @param {File} file
	 */
	showFilePreview: function(file) {
		this.selectedFile = file;

		// Determine icon
		var icon = '📄';
		if (file.type.startsWith('image/')) icon = '📷';
		else if (file.type.startsWith('video/')) icon = '🎬';
		else if (file.type.startsWith('audio/')) icon = '🎵';
		else if (file.type.indexOf('pdf') >= 0) icon = '📕';

		$('#whatsapp-file-preview-icon').text(icon);
		$('#whatsapp-file-preview-name').text(file.name);
		$('#whatsapp-file-preview-size').text(this.formatFileSize(file.size));

		// Show preview area, hide regular input
		$('#whatsapp-input-area').hide();
		$('#whatsapp-file-preview').show();
		$('#whatsapp-caption-input').val('').focus();
	},

	/**
	 * Clear file preview and return to normal input
	 */
	clearFilePreview: function() {
		this.selectedFile = null;
		$('#whatsapp-file-input').val('');
		$('#whatsapp-file-preview').hide();
		$('#whatsapp-input-area').show();
		$('#whatsapp-message-input').focus();
	},

	/**
	 * Send the selected media file
	 */
	sendMediaMessage: function() {
		if (!this.selectedFile || !this.currentConversationId) {
			return;
		}

		var file = this.selectedFile;
		var caption = $('#whatsapp-caption-input').val().trim();

		// Build FormData
		var formData = new FormData();
		formData.append('media_file', file);
		formData.append('conversation_id', this.currentConversationId);
		formData.append('token', $('input[name="token"]').val());
		if (caption) {
			formData.append('caption', caption);
		}

		// Disable send button
		$('#whatsapp-send-file-btn').prop('disabled', true).text(WhatsAppChat._t('Sending'));

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/upload_media.php',
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					WhatsAppChat.clearFilePreview();
					WhatsAppChat.loadMessages(WhatsAppChat.currentConversationId);
					WhatsAppChat.loadConversations();
				} else {
					var errMsg = WhatsAppChat._t('ErrorPrefix') + data.error;
					if (data.debug) errMsg += "\n\nDEBUG:\n" + JSON.stringify(data.debug, null, 2);
					alert(errMsg);
				}
			},
			error: function() {
				alert(WhatsAppChat._t('ConnectionError'));
			},
			complete: function() {
				$('#whatsapp-send-file-btn').prop('disabled', false).text(WhatsAppChat._t('Send'));
			}
		});
	},

	/**
	 * Format file size for display
	 * @param {number} bytes
	 * @return {string}
	 */
	formatFileSize: function(bytes) {
		if (bytes === 0) return '0 B';
		var k = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
	},

	// ==========================================
	// Voice Recording
	// ==========================================

	/**
	 * Start voice recording using MediaRecorder API
	 */
	startVoiceRecording: function() {
		var self = this;

		if (!this.currentConversationId) return;

		// Check browser support
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			alert(this._t('MicNotSupported'));
			return;
		}

		// Prevent double start
		if (this._voiceRecording) return;

		// Request microphone access
		navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
			self._voiceStream = stream;
			self._voiceChunks = [];
			self._voiceRecording = true;

			// Choose best supported MIME type for voice recording
			// All formats will be converted to ogg/opus on server via ffmpeg
			// Priority: ogg (Firefox, no conversion needed) > webm (Chrome, fast convert) > mp4
			var mimeType = '';
			var candidates = [
				'audio/ogg;codecs=opus',    // Firefox — WhatsApp native, no conversion needed
				'audio/ogg',                 // Firefox alt
				'audio/webm;codecs=opus',    // Chrome — needs ffmpeg convert to ogg
				'audio/webm',                // Chrome fallback
				'audio/mp4;codecs=opus',     // Chrome 124+ / Safari — needs ffmpeg convert
				'audio/mp4',                 // Safari — needs ffmpeg convert
				'audio/mpeg'                 // Unlikely fallback
			];
			if (typeof MediaRecorder.isTypeSupported === 'function') {
				for (var ci = 0; ci < candidates.length; ci++) {
					if (MediaRecorder.isTypeSupported(candidates[ci])) {
						mimeType = candidates[ci];
						break;
					}
				}
			}
			console.log('[WhatsAppDati] Voice MIME selected:', mimeType || '(browser default)');

			var options = {};
			if (mimeType) options.mimeType = mimeType;

			try {
				self._voiceRecorder = new MediaRecorder(stream, options);
			} catch (e) {
				console.warn('[WhatsAppDati] MediaRecorder error with mimeType, retrying default:', e);
				self._voiceRecorder = new MediaRecorder(stream);
			}

			self._voiceRecorder.ondataavailable = function(e) {
				if (e.data && e.data.size > 0) {
					self._voiceChunks.push(e.data);
				}
			};

			self._voiceRecorder.onstop = function() {
				// Handled in stopAndSendVoiceRecording
			};

			self._voiceRecorder.onerror = function(e) {
				console.error('[WhatsAppDati] MediaRecorder error:', e);
				self.cancelVoiceRecording();
			};

			self._voiceRecorder.start(250); // Collect data every 250ms

			// Show recording UI
			$('#whatsapp-input-area').hide();
			$('#whatsapp-voice-recording').show();
			self._voiceStartTime = Date.now();
			self._updateVoiceTimer();
			self._voiceTimerInterval = setInterval(function() {
				self._updateVoiceTimer();
			}, 1000);

			console.log('[WhatsAppDati] Voice recording started, mimeType:', self._voiceRecorder.mimeType);

		}).catch(function(err) {
			console.error('[WhatsAppDati] getUserMedia error:', err);
			if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
				alert(self._t('MicNotAllowed'));
			} else {
				alert(self._t('MicNotSupported'));
			}
		});
	},

	/**
	 * Update the voice recording timer display
	 */
	_updateVoiceTimer: function() {
		var elapsed = Math.floor((Date.now() - this._voiceStartTime) / 1000);
		var min = Math.floor(elapsed / 60);
		var sec = elapsed % 60;
		$('#whatsapp-voice-timer').text(min + ':' + String(sec).padStart(2, '0'));
	},

	/**
	 * Cancel voice recording without sending
	 */
	cancelVoiceRecording: function() {
		this._voiceRecording = false;

		// Stop recorder
		if (this._voiceRecorder && this._voiceRecorder.state !== 'inactive') {
			this._voiceRecorder.stop();
		}
		this._voiceRecorder = null;

		// Stop microphone
		if (this._voiceStream) {
			this._voiceStream.getTracks().forEach(function(track) { track.stop(); });
			this._voiceStream = null;
		}

		// Clear timer
		if (this._voiceTimerInterval) {
			clearInterval(this._voiceTimerInterval);
			this._voiceTimerInterval = null;
		}

		this._voiceChunks = [];

		// Restore UI
		$('#whatsapp-voice-recording').hide();
		$('#whatsapp-input-area').show();
	},

	/**
	 * Stop recording and send the voice note
	 */
	stopAndSendVoiceRecording: function() {
		var self = this;

		if (!this._voiceRecorder || this._voiceRecorder.state === 'inactive') {
			this.cancelVoiceRecording();
			return;
		}

		// Check minimum duration (1 second)
		var durationMs = Date.now() - this._voiceStartTime;
		if (durationMs < 1000) {
			alert(this._t('VoiceTooShort'));
			return;
		}

		this._voiceRecording = false;

		// Clear timer
		if (this._voiceTimerInterval) {
			clearInterval(this._voiceTimerInterval);
			this._voiceTimerInterval = null;
		}

		// Disable send button while processing
		$('#whatsapp-voice-send').prop('disabled', true).text(this._t('SendingVoice'));

		// When recorder stops, it fires a final dataavailable then onstop
		this._voiceRecorder.onstop = function() {
			// Stop microphone
			if (self._voiceStream) {
				self._voiceStream.getTracks().forEach(function(track) { track.stop(); });
				self._voiceStream = null;
			}

			if (self._voiceChunks.length === 0) {
				self.cancelVoiceRecording();
				return;
			}

			// Build the audio blob — force audio/* even if browser reports video/*
			var mimeType = self._voiceRecorder.mimeType || 'audio/webm';
			if (mimeType.indexOf('video/') === 0) {
				mimeType = mimeType.replace('video/', 'audio/');
				console.log('[WhatsAppDati] Corrected voice MIME from video/* to:', mimeType);
			}
			var blob = new Blob(self._voiceChunks, { type: mimeType });
			self._voiceChunks = [];
			self._voiceRecorder = null;

			console.log('[WhatsAppDati] Voice recording stopped, blob size:', blob.size, 'type:', mimeType);

			// Determine file extension from MIME
			var ext = 'webm';
			if (mimeType.indexOf('ogg') >= 0) ext = 'ogg';
			else if (mimeType.indexOf('mp4') >= 0) ext = 'm4a';
			else if (mimeType.indexOf('mpeg') >= 0) ext = 'mp3';
			else if (mimeType.indexOf('webm') >= 0) ext = 'webm';

			var filename = 'voice_' + Date.now() + '.' + ext;

			// Create a File from the Blob
			var file;
			try {
				file = new File([blob], filename, { type: mimeType });
			} catch (e) {
				// Fallback for older browsers
				file = blob;
				file.name = filename;
			}

			// Send via upload_media.php
			var formData = new FormData();
			formData.append('media_file', file, filename);
			formData.append('conversation_id', self.currentConversationId);
			formData.append('token', $('input[name="token"]').val());
			formData.append('is_voice_note', '1');

			$.ajax({
				url: WhatsAppAjaxBase + 'ajax/upload_media.php',
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						WhatsAppChat.loadMessages(WhatsAppChat.currentConversationId);
						WhatsAppChat.loadConversations();
					} else {
						var errMsg = WhatsAppChat._t('ErrorPrefix') + data.error;
						if (data.debug) errMsg += "\n\nDEBUG:\n" + JSON.stringify(data.debug, null, 2);
						alert(errMsg);
					}
				},
				error: function() {
					alert(WhatsAppChat._t('ConnectionError'));
				},
				complete: function() {
					// Restore UI
					$('#whatsapp-voice-recording').hide();
					$('#whatsapp-input-area').show();
					$('#whatsapp-voice-send').prop('disabled', false).text(WhatsAppChat._t('Send'));
				}
			});
		};

		this._voiceRecorder.stop();
	},

	// ==========================================
	// Real-time / SSE + Polling
	// ==========================================

	/**
	 * Initialize real-time: use SSE if configured, otherwise polling
	 */
	initRealtime: function() {
		var self = this;
		var serverMode = (typeof WhatsAppRealtimeMode !== 'undefined') ? WhatsAppRealtimeMode : 'polling';

		// If server is configured for SSE and browser supports EventSource, try SSE
		if (serverMode === 'sse' && typeof EventSource !== 'undefined') {
			console.log('[WhatsApp] Realtime mode: SSE (server configured)');
			this.connectSSE();
		} else {
			if (serverMode === 'sse') {
				console.warn('[WhatsApp] SSE configured but browser does not support EventSource, using polling');
			} else {
				console.log('[WhatsApp] Realtime mode: polling');
			}
			this.startPolling();
			this.updateConnectionStatus('polling');
		}
	},

	/**
	 * Connect to SSE endpoint (short SSE mode)
	 *
	 * The server checks DB once and exits immediately. EventSource auto-reconnects
	 * every ~2 seconds (via "retry: 2000" directive). Each reconnection sends
	 * Last-Event-ID header with composite "msgId:convTs" to track state.
	 *
	 * Connection lifecycle: connect → receive events → server closes → onerror fires
	 * → browser waits 2s → reconnects → repeat. This is NORMAL, not an error.
	 */
	connectSSE: function() {
		var self = this;

		// Close existing connection
		if (this.sseSource) {
			this.sseSource.close();
			this.sseSource = null;
		}

		this.updateConnectionStatus('connecting');
		this._sseGotEvent = false;  // Track if we received events in current cycle
		this._sseConsecutiveErrors = 0; // Only count errors with no events received

		try {
			var sseUrl = WhatsAppAjaxBase + 'ajax/sse.php';
			console.log('[WhatsApp SSE] Connecting (short mode) to:', sseUrl);
			this.sseSource = new EventSource(sseUrl);

			// ---- SSE: Connected (first connection only — no Last-Event-ID) ----
			this.sseSource.addEventListener('connected', function(e) {
				self._sseGotEvent = true;
				self._sseConsecutiveErrors = 0;
				self.sseConnected = true;
				self.realtimeMode = 'sse';
				self.stopPolling();
				self.updateConnectionStatus('connected');
				try {
					var data = JSON.parse(e.data);
					console.log('[WhatsApp SSE] Connected. Baseline msg_id:', data.last_msg_id, 'conv_ts:', data.last_conv_ts);
				} catch (err) {
					console.log('[WhatsApp SSE] Connected successfully');
				}
			});

			// ---- SSE: New message ----
			this.sseSource.addEventListener('new_message', function(e) {
				self._sseGotEvent = true;
				self._sseConsecutiveErrors = 0;
				self.sseConnected = true;
				self.updateConnectionStatus('connected');
				try {
					var data = JSON.parse(e.data);
					console.log('[WhatsApp SSE] New message for conversation:', data.conversation_id);

					// Refresh conversations list
					self.loadConversations();

					// If the message is for the current conversation, refresh messages
					if (data.conversation_id && data.conversation_id == self.currentConversationId) {
						self.loadMessages(self.currentConversationId, false); // smart scroll — don't interrupt if reading history
					}

					// Show desktop notification + sound for inbound messages
					if (data.direction === 'inbound') {
						// Only notify when this conversation is not the active one
						if (!data.conversation_id || data.conversation_id != self.currentConversationId || document.hidden) {
							self.showDesktopNotification(data);
							self.playNotificationSound();
						}
					}
				} catch (err) {
					console.warn('[WhatsApp SSE] Error parsing new_message:', err);
				}
			});

			// ---- SSE: Status update ----
			this.sseSource.addEventListener('status_update', function(e) {
				self._sseGotEvent = true;
				try {
					var data = JSON.parse(e.data);
					if (self.currentConversationId) {
						self.loadMessages(self.currentConversationId, false);
					}
				} catch (err) {}
			});

			// ---- SSE: New conversation ----
			this.sseSource.addEventListener('new_conversation', function(e) {
				self._sseGotEvent = true;
				self.loadConversations();
			});

			// ---- SSE: Conversation update ----
			this.sseSource.addEventListener('conversation_update', function(e) {
				self._sseGotEvent = true;
				self._sseConsecutiveErrors = 0;
				self.sseConnected = true;
				self.updateConnectionStatus('connected');
				try {
					var data = JSON.parse(e.data);
					self.loadConversations();
					if (data.conversation_id && data.conversation_id == self.currentConversationId) {
						self.loadMessages(self.currentConversationId, false);
					}
				} catch (err) {}
			});

			// ---- SSE: Heartbeat ----
			this.sseSource.addEventListener('heartbeat', function(e) {
				self._sseGotEvent = true;
				self._sseConsecutiveErrors = 0;
				self.sseConnected = true;
				self.updateConnectionStatus('connected');
			});

			// ---- SSE: Error / connection close ----
			// In "short SSE" mode, the server closes after each response.
			// EventSource fires onerror and auto-reconnects — this is NORMAL.
			// We only count as real failure if no events were received.
			this.sseSource.onerror = function(e) {
				var state = self.sseSource ? self.sseSource.readyState : -1;

				if (self._sseGotEvent) {
					// Normal cycle: got events, server closed. Browser will reconnect.
					self._sseGotEvent = false;
					self._sseConsecutiveErrors = 0;
					// readyState 0 (CONNECTING) = browser is reconnecting — expected
					if (state === EventSource.CONNECTING) {
						return; // Normal reconnection, do nothing
					}
				}

				// No events received in this cycle — potential problem
				self._sseConsecutiveErrors++;
				var stateNames = {0: 'CONNECTING', 1: 'OPEN', 2: 'CLOSED'};
				console.warn('[WhatsApp SSE] Error #' + self._sseConsecutiveErrors +
					' (no events), readyState=' + (stateNames[state] || state));

				if (self._sseConsecutiveErrors >= 5 || state === EventSource.CLOSED) {
					console.warn('[WhatsApp SSE] Too many empty errors, falling back to polling');
					self.fallbackToPolling();
				} else if (state === EventSource.CONNECTING) {
					// Browser is retrying — let it, but show reconnecting status
					self.updateConnectionStatus('reconnecting');
				}
			};

		} catch (err) {
			console.error('[WhatsApp SSE] Failed to create EventSource:', err);
			this.fallbackToPolling();
		}
	},

	/**
	 * Fallback to polling mode
	 */
	fallbackToPolling: function() {
		if (this.sseSource) {
			this.sseSource.close();
			this.sseSource = null;
		}
		this.sseConnected = false;
		this.realtimeMode = 'polling';
		this.startPolling();
		this.updateConnectionStatus('polling');
		console.log('[WhatsApp] Fallback: using polling mode');
	},

	/**
	 * Disconnect SSE and stop polling (cleanup)
	 */
	disconnectRealtime: function() {
		if (this.sseSource) {
			this.sseSource.close();
			this.sseSource = null;
		}
		if (this.sseReconnectTimer) {
			clearTimeout(this.sseReconnectTimer);
		}
		this.stopPolling();
		this.sseConnected = false;
		this.updateConnectionStatus('disconnected');
	},

	/**
	 * Show desktop notification for incoming messages
	 */
	showDesktopNotification: function(data) {
		if (!('Notification' in window)) return;

		if (Notification.permission === 'default') {
			Notification.requestPermission();
			return;
		}

		if (Notification.permission !== 'granted') return;

		// Don't notify if the page is visible and the conversation is active
		if (!document.hidden && data.conversation_id == this.currentConversationId) return;

		var title = data.contact_name || data.phone || 'WhatsApp';
		var body = data.preview || WhatsAppChat._t('NewMessage');

		try {
			var notification = new Notification(title, {
				body: body,
				icon: 'img/whatsappdati.png',
				tag: 'whatsapp-msg-' + data.conversation_id,
				renotify: true
			});

			notification.onclick = function() {
				window.focus();
				if (data.conversation_id) {
					WhatsAppChat.selectConversation(data.conversation_id);
				}
				notification.close();
			};

			// Auto-close after 5 seconds
			setTimeout(function() { notification.close(); }, 5000);
		} catch (err) {
			// Notifications may fail in insecure contexts
		}
	},

	/**
	 * Update connection status indicator
	 */
	updateConnectionStatus: function(status) {
		var indicator = $('#whatsapp-connection-status');
		if (!indicator.length) return;

		indicator.removeClass('status-connected status-connecting status-reconnecting status-polling status-disconnected');
		indicator.addClass('status-' + status);

		var labels = {
			'connected': WhatsAppChat._t('StatusRealtime'),
			'connecting': WhatsAppChat._t('StatusConnecting'),
			'reconnecting': WhatsAppChat._t('StatusReconnecting'),
			'polling': WhatsAppChat._t('StatusPolling'),
			'disconnected': WhatsAppChat._t('StatusDisconnected')
		};

		indicator.text(labels[status] || status);
		indicator.attr('title', status === 'connected' ?
			WhatsAppChat._t('TooltipSSE') :
			status === 'polling' ? WhatsAppChat._t('TooltipPolling', (WhatsAppChat.pollingDelay / 1000)) :
			status);
	},

	/**
	 * Start polling for new messages
	 */
	startPolling: function() {
		if (this.pollingInterval) return; // Already polling
		var self = this;
		this.pollingInterval = setInterval(function() {
			// M12: Skip if SSE is connected and working
			if (self.sseConnected) return;
			if (self.currentConversationId && !self.isLoadingMessages) {
				self.loadMessages(self.currentConversationId, false); // smart scroll in polling mode
			}
			self.loadConversations();
		}, this.pollingDelay);
	},

	/**
	 * Stop polling
	 */
	stopPolling: function() {
		if (this.pollingInterval) {
			clearInterval(this.pollingInterval);
			this.pollingInterval = null;
		}
	},

	/**
	 * Scroll messages to bottom (always)
	 */
	scrollToBottom: function() {
		var messagesArea = $('#whatsapp-messages-area');
		if (messagesArea.length && messagesArea[0]) {
			messagesArea.scrollTop(messagesArea[0].scrollHeight);
		}
	},

	/**
	 * Scroll to bottom only if the user is already near the bottom (smart scroll)
	 * Threshold: 120px from the bottom — avoids interrupting when reading history
	 */
	_scrollToBottomIfAtBottom: function() {
		var el = $('#whatsapp-messages-area')[0];
		if (!el) return;
		var distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
		if (distanceFromBottom <= 120) {
			el.scrollTop = el.scrollHeight;
		}
	},

	/**
	 * Load older messages (pagination)
	 */
	loadMoreMessages: function() {
		if (!this.currentConversationId || !this.messagesHasMore) return;
		if (this.isLoadingMessages) return;
		this.isLoadingMessages = true;
		this.messagesOffset += 100;
		var self = this;
		var messagesArea = $('#whatsapp-messages-area');
		var scrollHeightBefore = messagesArea[0] ? messagesArea[0].scrollHeight : 0;
		var btn = $('#whatsapp-load-more-btn');
		btn.prop('disabled', true).text('...');
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/messages.php',
			method: 'GET',
			data: { conversation_id: this.currentConversationId, offset: this.messagesOffset },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.messages) {
					self.messagesHasMore = data.has_more || false;
					// Build HTML for the older messages batch with date separators
					var oldHtml = '';
					var lastDateKey = '';
					data.messages.forEach(function(msg) {
						var dateKey = WhatsAppChat._getDateKey(msg.timestamp);
						if (dateKey && dateKey !== lastDateKey) {
							lastDateKey = dateKey;
							oldHtml += '<div class="whatsapp-date-separator"><span>' + WhatsAppChat._getDateLabel(msg.timestamp) + '</span></div>';
						}
						oldHtml += WhatsAppChat._buildSingleMessageHtml(msg);
					});
					// Remove duplicate date separator if the first existing message has the same date
					var firstExisting = messagesArea.find('.whatsapp-date-separator').first();
					if (firstExisting.length && lastDateKey) {
						var firstExistingText = firstExisting.find('span').text();
						var lastBatchLabel = WhatsAppChat._getDateLabel(data.messages[data.messages.length - 1].timestamp);
						if (firstExistingText === lastBatchLabel) {
							firstExisting.remove();
						}
					}
					// Remove the current load-more container
					$('#whatsapp-load-more-btn').closest('.whatsapp-load-more-container').remove();
					// Prepend new load-more button (if needed) + old messages
					var prependHtml = self.messagesHasMore
						? '<div class="whatsapp-load-more-container"><button id="whatsapp-load-more-btn" class="whatsapp-load-more-btn">' + WhatsAppChat._t('LoadMoreMessages') + '</button></div>'
						: '';
					messagesArea.prepend(prependHtml + oldHtml);
					// Restore scroll so the user stays at the same position
					if (messagesArea[0]) {
						var added = messagesArea[0].scrollHeight - scrollHeightBefore;
						messagesArea.scrollTop(messagesArea.scrollTop() + added);
					}
				}
			},
			complete: function() {
				self.isLoadingMessages = false;
			}
		});
	},

	/**
	 * Build HTML for a single message bubble (used by renderMessages + loadMoreMessages)
	 */
	_buildSingleMessageHtml: function(msg) {
		var direction = msg.direction;
		var statusIcon = WhatsAppChat.getStatusIcon(msg.status);
		if (direction === 'system') {
			var sysText = WhatsAppChat.escapeHtml(msg.content || '').replace(/\n/g, '<br>');
			var html = '<div class="whatsapp-message system">';
			html += '  <div class="whatsapp-system-message">';
			html += '    <span class="whatsapp-system-text">' + sysText + '</span>';
			html += '    <span class="whatsapp-system-time">' + WhatsAppChat.formatTime(msg.timestamp) + '</span>';
			html += '  </div>';
			html += '</div>';
			return html;
		}
		var html = '<div class="whatsapp-message ' + direction + '">';
		html += '  <div class="whatsapp-message-bubble">';
		html += WhatsAppChat.renderMediaContent(msg);
		var textContent = msg.content || '';
		if (msg.message_type === 'image' || msg.message_type === 'video') {
			if (direction === 'outbound' && textContent) {
				var colonIdx = textContent.indexOf(': ');
				if (colonIdx >= 0 && colonIdx < 15) textContent = textContent.substring(colonIdx + 2);
			}
			if (textContent) html += '<p class="whatsapp-message-text">' + WhatsAppChat.escapeHtml(textContent) + '</p>';
		} else if (msg.message_type !== 'audio' && msg.message_type !== 'document' && msg.message_type !== 'contacts' && msg.message_type !== 'location') {
			html += '<p class="whatsapp-message-text">' + WhatsAppChat.escapeHtml(textContent) + '</p>';
		}
		html += '    <div class="whatsapp-message-meta">';
		html += '      <span class="whatsapp-message-time">' + WhatsAppChat.formatTime(msg.timestamp) + '</span>';
		if (direction === 'outbound') {
			var statusLabel = WhatsAppChat.getStatusLabel(msg.status);
			var errorTitle = (msg.status === 'failed' && msg.error_message) ? ' title="' + WhatsAppChat.escapeAttr(msg.error_message) + '"' : '';
			html += '      <span class="whatsapp-message-status ' + msg.status + '" aria-label="' + statusLabel + '"' + errorTitle + '>' + statusIcon + '</span>';
		}
		html += '    </div>';
		html += '  </div>';
		html += '</div>';
		return html;
	},

	/**
	 * Assign current conversation to the logged-in user with one click
	 */
	assignToMe: function() {
		if (!this.currentConversationId) return;
		if (typeof WhatsAppCurrentUserId === 'undefined' || WhatsAppCurrentUserId <= 0) return;
		this.addAgentToConversation(this.currentConversationId, WhatsAppCurrentUserId);
	},

	/**
	 * Play a short notification sound for incoming messages
	 * Uses Web Audio API — no external files required
	 */
	playNotificationSound: function() {
		try {
			if (!this._audioCtx) {
				this._audioCtx = new (window.AudioContext || window.webkitAudioContext)();
			}
			var ctx = this._audioCtx;
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			osc.connect(gain);
			gain.connect(ctx.destination);
			osc.type = 'sine';
			osc.frequency.setValueAtTime(880, ctx.currentTime);
			osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.1);
			gain.gain.setValueAtTime(0.25, ctx.currentTime);
			gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
			osc.start(ctx.currentTime);
			osc.stop(ctx.currentTime + 0.35);
		} catch (e) {
			// AudioContext may be blocked before user interaction
		}
	},

	/**
	 * Update the browser tab title with total unread count
	 */
	_updateTabTitle: function(conversations) {
		var totalUnread = 0;
		if (conversations && conversations.length) {
			conversations.forEach(function(c) {
				totalUnread += (parseInt(c.unread_count) || 0);
			});
		}
		if (totalUnread > 0) {
			document.title = '(' + totalUnread + ') ' + (this._originalPageTitle || 'WhatsApp');
		} else {
			document.title = this._originalPageTitle || 'WhatsApp';
		}
	},

	/**
	 * Parse a timestamp (Unix number or DATETIME string) into a Date object.
	 * Returns null if invalid.
	 */
	_parseTimestamp: function(timestamp) {
		if (!timestamp) return null;
		var date;
		var ts = Number(timestamp);
		if (!isNaN(ts) && ts > 0) {
			date = new Date(ts * 1000);
		} else if (typeof timestamp === 'string') {
			date = new Date(timestamp.replace(' ', 'T'));
		} else {
			return null;
		}
		return isNaN(date.getTime()) ? null : date;
	},

	/**
	 * Get a date key string (YYYY-MM-DD) for grouping messages by day.
	 */
	_getDateKey: function(timestamp) {
		var date = this._parseTimestamp(timestamp);
		if (!date) return '';
		return date.getFullYear() + '-' +
			String(date.getMonth() + 1).padStart(2, '0') + '-' +
			String(date.getDate()).padStart(2, '0');
	},

	/**
	 * Get a human-readable date label like WhatsApp:
	 * - Today → "Hoy" / Yesterday → "Ayer" / Older → "DD/MM/YYYY"
	 */
	_getDateLabel: function(timestamp) {
		var date = this._parseTimestamp(timestamp);
		if (!date) return '';
		var now = new Date();
		var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		var msgDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		var diff = Math.floor((today - msgDay) / 86400000);
		if (diff === 0) return this._t('DateToday');
		if (diff === 1) return this._t('DateYesterday');
		return String(date.getDate()).padStart(2, '0') + '/' +
			String(date.getMonth() + 1).padStart(2, '0') + '/' +
			date.getFullYear();
	},

	/**
	 * Format timestamp for message bubbles (WhatsApp style):
	 * - Today → HH:MM
	 * - Yesterday → "ayer HH:MM" (localized)
	 * - Older → DD/MM/YYYY HH:MM
	 */
	formatTime: function(timestamp) {
		var date = this._parseTimestamp(timestamp);
		if (!date) return '';
		var hours = date.getHours().toString().padStart(2, '0');
		var minutes = date.getMinutes().toString().padStart(2, '0');
		var time = hours + ':' + minutes;
		var now = new Date();
		var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		var msgDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		var diff = Math.floor((today - msgDay) / 86400000);
		if (diff === 0) return time;
		if (diff === 1) return this._t('DateYesterday').toLowerCase() + ' ' + time;
		return String(date.getDate()).padStart(2, '0') + '/' +
			String(date.getMonth() + 1).padStart(2, '0') + '/' +
			date.getFullYear() + ' ' + time;
	},

	/**
	 * Format timestamp for conversation sidebar (WhatsApp style):
	 * - Today → HH:MM
	 * - Yesterday → "Ayer" (localized)
	 * - Older → DD/MM/YYYY
	 */
	formatDateLabel: function(timestamp) {
		var date = this._parseTimestamp(timestamp);
		if (!date) return '';
		var now = new Date();
		var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		var msgDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		var diff = Math.floor((today - msgDay) / 86400000);
		if (diff === 0) {
			return date.getHours().toString().padStart(2, '0') + ':' +
				date.getMinutes().toString().padStart(2, '0');
		}
		if (diff === 1) return this._t('DateYesterday');
		return String(date.getDate()).padStart(2, '0') + '/' +
			String(date.getMonth() + 1).padStart(2, '0') + '/' +
			date.getFullYear();
	},

	/**
	 * Get status icon
	 */
	getStatusIcon: function(status) {
		// Status icons are rendered by CSS ::after pseudo-elements
		// Return empty string to avoid duplication
		return '';
	},

	/**
	 * Get accessible label for message status (M22)
	 */
	getStatusLabel: function(status) {
		switch(status) {
			case 'sent': return WhatsAppChat._t('StatusSent');
			case 'delivered': return WhatsAppChat._t('StatusDelivered');
			case 'read': return WhatsAppChat._t('StatusRead');
			case 'failed': return WhatsAppChat._t('StatusFailed');
			default: return '';
		}
	},

	/**
	 * Check if a hex color is light (luminosity > 0.5) (M21)
	 */
	isLightColor: function(hex) {
		if (!hex) return true;
		hex = hex.replace('#', '');
		if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		var r = parseInt(hex.substr(0,2), 16) / 255;
		var g = parseInt(hex.substr(2,2), 16) / 255;
		var b = parseInt(hex.substr(4,2), 16) / 255;
		// Relative luminance (WCAG)
		var lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
		return lum > 0.5;
	},

	/**
	 * Escape HTML
	 */
	escapeHtml: function(text) {
		if (!text) return '';
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(/[&<>"']/g, function(m) { return map[m]; });
	},

	/**
	 * Get initials from a display name (up to 2 chars)
	 */
	_getInitials: function(name) {
		if (!name) return '?';
		name = name.replace(/^\+/, '').trim();
		var parts = name.split(/[\s._-]+/).filter(Boolean);
		if (parts.length >= 2) {
			return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
		}
		return name.substring(0, 2).toUpperCase();
	},

	/**
	 * Hash a name to pick an avatar color (1-6)
	 */
	_hashAvatarColor: function(name) {
		var hash = 0;
		for (var i = 0; i < (name || '').length; i++) {
			hash = name.charCodeAt(i) + ((hash << 5) - hash);
		}
		return (Math.abs(hash) % 6) + 1;
	},

	/**
	 * Filter conversation items in the sidebar by search query (client-side)
	 */
	_filterConversationsBySearch: function(query) {
		query = (query || '').toLowerCase().trim();
		$('#whatsapp-conversations-list .whatsapp-conversation-item').each(function() {
			var name = $(this).find('.whatsapp-conversation-name').text().toLowerCase();
			var preview = $(this).find('.whatsapp-conversation-preview').text().toLowerCase();
			if (!query || name.indexOf(query) !== -1 || preview.indexOf(query) !== -1) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	},

	/**
	 * Escape a string for safe use in HTML attributes (href, src, etc.)
	 */
	escapeAttr: function(text) {
		if (!text) return '';
		return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	},

	/**
	 * Build AJAX URL using the base path (L5)
	 */
	ajaxUrl: function(path) {
		return WhatsAppAjaxBase + path;
	},

	/**
	 * Load available templates into the template selector
	 */
	loadTemplates: function() {
		var lineId = this.currentLineId || 0;
		// If a conversation is selected, prefer its fk_line
		if (this.currentConversationId && this.conversationsData[this.currentConversationId]) {
			var convLine = this.conversationsData[this.currentConversationId].fk_line;
			if (convLine) lineId = convLine;
		}
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/sync_templates.php',
			method: 'GET',
			data: { action: 'list', line_id: lineId },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.templates) {
					var select = $('#whatsapp-template-select');
					var currentVal = select.val();
					select.find('option:not(:first)').remove();
					data.templates.forEach(function(tpl) {
						select.append('<option value="' + tpl.rowid + '">' + WhatsAppChat.escapeHtml(tpl.name) + ' (' + tpl.language + ')</option>');
					});
					if (currentVal) select.val(currentVal);
				}
			}
		});
	},

	/**
	 * Load available agents for the assignment dropdown
	 */
	loadAgents: function() {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'GET',
			data: { action: 'agents' },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.agents) {
					WhatsAppChat.agentsList = data.agents;
					// Populate legacy single-select
					var select = $('#whatsapp-assign-select');
					select.find('option:not(:first)').remove();
					data.agents.forEach(function(agent) {
						select.append('<option value="' + agent.id + '">' + WhatsAppChat.escapeHtml(agent.name) + '</option>');
					});
				}
			}
		});
	},

	/**
	 * Render assigned agents badges in header
	 */
	renderAssignedAgents: function(agents) {
		var container = $('#whatsapp-assigned-agents');
		if (!container.length) return;
		var html = '';
		if (agents && agents.length > 0) {
			var self = this;
			agents.forEach(function(agent) {
				html += '<span class="whatsapp-assigned-agent-pill" data-agent-id="' + agent.id + '" title="' + self.escapeHtml(agent.name) + '">';
				html += '&#x1F464; ' + self.escapeHtml(agent.name);
				html += ' <a href="javascript:void(0)" class="whatsapp-remove-agent" data-agent-id="' + agent.id + '" title="Quitar">&times;</a>';
				html += '</span>';
			});
		}
		container.html(html);
	},

	/**
	 * Claim an unassigned conversation for the current user
	 */
	claimConversation: function() {
		var self = this;
		var conversationId = this.currentConversationId;
		if (!conversationId) return;

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'claim',
				conversation_id: conversationId,
				token: this.csrfToken
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#whatsapp-claim-btn').hide();
					$('.whatsapp-assign-area').show();
					// Update cached data
					if (self.conversationsData[conversationId]) {
						self.conversationsData[conversationId].fk_user_assigned = data.agent_id;
						self.conversationsData[conversationId].agent_name = data.agent_name;
						self.conversationsData[conversationId].assigned_agents = [{id: data.agent_id, name: data.agent_name, role: 'agent'}];
					}
					self.renderAssignedAgents([{id: data.agent_id, name: data.agent_name, role: 'agent'}]);
					self.loadConversations(true);
				} else {
					alert(data.error || 'Error');
					self.loadConversations(true);
				}
			},
			error: function() {
				alert('Error de red');
			}
		});
	},

	/**
	 * Toggle multi-agent picker dropdown
	 */
	toggleMultiAgentPicker: function() {
		var dropdown = $('#whatsapp-multi-agent-dropdown');
		if (dropdown.is(':visible')) {
			dropdown.hide();
			return;
		}

		var conv = this.conversationsData[this.currentConversationId];
		var assignedIds = {};
		if (conv && conv.assigned_agents) {
			conv.assigned_agents.forEach(function(a) { assignedIds[a.id] = true; });
		}

		var html = '';
		this.agentsList.forEach(function(agent) {
			var checked = assignedIds[agent.id] ? 'checked' : '';
			html += '<div class="whatsapp-multi-agent-item" data-agent-id="' + agent.id + '">';
			html += '<input type="checkbox" ' + checked + '> ';
			html += '<span>' + WhatsAppChat.escapeHtml(agent.name) + '</span>';
			html += '</div>';
		});

		dropdown.html(html).show();
	},

	/**
	 * Add an agent to the current conversation
	 */
	addAgentToConversation: function(conversationId, agentId) {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'add_agent',
				conversation_id: conversationId,
				agent_id: agentId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					if (self.conversationsData[conversationId]) {
						self.conversationsData[conversationId].assigned_agents = data.assigned_agents;
						if (data.assigned_agents.length > 0) {
							self.conversationsData[conversationId].fk_user_assigned = data.assigned_agents[0].id;
							self.conversationsData[conversationId].agent_name = data.assigned_agents[0].name;
						}
					}
					self.renderAssignedAgents(data.assigned_agents);
					self.loadConversations();
				}
			}
		});
	},

	/**
	 * Remove an agent from the current conversation
	 */
	removeAgentFromConversation: function(conversationId, agentId) {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'remove_agent',
				conversation_id: conversationId,
				agent_id: agentId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					if (self.conversationsData[conversationId]) {
						self.conversationsData[conversationId].assigned_agents = data.assigned_agents;
						if (data.assigned_agents.length > 0) {
							self.conversationsData[conversationId].fk_user_assigned = data.assigned_agents[0].id;
							self.conversationsData[conversationId].agent_name = data.assigned_agents[0].name;
						} else {
							self.conversationsData[conversationId].fk_user_assigned = 0;
							self.conversationsData[conversationId].agent_name = '';
						}
					}
					self.renderAssignedAgents(data.assigned_agents);
					self.loadConversations();
				}
			}
		});
	},

	/**
	 * Load and refresh assigned agents for a conversation from server
	 */
	loadConversationAgents: function(conversationId) {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'GET',
			data: { action: 'conversation_agents', conversation_id: conversationId },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.assigned_agents) {
					if (self.conversationsData[conversationId]) {
						self.conversationsData[conversationId].assigned_agents = data.assigned_agents;
					}
					if (conversationId == self.currentConversationId) {
						self.renderAssignedAgents(data.assigned_agents);
					}
				}
			}
		});
	},

	/**
	 * Assign a conversation to an agent (legacy single-select)
	 */
	assignConversation: function(conversationId, agentId) {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'assign',
				conversation_id: conversationId,
				agent_id: agentId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					// Update cached data
					if (WhatsAppChat.conversationsData[conversationId]) {
						WhatsAppChat.conversationsData[conversationId].fk_user_assigned = data.agent_id;
						WhatsAppChat.conversationsData[conversationId].agent_name = data.agent_name;
						if (data.assigned_agents) {
							WhatsAppChat.conversationsData[conversationId].assigned_agents = data.assigned_agents;
						}
					}
					if (data.assigned_agents) {
						self.renderAssignedAgents(data.assigned_agents);
					}
					// Refresh conversation list to show updated badge
					WhatsAppChat.loadConversations();
				}
			},
			error: function() {
				console.warn('[WhatsApp] Failed to assign conversation');
			}
		});
	},

	// ==========================================
	// Transfer & Close Conversation
	// ==========================================

	/**
	 * Open transfer modal — populate agents dropdown and load transfer history
	 */
	openTransferModal: function() {
		if (!this.currentConversationId) return;

		var select = $('#whatsapp-transfer-agent-select');
		select.find('option:not(:first)').remove();

		// Populate with cached agents list (excluding currently assigned agent)
		var conv = this.conversationsData[this.currentConversationId];
		var currentAgentId = conv ? parseInt(conv.fk_user_assigned || 0) : 0;

		this.agentsList.forEach(function(agent) {
			if (parseInt(agent.id) !== currentAgentId) {
				select.append('<option value="' + agent.id + '">' + WhatsAppChat.escapeHtml(agent.name) + '</option>');
			}
		});

		$('#whatsapp-transfer-note').val('');
		this.loadTransferHistory(this.currentConversationId);
		$('#whatsapp-transfer-modal').show();
	},

	/**
	 * Load transfer history for a conversation
	 */
	loadTransferHistory: function(conversationId) {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'GET',
			data: { action: 'transfer_log', conversation_id: conversationId },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.transfers && data.transfers.length > 0) {
					var html = '';
					data.transfers.forEach(function(t) {
						html += '<div class="whatsapp-transfer-entry">';
						html += '<span class="whatsapp-transfer-date">' + WhatsAppChat.formatTime(t.date_transfer) + '</span> ';
						html += '<span class="whatsapp-transfer-detail">';
						html += WhatsAppChat.escapeHtml(t.from_name) + ' → ' + WhatsAppChat.escapeHtml(t.to_name);
						if (t.note) {
							html += ' <em class="whatsapp-transfer-note-text">"' + WhatsAppChat.escapeHtml(t.note) + '"</em>';
						}
						html += '</span>';
						html += '</div>';
					});
					$('#whatsapp-transfer-history-list').html(html);
					$('#whatsapp-transfer-history').show();
				} else {
					$('#whatsapp-transfer-history').hide();
				}
			}
		});
	},

	/**
	 * Execute the transfer
	 */
	doTransfer: function() {
		var toAgentId = parseInt($('#whatsapp-transfer-agent-select').val()) || 0;
		var note = $('#whatsapp-transfer-note').val().trim();
		var conversationId = parseInt(this.currentConversationId) || 0;

		if (toAgentId <= 0) {
			alert(WhatsAppChat._t('SelectAgent'));
			return;
		}
		if (conversationId <= 0) return;

		var self = this;
		$('#whatsapp-transfer-submit').prop('disabled', true);

		// Read the Dolibarr CSRF token: try global variable first, then hidden input
		var csrfToken = (typeof tokenDolibarr !== 'undefined' ? tokenDolibarr : '') ||
			$('input[name="token"]').val() ||
			$('input[name="newtoken"]').val() || '';

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'transfer',
				conversation_id: conversationId,
				to_agent_id: toAgentId,
				transfer_note: note,
				token: csrfToken
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#whatsapp-transfer-modal').hide();
					// Update cached data
					if (self.conversationsData[conversationId]) {
						self.conversationsData[conversationId].fk_user_assigned = toAgentId;
						self.conversationsData[conversationId].agent_name = data.to_agent_name || '';
					}
					// Update the assign dropdown to reflect new agent
					$('#whatsapp-assign-select').val(toAgentId);
					// Reload agents for the conversation
					self.loadConversationAgents(conversationId);
					// Reload messages to show system message
					self.loadMessages(conversationId, true);
					self.loadConversations();
				} else {
					alert(data.error || 'Error');
				}
			},
			error: function() {
				alert(WhatsAppChat._t('JsConnectionError'));
			},
			complete: function() {
				$('#whatsapp-transfer-submit').prop('disabled', false);
			}
		});
	},

	/**
	 * Open close conversation confirmation modal
	 */
	openCloseModal: function() {
		if (!this.currentConversationId) return;
		$('#whatsapp-close-modal').show();
	},

	/**
	 * Execute close conversation
	 */
	doCloseConversation: function() {
		var conversationId = this.currentConversationId;
		if (!conversationId) return;

		var sendCsat = $('#whatsapp-close-send-csat').is(':checked') ? 1 : 0;
		var self = this;
		$('#whatsapp-close-submit').prop('disabled', true);

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'POST',
			data: {
				action: 'close_conversation',
				conversation_id: conversationId,
				send_csat: sendCsat,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#whatsapp-close-modal').hide();
					// Reload messages to show system message
					self.loadMessages(conversationId, true);
					self.loadConversations();
				} else {
					alert(data.error || 'Error');
				}
			},
			error: function() {
				alert(WhatsAppChat._t('JsConnectionError'));
			},
			complete: function() {
				$('#whatsapp-close-submit').prop('disabled', false);
			}
		});
	},

	/**
	 * Load CSAT info for a conversation and display in header
	 */
	loadCSATInfo: function(conversationId) {
		$('#whatsapp-csat-info').hide();
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/assignment.php',
			method: 'GET',
			data: { action: 'csat_info', conversation_id: conversationId },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.csat && data.csat.rating) {
					var rating = parseInt(data.csat.rating);
					var stars = '';
					for (var i = 1; i <= 5; i++) {
						stars += (i <= rating) ? '⭐' : '☆';
					}
					$('#whatsapp-csat-info').html(
						'<span class="whatsapp-csat-rating" title="CSAT: ' + rating + '/5">' + stars + '</span>'
					).show();
				}
			}
		});
	},

	// ==========================================
	// Tags / Labels
	// ==========================================

	/**
	 * Load all available tags
	 */
	loadTags: function() {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/tags.php',
			method: 'GET',
			data: { action: 'list' },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.tags) {
					WhatsAppChat.allTags = data.tags;
					WhatsAppChat.populateTagFilter();
				}
			}
		});
	},

	/**
	 * Populate the tag filter dropdown in sidebar
	 */
	populateTagFilter: function() {
		var select = $('#whatsapp-tag-filter-select');
		var currentVal = select.val();
		select.find('option:not(:first)').remove();
		this.allTags.forEach(function(tag) {
			select.append('<option value="' + tag.id + '">' + WhatsAppChat.escapeHtml(tag.label) + '</option>');
		});
		if (currentVal) select.val(currentVal);
	},

	/**
	 * Render tags in the chat header for current conversation
	 */
	renderConversationTags: function(tags) {
		var container = $('#whatsapp-conversation-tags');
		if (!container.length) return;

		var html = '';
		if (tags && tags.length > 0) {
			tags.forEach(function(tag) {
				html += '<span class="whatsapp-conv-tag" style="background:' + WhatsAppChat.escapeHtml(tag.color) + '20; color:' + WhatsAppChat.escapeHtml(tag.color) + '; border-color:' + WhatsAppChat.escapeHtml(tag.color) + '40;">';
				html += WhatsAppChat.escapeHtml(tag.label);
				html += '<span class="whatsapp-conv-tag-remove" data-tag-id="' + tag.id + '">&times;</span>';
				html += '</span>';
			});
		}
		container.html(html);
	},

	/**
	 * Open the tag picker dropdown near the + button
	 */
	openTagPicker: function() {
		if (!this.currentConversationId) return;

		var picker = $('#whatsapp-tag-picker');
		var triggerBtn = $('#whatsapp-tag-add-btn');
		if (!triggerBtn.length) return;
		
		// Position picker near the + button
		var offset = triggerBtn.offset();
		if (!offset) return;
		picker.css({
			top: offset.top + triggerBtn.outerHeight() + 4,
			left: Math.min(offset.left, $(window).width() - 260)
		});

		// Build tag picker list
		this.renderTagPickerList();

		// Build color options
		var colorsHtml = '';
		var defaultColors = ['#25D366','#128C7E','#075E54','#34B7F1','#FF6B6B','#FFA726','#AB47BC','#78909C','#EC407A','#66BB6A'];
		defaultColors.forEach(function(c, idx) {
			colorsHtml += '<span class="whatsapp-tag-color-option' + (idx === 0 ? ' selected' : '') + '" data-color="' + c + '" style="background:' + c + ';"></span>';
		});
		$('#whatsapp-tag-picker-colors').html(colorsHtml);

		$('#whatsapp-tag-picker-search').val('');
		$('#whatsapp-tag-picker-create').hide();
		picker.show();
		$('#whatsapp-tag-picker-search').focus();
	},

	/**
	 * Render the list of tags in the picker
	 */
	renderTagPickerList: function() {
		var currentTags = [];
		var convData = this.conversationsData[this.currentConversationId];
		if (convData && convData.tags) {
			currentTags = convData.tags.map(function(t) { return t.id; });
		}

		var html = '';
		this.allTags.forEach(function(tag) {
			var assigned = currentTags.indexOf(tag.id) >= 0;
			html += '<div class="whatsapp-tag-picker-item' + (assigned ? ' assigned' : '') + '" data-tag-id="' + tag.id + '">';
			html += '<span class="whatsapp-tag-picker-color" style="background:' + WhatsAppChat.escapeHtml(tag.color) + ';"></span>';
			html += '<span class="whatsapp-tag-picker-label">' + WhatsAppChat.escapeHtml(tag.label) + '</span>';
			if (assigned) {
				html += '<span class="whatsapp-tag-picker-check">✓</span>';
			}
			html += '</div>';
		});

		if (this.allTags.length === 0) {
			html = '<div class="whatsapp-tag-picker-empty">' + WhatsAppChat._t('NoTagsCreate') + '</div>';
		}

		$('#whatsapp-tag-picker-list').html(html);
	},

	/**
	 * Filter tag picker list by search text
	 */
	filterTagPicker: function(query) {
		query = query.trim().toLowerCase();
		var hasMatch = false;

		$('.whatsapp-tag-picker-item').each(function() {
			var label = $(this).find('.whatsapp-tag-picker-label').text().toLowerCase();
			var match = !query || label.indexOf(query) >= 0;
			$(this).toggle(match);
			if (match) hasMatch = true;
		});

		// Show create option when no exact match
		if (query.length > 0) {
			var exactMatch = false;
			WhatsAppChat.allTags.forEach(function(tag) {
				if (tag.label.toLowerCase() === query) exactMatch = true;
			});
			$('#whatsapp-tag-picker-create').toggle(!exactMatch);
		} else {
			$('#whatsapp-tag-picker-create').hide();
		}
	},

	/**
	 * Assign a tag to the current conversation
	 */
	assignTag: function(tagId) {
		if (!this.currentConversationId) return;
		var self = this;

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/tags.php',
			method: 'POST',
			data: {
				action: 'assign',
				conversation_id: this.currentConversationId,
				tag_id: tagId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					// Update cached data
					if (self.conversationsData[self.currentConversationId]) {
						self.conversationsData[self.currentConversationId].tags = data.tags;
					}
					self.renderConversationTags(data.tags);
					self.renderTagPickerList();
					self.loadConversations();
				}
			}
		});
	},

	/**
	 * Remove a tag from the current conversation
	 */
	unassignTag: function(tagId) {
		if (!this.currentConversationId) return;
		var self = this;

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/tags.php',
			method: 'POST',
			data: {
				action: 'unassign',
				conversation_id: this.currentConversationId,
				tag_id: tagId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					if (self.conversationsData[self.currentConversationId]) {
						self.conversationsData[self.currentConversationId].tags = data.tags;
					}
					self.renderConversationTags(data.tags);
					self.renderTagPickerList();
					self.loadConversations();
				}
			}
		});
	},

	/**
	 * Create a new tag from the picker and immediately assign it
	 */
	createAndAssignTag: function() {
		if (!this.currentConversationId) return;
		var self = this;
		var label = $('#whatsapp-tag-picker-search').val().trim();
		if (!label) return;

		var color = $('.whatsapp-tag-color-option.selected').data('color') || '#25D366';

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/tags.php',
			method: 'POST',
			data: {
				action: 'create',
				label: label,
				color: color,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success && data.tag) {
					// Add to local cache
					self.allTags.push(data.tag);
					self.populateTagFilter();

					// Assign to current conversation
					self.assignTag(data.tag.id);

					// Reset picker
					$('#whatsapp-tag-picker-search').val('');
					$('#whatsapp-tag-picker-create').hide();
				} else {
					alert('Error: ' + (data.error || WhatsAppChat._t('UnknownError')));
				}
			}
		});
	},

	// =============================================
	// Emoji Picker Methods
	// =============================================

	/**
	 * Emoji data organized by category
	 */
	_emojiData: {
		'smileys': {
			icon: '😀',
			label: 'Smileys',
			emojis: ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🫡','🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','🫤','😟','🙁','☹️','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖']
		},
		'gestures': {
			icon: '👋',
			label: 'Gestures',
			emojis: ['👋','🤚','🖐️','✋','🖖','🫱','🫲','🫳','🫴','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','🧠','🫀','🫁','🦷','🦴','👀','👁️','👅','👄']
		},
		'people': {
			icon: '👤',
			label: 'People',
			emojis: ['👶','🧒','👦','👧','🧑','👱','👨','🧔','👩','🧓','👴','👵','🙍','🙎','🙅','🙆','💁','🙋','🧏','🙇','🤦','🤷','👮','🕵️','💂','🥷','👷','🫅','🤴','👸','👳','👲','🧕','🤵','👰','🤰','🫃','🫄','🤱','👼','🎅','🤶','🦸','🦹','🧙','🧚','🧛','🧜','🧝','🧞','🧟','🧌','💆','💇','🚶','🧍','🧎','🏃','💃','🕺','🕴️','👯','🧖','🧗','🤸','⛹️','🏋️','🚴','🚵','🤼','🤽','🤾','🤺','⛷️','🏂','🏌️','🏇','🏊','🤹']
		},
		'hearts': {
			icon: '❤️',
			label: 'Hearts',
			emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','❣️','💕','💞','💓','💗','💖','💘','💝','💟','♥️','🫶','💑','💏','👪','👨‍👩‍👦','👨‍👩‍👧','👨‍👩‍👧‍👦']
		},
		'nature': {
			icon: '🌿',
			label: 'Nature',
			emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐻‍❄️','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🪶','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊️','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿️','🦔','🌵','🎄','🌲','🌳','🌴','🪵','🌱','🌿','☘️','🍀','🎍','🪴','🎋','🍃','🍂','🍁','🌾','🪻','🌺','🌻','🌹','🥀','🌷','🌼','🫧','💐','🍄','🌰','🪸']
		},
		'food': {
			icon: '🍔',
			label: 'Food',
			emojis: ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🫑','🌽','🥕','🧄','🧅','🥔','🍠','🥐','🥖','🍞','🥨','🥯','🧇','🥞','🧈','🍳','🥚','🧀','🥩','🍖','🍗','🥓','🍔','🍟','🍕','🌭','🥪','🌮','🌯','🥙','🧆','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🍯','🥛','🍼','🫖','☕','🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧉','🍾','🧊','🥄','🍴','🍽️','🥣','🥡','🥢']
		},
		'travel': {
			icon: '✈️',
			label: 'Travel',
			emojis: ['🚗','🚕','🚙','🚌','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🏍️','🛵','🚲','🛴','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫','🛬','🛩️','💺','🛰️','🚀','🛸','🚁','🛶','⛵','🚤','🛥️','🛳️','🚢','🗼','🏰','🏯','🏟️','🎡','🎢','🎠','⛲','🏖️','🏝️','🏜️','🌋','⛰️','🏔️','🗻','🏕️','⛺','🏠','🏡','🏘️','🏗️','🏢','🏬','🏣','🏤','🏥','🏦','🏨','🏪','🏫','🏩','💒','🏛️','⛪','🕌','🕍','🛕','🕋','⛩️','🌅','🌄','🌠','🎇','🎆','🌇','🌆','🏙️','🌃','🌌','🌉','🌁']
		},
		'objects': {
			icon: '💡',
			label: 'Objects',
			emojis: ['⌚','📱','💻','⌨️','🖥️','🖨️','🖱️','🕹️','💽','💾','💿','📀','📷','📸','📹','🎥','📞','☎️','📟','📺','📻','🎙️','🎚️','🎛️','⏱️','⏲️','⏰','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯️','🧯','💸','💵','💴','💶','💷','💰','💳','💎','⚖️','🧰','🔧','🔨','🛠️','⛏️','🔩','⚙️','🧱','🔫','💣','🔪','🗡️','⚔️','🛡️','🔮','📿','🧿','💈','🔭','🔬','💊','💉','🧬','🌡️','🧹','🧺','🧻','🚽','🚰','🚿','🛁','🧼','🔑','🗝️','🚪','🛋️','🛏️','🧸','🖼️','🛍️','🛒','🎁','🎈','🎀','🎊','🎉','🏮','✉️','📩','📨','📧','💌','📦','📪','📫','📬','📭','📮','📜','📃','📄','📊','📈','📉','📆','📅','📋','📁','📂','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🔗','📎','📐','📏','📌','📍','✂️','🖊️','🖋️','✒️','🖌️','🖍️','📝','✏️','🔍','🔎','🔒','🔓']
		},
		'symbols': {
			icon: '⭐',
			label: 'Symbols',
			emojis: ['⭐','🌟','💫','✨','⚡','🔥','💥','☀️','🌤️','⛅','🌥️','☁️','🌦️','🌧️','⛈️','🌩️','🌨️','❄️','☃️','⛄','🌬️','💨','🌪️','🌈','☔','💧','💦','🌊','🎵','🎶','🔇','🔈','🔉','🔊','📢','📣','💬','💭','🗯️','♠️','♣️','♥️','♦️','🃏','🎴','🎭','🖼️','🎨','🧵','🪡','🧶','🪢','➕','➖','➗','✖️','♾️','💲','💱','™️','©️','®️','❗','❓','❕','❔','‼️','⁉️','✅','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🔅','🔆','⚠️','🔱','⚜️','♻️','✳️','❇️','🔰','💠','🏧','🚾','♿','🅿️','🈳','🈂️','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','🔀','🔁','🔂','🔄','🔼','🔽','➡️','⬅️','⬆️','⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','✔️','☑️','🔘','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔺','🔻','🔸','🔹','🔶','🔷','🔳','🔲','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🟫']
		},
		'flags': {
			icon: '🏁',
			label: 'Flags',
			emojis: ['🏳️','🏴','🏁','🚩','🏳️‍🌈','🏳️‍⚧️','🇺🇳','🇦🇷','🇧🇴','🇧🇷','🇨🇱','🇨🇴','🇨🇷','🇨🇺','🇩🇴','🇪🇨','🇸🇻','🇬🇹','🇭🇳','🇲🇽','🇳🇮','🇵🇦','🇵🇾','🇵🇪','🇵🇷','🇪🇸','🇺🇾','🇻🇪','🇺🇸','🇬🇧','🇫🇷','🇩🇪','🇮🇹','🇵🇹','🇯🇵','🇨🇳','🇰🇷','🇮🇳','🇦🇺','🇨🇦']
		}
	},

	/** Currently selected emoji category */
	_emojiCategory: 'smileys',
	/** Whether emoji picker is visible */
	_emojiPickerVisible: false,

	/**
	 * Toggle emoji picker visibility
	 */
	toggleEmojiPicker: function() {
		if (this._emojiPickerVisible) {
			this.hideEmojiPicker();
		} else {
			this.showEmojiPicker();
		}
	},

	/**
	 * Show emoji picker positioned above the emoji button
	 */
	showEmojiPicker: function() {
		var $picker = $('#whatsapp-emoji-picker');
		var $btn = $('#whatsapp-emoji-btn');

		if (!$picker.length) {
			console.error('[WhatsAppDati] Emoji picker element #whatsapp-emoji-picker not found!');
			return;
		}
		if (!$btn.length) {
			console.error('[WhatsAppDati] Emoji button element #whatsapp-emoji-btn not found!');
			return;
		}

		// Position picker above the button
		var btnOffset = $btn.offset();
		var btnHeight = $btn.outerHeight();
		var pickerHeight = 380;
		var pickerWidth = 350;

		var top = btnOffset.top - pickerHeight - 8;
		var left = btnOffset.left;

		// Ensure picker stays within viewport
		if (top < 10) top = btnOffset.top + btnHeight + 8;
		if (left + pickerWidth > $(window).width()) left = $(window).width() - pickerWidth - 10;

		$picker.css({ top: top + 'px', left: left + 'px' });

		// Build categories bar and emoji grid
		this.buildEmojiCategories();
		this.buildEmojiGrid(this._emojiCategory);

		$picker.show();
		this._emojiPickerVisible = true;
		$('#whatsapp-emoji-search').val('').focus();
	},

	/**
	 * Hide emoji picker
	 */
	hideEmojiPicker: function() {
		$('#whatsapp-emoji-picker').hide();
		this._emojiPickerVisible = false;
	},

	/**
	 * Build emoji category tabs
	 */
	buildEmojiCategories: function() {
		var html = '';
		for (var key in this._emojiData) {
			var cat = this._emojiData[key];
			var activeClass = (key === this._emojiCategory) ? ' active' : '';
			html += '<button type="button" class="whatsapp-emoji-cat-btn' + activeClass + '" data-category="' + key + '" title="' + cat.label + '">' + cat.icon + '</button>';
		}
		$('#whatsapp-emoji-categories').html(html);
	},

	/**
	 * Build emoji grid for a category
	 */
	buildEmojiGrid: function(category) {
		var emojis = this._emojiData[category] ? this._emojiData[category].emojis : [];
		var html = '';
		for (var i = 0; i < emojis.length; i++) {
			html += '<span class="whatsapp-emoji-item" data-emoji="' + emojis[i] + '" title="' + emojis[i] + '">' + emojis[i] + '</span>';
		}
		$('#whatsapp-emoji-grid').html(html);
	},

	/**
	 * Select emoji category tab
	 */
	selectEmojiCategory: function(category) {
		this._emojiCategory = category;
		this.buildEmojiCategories();
		this.buildEmojiGrid(category);
		$('#whatsapp-emoji-search').val('');
	},

	/**
	 * Filter emojis by search (shows all when searching since visual matching)
	 */
	filterEmojis: function(query) {
		query = (query || '').toLowerCase().trim();
		if (!query) {
			this.buildEmojiGrid(this._emojiCategory);
			return;
		}
		// Show all emojis across all categories when searching
		var html = '';
		for (var key in this._emojiData) {
			var emojis = this._emojiData[key].emojis;
			for (var i = 0; i < emojis.length; i++) {
				html += '<span class="whatsapp-emoji-item" data-emoji="' + emojis[i] + '" title="' + emojis[i] + '">' + emojis[i] + '</span>';
			}
		}
		$('#whatsapp-emoji-grid').html(html);
	},

	/**
	 * Insert emoji into message input at cursor position
	 */
	insertEmoji: function(emoji) {
		var $input = $('#whatsapp-message-input');
		var currentVal = $input.val();
		var cursorPos = $input[0].selectionStart || currentVal.length;
		var newVal = currentVal.substring(0, cursorPos) + emoji + currentVal.substring(cursorPos);
		$input.val(newVal);
		// Set cursor position after emoji
		var newPos = cursorPos + emoji.length;
		$input[0].setSelectionRange(newPos, newPos);
		$input.focus();
	},

	// =============================================
	// Quick Reply Methods
	// =============================================

	/**
	 * Load quick replies from server
	 */
	loadQuickReplies: function() {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/quick_replies.php',
			method: 'GET',
			data: { action: 'list' },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.quick_replies) {
					self.quickReplies = data.quick_replies;
				}
			}
		});
	},

	/**
	 * Toggle quick reply picker visibility
	 */
	toggleQuickReplyPicker: function() {
		if (this.qrPickerVisible) {
			this.hideQuickReplyPicker();
		} else {
			this.showQuickReplyPicker();
		}
	},

	/**
	 * Show quick reply picker
	 */
	showQuickReplyPicker: function() {
		if (this.qrPickerVisible) return;

		var picker = $('#whatsapp-qr-picker');
		var btn = $('#whatsapp-quick-reply-btn');
		if (!btn.length) return;

		// Position picker above the button
		var btnOffset = btn.offset();
		var inputArea = $('#whatsapp-input-area');
		var inputOffset = inputArea.offset();
		if (!btnOffset || !inputOffset) return;

		picker.css({
			bottom: ($(window).height() - inputOffset.top + 4) + 'px',
			left: (btnOffset.left - 10) + 'px'
		});

		this.renderQuickReplyPicker('');
		picker.show();
		this.qrPickerVisible = true;
		$('#whatsapp-qr-picker-search').val('').focus();
	},

	/**
	 * Hide quick reply picker
	 */
	hideQuickReplyPicker: function() {
		$('#whatsapp-qr-picker').hide();
		this.qrPickerVisible = false;
	},

	/**
	 * Render quick reply picker list
	 */
	renderQuickReplyPicker: function(filter) {
		var list = $('#whatsapp-qr-picker-list');
		var emptyMsg = $('#whatsapp-qr-picker-empty');
		list.empty();

		var filtered = this.quickReplies;
		if (filter) {
			var search = filter.toLowerCase().replace(/^\//, '');
			filtered = this.quickReplies.filter(function(qr) {
				return qr.shortcut.toLowerCase().indexOf(search) !== -1 ||
					qr.shortcut.toLowerCase().indexOf('/' + search) !== -1 ||
					qr.title.toLowerCase().indexOf(search) !== -1 ||
					qr.content.toLowerCase().indexOf(search) !== -1;
			});
		}

		if (filtered.length === 0) {
			emptyMsg.show();
			return;
		}

		emptyMsg.hide();

		// Group by category
		var groups = {};
		var noCategory = [];
		filtered.forEach(function(qr) {
			if (qr.category) {
				if (!groups[qr.category]) groups[qr.category] = [];
				groups[qr.category].push(qr);
			} else {
				noCategory.push(qr);
			}
		});

		// Render ungrouped first
		noCategory.forEach(function(qr) {
			list.append(WhatsAppChat.buildQuickReplyItem(qr));
		});

		// Render grouped
		Object.keys(groups).sort().forEach(function(cat) {
			list.append('<div class="whatsapp-qr-picker-category">' + $('<span>').text(cat).html() + '</div>');
			groups[cat].forEach(function(qr) {
				list.append(WhatsAppChat.buildQuickReplyItem(qr));
			});
		});
	},

	/**
	 * Build a single quick reply item HTML
	 */
	buildQuickReplyItem: function(qr) {
		var html = '<div class="whatsapp-qr-picker-item" data-content="' + $('<span>').text(qr.content).html().replace(/"/g, '&quot;') + '">';
		html += '<div class="whatsapp-qr-item-header">';
		html += '<span class="whatsapp-qr-item-shortcut">' + $('<span>').text(qr.shortcut).html() + '</span>';
		html += '<span class="whatsapp-qr-item-title">' + $('<span>').text(qr.title).html() + '</span>';
		html += '</div>';
		html += '<div class="whatsapp-qr-item-preview">' + $('<span>').text(qr.content).html().substring(0, 80);
		if (qr.content.length > 80) html += '...';
		html += '</div>';
		html += '</div>';
		return html;
	},

	/**
	 * Filter quick reply picker
	 */
	filterQuickReplyPicker: function(search) {
		this.renderQuickReplyPicker(search);
	},

	/**
	 * Insert quick reply content into message input
	 */
	insertQuickReply: function(content) {
		$('#whatsapp-message-input').val(content).focus();
		this.hideQuickReplyPicker();
	},

	// ==========================================
	// New Conversation Modal
	// ==========================================

	/**
	 * State for new conversation modal
	 */
	_newConvSelectedRecipient: null,
	_newConvSearchTimer: null,
	_newConvTemplateData: null,

	/**
	 * Open the New Conversation modal
	 */
	openNewConversationModal: function() {
		var self = this;
		this._newConvSelectedRecipient = null;
		this._newConvTemplateData = null;

		// Reset fields
		$('#whatsapp-newconv-search').val('');
		$('#whatsapp-newconv-results').hide().html('');
		$('#whatsapp-newconv-selected').hide();
		$('#whatsapp-newconv-phone').val('');
		$('#whatsapp-newconv-name').val('');
		$('#whatsapp-newconv-manual-group').show();
		$('#whatsapp-newconv-tpl-preview').hide();
		$('#whatsapp-newconv-tpl-vars').html('');
		$('#whatsapp-newconv-status').hide();
		$('#whatsapp-newconv-send').prop('disabled', true);

		// Load templates for the selected line (not copied from chat area)
		var tplSelect = $('#whatsapp-newconv-template');
		tplSelect.find('option:not(:first)').remove();
		var initialLineId = $('#whatsapp-newconv-line').val() || 0;
		this._loadNewConvTemplatesForLine(initialLineId);

		// Reload templates when line changes
		$('#whatsapp-newconv-line').off('change.newconv').on('change.newconv', function() {
			tplSelect.find('option:not(:first)').remove();
			$('#whatsapp-newconv-tpl-preview').hide();
			self._newConvTemplateData = null;
			self._updateNewConvSendState();
			self._loadNewConvTemplatesForLine($(this).val() || 0);
		});

		// Bind search with debounce
		$('#whatsapp-newconv-search').off('input.newconv').on('input.newconv', function() {
			var term = $(this).val().trim();
			clearTimeout(self._newConvSearchTimer);
			if (term.length < 2) {
				$('#whatsapp-newconv-results').hide();
				return;
			}
			$('#whatsapp-newconv-results').show().html('<div class="whatsapp-newconv-searching">' + self._t('Searching') + '...</div>');
			self._newConvSearchTimer = setTimeout(function() {
				self._searchRecipients(term);
			}, 350);
		});

		// Bind template change
		$('#whatsapp-newconv-template').off('change.newconv').on('change.newconv', function() {
			var tplId = $(this).val();
			if (!tplId) {
				$('#whatsapp-newconv-tpl-preview').hide();
				self._newConvTemplateData = null;
				self._updateNewConvSendState();
				return;
			}
			self._loadNewConvTemplate(tplId);
		});

		// Remove selected recipient
		$('#whatsapp-newconv-sel-remove').off('click.newconv').on('click.newconv', function() {
			self._newConvSelectedRecipient = null;
			$('#whatsapp-newconv-selected').hide();
			$('#whatsapp-newconv-manual-group').show();
			$('#whatsapp-newconv-search').val('').focus();
			self._updateNewConvSendState();
		});

		// Manual phone entry enables send button
		$('#whatsapp-newconv-phone').off('input.newconv').on('input.newconv', function() {
			self._updateNewConvSendState();
		});

		// Live preview for template variables
		$(document).off('input.newconv-vars').on('input.newconv-vars', '.whatsapp-newconv-var-input', function() {
			if (!self._newConvTemplateData) return;
			var body = self._newConvTemplateData.body_text || '';
			$('.whatsapp-newconv-var-input').each(function() {
				var num = $(this).data('var-num');
				var val = $(this).val();
				if (val) {
					body = body.replace(new RegExp('\\{\\{' + num + '\\}\\}', 'g'), val);
				}
			});
			$('#whatsapp-newconv-tpl-body').text(body);
		});

		$('#whatsapp-newconv-modal').fadeIn(200);
		setTimeout(function() { $('#whatsapp-newconv-search').focus(); }, 250);
	},

	/**
	 * Close the New Conversation modal
	 */
	closeNewConversationModal: function() {
		$('#whatsapp-newconv-modal').fadeOut(200);
		$(document).off('input.newconv-vars');
	},

	/**
	 * Search recipients via AJAX
	 */
	_searchRecipients: function(term) {
		var self = this;
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/recipients.php',
			method: 'GET',
			data: { search: term, limit: 20 },
			dataType: 'json',
			success: function(data) {
				var $results = $('#whatsapp-newconv-results');
				if (!data.recipients || data.recipients.length === 0) {
					$results.html('<div class="whatsapp-newconv-no-results">' + self._t('NoResultsFound') + '</div>');
					return;
				}
				var phoneTypeLabels = {'mobile': '📱 Móvil', 'phone': '📞 Teléfono', 'personal': '🏠 Personal', 'fax': '📠 Fax', 'company': '🏢 Empresa'};
				var html = '';
				data.recipients.forEach(function(r) {
					var phones = r.phones || [];
					if (phones.length === 0) return;

					html += '<div class="whatsapp-newconv-result-group">';
					html += '<div class="whatsapp-newconv-result-name">' + self.escapeHtml(r.name);
					if (r.company && r.company !== r.name) html += ' <small>(' + self.escapeHtml(r.company) + ')</small>';
					html += '</div>';

					phones.forEach(function(p) {
						var phoneLabel = phoneTypeLabels[p.type] || p.type;
						var recipientData = {
							id: r.id + '_' + p.type,
							name: r.name,
							phone: p.number,
							phone_type: p.type,
							company: r.company || '',
							fk_soc: r.fk_soc || 0,
							source: r.source,
							source_id: r.source_id
						};
						html += '<div class="whatsapp-newconv-phone-option" data-recipient=\'' + self.escapeHtml(JSON.stringify(recipientData)) + '\'>';
						html += '<span class="whatsapp-newconv-phone-number">' + self.escapeHtml(p.number) + '</span>';
						html += '<span class="whatsapp-newconv-phone-type">' + phoneLabel + '</span>';
						html += '</div>';
					});

					html += '</div>';
				});
				$results.html(html);

				// Bind click on individual phone options
				$results.find('.whatsapp-newconv-phone-option').on('click', function() {
					var recipient = JSON.parse($(this).attr('data-recipient'));
					self._selectNewConvRecipient(recipient);
				});
			},
			error: function() {
				$('#whatsapp-newconv-results').html('<div class="whatsapp-newconv-no-results">Error</div>');
			}
		});
	},

	/**
	 * Select a recipient from search results
	 */
	_selectNewConvRecipient: function(recipient) {
		this._newConvSelectedRecipient = recipient;
		var phoneTypeLabels = {'mobile': '📱 Móvil', 'phone': '📞 Teléfono', 'personal': '🏠 Personal', 'fax': '📠 Fax'};
		var typeLabel = phoneTypeLabels[recipient.phone_type] || '';
		$('#whatsapp-newconv-sel-name').text(recipient.name);
		$('#whatsapp-newconv-sel-phone').text(recipient.phone + (typeLabel ? ' — ' + typeLabel : ''));
		$('#whatsapp-newconv-selected').show();
		$('#whatsapp-newconv-results').hide();
		$('#whatsapp-newconv-search').val('');
		$('#whatsapp-newconv-manual-group').hide();
		this._updateNewConvSendState();
	},

	/**
	 * Load templates for a specific line into the new conversation modal dropdown
	 */
	_loadNewConvTemplatesForLine: function(lineId) {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/sync_templates.php',
			method: 'GET',
			data: { action: 'list', line_id: lineId },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.templates) {
					var select = $('#whatsapp-newconv-template');
					data.templates.forEach(function(tpl) {
						select.append('<option value="' + tpl.rowid + '">' + WhatsAppChat.escapeHtml(tpl.name) + ' (' + tpl.language + ')</option>');
					});
				}
			}
		});
	},

	/**
	 * Load template detail for the new conversation modal
	 */
	_loadNewConvTemplate: function(templateId) {
		var self = this;
		// Use cache if available
		if (this.templateCache[templateId]) {
			self._newConvTemplateData = this.templateCache[templateId];
			self._renderNewConvTemplate(self._newConvTemplateData);
			return;
		}
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/template_detail.php',
			method: 'GET',
			data: { id: templateId },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.templateCache[templateId] = data.template;
					self._newConvTemplateData = data.template;
					self._renderNewConvTemplate(data.template);
				}
			}
		});
	},

	/**
	 * Render template preview in new conversation modal
	 */
	_renderNewConvTemplate: function(template) {
		$('#whatsapp-newconv-tpl-body').text(template.body_text || '');
		var vars = template.variables || [];
		var varMapping = template.variable_mapping || {};
		var recipient = this._newConvSelectedRecipient || {};
		var operatorName = (typeof WhatsAppCurrentUserName !== 'undefined') ? WhatsAppCurrentUserName : '';
		var today = new Date().toLocaleDateString();
		var html = '';
		if (vars.length > 0) {
			for (var i = 0; i < vars.length; i++) {
				var varNum = vars[i];
				var cfg = varMapping[varNum] || { type: 'free_text', label: '', default_value: '' };
				var autoValue = '';
				var isAutoResolved = false;
				var sourceLabel = cfg.label || '';

				switch (cfg.type) {
					case 'contact_name':
						autoValue = recipient.name || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeContactName') || 'Contact';
						break;
					case 'operator_name':
						autoValue = operatorName;
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeOperatorName') || 'Operator';
						break;
					case 'phone':
						autoValue = recipient.phone || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypePhone') || 'Phone';
						break;
					case 'date_today':
						autoValue = today;
						isAutoResolved = true;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeDateToday') || 'Date';
						break;
					case 'fixed_text':
						autoValue = cfg.default_value || '';
						isAutoResolved = !!autoValue;
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeFixedText') || 'Fixed';
						break;
					case 'company_name':
						// Not available in new conv context, will be resolved server-side
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeCompanyName') || 'Company';
						break;
					case 'url':
						autoValue = cfg.default_value || '';
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeUrl') || 'URL';
						break;
					case 'free_text':
					default:
						autoValue = cfg.default_value || '';
						if (!sourceLabel) sourceLabel = WhatsAppChat._t('VarTypeFreeText') || 'Text';
						break;
				}

				html += '<div class="whatsapp-hook-var-field">';
				html += '<label class="whatsapp-hook-var-label">{{' + varNum + '}} <span style="font-weight:normal;color:#888;font-size:11px;">(' + this.escapeHtml(sourceLabel) + ')</span></label>';

				if (isAutoResolved && cfg.type !== 'free_text' && cfg.type !== 'url') {
					html += '<div style="display:flex;align-items:center;gap:8px;">';
					html += '<input type="text" class="whatsapp-newconv-var-input flat" data-var-num="' + varNum + '" value="' + this.escapeHtml(autoValue) + '" readonly style="background:#f0fdf4;border-color:#86efac;color:#166534;" />';
					html += '<span style="font-size:11px;color:#16a34a;white-space:nowrap;">✓ ' + WhatsAppChat._t('Auto') + '</span>';
					html += '</div>';
				} else {
					html += '<input type="text" class="whatsapp-newconv-var-input flat" data-var-num="' + varNum + '" value="' + this.escapeHtml(autoValue) + '" placeholder="' + this._t('TemplateVariables') + ' ' + varNum + '" />';
				}

				html += '</div>';
			}
		}
		// Header image upload for on_send templates (IMAGE/VIDEO/DOCUMENT with no pre-loaded media)
		var headerUploadHtml = '';
		if (['IMAGE', 'VIDEO', 'DOCUMENT'].indexOf(template.header_type) !== -1 && template.header_image_mode === 'on_send') {
			headerUploadHtml += '<div class="whatsapp-header-upload" style="margin-bottom:12px;padding:10px;background:#f0f9ff;border:1px dashed #7dd3fc;border-radius:8px;">';
			headerUploadHtml += '  <label style="display:block;font-size:12px;font-weight:600;color:#0369a1;margin-bottom:6px;">📷 ' + WhatsAppChat._t('HeaderImage') + '</label>';
			headerUploadHtml += '  <input type="file" id="whatsapp-newconv-header-image" accept="image/*,video/*,application/pdf" style="font-size:12px;" />';
			headerUploadHtml += '  <div style="font-size:11px;color:#888;margin-top:4px;">' + WhatsAppChat._t('HeaderImageOnSendHelp') + '</div>';
			headerUploadHtml += '</div>';
		}
		$('#whatsapp-newconv-tpl-vars').html(headerUploadHtml + html);
		$('#whatsapp-newconv-tpl-preview').show();
		this._updateNewConvSendState();
	},

	/**
	 * Update the enabled/disabled state of the New Conversation Send button
	 */
	_updateNewConvSendState: function() {
		var hasRecipient = !!this._newConvSelectedRecipient;
		var hasManualPhone = ($('#whatsapp-newconv-phone').val() || '').trim().length >= 5;
		var hasTemplate = !!$('#whatsapp-newconv-template').val();
		$('#whatsapp-newconv-send').prop('disabled', !(hasRecipient || hasManualPhone) || !hasTemplate);
	},

	/**
	 * Send the new conversation message
	 */
	sendNewConversation: function() {
		var self = this;
		var phone, contactName, fkSoc;

		if (this._newConvSelectedRecipient) {
			phone = this._newConvSelectedRecipient.phone;
			contactName = this._newConvSelectedRecipient.name;
			fkSoc = this._newConvSelectedRecipient.fk_soc || 0;
		} else {
			phone = ($('#whatsapp-newconv-phone').val() || '').trim();
			contactName = ($('#whatsapp-newconv-name').val() || '').trim() || phone;
			fkSoc = 0;
		}

		if (!phone) {
			$('#whatsapp-newconv-status').css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'})
				.text(self._t('SelectRecipientOrPhone')).show();
			return;
		}

		var templateId = $('#whatsapp-newconv-template').val();
		if (!templateId) return;

		// Validate variable values
		var params = [];
		var valid = true;
		$('.whatsapp-newconv-var-input').each(function() {
			var val = $(this).val().trim();
			if (!val) {
				$(this).css('border-color', '#dc3545');
				valid = false;
			} else {
				$(this).css('border-color', '');
			}
			params.push(val);
		});
		if (!valid) return;

		var lineId = $('#whatsapp-newconv-line').val() || 0;

		// Check for header image file
		var headerFileInput = document.getElementById('whatsapp-newconv-header-image');
		var headerImageFile = headerFileInput && headerFileInput.files && headerFileInput.files.length > 0 ? headerFileInput.files[0] : null;

		$('#whatsapp-newconv-send').prop('disabled', true).text(self._t('Sending'));

		var baseData = {
			phone: phone,
			contact_name: contactName,
			fk_soc: fkSoc,
			line_id: lineId,
			template_id: templateId,
			template_params: JSON.stringify(params),
			token: $('input[name="token"]').val()
		};

		var ajaxOptions = {
			url: WhatsAppAjaxBase + 'ajax/send_message.php',
			method: 'POST',
			dataType: 'json',
			success: function(response) {
				if (response.success) {
					$('#whatsapp-newconv-status').css({background: '#d4edda', color: '#155724', border: '1px solid #c3e6cb'})
						.text(self._t('MessageSent')).show();
					self.loadConversations(true);
					// Auto-close modal after short delay and select the conversation
					setTimeout(function() {
						self.closeNewConversationModal();
						// If conversation_id is returned, select it
						if (response.conversation_id) {
							self.selectConversation(response.conversation_id);
						}
					}, 1200);
				} else {
					$('#whatsapp-newconv-status').css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'})
						var ncErrMsg = self._t('MessageFailed') + ': ' + (response.error || '');
						if (response.debug) ncErrMsg += ' | DEBUG: ' + JSON.stringify(response.debug);
						$('#whatsapp-newconv-status').css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'})
						.text(ncErrMsg).show();
				}
			},
			error: function() {
				$('#whatsapp-newconv-status').css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'})
					.text(self._t('MessageFailed')).show();
			},
			complete: function() {
				$('#whatsapp-newconv-send').prop('disabled', false).text(self._t('StartConversation'));
			}
		};

		if (headerImageFile) {
			var formData = new FormData();
			Object.keys(baseData).forEach(function(k) { formData.append(k, baseData[k]); });
			formData.append('header_image', headerImageFile);
			ajaxOptions.data = formData;
			ajaxOptions.processData = false;
			ajaxOptions.contentType = false;
		} else {
			ajaxOptions.data = baseData;
		}

		$.ajax(ajaxOptions);
	}
};

/**
 * Bulk Send Module
 */
var BulkSend = {
	selectedTemplate: null,
	selectedRecipients: {},
	templateVariables: [],
	currentBatchId: null,
	progressPollTimer: null,
	_eventsBound: false, // L9: guard

	init: function() {
		this.loadTemplates();
		this.bindEvents();
	},

	bindEvents: function() {
		// L9: Prevent duplicate binding
		if (this._eventsBound) return;
		this._eventsBound = true;

		var self = this;

		// Template selection
		$(document).on('change', '#bulk-template-select', function() {
			var templateId = $(this).val();
			if (templateId) {
				self.loadTemplateDetail(templateId);
			} else {
				self.selectedTemplate = null;
				$('#bulk-template-preview').hide();
				$('#bulk-template-variables').hide();
				self.updateSummary();
			}
		});

		// Sync templates
		$(document).on('click', '#bulk-sync-templates-btn', function() {
			self.syncAndLoadTemplates();
		});

		// Recipient search
		$(document).on('click', '#bulk-search-btn', function() {
			self.searchRecipients();
		});
		$(document).on('keypress', '#bulk-recipient-search', function(e) {
			if (e.which === 13) {
				e.preventDefault();
				self.searchRecipients();
			}
		});

		// Select all results
		$(document).on('click', '#bulk-select-all-btn', function() {
			$('.bulk-result-checkbox:not(:checked)').each(function() {
				$(this).prop('checked', true).trigger('change');
			});
		});

		// Toggle recipient selection
		$(document).on('change', '.bulk-result-checkbox', function() {
			var id = $(this).data('id');
			var data = $(this).data('recipient');
			if ($(this).is(':checked')) {
				self.addRecipient(id, data);
			} else {
				self.removeRecipient(id);
			}
		});

		// Remove chip
		$(document).on('click', '.bulk-chip-remove', function() {
			var id = $(this).data('id');
			self.removeRecipient(id);
			$('.bulk-result-checkbox[data-id="' + id + '"]').prop('checked', false);
		});

		// Variable input update
		$(document).on('input', '.bulk-var-input', function() {
			self.updateBulkPreview();
			self.updateSummary();
		});

		// Send button
		$(document).on('click', '#bulk-send-btn', function() {
			self.startBulkSend();
		});

		// Cancel button
		$(document).on('click', '#bulk-cancel-btn', function() {
			self.cancelBulkSend();
		});

		// Line change — reload templates for selected line
		$(document).on('change', '#bulk-line-select', function() {
			self.loadTemplates();
			$('#bulk-template-select').val('').trigger('change');
		});
	},

	getSelectedLineId: function() {
		var el = $('#bulk-line-select');
		return el.length ? (parseInt(el.val()) || 0) : 0;
	},

	loadTemplates: function() {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/sync_templates.php',
			method: 'GET',
			data: { action: 'list', line_id: this.getSelectedLineId() },
			dataType: 'json',
			success: function(data) {
				if (data.success && data.templates) {
					var select = $('#bulk-template-select');
					select.find('option:not(:first)').remove();
					data.templates.forEach(function(tpl) {
						select.append('<option value="' + tpl.rowid + '">' + BulkSend.escapeHtml(tpl.name) + ' (' + tpl.language + ')</option>');
					});
				}
			}
		});
	},

	syncAndLoadTemplates: function() {
		var btn = $('#bulk-sync-templates-btn');
		btn.prop('disabled', true).text('...');
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/sync_templates.php',
			method: 'POST',
			data: { action: 'sync', line_id: this.getSelectedLineId(), token: $('input[name="token"]').val() || '' },
			dataType: 'json',
			success: function() {
				BulkSend.loadTemplates();
			},
			complete: function() {
				btn.prop('disabled', false).text(btn.data('label') || _wt('BulkSync'));
			}
		});
	},

	loadTemplateDetail: function(templateId) {
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/template_detail.php',
			method: 'GET',
			data: { id: templateId },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					BulkSend.selectedTemplate = data.template;
					BulkSend.showTemplatePreview(data.template);
					BulkSend.showVariableFields(data.template);
					BulkSend.updateSummary();
				}
			}
		});
	},

	showTemplatePreview: function(tpl) {
		var headerText = '';
		if (tpl.header_text) headerText = tpl.header_text;
		$('#bulk-preview-header').html(headerText ? BulkSend.escapeHtml(headerText) : '').toggle(!!headerText);
		$('#bulk-preview-body').html(BulkSend.escapeHtml(tpl.body_text || '').replace(/\n/g, '<br>'));
		$('#bulk-preview-footer').html(tpl.footer_text ? '<em>' + BulkSend.escapeHtml(tpl.footer_text) + '</em>' : '').toggle(!!tpl.footer_text);
		$('#bulk-template-preview').show();
	},

	showVariableFields: function(tpl) {
		var container = $('#bulk-template-variables');
		container.empty();

		// Extract variables from body
		var body = tpl.body_text || '';
		var regex = /\{\{(\d+)\}\}/g;
		var match;
		var vars = [];
		while ((match = regex.exec(body)) !== null) {
			vars.push(parseInt(match[1]));
		}

		BulkSend.templateVariables = vars;
		var varMapping = tpl.variable_mapping || {};
		var operatorName = (typeof WhatsAppCurrentUserName !== 'undefined') ? WhatsAppCurrentUserName : '';
		var today = new Date().toLocaleDateString();

		if (vars.length === 0) {
			container.html('<p class="opacitymedium">' + _wt('BulkNoVars') + '</p>');
			container.show();
			return;
		}

		var html = '<table class="noborder centpercent">';
		html += '<tr class="liste_titre"><th>Variable</th><th>Tipo</th><th>Valor</th></tr>';
		vars.forEach(function(varNum) {
			var cfg = varMapping[varNum] || { type: 'free_text', label: '', default_value: '' };
			var autoValue = '';
			var isAutoResolved = false;
			var typeLabel = cfg.type || 'free_text';

			// Auto-resolve constant values for bulk
			switch (cfg.type) {
				case 'operator_name':
					autoValue = operatorName;
					isAutoResolved = !!autoValue;
					break;
				case 'date_today':
					autoValue = today;
					isAutoResolved = true;
					break;
				case 'fixed_text':
					autoValue = cfg.default_value || '';
					isAutoResolved = !!autoValue;
					break;
				case 'contact_name':
				case 'company_name':
				case 'phone':
					// These are per-recipient, resolved server-side for bulk
					typeLabel = cfg.type + ' (' + _wt('Auto') + ')';
					break;
			}

			html += '<tr class="oddeven">';
			html += '<td><strong>{{' + varNum + '}}</strong></td>';
			html += '<td style="font-size:12px;color:#888;">' + BulkSend.escapeHtml(cfg.label || typeLabel) + '</td>';

			if (isAutoResolved) {
				html += '<td><input type="text" class="flat minwidth200 bulk-var-input" data-var="' + varNum + '" value="' + BulkSend.escapeHtml(autoValue) + '" readonly style="background:#f0fdf4;border-color:#86efac;color:#166534;" /> <span style="font-size:11px;color:#16a34a;">✓ ' + _wt('Auto') + '</span></td>';
			} else {
				html += '<td><input type="text" class="flat minwidth200 bulk-var-input" data-var="' + varNum + '" value="' + BulkSend.escapeHtml(cfg.default_value || '') + '" placeholder="' + _wt('ValueForVar', varNum) + '" /></td>';
			}

			html += '</tr>';
		});
		html += '</table>';
		container.html(html);
		container.show();
	},

	updateBulkPreview: function() {
		if (!BulkSend.selectedTemplate) return;
		var body = BulkSend.selectedTemplate.body_text || '';

		$('.bulk-var-input').each(function() {
			var varNum = $(this).data('var');
			var val = $(this).val() || '{{' + varNum + '}}';
			body = body.replace(new RegExp('\\{\\{' + varNum + '\\}\\}', 'g'), val);
		});

		$('#bulk-preview-body').html(BulkSend.escapeHtml(body).replace(/\n/g, '<br>'));
	},

	searchRecipients: function() {
		var query = $('#bulk-recipient-search').val().trim();
		if (query.length < 2) return;

		var resultsContainer = $('#bulk-search-results-list');
		resultsContainer.html('<div class="opacitymedium">' + _wt('BulkSearching') + '</div>');
		$('#bulk-search-results').show();

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/recipients.php',
			method: 'GET',
			data: { search: query },
			dataType: 'json',
			success: function(data) {
				resultsContainer.empty();
				if (!data.recipients || data.recipients.length === 0) {
					resultsContainer.html('<div class="opacitymedium">' + _wt('BulkNoContacts') + '</div>');
					return;
				}

				// Flatten grouped recipients: expand each contact's phones into individual items
				data.recipients.forEach(function(r) {
					var phones = r.phones || [];
					phones.forEach(function(p) {
						var flatItem = {
							id: r.id + '_' + p.type,
							name: r.name,
							phone: p.number,
							phone_type: p.type,
							company: r.company || '',
							fk_soc: r.fk_soc || 0,
							source: r.source,
							source_id: r.source_id
						};
						var checked = BulkSend.selectedRecipients[flatItem.id] ? ' checked' : '';
						var typeLabel = p.type === 'mobile' ? '📱' : (p.type === 'company' ? '🏢' : '📞');
						var recipientJson = WhatsAppChat.escapeAttr(JSON.stringify(flatItem));
						var html = '<label class="bulk-result-item">';
						html += '<input type="checkbox" class="bulk-result-checkbox" data-id="' + flatItem.id + '" data-recipient="' + recipientJson + '"' + checked + ' />';
						html += '<span class="bulk-result-name">' + BulkSend.escapeHtml(flatItem.name) + '</span>';
						html += '<span class="bulk-result-phone">' + typeLabel + ' ' + BulkSend.escapeHtml(p.number) + '</span>';
						if (r.company) {
							html += '<span class="bulk-result-company">' + BulkSend.escapeHtml(r.company) + '</span>';
						}
						html += '</label>';
						resultsContainer.append(html);
					});
				});
			}
		});
	},

	addRecipient: function(id, data) {
		if (typeof data === 'string') {
			try { data = JSON.parse(data); } catch(e) { return; }
		}
		BulkSend.selectedRecipients[id] = data;
		BulkSend.renderChips();
		BulkSend.updateSummary();
	},

	removeRecipient: function(id) {
		delete BulkSend.selectedRecipients[id];
		BulkSend.renderChips();
		BulkSend.updateSummary();
	},

	renderChips: function() {
		var container = $('#bulk-recipients-chips');
		container.empty();

		var count = Object.keys(BulkSend.selectedRecipients).length;
		$('#bulk-recipient-count').text(count > 0 ? _wt('BulkSelected', count) : '');

		$.each(BulkSend.selectedRecipients, function(id, r) {
			var chip = '<span class="bulk-chip">';
			chip += '<span class="bulk-chip-name">' + BulkSend.escapeHtml(r.name) + '</span>';
			chip += '<span class="bulk-chip-phone">' + BulkSend.escapeHtml(r.phone) + '</span>';
			chip += '<span class="bulk-chip-remove" data-id="' + id + '">&times;</span>';
			chip += '</span>';
			container.append(chip);
		});
	},

	updateSummary: function() {
		var recipientCount = Object.keys(BulkSend.selectedRecipients).length;
		var hasTemplate = !!BulkSend.selectedTemplate;

		$('#bulk-summary-template').text(hasTemplate ? BulkSend.selectedTemplate.name : '-');
		$('#bulk-summary-recipients').text(recipientCount);

		// Show variable values
		var varSummary = [];
		$('.bulk-var-input').each(function() {
			var v = $(this).val();
			if (v) varSummary.push('{{' + $(this).data('var') + '}} = ' + v);
		});
		$('#bulk-summary-variables').text(varSummary.length > 0 ? varSummary.join(', ') : '-');

		var canSend = hasTemplate && recipientCount > 0;
		$('#bulk-send-btn').prop('disabled', !canSend);
		$('#bulk-send-summary').toggle(canSend);
	},

	startBulkSend: function() {
		if (!BulkSend.selectedTemplate) return;
		var recipients = [];
		var params = [];

		// Gather variable values
		$('.bulk-var-input').each(function() {
			params.push($(this).val() || '');
		});

		// Build recipients array
		$.each(BulkSend.selectedRecipients, function(id, r) {
			recipients.push({
				phone: r.phone,
				name: r.name,
				fk_soc: r.fk_soc || 0
			});
		});

		if (recipients.length === 0) return;

		// Disable form
		$('#bulk-send-btn').prop('disabled', true).text(WhatsAppChat._t('Sending'));
		$('#bulk-send-progress').show();
		$('#bulk-progress-actions').hide();
		$('#bulk-send-form .bulk-send-section').css('opacity', '0.5').find('input, select, button').prop('disabled', true);

		// Create batch
		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/process_queue.php?action=create_batch&token=' + encodeURIComponent($('input[name="token"]').val()),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({
				template_id: BulkSend.selectedTemplate.rowid || BulkSend.selectedTemplate.id,
				template_name: BulkSend.selectedTemplate.name,
				recipients: recipients,
				params: params,
				line_id: BulkSend.getSelectedLineId()
			}),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					BulkSend.currentBatchId = data.batch_id;
					$('#bulk-stat-total').text(data.total);
					$('#bulk-stat-pending').text(data.total);
					BulkSend.processNextBatch();
				} else {
					alert('Error: ' + (data.error || WhatsAppChat._t('UnknownError')));
					BulkSend.resetForm();
				}
			},
			error: function() {
				alert(WhatsAppChat._t('ConnectionError'));
				BulkSend.resetForm();
			}
		});
	},

	processNextBatch: function() {
		if (!BulkSend.currentBatchId) return;

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/process_queue.php',
			method: 'POST',
			data: {
				action: 'process',
				batch_id: BulkSend.currentBatchId,
				limit: 10,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success && data.stats) {
					BulkSend.updateProgressUI(data.stats);

					if (!data.done) {
						// Continue processing
						BulkSend.progressPollTimer = setTimeout(function() {
							BulkSend.processNextBatch();
						}, 1000);
					} else {
						BulkSend.onBatchComplete(data.stats);
					}
				}
			},
			error: function() {
				// Retry after delay
				BulkSend.progressPollTimer = setTimeout(function() {
					BulkSend.processNextBatch();
				}, 3000);
			}
		});
	},

	updateProgressUI: function(stats) {
		var total = stats.total || 1;
		var done = (stats.sent || 0) + (stats.failed || 0) + (stats.cancelled || 0);
		var pct = Math.round(done / total * 100);

		$('#bulk-progress-bar').css('width', pct + '%');
		$('#bulk-progress-text').text(pct + '%');
		$('#bulk-stat-total').text(stats.total);
		$('#bulk-stat-sent').text(stats.sent || 0);
		$('#bulk-stat-failed').text(stats.failed || 0);
		$('#bulk-stat-pending').text(stats.pending || 0);
	},

	onBatchComplete: function(stats) {
		$('#bulk-progress-bar').css('width', '100%');
		$('#bulk-progress-text').text('100%');
		$('#bulk-progress-actions').show();
		$('#bulk-cancel-btn').hide();
		BulkSend.updateProgressUI(stats);
	},

	cancelBulkSend: function() {
		if (!BulkSend.currentBatchId) return;

		if (BulkSend.progressPollTimer) {
			clearTimeout(BulkSend.progressPollTimer);
		}

		$.ajax({
			url: WhatsAppAjaxBase + 'ajax/process_queue.php',
			method: 'POST',
			data: {
				action: 'cancel',
				batch_id: BulkSend.currentBatchId,
				token: $('input[name="token"]').val()
			},
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					// Refresh stats
					$.ajax({
						url: WhatsAppAjaxBase + 'ajax/process_queue.php',
						method: 'GET',
						data: { action: 'status', batch_id: BulkSend.currentBatchId },
						dataType: 'json',
						success: function(d) {
							if (d.stats) BulkSend.updateProgressUI(d.stats);
							BulkSend.onBatchComplete(d.stats);
						}
					});
				}
			}
		});
	},

	resetForm: function() {
		$('#bulk-send-btn').prop('disabled', false).text(_wt('BulkStartSend'));
		$('#bulk-send-form .bulk-send-section').css('opacity', '1').find('input, select, button').prop('disabled', false);
	},

	escapeHtml: function(text) {
		// L4: Delegate to shared implementation
		return WhatsAppChat.escapeHtml(text);
	}
};

// Initialize on document ready
$(document).ready(function() {
	if ($('#whatsapp-chat-container').length) {
		WhatsAppChat.init();
		WhatsAppCRM.init();
	}
	if ($('#bulk-send-form').length) {
		BulkSend.init();
	}
});

// ==========================================
// CRM Integration (Link Third Party + Create Lead)
// ==========================================
var WhatsAppCRM = {
	ajaxUrl: WhatsAppAjaxBase + 'ajax/leads.php',
	searchTimer: null,
	currentSoc: null,
	_eventsBound: false, // L9: guard

	init: function() {
		this.bindEvents();
	},

	bindEvents: function() {
		// L9: Prevent duplicate binding
		if (this._eventsBound) return;
		this._eventsBound = true;

		var self = this;

		// Open link modal
		$(document).on('click', '#btn-crm-link', function() {
			self.openLinkModal();
		});

		// Open lead modal
		$(document).on('click', '#btn-crm-lead', function() {
			self.openLeadModal();
		});

		// Close modals
		$(document).on('click', '.crm-modal-close', function() {
			$('.whatsapp-crm-modal').hide();
		});
		$(document).on('click', '.whatsapp-crm-modal', function(e) {
			if ($(e.target).hasClass('whatsapp-crm-modal')) $(this).hide();
		});

		// Search input with debounce
		$(document).on('input', '#crm-search-input', function() {
			var query = $(this).val().trim();
			clearTimeout(self.searchTimer);
			if (query.length >= 2) {
				self.searchTimer = setTimeout(function() {
					self.searchThirdParties(query);
				}, 300);
			} else {
				$('#crm-search-results').html('');
			}
		});

		// Select a search result
		$(document).on('click', '.crm-result-item', function() {
			var socId = $(this).data('soc-id');
			self.linkThirdParty(socId);
		});

		// Create new third party
		$(document).on('click', '#btn-crm-create-soc', function() {
			self.createThirdParty();
		});

		// Unlink third party
		$(document).on('click', '#whatsapp-crm-unlink', function() {
			self.unlinkThirdParty();
		});

		// Create lead
		$(document).on('click', '#btn-crm-create-lead', function() {
			self.createLead();
		});
	},

	/**
	 * Update CRM area based on conversation data
	 */
	updateCrmState: function(conversation) {
		var self = this;

		if (conversation.thirdparty && conversation.fk_soc > 0) {
			this.currentSoc = conversation.thirdparty;
			var badge = '<a href="' + conversation.thirdparty.url + '" target="_blank" class="crm-badge-link">';
			badge += '🏢 ' + this.escapeHtml(conversation.thirdparty.name) + '</a>';
			$('#whatsapp-crm-badge').html(badge);
			$('#whatsapp-crm-linked').show();
			$('#btn-crm-link').hide();
			$('#btn-crm-lead').show();
		} else {
			this.currentSoc = null;
			$('#whatsapp-crm-linked').hide();
			$('#btn-crm-link').show();
			$('#btn-crm-lead').hide();
		}
	},

	/**
	 * Open the link third party modal
	 */
	openLinkModal: function() {
		$('#crm-search-input').val('');
		$('#crm-search-results').html('');
		$('#crm-new-name').val('');
		$('#crm-new-email').val('');
		$('#crm-new-client-type').val('2');

		// Pre-fill phone from current conversation
		var conv = WhatsAppChat.conversationsData[WhatsAppChat.currentConversationId];
		if (conv) {
			$('#crm-new-phone').val(conv.phone_number || '');
			if (conv.contact_name) {
				$('#crm-new-name').val(conv.contact_name);
			}
		}

		$('#crm-link-modal').show();
		$('#crm-search-input').focus();
	},

	/**
	 * Search third parties
	 */
	searchThirdParties: function(query) {
		var self = this;
		$('#crm-search-results').html('<div class="crm-searching">' + _wt('CrmSearching') + '</div>');

		$.ajax({
			url: this.ajaxUrl,
			data: { action: 'search_thirdparty', query: query },
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.renderSearchResults(data.results);
				} else {
					$('#crm-search-results').html('<div class="crm-no-results">Error</div>');
				}
			}
		});
	},

	/**
	 * Render search results
	 */
	renderSearchResults: function(results) {
		var html = '';
		if (!results || results.length === 0) {
			html = '<div class="crm-no-results">' + _wt('CrmNoResults') + '</div>';
		} else {
			for (var i = 0; i < results.length; i++) {
				var r = results[i];
				html += '<div class="crm-result-item" data-soc-id="' + r.id + '">';
				html += '<div class="crm-result-name">🏢 ' + this.escapeHtml(r.name);
				if (r.name_alias) html += ' <small class="opacitymedium">(' + this.escapeHtml(r.name_alias) + ')</small>';
				html += '</div>';
				html += '<div class="crm-result-details">';
				if (r.phone) html += '📞 ' + this.escapeHtml(r.phone) + ' ';
				if (r.email) html += '✉️ ' + this.escapeHtml(r.email) + ' ';
				if (r.town) html += '📍 ' + this.escapeHtml(r.town);
				html += '</div>';
				html += '<div class="crm-result-type">' + this.escapeHtml(r.type);
				if (r.via_contact) html += ' <small>' + _wt('CrmViaContact', this.escapeHtml(r.via_contact)) + '</small>';
				html += '</div>';
				html += '</div>';
			}
		}
		$('#crm-search-results').html(html);
	},

	/**
	 * Link conversation to a third party
	 */
	linkThirdParty: function(socId) {
		var self = this;
		var convId = WhatsAppChat.currentConversationId;
		if (!convId) return;

		$.ajax({
			url: this.ajaxUrl + '?action=link_thirdparty&token=' + encodeURIComponent($('input[name="token"]').val()),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ conversation_id: convId, soc_id: socId }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#crm-link-modal').hide();
					self.currentSoc = { id: data.soc_id, name: data.soc_name, url: data.soc_url };
					var badge = '<a href="' + WhatsAppCRM.escapeAttr(data.soc_url) + '" target="_blank" class="crm-badge-link">🏢 ' + self.escapeHtml(data.soc_name) + '</a>';
					$('#whatsapp-crm-badge').html(badge);
					$('#whatsapp-crm-linked').show();
					$('#btn-crm-link').hide();
					$('#btn-crm-lead').show();
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	/**
	 * Unlink third party from conversation
	 */
	unlinkThirdParty: function() {
		var self = this;
		var convId = WhatsAppChat.currentConversationId;
		if (!convId) return;

		$.ajax({
			url: this.ajaxUrl + '?action=link_thirdparty&token=' + encodeURIComponent($('input[name="token"]').val()),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ conversation_id: convId, soc_id: 0 }),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					self.currentSoc = null;
					$('#whatsapp-crm-linked').hide();
					$('#btn-crm-link').show();
					$('#btn-crm-lead').hide();
				}
			}
		});
	},

	/**
	 * Create new third party and link to conversation
	 */
	createThirdParty: function() {
		var self = this;
		var convId = WhatsAppChat.currentConversationId;
		var name = $('#crm-new-name').val().trim();

		if (!name) {
			alert(_wt('CrmNameRequired'));
			return;
		}

		$.ajax({
			url: this.ajaxUrl + '?action=create_thirdparty&token=' + encodeURIComponent($('input[name="token"]').val()),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({
				conversation_id: convId,
				name: name,
				phone: $('#crm-new-phone').val().trim(),
				email: $('#crm-new-email').val().trim(),
				client_type: parseInt($('#crm-new-client-type').val())
			}),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#crm-link-modal').hide();
					self.currentSoc = { id: data.soc_id, name: data.soc_name, url: data.soc_url };
					var badge = '<a href="' + WhatsAppCRM.escapeAttr(data.soc_url) + '" target="_blank" class="crm-badge-link">🏢 ' + self.escapeHtml(data.soc_name) + '</a>';
					$('#whatsapp-crm-badge').html(badge);
					$('#whatsapp-crm-linked').show();
					$('#btn-crm-link').hide();
					$('#btn-crm-lead').show();
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	/**
	 * Open lead creation modal
	 */
	openLeadModal: function() {
		var conv = WhatsAppChat.conversationsData[WhatsAppChat.currentConversationId];
		var contactName = (conv ? (conv.contact_name || conv.phone_number) : '');

		$('#crm-lead-title').val(_wt('CrmLeadPrefix') + contactName);
		$('#crm-lead-description').val('');
		$('#crm-lead-amount').val('0');
		$('#crm-lead-percent').val('10');

		if (this.currentSoc) {
			$('#crm-lead-soc-info').html(_wt('CrmThirdParty', this.escapeHtml(this.currentSoc.name)));
		}

		$('#crm-lead-modal').show();
		$('#crm-lead-title').focus();
	},

	/**
	 * Create lead/opportunity
	 */
	createLead: function() {
		var self = this;
		var convId = WhatsAppChat.currentConversationId;
		var title = $('#crm-lead-title').val().trim();

		if (!title) {
			alert(_wt('CrmTitleRequired'));
			return;
		}

		$.ajax({
			url: this.ajaxUrl + '?action=create_lead&token=' + encodeURIComponent($('input[name="token"]').val()),
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({
				conversation_id: convId,
				title: title,
				description: $('#crm-lead-description').val(),
				opp_amount: parseFloat($('#crm-lead-amount').val()) || 0,
				opp_percent: parseInt($('#crm-lead-percent').val()) || 10
			}),
			dataType: 'json',
			success: function(data) {
				if (data.success) {
					$('#crm-lead-modal').hide();
					var msg = data.message + ' (' + data.project_ref + ')';
					if (confirm(msg + '\n\n' + _wt('CrmOpenLead'))) {
						window.open(data.project_url, '_blank');
					}
				} else {
					alert(data.error || 'Error');
				}
			}
		});
	},

	escapeHtml: function(text) {
		// L4: Delegate to shared implementation
		return WhatsAppChat.escapeHtml(text);
	},

	escapeAttr: function(text) {
		// L4: Delegate to shared implementation
		return WhatsAppChat.escapeAttr(text);
	}
};

// ============================================================
// FLOATING WIDGET BOOTSTRAP
// Loaded on every page via module_parts['js'].
// If the hook system didn't inject variables, derive them from
// this script's own URL so the widget always activates.
// ============================================================
(function() {
	// Skip if already on the full conversations page
	if (document.getElementById('whatsapp-chat-container')) {
		return;
	}

	// If hook already injected variables (normal flow), widget.js is loaded
	// separately — nothing to do here.
	if (typeof window.WhatsAppWidgetBase !== 'undefined') {
		return;
	}

	// Derive module base URL from the URL of this script file.
	// e.g. /custom/whatsappdati/js/whatsappdati.js → /custom/whatsappdati
	var moduleBase = '';
	try {
		var scripts = document.querySelectorAll('script[src]');
		for (var i = 0; i < scripts.length; i++) {
			var src = scripts[i].src || '';
			var m = src.match(/(.*\/whatsappdati)\/js\/whatsappdati\.js/);
			if (m) {
				moduleBase = m[1]; // absolute URL to module root
				break;
			}
		}
	} catch (e) {}

	if (!moduleBase) {
		return; // Can't determine path — bail
	}

	// Inject widget variables
	window.WhatsAppWidgetBase   = moduleBase + '/ajax/widget.php';
	window.WhatsAppWidgetToken  = '';   // Token not available without PHP; widget will use AJAX without token initially
	window.WhatsAppConvPageUrl  = moduleBase + '/conversations.php';
	window.WhatsAppWidgetLabels = window.WhatsAppWidgetLabels || {};

	// Load the widget JS dynamically
	var s = document.createElement('script');
	s.src = moduleBase + '/js/whatsapp_widget.js?v=' + Date.now();
	s.async = true;
	document.head.appendChild(s);
})();
