<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require '../../main.inc.php';

if (!$user->hasRight('gestion', 'read')) accessforbidden();
$langs->load("gestion@gestion");

llxHeader('', $langs->trans('GestionArea'), '');
print load_fiche_titre($langs->trans('GestionArea'), '', 'generic');

print '<div class="fichecenter"><div class="fichethirdleft">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('Statistics').'</th></tr>';

$entities = array(
    'Programas' => 'gestion_programa', 'Diagnósticos' => 'gestion_diagnostico',
    'EPS' => 'gestion_eps', 'Medicamentos' => 'gestion_medicamento',
    'Médicos' => 'gestion_medico', 'Operadores' => 'gestion_operador'
);

foreach ($entities as $label => $table) {
    $resql = $db->query("SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX.$table." WHERE entity = ".$conf->entity);
    $total = $resql ? $db->fetch_object($resql)->total : 0;
    print '<tr class="oddeven"><td>'.$label.'</td><td class="right"><span class="badge badge-secondary">'.$total.'</span></td></tr>';
}
print '</table></div></div>';

print '<div class="fichetwothirdright"><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('QuickAccess').'</th></tr>';

$links = array(
    array('/gestion/programas/list.php', 'fa-folder', 'Programas'),
    array('/gestion/diagnosticos/list.php', 'fa-stethoscope', 'Diagnósticos'),
    array('/gestion/eps/list.php', 'fa-hospital', 'EPS'),
    array('/gestion/medicamentos/list.php', 'fa-pills', 'Medicamentos'),
    array('/gestion/medicos/list.php', 'fa-user-md', 'Médicos'),
    array('/gestion/operadores/list.php', 'fa-building', 'Operadores')
);

foreach ($links as $l) {
    print '<tr class="oddeven"><td><a href="'.dol_buildpath($l[0], 1).'"><span class="fas '.$l[1].' paddingright"></span>'.$l[2].'</a></td></tr>';
}
print '</table></div></div></div>';

llxFooter();
$db->close();
