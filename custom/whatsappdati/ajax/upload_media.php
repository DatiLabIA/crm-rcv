<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/upload_media.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to upload and send a media message
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
require_once '../class/whatsappmanager.class.php';
require_once '../class/whatsappevent.class.php';
require_once '../class/whatsappoggemuxer.class.php';
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

header('Content-Type: application/json; charset=utf-8');

// Force utf8mb4 so emojis in captions are stored correctly
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}

// CSRF validation (this endpoint is mutation-only)
whatsappdatiCheckCSRFToken();

// Access control
if (!$user->rights->whatsappdati->message->send) {
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(array('success' => false, 'error' => 'POST required'));
	exit;
}

// Get parameters
$conversation_id = GETPOST('conversation_id', 'int');
$caption = GETPOST('caption', 'restricthtml');

if (!$conversation_id) {
	echo json_encode(array('success' => false, 'error' => 'Missing conversation_id'));
	exit;
}

// Check file upload
if (empty($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
	$uploadErrors = array(
		UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
		UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
		UPLOAD_ERR_PARTIAL => 'File partially uploaded',
		UPLOAD_ERR_NO_FILE => 'No file uploaded',
		UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
		UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
	);
	$errCode = !empty($_FILES['media_file']) ? $_FILES['media_file']['error'] : UPLOAD_ERR_NO_FILE;
	$errMsg = $uploadErrors[$errCode] ?? 'Unknown upload error';
	echo json_encode(array('success' => false, 'error' => $errMsg));
	exit;
}

$file = $_FILES['media_file'];
$originalName = $file['name'];
$tmpPath = $file['tmp_name'];
$fileSize = $file['size'];

// Server-side MIME detection (don't trust client-supplied type)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
if (empty($mimeType) || $mimeType === 'application/octet-stream') {
	// Fallback to client type only if finfo couldn't detect
	$mimeType = $file['type'];
}

// Voice notes: browsers may record as video/webm — normalize to audio/*
$isVoiceNoteRaw = GETPOST('is_voice_note', 'int') ? true : false;
if ($isVoiceNoteRaw && strpos($mimeType, 'video/') === 0) {
	$mimeType = str_replace('video/', 'audio/', $mimeType);
	dol_syslog('WhatsApp upload_media: voice note MIME corrected to '.$mimeType, LOG_DEBUG);
}

// Validate MIME type
$mediaType = WhatsAppManager::getMediaTypeFromMime($mimeType);
if (empty($mediaType)) {
	echo json_encode(array('success' => false, 'error' => 'Unsupported file type: '.$mimeType));
	exit;
}

// Validate file size (Meta limits: image 5MB, video 16MB, audio 16MB, document 100MB)
$maxSizes = array(
	'image' => 5 * 1024 * 1024,
	'video' => 16 * 1024 * 1024,
	'audio' => 16 * 1024 * 1024,
	'document' => 100 * 1024 * 1024,
);
if ($fileSize > $maxSizes[$mediaType]) {
	$maxMB = round($maxSizes[$mediaType] / (1024 * 1024));
	echo json_encode(array('success' => false, 'error' => "File too large. Maximum: {$maxMB}MB for {$mediaType}"));
	exit;
}

// Fetch conversation
$conversation = new WhatsAppConversation($db);
if ($conversation->fetch($conversation_id) <= 0) {
	echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
	exit;
}

// Check 24h window (media messages require open window, like text)
$manager = new WhatsAppManager($db, $conversation->fk_line > 0 ? $conversation->fk_line : 0);
if (!$manager->isConversationWindowOpen($conversation_id)) {
	echo json_encode(array(
		'success' => false,
		'error' => 'Message window expired. Use a template to reopen the conversation.'
	));
	exit;
}

// Save file locally
$dir = $conf->whatsappdati->dir_output.'/media/'.$conversation_id;
if (!is_dir($dir)) {
	dol_mkdir($dir);
}

// Sanitize filename
$safeName = dol_sanitizeFileName($originalName);
$localPath = $dir.'/'.$safeName;

// Avoid overwriting: append timestamp if exists
if (file_exists($localPath)) {
	$pathInfo = pathinfo($safeName);
	$safeName = $pathInfo['filename'].'_'.dol_now().'.'.$pathInfo['extension'];
	$localPath = $dir.'/'.$safeName;
}

if (!move_uploaded_file($tmpPath, $localPath)) {
	echo json_encode(array('success' => false, 'error' => 'Failed to save file'));
	exit;
}

// Voice note: MUST convert to ogg/opus for WhatsApp compatibility
// Chrome records webm(opus) or mp4(opus), Firefox records ogg(opus)
// Meta/WhatsApp only reliably plays ogg(opus) for voice notes
$isVoiceNote = $isVoiceNoteRaw;
if ($isVoiceNote && $mimeType !== 'audio/ogg') {
	$oggPath = preg_replace('/\.(webm|m4a|mp4|mp3|aac|wav)$/i', '.ogg', $localPath);
	if ($oggPath === $localPath) {
		$oggPath = $localPath.'.ogg';
	}

	$converted = false;

	// Method 1: Pure PHP remuxer (no external tools needed)
	// Works for WebM(Opus) files produced by Chrome/Edge/Opera
	if (!$converted && WhatsAppOggMuxer::canConvert($localPath)) {
		if (WhatsAppOggMuxer::convert($localPath, $oggPath)) {
			$converted = true;
			dol_syslog('WhatsApp upload_media: converted '.$mimeType.'→ogg via PHP muxer (no ffmpeg needed)', LOG_DEBUG);
		} else {
			dol_syslog('WhatsApp upload_media: PHP muxer failed, trying ffmpeg', LOG_WARNING);
			@unlink($oggPath);
		}
	}

	// Method 2: ffmpeg (handles any audio format)
	if (!$converted) {
		$ffmpegBins = array('ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg');
		foreach ($ffmpegBins as $ffmpegBin) {
			// Try codec copy first (fast, no re-encoding)
			$cmd = escapeshellarg($ffmpegBin).' -y -i '.escapeshellarg($localPath).' -c:a copy -f ogg '.escapeshellarg($oggPath).' 2>&1';
			$outputArr = array();
			$ret = -1;
			@exec($cmd, $outputArr, $ret);
			if ($ret === 0 && file_exists($oggPath) && filesize($oggPath) > 0) {
				$converted = true;
				dol_syslog('WhatsApp upload_media: converted '.$mimeType.'→ogg via '.$ffmpegBin.' (copy)', LOG_DEBUG);
				break;
			}
			@unlink($oggPath);

			// Try re-encoding with libopus
			$cmd = escapeshellarg($ffmpegBin).' -y -i '.escapeshellarg($localPath).' -c:a libopus -b:a 64k -vn -f ogg '.escapeshellarg($oggPath).' 2>&1';
			$outputArr = array();
			$ret = -1;
			@exec($cmd, $outputArr, $ret);
			if ($ret === 0 && file_exists($oggPath) && filesize($oggPath) > 0) {
				$converted = true;
				dol_syslog('WhatsApp upload_media: converted '.$mimeType.'→ogg via '.$ffmpegBin.' (libopus)', LOG_DEBUG);
				break;
			}
			@unlink($oggPath);
		}
	}

	// Method 3: avconv (ffmpeg fork on some Debian/Ubuntu)
	if (!$converted) {
		$cmd = 'avconv -y -i '.escapeshellarg($localPath).' -c:a copy -f ogg '.escapeshellarg($oggPath).' 2>&1';
		$outputArr = array();
		$ret = -1;
		@exec($cmd, $outputArr, $ret);
		if ($ret === 0 && file_exists($oggPath) && filesize($oggPath) > 0) {
			$converted = true;
			dol_syslog('WhatsApp upload_media: converted '.$mimeType.'→ogg via avconv', LOG_DEBUG);
		} else {
			@unlink($oggPath);
		}
	}

	if ($converted) {
		@unlink($localPath);
		$localPath = $oggPath;
		$mimeType = 'audio/ogg';
		$safeName = basename($oggPath);
	} else {
		// All methods failed
		@unlink($localPath);
		dol_syslog('WhatsApp upload_media: all conversion methods failed for '.$mimeType, LOG_ERR);
		echo json_encode(array(
			'success' => false,
			'error' => 'No se pudo convertir la nota de voz. Intente usar el navegador Firefox que graba directamente en formato compatible.'
		));
		exit;
	}
}

// Upload to Meta API
$uploadResult = $manager->uploadMedia($localPath, $mimeType);
if (!$uploadResult['success']) {
	// Clean up local file on failure
	@unlink($localPath);
	echo json_encode(array('success' => false, 'error' => 'Meta upload failed: '.$uploadResult['error']));
	exit;
}

$metaMediaId = $uploadResult['media_id'];

// Send media message via API
$sendResult = $manager->sendMediaMessage(
	$conversation->phone_number,
	$mediaType,
	$metaMediaId,
	$caption,
	($mediaType === 'document') ? $originalName : ''
);

if (!$sendResult['success']) {
	echo json_encode(array('success' => false, 'error' => 'Send failed: '.$sendResult['error']));
	exit;
}

// Build content preview
$typeLabels = array(
	'image' => '📷 Imagen',
	'video' => '🎬 Video',
	'audio' => $isVoiceNote ? '🎤 Nota de voz' : '🎵 Audio',
	'document' => '📄 '.$originalName
);
$contentPreview = $typeLabels[$mediaType];
if (!empty($caption)) {
	$contentPreview .= ': '.$caption;
}

// Save message to database
$message = new WhatsAppMessage($db);
$message->message_id = $sendResult['message_id'];
$message->fk_conversation = $conversation_id;
$message->direction = 'outbound';
$message->message_type = $mediaType;
$message->content = $contentPreview;
$message->media_url = $metaMediaId;
$message->media_mime_type = $mimeType;
$message->media_filename = $originalName;
$message->media_local_path = $localPath;
$message->fk_line = $conversation->fk_line;
$message->status = 'sent';
$message->fk_user_sender = $user->id;
$message->timestamp = dol_now();
$message->create($user);

// Emit real-time event for outbound media message
$eventEmitter = new WhatsAppEvent($db);
$eventEmitter->emitNewMessage(
	$conversation_id,
	'outbound',
	$mediaType,
	$contentPreview,
	$conversation->phone_number,
	$conversation->contact_name,
	$conversation->fk_line
);

echo json_encode(array(
	'success' => true,
	'message_id' => $sendResult['message_id'],
	'media_type' => $mediaType
));

$db->close();
