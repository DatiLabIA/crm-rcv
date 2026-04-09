<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * AJAX endpoint: search suppliers (patients) for Select2 autocomplete.
 *
 * Used by the supplier order create form to avoid loading all patients
 * into a <select> at once (which freezes the browser with 20K records).
 *
 * Actions:
 *   action=search  → returns patients matching `term` (SELECT2-compatible JSON)
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(403);
	exit('Forbidden');
}

// Require authenticated user
if (empty($user->id)) {
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode(array('error' => 'Not authenticated')));
}

header('Content-Type: application/json; charset=utf-8');

$action = GETPOST('action', 'aZ09');

// -------------------------------------------------------------------------
// Mode: search suppliers/patients by term (for Select2 AJAX)
// Returns up to 25 results per page; `more` flag indicates additional pages.
// -------------------------------------------------------------------------
if ($action === 'search') {
	$term   = GETPOST('term', 'alphanohtml');
	$page   = max(1, GETPOSTINT('page'));
	$limit  = 25;
	$offset = ($page - 1) * $limit;

	$sql  = "SELECT s.rowid, s.nom, s.name_alias";
	$sql .= " FROM " . MAIN_DB_PREFIX . "societe s";
	$sql .= " WHERE s.entity IN (" . getEntity('societe') . ")";
	$sql .= " AND s.fournisseur = 1 AND s.status = 1";

	if (!empty($term)) {
		$escapedTerm = $db->escape($term);
		$sql .= " AND (s.nom LIKE '%" . $escapedTerm . "%'";
		$sql .= "   OR s.name_alias LIKE '%" . $escapedTerm . "%')";
	}

	$sql .= " ORDER BY s.nom ASC";
	$sql .= $db->plimit($limit + 1, $offset);

	$resql   = $db->query($sql);
	$results = array();
	$more    = false;

	if ($resql) {
		$i = 0;
		while ($obj = $db->fetch_object($resql)) {
			if ($i < $limit) {
				$text = $obj->nom;
				if (!empty($obj->name_alias)) {
					$text .= ' (' . $obj->name_alias . ')';
				}
				$results[] = array('id' => (int) $obj->rowid, 'text' => $text);
			} else {
				$more = true;
			}
			$i++;
		}
		$db->free($resql);
	}

	echo json_encode(array('results' => $results, 'more' => $more));
	exit;
}

// Unknown action
echo json_encode(array('results' => array(), 'more' => false));
