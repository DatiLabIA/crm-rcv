<?php
/* Copyright (C) 2024 Your Company
 * Consultation Types Management
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array("admin", "cabinetmed_extcons@cabinetmed_extcons"));

if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$id = GETPOST('id', 'int');
$confirm = GETPOST('confirm', 'alpha');

$form = new Form($db);
$error = 0;

/*
 * Actions
 */

if ($cancel) {
    $action = '';
}

// Add new type
if ($action == 'add' && !$cancel) {
    $code = trim(GETPOST('code', 'alpha'));
    $label = trim(GETPOST('label', 'restricthtml'));
    $description = trim(GETPOST('description', 'restricthtml'));
    $fields_config = GETPOST('fields_config', 'alpha');
    $position = GETPOST('position', 'int');
    $active = GETPOST('active', 'int') ? 1 : 0;
    
    if (empty($code) || empty($label)) {
        $error++;
        setEventMessages("Error: Código y Etiqueta son obligatorios", null, 'errors');
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_types (";
        $sql .= " entity, code, label, description, fields_config, position, active, datec, fk_user_creat";
        $sql .= ") VALUES (";
        $sql .= $conf->entity;
        $sql .= ", '".$db->escape($code)."'";
        $sql .= ", '".$db->escape($label)."'";
        $sql .= ", '".$db->escape($description)."'";
        $sql .= ", '".$db->escape($fields_config)."'";
        $sql .= ", ".((int) $position);
        $sql .= ", ".$active;
        $sql .= ", '".$db->idate(dol_now())."'";
        $sql .= ", ".$user->id;
        $sql .= ")";
        
        if ($db->query($sql)) {
            setEventMessages("Registro guardado", null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]);
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

// Update type
if ($action == 'update' && !$cancel) {
    $label = trim(GETPOST('label', 'restricthtml'));
    $description = trim(GETPOST('description', 'restricthtml'));
    $fields_config = GETPOST('fields_config', 'alpha');
    $position = GETPOST('position', 'int');
    $active = GETPOST('active', 'int') ? 1 : 0;
    
    if (empty($label)) {
        $error++;
        setEventMessages("Error: La etiqueta es obligatoria", null, 'errors');
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."cabinetmed_extcons_types SET";
        $sql .= " label = '".$db->escape($label)."'";
        $sql .= ", description = '".$db->escape($description)."'";
        $sql .= ", fields_config = '".$db->escape($fields_config)."'";
        $sql .= ", position = ".((int) $position);
        $sql .= ", active = ".$active;
        $sql .= ", fk_user_modif = ".$user->id;
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity = ".$conf->entity;
        
        if ($db->query($sql)) {
            setEventMessages("Registro modificado", null, 'mesgs');
            header("Location: ".$_SERVER["PHP_SELF"]);
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

// Delete type
if ($action == 'confirm_delete' && $confirm == 'yes') {
    $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."cabinetmed_extcons";
    $sql .= " WHERE tipo_atencion = (SELECT code FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types WHERE rowid = ".((int) $id).")";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj->nb > 0) {
            setEventMessages("Error: Este tipo está en uso en " . $obj->nb . " registros.", null, 'errors');
            $action = '';
        } else {
            $sql = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
            $sql .= " WHERE rowid = ".((int) $id);
            $sql .= " AND entity = ".$conf->entity;
            
            if ($db->query($sql)) {
                setEventMessages("Registro eliminado", null, 'mesgs');
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }
}

// Initialize default types
if ($action == 'init_defaults') {
    $default_types = array(
        array('code' => 'adherencia', 'label' => 'Dispensación Adherencia', 'fields' => 'adherencia', 'position' => 10),
        array('code' => 'control', 'label' => 'Control Médico', 'fields' => 'control,general', 'position' => 20),
        array('code' => 'enfermeria', 'label' => 'Cuidados Enfermería', 'fields' => 'enfermeria', 'position' => 30),
        array('code' => 'farmacia', 'label' => 'Farmacia', 'fields' => 'farmacia', 'position' => 40),
        array('code' => 'general', 'label' => 'Consulta General', 'fields' => 'control,general', 'position' => 50)
    );
    
    foreach ($default_types as $type) {
        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
        $sql .= " WHERE code = '".$db->escape($type['code'])."' AND entity = ".$conf->entity;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj->nb == 0) {
                $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_types (";
                $sql .= " entity, code, label, fields_config, position, active, datec, fk_user_creat";
                $sql .= ") VALUES (";
                $sql .= $conf->entity;
                $sql .= ", '".$db->escape($type['code'])."'";
                $sql .= ", '".$db->escape($type['label'])."'";
                $sql .= ", '".$db->escape($type['fields'])."'";
                $sql .= ", ".$type['position'];
                $sql .= ", 1";
                $sql .= ", '".$db->idate(dol_now())."'";
                $sql .= ", ".$user->id;
                $sql .= ")";
                $db->query($sql);
            }
        }
    }
    setEventMessages("Tipos por defecto inicializados", null, 'mesgs');
}

/*
 * View
 */

$page_name = "Tipos de Consulta";
$help_url = '';

llxHeader('', $page_name, $help_url);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">Volver a módulos</a>';
print load_fiche_titre($page_name, $linkback, 'title_setup');

// Tabs
$head = array();
$h = 0;

$head[$h][0] = dol_buildpath('/cabinetmed_extcons/admin/setup.php', 1);
$head[$h][1] = "Configuración";
$head[$h][2] = 'settings';
$h++;

$head[$h][0] = dol_buildpath('/cabinetmed_extcons/admin/consultation_types.php', 1);
$head[$h][1] = "Tipos de Consulta";
$head[$h][2] = 'types';
$h++;

$head[$h][0] = dol_buildpath('/cabinetmed_extcons/admin/about.php', 1);
$head[$h][1] = "Acerca de";
$head[$h][2] = 'about';
$h++;

print dol_get_fiche_head($head, 'types', "Consultas Externas", -1, 'generic');

// Delete confirmation
if ($action == 'delete') {
    print $form->formconfirm(
        $_SERVER["PHP_SELF"].'?id='.$id,
        'Eliminar tipo de consulta',
        '¿Estás seguro de que quieres eliminar este tipo de consulta?',
        'confirm_delete',
        '',
        0,
        1
    );
}

// Create/Edit form
if ($action == 'create' || $action == 'edit') {
    $code = '';
    $label = '';
    $description = '';
    $fields_config = '';
    $position = 0;
    $active = 1;
    
    if ($action == 'edit' && $id > 0) {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity = ".$conf->entity;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj) {
                $code = $obj->code;
                $label = $obj->label;
                $description = $obj->description;
                $fields_config = $obj->fields_config;
                $position = $obj->position;
                $active = $obj->active;
            }
        }
    }
    
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($action == 'edit') {
        print '<input type="hidden" name="id" value="'.$id.'">';
    }
    
    print '<table class="border centpercent">';
    
    print '<tr><td class="titlefieldcreate'.($action == 'create' ? ' fieldrequired' : '').'">Código</td><td>';
    if ($action == 'create') {
        print '<input type="text" name="code" value="'.dol_escape_htmltag($code).'" class="flat minwidth300" maxlength="32" required>';
        print '<br><span class="opacitymedium">Solo alfanumérico</span>';
    } else {
        print '<strong>'.$code.'</strong>';
        print '<br><span class="opacitymedium">No se puede modificar</span>';
    }
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Etiqueta</td><td>';
    print '<input type="text" name="label" value="'.dol_escape_htmltag($label).'" class="flat minwidth300" required>';
    print '</td></tr>';
    
    print '<tr><td class="tdtop">Descripción</td><td>';
    print '<textarea name="description" rows="3" class="flat quatrevingtpercent">'.dol_escape_htmltag($description).'</textarea>';
    print '</td></tr>';
    
    print '<tr><td class="tdtop">Configuración de campos</td><td>';
    print '<input type="text" name="fields_config" value="'.dol_escape_htmltag($fields_config).'" class="flat quatrevingtpercent">';
    print '<br><span class="opacitymedium">Ayuda de configuración de campos (JSON o lista separada por comas)</span>';
    print '</td></tr>';
    
    print '<tr><td>Posición</td><td>';
    print '<input type="number" name="position" value="'.$position.'" class="flat maxwidth100">';
    print '</td></tr>';
    
    print '<tr><td>Activo</td><td>';
    print '<input type="checkbox" name="active" value="1"'.($active ? ' checked' : '').'>';
    print '</td></tr>';
    
    print '</table>';
    
    print '<div class="center" style="margin-top: 20px;">';
    print '<input type="submit" class="button button-save" value="Guardar">';
    print ' &nbsp; ';
    print '<input type="submit" class="button button-cancel" name="cancel" value="Cancelar">';
    print '</div>';
    
    print '</form>';
} else {
    print '<div class="tabsAction">';
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=create">Nuevo Tipo de Consulta</a>';
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=init_defaults">Inicializar valores por defecto</a>';
    print '</div>';
    
    $sql = "SELECT rowid, code, label, description, fields_config, position, active";
    $sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
    $sql .= " WHERE entity = ".$conf->entity;
    $sql .= " ORDER BY position ASC, label ASC";
    
    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<th>Código</th>';
        print '<th>Etiqueta</th>';
        print '<th>Config. Campos</th>';
        print '<th class="center">Posición</th>';
        print '<th class="center">Estado</th>';
        print '<th class="right">Acción</th>';
        print '</tr>';
        
        if ($num > 0) {
            $i = 0;
            while ($i < $num) {
                $obj = $db->fetch_object($resql);
                
                print '<tr class="oddeven">';
                print '<td><strong>'.$obj->code.'</strong></td>';
                print '<td>'.$obj->label.'</td>';
                print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($obj->fields_config).'">'.$obj->fields_config.'</td>';
                print '<td class="center">'.$obj->position.'</td>';
                print '<td class="center">';
                print $obj->active ? '<span class="badge badge-status4 badge-status">Activo</span>' : '<span class="badge badge-status8 badge-status">Desactivado</span>';
                print '</td>';
                print '<td class="right nowraponall">';
                print '<a class="editfielda marginrightonly" href="'.dol_buildpath('/cabinetmed_extcons/admin/consultation_type_fields.php', 1).'?type_id='.$obj->rowid.'" title="Gestionar campos personalizados">';
                print img_picto('Gestionar Campos', 'list', 'class="paddingright"');
                print '</a>';
                print '<a class="editfielda marginrightonly" href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$obj->rowid.'">';
                print img_edit();
                print '</a>';
                print '<a class="marginleftonly" href="'.$_SERVER["PHP_SELF"]
                .'?action=delete&id='.$obj->rowid.'&token='.newToken().'">';
                print img_delete();
                print '</a>';
                print '</td>';
                print '</tr>';
                
                $i++;
            }
        } else {
            print '<tr><td colspan="6" class="opacitymedium center">No se encontraron registros</td></tr>';
        }
        
        print '</table>';
    }
}

print dol_get_fiche_end();

llxFooter();
$db->close();