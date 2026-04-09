<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/leads.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for CRM integration (link third party, create lead)
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

if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once '../class/whatsappconversation.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

// Load projet class only if module is active
$hasProjet = !empty($conf->projet->enabled) || !empty($conf->project->enabled);
if ($hasProjet) {
	require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
}

header('Content-Type: application/json');

// Translations
$langs->loadLangs(array("whatsappdati@whatsappdati", "companies", "projects"));

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

$action = GETPOST('action', 'alpha');

// CSRF validation for mutation actions
if (in_array($action, array('link_thirdparty', 'create_lead', 'create_thirdparty'))) {
	whatsappdatiCheckCSRFToken();
}

// ==============================================
// ACTION: Search third parties
// ==============================================
if ($action === 'search_thirdparty') {
	$query = GETPOST('query', 'alpha');

	if (strlen($query) < 2) {
		echo json_encode(array('success' => true, 'results' => array()));
		exit;
	}

	$results = array();

	$queryLike = $db->escape($db->escapeforlike($query));

	// Search in societe table
	$sql = "SELECT s.rowid, s.nom as name, s.name_alias, s.phone, s.email, s.town, s.client, s.fournisseur,";
	$sql .= " s.code_client, s.status";
	$sql .= " FROM " . MAIN_DB_PREFIX . "societe as s";
	$sql .= " WHERE s.entity IN (" . getEntity('societe') . ")";
	$sql .= " AND (";
	$sql .= "   s.nom LIKE '%" . $queryLike . "%'";
	$sql .= "   OR s.name_alias LIKE '%" . $queryLike . "%'";
	$sql .= "   OR s.phone LIKE '%" . $queryLike . "%'";
	$sql .= "   OR s.email LIKE '%" . $queryLike . "%'";
	$sql .= "   OR s.code_client LIKE '%" . $queryLike . "%'";
	$sql .= " )";
	$sql .= " ORDER BY s.nom ASC";
	$sql .= " LIMIT 20";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$typeLabel = '';
			if ($obj->client == 1) $typeLabel = $langs->trans("Customer");
			elseif ($obj->client == 2) $typeLabel = $langs->trans("Prospect");
			elseif ($obj->client == 3) $typeLabel = $langs->trans("ProspectCustomer");
			if ($obj->fournisseur) {
				$typeLabel .= ($typeLabel ? ' / ' : '') . $langs->trans("Supplier");
			}
			if (empty($typeLabel)) $typeLabel = $langs->trans("Other");

			$results[] = array(
				'id' => (int) $obj->rowid,
				'name' => $obj->name,
				'name_alias' => $obj->name_alias,
				'phone' => $obj->phone,
				'email' => $obj->email,
				'town' => $obj->town,
				'type' => $typeLabel,
				'client' => (int) $obj->client,
				'status' => (int) $obj->status,
			);
		}
		$db->free($resql);
	}

	// Also search in contacts
	$sqlContact = "SELECT c.rowid as contact_id, c.lastname, c.firstname, c.phone_perso, c.phone_mobile, c.email,";
	$sqlContact .= " s.rowid as soc_id, s.nom as soc_name";
	$sqlContact .= " FROM " . MAIN_DB_PREFIX . "socpeople as c";
	$sqlContact .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc";
	$sqlContact .= " WHERE c.entity IN (" . getEntity('socpeople') . ")";
	$sqlContact .= " AND (";
	$sqlContact .= "   c.lastname LIKE '%" . $queryLike . "%'";
	$sqlContact .= "   OR c.firstname LIKE '%" . $queryLike . "%'";
	$sqlContact .= "   OR c.phone_perso LIKE '%" . $queryLike . "%'";
	$sqlContact .= "   OR c.phone_mobile LIKE '%" . $queryLike . "%'";
	$sqlContact .= "   OR c.email LIKE '%" . $queryLike . "%'";
	$sqlContact .= "   OR CONCAT(c.firstname, ' ', c.lastname) LIKE '%" . $queryLike . "%'";
	$sqlContact .= " )";
	$sqlContact .= " ORDER BY c.lastname ASC";
	$sqlContact .= " LIMIT 10";

	$resql2 = $db->query($sqlContact);
	if ($resql2) {
		while ($obj = $db->fetch_object($resql2)) {
			// Only add if related thirdparty is not already in results
			$alreadyInResults = false;
			if ($obj->soc_id) {
				foreach ($results as $r) {
					if ($r['id'] == $obj->soc_id) {
						$alreadyInResults = true;
						break;
					}
				}
			}
			if (!$alreadyInResults && $obj->soc_id) {
				// Add as thirdparty link via contact
				$soc = new Societe($db);
				$soc->fetch($obj->soc_id);
				$contactName = trim($obj->firstname . ' ' . $obj->lastname);
				$results[] = array(
					'id' => (int) $obj->soc_id,
					'name' => $soc->name,
					'name_alias' => $soc->name_alias,
					'phone' => $soc->phone,
					'email' => $soc->email,
					'town' => $soc->town,
					'type' => $langs->trans("ContactOf") . ' ' . $contactName,
					'client' => (int) $soc->client,
					'status' => (int) $soc->status,
					'via_contact' => $contactName,
				);
			}
		}
		$db->free($resql2);
	}

	echo json_encode(array('success' => true, 'results' => $results));
	exit;
}

// ==============================================
// ACTION: Link conversation to third party
// ==============================================
if ($action === 'link_thirdparty') {
	$input = json_decode(file_get_contents('php://input'), true);
	$conversationId = (int) ($input['conversation_id'] ?? 0);
	$socId = (int) ($input['soc_id'] ?? 0);

	if ($conversationId <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid conversation'));
		exit;
	}

	$conversation = new WhatsAppConversation($db);
	if ($conversation->fetch($conversationId) <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
		exit;
	}

	// Set or unlink (socId = 0 to unlink)
	$conversation->fk_soc = ($socId > 0) ? $socId : null;
	$result = $conversation->update($user);

	if ($result > 0) {
		$socName = '';
		$socUrl = '';
		if ($socId > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($socId) > 0) {
				$socName = $soc->name;
				$socUrl = DOL_URL_ROOT . '/societe/card.php?socid=' . $socId;
			}
		}
		echo json_encode(array(
			'success' => true,
			'message' => $socId > 0 ? $langs->trans("CrmLinkedSuccess") : $langs->trans("CrmUnlinkedSuccess"),
			'soc_id' => $socId,
			'soc_name' => $socName,
			'soc_url' => $socUrl,
		));
	} else {
		echo json_encode(array('success' => false, 'error' => 'Error updating conversation'));
	}
	exit;
}

// ==============================================
// ACTION: Get linked third party info
// ==============================================
if ($action === 'get_thirdparty_info') {
	$conversationId = GETPOST('conversation_id', 'int');

	if ($conversationId <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Invalid conversation'));
		exit;
	}

	$conversation = new WhatsAppConversation($db);
	if ($conversation->fetch($conversationId) <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
		exit;
	}

	$socInfo = null;
	if (!empty($conversation->fk_soc) && $conversation->fk_soc > 0) {
		$soc = new Societe($db);
		if ($soc->fetch($conversation->fk_soc) > 0) {
			$typeLabel = '';
			if ($soc->client == 1) $typeLabel = $langs->trans("Customer");
			elseif ($soc->client == 2) $typeLabel = $langs->trans("Prospect");
			elseif ($soc->client == 3) $typeLabel = $langs->trans("ProspectCustomer");
			if ($soc->fournisseur) {
				$typeLabel .= ($typeLabel ? ' / ' : '') . $langs->trans("Supplier");
			}

			$socInfo = array(
				'id' => (int) $soc->id,
				'name' => $soc->name,
				'phone' => $soc->phone,
				'email' => $soc->email,
				'town' => $soc->town,
				'type' => $typeLabel,
				'url' => DOL_URL_ROOT . '/societe/card.php?socid=' . $soc->id,
			);
		}
	}

	echo json_encode(array(
		'success' => true,
		'has_thirdparty' => !empty($socInfo),
		'thirdparty' => $socInfo,
		'project_enabled' => $hasProjet,
	));
	exit;
}

// ==============================================
// ACTION: Create new third party from conversation
// ==============================================
if ($action === 'create_thirdparty') {
	if (!$user->rights->societe->creer) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("NotEnoughPermissions")));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	$conversationId = (int) ($input['conversation_id'] ?? 0);
	$name = dol_string_nohtmltag(trim($input['name'] ?? ''));
	$phone = dol_string_nohtmltag(trim($input['phone'] ?? ''));
	$email = dol_string_nohtmltag(trim($input['email'] ?? ''));
	$clientType = (int) ($input['client_type'] ?? 2); // Default: prospect

	if (empty($name)) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("ErrorFieldRequired", $langs->transnoentitiesaliases("ThirdPartyName"))));
		exit;
	}

	$soc = new Societe($db);
	$soc->name = $name;
	$soc->phone = $phone;
	$soc->email = $email;
	$soc->client = $clientType;
	$soc->status = 1; // Active

	$socId = $soc->create($user);
	if ($socId > 0) {
		// Link to conversation if provided
		if ($conversationId > 0) {
			$conversation = new WhatsAppConversation($db);
			if ($conversation->fetch($conversationId) > 0) {
				$conversation->fk_soc = $socId;
				$conversation->update($user);
			}
		}

		echo json_encode(array(
			'success' => true,
			'message' => $langs->trans("CrmThirdPartyCreated"),
			'soc_id' => $socId,
			'soc_name' => $name,
			'soc_url' => DOL_URL_ROOT . '/societe/card.php?socid=' . $socId,
		));
	} else {
		echo json_encode(array('success' => false, 'error' => implode(', ', $soc->errors)));
	}
	exit;
}

// ==============================================
// ACTION: Create lead (projet/opportunity)
// ==============================================
if ($action === 'create_lead') {
	if (!$hasProjet) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("CrmProjectModuleRequired")));
		exit;
	}

	if (!$user->rights->projet->creer) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("NotEnoughPermissions")));
		exit;
	}

	$input = json_decode(file_get_contents('php://input'), true);
	$conversationId = (int) ($input['conversation_id'] ?? 0);
	$title = dol_string_nohtmltag(trim($input['title'] ?? ''));
	$description = dol_string_nohtmltag(trim($input['description'] ?? ''));
	$oppAmount = (float) ($input['opp_amount'] ?? 0);
	$oppPercent = (int) ($input['opp_percent'] ?? 10);

	if (empty($title)) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("ErrorFieldRequired", $langs->transnoentitiesaliases("Label"))));
		exit;
	}

	// Get conversation to find linked third party
	$socId = 0;
	$conversationPhone = '';
	if ($conversationId > 0) {
		$conversation = new WhatsAppConversation($db);
		if ($conversation->fetch($conversationId) > 0) {
			$socId = (int) $conversation->fk_soc;
			$conversationPhone = $conversation->phone_number;
		}
	}

	if ($socId <= 0) {
		echo json_encode(array('success' => false, 'error' => $langs->trans("CrmLinkThirdPartyFirst")));
		exit;
	}

	$project = new Project($db);
	$project->title = $title;
	$project->ref = '';  // Auto-generate
	$project->description = $description;
	$project->socid = $socId;
	$project->usage_opportunity = 1;
	$project->opp_amount = $oppAmount;
	$project->opp_percent = $oppPercent;
	$project->fk_opp_status = 1;  // Prospection
	$project->date_start = dol_now();
	$project->statut = 1; // Open / validated

	// Add WhatsApp source note
	if (empty($project->description)) {
		$project->description = $langs->trans("CrmLeadFromWhatsApp", $conversationPhone);
	}

	$projectId = $project->create($user);
	if ($projectId > 0) {
		// Validate the project so ref is generated
		if (method_exists($project, 'setValid')) {
			$project->setValid($user);
		}

		echo json_encode(array(
			'success' => true,
			'message' => $langs->trans("CrmLeadCreated"),
			'project_id' => $projectId,
			'project_ref' => $project->ref,
			'project_url' => DOL_URL_ROOT . '/projet/card.php?id=' . $projectId,
		));
	} else {
		echo json_encode(array('success' => false, 'error' => implode(', ', $project->errors)));
	}
	exit;
}

echo json_encode(array('success' => false, 'error' => 'Unknown action'));
$db->close();
