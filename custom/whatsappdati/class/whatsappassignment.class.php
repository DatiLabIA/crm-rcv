<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappassignment.class.php
 * \ingroup    whatsappdati
 * \brief      Automatic conversation assignment logic
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * Class for WhatsApp conversation auto-assignment
 */
class WhatsAppAssignment
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	// Assignment modes
	const MODE_DISABLED = 'disabled';
	const MODE_ROUNDROBIN = 'roundrobin';
	const MODE_LEASTACTIVE = 'leastactive';
	const MODE_MANUAL = 'manual';

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
	 * Get the current assignment mode from config
	 *
	 * @return string  Assignment mode constant
	 */
	public function getMode()
	{
		global $conf;
		$mode = !empty($conf->global->WHATSAPPDATI_ASSIGN_MODE) ? $conf->global->WHATSAPPDATI_ASSIGN_MODE : self::MODE_DISABLED;
		return $mode;
	}

	/**
	 * Get list of agent user IDs configured for assignment
	 *
	 * @return array  Array of user IDs
	 */
	public function getAgentIds()
	{
		global $conf;
		$raw = !empty($conf->global->WHATSAPPDATI_ASSIGN_AGENTS) ? $conf->global->WHATSAPPDATI_ASSIGN_AGENTS : '';
		if (empty($raw)) {
			return array();
		}
		$ids = array_map('intval', array_filter(explode(',', $raw)));
		return $ids;
	}

	/**
	 * Get list of agent users with details
	 *
	 * @return array  Array of user objects with id, firstname, lastname, login
	 */
	public function getAgentUsers()
	{
		$ids = $this->getAgentIds();
		if (empty($ids)) {
			return array();
		}

		$users = array();
		$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname, u.email, u.statut";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.rowid IN (".implode(',', $ids).")";
		$sql .= " AND u.statut = 1"; // active users only
		$sql .= " ORDER BY u.lastname, u.firstname";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$users[] = $obj;
			}
		}
		return $users;
	}

	/**
	 * Auto-assign a conversation based on the configured mode
	 *
	 * @param  int  $conversationId  Conversation ID to assign
	 * @return int                   User ID assigned (0 if none)
	 */
	public function autoAssign($conversationId)
	{
		$mode = $this->getMode();

		if ($mode === self::MODE_DISABLED || $mode === self::MODE_MANUAL) {
			return 0;
		}

		$agents = $this->getAgentIds();
		if (empty($agents)) {
			return 0;
		}

		$assignedUserId = 0;

		switch ($mode) {
			case self::MODE_ROUNDROBIN:
				$assignedUserId = $this->assignRoundRobin($agents);
				break;
			case self::MODE_LEASTACTIVE:
				$assignedUserId = $this->assignLeastActive($agents);
				break;
		}

		if ($assignedUserId > 0) {
			$this->setConversationAgent($conversationId, $assignedUserId);
		}

		return $assignedUserId;
	}

	/**
	 * Auto-assign a conversation using provided agents and mode (per-line)
	 *
	 * @param  int    $conversationId  Conversation ID to assign
	 * @param  array  $agents          Array of user IDs (line agents)
	 * @param  string $mode            Assignment mode: 'roundrobin' or 'leastactive'
	 * @return int                     User ID assigned (0 if none)
	 */
	public function autoAssignFromAgents($conversationId, $agents, $mode)
	{
		$agents = array_map('intval', array_filter($agents));
		if (empty($agents)) {
			return 0;
		}

		$assignedUserId = 0;

		switch ($mode) {
			case self::MODE_ROUNDROBIN:
				$assignedUserId = $this->assignRoundRobin($agents);
				break;
			case self::MODE_LEASTACTIVE:
				$assignedUserId = $this->assignLeastActive($agents);
				break;
		}

		if ($assignedUserId > 0) {
			$this->setConversationAgent($conversationId, $assignedUserId);
		}

		return $assignedUserId;
	}

	/**
	 * Round-robin assignment: pick the agent who was assigned least recently
	 *
	 * @param  array $agents  Array of user IDs
	 * @return int             User ID to assign
	 */
	private function assignRoundRobin($agents)
	{
		global $conf;

		// Get the last assigned agent across all conversations
		$sql = "SELECT fk_user_assigned, MAX(date_modification) as last_assigned";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_conversations";
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND fk_user_assigned IN (".implode(',', array_map('intval', $agents)).")";
		$sql .= " GROUP BY fk_user_assigned";
		$sql .= " ORDER BY last_assigned DESC";

		$assignedAgents = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$assignedAgents[] = (int) $obj->fk_user_assigned;
			}
		}

		// Find agents who have never been assigned — they go first
		foreach ($agents as $agentId) {
			if (!in_array($agentId, $assignedAgents)) {
				return $agentId;
			}
		}

		// All agents have been assigned at least once — pick the one assigned longest ago (last in the DESC list)
		if (!empty($assignedAgents)) {
			return end($assignedAgents);
		}

		// Fallback: first agent
		return $agents[0];
	}

	/**
	 * Least-active assignment: pick the agent with fewest active conversations
	 *
	 * @param  array $agents  Array of user IDs
	 * @return int             User ID to assign
	 */
	private function assignLeastActive($agents)
	{
		global $conf;

		$sql = "SELECT u.id as user_id, COALESCE(c.cnt, 0) as active_count";
		$sql .= " FROM (";
		// Build inline table of agent IDs
		$parts = array();
		foreach ($agents as $agentId) {
			$parts[] = "SELECT ".((int) $agentId)." as id";
		}
		$sql .= implode(" UNION ALL ", $parts);
		$sql .= ") as u";
		$sql .= " LEFT JOIN (";
		$sql .= "   SELECT fk_user_assigned, COUNT(*) as cnt";
		$sql .= "   FROM ".MAIN_DB_PREFIX."whatsapp_conversations";
		$sql .= "   WHERE entity = ".$conf->entity;
		$sql .= "   AND status = 'active'";
		$sql .= "   AND fk_user_assigned IS NOT NULL";
		$sql .= "   GROUP BY fk_user_assigned";
		$sql .= " ) as c ON u.id = c.fk_user_assigned";
		$sql .= " ORDER BY active_count ASC, u.id ASC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return (int) $obj->user_id;
			}
		}

		return $agents[0];
	}

	/**
	 * Set the primary assigned agent for a conversation
	 * Also ensures the agent is in the multi-agent table
	 *
	 * @param  int $conversationId  Conversation row ID
	 * @param  int $userId          User ID (0 to unassign)
	 * @return int                  1 on success, -1 on failure
	 */
	public function setConversationAgent($conversationId, $userId)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations";
		$sql .= " SET fk_user_assigned = ".($userId > 0 ? (int) $userId : "NULL").",";
		$sql .= " date_modification = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $conversationId);
		$sql .= " AND entity = ".((int) $conf->entity);

		if ($this->db->query($sql)) {
			// Also add to multi-agent table if not already there
			if ($userId > 0) {
				$this->addConversationAgent($conversationId, $userId);
			}
			return 1;
		}
		return -1;
	}

	/**
	 * Add an agent to a conversation (multi-agent table)
	 *
	 * @param  int    $conversationId  Conversation row ID
	 * @param  int    $userId          User ID to add
	 * @param  string $role            Role: 'agent' or 'observer'
	 * @return int                     1 on success, 0 if already exists, -1 on failure
	 */
	public function addConversationAgent($conversationId, $userId, $role = 'agent')
	{
		global $user;

		// Check if already assigned
		$sqlCheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents";
		$sqlCheck .= " WHERE fk_conversation = ".((int) $conversationId);
		$sqlCheck .= " AND fk_user = ".((int) $userId);
		$resCheck = $this->db->query($sqlCheck);
		if ($resCheck && $this->db->num_rows($resCheck) > 0) {
			return 0; // Already exists
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_conversation_agents";
		$sql .= " (fk_conversation, fk_user, role, date_creation, fk_user_creat)";
		$sql .= " VALUES (";
		$sql .= ((int) $conversationId).",";
		$sql .= ((int) $userId).",";
		$sql .= "'".$this->db->escape($role == 'observer' ? 'observer' : 'agent')."',";
		$sql .= "'".$this->db->idate(dol_now())."',";
		$sql .= ((int) ($user->id ?? 0));
		$sql .= ")";

		if ($this->db->query($sql)) {
			return 1;
		}
		return -1;
	}

	/**
	 * Remove an agent from a conversation (multi-agent table)
	 *
	 * @param  int $conversationId  Conversation row ID
	 * @param  int $userId          User ID to remove
	 * @return int                  1 on success, -1 on failure
	 */
	public function removeConversationAgent($conversationId, $userId)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents";
		$sql .= " WHERE fk_conversation = ".((int) $conversationId);
		$sql .= " AND fk_user = ".((int) $userId);

		if ($this->db->query($sql)) {
			return 1;
		}
		return -1;
	}

	/**
	 * Get all agents assigned to a conversation
	 *
	 * @param  int   $conversationId  Conversation row ID
	 * @return array  Array of objects with user_id, login, firstname, lastname, role
	 */
	public function getConversationAgents($conversationId)
	{
		$agents = array();

		$sql = "SELECT ca.fk_user as user_id, ca.role, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents ca";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = ca.fk_user";
		$sql .= " WHERE ca.fk_conversation = ".((int) $conversationId);
		$sql .= " AND u.statut = 1";
		$sql .= " ORDER BY ca.date_creation ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$agents[] = $obj;
			}
		}
		return $agents;
	}

	/**
	 * Get agents for multiple conversations at once (batch query)
	 *
	 * @param  array $conversationIds  Array of conversation IDs
	 * @return array  Keyed by conversation ID, each value is array of agent objects
	 */
	public function getConversationAgentsBatch($conversationIds)
	{
		$result = array();
		if (empty($conversationIds)) {
			return $result;
		}

		$ids = array_map('intval', $conversationIds);
		$sql = "SELECT ca.fk_conversation, ca.fk_user as user_id, ca.role, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents ca";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = ca.fk_user";
		$sql .= " WHERE ca.fk_conversation IN (".implode(',', $ids).")";
		$sql .= " AND u.statut = 1";
		$sql .= " ORDER BY ca.date_creation ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$convId = (int) $obj->fk_conversation;
				if (!isset($result[$convId])) {
					$result[$convId] = array();
				}
				$result[$convId][] = $obj;
			}
		}
		return $result;
	}

	/**
	 * Set multiple agents for a conversation (replaces all current agents)
	 *
	 * @param  int   $conversationId  Conversation row ID
	 * @param  array $userIds         Array of user IDs
	 * @param  int   $primaryUserId   The primary responder (set as fk_user_assigned), 0 = first in list
	 * @return int                    1 on success, -1 on failure
	 */
	public function setConversationAgents($conversationId, $userIds, $primaryUserId = 0)
	{
		global $conf;

		$userIds = array_map('intval', array_filter($userIds));

		// Remove all current agents
		$sqlDel = "DELETE FROM ".MAIN_DB_PREFIX."whatsapp_conversation_agents";
		$sqlDel .= " WHERE fk_conversation = ".((int) $conversationId);
		$this->db->query($sqlDel);

		// Add new agents
		foreach ($userIds as $uid) {
			$this->addConversationAgent($conversationId, $uid);
		}

		// Set primary responder
		if ($primaryUserId <= 0 && !empty($userIds)) {
			$primaryUserId = $userIds[0];
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations";
		$sql .= " SET fk_user_assigned = ".(!empty($userIds) ? (int) $primaryUserId : "NULL").",";
		$sql .= " date_modification = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $conversationId);
		$sql .= " AND entity = ".((int) $conf->entity);

		if ($this->db->query($sql)) {
			return 1;
		}
		return -1;
	}

	/**
	 * Get all internal users that could be agents
	 * (active users with whatsappdati conversation read permission)
	 *
	 * @return array  Array of user objects
	 */
	public function getAvailableUsers()
	{
		$sql = "SELECT DISTINCT u.rowid, u.login, u.firstname, u.lastname, u.email";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.statut = 1";
		$sql .= " AND u.entity IN (".getEntity('user').")";
		$sql .= " ORDER BY u.lastname, u.firstname";

		$users = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$users[] = $obj;
			}
		}
		return $users;
	}

	/**
	 * Get assignment statistics per agent
	 *
	 * @return array  Array of ['user_id' => ..., 'name' => ..., 'active' => ..., 'total' => ...]
	 */
	public function getAgentStats()
	{
		global $conf;

		$agents = $this->getAgentIds();
		if (empty($agents)) {
			return array();
		}

		$stats = array();

		$sql = "SELECT u.rowid as user_id,";
		$sql .= " CONCAT(u.firstname, ' ', u.lastname) as agent_name,";
		$sql .= " COALESCE(SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END), 0) as active_convs,";
		$sql .= " COUNT(c.rowid) as total_convs";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."whatsapp_conversations as c";
		$sql .= "   ON c.fk_user_assigned = u.rowid AND c.entity = ".$conf->entity;
		$sql .= " WHERE u.rowid IN (".implode(',', array_map('intval', $agents)).")";
		$sql .= " GROUP BY u.rowid, u.firstname, u.lastname";
		$sql .= " ORDER BY u.lastname, u.firstname";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$stats[] = array(
					'user_id' => (int) $obj->user_id,
					'name' => trim($obj->agent_name),
					'active' => (int) $obj->active_convs,
					'total' => (int) $obj->total_convs
				);
			}
		}

		return $stats;
	}
}
