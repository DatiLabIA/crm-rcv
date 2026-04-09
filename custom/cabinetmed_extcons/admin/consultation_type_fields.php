<?php
/* Copyright (C) 2024 Your Company
 * Manage Custom Fields for Consultation Type
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array("admin", "cabinetmed_extcons@cabinetmed_extcons"));

if (!$user->admin) accessforbidden();

dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');

// AJAX: Obtener columnas de una tabla
if (GETPOST('ajax_get_columns', 'alpha')) {
    $table_name = GETPOST('table_name', 'alpha');
    header('Content-Type: application/json');
    if (!empty($table_name)) {
        $columns = ExtConsultation::getTableColumns($db, $table_name);
        echo json_encode(array('success' => true, 'columns' => $columns));
    } else {
        echo json_encode(array('success' => false, 'columns' => array()));
    }
    exit;
}

// AJAX: Previsualizar opciones de tabla
if (GETPOST('ajax_preview_db_options', 'alpha')) {
    $db_options_str = GETPOST('db_options_str', 'restricthtml');
    header('Content-Type: application/json');
    if (!empty($db_options_str) && strpos($db_options_str, 'db:') === 0) {
        $opts = ExtConsultation::resolveDbFieldOptions($db_options_str, $db);
        echo json_encode(array('success' => true, 'count' => count($opts), 'options' => $opts));
    } else {
        echo json_encode(array('success' => false, 'options' => array()));
    }
    exit;
}

$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$type_id = GETPOST('type_id', 'int');
$field_id = GETPOST('field_id', 'int');
$confirm = GETPOST('confirm', 'alpha');

$form = new Form($db);
$error = 0;

/**
 * Renderiza el control apropiado para seleccionar valores condicionales
 * 
 * @param string $field_type Tipo del campo padre
 * @param string $field_options Opciones del campo padre
 * @param string $current_value Valor actual
 * @param Form $form Objeto Form de Dolibarr
 * @return string HTML del control
 */
function renderConditionalValueControl($field_type, $field_options, $current_value, $form) {
    global $db;
    $html = '';
    
    switch ($field_type) {
        case 'select':
        case 'radio':
        case 'multiselect':
            // Para select, radio y multiselect: permitir seleccionar múltiples valores activadores
            $current_values = !empty($current_value) ? array_map('trim', explode(',', $current_value)) : array();
            $html = '<div style="border: 1px solid #ccc; padding: 10px; max-height: 200px; overflow-y: auto;">';
            
            $resolved = ExtConsultation::resolveFieldOptions($field_options, $db);
            foreach($resolved as $key => $label) {
                $checked = in_array((string)$key, $current_values) ? ' checked' : '';
                $html .= '<label style="display:block; margin-bottom:5px;">';
                $html .= '<input type="checkbox" name="conditional_value_multi[]" value="'.dol_escape_htmltag($key).'"'.$checked.'> ';
                $html .= dol_escape_htmltag($label);
                $html .= '</label>';
            }
            
            $html .= '</div>';
            $html .= '<input type="hidden" name="conditional_value" id="conditional_value_hidden" value="'.dol_escape_htmltag($current_value).'">';
            $html .= '<script>
                jQuery(document).ready(function() {
                    jQuery("input[name=\'conditional_value_multi[]\']").change(function() {
                        var selected = [];
                        jQuery("input[name=\'conditional_value_multi[]\']:checked").each(function() {
                            selected.push(jQuery(this).val());
                        });
                        jQuery("#conditional_value_hidden").val(selected.join(","));
                    });
                });
            </script>';
            $html .= '<br><span class="opacitymedium">Selecciona uno o más valores que activarán este campo</span>';
            break;
        
        case 'boolean':
            $opts = array(
                '' => '-',
                '1' => 'Sí',
                '0' => 'No'
            );
            $html = $form->selectarray('conditional_value', $opts, $current_value, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
            $html .= '<br><span class="opacitymedium">Selecciona el valor que activará este campo</span>';
            break;
        
        case 'checkbox':
            $opts = array(
                '' => '-',
                '1' => 'Marcado',
                '0' => 'No marcado'
            );
            $html = $form->selectarray('conditional_value', $opts, $current_value, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
            $html .= '<br><span class="opacitymedium">Selecciona el estado que activará este campo</span>';
            break;
        
        default:
            $html = '<input type="text" name="conditional_value" id="conditional_value_text" value="'.dol_escape_htmltag($current_value).'" class="flat quatrevingtpercent">';
            $html .= '<br><span class="opacitymedium">Introduce el valor exacto que debe tener el campo padre para mostrar este campo</span>';
    }
    
    return $html;
}

// Get type info
$type_code = '';
$type_label = '';
if ($type_id > 0) {
    $sql = "SELECT code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
    $sql .= " WHERE rowid = ".((int) $type_id);
    $sql .= " AND entity = ".$conf->entity;
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql)) {
        $obj = $db->fetch_object($resql);
        $type_code = $obj->code;
        $type_label = $obj->label;
    } else {
        accessforbidden();
    }
}

/*
 * Actions
 */

if ($cancel) {
    $action = '';
}

// Add field
if ($action == 'add' && !$cancel) {
    $field_name = trim(GETPOST('field_name', 'alpha'));
    $field_label = trim(GETPOST('field_label', 'restricthtml'));
    $field_type = GETPOST('field_type', 'alpha');
    $field_options = trim(GETPOST('field_options', 'restricthtml'));
    $required = GETPOST('required', 'int') ? 1 : 0;
    $position = GETPOST('position', 'int');
    $conditional_field = trim(GETPOST('conditional_field', 'alpha'));
    $conditional_value = trim(GETPOST('conditional_value', 'restricthtml'));
    
    if (empty($field_name) || empty($field_label)) {
        $error++;
        setEventMessages("Error: El nombre del campo y la etiqueta son obligatorios", null, 'errors');
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_fields (";
        $sql .= " entity, fk_type, field_name, field_label, field_type, field_options, conditional_field, conditional_value, required, position, active, datec";
        $sql .= ") VALUES (";
        $sql .= $conf->entity;
        $sql .= ", ".((int) $type_id);
        $sql .= ", '".$db->escape($field_name)."'";
        $sql .= ", '".$db->escape($field_label)."'";
        $sql .= ", '".$db->escape($field_type)."'";
        $sql .= ", '".$db->escape($field_options)."'";
        $sql .= ", ".($conditional_field ? "'".$db->escape($conditional_field)."'" : "NULL");
        $sql .= ", ".($conditional_value ? "'".$db->escape($conditional_value)."'" : "NULL");
        $sql .= ", ".$required;
        $sql .= ", ".((int) $position);
        $sql .= ", 1";
        $sql .= ", '".$db->idate(dol_now())."'";
        $sql .= ")";
        
        if ($db->query($sql)) {
            setEventMessages("Registro guardado exitosamente", null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]."?type_id=".$type_id);
            exit;
        } else {
            $error++;
            setEventMessages($db->lasterror(), null, 'errors');
        }
    }
    
    if ($error) {
        $action = 'create';
    }
}

// Update field
if ($action == 'update' && !$cancel) {
    $field_label = trim(GETPOST('field_label', 'restricthtml'));
    $field_type = GETPOST('field_type', 'alpha');
    $field_options = trim(GETPOST('field_options', 'restricthtml'));
    $required = GETPOST('required', 'int') ? 1 : 0;
    $position = GETPOST('position', 'int');
    $active = GETPOST('active', 'int') ? 1 : 0;
    $conditional_field = trim(GETPOST('conditional_field', 'alpha'));
    $conditional_value = trim(GETPOST('conditional_value', 'restricthtml'));
    
    if (empty($field_label)) {
        $error++;
        setEventMessages("Error: La etiqueta es obligatoria", null, 'errors');
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."cabinetmed_extcons_fields SET";
        $sql .= " field_label = '".$db->escape($field_label)."'";
        $sql .= ", field_type = '".$db->escape($field_type)."'";
        $sql .= ", field_options = '".$db->escape($field_options)."'";
        $sql .= ", conditional_field = ".($conditional_field ? "'".$db->escape($conditional_field)."'" : "NULL");
        $sql .= ", conditional_value = ".($conditional_value ? "'".$db->escape($conditional_value)."'" : "NULL");
        $sql .= ", required = ".$required;
        $sql .= ", position = ".((int) $position);
        $sql .= ", active = ".$active;
        $sql .= " WHERE rowid = ".((int) $field_id);
        $sql .= " AND entity = ".$conf->entity;
        
        if ($db->query($sql)) {
            setEventMessages("Registro modificado exitosamente", null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]."?type_id=".$type_id);
            exit;
        } else {
            $error++;
            setEventMessages($db->lasterror(), null, 'errors');
        }
    }
    
    if ($error) {
        $action = 'edit';
    }
}

// Delete field
if ($action == 'confirm_delete' && $confirm == 'yes') {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
    $sql .= " WHERE rowid = ".((int) $field_id);
    $sql .= " AND entity = ".$conf->entity;
    
    if ($db->query($sql)) {
        setEventMessages("Registro eliminado", null, 'mesgs');
    } else {
        setEventMessages($db->lasterror(), null, 'errors');
    }
}

/*
 * View
 */

$title = 'Campos Personalizados - '.$type_label;
llxHeader('', $title, '');

$linkback = '<a href="'.dol_buildpath('/cabinetmed_extcons/admin/consultation_types.php', 1).'">Volver a la lista</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Delete confirmation
if ($action == 'delete') {
    print $form->formconfirm(
        $_SERVER["PHP_SELF"].'?type_id='.$type_id.'&field_id='.$field_id,
        'Eliminar campo',
        '¿Estás seguro de que quieres eliminar este campo?',
        'confirm_delete',
        '',
        0,
        1
    );
}

// Create/Edit form
if ($action == 'create' || $action == 'edit') {
    $field_name = '';
    $field_label = '';
    $field_type = 'text';
    $field_options = '';
    $required = 0;
    $position = 0;
    $active = 1;
    $conditional_field = '';
    $conditional_value = '';
    
    if ($action == 'edit' && $field_id > 0) {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
        $sql .= " WHERE rowid = ".((int) $field_id);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql)) {
            $obj = $db->fetch_object($resql);
            $field_name = $obj->field_name;
            $field_label = $obj->field_label;
            $field_type = $obj->field_type;
            $field_options = $obj->field_options;
            $required = $obj->required;
            $position = $obj->position;
            $active = $obj->active;
            $conditional_field = $obj->conditional_field;
            $conditional_value = $obj->conditional_value;
        }
    }
    
    // Obtener lista de campos existentes para el selector de condiciones
    $available_fields = array();
    $available_fields_data = array(); // Para guardar datos completos (tipo, opciones)
    $sql = "SELECT field_name, field_label, field_type, field_options FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
    $sql .= " WHERE fk_type = ".((int) $type_id);
    $sql .= " AND entity = ".$conf->entity;
    if ($action == 'edit' && $field_id > 0) {
        $sql .= " AND rowid != ".((int) $field_id); // Excluir el campo actual
    }
    $sql .= " ORDER BY position ASC";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $available_fields[$obj->field_name] = $obj->field_label.' ('.$obj->field_type.')';
            $available_fields_data[$obj->field_name] = array(
                'label' => $obj->field_label,
                'type' => $obj->field_type,
                'options' => $obj->field_options
            );
        }
    }
    
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="type_id" value="'.$type_id.'">';
    print '<input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($action == 'edit') {
        print '<input type="hidden" name="field_id" value="'.$field_id.'">';
    }
    
    print '<table class="border centpercent">';
    
    print '<tr><td class="titlefieldcreate'.($action == 'create' ? ' fieldrequired' : '').'">Nombre del campo (código)</td><td>';
    if ($action == 'create') {
        print '<input type="text" name="field_name" value="'.dol_escape_htmltag($field_name).'" class="flat minwidth300" maxlength="64" required>';
        print '<br><span class="opacitymedium">Solo caracteres alfanuméricos (ej: mi_campo_custom)</span>';
    } else {
        print '<strong>'.$field_name.'</strong>';
    }
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Etiqueta</td><td>';
    print '<input type="text" name="field_label" value="'.dol_escape_htmltag($field_label).'" class="flat minwidth300" required>';
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Tipo de campo</td><td>';
    $field_types = array(
        'text' => 'Texto Corto',
        'textarea' => 'Área de Texto',
        'number' => 'Número',
        'integer' => 'Número Entero',
        'price' => 'Precio/Moneda',
        'date' => 'Fecha',
        'datetime' => 'Fecha y Hora',
        'time' => 'Hora',
        'checkbox' => 'Casilla (Checkbox)',
        'boolean' => 'Sí/No',
        'select' => 'Lista de Selección',
        'multiselect' => 'Selección Múltiple',
        'radio' => 'Botones de Radio',
        'email' => 'Correo Electrónico',
        'phone' => 'Teléfono',
        'url' => 'URL/Enlace',
        'password' => 'Contraseña',
        'color' => 'Selector de Color',
        'range' => 'Rango/Deslizador',
        'stars' => 'Calificación (Estrellas)',
        'percentage' => 'Porcentaje',
        'html' => 'Editor HTML',
        'separator' => 'Separador/Título de Sección'
    );
    print $form->selectarray('field_type', $field_types, $field_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
    print '</td></tr>';
    
    print '<tr><td class="tdtop">Opciones</td><td>';
    
    // Detectar si las opciones actuales son de tipo BD
    $is_db_options = (strpos($field_options, 'db:') === 0);
    
    // Toggle entre opciones manuales y de BD
    print '<div style="margin-bottom:8px;">';
    print '<label style="margin-right:15px;"><input type="radio" name="options_source" value="manual" class="options-source-toggle"'.(!$is_db_options ? ' checked' : '').'> Opciones manuales</label>';
    print '<label><input type="radio" name="options_source" value="database" class="options-source-toggle"'.($is_db_options ? ' checked' : '').'> Valores desde tabla de base de datos</label>';
    print '</div>';
    
    // Sección: opciones manuales
    print '<div id="manual_options_section" style="'.($is_db_options ? 'display:none;' : '').'">';
    print '<textarea name="field_options_manual" rows="3" class="flat quatrevingtpercent">'.(!$is_db_options ? dol_escape_htmltag($field_options) : '').'</textarea>';
    print '<br><span class="opacitymedium"><strong>Ayuda según tipo de campo:</strong><br>';
    print '• <strong>select, multiselect, radio:</strong> Opciones separadas por comas. Formato: opción1, opción2, opción3 ó clave1:Etiqueta1, clave2:Etiqueta2<br>';
    print '• <strong>range:</strong> min, max, paso (ej: 0, 100, 5)<br>';
    print '• <strong>stars:</strong> Número máximo de estrellas (ej: 5)<br>';
    print '• <strong>separator:</strong> Texto del título de la sección</span>';
    print '</div>';
    
    // Sección: opciones desde BD
    // Obtener lista de tablas disponibles
    $available_tables = ExtConsultation::getAvailableDbTables($db);
    
    // Parsear valores actuales si es tipo BD
    $db_table_current = '';
    $db_key_current = '';
    $db_label_current = '';
    $db_where_current = '';
    if ($is_db_options) {
        $db_parts = explode(':', $field_options);
        $db_table_current = isset($db_parts[1]) ? $db_parts[1] : '';
        $db_key_current = isset($db_parts[2]) ? $db_parts[2] : '';
        $db_label_current = isset($db_parts[3]) ? $db_parts[3] : '';
        $db_where_current = isset($db_parts[4]) ? $db_parts[4] : '';
    }
    
    print '<div id="db_options_section" style="'.(!$is_db_options ? 'display:none;' : '').'">';
    print '<table class="nobordernopadding" style="width:100%;">';
    
    // Tabla
    print '<tr><td style="width:120px;"><strong>Tabla:</strong></td><td>';
    print '<select name="db_table" id="db_table" class="flat minwidth300">';
    print '<option value="">-- Seleccionar tabla --</option>';
    foreach ($available_tables as $tbl) {
        $selected = ($tbl == $db_table_current) ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($tbl).'"'.$selected.'>'.dol_escape_htmltag($tbl).'</option>';
    }
    print '</select>';
    print '</td></tr>';
    
    // Campo clave (valor)
    print '<tr><td><strong>Campo clave (valor):</strong></td><td>';
    print '<select name="db_key_field" id="db_key_field" class="flat minwidth200">';
    print '<option value="">-- Seleccionar --</option>';
    // Se llenará por AJAX cuando se seleccione la tabla
    if (!empty($db_key_current) && !empty($db_table_current)) {
        $current_cols = ExtConsultation::getTableColumns($db, $db_table_current);
        foreach ($current_cols as $col) {
            $selected = ($col == $db_key_current) ? ' selected' : '';
            print '<option value="'.dol_escape_htmltag($col).'"'.$selected.'>'.dol_escape_htmltag($col).'</option>';
        }
    }
    print '</select>';
    print ' <span class="opacitymedium">(ej: rowid, code)</span>';
    print '</td></tr>';
    
    // Campo etiqueta (lo que se muestra)
    print '<tr><td><strong>Campo etiqueta (mostrar):</strong></td><td>';
    print '<select name="db_label_field" id="db_label_field" class="flat minwidth200">';
    print '<option value="">-- Seleccionar --</option>';
    if (!empty($db_label_current) && !empty($db_table_current)) {
        foreach ($current_cols as $col) {
            $selected = ($col == $db_label_current) ? ' selected' : '';
            print '<option value="'.dol_escape_htmltag($col).'"'.$selected.'>'.dol_escape_htmltag($col).'</option>';
        }
    }
    print '</select>';
    print ' <span class="opacitymedium">(ej: label, nom, name)</span>';
    print '</td></tr>';
    
    // Filtro WHERE opcional
    print '<tr><td><strong>Filtro WHERE (opcional):</strong></td><td>';
    print '<input type="text" name="db_where" id="db_where" value="'.dol_escape_htmltag($db_where_current).'" class="flat quatrevingtpercent" placeholder="ej: active=1 AND entity=1">';
    print '</td></tr>';
    
    // Previsualización
    print '<tr><td></td><td>';
    print '<button type="button" id="btn_preview_db" class="button" style="margin-top:5px;">Previsualizar opciones</button>';
    print ' <span id="db_preview_count" class="opacitymedium"></span>';
    print '<div id="db_preview_result" style="margin-top:5px; max-height:150px; overflow-y:auto; border:1px solid #ddd; padding:5px; display:none;"></div>';
    print '</td></tr>';
    
    print '</table>';
    print '</div>';
    
    // Campo oculto real que se enviará
    print '<input type="hidden" name="field_options" id="field_options_real" value="'.dol_escape_htmltag($field_options).'">';
    
    // JavaScript para el manejo de opciones BD
    print '<script type="text/javascript">
    jQuery(document).ready(function() {
        // Toggle entre manual y BD
        jQuery(".options-source-toggle").change(function() {
            var source = jQuery(this).val();
            if (source === "database") {
                jQuery("#manual_options_section").slideUp();
                jQuery("#db_options_section").slideDown();
                updateDbFieldOptions();
            } else {
                jQuery("#db_options_section").slideUp();
                jQuery("#manual_options_section").slideDown();
                jQuery("#field_options_real").val(jQuery("textarea[name=field_options_manual]").val());
            }
        });
        
        // Al cambiar opciones manuales, actualizar campo oculto
        jQuery("textarea[name=field_options_manual]").on("input", function() {
            if (jQuery("input[name=options_source]:checked").val() === "manual") {
                jQuery("#field_options_real").val(jQuery(this).val());
            }
        });
        
        // Al cambiar tabla, cargar columnas por AJAX
        jQuery("#db_table").change(function() {
            var table = jQuery(this).val();
            if (!table) return;
            
            jQuery.ajax({
                url: "'.dol_buildpath('/cabinetmed_extcons/admin/consultation_type_fields.php', 1).'",
                data: { ajax_get_columns: 1, table_name: table, type_id: '.((int)$type_id).' },
                dataType: "json",
                success: function(data) {
                    if (data.success) {
                        var html = \'<option value="">-- Seleccionar --</option>\';
                        data.columns.forEach(function(col) {
                            html += \'<option value="\' + col + \'">\' + col + \'</option>\';
                        });
                        jQuery("#db_key_field").html(html);
                        jQuery("#db_label_field").html(html);
                    }
                }
            });
        });
        
        // Al cambiar cualquier campo BD, actualizar el valor oculto
        jQuery("#db_table, #db_key_field, #db_label_field, #db_where").on("change input", function() {
            updateDbFieldOptions();
        });
        
        function updateDbFieldOptions() {
            var table = jQuery("#db_table").val();
            var key = jQuery("#db_key_field").val();
            var label = jQuery("#db_label_field").val();
            var where = jQuery("#db_where").val();
            
            if (table && key && label) {
                var val = "db:" + table + ":" + key + ":" + label;
                if (where) val += ":" + where;
                jQuery("#field_options_real").val(val);
            }
        }
        
        // Previsualizar opciones de BD
        jQuery("#btn_preview_db").click(function() {
            var optStr = jQuery("#field_options_real").val();
            if (!optStr || optStr.indexOf("db:") !== 0) {
                alert("Configure la tabla, campo clave y campo etiqueta primero.");
                return;
            }
            
            jQuery.ajax({
                url: "'.dol_buildpath('/cabinetmed_extcons/admin/consultation_type_fields.php', 1).'",
                data: { ajax_preview_db_options: 1, db_options_str: optStr, type_id: '.((int)$type_id).' },
                dataType: "json",
                success: function(data) {
                    if (data.success) {
                        jQuery("#db_preview_count").text(data.count + " opciones encontradas");
                        var html = "<ul style=\"margin:0; padding-left:20px;\">";
                        var count = 0;
                        for (var k in data.options) {
                            if (count >= 20) {
                                html += "<li>... y " + (data.count - 20) + " más</li>";
                                break;
                            }
                            html += "<li><strong>" + k + "</strong>: " + data.options[k] + "</li>";
                            count++;
                        }
                        html += "</ul>";
                        jQuery("#db_preview_result").html(html).slideDown();
                    } else {
                        jQuery("#db_preview_count").text("Error al consultar la tabla");
                        jQuery("#db_preview_result").hide();
                    }
                }
            });
        });
    });
    </script>';
    
    print '</td></tr>';
    
    // Sección de campos condicionales
    print '<tr class="liste_titre"><td colspan="2"><strong>Visibilidad Condicional</strong></td></tr>';
    
    print '<tr><td class="tdtop">Depende del campo</td><td>';
    if (!empty($available_fields)) {
        $cond_fields_arr = array('' => '-- Siempre visible --');
        $cond_fields_arr = array_merge($cond_fields_arr, $available_fields);
        
        // Generar select manualmente con ID explícito para que jQuery funcione
        print '<select name="conditional_field" id="conditional_field" class="flat minwidth300">';
        foreach ($cond_fields_arr as $key => $label) {
            $selected = ($conditional_field == $key) ? ' selected' : '';
            print '<option value="'.dol_escape_htmltag($key).'"'.$selected.'>'.dol_escape_htmltag($label).'</option>';
        }
        print '</select>';
        
        print '<br><span class="opacitymedium">Si seleccionas un campo, este campo solo se mostrará cuando el campo seleccionado tenga el valor especificado</span>';
    } else {
        print '<span class="opacitymedium">No hay otros campos disponibles. Crea al menos un campo primero.</span>';
        print '<input type="hidden" name="conditional_field" value="">';
    }
    print '</td></tr>';
    
    print '<tr><td class="tdtop">Valor(es) que activan</td><td>';
    print '<div id="conditional_value_container">';
    
    // Si ya hay un campo condicional seleccionado, mostrar el control apropiado
    if (!empty($conditional_field) && isset($available_fields_data[$conditional_field])) {
        $parent_field_data = $available_fields_data[$conditional_field];
        print renderConditionalValueControl($parent_field_data['type'], $parent_field_data['options'], $conditional_value, $form);
    } else {
        // Por defecto, mostrar input de texto
        print '<input type="text" name="conditional_value" id="conditional_value_text" value="'.dol_escape_htmltag($conditional_value).'" class="flat quatrevingtpercent">';
        print '<br><span class="opacitymedium">Selecciona primero el campo del cual depende</span>';
    }
    
    print '</div>';
    print '</td></tr>';
    
    // JavaScript para actualizar el control de valor cuando cambia el campo padre
    if (!empty($available_fields_data)) {
        ?>
        <script type="text/javascript">
        (function() {
            var fieldsData = <?php echo json_encode($available_fields_data); ?>;
            
            console.log("[ConditionalFieldsAdmin] Inicializando...");
            console.log("[ConditionalFieldsAdmin] Campos disponibles:", fieldsData);
            
            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            function generateSelectControl(options, currentValue) {
                if (!options) {
                    return '<input type="text" name="conditional_value" value="" class="flat quatrevingtpercent"><br><span class="opacitymedium">El campo no tiene opciones configuradas</span>';
                }
                
                var html = '<select name="conditional_value" class="flat minwidth200">';
                html += '<option value="">-</option>';
                
                var opts = options.split(",");
                for (var i = 0; i < opts.length; i++) {
                    var opt = opts[i].trim();
                    var key = opt;
                    var label = opt;
                    
                    if (opt.indexOf(":") !== -1) {
                        var parts = opt.split(":", 2);
                        key = parts[0].trim();
                        label = parts[1].trim();
                    }
                    
                    var selected = (currentValue == key) ? ' selected' : '';
                    html += '<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(label) + '</option>';
                }
                
                html += '</select>';
                html += '<br><span class="opacitymedium">Selecciona el valor que activará este campo</span>';
                return html;
            }
            
            function generateMultiSelectControl(options, currentValue) {
                if (!options) {
                    return '<input type="text" name="conditional_value" value="" class="flat quatrevingtpercent"><br><span class="opacitymedium">El campo no tiene opciones configuradas</span>';
                }
                
                var currentValues = currentValue ? currentValue.split(",").map(function(v) { return v.trim(); }) : [];
                var html = '<div style="border: 1px solid #ccc; padding: 10px; max-height: 200px; overflow-y: auto;">';
                
                var opts = options.split(",");
                for (var i = 0; i < opts.length; i++) {
                    var opt = opts[i].trim();
                    var key = opt;
                    var label = opt;
                    
                    if (opt.indexOf(":") !== -1) {
                        var parts = opt.split(":", 2);
                        key = parts[0].trim();
                        label = parts[1].trim();
                    }
                    
                    var checked = (currentValues.indexOf(key) !== -1) ? ' checked' : '';
                    html += '<label style="display:block; margin-bottom:5px;">';
                    html += '<input type="checkbox" name="conditional_value_multi[]" value="' + escapeHtml(key) + '"' + checked + '> ';
                    html += escapeHtml(label);
                    html += '</label>';
                }
                
                html += '</div>';
                html += '<input type="hidden" name="conditional_value" id="conditional_value_hidden" value="' + escapeHtml(currentValue) + '">';
                html += '<br><span class="opacitymedium">Selecciona uno o más valores que activarán este campo</span>';
                
                // Agregar listener para actualizar el hidden cuando cambien los checkboxes
                setTimeout(function() {
                    jQuery("input[name='conditional_value_multi[]']").off('change').on('change', function() {
                        var selected = [];
                        jQuery("input[name='conditional_value_multi[]']:checked").each(function() {
                            selected.push(jQuery(this).val());
                        });
                        jQuery("#conditional_value_hidden").val(selected.join(","));
                    });
                }, 100);
                
                return html;
            }
            
            jQuery(document).ready(function() {
                // Verificar que el select existe
                var $select = jQuery("#conditional_field");
                if ($select.length === 0) {
                    console.error("[ConditionalFieldsAdmin] ERROR: No se encontró el select #conditional_field");
                    // Intentar con select por nombre
                    $select = jQuery('select[name="conditional_field"]');
                    if ($select.length === 0) {
                        console.error("[ConditionalFieldsAdmin] ERROR: Tampoco se encontró por nombre");
                        return;
                    } else {
                        console.log("[ConditionalFieldsAdmin] Select encontrado por nombre, agregando ID");
                        $select.attr('id', 'conditional_field');
                    }
                }
                
                console.log("[ConditionalFieldsAdmin] Select encontrado, configurando listener");
                
                $select.off('change').on('change', function() {
                    var selectedField = jQuery(this).val();
                    var container = jQuery("#conditional_value_container");
                    
                    console.log("[ConditionalFieldsAdmin] Campo seleccionado:", selectedField);
                    
                    if (!selectedField || !fieldsData[selectedField]) {
                        console.log("[ConditionalFieldsAdmin] Sin selección o sin datos");
                        container.html('<input type="text" name="conditional_value" id="conditional_value_text" value="" class="flat quatrevingtpercent"><br><span class="opacitymedium">Selecciona primero el campo del cual depende</span>');
                        return;
                    }
                    
                    var fieldData = fieldsData[selectedField];
                    console.log("[ConditionalFieldsAdmin] Datos del campo:", fieldData);
                    
                    var html = "";
                    
                    // Generar el control apropiado según el tipo de campo
                    switch(fieldData.type) {
                        case "select":
                        case "radio":
                            console.log("[ConditionalFieldsAdmin] Generando select/radio control");
                            html = generateSelectControl(fieldData.options, "");
                            break;
                        
                        case "multiselect":
                            console.log("[ConditionalFieldsAdmin] Generando multiselect control");
                            html = generateMultiSelectControl(fieldData.options, "");
                            break;
                        
                        case "boolean":
                            console.log("[ConditionalFieldsAdmin] Generando boolean control");
                            html = '<select name="conditional_value" class="flat minwidth200">';
                            html += '<option value="">-</option>';
                            html += '<option value="1">Sí</option>';
                            html += '<option value="0">No</option>';
                            html += '</select>';
                            html += '<br><span class="opacitymedium">Selecciona el valor que activará este campo</span>';
                            break;
                        
                        case "checkbox":
                            console.log("[ConditionalFieldsAdmin] Generando checkbox control");
                            html = '<select name="conditional_value" class="flat minwidth200">';
                            html += '<option value="">-</option>';
                            html += '<option value="1">Marcado</option>';
                            html += '<option value="0">No marcado</option>';
                            html += '</select>';
                            html += '<br><span class="opacitymedium">Selecciona el estado que activará este campo</span>';
                            break;
                        
                        default:
                            console.log("[ConditionalFieldsAdmin] Tipo desconocido:", fieldData.type);
                            html = '<input type="text" name="conditional_value" id="conditional_value_text" value="" class="flat quatrevingtpercent">';
                            html += '<br><span class="opacitymedium">Introduce el valor exacto que debe tener el campo "' + escapeHtml(fieldData.label) + '" para mostrar este campo</span>';
                    }
                    
                    console.log("[ConditionalFieldsAdmin] Actualizando container");
                    container.html(html);
                });
                
                // Trigger inicial si ya hay un campo seleccionado
                var initialValue = $select.val();
                if (initialValue) {
                    console.log("[ConditionalFieldsAdmin] Valor inicial detectado:", initialValue);
                    setTimeout(function() {
                        $select.trigger("change");
                    }, 100);
                }
            });
        })();
        </script>
        <?php
    }
    
    print '<tr><td>Obligatorio</td><td>';
    print '<input type="checkbox" name="required" value="1"'.($required ? ' checked' : '').'>';
    print '</td></tr>';
    
    print '<tr><td>Posición</td><td>';
    print '<input type="number" name="position" value="'.$position.'" class="flat maxwidth100">';
    print '</td></tr>';
    
    if ($action == 'edit') {
        print '<tr><td>Activo</td><td>';
        print '<input type="checkbox" name="active" value="1"'.($active ? ' checked' : '').'>';
        print '</td></tr>';
    }
    
    print '</table>';
    
    print '<div class="center" style="margin-top: 20px;">';
    print '<input type="submit" class="button button-save" value="Guardar">';
    print ' &nbsp; ';
    print '<input type="submit" class="button button-cancel" name="cancel" value="Cancelar">';
    print '</div>';
    
    print '</form>';
} else {
    print '<div class="tabsAction">';
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?type_id='.$type_id.'&action=create">Nuevo Campo</a>';
    print '</div>';
    
    $sql = "SELECT rowid, field_name, field_label, field_type, required, position, active, conditional_field, conditional_value";
    $sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
    $sql .= " WHERE fk_type = ".((int) $type_id);
    $sql .= " AND entity = ".$conf->entity;
    $sql .= " ORDER BY position ASC, field_label ASC";
    
    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<th>Nombre del Campo</th>';
        print '<th>Etiqueta</th>';
        print '<th>Tipo</th>';
        print '<th>Condición</th>';
        print '<th class="center">Obligatorio</th>';
        print '<th class="center">Posición</th>';
        print '<th class="center">Estado</th>';
        print '<th class="right">Acción</th>';
        print '</tr>';
        
        if ($num > 0) {
            $i = 0;
            while ($i < $num) {
                $obj = $db->fetch_object($resql);
                
                print '<tr class="oddeven">';
                print '<td><code>'.$obj->field_name.'</code></td>';
                print '<td>'.$obj->field_label.'</td>';
                print '<td>'.$obj->field_type.'</td>';
                print '<td>';
                if (!empty($obj->conditional_field)) {
                    print '<span class="opacitymedium" title="Depende del campo: '.$obj->conditional_field.'">';
                    print '<code>'.$obj->conditional_field.'</code> = <code>'.$obj->conditional_value.'</code>';
                    print '</span>';
                } else {
                    print '<span class="opacitymedium">-</span>';
                }
                print '</td>';
                print '<td class="center">'.($obj->required ? yn(1) : yn(0)).'</td>';
                print '<td class="center">'.$obj->position.'</td>';
                print '<td class="center">';
                print $obj->active ? '<span class="badge badge-status4 badge-status">Activo</span>' : '<span class="badge badge-status8 badge-status">Desactivado</span>';
                print '</td>';
                print '<td class="right nowraponall">';
                print '<a class="editfielda marginrightonly" href="'.$_SERVER["PHP_SELF"].'?type_id='.$type_id.'&action=edit&field_id='.$obj->rowid.'">';
                print img_edit();
                print '</a>';
                print '<a class="marginleftonly" href="'.$_SERVER["PHP_SELF"]
                .'?type_id='.$type_id.'&action=delete&field_id='.$obj->rowid.'&token='.newToken().'">';
                print img_delete();
                print '</a>';
                print '</td>';
                print '</tr>';
                
                $i++;
            }
        } else {
            print '<tr><td colspan="8" class="opacitymedium center">No hay campos definidos</td></tr>';
        }
        
        print '</table>';
    }
}

llxFooter();
$db->close();