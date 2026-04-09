/**
 * WhatsApp Dati — Global Floating Chat Widget
 *
 * Injected on every Dolibarr page via the printCommonFooter hook.
 * Shows a floating WhatsApp bubble with unread badge, mini-chat panel,
 * conversation list, and quick-reply capability.
 *
 * SECURITY: Only shows conversations assigned to the current agent
 * (or unassigned). Admins see all.
 *
 * Copyright (C) 2024-2026 DatiLab — GPLv3+
 */
(function() {
	'use strict';

	console.log('[WhatsApp Widget] JS loaded. WhatsAppChat exists:', !!window.WhatsAppChat,
		'initialized:', !!(window.WhatsAppChat && WhatsAppChat._initialized),
		'container:', !!document.getElementById('whatsapp-chat-container'),
		'AJAX_BASE:', !!window.WhatsAppWidgetBase);

	// Bail if the full conversations page is active — widget would be redundant.
	// WhatsAppChat is defined globally (via module JS), but only _initialized
	// when #whatsapp-chat-container exists (conversations.php). Check the flag,
	// not just the object's existence.
	if (window.WhatsAppChat && WhatsAppChat._initialized) {
		console.log('[WhatsApp Widget] Skipped: full chat page is active');
		return;
	}
	// Also bail if the conversations container is in the DOM (init may be pending)
	if (document.getElementById('whatsapp-chat-container')) {
		console.log('[WhatsApp Widget] Skipped: chat container found in DOM');
		return;
	}

	// -------------------------------------------------------------------
	// CONFIG
	// -------------------------------------------------------------------
	var POLL_INTERVAL  = 30000;  // 30 seconds for badge count
	var POLL_MESSAGES  = 8000;   // 8 seconds when a conversation is open
	var AJAX_BASE      = window.WhatsAppWidgetBase || '';
	var CSRF_TOKEN     = window.WhatsAppWidgetToken || '';
	var CONV_PAGE_URL  = window.WhatsAppConvPageUrl || '';
	var LABELS         = window.WhatsAppWidgetLabels || {};

	// -------------------------------------------------------------------
	// STATE
	// -------------------------------------------------------------------
	var isOpen            = false;
	var currentConvId     = null;
	var currentConvData   = null;
	var lastUnreadCount   = 0;
	var badgeTimer        = null;
	var msgTimer          = null;
	var panelView         = 'list'; // 'list' | 'chat'
	var notifSoundEnabled = true;
	var notifAudioCtx     = null;

	// -------------------------------------------------------------------
	// HELPERS
	// -------------------------------------------------------------------
	function _t(key) {
		return LABELS[key] || key;
	}

	function escapeHtml(text) {
		if (!text) return '';
		var m = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
		return String(text).replace(/[&<>"']/g, function(c) { return m[c]; });
	}

	function getInitials(name) {
		if (!name) return '?';
		name = name.replace(/^\+/, '').trim();
		var parts = name.split(/[\s._-]+/).filter(Boolean);
		if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
		return name.substring(0, 2).toUpperCase();
	}

	function hashColor(name) {
		var h = 0;
		for (var i = 0; i < (name || '').length; i++) {
			h = name.charCodeAt(i) + ((h << 5) - h);
		}
		return (Math.abs(h) % 6) + 1;
	}

	function timeAgo(dateStr) {
		if (!dateStr) return '';
		var d = new Date(dateStr.replace(' ', 'T'));
		var now = new Date();
		var diff = Math.floor((now - d) / 1000);
		if (diff < 60) return _t('JustNow');
		if (diff < 3600) return Math.floor(diff / 60) + ' min';
		if (diff < 86400) return Math.floor(diff / 3600) + 'h';
		return d.toLocaleDateString();
	}

	function formatTime(dateStr) {
		if (!dateStr) return '';
		var d = new Date(dateStr.replace(' ', 'T'));
		return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
	}

	// -------------------------------------------------------------------
	// NOTIFICATION SOUND — generated programmatically (no file needed)
	// -------------------------------------------------------------------

	// Unlock AudioContext on first user interaction (required by browser autoplay policy).
	// Chrome/Opera block audio until a click/keypress occurs.
	var _audioUnlocked = false;
	function _unlockAudio() {
		if (_audioUnlocked) return;
		try {
			if (!notifAudioCtx) {
				notifAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
			}
			// Resume suspended context (Chrome suspends it until user gesture)
			if (notifAudioCtx.state === 'suspended') {
				notifAudioCtx.resume();
			}
			// Play a silent buffer to fully unlock
			var buf = notifAudioCtx.createBuffer(1, 1, 22050);
			var src = notifAudioCtx.createBufferSource();
			src.buffer = buf;
			src.connect(notifAudioCtx.destination);
			src.start(0);
			_audioUnlocked = true;
		} catch (e) {}
	}

	// Attach unlock listeners once — removed after first use
	function _initAudioUnlock() {
		var events = ['click', 'keydown', 'touchstart'];
		function handler() {
			_unlockAudio();
			events.forEach(function(ev) {
				document.removeEventListener(ev, handler, true);
			});
		}
		events.forEach(function(ev) {
			document.addEventListener(ev, handler, true);
		});
	}
	_initAudioUnlock();

	function playNotificationSound() {
		if (!notifSoundEnabled) return;
		try {
			if (!notifAudioCtx) {
				notifAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
			}
			var ctx = notifAudioCtx;

			// Ensure context is running before scheduling nodes
			var doPlay = function() {
				var osc1 = ctx.createOscillator();
				var osc2 = ctx.createOscillator();
				var gain = ctx.createGain();

				osc1.type = 'sine';
				osc1.frequency.setValueAtTime(830, ctx.currentTime);
				osc1.frequency.setValueAtTime(990, ctx.currentTime + 0.08);

				osc2.type = 'sine';
				osc2.frequency.setValueAtTime(1200, ctx.currentTime + 0.12);

				gain.gain.setValueAtTime(0.18, ctx.currentTime);
				gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);

				osc1.connect(gain);
				osc2.connect(gain);
				gain.connect(ctx.destination);

				osc1.start(ctx.currentTime);
				osc2.start(ctx.currentTime + 0.12);
				osc1.stop(ctx.currentTime + 0.35);
				osc2.stop(ctx.currentTime + 0.35);
			};

			if (ctx.state === 'suspended') {
				ctx.resume().then(doPlay).catch(function() {});
			} else {
				doPlay();
			}
		} catch (e) {
			// AudioContext not available — silent fail
		}
	}

	// -------------------------------------------------------------------
	// DESKTOP NOTIFICATION
	// -------------------------------------------------------------------
	function showDesktopNotif(contactName, preview) {
		if (!('Notification' in window)) return;
		if (Notification.permission === 'default') {
			Notification.requestPermission();
			return;
		}
		if (Notification.permission !== 'granted') return;
		// Don't notify if panel is open and visible
		if (isOpen && !document.hidden) return;

		try {
			var n = new Notification(contactName || 'WhatsApp', {
				body: preview || _t('NewMessage'),
				icon: AJAX_BASE.replace('/ajax/widget.php', '/img/whatsappdati.png'),
				tag: 'wa-widget-notif',
				renotify: true
			});
			n.onclick = function() {
				window.focus();
				togglePanel(true);
				n.close();
			};
			setTimeout(function() { n.close(); }, 6000);
		} catch (e) {}
	}

	// -------------------------------------------------------------------
	// AJAX helpers
	// -------------------------------------------------------------------

	// Get CSRF token — from injected variable or from Dolibarr's DOM
	function getCsrfToken() {
		if (CSRF_TOKEN) return CSRF_TOKEN;
		// Dolibarr injects token in a hidden input on every page
		var t = document.querySelector('input[name="token"]');
		if (t) return t.value;
		// Also try meta tag (some Dolibarr versions use this)
		var m = document.querySelector('meta[name="anti-csrf-newtoken"]');
		if (m) return m.getAttribute('content');
		return '';
	}

	function ajaxGet(params, cb) {
		params.action = params.action || '';
		var qs = [];
		for (var k in params) {
			if (params.hasOwnProperty(k)) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
		}
		var xhr = new XMLHttpRequest();
		xhr.open('GET', AJAX_BASE + '?' + qs.join('&'), true);
		xhr.onreadystatechange = function() {
			if (xhr.readyState === 4) {
				try { cb(JSON.parse(xhr.responseText)); } catch (e) { cb({success: false}); }
			}
		};
		xhr.send();
	}

	function ajaxPost(params, cb) {
		var fd = new FormData();
		for (var k in params) {
			if (params.hasOwnProperty(k)) fd.append(k, params[k]);
		}
		fd.append('token', getCsrfToken());
		var xhr = new XMLHttpRequest();
		xhr.open('POST', AJAX_BASE, true);
		xhr.onreadystatechange = function() {
			if (xhr.readyState === 4) {
				try { cb(JSON.parse(xhr.responseText)); } catch (e) { cb({success: false}); }
			}
		};
		xhr.send(fd);
	}

	// -------------------------------------------------------------------
	// BADGE POLLING
	// -------------------------------------------------------------------
	function pollBadge() {
		ajaxGet({action: 'unread_count'}, function(data) {
			if (!data.success) return;
			var total = data.total_unread || 0;
			updateBadge(total);

			// Play sound + desktop notif if count increased
			if (total > lastUnreadCount && lastUnreadCount >= 0) {
				playNotificationSound();
				// We don't have the contact name here, just notify generically
				showDesktopNotif(_t('NewWhatsApp'), total + ' ' + _t('UnreadMessages'));
			}
			lastUnreadCount = total;

			// If panel is open on list view, refresh conversations list
			if (isOpen && panelView === 'list') {
				loadConversations();
			}
		});
	}

	function startBadgePolling() {
		pollBadge();
		badgeTimer = setInterval(pollBadge, POLL_INTERVAL);
	}

	function stopBadgePolling() {
		if (badgeTimer) clearInterval(badgeTimer);
		badgeTimer = null;
	}

	// -------------------------------------------------------------------
	// MESSAGE POLLING (when a conversation is open in the widget)
	// -------------------------------------------------------------------
	function startMsgPolling() {
		stopMsgPolling();
		msgTimer = setInterval(function() {
			if (currentConvId && isOpen && panelView === 'chat') {
				loadMessages(currentConvId, true); // silent refresh
			}
		}, POLL_MESSAGES);
	}

	function stopMsgPolling() {
		if (msgTimer) clearInterval(msgTimer);
		msgTimer = null;
	}

	// -------------------------------------------------------------------
	// UI UPDATE
	// -------------------------------------------------------------------
	function updateBadge(count) {
		var el = document.getElementById('wa-widget-badge');
		if (!el) return;
		if (count > 0) {
			el.textContent = count > 99 ? '99+' : count;
			el.style.display = 'flex';
		} else {
			el.style.display = 'none';
		}
	}

	// -------------------------------------------------------------------
	// TOGGLE PANEL
	// -------------------------------------------------------------------
	function togglePanel(forceOpen) {
		var panel = document.getElementById('wa-widget-panel');
		if (!panel) return;
		if (typeof forceOpen === 'boolean') {
			isOpen = forceOpen;
		} else {
			isOpen = !isOpen;
		}
		panel.style.display = isOpen ? 'flex' : 'none';

		if (isOpen) {
			showListView();
			// Request notification permission on first interaction
			if ('Notification' in window && Notification.permission === 'default') {
				Notification.requestPermission();
			}
		} else {
			stopMsgPolling();
			currentConvId = null;
			panelView = 'list';
		}
	}

	// -------------------------------------------------------------------
	// LIST VIEW
	// -------------------------------------------------------------------
	function showListView() {
		panelView = 'list';
		currentConvId = null;
		stopMsgPolling();

		var header = document.getElementById('wa-widget-header');
		header.innerHTML =
			'<span class="wa-widget-header-title">' + escapeHtml(_t('WhatsAppChats')) + '</span>' +
			'<div class="wa-widget-header-actions">' +
			'<a href="' + escapeHtml(CONV_PAGE_URL) + '" class="wa-widget-expand-btn" title="' + escapeHtml(_t('OpenFull')) + '">&#8599;</a>' +
			'<button class="wa-widget-close-btn" id="wa-widget-close-btn" title="' + escapeHtml(_t('Close')) + '">&times;</button>' +
			'</div>';

		document.getElementById('wa-widget-close-btn').addEventListener('click', function() {
			togglePanel(false);
		});

		// Show search + list
		var body = document.getElementById('wa-widget-body');
		body.innerHTML =
			'<div class="wa-widget-search">' +
			'<input type="text" id="wa-widget-search-input" placeholder="' + escapeHtml(_t('SearchConversations')) + '" />' +
			'</div>' +
			'<div class="wa-widget-convlist" id="wa-widget-convlist">' +
			'<div class="wa-widget-loading">' + escapeHtml(_t('Loading')) + '...</div>' +
			'</div>';

		// Show footer with mute toggle
		var footer = document.getElementById('wa-widget-footer');
		footer.innerHTML =
			'<label class="wa-widget-mute-label">' +
			'<input type="checkbox" id="wa-widget-mute-cb" ' + (notifSoundEnabled ? 'checked' : '') + ' /> ' +
			escapeHtml(_t('SoundEnabled')) +
			'</label>';
		footer.style.display = 'flex';

		document.getElementById('wa-widget-mute-cb').addEventListener('change', function() {
			notifSoundEnabled = this.checked;
		});

		document.getElementById('wa-widget-search-input').addEventListener('input', function() {
			filterConversations(this.value);
		});

		loadConversations();
	}

	function loadConversations() {
		ajaxGet({action: 'conversations'}, function(data) {
			if (!data.success) return;
			renderConversationList(data.conversations);
		});
	}

	function renderConversationList(convs) {
		var container = document.getElementById('wa-widget-convlist');
		if (!container) return;

		if (!convs || convs.length === 0) {
			container.innerHTML = '<div class="wa-widget-empty">' + escapeHtml(_t('NoConversations')) + '</div>';
			return;
		}

		var html = '';
		for (var i = 0; i < convs.length; i++) {
			var c = convs[i];
			var name = c.contact_name || c.phone_number;
			var initials = getInitials(name);
			var colorCls = 'wa-widget-avatar-' + hashColor(name);
			var unread = c.unread_count > 0
				? '<span class="wa-widget-conv-badge">' + c.unread_count + '</span>'
				: '';

			html += '<div class="wa-widget-conv-item' + (c.unread_count > 0 ? ' wa-widget-conv-unread' : '') + '" data-conv-id="' + c.rowid + '">';
			html += '<div class="wa-widget-conv-avatar ' + colorCls + '">' + escapeHtml(initials) + '</div>';
			html += '<div class="wa-widget-conv-body">';
			html += '<div class="wa-widget-conv-top">';
			html += '<span class="wa-widget-conv-name">' + escapeHtml(name) + '</span>';
			html += '<span class="wa-widget-conv-time">' + timeAgo(c.last_message_date) + '</span>';
			html += '</div>';
			html += '<div class="wa-widget-conv-bottom">';
			html += '<span class="wa-widget-conv-preview">' + escapeHtml(c.last_message_preview || '') + '</span>';
			html += unread;
			html += '</div>';
			html += '</div>';
			html += '</div>';
		}
		container.innerHTML = html;

		// Bind click events
		var items = container.querySelectorAll('.wa-widget-conv-item');
		for (var j = 0; j < items.length; j++) {
			items[j].addEventListener('click', (function(id) {
				return function() { openConversation(id); };
			})(convs[j].rowid));
		}
	}

	function filterConversations(query) {
		query = (query || '').toLowerCase();
		var items = document.querySelectorAll('.wa-widget-conv-item');
		for (var i = 0; i < items.length; i++) {
			var name = items[i].querySelector('.wa-widget-conv-name');
			var preview = items[i].querySelector('.wa-widget-conv-preview');
			var text = ((name ? name.textContent : '') + ' ' + (preview ? preview.textContent : '')).toLowerCase();
			items[i].style.display = (!query || text.indexOf(query) !== -1) ? '' : 'none';
		}
	}

	// -------------------------------------------------------------------
	// CHAT VIEW
	// -------------------------------------------------------------------
	function openConversation(convId) {
		currentConvId = convId;
		panelView = 'chat';

		var header = document.getElementById('wa-widget-header');
		header.innerHTML =
			'<button class="wa-widget-back-btn" id="wa-widget-back-btn">&#8592;</button>' +
			'<span class="wa-widget-header-title" id="wa-widget-chat-title">' + escapeHtml(_t('Loading')) + '</span>' +
			'<a href="' + escapeHtml(CONV_PAGE_URL) + '" class="wa-widget-expand-btn" title="' + escapeHtml(_t('OpenFull')) + '">&#8599;</a>';

		document.getElementById('wa-widget-back-btn').addEventListener('click', function() {
			showListView();
		});

		var body = document.getElementById('wa-widget-body');
		body.innerHTML = '<div class="wa-widget-messages" id="wa-widget-messages">' +
			'<div class="wa-widget-loading">' + escapeHtml(_t('Loading')) + '...</div></div>';

		// Footer: input area
		var footer = document.getElementById('wa-widget-footer');
		footer.innerHTML =
			'<div class="wa-widget-input-area">' +
			'<input type="text" class="wa-widget-input" id="wa-widget-msg-input" placeholder="' + escapeHtml(_t('TypeMessage')) + '" />' +
			'<button class="wa-widget-send-btn" id="wa-widget-send-btn">' + escapeHtml(_t('Send')) + '</button>' +
			'</div>';
		footer.style.display = 'flex';

		document.getElementById('wa-widget-send-btn').addEventListener('click', sendWidgetMessage);
		document.getElementById('wa-widget-msg-input').addEventListener('keypress', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendWidgetMessage();
			}
		});

		loadMessages(convId, false);
		startMsgPolling();
	}

	function loadMessages(convId, silent) {
		ajaxGet({action: 'messages', conversation_id: convId}, function(data) {
			if (!data.success) return;
			if (data.conversation) {
				currentConvData = data.conversation;
				var titleEl = document.getElementById('wa-widget-chat-title');
				if (titleEl) {
					titleEl.textContent = data.conversation.contact_name || data.conversation.phone_number;
				}
			}
			renderMessages(data.messages, silent);
			// Update badge since we just read this conversation
			pollBadge();
		});
	}

	function renderMessages(msgs, silent) {
		var container = document.getElementById('wa-widget-messages');
		if (!container) return;

		// Remember scroll position
		var wasAtBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 60;
		var previousCount = container.querySelectorAll('.wa-widget-msg').length;

		if (!msgs || msgs.length === 0) {
			container.innerHTML = '<div class="wa-widget-empty">' + escapeHtml(_t('NoMessages')) + '</div>';
			return;
		}

		var html = '';
		for (var i = 0; i < msgs.length; i++) {
			var m = msgs[i];
			var cls = m.direction === 'outbound' ? 'wa-widget-msg-out' : 'wa-widget-msg-in';
			var isNew = !silent && i >= previousCount;
			html += '<div class="wa-widget-msg ' + cls + (isNew ? ' wa-widget-msg-new' : '') + '">';

			// Media rendering
			if (m.message_type === 'image' && m.media_serve_url) {
				html += '<img class="wa-widget-msg-image" src="' + escapeHtml(m.media_serve_url) + '" loading="lazy" />';
			} else if (m.message_type === 'template' && m.media_serve_url) {
				html += '<img class="wa-widget-msg-image" src="' + escapeHtml(m.media_serve_url) + '" loading="lazy" />';
			} else if (m.message_type === 'document' && m.media_serve_url) {
				html += '<div class="wa-widget-msg-doc">';
				html += '<span>&#128196;</span> ';
				html += '<a href="' + escapeHtml(m.media_download_url || m.media_serve_url) + '" target="_blank">' + escapeHtml(m.media_filename || _t('Document')) + '</a>';
				html += '</div>';
			} else if (m.message_type === 'audio' && m.media_serve_url) {
				html += '<audio controls src="' + escapeHtml(m.media_serve_url) + '" preload="none"></audio>';
			} else if (m.message_type === 'video' && m.media_serve_url) {
				html += '<video controls class="wa-widget-msg-video" src="' + escapeHtml(m.media_serve_url) + '" preload="none"></video>';
			} else if (m.message_type === 'contacts') {
				try {
					var contacts = JSON.parse(m.content || '[]');
					for (var ci = 0; ci < contacts.length; ci++) {
						var cn = (contacts[ci].name && contacts[ci].name.formatted_name) ? contacts[ci].name.formatted_name : 'Contact';
						html += '<div class="wa-widget-msg-contact">&#128100; ' + escapeHtml(cn) + '</div>';
					}
				} catch (e) {
					html += '<div class="wa-widget-msg-contact">&#128100; Contact</div>';
				}
			} else if (m.message_type === 'location') {
				try {
					var loc = JSON.parse(m.content || '{}');
					if (loc.latitude && loc.longitude) {
						var mUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(loc.latitude + ',' + loc.longitude);
						html += '<div class="wa-widget-msg-location"><a href="' + escapeHtml(mUrl) + '" target="_blank">&#128205; ' + escapeHtml(loc.name || (loc.latitude + ', ' + loc.longitude)) + '</a></div>';
					}
				} catch (e) {
					html += '<div class="wa-widget-msg-location">&#128205; Location</div>';
				}
			}

			if (m.content && m.message_type !== 'contacts' && m.message_type !== 'location') {
				html += '<div class="wa-widget-msg-text">' + escapeHtml(m.content) + '</div>';
			}
			html += '<div class="wa-widget-msg-meta">';
			html += '<span>' + formatTime(m.timestamp) + '</span>';
			if (m.direction === 'outbound') {
				var statusIcon = m.status === 'read' ? '&#10003;&#10003;' : (m.status === 'delivered' ? '&#10003;&#10003;' : '&#10003;');
				var statusCls = m.status === 'read' ? ' wa-widget-status-read' : '';
				html += '<span class="wa-widget-msg-status' + statusCls + '">' + statusIcon + '</span>';
			}
			html += '</div>';
			html += '</div>';
		}

		container.innerHTML = html;

		// Scroll to bottom if was already there or first load
		if (wasAtBottom || !silent) {
			container.scrollTop = container.scrollHeight;
		}
	}

	function sendWidgetMessage() {
		var input = document.getElementById('wa-widget-msg-input');
		if (!input) return;
		var text = input.value.trim();
		if (!text || !currentConvId) return;

		// Disable while sending
		input.disabled = true;
		var btn = document.getElementById('wa-widget-send-btn');
		if (btn) btn.disabled = true;

		ajaxPost({
			action: 'send',
			conversation_id: currentConvId,
			message: text
		}, function(data) {
			input.disabled = false;
			if (btn) btn.disabled = false;

			if (data.success) {
				input.value = '';
				loadMessages(currentConvId, false);
			} else {
				if (data.error === 'window_expired') {
					alert(_t('WindowExpired'));
				} else {
					alert(_t('ErrorSending') + ': ' + (data.error || ''));
				}
			}
			input.focus();
		});
	}

	// -------------------------------------------------------------------
	// BUILD DOM
	// -------------------------------------------------------------------
	function buildWidget() {
		// Create container
		var wrap = document.createElement('div');
		wrap.id = 'wa-widget-root';
		wrap.innerHTML =
			// Floating button
			'<button class="wa-widget-fab" id="wa-widget-fab" title="WhatsApp">' +
			'<svg viewBox="0 0 24 24" width="28" height="28" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347zM12.05 21.785h-.01a9.94 9.94 0 01-5.064-1.39l-.364-.215-3.767.988.998-3.648-.236-.376A9.93 9.93 0 012.1 12.05c0-5.49 4.471-9.96 9.97-9.96a9.96 9.96 0 019.95 9.97c-.003 5.49-4.474 9.96-9.97 9.96zm8.413-18.394A11.89 11.89 0 0012.05 0C5.464 0 .104 5.36.1 11.95a11.91 11.91 0 001.596 5.945L0 24l6.305-1.654a11.88 11.88 0 005.738 1.463h.005c6.585 0 11.946-5.36 11.95-11.95A11.89 11.89 0 0020.463 3.39z"/></svg>' +
			'<span class="wa-widget-badge" id="wa-widget-badge" style="display:none">0</span>' +
			'</button>' +
			// Panel
			'<div class="wa-widget-panel" id="wa-widget-panel" style="display:none">' +
			'<div class="wa-widget-header" id="wa-widget-header"></div>' +
			'<div class="wa-widget-body" id="wa-widget-body"></div>' +
			'<div class="wa-widget-footer" id="wa-widget-footer"></div>' +
			'</div>';

		document.body.appendChild(wrap);

		// Bind FAB click
		document.getElementById('wa-widget-fab').addEventListener('click', function() {
			togglePanel();
		});

		// Close panel on Escape
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && isOpen) {
				togglePanel(false);
			}
		});
	}

	// -------------------------------------------------------------------
	// INIT
	// -------------------------------------------------------------------
	function init() {
		console.log('[WhatsApp Widget] init() called, AJAX_BASE:', AJAX_BASE ? 'SET' : 'EMPTY');
		if (!AJAX_BASE) {
			console.warn('[WhatsApp Widget] No AJAX_BASE configured — widget disabled.');
			return;
		}
		buildWidget();
		console.log('[WhatsApp Widget] Bubble rendered OK');
		startBadgePolling();
	}

	// Run when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
