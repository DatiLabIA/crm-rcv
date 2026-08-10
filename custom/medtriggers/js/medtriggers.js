/**
 * MedTriggers - Campos condicionales + Selects dependientes para Dolibarr
 * @version 2.2.0
 * @author DatiLab
 *
 * Funcionalidades:
 *   1. Toggle show/hide de campos dependientes de un checkbox (v1 original)
 *   2. Selects dinámicos: al cambiar un select "padre", se cargan vía AJAX
 *      las opciones del select "hijo" (ej: medicamento → concentración)
 *
 * NOTA: Este JS es autocontenido. No depende del hook PHP para funcionar.
 *       Si window.MedTriggersConf existe (inyectado por hook), lo usa como override.
 *       Si no existe, auto-detecta la URL AJAX y usa la config embebida.
 */
(function(window, $) {
    'use strict';

    if (typeof $ === 'undefined') {
        console.error('[MedTriggers] jQuery no disponible');
        return;
    }

    // =========================================================================
    //  CONFIGURACIÓN EMBEBIDA (no depende del hook PHP)
    // =========================================================================

    /**
     * Toggle show/hide: checkbox → campos dependientes
     * Formato: { 'codigo_checkbox': ['campo_dep1', 'campo_dep2'] }
     */
    var TOGGLE_CONFIG = {
        'guardian': [
            'fecha_entregado_guardian',
            'fecha_cambio_guardian'
        ],
        // Agrega más: 'otro_checkbox': ['campo1', 'campo2'],
    };

    /**
     * Selects dependientes: padre → hijo con carga AJAX
     * Formato: { 'campo_padre': { childField, ajaxParam, emptyLabel } }
     *
     * Puede ser sobreescrito por window.MedTriggersConf.dependentSelects (desde hook PHP)
     */
    var DEFAULT_DEPENDENT_SELECTS = {
        'medicamento': {
            childField:  'concentracion',
            ajaxParam:   'medicamento_id',
            emptyLabel:  '-- Seleccione concentración --'
        }
        // Agrega más pares: 'diagnostico': { childField: 'tratamiento', ... }
    };

    // =========================================================================
    //  MÓDULO PRINCIPAL
    // =========================================================================

    var MedTriggers = {
        PREFIX: 'options_',
        DEBUG: false,

        log: function(msg, data) {
            if (this.DEBUG) console.log('[MedTriggers] ' + msg, data || '');
        },

        warn: function(msg, data) {
            console.warn('[MedTriggers] ' + msg, data || '');
        },

        // -----------------------------------------------------------------
        //  Auto-detección de la URL AJAX desde el propio <script>
        // -----------------------------------------------------------------

        /**
         * Detecta la URL base del módulo a partir del src del script actual.
         * Ej: si el script está en /custom/medtriggers/js/medtriggers.js
         *     devuelve /custom/medtriggers/ajax/get_concentraciones.php
         */
        detectAjaxUrl: function() {
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                var src = scripts[i].src || '';
                var idx = src.indexOf('/medtriggers/js/medtriggers.js');
                if (idx !== -1) {
                    var basePath = src.substring(0, idx);
                    var url = basePath + '/medtriggers/ajax/get_concentraciones.php';
                    this.log('AJAX URL auto-detectada: ' + url);
                    return url;
                }
            }
            // Fallback: intentar ruta relativa común
            this.warn('No se pudo auto-detectar URL del script, usando fallback');
            return '/custom/medtriggers/ajax/get_concentraciones.php';
        },

        // -----------------------------------------------------------------
        //  Resolver configuración (hook override > config embebida)
        // -----------------------------------------------------------------

        resolveConfig: function() {
            var hookConf = window.MedTriggersConf || {};

            return {
                ajaxUrl:         hookConf.ajaxUrl || this.detectAjaxUrl(),
                dependentSelects: hookConf.dependentSelects || DEFAULT_DEPENDENT_SELECTS
            };
        },

        // -----------------------------------------------------------------
        //  Inicialización general
        // -----------------------------------------------------------------
        init: function() {
            var self = this;
            this.log('Inicializando MedTriggers v2.2.0...');

            // 1) Toggle show/hide (original)
            $.each(TOGGLE_CONFIG, function(trigger, fields) {
                self.setupTrigger(trigger, fields);
            });

            // 2) Selects dependientes
            this.initDependentSelects();
        },

        // =================================================================
        //  SECCIÓN 1: TOGGLE SHOW/HIDE
        // =================================================================

        setupTrigger: function(triggerCode, dependentFields) {
            var self = this;
            var selector = '#' + this.PREFIX + triggerCode;
            var $trigger = $(selector);

            if ($trigger.length === 0) {
                this.log('Trigger "' + triggerCode + '" no encontrado en DOM');
                return;
            }

            this.log('Toggle configurado: "' + triggerCode + '"', dependentFields);
            this.toggleFields($trigger, dependentFields);

            $(document).on('change', selector, function() {
                self.toggleFields($(this), dependentFields);
            });

            $(document).on('click', 'label[for="' + this.PREFIX + triggerCode + '"]', function() {
                setTimeout(function() { self.toggleFields($trigger, dependentFields); }, 50);
            });
        },

        toggleFields: function($trigger, dependentFields) {
            var self = this;
            var isChecked = $trigger.prop('checked');
            this.log('Toggle estado: ' + (isChecked ? 'ACTIVO' : 'INACTIVO'));

            $.each(dependentFields, function(i, fieldCode) {
                self.toggleSingleField(fieldCode, isChecked);
            });
        },

        toggleSingleField: function(fieldCode, show) {
            var $row = this.findFieldRow(fieldCode);
            if ($row && $row.length > 0) {
                $row.toggle(show);
                this.log((show ? 'Mostrando' : 'Ocultando') + ': ' + fieldCode);
            }
        },

        // =================================================================
        //  SECCIÓN 2: SELECTS DEPENDIENTES (medicamento → concentración)
        // =================================================================

        initDependentSelects: function() {
            var conf = this.resolveConfig();
            var self = this;

            this.log('Config resuelta — ajaxUrl: ' + conf.ajaxUrl, conf.dependentSelects);

            $.each(conf.dependentSelects, function(parentField, config) {
                self.setupDependentSelect(parentField, config, conf.ajaxUrl);
            });
        },

        /**
         * Configura un par de selects dependientes.
         *
         * @param {string} parentField  - Código del extrafield padre (sin prefix), ej: 'medicamento'
         * @param {object} config       - { childField, ajaxParam, emptyLabel }
         * @param {string} ajaxUrl      - URL del endpoint AJAX
         */
        setupDependentSelect: function(parentField, config, ajaxUrl) {
            var self = this;
            var parentName = this.PREFIX + parentField;
            var childName  = this.PREFIX + config.childField;

            // Buscar el select padre
            var $parent = this.findSelectElement(parentName);

            if ($parent.length === 0) {
                this.log('Select padre "' + parentField + '" no encontrado en DOM (página sin este campo)');
                return;
            }

            var $child = this.findSelectElement(childName);
            if ($child.length === 0) {
                this.log('Select hijo "' + config.childField + '" no encontrado en DOM');
                return;
            }

            this.log('Selects dependientes configurados: ' + parentField + ' → ' + config.childField);

            // Guardar el valor actual del hijo (para restaurar en carga inicial)
            var currentChildValue = $child.val();

            // Handler del cambio
            var onChange = function() {
                var parentValue = self.getSelectValue($parent);
                self.log('Padre "' + parentField + '" cambió a: ' + parentValue);

                if (!parentValue || parentValue === '' || parentValue === '0' || parentValue === '-1') {
                    self.resetChildSelect($child, config.emptyLabel || '');
                    return;
                }

                self.loadChildOptions($child, ajaxUrl, config.ajaxParam, parentValue, config.emptyLabel, currentChildValue);
                // Solo restaurar valor previo en la primera carga
                currentChildValue = null;
            };

            // Escuchar cambios (compatible con Select2 y select nativo)
            $parent.on('change', onChange);

            // Select2 v4 dispara 'select2:select' en vez de 'change' en algunos casos
            $parent.on('select2:select select2:clear', function() {
                setTimeout(onChange, 50);
            });

            // Disparar carga inicial si el padre ya tiene valor seleccionado
            var initialValue = this.getSelectValue($parent);
            if (initialValue && initialValue !== '' && initialValue !== '0' && initialValue !== '-1') {
                this.log('Carga inicial: padre="' + initialValue + '", hijo actual="' + currentChildValue + '"');
                self.loadChildOptions($child, ajaxUrl, config.ajaxParam, initialValue, config.emptyLabel, currentChildValue);
            } else {
                // Limpiar el hijo si el padre no tiene valor
                self.resetChildSelect($child, config.emptyLabel || '');
            }
        },

        /**
         * Obtiene el valor real de un select, compatible con Select2.
         */
        getSelectValue: function($el) {
            var val = $el.val();
            // Select2 a veces devuelve array
            if ($.isArray(val)) val = val[0] || '';
            return val ? String(val) : '';
        },

        /**
         * Busca un elemento select por nombre del extrafield.
         * Dolibarr puede renderizar como <select name="options_X"> o con Select2.
         */
        findSelectElement: function(fieldName) {
            var $el;

            // 1. Por name exacto
            $el = $('select[name="' + fieldName + '"]');
            if ($el.length > 0) return $el;

            // 2. Por ID exacto
            $el = $('select#' + fieldName);
            if ($el.length > 0) return $el;

            // 3. Dolibarr a veces agrega sufijos al name para listas
            $el = $('select[name^="' + fieldName + '"]').first();
            if ($el.length > 0) return $el;

            // 4. Input hidden de Select2
            $el = $('input[name="' + fieldName + '"]').filter(function() {
                return $(this).data('select2') !== undefined;
            });
            if ($el.length > 0) return $el;

            // 5. Select2 v4: buscar por data-select2-id o aria
            $el = $('select[data-select2-id^="' + fieldName + '"]');
            if ($el.length > 0) return $el;

            return $([]);
        },

        /**
         * Carga las opciones del select hijo vía AJAX.
         */
        loadChildOptions: function($child, ajaxUrl, paramName, parentValue, emptyLabel, restoreValue) {
            var self = this;
            var postData = {};
            postData[paramName] = parentValue;

            // Token CSRF de Dolibarr
            var csrfToken = $('input[name="token"]').val();
            if (csrfToken) {
                postData.token = csrfToken;
            }

            // Indicador de carga
            self.setChildLoading($child, true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: postData,
                dataType: 'json',
                timeout: 10000,
                success: function(data) {
                    self.log('AJAX respondió: ' + (data ? data.length : 0) + ' opciones', data);
                    self.populateChildSelect($child, data, emptyLabel, restoreValue);
                },
                error: function(xhr, status, error) {
                    self.warn('Error AJAX (' + status + '): ' + error + ' — URL: ' + ajaxUrl);
                    self.resetChildSelect($child, '-- Error al cargar --');
                },
                complete: function() {
                    self.setChildLoading($child, false);
                }
            });
        },

        /**
         * Rellena el select hijo con las opciones recibidas del AJAX.
         */
        populateChildSelect: function($child, data, emptyLabel, restoreValue) {
            var isSelect2 = this.isSelect2Element($child);

            // Destruir Select2 temporalmente
            if (isSelect2) {
                try { $child.select2('destroy'); } catch(e) {}
            }

            // Limpiar y reconstruir
            $child.empty();

            // Opción vacía / placeholder
            if (emptyLabel !== false) {
                $child.append($('<option>', {
                    value: '',
                    text: emptyLabel || '-- Seleccione --'
                }));
            }

            // Agregar opciones de la respuesta
            if (data && data.length > 0) {
                $.each(data, function(i, item) {
                    $child.append($('<option>', {
                        value: item.id,
                        text:  item.label
                    }));
                });
            }

            // Restaurar valor previo si existe entre las nuevas opciones
            if (restoreValue && $child.find('option[value="' + restoreValue + '"]').length > 0) {
                $child.val(restoreValue);
                this.log('Valor restaurado: ' + restoreValue);
            }

            // Re-inicializar Select2
            if (isSelect2) {
                this.reinitSelect2($child, emptyLabel);
            }

            $child.trigger('change');
        },

        /**
         * Limpia el select hijo.
         */
        resetChildSelect: function($child, label) {
            var isSelect2 = this.isSelect2Element($child);

            if (isSelect2) {
                try { $child.select2('destroy'); } catch(e) {}
            }

            $child.empty();
            $child.append($('<option>', {
                value: '',
                text: label || ''
            }));

            if (isSelect2) {
                this.reinitSelect2($child, label);
            }

            $child.trigger('change');
        },

        /**
         * Detecta si un elemento está usando Select2.
         */
        isSelect2Element: function($el) {
            return $el.hasClass('select2-offscreen') ||
                   $el.hasClass('select2-hidden-accessible') ||
                   $el.data('select2') !== undefined ||
                   $el.next('.select2-container').length > 0 ||
                   $el.hasClass('select2');
        },

        /**
         * Re-inicializa Select2 en un elemento.
         */
        reinitSelect2: function($el, placeholder) {
            try {
                $el.select2({
                    width: 'resolve',
                    allowClear: true,
                    placeholder: placeholder || '-- Seleccione --'
                });
            } catch(e) {
                this.log('Select2 re-init falló, continuando sin él');
            }
        },

        /**
         * Muestra/oculta spinner de carga junto al select hijo.
         */
        setChildLoading: function($child, loading) {
            var $container = $child.closest('td, div');
            if (loading) {
                if ($container.find('.medtriggers-loading').length === 0) {
                    $container.append(
                        '<span class="medtriggers-loading" style="margin-left:5px;color:#888;font-size:0.85em;">' +
                        '<i class="fas fa-spinner fa-spin"></i> Cargando...</span>'
                    );
                }
                $child.prop('disabled', true);
            } else {
                $container.find('.medtriggers-loading').remove();
                $child.prop('disabled', false);
            }
        },

        // =================================================================
        //  UTILIDAD: Buscar fila TR de un campo (para toggle)
        // =================================================================

        findFieldRow: function(fieldCode) {
            var fullId = this.PREFIX + fieldCode;
            var $el, $row;

            $el = $('#' + fullId);
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            $el = $('[id^="' + fullId + '"]').first();
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            $el = $('[name="' + fullId + '"]');
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            $el = $('[name^="' + fullId + '"]').first();
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            $('tr').each(function() {
                var $tr = $(this);
                if ($tr.find('[id*="' + fieldCode + '"], [name*="' + fieldCode + '"]').length > 0) {
                    $row = $tr;
                    return false;
                }
            });
            return $row;
        }
    };

    // =========================================================================
    //  BOOTSTRAP
    // =========================================================================

    $(document).ready(function() {
        // Delay para que Dolibarr termine de renderizar Select2
        setTimeout(function() { MedTriggers.init(); }, 300);
    });

    // Exponer globalmente para debug
    window.MedTriggers = MedTriggers;

})(window, jQuery);
