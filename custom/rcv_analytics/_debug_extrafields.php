<?php
// Temporal: muestra definición de extrafields de societe - BORRAR DESPUÉS
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include fails");
if (!$user->admin) accessforbidden();

print '<pre>';
$sql = "SELECT name, type, param, label FROM llx_extrafields WHERE elementtype='societe' ORDER BY name ASC";
$res = $db->query($sql);
while ($obj = $db->fetch_object($res)) {
    $p = unserialize($obj->param);
    $options = isset($p['options']) ? $p['options'] : array();
    echo str_pad($obj->name, 35).' ['.$obj->type.'] => '.print_r($options, true)."\n";
}
print '</pre>';
$db->close();
