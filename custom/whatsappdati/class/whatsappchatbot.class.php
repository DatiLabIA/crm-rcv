<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappchatbot.class.php
 * \ingroup    whatsappdati
 * \brief      Chatbot engine - rule matching and auto-reply logic
 */

class WhatsAppChatbot
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Error messages array */
	public $errors = array();

	// Rule fields
	public $id;
	public $entity;
	public $fk_line;
	public $name;
	public $trigger_type;
	public $trigger_value;
	public $response_type;
	public $response_text;
	public $response_template_name;
	public $response_template_params;
	public $delay_seconds;
	public $condition_type;
	public $work_hours_start;
	public $work_hours_end;
	public $max_triggers_per_conv;
	public $priority;
	public $stop_on_match;
	public $active;
	public $date_creation;
	public $tms;
	public $fk_user_creat;

	/** @var string Table name */
	const TABLE = 'whatsapp_chatbot_rules';
	const LOG_TABLE = 'whatsapp_chatbot_log';

	/** @var array Valid trigger types */
	const TRIGGER_TYPES = array('exact', 'contains', 'starts_with', 'regex', 'default', 'new_conversation');

	/** @var array Valid condition types */
	const CONDITION_TYPES = array('always', 'outside_hours', 'unassigned');

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
	 * Create a chatbot rule
	 *
	 * @param User $user User creating the record
	 * @return int >0 if OK, <0 if KO
	 */
	public function create($user)
	{
		global $conf;

		$this->name = trim($this->name ?? '');
		if (empty($this->name)) {
			$this->error = 'ErrorNameRequired';
			return -1;
		}

		if (!in_array($this->trigger_type, self::TRIGGER_TYPES)) {
			$this->error = 'ErrorInvalidTriggerType';
			return -1;
		}

		// Validate trigger_value required for keyword-based triggers
		if (in_array($this->trigger_type, array('exact', 'contains', 'starts_with', 'regex'))) {
			if (empty(trim($this->trigger_value ?? ''))) {
				$this->error = 'ErrorTriggerValueRequired';
				return -1;
			}
		}

		// Validate response
		if ($this->response_type === 'text' && empty(trim($this->response_text ?? ''))) {
			$this->error = 'ErrorResponseTextRequired';
			return -1;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE . " (";
		$sql .= "entity, fk_line, name, trigger_type, trigger_value, response_type, response_text,";
		$sql .= " response_template_name, response_template_params,";
		$sql .= " delay_seconds, condition_type, work_hours_start, work_hours_end,";
		$sql .= " max_triggers_per_conv, priority, stop_on_match, active, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", " . ($this->fk_line > 0 ? ((int) $this->fk_line) : "NULL");
		$sql .= ", '" . $this->db->escape($this->name) . "'";
		$sql .= ", '" . $this->db->escape($this->trigger_type) . "'";
		$sql .= ", " . (!empty($this->trigger_value) ? "'" . $this->db->escape($this->trigger_value) . "'" : "NULL");
		$sql .= ", '" . $this->db->escape($this->response_type ?: 'text') . "'";
		$sql .= ", " . (!empty($this->response_text) ? "'" . $this->db->escape($this->response_text) . "'" : "NULL");
		$sql .= ", " . (!empty($this->response_template_name) ? "'" . $this->db->escape($this->response_template_name) . "'" : "NULL");
		$sql .= ", " . (!empty($this->response_template_params) ? "'" . $this->db->escape($this->response_template_params) . "'" : "NULL");
		$sql .= ", " . ((int) ($this->delay_seconds ?? 0));
		$sql .= ", '" . $this->db->escape($this->condition_type ?: 'always') . "'";
		$sql .= ", '" . $this->db->escape($this->work_hours_start ?: '09:00:00') . "'";
		$sql .= ", '" . $this->db->escape($this->work_hours_end ?: '18:00:00') . "'";
		$sql .= ", " . ((int) ($this->max_triggers_per_conv ?? 0));
		$sql .= ", " . ((int) ($this->priority ?? 10));
		$sql .= ", " . ((int) ($this->stop_on_match ?? 1));
		$sql .= ", 1";
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
	 * Fetch a rule by ID
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
	 * Fetch all rules for current entity
	 *
	 * @param string $filter 'active', 'all'
	 * @param int    $lineId Filter by line (0 = all, includes global rules where fk_line IS NULL)
	 * @return array|int Array of objects on success, <0 on error
	 */
	public function fetchAll($filter = 'active', $lineId = 0)
	{
		global $conf;

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		if ($filter === 'active') {
			$sql .= " AND active = 1";
		}
		if ($lineId > 0) {
			$sql .= " AND (fk_line = " . ((int) $lineId) . " OR fk_line IS NULL)";
		}
		$sql .= " ORDER BY priority ASC, name ASC";

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
	 * Update a rule
	 *
	 * @param User $user User making the update
	 * @return int >0 if OK, <0 if KO
	 */
	public function update($user)
	{
		global $conf;

		$this->name = trim($this->name ?? '');
		if (empty($this->name)) {
			$this->error = 'ErrorNameRequired';
			return -1;
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
		$sql .= " name = '" . $this->db->escape($this->name) . "'";
		$sql .= ", trigger_type = '" . $this->db->escape($this->trigger_type) . "'";
		$sql .= ", trigger_value = " . (!empty($this->trigger_value) ? "'" . $this->db->escape($this->trigger_value) . "'" : "NULL");
		$sql .= ", response_type = '" . $this->db->escape($this->response_type ?: 'text') . "'";
		$sql .= ", response_text = " . (!empty($this->response_text) ? "'" . $this->db->escape($this->response_text) . "'" : "NULL");
		$sql .= ", response_template_name = " . (!empty($this->response_template_name) ? "'" . $this->db->escape($this->response_template_name) . "'" : "NULL");
		$sql .= ", response_template_params = " . (!empty($this->response_template_params) ? "'" . $this->db->escape($this->response_template_params) . "'" : "NULL");
		$sql .= ", delay_seconds = " . ((int) ($this->delay_seconds ?? 0));
		$sql .= ", condition_type = '" . $this->db->escape($this->condition_type ?: 'always') . "'";
		$sql .= ", work_hours_start = '" . $this->db->escape($this->work_hours_start ?: '09:00:00') . "'";
		$sql .= ", work_hours_end = '" . $this->db->escape($this->work_hours_end ?: '18:00:00') . "'";
		$sql .= ", max_triggers_per_conv = " . ((int) ($this->max_triggers_per_conv ?? 0));
		$sql .= ", priority = " . ((int) ($this->priority ?? 10));
		$sql .= ", stop_on_match = " . ((int) ($this->stop_on_match ?? 1));
		$sql .= ", active = " . ((int) $this->active);
		$sql .= ", fk_line = " . ($this->fk_line > 0 ? ((int) $this->fk_line) : "NULL");
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
	 * Delete a rule
	 *
	 * @return int >0 if OK, <0 if KO
	 */
	public function delete()
	{
		global $conf;

		// Log entries will cascade-delete
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
	// Matching Engine
	// =========================================

	/**
	 * Process an incoming message against chatbot rules.
	 * Returns the list of matching rules (respecting priority and stop_on_match).
	 *
	 * @param int    $conversationId   Conversation ID
	 * @param string $messageText      Incoming message text content
	 * @param string $messageType      Message type (text, image, etc.)
	 * @param bool   $isNewConversation Whether this is the first message of a new conversation
	 * @param int    $lineId            Line ID for filtering rules (0 = all)
	 * @return array Array of matching rule objects (may be empty)
	 */
	public function findMatchingRules($conversationId, $messageText, $messageType = 'text', $isNewConversation = false, $lineId = 0)
	{
		global $conf;

		// Check if chatbot is enabled
		if (empty($conf->global->WHATSAPPDATI_CHATBOT_ENABLED)) {
			return array();
		}

		// Load active rules (filtered by line if specified)
		$rules = $this->fetchAll('active', $lineId);
		if (!is_array($rules) || empty($rules)) {
			return array();
		}

		$matchedRules = array();

		foreach ($rules as $rule) {
			// Check condition
			if (!$this->checkCondition($rule, $conversationId)) {
				continue;
			}

			// Check max triggers per conversation
			if ($rule->max_triggers_per_conv > 0) {
				$triggerCount = $this->getTriggerCount($rule->rowid, $conversationId);
				if ($triggerCount >= $rule->max_triggers_per_conv) {
					continue;
				}
			}

			// Check trigger match
			if ($this->matchesTrigger($rule, $messageText, $messageType, $isNewConversation)) {
				$matchedRules[] = $rule;

				// If stop_on_match, don't check lower-priority rules
				if ($rule->stop_on_match) {
					break;
				}
			}
		}

		return $matchedRules;
	}

	/**
	 * Check if a rule's trigger matches the incoming message
	 *
	 * @param object $rule             Rule object
	 * @param string $messageText      Message text
	 * @param string $messageType      Message type
	 * @param bool   $isNewConversation Whether new conversation
	 * @return bool
	 */
	private function matchesTrigger($rule, $messageText, $messageType, $isNewConversation)
	{
		$text = mb_strtolower(trim($messageText));
		$trigger = mb_strtolower(trim($rule->trigger_value));

		switch ($rule->trigger_type) {
			case 'new_conversation':
				return $isNewConversation;

			case 'exact':
				return ($text === $trigger);

			case 'contains':
				// Support comma-separated keywords (any match)
				$keywords = array_map('trim', explode(',', $trigger));
				foreach ($keywords as $kw) {
					if (!empty($kw) && mb_strpos($text, $kw) !== false) {
						return true;
					}
				}
				return false;

			case 'starts_with':
				return (mb_substr($text, 0, mb_strlen($trigger)) === $trigger);

			case 'regex':
				// Use the original (non-lowered) trigger as regex pattern
				$pattern = trim($rule->trigger_value);
				// Auto-add delimiters if not present
				if (substr($pattern, 0, 1) !== '/') {
					$pattern = '/' . $pattern . '/iu';
				}
				// ReDoS protection: limit backtracking
				$prevLimit = ini_get('pcre.backtrack_limit');
				ini_set('pcre.backtrack_limit', 10000);
				$result = @preg_match($pattern, $messageText);
				ini_set('pcre.backtrack_limit', $prevLimit);
				return ($result === 1);

			case 'default':
				// Default rule matches any text message that didn't match anything else
				return ($messageType === 'text' && !empty($text));

			default:
				return false;
		}
	}

	/**
	 * Check if a rule's condition is satisfied
	 *
	 * @param object $rule           Rule object
	 * @param int    $conversationId Conversation ID
	 * @return bool
	 */
	private function checkCondition($rule, $conversationId)
	{
		switch ($rule->condition_type) {
			case 'always':
				return true;

			case 'outside_hours':
				return $this->isOutsideWorkHours($rule->work_hours_start, $rule->work_hours_end);

			case 'unassigned':
				return $this->isConversationUnassigned($conversationId);

			default:
				return true;
		}
	}

	/**
	 * Check if current time is outside work hours
	 *
	 * @param string $start Work hours start (HH:MM:SS)
	 * @param string $end   Work hours end (HH:MM:SS)
	 * @return bool True if currently OUTSIDE work hours
	 */
	private function isOutsideWorkHours($start, $end)
	{
		global $conf;

		// Use Dolibarr timezone if configured, otherwise server default
		$tz = !empty($conf->global->MAIN_SERVER_TZ) ? $conf->global->MAIN_SERVER_TZ : date_default_timezone_get();
		try {
			$dateTime = new DateTime('now', new DateTimeZone($tz));
			$now = $dateTime->format('H:i:s');
		} catch (Exception $e) {
			$now = date('H:i:s');
		}
		return ($now < $start || $now > $end);
	}

	/**
	 * Check if conversation has no agent assigned
	 *
	 * @param int $conversationId Conversation ID
	 * @return bool
	 */
	private function isConversationUnassigned($conversationId)
	{
		global $conf;

		$sql = "SELECT fk_user_assigned FROM " . MAIN_DB_PREFIX . "whatsapp_conversations";
		$sql .= " WHERE rowid = " . ((int) $conversationId);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			if (!empty($obj->fk_user_assigned)) {
				return false;
			}
			// Also check multi-agent table
			$sql2 = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "whatsapp_conversation_agents";
			$sql2 .= " WHERE fk_conversation = " . ((int) $conversationId);
			$res2 = $this->db->query($sql2);
			if ($res2 && ($obj2 = $this->db->fetch_object($res2))) {
				return ((int) $obj2->cnt === 0);
			}
			return true;
		}
		return true; // If can't find, assume unassigned
	}

	/**
	 * Get how many times a rule has been triggered for a conversation
	 *
	 * @param int $ruleId         Rule ID
	 * @param int $conversationId Conversation ID
	 * @return int Count
	 */
	private function getTriggerCount($ruleId, $conversationId)
	{
		$sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . self::LOG_TABLE;
		$sql .= " WHERE fk_rule = " . ((int) $ruleId);
		$sql .= " AND fk_conversation = " . ((int) $conversationId);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->cnt;
		}
		return 0;
	}

	/**
	 * Execute a matched rule: send the auto-reply
	 *
	 * @param object $rule           Rule object
	 * @param int    $conversationId Conversation ID
	 * @param int    $inboundMsgId   Inbound message ID that triggered this
	 * @param string $phoneNumber    Phone number to send to
	 * @return array Result with 'success' boolean
	 */
	public function executeRule($rule, $conversationId, $inboundMsgId, $phoneNumber, $lineId = 0)
	{
		global $db;

		require_once dirname(__FILE__) . '/whatsappmanager.class.php';
		require_once dirname(__FILE__) . '/whatsappmessage.class.php';
		require_once dirname(__FILE__) . '/whatsappevent.class.php';

		$manager = new WhatsAppManager($this->db, $lineId > 0 ? $lineId : 0);

		// Send response based on type
		$sendResult = null;
		$messageContent = '';

		if ($rule->response_type === 'template' && !empty($rule->response_template_name)) {
			// Send template message
			$params = array();
			if (!empty($rule->response_template_params)) {
				$decoded = json_decode($rule->response_template_params, true);
				if (is_array($decoded)) {
					$params = $decoded;
				}
			}
			$sendResult = $manager->sendTemplateMessage($phoneNumber, $rule->response_template_name, $params);
			$messageContent = '[Template: ' . $rule->response_template_name . ']';
		} else {
			// Send text message
			// Replace variables in response text
			$responseText = $this->replaceVariables($rule->response_text, $conversationId);
			$sendResult = $manager->sendTextMessage($phoneNumber, $responseText);
			$messageContent = $responseText;
		}

		if (!$sendResult || !$sendResult['success']) {
			dol_syslog("WhatsApp Chatbot: Failed to send auto-reply for rule " . $rule->rowid . ": " . ($sendResult['error'] ?? 'Unknown error'), LOG_WARNING);
			return array('success' => false, 'error' => $sendResult['error'] ?? 'Send failed');
		}

		// Save outbound message
		$outMessage = new WhatsAppMessage($this->db);
		$outMessage->message_id = $sendResult['message_id'];
		$outMessage->fk_conversation = $conversationId;
		$outMessage->fk_line = $lineId;
		$outMessage->direction = 'outbound';
		$outMessage->message_type = ($rule->response_type === 'template') ? 'template' : 'text';
		$outMessage->content = $messageContent;
		$outMessage->template_name = $rule->response_template_name;
		$outMessage->status = 'sent';
		$outMessage->fk_user_sender = 0; // System/bot
		$outMessage->timestamp = dol_now();

		$webhookUser = new User($this->db);
		$adminUserId = getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1);
		$webhookUser->fetch($adminUserId);
		$outMessage->create($webhookUser);

		// Log trigger
		$this->logTrigger($rule->rowid, $conversationId, $inboundMsgId, $outMessage->id);

		// Emit SSE event
		$eventEmitter = new WhatsAppEvent($this->db);
		$eventEmitter->emitNewMessage(
			$conversationId,
			'outbound',
			$outMessage->message_type,
			mb_substr($messageContent, 0, 80),
			$phoneNumber,
			'',
			$lineId
		);

		dol_syslog("WhatsApp Chatbot: Auto-reply sent for rule '" . $rule->name . "' (ID:" . $rule->rowid . ") to conversation " . $conversationId, LOG_INFO);

		return array('success' => true, 'message_id' => $sendResult['message_id']);
	}

	/**
	 * Log a rule trigger
	 *
	 * @param int $ruleId         Rule ID
	 * @param int $conversationId Conversation ID
	 * @param int $msgInId        Inbound message ID
	 * @param int $msgOutId       Outbound message ID
	 */
	private function logTrigger($ruleId, $conversationId, $msgInId, $msgOutId)
	{
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::LOG_TABLE . " (";
		$sql .= "fk_rule, fk_conversation, fk_message_in, fk_message_out, date_triggered";
		$sql .= ") VALUES (";
		$sql .= ((int) $ruleId);
		$sql .= ", " . ((int) $conversationId);
		$sql .= ", " . ((int) $msgInId);
		$sql .= ", " . ((int) $msgOutId);
		$sql .= ", '" . $this->db->idate(dol_now()) . "'";
		$sql .= ")";

		$this->db->query($sql);
	}

	/**
	 * Replace template variables in response text
	 * Supports: {contact_name}, {phone}, {date}, {time}
	 *
	 * @param string $text           Response text with placeholders
	 * @param int    $conversationId Conversation ID
	 * @return string Processed text
	 */
	private function replaceVariables($text, $conversationId)
	{
		global $conf;

		// Fetch conversation data
		$sql = "SELECT contact_name, phone_number FROM " . MAIN_DB_PREFIX . "whatsapp_conversations";
		$sql .= " WHERE rowid = " . ((int) $conversationId);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$contactName = '';
		$phone = '';
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$contactName = $obj->contact_name;
			$phone = $obj->phone_number;
		}

		$replacements = array(
			'{contact_name}' => $contactName ?: 'Cliente',
			'{phone}' => $phone,
			'{date}' => dol_print_date(dol_now(), 'day'),
			'{time}' => dol_print_date(dol_now(), 'hour'),
		);

		return str_replace(array_keys($replacements), array_values($replacements), $text);
	}

	/**
	 * Get trigger statistics for admin display
	 *
	 * @param int $ruleId Rule ID
	 * @return array Stats array with 'total', 'today', 'last_triggered'
	 */
	public function getRuleStats($ruleId)
	{
		$stats = array('total' => 0, 'today' => 0, 'last_triggered' => null);

		// Total
		$sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . self::LOG_TABLE;
		$sql .= " WHERE fk_rule = " . ((int) $ruleId);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$stats['total'] = (int) $obj->cnt;
		}

		// Today
		$sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . self::LOG_TABLE;
		$sql .= " WHERE fk_rule = " . ((int) $ruleId);
		$sql .= " AND DATE(date_triggered) = CURDATE()";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$stats['today'] = (int) $obj->cnt;
		}

		// Last triggered
		$sql = "SELECT date_triggered FROM " . MAIN_DB_PREFIX . self::LOG_TABLE;
		$sql .= " WHERE fk_rule = " . ((int) $ruleId);
		$sql .= " ORDER BY date_triggered DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$stats['last_triggered'] = $obj->date_triggered;
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
		$this->name = $obj->name;
		$this->trigger_type = $obj->trigger_type;
		$this->trigger_value = $obj->trigger_value;
		$this->response_type = $obj->response_type;
		$this->response_text = $obj->response_text;
		$this->response_template_name = $obj->response_template_name;
		$this->response_template_params = $obj->response_template_params;
		$this->delay_seconds = $obj->delay_seconds;
		$this->condition_type = $obj->condition_type;
		$this->work_hours_start = $obj->work_hours_start;
		$this->work_hours_end = $obj->work_hours_end;
		$this->max_triggers_per_conv = $obj->max_triggers_per_conv;
		$this->priority = $obj->priority;
		$this->stop_on_match = $obj->stop_on_match;
		$this->active = $obj->active;
		$this->date_creation = $obj->date_creation;
		$this->tms = $obj->tms;
		$this->fk_user_creat = $obj->fk_user_creat;
	}
}
