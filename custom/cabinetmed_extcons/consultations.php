<?php
/* Copyright (C) 2024 Your Company
 * Extended Consultations Tab for CabinetMed
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');
dol_include_once('/cabinetmed_extcons/lib/cabinetmed_extcons.lib.php');

// Load translation files
$langs->loadLangs(array("companies", "other", "cabinetmed@cabinetmed", "cabinetmed_extcons@cabinetmed_extcons"));

// Security check
$socid = GETPOST('socid', 'int');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');
$id = GETPOST('id', 'int');

// Initialize objects
$object = new Societe($db);
$consultation = new ExtConsultation($db);
$form = new Form($db);

// Load patient/thirdparty
if ($socid > 0) {
    $result = $object->fetch($socid);
    if ($result < 0) {
        setEventMessages($object->error, $object->errors, 'errors');
    }
}

$permtoread   = !empty($user->rights->cabinetmed->read) 
            || !empty($user->rights->cabinetmed_extcons->read);

$permtocreate = !empty($user->rights->cabinetmed->write) 
            || !empty($user->rights->cabinetmed_extcons->write);

$permtodelete = !empty($user->rights->cabinetmed->delete)    
            || !empty($user->rights->cabinetmed->write)
            || !empty($user->rights->cabinetmed_extcons->delete);

if (!$permtoread) accessforbidden();

/*
 * Actions
 */

if ($cancel) {
    $action = '';
}

// Acción: Toggle favorito (AJAX o normal)
if ($action == 'togglefavorite') {
    $cons_id = GETPOST('id', 'int');
    if ($cons_id > 0) {
        $tmpcons = new ExtConsultation($db);
        $tmpcons->fetch($cons_id);
        $result = $tmpcons->toggleFavorite($user->id);
        
        if (GETPOST('ajax', 'int')) {
            // Respuesta AJAX
            header('Content-Type: application/json');
            echo json_encode(array(
                'success' => ($result > 0),
                'is_favorite' => $tmpcons->is_favorite
            ));
            exit;
        }
    }
    $action = '';
}

// Create/Update consultation
if ($action == 'add' || $action == 'update') {
    if (!$permtocreate) {
        accessforbidden();
    }
    
    $error = 0;
    
    // Get posted data
    $consultation->fk_soc = $socid;
    
    // Múltiples encargados: obtener array de usuarios
    $assigned_users = GETPOST('assigned_users', 'array');
    if (!is_array($assigned_users)) {
        $assigned_users = array();
    }
    // Si no se seleccionó ningún usuario, asignar al usuario actual por defecto
    if (empty($assigned_users)) {
        $assigned_users = array($user->id);
    }
    // fk_user legacy = primer usuario seleccionado
    $consultation->fk_user = !empty($assigned_users) ? (int) $assigned_users[0] : 0;
    
    $consultation->date_start = dol_mktime(GETPOST('date_starthour', 'int'), GETPOST('date_startmin', 'int'), 0, 
                                           GETPOST('date_startmonth', 'int'), GETPOST('date_startday', 'int'), 
                                           GETPOST('date_startyear', 'int'));
    $consultation->date_end = dol_mktime(GETPOST('date_endhour', 'int'), GETPOST('date_endmin', 'int'), 0,
                                         GETPOST('date_endmonth', 'int'), GETPOST('date_endday', 'int'), 
                                         GETPOST('date_endyear', 'int'));
    $consultation->tipo_atencion = GETPOST('tipo_atencion', 'alpha');
    $consultation->status = GETPOST('status', 'int');
    
    // Type-specific fields
    $consultation->cumplimiento = GETPOST('cumplimiento', 'alpha');
    $consultation->razon_inc = GETPOST('razon_inc', 'alpha');
    $consultation->mes_actual = GETPOST('mes_actual', 'alpha');
    $consultation->proximo_mes = GETPOST('proximo_mes', 'alpha');
    $consultation->dificultad = GETPOST('dificultad', 'int');
    $consultation->motivo = GETPOST('motivo', 'alpha');
    $consultation->diagnostico = GETPOST('diagnostico', 'restricthtml');
    $consultation->procedimiento = GETPOST('procedimiento', 'alpha');
    $consultation->insumos_enf = GETPOST('insumos_enf', 'alpha');
    $consultation->rx_num = GETPOST('rx_num', 'alpha');
    $consultation->medicamentos = GETPOST('medicamentos', 'restricthtml');
    $consultation->note_private = GETPOST('note_private', 'restricthtml');
    // note_public is read-only, not assigned from POST
    
    // Observaciones: prefer imgtext hidden input (JS ran), fallback to textarea (JS didn't)
    $obs_imgtext = GETPOST('observaciones_html', 'restricthtml');
    if ($obs_imgtext !== '' && $obs_imgtext !== null) {
        $consultation->observaciones = $obs_imgtext;
    } else {
        $obs_fallback = GETPOST('observaciones', 'restricthtml');
        $consultation->observaciones = ($obs_fallback !== '' && $obs_fallback !== null) ? $obs_fallback : '';
    }
    
    // Recurrence fields
    $consultation->recurrence_enabled = GETPOST('recurrence_enabled', 'int') ? 1 : 0;
    $consultation->recurrence_interval = GETPOST('recurrence_interval', 'int');
    $consultation->recurrence_unit = GETPOST('recurrence_unit', 'alpha');
    $consultation->recurrence_end_type = GETPOST('recurrence_end_type', 'alpha');
    $recurrence_end_date_day = GETPOST('recurrence_end_dateday', 'int');
    $recurrence_end_date_month = GETPOST('recurrence_end_datemonth', 'int');
    $recurrence_end_date_year = GETPOST('recurrence_end_dateyear', 'int');
    if ($recurrence_end_date_day > 0 && $recurrence_end_date_month > 0 && $recurrence_end_date_year > 0) {
        $consultation->recurrence_end_date = sprintf('%04d-%02d-%02d', $recurrence_end_date_year, $recurrence_end_date_month, $recurrence_end_date_day);
    } else {
        $consultation->recurrence_end_date = null;
    }
    
    // Validation
    if (empty($consultation->tipo_atencion)) {
        $error++;
        setEventMessages($langs->trans("ErrorFieldRequired", "Tipo de consulta"), null, 'errors');
    }
    
    if (!$error) {
        $db->begin();
        
        if ($action == 'add') {
            // Protección servidor contra doble-envío: rechazar si ya existe una consulta
            // idéntica (mismo paciente + tipo + fecha inicio) creada en los últimos 15 segundos.
            $dupWindow = dol_now() - 15;
            $sqlDup  = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmed_extcons";
            $sqlDup .= " WHERE fk_soc = ".(int) $consultation->fk_soc;
            $sqlDup .= " AND tipo_atencion = '".$db->escape($consultation->tipo_atencion)."'";
            $sqlDup .= " AND date_start = '".$db->idate($consultation->date_start)."'";
            $sqlDup .= " AND datec >= '".$db->idate($dupWindow)."'";
            $sqlDup .= " AND entity = ".$conf->entity." LIMIT 1";
            $resDup = $db->query($sqlDup);
            if ($resDup && $db->num_rows($resDup) > 0) {
                $db->free($resDup);
                $db->rollback();
                $error++;
                setEventMessages("La consulta ya fue guardada. Recarga la página si no la ves.", null, 'warnings');
            }
        }

        if (!$error && $action == 'add') {
            $result = $consultation->create($user);
        } elseif (!$error) {
            $consultation->id = $id;
            $result = $consultation->update($user);
        } else {
            $result = 0; // dup check falló — $error ya está seteado, se mostrará el form con warning
        }
        
        if ($error) {
            $db->rollback();
        } elseif ($result < 0) {
            $error++;
            setEventMessages($consultation->error, $consultation->errors, 'errors');
            $db->rollback();
        } else {
            // Guardar múltiples usuarios asignados
            if (!empty($assigned_users)) {
                $consultation->setAssignedUsers($assigned_users, $user);
            }
            
            // Generar consultas recurrentes si está habilitado
            if ($action == 'add' && $consultation->recurrence_enabled) {
                $rec_result = $consultation->generateRecurrences($user);
                if ($rec_result > 0) {
                    setEventMessages("Se generaron ".$rec_result." consultas recurrentes", null, 'mesgs');
                } elseif ($rec_result < 0) {
                    setEventMessages("Error generando recurrencias: ".$consultation->error, null, 'warnings');
                }
            }
            
            $db->commit();
            setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]."?socid=".$socid);
            exit;
        }
        if ($action == 'save' && $permtocreate) {
            if (!GETPOST('token') || !checkCSRFToken(GETPOST('token'))) {
            setEventMessages($langs->trans('TokenNotProvided'), null, 'errors');
        } else {
            $error = 0;
    }
}
    }
}

        // Delete consultation from list
    if ($action == 'confirm_delete' && $permtodelete) {

        // ID de la consulta a borrar
        $id = GETPOST('id', 'int');

        if ($id > 0) {
            // Cargar la consulta
            $consultation->fetch($id);

            if ($consultation->id > 0) {
                $result = $consultation->delete($user);

                if ($result < 0) {
                    setEventMessages($consultation->error, $consultation->errors, 'errors');
                } else {
                    setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
                }
            } else {
                setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
            }
        } else {
            setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
        }

        // Volvemos a la pestaña de consultas del mismo paciente
        header("Location: ".$_SERVER["PHP_SELF"]."?socid=".$socid);
        exit;
    }



/*
 * View
 */

// Auto-extender recurrencias si es necesario (lazy generation)
ExtConsultation::extendAllRecurrences($db, $user, $conf->entity);

$title = $langs->trans("Consultations").' - '.$object->name;
$help_url = "";

llxHeader('', $title, $help_url);

if ($socid > 0 && $object->id > 0) {
    // Show tabs
    $head = societe_prepare_head($object);
    print dol_get_fiche_head($head, 'tabconsultations', $langs->trans("ThirdParty"), -1, 'company');
    
    // Object card
    $linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
    
    dol_banner_tab($object, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');
    
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    
    print '<table class="border centpercent tableforfield">';
    
    // Patient code
    print '<tr><td class="titlefield">'.$langs->trans("PatientCode").'</td><td>';
    print $object->code_client;
    print '</td></tr>';
    
    print '</table>';
    print '</div>';
    
    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">';
    
    // Gender
    $val = $object->array_options['options_genre'] ?? '';  
if ($val !== '') {
    print '<tr><td>'.$langs->trans("Gender").'</td><td>';
    print dol_escape_htmltag($val);
    print '</td></tr>';
}
    
    // Date of birth
    if ($object->array_options['options_birthdate']) {
        print '<tr><td>'.$langs->trans("DateOfBirth").'</td><td>';
        print dol_print_date($object->array_options['options_birthdate'], 'day');
        print '</td></tr>';
    }
    
    print '</table>';
    print '</div>';
    print '</div>';
    
    print '<div class="clearboth"></div>';
    print dol_get_fiche_end();
    
    // Buttons for new consultation
    print '<div class="tabsAction">';
    if ($permtocreate) {
        print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'&action=create">'.$langs->trans("NewConsultation").'</a>';
    }
    print '</div>';
    
    // Form for new/edit consultation
    if ($action == 'create' || $action == 'edit') {

        // En modo edición: cargar la consulta desde BD para que todos los campos
        // tengan persistencia. Sin este fetch el objeto queda vacío y los campos se resetean.
        if ($action == 'edit' && $id > 0) {
            $result = $consultation->fetch($id);
            if ($result < 0) {
                setEventMessages($consultation->error, $consultation->errors, 'errors');
            } else {
                // Cargar también los usuarios asignados
                $consultation->fetchAssignedUsers();
            }
        }

        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
        print '<input type="hidden" name="socid" value="'.$socid.'">';
        if ($action == 'edit') {
            print '<input type="hidden" name="id" value="'.$id.'">';
        }
        
        print '<div class="div-table-responsive-no-min">';
        print '<table class="border centpercent">';
        
        // Para cada campo: si hay POST usarlo (re-submit con error), sino usar valor de BD
        $f_tipo      = GETPOSTISSET('tipo_atencion')    ? GETPOST('tipo_atencion', 'alpha')          : $consultation->tipo_atencion;
        $f_datestart = GETPOSTISSET('date_startyear')   ? dol_mktime(GETPOST('date_starthour','int'), GETPOST('date_startmin','int'), 0, GETPOST('date_startmonth','int'), GETPOST('date_startday','int'), GETPOST('date_startyear','int')) : $consultation->date_start;
        $f_dateend   = GETPOSTISSET('date_endyear')     ? dol_mktime(GETPOST('date_endhour','int'),   GETPOST('date_endmin','int'),   0, GETPOST('date_endmonth','int'),   GETPOST('date_endday','int'),   GETPOST('date_endyear','int'))   : $consultation->date_end;

        print '<tr><td class="titlefieldcreate fieldrequired">'."Tipo de consulta".'</td><td>';
        print $form->selectarray('tipo_atencion', ExtConsultation::getTypesArray($langs), $f_tipo, 1, 0, 0, 'id="tipo_atencion"');
        print '</td></tr>';
        
        print '<tr><td>'.$langs->trans("AssignedTo").'</td><td>';
        print img_picto('', 'user', 'class="pictofixedwidth"');
        // Prioridad: POST (re-submit) → usuarios asignados guardados en BD → usuario actual por defecto
        if (GETPOSTISSET('assigned_users')) {
            $selected_users = GETPOST('assigned_users', 'array');
            if (!is_array($selected_users)) $selected_users = array();
        } elseif ($action == 'edit') {
            $selected_users = $consultation->getAssignedUserIds();
        } else {
            // Crear nueva consulta: asignar usuario actual por defecto
            $selected_users = array($user->id);
        }
        print $form->select_dolusers($selected_users, 'assigned_users', 1, '', 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth500', 0, 0, 1);
        print '<br><small class="opacitymedium">Puede seleccionar múltiples encargados manteniendo Ctrl presionado</small>';
        print '</td></tr>';
        
        print '<tr><td>'.$langs->trans("DateStart").'</td><td>';
        print $form->selectDate($f_datestart ? $f_datestart : -1, 'date_start', 1, 1, 0, '', 1, 1);
        print '</td></tr>';
        
        print '<tr><td>'.$langs->trans("DateEnd").'</td><td>';
        print $form->selectDate($f_dateend ? $f_dateend : -1, 'date_end', 1, 1, 0, '', 1, 1);
        print '</td></tr>';

        // Status
        print '<tr><td>'.$langs->trans("Status").'</td><td>';
        $statusarray = ExtConsultation::getStatusArray();
        $currentstatus = GETPOSTISSET('status') ? GETPOST('status', 'int') : $consultation->status;
        print $form->selectarray('status', $statusarray, $currentstatus);
        print '</td></tr>';

        // ===== RECURRENCE SECTION =====
        print '<tr><td>Repetir consulta</td><td>';
        $rec_enabled = GETPOSTISSET('recurrence_enabled') ? GETPOST('recurrence_enabled', 'int') : $consultation->recurrence_enabled;
        print '<input type="checkbox" name="recurrence_enabled" id="recurrence_enabled" value="1"'.($rec_enabled ? ' checked' : '').'>';
        print ' <label for="recurrence_enabled">Habilitar repetición</label>';
        print '</td></tr>';
        
        // Recurrence details (hidden by default)
        print '<tr class="recurrence-fields" style="'.($rec_enabled ? '' : 'display:none;').'">';
        print '<td>Repetir cada</td><td>';
        $rec_interval = GETPOSTISSET('recurrence_interval') ? GETPOST('recurrence_interval', 'int') : $consultation->recurrence_interval;
        if ($rec_interval <= 0) $rec_interval = 1;
        print '<input type="number" name="recurrence_interval" value="'.$rec_interval.'" min="1" max="365" class="flat maxwidth75" style="width:60px;">';
        print ' ';
        $rec_unit = GETPOSTISSET('recurrence_unit') ? GETPOST('recurrence_unit', 'alpha') : $consultation->recurrence_unit;
        if (empty($rec_unit)) $rec_unit = 'weeks';
        $units = array('days' => 'Día(s)', 'weeks' => 'Semana(s)', 'months' => 'Mes(es)', 'years' => 'Año(s)');
        print $form->selectarray('recurrence_unit', $units, $rec_unit, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
        print '</td></tr>';
        
        print '<tr class="recurrence-fields" style="'.($rec_enabled ? '' : 'display:none;').'">';
        print '<td>Finalización</td><td>';
        $rec_end_type = GETPOSTISSET('recurrence_end_type') ? GETPOST('recurrence_end_type', 'alpha') : $consultation->recurrence_end_type;
        if (empty($rec_end_type)) $rec_end_type = 'forever';
        print '<label style="margin-right:15px;"><input type="radio" name="recurrence_end_type" value="forever" class="rec-end-type"'.($rec_end_type == 'forever' ? ' checked' : '').'> Sin fecha fin (para siempre)</label><br>';
        print '<label><input type="radio" name="recurrence_end_type" value="date" class="rec-end-type"'.($rec_end_type == 'date' ? ' checked' : '').'> Hasta fecha específica: </label> ';
        $rec_end_date_val = '';
        if (GETPOSTISSET('recurrence_end_dateyear')) {
            $rec_end_date_val = '';
        } elseif (!empty($consultation->recurrence_end_date)) {
            $rec_end_date_val = strtotime($consultation->recurrence_end_date);
        }
        print $form->selectDate($rec_end_date_val, 'recurrence_end_date', 0, 0, 1, '', 1, 0);
        print '</td></tr>';
        // ===== END RECURRENCE SECTION =====

        
        print '</table>';
        
        // Dynamic sections based on consultation type (AUTOMATIC RENDERING)
        print '<div id="dynamic-sections">';
        
        // 1. Obtener todos los tipos activos
        $sql_types = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types WHERE entity = ".$conf->entity." AND active = 1";
        $res_types = $db->query($sql_types);
        
        if ($res_types) {
            while ($type_obj = $db->fetch_object($res_types)) {
                // Preparamos los datos guardados (si estamos editando y existen)
                $saved_data = array();
                if (!empty($consultation->custom_fields_array)) {
                    $saved_data = $consultation->custom_fields_array;
                }

                // Inicio del bloque oculto para este tipo
                print '<div class="consultation-section" data-types="'.$type_obj->code.'" style="display:none;">';
                print '<table class="border centpercent">';
                print '<tr class="liste_titre"><td colspan="2">'.$langs->trans($type_obj->label).'</td></tr>';
                
                // 2. Obtener los campos configurados para este tipo
                $sql_fields = "SELECT field_name, field_label, field_type, field_options, required, conditional_field, conditional_value 
                               FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields 
                               WHERE fk_type = ".$type_obj->rowid." AND active = 1 
                               ORDER BY position ASC";
                $res_fields = $db->query($sql_fields);
                
                if ($res_fields && $db->num_rows($res_fields) > 0) {
                    while ($field = $db->fetch_object($res_fields)) {
                        
                        // Recuperar valor (prioridad: POST > columna BD > JSON custom_data)
                        $value = '';
                        
                        // Definir el nombre del input y resolver el valor
                        if (property_exists('ExtConsultation', $field->field_name)) {
                            // Es campo nativo con columna real (ej: motivo, diagnostico)
                            $input_name = $field->field_name;
                            if (GETPOSTISSET($field->field_name)) {
                                // Re-submit con error de validación: usar POST
                                $value = GETPOST($field->field_name);
                            } else {
                                // Primera apertura del form edición: leer de BD
                                // Usar property_exists ya garantiza que la propiedad existe;
                                // preferir columna si no está vacía, si no intentar JSON (campo que
                                // en algún momento fue guardado como custom por el bug anterior)
                                $col_val  = $consultation->{$field->field_name};
                                $json_val = isset($saved_data[$field->field_name]) ? $saved_data[$field->field_name] : '';
                                $value = ($col_val !== null && $col_val !== '') ? $col_val : $json_val;
                            }
                        } else {
                            // Es campo dinámico -> va al array custom_fields (JSON)
                            $input_name = 'custom_fields['.$field->field_name.']';
                            $custom_post = GETPOST('custom_fields', 'array');
                            if (!empty($custom_post) && isset($custom_post[$field->field_name])) {
                                $value = $custom_post[$field->field_name];
                            } elseif (isset($saved_data[$field->field_name])) {
                                $value = $saved_data[$field->field_name];
                            }
                        }

                        // Renderizar etiqueta
                        $req_class = $field->required ? 'class="fieldrequired"' : '';
                        // Atributos de condición para JS
                        $cond_attrs = ' data-field-name="'.dol_escape_htmltag($field->field_name).'"';
                        if (!empty($field->conditional_field)) {
                            $cond_attrs .= ' data-conditional-field="'.dol_escape_htmltag($field->conditional_field).'"';
                            $cond_attrs .= ' data-conditional-value="'.dol_escape_htmltag($field->conditional_value).'"';
                            $cond_attrs .= ' style="display:none;"'; // Oculto por defecto si es condicional
                        }
                        print '<tr'.$cond_attrs.'><td '.$req_class.'>'.$langs->trans($field->field_label).'</td><td>';
                        
                        // Renderizar Input según tipo
                        switch($field->field_type) {
                            case 'textarea':
                                if (strpos($input_name, 'custom_fields[') !== false) {
                                    // Custom/dynamic field: textarea with optional image-paste enhancement
                                    $imgtext_name = 'cf_imgtext_'.$field->field_name;
                                    $textarea_id = 'imgtext_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $field->field_name);
                                    // Fallback textarea (always submits, even without JS)
                                    print '<textarea name="'.$input_name.'" id="'.$textarea_id.'_fallback" rows="3" class="flat quatrevingtpercent" style="display:none;">'.dol_escape_htmltag($value).'</textarea>';
                                    // Hidden input starts EMPTY; JS syncs div.innerHTML here on submit
                                    print '<input type="hidden" name="'.$imgtext_name.'" id="'.$textarea_id.'_hidden" value="">';
                                    print '<div id="'.$textarea_id.'" class="imgtext-editor flat quatrevingtpercent" contenteditable="true" ';
                                    print 'style="min-height:80px; max-height:300px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff; white-space:pre-wrap;">';
                                    if (preg_match('/<(img|br|div|p|span)\b/i', $value)) {
                                        print ExtConsultation::sanitizeImgTextHtml($value);
                                    } else {
                                        print dol_nl2br(dol_escape_htmltag($value));
                                    }
                                    print '</div>';
                                    print '<small class="opacitymedium"><i class="fas fa-image" style="color:#666;"></i> Pega capturas de pantalla (Ctrl+V) o arrastra imágenes. Doble clic en una imagen para eliminarla</small>';
                                } else {
                                    // Legacy field: plain textarea
                                    print '<textarea name="'.$input_name.'" rows="3" class="flat quatrevingtpercent">'.dol_escape_htmltag($value).'</textarea>';
                                }
                                break;
                            
                            case 'html':
                                require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
                                $doleditor = new DolEditor($input_name, $value, '', 150, 'dolibarr_notes', '', false, true, getDolGlobalInt('FCKEDITOR_ENABLE_SOCIETE'), ROWS_3, '90%');
                                $doleditor->Create();
                                break;
                                
                            case 'number':
                                print '<input type="number" step="any" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth100">';
                                break;
                            
                            case 'integer':
                                print '<input type="number" step="1" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth100">';
                                break;
                            
                            case 'price':
                                print '<input type="number" step="0.01" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth100"> '.$langs->getCurrencySymbol($conf->currency);
                                break;
                            
                            case 'percentage':
                                print '<input type="number" step="0.1" min="0" max="100" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth75"> %';
                                break;
                                
                            case 'date':
                                // For dynamic fields, use special prefix so PHP can process them
                                $date_input_name = $input_name;
                                if (strpos($input_name, 'custom_fields[') !== false) {
                                    $date_input_name = 'cf_date_'.$field->field_name;
                                }
                                print $form->selectDate($value ? $value : '', $date_input_name, 0, 0, 1);
                                break;
                            
                            case 'datetime':
                                // For dynamic fields, use special prefix so PHP can process them
                                $datetime_input_name = $input_name;
                                if (strpos($input_name, 'custom_fields[') !== false) {
                                    $datetime_input_name = 'cf_datetime_'.$field->field_name;
                                }
                                print $form->selectDate($value ? $value : '', $datetime_input_name, 1, 1, 1);
                                break;
                            
                            case 'time':
                                $hours = '';
                                $mins = '';
                                if ($value && strpos($value, ':') !== false) {
                                    list($hours, $mins) = explode(':', $value);
                                }
                                print '<select name="'.$input_name.'_hour" class="flat minwidth50">';
                                print '<option value="">--</option>';
                                for ($h = 0; $h <= 23; $h++) {
                                    $hval = str_pad($h, 2, '0', STR_PAD_LEFT);
                                    $sel = ($hours == $hval) ? ' selected' : '';
                                    print '<option value="'.$hval.'"'.$sel.'>'.$hval.'</option>';
                                }
                                print '</select>';
                                print ' : ';
                                print '<select name="'.$input_name.'_min" class="flat minwidth50">';
                                print '<option value="">--</option>';
                                for ($m = 0; $m <= 59; $m += 5) {
                                    $mval = str_pad($m, 2, '0', STR_PAD_LEFT);
                                    $sel = ($mins == $mval) ? ' selected' : '';
                                    print '<option value="'.$mval.'"'.$sel.'>'.$mval.'</option>';
                                }
                                print '</select>';
                                break;
                                
                            case 'checkbox':
                            case 'boolean':
                                $checked = $value ? ' checked' : '';
                                print '<input type="checkbox" name="'.$input_name.'" value="1"'.$checked.'>';
                                if ($field->field_type == 'boolean') {
                                    print ' <span class="opacitymedium">(Sí/No)</span>';
                                }
                                break;
                                
                            case 'select':
                                $opts_array = ExtConsultation::resolveFieldOptions($field->field_options, $db);
                                print $form->selectarray($input_name, $opts_array, $value, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
                                break;
                            
                            case 'multiselect':
                                $opts_array = ExtConsultation::resolveFieldOptions($field->field_options, $db);
                                $selected_values = is_array($value) ? $value : (is_string($value) && $value !== '' ? explode(',', $value) : array());
                                $multi_input_name = 'cf_multi_'.$field->field_name;
                                $select_html_id = 'multiselect_'.dol_escape_htmltag($field->field_name);
                                print '<select name="'.$multi_input_name.'[]" id="'.$select_html_id.'" multiple="multiple" class="flat minwidth200">';
                                foreach ($opts_array as $opt_k => $opt_l) {
                                    $sel = in_array((string)$opt_k, $selected_values) ? ' selected' : '';
                                    print '<option value="'.dol_escape_htmltag($opt_k).'"'.$sel.'>'.dol_escape_htmltag($opt_l).'</option>';
                                }
                                print '</select>';
                                print '<script type="text/javascript">$(document).ready(function(){ $("#'.$select_html_id.'").select2({ width: "resolve", closeOnSelect: false, placeholder: "Seleccionar..." }); });</script>';
                                break;
                            
                            case 'radio':
                                $opts_array = ExtConsultation::resolveFieldOptions($field->field_options, $db);
                                foreach($opts_array as $opt_value_r => $opt_label_r) {
                                    $checked = ($value == $opt_value_r) ? ' checked' : '';
                                    print '<label style="margin-right:15px;"><input type="radio" name="'.$input_name.'" value="'.dol_escape_htmltag($opt_value_r).'"'.$checked.'> '.$opt_label_r.'</label>';
                                }
                                break;
                            
                            case 'email':
                                print '<input type="email" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat quatrevingtpercent" placeholder="correo@ejemplo.com">';
                                break;
                            
                            case 'phone':
                                print '<input type="tel" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth200" placeholder="+57 300 000 0000">';
                                break;
                            
                            case 'url':
                                print '<input type="url" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat quatrevingtpercent" placeholder="https://">';
                                break;
                            
                            case 'password':
                                print '<input type="password" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat maxwidth200">';
                                break;
                            
                            case 'color':
                                print '<input type="color" name="'.$input_name.'" value="'.($value ? dol_escape_htmltag($value) : '#000000').'" class="flat">';
                                break;
                            
                            case 'range':
                                $min = 0;
                                $max = 100;
                                $step = 1;
                                if ($field->field_options) {
                                    $range_opts = explode(',', $field->field_options);
                                    if (isset($range_opts[0])) $min = trim($range_opts[0]);
                                    if (isset($range_opts[1])) $max = trim($range_opts[1]);
                                    if (isset($range_opts[2])) $step = trim($range_opts[2]);
                                }
                                $range_id = 'range_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $input_name);
                                print '<input type="range" name="'.$input_name.'" id="'.$range_id.'" value="'.dol_escape_htmltag($value ? $value : $min).'" min="'.$min.'" max="'.$max.'" step="'.$step.'" class="flat" style="width:200px;">';
                                print ' <span id="'.$range_id.'_val">'.($value ? $value : $min).'</span>';
                                print '<script>document.getElementById("'.$range_id.'").oninput=function(){document.getElementById("'.$range_id.'_val").textContent=this.value;}</script>';
                                break;
                            
                            case 'stars':
                                $max_stars = 5;
                                if ($field->field_options && is_numeric($field->field_options)) {
                                    $max_stars = (int) $field->field_options;
                                }
                                print '<select name="'.$input_name.'" class="flat">';
                                print '<option value="">-</option>';
                                for ($s = 1; $s <= $max_stars; $s++) {
                                    $sel = ($value == $s) ? ' selected' : '';
                                    print '<option value="'.$s.'"'.$sel.'>'.str_repeat('★', $s).str_repeat('☆', $max_stars - $s).'</option>';
                                }
                                print '</select>';
                                break;
                            
                            case 'separator':
                                print '<hr style="margin:5px 0;"><strong>'.dol_escape_htmltag($field->field_options).'</strong>';
                                break;
                                
                            default: // text
                                print '<input type="text" name="'.$input_name.'" value="'.dol_escape_htmltag($value).'" class="flat quatrevingtpercent">';
                        }
                        print '</td></tr>';
                    }
                } else {
                    print '<tr><td colspan="2"><span class="opacitymedium">'.$langs->trans("NoFieldsDefined").'</span></td></tr>';
                }
                
                print '</table>';
                print '</div>';
            }
        }
        
        // Observaciones section (universal, all consultation types - editable with image support)
        $obs_value = GETPOSTISSET('observaciones') ? GETPOST('observaciones', 'restricthtml') : $consultation->observaciones;
        $obs_textarea_id = 'imgtext_observaciones';
        print '<br>';
        print '<table class="border centpercent">';
        print '<tr class="liste_titre"><td colspan="2">Observaciones</td></tr>';
        print '<tr><td class="tdtop">Observaciones</td><td>';
        print '<textarea name="observaciones" id="'.$obs_textarea_id.'_fallback" rows="3" class="flat quatrevingtpercent" style="display:none;">'.dol_escape_htmltag($obs_value).'</textarea>';
        print '<input type="hidden" name="observaciones_html" id="'.$obs_textarea_id.'_hidden" value="">';
        print '<div id="'.$obs_textarea_id.'" class="imgtext-editor flat quatrevingtpercent" contenteditable="true" ';
        print 'style="min-height:80px; max-height:300px; overflow-y:auto; border:1px solid #ccc; padding:8px; background:#fff; white-space:pre-wrap;">';
        if (preg_match('/<(img|br|div|p|span)\b/i', $obs_value)) {
            print ExtConsultation::sanitizeImgTextHtml($obs_value);
        } else {
            print dol_nl2br(dol_escape_htmltag($obs_value));
        }
        print '</div>';
        print '<small class="opacitymedium"><i class="fas fa-image" style="color:#666;"></i> Pega capturas de pantalla (Ctrl+V) o arrastra imágenes. Doble clic en una imagen para eliminarla</small>';
        print '</td></tr>';
        print '</table>';
        
        // Notes section (Solo lectura)
        $f_note_public = $consultation->note_public;
        if ($f_note_public) {
            print '<br>';
            print '<table class="border centpercent">';
            print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Notes").'</td></tr>';
            print '<tr><td class="tdtop">'.$langs->trans("NotePublic").'</td><td>';
            print dol_htmlentitiesbr($f_note_public);
            print '</td></tr>';
            print '</table>';
        }
        
        print '</div>'; // End dynamic sections
        
        print '</div>';
        
        // Buttons
        print '<div class="center">';
        print '<input type="submit" id="extcons-btn-save" class="button button-save" value="'.$langs->trans("Save").'">';
        print ' &nbsp; ';
        print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';
        print '</div>';
        
        print '</form>';
        print '<script>';
        print 'document.getElementById("extcons-btn-save").closest("form").addEventListener("submit", function(e) {';
        print '  // Re-deshabilitar inputs de secciones ocultas (seguridad ante fallos de JS previos)';
        print '  jQuery(".consultation-section:hidden").find("input, textarea, select").prop("disabled", true);';
        print '  var btn = document.getElementById("extcons-btn-save");';
        print '  if (btn.dataset.submitting) { e.preventDefault(); return; }';
        print '  btn.dataset.submitting = "1";';
        print '  btn.disabled = true;';
        print '  btn.value = "' . $langs->trans("Saving") . '...";';
        print '});';
        print '</script>';
        
        // Recopilar configuración de campos condicionales para JavaScript
        $conditional_fields_config = array();
        $sql_all_cond_fields = "SELECT f.field_name, f.conditional_field, f.conditional_value, t.code as type_code 
                                FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields f
                                INNER JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_types t ON f.fk_type = t.rowid
                                WHERE f.active = 1 AND f.conditional_field IS NOT NULL AND f.conditional_field != ''
                                AND t.entity = ".$conf->entity." AND t.active = 1";
        $res_cond = $db->query($sql_all_cond_fields);
        if ($res_cond) {
            while ($cond_field = $db->fetch_object($res_cond)) {
                $conditional_fields_config[] = array(
                    'field_name' => $cond_field->field_name,
                    'conditional_field' => $cond_field->conditional_field,
                    'conditional_value' => $cond_field->conditional_value,
                    'type_code' => $cond_field->type_code
                );
            }
        }
        
        // JavaScript for dynamic sections
        print '
        <script type="text/javascript" src="'.dol_buildpath('/cabinetmed_extcons/js/imgtext_editor.js', 1).'"></script>
        <script type="text/javascript" src="'.dol_buildpath('/cabinetmed_extcons/js/conditional_fields.js', 1).'"></script>
        <script type="text/javascript">
        jQuery(document).ready(function() {
            // Inicializar manejador de campos condicionales PRIMERO
            var conditionalFieldsConfig = '.json_encode($conditional_fields_config).';
            if (typeof ConditionalFieldsHandler !== "undefined" && conditionalFieldsConfig.length > 0) {
                ConditionalFieldsHandler.init(conditionalFieldsConfig);
            }
            
            function updateSections() {
                var selectedType = jQuery("#tipo_atencion").val();
                
                jQuery(".consultation-section").each(function() {
                    var rawTypes = jQuery(this).attr("data-types");
                    if (!rawTypes) return;

                    var types = rawTypes.split(",");
                    types = types.map(function(s) { return s.trim(); });

                    if (types.indexOf(selectedType) !== -1) {
                        jQuery(this).slideDown();
                        // Habilitar campos, EXCEPTO los que están condicionalmente ocultos
                        jQuery(this).find("input, textarea, select").each(function() {
                            var $row = jQuery(this).closest("tr");
                            if ($row.attr("data-conditional-hidden") !== "true") {
                                jQuery(this).prop("disabled", false);
                            }
                        });
                    } else {
                        jQuery(this).slideUp();
                        jQuery(this).find("input, textarea, select").prop("disabled", true);
                    }
                });
                
                // Re-evaluar campos condicionales después de cambiar tipo
                if (typeof ConditionalFieldsHandler !== "undefined" && conditionalFieldsConfig.length > 0) {
                    setTimeout(function() {
                        ConditionalFieldsHandler.updateAllFields();
                    }, 350);
                }
            }
            
            jQuery("#tipo_atencion").change(function() {
                updateSections();
            });
            
            updateSections();
            
            // ===== RECURRENCE TOGGLE =====
            jQuery("#recurrence_enabled").change(function() {
                if (jQuery(this).is(":checked")) {
                    jQuery(".recurrence-fields").slideDown();
                } else {
                    jQuery(".recurrence-fields").slideUp();
                }
            });
            
            jQuery(".rec-end-type").change(function() {
                var isDate = jQuery("input[name=recurrence_end_type]:checked").val() === "date";
                jQuery("select[name^=recurrence_end_date], input[name^=recurrence_end_date]").prop("disabled", !isDate);
            });
            // Trigger initial state
            jQuery(".rec-end-type:checked").trigger("change");
            // ===== END RECURRENCE JS =====
        });
        </script>';
            }
    
    // List of existing consultations
    $sql = "SELECT c.rowid, c.date_start, c.date_end, c.tipo_atencion, c.fk_user, c.status, c.recurrence_enabled, c.recurrence_parent_id";
    $sql .= ", (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites f WHERE f.fk_extcons = c.rowid AND f.fk_user = ".((int) $user->id).") as is_favorite";
    $sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons as c";
    $sql .= " WHERE c.fk_soc = ".((int) $socid);
    $sql .= " ORDER BY is_favorite DESC, c.date_start DESC";
    
    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        
        print '<br>';
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td></td>'; // Columna para el icono de favorito
        print '<td>'.$langs->trans("Ref").'</td>';
        print '<td>'.$langs->trans("Date").'</td>';
        print '<td>'.$langs->trans("Type").'</td>';
        print '<td>Encargados</td>';
        print '<td>'.$langs->trans("Status").'</td>';
        print '<td class="center">Recurrencia</td>';
        print '<td class="right">'.$langs->trans("Action").'</td>';

        print '</tr>';
        
        if ($num > 0) {
            $i = 0;
            while ($i < $num) {
                $obj = $db->fetch_object($resql);
                
                // Cargar consulta para obtener múltiples encargados
                $tmpcons = new ExtConsultation($db);
                $tmpcons->id = $obj->rowid;
                $tmpcons->tipo_atencion = $obj->tipo_atencion;
                $tmpcons->status = (int) $obj->status;
                $tmpcons->is_favorite = ($obj->is_favorite > 0);
                $tmpcons->recurrence_enabled = (int) $obj->recurrence_enabled;
                $tmpcons->recurrence_parent_id = $obj->recurrence_parent_id ? (int) $obj->recurrence_parent_id : null;
                $tmpcons->fetchAssignedUsers();
                
                print '<tr class="oddeven">';
                
                // Columna de favorito
                print '<td class="center" style="width: 30px;">';
                $star_class = $tmpcons->is_favorite ? 'fas fa-star' : 'far fa-star';
                $star_color = $tmpcons->is_favorite ? 'color: #f39c12;' : 'color: #ccc;';
                print '<a href="javascript:void(0);" class="favorite-toggle" data-id="'.$obj->rowid.'" title="'.($tmpcons->is_favorite ? 'Desfijar' : 'Fijar consulta').'">';
                print '<i class="'.$star_class.'" style="'.$star_color.' cursor: pointer; font-size: 16px;"></i>';
                print '</a>';
                print '</td>';
                
                print '<td>CONS-'.sprintf("%06d", $obj->rowid).'</td>';
                print '<td>'.dol_print_date($db->jdate($obj->date_start), 'dayhour').'</td>';
                print '<td>';
                print $tmpcons->getTypeLabel($langs);
                print '</td>';
                // Mostrar múltiples encargados
                print '<td>'.$tmpcons->getAssignedUsersHTML(1).'</td>';
                // Status (colored badge)
                print '<td>'.$tmpcons->getLibStatus(1).'</td>';
                // Recurrence icon
                print '<td class="center">';
                if ($tmpcons->recurrence_enabled) {
                    $child_count = $tmpcons->countChildRecurrences();
                    print '<span title="Consulta recurrente ('.$child_count.' ocurrencias)" style="color:#2196F3;"><i class="fas fa-sync-alt"></i> '.$child_count.'</span>';
                } elseif ($tmpcons->recurrence_parent_id > 0) {
                    print '<span title="Ocurrencia de CONS-'.sprintf('%06d', $tmpcons->recurrence_parent_id).'" style="color:#9e9e9e;"><i class="fas fa-link"></i></span>';
                } else {
                    print '<span class="opacitymedium">-</span>';
                }
                print '</td>';
                print '<td class="right nowraponall">';

                // Botón Detalle
                if ($permtoread) {
                    print '<a class="marginrightonly" href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1)
                        .'?id='.$obj->rowid
                        .'&socid='.$socid.'">';
                    // Si en tu versión no existe el picto "detail", puedes usar img_view()
                    print img_picto($langs->trans("Detail"), 'detail');
                    // print img_view();  // alternativa
                    print '</a>';
                }

                // Botón Editar
                if ($permtocreate) {
                    print '<a class="editfielda marginrightonly" href="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'&action=edit&id='.$obj->rowid.'">';
                    print img_edit();
                    print '</a>';
                }

                // Botón Eliminar
                if ($permtodelete) {
                    print '<a class="marginleftonly" href="'.$_SERVER["PHP_SELF"]
                        .'?socid='.$socid
                        .'&action=confirm_delete'
                        .'&id='.$obj->rowid
                        .'&token='.newToken().'">';
                    print img_delete();
                    print '</a>';
                }


                print '</td>';
                print '</tr>';
                
                $i++;
            }
        } else {
            print '<tr><td colspan="8" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
        }
        
        print '</table>';
        print '</div>';
        
        // JavaScript para manejar favoritos
        print '
        <script type="text/javascript">
        jQuery(document).ready(function() {
            jQuery(".favorite-toggle").click(function() {
                var consId = jQuery(this).data("id");
                var starIcon = jQuery(this).find("i");
                
                jQuery.ajax({
                    url: "'.$_SERVER["PHP_SELF"].'",
                    type: "GET",
                    data: {
                        action: "togglefavorite",
                        id: consId,
                        socid: '.$socid.',
                        ajax: 1
                    },
                    dataType: "json",
                    success: function(response) {
                        if (response.success) {
                            if (response.is_favorite) {
                                starIcon.removeClass("far").addClass("fas");
                                starIcon.css("color", "#f39c12");
                                starIcon.parent().attr("title", "Desfijar");
                            } else {
                                starIcon.removeClass("fas").addClass("far");
                                starIcon.css("color", "#ccc");
                                starIcon.parent().attr("title", "Fijar consulta");
                            }
                            // Recargar la página después de un pequeño delay para reorganizar
                            setTimeout(function() {
                                window.location.reload();
                            }, 300);
                        }
                    },
                    error: function() {
                        console.error("Error al actualizar favorito");
                    }
                });
            });
        });
        </script>';
    }
}

llxFooter();
$db->close();
