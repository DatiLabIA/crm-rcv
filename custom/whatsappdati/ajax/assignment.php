<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/assignment.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint for conversation assignment management
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once dol_buildpath('/whatsappdati/class/whatsappassignment.class.php', 0);
require_once dol_buildpath('/whatsappdati/class/whatsappconversation.class.php', 0);
require_once dol_buildpath('/whatsappdati/class/whatsappcsat.class.php', 0);
require_once dol_buildpath('/whatsappdati/lib/whatsappdati_ajax.lib.php', 0);

header('Content-Type: application/json; charset=UTF-8');

// Force utf8mb4 so emoji content in system messages is saved correctly
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}

// Access control
if (!$user->rights->whatsappdati->conversation->read) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

$action = GETPOST('action', 'aZ09');

// CSRF validation for mutation actions
if (in_array($action, array('assign', 'unassign', 'transfer', 'close_conversation', 'send_csat', 'multi_assign', 'add_agent', 'remove_agent', 'claim'))) {
	whatsappdatiCheckCSRFToken();
}
$assignment = new WhatsAppAssignment($db);

switch ($action) {
	// --------------------------------------------------
	// CLAIM: agent claims an unassigned conversation
	// --------------------------------------------------
	case 'claim':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation ID required'));
			break;
		}

		// Verify the conversation is still unassigned
		$convCheck = new WhatsAppConversation($db);
		if ($convCheck->fetch($conversationId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
			break;
		}
		if (!empty($convCheck->fk_user_assigned)) {
			// Already claimed by someone
			$claimerUser = new User($db);
			$claimerUser->fetch($convCheck->fk_user_assigned);
			$claimerName = trim($claimerUser->firstname.' '.$claimerUser->lastname);
			if (empty($claimerName)) $claimerName = $claimerUser->login;
			echo json_encode(array('success' => false, 'error' => 'Ya tomada por '.$claimerName));
			break;
		}

		// Claim: set this user as assigned and replace conversation agents
		$result = $assignment->setConversationAgents($conversationId, array($user->id), $user->id);
		if ($result > 0) {
			$agentName = trim($user->firstname.' '.$user->lastname);
			if (empty($agentName)) $agentName = $user->login;
			echo json_encode(array(
				'success' => true,
				'agent_id' => (int) $user->id,
				'agent_name' => $agentName
			));
		} else {
			echo json_encode(array('success' => false, 'error' => 'Claim failed'));
		}
		break;

	// --------------------------------------------------
	// ASSIGN: manually assign a conversation to an agent
	// --------------------------------------------------
	case 'assign':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$agentId = GETPOST('agent_id', 'int');

		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation ID required'));
			exit;
		}

		$result = $assignment->setConversationAgent($conversationId, $agentId);

		if ($result > 0) {
			// Get agent name for response
			$agentName = '';
			if ($agentId > 0) {
				$agentUser = new User($db);
				if ($agentUser->fetch($agentId) > 0) {
					$agentName = trim($agentUser->firstname.' '.$agentUser->lastname);
					if (empty($agentName)) {
						$agentName = $agentUser->login;
					}
				}
			}
			// Return all assigned agents
			$allAgents = $assignment->getConversationAgents($conversationId);
			$agentsList = array();
			foreach ($allAgents as $a) {
				$name = trim($a->firstname.' '.$a->lastname);
				$agentsList[] = array('id' => (int) $a->user_id, 'name' => !empty($name) ? $name : $a->login, 'role' => $a->role);
			}
			echo json_encode(array(
				'success' => true,
				'agent_id' => $agentId,
				'agent_name' => $agentName,
				'assigned_agents' => $agentsList
			));
		} else {
			echo json_encode(array('success' => false, 'error' => 'Assignment failed'));
		}
		break;

	// --------------------------------------------------
	// MULTI_ASSIGN: set multiple agents for a conversation
	// --------------------------------------------------
	case 'multi_assign':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$agentIdsRaw = GETPOST('agent_ids', 'alpha');

		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation ID required'));
			break;
		}

		$agentIds = array_map('intval', array_filter(explode(',', $agentIdsRaw)));

		$result = $assignment->setConversationAgents($conversationId, $agentIds);

		if ($result > 0) {
			$allAgents = $assignment->getConversationAgents($conversationId);
			$agentsList = array();
			foreach ($allAgents as $a) {
				$name = trim($a->firstname.' '.$a->lastname);
				$agentsList[] = array('id' => (int) $a->user_id, 'name' => !empty($name) ? $name : $a->login, 'role' => $a->role);
			}
			echo json_encode(array(
				'success' => true,
				'assigned_agents' => $agentsList
			));
		} else {
			echo json_encode(array('success' => false, 'error' => 'Multi-assignment failed'));
		}
		break;

	// --------------------------------------------------
	// ADD_AGENT: add a single agent to a conversation
	// --------------------------------------------------
	case 'add_agent':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$agentId = GETPOST('agent_id', 'int');

		if ($conversationId <= 0 || $agentId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id and agent_id required'));
			break;
		}

		$result = $assignment->addConversationAgent($conversationId, $agentId);

		// Also set as primary if none is set
		$sqlCheck = "SELECT fk_user_assigned FROM ".MAIN_DB_PREFIX."whatsapp_conversations WHERE rowid = ".((int) $conversationId);
		$resCheck = $db->query($sqlCheck);
		if ($resCheck) {
			$obj = $db->fetch_object($resCheck);
			if (empty($obj->fk_user_assigned)) {
				$assignment->setConversationAgent($conversationId, $agentId);
			}
		}

		$allAgents = $assignment->getConversationAgents($conversationId);
		$agentsList = array();
		foreach ($allAgents as $a) {
			$name = trim($a->firstname.' '.$a->lastname);
			$agentsList[] = array('id' => (int) $a->user_id, 'name' => !empty($name) ? $name : $a->login, 'role' => $a->role);
		}
		echo json_encode(array(
			'success' => true,
			'assigned_agents' => $agentsList
		));
		break;

	// --------------------------------------------------
	// REMOVE_AGENT: remove a single agent from a conversation
	// --------------------------------------------------
	case 'remove_agent':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$agentId = GETPOST('agent_id', 'int');

		if ($conversationId <= 0 || $agentId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id and agent_id required'));
			break;
		}

		$assignment->removeConversationAgent($conversationId, $agentId);

		// If removed agent was primary, reassign primary to first remaining agent
		$sqlPrimary = "SELECT fk_user_assigned FROM ".MAIN_DB_PREFIX."whatsapp_conversations WHERE rowid = ".((int) $conversationId);
		$resPrimary = $db->query($sqlPrimary);
		if ($resPrimary) {
			$objP = $db->fetch_object($resPrimary);
			if ((int) $objP->fk_user_assigned === $agentId) {
				$remaining = $assignment->getConversationAgents($conversationId);
				$newPrimary = !empty($remaining) ? (int) $remaining[0]->user_id : 0;
				$sqlUp = "UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations SET fk_user_assigned = ".($newPrimary > 0 ? $newPrimary : "NULL");
				$sqlUp .= " WHERE rowid = ".((int) $conversationId);
				$db->query($sqlUp);
			}
		}

		$allAgents = $assignment->getConversationAgents($conversationId);
		$agentsList = array();
		foreach ($allAgents as $a) {
			$name = trim($a->firstname.' '.$a->lastname);
			$agentsList[] = array('id' => (int) $a->user_id, 'name' => !empty($name) ? $name : $a->login, 'role' => $a->role);
		}
		echo json_encode(array(
			'success' => true,
			'assigned_agents' => $agentsList
		));
		break;

	// --------------------------------------------------
	// CONVERSATION_AGENTS: get agents assigned to a conversation
	// --------------------------------------------------
	case 'conversation_agents':
		$conversationId = GETPOST('conversation_id', 'int');
		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id required'));
			break;
		}

		$allAgents = $assignment->getConversationAgents($conversationId);
		$agentsList = array();
		foreach ($allAgents as $a) {
			$name = trim($a->firstname.' '.$a->lastname);
			$agentsList[] = array('id' => (int) $a->user_id, 'name' => !empty($name) ? $name : $a->login, 'role' => $a->role);
		}
		echo json_encode(array(
			'success' => true,
			'assigned_agents' => $agentsList
		));
		break;

	// --------------------------------------------------
	// AGENTS: get list of available agents for assignment
	// --------------------------------------------------
	case 'agents':
		$users = $assignment->getAvailableUsers();
		$agents = array();

		foreach ($users as $u) {
			$name = trim($u->firstname.' '.$u->lastname);
			if (empty($name)) {
				$name = $u->login;
			}
			$agents[] = array(
				'id' => (int) $u->rowid,
				'name' => $name,
				'login' => $u->login
			);
		}

		echo json_encode(array('success' => true, 'agents' => $agents));
		break;

	// --------------------------------------------------
	// STATS: get assignment statistics per agent
	// --------------------------------------------------
	case 'stats':
		$stats = $assignment->getAgentStats();
		echo json_encode(array('success' => true, 'stats' => $stats));
		break;

	// --------------------------------------------------
	// TRANSFER: transfer a conversation to another agent
	// with a context note and audit trail
	// --------------------------------------------------
	case 'transfer':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$toAgentId = GETPOST('to_agent_id', 'int');
		$transferNote = GETPOST('transfer_note', 'restricthtml');

		if ($conversationId <= 0 || $toAgentId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id and to_agent_id required'));
			break;
		}

		// Fetch conversation to get current agent
		$conv = new WhatsAppConversation($db);
		if ($conv->fetch($conversationId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
			break;
		}

		$fromUserId = $conv->fk_user_assigned ? (int) $conv->fk_user_assigned : null;

		// Transfer: replace all current agents with the new one only
		$result = $assignment->setConversationAgents($conversationId, array($toAgentId), $toAgentId);
		if ($result < 0) {
			echo json_encode(array('success' => false, 'error' => 'Transfer failed'));
			break;
		}

		// Log the transfer
		$sqlTransfer = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_transfers (entity, fk_conversation, from_user_id, to_user_id, note, date_transfer) VALUES (";
		$sqlTransfer .= (int) $conf->entity.",";
		$sqlTransfer .= " ".(int) $conversationId.",";
		$sqlTransfer .= " ".($fromUserId ? (int) $fromUserId : "NULL").",";
		$sqlTransfer .= " ".(int) $toAgentId.",";
		$sqlTransfer .= " ".(!empty($transferNote) ? "'".$db->escape($transferNote)."'" : "NULL").",";
		$sqlTransfer .= " '".$db->idate(dol_now())."'";
		$sqlTransfer .= ")";
		$db->query($sqlTransfer);

		// Create a system message in the conversation so agents see the transfer
		require_once dol_buildpath('/whatsappdati/class/whatsappmessage.class.php', 0);
		$toAgentUser = new User($db);
		$toAgentUser->fetch($toAgentId);
		$toAgentName = trim($toAgentUser->firstname.' '.$toAgentUser->lastname) ?: $toAgentUser->login;

		$fromAgentName = '';
		if ($fromUserId) {
			$fromAgentUser = new User($db);
			$fromAgentUser->fetch($fromUserId);
			$fromAgentName = trim($fromAgentUser->firstname.' '.$fromAgentUser->lastname) ?: $fromAgentUser->login;
		}

		$sysContent = '🔄 Conversación transferida';
		if ($fromAgentName) {
			$sysContent .= ' de '.$fromAgentName;
		}
		$sysContent .= ' a '.$toAgentName;
		if (!empty($transferNote)) {
			$sysContent .= "\nNota: ".$transferNote;
		}

		// Insert system message directly (avoids class transaction wrapper masking errors)
		$sqlSysMsg  = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_messages";
		$sqlSysMsg .= " (entity, fk_line, message_id, fk_conversation, direction, message_type, content, status, fk_user_sender, timestamp, date_creation)";
		$sqlSysMsg .= " VALUES (";
		$sqlSysMsg .= (int) $conf->entity.",";
		$sqlSysMsg .= ($conv->fk_line > 0 ? (int) $conv->fk_line : "NULL").",";
		$sqlSysMsg .= "'".$db->escape('sys_transfer_'.dol_now().'_'.$conversationId)."',";
		$sqlSysMsg .= (int) $conversationId.",";
		$sqlSysMsg .= "'system',";
		$sqlSysMsg .= "'text',";
		$sqlSysMsg .= "'".$db->escape($sysContent)."',";
		$sqlSysMsg .= "'delivered',";
		$sqlSysMsg .= (int) $user->id.",";
		$sqlSysMsg .= "'".$db->idate(dol_now())."',";
		$sqlSysMsg .= "'".$db->idate(dol_now())."'";
		$sqlSysMsg .= ")";
		$db->query($sqlSysMsg);

		// Update conversation last message preview
		$db->query("UPDATE ".MAIN_DB_PREFIX."whatsapp_conversations SET last_message_date = '".$db->idate(dol_now())."', last_message_preview = '".$db->escape(mb_substr($sysContent, 0, 100))."' WHERE rowid = ".(int) $conversationId);

		// Emit SSE events — new_message so agents see the system note,
		// and conversation_update(assignment) so agent B's UI highlights the new assignment
		require_once dol_buildpath('/whatsappdati/class/whatsappevent.class.php', 0);
		$eventEmitter = new WhatsAppEvent($db);
		$eventEmitter->emitNewMessage(
			$conversationId, 'system', 'text', $sysContent,
			$conv->phone_number, $conv->contact_name, $conv->fk_line
		);
		$eventEmitter->emitConversationUpdate($conversationId, 'assignment');

		echo json_encode(array(
			'success'        => true,
			'to_agent_id'    => $toAgentId,
			'to_agent_name'  => $toAgentName,
			'from_agent_id'  => $fromUserId,
			'from_agent_name'=> $fromAgentName,
			'transfer_note'  => $transferNote
		));
		break;

	// --------------------------------------------------
	// TRANSFER_LOG: get transfer history for a conversation
	// --------------------------------------------------
	case 'transfer_log':
		$conversationId = GETPOST('conversation_id', 'int');
		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id required'));
			break;
		}

		$sqlLog = "SELECT t.rowid, t.from_user_id, t.to_user_id, t.note, t.date_transfer,";
		$sqlLog .= " CONCAT(uf.firstname, ' ', uf.lastname) as from_name,";
		$sqlLog .= " CONCAT(ut.firstname, ' ', ut.lastname) as to_name";
		$sqlLog .= " FROM ".MAIN_DB_PREFIX."whatsapp_transfers as t";
		$sqlLog .= " LEFT JOIN ".MAIN_DB_PREFIX."user as uf ON t.from_user_id = uf.rowid";
		$sqlLog .= " LEFT JOIN ".MAIN_DB_PREFIX."user as ut ON t.to_user_id = ut.rowid";
		$sqlLog .= " WHERE t.fk_conversation = ".(int) $conversationId;
		$sqlLog .= " AND t.entity = ".(int) $conf->entity;
		$sqlLog .= " ORDER BY t.date_transfer DESC LIMIT 20";

		$transfers = array();
		$resql = $db->query($sqlLog);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$transfers[] = array(
					'id'            => (int) $obj->rowid,
					'from_user_id'  => (int) $obj->from_user_id,
					'to_user_id'    => (int) $obj->to_user_id,
					'from_name'     => trim($obj->from_name) ?: '-',
					'to_name'       => trim($obj->to_name),
					'note'          => $obj->note,
					'date_transfer' => $obj->date_transfer
				);
			}
		}
		echo json_encode(array('success' => true, 'transfers' => $transfers));
		break;

	// --------------------------------------------------
	// CLOSE_CONVERSATION: close/archive a conversation
	// optionally sends CSAT survey before closing
	// --------------------------------------------------
	case 'close_conversation':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		$sendCSAT = GETPOST('send_csat', 'int');

		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id required'));
			break;
		}

		$conv = new WhatsAppConversation($db);
		if ($conv->fetch($conversationId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
			break;
		}

		$csatResult = null;

		// Send CSAT survey if requested and enabled
		if ($sendCSAT && WhatsAppCSAT::isEnabled()) {
			require_once dol_buildpath('/whatsappdati/class/whatsappmanager.class.php', 0);
			$manager = new WhatsAppManager($db, $conv->fk_line > 0 ? $conv->fk_line : 0);
			$csatObj = new WhatsAppCSAT($db);
			$csatResult = $csatObj->sendSurvey($conversationId, $manager);
		}

		// Close the conversation
		$conv->status = 'closed';
		$conv->update($user);

		// System message
		require_once dol_buildpath('/whatsappdati/class/whatsappmessage.class.php', 0);
		$agentName = trim($user->firstname.' '.$user->lastname) ?: $user->login;

		$sysMsg = new WhatsAppMessage($db);
		$sysMsg->message_id = 'sys_close_'.dol_now().'_'.$conversationId;
		$sysMsg->fk_conversation = $conversationId;
		$sysMsg->fk_line = $conv->fk_line;
		$sysMsg->direction = 'system';
		$sysMsg->message_type = 'text';
		$sysMsg->content = '🔒 Conversación cerrada por '.$agentName;
		$sysMsg->status = 'delivered';
		$sysMsg->fk_user_sender = $user->id;
		$sysMsg->timestamp = dol_now();
		$sysMsg->create($user);

		echo json_encode(array(
			'success'     => true,
			'csat_sent'   => ($csatResult && $csatResult['success']),
			'csat_result' => $csatResult
		));
		break;

	// --------------------------------------------------
	// SEND_CSAT: manually send a CSAT survey
	// --------------------------------------------------
	case 'send_csat':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			echo json_encode(array('success' => false, 'error' => 'POST method required'));
			break;
		}
		$conversationId = GETPOST('conversation_id', 'int');
		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id required'));
			break;
		}

		if (!WhatsAppCSAT::isEnabled()) {
			echo json_encode(array('success' => false, 'error' => 'CSAT surveys are disabled'));
			break;
		}

		$conv = new WhatsAppConversation($db);
		if ($conv->fetch($conversationId) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Conversation not found'));
			break;
		}

		require_once dol_buildpath('/whatsappdati/class/whatsappmanager.class.php', 0);
		$manager = new WhatsAppManager($db, $conv->fk_line > 0 ? $conv->fk_line : 0);
		$csatObj = new WhatsAppCSAT($db);
		$result = $csatObj->sendSurvey($conversationId, $manager);

		echo json_encode($result);
		break;

	// --------------------------------------------------
	// CSAT_STATS: get CSAT statistics
	// --------------------------------------------------
	case 'csat_stats':
		$agentId = GETPOST('agent_id', 'int');
		$from = GETPOST('from', 'alpha');
		$to = GETPOST('to', 'alpha');

		$csatObj = new WhatsAppCSAT($db);
		$stats = $csatObj->getStats($agentId, $from, $to);
		echo json_encode(array('success' => true, 'stats' => $stats));
		break;

	// --------------------------------------------------
	// CSAT_INFO: get CSAT info for a specific conversation
	// --------------------------------------------------
	case 'csat_info':
		$conversationId = GETPOST('conversation_id', 'int');
		if ($conversationId <= 0) {
			echo json_encode(array('success' => false, 'error' => 'conversation_id required'));
			break;
		}

		$csatObj = new WhatsAppCSAT($db);
		$found = $csatObj->fetchByConversation($conversationId);
		if ($found > 0) {
			echo json_encode(array(
				'success' => true,
				'csat'    => array(
					'id'           => $csatObj->id,
					'rating'       => $csatObj->rating,
					'feedback'     => $csatObj->feedback_text,
					'status'       => $csatObj->status,
					'sent_at'      => $csatObj->sent_at,
					'responded_at' => $csatObj->responded_at
				)
			));
		} else {
			echo json_encode(array('success' => true, 'csat' => null));
		}
		break;

	default:
		echo json_encode(array('success' => false, 'error' => 'Unknown action'));
		break;
}

$db->close();
