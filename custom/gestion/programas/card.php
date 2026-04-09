<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/gestion/class/programa.class.php');

if (!$user->hasRight('gestion', 'read')) accessforbidden();
$langs->load("gestion@gestion");

$action = GETPOST('action', 'aZ09');
$id = GETPOST('id', 'int');
$cancel = GETPOST('cancel', 'alpha');

$object = new Programa($db);
if ($id > 0) $object->fetch($id);

if ($cancel) { header("Location: list.php"); exit; }

if ($action == 'add' && $user->hasRight('gestion', 'write')) {
    $object->nombre = GETPOST('nombre', 'alphanohtml');
    if (empty($object->nombre)) { setEventMessages($langs->trans("ErrorFieldRequired", "Nombre"), null, 'errors'); $action = 'create'; }
    else { if ($object->create($user) > 0) { setEventMessages($langs->trans("RecordCreatedSuccessfully"), null); header("Location: list.php"); exit; } else { setEventMessages($object->error, null, 'errors'); $action = 'create'; } }
}

if ($action == 'update' && $user->hasRight('gestion', 'write')) {
    $object->nombre = GETPOST('nombre', 'alphanohtml');
    if ($object->update($user) > 0) { setEventMessages($langs->trans("RecordModifiedSuccessfully"), null); header("Location: card.php?id=".$object->id); exit; }
    else { setEventMessages($object->error, null, 'errors'); $action = 'edit'; }
}

if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('gestion', 'delete')) {
    if ($object->delete($user) > 0) { setEventMessages($langs->trans("RecordDeleted"), null); header("Location: list.php"); exit; }
}

$form = new Form($db);
llxHeader('', 'Programa', '');

if ($action == 'delete') print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('Delete'), $langs->trans('ConfirmDeleteRecord'), 'confirm_delete', '', 0, 1);

if ($action == 'create' || $action == 'edit') {
    print load_fiche_titre(($action == 'create') ? $langs->trans("NewRecord") : $langs->trans("EditRecord"), '', 'generic');
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfieldcreate">';
    print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("Name").'</td><td><input type="text" class="flat minwidth300" name="nombre" value="'.dol_escape_htmltag(GETPOSTISSET('nombre') ? GETPOST('nombre') : $object->nombre).'" autofocus></td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"> &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'"></div></form>';
} else {
    print load_fiche_titre($object->nombre, '', 'generic');
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">ID</td><td>'.$object->id.'</td></tr>';
    print '<tr><td>'.$langs->trans("Name").'</td><td>'.dol_escape_htmltag($object->nombre).'</td></tr>';
    print '<tr><td>'.$langs->trans("DateCreation").'</td><td>'.dol_print_date($object->datec, 'dayhour').'</td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="tabsAction">';
    if ($user->hasRight('gestion', 'write')) print '<a class="butAction" href="?id='.$object->id.'&action=edit">'.$langs->trans("Edit").'</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a class="butActionDelete" href="?id='.$object->id.'&action=delete&token='.newToken().'">'.$langs->trans("Delete").'</a>';
    print '</div>';
}
llxFooter(); $db->close();
