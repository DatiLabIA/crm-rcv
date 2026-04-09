/**
 * CabinetMedFix - Diagnóstico Select2 AJAX
 *
 * Detects heavy or emptied diagnostico selects (chkbxlst with 16K+ options or
 * intentionally emptied by the PHP hook) and replaces them with lightweight
 * Select2 AJAX widgets.
 *
 * Targets:
 *   - #options_diagnostico        → Patient edit/create form (fallback if PHP hook missed it)
 *   - #search_options_diagnostico → Patient list filter (PHP empties it, JS enhances it)
 *
 * Copyright (C) 2026 CRM-RCV - GPL v3
 */
(function () {
	'use strict';

	// IDs to check and their config
	var TARGETS = [
		{
			id: 'options_diagnostico',
			placeholder: 'Buscar diagnóstico...',
			skipClass: 'diagnostico-select2-ajax',   // PHP hook marker
			alwaysReplace: false                     // Only if >500 options (fallback)
		},
		{
			id: 'search_options_diagnostico',
			placeholder: 'Filtrar por diagnóstico...',
			skipClass: null,
			alwaysReplace: true                      // Always: PHP empties the options
		}
	];

	document.addEventListener('DOMContentLoaded', function () {
		// Small delay to run after Dolibarr's own Select2/multiselect init
		setTimeout(initAllDiagnosticoFallbacks, 500);
	});

	/**
	 * Iterate over every target and apply the AJAX replacement if needed.
	 */
	function initAllDiagnosticoFallbacks() {
		var ajaxUrl = buildAjaxUrl();
		TARGETS.forEach(function (target) {
			replaceDiagnosticoSelect(target, ajaxUrl);
		});
	}

	/**
	 * Replace a single heavy/empty diagnostico <select> with Select2 AJAX.
	 */
	function replaceDiagnosticoSelect(target, ajaxUrl) {
		var el = document.getElementById(target.id);
		if (!el) return;

		// If our PHP hook already replaced it, skip
		if (target.skipClass && el.classList.contains(target.skipClass)) return;

		// For fallback targets: only act on heavy selects (> 500 options)
		// For alwaysReplace targets: act regardless of option count
		if (!target.alwaysReplace && (!el.options || el.options.length <= 500)) return;

		console.warn(
			'CabinetMedFix: Processing #' + target.id +
			' (' + (el.options ? el.options.length : 0) + ' options). Replacing with AJAX widget...'
		);

		// Collect currently selected values from the <select> itself
		var selectedValues = [];
		if (el.options) {
			for (var i = 0; i < el.options.length; i++) {
				if (el.options[i].selected && el.options[i].value) {
					selectedValues.push({
						id: el.options[i].value,
						text: el.options[i].textContent || el.options[i].innerText
					});
				}
			}
		}

		// Destroy any existing Select2 / multiselect widget
		try {
			var $el = jQuery(el);
			if ($el.data('select2')) $el.select2('destroy');
		} catch (e) { /* ignore */ }

		// Remove leftover UI widgets (but keep hidden companion fields)
		var parent = el.parentNode;
		var widgets = parent.querySelectorAll('.ui-multiselect, .select2-container');
		widgets.forEach(function (w) { w.remove(); });

		// Clear all <option> elements (the ones causing the DOM bloat)
		while (el.options.length > 0) el.remove(0);
		el.style.display = '';
		el.style.width = '100%';

		// Re-add selected options so they're preserved on form submit
		selectedValues.forEach(function (item) {
			var opt = new Option(item.text, item.id, true, true);
			el.appendChild(opt);
		});

		// For search selects: also load pre-selected values from PHP JSON block
		if (target.alwaysReplace) {
			var dataEl = document.getElementById('diag-search-preselect');
			if (dataEl) {
				try {
					var preselected = JSON.parse(dataEl.textContent);
					if (preselected && preselected.length) {
						preselected.forEach(function (item) {
							// Avoid duplicates
							if (!el.querySelector('option[value="' + item.id + '"]')) {
								var opt = new Option(item.text, item.id, true, true);
								el.appendChild(opt);
							}
						});
					}
				} catch (e) {
					console.warn('CabinetMedFix: Could not parse preselected data', e);
				}
			}

			// Ensure the _multiselect hidden field exists (required by getOptionalsFromPost)
			var msName = target.id + '_multiselect';
			if (!parent.querySelector('input[name="' + msName + '"]')) {
				var hidden = document.createElement('input');
				hidden.type = 'hidden';
				hidden.name = msName;
				hidden.value = '1';
				parent.appendChild(hidden);
			}
		}

		// Initialize Select2 with AJAX search
		jQuery(el).select2({
			ajax: {
				url: ajaxUrl,
				dataType: 'json',
				delay: 300,
				data: function (params) {
					return { action: 'search', term: params.term || '', page: params.page || 1 };
				},
				processResults: function (data, params) {
					params.page = params.page || 1;
					return {
						results: data.results || [],
						pagination: { more: data.pagination ? data.pagination.more : false }
					};
				},
				cache: true
			},
			minimumInputLength: 2,
			placeholder: target.placeholder,
			allowClear: true,
			multiple: true,
			width: '100%',
			language: {
				inputTooShort: function () { return 'Escribe al menos 2 caracteres...'; },
				noResults: function () { return 'No se encontraron diagnósticos'; },
				searching: function () { return 'Buscando...'; }
			}
		});

		// Trigger change to sync Select2 with pre-populated options
		jQuery(el).trigger('change');
		console.log('CabinetMedFix: #' + target.id + ' replaced with Select2 AJAX widget');
	}

	/**
	 * Build the AJAX endpoint URL dynamically from the current page path.
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

		var token = '';
		var tokenInput = document.querySelector('input[name="token"]');
		if (tokenInput) token = tokenInput.value;

		return base + '/custom/cabinetmedfix/ajax/diagnostico_search.php?token=' + token;
	}
})();
