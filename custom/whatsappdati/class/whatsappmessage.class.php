<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappmessage.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Message class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Message
 */
class WhatsAppMessage extends CommonObject
{
	public $element = 'whatsappmessage';
	public $table_element = 'whatsapp_messages';
	public $ismultientitymanaged = 1;
	public $picto = 'whatsappdati@whatsappdati';

	public $message_id;
	public $fk_conversation;
	public $fk_line;
	public $direction;
	public $message_type;
	public $content;
	public $template_name;
	public $template_params;
	public $media_url;
	public $media_mime_type;
	public $media_filename;
	public $media_local_path;
	public $status;
	public $error_message;
	public $fk_user_sender;
	public $timestamp;
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

	/**
	 * Create message in database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		global $conf;

		$error = 0;

		// Insert request
		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity,";
		$sql .= "fk_line,";
		$sql .= "message_id,";
		$sql .= "fk_conversation,";
		$sql .= "direction,";
		$sql .= "message_type,";
		$sql .= "content,";
		$sql .= "template_name,";
		$sql .= "template_params,";
		$sql .= "media_url,";
		$sql .= "media_mime_type,";
		$sql .= "media_filename,";
		$sql .= "media_local_path,";
		$sql .= "status,";
		$sql .= "error_message,";
		$sql .= "fk_user_sender,";
		$sql .= "timestamp,";
		$sql .= "date_creation";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " ".($this->message_id ? "'".$this->db->escape($this->message_id)."'" : "NULL").",";
		$sql .= " ".((int) $this->fk_conversation).",";
		$sql .= " '".$this->db->escape($this->direction)."',";
		$sql .= " '".$this->db->escape($this->message_type ? $this->message_type : 'text')."',";
		$sql .= " ".($this->content ? "'".$this->db->escape($this->content)."'" : "NULL").",";
		$sql .= " ".($this->template_name ? "'".$this->db->escape($this->template_name)."'" : "NULL").",";
		$sql .= " ".($this->template_params ? "'".$this->db->escape($this->template_params)."'" : "NULL").",";
		$sql .= " ".($this->media_url ? "'".$this->db->escape($this->media_url)."'" : "NULL").",";
		$sql .= " ".($this->media_mime_type ? "'".$this->db->escape($this->media_mime_type)."'" : "NULL").",";
		$sql .= " ".($this->media_filename ? "'".$this->db->escape($this->media_filename)."'" : "NULL").",";
		$sql .= " ".($this->media_local_path ? "'".$this->db->escape($this->media_local_path)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->status ? $this->status : 'pending')."',";
		$sql .= " ".($this->error_message ? "'".$this->db->escape($this->error_message)."'" : "NULL").",";
		$sql .= " ".($this->fk_user_sender > 0 ? (int) $this->fk_user_sender : "NULL").",";
		$sql .= " '".$this->db->idate($this->timestamp ? $this->timestamp : dol_now())."',";
		$sql .= " '".$this->db->idate(dol_now())."'";
		$sql .= ")";

		$this->db->begin();

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->errors[] = "Error ".$this->db->lasterror();
		}

		if (!$error) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
			
			// Update conversation last message
			$this->updateConversationLastMessage();
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
	 * Fetch message
	 *
	 * @param int $id Message ID
	 * @return int <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.message_id,";
		$sql .= " t.fk_conversation,";
		$sql .= " t.fk_line,";
		$sql .= " t.direction,";
		$sql .= " t.message_type,";
		$sql .= " t.content,";
		$sql .= " t.template_name,";
		$sql .= " t.template_params,";
		$sql .= " t.media_url,";
		$sql .= " t.media_mime_type,";
		$sql .= " t.media_filename,";
		$sql .= " t.media_local_path,";
		$sql .= " t.status,";
		$sql .= " t.error_message,";
		$sql .= " t.fk_user_sender,";
		$sql .= " t.timestamp,";
		$sql .= " t.date_creation";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);
		$sql .= " AND t.entity IN (".getEntity('whatsappmessage').")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = $obj->rowid;
				$this->message_id = $obj->message_id;
				$this->fk_conversation = $obj->fk_conversation;
				$this->fk_line = $obj->fk_line;
				$this->direction = $obj->direction;
				$this->message_type = $obj->message_type;
				$this->content = $obj->content;
				$this->template_name = $obj->template_name;
				$this->template_params = $obj->template_params;
				$this->media_url = $obj->media_url;
				$this->media_mime_type = $obj->media_mime_type;
				$this->media_filename = $obj->media_filename;
				$this->media_local_path = $obj->media_local_path;
				$this->status = $obj->status;
				$this->error_message = $obj->error_message;
				$this->fk_user_sender = $obj->fk_user_sender;
				$this->timestamp = $this->db->jdate($obj->timestamp);
				$this->date_creation = $this->db->jdate($obj->date_creation);
				return 1;
			}
			return 0;
		}
		return -1;
	}

	/**
	 * Get messages for a conversation
	 *
	 * @param  int    $conversation_id Conversation ID
	 * @param  int    $limit           Limit
	 * @param  int    $offset          Offset
	 * @return array                   Array of messages
	 */
	public function fetchByConversation($conversation_id, $limit = 100, $offset = 0, $order = 'ASC')
	{
		global $conf;
		$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.message_id,";
		$sql .= " t.fk_line,";
		$sql .= " t.direction,";
		$sql .= " t.message_type,";
		$sql .= " t.content,";
		$sql .= " t.template_name,";
		$sql .= " t.media_url,";
		$sql .= " t.media_mime_type,";
		$sql .= " t.media_filename,";
		$sql .= " t.media_local_path,";
		$sql .= " t.status,";
		$sql .= " t.fk_user_sender,";
		$sql .= " t.timestamp,";
		$sql .= " u.firstname,";
		$sql .= " u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = t.fk_user_sender";
		$sql .= " WHERE t.fk_conversation = ".((int) $conversation_id);
		$sql .= " AND t.entity = ".((int) $conf->entity);
		$sql .= " ORDER BY t.timestamp ".$order;
		$sql .= " LIMIT ".((int) $limit)." OFFSET ".((int) $offset);

		$resql = $this->db->query($sql);
		if ($resql) {
			$messages = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$messages[] = $obj;
			}
			return $messages;
		}
		return array();
	}

	/**
	 * Update message status
	 *
	 * @param  string $status New status
	 * @return int            <0 if KO, >0 if OK
	 */
	public function updateStatus($status)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = '".$this->db->escape($status)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->status = $status;
			return 1;
		}
		return -1;
	}

	/**
	 * Update conversation last message info
	 *
	 * @return void
	 */
	private function updateConversationLastMessage()
	{
		global $conf;
		// Build preview based on message type
		if (in_array($this->message_type, array('image', 'video', 'audio', 'document'))) {
			$typeLabels = array('image' => '📷 Imagen', 'video' => '🎬 Video', 'audio' => '🎵 Audio', 'document' => '📄 Documento');
			$preview = $typeLabels[$this->message_type];
			if (!empty($this->content) && !in_array($this->content, array('[Image]', '[Video]', '[Audio]', '[Document]'))) {
				$preview .= ': '.$this->content;
			}
		} else {
			$preview = $this->content;
		}
		if (strlen($preview) > 100) {
			$preview = substr($preview, 0, 97).'...';
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations SET";
		$sql .= " last_message_date = '".$this->db->idate($this->timestamp ? $this->timestamp : dol_now())."',";
		$sql .= " last_message_preview = '".$this->db->escape($preview)."'";
		
		// If inbound message, increment unread count and update 24h window
		if ($this->direction === 'inbound') {
			$sql .= ", unread_count = unread_count + 1";
			$sql .= ", window_expires_at = '".$this->db->idate(dol_now() + 86400)."'";
		}
		
		$sql .= " WHERE rowid = ".((int) $this->fk_conversation);
		$sql .= " AND entity = ".((int) $conf->entity);

		$this->db->query($sql);
	}
}
