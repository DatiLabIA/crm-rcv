<?php
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/gestion/class/diagnostico.class.php');

if (!$user->hasRight('gestion', 'read')) accessforbidden();
$langs->load("gestion@gestion");

$action = GETPOST('action', 'aZ09'); $id = GETPOST('id', 'int'); $cancel = GETPOST('cancel', 'alpha');
$object = new Diagnostico($db);
if ($id > 0) $object->fetch($id);
if ($cancel) { header("Location: list.php"); exit; }

if ($action == 'add' && $user->hasRight('gestion', 'write')) {
    $object->codigo = GETPOST('codigo', 'alphanohtml'); $object->label = GETPOST('label', 'alphanohtml'); $object->description = GETPOST('description', 'restricthtml');
    if (empty($object->codigo) || empty($object->label)) { setEventMessages("Código y Nombre son obligatorios", null, 'errors'); $action = 'create'; }
    else { if ($object->create($user) > 0) { header("Location: list.php"); exit; } else { setEventMessages($object->error, null, 'errors'); $action = 'create'; } }
}
if ($action == 'update' && $user->hasRight('gestion', 'write')) {
    $object->codigo = GETPOST('codigo', 'alphanohtml'); $object->label = GETPOST('label', 'alphanohtml'); $object->description = GETPOST('description', 'restricthtml');
    if ($object->update($user) > 0) { header("Location: card.php?id=".$object->id); exit; } else { setEventMessages($object->error, null, 'errors'); $action = 'edit'; }
}
if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('gestion', 'delete')) { if ($object->delete($user) > 0) { header("Location: list.php"); exit; } }

$form = new Form($db);
llxHeader('', 'Diagnóstico', '');
if ($action == 'delete') print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, 'Eliminar', '¿Confirmar eliminación?', 'confirm_delete', '', 0, 1);

if ($action == 'create' || $action == 'edit') {
    print load_fiche_titre(($action == 'create') ? 'Nuevo Diagnóstico' : 'Editar Diagnóstico', '', 'generic');
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfieldcreate">';
    print '<tr><td class="titlefieldcreate fieldrequired">Código (CIE-10)</td><td><input type="text" class="flat minwidth150" name="codigo" value="'.dol_escape_htmltag(GETPOSTISSET('codigo') ? GETPOST('codigo') : $object->codigo).'" autofocus></td></tr>';
    print '<tr><td class="fieldrequired">Nombre</td><td><input type="text" class="flat minwidth400" name="label" value="'.dol_escape_htmltag(GETPOSTISSET('label') ? GETPOST('label') : $object->label).'"></td></tr>';
    print '<tr><td>Descripción</td><td><textarea class="flat minwidth400" name="description" rows="3">'.(GETPOSTISSET('description') ? GETPOST('description') : $object->description).'</textarea></td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="center"><input type="submit" class="button button-save" value="Guardar"> &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="Cancelar"></div></form>';
} else {
    print load_fiche_titre($object->codigo.' - '.$object->label, '', 'generic');
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">Código</td><td>'.dol_escape_htmltag($object->codigo).'</td></tr>';
    print '<tr><td>Nombre</td><td>'.dol_escape_htmltag($object->label).'</td></tr>';
    print '<tr><td>Descripción</td><td>'.dol_escape_htmltag($object->description).'</td></tr>';
    print '<tr><td>Fecha creación</td><td>'.dol_print_date($object->datec, 'dayhour').'</td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="tabsAction">';
    if ($user->hasRight('gestion', 'write')) print '<a class="butAction" href="?id='.$object->id.'&action=edit">Modificar</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a class="butActionDelete" href="?id='.$object->id.'&action=delete&token='.newToken().'">Eliminar</a>';
    print '</div>';
}
llxFooter(); $db->close();
