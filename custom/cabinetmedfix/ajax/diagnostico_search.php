<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * AJAX endpoint to search diagnostics from llx_gestion_diagnostico.
 * Returns JSON results for Select2 integration.
 */

// Prevent direct access without Dolibarr context
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) {
	http_response_code(403);
	exit('Forbidden');
}

// Security check
if (empty($user->id)) {
	http_response_code(403);
	exit(json_encode(array('error' => 'Not authenticated')));
}

header('Content-Type: application/json; charset=utf-8');

$action = GETPOST('action', 'aZ09');

if ($action === 'search') {
	$term = GETPOST('term', 'alpha');
	$page = GETPOSTINT('page') ?: 1;
	$pageSize = 30;
	$offset = ($page - 1) * $pageSize;

	$results = array();
	$total = 0;

	// Build search query
	$sql = "SELECT rowid, codigo, label, description";
	$sql .= " FROM " . MAIN_DB_PREFIX . "gestion_diagnostico";
	$sql .= " WHERE 1=1";

	if (!empty($term)) {
		$term = $db->escape($term);
		$sql .= " AND (codigo LIKE '%" . $term . "%' OR description LIKE '%" . $term . "%' OR label LIKE '%" . $term . "%')";
	}

	// Count total for pagination
	$sqlCount = preg_replace('/^SELECT .* FROM/', 'SELECT COUNT(*) as total FROM', $sql, 1);
	$resqlCount = $db->query($sqlCount);
	if ($resqlCount) {
		$objCount = $db->fetch_object($resqlCount);
		$total = (int) $objCount->total;
	}

	// Add ordering and pagination
	$sql .= " ORDER BY codigo ASC, description ASC";
	$sql .= " LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset;

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$text = $obj->description;
			if (!empty($obj->codigo)) {
				$text = $obj->codigo . ' - ' . $obj->description;
			}
			$results[] = array(
				'id'   => (int) $obj->rowid,
				'text' => $text,
			);
		}
		$db->free($resql);
	}

	echo json_encode(array(
		'results'    => $results,
		'pagination' => array(
			'more' => ($offset + $pageSize) < $total,
		),
		'total' => $total,
	));
	exit;
}

// Action: fetch specific IDs (for loading pre-selected values)
if ($action === 'fetch') {
	$ids = GETPOST('ids', 'alpha');
	$results = array();

	if (!empty($ids)) {
		$idArray = array_map('intval', explode(',', $ids));
		$idArray = array_filter($idArray, function ($v) { return $v > 0; });

		if (!empty($idArray)) {
			$sql = "SELECT rowid, codigo, description";
			$sql .= " FROM " . MAIN_DB_PREFIX . "gestion_diagnostico";
			$sql .= " WHERE rowid IN (" . implode(',', $idArray) . ")";
			$sql .= " ORDER BY codigo ASC";

			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					$text = $obj->description;
					if (!empty($obj->codigo)) {
						$text = $obj->codigo . ' - ' . $obj->description;
					}
					$results[] = array(
						'id'   => (int) $obj->rowid,
						'text' => $text,
					);
				}
				$db->free($resql);
			}
		}
	}

	echo json_encode(array('results' => $results));
	exit;
}

// Default: bad request
http_response_code(400);
echo json_encode(array('error' => 'Invalid action'));
