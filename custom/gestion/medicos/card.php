<?php
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/gestion/class/medico.class.php');

if (!$user->hasRight('gestion', 'read')) accessforbidden();

$action = GETPOST('action', 'aZ09'); $id = GETPOST('id', 'int'); $cancel = GETPOST('cancel', 'alpha');
$object = new Medico($db);
if ($id > 0) $object->fetch($id);
if ($cancel) { header("Location: list.php"); exit; }

if ($action == 'add' && $user->hasRight('gestion', 'write')) {
    $object->ref = GETPOST('ref', 'alphanohtml');
    $object->nombre = GETPOST('nombre', 'alphanohtml');
    $object->tipo_doc = GETPOST('tipo_doc', 'alpha');
    $object->numero_identificacion = GETPOST('numero_identificacion', 'alphanohtml');
    $object->tarjeta_profesional = GETPOST('tarjeta_profesional', 'alphanohtml');
    $object->ciudades = GETPOST('ciudades', 'array');
    $object->departamentos = GETPOST('departamentos', 'array');
    $object->especialidades = GETPOST('especialidades', 'array');
    $object->eps_ids = GETPOST('eps_ids', 'array');

    if (empty($object->nombre)) {
        setEventMessages("El nombre es obligatorio", null, 'errors');
        $action = 'create';
    } else {
        if ($object->create($user) > 0) {
            header("Location: card.php?id=".$object->id);
            exit;
        } else {
            setEventMessages($object->error, null, 'errors');
            $action = 'create';
        }
    }
}
if ($action == 'update' && $user->hasRight('gestion', 'write')) {
    $object->ref = GETPOST('ref', 'alphanohtml');
    $object->nombre = GETPOST('nombre', 'alphanohtml');
    $object->tipo_doc = GETPOST('tipo_doc', 'alpha');
    $object->numero_identificacion = GETPOST('numero_identificacion', 'alphanohtml');
    $object->tarjeta_profesional = GETPOST('tarjeta_profesional', 'alphanohtml');
    $object->ciudades = GETPOST('ciudades', 'array');
    $object->departamentos = GETPOST('departamentos', 'array');
    $object->especialidades = GETPOST('especialidades', 'array');
    $object->eps_ids = GETPOST('eps_ids', 'array');

    if ($object->update($user) > 0) {
        header("Location: card.php?id=".$object->id);
        exit;
    } else {
        setEventMessages($object->error, null, 'errors');
        $action = 'edit';
    }
}
if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('gestion', 'delete')) {
    if ($object->delete($user) > 0) {
        header("Location: list.php");
        exit;
    }
}

$form = new Form($db);
llxHeader('', 'Médico', '');
if ($action == 'delete') print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, 'Eliminar', '¿Confirmar eliminación?', 'confirm_delete', '', 0, 1);

if ($action == 'create' || $action == 'edit') {
    // Recuperar valores del POST en caso de error
    $sel_ciudades = GETPOSTISSET('ciudades') ? GETPOST('ciudades', 'array') : $object->ciudades;
    $sel_departamentos = GETPOSTISSET('departamentos') ? GETPOST('departamentos', 'array') : $object->departamentos;
    $sel_especialidades = GETPOSTISSET('especialidades') ? GETPOST('especialidades', 'array') : $object->especialidades;
    $sel_eps_ids = GETPOSTISSET('eps_ids') ? GETPOST('eps_ids', 'array') : $object->eps_ids;

    print load_fiche_titre(($action == 'create') ? 'Nuevo Médico' : 'Editar Médico', '', 'generic');
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfieldcreate">';
    print '<tr><td class="titlefieldcreate">Referencia</td><td><input type="text" class="flat minwidth100" name="ref" value="'.dol_escape_htmltag(GETPOSTISSET('ref') ? GETPOST('ref') : $object->ref).'"></td></tr>';
    print '<tr><td class="fieldrequired">Nombre completo</td><td><input type="text" class="flat minwidth300" name="nombre" value="'.dol_escape_htmltag(GETPOSTISSET('nombre') ? GETPOST('nombre') : $object->nombre).'" autofocus></td></tr>';
    print '<tr><td>Tipo documento</td><td>'.$object->selectTipoDocumento(GETPOSTISSET('tipo_doc') ? GETPOST('tipo_doc') : $object->tipo_doc).'</td></tr>';
    print '<tr><td>Número identificación</td><td><input type="text" class="flat minwidth150" name="numero_identificacion" value="'.dol_escape_htmltag(GETPOSTISSET('numero_identificacion') ? GETPOST('numero_identificacion') : $object->numero_identificacion).'"></td></tr>';
    print '<tr><td>Tarjeta profesional</td><td><input type="text" class="flat minwidth150" name="tarjeta_profesional" value="'.dol_escape_htmltag(GETPOSTISSET('tarjeta_profesional') ? GETPOST('tarjeta_profesional') : $object->tarjeta_profesional).'"></td></tr>';

    // Departamentos - multiselect
    print '<tr><td>Departamento(s)</td><td>'.$object->selectDepartamentosMulti($sel_departamentos).'</td></tr>';
    // Ciudades - multiselect
    print '<tr><td>Ciudad(es)</td><td>'.$object->selectCiudadesMulti($sel_ciudades).'</td></tr>';
    // EPS - multiselect desde tabla EPS
    print '<tr><td>EPS</td><td>'.$object->selectEpsMulti($sel_eps_ids).'</td></tr>';
    // Especialidades - multiselect
    print '<tr><td>Especialidad(es)</td><td>'.$object->selectEspecialidadesMulti($sel_especialidades).'</td></tr>';

    print '</table>';
    print dol_get_fiche_end();
    print '<div class="center"><input type="submit" class="button button-save" value="Guardar"> &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="Cancelar"></div></form>';

    // Inicializar Select2 en los multiselect
    print '<script type="text/javascript">
    $(document).ready(function() {
        $(".select2-multi").select2({
            width: "100%",
            placeholder: "Seleccione una o más opciones...",
            allowClear: true,
            tags: true,
            tokenSeparators: []
        });
    });
    </script>';

} else {
    // Vista de detalle
    $eps_names = $object->getEpsNames();

    print load_fiche_titre($object->nombre, '', 'generic');
    print dol_get_fiche_head();
    print '<table class="border centpercent tableforfield">';
    if ($object->ref) print '<tr><td class="titlefield">Referencia</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
    print '<tr><td>Nombre</td><td>'.dol_escape_htmltag($object->nombre).'</td></tr>';
    print '<tr><td>Documento</td><td>'.dol_escape_htmltag($object->tipo_doc.' '.$object->numero_identificacion).'</td></tr>';
    print '<tr><td>Tarjeta profesional</td><td>'.dol_escape_htmltag($object->tarjeta_profesional).'</td></tr>';

    // Departamentos
    print '<tr><td>Departamento(s)</td><td>';
    if (!empty($object->departamentos)) {
        foreach ($object->departamentos as $dep) {
            print '<span class="badge badge-status4 badge-status marginrightonly" style="padding: 3px 8px;">'.dol_escape_htmltag($dep).'</span>';
        }
    } else {
        print '<span class="opacitymedium">No definido</span>';
    }
    print '</td></tr>';

    // Ciudades
    print '<tr><td>Ciudad(es)</td><td>';
    if (!empty($object->ciudades)) {
        foreach ($object->ciudades as $ciu) {
            print '<span class="badge badge-status1 badge-status marginrightonly" style="padding: 3px 8px;">'.dol_escape_htmltag($ciu).'</span>';
        }
    } else {
        print '<span class="opacitymedium">No definido</span>';
    }
    print '</td></tr>';

    // EPS
    print '<tr><td>EPS</td><td>';
    if (!empty($eps_names)) {
        foreach ($eps_names as $ename) {
            print '<span class="badge badge-status6 badge-status marginrightonly" style="padding: 3px 8px;">'.dol_escape_htmltag($ename).'</span>';
        }
    } else {
        print '<span class="opacitymedium">No definido</span>';
    }
    print '</td></tr>';

    // Especialidades
    print '<tr><td>Especialidad(es)</td><td>';
    if (!empty($object->especialidades)) {
        foreach ($object->especialidades as $esp) {
            print '<span class="badge badge-status5 badge-status marginrightonly" style="padding: 3px 8px;">'.dol_escape_htmltag($esp).'</span>';
        }
    } else {
        print '<span class="opacitymedium">No definido</span>';
    }
    print '</td></tr>';

    print '<tr><td>Fecha creación</td><td>'.dol_print_date($object->datec, 'dayhour').'</td></tr>';
    print '</table>';
    print dol_get_fiche_end();
    print '<div class="tabsAction">';
    if ($user->hasRight('gestion', 'write')) print '<a class="butAction" href="?id='.$object->id.'&action=edit">Modificar</a>';
    if ($user->hasRight('gestion', 'delete')) print '<a class="butActionDelete" href="?id='.$object->id.'&action=delete&token='.newToken().'">Eliminar</a>';
    print '</div>';
}
llxFooter(); $db->close();
