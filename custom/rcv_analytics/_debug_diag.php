<?php
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");
if (!$user->admin) die("Admin only");

header('Content-Type: text/plain; charset=utf-8');
$p = MAIN_DB_PREFIX;
$entity = (int)$conf->entity;

echo "=== 1. Filas en gestion_diagnostico ===\n";
$r = $db->query("SELECT rowid, codigo, label, description FROM {$p}gestion_diagnostico LIMIT 10");
if ($r) { while ($o = $db->fetch_object($r)) echo "  rowid={$o->rowid} codigo={$o->codigo} label={$o->label}\n"; }
else echo "  ERROR: " . $db->lasterror() . "\n";

echo "\n=== 2. Columnas en societe_extrafields ===\n";
$r = $db->query("SHOW COLUMNS FROM {$p}societe_extrafields LIKE '%diag%'");
if ($r) { while ($o = $db->fetch_object($r)) echo "  Field={$o->Field} Type={$o->Type}\n"; }
else echo "  ERROR: " . $db->lasterror() . "\n";

echo "\n=== 3. Muestra de valores se.diagnostico con pacientes ===\n";
$r = $db->query("SELECT se.diagnostico FROM {$p}societe_extrafields se INNER JOIN {$p}societe s ON s.rowid=se.fk_object WHERE s.canvas='patient@cabinetmed' AND s.entity=$entity AND se.diagnostico IS NOT NULL AND se.diagnostico != '' LIMIT 10");
if ($r) { while ($o = $db->fetch_object($r)) echo "  diagnostico=" . var_export($o->diagnostico, true) . "\n"; }
else echo "  ERROR: " . $db->lasterror() . "\n";

echo "\n=== 4. Query FIND_IN_SET ===\n";
$r = $db->query("SELECT ref.rowid, ref.label, COUNT(*) as cnt FROM {$p}gestion_diagnostico ref INNER JOIN {$p}societe_extrafields se ON FIND_IN_SET(CAST(ref.rowid AS CHAR), se.diagnostico) > 0 INNER JOIN {$p}societe s ON s.rowid=se.fk_object WHERE s.canvas='patient@cabinetmed' AND s.entity=$entity GROUP BY ref.rowid, ref.label ORDER BY cnt DESC LIMIT 10");
if ($r) { while ($o = $db->fetch_object($r)) echo "  rowid={$o->rowid} label={$o->label} cnt={$o->cnt}\n"; }
else echo "  ERROR: " . $db->lasterror() . "\n";

$db->close();
