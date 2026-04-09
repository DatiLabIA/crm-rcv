<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    log.php
 * \ingroup importfill
 * \brief   View import job results and detailed log
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/class/importfill_job.class.php';
require_once __DIR__.'/lib/importfill.lib.php';

// Security check
if (!$user->hasRight('importfill', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array("importfill@importfill", "companies"));

$id = GETPOSTINT('id');
$filterAction = GETPOST('filter_action', 'alpha');
$page = GETPOSTINT('page') ?: 0;
$limit = 100;
$offset = $page * $limit;

if (empty($id)) {
    header('Location: '.dol_buildpath('/importfill/index.php', 1));
    exit;
}

$job = new ImportFillJob($db);
if ($job->fetch($id) <= 0) {
    setEventMessages('Job not found', null, 'errors');
    header('Location: '.dol_buildpath('/importfill/index.php', 1));
    exit;
}

$stats = $job->getStats();
$actionCounts = $job->countLinesByAction();

/*
 * View
 */

$title = $langs->trans('JobLog').' #'.$job->id;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-importfill page-log');

print load_fiche_titre($title, '<a class="button" href="'.dol_buildpath('/importfill/index.php', 1).'">'.$langs->trans('BackToList').'</a>', '');

// Job info card
print '<table class="border centpercent">';
print '<tr><td class="titlefield">Job ID</td><td>'.$job->id.'</td></tr>';
print '<tr><td>'.$langs->trans('Date').'</td><td>'.dol_print_date($job->datec, 'dayhour').'</td></tr>';
print '<tr><td>'.$langs->trans('File').'</td><td>'.dol_escape_htmltag($job->filename_original).'</td></tr>';
print '<tr><td>'.$langs->trans('ImportMode').'</td><td>'.($job->import_mode === 'fill_empty' ? $langs->trans('FillEmptyOnly') : $langs->trans('OverwriteExisting')).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$job->getLibStatut().'</td></tr>';

// Stats row
if (!empty($stats)) {
    print '<tr><td>'.$langs->trans('TotalRows').'</td><td>';
    print '<strong>'.($stats['total'] ?? 0).'</strong> total &nbsp;|&nbsp; ';
    print '<span class="badge badge-status6">'.($stats['created'] ?? 0).' created</span> &nbsp; ';
    print '<span class="badge badge-status4">'.($stats['updated'] ?? 0).' updated</span> &nbsp; ';
    print '<span class="badge badge-status0">'.($stats['skipped'] ?? 0).' skipped</span> &nbsp; ';
    print '<span class="badge badge-status8">'.($stats['errors'] ?? 0).' errors</span>';
    if (isset($stats['start']) && isset($stats['end']) && $stats['end'] > 0) {
        print ' &nbsp;|&nbsp; Duration: '.($stats['end'] - $stats['start']).'s';
    }
    print '</td></tr>';
}

print '</table>';

// Filter tabs
print '<br>';
print '<div class="tabs" style="margin-bottom:10px;">';
$baseUrl = $_SERVER['PHP_SELF'].'?id='.$job->id;
$allClass = empty($filterAction) ? 'tabactive' : 'tabunactive';
print '<a class="tab '.$allClass.'" href="'.$baseUrl.'">All ('.(array_sum($actionCounts)).')</a>';

foreach (array('create', 'update', 'skip', 'error') as $act) {
    $cnt = $actionCounts[$act] ?? 0;
    $actClass = ($filterAction === $act) ? 'tabactive' : 'tabunactive';
    $badge = '';
    switch ($act) {
        case 'create': $badge = 'badge-status6'; break;
        case 'update': $badge = 'badge-status4'; break;
        case 'skip':   $badge = 'badge-status0'; break;
        case 'error':  $badge = 'badge-status8'; break;
    }
    print '<a class="tab '.$actClass.'" href="'.$baseUrl.'&filter_action='.$act.'">'.ucfirst($act).' <span class="badge '.$badge.'">'.$cnt.'</span></a>';
}
print '</div>';

// Log lines
$lines = $job->getLines($filterAction, $limit, $offset);

if (is_array($lines) && !empty($lines)) {
    print '<div class="div-table-responsive">';
    print '<table class="tagtable nobottomiftotal liste">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('Line').'</th>';
    print '<th>n_documento</th>';
    print '<th>'.$langs->trans('Action').'</th>';
    print '<th>'.$langs->trans('ThirdParty').'</th>';
    print '<th>'.$langs->trans('Message').'</th>';
    print '<th>'.$langs->trans('Details').'</th>';
    print '</tr>';

    foreach ($lines as $line) {
        $actionBadge = '';
        switch ($line->action) {
            case 'create':
                $actionBadge = '<span class="badge badge-status6">CREATE</span>';
                break;
            case 'update':
                $actionBadge = '<span class="badge badge-status4">UPDATE</span>';
                break;
            case 'skip':
                $actionBadge = '<span class="badge badge-status0">SKIP</span>';
                break;
            case 'error':
                $actionBadge = '<span class="badge badge-status8">ERROR</span>';
                break;
            default:
                $actionBadge = '<span class="badge badge-status0">'.strtoupper($line->action).'</span>';
        }

        print '<tr class="oddeven">';
        print '<td>'.$line->line_num.'</td>';
        print '<td>'.dol_escape_htmltag($line->key_value ?: '-').'</td>';
        print '<td>'.$actionBadge.'</td>';

        // Third party link
        print '<td>';
        if ($line->fk_societe > 0) {
            $societe = new Societe($db);
            if ($societe->fetch($line->fk_societe) > 0) {
                print $societe->getNomUrl(1);
            } else {
                print '#'.$line->fk_societe;
            }
        } else {
            print '-';
        }
        print '</td>';

        // Message
        print '<td>'.dol_escape_htmltag($line->message).'</td>';

        // Payload
        print '<td>';
        if (!empty($line->payload_json)) {
            $payload = json_decode($line->payload_json, true);
            if (is_array($payload)) {
                $parts = array();
                foreach ($payload as $k => $v) {
                    $parts[] = '<span class="opacitymedium">'.$k.'</span>='.dol_trunc($v, 30);
                }
                print '<small>'.implode(', ', $parts).'</small>';
            }
        } else {
            print '-';
        }
        print '</td>';

        print '</tr>';
    }

    print '</table>';
    print '</div>';

    // Pagination
    $totalLines = array_sum($actionCounts);
    if ($filterAction) {
        $totalLines = $actionCounts[$filterAction] ?? 0;
    }
    if ($totalLines > $limit) {
        print '<div class="center" style="margin-top:10px;">';
        if ($page > 0) {
            print '<a class="button" href="'.$baseUrl.'&filter_action='.$filterAction.'&page='.($page - 1).'">&larr; Previous</a> ';
        }
        print 'Page '.($page + 1).' of '.ceil($totalLines / $limit);
        if (($page + 1) * $limit < $totalLines) {
            print ' <a class="button" href="'.$baseUrl.'&filter_action='.$filterAction.'&page='.($page + 1).'">Next &rarr;</a>';
        }
        print '</div>';
    }
} else {
    print '<div class="opacitymedium" style="padding:20px;">';
    if ($job->status === 'draft' || $job->status === 'mapped') {
        print 'This job has not been executed yet.';
        if ($job->status === 'mapped') {
            print ' <a class="butAction" href="'.dol_buildpath('/importfill/preview.php', 1).'?id='.$job->id.'">'.$langs->trans('Preview').'</a>';
        }
    } else {
        print 'No log entries found for the selected filter.';
    }
    print '</div>';
}

llxFooter();
$db->close();
