<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    preview.php
 * \ingroup importfill
 * \brief   Step 3: Preview import results before execution
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once __DIR__.'/class/importfill_job.class.php';
require_once __DIR__.'/class/importfill_engine.class.php';
require_once __DIR__.'/class/importfill_mapper.class.php';
require_once __DIR__.'/lib/importfill.lib.php';

// Security check
if (!$user->hasRight('importfill', 'write')) {
    accessforbidden();
}

$langs->loadLangs(array("importfill@importfill"));

$id = GETPOSTINT('id');

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

// Run preview
$engine = new ImportFillEngine($db, $user, $job);
$previewResults = $engine->preview(50);

// Count totals
$mapper = new ImportFillMapper($db);
$totalRows = $mapper->countCSVRows($job->filepath, $job->has_header);

$previewStats = array('create' => 0, 'update' => 0, 'error' => 0, 'unknown' => 0);
foreach ($previewResults as $pr) {
    if (isset($previewStats[$pr['action']])) {
        $previewStats[$pr['action']]++;
    }
}

/*
 * View
 */

$title = $langs->trans('Preview');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-importfill page-preview');

// Wizard steps
$stepLabels = array(
    1 => $langs->trans('UploadCSV'),
    2 => $langs->trans('ColumnMapping'),
    3 => $langs->trans('Preview'),
    4 => $langs->trans('RunImport'),
);
print '<div class="importfill-steps" style="display:flex;gap:10px;margin:15px 0;">';
foreach ($stepLabels as $sNum => $sLabel) {
    $class = ($sNum === 3) ? 'badge badge-status4' : (($sNum < 3) ? 'badge badge-status6' : 'badge badge-status0');
    print '<span class="'.$class.'" style="padding:5px 12px;border-radius:20px;font-size:0.9em;">'.$sNum.'. '.$sLabel.'</span>';
}
print '</div>';

print load_fiche_titre(
    $langs->trans('Preview').' - '.$langs->trans('File').': '.dol_escape_htmltag($job->filename_original),
    '',
    ''
);

// Summary stats
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('TotalRows').'</td><td><strong>'.$totalRows.'</strong></td></tr>';
print '<tr><td>'.$langs->trans('ImportMode').'</td><td>'.($job->import_mode === 'fill_empty' ? $langs->trans('FillEmptyOnly') : $langs->trans('OverwriteExisting')).'</td></tr>';
print '<tr><td>Preview ('.$langs->trans('Created').')</td><td><span class="badge badge-status6">'.$previewStats['create'].'</span></td></tr>';
print '<tr><td>Preview ('.$langs->trans('Updated').')</td><td><span class="badge badge-status4">'.$previewStats['update'].'</span></td></tr>';
print '<tr><td>Preview ('.$langs->trans('Errors').')</td><td><span class="badge badge-status8">'.$previewStats['error'].'</span></td></tr>';
print '</table>';
print '</div>';
print '</div>';

// Preview table
if (!empty($previewResults)) {
    print '<br>';
    print '<div class="div-table-responsive">';
    print '<table class="tagtable nobottomiftotal liste">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('Line').'</th>';
    print '<th>'.$langs->trans('Document').' (n_documento)</th>';
    print '<th>'.$langs->trans('PredictedAction').'</th>';
    print '<th>'.$langs->trans('Details').'</th>';
    print '</tr>';

    foreach ($previewResults as $pr) {
        $actionClass = '';
        $actionIcon = '';
        switch ($pr['action']) {
            case 'create':
                $actionClass = 'badge badge-status6';
                $actionIcon = '➕';
                break;
            case 'update':
                $actionClass = 'badge badge-status4';
                $actionIcon = '✏️';
                break;
            case 'error':
                $actionClass = 'badge badge-status8';
                $actionIcon = '❌';
                break;
            default:
                $actionClass = 'badge badge-status0';
                $actionIcon = '❓';
        }

        print '<tr class="oddeven">';
        print '<td>'.$pr['line'].'</td>';
        print '<td>'.dol_escape_htmltag($pr['documento'] ?: '(empty)').'</td>';
        print '<td><span class="'.$actionClass.'">'.$actionIcon.' '.ucfirst($pr['action']).'</span></td>';
        print '<td class="opacitymedium">'.dol_escape_htmltag($pr['details']).'</td>';
        print '</tr>';
    }

    if ($totalRows > count($previewResults)) {
        print '<tr class="oddeven"><td colspan="4" class="opacitymedium center">... and '.($totalRows - count($previewResults)).' more rows</td></tr>';
    }

    print '</table>';
    print '</div>';
}

// Action buttons
print '<div class="center" style="margin-top:20px;">';
print '<a class="button" href="'.dol_buildpath('/importfill/new.php', 1).'?step=2&id='.$job->id.'">&larr; '.$langs->trans('ColumnMapping').'</a> ';
print '<a class="butAction" href="'.dol_buildpath('/importfill/run.php', 1).'?id='.$job->id.'&token='.newToken().'" onclick="return confirm(\''.$langs->trans('ConfirmRunImport').'\');">'.$langs->trans('StartImport').' →</a>';
print '</div>';

llxFooter();
$db->close();
