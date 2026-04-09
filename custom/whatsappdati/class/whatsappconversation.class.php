<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappconversation.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Conversation class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Conversation
 */
class WhatsAppConversation extends CommonObject
{
	public $element = 'whatsappconversation';
	public $table_element = 'whatsapp_conversations';
	public $ismultientitymanaged = 1;
	public $picto = 'whatsappdati@whatsappdati';

	public $conversation_id;
	public $fk_line;
	public $phone_number;
	public $contact_name;
	public $fk_soc;
	public $fk_user_assigned;
	public $status;
	public $last_message_date;
	public $last_message_preview;
	public $unread_count;
	public $window_expires_at;
	public $date_creation;
	public $date_modification;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create conversation in database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		global $conf;

		$error = 0;

		// Clean parameters
		$this->conversation_id = trim($this->conversation_id);
		$this->phone_number = trim($this->phone_number ?? '');
		// Sanitize contact_name: remove 4-byte UTF-8 chars (emojis) if DB charset unknown
		if (!empty($this->contact_name)) {
			$this->contact_name = trim($this->contact_name);
		}

		// Generate conversation_id if not provided
		if (empty($this->conversation_id)) {
			$this->conversation_id = 'conv_'.$this->phone_number.'_'.time();
		}

		// Insert request
		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity,";
		$sql .= "fk_line,";
		$sql .= "conversation_id,";
		$sql .= "phone_number,";
		$sql .= "contact_name,";
		$sql .= "fk_soc,";
		$sql .= "fk_user_assigned,";
		$sql .= "status,";
		$sql .= "last_message_date,";
		$sql .= "unread_count,";
		$sql .= "window_expires_at,";
		$sql .= "date_creation";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " '".$this->db->escape($this->conversation_id)."',";
		$sql .= " '".$this->db->escape($this->phone_number)."',";
		$sql .= " ".($this->contact_name ? "'".$this->db->escape($this->contact_name)."'" : "NULL").",";
		$sql .= " ".($this->fk_soc > 0 ? (int) $this->fk_soc : "NULL").",";
		$sql .= " ".($this->fk_user_assigned > 0 ? (int) $this->fk_user_assigned : "NULL").",";
		$sql .= " '".$this->db->escape($this->status ? $this->status : 'active')."',";
		$sql .= " '".$this->db->idate(dol_now())."',";
		$sql .= " 0,";
		$sql .= " '".$this->db->idate(dol_now() + 86400)."',"; // 24h from now
		$sql .= " '".$this->db->idate(dol_now())."'";
		$sql .= ")";

		$this->db->begin();

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			// If charset error (emoji), retry with sanitized contact_name
			$dbError = $this->db->lasterror();
			if (strpos($dbError, 'Incorrect string value') !== false && !empty($this->contact_name)) {
				$this->db->rollback();
				$this->contact_name = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $this->contact_name);
				// Rebuild SQL with sanitized name
				$sql2 = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
				$sql2 .= "entity,fk_line,conversation_id,phone_number,contact_name,fk_soc,fk_user_assigned,status,last_message_date,unread_count,window_expires_at,date_creation";
				$sql2 .= ") VALUES (";
				$sql2 .= " ".$conf->entity.",";
				$sql2 .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
				$sql2 .= " '".$this->db->escape($this->conversation_id)."',";
				$sql2 .= " '".$this->db->escape($this->phone_number)."',";
				$sql2 .= " ".($this->contact_name ? "'".$this->db->escape($this->contact_name)."'" : "NULL").",";
				$sql2 .= " ".($this->fk_soc > 0 ? (int) $this->fk_soc : "NULL").",";
				$sql2 .= " ".($this->fk_user_assigned > 0 ? (int) $this->fk_user_assigned : "NULL").",";
				$sql2 .= " '".$this->db->escape($this->status ? $this->status : 'active')."',";
				$sql2 .= " '".$this->db->idate(dol_now())."',";
				$sql2 .= " 0,";
				$sql2 .= " '".$this->db->idate(dol_now() + 86400)."',";
				$sql2 .= " '".$this->db->idate(dol_now())."'";
				$sql2 .= ")";
				$this->db->begin();
				$resql = $this->db->query($sql2);
				if (!$resql) {
					$error++;
					$this->errors[] = "Error ".$this->db->lasterror();
				}
			} else {
				$error++;
				$this->errors[] = "Error ".$dbError;
			}
		}

		if (!$error) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		}

		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return $this->id;
		}
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id   Id object
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.entity,";
		$sql .= " t.fk_line,";
		$sql .= " t.conversation_id,";
		$sql .= " t.phone_number,";
		$sql .= " t.contact_name,";
		$sql .= " t.fk_soc,";
		$sql .= " t.fk_user_assigned,";
		$sql .= " t.status,";
		$sql .= " t.last_message_date,";
		$sql .= " t.last_message_preview,";
		$sql .= " t.unread_count,";
		$sql .= " t.window_expires_at,";
		$sql .= " t.date_creation,";
		$sql .= " t.date_modification";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);
		$sql .= " AND t.entity IN (".getEntity('whatsappconversation').")";

		dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$numrows = $this->db->num_rows($resql);
			if ($numrows) {
				$obj = $this->db->fetch_object($resql);

				$this->id = $obj->rowid;
				$this->entity = $obj->entity;
				$this->fk_line = $obj->fk_line;
				$this->conversation_id = $obj->conversation_id;
				$this->phone_number = $obj->phone_number;
				$this->contact_name = $obj->contact_name;
				$this->fk_soc = $obj->fk_soc;
				$this->fk_user_assigned = $obj->fk_user_assigned;
				$this->status = $obj->status;
				$this->last_message_date = $this->db->jdate($obj->last_message_date);
				$this->last_message_preview = $obj->last_message_preview;
				$this->unread_count = $obj->unread_count;
				$this->window_expires_at = $this->db->jdate($obj->window_expires_at);
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->date_modification = $this->db->jdate($obj->date_modification);
			}
			$this->db->free($resql);

			if ($numrows) {
				return 1;
			} else {
				return 0;
			}
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(get_class($this).'::fetch '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Fetch conversation by phone number
	 *
	 * @param  string $phone  Phone number
	 * @param  int    $lineId Optional line ID filter (0 = any line)
	 * @return int            <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchByPhone($phone, $lineId = 0)
	{
		global $conf;

		// Normalize: strip non-digits and match by last 10 digits (suffix)
		// This handles format variations: +57 300 123 4567, 573001234567, 3001234567
		$digits = preg_replace('/[^0-9]/', '', $phone);
		$suffixLen = 10;
		$suffix = strlen($digits) >= $suffixLen ? substr($digits, -$suffixLen) : $digits;

		$stripSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number, '+', ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', '')";

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE RIGHT(".$stripSql.", ".strlen($suffix).") = '".$this->db->escape($suffix)."'";
		$sql .= " AND entity = ".$conf->entity;
		$sql .= " AND status = 'active'";
		if ($lineId > 0) {
			$sql .= " AND fk_line = ".((int) $lineId);
		}
		$sql .= " ORDER BY date_creation DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return $this->fetch($obj->rowid);
			}
			return 0;
		}
		return -1;
	}

	/**
	 * Get list of active conversations
	 *
	 * @param  int    $user_id   Filter by assigned user (0 = all)
	 * @param  string $sortfield Sort field
	 * @param  string $sortorder Sort order
	 * @param  int    $limit     Limit
	 * @param  int    $offset    Offset
	 * @param  int    $lineId    Filter by line (0 = all)
	 * @param  int    $tag_id    Filter by tag (0 = all)
	 * @return array             Array of conversations
	 */
	/**
	 * Get list of active conversations
	 *
	 * @param  int    $user_id     Filter by assigned user (0 = all)
	 * @param  string $sortfield   Sort field
	 * @param  string $sortorder   Sort order
	 * @param  int    $limit       Limit
	 * @param  int    $offset      Offset
	 * @param  int    $lineId      Filter by line (0 = all)
	 * @param  int    $tag_id      Filter by tag (0 = all)
	 * @param  bool   $unread_only Only conversations with unread_count > 0
	 * @param  string $search      Search term (matches contact_name or phone_number)
	 * @return array               Array of conversations
	 */
	public function fetchAll($user_id = 0, $sortfield = 'last_message_date', $sortorder = 'DESC', $limit = 100, $offset = 0, $lineId = 0, $tag_id = 0, $unread_only = false, $search = '', $scopeUserId = 0)
	{
		global $conf;

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.fk_line,";
		$sql .= " t.conversation_id,";
		$sql .= " t.phone_number,";
		$sql .= " t.contact_name,";
		$sql .= " t.fk_soc,";
		$sql .= " t.fk_user_assigned,";
		$sql .= " t.status,";
		$sql .= " t.last_message_date,";
		$sql .= " t.last_message_preview,";
		$sql .= " t.unread_count,";
		$sql .= " t.window_expires_at,";
		$sql .= " u.firstname AS agent_firstname,";
		$sql .= " u.lastname AS agent_lastname,";
		$sql .= " u.login AS agent_login";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = t.fk_user_assigned";
		$sql .= " WHERE t.entity = ".$conf->entity;
		$sql .= " AND t.status = 'active'";

		// Visibility scope: non-admin users only see conversations on their
		// assigned lines or conversations explicitly assigned to them
		if ($scopeUserId > 0) {
			$sql .= " AND (";
			// Conversations on lines the user is assigned to WHERE the conversation is unassigned (manual/claim mode)
			$sql .= "(EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."whatsapp_line_agents la WHERE la.fk_line = t.fk_line AND la.fk_user = ".((int) $scopeUserId).") AND t.fk_user_assigned IS NULL)";
			// OR conversations directly assigned to the user (primary)
			$sql .= " OR t.fk_user_assigned = ".((int) $scopeUserId);
			// OR conversations where the user is in the multi-agent table
			$sql .= " OR EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents ca WHERE ca.fk_conversation = t.rowid AND ca.fk_user = ".((int) $scopeUserId).")";
			$sql .= ")";
		}

		// Additional filter: "My conversations" toggle (further narrows within visible scope)
		if ($user_id > 0) {
			$sql .= " AND (t.fk_user_assigned = ".((int) $user_id);
			$sql .= " OR EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents ca2 WHERE ca2.fk_conversation = t.rowid AND ca2.fk_user = ".((int) $user_id)."))";
		}
		if ($lineId > 0) {
			$sql .= " AND t.fk_line = ".((int) $lineId);
		}
		if ($tag_id > 0) {
			$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."whatsapp_conversation_tags ct WHERE ct.fk_conversation = t.rowid AND ct.fk_tag = ".((int) $tag_id).")";
		}
		if ($unread_only) {
			$sql .= " AND t.unread_count > 0";
		}
		if (!empty($search)) {
			$escapedSearch = $this->db->escape($search);
			$sql .= " AND (t.contact_name LIKE '%".$escapedSearch."%' OR t.phone_number LIKE '%".$escapedSearch."%')";
		}
		
		// Whitelist allowed sort columns to prevent SQL injection
		$allowedSortFields = array('last_message_date', 'contact_name', 'phone_number', 'unread_count', 'date_creation', 'status', 'rowid');
		if (!in_array($sortfield, $allowedSortFields)) {
			$sortfield = 'last_message_date';
		}
		$sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';

		$sql .= " ORDER BY t.".$sortfield." ".$sortorder;
		$sql .= " LIMIT ".((int) $limit)." OFFSET ".((int) $offset);

		$resql = $this->db->query($sql);
		if ($resql) {
			$conversations = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$conversations[] = $obj;
			}
			return $conversations;
		}
		return array();
	}

	/**
	 * Update conversation
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " contact_name = ".($this->contact_name ? "'".$this->db->escape($this->contact_name)."'" : "NULL").",";
		$sql .= " fk_soc = ".($this->fk_soc > 0 ? (int) $this->fk_soc : "NULL").",";
		$sql .= " fk_user_assigned = ".($this->fk_user_assigned > 0 ? (int) $this->fk_user_assigned : "NULL").",";
		$sql .= " status = '".$this->db->escape($this->status)."',";
		$sql .= " last_message_date = ".($this->last_message_date ? "'".$this->db->idate($this->last_message_date)."'" : "NULL").",";
		$sql .= " last_message_preview = ".($this->last_message_preview ? "'".$this->db->escape($this->last_message_preview)."'" : "NULL").",";
		$sql .= " unread_count = ".((int) $this->unread_count).",";
		$sql .= " window_expires_at = ".($this->window_expires_at ? "'".$this->db->idate($this->window_expires_at)."'" : "NULL").",";
		$sql .= " date_modification = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		} else {
			$this->errors[] = "Error ".$this->db->lasterror();
			return -1;
		}
	}

	/**
	 * Find a third party (patient) by phone number with fuzzy matching.
	 * Strips all non-digit characters and compares last N digits to handle
	 * format variations: +57 300 123 4567, 573001234567, 300 123 4567, etc.
	 *
	 * @param  DoliDB $db    Database handler
	 * @param  string $phone Phone number from WhatsApp (digits only, e.g. 573001234567)
	 * @param  int    $minDigits Minimum suffix length to match (default 10)
	 * @return int    rowid of societe if unique match found, 0 if no match or multiple matches
	 */
	public static function findSocByPhone(DoliDB $db, $phone, $minDigits = 10)
	{
		global $conf;

		// Normalize incoming phone: keep only digits
		$digits = preg_replace('/[^0-9]/', '', $phone);
		if (strlen($digits) < 7) {
			return 0; // Too short to be a valid phone
		}

		// Extract suffix for matching (last N digits)
		$suffix = substr($digits, -$minDigits);
		$suffixLen = strlen($suffix);

		// Search in societe: phone, phone_mobile, fax
		// We strip non-digits from stored values using REPLACE chains, then compare right N chars
		$stripSql = function ($field) {
			// Remove common non-digit chars: +, -, (, ), space, dot
			return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(".$field.", '+', ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', '')";
		};

		$sqlPhone = $stripSql('s.phone');
		$sqlMobile = $stripSql('s.phone_mobile');
		$sqlFax = $stripSql('s.fax');

		$sql = "SELECT DISTINCT s.rowid FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE s.entity IN (".getEntity('societe').")";
		$sql .= " AND s.status = 1";
		$sql .= " AND (";
		$sql .= " RIGHT(".$sqlPhone.", ".$suffixLen.") = '".$db->escape($suffix)."'";
		$sql .= " OR RIGHT(".$sqlMobile.", ".$suffixLen.") = '".$db->escape($suffix)."'";
		$sql .= " OR RIGHT(".$sqlFax.", ".$suffixLen.") = '".$db->escape($suffix)."'";
		$sql .= ")";
		$sql .= " LIMIT 2"; // We only need to know if there's exactly 1

		dol_syslog("WhatsAppConversation::findSocByPhone suffix=".$suffix, LOG_DEBUG);

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) == 1) {
				$obj = $db->fetch_object($resql);
				return (int) $obj->rowid;
			}
		}

		return 0; // No match or multiple matches
	}
}
