<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappconfig.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Configuration class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Configuration
 */
class WhatsAppConfig extends CommonObject
{
	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'whatsappconfig';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'whatsapp_config';

	/**
	 * @var int  Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var string String with name of icon for whatsappconfig
	 */
	public $picto = 'whatsappdati@whatsappdati';

	public $label;
	public $app_id;
	public $phone_number_id;
	public $business_account_id;
	public $access_token;
	public $webhook_verify_token;
	public $app_secret;
	public $webhook_url;
	public $fk_user_default_agent;
	public $country_code;
	public $assign_mode;
	public $status;
	public $date_creation;
	public $date_modification;
	public $fk_user_creat;
	public $fk_user_modif;

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
	 * Create object into database
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
		$this->label = trim($this->label ?? '');
		$this->app_id = trim($this->app_id ?? '');
		$this->phone_number_id = trim($this->phone_number_id ?? '');
		$this->business_account_id = trim($this->business_account_id ?? '');
		$this->access_token = trim($this->access_token ?? '');
		$this->webhook_verify_token = trim($this->webhook_verify_token ?? '');
		$this->app_secret = trim($this->app_secret ?? '');
		$this->country_code = trim($this->country_code ?? '57');

		// Check parameters
		if (empty($this->phone_number_id)) {
			$this->errors[] = 'ErrorPhoneNumberIDRequired';
			return -1;
		}

		// Insert request
		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity,";
		$sql .= "label,";
		$sql .= "app_id,";
		$sql .= "phone_number_id,";
		$sql .= "business_account_id,";
		$sql .= "access_token,";
		$sql .= "webhook_verify_token,";
		$sql .= "app_secret,";
		$sql .= "webhook_url,";
		$sql .= "fk_user_default_agent,";
		$sql .= "country_code,";
		$sql .= "assign_mode,";
		$sql .= "status,";
		$sql .= "date_creation,";
		$sql .= "fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " ".($this->label ? "'".$this->db->escape($this->label)."'" : "'Principal'").",";
		$sql .= " ".(!empty($this->app_id) ? "'".$this->db->escape($this->app_id)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->phone_number_id)."',";
		$sql .= " '".$this->db->escape($this->business_account_id)."',";
		$sql .= " '".$this->db->escape($this->access_token)."',";
		$sql .= " '".$this->db->escape($this->webhook_verify_token)."',";
		$sql .= " ".(!empty($this->app_secret) ? "'".$this->db->escape($this->app_secret)."'" : "NULL").",";
		$sql .= " ".($this->webhook_url ? "'".$this->db->escape($this->webhook_url)."'" : "NULL").",";
		$sql .= " ".($this->fk_user_default_agent > 0 ? (int) $this->fk_user_default_agent : "NULL").",";
		$sql .= " '".$this->db->escape($this->country_code)."',";
		$sql .= " '".$this->db->escape(!empty($this->assign_mode) ? $this->assign_mode : 'manual')."',";
		$sql .= " ".($this->status ? (int) $this->status : 1).",";
		$sql .= " '".$this->db->idate(dol_now())."',";
		$sql .= " ".((int) $user->id);
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
		}

		// Commit or rollback
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
		$sql .= " t.label,";
		$sql .= " t.app_id,";
		$sql .= " t.phone_number_id,";
		$sql .= " t.business_account_id,";
		$sql .= " t.access_token,";
		$sql .= " t.webhook_verify_token,";
		$sql .= " t.app_secret,";
		$sql .= " t.webhook_url,";
		$sql .= " t.fk_user_default_agent,";
		$sql .= " t.country_code,";
		$sql .= " t.assign_mode,";
		$sql .= " t.status,";
		$sql .= " t.date_creation,";
		$sql .= " t.date_modification,";
		$sql .= " t.fk_user_creat,";
		$sql .= " t.fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);
		$sql .= " AND t.entity IN (".getEntity('whatsappconfig').")";

		dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			$numrows = $this->db->num_rows($resql);
			if ($numrows) {
				$obj = $this->db->fetch_object($resql);

				$this->id = $obj->rowid;
				$this->rowid = $obj->rowid;
				$this->entity = $obj->entity;
				$this->label = $obj->label;
				$this->app_id = $obj->app_id;
				$this->phone_number_id = $obj->phone_number_id;
				$this->business_account_id = $obj->business_account_id;
				$this->access_token = $obj->access_token;
				$this->webhook_verify_token = $obj->webhook_verify_token;
				$this->app_secret = $obj->app_secret;
				$this->webhook_url = $obj->webhook_url;
				$this->fk_user_default_agent = $obj->fk_user_default_agent;
				$this->country_code = $obj->country_code;
				$this->assign_mode = $obj->assign_mode;
				$this->status = $obj->status;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->date_modification = $this->db->jdate($obj->date_modification);
				$this->fk_user_creat = $obj->fk_user_creat;
				$this->fk_user_modif = $obj->fk_user_modif;
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
	 * Fetch active configuration for current entity
	 *
	 * @return int <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchActive()
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND status = 1";
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
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		global $conf;

		$error = 0;

		// Clean parameters
		$this->label = trim($this->label ?? '');
		$this->app_id = trim($this->app_id ?? '');
		$this->phone_number_id = trim($this->phone_number_id ?? '');
		$this->business_account_id = trim($this->business_account_id ?? '');
		$this->access_token = trim($this->access_token ?? '');
		$this->webhook_verify_token = trim($this->webhook_verify_token ?? '');
		$this->app_secret = trim($this->app_secret ?? '');
		$this->country_code = trim($this->country_code ?? '57');

		// Update request
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " label = ".($this->label ? "'".$this->db->escape($this->label)."'" : "NULL").",";
		$sql .= " app_id = ".(!empty($this->app_id) ? "'".$this->db->escape($this->app_id)."'" : "NULL").",";
		$sql .= " phone_number_id = '".$this->db->escape($this->phone_number_id)."',";
		$sql .= " business_account_id = '".$this->db->escape($this->business_account_id)."',";
		$sql .= " access_token = '".$this->db->escape($this->access_token)."',";
		$sql .= " webhook_verify_token = '".$this->db->escape($this->webhook_verify_token)."',";
		$sql .= " app_secret = ".(!empty($this->app_secret) ? "'".$this->db->escape($this->app_secret)."'" : "NULL").",";
		$sql .= " webhook_url = ".($this->webhook_url ? "'".$this->db->escape($this->webhook_url)."'" : "NULL").",";
		$sql .= " fk_user_default_agent = ".($this->fk_user_default_agent > 0 ? (int) $this->fk_user_default_agent : "NULL").",";
		$sql .= " country_code = '".$this->db->escape($this->country_code)."',";
		$sql .= " assign_mode = '".$this->db->escape(!empty($this->assign_mode) ? $this->assign_mode : 'manual')."',";
		$sql .= " status = ".((int) $this->status).",";
		$sql .= " date_modification = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$this->db->begin();

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->errors[] = "Error ".$this->db->lasterror();
		}

		// Commit or rollback
		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return 1;
		}
	}

	/**
	 * Fetch all active lines for current entity
	 *
	 * @return array Array of WhatsAppConfig objects (lines)
	 */
	public function fetchAllLines()
	{
		global $conf;

		$lines = array();
		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity = ".$conf->entity;
		$sql .= " ORDER BY t.label ASC, t.rowid ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$line = new WhatsAppConfig($this->db);
				$line->fetch($obj->rowid);
				$lines[] = $line;
			}
			$this->db->free($resql);
		}
		return $lines;
	}

	/**
	 * Fetch active lines only (status = 1)
	 *
	 * @return array Array of WhatsAppConfig objects
	 */
	public function fetchActiveLines()
	{
		global $conf;

		$lines = array();
		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity = ".$conf->entity;
		$sql .= " AND t.status = 1";
		$sql .= " ORDER BY t.label ASC, t.rowid ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$line = new WhatsAppConfig($this->db);
				$line->fetch($obj->rowid);
				$lines[] = $line;
			}
			$this->db->free($resql);
		}
		return $lines;
	}

	/**
	 * Fetch config by phone_number_id (used by webhook to identify line)
	 *
	 * @param  string $phoneNumberId Meta Phone Number ID
	 * @return int                   <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchByPhoneNumberId($phoneNumberId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE phone_number_id = '".$this->db->escape($phoneNumberId)."'";
		$sql .= " AND status = 1";
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
	 * Delete a line
	 *
	 * @param  User $user User performing delete
	 * @return int        <0 if KO, >0 if OK
	 */
	public function delete(User $user)
	{
		global $conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Get all agents assigned to a line
	 *
	 * @param  int   $lineId  Line ID
	 * @return array          Array of user IDs
	 */
	public function getLineAgents($lineId)
	{
		$agents = array();
		$sql = "SELECT fk_user FROM ".MAIN_DB_PREFIX."whatsapp_line_agents";
		$sql .= " WHERE fk_line = ".((int) $lineId);
		$sql .= " ORDER BY date_creation ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$agents[] = (int) $obj->fk_user;
			}
			$this->db->free($resql);
		}
		return $agents;
	}

	/**
	 * Set agents for a line (replaces existing)
	 *
	 * @param  int   $lineId   Line ID
	 * @param  array $userIds  Array of user IDs
	 * @param  User  $user     User performing the action
	 * @return int             Number of agents set, or -1 on error
	 */
	public function setLineAgents($lineId, $userIds, User $user)
	{
		$this->db->begin();

		// Remove existing agents
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."whatsapp_line_agents";
		$sql .= " WHERE fk_line = ".((int) $lineId);
		$this->db->query($sql);

		// Insert new agents
		$count = 0;
		foreach ($userIds as $uid) {
			$uid = (int) $uid;
			if ($uid <= 0) continue;

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_line_agents";
			$sql .= " (fk_line, fk_user, date_creation, fk_user_creat)";
			$sql .= " VALUES (".((int) $lineId).", ".$uid.", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";

			if ($this->db->query($sql)) {
				$count++;
			}
		}

		$this->db->commit();
		return $count;
	}
}
