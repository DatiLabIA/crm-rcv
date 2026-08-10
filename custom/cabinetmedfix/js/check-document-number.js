(function () {
	'use strict';

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

		return base + '/custom/cabinetmedfix/ajax/check_document.php?token=' + token;
	}

	function init() {
		if (typeof jQuery === 'undefined') return;

		var $ = jQuery;
		var $input = $('#options_n_documento');
		if (!$input.length) return;

		var $msgBox = $('<div id="n_documento_msg" style="margin-top: 5px; font-size: 0.9em;"></div>');
		$input.after($msgBox);

		var timeout = null;
		var ajaxUrl = buildAjaxUrl();

		$input.on('input', function () {
			var val = $(this).val().trim();
			$msgBox.empty();

			if (timeout) {
				clearTimeout(timeout);
			}

			if (val.length < 3) return;

			timeout = setTimeout(function () {
				$msgBox.html('<span style="color: #888;">Buscando...</span>');

				var currentId = '';
				if ($('input[name="id"]').length) currentId = $('input[name="id"]').val();
				else if ($('input[name="socid"]').length) currentId = $('input[name="socid"]').val();
				else {
					var urlParams = new URLSearchParams(window.location.search);
					if (urlParams.has('id')) currentId = urlParams.get('id');
					else if (urlParams.has('socid')) currentId = urlParams.get('socid');
				}

				$.ajax({
					url: ajaxUrl,
					method: 'POST',
					data: { documento: val, id: currentId },
					dataType: 'json',
					success: function (data) {
						if (data.exists) {
							$msgBox.html('<span style="color: #c00;"><strong>Advertencia:</strong> Ya existe un paciente con este documento (' + data.patient_name + ').</span>');
						} else {
							$msgBox.html('<span style="color: #0c0;">Documento disponible.</span>');
						}
					},
					error: function () {
						$msgBox.html('<span style="color: #c00;">Error al comprobar el documento.</span>');
					}
				});
			}, 800);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
