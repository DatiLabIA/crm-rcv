<?php
/**
 * Script temporal: elimina las entradas antiguas del módulo rcv_analytics
 * de llx_menu y fuerza la regeneración al recargar cualquier página.
 * BORRAR este archivo después de ejecutarlo.
 */
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

if (!$user->admin) accessforbidden();

// 1. Borrar entradas viejas del módulo en llx_menu
$sql = "DELETE FROM ".MAIN_DB_PREFIX."menu WHERE module = 'rcv_analytics'";
$db->query($sql);
$deleted = $db->affected_rows($sql);

// 2. También borrar por url que contenga rcv_analytics
$sql2 = "DELETE FROM ".MAIN_DB_PREFIX."menu WHERE url LIKE '%rcv_analytics%'";
$db->query($sql2);
$deleted2 = $db->affected_rows($sql2);

// 3. Forzar recarga del módulo para que inserte las nuevas entradas
$modfile = dol_buildpath('/custom/rcv_analytics/core/modules/modRcvAnalytics.class.php', 0);
if (file_exists($modfile)) {
    require_once $modfile;
    $mod = new modRcvAnalytics($db);
    // init() llama a _init() que re-inserta el menú
    $result = $mod->init();
    $init_ok = ($result >= 0);
} else {
    $init_ok = false;
}

// Salida
header('Content-Type: text/plain; charset=utf-8');
echo "=== RCV Analytics menu reset ===\n";
echo "Filas eliminadas (por module): $deleted\n";
echo "Filas eliminadas (por url):    $deleted2\n";
echo "Módulo re-inicializado:        ".($init_ok ? 'OK' : 'FALLÓ')." \n";
echo "\nAhora recarga cualquier página de Dolibarr.\n";
echo "ELIMINA este archivo: custom/rcv_analytics/_reset_menu.php\n";

$db->close();
