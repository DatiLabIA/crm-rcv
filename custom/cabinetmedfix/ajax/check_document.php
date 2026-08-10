<?php
// ajax/check_document.php

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

$documento = GETPOST('documento', 'alpha');

$result = array('exists' => false);

if (!empty($documento)) {
	global $db;
	
	$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "societe_extrafields WHERE n_documento = '" . $db->escape($documento) . "'";
	
	// Si estamos editando un paciente, debemos ignorar su propio ID
	$current_id = GETPOST('id', 'int');
	$current_socid = GETPOST('socid', 'int');
	$ignore_id = 0;
	if ($current_id > 0) $ignore_id = $current_id;
	elseif ($current_socid > 0) $ignore_id = $current_socid;
	
	if ($ignore_id > 0) {
		$sql .= " AND fk_object != " . (int)$ignore_id;
	}

	$resql = $db->query($sql);
	if ($resql) {
		if ($db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			
			// Get patient info
			$sql2 = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int)$obj->fk_object;
			$resql2 = $db->query($sql2);
			if ($resql2 && $db->num_rows($resql2) > 0) {
				$obj2 = $db->fetch_object($resql2);
				$result['exists'] = true;
				$result['patient_id'] = $obj2->rowid;
				$result['patient_name'] = $obj2->nom;
			} else {
				$result['exists'] = true;
				$result['patient_id'] = $obj->fk_object;
				$result['patient_name'] = 'Desconocido';
			}
		}
		$db->free($resql);
	}
}

header('Content-Type: application/json');
echo json_encode($result);
