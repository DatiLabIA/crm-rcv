/**
 * CabinetMedFix - Document Tags/Labels
 *
 * Injects a "Etiquetas" column into the CabinetMed documents table,
 * allowing users to add/remove free-text tags on each document.
 * Tags are stored in llx_cabinetmedfix_doc_labels via AJAX.
 *
 * Copyright (C) 2026 CRM-RCV - GPL v3
 */
(function () {
	'use strict';

	// Only run on pages that have the CabinetMed document table
	document.addEventListener('DOMContentLoaded', function () {
		setTimeout(initDocumentTags, 300);
	});

	function initDocumentTags() {
		// Find the document list table - it has id="tablelines"
		var table = document.getElementById('tablelines');
		if (!table) return;

		// Verify we're on the documents tab (check URL or page content)
		var url = window.location.href;
		if (url.indexOf('/cabinetmed/documents.php') === -1) return;

		// Get socid from URL
		var socid = getUrlParam('socid') || getUrlParam('id');
		if (!socid) return;

		// Build AJAX base URL
		var ajaxUrl = buildAjaxUrl();
		if (!ajaxUrl) return;

		// Add CSS for tags
		injectTagStyles();

		// Add header column
		var headerRow = table.querySelector('tr.liste_titre');
		if (!headerRow) return;

		// Find the position: insert after "Size" column (3rd column, index 2)
		// Header has: Date | Documents2 | Size | (preview) | (share) | (email) | (delete)
		var headerCells = headerRow.querySelectorAll('td, th');
		var insertAfterIdx = 2; // After "Size" (0=Date, 1=Documents2, 2=Size)

		var thTag = document.createElement('td');
		thTag.className = 'liste_titre';
		thTag.style.minWidth = '180px';
		thTag.innerHTML = '<b>Etiquetas</b>';

		if (headerCells.length > insertAfterIdx + 1) {
			headerRow.insertBefore(thTag, headerCells[insertAfterIdx + 1]);
		} else {
			headerRow.appendChild(thTag);
		}

		// Process data rows
		var dataRows = table.querySelectorAll('tr.oddeven');

		// Load tags via AJAX, then populate
		loadTags(ajaxUrl, socid, function (tagsMap) {
			dataRows.forEach(function (row) {
				var filename = extractFilename(row);
				if (!filename) {
					// Add empty cell to maintain alignment
					var emptyTd = document.createElement('td');
					emptyTd.className = 'center';
					var cells = row.querySelectorAll('td');
					if (cells.length > insertAfterIdx + 1) {
						row.insertBefore(emptyTd, cells[insertAfterIdx + 1]);
					} else {
						row.appendChild(emptyTd);
					}
					return;
				}

				var currentTags = tagsMap[filename] || '';
				var td = createTagCell(ajaxUrl, socid, filename, currentTags);

				var cells = row.querySelectorAll('td');
				if (cells.length > insertAfterIdx + 1) {
					row.insertBefore(td, cells[insertAfterIdx + 1]);
				} else {
					row.appendChild(td);
				}
			});
		});

		// Also handle "no files" row
		var noFileRow = table.querySelector('tr.oddeven td[colspan]');
		if (noFileRow) {
			var currentColspan = parseInt(noFileRow.getAttribute('colspan') || '6');
			noFileRow.setAttribute('colspan', currentColspan + 1);
		}
	}

	/**
	 * Extract filename from a table row by finding the download link
	 */
	function extractFilename(row) {
		// Look for the document link in the second cell
		var links = row.querySelectorAll('td a[href*="document.php"]');
		if (links.length === 0) return null;

		var href = links[0].getAttribute('href');
		// Extract file parameter from URL
		var match = href.match(/[?&]file=([^&]+)/);
		if (match) {
			var filepath = decodeURIComponent(match[1]);
			// Get just the filename (last part of path)
			var parts = filepath.split('/');
			return parts[parts.length - 1];
		}

		// Fallback: get text content of link
		var textContent = links[0].textContent || links[0].innerText;
		return textContent.trim() || null;
	}

	/**
	 * Create a TD element with tag display and editing
	 */
	function createTagCell(ajaxUrl, socid, filename, currentTags) {
		var td = document.createElement('td');
		td.className = 'doc-tags-cell';
		td.style.minWidth = '150px';

		renderTags(td, ajaxUrl, socid, filename, currentTags);
		return td;
	}

	/**
	 * Render tags display with edit capability
	 */
	function renderTags(container, ajaxUrl, socid, filename, tagsStr) {
		container.innerHTML = '';

		var tagsArray = [];
		if (tagsStr) {
			tagsArray = tagsStr.split(',').map(function(t) { return t.trim(); }).filter(function(t) { return t !== ''; });
		}

		// Tags display
		var tagsDiv = document.createElement('div');
		tagsDiv.className = 'doc-tags-display';

		tagsArray.forEach(function (tag) {
			var badge = document.createElement('span');
			badge.className = 'doc-tag-badge';
			badge.innerHTML = escapeHtml(tag) + ' <a class="doc-tag-remove" title="Eliminar">&times;</a>';

			badge.querySelector('.doc-tag-remove').addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var newTags = tagsArray.filter(function(t) { return t !== tag; });
				saveTags(ajaxUrl, socid, filename, newTags.join(','), function (savedTags) {
					renderTags(container, ajaxUrl, socid, filename, savedTags);
				});
			});

			tagsDiv.appendChild(badge);
		});

		container.appendChild(tagsDiv);

		// Add tag button/input
		var addBtn = document.createElement('a');
		addBtn.className = 'doc-tag-add-btn';
		addBtn.href = '#';
		addBtn.textContent = '+ Etiqueta';
		addBtn.addEventListener('click', function (e) {
			e.preventDefault();
			showTagInput(container, ajaxUrl, socid, filename, tagsArray);
		});
		container.appendChild(addBtn);
	}

	/**
	 * Show inline input for adding a tag
	 */
	function showTagInput(container, ajaxUrl, socid, filename, existingTags) {
		// Remove the add button
		var addBtn = container.querySelector('.doc-tag-add-btn');
		if (addBtn) addBtn.style.display = 'none';

		// Check if input already exists
		if (container.querySelector('.doc-tag-input-wrap')) return;

		var wrap = document.createElement('div');
		wrap.className = 'doc-tag-input-wrap';

		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'doc-tag-input';
		input.placeholder = 'Nueva etiqueta...';
		input.style.width = '120px';
		input.style.fontSize = '12px';
		input.style.padding = '2px 4px';

		var saveBtn = document.createElement('button');
		saveBtn.type = 'button';
		saveBtn.className = 'doc-tag-save-btn';
		saveBtn.textContent = '✓';
		saveBtn.title = 'Guardar';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'doc-tag-cancel-btn';
		cancelBtn.textContent = '✕';
		cancelBtn.title = 'Cancelar';

		// Autocomplete dropdown
		var dropdown = document.createElement('div');
		dropdown.className = 'doc-tag-dropdown';
		dropdown.style.display = 'none';

		wrap.appendChild(input);
		wrap.appendChild(saveBtn);
		wrap.appendChild(cancelBtn);
		wrap.appendChild(dropdown);
		container.appendChild(wrap);

		input.focus();

		// Autocomplete logic
		var debounceTimer = null;
		input.addEventListener('input', function () {
			clearTimeout(debounceTimer);
			var val = input.value.trim();
			if (val.length < 1) {
				dropdown.style.display = 'none';
				return;
			}
			debounceTimer = setTimeout(function () {
				fetchAutocomplete(ajaxUrl, val, function (suggestions) {
					dropdown.innerHTML = '';
					// Filter out already-assigned tags
					var filtered = suggestions.filter(function (s) {
						return existingTags.indexOf(s) === -1;
					});
					if (filtered.length === 0) {
						dropdown.style.display = 'none';
						return;
					}
					filtered.forEach(function (s) {
						var item = document.createElement('div');
						item.className = 'doc-tag-dropdown-item';
						item.textContent = s;
						item.addEventListener('mousedown', function (e) {
							e.preventDefault();
							input.value = s;
							confirmAddTag();
						});
						dropdown.appendChild(item);
					});
					dropdown.style.display = 'block';
				});
			}, 200);
		});

		function confirmAddTag() {
			var newTag = input.value.trim();
			if (!newTag) return;

			// Support adding multiple tags separated by comma
			var newTagsArr = newTag.split(',').map(function(t) { return t.trim(); }).filter(function(t) { return t !== ''; });
			var allTags = existingTags.concat(newTagsArr);
			// Deduplicate
			var unique = [];
			allTags.forEach(function(t) { if (unique.indexOf(t) === -1) unique.push(t); });

			saveTags(ajaxUrl, socid, filename, unique.join(','), function (savedTags) {
				renderTags(container, ajaxUrl, socid, filename, savedTags);
			});
		}

		function cancelInput() {
			wrap.remove();
			if (addBtn) addBtn.style.display = '';
		}

		saveBtn.addEventListener('click', confirmAddTag);
		cancelBtn.addEventListener('click', cancelInput);

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				confirmAddTag();
			} else if (e.key === 'Escape') {
				cancelInput();
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(function () {
				dropdown.style.display = 'none';
			}, 200);
		});
	}

	/**
	 * Load all tags for a patient
	 */
	function loadTags(ajaxUrl, socid, callback) {
		var xhr = new XMLHttpRequest();
		xhr.open('GET', ajaxUrl + '&action=get_tags&socid=' + socid, true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4) {
				if (xhr.status === 200) {
					try {
						var data = JSON.parse(xhr.responseText);
						callback(data.tags || {});
					} catch (e) {
						console.error('CabinetMedFix: Error parsing tags response', e);
						callback({});
					}
				} else {
					console.error('CabinetMedFix: Error loading tags, status ' + xhr.status);
					callback({});
				}
			}
		};
		xhr.send();
	}

	/**
	 * Save tags for a document
	 */
	function saveTags(ajaxUrl, socid, filename, tags, callback) {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4) {
				if (xhr.status === 200) {
					try {
						var data = JSON.parse(xhr.responseText);
						if (data.success) {
							callback(data.tags || '');
						} else {
							console.error('CabinetMedFix: Error saving tags', data.error);
							alert('Error al guardar etiquetas: ' + (data.error || 'Error desconocido'));
						}
					} catch (e) {
						console.error('CabinetMedFix: Error parsing save response', e);
					}
				}
			}
		};

		var params = 'action=save_tags'
			+ '&socid=' + encodeURIComponent(socid)
			+ '&filename=' + encodeURIComponent(filename)
			+ '&tags=' + encodeURIComponent(tags)
			+ '&token=' + getToken();
		xhr.send(params);
	}

	/**
	 * Fetch autocomplete suggestions
	 */
	function fetchAutocomplete(ajaxUrl, term, callback) {
		var xhr = new XMLHttpRequest();
		xhr.open('GET', ajaxUrl + '&action=autocomplete&term=' + encodeURIComponent(term), true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4 && xhr.status === 200) {
				try {
					var data = JSON.parse(xhr.responseText);
					callback(data.suggestions || []);
				} catch (e) {
					callback([]);
				}
			}
		};
		xhr.send();
	}

	/**
	 * Build AJAX URL for document_tags.php
	 */
	function buildAjaxUrl() {
		var path = window.location.pathname;
		var base = '';
		var idx = path.indexOf('/custom/');
		if (idx >= 0) base = path.substring(0, idx);
		else {
			idx = path.indexOf('/societe/');
			if (idx >= 0) base = path.substring(0, idx);
		}

		var token = getToken();
		return base + '/custom/cabinetmedfix/ajax/document_tags.php?token=' + token;
	}

	function getToken() {
		var tokenInput = document.querySelector('input[name="token"]');
		return tokenInput ? tokenInput.value : '';
	}

	function getUrlParam(name) {
		var results = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.href);
		return results ? decodeURIComponent(results[1]) : null;
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(text));
		return div.innerHTML;
	}

	/**
	 * Inject CSS styles for tags
	 */
	function injectTagStyles() {
		if (document.getElementById('doc-tags-styles')) return;

		var style = document.createElement('style');
		style.id = 'doc-tags-styles';
		style.textContent = ''
			+ '.doc-tags-cell { vertical-align: middle; }'
			+ '.doc-tags-display { display: flex; flex-wrap: wrap; gap: 3px; margin-bottom: 3px; }'
			+ '.doc-tag-badge {'
			+ '  display: inline-flex; align-items: center; gap: 2px;'
			+ '  background: #e8f4fd; color: #1a5276; border: 1px solid #aed6f1;'
			+ '  border-radius: 3px; padding: 1px 6px; font-size: 11px;'
			+ '  line-height: 18px; white-space: nowrap;'
			+ '}'
			+ '.doc-tag-badge:hover { background: #d4effc; }'
			+ '.doc-tag-remove {'
			+ '  cursor: pointer; color: #7fb3d8; font-weight: bold;'
			+ '  text-decoration: none; font-size: 13px; line-height: 1;'
			+ '  margin-left: 2px;'
			+ '}'
			+ '.doc-tag-remove:hover { color: #c0392b; }'
			+ '.doc-tag-add-btn {'
			+ '  font-size: 11px; color: #2980b9; text-decoration: none;'
			+ '  cursor: pointer; display: inline-block;'
			+ '}'
			+ '.doc-tag-add-btn:hover { text-decoration: underline; }'
			+ '.doc-tag-input-wrap {'
			+ '  display: inline-flex; align-items: center; gap: 3px;'
			+ '  position: relative; margin-top: 2px;'
			+ '}'
			+ '.doc-tag-input {'
			+ '  border: 1px solid #aaa; border-radius: 3px;'
			+ '  padding: 2px 5px; font-size: 11px;'
			+ '}'
			+ '.doc-tag-input:focus { border-color: #2980b9; outline: none; }'
			+ '.doc-tag-save-btn, .doc-tag-cancel-btn {'
			+ '  border: none; cursor: pointer; font-size: 13px;'
			+ '  padding: 1px 4px; border-radius: 3px; line-height: 1;'
			+ '}'
			+ '.doc-tag-save-btn { background: #27ae60; color: white; }'
			+ '.doc-tag-save-btn:hover { background: #219a52; }'
			+ '.doc-tag-cancel-btn { background: #e74c3c; color: white; }'
			+ '.doc-tag-cancel-btn:hover { background: #c0392b; }'
			+ '.doc-tag-dropdown {'
			+ '  position: absolute; top: 100%; left: 0; z-index: 1000;'
			+ '  background: white; border: 1px solid #ccc; border-radius: 3px;'
			+ '  box-shadow: 0 2px 6px rgba(0,0,0,0.15); max-height: 150px;'
			+ '  overflow-y: auto; min-width: 120px;'
			+ '}'
			+ '.doc-tag-dropdown-item {'
			+ '  padding: 4px 8px; cursor: pointer; font-size: 12px;'
			+ '}'
			+ '.doc-tag-dropdown-item:hover { background: #e8f4fd; }';

		document.head.appendChild(style);
	}
})();
