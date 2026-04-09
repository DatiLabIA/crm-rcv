/**
 * Conditional Fields Handler
 * Maneja la visibilidad condicional de campos personalizados
 * Versión: 2.0.0
 * Fecha: 2025-02-09
 * 
 * Estrategia: Usa data-field-name en los <tr> para encontrar filas,
 * y múltiples selectores para encontrar los inputs dentro.
 */

var ConditionalFieldsHandler = {
    /**
     * Inicializa el manejador de campos condicionales
     * @param {Array} fieldsConfig - Array de objetos con configuración de campos
     *        Cada objeto debe tener: {field_name, conditional_field, conditional_value}
     */
    init: function(fieldsConfig) {
        if (!fieldsConfig || !Array.isArray(fieldsConfig) || fieldsConfig.length === 0) {
            return;
        }

        this.fieldsConfig = fieldsConfig;
        this.debug = false; // Cambiar a true para depuración en consola
        this._log('Inicializando con', fieldsConfig.length, 'campos condicionales');
        this.setupEventListeners();
        // Pequeño delay para asegurar que el DOM esté listo después de updateSections
        var self = this;
        setTimeout(function() {
            self.updateAllFields();
        }, 350);
    },

    _log: function() {
        if (this.debug) {
            var args = ['[ConditionalFields]'].concat(Array.prototype.slice.call(arguments));
            console.log.apply(console, args);
        }
    },

    /**
     * Configura los event listeners para todos los campos padre
     */
    setupEventListeners: function() {
        var self = this;
        var watchedFields = {};

        // Identificar qué campos necesitan ser observados
        this.fieldsConfig.forEach(function(fieldConfig) {
            if (fieldConfig.conditional_field) {
                watchedFields[fieldConfig.conditional_field] = true;
            }
        });

        // Agregar listeners a los campos observados
        Object.keys(watchedFields).forEach(function(fieldName) {
            var $elements = self.getFieldInputs(fieldName);
            self._log('Observando campo "' + fieldName + '", encontrados:', $elements.length, 'elementos');
            
            if ($elements.length > 0) {
                $elements.each(function() {
                    var eventType = self.getFieldEventType(this);
                    jQuery(this).on(eventType, function() {
                        self._log('Campo padre "' + fieldName + '" cambió');
                        self.updateDependentFields(fieldName);
                    });
                });
            }
        });
    },

    /**
     * Obtiene los inputs/selects/textareas de un campo usando múltiples estrategias
     * @param {string} fieldName - Nombre lógico del campo (ej: "tipo_lesion")
     * @return {jQuery} Colección jQuery de elementos encontrados
     */
    getFieldInputs: function(fieldName) {
        // Estrategia 1: Buscar por data-field-name en el <tr> y luego los inputs dentro
        var $fromDataAttr = jQuery('tr[data-field-name="' + fieldName + '"]').find('input, textarea, select');
        if ($fromDataAttr.length > 0) {
            this._log('Encontrado por data-field-name:', fieldName);
            return $fromDataAttr;
        }
        
        // Estrategia 2: Buscar por name exacto (campo nativo como "motivo")
        var $byName = jQuery('[name="' + fieldName + '"]');
        if ($byName.length > 0) {
            this._log('Encontrado por name exacto:', fieldName);
            return $byName;
        }
        
        // Estrategia 3: Buscar como custom_fields[fieldName] con escape manual de corchetes
        var customSelector = 'custom_fields[' + fieldName + ']';
        var $byCustom = jQuery('[name]').filter(function() {
            return jQuery(this).attr('name') === customSelector;
        });
        if ($byCustom.length > 0) {
            this._log('Encontrado por custom_fields[name]:', fieldName);
            return $byCustom;
        }
        
        // Estrategia 4: Buscar como custom_fields[fieldName][] (multiselect/checkboxes legacy)
        var customSelectorArray = 'custom_fields[' + fieldName + '][]';
        var $byCustomArray = jQuery('[name]').filter(function() {
            return jQuery(this).attr('name') === customSelectorArray;
        });
        if ($byCustomArray.length > 0) {
            this._log('Encontrado por custom_fields[name][]:', fieldName);
            return $byCustomArray;
        }
        
        // Estrategia 4b: Buscar como cf_multi_fieldName[] (nuevo patrón multiselect)
        var cfMultiSelector = 'cf_multi_' + fieldName + '[]';
        var $byCfMulti = jQuery('[name]').filter(function() {
            return jQuery(this).attr('name') === cfMultiSelector;
        });
        if ($byCfMulti.length > 0) {
            this._log('Encontrado por cf_multi_name[]:', fieldName);
            return $byCfMulti;
        }
        
        // Estrategia 4c: Buscar como cf_imgtext_fieldName (textarea con imágenes)
        var cfImgtextSelector = 'cf_imgtext_' + fieldName;
        var $byCfImgtext = jQuery('[name]').filter(function() {
            return jQuery(this).attr('name') === cfImgtextSelector;
        });
        if ($byCfImgtext.length > 0) {
            this._log('Encontrado por cf_imgtext_name:', fieldName);
            return $byCfImgtext;
        }
        
        // Estrategia 5: Buscar radio buttons nativos
        var $radiosNative = jQuery('input[type="radio"][name="' + fieldName + '"]');
        if ($radiosNative.length > 0) {
            this._log('Encontrado radios nativos:', fieldName);
            return $radiosNative;
        }
        
        // Estrategia 6: Buscar radio buttons en custom_fields
        var $radiosCustom = jQuery('input[type="radio"]').filter(function() {
            return jQuery(this).attr('name') === customSelector;
        });
        if ($radiosCustom.length > 0) {
            this._log('Encontrado radios custom:', fieldName);
            return $radiosCustom;
        }

        this._log('WARN: No se encontraron inputs para:', fieldName);
        return jQuery();
    },

    /**
     * Obtiene el tipo de evento apropiado para un elemento
     * @param {Element} element - Elemento DOM
     * @return {string} Tipo de evento
     */
    getFieldEventType: function(element) {
        var tagName = element.tagName.toLowerCase();
        var type = element.type ? element.type.toLowerCase() : '';
        
        if (tagName === 'select' || type === 'checkbox' || type === 'radio') {
            return 'change';
        }
        return 'input change';
    },

    /**
     * Obtiene el valor actual de un campo
     * @param {string} fieldName - Nombre del campo
     * @return {string|Array} Valor del campo
     */
    getFieldValue: function(fieldName) {
        var $elements = this.getFieldInputs(fieldName);
        
        if ($elements.length === 0) {
            return '';
        }
        
        var element = $elements[0];
        var type = element.type ? element.type.toLowerCase() : '';
        var tagName = element.tagName.toLowerCase();
        
        // Checkbox único
        if (type === 'checkbox' && $elements.length === 1) {
            return element.checked ? '1' : '0';
        }
        
        // Radio buttons
        if (type === 'radio') {
            var $checked = $elements.filter(':checked');
            return $checked.length > 0 ? $checked.val() : '';
        }
        
        // Multiple checkboxes
        if (type === 'checkbox' && $elements.length > 1) {
            var values = [];
            $elements.filter(':checked').each(function() {
                values.push(jQuery(this).val());
            });
            return values;
        }
        
        // Select multiple
        if (tagName === 'select' && element.multiple) {
            var values = [];
            jQuery(element).find('option:selected').each(function() {
                values.push(this.value);
            });
            return values;
        }
        
        // Valor simple (text, textarea, select simple, etc.)
        return jQuery(element).val() || '';
    },

    /**
     * Verifica si un valor coincide con la condición
     * @param {string|Array} fieldValue - Valor actual del campo
     * @param {string} conditionalValue - Valor(es) esperado(s) separados por coma
     * @return {boolean}
     */
    matchesCondition: function(fieldValue, conditionalValue) {
        if (conditionalValue === null || conditionalValue === undefined || conditionalValue === '') {
            return false;
        }
        
        // Normalizar valores a arrays de strings
        var currentValues = Array.isArray(fieldValue) ? fieldValue : [String(fieldValue)];
        var expectedValues = String(conditionalValue).split(',').map(function(v) {
            return v.trim().toLowerCase();
        });
        
        // Verificar si alguno de los valores actuales coincide con alguno de los esperados
        return currentValues.some(function(currentValue) {
            var cv = String(currentValue).trim().toLowerCase();
            return expectedValues.indexOf(cv) !== -1;
        });
    },

    /**
     * Actualiza todos los campos que dependen de un campo padre
     * @param {string} parentFieldName - Nombre del campo que cambió
     */
    updateDependentFields: function(parentFieldName) {
        var self = this;
        var parentValue = this.getFieldValue(parentFieldName);
        
        this._log('Valor de "' + parentFieldName + '":', parentValue);
        
        this.fieldsConfig.forEach(function(fieldConfig) {
            if (fieldConfig.conditional_field === parentFieldName) {
                self.updateFieldVisibility(fieldConfig, parentValue);
            }
        });
    },

    /**
     * Actualiza la visibilidad de un campo específico
     * @param {Object} fieldConfig - Configuración del campo
     * @param {string|Array} parentValue - Valor del campo padre
     */
    updateFieldVisibility: function(fieldConfig, parentValue) {
        var shouldShow = this.matchesCondition(parentValue, fieldConfig.conditional_value);
        var $fieldRow = this.getFieldRow(fieldConfig.field_name);
        
        if (!$fieldRow || $fieldRow.length === 0) {
            this._log('WARN: No se encontró fila para:', fieldConfig.field_name);
            return;
        }
        
        this._log('Campo "' + fieldConfig.field_name + '":', shouldShow ? 'MOSTRAR' : 'OCULTAR', '(padre=' + fieldConfig.conditional_field + ', esperado=' + fieldConfig.conditional_value + ')');
        
        if (shouldShow) {
            $fieldRow.slideDown(200);
            $fieldRow.find('input, textarea, select').prop('disabled', false);
            $fieldRow.attr('data-conditional-hidden', 'false');
        } else {
            $fieldRow.slideUp(200);
            $fieldRow.find('input, textarea, select').prop('disabled', true);
            $fieldRow.attr('data-conditional-hidden', 'true');
        }
    },

    /**
     * Obtiene el contenedor <tr> de un campo
     * @param {string} fieldName - Nombre del campo
     * @return {jQuery} Fila del campo
     */
    getFieldRow: function(fieldName) {
        // Estrategia 1: Buscar por data-field-name (método preferido y más fiable)
        var $row = jQuery('tr[data-field-name="' + fieldName + '"]');
        if ($row.length > 0) {
            return $row;
        }
        
        // Estrategia 2: Buscar el input y subir al <tr> contenedor
        var $inputs = this.getFieldInputs(fieldName);
        if ($inputs.length > 0) {
            var $container = $inputs.first().closest('tr');
            if ($container.length > 0) {
                return $container;
            }
        }
        
        return jQuery();
    },

    /**
     * Actualiza la visibilidad de todos los campos condicionales
     * Llamar después de cambiar secciones o al inicializar
     */
    updateAllFields: function() {
        var self = this;
        this._log('updateAllFields - procesando', this.fieldsConfig.length, 'campos');
        this.fieldsConfig.forEach(function(fieldConfig) {
            if (fieldConfig.conditional_field) {
                var parentValue = self.getFieldValue(fieldConfig.conditional_field);
                self.updateFieldVisibility(fieldConfig, parentValue);
            }
        });
    }
};

// Exportar globalmente
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ConditionalFieldsHandler;
}
