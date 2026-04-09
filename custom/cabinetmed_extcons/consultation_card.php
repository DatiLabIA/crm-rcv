    <?php
    /* Copyright (C) 2024 Your Company
    * Consultation Card - View/Edit individual consultation - CSRF FIXED
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
    require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
    require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
    require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
    dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');
    dol_include_once('/cabinetmed_extcons/lib/cabinetmed_extcons.lib.php');

    // Load translation files
    $langs->loadLangs(array("companies", "other", "cabinetmed@cabinetmed", "cabinetmed_extcons@cabinetmed_extcons"));

    // Get parameters
    $id = GETPOST('id', 'int');
    $ref = GETPOST('ref', 'alpha');
    $action = GETPOST('action', 'aZ09');
    $confirm = GETPOST('confirm', 'alpha');
    $cancel = GETPOST('cancel', 'alpha');
    $backtopage = GETPOST('backtopage', 'alpha');

    // Initialize objects
    $object = new ExtConsultation($db);
    $patient = new Societe($db);
    $assigneduser = new User($db);
    $form = new Form($db);
    $formfile = new FormFile($db);

    // Load object
    if ($id > 0 || !empty($ref)) {
        $result = $object->fetch($id, $ref);
        if ($result < 0) {
            dol_print_error($db, $object->error);
            exit;
        }
        
        // Load patient
        if ($object->fk_soc > 0) {
            $patient->fetch($object->fk_soc);
        }
        
        // Load assigned user
        if ($object->fk_user > 0) {
            $assigneduser->fetch($object->fk_user);
        }
    }

    // Security check
    $socid = $object->fk_soc;
    if (!$socid) $socid = GETPOST('socid', 'int');

    // Simplified security check - based on CabinetMed module rights
    if (!$user->rights->cabinetmed->read && !$user->rights->cabinetmed_extcons->read) {
        accessforbidden();
    }

    // Permissions
    // Permisos, usando empty() para evitar warnings si aún no existen las propiedades
    $permtoread   = !empty($user->rights->cabinetmed->read)
                || !empty($user->rights->cabinetmed_extcons->read);

    $permtocreate = !empty($user->rights->cabinetmed->write)
                || !empty($user->rights->cabinetmed_extcons->write);

    $permtodelete = !empty($user->rights->cabinetmed->delete)
                || !empty($user->rights->cabinetmed_extcons->delete);


    if (!$permtoread) accessforbidden();

        /*
        * Actions
        */

     // Cancel action
    if ($cancel) {
        if (!empty($backtopage)) {
            header("Location: ".$backtopage);
            exit;
        }
    }

    // Si venimos del form de confirmación y el usuario ha pulsado "No"
    if ($action == 'confirm_delete' && $confirm != 'yes') {
        header("Location: ".dol_buildpath('/cabinetmed_extcons/consultation_list.php', 1));
        exit;
    }

    // Si venimos del form de confirmación y el usuario ha pulsado "No"
    if ($action == 'confirm_delete' && $confirm != 'yes') {
        // Volvemos simplemente al modo vista de la consulta
        $action = '';
    }

    // Borrado real solo si confirm_delete + yes
    if ($action == 'confirm_delete' && $permtodelete && $confirm == 'yes') {
        $res = $object->delete($user);
        if ($res > 0) {
            header("Location: ".dol_buildpath('/cabinetmed_extcons/consultation_list.php', 1));
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
            $action = '';
        }
    }

    // Update consultation
    if ($action == 'update' && $permtocreate && !$cancel) {
        $error = 0;
        
        
        if (!$error) {
            // Get posted data
            // Legacy: mantener fk_user con el primer usuario seleccionado
            $assigned_users = GETPOST('assigned_users', 'array');
            if (!is_array($assigned_users)) {
                $assigned_users = array();
            }
            // Si no se seleccionó ningún usuario, asignar al usuario actual por defecto
            if (empty($assigned_users)) {
                $assigned_users = array($user->id);
            }
            $object->fk_user = !empty($assigned_users) ? (int) $assigned_users[0] : 0;
            
            $object->date_start = dol_mktime(GETPOST('date_starthour', 'int'), GETPOST('date_startmin', 'int'), 0, 
                                            GETPOST('date_startmonth', 'int'), GETPOST('date_startday', 'int'), 
                                            GETPOST('date_startyear', 'int'));
            $object->date_end = dol_mktime(GETPOST('date_endhour', 'int'), GETPOST('date_endmin', 'int'), 0,
                                            GETPOST('date_endmonth', 'int'), GETPOST('date_endday', 'int'), 
                                            GETPOST('date_endyear', 'int'));
            $object->tipo_atencion = GETPOST('tipo_atencion', 'alpha');
            
            // Type-specific legacy fields: solo actualizar si el campo fue realmente enviado
            // (los campos en secciones ocultas/deshabilitadas no se envían por POST)
            $legacy_fields = array(
                'cumplimiento' => 'alpha', 'razon_inc' => 'alpha', 'mes_actual' => 'alpha',
                'proximo_mes' => 'alpha', 'dificultad' => 'int', 'motivo' => 'alpha',
                'diagnostico' => 'restricthtml', 'procedimiento' => 'alpha',
                'insumos_enf' => 'alpha', 'rx_num' => 'alpha', 'medicamentos' => 'restricthtml'
            );
            foreach ($legacy_fields as $fname => $ftype) {
                if (GETPOSTISSET($fname)) {
                    $object->$fname = GETPOST($fname, $ftype);
                }
                // Si no fue enviado, mantener el valor existente del objeto (ya cargado en fetch)
            }

            $object->note_private = GETPOST('note_private', 'restricthtml');
            // note_public is read-only, keep existing value from fetch
            
            // Observaciones: prefer imgtext hidden input (JS ran), fallback to textarea (JS didn't)
            $obs_imgtext = GETPOST('observaciones_html', 'restricthtml');
            if ($obs_imgtext !== '' && $obs_imgtext !== null) {
                $object->observaciones = $obs_imgtext;
            } else {
                $obs_fallback = GETPOST('observaciones', 'restricthtml');
                $object->observaciones = ($obs_fallback !== '' && $obs_fallback !== null) ? $obs_fallback : $object->observaciones;
            }
            
            $object->status = GETPOST('status', 'int');
            
            // Recurrence fields
            $old_recurrence_enabled = $object->recurrence_enabled;
            $object->recurrence_enabled = GETPOST('recurrence_enabled', 'int') ? 1 : 0;
            $object->recurrence_interval = GETPOST('recurrence_interval', 'int');
            $object->recurrence_unit = GETPOST('recurrence_unit', 'alpha');
            $object->recurrence_end_type = GETPOST('recurrence_end_type', 'alpha');
            $rec_end_date_day = GETPOST('recurrence_end_dateday', 'int');
            $rec_end_date_month = GETPOST('recurrence_end_datemonth', 'int');
            $rec_end_date_year = GETPOST('recurrence_end_dateyear', 'int');
            if ($rec_end_date_day > 0 && $rec_end_date_month > 0 && $rec_end_date_year > 0) {
                $object->recurrence_end_date = sprintf('%04d-%02d-%02d', $rec_end_date_year, $rec_end_date_month, $rec_end_date_day);
            } else {
                $object->recurrence_end_date = null;
            }

        
        // Validation
        if (empty($object->tipo_atencion)) {
            $error++;
            setEventMessages($langs->trans("ErrorFieldRequired", "Tipo de consulta"), null, 'errors');
        }
        
        if (!$error) {
            $result = $object->update($user);
            if ($result < 0) {
                setEventMessages($object->error, $object->errors, 'errors');
                $action = 'edit';
            } else {
                // Guardar múltiples usuarios asignados
                $object->setAssignedUsers($assigned_users, $user);
                
                // Manejar recurrencias: si cambió la configuración, regenerar
                if ($object->recurrence_enabled) {
                    // Eliminar hijas futuras y regenerar
                    $deleted = $object->deleteChildRecurrences($user);
                    if ($deleted > 0) {
                        dol_syslog("Deleted ".$deleted." old child recurrences before regeneration", LOG_DEBUG);
                    }
                    $rec_result = $object->generateRecurrences($user);
                    if ($rec_result > 0) {
                        setEventMessages("Se regeneraron ".$rec_result." consultas recurrentes", null, 'mesgs');
                    }
                } elseif ($old_recurrence_enabled && !$object->recurrence_enabled) {
                    // Se desactivó la recurrencia: eliminar hijas futuras pendientes
                    $deleted = $object->deleteChildRecurrences($user);
                    if ($deleted > 0) {
                        setEventMessages("Se eliminaron ".$deleted." consultas futuras pendientes", null, 'mesgs');
                    }
                }
                
                setEventMessages($langs->trans("RecordModified"), null, 'mesgs');
                $action = '';
            }
            } else {
                $action = 'edit';
            }
        }
    }

    /*
    * View
    */

    // Handle linked-object actions (add/delete links via llx_element_element)
    if ($action == 'addlink' && $permtocreate) {
        $typelink = GETPOST('typelink', 'aZ09');
        $idlink = GETPOST('idlink', 'int');
        if ($typelink && $idlink > 0) {
            $result = $object->add_object_linked($typelink, $idlink);
            if ($result < 0) {
                setEventMessages($object->error, $object->errors, 'errors');
            }
        }
        $action = '';
    }

    if ($action == 'deletelink' && $permtocreate) {
        $linkid = GETPOST('linkid', 'int');
        $linktype = GETPOST('linktype', 'aZ09');
        if ($linkid > 0) {
            $result = $object->deleteObjectLinked($linkid, $linktype);
            if ($result < 0) {
                setEventMessages($object->error, $object->errors, 'errors');
            }
        }
        $action = '';
    }

    $title = $langs->trans('Consultation').' - '.($object->id ? 'CONS-'.sprintf("%06d", $object->id) : $langs->trans("New"));
    $help_url = '';

    llxHeader('', $title, $help_url);

    if ($action == 'delete' && $permtodelete) {
        $formconfirm = $form->formconfirm(
            $_SERVER["PHP_SELF"].'?id='.$object->id.'&token='.newToken(),
            $langs->trans('DeleteConsultation'),
            $langs->trans('ConfirmDeleteConsultation'),
            'confirm_delete',
            '',
            0,
            1
        );
        print $formconfirm;
    }


    // View mode
    if ($object->id > 0 && $action != 'edit' && $action != 'delete') {
        $head = extconsultation_prepare_head($object);
        
        print dol_get_fiche_head($head, 'card', $langs->trans("Consultation"), -1, 'action');
        
        // Consultation card
        $linkback = '<a href="'.dol_buildpath('/cabinetmed_extcons/consultation_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
        
        $morehtmlref = '<div class="refidno">';
        $morehtmlref .= $langs->trans('Patient').': ';
        if ($patient->id > 0) {
            $morehtmlref .= $patient->getNomUrl(1);
        }
        $morehtmlref .= '</div>';
    
    // FIXED: Proper reference generation
    $objectref = 'CONS-'.sprintf("%06d", $object->id);
    $object->ref = $objectref; // Set ref property for banner
    
    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
    
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">';
    
    // Consultation type
    print '<tr><td class="titlefield">'."Tipo de consulta".'</td><td>';
    print $object->getTypeLabel($langs);
    print '</td></tr>';

    // Status
    print '<tr><td>'.$langs->trans("Status").'</td><td>';
    print $object->getLibStatus(1);    // badge con color
    print '</td></tr>';
    
    // Assigned to - Múltiples encargados
    print '<tr><td>'.$langs->trans("AssignedTo").'</td><td>';
    $object->fetchAssignedUsers();
    print $object->getAssignedUsersHTML(1);
    print '</td></tr>';
    
    // Date start
    print '<tr><td>'.$langs->trans("DateStart").'</td><td>';
    print $object->date_start ? dol_print_date($object->date_start, 'dayhour') : '';
    print '</td></tr>';
    
    // Date end
    print '<tr><td>'.$langs->trans("DateEnd").'</td><td>';
    print $object->date_end ? dol_print_date($object->date_end, 'dayhour') : '';
    print '</td></tr>';

    // Recurrence info
    if ($object->recurrence_enabled) {
        print '<tr><td><i class="fas fa-sync-alt" style="color:#2196F3;"></i> Recurrencia</td><td>';
        print '<span class="badge" style="background-color:#2196F3;color:#fff;padding:2px 8px;border-radius:3px;">';
        print dol_escape_htmltag($object->getRecurrenceLabel());
        print '</span>';
        $child_count = $object->countChildRecurrences();
        if ($child_count > 0) {
            print ' <span class="opacitymedium">('.$child_count.' ocurrencias generadas)</span>';
        }
        print '</td></tr>';
    } elseif ($object->recurrence_parent_id > 0) {
        print '<tr><td><i class="fas fa-link" style="color:#9e9e9e;"></i> Origen</td><td>';
        print 'Ocurrencia de <a href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$object->recurrence_parent_id.'">';
        print 'CONS-'.sprintf('%06d', $object->recurrence_parent_id);
        print '</a>';
        print '</td></tr>';
    }

    // Creation info
    print '<tr><td>'.$langs->trans("DateCreation").'</td><td>';
    print dol_print_date($object->datec, 'dayhour');
    if ($object->fk_user_creat > 0) {
        $usercreat = new User($db);
        $usercreat->fetch($object->fk_user_creat);
        print ' - '.$usercreat->getNomUrl(1);
    }
    print '</td></tr>';
    
    // Modification info
    if ($object->tms) {
        print '<tr><td>'.$langs->trans("DateModification").'</td><td>';
        print dol_print_date($object->tms, 'dayhour');
        if ($object->fk_user_modif > 0) {
            $usermodif = new User($db);
            $usermodif->fetch($object->fk_user_modif);
            print ' - '.$usermodif->getNomUrl(1);
        }
        print '</td></tr>';
    }

    print '</table>';
    print '</div>';
    
    print '<div class="fichehalfright">';
    print '</div>';
    print '</div>';
    
    print '<div class="clearboth"></div>';

    // ===== DYNAMIC FIELDS SECTION - VIEW MODE =====
    // Obtener el tipo de consulta actual
    $current_type = $object->tipo_atencion;
    
    if (!empty($current_type)) {
        // Buscar el rowid del tipo de consulta
        $sql_type = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types 
                     WHERE code = '".$db->escape($current_type)."' 
                     AND entity = ".$conf->entity." AND active = 1";
        $res_type = $db->query($sql_type);
        
        if ($res_type && $db->num_rows($res_type) > 0) {
            $type_data = $db->fetch_object($res_type);
            
            // Obtener los campos configurados para este tipo
            $sql_fields = "SELECT field_name, field_label, field_type, field_options, required 
                           FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields 
                           WHERE fk_type = ".$type_data->rowid." AND active = 1 
                           ORDER BY position ASC";
            $res_fields = $db->query($sql_fields);
            
            if ($res_fields && $db->num_rows($res_fields) > 0) {
                // Datos guardados en custom_fields_array
                $saved_data = array();
                if (!empty($object->custom_fields_array)) {
                    $saved_data = $object->custom_fields_array;
                }
                
                // Primera pasada: recopilar campos con datos para decidir si mostrar la sección
                $fields_with_data = array();
                $all_fields = array();
                while ($field = $db->fetch_object($res_fields)) {
                    $all_fields[] = $field;
                    if ($field->field_type === 'separator') continue;
                    $value = '';
                    if (isset($object->{$field->field_name}) && !empty($object->{$field->field_name})) {
                        $value = $object->{$field->field_name};
                    } elseif (isset($saved_data[$field->field_name]) && !empty($saved_data[$field->field_name])) {
                        $value = $saved_data[$field->field_name];
                    }
                    if ($value !== '' && $value !== null && $value !== '-1' && $value !== -1
                        && !(is_array($value) && empty($value))) {
                        $fields_with_data[] = $field->field_name;
                    }
                }
                
                // Solo mostrar la sección si hay al menos un campo con datos
                if (!empty($fields_with_data)) {
                print '<br>';
                print '<div class="fichecenter">';
                print '<div class="underbanner clearboth"></div>';
                print '<table class="border centpercent tableforfield">';
                print '<tr class="liste_titre"><td colspan="2">'.$langs->trans($type_data->label).'</td></tr>';
                
                // Segunda pasada: renderizar solo campos con datos
                foreach ($all_fields as $field) {
                    // Obtener el valor del campo
                    $value = '';
                    
                    // Primero intentar columna directa (campos legacy)
                    if (isset($object->{$field->field_name}) && !empty($object->{$field->field_name})) {
                        $value = $object->{$field->field_name};
                    } 
                    // Luego intentar custom_fields_array (campos dinámicos JSON)
                    elseif (isset($saved_data[$field->field_name]) && !empty($saved_data[$field->field_name])) {
                        $value = $saved_data[$field->field_name];
                    }
                    
                    // Saltar campos vacíos / -1 (selectarray vacío de Dolibarr)
                    if ($field->field_type !== 'separator') {
                        $is_empty = false;
                        if ($value === '' || $value === null) {
                            $is_empty = true;
                        } elseif ($value === '-1' || $value === -1) {
                            $is_empty = true;
                        } elseif (is_array($value) && empty($value)) {
                            $is_empty = true;
                        } elseif ($value === '0' && in_array($field->field_type, array('select', 'radio', 'multiselect'))) {
                            $is_empty = true;
                        }
                        if ($is_empty) continue;
                    }
                    
                    // Mostrar la fila del campo
                    print '<tr>';
                    print '<td class="titlefield">'.$langs->trans($field->field_label).'</td>';
                    print '<td>';
                    
                    // Formatear el valor según el tipo de campo
                    if (!empty($value) || $value === '0' || $value === 0) {
                        switch ($field->field_type) {
                            case 'textarea':
                            case 'html':
                                // Detectar si el contenido tiene HTML (del editor contenteditable)
                                if (preg_match('/<(img|br|div|p|span)\b/i', $value)) {
                                    print '<div style="max-width:100%; overflow:auto;">'.ExtConsultation::sanitizeImgTextHtml($value).'</div>';
                                } else {
                                    print dol_htmlentitiesbr($value);
                                }
                                break;
                                
                            case 'checkbox':
                            case 'boolean':
                                print $value ? $langs->trans("Yes") : $langs->trans("No");
                                break;
                                
                            case 'date':
                                if ($value) {
                                    $timestamp = is_numeric($value) ? $value : strtotime($value);
                                    if ($timestamp) {
                                        print dol_print_date($timestamp, 'day');
                                    } else {
                                        print dol_escape_htmltag($value);
                                    }
                                }
                                break;
                            
                            case 'datetime':
                                if ($value) {
                                    $timestamp = is_numeric($value) ? $value : strtotime($value);
                                    if ($timestamp) {
                                        print dol_print_date($timestamp, 'dayhour');
                                    } else {
                                        print dol_escape_htmltag($value);
                                    }
                                }
                                break;
                            
                            case 'time':
                                print dol_escape_htmltag($value);
                                break;
                                
                            case 'number':
                            case 'integer':
                                print dol_escape_htmltag($value);
                                break;
                            
                            case 'price':
                                print price($value, 0, $langs, 1, -1, -1, $conf->currency);
                                break;
                            
                            case 'percentage':
                                print dol_escape_htmltag($value).' %';
                                break;
                                
                            case 'select':
                            case 'radio':
                                // Resolver opciones (estáticas o desde BD)
                                $opts_resolved = ExtConsultation::resolveFieldOptions($field->field_options, $db);
                                $display_value = isset($opts_resolved[$value]) ? $opts_resolved[$value] : $value;
                                print dol_escape_htmltag($display_value);
                                break;
                            
                            case 'multiselect':
                                $values = is_array($value) ? $value : explode(',', $value);
                                $opts_resolved = ExtConsultation::resolveFieldOptions($field->field_options, $db);
                                $display_values = array();
                                foreach($values as $v) {
                                    $v = trim($v);
                                    $display_values[] = isset($opts_resolved[$v]) ? $opts_resolved[$v] : $v;
                                }
                                print dol_escape_htmltag(implode(', ', $display_values));
                                break;
                            
                            case 'email':
                                print '<a href="mailto:'.dol_escape_htmltag($value).'">'.dol_escape_htmltag($value).'</a>';
                                break;
                            
                            case 'phone':
                                print dol_print_phone($value);
                                break;
                            
                            case 'url':
                                print '<a href="'.dol_escape_htmltag($value).'" target="_blank">'.dol_trunc($value, 50).'</a>';
                                break;
                            
                            case 'color':
                                print '<span style="display:inline-block;width:24px;height:24px;background-color:'.dol_escape_htmltag($value).';border:1px solid #ccc;vertical-align:middle;"></span> '.dol_escape_htmltag($value);
                                break;
                            
                            case 'stars':
                                $max_stars = 5;
                                if ($field->field_options && is_numeric($field->field_options)) {
                                    $max_stars = (int) $field->field_options;
                                }
                                print '<span style="color:#f1c40f;font-size:1.2em;">'.str_repeat('★', (int)$value).str_repeat('☆', $max_stars - (int)$value).'</span>';
                                break;
                            
                            case 'range':
                                print dol_escape_htmltag($value);
                                break;
                            
                            case 'separator':
                                print '<strong>'.dol_escape_htmltag($field->field_options).'</strong>';
                                break;
                            
                            case 'password':
                                print '********';
                                break;
                                
                            default: // text
                                print dol_escape_htmltag($value);
                        }
                    }
                    
                    print '</td>';
                    print '</tr>';
                }
                
                print '</table>';
                print '</div>';
                } // end if (!empty($fields_with_data))
            }
        }
    }
    // ===== END DYNAMIC FIELDS SECTION =====
    
    // Observaciones (universal, all consultation types)
    if (!empty($object->observaciones)) {
        print '<br>';
        print '<div class="fichecenter">';
        print '<div class="underbanner clearboth"></div>';
        print '<table class="border centpercent tableforfield">';
        print '<tr class="liste_titre"><td colspan="2">Observaciones</td></tr>';
        print '<tr><td class="titlefield tdtop">Observaciones</td><td>';
        if (preg_match('/<(img|br|div|p|span)\b/i', $object->observaciones)) {
            print '<div style="max-width:100%; overflow:auto;">'.ExtConsultation::sanitizeImgTextHtml($object->observaciones).'</div>';
        } else {
            print dol_htmlentitiesbr($object->observaciones);
        }
        print '</td></tr>';
        print '</table>';
        print '</div>';
    }
    
    // Notes
    if ($object->note_public) {
        print '<br>';
        print '<div class="fichecenter">';
        print '<div class="underbanner clearboth"></div>';
        print '<table class="border centpercent tableforfield">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Notes").'</td></tr>';
        
        print '<tr><td class="titlefield tdtop">'.$langs->trans("NotePublic").'</td><td>';
        print dol_htmlentitiesbr($object->note_public);
        print '</td></tr>';
        
        print '</table>';
        print '</div>';
    }
    
    print dol_get_fiche_end();
    
    // FIXED: Action buttons with proper token handling
    print '<div class="tabsAction">';
    
    // Edit button
    if ($permtocreate) {
        print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
    }
    
    // Delete button
    if ($permtodelete) {
        print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken().'">'.$langs->trans("Delete").'</a>';
    }
    
    print '</div>';

    // ===== LINKED OBJECTS BLOCK =====
    // Shows objects linked via llx_element_element (e.g. dispensation orders from this consultation)
    print '<div class="fichecenter"><div class="fichehalfleft">';

    // "Link to" button — lets user pick an existing dispensation order (commande) to link
    $tmparray = $form->showLinkToObjectBlock($object, array('commande'), array(), 1);
    $linktoelem = $tmparray['linktoelem'];
    $htmltoenteralink = $tmparray['htmltoenteralink'];
    print $htmltoenteralink;

    $form->showLinkedObjectBlock($object, $linktoelem);

    print '</div></div>';
}

// Edit mode
if ($action == 'edit' && $permtocreate) {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="id" value="'.$object->id.'">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
    }
    
    print dol_get_fiche_head(array(), '');
    
    print '<table class="border centpercent">';
    
    // Ref
    print '<tr><td class="titlefieldcreate">'.$langs->trans("Ref").'</td><td>';
    print 'CONS-'.sprintf("%06d", $object->id);
    print '</td></tr>';
    
    // Patient
    print '<tr><td>'.$langs->trans("Patient").'</td><td>';
    if ($patient->id > 0) {
        print $patient->getNomUrl(1);
    }
    print '</td></tr>';
    
    // Consultation type
    print '<tr><td class="fieldrequired">'."Tipo de consulta".'</td><td>';
    print $form->selectarray('tipo_atencion', ExtConsultation::getTypesArray($langs), $object->tipo_atencion, 0, 0, 0, 'id="tipo_atencion"');
    print '</td></tr>';
    
    // Assigned to - Múltiples encargados
    print '<tr><td>'.$langs->trans("AssignedTo").'</td><td>';
    print img_picto('', 'user', 'class="pictofixedwidth"');
    // Cargar usuarios asignados actuales
    $object->fetchAssignedUsers();
    $selected_users = $object->getAssignedUserIds();
    // Si no hay usuarios asignados, pre-seleccionar usuario actual
    if (empty($selected_users)) {
        $selected_users = array($user->id);
    }
    // Selector de múltiples usuarios
    print $form->select_dolusers($selected_users, 'assigned_users', 1, '', 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth500', 0, 0, 1);
    print '<br><small class="opacitymedium">Puede seleccionar múltiples encargados manteniendo Ctrl presionado</small>';
    print '</td></tr>';
    
    // Date start
    print '<tr><td>'.$langs->trans("DateStart").'</td><td>';
    print $form->selectDate($object->date_start ? $object->date_start : -1, 'date_start', 1, 1, 0, '', 1, 1);
    print '</td></tr>';
    
    // Date end
    print '<tr><td>'.$langs->trans("DateEnd").'</td><td>';
    print $form->selectDate($object->date_end ? $object->date_end : -1, 'date_end', 1, 1, 0, '', 1, 1);
    print '</td></tr>';

     // Status
    print '<tr><td>'.$langs->trans("Status").'</td><td>';
    $statusarray = ExtConsultation::getStatusArray();
    print $form->selectarray('status', $statusarray, (int) $object->status);
    print '</td></tr>';

    // ===== RECURRENCE SECTION (EDIT) =====
    print '<tr><td>Repetir consulta</td><td>';
    $rec_enabled = GETPOSTISSET('recurrence_enabled') ? GETPOST('recurrence_enabled', 'int') : $object->recurrence_enabled;
    print '<input type="checkbox" name="recurrence_enabled" id="recurrence_enabled" value="1"'.($rec_enabled ? ' checked' : '').'>';
    print ' <label for="recurrence_enabled">Habilitar repetición</label>';
    if ($object->isChildRecurrence()) {
        print '<br><small class="opacitymedium"><i class="fas fa-info-circle"></i> Esta consulta es una ocurrencia de <a href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$object->recurrence_parent_id.'">CONS-'.sprintf('%06d', $object->recurrence_parent_id).'</a></small>';
    }
    print '</td></tr>';
    
    // Recurrence details
    $rec_interval = GETPOSTISSET('recurrence_interval') ? GETPOST('recurrence_interval', 'int') : $object->recurrence_interval;
    if ($rec_interval <= 0) $rec_interval = 1;
    $rec_unit = GETPOSTISSET('recurrence_unit') ? GETPOST('recurrence_unit', 'alpha') : $object->recurrence_unit;
    if (empty($rec_unit)) $rec_unit = 'weeks';
    $rec_end_type = GETPOSTISSET('recurrence_end_type') ? GETPOST('recurrence_end_type', 'alpha') : $object->recurrence_end_type;
    if (empty($rec_end_type)) $rec_end_type = 'forever';
    
    print '<tr class="recurrence-fields" style="'.($rec_enabled ? '' : 'display:none;').'">';
    print '<td>Repetir cada</td><td>';
    print '<input type="number" name="recurrence_interval" value="'.$rec_interval.'" min="1" max="365" class="flat maxwidth75" style="width:60px;">';
    print ' ';
    $units = array('days' => 'Día(s)', 'weeks' => 'Semana(s)', 'months' => 'Mes(es)', 'years' => 'Año(s)');
    print $form->selectarray('recurrence_unit', $units, $rec_unit, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
    print '</td></tr>';
    
    print '<tr class="recurrence-fields" style="'.($rec_enabled ? '' : 'display:none;').'">';
    print '<td>Finalización</td><td>';
    print '<label style="margin-right:15px;"><input type="radio" name="recurrence_end_type" value="forever" class="rec-end-type"'.($rec_end_type == 'forever' ? ' checked' : '').'> Sin fecha fin (para siempre)</label><br>';
    print '<label><input type="radio" name="recurrence_end_type" value="date" class="rec-end-type"'.($rec_end_type == 'date' ? ' checked' : '').'> Hasta fecha específica: </label> ';
    $rec_end_date_val = '';
    if (!empty($object->recurrence_end_date)) {
        $rec_end_date_val = strtotime($object->recurrence_end_date);
    }
    print $form->selectDate($rec_end_date_val, 'recurrence_end_date', 0, 0, 1, '', 1, 0);
    print '</td></tr>';
    
    if ($object->recurrence_enabled) {
        $child_count = $object->countChildRecurrences();
        if ($child_count > 0) {
            print '<tr class="recurrence-fields" style="'.($rec_enabled ? '' : 'display:none;').'">';
            print '<td></td><td>';
            print '<div class="info" style="background:#e3f2fd;padding:8px;border-radius:4px;margin-top:4px;">';
            print '<i class="fas fa-info-circle" style="color:#2196F3;"></i> ';
            print 'Esta consulta tiene <strong>'.$child_count.'</strong> ocurrencias generadas. ';
            print 'Si modifica la recurrencia, se eliminarán las futuras pendientes y se regenerarán.';
            print '</div>';
            print '</td></tr>';
        }
    }
    // ===== END RECURRENCE SECTION =====

    
    print '</table>';
    
    // Dynamic sections based on consultation type
    // Dynamic sections based on consultation type (AUTOMATIC RENDERING)
    print '<div id="dynamic-sections">';
    
    // 1. Obtener todos los tipos activos de la base de datos
    $sql_types = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types WHERE entity = ".$conf->entity." AND active = 1";
    $res_types = $db->query($sql_types);
    
    if ($res_types) {
        while ($type_obj = $db->fetch_object($res_types)) {
            // Preparamos los datos guardados (si estamos editando)
            $saved_data = array();
            if (!empty($object->custom_fields_array)) {
                $saved_data = $object->custom_fields_array;
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
                    
                    // Recuperar valor: Primero intentamos columna directa (legacy), luego custom_data (JSON)
                    // NOTA: Usamos property_exists() en lugar de isset() para detectar campos legacy correctamente.
                    // isset() falla cuando el valor de la propiedad es null O string vacío '', lo cual provoca
                    // que el valor guardado en JSON sea ignorado y el campo aparezca en blanco al editar.
                    $value = '';
                    if (property_exists($object, $field->field_name)) {
                        // Es una columna legacy de la tabla → siempre usar el nombre simple como input
                        $input_name = $field->field_name;
                        $col_value  = $object->{$field->field_name};
                        $json_value = isset($saved_data[$field->field_name]) ? $saved_data[$field->field_name] : '';
                        // Prioridad: valor de columna si no está vacío; fallback a JSON (puede tener datos
                        // de un guardado anterior cuando la columna era NULL)
                        $value = ($col_value !== null && $col_value !== '') ? $col_value : $json_value;
                    } else {
                        // Es un campo nuevo dinámico → va al array custom_fields (JSON)
                        $value = isset($saved_data[$field->field_name]) ? $saved_data[$field->field_name] : '';
                        $input_name = 'custom_fields['.$field->field_name.']'; // Nombre array para JSON
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
    $obs_value = $object->observaciones;
    $obs_textarea_id = 'imgtext_observaciones';
    print '<br>';
    print '<table class="border centpercent">';
    print '<tr class="liste_titre"><td colspan="2">Observaciones</td></tr>';
    print '<tr><td class="tdtop">Observaciones</td><td>';
    // Fallback textarea (hidden, used if JS doesn't run)
    print '<textarea name="observaciones" id="'.$obs_textarea_id.'_fallback" rows="3" class="flat quatrevingtpercent" style="display:none;">'.dol_escape_htmltag($obs_value).'</textarea>';
    // Hidden input: JS syncs contenteditable div innerHTML here on submit
    print '<input type="hidden" name="observaciones_html" id="'.$obs_textarea_id.'_hidden" value="">';
    // Contenteditable div for rich editing with image support
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
    
    // Notes section (Siempre visible fuera del bucle - solo lectura)
    if ($object->note_public) {
        print '<br>';
        print '<table class="border centpercent">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Notes").'</td></tr>';
        print '<tr><td class="tdtop">'.$langs->trans("NotePublic").'</td><td>';
        print dol_htmlentitiesbr($object->note_public);
        print '</td></tr>';
        print '</table>';
    }
    
    print '</div>'; // End dynamic sections // End dynamic sections
    
    print dol_get_fiche_end();
    
    // Buttons
    print '<div class="center">';
    print '<input type="submit" id="extcons-card-btn-save" class="button button-save" value="'.$langs->trans("Save").'">';
    print ' &nbsp; ';
    print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';
    print '</div>';
    
    print '</form>';
    print '<script>';
    print 'document.getElementById("extcons-card-btn-save").closest("form").addEventListener("submit", function(e) {';
    print '  var btn = document.getElementById("extcons-card-btn-save");';
    print '  if (btn.dataset.submitting) { e.preventDefault(); return; }';
    print '  btn.dataset.submitting = "1";';
    print '  btn.disabled = true;';
    print '  btn.value = "' . $langs->trans("Saving") . '...";';
    print '});';
    print '</script>';
    
    // JavaScript for dynamic sections
    // Recopilar configuración de campos condicionales
    $conditional_fields_config = array();
    $sql_all_cond = "SELECT f.field_name, f.conditional_field, f.conditional_value, t.code as type_code 
                     FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields f
                     INNER JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_types t ON f.fk_type = t.rowid
                     WHERE f.active = 1 AND f.conditional_field IS NOT NULL AND f.conditional_field != ''
                     AND t.entity = ".$conf->entity." AND t.active = 1";
    $res_cond = $db->query($sql_all_cond);
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
                // Habilitar campos EXCEPTO los condicionalmente ocultos
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

llxFooter();
$db->close();
