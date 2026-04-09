<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappschedule.class.php
 * \ingroup    whatsappdati
 * \brief      Scheduled messages management - CRUD + execution engine
 */

class WhatsAppSchedule
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Error messages */
	public $errors = array();

	// Fields
	public $id;
	public $entity;
	public $fk_line;
	public $phone_number;
	public $contact_name;
	public $fk_soc;
	public $fk_conversation;
	public $message_type;
	public $message_content;
	public $template_name;
	public $template_params;
	public $scheduled_date;
	public $recurrence_type;
	public $recurrence_end_date;
	public $next_execution;
	public $status;
	public $last_execution;
	public $execution_count;
	public $retry_count;
	public $max_retries;
	public $error_message;
	public $message_id_wa;
	public $note;
	public $date_creation;
	public $tms;
	public $fk_user_creat;

	const TABLE = 'whatsapp_scheduled';

	const RECURRENCE_TYPES = array('once', 'daily', 'weekly', 'monthly');

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create a scheduled message
	 *
	 * @param User $user User creating the record
	 * @return int >0 if OK, <0 if KO
	 */
	public function create($user)
	{
		global $conf;

		// Validation
		$this->phone_number = trim($this->phone_number ?? '');
		if (empty($this->phone_number)) {
			$this->error = 'ErrorPhoneRequired';
			return -1;
		}

		if ($this->message_type === 'text' && empty(trim($this->message_content ?? ''))) {
			$this->error = 'ErrorMessageContentRequired';
			return -1;
		}

		if ($this->message_type === 'template' && empty(trim($this->template_name ?? ''))) {
			$this->error = 'ErrorTemplateNameRequired';
			return -1;
		}

		if (empty($this->scheduled_date)) {
			$this->error = 'ErrorScheduledDateRequired';
			return -1;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE . " (";
		$sql .= "entity, fk_line, phone_number, contact_name, fk_soc, fk_conversation,";
		$sql .= " message_type, message_content, template_name, template_params,";
		$sql .= " scheduled_date, recurrence_type, recurrence_end_date, next_execution,";
		$sql .= " status, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", " . ($this->fk_line > 0 ? ((int) $this->fk_line) : "NULL");
		$sql .= ", '" . $this->db->escape($this->phone_number) . "'";
		$sql .= ", " . (!empty($this->contact_name) ? "'" . $this->db->escape($this->contact_name) . "'" : "NULL");
		$sql .= ", " . ($this->fk_soc > 0 ? ((int) $this->fk_soc) : "NULL");
		$sql .= ", " . ($this->fk_conversation > 0 ? ((int) $this->fk_conversation) : "NULL");
		$sql .= ", '" . $this->db->escape($this->message_type ?: 'text') . "'";
		$sql .= ", " . (!empty($this->message_content) ? "'" . $this->db->escape($this->message_content) . "'" : "NULL");
		$sql .= ", " . (!empty($this->template_name) ? "'" . $this->db->escape($this->template_name) . "'" : "NULL");
		$sql .= ", " . (!empty($this->template_params) ? "'" . $this->db->escape($this->template_params) . "'" : "NULL");
		$sql .= ", '" . $this->db->idate($this->scheduled_date) . "'";
		$sql .= ", '" . $this->db->escape($this->recurrence_type ?: 'once') . "'";
		$sql .= ", " . (!empty($this->recurrence_end_date) ? "'" . $this->db->idate($this->recurrence_end_date) . "'" : "NULL");
		$sql .= ", '" . $this->db->idate($this->scheduled_date) . "'"; // next_execution = scheduled_date initially
		$sql .= ", 'pending'";
		$sql .= ", '" . $this->db->idate(dol_now()) . "'";
		$sql .= ", " . ((int) $user->id);
		$sql .= ")";

		$this->db->begin();
		$resql = $this->db->query($sql);

		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
			$this->db->commit();
			return $this->id;
		} else {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Fetch a scheduled message by ID
	 *
	 * @param int $id Row ID
	 * @return int >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE rowid = " . ((int) $id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->setFromObject($obj);
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Fetch all scheduled messages
	 *
	 * @param string $filter 'all', 'pending', 'sent', 'failed', 'recurring'
	 * @param int    $limit  Max results
	 * @param int    $offset Offset
	 * @param int    $lineId Filter by line (0 = all)
	 * @return array|int Array of objects on success, <0 on error
	 */
	public function fetchAll($filter = 'all', $limit = 100, $offset = 0, $lineId = 0)
	{
		global $conf;

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);

		if ($lineId > 0) {
			$sql .= " AND fk_line = " . ((int) $lineId);
		}

		switch ($filter) {
			case 'pending':
				$sql .= " AND status = 'pending'";
				break;
			case 'sent':
				$sql .= " AND status = 'sent'";
				break;
			case 'failed':
				$sql .= " AND status = 'failed'";
				break;
			case 'recurring':
				$sql .= " AND recurrence_type != 'once'";
				$sql .= " AND status IN ('pending', 'paused')";
				break;
		}

		$sql .= " ORDER BY COALESCE(next_execution, scheduled_date) ASC";
		$sql .= " LIMIT " . ((int) $limit) . " OFFSET " . ((int) $offset);

		$resql = $this->db->query($sql);
		if ($resql) {
			$results = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$results[] = $obj;
			}
			return $results;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Update a scheduled message
	 *
	 * @param User $user User making the update
	 * @return int >0 if OK, <0 if KO
	 */
	public function update($user)
	{
		global $conf;

		$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
		$sql .= " phone_number = '" . $this->db->escape($this->phone_number) . "'";
		$sql .= ", contact_name = " . (!empty($this->contact_name) ? "'" . $this->db->escape($this->contact_name) . "'" : "NULL");
		$sql .= ", fk_soc = " . ($this->fk_soc > 0 ? ((int) $this->fk_soc) : "NULL");
		$sql .= ", fk_conversation = " . ($this->fk_conversation > 0 ? ((int) $this->fk_conversation) : "NULL");
		$sql .= ", message_type = '" . $this->db->escape($this->message_type ?: 'text') . "'";
		$sql .= ", message_content = " . (!empty($this->message_content) ? "'" . $this->db->escape($this->message_content) . "'" : "NULL");
		$sql .= ", template_name = " . (!empty($this->template_name) ? "'" . $this->db->escape($this->template_name) . "'" : "NULL");
		$sql .= ", template_params = " . (!empty($this->template_params) ? "'" . $this->db->escape($this->template_params) . "'" : "NULL");
		$sql .= ", scheduled_date = '" . $this->db->idate($this->scheduled_date) . "'";
		$sql .= ", recurrence_type = '" . $this->db->escape($this->recurrence_type ?: 'once') . "'";
		$sql .= ", recurrence_end_date = " . (!empty($this->recurrence_end_date) ? "'" . $this->db->idate($this->recurrence_end_date) . "'" : "NULL");
		$sql .= ", next_execution = " . (!empty($this->next_execution) ? "'" . $this->db->idate($this->next_execution) . "'" : "NULL");
		$sql .= ", status = '" . $this->db->escape($this->status) . "'";
		$sql .= ", fk_line = " . ($this->fk_line > 0 ? ((int) $this->fk_line) : "NULL");
		$sql .= ", note = " . (!empty($this->note) ? "'" . $this->db->escape($this->note) . "'" : "NULL");
		$sql .= " WHERE rowid = " . ((int) $this->id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Delete a scheduled message
	 *
	 * @return int >0 if OK, <0 if KO
	 */
	public function delete()
	{
		global $conf;

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE rowid = " . ((int) $this->id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	// =========================================
	// Execution Engine
	// =========================================

	/**
	 * Fetch messages ready for execution (next_execution <= now)
	 *
	 * @param int $limit Max items
	 * @return array Array of scheduled message objects
	 */
	public function fetchDue($limit = 50)
	{
		global $conf;

		// Use transaction + FOR UPDATE to prevent duplicate processing by concurrent cron jobs
		$this->db->begin();

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND status = 'pending'";
		$sql .= " AND next_execution <= '" . $this->db->idate(dol_now()) . "'";
		$sql .= " ORDER BY next_execution ASC";
		$sql .= " LIMIT " . ((int) $limit);
		$sql .= " FOR UPDATE";

		$ids = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
		}

		// Atomically mark as processing
		if (!empty($ids)) {
			$sqlUp = "UPDATE " . MAIN_DB_PREFIX . self::TABLE;
			$sqlUp .= " SET status = 'processing'";
			$sqlUp .= " WHERE rowid IN (" . implode(',', $ids) . ")";
			$this->db->query($sqlUp);
		}

		$this->db->commit();

		// Fetch full objects
		$items = array();
		foreach ($ids as $id) {
			$item = new WhatsAppSchedule($this->db);
			$item->fetch($id);
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Execute a scheduled message - send it
	 *
	 * @return array ['success' => bool, 'error' => string]
	 */
	public function execute()
	{
		require_once dirname(__FILE__) . '/whatsappmanager.class.php';
		require_once dirname(__FILE__) . '/whatsappconversation.class.php';
		require_once dirname(__FILE__) . '/whatsappmessage.class.php';
		require_once dirname(__FILE__) . '/whatsappevent.class.php';
		require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

		global $conf;

		$manager = new WhatsAppManager($this->db, $this->fk_line > 0 ? $this->fk_line : 0);

		// Send based on type
		$sendResult = null;
		$messageContent = '';

		if ($this->message_type === 'template' && !empty($this->template_name)) {
			$params = array();
			if (!empty($this->template_params)) {
				$decoded = json_decode($this->template_params, true);
				if (is_array($decoded)) {
					$params = $decoded;
				}
			}
			$sendResult = $manager->sendTemplateMessage($this->phone_number, $this->template_name, $params);
			$messageContent = '[Template: ' . $this->template_name . ']';
		} else {
			$sendResult = $manager->sendTextMessage($this->phone_number, $this->message_content);
			$messageContent = $this->message_content;
		}

		$systemUser = new User($this->db);
		$adminUserId = getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1);
		$systemUser->fetch($adminUserId);

		if (!$sendResult || !$sendResult['success']) {
			// Handle failure
			$retryCount = $this->retry_count + 1;
			$newStatus = ($retryCount >= $this->max_retries) ? 'failed' : 'pending';

			$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
			$sql .= " retry_count = " . ((int) $retryCount);
			$sql .= ", status = '" . $this->db->escape($newStatus) . "'";
			$sql .= ", error_message = '" . $this->db->escape($sendResult['error'] ?? 'Send failed') . "'";
			$sql .= " WHERE rowid = " . ((int) $this->id);
			$sql .= " AND entity = " . ((int) $conf->entity);
			$this->db->query($sql);

			dol_syslog("WhatsApp Schedule: Failed to send ID " . $this->id . ": " . ($sendResult['error'] ?? ''), LOG_WARNING);
			return array('success' => false, 'error' => $sendResult['error'] ?? 'Send failed');
		}

		// Success - get or create conversation
		$conversation = new WhatsAppConversation($this->db);
		$existing = $conversation->fetchByPhone($this->phone_number, $this->fk_line > 0 ? $this->fk_line : 0);
		if ($existing <= 0) {
			$conversation->phone_number = $this->phone_number;
			$conversation->contact_name = $this->contact_name;
			$conversation->fk_soc = $this->fk_soc;
			$conversation->fk_line = $this->fk_line;
			$conversation->status = 'active';
			$conversation->create($systemUser);
		}

		// Save message
		$outMessage = new WhatsAppMessage($this->db);
		$outMessage->message_id = $sendResult['message_id'];
		$outMessage->fk_conversation = $conversation->id;
		$outMessage->fk_line = $this->fk_line;
		$outMessage->direction = 'outbound';
		$outMessage->message_type = ($this->message_type === 'template') ? 'template' : 'text';
		$outMessage->content = $messageContent;
		$outMessage->template_name = $this->template_name;
		$outMessage->status = 'sent';
		$outMessage->fk_user_sender = $this->fk_user_creat ?: 0;
		$outMessage->timestamp = dol_now();
		$outMessage->create($systemUser);

		// Emit SSE event
		$eventEmitter = new WhatsAppEvent($this->db);
		$eventEmitter->emitNewMessage(
			$conversation->id,
			'outbound',
			$outMessage->message_type,
			mb_substr($messageContent, 0, 80),
			$this->phone_number,
			$conversation->contact_name ?: $this->contact_name,
			$this->fk_line
		);

		// Update this scheduled message
		$this->handlePostExecution($sendResult['message_id']);

		dol_syslog("WhatsApp Schedule: Sent ID " . $this->id . " to " . $this->phone_number, LOG_INFO);
		return array('success' => true, 'message_id' => $sendResult['message_id']);
	}

	/**
	 * Handle post-execution: update status, compute next_execution for recurring
	 *
	 * @param string $messageIdWa WhatsApp message ID
	 */
	private function handlePostExecution($messageIdWa)
	{
		global $conf;

		$now = dol_now();
		$executionCount = $this->execution_count + 1;

		if ($this->recurrence_type === 'once') {
			// One-time: mark as sent
			$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
			$sql .= " status = 'sent'";
			$sql .= ", last_execution = '" . $this->db->idate($now) . "'";
			$sql .= ", execution_count = " . ((int) $executionCount);
			$sql .= ", message_id_wa = '" . $this->db->escape($messageIdWa) . "'";
			$sql .= ", retry_count = 0";
			$sql .= ", error_message = NULL";
			$sql .= " WHERE rowid = " . ((int) $this->id);
			$sql .= " AND entity = " . ((int) $conf->entity);
			$this->db->query($sql);
		} else {
			// Recurring: compute next execution
			$nextExecution = $this->computeNextExecution($now);

			// Check if recurrence has ended
			$isExpired = false;
			if (!empty($this->recurrence_end_date) && $nextExecution > $this->recurrence_end_date) {
				$isExpired = true;
			}

			$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
			$sql .= " last_execution = '" . $this->db->idate($now) . "'";
			$sql .= ", execution_count = " . ((int) $executionCount);
			$sql .= ", message_id_wa = '" . $this->db->escape($messageIdWa) . "'";
			$sql .= ", retry_count = 0";
			$sql .= ", error_message = NULL";

			if ($isExpired) {
				$sql .= ", status = 'sent'";
				$sql .= ", next_execution = NULL";
			} else {
				$sql .= ", status = 'pending'";
				$sql .= ", next_execution = '" . $this->db->idate($nextExecution) . "'";
			}

			$sql .= " WHERE rowid = " . ((int) $this->id);
			$sql .= " AND entity = " . ((int) $conf->entity);
			$this->db->query($sql);
		}
	}

	/**
	 * Compute next execution time for recurring messages
	 *
	 * @param int $fromTimestamp Base timestamp
	 * @return int Next execution timestamp
	 */
	private function computeNextExecution($fromTimestamp)
	{
		// Use the original scheduled time-of-day, just advance the date
		$scheduledTime = date('H:i:s', $this->scheduled_date);
		$baseDate = date('Y-m-d', $fromTimestamp);

		switch ($this->recurrence_type) {
			case 'daily':
				$nextDate = date('Y-m-d', strtotime($baseDate . ' +1 day'));
				break;
			case 'weekly':
				$nextDate = date('Y-m-d', strtotime($baseDate . ' +1 week'));
				break;
			case 'monthly':
				$nextDate = date('Y-m-d', strtotime($baseDate . ' +1 month'));
				break;
			default:
				return $fromTimestamp + 86400; // fallback: +1 day
		}

		return strtotime($nextDate . ' ' . $scheduledTime);
	}

	/**
	 * Process all due scheduled messages (called by cron)
	 *
	 * @param int $limit Max messages to process
	 * @return array ['processed' => int, 'sent' => int, 'failed' => int]
	 */
	public function processDueMessages($limit = 50)
	{
		$due = $this->fetchDue($limit);
		$sent = 0;
		$failed = 0;

		foreach ($due as $item) {
			$result = $item->execute();
			if ($result['success']) {
				$sent++;
			} else {
				$failed++;
			}

			// Configurable rate limiting between sends
			$rateLimitMs = getDolGlobalInt('WHATSAPPDATI_RATE_LIMIT_MS', 100);
			usleep($rateLimitMs * 1000);
		}

		dol_syslog("WhatsApp Schedule cron: processed=" . count($due) . " sent=$sent failed=$failed", LOG_INFO);

		return array(
			'processed' => count($due),
			'sent' => $sent,
			'failed' => $failed
		);
	}

	/**
	 * Get summary statistics
	 *
	 * @return array Stats
	 */
	public function getStats()
	{
		global $conf;

		$stats = array('total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'recurring' => 0);

		$sql = "SELECT status, recurrence_type, COUNT(*) as cnt";
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " GROUP BY status, recurrence_type";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$stats['total'] += (int) $obj->cnt;
				if (isset($stats[$obj->status])) {
					$stats[$obj->status] += (int) $obj->cnt;
				}
				if ($obj->recurrence_type !== 'once') {
					$stats['recurring'] += (int) $obj->cnt;
				}
			}
		}

		return $stats;
	}

	/**
	 * Set object properties from a database result object
	 *
	 * @param object $obj DB result object
	 */
	private function setFromObject($obj)
	{
		$this->id = $obj->rowid;
		$this->entity = $obj->entity;
		$this->fk_line = $obj->fk_line;
		$this->phone_number = $obj->phone_number;
		$this->contact_name = $obj->contact_name;
		$this->fk_soc = $obj->fk_soc;
		$this->fk_conversation = $obj->fk_conversation;
		$this->message_type = $obj->message_type;
		$this->message_content = $obj->message_content;
		$this->template_name = $obj->template_name;
		$this->template_params = $obj->template_params;
		$this->scheduled_date = $this->db->jdate($obj->scheduled_date);
		$this->recurrence_type = $obj->recurrence_type;
		$this->recurrence_end_date = !empty($obj->recurrence_end_date) ? $this->db->jdate($obj->recurrence_end_date) : null;
		$this->next_execution = !empty($obj->next_execution) ? $this->db->jdate($obj->next_execution) : null;
		$this->status = $obj->status;
		$this->last_execution = !empty($obj->last_execution) ? $this->db->jdate($obj->last_execution) : null;
		$this->execution_count = $obj->execution_count;
		$this->retry_count = $obj->retry_count;
		$this->max_retries = $obj->max_retries;
		$this->error_message = $obj->error_message;
		$this->message_id_wa = $obj->message_id_wa;
		$this->note = $obj->note;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $obj->tms;
		$this->fk_user_creat = $obj->fk_user_creat;
	}
}
