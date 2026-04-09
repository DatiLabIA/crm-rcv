<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappevent.class.php
 * \ingroup    whatsappdati
 * \brief      Event emitter for real-time SSE push notifications
 *
 * Writes event files to a shared temp directory. The SSE endpoint reads these
 * files and streams them to connected clients. Events are auto-cleaned after 60s.
 */

class WhatsAppEvent
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var string Events directory path */
	private $eventsDir;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$entity = (int) $conf->entity;

		// Use Dolibarr temp directory for events (handle NOLOGIN context where module dirs may not be set)
		if (!empty($conf->whatsappdati->dir_temp)) {
			$this->eventsDir = $conf->whatsappdati->dir_temp . '/events/' . $entity;
		} else {
			// Fallback: build path from DOL_DATA_ROOT
			$dataRoot = defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : $conf->entities[$entity]->dir_output ?? sys_get_temp_dir();
			$this->eventsDir = $dataRoot . '/whatsappdati/temp/events/' . $entity;
			dol_syslog('WhatsAppEvent: dir_temp not set, using fallback: ' . $this->eventsDir, LOG_WARNING);
		}
	}

	/**
	 * Check if real-time mode is enabled
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		global $conf;

		return !empty($conf->global->WHATSAPPDATI_REALTIME_MODE)
			&& $conf->global->WHATSAPPDATI_REALTIME_MODE !== 'polling';
	}

	/**
	 * Emit an event
	 *
	 * @param string $type Event type: new_message, status_update, conversation_update, new_conversation
	 * @param array  $data Event payload
	 * @return bool  True on success
	 */
	public function emit($type, $data = array())
	{
		if (!$this->isEnabled()) {
			return false;
		}

		// Ensure directory exists
		if (!is_dir($this->eventsDir)) {
			dol_mkdir($this->eventsDir);
		}

		// Generate unique sequential ID (microsecond timestamp)
		$eventId = (int) (microtime(true) * 10000);

		$event = array(
			'type' => $type,
			'data' => $data,
			'timestamp' => time()
		);

		$filename = sprintf('event_%d_%d.json', $eventId, time());
		$filepath = $this->eventsDir . '/' . $filename;

		$result = @file_put_contents($filepath, json_encode($event));

		return ($result !== false);
	}

	/**
	 * Emit a "new_message" event
	 *
	 * @param int    $conversationId Conversation ID
	 * @param string $direction      'inbound' or 'outbound'
	 * @param string $messageType    'text', 'image', etc.
	 * @param string $preview        Short preview of message content
	 * @param string $phone          Phone number
	 * @param string $contactName    Contact name
	 * @param int    $lineId         WhatsApp line ID (0 = not specified)
	 */
	public function emitNewMessage($conversationId, $direction, $messageType = 'text', $preview = '', $phone = '', $contactName = '', $lineId = 0)
	{
		$this->emit('new_message', array(
			'conversation_id' => (int) $conversationId,
			'direction' => $direction,
			'message_type' => $messageType,
			'preview' => mb_substr($preview, 0, 100),
			'phone' => $phone,
			'contact_name' => $contactName,
			'fk_line' => (int) $lineId
		));
	}

	/**
	 * Emit a "status_update" event (sent, delivered, read)
	 *
	 * @param string $messageId External message ID
	 * @param string $status    New status
	 */
	public function emitStatusUpdate($messageId, $status)
	{
		$this->emit('status_update', array(
			'message_id' => $messageId,
			'status' => $status
		));
	}

	/**
	 * Emit a "new_conversation" event
	 *
	 * @param int    $conversationId Conversation ID
	 * @param string $phone          Phone number
	 * @param string $contactName    Contact name
	 */
	public function emitNewConversation($conversationId, $phone, $contactName)
	{
		$this->emit('new_conversation', array(
			'conversation_id' => (int) $conversationId,
			'phone' => $phone,
			'contact_name' => $contactName
		));
	}

	/**
	 * Emit a "conversation_update" event (assignment change, tag change, etc.)
	 *
	 * @param int    $conversationId Conversation ID
	 * @param string $updateType     What changed: 'assignment', 'tags', 'status'
	 */
	public function emitConversationUpdate($conversationId, $updateType = 'general')
	{
		$this->emit('conversation_update', array(
			'conversation_id' => (int) $conversationId,
			'update_type' => $updateType
		));
	}

	/**
	 * Clean up old event files (older than given seconds)
	 *
	 * @param int $maxAge Maximum file age in seconds (default 60)
	 * @return int Number of files cleaned
	 */
	public function cleanup($maxAge = 60)
	{
		if (!is_dir($this->eventsDir)) {
			return 0;
		}

		$files = glob($this->eventsDir . '/event_*.json');
		$cleaned = 0;

		foreach ($files as $file) {
			if ((time() - filemtime($file)) > $maxAge) {
				if (@unlink($file)) {
					$cleaned++;
				}
			}
		}

		return $cleaned;
	}
}
