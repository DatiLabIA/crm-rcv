<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsapptag.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Tag / Label class for conversation categorization
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Tags (Etiquetas)
 */
class WhatsAppTag extends CommonObject
{
	public $element = 'whatsapptag';
	public $table_element = 'whatsapp_tags';
	public $ismultientitymanaged = 1;

	public $label;
	public $color;
	public $description;
	public $position;
	public $active;
	public $date_creation;
	public $fk_user_creat;

	// Default colors palette
	const DEFAULT_COLORS = array(
		'#25D366', // WhatsApp green
		'#128C7E', // Teal
		'#075E54', // Dark teal
		'#34B7F1', // Blue
		'#FF6B6B', // Red
		'#FFA726', // Orange
		'#AB47BC', // Purple
		'#78909C', // Gray
		'#EC407A', // Pink
		'#66BB6A', // Light green
	);

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
	 * Create tag in database
	 *
	 * @param  User $user User that creates
	 * @return int        <0 if KO, Id of created object if OK
	 */
	public function create(User $user)
	{
		global $conf;

		$error = 0;
		$this->label = trim($this->label);

		if (empty($this->label)) {
			$this->errors[] = 'ErrorTagLabelRequired';
			return -1;
		}

		if (empty($this->color)) {
			$this->color = self::DEFAULT_COLORS[0];
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, label, color, description, position, active, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " '".$this->db->escape($this->label)."',";
		$sql .= " '".$this->db->escape($this->color)."',";
		$sql .= " ".($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").",";
		$sql .= " ".((int) $this->position).",";
		$sql .= " 1,";
		$sql .= " '".$this->db->idate(dol_now())."',";
		$sql .= " ".((int) $user->id);
		$sql .= ")";

		$this->db->begin();

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			if ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->errors[] = 'ErrorTagAlreadyExists';
			} else {
				$this->errors[] = "Error ".$this->db->lasterror();
			}
		}

		if (!$error) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		} else {
			$this->db->commit();
			return $this->id;
		}
	}

	/**
	 * Load tag from database
	 *
	 * @param  int $id Tag ID
	 * @return int     <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT rowid, entity, label, color, description, position, active, date_creation, fk_user_creat";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $id);
		$sql .= " AND entity IN (".getEntity('whatsapptag').")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = $obj->rowid;
				$this->entity = $obj->entity;
				$this->label = $obj->label;
				$this->color = $obj->color;
				$this->description = $obj->description;
				$this->position = $obj->position;
				$this->active = $obj->active;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->fk_user_creat = $obj->fk_user_creat;
				$this->db->free($resql);
				return 1;
			}
			$this->db->free($resql);
			return 0;
		}
		$this->errors[] = 'Error '.$this->db->lasterror();
		return -1;
	}

	/**
	 * Update tag
	 *
	 * @param  User $user User that modifies
	 * @return int        <0 if KO, >0 if OK
	 */
	public function update(User $user)
	{
		global $conf;

		$this->label = trim($this->label);

		if (empty($this->label)) {
			$this->errors[] = 'ErrorTagLabelRequired';
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " label = '".$this->db->escape($this->label)."',";
		$sql .= " color = '".$this->db->escape($this->color)."',";
		$sql .= " description = ".($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").",";
		$sql .= " position = ".((int) $this->position).",";
		$sql .= " active = ".((int) $this->active);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		$this->errors[] = "Error ".$this->db->lasterror();
		return -1;
	}

	/**
	 * Delete tag and all its conversation associations
	 *
	 * @param  User $user User that deletes
	 * @return int        <0 if KO, >0 if OK
	 */
	public function delete(User $user)
	{
		global $conf;

		$this->db->begin();

		// Associations are deleted via CASCADE, but be explicit
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."whatsapp_conversation_tags";
		$sql .= " WHERE fk_tag = ".((int) $this->id);
		$this->db->query($sql);

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		}
		$this->db->rollback();
		$this->errors[] = "Error ".$this->db->lasterror();
		return -1;
	}

	/**
	 * Get all active tags for current entity
	 *
	 * @return array Array of tag objects
	 */
	public function fetchAll()
	{
		global $conf;

		$tags = array();

		$sql = "SELECT rowid, label, color, description, position, active";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND active = 1";
		$sql .= " ORDER BY position ASC, label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$tags[] = $obj;
			}
			$this->db->free($resql);
		}
		return $tags;
	}

	/**
	 * Get all tags (including inactive) for admin
	 *
	 * @return array Array of tag objects
	 */
	public function fetchAllAdmin()
	{
		global $conf;

		$tags = array();

		$sql = "SELECT t.rowid, t.label, t.color, t.description, t.position, t.active, t.date_creation,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."whatsapp_conversation_tags ct WHERE ct.fk_tag = t.rowid) as usage_count";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity = ".$conf->entity;
		$sql .= " ORDER BY t.position ASC, t.label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$tags[] = $obj;
			}
			$this->db->free($resql);
		}
		return $tags;
	}

	// ==========================================
	// Conversation ↔ Tag Association Methods
	// ==========================================

	/**
	 * Add a tag to a conversation
	 *
	 * @param  int  $conversationId Conversation ID
	 * @param  int  $tagId          Tag ID
	 * @param  User $user           User performing the action
	 * @return int                  1 if OK, -1 if KO (already exists returns 1)
	 */
	public function addTagToConversation($conversationId, $tagId, User $user)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_conversation_tags";
		$sql .= " (fk_conversation, fk_tag, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conversationId).", ".((int) $tagId).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		// Duplicate key = already tagged, not an error
		if ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			return 1;
		}
		$this->errors[] = "Error ".$this->db->lasterror();
		return -1;
	}

	/**
	 * Remove a tag from a conversation
	 *
	 * @param  int $conversationId Conversation ID
	 * @param  int $tagId          Tag ID
	 * @return int                 1 if OK, -1 if KO
	 */
	public function removeTagFromConversation($conversationId, $tagId)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."whatsapp_conversation_tags";
		$sql .= " WHERE fk_conversation = ".((int) $conversationId);
		$sql .= " AND fk_tag = ".((int) $tagId);

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		$this->errors[] = "Error ".$this->db->lasterror();
		return -1;
	}

	/**
	 * Get all tags assigned to a conversation
	 *
	 * @param  int   $conversationId Conversation ID
	 * @return array Array of tag objects with id, label, color
	 */
	public function getConversationTags($conversationId)
	{
		$tags = array();

		$sql = "SELECT t.rowid, t.label, t.color";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_tags t";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."whatsapp_conversation_tags ct ON ct.fk_tag = t.rowid";
		$sql .= " WHERE ct.fk_conversation = ".((int) $conversationId);
		$sql .= " AND t.active = 1";
		$sql .= " ORDER BY t.position ASC, t.label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$tags[] = $obj;
			}
			$this->db->free($resql);
		}
		return $tags;
	}

	/**
	 * Get tags for multiple conversations at once (efficient batch query)
	 *
	 * @param  array $conversationIds Array of conversation IDs
	 * @return array Associative array: [conv_id => [tag objects]]
	 */
	public function getTagsForConversations($conversationIds)
	{
		$result = array();
		if (empty($conversationIds)) {
			return $result;
		}

		$ids = array_map('intval', $conversationIds);
		$sql = "SELECT ct.fk_conversation, t.rowid, t.label, t.color";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_tags t";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."whatsapp_conversation_tags ct ON ct.fk_tag = t.rowid";
		$sql .= " WHERE ct.fk_conversation IN (".implode(',', $ids).")";
		$sql .= " AND t.active = 1";
		$sql .= " ORDER BY t.position ASC, t.label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$convId = $obj->fk_conversation;
				if (!isset($result[$convId])) {
					$result[$convId] = array();
				}
				$result[$convId][] = (object) array(
					'rowid' => $obj->rowid,
					'label' => $obj->label,
					'color' => $obj->color
				);
			}
			$this->db->free($resql);
		}
		return $result;
	}

	/**
	 * Get conversations filtered by tag
	 *
	 * @param  int   $tagId Tag ID
	 * @return array Array of conversation IDs
	 */
	public function getConversationsByTag($tagId)
	{
		$ids = array();

		$sql = "SELECT fk_conversation FROM ".MAIN_DB_PREFIX."whatsapp_conversation_tags";
		$sql .= " WHERE fk_tag = ".((int) $tagId);

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->fk_conversation;
			}
			$this->db->free($resql);
		}
		return $ids;
	}
}
