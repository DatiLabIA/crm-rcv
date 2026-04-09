<?php
/* Copyright (C) 2024 Your Company
 * Module Setup Page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res && file_exists("../../../../../main.inc.php")) $res = @include "../../../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// Load translation files
$langs->loadLangs(array("admin", "cabinetmed_extcons@cabinetmed_extcons"));

// Access control
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$value = GETPOST('value', 'alpha');

$error = 0;

/*
 * Actions
 */

// Save settings
if ($action == 'update') {
    // Enable/disable features
    $enable_notes = GETPOST('enable_notes', 'int');
    $res = dolibarr_set_const($db, "CABINETMED_EXTCONS_ENABLE_NOTES", $enable_notes, 'chaine', 0, '', $conf->entity);
    if (!$res > 0) $error++;
    
    $enable_attachments = GETPOST('enable_attachments', 'int');
    $res = dolibarr_set_const($db, "CABINETMED_EXTCONS_ENABLE_ATTACHMENTS", $enable_attachments, 'chaine', 0, '', $conf->entity);
    if (!$res > 0) $error++;
    
    $default_duration = GETPOST('default_duration', 'int');
    $res = dolibarr_set_const($db, "CABINETMED_EXTCONS_DEFAULT_DURATION", $default_duration, 'chaine', 0, '', $conf->entity);
    if (!$res > 0) $error++;
    
    if (!$error) {
        setEventMessages("Configuración guardada", null, 'mesgs');
    } else {
        setEventMessages("Error al guardar", null, 'errors');
    }
}

/*
 * View
 */

$page_name = "Configuración Consultas";
$help_url = '';

llxHeader('', $page_name, $help_url);

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">Volver a módulos</a>';
print load_fiche_titre($page_name, $linkback, 'title_setup');

// Configuration header
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

print dol_get_fiche_head($head, 'settings', "Consultas Externas", -1, 'generic');

// Module info
print '<span class="opacitymedium">Configuración del módulo de consultas externas</span><br><br>';

// Setup form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

// Settings table
print '<table class="noborder centpercent">';

// Title
print '<tr class="liste_titre">';
print '<td>Parámetro</td>';
print '<td align="center" width="60">Valor</td>';
print '<td width="300">Descripción</td>';
print '</tr>';

// Enable notes
print '<tr class="oddeven">';
print '<td>Habilitar Notas</td>';
print '<td align="center">';
$checked = getDolGlobalInt('CABINETMED_EXTCONS_ENABLE_NOTES', 1) ? ' checked' : '';
print '<input type="checkbox" name="enable_notes" value="1"'.$checked.'>';
print '</td>';
print '<td>Permitir agregar notas a las consultas</td>';
print '</tr>';

// Enable attachments
print '<tr class="oddeven">';
print '<td>Habilitar Adjuntos</td>';
print '<td align="center">';
$checked = getDolGlobalInt('CABINETMED_EXTCONS_ENABLE_ATTACHMENTS') ? ' checked' : '';
print '<input type="checkbox" name="enable_attachments" value="1"'.$checked.'>';
print '</td>';
print '<td>Permitir subir archivos adjuntos en las consultas</td>';
print '</tr>';

// Default duration
print '<tr class="oddeven">';
print '<td>Duración por defecto (min)</td>';
print '<td align="center">';
print '<input type="number" name="default_duration" value="'.getDolGlobalInt('CABINETMED_EXTCONS_DEFAULT_DURATION', 30).'" class="flat" min="15" max="180" style="width:60px;">';
print '</td>';
print '<td>Duración predeterminada para nuevas consultas</td>';
print '</tr>';

print '</table>';

// Save button
print '<div class="center" style="margin-top: 20px;">';
print '<input type="submit" class="button button-save" value="Guardar">';
print '</div>';

print '</form>';

// Additional actions
print '<br><br>';
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>Otras Acciones</td>';
print '<td align="center">Acción</td>';
print '</tr>';

// Link to consultation types
print '<tr class="oddeven">';
print '<td>Gestionar Tipos de Consulta</td>';
print '<td align="center">';
print '<a class="button button-save" href="'.dol_buildpath('/cabinetmed_extcons/admin/consultation_types.php', 1).'">';
print "Tipos de Consulta";
print '</a>';
print '</td>';
print '</tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Statistics
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">Estadísticas</td>';
print '</tr>';

// Count consultations
$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."cabinetmed_extcons";
$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    print '<tr class="oddeven">';
    print '<td>Total Consultas</td>';
    print '<td align="right"><strong>'.$obj->nb.'</strong></td>';
    print '</tr>';
}

// Count by type
$sql = "SELECT tipo_atencion, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."cabinetmed_extcons";
$sql .= " GROUP BY tipo_atencion";
$sql .= " ORDER BY nb DESC";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<tr class="oddeven">';
        print '<td>';
        dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');
        $types = ExtConsultation::getTypesArray($langs);
        print isset($types[$obj->tipo_atencion]) ? $types[$obj->tipo_atencion] : $obj->tipo_atencion;
        print '</td>';
        print '<td align="right">'.$obj->nb.'</td>';
        print '</tr>';
    }
}

print '</table>';

print '</div>';
print '</div>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();