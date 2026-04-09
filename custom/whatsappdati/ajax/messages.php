<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/messages.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to get messages for a conversation
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
require_once '../class/whatsappmessage.class.php';
require_once '../class/whatsapptag.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

header('Content-Type: application/json; charset=utf-8');

// Force utf8mb4 so emojis are read correctly from DB
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

// Get parameters
$conversation_id = GETPOST('conversation_id', 'int');
$limit = GETPOST('limit', 'int') ?: 100;
$offset = GETPOST('offset', 'int') ?: 0;
// Fetch one extra to detect if there are older messages
$fetchLimit = $limit + 1;

if (!$conversation_id) {
	echo json_encode(array('success' => false, 'error' => 'Missing conversation_id'));
	exit;
}

// Fetch conversation
$conversation = new WhatsAppConversation($db);
if ($conversation->fetch($conversation_id) <= 0) {
	echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
	exit;
}

// Mark messages as read
$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations";
$sql .= " SET unread_count = 0";
$sql .= " WHERE rowid = ".((int) $conversation_id);
$db->query($sql);

// Fetch messages — DESC order so we always get the most recent $limit messages;
// then reverse to restore chronological (ASC) order for the frontend.
$message = new WhatsAppMessage($db);
$messages = $message->fetchByConversation($conversation_id, $fetchLimit, $offset, 'DESC');
$has_more = count($messages) > $limit;
if ($has_more) {
	array_pop($messages); // remove the extra probe element
}
$messages = array_reverse($messages); // chronological order for the UI

// Build media URLs for messages that have media
// Use dol_buildpath for proper URL construction, with fallback to relative path
$mediaBaseUrl = dol_buildpath('/custom/whatsappdati/ajax/media.php', 1);
if (empty($mediaBaseUrl)) {
	// Fallback: use DOL_URL_ROOT if dol_buildpath fails
	$mediaBaseUrl = DOL_URL_ROOT.'/custom/whatsappdati/ajax/media.php';
}
$messagesOut = array();
foreach ($messages as $msg) {
	$m = (array) $msg;
	// Convert DATETIME strings to Unix timestamps for JavaScript
	if (!empty($msg->timestamp)) {
		$m['timestamp'] = $db->jdate($msg->timestamp);
	}
	// Add media_serve_url if message has media (including template messages with images)
	if (!empty($msg->rowid) && (!empty($msg->media_url) || !empty($msg->media_local_path))) {
		$m['media_serve_url'] = $mediaBaseUrl.'?id='.$msg->rowid;
		$m['media_download_url'] = $mediaBaseUrl.'?id='.$msg->rowid.'&action=download';
	}
	$messagesOut[] = $m;
}

// Get agent name
$agentName = '';
if (!empty($conversation->fk_user_assigned)) {
	$agentUser = new User($db);
	if ($agentUser->fetch((int) $conversation->fk_user_assigned) > 0) {
		$agentName = trim($agentUser->firstname.' '.$agentUser->lastname);
		if (empty($agentName)) {
			$agentName = $agentUser->login;
		}
	}
}

// Get conversation tags
$tagHandler = new WhatsAppTag($db);
$convTags = $tagHandler->getConversationTags($conversation_id);
$tagList = array();
foreach ($convTags as $t) {
	$tagList[] = array('id' => (int) $t->rowid, 'label' => $t->label, 'color' => $t->color);
}

// Get linked thirdparty info
$socInfo = null;
if (!empty($conversation->fk_soc) && $conversation->fk_soc > 0) {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$soc = new Societe($db);
	if ($soc->fetch($conversation->fk_soc) > 0) {
		$socInfo = array(
			'id' => (int) $soc->id,
			'name' => $soc->name,
			'url' => DOL_URL_ROOT.'/societe/card.php?socid='.$soc->id
		);
	}
}

// Return JSON (use JSON_UNESCAPED_UNICODE to preserve emojis)
echo json_encode(array(
	'success' => true,
	'has_more' => $has_more,
	'offset' => $offset,
	'conversation' => array(
		'rowid' => $conversation->id,
		'fk_line' => (int) $conversation->fk_line,
		'phone_number' => $conversation->phone_number,
		'contact_name' => $conversation->contact_name,
		'window_expires_at' => $conversation->window_expires_at,
		'status' => $conversation->status,
		'fk_soc' => (int) $conversation->fk_soc,
		'fk_user_assigned' => (int) $conversation->fk_user_assigned,
		'agent_name' => $agentName,
		'tags' => $tagList,
		'thirdparty' => $socInfo
	),
	'messages' => $messagesOut
), JSON_UNESCAPED_UNICODE);

$db->close();
