<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/widget.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for the floating chat widget.
 *             Returns only conversations visible to the current agent.
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
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

header('Content-Type: application/json');

// Force utf8mb4 so emojis are read correctly from DB
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}

// Access control — must have read permission
if (!$user->rights->whatsappdati->conversation->read) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

$action = GETPOST('action', 'aZ09');

// ------------------------------------------------------------------
// Helper: build the SQL WHERE clause that filters conversations
// so that each agent only sees their own assigned conversations.
// Admin users ($user->admin) see everything.
// ------------------------------------------------------------------
function _widgetConversationFilter($user)
{
	global $conf, $db;

	$where = " t.entity = ".((int) $conf->entity);
	$where .= " AND t.status = 'active'";

	// Non-admin users only see conversations assigned to them or unassigned
	if (empty($user->admin)) {
		$where .= " AND (t.fk_user_assigned = ".((int) $user->id)." OR t.fk_user_assigned IS NULL OR t.fk_user_assigned = 0)";
	}

	return $where;
}

// ==================================================================
// ACTION: unread_count — lightweight poll to update badge
// ==================================================================
if ($action === 'unread_count') {
	$where = _widgetConversationFilter($user);

	$sql = "SELECT COALESCE(SUM(t.unread_count), 0) as total_unread,";
	$sql .= " COUNT(CASE WHEN t.unread_count > 0 THEN 1 END) as conv_with_unread";
	$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_conversations as t";
	$sql .= " WHERE ".$where;

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		echo json_encode(array(
			'success' => true,
			'total_unread' => (int) $obj->total_unread,
			'conversations_with_unread' => (int) $obj->conv_with_unread
		));
	} else {
		echo json_encode(array('success' => false, 'error' => 'Database error'));
	}
	$db->close();
	exit;
}

// ==================================================================
// ACTION: conversations — return conversations visible to this agent
// ==================================================================
if ($action === 'conversations') {
	$where = _widgetConversationFilter($user);
	$filterUnread = GETPOST('unread_only', 'int');

	if ($filterUnread) {
		$where .= " AND t.unread_count > 0";
	}

	$sql = "SELECT t.rowid, t.fk_line, t.phone_number, t.contact_name,";
	$sql .= " t.fk_user_assigned, t.last_message_date, t.last_message_preview,";
	$sql .= " t.unread_count, t.window_expires_at";
	$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_conversations as t";
	$sql .= " WHERE ".$where;
	$sql .= " ORDER BY t.last_message_date DESC";
	$sql .= " LIMIT 30";

	$resql = $db->query($sql);
	$conversations = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$conversations[] = array(
				'rowid'                => (int) $obj->rowid,
				'fk_line'              => (int) $obj->fk_line,
				'phone_number'         => $obj->phone_number,
				'contact_name'         => $obj->contact_name,
				'fk_user_assigned'     => (int) $obj->fk_user_assigned,
				'last_message_date'    => $obj->last_message_date,
				'last_message_preview' => $obj->last_message_preview,
				'unread_count'         => (int) $obj->unread_count,
				'window_expires_at'    => $obj->window_expires_at
			);
		}
	}

	echo json_encode(array('success' => true, 'conversations' => $conversations));
	$db->close();
	exit;
}

// ==================================================================
// ACTION: messages — return messages for a specific conversation
// (respects agent visibility)
// ==================================================================
if ($action === 'messages') {
	$conversation_id = GETPOST('conversation_id', 'int');
	if (!$conversation_id) {
		echo json_encode(array('success' => false, 'error' => 'Missing conversation_id'));
		exit;
	}

	// Verify the agent has access to this conversation
	$where = _widgetConversationFilter($user);
	$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."whatsapp_conversations as t";
	$sql .= " WHERE ".$where." AND t.rowid = ".((int) $conversation_id);
	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) === 0) {
		echo json_encode(array('success' => false, 'error' => 'Access denied to this conversation'));
		exit;
	}

	// Mark as read
	$sqlUp = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations SET unread_count = 0 WHERE rowid = ".((int) $conversation_id);
	$db->query($sqlUp);

	// Fetch conversation metadata
	$conversation = new WhatsAppConversation($db);
	$conversation->fetch($conversation_id);

	// Fetch messages (last 80)
	$msgObj = new WhatsAppMessage($db);
	$messages = $msgObj->fetchByConversation($conversation_id, 80, 0);

	$mediaBaseUrl = dol_buildpath('/custom/whatsappdati/ajax/media.php', 1);
	$messagesOut = array();
	foreach ($messages as $msg) {
		$m = (array) $msg;
		if (in_array($msg->message_type, array('image', 'video', 'audio', 'document')) && !empty($msg->rowid)) {
			$m['media_serve_url'] = $mediaBaseUrl.'?id='.$msg->rowid;
			$m['media_download_url'] = $mediaBaseUrl.'?id='.$msg->rowid.'&action=download';
		}
		$messagesOut[] = $m;
	}

	echo json_encode(array(
		'success' => true,
		'conversation' => array(
			'rowid'             => (int) $conversation->id,
			'phone_number'      => $conversation->phone_number,
			'contact_name'      => $conversation->contact_name,
			'window_expires_at' => $conversation->window_expires_at,
			'fk_line'           => (int) $conversation->fk_line,
			'fk_user_assigned'  => (int) $conversation->fk_user_assigned
		),
		'messages' => $messagesOut
	));
	$db->close();
	exit;
}

// ==================================================================
// ACTION: send — send a text message from the widget
// ==================================================================
if ($action === 'send') {
	require_once '../class/whatsappmanager.class.php';
	require_once '../class/whatsappevent.class.php';
	require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

	// CSRF validation
	whatsappdatiCheckCSRFToken();

	if (!$user->rights->whatsappdati->message->send) {
		echo json_encode(array('success' => false, 'error' => 'Access denied'));
		exit;
	}

	$conversation_id = GETPOST('conversation_id', 'int');
	$message_text = GETPOST('message', 'restricthtml');

	if (!$conversation_id || empty($message_text)) {
		echo json_encode(array('success' => false, 'error' => 'Missing parameters'));
		exit;
	}

	// Verify agent has access
	$where = _widgetConversationFilter($user);
	$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."whatsapp_conversations as t";
	$sql .= " WHERE ".$where." AND t.rowid = ".((int) $conversation_id);
	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) === 0) {
		echo json_encode(array('success' => false, 'error' => 'Access denied to this conversation'));
		exit;
	}

	// Fetch conversation
	$conversation = new WhatsAppConversation($db);
	if ($conversation->fetch($conversation_id) <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
		exit;
	}

	// Initialize manager with the conversation's line
	$manager = new WhatsAppManager($db, $conversation->fk_line > 0 ? $conversation->fk_line : 0);

	// Check 24h window
	if (!$manager->isConversationWindowOpen($conversation_id)) {
		echo json_encode(array('success' => false, 'error' => 'window_expired'));
		exit;
	}

	// Send
	$result = $manager->sendTextMessage($conversation->phone_number, $message_text);

	if ($result['success']) {
		$message = new WhatsAppMessage($db);
		$message->message_id = $result['message_id'];
		$message->fk_conversation = $conversation_id;
		$message->fk_line = $conversation->fk_line;
		$message->direction = 'outbound';
		$message->message_type = 'text';
		$message->content = $message_text;
		$message->status = 'sent';
		$message->fk_user_sender = $user->id;
		$message->timestamp = dol_now();
		$message->create($user);

		// Emit SSE event
		$eventEmitter = new WhatsAppEvent($db);
		$eventEmitter->emitNewMessage(
			$conversation_id,
			'outbound',
			'text',
			mb_substr($message_text, 0, 80),
			$conversation->phone_number,
			$conversation->contact_name,
			$conversation->fk_line
		);

		echo json_encode(array('success' => true, 'message_id' => $result['message_id']));
	} else {
		echo json_encode(array('success' => false, 'error' => $result['error']));
	}
	$db->close();
	exit;
}

// Unknown action
echo json_encode(array('success' => false, 'error' => 'Unknown action'));
$db->close();
