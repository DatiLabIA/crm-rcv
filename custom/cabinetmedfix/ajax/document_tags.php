<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * AJAX endpoint for document tag management
 * Actions: get_tags, save_tags, autocomplete
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include($_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php");
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) $res = @include(substr($tmp, 0, ($i + 1))."/main.inc.php");
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) $res = @include(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php");
if (!$res && file_exists("../../../main.inc.php")) $res = @include("../../../main.inc.php");
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

// Security
if (!$user->id) {
	http_response_code(403);
	echo json_encode(array('error' => 'Not authenticated'));
	exit;
}

$action = GETPOST('action', 'alpha');
$socid = GETPOST('socid', 'int');
$filename = GETPOST('filename', 'alpha');
$tags = GETPOST('tags', 'restricthtml');
$term = GETPOST('term', 'alpha');

header('Content-Type: application/json; charset=UTF-8');

// Verify access to this societe
if ($socid > 0) {
	$result = restrictedArea($user, 'societe', $socid, '&societe', '', '', 'rowid', 0, 1);
	if (!$result) {
		http_response_code(403);
		echo json_encode(array('error' => 'Access denied'));
		exit;
	}
}


/**
 * GET TAGS - Get all tags for documents of a patient
 */
if ($action == 'get_tags') {
	if (empty($socid)) {
		echo json_encode(array('error' => 'Missing socid'));
		exit;
	}

	$sql = "SELECT filename, tags FROM ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels";
	$sql .= " WHERE fk_societe = ".((int) $socid);
	$sql .= " AND entity = ".$conf->entity;

	$result = $db->query($sql);
	$data = array();

	if ($result) {
		while ($obj = $db->fetch_object($result)) {
			$data[$obj->filename] = $obj->tags;
		}
	}

	echo json_encode(array('success' => true, 'tags' => $data));
	exit;
}


/**
 * SAVE TAGS - Save tags for a specific document
 */
if ($action == 'save_tags') {
	if (empty($socid) || empty($filename)) {
		echo json_encode(array('error' => 'Missing socid or filename'));
		exit;
	}

	if (!$user->hasRight('societe', 'creer')) {
		http_response_code(403);
		echo json_encode(array('error' => 'No write permission'));
		exit;
	}

	// Sanitize tags: trim each tag, remove empty ones
	$tagsClean = '';
	if (!empty($tags)) {
		$tagArray = array_map('trim', explode(',', $tags));
		$tagArray = array_filter($tagArray, function($t) { return $t !== ''; });
		$tagArray = array_unique($tagArray);
		$tagsClean = implode(',', $tagArray);
	}

	// Check if entry exists
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels";
	$sql .= " WHERE fk_societe = ".((int) $socid);
	$sql .= " AND filename = '".$db->escape($filename)."'";
	$sql .= " AND entity = ".$conf->entity;

	$result = $db->query($sql);

	if ($result && $db->num_rows($result) > 0) {
		// Update existing
		if (empty($tagsClean)) {
			// Remove entry if no tags
			$sqlUpdate = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels";
			$sqlUpdate .= " WHERE fk_societe = ".((int) $socid);
			$sqlUpdate .= " AND filename = '".$db->escape($filename)."'";
			$sqlUpdate .= " AND entity = ".$conf->entity;
		} else {
			$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels SET";
			$sqlUpdate .= " tags = '".$db->escape($tagsClean)."'";
			$sqlUpdate .= ", fk_user_modif = ".((int) $user->id);
			$sqlUpdate .= " WHERE fk_societe = ".((int) $socid);
			$sqlUpdate .= " AND filename = '".$db->escape($filename)."'";
			$sqlUpdate .= " AND entity = ".$conf->entity;
		}
	} else {
		// Insert new
		if (!empty($tagsClean)) {
			$sqlUpdate = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels";
			$sqlUpdate .= " (fk_societe, filename, tags, fk_user_modif, entity)";
			$sqlUpdate .= " VALUES (";
			$sqlUpdate .= ((int) $socid);
			$sqlUpdate .= ", '".$db->escape($filename)."'";
			$sqlUpdate .= ", '".$db->escape($tagsClean)."'";
			$sqlUpdate .= ", ".((int) $user->id);
			$sqlUpdate .= ", ".$conf->entity;
			$sqlUpdate .= ")";
		} else {
			// No tags and no existing entry, nothing to do
			echo json_encode(array('success' => true, 'tags' => ''));
			exit;
		}
	}

	$resUpdate = $db->query($sqlUpdate);
	if ($resUpdate) {
		echo json_encode(array('success' => true, 'tags' => $tagsClean));
	} else {
		echo json_encode(array('error' => 'Database error: '.$db->lasterror()));
	}
	exit;
}


/**
 * AUTOCOMPLETE - Suggest existing tags across all documents of this entity
 */
if ($action == 'autocomplete') {
	$sql = "SELECT DISTINCT tags FROM ".MAIN_DB_PREFIX."cabinetmedfix_doc_labels";
	$sql .= " WHERE entity = ".$conf->entity;
	$sql .= " AND tags IS NOT NULL AND tags != ''";
	if (!empty($socid)) {
		// Prioritize tags from the same patient, but include all
	}

	$result = $db->query($sql);
	$allTags = array();

	if ($result) {
		while ($obj = $db->fetch_object($result)) {
			$parts = explode(',', $obj->tags);
			foreach ($parts as $t) {
				$t = trim($t);
				if ($t !== '') {
					$allTags[$t] = true;
				}
			}
		}
	}

	// Filter by search term if provided
	$suggestions = array();
	$termLower = mb_strtolower(trim($term));
	foreach (array_keys($allTags) as $tag) {
		if (empty($termLower) || mb_strpos(mb_strtolower($tag), $termLower) !== false) {
			$suggestions[] = $tag;
		}
	}

	sort($suggestions);
	echo json_encode(array('success' => true, 'suggestions' => array_slice($suggestions, 0, 20)));
	exit;
}


echo json_encode(array('error' => 'Unknown action'));
