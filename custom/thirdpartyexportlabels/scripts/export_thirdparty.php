<?php
/* Copyright (C) 2026 DatiLab <info@datilab.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file scripts/export_thirdparty.php
 * \brief Export third parties with resolved extrafield labels
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

dol_include_once('/thirdpartyexportlabels/class/exportlabels.class.php');

// Security check
if (empty($user->rights->thirdpartyexportlabels->export) && !$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("thirdpartyexportlabels@thirdpartyexportlabels", "companies"));

$action = GETPOST('action', 'aZ09');

$exportLabels = new ExportLabels($db);

/*
 * Actions
 */

if ($action == 'export') {
    $selected = GETPOST('extrafields', 'array');
    $data = $exportLabels->exportThirdparties($selected);

    if (!empty($exportLabels->error)) {
        setEventMessages('Error: '.$exportLabels->error, null, 'errors');
    } else {
        // Send CSV to browser
        $filename = 'export_terceros_'.date('Y-m-d_His').'.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $exportLabels->writeCSV($data);
        exit;
    }
}

/*
 * View
 */

$page_name = "Exportar Terceros con Labels";
llxHeader('', $page_name);

print load_fiche_titre($page_name, '', 'object_company');

// Get data for form
$extrafields = $exportLabels->getThirdpartyExtrafields();
$mappings = $exportLabels->getAllMappings();

// --- Info Box ---
print '<div class="info">';
print 'Este módulo exporta terceros (clientes/proveedores) incluyendo extrafields. ';
print 'Los campos configurados con mapeo reemplazarán los IDs por etiquetas legibles.';
print '</div>';
print '<br>';

// --- Export Form ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="export">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="wrapcolumntitle center" width="30"><input type="checkbox" id="checkall" onclick="toggleAll(this)"></th>';
print '<th class="wrapcolumntitle">Extrafield</th>';
print '<th class="wrapcolumntitle">Nombre técnico</th>';
print '<th class="wrapcolumntitle">Tipo</th>';
print '<th class="wrapcolumntitle">Mapeo configurado</th>';
print '</tr>';

if (empty($extrafields)) {
    print '<tr><td colspan="5" class="opacitymedium">No hay extrafields definidos para terceros.</td></tr>';
} else {
    foreach ($extrafields as $attrname => $ef) {
        $mapping = isset($mappings[$attrname]) ? $mappings[$attrname] : null;

        print '<tr class="oddeven">';

        // Checkbox
        print '<td class="center">';
        print '<input type="checkbox" name="extrafields[]" value="'.$attrname.'" class="ef_checkbox" checked>';
        print '</td>';

        // Label
        print '<td><strong>'.$ef->label.'</strong></td>';

        // Technical name
        print '<td><code>'.$attrname.'</code></td>';

        // Type
        print '<td>'.$ef->type.'</td>';

        // Mapping status
        print '<td>';
        if (in_array($ef->type, array('select', 'radio'))) {
            print '<span class="badge badge-status4">✓ Auto (opciones en param)</span>';
        } elseif (in_array($ef->type, array('sellist', 'chkbxlst'))) {
            print '<span class="badge badge-status4">✓ Auto ('.$ef->type.')</span>';
        } elseif ($ef->type == 'boolean') {
            print '<span class="badge badge-status4">✓ Auto (Sí/No)</span>';
        } elseif ($ef->type == 'checkbox') {
            print '<span class="badge badge-status4">✓ Auto (multi-checkbox)</span>';
        } elseif (in_array($ef->type, array('date', 'datetime'))) {
            print '<span class="badge badge-status4">✓ Auto (fecha)</span>';
        } elseif ($mapping && $mapping->active) {
            print '<span class="badge badge-status4">✓ '.$mapping->table_name.'.'.$mapping->label_field.'</span>';
        } else {
            print '<span class="badge badge-status8">Valor directo</span>';
            print ' <a href="'.dol_buildpath('/thirdpartyexportlabels/admin/setup.php', 1).'" class="small">Configurar mapeo</a>';
        }
        print '</td>';

        print '</tr>';
    }
}

print '</table>';
print '</div>';

// --- Summary & Export Button ---
print '<br>';
print '<div class="center">';
print '<p class="opacitymedium">Se exportarán los campos base (ID, Nombre, Código cliente, Dirección, etc.) + los extrafields seleccionados.</p>';
print '<input type="submit" class="button" value="Exportar CSV">';
print '</div>';

print '</form>';

// --- Preview Section ---
print '<br><br>';
print load_fiche_titre('Vista previa (primeros 10 registros)', '', '');

$preview_data = $exportLabels->exportThirdparties(array_keys($extrafields));

if (!empty($preview_data['rows'])) {
    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';

    // Headers
    print '<tr class="liste_titre">';
    foreach ($preview_data['headers'] as $h) {
        print '<th>'.dol_escape_htmltag($h).'</th>';
    }
    print '</tr>';

    // Rows (max 10)
    $count = 0;
    foreach ($preview_data['rows'] as $row) {
        if ($count >= 10) break;
        print '<tr class="oddeven">';
        foreach ($row as $cell) {
            print '<td>'.dol_escape_htmltag($cell).'</td>';
        }
        print '</tr>';
        $count++;
    }

    print '</table>';
    print '</div>';

    print '<p class="opacitymedium">Total de registros: '.$preview_data['count'].'</p>';
} else {
    print '<p class="opacitymedium">No se encontraron terceros para exportar.</p>';
}

// JavaScript
print '<script>
function toggleAll(source) {
    var checkboxes = document.querySelectorAll(".ef_checkbox");
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>';

llxFooter();
$db->close();
