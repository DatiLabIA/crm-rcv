<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsappqueue.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Queue class for bulk/scheduled messaging
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Queue
 */
class WhatsAppQueue extends CommonObject
{
	public $element = 'whatsappqueue';
	public $table_element = 'whatsapp_queue';
	public $ismultientitymanaged = 1;

	public $batch_id;
	public $fk_line;
	public $phone_number;
	public $contact_name;
	public $fk_soc;
	public $fk_template;
	public $template_name;
	public $template_params;
	public $message_content;
	public $scheduled_date;
	public $status;
	public $retry_count;
	public $max_retries;
	public $error_message;
	public $message_id_wa;
	public $date_sent;
	public $date_creation;
	public $fk_user_creat;

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
	 * Create queue entry
	 *
	 * @param  User $user      User that creates
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $noTransaction = false)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
		$sql .= "entity, fk_line, batch_id, phone_number, contact_name, fk_soc, fk_template,";
		$sql .= "template_name, template_params, message_content, scheduled_date,";
		$sql .= "status, retry_count, max_retries, fk_user_creat, date_creation";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " ".($this->batch_id ? "'".$this->db->escape($this->batch_id)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->phone_number)."',";
		$sql .= " ".($this->contact_name ? "'".$this->db->escape($this->contact_name)."'" : "NULL").",";
		$sql .= " ".($this->fk_soc > 0 ? (int) $this->fk_soc : "NULL").",";
		$sql .= " ".($this->fk_template > 0 ? (int) $this->fk_template : "NULL").",";
		$sql .= " ".($this->template_name ? "'".$this->db->escape($this->template_name)."'" : "NULL").",";
		$sql .= " ".($this->template_params ? "'".$this->db->escape($this->template_params)."'" : "NULL").",";
		$sql .= " ".($this->message_content ? "'".$this->db->escape($this->message_content)."'" : "NULL").",";
		$sql .= " '".$this->db->idate($this->scheduled_date ? $this->scheduled_date : dol_now())."',";
		$sql .= " '".$this->db->escape($this->status ? $this->status : 'pending')."',";
		$sql .= " ".((int) ($this->retry_count ? $this->retry_count : 0)).",";
		$sql .= " ".((int) ($this->max_retries ? $this->max_retries : 3)).",";
		$sql .= " ".((int) $user->id).",";
		$sql .= " '".$this->db->idate(dol_now())."'";
		$sql .= ")";

		if (!$noTransaction) {
			$this->db->begin();
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			if (!$noTransaction) {
				$this->db->rollback();
			}
			return -1;
		}

		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		if (!$noTransaction) {
			$this->db->commit();
		}
		return $this->id;
	}

	/**
	 * Fetch queue entry
	 *
	 * @param  int $id Row ID
	 * @return int     <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT t.* FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);
		$sql .= " AND t.entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = $obj->rowid;
				$this->batch_id = $obj->batch_id;
				$this->fk_line = $obj->fk_line;
				$this->phone_number = $obj->phone_number;
				$this->contact_name = $obj->contact_name;
				$this->fk_soc = $obj->fk_soc;
				$this->fk_template = $obj->fk_template;
				$this->template_name = $obj->template_name;
				$this->template_params = $obj->template_params;
				$this->message_content = $obj->message_content;
				$this->scheduled_date = $this->db->jdate($obj->scheduled_date);
				$this->status = $obj->status;
				$this->retry_count = $obj->retry_count;
				$this->max_retries = $obj->max_retries;
				$this->error_message = $obj->error_message;
				$this->message_id_wa = $obj->message_id_wa;
				$this->date_sent = $this->db->jdate($obj->date_sent);
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->fk_user_creat = $obj->fk_user_creat;
				return 1;
			}
			return 0;
		}
		return -1;
	}

	/**
	 * Create a bulk send batch
	 *
	 * @param  User   $user         User creating the batch
	 * @param  int    $templateId   Template ID
	 * @param  string $templateName Template name
	 * @param  array  $recipients   Array of ['phone' => ..., 'name' => ..., 'fk_soc' => ..., 'params' => [...]]
	 * @param  array  $defaultParams Default template params (used if recipient has no specific params)
	 * @param  int    $scheduledDate Unix timestamp for scheduled send (0 = now)
	 * @param  int    $lineId        Line ID for sending (0 = default)
	 * @return array  ['batch_id' => string, 'total' => int, 'created' => int]
	 */
	public function createBulkBatch(User $user, $templateId, $templateName, $recipients, $defaultParams = array(), $scheduledDate = 0, $lineId = 0)
	{
		$batchId = 'bulk_'.dol_now().'_'.mt_rand(1000, 9999);
		$created = 0;

		if (empty($scheduledDate)) {
			$scheduledDate = dol_now();
		}

		$this->db->begin();

		foreach ($recipients as $recipient) {
			$queue = new WhatsAppQueue($this->db);
			$queue->batch_id = $batchId;
			$queue->fk_line = $lineId;
			$queue->phone_number = $recipient['phone'];
			$queue->contact_name = $recipient['name'] ?? '';
			$queue->fk_soc = $recipient['fk_soc'] ?? 0;
			$queue->fk_template = $templateId;
			$queue->template_name = $templateName;
			$queue->scheduled_date = $scheduledDate;
			$queue->status = 'pending';

			// Use recipient-specific params or defaults
			$params = !empty($recipient['params']) ? $recipient['params'] : $defaultParams;
			$queue->template_params = !empty($params) ? json_encode($params) : null;

			$result = $queue->create($user, true);
			if ($result > 0) {
				$created++;
			}
		}

		$this->db->commit();

		return array(
			'batch_id' => $batchId,
			'total' => count($recipients),
			'created' => $created
		);
	}

	/**
	 * Get pending queue items ready to process
	 *
	 * @param  int   $limit     Max items to fetch
	 * @param  string $batchId  Optional: filter by batch
	 * @return array            Array of queue objects
	 */
	public function fetchPending($limit = 50, $batchId = '')
	{
		global $conf;

		// Use transaction + FOR UPDATE to prevent duplicate processing by concurrent cron jobs
		$this->db->begin();

		$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity = ".$conf->entity;
		$sql .= " AND t.status = 'pending'";
		$sql .= " AND t.scheduled_date <= '".$this->db->idate(dol_now())."'";
		if (!empty($batchId)) {
			$sql .= " AND t.batch_id = '".$this->db->escape($batchId)."'";
		}
		$sql .= " ORDER BY t.scheduled_date ASC, t.rowid ASC";
		$sql .= " LIMIT ".((int) $limit);
		$sql .= " FOR UPDATE";

		$items = array();
		$ids = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
		}

		// Atomically mark as processing to prevent re-fetch
		if (!empty($ids)) {
			$sqlUp = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
			$sqlUp .= " SET status = 'processing'";
			$sqlUp .= " WHERE rowid IN (".implode(',', $ids).")";
			$this->db->query($sqlUp);
		}

		$this->db->commit();

		// Now fetch the full objects
		foreach ($ids as $id) {
			$item = new WhatsAppQueue($this->db);
			$item->fetch($id);
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Process a single queue item (send the message)
	 *
	 * @param  User $user System user for creating records
	 * @return array       ['success' => bool, 'error' => string]
	 */
	public function process(User $user)
	{
		global $conf;

		require_once dol_buildpath('/whatsappdati/class/whatsappmanager.class.php', 0);
		require_once dol_buildpath('/whatsappdati/class/whatsappconversation.class.php', 0);
		require_once dol_buildpath('/whatsappdati/class/whatsappmessage.class.php', 0);

		// Mark as processing
		$this->updateStatus('processing');

		$manager = new WhatsAppManager($this->db, $this->fk_line > 0 ? $this->fk_line : 0);

		// Decode params
		$params = array();
		if (!empty($this->template_params)) {
			$decoded = json_decode($this->template_params, true);
			if (is_array($decoded)) {
				$params = $decoded;
			}
		}

		// Send template message
		$result = $manager->sendTemplateMessage(
			$this->phone_number,
			$this->template_name,
			$params
		);

		if ($result['success']) {
			// Update queue
			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
			$sql .= " status = 'sent',";
			$sql .= " message_id_wa = '".$this->db->escape($result['message_id'])."',";
			$sql .= " date_sent = '".$this->db->idate(dol_now())."'";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND entity = ".((int) $conf->entity);
			$this->db->query($sql);

			// Create or get conversation
			$conversation = new WhatsAppConversation($this->db);
			$existing = $conversation->fetchByPhone($this->phone_number, $this->fk_line > 0 ? $this->fk_line : 0);
			if ($existing <= 0) {
				$conversation->phone_number = $this->phone_number;
				$conversation->contact_name = $this->contact_name;
				$conversation->fk_soc = $this->fk_soc;
				$conversation->fk_line = $this->fk_line;
				$conversation->status = 'active';
				$conversation->create($user);
			}

			// Save message record
			$message = new WhatsAppMessage($this->db);
			$message->message_id = $result['message_id'];
			$message->fk_conversation = $conversation->id;
			$message->fk_line = $this->fk_line;
			$message->direction = 'outbound';
			$message->message_type = 'template';
			$message->template_name = $this->template_name;
			$message->template_params = $this->template_params;
			$message->status = 'sent';
			$message->fk_user_sender = $this->fk_user_creat;
			$message->timestamp = dol_now();

			// Build readable content
			if (!empty($this->message_content)) {
				$message->content = $this->message_content;
			} else {
				// Try to build from template body + params
				require_once dol_buildpath('/whatsappdati/class/whatsapptemplate.class.php', 0);
				$tpl = new WhatsAppTemplate($this->db);
				if ($this->fk_template > 0 && $tpl->fetch($this->fk_template) > 0) {
					$body = $tpl->body_text;
					foreach ($params as $i => $p) {
						$body = str_replace('{{'.($i + 1).'}}', $p, $body);
					}
					$message->content = $body;
				} else {
					$message->content = '[Template: '.$this->template_name.']';
				}
			}

			$message->create($user);

			return array('success' => true);
		} else {
			// Handle failure
			$retryCount = $this->retry_count + 1;
			$newStatus = ($retryCount >= $this->max_retries) ? 'failed' : 'pending';

			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
			$sql .= " status = '".$this->db->escape($newStatus)."',";
			$sql .= " retry_count = ".((int) $retryCount).",";
			$sql .= " error_message = '".$this->db->escape($result['error'])."'";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND entity = ".((int) $conf->entity);
			$this->db->query($sql);

			return array('success' => false, 'error' => $result['error']);
		}
	}

	/**
	 * Update status of this queue item
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

		if ($this->db->query($sql)) {
			$this->status = $status;
			return 1;
		}
		return -1;
	}

	/**
	 * Get batch statistics
	 *
	 * @param  string $batchId Batch ID
	 * @return array           Stats: total, pending, processing, sent, failed, cancelled
	 */
	public function getBatchStats($batchId)
	{
		global $conf;

		$stats = array(
			'total' => 0,
			'pending' => 0,
			'processing' => 0,
			'sent' => 0,
			'failed' => 0,
			'cancelled' => 0
		);

		$sql = "SELECT status, COUNT(*) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND batch_id = '".$this->db->escape($batchId)."'";
		$sql .= " GROUP BY status";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$stats[$obj->status] = (int) $obj->cnt;
				$stats['total'] += (int) $obj->cnt;
			}
		}

		return $stats;
	}

	/**
	 * Get all batches with summary info
	 *
	 * @param  int   $limit  Max batches to return
	 * @param  int   $offset Offset
	 * @return array         Array of batch summary objects
	 */
	public function fetchBatches($limit = 50, $offset = 0)
	{
		global $conf;

		$sql = "SELECT batch_id,";
		$sql .= " MIN(template_name) as template_name,";
		$sql .= " COUNT(*) as total,";
		$sql .= " SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,";
		$sql .= " SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,";
		$sql .= " SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,";
		$sql .= " SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,";
		$sql .= " MIN(scheduled_date) as scheduled_date,";
		$sql .= " MAX(date_sent) as last_sent,";
		$sql .= " MIN(date_creation) as date_creation,";
		$sql .= " MIN(fk_user_creat) as fk_user_creat";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND batch_id IS NOT NULL";
		$sql .= " AND batch_id != ''";
		$sql .= " GROUP BY batch_id";
		$sql .= " ORDER BY date_creation DESC";
		$sql .= " LIMIT ".((int) $limit)." OFFSET ".((int) $offset);

		$batches = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$batches[] = $obj;
			}
		}
		return $batches;
	}

	/**
	 * Cancel all pending items in a batch
	 *
	 * @param  string $batchId Batch ID
	 * @return int             Number of items cancelled
	 */
	public function cancelBatch($batchId)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = 'cancelled'";
		$sql .= " WHERE entity = ".$conf->entity;
		$sql .= " AND batch_id = '".$this->db->escape($batchId)."'";
		$sql .= " AND status = 'pending'";

		$this->db->query($sql);
		return $this->db->affected_rows();
	}

	/**
	 * Process a batch of pending queue items
	 * Includes rate limiting: 80 messages/second max for WhatsApp Business API (we limit to 10/second for safety)
	 *
	 * @param  int    $limit   Max messages to process in one run
	 * @param  string $batchId Optional: focus on specific batch
	 * @return array           ['processed' => int, 'sent' => int, 'failed' => int]
	 */
	public function processBatch($limit = 50, $batchId = '')
	{
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		// Use configurable admin user
		$systemUser = new User($this->db);
		$adminUserId = getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1);
		$systemUser->fetch($adminUserId);

		$pending = $this->fetchPending($limit, $batchId);
		$sent = 0;
		$failed = 0;

		foreach ($pending as $item) {
			$result = $item->process($systemUser);
			if ($result['success']) {
				$sent++;
			} else {
				$failed++;
			}

			// Configurable rate limiting between sends
			$rateLimitMs = getDolGlobalInt('WHATSAPPDATI_RATE_LIMIT_MS', 100);
			usleep($rateLimitMs * 1000);
		}

		return array(
			'processed' => count($pending),
			'sent' => $sent,
			'failed' => $failed
		);
	}
}
