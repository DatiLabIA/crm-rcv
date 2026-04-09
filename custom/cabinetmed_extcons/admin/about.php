<?php
/* Copyright (C) 2024 Your Company
 * About Page
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array("admin", "cabinetmed_extcons@cabinetmed_extcons"));

if (!$user->admin) accessforbidden();

$page_name = "Acerca de";
llxHeader('', $page_name, '');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">Volver a módulos</a>';
print load_fiche_titre($page_name, $linkback, 'title_setup');

// Tabs
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

print dol_get_fiche_head($head, 'about', "Consultas Externas", -1, 'generic');

print '<div class="aboutpage">';
print '<h3>Consultas Externas</h3>';
print '<p>Versión 1.0.3</p>';
print '<p>Módulo para la gestión de consultas externas médicas y tipos de atención personalizada.</p>';
print '<br>';
print '<h4>Características</h4>';
print '<ul>';
print '<li>Crear y gestionar tipos de consulta personalizados</li>';
print '<li>Definir campos personalizados para cada tipo de consulta</li>';
print '<li>Renderizado dinámico de formularios basado en el tipo de consulta</li>';
print '<li>Soporte para múltiples tipos de campo: texto, área de texto, número, fecha, checkbox, selección, radio</li>';
print '<li>Validación de campos obligatorios</li>';
print '<li>Almacenamiento de datos</li>';
print '</ul>';
print '<br>';
print '<h4>Soporte</h4>';
print '<p>Para soporte, por favor contacte a: soporte@example.com</p>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();