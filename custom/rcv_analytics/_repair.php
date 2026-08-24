<?php
/**
 * Diagnóstico y reparación del registro del módulo RCV Analytics.
 *
 * Sirve para dos síntomas típicos:
 *   - El módulo no aparece en el menú superior.
 *   - Sus permisos no salen al editar un grupo de usuarios.
 *
 * Causas que corrige:
 *   1. rights_class antiguo 'rcv_analytics' (con guion bajo). User::hasRight() hace un
 *      isModEnabled($module) previo y la clave real en $conf->modules es 'rcvanalytics',
 *      así que toda condición de permiso evaluaba false (incluso para admin).
 *   2. Colisión de $numero 502200 con custom/rcvrest: los ids de permiso 502201/502202
 *      ya estaban ocupados en llx_rights_def, e insert_permissions() los omite en
 *      silencio. Ahora el módulo usa 502300 -> ids 502301/502302.
 *
 * Uso: abrir esta página como admin para ver el diagnóstico, y pulsar "Reparar" para
 * limpiar las filas antiguas y re-registrar menús y permisos.
 *
 * Se puede borrar este archivo cuando todo funcione.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

if (empty($user->admin)) accessforbidden();

$p      = MAIN_DB_PREFIX;
$entity = (int) $conf->entity;
$action = GETPOST('action', 'aZ09');

// Identificador de permisos antiguo (roto) y nuevo
$OLD_RIGHTS_CLASS = 'rcv_analytics';
$NEW_RIGHTS_CLASS = 'rcvanalytics';

$modfile = dol_buildpath('/custom/rcv_analytics/core/modules/modRcvAnalytics.class.php', 0);
if (!file_exists($modfile)) {
    die("No se encuentra el descriptor del módulo en ".$modfile);
}
require_once $modfile;

$log = array();

/**
 * Ejecuta una sentencia y registra el resultado en el log.
 *
 * @param  DoliDB  $db     Conexión
 * @param  string  $sql    Sentencia SQL
 * @param  string  $label  Etiqueta para el log
 * @param  string[] $log   Log acumulado (por referencia)
 * @return void
 */
function rcvRun($db, $sql, $label, &$log)
{
    $r = $db->query($sql);
    if ($r) {
        $log[] = "OK    ".$label." (".$db->affected_rows($r)." fila/s)";
    } else {
        $log[] = "FALLO ".$label.": ".$db->lasterror();
    }
}

/**
 * Imprime una fila de diagnóstico.
 *
 * @param  string    $label Etiqueta
 * @param  string    $value Valor
 * @param  bool|null $ok    true=verde, false=rojo, null=neutro
 * @return void
 */
function rcvRow($label, $value, $ok = null)
{
    $color = ($ok === true ? 'green' : ($ok === false ? 'red' : 'inherit'));
    print '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td>';
    print '<td style="color:'.$color.'"><strong>'.dol_escape_htmltag($value).'</strong></td></tr>';
}

if ($action == 'repair') {
    // ---------------------------------------------------------------------
    // FASE 1: limpieza, en su propia transacción y con commit inmediato.
    //
    // Va aparte a propósito: _init() abre su propia transacción anidada y hace
    // rollback si insert_menus() falla. Si la limpieza compartiera transacción
    // con init(), ese rollback restauraría las filas viejas y el error
    // "Menu entry (...) already exists" se repetiría en cada intento.
    // ---------------------------------------------------------------------
    $log[] = "-- FASE 1: limpieza de registros antiguos --";
    $db->begin();
    $cleanerr = 0;

    // 1a. Quitar asignaciones a usuarios/grupos de los permisos antiguos
    $idlist = array();
    $r = $db->query("SELECT id FROM ".$p."rights_def WHERE module = '".$db->escape($OLD_RIGHTS_CLASS)."' AND entity IN (0, ".$entity.")");
    if ($r) {
        while ($o = $db->fetch_object($r)) {
            $idlist[] = (int) $o->id;
        }
    }

    if (count($idlist)) {
        $in = implode(',', $idlist);
        rcvRun($db, "DELETE FROM ".$p."usergroup_rights WHERE fk_id IN (".$in.") AND entity IN (0, ".$entity.")", "Limpiar usergroup_rights antiguos (".$in.")", $log);
        rcvRun($db, "DELETE FROM ".$p."user_rights WHERE fk_id IN (".$in.") AND entity IN (0, ".$entity.")", "Limpiar user_rights antiguos (".$in.")", $log);
        rcvRun($db, "DELETE FROM ".$p."rights_def WHERE module = '".$db->escape($OLD_RIGHTS_CLASS)."' AND entity IN (0, ".$entity.")", "Borrar rights_def de '".$OLD_RIGHTS_CLASS."'", $log);
    } else {
        $log[] = "INFO  No había permisos antiguos con module='".$OLD_RIGHTS_CLASS."'";
    }

    // 1b. Borrar las entradas de menú generadas por la activación (menu_handler='all').
    //     Las creadas a mano desde Inicio > Configuración > Menús llevan el handler del
    //     gestor activo ('eldy', ...), no 'all', así que no se tocan. Además el control
    //     de duplicados de Menubase::create() sólo mira menu_handler='all', o sea que
    //     con esto basta para que no vuelva a chocar.
    $sqlmenu = "DELETE FROM ".$p."menu WHERE menu_handler = 'all' AND entity IN (0, ".$entity.")";
    $sqlmenu .= " AND (module IN ('".$db->escape($OLD_RIGHTS_CLASS)."', '".$db->escape($NEW_RIGHTS_CLASS)."')";
    $sqlmenu .= " OR url LIKE '%/custom/rcv_analytics/%')";
    rcvRun($db, $sqlmenu, "Borrar menús generados del módulo", $log);

    if (strpos(implode("\n", $log), 'FALLO') === false) {
        $db->commit();
        $log[] = "== COMMIT de la limpieza ==";
    } else {
        $db->rollback();
        $cleanerr = 1;
        $log[] = "== ROLLBACK: la limpieza falló, no se intenta re-registrar ==";
    }

    // ---------------------------------------------------------------------
    // FASE 2: re-registro. init() gestiona su propia transacción.
    // ---------------------------------------------------------------------
    if (!$cleanerr) {
        // Verificación previa: no debe quedar ninguna fila que pueda colisionar
        $left = array();
        $r = $db->query("SELECT rowid, position, url, module, menu_handler FROM ".$p."menu"
                      . " WHERE menu_handler = 'all' AND entity IN (0, ".$entity.")"
                      . " AND url LIKE '%/custom/rcv_analytics/%'");
        if ($r) {
            while ($o = $db->fetch_object($r)) {
                $left[] = "rowid=".$o->rowid." pos=".$o->position." module='".$o->module."' url=".$o->url;
            }
        }
        if (count($left)) {
            $log[] = "FALLO Quedan filas de menú que provocarán colisión:";
            foreach ($left as $l) {
                $log[] = "        ".$l;
            }
        } else {
            $log[] = "-- FASE 2: re-registro del módulo --";
            $mod = new modRcvAnalytics($db);
            // _init() devuelve 1 si OK y 0 si KO (no un negativo)
            $errinit = $mod->init();
            if ($errinit > 0) {
                $log[] = "OK    Módulo re-inicializado (constante, permisos y menús)";
            } else {
                $log[] = "FALLO init(): ".$mod->error;
            }
        }
    }
}

llxHeader('', 'RCV Analytics - Reparación');
print load_fiche_titre('RCV Analytics — diagnóstico y reparación', '', 'stats');

if (count($log)) {
    print '<div class="info"><pre>'.dol_escape_htmltag(implode("\n", $log)).'</pre></div>';
    print '<div class="warning">Recarga esta página (F5) antes de leer el diagnóstico de abajo:'
        . ' $user->rights se carga al inicio de la petición, así que los permisos recién'
        . ' insertados todavía no se ven en este render.</div><br>';
}

print '<table class="noborder centpercent"><tr class="liste_titre"><td>Comprobación</td><td>Resultado</td></tr>';

// Constante de activación
$constval = null;
$r = $db->query("SELECT ".$db->decrypt('value')." as value FROM ".$p."const WHERE ".$db->decrypt('name')." = 'MAIN_MODULE_RCVANALYTICS' AND entity IN (0, ".$entity.")");
if ($r && $db->num_rows($r)) {
    $o = $db->fetch_object($r);
    $constval = $o->value;
}
rcvRow('llx_const MAIN_MODULE_RCVANALYTICS', ($constval === null ? 'NO EXISTE (módulo desactivado)' : $constval), ($constval !== null && $constval != '0'));

rcvRow("isModEnabled('rcvanalytics')", var_export(isModEnabled('rcvanalytics'), true), isModEnabled('rcvanalytics'));
rcvRow("isModEnabled('rcv_analytics') — clave antigua, debe ser false", var_export(isModEnabled('rcv_analytics'), true), !isModEnabled('rcv_analytics'));

// Permisos en rights_def
foreach (array($NEW_RIGHTS_CLASS, $OLD_RIGHTS_CLASS) as $rc) {
    $rows = array();
    $r = $db->query("SELECT id, perms FROM ".$p."rights_def WHERE module = '".$db->escape($rc)."' AND entity IN (0, ".$entity.") ORDER BY id");
    if ($r) {
        while ($o = $db->fetch_object($r)) {
            $rows[] = $o->id.':'.$o->perms;
        }
    }
    $isnew = ($rc === $NEW_RIGHTS_CLASS);
    rcvRow("llx_rights_def module='".$rc."'", (count($rows) ? implode(', ', $rows) : '(ninguno)'), ($isnew ? count($rows) > 0 : count($rows) === 0));
}

// Ids de permiso ocupados por otro módulo en el rango nuevo
$conflicts = array();
$r = $db->query("SELECT id, module FROM ".$p."rights_def WHERE id IN (502301, 502302) AND module <> '".$db->escape($NEW_RIGHTS_CLASS)."' AND entity IN (0, ".$entity.")");
if ($r) {
    while ($o = $db->fetch_object($r)) {
        $conflicts[] = $o->id.' ocupado por '.$o->module;
    }
}
rcvRow('Colisión de ids 502301/502302', (count($conflicts) ? implode(' | ', $conflicts) : 'ninguna'), count($conflicts) === 0);

// Permisos efectivos del usuario actual
rcvRow('$user->hasRight(\'rcvanalytics\',\'read\')', var_export($user->hasRight('rcvanalytics', 'read'), true), (bool) $user->hasRight('rcvanalytics', 'read'));
rcvRow('$user->hasRight(\'rcvanalytics\',\'export\')', var_export($user->hasRight('rcvanalytics', 'export'), true), (bool) $user->hasRight('rcvanalytics', 'export'));

// Menús
$menus = array();
$sqlm = "SELECT rowid, type, module, titre, menu_handler, fk_menu, position, url, enabled, perms FROM ".$p."menu";
$sqlm .= " WHERE (module IN ('".$db->escape($OLD_RIGHTS_CLASS)."', '".$db->escape($NEW_RIGHTS_CLASS)."')";
$sqlm .= " OR url LIKE '%/custom/rcv_analytics/%')";
$sqlm .= " AND entity IN (0, ".$entity.") ORDER BY type DESC, position, rowid";
$r = $db->query($sqlm);
if ($r) {
    while ($o = $db->fetch_object($r)) {
        $menus[] = $o;
    }
}
rcvRow('Entradas en llx_menu', count($menus).' fila/s', count($menus) > 0);

print '</table><br>';

if (count($menus)) {
    print '<table class="noborder centpercent"><tr class="liste_titre">';
    print '<td>rowid</td><td>type</td><td>module</td><td>handler</td><td>fk_menu</td><td>pos</td><td>título</td><td>enabled</td><td>perms</td><td>evalúa a</td></tr>';
    foreach ($menus as $m) {
        $ev = verifCond($m->enabled) && verifCond($m->perms);
        print '<tr class="oddeven">';
        print '<td>'.((int) $m->rowid).'</td>';
        print '<td>'.dol_escape_htmltag($m->type).'</td>';
        print '<td>'.dol_escape_htmltag($m->module).'</td>';
        print '<td>'.dol_escape_htmltag($m->menu_handler).'</td>';
        print '<td>'.((int) $m->fk_menu).'</td>';
        print '<td>'.((int) $m->position).'</td>';
        print '<td>'.dol_escape_htmltag($m->titre).'</td>';
        print '<td><small>'.dol_escape_htmltag($m->enabled).'</small></td>';
        print '<td><small>'.dol_escape_htmltag($m->perms).'</small></td>';
        print '<td style="color:'.($ev ? 'green' : 'red').'"><strong>'.($ev ? 'VISIBLE' : 'OCULTO').'</strong></td>';
        print '</tr>';
    }
    print '</table><br>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="repair">';
print '<input type="submit" class="button" value="Reparar (limpiar filas antiguas y re-registrar)">';
print '</form>';

llxFooter();
$db->close();
