<?php
/* Copyright (C) 2024 Your Company
 * Consultation List - View all consultations
 * v1.1.0 - Agregado: Favoritos y múltiples encargados
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');

// Load translation files
$langs->loadLangs(array("companies", "bills", "cabinetmed@cabinetmed", "cabinetmed_extcons@cabinetmed_extcons"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'atenciones';

// List parameters
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone', 'int') - 1) : GETPOST('page', 'int');
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$optioncss = GETPOST('optioncss', 'alpha');

// Default sort
if (empty($sortfield)) $sortfield = "c.date_start";
if (empty($sortorder)) $sortorder = "DESC";

// Security check
$socid = GETPOST('socid', 'int');
if ($socid > 0) {
    $soc = new Societe($db);
    $soc->fetch($socid);
}
restrictedArea($user, 'cabinetmed');

// Initialize technical objects
$object = new ExtConsultation($db);
$form = new Form($db);
$formother = new FormOther($db);

// Permissions
$permtoread   = !empty($user->rights->cabinetmed->read) || !empty($user->rights->cabinetmed_extcons->read);
$permtocreate = !empty($user->rights->cabinetmed->write) || !empty($user->rights->cabinetmed_extcons->write);
$permtodelete = !empty($user->rights->cabinetmed->delete) || !empty($user->rights->cabinetmed_extcons->delete);

if (!$permtoread) accessforbidden();

/*
 * Actions - BEFORE getting search parameters
 */

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

// Detect if we need to reset filters
$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
$button_search = GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha');

// Reset page on search or filter reset
if ($button_search || $button_removefilter) {
    $page = 0;
}

/*
 * Get search parameters - AFTER action detection
 */
if ($button_removefilter) {
    // Clear all filters
    $search_ref = '';
    $search_patient = '';
    $search_type = '';
    $search_user = '';
    $search_date_startday = '';
    $search_date_startmonth = '';
    $search_date_startyear = '';
    $search_date_endday = '';
    $search_date_endmonth = '';
    $search_date_endyear = '';
    $search_status = '';
    $search_favorites_only = '';
} else {
    // Get filter values
    $search_ref = GETPOST('search_ref', 'alpha');
    $search_patient = GETPOST('search_patient', 'alpha');
    $search_type = GETPOST('search_type', 'alpha');
    $search_user = GETPOST('search_user', 'int');
    $search_date_startday = GETPOST('search_date_startday', 'int');
    $search_date_startmonth = GETPOST('search_date_startmonth', 'int');
    $search_date_startyear = GETPOST('search_date_startyear', 'int');
    $search_date_endday = GETPOST('search_date_endday', 'int');
    $search_date_endmonth = GETPOST('search_date_endmonth', 'int');
    $search_date_endyear = GETPOST('search_date_endyear', 'int');
    $search_status = GETPOST('search_status', 'alpha');
    $search_favorites_only = GETPOST('search_favorites_only', 'int');
}

// Build date timestamps from components
$search_date_start = '';
if ($search_date_startyear) {
    $search_date_start = dol_mktime(0, 0, 0, $search_date_startmonth ? $search_date_startmonth : 1, $search_date_startday ? $search_date_startday : 1, $search_date_startyear);
}
$search_date_end = '';
if ($search_date_endyear) {
    $search_date_end = dol_mktime(23, 59, 59, $search_date_endmonth ? $search_date_endmonth : 12, $search_date_endday ? $search_date_endday : 31, $search_date_endyear);
}

// Pagination
if (empty($page) || $page < 0) {
    $page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Mass actions
if (!empty($massaction) && $massaction == 'delete' && $permtodelete) {
    $error = 0;
    foreach ($toselect as $toselectid) {
        $consultation = new ExtConsultation($db);
        $result = $consultation->fetch($toselectid);
        if ($result > 0) {
            $result = $consultation->delete($user);
            if ($result < 0) {
                $error++;
                setEventMessages($consultation->error, $consultation->errors, 'errors');
            }
        }
    }
    if (!$error) {
        setEventMessages($langs->trans("RecordsDeleted", count($toselect)), null, 'mesgs');
    }
}

/*
 * View
 */

$title = "Atenciones";
$help_url = '';

// Obtener IDs de favoritos del usuario actual
$favorite_ids = ExtConsultation::getFavoriteIds($db, $user->id);

// Build SQL query
$sql = "SELECT DISTINCT";
$sql .= " c.rowid,";
$sql .= " c.fk_soc,";
$sql .= " c.fk_user,";
$sql .= " c.date_start,";
$sql .= " c.date_end,";
$sql .= " c.tipo_atencion,";
$sql .= " c.datec,";
$sql .= " c.tms,";
$sql .= " c.status,";
$sql .= " c.recurrence_enabled,";
$sql .= " c.recurrence_parent_id,";
$sql .= " s.rowid as socid,";
$sql .= " s.nom as patient_name,";
$sql .= " s.code_client,";
// Subquery para verificar si es favorita
$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites f WHERE f.fk_extcons = c.rowid AND f.fk_user = ".$user->id.") as is_favorite";

$sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons as c";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON c.fk_soc = s.rowid";

// Join para filtrar por usuario asignado (nueva tabla de múltiples usuarios)
if ($search_user > 0) {
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_users as eu ON eu.fk_extcons = c.rowid";
}

$sql .= " WHERE c.entity IN (".getEntity('consultation').")";

// Add search filters to SQL
if ($search_ref !== '' && $search_ref !== null) {
    $sql .= natural_search("c.rowid", $search_ref);
}
if ($search_patient !== '' && $search_patient !== null) {
    $sql .= natural_search(array("s.nom", "s.code_client"), $search_patient);
}
if ($search_type !== '' && $search_type !== null && $search_type !== '-1') {
    $sql .= " AND c.tipo_atencion = '".$db->escape($search_type)."'";
}
if ($search_user > 0) {
    // Buscar en la tabla de múltiples usuarios O en el campo legacy fk_user
    $sql .= " AND (eu.fk_user = ".((int) $search_user)." OR c.fk_user = ".((int) $search_user).")";
}
if ($search_date_start) {
    $sql .= " AND c.date_start >= '".$db->idate($search_date_start)."'";
}
if ($search_date_end) {
    $sql .= " AND c.date_start <= '".$db->idate($search_date_end)."'";
}
if ($socid > 0) {
    $sql .= " AND c.fk_soc = ".((int) $socid);
}
if ($search_status !== '' && $search_status !== null && $search_status !== '-1') {
    $sql .= " AND c.status = ".((int) $search_status);
}
if ($search_favorites_only) {
    $sql .= " AND c.rowid IN (SELECT fk_extcons FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites WHERE fk_user = ".$user->id.")";
}

// Add sorting - FAVORITOS PRIMERO, luego por el campo seleccionado
$sql .= " ORDER BY is_favorite DESC, ".$sortfield." ".$sortorder;

// Count total nb of records
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
    $sqlcount = preg_replace('/SELECT DISTINCT.*FROM/s', 'SELECT COUNT(DISTINCT c.rowid) as total FROM', $sql);
    $sqlcount = preg_replace('/ORDER BY.*$/s', '', $sqlcount);
    $resqlcount = $db->query($sqlcount);
    if ($resqlcount) {
        $objcount = $db->fetch_object($resqlcount);
        $nbtotalofrecords = $objcount->total;
    }
}

// Add limit
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}
$num = $db->num_rows($resql);

// Auto-extender recurrencias si es necesario (lazy generation)
ExtConsultation::extendAllRecurrences($db, $user, $conf->entity);

// Output page
llxHeader('', $title, $help_url);

// Build param string for pagination/sorting URLs
$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage='.urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit='.((int) $limit);
if ($search_ref !== '' && $search_ref !== null) $param .= '&search_ref='.urlencode($search_ref);
if ($search_patient !== '' && $search_patient !== null) $param .= '&search_patient='.urlencode($search_patient);
if ($search_type !== '' && $search_type !== null && $search_type !== '-1') $param .= '&search_type='.urlencode($search_type);
if ($search_user > 0) $param .= '&search_user='.((int) $search_user);
if ($search_date_startday) $param .= '&search_date_startday='.((int) $search_date_startday);
if ($search_date_startmonth) $param .= '&search_date_startmonth='.((int) $search_date_startmonth);
if ($search_date_startyear) $param .= '&search_date_startyear='.((int) $search_date_startyear);
if ($search_date_endday) $param .= '&search_date_endday='.((int) $search_date_endday);
if ($search_date_endmonth) $param .= '&search_date_endmonth='.((int) $search_date_endmonth);
if ($search_date_endyear) $param .= '&search_date_endyear='.((int) $search_date_endyear);
if ($search_status !== '' && $search_status !== null && $search_status !== '-1') $param .= '&search_status='.urlencode($search_status);
if ($search_favorites_only) $param .= '&search_favorites_only=1';
if ($socid > 0) $param .= '&socid='.((int) $socid);
if ($optioncss != '') $param .= '&optioncss='.urlencode($optioncss);

// Form
print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'" name="formfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
if ($socid > 0) print '<input type="hidden" name="socid" value="'.$socid.'">';

// Title and navigation
$title = "Atenciones";
if ($socid > 0) {
    $title .= ' - '.$soc->name;
}

// New button + Export button
$newcardbutton = '';
if ($permtocreate) {
    $url = dol_buildpath('/cabinetmed_extcons/consultations.php', 1).'?action=create';
    if ($socid > 0) $url .= '&socid='.$socid;
    $newcardbutton = dolGetButtonTitle($langs->trans('NewConsultation'), '', 'fa fa-plus-circle', $url, '', $permtocreate);
}
$exporturl = dol_buildpath('/cabinetmed_extcons/export.php', 1);
$newcardbutton .= dolGetButtonTitle('Exportar a Excel', 'Exportar consultas filtradas', 'fa fa-file-excel', $exporturl, '', $permtoread);

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'action', 0, $newcardbutton, '', $limit, 0, 0, 1);

// Search fields table
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter row
print '<tr class="liste_titre_filter">';

// Favorito (icono de filtro)
print '<td class="liste_titre center" style="width: 30px;">';
print '<input type="checkbox" name="search_favorites_only" value="1"'.($search_favorites_only ? ' checked' : '').' title="Solo favoritos">';
print '</td>';

// Ref
print '<td class="liste_titre">';
print '<input type="text" class="flat maxwidth50" name="search_ref" value="'.dol_escape_htmltag($search_ref).'">';
print '</td>';

// Patient
print '<td class="liste_titre">';
print '<input type="text" class="flat maxwidth100" name="search_patient" value="'.dol_escape_htmltag($search_patient).'">';
print '</td>';

// Type - using selectarray with proper selected value
print '<td class="liste_titre">';
$typesarray = ExtConsultation::getTypesArray($langs);
print $form->selectarray('search_type', $typesarray, $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150', 0, '', 0, 1);
print '</td>';

// Date
print '<td class="liste_titre center">';
print '<div class="nowrap">';
print $form->selectDate($search_date_start ? $search_date_start : -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print '</div>';
print '<div class="nowrap">';
print $form->selectDate($search_date_end ? $search_date_end : -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('To'));
print '</div>';
print '</td>';

// User - ahora busca en la tabla de múltiples usuarios
print '<td class="liste_titre">';
print $form->select_dolusers($search_user, 'search_user', 1, '', 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth150');
print '</td>';

// Status
print '<td class="liste_titre center">';
$statusarray = array('-1' => '&nbsp;') + ExtConsultation::getStatusArray();
print $form->selectarray('search_status', $statusarray, ($search_status !== '' ? $search_status : '-1'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100', 0, '', 0, 1);
print '</td>';

// Recurrence filter (empty placeholder)
print '<td class="liste_titre center">';
print '</td>';

// Action column - filter buttons
print '<td class="liste_titre center">';
print $form->showFilterButtons();
print '</td>';

print '</tr>';

// Column titles
print '<tr class="liste_titre">';
print_liste_field_titre('<span style="font-size:1.2em;" title="Favoritos">★</span>', $_SERVER["PHP_SELF"], "", "", $param, 'align="center" style="width:40px;"', $sortfield, $sortorder);
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "c.rowid", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Patient", $_SERVER["PHP_SELF"], "s.nom", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "c.tipo_atencion", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "c.date_start", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre("Encargados", $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "c.status", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre("Recurrencia", $_SERVER["PHP_SELF"], "c.recurrence_enabled", "", $param, 'align="center"', $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER["PHP_SELF"], "", "", $param, 'align="center"', $sortfield, $sortorder);
print '</tr>';

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    if (!$obj) break;

    $consultation = new ExtConsultation($db);
    $consultation->id = $obj->rowid;
    $consultation->tipo_atencion = $obj->tipo_atencion;
    $consultation->status = (int) $obj->status;
    $consultation->recurrence_enabled = (int) $obj->recurrence_enabled;
    $consultation->recurrence_parent_id = $obj->recurrence_parent_id ? (int) $obj->recurrence_parent_id : null;
    $consultation->is_favorite = ($obj->is_favorite > 0);
    
    // Cargar usuarios asignados
    $consultation->fetchAssignedUsers();

    $patient = new Societe($db);
    $patient->id = $obj->socid;
    $patient->name = $obj->patient_name;
    $patient->nom = $obj->patient_name;
    $patient->code_client = $obj->code_client;
    
    // Clase especial para favoritos
    $trclass = 'oddeven';
    if ($consultation->is_favorite) {
        $trclass .= ' favorite-row';
    }
    
    print '<tr class="'.$trclass.'">';
    
    // Favorito (estrella)
    print '<td class="center nowraponall" style="width:40px;">';
    print '<a href="'.$_SERVER["PHP_SELF"].'?action=togglefavorite&id='.$obj->rowid.'&token='.newToken().$param.'" class="favorite-toggle" data-id="'.$obj->rowid.'">';
    if ($consultation->is_favorite) {
        print '<span class="favorite-star is-favorite" title="Quitar de favoritos">★</span>';
    } else {
        print '<span class="favorite-star not-favorite" title="Agregar a favoritos">☆</span>';
    }
    print '</a>';
    print '</td>';
    
    // Ref
    print '<td class="nowraponall">';
    print '<a href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$obj->rowid.'">';
    print 'CONS-'.sprintf("%06d", $obj->rowid);
    print '</a>';
    print '</td>';
    
    // Patient
    print '<td class="tdoverflowmax200">';
    if ($obj->socid > 0) {
        print $patient->getNomUrl(1, 'customer');
    }
    print '</td>';
    
    // Type
    print '<td>';
    print $consultation->getTypeLabel($langs);
    print '</td>';
    
    // Date
    print '<td class="center nowraponall">';
    print dol_print_date($db->jdate($obj->date_start), 'dayhour');
    if ($obj->date_end && $db->jdate($obj->date_end) != $db->jdate($obj->date_start)) {
        print '<br><span class="opacitymedium">'.$langs->trans("To").':</span> '.dol_print_date($db->jdate($obj->date_end), 'dayhour');
    }
    print '</td>';
    
    // Encargados (múltiples usuarios)
    print '<td class="tdoverflowmax200">';
    print $consultation->getAssignedUsersHTML(1);
    print '</td>';

    // Status
    print '<td class="center">';
    print $consultation->getLibStatus(1);
    print '</td>';

    // Recurrence
    print '<td class="center">';
    if ($obj->recurrence_enabled) {
        $child_count = $consultation->countChildRecurrences();
        print '<span title="Recurrente ('.$child_count.' ocurrencias)" style="color:#2196F3;"><i class="fas fa-sync-alt"></i> '.$child_count.'</span>';
    } elseif ($obj->recurrence_parent_id > 0) {
        print '<span title="Ocurrencia de CONS-'.sprintf('%06d', $obj->recurrence_parent_id).'" style="color:#9e9e9e;"><i class="fas fa-link"></i></span>';
    } else {
        print '-';
    }
    print '</td>';

    // Actions
    print '<td class="center nowraponall">';
    if ($permtoread) {
        print '<a class="marginrightonly" href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$obj->rowid.'">';
        print img_picto($langs->trans("Detail"), 'detail');
        print '</a>';
    }
    if ($permtocreate) {
        print '<a class="editfielda marginrightonly" href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$obj->rowid.'&action=edit&token='.newToken().'">';
        print img_edit();
        print '</a>';
    }
    if ($permtodelete) {
        print '<a class="marginleftonly" href="'.dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$obj->rowid.'&action=delete&token='.newToken().'">';
        print img_delete();
        print '</a>';
    }
    print '</td>';
    
    print '</tr>';
    $i++;
}

// No records
if ($num == 0) {
    print '<tr><td colspan="9" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

// CSS para favoritos
print '<style>
.favorite-row {
    background-color: #fffde7 !important;
}
.favorite-star {
    font-size: 1.4em;
    cursor: pointer;
    text-decoration: none;
}
.favorite-star.is-favorite {
    color: #f1c40f;
}
.favorite-star.not-favorite {
    color: #aaa;
}
.favorite-toggle:hover .not-favorite {
    color: #f1c40f;
}
.favorite-toggle:hover .is-favorite {
    color: #aaa;
}
</style>';

// JavaScript para toggle de favoritos con AJAX
print '
<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery(".favorite-toggle").click(function(e) {
        e.preventDefault();
        var link = jQuery(this);
        var id = link.data("id");
        var star = link.find(".favorite-star");
        
        jQuery.ajax({
            url: "'.$_SERVER["PHP_SELF"].'",
            type: "GET",
            data: {
                action: "togglefavorite",
                id: id,
                ajax: 1,
                token: "'.newToken().'"
            },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    if (response.is_favorite) {
                        star.text("★").removeClass("not-favorite").addClass("is-favorite");
                        star.attr("title", "Quitar de favoritos");
                        link.closest("tr").addClass("favorite-row");
                    } else {
                        star.text("☆").removeClass("is-favorite").addClass("not-favorite");
                        star.attr("title", "Agregar a favoritos");
                        link.closest("tr").removeClass("favorite-row");
                    }
                }
            },
            error: function() {
                // Fallback: recargar la página
                window.location.href = link.attr("href");
            }
        });
    });
});
</script>';

llxFooter();
$db->close();
