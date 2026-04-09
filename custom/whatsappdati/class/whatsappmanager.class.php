<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappmanager.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Manager - Core class for WhatsApp Business API integration
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Class for WhatsApp Manager
 */
class WhatsAppManager
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var string WhatsApp API Base URL
	 */
	private $api_base_url = 'https://graph.facebook.com/v25.0';

	/**
	 * @var WhatsAppConfig Configuration object (current line)
	 */
	private $config;

	/**
	 * @var int Line ID to use (0 = first active)
	 */
	private $lineId = 0;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $lineId Optional line ID to use (0 = first active)
	 */
	public function __construct($db, $lineId = 0)
	{
		$this->db = $db;
		$this->lineId = (int) $lineId;
	}

	/**
	 * Load WhatsApp configuration for the configured line
	 *
	 * @return int <0 if KO, >0 if OK
	 */
	public function loadConfig()
	{
		require_once dol_buildpath('/whatsappdati/class/whatsappconfig.class.php', 0);
		
		$this->config = new WhatsAppConfig($this->db);

		if ($this->lineId > 0) {
			$result = $this->config->fetch($this->lineId);
		} else {
			$result = $this->config->fetchActive();
		}
		
		if ($result <= 0) {
			$this->errors[] = 'No active WhatsApp configuration found';
			return -1;
		}
		
		return 1;
	}

	/**
	 * Get the currently loaded config (line)
	 *
	 * @return WhatsAppConfig|null
	 */
	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Set which line to use
	 *
	 * @param int $lineId Line ID
	 */
	public function setLineId($lineId)
	{
		$this->lineId = (int) $lineId;
		$this->config = null; // Force reload on next call
	}

	/**
	 * Send template message
	 *
	 * @param  string $to              Phone number (format: 57XXXXXXXXXX)
	 * @param  string $template_name   Template name
	 * @param  array  $parameters      Template parameters
	 * @param  string $language        Language code (default: 'es')
	 * @param  array  $headerParams    Optional header component parameters (e.g., ['type'=>'image', 'image'=>['link'=>'https://...']])
	 * @return array                   Response array with 'success' and 'message_id' or 'error'
	 */
	public function sendTemplateMessage($to, $template_name, $parameters = array(), $language = 'es', $headerParams = array())
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		// Clean phone number
		$to = $this->cleanPhoneNumber($to);

		// Build template components
		$components = array();

		// Header component (image, video, document)
		if (!empty($headerParams)) {
			$headerComponent = array(
				'type' => 'header',
				'parameters' => array()
			);
			if (!empty($headerParams['type'])) {
				$hpType = strtolower($headerParams['type']); // image, video, document
				$hp = array('type' => $hpType);
				if (!empty($headerParams[$hpType])) {
					$hp[$hpType] = $headerParams[$hpType]; // e.g., ['link' => 'https://...'] or ['id' => 'media_id']
				}
				$headerComponent['parameters'][] = $hp;
			}
			if (!empty($headerComponent['parameters'])) {
				$components[] = $headerComponent;
			}
		}
		
		if (!empty($parameters)) {
			$body_parameters = array();
			foreach ($parameters as $param) {
				$body_parameters[] = array(
					'type' => 'text',
					'text' => $param
				);
			}
			
			$components[] = array(
				'type' => 'body',
				'parameters' => $body_parameters
			);
		}

		// Build request payload
		$data = array(
			'messaging_product' => 'whatsapp',
			'to' => $to,
			'type' => 'template',
			'template' => array(
				'name' => $template_name,
				'language' => array(
					'code' => $language
				),
				'components' => $components
			)
		);

		// Send API request
		$url = $this->api_base_url.'/'.$this->config->phone_number_id.'/messages';
		$response = $this->makeApiRequest($url, 'POST', $data);

		if ($response['success']) {
			return array(
				'success' => true,
				'message_id' => $response['data']['messages'][0]['id']
			);
		} else {
			return array(
				'success' => false,
				'error' => $response['error'],
				'error_details' => $response['error_details'] ?? null,
				'error_full' => $response['error_full'] ?? null,
				'meta_payload' => $data
			);
		}
	}

	/**
	 * Send text message (only within 24-hour window)
	 *
	 * @param  string $to      Phone number
	 * @param  string $message Message text
	 * @return array           Response array
	 */
	public function sendTextMessage($to, $message)
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		// Clean phone number
		$to = $this->cleanPhoneNumber($to);

		// Build request payload
		$data = array(
			'messaging_product' => 'whatsapp',
			'to' => $to,
			'type' => 'text',
			'text' => array(
				'body' => $message
			)
		);

		// Send API request
		$url = $this->api_base_url.'/'.$this->config->phone_number_id.'/messages';
		$response = $this->makeApiRequest($url, 'POST', $data);

		if ($response['success']) {
			return array(
				'success' => true,
				'message_id' => $response['data']['messages'][0]['id']
			);
		} else {
			return array(
				'success' => false,
				'error' => $response['error']
			);
		}
	}

	/**
	 * Send media message (image, document, video, audio)
	 *
	 * @param  string $to        Phone number
	 * @param  string $mediaType Media type: image, document, video, audio
	 * @param  string $mediaId   Meta media ID (from uploadMedia)
	 * @param  string $caption   Optional caption for image/video/document
	 * @param  string $filename  Optional filename for documents
	 * @return array             Response array
	 */
	public function sendMediaMessage($to, $mediaType, $mediaId, $caption = '', $filename = '')
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		$to = $this->cleanPhoneNumber($to);

		// Build media object
		$mediaObj = array('id' => $mediaId);
		
		if (!empty($caption) && in_array($mediaType, array('image', 'video', 'document'))) {
			$mediaObj['caption'] = $caption;
		}
		if (!empty($filename) && $mediaType === 'document') {
			$mediaObj['filename'] = $filename;
		}

		$data = array(
			'messaging_product' => 'whatsapp',
			'to' => $to,
			'type' => $mediaType,
			$mediaType => $mediaObj
		);

		$url = $this->api_base_url.'/'.$this->config->phone_number_id.'/messages';
		$response = $this->makeApiRequest($url, 'POST', $data);

		if ($response['success']) {
			return array(
				'success' => true,
				'message_id' => $response['data']['messages'][0]['id']
			);
		} else {
			return array(
				'success' => false,
				'error' => $response['error']
			);
		}
	}

	/**
	 * Upload media file to Meta WhatsApp API
	 *
	 * @param  string $filePath  Local file path
	 * @param  string $mimeType  MIME type
	 * @return array             Response with media_id or error
	 */
	public function uploadMedia($filePath, $mimeType)
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		$url = $this->api_base_url.'/'.$this->config->phone_number_id.'/media';

		$ch = curl_init();

		$postFields = array(
			'messaging_product' => 'whatsapp',
			'file' => new CURLFile($filePath, $mimeType),
			'type' => $mimeType
		);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$this->config->access_token
		));

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			return array('success' => false, 'error' => $curl_error);
		}

		$data = json_decode($response, true);

		if ($http_code >= 200 && $http_code < 300 && !empty($data['id'])) {
			return array('success' => true, 'media_id' => $data['id']);
		}

		$error = isset($data['error']['message']) ? $data['error']['message'] : 'Upload failed';
		return array('success' => false, 'error' => $error);
	}

	/**
	 * Get media URL from Meta (for downloading inbound media)
	 *
	 * @param  string $mediaId Meta media ID
	 * @return array           Response with url or error
	 */
	public function getMediaUrl($mediaId)
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		$url = $this->api_base_url.'/'.$mediaId;
		$response = $this->makeApiRequest($url, 'GET');

		if ($response['success'] && !empty($response['data']['url'])) {
			return array(
				'success' => true,
				'url' => $response['data']['url'],
				'mime_type' => $response['data']['mime_type'] ?? '',
				'file_size' => $response['data']['file_size'] ?? 0
			);
		}

		return array('success' => false, 'error' => $response['error'] ?? 'Could not get media URL');
	}

	/**
	 * Download media from Meta and save locally
	 *
	 * @param  string $mediaId        Meta media ID
	 * @param  int    $conversationId Conversation ID for directory structure
	 * @param  string $filename       Optional filename
	 * @return array                  Response with local_path or error
	 */
	public function downloadMedia($mediaId, $conversationId, $filename = '')
	{
		global $conf;

		// Get media URL
		$mediaInfo = $this->getMediaUrl($mediaId);
		if (!$mediaInfo['success']) {
			return $mediaInfo;
		}

		// Create storage directory
		$dir = $conf->whatsappdati->dir_output.'/media/'.$conversationId;
		if (!is_dir($dir)) {
			dol_mkdir($dir);
		}

		// Determine filename
		if (empty($filename)) {
			$ext = $this->getExtensionFromMimeType($mediaInfo['mime_type']);
			$filename = $mediaId.'.'.$ext;
		}

		// SECURITY: Sanitize filename to prevent path traversal (e.g., ../../evil.php)
		$filename = basename($filename);
		$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
		if (empty($filename) || $filename === '.' || $filename === '..') {
			$filename = 'media_'.dol_now().'.bin';
		}

		$localPath = $dir.'/'.$filename;

		// Download file using cURL with auth token
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $mediaInfo['url']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$this->config->access_token
		));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		$fileData = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error || $http_code >= 400) {
			dol_syslog("WhatsApp downloadMedia error: ".($curl_error ?: "HTTP $http_code"), LOG_ERR);
			return array('success' => false, 'error' => $curl_error ?: "HTTP $http_code");
		}

		// Save file
		if (file_put_contents($localPath, $fileData) === false) {
			return array('success' => false, 'error' => 'Failed to save file locally');
		}

		return array(
			'success' => true,
			'local_path' => $localPath,
			'mime_type' => $mediaInfo['mime_type'],
			'filename' => $filename
		);
	}

	/**
	 * Get file extension from MIME type
	 *
	 * @param  string $mimeType MIME type
	 * @return string           File extension
	 */
	private function getExtensionFromMimeType($mimeType)
	{
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
			'video/mp4' => 'mp4',
			'video/3gpp' => '3gp',
			'audio/aac' => 'aac',
			'audio/mp4' => 'm4a',
			'audio/mpeg' => 'mp3',
			'audio/amr' => 'amr',
			'audio/ogg' => 'ogg',
			'audio/opus' => 'opus',
			'audio/webm' => 'webm',
			'application/pdf' => 'pdf',
			'application/vnd.ms-excel' => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'application/msword' => 'doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.ms-powerpoint' => 'ppt',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
			'text/plain' => 'txt',
		);

		return $map[$mimeType] ?? 'bin';
	}

	/**
	 * Get allowed MIME types for WhatsApp media
	 *
	 * @return array Associative array of type => allowed mimes
	 */
	public static function getAllowedMediaTypes()
	{
		return array(
			'image' => array('image/jpeg', 'image/png', 'image/webp'),
			'video' => array('video/mp4', 'video/3gpp'),
			'audio' => array('audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus', 'audio/webm'),
			'document' => array(
				'application/pdf',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'text/plain'
			)
		);
	}

	/**
	 * Determine media type from MIME type
	 *
	 * @param  string $mimeType MIME type
	 * @return string           Media type (image, video, audio, document) or empty string
	 */
	public static function getMediaTypeFromMime($mimeType)
	{
		$allowed = self::getAllowedMediaTypes();
		foreach ($allowed as $type => $mimes) {
			if (in_array($mimeType, $mimes)) {
				return $type;
			}
		}
		return '';
	}

	/**
	 * Make API request to WhatsApp Business API
	 *
	 * @param  string $url    API endpoint URL
	 * @param  string $method HTTP method (GET, POST, etc.)
	 * @param  array  $data   Request data
	 * @return array          Response array
	 */
	private function makeApiRequest($url, $method = 'GET', $data = null)
	{
		$ch = curl_init();

		$headers = array(
			'Authorization: Bearer '.$this->config->access_token,
			'Content-Type: application/json'
		);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		}

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			dol_syslog("WhatsApp API Error: ".$curl_error, LOG_ERR);
			return array(
				'success' => false,
				'error' => $curl_error
			);
		}

		$response_data = json_decode($response, true);

		if ($http_code >= 200 && $http_code < 300) {
			return array(
				'success' => true,
				'data' => $response_data
			);
		} else {
			$error_message = isset($response_data['error']['message']) ? 
				$response_data['error']['message'] : 'Unknown error';
			$error_details = isset($response_data['error']['error_data']['details']) ?
				$response_data['error']['error_data']['details'] : null;
			
			dol_syslog("WhatsApp API Error: ".$error_message, LOG_ERR);
			return array(
				'success' => false,
				'error' => $error_message,
				'error_details' => $error_details,
				'error_full' => $response_data['error'] ?? null,
				'http_code' => $http_code
			);
		}
	}

	/**
	 * Test connection to WhatsApp Business API
	 *
	 * @return array Response with success status and message
	 */
	public function testConnection()
	{
		if ($this->loadConfig() < 0) {
			return array('success' => false, 'error' => 'Configuration not found');
		}

		// Get phone number info to test connection
		$url = $this->api_base_url.'/'.$this->config->phone_number_id;
		$response = $this->makeApiRequest($url, 'GET');

		return $response;
	}

	/**
	 * Clean phone number to international format
	 *
	 * @param  string $phone Phone number
	 * @return string        Cleaned phone number
	 */
	private function cleanPhoneNumber($phone)
	{
		global $conf;

		// Remove all non-numeric characters
		$phone = preg_replace('/[^0-9]/', '', $phone);
		
		// If starts with 0, remove it
		if (substr($phone, 0, 1) === '0') {
			$phone = substr($phone, 1);
		}
		
		// Get country code: prefer line-specific, then global config, then default 57
		$country_code = '57';
		if (!empty($this->config->country_code)) {
			$country_code = $this->config->country_code;
		} elseif (!empty($conf->global->WHATSAPPDATI_COUNTRY_CODE)) {
			$country_code = $conf->global->WHATSAPPDATI_COUNTRY_CODE;
		}
		$code_len = strlen($country_code);

		// If doesn't start with country code, add it
		if (substr($phone, 0, $code_len) !== $country_code && strlen($phone) <= 10) {
			$phone = $country_code.$phone;
		}
		
		return $phone;
	}

	/**
	 * Check if conversation window is still open (24 hours)
	 *
	 * @param  int $conversation_id Conversation ID
	 * @return bool                 True if window is open
	 */
	public function isConversationWindowOpen($conversation_id)
	{
		require_once dol_buildpath('/whatsappdati/class/whatsappconversation.class.php', 0);
		
		$conversation = new WhatsAppConversation($this->db);
		if ($conversation->fetch($conversation_id) > 0) {
			if ($conversation->window_expires_at && 
				$conversation->window_expires_at > dol_now()) {
				return true;
			}
		}
		
		return false;
	}
}
