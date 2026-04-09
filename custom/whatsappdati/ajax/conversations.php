<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/conversations.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to get conversations list
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
require_once '../class/whatsapptag.class.php';
require_once '../class/whatsappassignment.class.php';

// Force utf8mb4 so emojis are read correctly from DB
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}

header('Content-Type: application/json; charset=utf-8');

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

// Get parameters
$user_id = GETPOST('user_id', 'int');
$tag_id = GETPOST('tag_id', 'int');
$line_id = GETPOST('line_id', 'int');
$search = trim(GETPOST('search', 'alphanohtml') ?? '');
$limit = GETPOST('limit', 'int') ?: 50;
$offset = GETPOST('offset', 'int') ?: 0;

// Fetch conversations (all filters applied in SQL)
$unread_only = (bool) GETPOST('unread_only', 'int');
$conversation = new WhatsAppConversation($db);
// Visibility: non-admin users only see conversations on their lines or assigned to them
$scopeUserId = $user->admin ? 0 : (int) $user->id;
$conversations = $conversation->fetchAll($user_id, 'last_message_date', 'DESC', $limit, $offset, $line_id, $tag_id, $unread_only, $search, $scopeUserId);

// Tag handler (used for enrichment only)
$tagHandler = new WhatsAppTag($db);

// Convert DATETIME strings to Unix timestamps for JavaScript
foreach ($conversations as &$conv) {
	if (!empty($conv->last_message_date)) {
		$conv->last_message_date = $db->jdate($conv->last_message_date);
	}
	if (!empty($conv->window_expires_at)) {
		$conv->window_expires_at = $db->jdate($conv->window_expires_at);
	}
}
unset($conv);

// Build agent_name from JOIN columns (no extra queries needed)
foreach ($conversations as &$conv) {
	$conv->agent_name = '';
	if (!empty($conv->fk_user_assigned)) {
		$fullName = trim(($conv->agent_firstname ?? '').' '.($conv->agent_lastname ?? ''));
		$conv->agent_name = !empty($fullName) ? $fullName : ($conv->agent_login ?? '');
	}
	unset($conv->agent_firstname, $conv->agent_lastname, $conv->agent_login);
}
unset($conv);

// Enrich with tags (batch query for efficiency)
$convIds = array_map(function($c) { return $c->rowid; }, $conversations);
$allTags = $tagHandler->getTagsForConversations($convIds);
foreach ($conversations as &$conv) {
	$conv->tags = array();
	if (isset($allTags[$conv->rowid])) {
		foreach ($allTags[$conv->rowid] as $tag) {
			$conv->tags[] = array('id' => (int) $tag->rowid, 'label' => $tag->label, 'color' => $tag->color);
		}
	}
}
unset($conv);

// Enrich with assigned agents (batch query)
$assignHandler = new WhatsAppAssignment($db);
$allAgents = $assignHandler->getConversationAgentsBatch($convIds);
foreach ($conversations as &$conv) {
	$conv->assigned_agents = array();
	if (isset($allAgents[$conv->rowid])) {
		foreach ($allAgents[$conv->rowid] as $agent) {
			$name = trim($agent->firstname.' '.$agent->lastname);
			$conv->assigned_agents[] = array('id' => (int) $agent->user_id, 'name' => !empty($name) ? $name : $agent->login, 'role' => $agent->role);
		}
	}
}
unset($conv);

// Return JSON
echo json_encode(array(
	'success' => true,
	'conversations' => $conversations
), JSON_UNESCAPED_UNICODE);

$db->close();
