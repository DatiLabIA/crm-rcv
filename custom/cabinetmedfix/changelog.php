<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/cabinetmedfix/changelog.php
 * \ingroup cabinetmedfix
 * \brief   Page to display detailed change history for a thirdparty/patient
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

// Load translations
$langs->loadLangs(array('companies', 'other', 'cabinetmedfix@cabinetmedfix'));

// Get parameters
$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');
$page = GETPOSTINT('page');
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$offset = $limit * $page;
$filterfield = GETPOST('filter_field', 'alpha');
$filteruser = GETPOSTINT('filter_user');
$filterdatestart = dol_mktime(0, 0, 0, GETPOSTINT('filter_date_startmonth'), GETPOSTINT('filter_date_startday'), GETPOSTINT('filter_date_startyear'));
$filterdateend = dol_mktime(23, 59, 59, GETPOSTINT('filter_date_endmonth'), GETPOSTINT('filter_date_endday'), GETPOSTINT('filter_date_endyear'));

if (empty($sortfield)) $sortfield = 'c.datec';
if (empty($sortorder)) $sortorder = 'DESC';

// Security check
if ($user->socid > 0) {
	$socid = $user->socid;
}
$result = restrictedArea($user, 'societe', $socid, '&societe');

if (empty($socid)) {
	accessforbidden('Missing socid parameter');
	exit;
}

// Load the thirdparty
$object = new Societe($db);
$result = $object->fetch($socid);
if ($result <= 0) {
	dol_print_error($db, $object->error);
	exit;
}

/*
 * Actions
 */
if ($action === 'export_csv') {
	// Export changelog as CSV
	$filename = 'changelog_' . $object->id . '_' . date('Y-m-d') . '.csv';
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');

	$output = fopen('php://output', 'w');
	// BOM for Excel UTF-8
	fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
	fputcsv($output, array('Fecha', 'Usuario', 'Tipo', 'Campo', 'Valor anterior', 'Valor nuevo', 'IP'), ';');

	$sql = "SELECT c.datec, c.action_type, c.field_name, c.field_label, c.field_type,";
	$sql .= " c.old_value, c.new_value, c.ip_address,";
	$sql .= " u.login, u.firstname, u.lastname";
	$sql .= " FROM " . MAIN_DB_PREFIX . "cabinetmedfix_changelog as c";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON c.fk_user = u.rowid";
	$sql .= " WHERE c.fk_societe = " . ((int) $socid);
	$sql .= " AND c.entity = " . ((int) $conf->entity);
	$sql .= " ORDER BY c.datec DESC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$userName = trim($obj->firstname . ' ' . $obj->lastname);
			if (empty($userName)) $userName = $obj->login;
			$typeLabel = ($obj->field_type === 'extrafield') ? 'Campo personalizado' : 'Campo estándar';
			if ($obj->action_type === 'CREATE') $typeLabel = 'Creación';

			fputcsv($output, array(
				$obj->datec,
				$userName,
				$typeLabel,
				$obj->field_label ?: $obj->field_name,
				$obj->old_value,
				$obj->new_value,
				$obj->ip_address
			), ';');
		}
	}
	fclose($output);
	exit;
}

/*
 * View
 */
$title = $langs->trans('ChangelogTitle') ?: 'Historial de cambios';
$help_url = '';

llxHeader('', $title . ' - ' . $object->name, $help_url);

// Build tabs
$head = societe_prepare_head($object);

print dol_get_fiche_head($head, 'changelog', $langs->trans("ThirdParty"), -1, 'company');

// Thirdparty card header
$linkback = '<a href="' . DOL_URL_ROOT . '/societe/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

dol_banner_tab($object, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

print dol_get_fiche_end();

// --- Filter form ---
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="socid" value="' . $socid . '">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';

print '<div class="div-table-responsive">';
print '<table class="liste nohover centpercent">';
print '<tr class="liste_titre">';

// Date filter
$form = new Form($db);
print '<td class="liste_titre">';
print $langs->trans('DateStart') . ': ';
print $form->selectDate($filterdatestart, 'filter_date_start', 0, 0, 1, '', 1, 0);
print ' - ' . $langs->trans('DateEnd') . ': ';
print $form->selectDate($filterdateend, 'filter_date_end', 0, 0, 1, '', 1, 0);
print '</td>';

// Field filter
print '<td class="liste_titre">';
print '<input type="text" name="filter_field" class="flat maxwidth150" value="' . dol_escape_htmltag($filterfield) . '" placeholder="' . ($langs->trans('Field') ?: 'Campo') . '">';
print '</td>';

// User filter
print '<td class="liste_titre">';
print $form->select_dolusers($filteruser, 'filter_user', 1, '', 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth200');
print '</td>';

// Buttons
print '<td class="liste_titre right">';
print '<button type="submit" class="liste_titre button_search" name="button_search" value="x">';
print '<span class="fa fa-search"></span>';
print '</button>';
print ' <a href="' . $_SERVER['PHP_SELF'] . '?socid=' . $socid . '" class="button buttonreset">';
print '<span class="fa fa-undo"></span>';
print '</a>';
print '</td>';

print '</tr>';
print '</table>';
print '</div>';
print '</form>';

// --- Build SQL Query ---
$sql = "SELECT c.rowid, c.datec, c.action_type, c.field_name, c.field_label, c.field_type,";
$sql .= " c.old_value, c.new_value, c.ip_address,";
$sql .= " u.rowid as user_id, u.login, u.firstname, u.lastname, u.photo";
$sql .= " FROM " . MAIN_DB_PREFIX . "cabinetmedfix_changelog as c";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON c.fk_user = u.rowid";
$sql .= " WHERE c.fk_societe = " . ((int) $socid);
$sql .= " AND c.entity = " . ((int) $conf->entity);

// Apply filters
if (!empty($filterfield)) {
	$sql .= " AND (c.field_label LIKE '%" . $db->escape($filterfield) . "%' OR c.field_name LIKE '%" . $db->escape($filterfield) . "%')";
}
if (!empty($filteruser)) {
	$sql .= " AND c.fk_user = " . ((int) $filteruser);
}
if (!empty($filterdatestart)) {
	$sql .= " AND c.datec >= '" . $db->idate($filterdatestart) . "'";
}
if (!empty($filterdateend)) {
	$sql .= " AND c.datec <= '" . $db->idate($filterdateend) . "'";
}

// Count total
$sqlcount = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "cabinetmedfix_changelog as c";
$sqlcount .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON c.fk_user = u.rowid";
$sqlcount .= " WHERE c.fk_societe = " . ((int) $socid);
$sqlcount .= " AND c.entity = " . ((int) $conf->entity);
if (!empty($filterfield)) {
	$sqlcount .= " AND (c.field_label LIKE '%" . $db->escape($filterfield) . "%' OR c.field_name LIKE '%" . $db->escape($filterfield) . "%')";
}
if (!empty($filteruser)) {
	$sqlcount .= " AND c.fk_user = " . ((int) $filteruser);
}
if (!empty($filterdatestart)) {
	$sqlcount .= " AND c.datec >= '" . $db->idate($filterdatestart) . "'";
}
if (!empty($filterdateend)) {
	$sqlcount .= " AND c.datec <= '" . $db->idate($filterdateend) . "'";
}
$resqlcount = $db->query($sqlcount);
$totalrecords = 0;
if ($resqlcount) {
	$objcount = $db->fetch_object($resqlcount);
	$totalrecords = $objcount->total;
}

// Add sort and limit
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

// --- Toolbar ---
print '<div class="tabsAction">';
print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?socid=' . $socid . '&action=export_csv">';
print '<span class="fa fa-download"></span> ' . ($langs->trans('ExportCSV') ?: 'Exportar CSV');
print '</a>';
print '</div>';

// --- Count summary ---
// Group changes by timestamp for grouped display
$sqlGrouped = "SELECT DATE_FORMAT(c.datec, '%Y-%m-%d %H:%i:%s') as change_date, COUNT(*) as nb_changes";
$sqlGrouped .= " FROM " . MAIN_DB_PREFIX . "cabinetmedfix_changelog as c";
$sqlGrouped .= " WHERE c.fk_societe = " . ((int) $socid);
$sqlGrouped .= " AND c.entity = " . ((int) $conf->entity);
$sqlGrouped .= " GROUP BY change_date ORDER BY change_date DESC LIMIT 1";
$resqlGrouped = $db->query($sqlGrouped);
if ($resqlGrouped && $db->num_rows($resqlGrouped) > 0) {
	$objGrouped = $db->fetch_object($resqlGrouped);
	print '<div class="info">';
	print ($langs->trans('ChangelogTotalRecords', $totalrecords) ?: 'Total: ' . $totalrecords . ' cambios registrados') . '. ';
	print ($langs->trans('ChangelogLastChange', dol_print_date($db->jdate($objGrouped->change_date), 'dayhour'), $objGrouped->nb_changes) ?: 'Último cambio: ' . dol_print_date($db->jdate($objGrouped->change_date), 'dayhour') . ' (' . $objGrouped->nb_changes . ' campos)');
	print '</div>';
}

// --- Results table ---
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);

print_barre_liste(
	$title . ' <span class="badge">' . $totalrecords . '</span>',
	$page,
	$_SERVER['PHP_SELF'],
	'&socid=' . $socid,
	$sortfield,
	$sortorder,
	'',
	$num,
	$totalrecords,
	'',
	0,
	'',
	'',
	$limit
);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Table header
print '<tr class="liste_titre">';
print_liste_field_titre(
	$langs->trans('Date') ?: 'Fecha',
	$_SERVER['PHP_SELF'],
	'c.datec',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print_liste_field_titre(
	$langs->trans('User') ?: 'Usuario',
	$_SERVER['PHP_SELF'],
	'u.lastname',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print_liste_field_titre(
	$langs->trans('Action') ?: 'Acción',
	$_SERVER['PHP_SELF'],
	'c.action_type',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print_liste_field_titre(
	$langs->trans('Field') ?: 'Campo',
	$_SERVER['PHP_SELF'],
	'c.field_label',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print_liste_field_titre(
	$langs->trans('ChangelogFieldType') ?: 'Tipo',
	$_SERVER['PHP_SELF'],
	'c.field_type',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print_liste_field_titre(
	$langs->trans('ChangelogOldValue') ?: 'Antes',
	$_SERVER['PHP_SELF'],
	'',
	'',
	'',
	''
);
print_liste_field_titre(
	$langs->trans('ChangelogNewValue') ?: 'Después',
	$_SERVER['PHP_SELF'],
	'',
	'',
	'',
	''
);
print_liste_field_titre(
	'IP',
	$_SERVER['PHP_SELF'],
	'c.ip_address',
	'',
	'&socid=' . $socid,
	'',
	$sortfield,
	$sortorder
);
print '</tr>';

if ($num == 0) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">';
	print $langs->trans('ChangelogNoRecords') ?: 'No hay cambios registrados para este tercero';
	print '</td></tr>';
}

// Track date changes for visual grouping
$lastDate = '';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);

	// Visual separator when the timestamp changes (group of changes)
	$currentDate = dol_print_date($db->jdate($obj->datec), '%Y-%m-%d %H:%M:%S');
	$showSeparator = ($currentDate !== $lastDate && $lastDate !== '');
	$lastDate = $currentDate;

	if ($showSeparator) {
		print '<tr><td colspan="8" style="height: 4px; background-color: #e0e0e0; padding: 0;"></td></tr>';
	}

	print '<tr class="oddeven">';

	// Date
	print '<td class="nowraponall">' . dol_print_date($db->jdate($obj->datec), 'dayhour') . '</td>';

	// User
	print '<td>';
	$userName = trim($obj->firstname . ' ' . $obj->lastname);
	if (empty($userName)) $userName = $obj->login;
	if (!empty($obj->user_id)) {
		$tmpuser = new User($db);
		$tmpuser->id = $obj->user_id;
		$tmpuser->login = $obj->login;
		$tmpuser->firstname = $obj->firstname;
		$tmpuser->lastname = $obj->lastname;
		$tmpuser->photo = $obj->photo;
		print $tmpuser->getNomUrl(-1);
	} else {
		print dol_escape_htmltag($userName);
	}
	print '</td>';

	// Action type
	print '<td>';
	if ($obj->action_type === 'CREATE') {
		print '<span class="badge badge-status4">' . ($langs->trans('Creation') ?: 'Creación') . '</span>';
	} elseif ($obj->action_type === 'MODIFY') {
		print '<span class="badge badge-status1">' . ($langs->trans('Modification') ?: 'Modificación') . '</span>';
	} elseif ($obj->action_type === 'DELETE') {
		print '<span class="badge badge-status8">' . ($langs->trans('Deletion') ?: 'Eliminación') . '</span>';
	} else {
		print dol_escape_htmltag($obj->action_type);
	}
	print '</td>';

	// Field label
	print '<td>';
	$fieldLabel = !empty($obj->field_label) ? $obj->field_label : $obj->field_name;
	print '<strong>' . dol_escape_htmltag($fieldLabel) . '</strong>';
	if ($obj->field_label && $obj->field_name && $obj->field_name !== $obj->field_label && $obj->field_name !== '_all_') {
		print ' <span class="opacitymedium small">(' . dol_escape_htmltag($obj->field_name) . ')</span>';
	}
	print '</td>';

	// Field type
	print '<td>';
	if ($obj->field_type === 'extrafield') {
		print '<span class="badge badge-status6">Extra</span>';
	} elseif ($obj->field_type === 'standard') {
		print '<span class="badge badge-status0">Estándar</span>';
	} else {
		print dol_escape_htmltag($obj->field_type);
	}
	print '</td>';

	// Old value
	print '<td class="tdoverflowmax200">';
	if ($obj->old_value !== null && $obj->old_value !== '') {
		print '<span class="opacitymedium" style="text-decoration: line-through;">';
		print dol_escape_htmltag(dol_trunc($obj->old_value, 100));
		print '</span>';
	} else {
		print '<span class="opacitymedium">(vacío)</span>';
	}
	print '</td>';

	// New value
	print '<td class="tdoverflowmax200">';
	if ($obj->new_value !== null && $obj->new_value !== '') {
		print '<span style="color: #2e7d32; font-weight: bold;">';
		print dol_escape_htmltag(dol_trunc($obj->new_value, 100));
		print '</span>';
	} else {
		print '<span class="opacitymedium">(vacío)</span>';
	}
	print '</td>';

	// IP
	print '<td class="small opacitymedium">' . dol_escape_htmltag($obj->ip_address) . '</td>';

	print '</tr>';

	$i++;
}

print '</table>';
print '</div>';

$db->free($resql);

llxFooter();
$db->close();
