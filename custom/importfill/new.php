<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    new.php
 * \ingroup importfill
 * \brief   Step 1: Upload CSV + Step 2: Column Mapping
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/class/importfill_job.class.php';
require_once __DIR__.'/class/importfill_mapper.class.php';
require_once __DIR__.'/lib/importfill.lib.php';

// Security check
if (!$user->hasRight('importfill', 'write')) {
    accessforbidden();
}

$langs->loadLangs(array("importfill@importfill"));

// Parameters
$action = GETPOST('action', 'aZ09');
$step = GETPOSTINT('step') ?: 1;
$id = GETPOSTINT('id');

$mapper = new ImportFillMapper($db);
$error = 0;
$errors = array();

/*
 * Actions
 */

// Step 1: Upload CSV
if ($action === 'upload' && !empty($_FILES['csvfile']['name'])) {
    $job = new ImportFillJob($db);
    $job->delimiter_char = GETPOST('delimiter', 'alpha') ?: ',';
    $job->encoding = GETPOST('encoding', 'alpha') ?: 'UTF-8';
    $job->has_header = GETPOSTINT('has_header') ? 1 : 0;
    $job->import_mode = GETPOST('import_mode', 'alpha') ?: 'fill_empty';

    $result = $job->create($user);
    if ($result < 0) {
        setEventMessages($job->error, null, 'errors');
        $error++;
    } else {
        // Store file
        $uploadDir = importfillGetUploadDir($job->id);
        dol_mkdir($uploadDir);

        $originalName = dol_sanitizeFileName($_FILES['csvfile']['name']);
        $destPath = $uploadDir.'/'.$originalName;

        if (dol_move_uploaded_file($_FILES['csvfile']['tmp_name'], $destPath, 1, 0, $_FILES['csvfile']['error']) > 0) {
            $job->filename_original = $originalName;
            $job->filepath = $destPath;
            $job->update($user);

            setEventMessages($langs->trans('UploadSuccess'), null, 'mesgs');

            // Redirect to step 2
            header('Location: '.$_SERVER['PHP_SELF'].'?step=2&id='.$job->id);
            exit;
        } else {
            setEventMessages('File upload failed', null, 'errors');
            $job->delete($user);
            $error++;
        }
    }
}

// Step 2: Save mapping
if ($action === 'savemapping' && $id > 0) {
    $job = new ImportFillJob($db);
    if ($job->fetch($id) > 0) {
        // Build mapping from POST
        $mapping = array();
        $headers = $mapper->parseCSVHeaders($job->filepath, $job->delimiter_char, $job->encoding, $job->has_header);

        if ($headers) {
            foreach ($headers as $idx => $header) {
                $dest = GETPOST('mapping_'.$idx, 'alpha');
                if (!empty($dest) && $dest !== '--') {
                    $mapping[$idx] = $dest;
                }
            }
        }

        // Validate
        $validationErrors = $mapper->validateMapping($mapping);
        if (!empty($validationErrors)) {
            foreach ($validationErrors as $ve) {
                setEventMessages($ve, null, 'errors');
            }
            $error++;
            $step = 2;
        } else {
            $job->mapping_json = json_encode($mapping);
            $job->status = ImportFillJob::STATUS_MAPPED;
            $result = $job->update($user);

            if ($result > 0) {
                setEventMessages($langs->trans('MappingSaved'), null, 'mesgs');
                header('Location: '.dol_buildpath('/importfill/preview.php', 1).'?id='.$job->id);
                exit;
            } else {
                setEventMessages($job->error, null, 'errors');
                $error++;
            }
        }
    }
}

/*
 * View
 */

$title = $langs->trans('ImportFillNewJob');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-importfill page-new');

// Wizard steps indicator
print '<div class="fichecenter">';
print '<div class="underbanner clearboth">';
print '<div class="fichehalfleft">';

$stepLabels = array(
    1 => $langs->trans('UploadCSV'),
    2 => $langs->trans('ColumnMapping'),
    3 => $langs->trans('Preview'),
    4 => $langs->trans('RunImport'),
);

print '<div class="importfill-steps" style="display:flex;gap:10px;margin:15px 0;">';
foreach ($stepLabels as $sNum => $sLabel) {
    $class = ($sNum === $step) ? 'badge badge-status4' : (($sNum < $step) ? 'badge badge-status6' : 'badge badge-status0');
    print '<span class="'.$class.'" style="padding:5px 12px;border-radius:20px;font-size:0.9em;">'.$sNum.'. '.$sLabel.'</span>';
}
print '</div>';

print '</div></div></div>';

/*
 * Step 1: Upload CSV
 */
if ($step === 1) {
    print load_fiche_titre($langs->trans('UploadCSV'), '', '');

    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="upload">';
    print '<input type="hidden" name="step" value="1">';

    print '<table class="border centpercent">';

    // CSV file
    print '<tr><td class="titlefield fieldrequired">'.$langs->trans('SelectCSVFile').'</td>';
    print '<td><input type="file" name="csvfile" accept=".csv,.txt" required></td></tr>';

    // Delimiter
    print '<tr><td>'.$langs->trans('Delimiter').'</td>';
    print '<td><select name="delimiter">';
    print '<option value=",">,  (comma)</option>';
    print '<option value=";">; (semicolon)</option>';
    print '<option value="\t">TAB</option>';
    print '<option value="|">| (pipe)</option>';
    print '</select></td></tr>';

    // Encoding
    print '<tr><td>'.$langs->trans('Encoding').'</td>';
    print '<td><select name="encoding">';
    print '<option value="UTF-8">UTF-8</option>';
    print '<option value="ISO-8859-1">ISO-8859-1 (Latin-1)</option>';
    print '<option value="Windows-1252">Windows-1252</option>';
    print '</select></td></tr>';

    // Header row
    print '<tr><td>'.$langs->trans('HasHeaderRow').'</td>';
    print '<td><input type="checkbox" name="has_header" value="1" checked></td></tr>';

    // Import mode
    print '<tr><td class="fieldrequired">'.$langs->trans('ImportMode').'</td>';
    print '<td>';
    print '<input type="radio" name="import_mode" value="fill_empty" id="mode_fill" checked>';
    print '<label for="mode_fill"> '.$langs->trans('FillEmptyOnly').'</label><br>';
    print '<input type="radio" name="import_mode" value="overwrite" id="mode_overwrite">';
    print '<label for="mode_overwrite"> '.$langs->trans('OverwriteExisting').'</label>';
    print '</td></tr>';

    print '</table>';

    print '<div class="center" style="margin-top:15px;">';
    print '<input type="submit" class="button" value="'.$langs->trans('Next').' →">';
    print '</div>';

    print '</form>';
}

/*
 * Step 2: Column Mapping
 */
if ($step === 2 && $id > 0) {
    $job = new ImportFillJob($db);
    if ($job->fetch($id) <= 0) {
        setEventMessages('Job not found', null, 'errors');
        llxFooter();
        exit;
    }

    $headers = $mapper->parseCSVHeaders($job->filepath, $job->delimiter_char, $job->encoding, $job->has_header);
    $sampleData = $mapper->getSampleData($job->filepath, $job->delimiter_char, $job->encoding, $job->has_header, 3);
    $existingMapping = $job->getMapping();
    $availableFields = $mapper->getAvailableFields();

    print load_fiche_titre($langs->trans('ColumnMapping').' - '.$langs->trans('File').': '.dol_escape_htmltag($job->filename_original), '', '');

    // Show sample data
    if (!empty($sampleData)) {
        print '<div class="opacitymedium" style="margin-bottom:10px;">'.$langs->trans('SampleData').':</div>';
        print '<div class="div-table-responsive" style="margin-bottom:20px;">';
        print '<table class="tagtable nobottomiftotal liste" style="font-size:0.85em;">';
        print '<tr class="liste_titre">';
        if ($headers) {
            foreach ($headers as $h) {
                print '<th>'.dol_escape_htmltag($h).'</th>';
            }
        }
        print '</tr>';
        foreach ($sampleData as $row) {
            print '<tr class="oddeven">';
            foreach ($row as $cell) {
                print '<td>'.dol_escape_htmltag(dol_trunc($cell, 50)).'</td>';
            }
            print '</tr>';
        }
        print '</table></div>';
    }

    // Mapping form
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="savemapping">';
    print '<input type="hidden" name="step" value="2">';
    print '<input type="hidden" name="id" value="'.$job->id.'">';

    print '<table class="border centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('CSVColumn').'</th>';
    print '<th>'.$langs->trans('SampleData').'</th>';
    print '<th class="fieldrequired">'.$langs->trans('Destination').'</th>';
    print '</tr>';

    if ($headers) {
        foreach ($headers as $idx => $header) {
            print '<tr class="oddeven">';

            // CSV column name
            print '<td><strong>'.dol_escape_htmltag($header).'</strong></td>';

            // Sample value
            $sampleVal = isset($sampleData[0][$idx]) ? dol_trunc($sampleData[0][$idx], 40) : '';
            print '<td class="opacitymedium">'.dol_escape_htmltag($sampleVal).'</td>';

            // Destination select
            $currentMapping = isset($existingMapping[$idx]) ? $existingMapping[$idx] : '';

            print '<td>';
            print '<select name="mapping_'.$idx.'" class="flat minwidth300">';
            print '<option value="--">'.$langs->trans('NotMapped').'</option>';

            // n_documento (special - always first)
            $sel = ($currentMapping === 'extra.n_documento') ? ' selected' : '';
            print '<option value="extra.n_documento"'.$sel.'>★ '.$langs->trans('NDocumento').'</option>';

            // Core fields
            print '<optgroup label="'.$langs->trans('CoreField').'">';
            foreach ($availableFields['core'] as $fname => $finfo) {
                $val = 'core.'.$fname;
                $sel = ($currentMapping === $val) ? ' selected' : '';
                print '<option value="'.$val.'"'.$sel.'>'.$finfo['label'].' ('.$fname.')</option>';
            }
            print '</optgroup>';

            // Extra fields
            if (!empty($availableFields['extra'])) {
                print '<optgroup label="'.$langs->trans('ExtraField').'">';
                foreach ($availableFields['extra'] as $ename => $einfo) {
                    if ($ename === 'n_documento') continue; // Already shown
                    $val = 'extra.'.$ename;
                    $sel = ($currentMapping === $val) ? ' selected' : '';
                    print '<option value="'.$val.'"'.$sel.'>'.$einfo['label'].' ('.$ename.')</option>';
                }
                print '</optgroup>';
            }

            print '</select>';
            print '</td>';

            print '</tr>';
        }
    }

    print '</table>';

    print '<div class="center" style="margin-top:15px;">';
    print '<a class="button" href="'.dol_buildpath('/importfill/index.php', 1).'">'.$langs->trans('Cancel').'</a> ';
    print '<input type="submit" class="button button-save" value="'.$langs->trans('Next').' → '.$langs->trans('Preview').'">';
    print '</div>';

    print '</form>';
}

llxFooter();
$db->close();
