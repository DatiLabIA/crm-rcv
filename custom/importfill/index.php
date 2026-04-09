<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    index.php
 * \ingroup importfill
 * \brief   Import job list
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once __DIR__.'/class/importfill_job.class.php';
require_once __DIR__.'/lib/importfill.lib.php';

// Security check
if (!$user->hasRight('importfill', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array("importfill@importfill"));

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'datec';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$page = GETPOSTINT('page') ?: 0;
$limit = GETPOSTINT('limit') ?: 20;
$offset = $page * $limit;

// Actions
if ($action === 'delete' && $id > 0 && $user->hasRight('importfill', 'write')) {
    $job = new ImportFillJob($db);
    if ($job->fetch($id) > 0) {
        $result = $job->delete($user);
        if ($result > 0) {
            setEventMessages('Job deleted', null, 'mesgs');
        } else {
            setEventMessages($job->error, null, 'errors');
        }
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

/*
 * View
 */

$title = $langs->trans('ImportFillJobList');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-importfill page-index');

print load_fiche_titre($title, '<a class="butAction" href="'.dol_buildpath('/importfill/new.php', 1).'">'.$langs->trans('NewImport').'</a>', 'title_setup');

// Fetch jobs
$jobObj = new ImportFillJob($db);
$jobs = $jobObj->fetchAll($sortfield, $sortorder, $limit, $offset);

if (is_array($jobs)) {
    print '<div class="div-table-responsive">';
    print '<table class="tagtable nobottomiftotal liste">';
    print '<tr class="liste_titre">';
    print_liste_field_titre($langs->trans('JobId'), $_SERVER['PHP_SELF'], 'rowid', '', '', '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'datec', '', '', '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('File'), $_SERVER['PHP_SELF'], 'filename_original', '', '', '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('ImportMode'));
    print_liste_field_titre($langs->trans('Status'));
    print_liste_field_titre($langs->trans('TotalRows'), '', '', '', '', 'class="right"');
    print_liste_field_titre($langs->trans('Created'), '', '', '', '', 'class="right"');
    print_liste_field_titre($langs->trans('Updated'), '', '', '', '', 'class="right"');
    print_liste_field_titre($langs->trans('Errors'), '', '', '', '', 'class="right"');
    print_liste_field_titre($langs->trans('Actions'), '', '', '', '', 'class="right"');
    print '</tr>';

    if (count($jobs) === 0) {
        print '<tr class="oddeven"><td colspan="10" class="opacitymedium">'.$langs->trans('NoJobsFound').'</td></tr>';
    }

    foreach ($jobs as $job) {
        $stats = $job->getStats();

        print '<tr class="oddeven">';

        // ID
        print '<td><a href="'.dol_buildpath('/importfill/log.php', 1).'?id='.$job->id.'">'.$job->id.'</a></td>';

        // Date
        print '<td>'.dol_print_date($job->datec, 'dayhour').'</td>';

        // Filename
        print '<td>'.dol_escape_htmltag($job->filename_original).'</td>';

        // Import mode
        $modeLabel = ($job->import_mode === 'fill_empty') ? $langs->trans('FillEmptyOnly') : $langs->trans('OverwriteExisting');
        print '<td>'.$modeLabel.'</td>';

        // Status
        print '<td>'.$job->getLibStatut().'</td>';

        // Stats
        print '<td class="right">'.(isset($stats['total']) ? $stats['total'] : '-').'</td>';
        print '<td class="right">'.(isset($stats['created']) ? $stats['created'] : '-').'</td>';
        print '<td class="right">'.(isset($stats['updated']) ? $stats['updated'] : '-').'</td>';
        print '<td class="right">'.(isset($stats['errors']) ? '<span class="'.($stats['errors'] > 0 ? 'error' : '').'">'.$stats['errors'].'</span>' : '-').'</td>';

        // Actions
        print '<td class="right nowraponall">';
        if ($job->status === 'mapped') {
            print '<a class="butAction butActionSmall" href="'.dol_buildpath('/importfill/preview.php', 1).'?id='.$job->id.'">'.$langs->trans('Preview').'</a> ';
        }
        if ($job->status === 'done' || $job->status === 'failed') {
            print '<a class="butAction butActionSmall" href="'.dol_buildpath('/importfill/log.php', 1).'?id='.$job->id.'">'.$langs->trans('ViewLog').'</a> ';
        }
        if ($user->hasRight('importfill', 'write')) {
            print '<a class="butActionDelete butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=delete&id='.$job->id.'&token='.newToken().'" onclick="return confirm(\''.$langs->trans('ConfirmDeleteJob').'\');">'.$langs->trans('Delete').'</a>';
        }
        print '</td>';

        print '</tr>';
    }

    print '</table>';
    print '</div>';
} else {
    setEventMessages($jobObj->error, null, 'errors');
}

llxFooter();
$db->close();
