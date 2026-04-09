<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/send_message.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to send a message
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
require_once '../class/whatsapptemplate.class.php';
require_once '../class/whatsappevent.class.php';
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

header('Content-Type: application/json; charset=utf-8');

// Force utf8mb4 so emojis are stored correctly in DB
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

// Get parameters
$conversation_id = GETPOST('conversation_id', 'int');
$phone = GETPOST('phone', 'alpha');           // For new conversations (from hook / new conv modal)
$contact_name = GETPOST('contact_name', 'alphanohtml');
$fk_soc = GETPOST('fk_soc', 'int');
$line_id = GETPOST('line_id', 'int');
// Use 'none' filter to preserve emoji/Unicode characters, then sanitize manually
$message_text = GETPOST('message', 'none');
// Strip any HTML tags but keep Unicode (emojis)
if (!empty($message_text)) {
	$message_text = dol_string_nohtmltag($message_text, 0);
}
$template_name = GETPOST('template_name', 'alpha');
$template_id = GETPOST('template_id', 'int');
$template_params = GETPOST('template_params', 'restricthtml'); // JSON string or array

// If template_params is a JSON string, decode it
if (!empty($template_params) && is_string($template_params)) {
	$decoded = json_decode($template_params, true);
	if (is_array($decoded)) {
		$template_params = $decoded;
	}
}
if (empty($template_params)) {
	$template_params = array();
}

// If template_id is provided, fetch template name and body
$template_body = '';
$template_language = 'es';
$template_header_type = '';
$template_header_image_mode = 'on_send';
$template_header_media_url = '';
$template_header_media_local = '';
$template_variable_mapping = array();
if (!empty($template_id) && empty($template_name)) {
	$tpl = new WhatsAppTemplate($db);
	if ($tpl->fetch($template_id) > 0) {
		$template_name = $tpl->name;
		$template_body = $tpl->body_text;
		$template_language = $tpl->language ?: 'es';
		$template_header_type = $tpl->header_type;
		$template_header_image_mode = $tpl->header_image_mode ?: 'on_send';
		$template_header_media_url = $tpl->header_media_url;
		$template_header_media_local = $tpl->header_media_local;
		if (!empty($tpl->variable_mapping)) {
			$vmDec = json_decode($tpl->variable_mapping, true);
			if (is_array($vmDec)) $template_variable_mapping = $vmDec;
		}
		// Resolve line from template if not explicitly provided
		if (empty($line_id) && !empty($tpl->fk_line)) {
			$line_id = $tpl->fk_line;
		}
	} else {
		echo json_encode(array('success' => false, 'error' => 'Template not found'));
		exit;
	}
}

// ---------------------------------------------------------------
// When phone is provided without conversation_id, find or create
// a conversation on the fly (used by thirdparty card hook and
// the "New Conversation" modal).
// ---------------------------------------------------------------
if (!$conversation_id && !empty($phone)) {
	require_once '../class/whatsappconfig.class.php';

	// Normalize phone: keep only digits for consistent storage
	$phone = preg_replace('/[^0-9]/', '', $phone);

	// Resolve line
	if (empty($line_id)) {
		$cfgObj = new WhatsAppConfig($db);
		if ($cfgObj->fetchActive() > 0) {
			$line_id = $cfgObj->id;
		}
	}

	// Try to find existing conversation for this phone+line (uses normalized suffix matching)
	$conversation = new WhatsAppConversation($db);
	$existing = $conversation->fetchByPhone($phone, $line_id);

	if ($existing <= 0) {
		// Create a new conversation with normalized phone
		$conversation->phone_number = $phone;
		$conversation->contact_name = !empty($contact_name) ? $contact_name : $phone;
		$conversation->fk_line = $line_id;
		$conversation->fk_soc = (int) $fk_soc;
		$conversation->status = 'active';
		$res = $conversation->create($user);
		if ($res <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Could not create conversation'));
			exit;
		}
	}
	$conversation_id = $conversation->id;
}

if (!$conversation_id) {
	echo json_encode(array('success' => false, 'error' => 'Missing conversation_id or phone'));
	exit;
}

if (empty($message_text) && empty($template_name)) {
	echo json_encode(array('success' => false, 'error' => 'Message or template is required'));
	exit;
}

// Fetch conversation
$conversation = new WhatsAppConversation($db);
if ($conversation->fetch($conversation_id) <= 0) {
	echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
	exit;
}

// Initialize WhatsApp Manager (use conversation's line)
$manager = new WhatsAppManager($db, $conversation->fk_line > 0 ? $conversation->fk_line : 0);

// Auto-claim: if conversation is unassigned, assign to the sending agent
if (empty($conversation->fk_user_assigned)) {
	require_once '../class/whatsappassignment.class.php';
	$claimAssign = new WhatsAppAssignment($db);
	$claimAssign->setConversationAgents($conversation_id, array($user->id), $user->id);
	$conversation->fk_user_assigned = $user->id;
}

// Send message
if (!empty($template_name)) {
	// Server-side variable auto-resolve for unmapped/empty params
	if (!empty($template_variable_mapping)) {
		// Get thirdparty name if needed (company_name)
		$thirdpartyName = '';
		if (!empty($conversation->fk_soc)) {
			$sql = "SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".(int) $conversation->fk_soc;
			$resql = $db->query($sql);
			if ($resql && $obj = $db->fetch_object($resql)) {
				$thirdpartyName = $obj->nom;
			}
		}
		$operatorName = trim($user->firstname.' '.$user->lastname);
		if (empty($operatorName)) $operatorName = $user->login;

		foreach ($template_variable_mapping as $varIdx => $cfg) {
			$idx = intval($varIdx) - 1; // 0-based index for params array
			if ($idx < 0) continue;
			// Only fill if param is empty at this position
			if (!empty($template_params[$idx])) continue;
			$type = $cfg['type'] ?? 'free_text';
			$resolvedValue = '';
			switch ($type) {
				case 'contact_name':
					$resolvedValue = $conversation->contact_name ?: '';
					break;
				case 'operator_name':
					$resolvedValue = $operatorName;
					break;
				case 'company_name':
					$resolvedValue = $thirdpartyName;
					break;
				case 'phone':
					$resolvedValue = $conversation->phone_number ?: '';
					break;
				case 'date_today':
					$resolvedValue = dol_print_date(dol_now(), 'day');
					break;
				case 'fixed_text':
					$resolvedValue = $cfg['default_value'] ?? '';
					break;
				default:
					break; // free_text, url: not auto-resolved server-side
			}
			if (!empty($resolvedValue)) {
				// Ensure params array is big enough
				while (count($template_params) <= $idx) {
					$template_params[] = '';
				}
				$template_params[$idx] = $resolvedValue;
			}
		}
	}

	// Build header parameters if template has IMAGE/VIDEO/DOCUMENT header
	$headerParams = array();
	if (in_array($template_header_type, array('IMAGE', 'VIDEO', 'DOCUMENT'))) {
		$mediaType = strtolower($template_header_type); // image, video, document

		// Check for uploaded header image (on_send mode)
		if (!empty($_FILES['header_image']['tmp_name'])) {
			$uploadResult = $manager->uploadMedia(
				$_FILES['header_image']['tmp_name'],
				$_FILES['header_image']['type'] ?: 'image/jpeg'
			);
			if ($uploadResult['success']) {
				$headerParams = array(
					'type' => $mediaType,
					$mediaType => array('id' => $uploadResult['media_id'])
				);
			} else {
				// Upload failed — return clear error instead of sending without image
				echo json_encode(array(
					'success' => false,
					'error' => 'Error subiendo imagen a Meta: '.($uploadResult['error'] ?: 'Error desconocido')
				));
				exit;
			}
		}
		// Or use pre-uploaded media from template (on_template mode — Meta handle or URL)
		elseif ($template_header_image_mode == 'on_template' && !empty($template_header_media_url)) {
			if (strpos($template_header_media_url, 'http') === 0) {
				// URL stored (legacy) — use as link
				$headerParams = array(
					'type' => $mediaType,
					$mediaType => array('link' => $template_header_media_url)
				);
			} else {
				// Numeric media_id obtained during sync — pass as id
				$headerParams = array(
					'type' => $mediaType,
					$mediaType => array('id' => $template_header_media_url)
				);
			}
		}
		// Or upload local file to Meta on-the-fly (on_template mode with local file)
		elseif ($template_header_image_mode == 'on_template' && !empty($template_header_media_local) && file_exists($template_header_media_local)) {
			$mimeType = mime_content_type($template_header_media_local) ?: 'application/octet-stream';
			$uploadResult = $manager->uploadMedia($template_header_media_local, $mimeType);
			if ($uploadResult['success']) {
				$headerParams = array(
					'type' => $mediaType,
					$mediaType => array('id' => $uploadResult['media_id'])
				);
			}
		}

		// Guard: if a media header is required but we have nothing to send, abort with clear error
		if (empty($headerParams)) {
			if ($template_header_image_mode === 'on_send') {
				$errMsg = 'Esta plantilla requiere que cargues una imagen/archivo antes de enviar.';
			} else {
				$errMsg = 'No hay imagen configurada para esta plantilla. Edita la plantilla y sube una imagen, o sincroniza para obtenerla de Meta.';
			}
			echo json_encode(array('success' => false, 'error' => $errMsg));
			exit;
		}
	}

	// Send template message — Meta requires the slugified name
	$metaTemplateName = WhatsAppTemplate::slugify($template_name);
	$result = $manager->sendTemplateMessage(
		$conversation->phone_number,
		$metaTemplateName,
		$template_params,
		$template_language,
		$headerParams
	);
} else {
	// Check if window is open
	if (!$manager->isConversationWindowOpen($conversation_id)) {
		echo json_encode(array(
			'success' => false,
			'error' => 'Message window expired. Use a template instead.'
		));
		exit;
	}
	
	// Send text message
	$result = $manager->sendTextMessage(
		$conversation->phone_number,
		$message_text
	);
}

if ($result['success']) {
	// Save message to database
	$message = new WhatsAppMessage($db);
	$message->message_id = $result['message_id'];
	$message->fk_conversation = $conversation_id;
	$message->fk_line = $conversation->fk_line;
	$message->direction = 'outbound';
	$message->message_type = !empty($template_name) ? 'template' : 'text';
	// For template messages, build a readable preview of the sent content
	if (!empty($template_name) && !empty($template_body)) {
		$preview = $template_body;
		foreach ($template_params as $i => $param) {
			$preview = str_replace('{{'.($i + 1).'}}', $param, $preview);
		}
		$message->content = $preview;
	} else {
		$message->content = $message_text;
	}
	$message->template_name = $template_name;
	$message->template_params = !empty($template_params) ? json_encode($template_params) : null;
	$message->status = 'sent';
	$message->fk_user_sender = $user->id;
	$message->timestamp = dol_now();

	// Save media fields for template messages with images so agents can see them in chat
	if (!empty($template_name) && !empty($headerParams) && !empty($headerParams['type'])) {
		$hType = $headerParams['type']; // image, video, document
		if ($hType === 'image') {
			$message->media_mime_type = 'image/jpeg';
		} elseif ($hType === 'video') {
			$message->media_mime_type = 'video/mp4';
		} elseif ($hType === 'document') {
			$message->media_mime_type = 'application/pdf';
		}
		// Store the Meta media ID or URL so media.php can serve it
		if (!empty($headerParams[$hType]['id'])) {
			$message->media_url = $headerParams[$hType]['id'];
		} elseif (!empty($headerParams[$hType]['link'])) {
			$message->media_url = $headerParams[$hType]['link'];
		}
		// Copy uploaded file locally for reliable serving
		if (!empty($_FILES['header_image']['tmp_name']) && file_exists($_FILES['header_image']['tmp_name'])) {
			$uploadDir = DOL_DATA_ROOT.'/whatsappdati/media';
			if (!is_dir($uploadDir)) {
				dol_mkdir($uploadDir);
			}
			$localName = dol_now().'_'.dol_sanitizeFileName($_FILES['header_image']['name'] ?: 'header.jpg');
			$localPath = $uploadDir.'/'.$localName;
			if (move_uploaded_file($_FILES['header_image']['tmp_name'], $localPath)) {
				$message->media_local_path = $localPath;
			}
		}
	}

	$message->create($user);
	
	// Emit real-time event for outbound message
	$eventEmitter = new WhatsAppEvent($db);
	$eventEmitter->emitNewMessage(
		$conversation_id,
		'outbound',
		$message->message_type,
		mb_substr($message->content, 0, 80),
		$conversation->phone_number,
		$conversation->contact_name,
		$conversation->fk_line
	);
	
	echo json_encode(array(
		'success' => true,
		'message_id' => $result['message_id'],
		'conversation_id' => (int) $conversation_id
	));
} else {
	$errorDetail = !empty($result['error']) ? $result['error'] : 'Unknown error';
	dol_syslog("WhatsAppDati send_message FAILED to=".$conversation->phone_number." template=".$template_name." error=".$errorDetail, LOG_ERR);
	echo json_encode(array(
		'success' => false,
		'error' => 'Error al enviar mensaje: '.$errorDetail,
		'debug' => array(
			'template_name'   => $metaTemplateName,
			'header_type'     => $template_header_type,
			'header_mode'     => $template_header_image_mode,
			'header_params'   => $headerParams,
			'body_params'     => $template_params,
			'language'        => $template_language,
			'meta_payload'    => $result['meta_payload'] ?? null,
			'meta_error_details' => $result['error_details'] ?? null,
			'meta_error_full' => $result['error_full'] ?? null,
		)
	));
}

$db->close();
