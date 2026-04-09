<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/whatsapptemplate.class.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Template class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for WhatsApp Template
 */
class WhatsAppTemplate extends CommonObject
{
	public $element = 'whatsapptemplate';
	public $table_element = 'whatsapp_templates';
	public $ismultientitymanaged = 1;
	public $picto = 'whatsappdati@whatsappdati';

	public $template_id;
	public $fk_line;
	public $name;
	public $language;
	public $category;
	public $status;
	public $header_type;
	public $header_content;
	public $header_image_mode;
	public $header_media_url;
	public $header_media_local;
	public $body_text;
	public $footer_text;
	public $buttons;
	public $variables;
	public $variable_mapping;
	public $sync_date;
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
	 * Create template in database
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
		$sql .= "template_id,";
		$sql .= "name,";
		$sql .= "language,";
		$sql .= "category,";
		$sql .= "status,";
		$sql .= "header_type,";
		$sql .= "header_content,";
		$sql .= "header_image_mode,";
		$sql .= "header_media_url,";
		$sql .= "header_media_local,";
		$sql .= "body_text,";
		$sql .= "footer_text,";
		$sql .= "buttons,";
		$sql .= "variables,";
		$sql .= "variable_mapping,";
		$sql .= "sync_date,";
		$sql .= "date_creation,";
		$sql .= "fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= " ".$conf->entity.",";
		$sql .= " ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " ".($this->template_id ? "'".$this->db->escape($this->template_id)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->name)."',";
		$sql .= " '".$this->db->escape($this->language)."',";
		$sql .= " ".($this->category ? "'".$this->db->escape($this->category)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->status ? $this->status : 'pending')."',";
		$sql .= " ".($this->header_type ? "'".$this->db->escape($this->header_type)."'" : "NULL").",";
		$sql .= " ".($this->header_content ? "'".$this->db->escape($this->header_content)."'" : "NULL").",";
		$sql .= " ".($this->header_image_mode ? "'".$this->db->escape($this->header_image_mode)."'" : "'on_send'").",";
		$sql .= " ".($this->header_media_url ? "'".$this->db->escape($this->header_media_url)."'" : "NULL").",";
		$sql .= " ".($this->header_media_local ? "'".$this->db->escape($this->header_media_local)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($this->body_text)."',";
		$sql .= " ".($this->footer_text ? "'".$this->db->escape($this->footer_text)."'" : "NULL").",";
		$sql .= " ".($this->buttons ? "'".$this->db->escape($this->buttons)."'" : "NULL").",";
		$sql .= " ".($this->variables ? "'".$this->db->escape($this->variables)."'" : "NULL").",";
		$sql .= " ".($this->variable_mapping ? "'".$this->db->escape($this->variable_mapping)."'" : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."',";
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

		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		} else {
			$this->db->commit();
			return $this->id;
		}
	}

	/**
	 * Fetch template
	 *
	 * @param int $id Template ID
	 * @return int <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.template_id,";
		$sql .= " t.fk_line,";
		$sql .= " t.name,";
		$sql .= " t.language,";
		$sql .= " t.category,";
		$sql .= " t.status,";
		$sql .= " t.header_type,";
		$sql .= " t.header_content,";
		$sql .= " t.header_image_mode,";
		$sql .= " t.header_media_url,";
		$sql .= " t.header_media_local,";
		$sql .= " t.body_text,";
		$sql .= " t.footer_text,";
		$sql .= " t.buttons,";
		$sql .= " t.variables,";
		$sql .= " t.variable_mapping,";
		$sql .= " t.sync_date,";
		$sql .= " t.date_creation,";
		$sql .= " t.date_modification";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);
		$sql .= " AND t.entity IN (".getEntity('whatsapptemplate').")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = $obj->rowid;
				$this->template_id = $obj->template_id;
				$this->fk_line = $obj->fk_line;
				$this->name = $obj->name;
				$this->language = $obj->language;
				$this->category = $obj->category;
				$this->status = $obj->status;
				$this->header_type = $obj->header_type;
				$this->header_content = $obj->header_content;
				$this->header_image_mode = $obj->header_image_mode;
				$this->header_media_url = $obj->header_media_url;
				$this->header_media_local = $obj->header_media_local;
				$this->body_text = $obj->body_text;
				$this->footer_text = $obj->footer_text;
				$this->buttons = $obj->buttons;
				$this->variables = $obj->variables;
				$this->variable_mapping = $obj->variable_mapping;
				$this->sync_date = $this->db->jdate($obj->sync_date);
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->date_modification = $this->db->jdate($obj->date_modification);
				return 1;
			}
			return 0;
		}
		return -1;
	}

	/**
	 * Get all templates
	 *
	 * @param  string $status Filter by status (optional)
	 * @param  int    $lineId Filter by line (0 = all)
	 * @return array          Array of template objects
	 */
	public function fetchAll($status = '', $lineId = 0)
	{
		global $conf;

		$sql = "SELECT";
		$sql .= " t.rowid,";
		$sql .= " t.fk_line,";
		$sql .= " t.name,";
		$sql .= " t.language,";
		$sql .= " t.category,";
		$sql .= " t.status,";
		$sql .= " t.header_type,";
		$sql .= " t.header_content,";
		$sql .= " t.header_image_mode,";
		$sql .= " t.header_media_url,";
		$sql .= " t.body_text,";
		$sql .= " t.footer_text,";
		$sql .= " t.variables,";
		$sql .= " t.variable_mapping";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity = ".$conf->entity;
		
		if (!empty($status)) {
			$sql .= " AND t.status = '".$this->db->escape($status)."'";
		}
		if ($lineId > 0) {
			$sql .= " AND t.fk_line = ".((int) $lineId);
		}
		
		$sql .= " ORDER BY t.name ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			$templates = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$templates[] = $obj;
			}
			return $templates;
		}
		return array();
	}

	/**
	 * Sync templates from Meta WhatsApp API
	 *
	 * @param  int $lineId Line ID to sync (0 = first active line)
	 * @return int Number of templates synced, <0 if error
	 */
	public function syncFromMeta($lineId = 0)
	{
		global $conf;

		require_once dol_buildpath('/whatsappdati/class/whatsappconfig.class.php', 0);
		
		// Load config for specific line
		$config = new WhatsAppConfig($this->db);
		if ($lineId > 0) {
			if ($config->fetch($lineId) <= 0 || empty($config->status)) {
				$this->errors[] = 'No active WhatsApp configuration found for line '.$lineId;
				return -1;
			}
		} else {
			if ($config->fetchActive() <= 0) {
				$this->errors[] = 'No active WhatsApp configuration found';
				return -1;
			}
			$lineId = $config->id;
		}

		// Call Meta API to get templates
		$url = 'https://graph.facebook.com/v25.0/'.$config->business_account_id.'/message_templates';
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$config->access_token
		));
		
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($http_code != 200) {
			$this->errors[] = 'Error fetching templates from Meta API';
			return -1;
		}

		$data = json_decode($response, true);
		
		if (empty($data['data'])) {
			return 0;
		}

		// Pre-load local templates for slug-based matching (friendly name → Meta slug)
		$localMap = array(); // key = "slug|lang" => array(rowid, header_image_mode, header_type, template_id)
		$sqlLocal = "SELECT rowid, name, language, header_type, header_image_mode, template_id FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sqlLocal .= " WHERE entity = ".$conf->entity;
		$sqlLocal .= " AND fk_line = ".((int) $lineId);
		$resLocal = $this->db->query($sqlLocal);
		if ($resLocal) {
			while ($objLocal = $this->db->fetch_object($resLocal)) {
				$slugKey  = self::slugify($objLocal->name).'|'.$objLocal->language;
				$exactKey = $objLocal->name.'|'.$objLocal->language;
				$entry = array(
					'rowid'             => $objLocal->rowid,
					'header_type'       => $objLocal->header_type,
					'header_image_mode' => $objLocal->header_image_mode,
					'template_id'       => $objLocal->template_id,
				);
				if (!isset($localMap[$exactKey])) $localMap[$exactKey] = $entry;
				if (!isset($localMap[$slugKey]))  $localMap[$slugKey]  = $entry;
			}
		}

		$count = 0;
		foreach ($data['data'] as $tpl) {
			// Check if template already exists (exact name match OR slugified name match)
			$metaName = $tpl['name']; // Meta always returns the slug
			$metaLang = $tpl['language'];
			$lookupKey = $metaName.'|'.$metaLang;
			
			$exists = false;
			$existing_id = 0;
			$existing_header_type = null;
			$existing_header_image_mode = null;
			$existing_template_id = null;
			if (isset($localMap[$lookupKey])) {
				$exists = true;
				$existing_id = $localMap[$lookupKey]['rowid'];
				$existing_header_type = $localMap[$lookupKey]['header_type'];
				$existing_header_image_mode = $localMap[$lookupKey]['header_image_mode'];
				$existing_template_id = $localMap[$lookupKey]['template_id'];
			}

			// Extract template components
			$body_text = '';
			$header_type = null;
			$header_content = null;
			$footer_text = null;
			$buttons = null;
			$variables = array();

			$header_image_mode = null;
			$header_media_url = null;

			foreach ($tpl['components'] as $component) {
				if ($component['type'] === 'BODY') {
					$body_text = $component['text'];
					// Extract variables {{1}}, {{2}}, etc.
					preg_match_all('/\{\{(\d+)\}\}/', $body_text, $matches);
					if (!empty($matches[1])) {
						$variables = array_unique($matches[1]);
					}
				} elseif ($component['type'] === 'HEADER') {
					$header_type = $component['format'];
					if (isset($component['text'])) {
						$header_content = $component['text'];
					}
					// For media headers (IMAGE/VIDEO/DOCUMENT), capture the example
					// header_handle that Meta stores on the template.
					// - If it's a numeric/opaque ID: use it directly as media_id when sending.
					// - If it's a CDN URL (scontent.*): the image IS stored in Meta but the handle
					//   expires. Download it and re-upload to Meta to get a permanent media_id.
					//   Exception: if the existing local record was explicitly configured as 'on_send'
					//   by the user (template was created locally expecting image upload at send time),
					//   respect that choice and do NOT convert to on_template.
					if (in_array($component['format'], array('IMAGE', 'VIDEO', 'DOCUMENT'))) {
						if (!empty($component['example']['header_handle'][0])) {
							$handle = $component['example']['header_handle'][0];
							if (strpos($handle, 'http') !== 0) {
								// Proper numeric/opaque handle — reusable as media ID
								// Only set if not explicitly kept as on_send by the user
								if ($existing_header_image_mode !== 'on_send') {
									$header_media_url  = $handle;
									$header_image_mode = 'on_template';
								}
							} else {
								// CDN URL: only re-upload if the user hasn't explicitly set on_send
								if ($existing_header_image_mode !== 'on_send') {
									require_once dol_buildpath('/whatsappdati/class/whatsappmanager.class.php', 0);
									$manager = new WhatsAppManager($this->db, $lineId);
									$tmpFile = tempnam(sys_get_temp_dir(), 'wa_sync_');
									$ch2 = curl_init($handle);
									curl_setopt_array($ch2, array(
										CURLOPT_RETURNTRANSFER => true,
										CURLOPT_FOLLOWLOCATION => true,
										CURLOPT_TIMEOUT => 30,
										CURLOPT_SSL_VERIFYPEER => false,
									));
									$fileContent = curl_exec($ch2);
									$curlErr2 = curl_errno($ch2);
									curl_close($ch2);
									if (!$curlErr2 && $fileContent !== false && strlen($fileContent) > 0) {
										file_put_contents($tmpFile, $fileContent);
										$mimeType = mime_content_type($tmpFile) ?: 'image/jpeg';
										$uploadResult = $manager->uploadMedia($tmpFile, $mimeType);
										@unlink($tmpFile);
										if ($uploadResult['success']) {
											$header_media_url  = $uploadResult['media_id'];
											$header_image_mode = 'on_template';
										}
									} else {
										@unlink($tmpFile);
									}
								}
								// else: user keeps on_send — leave $header_image_mode = null (don't overwrite)
							}
						}
					}
				} elseif ($component['type'] === 'FOOTER') {
					$footer_text = $component['text'];
				} elseif ($component['type'] === 'BUTTONS') {
					$buttons = json_encode($component['buttons']);
				}
			}

			if ($exists) {
				// Update existing template
				$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
				$sql .= " template_id = '".$this->db->escape($tpl['id'])."',";
				$sql .= " category = '".$this->db->escape($tpl['category'])."',";
				$sql .= " status = '".$this->db->escape(strtolower($tpl['status']))."',";
				// Protect header_type: if local template is on_send and Meta has no header
				// (because it was pushed without a sample image), preserve the local header_type.
				// Otherwise Meta would destroy IMAGE/VIDEO/DOCUMENT config on every sync.
				$syncHeaderType = $header_type;
				if (empty($header_type) && $existing_header_image_mode === 'on_send' && !empty($existing_header_type)) {
					$syncHeaderType = $existing_header_type;
				}
				$sql .= " header_type = ".($syncHeaderType ? "'".$this->db->escape($syncHeaderType)."'" : "NULL").",";
				$sql .= " header_content = ".($header_content ? "'".$this->db->escape($header_content)."'" : "NULL").",";
				// Rules for header_image_mode during sync:
				// - If user explicitly set 'on_send' in Dolibarr → NEVER overwrite (respect user choice).
				// - If $header_image_mode is null (Meta has no handle, non-media header) → don't touch.
				// - Otherwise (on_template with fresh media_id) → update so media stays valid.
				$canUpdateMediaMode = ($header_image_mode !== null) && ($existing_header_image_mode !== 'on_send');
				if ($canUpdateMediaMode) {
					$sql .= " header_image_mode = '".$this->db->escape($header_image_mode)."',";
					$sql .= " header_media_url = ".($header_media_url ? "'".$this->db->escape($header_media_url)."'" : "NULL").",";
				}
				$sql .= " body_text = '".$this->db->escape($body_text)."',";
				$sql .= " footer_text = ".($footer_text ? "'".$this->db->escape($footer_text)."'" : "NULL").",";
				$sql .= " buttons = ".($buttons ? "'".$this->db->escape($buttons)."'" : "NULL").",";
				$sql .= " variables = '".$this->db->escape(json_encode($variables))."',";
				$sql .= " sync_date = '".$this->db->idate(dol_now())."',";
				$sql .= " date_modification = '".$this->db->idate(dol_now())."'";
				$sql .= " WHERE rowid = ".((int) $existing_id);
				$sql .= " AND entity = ".((int) $conf->entity);
				
				$this->db->query($sql);
			} else {
				// Create new template
				$template = new WhatsAppTemplate($this->db);
				$template->template_id = $tpl['id'];
				$template->fk_line = $lineId;
				$template->name = $tpl['name'];
				$template->language = $tpl['language'];
				$template->category = $tpl['category'];
				$template->status = strtolower($tpl['status']);
				$template->header_type = $header_type;
				$template->header_content = $header_content;
				$template->header_image_mode = $header_image_mode; // 'on_template' if handle captured, else null→default on_send
				$template->header_media_url  = $header_media_url;
				$template->body_text = $body_text;
				$template->footer_text = $footer_text;
				$template->buttons = $buttons;
				$template->variables = json_encode($variables);
				
				// Use a proper User object for the create method
				require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
				$syncUser = new User($this->db);
				$adminUserId = getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1);
				$syncUser->fetch($adminUserId);
				$template->create($syncUser);
			}
			
			$count++;
		}

		return $count;
	}

	/**
	 * Update template
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
		$sql .= " fk_line = ".($this->fk_line > 0 ? (int) $this->fk_line : "NULL").",";
		$sql .= " name = '".$this->db->escape($this->name)."',";
		$sql .= " language = '".$this->db->escape($this->language)."',";
		$sql .= " category = ".($this->category ? "'".$this->db->escape($this->category)."'" : "NULL").",";
		$sql .= " status = '".$this->db->escape($this->status)."',";
		$sql .= " header_type = ".($this->header_type ? "'".$this->db->escape($this->header_type)."'" : "NULL").",";
		$sql .= " header_content = ".($this->header_content ? "'".$this->db->escape($this->header_content)."'" : "NULL").",";
		$sql .= " header_image_mode = ".($this->header_image_mode ? "'".$this->db->escape($this->header_image_mode)."'" : "'on_send'").",";
		$sql .= " header_media_url = ".($this->header_media_url ? "'".$this->db->escape($this->header_media_url)."'" : "NULL").",";
		$sql .= " header_media_local = ".($this->header_media_local ? "'".$this->db->escape($this->header_media_local)."'" : "NULL").",";
		$sql .= " body_text = '".$this->db->escape($this->body_text)."',";
		$sql .= " footer_text = ".($this->footer_text ? "'".$this->db->escape($this->footer_text)."'" : "NULL").",";
		$sql .= " buttons = ".($this->buttons ? "'".$this->db->escape($this->buttons)."'" : "NULL").",";
		$sql .= " variables = ".($this->variables ? "'".$this->db->escape($this->variables)."'" : "NULL").",";
		$sql .= " variable_mapping = ".($this->variable_mapping ? "'".$this->db->escape($this->variable_mapping)."'" : "NULL").",";
		$sql .= " date_modification = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		return -1;
	}

	/**
	 * Delete template from database
	 *
	 * @param  User $user      User that deletes
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		global $conf;

		$sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql) {
			return 1;
		}
		$this->errors[] = "Error ".$this->db->lasterror();
		return -1;
	}

	/**
	 * Generate a Meta-compatible template name (slug) from a display name.
	 * Lowercase, underscores, no spaces, no special chars, max 512 chars.
	 *
	 * @param  string $name Human-readable name
	 * @return string       Meta-compatible slug
	 */
	public static function slugify($name)
	{
		// Transliterate accented characters
		if (function_exists('transliterator_transliterate')) {
			$slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
		} else {
			$slug = strtolower($name);
			$slug = strtr($slug, array(
				'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
				'ñ'=>'n','ü'=>'u','ä'=>'a','ö'=>'o','ê'=>'e',
				'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
				'â'=>'a','î'=>'i','ô'=>'o','û'=>'u','ç'=>'c'
			));
		}
		$slug = strtolower($slug);
		// Replace non-alphanumeric with underscores
		$slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
		// Trim underscores from edges
		$slug = trim($slug, '_');
		// Collapse multiple underscores
		$slug = preg_replace('/_+/', '_', $slug);
		// Max 512 chars
		if (strlen($slug) > 512) $slug = substr($slug, 0, 512);
		return $slug;
	}

	/**
	 * Get the Meta-compatible name (slug) for this template.
	 *
	 * @return string
	 */
	public function getMetaName()
	{
		return self::slugify($this->name);
	}

	/**
	 * Upload a media file to Meta using the Resumable Upload API.
	 * Returns a handle string ("h" value) that can be used as header_handle
	 * when creating templates.
	 *
	 * @param  string          $filePath    Absolute path to the local file
	 * @param  WhatsAppConfig  $config      Config object with access_token and app_id
	 * @return string|false                 Handle string on success, false on failure
	 */
	private function uploadMediaForTemplate($filePath, $config)
	{
		if (!file_exists($filePath)) {
			$this->errors[] = 'Header media file not found: '.$filePath;
			return false;
		}

		if (empty($config->app_id)) {
			$this->errors[] = 'App ID not configured — required for media upload';
			return false;
		}

		$fileSize = filesize($filePath);
		$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
		$fileName = basename($filePath);

		// Step 1: Create a resumable upload session
		$sessionUrl = 'https://graph.facebook.com/v21.0/'.$config->app_id.'/uploads';
		$sessionUrl .= '?file_length='.$fileSize;
		$sessionUrl .= '&file_type='.urlencode($mimeType);
		$sessionUrl .= '&file_name='.urlencode($fileName);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $sessionUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, '');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$config->access_token
		));

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$sessionData = json_decode($response, true);
		if ($http_code < 200 || $http_code >= 300 || empty($sessionData['id'])) {
			$err = isset($sessionData['error']['message']) ? $sessionData['error']['message'] : 'HTTP '.$http_code;
			dol_syslog('WhatsAppTemplate::uploadMediaForTemplate session error: '.$err.' — '.$response, LOG_ERR);
			$this->errors[] = 'Upload session error: '.$err;
			return false;
		}

		$uploadSessionId = $sessionData['id'];

		// Step 2: Upload the file binary
		$uploadUrl = 'https://graph.facebook.com/v21.0/'.$uploadSessionId;

		$fileContents = file_get_contents($filePath);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContents);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: OAuth '.$config->access_token,
			'file_offset: 0',
			'Content-Type: application/octet-stream'
		));

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$uploadData = json_decode($response, true);
		if ($http_code >= 200 && $http_code < 300 && !empty($uploadData['h'])) {
			dol_syslog('WhatsAppTemplate::uploadMediaForTemplate success, handle='.$uploadData['h'], LOG_DEBUG);
			return $uploadData['h'];
		}

		$err = isset($uploadData['error']['message']) ? $uploadData['error']['message'] : 'HTTP '.$http_code;
		dol_syslog('WhatsAppTemplate::uploadMediaForTemplate upload error: '.$err.' — '.$response, LOG_ERR);
		$this->errors[] = 'File upload error: '.$err;
		return false;
	}

	/**
	 * Push (create) a template to Meta WhatsApp Business API for review.
	 * The status returned by Meta is stored in the local record.
	 *
	 * @param  int $lineId  Line ID to use for API credentials (0 = use template's fk_line or first active)
	 * @return int          1 if OK, <0 if KO
	 */
	public function pushToMeta($lineId = 0)
	{
		global $conf;

		require_once dol_buildpath('/whatsappdati/class/whatsappconfig.class.php', 0);

		if ($lineId <= 0) $lineId = $this->fk_line;

		$config = new WhatsAppConfig($this->db);
		if ($lineId > 0) {
			if ($config->fetch($lineId) <= 0 || empty($config->status)) {
				$this->errors[] = 'No active WhatsApp configuration found for line '.$lineId;
				return -1;
			}
		} else {
			if ($config->fetchActive() <= 0) {
				$this->errors[] = 'No active WhatsApp configuration found';
				return -1;
			}
		}

		// Build components array for Meta API
		$components = array();

		// HEADER component
		if (!empty($this->header_type)) {
			$hFormat = strtoupper($this->header_type);
			if ($hFormat === 'TEXT') {
				$header = array('type' => 'HEADER', 'format' => 'TEXT');
				if (!empty($this->header_content)) {
					$header['text'] = $this->header_content;
					if (preg_match_all('/\{\{(\d+)\}\}/', $this->header_content, $hm)) {
						$header['example'] = array('header_text' => array_fill(0, count($hm[1]), 'Example'));
					}
				}
				$components[] = $header;
			} elseif (in_array($hFormat, array('IMAGE', 'VIDEO', 'DOCUMENT'))) {
				// For media headers, upload the sample file to Meta to get a header_handle.
				// This is required by Meta even for on_send templates (the sample is only for review).
				$mediaFile = $this->header_media_local;
				if (!empty($mediaFile) && file_exists($mediaFile)) {
					$handle = $this->uploadMediaForTemplate($mediaFile, $config);
					if ($handle !== false) {
						$components[] = array(
							'type'    => 'HEADER',
							'format'  => $hFormat,
							'example' => array('header_handle' => array($handle))
						);
					} else {
						// Upload failed — include header without example (Meta may reject, but worth trying)
						dol_syslog('WhatsAppTemplate::pushToMeta media upload failed, sending header without example', LOG_WARNING);
						$components[] = array('type' => 'HEADER', 'format' => $hFormat);
					}
				} else {
					// No sample file — Meta requires an example image to review the template.
					// Without it, Meta creates the template without a header component, causing
					// #132018 errors when we later try to send with an image parameter.
					$this->errors[] = 'Para plantillas con imagen en el header, debes subir una imagen de muestra antes de enviar a Meta. '.
						'Edita la plantilla, sube una imagen en el campo "Imagen de muestra" y guarda de nuevo.';
					return -4;
				}
			}
		}

		// BODY component (required)
		$body = array('type' => 'BODY', 'text' => $this->body_text);
		preg_match_all('/\{\{(\d+)\}\}/', $this->body_text, $bm);
		if (!empty($bm[1])) {
			$examples = array_fill(0, count(array_unique($bm[1])), 'Example');
			$body['example'] = array('body_text' => array($examples));
		}
		$components[] = $body;

		// FOOTER component
		if (!empty($this->footer_text)) {
			$components[] = array('type' => 'FOOTER', 'text' => $this->footer_text);
		}

		// BUTTONS component
		if (!empty($this->buttons)) {
			$btns = json_decode($this->buttons, true);
			if (is_array($btns) && !empty($btns)) {
				$metaButtons = array();
				foreach ($btns as $btn) {
					$mb = array('type' => strtoupper($btn['type']), 'text' => $btn['text'] ?: 'Button');
					if (strtoupper($btn['type']) === 'URL' && !empty($btn['url'])) {
						$mb['url'] = $btn['url'];
					}
					if (strtoupper($btn['type']) === 'PHONE_NUMBER' && !empty($btn['phone_number'])) {
						$mb['phone_number'] = $btn['phone_number'];
					}
					$metaButtons[] = $mb;
				}
				$components[] = array('type' => 'BUTTONS', 'buttons' => $metaButtons);
			}
		}

		// Build request payload
		$metaName = self::slugify($this->name);
		$payload = array(
			'name'       => $metaName,
			'language'   => $this->language,
			'category'   => strtoupper($this->category ?: 'MARKETING'),
			'components' => $components
		);

		$url = 'https://graph.facebook.com/v21.0/'.$config->business_account_id.'/message_templates';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$config->access_token,
			'Content-Type: application/json'
		));

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		dol_syslog('WhatsAppTemplate::pushToMeta response HTTP '.$http_code.' : '.$response, LOG_DEBUG);

		if ($curl_error) {
			$this->errors[] = 'cURL: '.$curl_error;
			return -2;
		}

		$data = json_decode($response, true);

		if ($http_code >= 200 && $http_code < 300 && !empty($data['id'])) {
			// Success — update local record with Meta's template_id and status
			$metaStatus = !empty($data['status']) ? strtolower($data['status']) : 'pending';
			$metaId     = $data['id'];

			$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
			$sql .= " template_id = '".$this->db->escape($metaId)."',";
			$sql .= " status = '".$this->db->escape($metaStatus)."',";
			$sql .= " sync_date = '".$this->db->idate(dol_now())."'";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND entity = ".((int) $conf->entity);
			$this->db->query($sql);

			$this->template_id = $metaId;
			$this->status = $metaStatus;

			return 1;
		}

		// Error from Meta
		$errMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error (HTTP '.$http_code.')';
		if (isset($data['error']['error_user_msg'])) {
			$errMsg .= ' — '.$data['error']['error_user_msg'];
		}
		$this->errors[] = $errMsg;
		return -3;
	}
}
