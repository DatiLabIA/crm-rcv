/**
 * MedTriggers - Campos condicionales para Dolibarr
 * @version 1.0.2
 * @author DatiLab
 */
(function(window, $) {
    'use strict';

    if (typeof $ === 'undefined') {
        console.error('[MedTriggers] jQuery no disponible');
        return;
    }

    // CONFIGURACIÓN - Modifica aquí tus campos
    var CONFIG = {
        'guardian': [
            'fecha_entregado_guardian',
            'fecha_cambio_guardian'
        ],
        // Agrega más: 'otro_checkbox': ['campo1', 'campo2'],
    };

    var MedTriggers = {
        PREFIX: 'options_',
        DEBUG: false,

        log: function(msg, data) {
            if (this.DEBUG) console.log('[MedTriggers] ' + msg, data || '');
        },

        init: function() {
            var self = this;
            this.log('Inicializando...');
            $.each(CONFIG, function(trigger, fields) {
                self.setupTrigger(trigger, fields);
            });
        },

        setupTrigger: function(triggerCode, dependentFields) {
            var self = this;
            var selector = '#' + this.PREFIX + triggerCode;
            var $trigger = $(selector);

            if ($trigger.length === 0) {
                this.log('Trigger "' + triggerCode + '" no encontrado');
                return;
            }

            this.log('Trigger "' + triggerCode + '" configurado', dependentFields);
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
            this.log('Estado: ' + (isChecked ? 'ACTIVO' : 'INACTIVO'));

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

        findFieldRow: function(fieldCode) {
            var fullId = this.PREFIX + fieldCode;
            var $el, $row;

            // Por ID exacto
            $el = $('#' + fullId);
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            // Por prefijo ID (fechas)
            $el = $('[id^="' + fullId + '"]').first();
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            // Por name
            $el = $('[name="' + fullId + '"]');
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            // Por prefijo name
            $el = $('[name^="' + fullId + '"]').first();
            if ($el.length > 0 && ($row = $el.closest('tr')).length > 0) return $row;

            // Búsqueda en todas las filas
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

    $(document).ready(function() {
        setTimeout(function() { MedTriggers.init(); }, 100);
    });

    window.MedTriggers = MedTriggers;
})(window, jQuery);
