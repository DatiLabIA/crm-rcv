<?php
/**
 * Script de diagnóstico y reparación para rcv_analytics.
 * Verifica condiciones del menú en tiempo real.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

if (!$user->admin) accessforbidden();

header('Content-Type: text/plain; charset=utf-8');

$entity = $conf->entity;
$p = MAIN_DB_PREFIX;

echo "=== Diagnóstico rcv_analytics ===\n\n";

// ─── 1. Estado en BD ──────────────────────────────────────────────────────────
echo "── BD ──\n";
$r = $db->query("SELECT name, value FROM {$p}const WHERE name = 'MAIN_MODULE_RCVANALYTICS'");
$o = ($r && $db->num_rows($r)) ? $db->fetch_object($r) : null;
echo "MAIN_MODULE_RCVANALYTICS: ".($o ? $o->value : 'NO EXISTE')."\n";

$r = $db->query("SELECT rowid, type, titre, usertype, enabled, perms FROM {$p}menu WHERE module = 'rcv_analytics' ORDER BY type DESC, rowid");
echo "llx_menu: ".($r ? $db->num_rows($r)." entrada(s)" : "ERROR: ".$db->lasterror())."\n";
if ($r) while ($o = $db->fetch_object($r)) {
    echo "  #{$o->rowid} [{$o->type}] {$o->titre}\n";
    echo "    enabled  = {$o->enabled}\n";
    echo "    perms    = {$o->perms}\n";
    echo "    usertype = {$o->usertype}\n";
}

// ─── 2. Evaluar condiciones en tiempo real ────────────────────────────────────
echo "\n── Evaluación de condiciones ──\n";

// ¿Existe $conf->rcvanalytics?
echo "isset(\$conf->rcvanalytics): ".(isset($conf->rcvanalytics) ? 'SI' : 'NO')."\n";
if (isset($conf->rcvanalytics)) {
    echo "  ->enabled: ".var_export($conf->rcvanalytics->enabled ?? null, true)."\n";
}

// Probar verifCond
$condEnabled = '$conf->rcvanalytics->enabled';
$condPerms   = '$user->hasRight("rcv_analytics", "read")';

$resEnabled = verifCond($condEnabled);
echo "verifCond('$condEnabled') = ".var_export($resEnabled, true)."\n";

$resPerms = verifCond($condPerms);
echo "verifCond(perms read) = ".var_export($resPerms, true)."\n";

// Probar con dol_eval directamente para ver si hay error
$evEnabled = dol_eval($condEnabled, 1, 0, '1');
echo "dol_eval enabled = ".var_export($evEnabled, true)."\n";

$evPerms = dol_eval($condPerms, 1, 0, '1');
echo "dol_eval perms = ".var_export($evPerms, true)."\n";

// Estado del usuario
echo "\n\$user->admin = ".var_export($user->admin, true)."\n";
echo "\$user->socid = ".var_export($user->socid, true)." (0=interno)\n";
echo "\$user->hasRight('rcv_analytics','read') = ".var_export($user->hasRight('rcv_analytics', 'read'), true)."\n";

// isModEnabled
echo "\nisModEnabled('rcvanalytics') = ".var_export(isModEnabled('rcvanalytics'), true)."\n";
echo "isModEnabled('rcv_analytics') = ".var_export(isModEnabled('rcv_analytics'), true)."\n";

// ─── 3. Módulos cargados (verificar si rcvanalytics aparece) ─────────────────
echo "\n── Módulos activos (parcial) ──\n";
$modKeys = array_filter(array_keys((array)$conf->modules), function($k) {
    return strpos($k, 'rcv') !== false || strpos($k, 'cabinet') !== false;
});
echo "Módulos con 'rcv' o 'cabinet' en nombre: ";
echo count($modKeys) ? implode(', ', $modKeys) : "(ninguno)";
echo "\n";

// ─── 4. Simular la consulta SQL que usa menuLoad ──────────────────────────────
echo "\n── SQL de menuLoad para usuario interno (type_user=0) ──\n";
$sqlMenu = "SELECT rowid, type, module, enabled, perms, usertype, menu_handler"
         . " FROM {$p}menu"
         . " WHERE entity IN (0, {$entity})"
         . " AND menu_handler IN ('eldy', 'all')"
         . " AND usertype IN (0, 2)"
         . " AND module = 'rcv_analytics'"
         . " ORDER BY type DESC, position, rowid";
$r = $db->query($sqlMenu);
echo "Entradas que menuLoad vería: ".($r ? $db->num_rows($r) : "ERROR: ".$db->lasterror())."\n";
if ($r) while ($o = $db->fetch_object($r)) {
    echo "  #{$o->rowid} [{$o->type}] handler={$o->menu_handler} usertype={$o->usertype}\n";
}

echo "\n=== Fin diagnóstico ===\n";
echo "Con esta info podemos identificar exactamente por qué no aparece el menú.\n";

$db->close();
