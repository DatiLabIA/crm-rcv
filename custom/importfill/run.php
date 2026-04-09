<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    run.php
 * \ingroup importfill
 * \brief   Step 4: Execute the import
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once __DIR__.'/class/importfill_job.class.php';
require_once __DIR__.'/class/importfill_engine.class.php';
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

// Only run mapped or draft jobs
if (!in_array($job->status, array('draft', 'mapped'))) {
    setEventMessages('Job already processed (status: '.$job->status.')', null, 'warnings');
    header('Location: '.dol_buildpath('/importfill/log.php', 1).'?id='.$job->id);
    exit;
}

/*
 * View - show processing page
 */

$title = $langs->trans('RunImport');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-importfill page-run');

// Wizard steps
$stepLabels = array(
    1 => $langs->trans('UploadCSV'),
    2 => $langs->trans('ColumnMapping'),
    3 => $langs->trans('Preview'),
    4 => $langs->trans('RunImport'),
);
print '<div class="importfill-steps" style="display:flex;gap:10px;margin:15px 0;">';
foreach ($stepLabels as $sNum => $sLabel) {
    $class = ($sNum === 4) ? 'badge badge-status4' : 'badge badge-status6';
    print '<span class="'.$class.'" style="padding:5px 12px;border-radius:20px;font-size:0.9em;">'.$sNum.'. '.$sLabel.'</span>';
}
print '</div>';

print load_fiche_titre($langs->trans('RunImport').' - Job #'.$job->id, '', '');

print '<div class="info">';
print $langs->trans('ImportRunning').'<br>';
print $langs->trans('File').': <strong>'.dol_escape_htmltag($job->filename_original).'</strong><br>';
print $langs->trans('ImportMode').': <strong>'.($job->import_mode === 'fill_empty' ? $langs->trans('FillEmptyOnly') : $langs->trans('OverwriteExisting')).'</strong>';
print '</div>';

// Flush output to show progress indicator
if (ob_get_level()) ob_end_flush();
flush();

// Execute import
$engine = new ImportFillEngine($db, $user, $job);
$stats = $engine->processBatch();

// Show results
print '<br>';
if ($job->status === 'done') {
    print '<div class="ok">'.$langs->trans('ImportCompleted').'</div>';
} else {
    print '<div class="error">'.$langs->trans('ImportFailed').'</div>';
}

if (!empty($engine->errors)) {
    foreach ($engine->errors as $err) {
        setEventMessages($err, null, 'errors');
    }
}

// Stats table
print '<br>';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('TotalRows').' Summary</th></tr>';
print '<tr><td class="titlefield">'.$langs->trans('TotalRows').'</td><td><strong>'.$stats['total'].'</strong></td></tr>';
print '<tr><td>'.$langs->trans('Created').'</td><td><span class="badge badge-status6">'.$stats['created'].'</span></td></tr>';
print '<tr><td>'.$langs->trans('Updated').'</td><td><span class="badge badge-status4">'.$stats['updated'].'</span></td></tr>';
print '<tr><td>'.$langs->trans('Skipped').'</td><td><span class="badge badge-status0">'.$stats['skipped'].'</span></td></tr>';
print '<tr><td>'.$langs->trans('Errors').'</td><td><span class="badge badge-status8">'.$stats['errors'].'</span></td></tr>';
if ($stats['start'] && $stats['end']) {
    $duration = $stats['end'] - $stats['start'];
    print '<tr><td>Duration</td><td>'.$duration.' seconds</td></tr>';
}
print '</table>';

// Actions
print '<div class="center" style="margin-top:20px;">';
print '<a class="butAction" href="'.dol_buildpath('/importfill/log.php', 1).'?id='.$job->id.'">'.$langs->trans('ViewLog').'</a> ';
print '<a class="button" href="'.dol_buildpath('/importfill/index.php', 1).'">'.$langs->trans('BackToList').'</a>';
print '</div>';

llxFooter();
$db->close();
