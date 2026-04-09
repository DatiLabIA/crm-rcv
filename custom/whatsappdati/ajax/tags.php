<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/tags.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for tag management
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

require_once '../class/whatsapptag.class.php';
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

header('Content-Type: application/json');

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

$action = GETPOST('action', 'aZ09');

// CSRF validation for mutation actions
if (in_array($action, array('create', 'update', 'delete', 'assign', 'unassign'))) {
	whatsappdatiCheckCSRFToken();
}

$tagHandler = new WhatsAppTag($db);

switch ($action) {

	// ==========================================
	// List all active tags
	// ==========================================
	case 'list':
		$tags = $tagHandler->fetchAll();
		$result = array();
		foreach ($tags as $tag) {
			$result[] = array(
				'id' => (int) $tag->rowid,
				'label' => $tag->label,
				'color' => $tag->color,
				'description' => $tag->description
			);
		}
		echo json_encode(array('success' => true, 'tags' => $result));
		break;

	// ==========================================
	// List all tags (admin view, with usage count)
	// ==========================================
	case 'list_admin':
		if (!$user->rights->whatsappdati->config->write) {
			echo json_encode(array('success' => false, 'error' => 'Access denied'));
			break;
		}
		$tags = $tagHandler->fetchAllAdmin();
		$result = array();
		foreach ($tags as $tag) {
			$result[] = array(
				'id' => (int) $tag->rowid,
				'label' => $tag->label,
				'color' => $tag->color,
				'description' => $tag->description,
				'position' => (int) $tag->position,
				'active' => (int) $tag->active,
				'usage_count' => (int) $tag->usage_count
			);
		}
		echo json_encode(array('success' => true, 'tags' => $result));
		break;

	// ==========================================
	// Create a new tag
	// ==========================================
	case 'create':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		if (!$user->rights->whatsappdati->config->write) {
			echo json_encode(array('success' => false, 'error' => 'Access denied'));
			break;
		}
		$label = GETPOST('label', 'alphanohtml');
		$color = GETPOST('color', 'alphanohtml');
		$description = GETPOST('description', 'alphanohtml');

		if (empty($label)) {
			echo json_encode(array('success' => false, 'error' => 'Label is required'));
			break;
		}

		$tagHandler->label = $label;
		$tagHandler->color = $color ?: '#25D366';
		$tagHandler->description = $description;
		$tagHandler->position = 0;

		$result = $tagHandler->create($user);
		if ($result > 0) {
			echo json_encode(array(
				'success' => true,
				'tag' => array(
					'id' => $result,
					'label' => $tagHandler->label,
					'color' => $tagHandler->color,
					'description' => $tagHandler->description
				)
			));
		} else {
			$errorMsg = !empty($tagHandler->errors) ? implode(', ', $tagHandler->errors) : 'Unknown error';
			echo json_encode(array('success' => false, 'error' => $errorMsg));
		}
		break;

	// ==========================================
	// Update an existing tag
	// ==========================================
	case 'update':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		if (!$user->rights->whatsappdati->config->write) {
			echo json_encode(array('success' => false, 'error' => 'Access denied'));
			break;
		}
		$tagId = GETPOST('tag_id', 'int');
		$label = GETPOST('label', 'alphanohtml');
		$color = GETPOST('color', 'alphanohtml');
		$description = GETPOST('description', 'alphanohtml');

		if ($tagHandler->fetch($tagId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Tag not found'));
			break;
		}

		if (!empty($label)) $tagHandler->label = $label;
		if (!empty($color)) $tagHandler->color = $color;
		$tagHandler->description = $description;

		$result = $tagHandler->update($user);
		if ($result > 0) {
			echo json_encode(array(
				'success' => true,
				'tag' => array(
					'id' => (int) $tagHandler->id,
					'label' => $tagHandler->label,
					'color' => $tagHandler->color,
					'description' => $tagHandler->description
				)
			));
		} else {
			echo json_encode(array('success' => false, 'error' => implode(', ', $tagHandler->errors)));
		}
		break;

	// ==========================================
	// Delete a tag
	// ==========================================
	case 'delete':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		if (!$user->rights->whatsappdati->config->write) {
			echo json_encode(array('success' => false, 'error' => 'Access denied'));
			break;
		}
		$tagId = GETPOST('tag_id', 'int');

		if ($tagHandler->fetch($tagId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Tag not found'));
			break;
		}

		$result = $tagHandler->delete($user);
		if ($result > 0) {
			echo json_encode(array('success' => true));
		} else {
			echo json_encode(array('success' => false, 'error' => implode(', ', $tagHandler->errors)));
		}
		break;

	// ==========================================
	// Add tag to conversation
	// ==========================================
	case 'assign':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$tagId = GETPOST('tag_id', 'int');

		if (empty($conversationId) || empty($tagId)) {
			echo json_encode(array('success' => false, 'error' => 'Missing parameters'));
			break;
		}

		$result = $tagHandler->addTagToConversation($conversationId, $tagId, $user);
		if ($result > 0) {
			// Return updated tag list for conversation
			$tags = $tagHandler->getConversationTags($conversationId);
			$tagList = array();
			foreach ($tags as $t) {
				$tagList[] = array('id' => (int) $t->rowid, 'label' => $t->label, 'color' => $t->color);
			}
			echo json_encode(array('success' => true, 'tags' => $tagList));
		} else {
			echo json_encode(array('success' => false, 'error' => implode(', ', $tagHandler->errors)));
		}
		break;

	// ==========================================
	// Remove tag from conversation
	// ==========================================
	case 'unassign':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$tagId = GETPOST('tag_id', 'int');

		if (empty($conversationId) || empty($tagId)) {
			echo json_encode(array('success' => false, 'error' => 'Missing parameters'));
			break;
		}

		$result = $tagHandler->removeTagFromConversation($conversationId, $tagId);
		if ($result > 0) {
			$tags = $tagHandler->getConversationTags($conversationId);
			$tagList = array();
			foreach ($tags as $t) {
				$tagList[] = array('id' => (int) $t->rowid, 'label' => $t->label, 'color' => $t->color);
			}
			echo json_encode(array('success' => true, 'tags' => $tagList));
		} else {
			echo json_encode(array('success' => false, 'error' => implode(', ', $tagHandler->errors)));
		}
		break;

	// ==========================================
	// Get tags for a conversation
	// ==========================================
	case 'conversation_tags':
		$conversationId = GETPOST('conversation_id', 'int');

		if (empty($conversationId)) {
			echo json_encode(array('success' => false, 'error' => 'Missing conversation_id'));
			break;
		}

		$tags = $tagHandler->getConversationTags($conversationId);
		$tagList = array();
		foreach ($tags as $t) {
			$tagList[] = array('id' => (int) $t->rowid, 'label' => $t->label, 'color' => $t->color);
		}
		echo json_encode(array('success' => true, 'tags' => $tagList));
		break;

	default:
		echo json_encode(array('success' => false, 'error' => 'Unknown action'));
		break;
}

$db->close();
