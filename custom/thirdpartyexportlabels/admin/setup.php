<?php
/* Copyright (C) 2026 DatiLab <info@datilab.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file admin/setup.php
 * \brief Admin configuration page for ThirdpartyExportLabels
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';

dol_include_once('/thirdpartyexportlabels/class/exportlabels.class.php');

// Security check
if (!$user->admin) accessforbidden();

// Load translation files
$langs->loadLangs(array("admin", "thirdpartyexportlabels@thirdpartyexportlabels"));

$action = GETPOST('action', 'aZ09');

$exportLabels = new ExportLabels($db);

/*
 * Actions
 */
$message = '';
$messageType = 'mesgs';

if ($action == 'save_mapping') {
    $extrafield_name = GETPOST('extrafield_name', 'alpha');
    $table_name = GETPOST('table_name', 'alpha');
    $key_field = GETPOST('key_field', 'alpha');
    $label_field = GETPOST('label_field', 'alpha');

    if (empty($extrafield_name) || empty($table_name) || empty($key_field) || empty($label_field)) {
        $message = 'Todos los campos son obligatorios';
        $messageType = 'errors';
    } else {
        $result = $exportLabels->saveMapping($extrafield_name, $table_name, $key_field, $label_field);
        if ($result > 0) {
            $message = 'Mapeo guardado correctamente para: '.$extrafield_name;
        } else {
            $message = 'Error al guardar: '.$exportLabels->error;
            $messageType = 'errors';
        }
    }
}

if ($action == 'delete_mapping') {
    $extrafield_name = GETPOST('extrafield_name', 'alpha');
    $result = $exportLabels->deleteMapping($extrafield_name);
    if ($result > 0) {
        $message = 'Mapeo eliminado para: '.$extrafield_name;
    } else {
        $message = 'Error al eliminar: '.$exportLabels->error;
        $messageType = 'errors';
    }
}

/*
 * View
 */
$page_name = "ThirdpartyExportLabels - Configuración";
llxHeader('', $page_name);

print load_fiche_titre($page_name, '', 'title_setup');

if (!empty($message)) {
    if ($messageType == 'errors') {
        setEventMessages($message, null, 'errors');
    } else {
        setEventMessages($message, null, 'mesgs');
    }
}

// Get data
$extrafields = $exportLabels->getThirdpartyExtrafields();
$tables = $exportLabels->getTables();
$mappings = $exportLabels->getAllMappings();

// ---- Configuration Table ----
print '<div class="div-table-responsive-no-min">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_mapping">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="wrapcolumntitle">Extrafield</th>';
print '<th class="wrapcolumntitle">Tipo</th>';
print '<th class="wrapcolumntitle">Tabla origen</th>';
print '<th class="wrapcolumntitle">Campo ID</th>';
print '<th class="wrapcolumntitle">Campo Label</th>';
print '<th class="wrapcolumntitle">Estado</th>';
print '<th class="wrapcolumntitle center">Acciones</th>';
print '</tr>';

if (empty($extrafields)) {
    print '<tr><td colspan="7" class="opacitymedium">No hay extrafields definidos para terceros (societe).</td></tr>';
} else {
    foreach ($extrafields as $attrname => $ef) {
        $mapping = isset($mappings[$attrname]) ? $mappings[$attrname] : null;
        $is_inline_select = in_array($ef->type, array('select', 'radio', 'checkbox'));
        $is_table_ref = in_array($ef->type, array('sellist', 'chkbxlst'));
        $is_boolean = ($ef->type == 'boolean');
        $is_date = in_array($ef->type, array('date', 'datetime'));

        // Parse inline options for preview (select, radio, checkbox types)
        $inline_options = array();
        if ($is_inline_select && !empty($ef->param)) {
            if (is_array($ef->param) && isset($ef->param['options'])) {
                $inline_options = $ef->param['options'];
            } elseif (is_string($ef->param)) {
                $decoded = json_decode($ef->param, true);
                if (is_array($decoded) && isset($decoded['options'])) {
                    $inline_options = $decoded['options'];
                } else {
                    $unserialized = @unserialize($ef->param);
                    if (is_array($unserialized) && isset($unserialized['options'])) {
                        $inline_options = $unserialized['options'];
                    }
                }
            }
            // Filter out empty/null entries (e.g. checkbox default param has {"":null})
            foreach ($inline_options as $k => $v) {
                if ($k === '' && ($v === null || $v === '')) {
                    unset($inline_options[$k]);
                }
            }
        }

        // Parse table reference for sellist/chkbxlst
        $table_ref_info = '';
        if ($is_table_ref && !empty($ef->param)) {
            $ref_opts = array();
            if (is_array($ef->param) && isset($ef->param['options'])) {
                $ref_opts = $ef->param['options'];
            } elseif (is_string($ef->param)) {
                $decoded = json_decode($ef->param, true);
                if (is_array($decoded) && isset($decoded['options'])) {
                    $ref_opts = $decoded['options'];
                } else {
                    $unserialized = @unserialize($ef->param);
                    if (is_array($unserialized) && isset($unserialized['options'])) {
                        $ref_opts = $unserialized['options'];
                    }
                }
            }
            if (!empty($ref_opts)) {
                $table_ref_info = array_keys($ref_opts)[0];
            }
        }

        print '<tr class="oddeven">';

        // Extrafield name & label
        print '<td>';
        print '<strong>'.dol_escape_htmltag($ef->label).'</strong>';
        print '<br><span class="opacitymedium">'.$attrname.'</span>';
        print '</td>';

        // Type
        print '<td>'.$ef->type.'</td>';

        if ($is_inline_select && !empty($inline_options)) {
            // ----- SELECT WITH INLINE OPTIONS: no mapping needed -----
            print '<td colspan="3">';
            print '<span class="opacitymedium">Opciones definidas en el extrafield:</span><br>';
            $preview_count = 0;
            foreach ($inline_options as $k => $v) {
                if ($preview_count >= 5) {
                    print '<span class="opacitymedium">... y '.(count($inline_options) - 5).' más</span>';
                    break;
                }
                $v_display = ($v === null || $v === '') ? '(vacío)' : dol_escape_htmltag($v);
                print '<span class="badge badge-status0" style="margin:1px">'.$k.' → '.$v_display.'</span> ';
                $preview_count++;
            }
            print '</td>';

            // Status
            print '<td>';
            print '<span class="badge badge-status4">Auto (param)</span>';
            print '</td>';

            // Actions
            print '<td class="center opacitymedium">No requiere mapeo</td>';

        } elseif ($is_table_ref) {
            // ----- SELLIST / CHKBXLST: auto-resolved from param table reference -----
            print '<td colspan="3">';
            if (!empty($table_ref_info)) {
                $type_label = ($ef->type == 'chkbxlst') ? 'chkbxlst (multiselect)' : 'sellist';
                print '<span class="opacitymedium">Referencia tabla ('.$type_label.'):</span><br>';
                print '<code>'.dol_escape_htmltag($table_ref_info).'</code>';
            } else {
                print '<span class="opacitymedium">'.$ef->type.' (sin definición detectada)</span>';
            }
            print '</td>';

            // Status
            print '<td>';
            if (!empty($table_ref_info)) {
                print '<span class="badge badge-status4">Auto ('.$ef->type.')</span>';
            } else {
                print '<span class="badge badge-status8">Verificar</span>';
            }
            print '</td>';

            // Actions
            print '<td class="center opacitymedium">No requiere mapeo</td>';

        } elseif ($is_boolean) {
            // ----- BOOLEAN: Sí/No -----
            print '<td colspan="3">';
            print '<span class="opacitymedium">Campo booleano: se exportará como Sí / No</span>';
            print '</td>';

            // Status
            print '<td>';
            print '<span class="badge badge-status4">Auto (boolean)</span>';
            print '</td>';

            // Actions
            print '<td class="center opacitymedium">No requiere mapeo</td>';

        } elseif ($is_date) {
            // ----- DATE / DATETIME: auto-formatted -----
            print '<td colspan="3">';
            $fmt = ($ef->type == 'datetime') ? 'dd/mm/aaaa hh:mm' : 'dd/mm/aaaa';
            print '<span class="opacitymedium">Se formateará como '.$fmt.'</span>';
            print '</td>';

            // Status
            print '<td>';
            print '<span class="badge badge-status4">Auto (fecha)</span>';
            print '</td>';

            // Actions
            print '<td class="center opacitymedium">No requiere mapeo</td>';

        } else {
            // ----- VARCHAR, INT, LINK, OTHER: manual mapping available -----

            // Table selector
            print '<td>';
            print '<select name="table_name" class="flat minwidth200" id="table_'.$attrname.'" onchange="loadFields(\''.$attrname.'\')">';
            print '<option value="">-- Seleccionar tabla --</option>';
            foreach ($tables as $t) {
                $selected = ($mapping && $mapping->table_name == $t) ? ' selected' : '';
                print '<option value="'.$t.'"'.$selected.'>'.$t.'</option>';
            }
            print '</select>';
            print '</td>';

            // Key field selector
            print '<td>';
            print '<select name="key_field" class="flat minwidth150" id="key_field_'.$attrname.'">';
            if ($mapping) {
                print '<option value="'.$mapping->key_field.'" selected>'.$mapping->key_field.'</option>';
            } else {
                print '<option value="">-- Primero seleccione tabla --</option>';
            }
            print '</select>';
            print '</td>';

            // Label field selector
            print '<td>';
            print '<select name="label_field" class="flat minwidth150" id="label_field_'.$attrname.'">';
            if ($mapping) {
                print '<option value="'.$mapping->label_field.'" selected>'.$mapping->label_field.'</option>';
            } else {
                print '<option value="">-- Primero seleccione tabla --</option>';
            }
            print '</select>';
            print '</td>';

            // Status
            print '<td>';
            if ($mapping && $mapping->active) {
                print '<span class="badge badge-status4">Mapeo activo</span>';
            } else {
                print '<span class="badge badge-status8">Sin configurar</span>';
            }
            print '</td>';

            // Actions
            print '<td class="center nowraponall">';
            print '<button type="button" class="butAction small" onclick="saveMapping(\''.$attrname.'\')">Guardar</button>';
            if ($mapping) {
                print ' <a href="'.$_SERVER['PHP_SELF'].'?action=delete_mapping&extrafield_name='.$attrname.'&token='.newToken().'" class="butActionDelete small" onclick="return confirm(\'¿Eliminar mapeo para '.$attrname.'?\')">Eliminar</a>';
            }
            print '</td>';
        }

        print '</tr>';
    }
}

print '</table>';
print '</form>';
print '</div>';

// ---- JavaScript for AJAX ----
print '<script type="text/javascript">
function loadFields(attrname) {
    var table = document.getElementById("table_" + attrname).value;
    if (!table) return;

    var keySelect = document.getElementById("key_field_" + attrname);
    var labelSelect = document.getElementById("label_field_" + attrname);

    keySelect.innerHTML = "<option value=\"\">Cargando...</option>";
    labelSelect.innerHTML = "<option value=\"\">Cargando...</option>";

    fetch("'.dol_buildpath('/thirdpartyexportlabels/ajax/get_fields.php', 1).'?table=" + encodeURIComponent(table) + "&token='.newToken().'")
    .then(function(response) { return response.json(); })
    .then(function(fields) {
        var keyHtml = "";
        var labelHtml = "";

        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            var keySelected = (f === "rowid") ? " selected" : "";
            keyHtml += "<option value=\"" + f + "\"" + keySelected + ">" + f + "</option>";

            var labelSelected = (f === "label" || f === "nom" || f === "name") ? " selected" : "";
            labelHtml += "<option value=\"" + f + "\"" + labelSelected + ">" + f + "</option>";
        }

        keySelect.innerHTML = keyHtml;
        labelSelect.innerHTML = labelHtml;
    })
    .catch(function(err) {
        keySelect.innerHTML = "<option value=\"\">Error</option>";
        labelSelect.innerHTML = "<option value=\"\">Error</option>";
        console.error("Error loading fields:", err);
    });
}

function saveMapping(attrname) {
    var table = document.getElementById("table_" + attrname).value;
    var keyField = document.getElementById("key_field_" + attrname).value;
    var labelField = document.getElementById("label_field_" + attrname).value;

    if (!table || !keyField || !labelField) {
        alert("Por favor complete todos los campos (tabla, campo ID y campo label).");
        return;
    }

    // Build form and submit
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "'.$_SERVER['PHP_SELF'].'";

    var fields = {
        "token": "'.newToken().'",
        "action": "save_mapping",
        "extrafield_name": attrname,
        "table_name": table,
        "key_field": keyField,
        "label_field": labelField
    };

    for (var key in fields) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

// Auto-load fields for already configured mappings on page load
document.addEventListener("DOMContentLoaded", function() {
';

foreach ($extrafields as $attrname => $ef) {
    $mapping = isset($mappings[$attrname]) ? $mappings[$attrname] : null;
    if ($mapping) {
        print '    loadFields("'.$attrname.'");'."\n";
        // After loading, re-select the saved values
        print '    setTimeout(function() {'."\n";
        print '        var ks = document.getElementById("key_field_'.$attrname.'");'."\n";
        print '        var ls = document.getElementById("label_field_'.$attrname.'");'."\n";
        print '        if (ks) ks.value = "'.$mapping->key_field.'";'."\n";
        print '        if (ls) ls.value = "'.$mapping->label_field.'";'."\n";
        print '    }, 500);'."\n";
    }
}

print '});
</script>';

// ---- Help Section ----
print '<br>';
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">Uso</td><td>';
print 'Este módulo permite exportar terceros con sus extrafields, reemplazando los IDs almacenados por etiquetas legibles.<br><br>';
print '<strong>Pasos:</strong><br>';
print '1. Seleccione la tabla de origen que contiene los valores de referencia<br>';
print '2. Seleccione el campo ID (generalmente <code>rowid</code>)<br>';
print '3. Seleccione el campo que contiene la etiqueta visible<br>';
print '4. Haga clic en "Guardar"<br>';
print '5. Use la <a href="'.dol_buildpath('/thirdpartyexportlabels/scripts/export_thirdparty.php', 1).'">página de exportación</a> para descargar el CSV';
print '</td></tr>';
print '</table>';
print '</div>';
print '</div>';

llxFooter();
$db->close();
