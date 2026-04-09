<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \defgroup   whatsappdati     Module WhatsAppDati
 * \brief      WhatsAppDati module descriptor
 * \file       core/modules/modWhatsAppDati.class.php
 * \ingroup    whatsappdati
 * \brief      Description and activation file for module WhatsAppDati
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module WhatsAppDati
 */
class modWhatsAppDati extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique).
		$this->numero = 500000;
		
		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'whatsappdati';

		// Family can be 'base', 'crm', 'financial', 'hr', 'projects', 'products', 'ecm', 'technic' (Engineering), 'interface' (Others), 'other'
		$this->family = "crm";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string 'ModuleWhatsAppDatiName' not found
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleWhatsAppDatiDesc' not found
		$this->description = "WhatsApp Business Integration for Dolibarr";

		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "WhatsApp Business Integration Module - Send and receive WhatsApp messages, manage conversations, templates and multi-agent support";

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
		$this->version = '2.0.0';

		// Publisher name
		$this->editor_name = 'DatiLab';
		$this->editor_url = 'https://datilab.com';

		// URL to check for updates (optional, used by Dolibarr core to notify new versions)
		// $this->url_last_version = 'https://datilab.com/dolibarr/whatsappdati/version.txt';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'whatsappdati@whatsappdati';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'css' => array('/custom/whatsappdati/css/whatsappdati.css?v=20260304d'),
			'js' => array('/custom/whatsappdati/js/whatsappdati.js?v=20260304d'),
			'hooks' => array(
				'thirdpartycard',
				'contactcard',
				'productcard',
				'invoicecard',
				'ordercard',
				'propalcard',
				'projectcard',
				'ticketcard',
				'main',
				'commonobject'
			),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/whatsappdati/temp", "/whatsappdati/media");

		// Config pages
		$this->config_page_url = array("setup.php@whatsappdati");

		// Dependencies
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// Constants
		$this->const = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array(
			0 => array(
				'label' => 'ProcessWhatsAppQueue',
				'jobtype' => 'method',
				'class' => '/custom/whatsappdati/class/whatsappqueue.class.php',
				'objectname' => 'WhatsAppQueue',
				'method' => 'processBatch',
				'parameters' => '50',
				'comment' => 'Process pending WhatsApp queue messages (bulk send)',
				'frequency' => 5,
				'unitfrequency' => 60, // 60 = minutes
				'status' => 1,
				'test' => '$conf->whatsappdati->enabled',
				'priority' => 50,
			),
			1 => array(
				'label' => 'ProcessWhatsAppScheduled',
				'jobtype' => 'method',
				'class' => '/custom/whatsappdati/class/whatsappschedule.class.php',
				'objectname' => 'WhatsAppSchedule',
				'method' => 'processDueMessages',
				'parameters' => '50',
				'comment' => 'Process scheduled WhatsApp messages (one-time and recurring)',
				'frequency' => 2,
				'unitfrequency' => 60, // 60 = minutes
				'status' => 1,
				'test' => '$conf->whatsappdati->enabled',
				'priority' => 50,
			)
		);

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		// Main menu entries to add
		$this->menu = array();
		$r = 0;

		// Add here entries to declare new permissions
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Read WhatsApp conversations';
		$this->rights[$r][4] = 'conversation';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Send WhatsApp messages';
		$this->rights[$r][4] = 'message';
		$this->rights[$r][5] = 'send';
		$r++;

		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Manage WhatsApp templates';
		$this->rights[$r][4] = 'template';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Configure WhatsApp module';
		$this->rights[$r][4] = 'config';
		$this->rights[$r][5] = 'write';
		$r++;

		// Main menu entries
		$this->menu[$r++] = array(
			'fk_menu'=>'', // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type'=>'top',
			'titre'=>'WhatsApp',
			'prefix' => img_picto('', 'fontawesome_whatsapp_fab_#25D366', 'class="pictofixedwidth"'),
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'',
			'url'=>'/custom/whatsappdati/conversations.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->conversation->read',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'Conversations',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_conversations',
			'url'=>'/custom/whatsappdati/conversations.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->conversation->read',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'Templates',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_templates',
			'url'=>'/custom/whatsappdati/templates.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->template->write',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'BulkSend',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_bulksend',
			'url'=>'/custom/whatsappdati/bulk_send.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->message->send',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'ChatbotMenu',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_chatbot',
			'url'=>'/custom/whatsappdati/chatbot.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->config->write',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'ScheduleMenu',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_schedule',
			'url'=>'/custom/whatsappdati/schedule.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->message->send',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++] = array(
			'fk_menu'=>'fk_mainmenu=whatsappdati',
			'type'=>'left',
			'titre'=>'Configuration',
			'mainmenu'=>'whatsappdati',
			'leftmenu'=>'whatsappdati_config',
			'url'=>'/custom/whatsappdati/admin/setup.php',
			'langs'=>'whatsappdati@whatsappdati',
			'position'=>1000+$r,
			'enabled'=>'$conf->whatsappdati->enabled',
			'perms'=>'$user->rights->whatsappdati->config->write',
			'target'=>'',
			'user'=>2,
		);
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Attempt _load_tables but do not abort on failure - some environments
		// reject certain SQL statements (e.g. FOREIGN KEY cross-references).
		$result = $this->_load_tables('/custom/whatsappdati/sql/');
		if ($result < 0) {
			dol_syslog('modWhatsAppDati::init _load_tables returned '.$result.' - trying direct table creation fallback', LOG_WARNING);
		}

		// Fallback: always ensure the critical config table exists regardless of
		// _load_tables result. This guards against environments where _load_tables
		// exits early due to a non-ignorable error in a migration/key file.
		$this->createTablesIfMissing();

		// Run safe schema migrations (idempotent ALTER TABLE ADD COLUMN)
		$this->applyMigrations();

		// Create extrafields during init
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		return $this->_init(array(), $options);
	}

	/**
	 * Ensure all module tables exist. Runs CREATE TABLE IF NOT EXISTS for every
	 * table defined in llx_whatsapp.sql directly via PHP so the module works even
	 * when _load_tables() fails partway through.
	 *
	 * @return void
	 */
	private function createTablesIfMissing()
	{
		$tables = $this->getTableDefinitions();
		foreach ($tables as $sql) {
			$res = $this->db->query($sql);
			if (!$res) {
				$errno = $this->db->lasterrno();
				// Ignore "already exists" — that is the expected state after first install
				if ($errno !== 'DB_ERROR_TABLE_ALREADY_EXISTS' && $errno !== 'DB_ERROR_TABLE_OR_KEY_ALREADY_EXISTS') {
					dol_syslog('modWhatsAppDati::createTablesIfMissing error: '.$this->db->lasterror(), LOG_ERR);
				}
			}
		}
	}

	/**
	 * Run idempotent ALTER TABLE migrations. Each statement uses ADD COLUMN
	 * which silently fails if the column already exists — safe to run on
	 * every activation.
	 *
	 * @return void
	 */
	private function applyMigrations()
	{
		$migrations = array(
			// v2.1 — Variable mapping & header media for templates
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates ADD COLUMN variable_mapping TEXT DEFAULT NULL",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates ADD COLUMN header_image_mode VARCHAR(20) DEFAULT 'on_send'",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates ADD COLUMN header_media_url TEXT DEFAULT NULL",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates ADD COLUMN header_media_local TEXT DEFAULT NULL",

			// v2.1 — Convert all module tables to utf8mb4 so emojis are supported
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_templates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_conversations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_queue CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_transfers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_csat CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",

			// v2.2 — Per-line assignment mode
			"ALTER TABLE ".MAIN_DB_PREFIX."whatsapp_config ADD COLUMN assign_mode VARCHAR(20) DEFAULT 'manual' AFTER country_code",
		);

		foreach ($migrations as $sql) {
			$res = $this->db->query($sql);
			if (!$res) {
				$err = $this->db->lasterror();
				// "Duplicate column name" means it already exists — perfectly fine
				if (strpos($err, 'Duplicate column') === false && strpos($err, 'duplicate column') === false) {
					dol_syslog('modWhatsAppDati::applyMigrations notice: '.$err, LOG_DEBUG);
				}
			}
		}
	}

	/**
	 * Returns an array of CREATE TABLE IF NOT EXISTS statements for all module tables.
	 * Intentionally free of FOREIGN KEY constraints for maximum compatibility.
	 *
	 * @return string[]
	 */
	private function getTableDefinitions()
	{
		return array(
			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_config (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				label VARCHAR(100) DEFAULT NULL,
				app_id VARCHAR(50) DEFAULT NULL,
				phone_number_id VARCHAR(50) NOT NULL,
				business_account_id VARCHAR(50) NOT NULL,
				access_token TEXT NOT NULL,
				webhook_verify_token VARCHAR(100) NOT NULL,
				app_secret VARCHAR(255) DEFAULT NULL,
				webhook_url VARCHAR(255),
				fk_user_default_agent INTEGER DEFAULT NULL,
				country_code VARCHAR(5) DEFAULT '57',
				status TINYINT DEFAULT 1,
				date_creation DATETIME NOT NULL,
				date_modification DATETIME,
				fk_user_creat INTEGER,
				fk_user_modif INTEGER,
				import_key VARCHAR(14)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_conversations (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				conversation_id VARCHAR(100) NOT NULL,
				phone_number VARCHAR(50) NOT NULL,
				contact_name VARCHAR(255),
				fk_soc INTEGER,
				fk_user_assigned INTEGER,
				status VARCHAR(20) DEFAULT 'active',
				last_message_date DATETIME,
				last_message_preview TEXT,
				unread_count INTEGER DEFAULT 0,
				window_expires_at DATETIME,
				date_creation DATETIME NOT NULL,
				date_modification DATETIME,
				import_key VARCHAR(14),
				INDEX idx_phone (phone_number),
				INDEX idx_status (status),
				INDEX idx_assigned (fk_user_assigned),
				INDEX idx_entity (entity),
				INDEX idx_last_message (last_message_date),
				UNIQUE KEY uk_conv_id (conversation_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_messages (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				message_id VARCHAR(100),
				fk_conversation INTEGER NOT NULL,
				direction VARCHAR(10) NOT NULL,
				message_type VARCHAR(20) DEFAULT 'text',
				content TEXT,
				template_name VARCHAR(100),
				template_params TEXT,
				media_url VARCHAR(500),
				media_mime_type VARCHAR(100),
				media_filename VARCHAR(255),
				media_local_path VARCHAR(500),
				status VARCHAR(20) DEFAULT 'pending',
				error_message TEXT,
				fk_user_sender INTEGER,
				timestamp DATETIME NOT NULL,
				date_creation DATETIME NOT NULL,
				import_key VARCHAR(14),
				INDEX idx_conversation (fk_conversation),
				INDEX idx_status (status),
				INDEX idx_timestamp (timestamp),
				INDEX idx_entity (entity),
				UNIQUE KEY uk_msg_id (message_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_templates (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				template_id VARCHAR(100),
				name VARCHAR(100) NOT NULL,
				language VARCHAR(10) DEFAULT 'es',
				category VARCHAR(50),
				status VARCHAR(20) DEFAULT 'pending',
				header_type VARCHAR(20),
				header_content TEXT,
				body_text TEXT NOT NULL,
				footer_text VARCHAR(60),
				buttons TEXT,
				variables TEXT,
				variable_mapping TEXT,
				header_image_mode VARCHAR(20) DEFAULT 'on_send',
				header_media_url TEXT,
				header_media_local TEXT,
				sync_date DATETIME,
				date_creation DATETIME NOT NULL,
				date_modification DATETIME,
				fk_user_creat INTEGER,
				fk_user_modif INTEGER,
				import_key VARCHAR(14),
				INDEX idx_name (name),
				INDEX idx_status (status),
				INDEX idx_entity (entity),
				UNIQUE KEY uk_template (entity, name, language)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_queue (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				batch_id VARCHAR(50),
				phone_number VARCHAR(50) NOT NULL,
				contact_name VARCHAR(255),
				fk_soc INTEGER,
				fk_template INTEGER,
				template_name VARCHAR(100),
				template_params TEXT,
				message_content TEXT,
				scheduled_date DATETIME NOT NULL,
				status VARCHAR(20) DEFAULT 'pending',
				retry_count INTEGER DEFAULT 0,
				max_retries INTEGER DEFAULT 3,
				error_message TEXT,
				message_id_wa VARCHAR(100),
				date_sent DATETIME,
				date_creation DATETIME NOT NULL,
				fk_user_creat INTEGER,
				import_key VARCHAR(14),
				INDEX idx_batch (batch_id),
				INDEX idx_scheduled (scheduled_date),
				INDEX idx_status (status),
				INDEX idx_entity (entity)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_tags (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(7) DEFAULT '#25D366',
				description VARCHAR(255),
				position INTEGER DEFAULT 0,
				active TINYINT DEFAULT 1,
				date_creation DATETIME NOT NULL,
				fk_user_creat INTEGER,
				INDEX idx_entity (entity),
				UNIQUE KEY uk_tag_label (entity, label)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_conversation_tags (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				fk_conversation INTEGER NOT NULL,
				fk_tag INTEGER NOT NULL,
				date_creation DATETIME NOT NULL,
				fk_user_creat INTEGER,
				INDEX idx_conversation (fk_conversation),
				INDEX idx_tag (fk_tag),
				UNIQUE KEY uk_conv_tag (fk_conversation, fk_tag)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_quick_replies (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				shortcut VARCHAR(50) NOT NULL,
				title VARCHAR(150) NOT NULL,
				content TEXT NOT NULL,
				category VARCHAR(100),
				position INTEGER DEFAULT 0,
				active TINYINT DEFAULT 1,
				date_creation DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat INTEGER,
				INDEX idx_entity (entity),
				INDEX idx_category (category),
				UNIQUE KEY uk_quick_reply_shortcut (entity, shortcut)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_chatbot_rules (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				name VARCHAR(200) NOT NULL,
				trigger_type VARCHAR(30) NOT NULL,
				trigger_value VARCHAR(500),
				response_type VARCHAR(30) DEFAULT 'text',
				response_text TEXT,
				response_template_name VARCHAR(200),
				response_template_params TEXT,
				delay_seconds INTEGER DEFAULT 0,
				condition_type VARCHAR(30) DEFAULT 'always',
				work_hours_start TIME DEFAULT '09:00:00',
				work_hours_end TIME DEFAULT '18:00:00',
				max_triggers_per_conv INTEGER DEFAULT 0,
				priority INTEGER DEFAULT 10,
				stop_on_match TINYINT DEFAULT 1,
				active TINYINT DEFAULT 1,
				date_creation DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat INTEGER,
				INDEX idx_entity (entity),
				INDEX idx_trigger (trigger_type),
				INDEX idx_priority (priority),
				INDEX idx_active (active)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_chatbot_log (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				fk_rule INTEGER NOT NULL,
				fk_conversation INTEGER NOT NULL,
				fk_message_in INTEGER,
				fk_message_out INTEGER,
				date_triggered DATETIME NOT NULL,
				INDEX idx_rule (fk_rule),
				INDEX idx_conversation (fk_conversation),
				INDEX idx_rule_conv (fk_rule, fk_conversation)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_scheduled (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				phone_number VARCHAR(50) NOT NULL,
				contact_name VARCHAR(255),
				fk_soc INTEGER,
				fk_conversation INTEGER,
				message_type VARCHAR(20) DEFAULT 'text',
				message_content TEXT,
				template_name VARCHAR(100),
				template_params TEXT,
				scheduled_date DATETIME NOT NULL,
				recurrence_type VARCHAR(20) DEFAULT 'once',
				recurrence_end_date DATETIME,
				next_execution DATETIME,
				status VARCHAR(20) DEFAULT 'pending',
				last_execution DATETIME,
				execution_count INTEGER DEFAULT 0,
				retry_count INTEGER DEFAULT 0,
				max_retries INTEGER DEFAULT 3,
				error_message TEXT,
				message_id_wa VARCHAR(100),
				note VARCHAR(500),
				date_creation DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat INTEGER,
				INDEX idx_entity (entity),
				INDEX idx_scheduled (scheduled_date),
				INDEX idx_next_exec (next_execution),
				INDEX idx_status (status),
				INDEX idx_phone (phone_number),
				INDEX idx_recurrence (recurrence_type)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_transfers (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_conversation INTEGER NOT NULL,
				from_user_id INTEGER,
				to_user_id INTEGER NOT NULL,
				note TEXT,
				date_transfer DATETIME NOT NULL,
				INDEX idx_entity (entity),
				INDEX idx_conversation (fk_conversation)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_csat (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_conversation INTEGER NOT NULL,
				fk_line INTEGER DEFAULT NULL,
				phone_number VARCHAR(50) NOT NULL,
				rating TINYINT DEFAULT NULL,
				feedback_text TEXT,
				fk_user_agent INTEGER,
				sent_at DATETIME NOT NULL,
				responded_at DATETIME DEFAULT NULL,
				message_id_wa VARCHAR(100),
				status VARCHAR(20) DEFAULT 'sent',
				date_creation DATETIME NOT NULL,
				INDEX idx_entity (entity),
				INDEX idx_conversation (fk_conversation),
				INDEX idx_rating (rating),
				INDEX idx_status (status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_agent_assignment (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER DEFAULT 1 NOT NULL,
				fk_conversation INTEGER NOT NULL,
				fk_user INTEGER NOT NULL,
				assigned_at DATETIME NOT NULL,
				INDEX idx_entity (entity),
				INDEX idx_conv (fk_conversation),
				INDEX idx_user (fk_user)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_conversation_agents (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				fk_conversation INTEGER NOT NULL,
				fk_user INTEGER NOT NULL,
				role VARCHAR(20) DEFAULT 'agent',
				date_creation DATETIME NOT NULL,
				fk_user_creat INTEGER,
				INDEX idx_conv (fk_conversation),
				INDEX idx_user (fk_user),
				UNIQUE KEY uk_conv_user (fk_conversation, fk_user)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."whatsapp_line_agents (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				fk_line INTEGER NOT NULL,
				fk_user INTEGER NOT NULL,
				date_creation DATETIME NOT NULL,
				fk_user_creat INTEGER,
				INDEX idx_line (fk_line),
				INDEX idx_user (fk_user),
				UNIQUE KEY uk_line_user (fk_line, fk_user)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
