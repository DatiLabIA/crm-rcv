<?php
/* Copyright (C) 2024 DatiLab
 * Administración de roles de alcance para RCV Analytics.
 * Permite crear roles (programas + medicamentos) y asignarlos a usuarios,
 * limitando qué métricas/exports puede ver cada usuario.
 * Solo accesible para administradores.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/rcv_analytics/class/rcvanalyticsscope.class.php');
dol_include_once('/rcv_analytics/lib/rcv_analytics.lib.php');

$langs->loadLangs(array("admin", "companies", "users", "rcv_analytics@rcv_analytics"));

// Solo administradores
if (empty($user->admin)) accessforbidden();

// Asegurar que las tablas existen (idempotente)
RcvAnalyticsScope::ensureTables($db);

$form = new Form($db);

$action  = GETPOST('action', 'aZ09');
$id      = GETPOSTINT('id');
$confirm = GETPOST('confirm', 'alpha');

$entity = (int) $conf->entity;

// ── Diccionarios: programas y medicamentos disponibles ─────────────────────
$optProgramas = array();
$resql = $db->query("SELECT rowid, nombre FROM ".MAIN_DB_PREFIX."gestion_programa WHERE entity IN (".getEntity('societe').") ORDER BY nombre ASC");
if ($resql) { while ($o = $db->fetch_object($resql)) { $optProgramas[$o->rowid] = $o->nombre; } $db->free($resql); }

$optMedicamentos = array();
$resql = $db->query("SELECT rowid, etiqueta FROM ".MAIN_DB_PREFIX."gestion_medicamento WHERE entity IN (".getEntity('societe').") ORDER BY etiqueta ASC");
if ($resql) { while ($o = $db->fetch_object($resql)) { $optMedicamentos[$o->rowid] = ($o->etiqueta !== '' && $o->etiqueta !== null) ? $o->etiqueta : ('#'.$o->rowid); } $db->free($resql); }

/*
 * Acciones
 */

// Guardar (crear o actualizar)
if ($action == 'save') {
    $label       = GETPOST('label', 'alphanohtml');
    $all_access  = GETPOST('all_access', 'int') ? 1 : 0;
    $description = GETPOST('description', 'restricthtml');
    $progs       = GETPOST('role_programas', 'array');
    $meds        = GETPOST('role_medicamentos', 'array');
    $users       = GETPOST('role_users', 'array');

    if (empty(trim($label))) {
        setEventMessages('La etiqueta del rol es obligatoria.', null, 'errors');
        $action = ($id > 0) ? 'edit' : 'create';
    } elseif (!$all_access && empty($progs) && empty($meds)) {
        setEventMessages('Debe marcar "Acceso total" o seleccionar al menos un programa o medicamento.', null, 'errors');
        $action = ($id > 0) ? 'edit' : 'create';
    } else {
        if ($id > 0) {
            RcvAnalyticsScope::updateRole($db, $id, $label, $all_access, $description);
        } else {
            $id = RcvAnalyticsScope::createRole($db, $user, $label, $all_access, $description);
        }

        if ($id > 0) {
            // Si es acceso total, no guardamos restricciones de programa/medicamento
            RcvAnalyticsScope::setRoleProgramas($db, $id, $all_access ? array() : (array) $progs);
            RcvAnalyticsScope::setRoleMedicamentos($db, $id, $all_access ? array() : (array) $meds);
            RcvAnalyticsScope::setRoleUsers($db, $id, (array) $users);
            setEventMessages('Rol guardado correctamente.', null, 'mesgs');
            $action = '';
            $id = 0;
        } else {
            setEventMessages('No se pudo guardar el rol.', null, 'errors');
            $action = 'create';
        }
    }
}

// Eliminar
if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0) {
    RcvAnalyticsScope::deleteRole($db, $id);
    setEventMessages('Rol eliminado.', null, 'mesgs');
    $action = '';
    $id = 0;
}

/*
 * Vista
 */

$title = 'Roles y permisos · Analíticas RCV';
llxHeader('', $title, '', '', 0, 0, array(), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'roles', $langs->trans('Analiticas'), -1, 'stats');
rcv_print_inline_styles();

print '<div class="rcv-wrap">';

// Confirmación de borrado
if ($action == 'delete' && $id > 0) {
    print $form->formconfirm(
        $_SERVER["PHP_SELF"].'?id='.$id,
        'Eliminar rol',
        '¿Seguro que desea eliminar este rol? Los usuarios asignados dejarán de estar restringidos por él.',
        'confirm_delete', '', 'no', 0
    );
}

// ── Formulario de creación / edición ───────────────────────────────────────
if ($action == 'create' || $action == 'edit') {
    $curLabel = ''; $curDesc = ''; $curAll = 0;
    $selProgs = array(); $selMeds = array(); $selUsers = array();

    if ($action == 'edit' && $id > 0) {
        $role = RcvAnalyticsScope::getRole($db, $id);
        if ($role) {
            $curLabel = $role['label'];
            $curDesc  = $role['description'];
            $curAll   = (int) $role['all_access'];
            $selProgs = RcvAnalyticsScope::getRoleProgramaIds($db, $id);
            $selMeds  = RcvAnalyticsScope::getRoleMedicamentoIds($db, $id);
            $selUsers = RcvAnalyticsScope::getRoleUserIds($db, $id);
        }
    }
    // Repoblar tras error de validación
    if (GETPOSTISSET('label')) {
        $curLabel = GETPOST('label', 'alphanohtml');
        $curDesc  = GETPOST('description', 'restricthtml');
        $curAll   = GETPOST('all_access', 'int') ? 1 : 0;
        $selProgs = GETPOST('role_programas', 'array');
        $selMeds  = GETPOST('role_medicamentos', 'array');
        $selUsers = GETPOST('role_users', 'array');
    }

    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="save">';
    print '<input type="hidden" name="id" value="'.((int) $id).'">';

    print '<h3>'.($action == 'edit' ? 'Editar rol' : 'Nuevo rol').'</h3>';
    print '<table class="border centpercent">';

    print '<tr><td class="titlefieldcreate fieldrequired">Etiqueta</td><td>';
    print '<input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag($curLabel).'" autofocus>';
    print '</td></tr>';

    print '<tr><td>Descripción</td><td>';
    print '<input type="text" name="description" class="minwidth500" value="'.dol_escape_htmltag($curDesc).'">';
    print '</td></tr>';

    print '<tr><td>Acceso total</td><td>';
    print '<input type="checkbox" id="all_access" name="all_access" value="1"'.($curAll ? ' checked' : '').'> ';
    print '<label for="all_access">Sin restricción: ve todas las métricas de todos los programas y medicamentos</label>';
    print '</td></tr>';

    print '<tr class="rcv-scope-fields"><td>Programas permitidos</td><td>';
    print $form->multiselectarray('role_programas', $optProgramas, (array) $selProgs, 0, 0, 'minwidth400', 0, '400');
    print '<br><small class="opacitymedium">Vacío = sin restricción por programa.</small>';
    print '</td></tr>';

    print '<tr class="rcv-scope-fields"><td>Medicamentos permitidos</td><td>';
    print $form->multiselectarray('role_medicamentos', $optMedicamentos, (array) $selMeds, 0, 0, 'minwidth400', 0, '400');
    print '<br><small class="opacitymedium">Vacío = sin restricción por medicamento.</small>';
    print '</td></tr>';

    print '<tr><td>Usuarios asignados</td><td>';
    print $form->select_dolusers(is_array($selUsers) ? $selUsers : array(), 'role_users', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'minwidth400', 0, 0, true);
    print '<br><small class="opacitymedium">Usuarios cuyas analíticas quedarán limitadas por este rol.</small>';
    print '</td></tr>';

    print '</table>';

    print '<div class="center" style="margin-top:14px">';
    print '<input type="submit" class="button" value="Guardar">';
    print ' &nbsp; <a class="button button-cancel" href="'.$_SERVER["PHP_SELF"].'">Cancelar</a>';
    print '</div>';
    print '</form>';

    // Ocultar campos de alcance cuando "Acceso total" está marcado
    print '<script>
    jQuery(document).ready(function(){
        function toggleScope(){
            if (jQuery("#all_access").prop("checked")) jQuery(".rcv-scope-fields").hide();
            else jQuery(".rcv-scope-fields").show();
        }
        jQuery("#all_access").change(toggleScope);
        toggleScope();
    });
    </script>';

} else {
    // ── Listado de roles ───────────────────────────────────────────────────
    print '<div style="margin:6px 0 12px">';
    print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=create">'.$langs->trans('New').' rol</a>';
    print '</div>';

    print '<div class="rcv-info" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:.9em">';
    print '<strong>ℹ️ Cómo funciona:</strong> un usuario <em>sin</em> ningún rol asignado ve todas las métricas (compatibilidad). ';
    print 'Al asignarle uno o más roles, solo verá los datos de los programas y medicamentos definidos en esos roles (unión de todos ellos). ';
    print 'Los administradores siempre ven todo.';
    print '</div>';

    $roles = RcvAnalyticsScope::getRoles($db, $entity);

    print '<div class="rcv-table-wrapper"><table class="centpercent">';
    print '<tr class="liste_titre">';
    print '<th>Rol</th>';
    print '<th style="text-align:center">Acceso</th>';
    print '<th style="text-align:center">Programas</th>';
    print '<th style="text-align:center">Medicamentos</th>';
    print '<th style="text-align:center">Usuarios</th>';
    print '<th style="text-align:center">Acciones</th>';
    print '</tr>';

    if (empty($roles)) {
        print '<tr><td colspan="6" class="opacitymedium center">No hay roles definidos todavía.</td></tr>';
    } else {
        foreach ($roles as $r) {
            print '<tr class="oddeven">';
            print '<td><a href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$r['rowid'].'"><strong>'.dol_escape_htmltag($r['label']).'</strong></a></td>';
            print '<td style="text-align:center">'.(!empty($r['all_access'])
                ? '<span class="badge badge-status4 badge-status" style="background:#16a34a;color:#fff;padding:2px 8px;border-radius:10px">Total</span>'
                : '<span class="opacitymedium">Restringido</span>').'</td>';
            print '<td style="text-align:center">'.(!empty($r['all_access']) ? '—' : (int) $r['nb_prog']).'</td>';
            print '<td style="text-align:center">'.(!empty($r['all_access']) ? '—' : (int) $r['nb_med']).'</td>';
            print '<td style="text-align:center">'.(int) $r['nb_user'].'</td>';
            print '<td style="text-align:center">';
            print '<a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.$r['rowid'].'" title="Editar">'.img_edit().'</a> ';
            print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$r['rowid'].'" title="Eliminar">'.img_delete().'</a>';
            print '</td>';
            print '</tr>';
        }
    }

    print '</table></div>';
}

print '</div>'; // rcv-wrap

print dol_get_fiche_end();
llxFooter();
$db->close();
