<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/sse.php
 * \ingroup    whatsappdati
 * \brief      Server-Sent Events endpoint for real-time updates
 *
 * "Short SSE" approach for compatibility with Plesk/nginx reverse proxy:
 * Instead of keeping a long-running PHP process open (which nginx buffers),
 * this endpoint checks the database ONCE for new events, sends them, and exits.
 * The browser's EventSource automatically reconnects using the "retry" directive.
 *
 * Flow:
 *  1. Client opens EventSource → connects to this endpoint
 *  2. Server sends "connected" event + any pending DB changes → exits
 *  3. nginx delivers the complete response (no buffering issue)
 *  4. EventSource sees connection close → waits "retry" ms → reconnects
 *  5. On reconnect, client sends Last-Event-ID header with last msg rowid
 *  6. Server checks for events newer than that ID → sends them → exits
 *  7. Repeat from step 4
 *
 * Result: ~2s latency for new messages (like polling but using SSE protocol,
 * with automatic reconnection and event ID tracking built-in).
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

// Security check
if (empty($user->id)) {
	http_response_code(403);
	exit;
}

// Check module permissions
if (empty($user->rights->whatsappdati->conversation->read)) {
	http_response_code(403);
	exit;
}

// Release session lock immediately
if (session_status() === PHP_SESSION_ACTIVE) {
	session_write_close();
}

// Check if SSE/realtime is enabled
$realtimeEnabled = !empty($conf->global->WHATSAPPDATI_REALTIME_MODE)
	&& $conf->global->WHATSAPPDATI_REALTIME_MODE !== 'polling';
if (!$realtimeEnabled) {
	header('Content-Type: text/plain');
	http_response_code(404);
	echo 'SSE disabled';
	$db->close();
	exit;
}

// ---- SSE Headers ----
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

// Disable compression
if (function_exists('apache_setenv')) {
	@apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');

// Clear Dolibarr output buffers (discard any buffered HTML)
while (ob_get_level() > 0) {
	ob_end_clean();
}

// Parameters
$lineId = GETPOST('line_id', 'int') ?: 0;
$entity = (int) $conf->entity;

// ---- Get client's last known state ----
// EventSource sends Last-Event-ID automatically on reconnect.
// We use composite IDs: "msgId:convTs" so both values survive reconnection.
$lastMsgId = 0;
$lastConvTs = 0;

$compositeId = '';
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
	$compositeId = $_SERVER['HTTP_LAST_EVENT_ID'];
}
if (empty($compositeId) && !empty($_GET['last_id'])) {
	// Sanitized below via explode + (int) casting
	$compositeId = preg_replace('/[^0-9:]/', '', $_GET['last_id']);
}

if (!empty($compositeId) && strpos($compositeId, ':') !== false) {
	$parts = explode(':', $compositeId, 2);
	$lastMsgId = (int) $parts[0];
	$lastConvTs = (int) $parts[1];
} elseif (!empty($compositeId)) {
	$lastMsgId = (int) $compositeId;
}

// ---- Tell client to reconnect in 2 seconds after this response ends ----
echo "retry: 2000\n\n";

// ---- First connection (no Last-Event-ID) ----
if ($lastMsgId == 0) {
	// Get current max message ID as baseline
	$sqlMax = "SELECT COALESCE(MAX(rowid), 0) as maxid FROM ".MAIN_DB_PREFIX."whatsapp_messages"
		. " WHERE entity = ".(int)$entity;
	$resMax = $db->query($sqlMax);
	$maxId = 0;
	if ($resMax) {
		$obj = $db->fetch_object($resMax);
		if ($obj) {
			$maxId = (int) $obj->maxid;
		}
	}

	// Get current max conversation timestamp
	$sqlMaxConv = "SELECT COALESCE(MAX(UNIX_TIMESTAMP(date_modification)), 0) as maxts"
		. " FROM ".MAIN_DB_PREFIX."whatsapp_conversations WHERE entity = ".(int)$entity;
	$resMaxConv = $db->query($sqlMaxConv);
	$convTs = 0;
	if ($resMaxConv) {
		$obj = $db->fetch_object($resMaxConv);
		if ($obj) {
			$convTs = (int) $obj->maxts;
		}
	}

	// Send connected event with baseline composite ID
	echo "id: " . $maxId . ":" . $convTs . "\n";
	echo "event: connected\n";
	echo "data: " . json_encode(array(
		'time' => time(),
		'mode' => 'sse-short',
		'last_msg_id' => $maxId,
		'last_conv_ts' => $convTs
	)) . "\n\n";

	$db->close();
	exit;
}

// ---- Reconnection: check for new events since last composite ID ----
$hadEvents = false;
$currentMsgId = $lastMsgId;
$currentConvTs = $lastConvTs;

// 1) New messages since last msg ID
$sqlNew = "SELECT m.rowid, m.fk_conversation, m.direction, m.message_type,"
	. " m.content, m.fk_line, m.status,"
	. " c.phone_number, c.contact_name"
	. " FROM ".MAIN_DB_PREFIX."whatsapp_messages m"
	. " LEFT JOIN ".MAIN_DB_PREFIX."whatsapp_conversations c ON c.rowid = m.fk_conversation"
	. " WHERE m.rowid > ".(int)$lastMsgId
	. " AND m.entity = ".(int)$entity;
if ($lineId > 0) {
	$sqlNew .= " AND m.fk_line = ".(int)$lineId;
}
$sqlNew .= " ORDER BY m.rowid ASC LIMIT 50";

$resNew = $db->query($sqlNew);
if ($resNew) {
	while ($obj = $db->fetch_object($resNew)) {
		$msgRowId = (int) $obj->rowid;
		if ($msgRowId > $currentMsgId) {
			$currentMsgId = $msgRowId;
		}

		$eventType = ($obj->direction === 'inbound') ? 'new_message' : 'conversation_update';
		echo "id: " . $currentMsgId . ":" . $currentConvTs . "\n";
		echo "event: " . $eventType . "\n";
		echo "data: " . json_encode(array(
			'conversation_id' => (int) $obj->fk_conversation,
			'direction' => $obj->direction,
			'message_type' => $obj->message_type,
			'preview' => mb_substr($obj->content ?: '', 0, 100),
			'phone' => $obj->phone_number ?: '',
			'contact_name' => $obj->contact_name ?: '',
			'fk_line' => (int) $obj->fk_line,
			'status' => $obj->status
		)) . "\n\n";
		$hadEvents = true;
	}
}

// 2) Conversation metadata changes
if ($lastConvTs > 0) {
	$sqlConvUpd = "SELECT rowid, UNIX_TIMESTAMP(date_modification) as ts"
		. " FROM ".MAIN_DB_PREFIX."whatsapp_conversations"
		. " WHERE entity = ".(int)$entity
		. " AND UNIX_TIMESTAMP(date_modification) > ".(int)$lastConvTs;
	if ($lineId > 0) {
		$sqlConvUpd .= " AND fk_line = ".(int)$lineId;
	}
	$sqlConvUpd .= " ORDER BY date_modification ASC LIMIT 20";

	$resConvUpd = $db->query($sqlConvUpd);
	if ($resConvUpd) {
		while ($obj = $db->fetch_object($resConvUpd)) {
			$ts = (int) $obj->ts;
			if ($ts > $currentConvTs) {
				$currentConvTs = $ts;
			}
			echo "id: " . $currentMsgId . ":" . $currentConvTs . "\n";
			echo "event: conversation_update\n";
			echo "data: " . json_encode(array(
				'conversation_id' => (int) $obj->rowid,
				'update_type' => 'metadata'
			)) . "\n\n";
			$hadEvents = true;
		}
	}
}

// 3) If no events, send heartbeat (keeps EventSource happy)
if (!$hadEvents) {
	echo "event: heartbeat\n";
	echo "data: " . json_encode(array('time' => time())) . "\n\n";
}

$db->close();
exit;
