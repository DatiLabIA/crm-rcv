<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappquickreply.class.php
 * \ingroup    whatsappdati
 * \brief      CRUD class for quick replies (predefined agent responses)
 */

class WhatsAppQuickReply
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Error messages array */
	public $errors = array();

	// Fields
	public $id;
	public $entity;
	public $shortcut;
	public $title;
	public $content;
	public $category;
	public $position;
	public $active;
	public $date_creation;
	public $tms;
	public $fk_user_creat;

	/** @var string Table name */
	const TABLE = 'whatsapp_quick_replies';

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
	 * Create a quick reply
	 *
	 * @param User $user User creating the record
	 * @return int >0 if OK, <0 if KO
	 */
	public function create($user)
	{
		global $conf;

		$this->shortcut = trim($this->shortcut);
		$this->title = trim($this->title);
		$this->content = trim($this->content);
		$this->category = trim($this->category);

		// Ensure shortcut starts with /
		if (substr($this->shortcut, 0, 1) !== '/') {
			$this->shortcut = '/' . $this->shortcut;
		}

		// Validate required fields
		if (empty($this->shortcut) || $this->shortcut === '/') {
			$this->error = 'ErrorShortcutRequired';
			return -1;
		}
		if (empty($this->title)) {
			$this->error = 'ErrorTitleRequired';
			return -1;
		}
		if (empty($this->content)) {
			$this->error = 'ErrorContentRequired';
			return -1;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE . " (";
		$sql .= "entity, shortcut, title, content, category, position, active, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", '" . $this->db->escape($this->shortcut) . "'";
		$sql .= ", '" . $this->db->escape($this->title) . "'";
		$sql .= ", '" . $this->db->escape($this->content) . "'";
		$sql .= ", " . ($this->category ? "'" . $this->db->escape($this->category) . "'" : "NULL");
		$sql .= ", " . ((int) ($this->position ?? 0));
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
	 * Fetch a quick reply by ID
	 *
	 * @param int $id Row ID
	 * @return int >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT rowid, entity, shortcut, title, content, category, position, active, date_creation, tms, fk_user_creat";
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE rowid = " . ((int) $id);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->id = $obj->rowid;
				$this->entity = $obj->entity;
				$this->shortcut = $obj->shortcut;
				$this->title = $obj->title;
				$this->content = $obj->content;
				$this->category = $obj->category;
				$this->position = $obj->position;
				$this->active = $obj->active;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->tms = $obj->tms;
				$this->fk_user_creat = $obj->fk_user_creat;
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Fetch all quick replies for current entity
	 *
	 * @param string $filter   Optional filter: 'active' = only active, 'all' = all
	 * @param string $category Optional category filter
	 * @param string $search   Optional search text (matches shortcut, title, content)
	 * @return array|int       Array of objects on success, <0 on error
	 */
	public function fetchAll($filter = 'active', $category = '', $search = '')
	{
		global $conf;

		$sql = "SELECT rowid, entity, shortcut, title, content, category, position, active, date_creation, tms, fk_user_creat";
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);

		if ($filter === 'active') {
			$sql .= " AND active = 1";
		}
		if (!empty($category)) {
			$sql .= " AND category = '" . $this->db->escape($category) . "'";
		}
		if (!empty($search)) {
			$search = $this->db->escape($search);
			$sql .= " AND (shortcut LIKE '%" . $search . "%' OR title LIKE '%" . $search . "%' OR content LIKE '%" . $search . "%')";
		}

		$sql .= " ORDER BY category ASC, position ASC, title ASC";

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
	 * Fetch a quick reply by its shortcut
	 *
	 * @param string $shortcut The shortcut text (e.g. "/gracias")
	 * @return int >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetchByShortcut($shortcut)
	{
		global $conf;

		$sql = "SELECT rowid, entity, shortcut, title, content, category, position, active, date_creation, tms, fk_user_creat";
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND shortcut = '" . $this->db->escape($shortcut) . "'";
		$sql .= " AND active = 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($obj = $this->db->fetch_object($resql)) {
				$this->id = $obj->rowid;
				$this->entity = $obj->entity;
				$this->shortcut = $obj->shortcut;
				$this->title = $obj->title;
				$this->content = $obj->content;
				$this->category = $obj->category;
				$this->position = $obj->position;
				$this->active = $obj->active;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->tms = $obj->tms;
				$this->fk_user_creat = $obj->fk_user_creat;
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Update a quick reply
	 *
	 * @param User $user User making the update
	 * @return int >0 if OK, <0 if KO
	 */
	public function update($user)
	{
		global $conf;

		$this->shortcut = trim($this->shortcut);
		$this->title = trim($this->title);
		$this->content = trim($this->content);
		$this->category = trim($this->category);

		// Ensure shortcut starts with /
		if (substr($this->shortcut, 0, 1) !== '/') {
			$this->shortcut = '/' . $this->shortcut;
		}

		if (empty($this->shortcut) || $this->shortcut === '/') {
			$this->error = 'ErrorShortcutRequired';
			return -1;
		}
		if (empty($this->title)) {
			$this->error = 'ErrorTitleRequired';
			return -1;
		}
		if (empty($this->content)) {
			$this->error = 'ErrorContentRequired';
			return -1;
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
		$sql .= " shortcut = '" . $this->db->escape($this->shortcut) . "'";
		$sql .= ", title = '" . $this->db->escape($this->title) . "'";
		$sql .= ", content = '" . $this->db->escape($this->content) . "'";
		$sql .= ", category = " . ($this->category ? "'" . $this->db->escape($this->category) . "'" : "NULL");
		$sql .= ", position = " . ((int) ($this->position ?? 0));
		$sql .= ", active = " . ((int) $this->active);
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
	 * Delete a quick reply
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

	/**
	 * Get distinct categories for current entity
	 *
	 * @return array|int Array of category strings on success, <0 on error
	 */
	public function getCategories()
	{
		global $conf;

		$sql = "SELECT DISTINCT category FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND category IS NOT NULL AND category != ''";
		$sql .= " AND active = 1";
		$sql .= " ORDER BY category ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			$categories = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$categories[] = $obj->category;
			}
			return $categories;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}
}
