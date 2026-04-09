<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappcsat.class.php
 * \ingroup    whatsappdati
 * \brief      Customer Satisfaction (CSAT) survey management
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp CSAT (Customer Satisfaction) surveys
 */
class WhatsAppCSAT
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	public $id;
	public $entity;
	public $fk_conversation;
	public $fk_line;
	public $phone_number;
	public $rating;
	public $feedback_text;
	public $fk_user_agent;
	public $sent_at;
	public $responded_at;
	public $message_id_wa;
	public $status;
	public $date_creation;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------------
	// Configuration helpers (reads from dolibarr const table)
	// ------------------------------------------------------------------

	/**
	 * Check if CSAT is enabled
	 *
	 * @return bool
	 */
	public static function isEnabled()
	{
		return !empty(getDolGlobalString('WHATSAPPDATI_CSAT_ENABLED'));
	}

	/**
	 * Get the survey message template text
	 *
	 * @return string
	 */
	public static function getSurveyMessage()
	{
		$msg = getDolGlobalString('WHATSAPPDATI_CSAT_MESSAGE');
		if (empty($msg)) {
			$msg = "Gracias por contactarnos. Por favor califique su experiencia del 1 al 5:\n1 ⭐ Muy mala\n2 ⭐⭐ Mala\n3 ⭐⭐⭐ Regular\n4 ⭐⭐⭐⭐ Buena\n5 ⭐⭐⭐⭐⭐ Excelente";
		}
		return $msg;
	}

	/**
	 * Get the thank-you message after response
	 *
	 * @return string
	 */
	public static function getThanksMessage()
	{
		$msg = getDolGlobalString('WHATSAPPDATI_CSAT_THANKS');
		if (empty($msg)) {
			$msg = "¡Gracias por su calificación! Su opinión nos ayuda a mejorar. 🙏";
		}
		return $msg;
	}

	// ------------------------------------------------------------------
	// CRUD
	// ------------------------------------------------------------------

	/**
	 * Create a CSAT survey record
	 *
	 * @return int  <0 if KO, ID if OK
	 */
	public function create()
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_csat (";
		$sql .= "entity, fk_conversation, fk_line, phone_number, fk_user_agent,";
		$sql .= " sent_at, status, date_creation";
		$sql .= ") VALUES (";
		$sql .= (int) $conf->entity.",";
		$sql .= " ".(int) $this->fk_conversation.",";
		$sql .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " '".$this->db->escape($this->phone_number)."',";
		$sql .= " ".($this->fk_user_agent > 0 ? (int) $this->fk_user_agent : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."',";
		$sql .= " 'sent',";
		$sql .= " '".$this->db->idate(dol_now())."'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."whatsapp_csat");
			return $this->id;
		}
		return -1;
	}

	/**
	 * Fetch a CSAT record by ID
	 *
	 * @param  int $id  Record ID
	 * @return int      <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."whatsapp_csat WHERE rowid = ".(int) $id;
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			$obj = $this->db->fetch_object($resql);
			return $this->_mapFromDb($obj);
		}
		return $resql ? 0 : -1;
	}

	/**
	 * Find a pending CSAT survey for a given conversation
	 *
	 * @param  int $conversationId  Conversation row ID
	 * @return int                  <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchPendingByConversation($conversationId)
	{
		global $conf;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."whatsapp_csat";
		$sql .= " WHERE fk_conversation = ".(int) $conversationId;
		$sql .= " AND entity = ".(int) $conf->entity;
		$sql .= " AND status = 'sent'";
		$sql .= " ORDER BY sent_at DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			$obj = $this->db->fetch_object($resql);
			return $this->_mapFromDb($obj);
		}
		return $resql ? 0 : -1;
	}

	/**
	 * Record the customer's rating response
	 *
	 * @param  int    $rating       Rating 1-5
	 * @param  string $feedbackText Optional feedback text
	 * @return int                  1 if OK, <0 if KO
	 */
	public function recordRating($rating, $feedbackText = '')
	{
		$rating = max(1, min(5, (int) $rating));

		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_csat SET";
		$sql .= " rating = ".$rating.",";
		$sql .= " feedback_text = ".(!empty($feedbackText) ? "'".$this->db->escape($feedbackText)."'" : "NULL").",";
		$sql .= " responded_at = '".$this->db->idate(dol_now())."',";
		$sql .= " status = 'responded'";
		$sql .= " WHERE rowid = ".(int) $this->id;

		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Fetch the latest CSAT for a conversation (any status)
	 *
	 * @param  int $conversationId  Conversation row ID
	 * @return int                  <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchByConversation($conversationId)
	{
		global $conf;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."whatsapp_csat";
		$sql .= " WHERE fk_conversation = ".(int) $conversationId;
		$sql .= " AND entity = ".(int) $conf->entity;
		$sql .= " ORDER BY sent_at DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			$obj = $this->db->fetch_object($resql);
			return $this->_mapFromDb($obj);
		}
		return $resql ? 0 : -1;
	}

	/**
	 * Get aggregate CSAT statistics
	 *
	 * @param  int    $agentId  Optional: filter by agent (0 = all)
	 * @param  string $from     Optional: date from (Y-m-d)
	 * @param  string $to       Optional: date to (Y-m-d)
	 * @return array            [total_surveys, responded, avg_rating, distribution => [1=>n, ...]]
	 */
	public function getStats($agentId = 0, $from = '', $to = '')
	{
		global $conf;

		$where = " entity = ".(int) $conf->entity;
		if ($agentId > 0) {
			$where .= " AND fk_user_agent = ".(int) $agentId;
		}
		if (!empty($from)) {
			// SECURITY: Validate date format before using in SQL
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
				$from = '';
			} else {
				$where .= " AND sent_at >= '".$this->db->escape($from)." 00:00:00'";
			}
		}
		if (!empty($to)) {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
				$to = '';
			} else {
				$where .= " AND sent_at <= '".$this->db->escape($to)." 23:59:59'";
			}
		}

		$stats = array(
			'total_surveys' => 0,
			'responded'     => 0,
			'avg_rating'    => 0,
			'distribution'  => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0)
		);

		// Total and responded
		$sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded,";
		$sql .= " AVG(CASE WHEN rating IS NOT NULL THEN rating END) as avg_rating";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_csat";
		$sql .= " WHERE ".$where;

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$stats['total_surveys'] = (int) $obj->total;
			$stats['responded']     = (int) $obj->responded;
			$stats['avg_rating']    = round((float) $obj->avg_rating, 2);
		}

		// Distribution
		$sql2 = "SELECT rating, COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."whatsapp_csat";
		$sql2 .= " WHERE ".$where." AND rating IS NOT NULL";
		$sql2 .= " GROUP BY rating ORDER BY rating";

		$resql2 = $this->db->query($sql2);
		if ($resql2) {
			while ($obj2 = $this->db->fetch_object($resql2)) {
				$stats['distribution'][(int) $obj2->rating] = (int) $obj2->cnt;
			}
		}

		return $stats;
	}

	/**
	 * Send a CSAT survey via WhatsApp for a conversation
	 *
	 * @param  int              $conversationId  Conversation ID
	 * @param  WhatsAppManager  $manager         Manager instance
	 * @return array            [success => bool, error => string]
	 */
	public function sendSurvey($conversationId, $manager)
	{
		global $db;

		require_once __DIR__.'/whatsappconversation.class.php';
		require_once __DIR__.'/whatsappmessage.class.php';

		$conversation = new WhatsAppConversation($db);
		if ($conversation->fetch($conversationId) <= 0) {
			return array('success' => false, 'error' => 'Conversation not found');
		}

		// Check if there's already a pending survey
		$existing = new self($db);
		if ($existing->fetchPendingByConversation($conversationId) > 0) {
			return array('success' => false, 'error' => 'Survey already pending');
		}

		$messageText = self::getSurveyMessage();

		// Send via WhatsApp API
		$result = $manager->sendTextMessage($conversation->phone_number, $messageText);
		if (!$result['success']) {
			return array('success' => false, 'error' => $result['error']);
		}

		// Save outbound message
		$webhookUser = new User($db);
		$webhookUser->fetch(getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1));

		$message = new WhatsAppMessage($db);
		$message->message_id = $result['message_id'];
		$message->fk_conversation = $conversationId;
		$message->fk_line = $conversation->fk_line;
		$message->direction = 'outbound';
		$message->message_type = 'text';
		$message->content = $messageText;
		$message->status = 'sent';
		$message->timestamp = dol_now();
		$message->create($webhookUser);

		// Create CSAT record
		$this->fk_conversation = $conversationId;
		$this->fk_line = $conversation->fk_line;
		$this->phone_number = $conversation->phone_number;
		$this->fk_user_agent = $conversation->fk_user_assigned;
		$this->message_id_wa = $result['message_id'];
		$csatId = $this->create();

		if ($csatId > 0) {
			return array('success' => true, 'csat_id' => $csatId, 'message_id' => $result['message_id']);
		}
		return array('success' => false, 'error' => 'Failed to create CSAT record');
	}

	/**
	 * Check an inbound message for a CSAT rating response (1-5)
	 * If found, records the rating and sends a thank-you message.
	 *
	 * @param  int             $conversationId  Conversation ID
	 * @param  string          $messageContent  Message text
	 * @param  WhatsAppManager $manager         Manager instance (for sending thanks)
	 * @param  string          $phoneNumber     Phone number
	 * @return bool            True if message was a CSAT response, false otherwise
	 */
	public function processInboundForCSAT($conversationId, $messageContent, $manager, $phoneNumber)
	{
		global $db;

		if (!self::isEnabled()) {
			return false;
		}

		// Check for a pending survey on this conversation
		$pending = new self($db);
		if ($pending->fetchPendingByConversation($conversationId) <= 0) {
			return false;
		}

		// Extract rating: look for a single digit 1-5 in the message
		$content = trim($messageContent);
		if (preg_match('/^([1-5])(?:\s|$|\.)/', $content, $matches)) {
			$rating = (int) $matches[1];
		} elseif (preg_match('/^([1-5])$/', $content, $matches)) {
			$rating = (int) $matches[1];
		} else {
			// Not a rating response
			return false;
		}

		// Extract any additional text as feedback
		$feedback = trim(preg_replace('/^[1-5]\s*/', '', $content));

		// Record the rating
		$pending->recordRating($rating, $feedback);

		// Send thank-you message
		$thanksMsg = self::getThanksMessage();
		$result = $manager->sendTextMessage($phoneNumber, $thanksMsg);

		if ($result['success']) {
			require_once __DIR__.'/whatsappmessage.class.php';
			$webhookUser = new User($db);
			$webhookUser->fetch(getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1));

			$message = new WhatsAppMessage($db);
			$message->message_id = $result['message_id'];
			$message->fk_conversation = $conversationId;
			$message->fk_line = $pending->fk_line;
			$message->direction = 'outbound';
			$message->message_type = 'text';
			$message->content = $thanksMsg;
			$message->status = 'sent';
			$message->timestamp = dol_now();
			$message->create($webhookUser);
		}

		dol_syslog("WhatsApp CSAT: Recorded rating ".$rating." for conversation ".$conversationId, LOG_INFO);
		return true;
	}

	/**
	 * Map database row to object properties
	 *
	 * @param  object $obj  Database row
	 * @return int          1
	 */
	private function _mapFromDb($obj)
	{
		$this->id               = (int) $obj->rowid;
		$this->entity           = (int) $obj->entity;
		$this->fk_conversation  = (int) $obj->fk_conversation;
		$this->fk_line          = (int) $obj->fk_line;
		$this->phone_number     = $obj->phone_number;
		$this->rating           = $obj->rating !== null ? (int) $obj->rating : null;
		$this->feedback_text    = $obj->feedback_text;
		$this->fk_user_agent   = $obj->fk_user_agent ? (int) $obj->fk_user_agent : null;
		$this->sent_at          = $obj->sent_at;
		$this->responded_at     = $obj->responded_at;
		$this->message_id_wa    = $obj->message_id_wa;
		$this->status           = $obj->status;
		$this->date_creation    = $obj->date_creation;
		return 1;
	}
}
