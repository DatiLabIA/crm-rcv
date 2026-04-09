/**
 * CabinetMedFix - Responsive Fixes for Patient Tables
 * 
 * This script makes patient list tables responsive and mobile-friendly
 * 
 * Part of the CabinetMedFix independent module
 * Survives all updates to Dolibarr and CabinetMed
 */

(function() {
	'use strict';
	
	/**
	 * Inject responsive CSS directly into the page
	 */
	function injectResponsiveCSS() {
		var css = `
			/* Ocultar filtro de diagnósticos */
			#listsearch_diagles,
			.divsearchfield:has(#listsearch_diagles) {
				display: none !important;
			}
			
			/* Hacer tablas más compactas - NO ocultar nada */
			table.liste td, table.liste th {
				padding: 3px 5px !important;
				font-size: 0.8em !important;
				white-space: nowrap;
			}
			
			/* Forzar scroll horizontal SIEMPRE */
			.div-table-responsive-no-min,
			.div-table-responsive,
			.cabinetmed-patients-list {
				overflow-x: auto !important;
				-webkit-overflow-scrolling: touch;
				width: 100% !important;
				max-width: 100% !important;
			}
			
			/* Evitar que la tabla se desborde */
			table.liste {
				width: max-content !important;
				min-width: 100%;
			}
		`;
		
		var style = document.createElement('style');
		style.type = 'text/css';
		style.id = 'cabinetmedfix-responsive-css';
		
		var existing = document.getElementById('cabinetmedfix-responsive-css');
		if (existing) {
			existing.remove();
		}
		
		if (style.styleSheet) {
			style.styleSheet.cssText = css;
		} else {
			style.appendChild(document.createTextNode(css));
		}
		
		document.getElementsByTagName('head')[0].appendChild(style);
	}

	/**
	 * Apply responsive fixes to patient tables
	 */
	function applyResponsiveFixes() {
		console.log('🔧 Aplicando mejoras responsive...');
		
		// Find all patient list tables
		var tablesFound = 0;
		jQuery('table.liste').each(function() {
			var $table = jQuery(this);
			tablesFound++;
			
			console.log('📊 Procesando tabla ' + tablesFound);
			
			// Mark table for CSS
			$table.attr('data-patient-list', 'true');
			
			var $wrapper = $table.closest('.div-table-responsive, .div-table-responsive-no-min');
			
			if ($wrapper.length > 0) {
				$wrapper.addClass('cabinetmed-patients-list');
				
				// Make first column sticky on larger screens
				if (jQuery(window).width() > 768) {
					$wrapper.addClass('sticky-first-column');
				}
			} else {
				// Create wrapper if it doesn't exist
				$table.wrap('<div class="div-table-responsive-no-min cabinetmed-patients-list"></div>');
			}
			
			// Add data-labels for mobile card view
			var headers = [];
			$table.find('thead th').each(function() {
				headers.push(jQuery(this).text().trim());
			});
			
			$table.find('tbody tr').each(function() {
				var $row = jQuery(this);
				$row.find('td').each(function(index) {
					if (headers[index]) {
						jQuery(this).attr('data-label', headers[index]);
					}
				});
			});
			
			// Ocultar columnas excesivas (más de 8 columnas visibles)
			hideExcessiveColumns($table);
		});
		
		// Add view toggle button for mobile
		addViewToggleButton();
		
		// Optimize columns for current screen size
		optimizeColumnsForScreenSize();
		
		console.log('✓ Responsive aplicado a ' + tablesFound + ' tabla(s)');
	}
	
	/**
	 * Hide excessive columns to make table more manageable
	 */
	function hideExcessiveColumns($table) {
		var $headers = $table.find('thead th');
		var totalColumns = $headers.length;
		
		console.log('📊 Tabla detectada con ' + totalColumns + ' columnas - TODAS visibles con scroll horizontal');
		
		// NO ocultar columnas, solo habilitar scroll
		var $wrapper = $table.closest('.div-table-responsive, .div-table-responsive-no-min');
		if ($wrapper.length > 0) {
			$wrapper.css({
				'overflow-x': 'auto',
				'max-width': '100%'
			});
			console.log('✓ Scroll horizontal habilitado');
		}
		
		// Loggear todas las columnas
		$headers.each(function(index) {
			console.log('  ✓ Columna ' + (index + 1) + ': ' + jQuery(this).text().trim());
		});
	}

	/**
	 * Add toggle button to switch between table and cards view on mobile
	 */
	function addViewToggleButton() {
		// Only on small screens
		if (jQuery(window).width() >= 480) {
			return;
		}
		
		var $wrapper = jQuery('.cabinetmed-patients-list').first();
		if ($wrapper.length === 0) {
			return;
		}
		
		// Don't add if already exists
		if (jQuery('#view-toggle-btn').length > 0) {
			return;
		}
		
		var $toggleBtn = jQuery('<button>')
			.attr('id', 'view-toggle-btn')
			.addClass('butAction')
			.css({
				'margin': '10px 0',
				'width': '100%'
			})
			.text('📱 Vista Cards');
		
		var isCardsView = false;
		
		$toggleBtn.on('click', function(e) {
			e.preventDefault();
			isCardsView = !isCardsView;
			
			if (isCardsView) {
				$wrapper.addClass('view-cards');
				$toggleBtn.text('📊 Vista Tabla');
			} else {
				$wrapper.removeClass('view-cards');
				$toggleBtn.text('📱 Vista Cards');
			}
		});
		
		$wrapper.before($toggleBtn);
	}

	/**
	 * Optimize table columns based on screen size
	 */
	function optimizeColumnsForScreenSize() {
		var width = jQuery(window).width();
		
		// Only on tablets and smaller
		if (width >= 1024) {
			return;
		}
		
		jQuery('.cabinetmed-patients-list table.liste').each(function() {
			var $table = jQuery(this);
			var $ths = $table.find('thead th');
			var $rows = $table.find('tbody tr');
			
			// Hide less important columns on tablets
			$ths.each(function(index) {
				var $th = jQuery(this);
				var text = $th.text().trim().toLowerCase();
				
				// List of columns to hide on smaller screens
				var hideOnTablet = [
					'fecha modificación',
					'modificado por',
					'fecha creación',
					'creado por',
					'etiquetas',
					'tags',
					'usuario asignado'
				];
				
				var shouldHide = hideOnTablet.some(function(hide) {
					return text.indexOf(hide) !== -1;
				});
				
				if (shouldHide) {
					$th.addClass('hide-on-tablet');
					$rows.each(function() {
						jQuery(this).find('td').eq(index).addClass('hide-on-tablet');
					});
				}
			});
		});
	}

	/**
	 * Wait for table to be loaded before applying fixes
	 */
	function waitForTable(callback) {
		var attempts = 0;
		var maxAttempts = 20; // Máximo 2 segundos
		
		var checkTable = function() {
			attempts++;
			var table = jQuery('table.liste');
			var colCount = table.length > 0 ? table.find('thead th').length : 0;
			
			if (table.length > 0 && colCount > 0) {
				console.log('✓ Tabla encontrada con ' + colCount + ' columnas');
				callback();
			} else if (attempts >= maxAttempts) {
				console.log('⚠ Timeout esperando tabla - aplicando fixes de todas formas');
				callback();
			} else {
				console.log('⏳ Esperando tabla... intento ' + attempts + '/' + maxAttempts);
				setTimeout(checkTable, 100);
			}
		};
		checkTable();
	}

	/**
	 * Initialize responsive fixes
	 */
	function init() {
		injectResponsiveCSS();
		applyDirectStyles();
	}
	
	function applyDirectStyles() {
		jQuery('#listsearch_diagles').closest('.divsearchfield').hide();
		
		var viewportWidth = jQuery(window).width();
		var sidebar = jQuery('#id-left').width() || 200;
		var maxWrapperWidth = viewportWidth - sidebar - 40;
		
		jQuery('.div-table-responsive-no-min, .div-table-responsive').each(function() {
			this.style.setProperty('overflow-x', 'auto', 'important');
			this.style.setProperty('-webkit-overflow-scrolling', 'touch', 'important');
			this.style.setProperty('max-width', maxWrapperWidth + 'px', 'important');
			this.style.setProperty('width', '100%', 'important');
		});
		
		var styleObserver = setInterval(function() {
			var cells = jQuery('table.liste td, table.liste th');
			if (cells.length > 0) {
				clearInterval(styleObserver);
				
				cells.each(function() {
					this.style.setProperty('padding', '3px 5px', 'important');
					this.style.setProperty('font-size', '0.8em', 'important');
					this.style.setProperty('white-space', 'nowrap', 'important');
					this.style.setProperty('min-width', '100px', 'important');
				});
				
				jQuery('table.liste thead th:nth-child(2)').each(function() {
					this.style.setProperty('position', 'sticky', 'important');
					this.style.setProperty('left', '0', 'important');
					this.style.setProperty('background', '#f9f9f9', 'important');
					this.style.setProperty('z-index', '11', 'important');
					this.style.setProperty('box-shadow', '1px 0 3px rgba(0,0,0,0.05)', 'important');
				});
				
				jQuery('table.liste tbody td:nth-child(2)').each(function() {
					this.style.setProperty('position', 'sticky', 'important');
					this.style.setProperty('left', '0', 'important');
					this.style.setProperty('background', '#fff', 'important');
					this.style.setProperty('z-index', '10', 'important');
					this.style.setProperty('box-shadow', '1px 0 3px rgba(0,0,0,0.05)', 'important');
				});
				
				jQuery('table.liste').each(function() {
					this.style.setProperty('width', 'auto', 'important');
					this.style.setProperty('table-layout', 'auto', 'important');
				});
			}
		}, 100);
		
		setTimeout(function() {
			clearInterval(styleObserver);
		}, 5000);
	}

	if (typeof jQuery !== 'undefined') {
		jQuery(document).ready(function() {
			init();
		});
	}
})();