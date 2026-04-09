<?php
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/gestion/class/medicamento.class.php');
dol_include_once('/gestion/class/medicamento_concentracion.class.php');

if (!$user->hasRight('gestion', 'read')) accessforbidden();

$action = GETPOST('action', 'aZ09'); $id = GETPOST('id', 'int'); $lineid = GETPOST('lineid', 'int'); $cancel = GETPOST('cancel', 'alpha');
$object = new Medicamento($db);
if ($id > 0) { $object->fetch($id); $object->fetchConcentraciones(); }
if ($cancel) { header("Location: ".($id > 0 ? "card.php?id=$id" : "list.php")); exit; }

// Acciones sobre el medicamento principal
if ($action == 'add' && $user->hasRight('gestion', 'write')) {
    $object->ref = GETPOST('ref', 'alphanohtml'); $object->etiqueta = GETPOST('etiqueta', 'alphanohtml');
    if (empty($object->ref)) { setEventMessages("La referencia es obligatoria", null, 'errors'); $action = 'create'; }
    else { if ($object->create($user) > 0) { header("Location: card.php?id=".$object->id); exit; } else { setEventMessages($object->error, null, 'errors'); $action = 'create'; } }
}
if ($action == 'update' && $user->hasRight('gestion', 'write')) {
    $object->ref = GETPOST('ref', 'alphanohtml'); $object->etiqueta = GETPOST('etiqueta', 'alphanohtml');
    if ($object->update($user) > 0) { header("Location: card.php?id=".$object->id); exit; } else { setEventMessages($object->error, null, 'errors'); $action = 'edit'; }
}
if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('gestion', 'delete')) {
    if ($object->delete($user) > 0) { header("Location: list.php"); exit; }
}

// Acciones sobre concentraciones
if ($action == 'addline' && $user->hasRight('gestion', 'write')) {
    $concentracion = GETPOST('concentracion', 'alphanohtml');
    $unidad = GETPOST('unidad', 'alphanohtml');
    if (!empty($concentracion)) {
        $object->addLine($concentracion, $unidad, $user);
    } else {
        setEventMessages("La concentración es obligatoria", null, 'errors');
    }
    header("Location: card.php?id=".$object->id); exit;
}

if ($action == 'updateline' && $user->hasRight('gestion', 'write') && $lineid > 0) {
    $lineObj = new MedicamentoConcentracion($db);
    if ($lineObj->fetch($lineid) > 0) {
        $lineObj->concentracion = GETPOST('edit_concentracion', 'alphanohtml');
        $lineObj->unidad = GETPOST('edit_unidad', 'alphanohtml');
        if ($lineObj->update($user) > 0) {
            setEventMessages("Concentración actualizada", null, 'mesgs');
        } else {
            setEventMessages($lineObj->error, null, 'errors');
        }
    }
    header("Location: card.php?id=".$object->id); exit;
}

if ($action == 'deleteline' && $user->hasRight('gestion', 'write') && $lineid > 0) {
    $object->deleteLine($lineid, $user);
    header("Location: card.php?id=".$object->id); exit;
}

$editlineid = ($action == 'editline') ? GETPOST('lineid', 'int') : 0;

$form = new Form($db);
llxHeader('', 'Medicamento', '');
if ($action == 'delete') print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, 'Eliminar', '¿Eliminar medicamento y concentraciones?', 'confirm_delete', '', 0, 1);

if ($action == 'create' || $action == 'edit') {
    print load_fiche_titre(($action == 'create') ? 'Nuevo Medicamento' : 'Editar Medicamento', '', 'generic');
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfieldcreate">';
    print '<tr><td class="titlefieldcreate fieldrequired">Referencia</td><td><input type="text" class="flat minwidth200" name="ref" value="'.dol_escape_htmltag(GETPOSTISSET('ref') ? GETPOST('ref') : $object->ref).'" autofocus></td></tr>';
    print '<tr><td>Etiqueta</td><td><input type="text" class="flat minwidth400" name="etiqueta" value="'.dol_escape_htmltag(GETPOSTISSET('etiqueta') ? GETPOST('etiqueta') : $object->etiqueta).'"></td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="center"><input type="submit" class="button button-save" value="Guardar"> &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="Cancelar"></div></form>';
} else {
    // --- Vista de detalle ---
    print load_fiche_titre($object->ref.' - '.$object->etiqueta, '', 'generic');
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">Referencia</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
    print '<tr><td>Etiqueta</td><td>'.dol_escape_htmltag($object->etiqueta).'</td></tr>';
    print '<tr><td>Estado</td><td>'.($object->estado ? '<span class="badge badge-status4">Activo</span>' : '<span class="badge badge-status8">Inactivo</span>').'</td></tr>';
    print '<tr><td>Fecha creación</td><td>'.dol_print_date($object->datec, 'dayhour').'</td></tr>';
    print '</table>';
    print dol_get_fiche_end();

    // --- Sección de Concentraciones ---
    print '<br>'.load_fiche_titre('Concentraciones', '', '');

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Concentración</td><td>Unidad</td><td>Concentración completa</td><td class="right">Acciones</td></tr>';

    // Formulario para agregar nueva concentración
    if ($user->hasRight('gestion', 'write')) {
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="addline">';
        print '<input type="hidden" name="id" value="'.$object->id.'">';
        print '<tr class="oddeven">';
        print '<td><input type="text" class="flat minwidth150" name="concentracion" placeholder="Ej: 500"></td>';
        print '<td>'.MedicamentoConcentracion::selectUnidad('', 'unidad').'</td>';
        print '<td class="opacitymedium"><em>Se genera automáticamente</em></td>';
        print '<td class="right"><input type="submit" class="button buttongen" value="Agregar"></td>';
        print '</tr>';
        print '</form>';
    }

    // Listar concentraciones existentes
    if (count($object->lines) > 0) {
        foreach ($object->lines as $line) {
            if ($editlineid == $line->id) {
                // Modo edición inline
                print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="action" value="updateline">';
                print '<input type="hidden" name="id" value="'.$object->id.'">';
                print '<input type="hidden" name="lineid" value="'.$line->id.'">';
                print '<tr class="oddeven">';
                print '<td><input type="text" class="flat minwidth150" name="edit_concentracion" value="'.dol_escape_htmltag($line->concentracion).'"></td>';
                print '<td>'.MedicamentoConcentracion::selectUnidad($line->unidad, 'edit_unidad').'</td>';
                print '<td class="opacitymedium">'.dol_escape_htmltag($line->getConcentracionDisplay()).'</td>';
                print '<td class="right nowraponall">';
                print '<input type="submit" class="button buttongen smallpaddingimp" value="Guardar">';
                print ' &nbsp; <a href="?id='.$object->id.'">'.img_picto('Cancelar', 'cancel').'</a>';
                print '</td>';
                print '</tr>';
                print '</form>';
            } else {
                // Modo lectura
                $unidadLabel = $line->unidad;
                if (isset(MedicamentoConcentracion::$unidades_medida[$line->unidad])) {
                    $unidadLabel = $line->unidad;
                }
                print '<tr class="oddeven">';
                print '<td>'.dol_escape_htmltag($line->concentracion).'</td>';
                print '<td>'.dol_escape_htmltag($unidadLabel).'</td>';
                print '<td><strong>'.dol_escape_htmltag($line->getConcentracionDisplay()).'</strong></td>';
                print '<td class="right nowraponall">';
                if ($user->hasRight('gestion', 'write')) {
                    print '<a class="paddingright" href="?id='.$object->id.'&action=editline&lineid='.$line->id.'&token='.newToken().'">'.img_edit().'</a>';
                    print '<a href="?id='.$object->id.'&action=deleteline&lineid='.$line->id.'&token='.newToken().'">'.img_delete().'</a>';
                }
                print '</td></tr>';
            }
        }
    } else {
        print '<tr class="oddeven"><td colspan="4" class="opacitymedium">No hay concentraciones registradas</td></tr>';
    }
    print '</table>';

    // Botones de acción
    print '<div class="tabsAction">';
    if ($user->hasRight('gestion', 'write')) print '<a class="butAction" href="?id='.$object->id.'&action=edit">Modificar</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a class="butActionDelete" href="?id='.$object->id.'&action=delete&token='.newToken().'">Eliminar</a>';
    print '</div>';
}
llxFooter(); $db->close();
