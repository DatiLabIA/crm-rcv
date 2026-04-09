<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/media.php
 * \ingroup    whatsappdati
 * \brief      Serve media files securely (checks auth + permissions)
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

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	http_response_code(403);
	exit('Access denied');
}

// Get parameters
$messageId = GETPOST('id', 'int');

if (empty($messageId)) {
	http_response_code(400);
	exit('Missing message id');
}

require_once '../class/whatsappmessage.class.php';
require_once '../class/whatsappmanager.class.php';

$message = new WhatsAppMessage($db);
if ($message->fetch($messageId) <= 0) {
	http_response_code(404);
	exit('Message not found');
}

// Check if we have a local file
$localPath = $message->media_local_path;

// For outbound messages with local path, verify the file exists.
// If the stored path doesn't match (e.g., dir_output changed), try to reconstruct it.
if (!empty($localPath) && !file_exists($localPath)) {
	// Try reconstructing path from current dir_output
	$basename = basename($localPath);
	$altPath = $conf->whatsappdati->dir_output.'/media/'.$message->fk_conversation.'/'.$basename;
	if (file_exists($altPath)) {
		$localPath = $altPath;
		// Update stored path for future requests
		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_messages SET media_local_path = '".$db->escape($altPath)."' WHERE rowid = ".((int) $messageId);
		$db->query($sql);
	} else {
		dol_syslog('WhatsApp media.php: file not found at stored path ('.$message->media_local_path.') nor reconstructed path ('.$altPath.')', LOG_WARNING);
	}
}

// If no local path or file doesn't exist, try to download from Meta API
if ((empty($localPath) || !file_exists($localPath)) && !empty($message->media_url)) {
	$manager = new WhatsAppManager($db, $message->fk_line > 0 ? $message->fk_line : 0);
	$result = $manager->downloadMedia(
		$message->media_url,
		$message->fk_conversation,
		$message->media_filename
	);
	
	if ($result['success']) {
		$localPath = $result['local_path'];
		
		// Update the message with local path
		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_messages SET";
		$sql .= " media_local_path = '".$db->escape($localPath)."'";
		if (!empty($result['mime_type']) && empty($message->media_mime_type)) {
			$sql .= ", media_mime_type = '".$db->escape($result['mime_type'])."'";
		}
		if (!empty($result['filename']) && empty($message->media_filename)) {
			$sql .= ", media_filename = '".$db->escape($result['filename'])."'";
		}
		$sql .= " WHERE rowid = ".((int) $messageId);
		$db->query($sql);
	}
}

if (empty($localPath) || !file_exists($localPath)) {
	dol_syslog('WhatsApp media.php: FAILED to serve media for message rowid='.$messageId
		.' type='.$message->message_type.' dir='.$message->direction
		.' stored_path='.($message->media_local_path ?: 'NULL')
		.' media_url='.($message->media_url ?: 'NULL')
		.' dir_output='.($conf->whatsappdati->dir_output ?: 'NOT_SET'), LOG_ERR);
	http_response_code(404);
	exit('Media file not available');
}

// Determine content type
$mimeType = $message->media_mime_type;
if (empty($mimeType)) {
	$mimeType = mime_content_type($localPath);
}

// Get action (view or download)
$action = GETPOST('action', 'alpha');

// Serve file - sanitize MIME type to prevent header injection
$safeMimeType = preg_replace('/[^\w\/\-\+\.]/', '', $mimeType);
header('Content-Type: '.$safeMimeType);
header('Content-Length: '.filesize($localPath));

if ($action === 'download' || $message->message_type === 'document') {
	$filename = !empty($message->media_filename) ? $message->media_filename : basename($localPath);
	// Sanitize filename: remove path traversal and header injection characters
	$filename = basename($filename);
	$filename = preg_replace('/[^\w\s.\-]/', '_', $filename);
	$filename = str_replace(array("\r", "\n", "\0"), '', $filename);
	header('Content-Disposition: attachment; filename="'.$filename.'"');
} else {
	header('Content-Disposition: inline');
}

// Cache for 1 hour
header('Cache-Control: private, max-age=3600');

readfile($localPath);

$db->close();
exit;
