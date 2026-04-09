<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/recipients.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to search for recipients (third parties + contacts with phone numbers)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Disable output buffering
if (ob_get_level()) {
	ob_end_clean();
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Access control
if (!$user->rights->whatsappdati->message->send) {
	http_response_code(403);
	echo json_encode(array('error' => 'Access denied'));
	exit;
}

header('Content-Type: application/json; charset=UTF-8');

$search = GETPOST('search', 'alphanohtml');
$limit = GETPOST('limit', 'int');
if (empty($limit) || $limit > 200) {
	$limit = 50;
}

$recipients = array();

if (empty($search) || strlen($search) < 2) {
	echo json_encode(array('recipients' => $recipients, 'total' => 0));
	exit;
}

$searchEsc = $db->escape($db->escapeforlike($search));

// Split search into words for multi-word matching (e.g. "Juan Perez")
$searchWords = preg_split('/\s+/', trim($search));
$searchWords = array_filter($searchWords, function($w) { return strlen($w) >= 2; });

// Search contacts (socpeople) with phone numbers
$sql = "SELECT sp.rowid, sp.lastname, sp.firstname, sp.phone, sp.phone_perso, sp.phone_mobile, sp.fax,";
$sql .= " sp.email, sp.fk_soc, s.nom as company_name";
$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as sp";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON sp.fk_soc = s.rowid";
$sql .= " WHERE sp.entity IN (".getEntity('socpeople').")";
$sql .= " AND sp.statut = 1"; // active contacts only
$sql .= " AND (sp.phone != '' OR sp.phone_perso != '' OR sp.phone_mobile != '' OR sp.fax != '')";
$sql .= " AND (";

// Match full search term against individual fields
$sql .= " sp.lastname LIKE '%".$searchEsc."%'";
$sql .= " OR sp.firstname LIKE '%".$searchEsc."%'";
$sql .= " OR sp.phone LIKE '%".$searchEsc."%'";
$sql .= " OR sp.phone_perso LIKE '%".$searchEsc."%'";
$sql .= " OR sp.phone_mobile LIKE '%".$searchEsc."%'";
$sql .= " OR sp.fax LIKE '%".$searchEsc."%'";
$sql .= " OR s.nom LIKE '%".$searchEsc."%'";
$sql .= " OR sp.email LIKE '%".$searchEsc."%'";
// Match full name (firstname + lastname combined)
$sql .= " OR CONCAT(COALESCE(sp.firstname,''), ' ', COALESCE(sp.lastname,'')) LIKE '%".$searchEsc."%'";
$sql .= " OR CONCAT(COALESCE(sp.lastname,''), ' ', COALESCE(sp.firstname,'')) LIKE '%".$searchEsc."%'";

// Multi-word search: each word must match somewhere (name, company, email)
if (count($searchWords) > 1) {
	$wordConditions = array();
	foreach ($searchWords as $word) {
		$wordEsc = $db->escape($db->escapeforlike($word));
		$wordConditions[] = "(CONCAT(COALESCE(sp.firstname,''), ' ', COALESCE(sp.lastname,''), ' ', COALESCE(s.nom,''), ' ', COALESCE(sp.email,'')) LIKE '%".$wordEsc."%')";
	}
	$sql .= " OR (".implode(' AND ', $wordConditions).")";
}

$sql .= ")";
$sql .= " ORDER BY sp.lastname, sp.firstname";
$sql .= " LIMIT ".((int) $limit);

$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		// Collect all available phone numbers, deduplicating by normalized digits
		$phones = array();
		$seenDigits = array();
		$addPhone = function($type, $number) use (&$phones, &$seenDigits) {
			if (empty($number)) return;
			$normalized = preg_replace('/[^0-9]/', '', $number);
			// Use last 10 digits as key to catch +57/57/local variants
			$key = strlen($normalized) >= 10 ? substr($normalized, -10) : $normalized;
			if (!isset($seenDigits[$key])) {
				$seenDigits[$key] = true;
				$phones[] = array('type' => $type, 'number' => $number);
			}
		};
		$addPhone('mobile', $obj->phone_mobile);
		$addPhone('phone', $obj->phone);
		$addPhone('personal', $obj->phone_perso);
		$addPhone('fax', $obj->fax);

		$name = trim($obj->firstname.' '.$obj->lastname);
		if (empty($name)) {
			$name = $obj->company_name ?: 'Contact #'.$obj->rowid;
		}

		$recipients[] = array(
			'id' => 'contact_'.$obj->rowid,
			'name' => $name,
			'phones' => $phones,
			'company' => $obj->company_name ?: '',
			'fk_soc' => (int) $obj->fk_soc,
			'source' => 'contact',
			'source_id' => (int) $obj->rowid
		);
	}
}

// Search third parties with phone numbers (if not enough results from contacts)
if (count($recipients) < $limit) {
	$remaining = $limit - count($recipients);

	$sql2 = "SELECT s.rowid, s.nom, s.phone, s.phone_mobile, s.fax, s.email, s.fk_forme_juridique";
	$sql2 .= " FROM ".MAIN_DB_PREFIX."societe as s";
	$sql2 .= " WHERE s.entity IN (".getEntity('societe').")";
	$sql2 .= " AND s.status = 1"; // active third parties only
	$sql2 .= " AND (s.phone != '' OR s.phone_mobile != '' OR s.fax != '')";
	$sql2 .= " AND (";
	$sql2 .= " s.nom LIKE '%".$searchEsc."%'";
	$sql2 .= " OR s.phone LIKE '%".$searchEsc."%'";
	$sql2 .= " OR s.phone_mobile LIKE '%".$searchEsc."%'";
	$sql2 .= " OR s.fax LIKE '%".$searchEsc."%'";
	$sql2 .= " OR s.email LIKE '%".$searchEsc."%'";
	$sql2 .= ")";
	$sql2 .= " ORDER BY s.nom";
	$sql2 .= " LIMIT ".((int) $remaining);

	$resql2 = $db->query($sql2);
	if ($resql2) {
		while ($obj2 = $db->fetch_object($resql2)) {
			// Collect all available phone numbers, deduplicating by normalized digits
			$soc_phones = array();
			$seenDigits2 = array();
			$addPhone2 = function($type, $number) use (&$soc_phones, &$seenDigits2) {
				if (empty($number)) return;
				$normalized = preg_replace('/[^0-9]/', '', $number);
				$key = strlen($normalized) >= 10 ? substr($normalized, -10) : $normalized;
				if (!isset($seenDigits2[$key])) {
					$seenDigits2[$key] = true;
					$soc_phones[] = array('type' => $type, 'number' => $number);
				}
			};
			$addPhone2('phone', $obj2->phone);
			$addPhone2('mobile', $obj2->phone_mobile);
			$addPhone2('fax', $obj2->fax);

			$recipients[] = array(
				'id' => 'thirdparty_'.$obj2->rowid,
				'name' => $obj2->nom,
				'phones' => $soc_phones,
				'company' => $obj2->nom,
				'fk_soc' => (int) $obj2->rowid,
				'source' => 'thirdparty',
				'source_id' => (int) $obj2->rowid
			);
		}
	}
}

echo json_encode(array(
	'recipients' => $recipients,
	'total' => count($recipients)
));
