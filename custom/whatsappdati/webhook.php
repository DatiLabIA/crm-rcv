<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       webhook.php
 * \ingroup    whatsappdati
 * \brief      WhatsApp Webhook endpoint for receiving messages from Meta
 */

// =====================================================================
// ULTRA-EARLY REQUEST LOGGING (before Dolibarr loads)
// Writes directly to a known path to confirm Meta delivers webhooks.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Write to a hardcoded path we know exists from diagnostic
	$_earlyLogDir = dirname(__DIR__) . '/../../../documents/whatsappdati/temp';
	if (!is_dir($_earlyLogDir)) {
		// Try alternative paths
		$_tryPaths = array(
			'/var/www/vhosts/loving-lumiere.144-217-88-124.plesk.page/documents/whatsappdati/temp',
			dirname(__DIR__) . '/../../documents/whatsappdati/temp',
		);
		foreach ($_tryPaths as $_tp) {
			if (is_dir($_tp)) { $_earlyLogDir = $_tp; break; }
		}
	}
	// Store path for checkpoint logging
	$_webhookLogFile = $_earlyLogDir . '/webhook_early.log';
	@file_put_contents(
		$_webhookLogFile,
		date('Y-m-d H:i:s') . ' POST from=' . ($_SERVER['REMOTE_ADDR'] ?? '?') .
		' len=' . ($_SERVER['CONTENT_LENGTH'] ?? '?') .
		' ua=' . substr(($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80) . "\n",
		FILE_APPEND | LOCK_EX
	);
}

// =====================================================================
// EARLY WEBHOOK VERIFICATION
// Meta sends GET ?hub.mode=subscribe to verify the webhook.
// We load Dolibarr (NOLOGIN) the same way diagnose.php does — proven
// to work — then query the DB for the verify token.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
	$hubToken     = isset($_GET['hub_verify_token']) ? $_GET['hub_verify_token'] : '';
	$hubChallenge = isset($_GET['hub_challenge'])    ? $_GET['hub_challenge']    : '';
	$lineId       = isset($_GET['line'])             ? (int) $_GET['line']       : 0;

	if (empty($hubToken) || empty($hubChallenge)) {
		http_response_code(403);
		echo 'Forbidden';
		exit;
	}

	// Load Dolibarr with NOLOGIN — same pattern as diagnose.php, proven on this server
	if (!defined('NOLOGIN'))          define('NOLOGIN', 1);
	if (!defined('NOCSRFCHECK'))      define('NOCSRFCHECK', 1);
	if (!defined('NOREQUIREUSER'))    define('NOREQUIREUSER', 1);
	if (!defined('NOREQUIREMENU'))    define('NOREQUIREMENU', 1);
	if (!defined('NOREQUIREHTML'))    define('NOREQUIREHTML', 1);
	if (!defined('NOREQUIREAJAX'))    define('NOREQUIREAJAX', 1);
	if (!defined('NOTOKENRENEWAL'))   define('NOTOKENRENEWAL', 1);

	$_wres = 0;
	if (!$_wres && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
		$_wres = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
	}
	$_wtmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
	$_wtmp2 = realpath(__FILE__);
	$_wi = strlen($_wtmp) - 1; $_wj = strlen($_wtmp2) - 1;
	while ($_wi > 0 && $_wj > 0 && isset($_wtmp[$_wi]) && isset($_wtmp2[$_wj]) && $_wtmp[$_wi] == $_wtmp2[$_wj]) { $_wi--; $_wj--; }
	if (!$_wres && $_wi > 0 && file_exists(substr($_wtmp, 0, $_wi + 1).'/main.inc.php')) {
		$_wres = @include substr($_wtmp, 0, $_wi + 1).'/main.inc.php';
	}
	if (!$_wres && $_wi > 0 && file_exists(dirname(substr($_wtmp, 0, $_wi + 1)).'/main.inc.php')) {
		$_wres = @include dirname(substr($_wtmp, 0, $_wi + 1)).'/main.inc.php';
	}
	if (!$_wres && file_exists('../../main.inc.php'))    { $_wres = @include '../../main.inc.php'; }
	if (!$_wres && file_exists('../../../main.inc.php')) { $_wres = @include '../../../main.inc.php'; }

	if ($_wres && isset($db)) {
		$_verified = false;
		$_dbPrefix = MAIN_DB_PREFIX;

		// Try specific line first
		if ($lineId > 0) {
			$_sqlV = "SELECT webhook_verify_token FROM ".$_dbPrefix."whatsapp_config WHERE rowid = ".(int)$lineId." LIMIT 1";
			$_resV = $db->query($_sqlV);
			if ($_resV) {
				$_rowV = $db->fetch_object($_resV);
				if ($_rowV && hash_equals((string)$_rowV->webhook_verify_token, (string)$hubToken)) {
					$_verified = true;
				}
				$db->free($_resV);
			}
		}

		// Fallback: scan all lines
		if (!$_verified) {
			$_resAll = $db->query("SELECT webhook_verify_token FROM ".$_dbPrefix."whatsapp_config");
			if ($_resAll) {
				while ($_rowAll = $db->fetch_object($_resAll)) {
					if (!empty($_rowAll->webhook_verify_token) && hash_equals((string)$_rowAll->webhook_verify_token, (string)$hubToken)) {
						$_verified = true;
						break;
					}
				}
				$db->free($_resAll);
			}
		}

		if ($_verified) {
			while (ob_get_level()) ob_end_clean();
			header_remove();
			http_response_code(200);
			header('Content-Type: text/plain; charset=utf-8');
			header('Content-Length: '.strlen($hubChallenge));
			echo $hubChallenge;
			exit;
		}
	}

	http_response_code(403);
	echo 'Forbidden';
	exit;
}


// =====================================================================
// WEBHOOK VERIFY SIMULATOR — Test without going through Meta
// Call: GET webhook.php?wa_verify_test=1&token=YOUR_VERIFY_TOKEN&line=LINE_ID
// Returns JSON with what the real Meta verification would do.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['wa_verify_test'])) {
	header('Content-Type: application/json; charset=utf-8');
	$_testToken = isset($_GET['token']) ? $_GET['token'] : '';
	$_testLine  = isset($_GET['line'])  ? (int)$_GET['line'] : 0;
	$_out = array('dir' => __DIR__, 'conf_found' => false, 'db_connected' => false, 'rows' => 0, 'token_match' => false, 'paths_tried' => array());

	$_dir2 = __DIR__;
	$_cands2 = array();
	for ($up = 1; $up <= 5; $up++) {
		$_dir2 = dirname($_dir2);
		$_cands2[] = $_dir2 . '/conf/conf.php';
		$_cands2[] = $_dir2 . '/conf.php';
	}
	foreach (array($_SERVER['DOCUMENT_ROOT'] ?? '', $_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '') as $_dr2) {
		if (!empty($_dr2)) { $_cands2[] = dirname($_dr2) . '/conf/conf.php'; $_cands2[] = $_dr2 . '/../conf/conf.php'; }
	}
	foreach ($_cands2 as $_cp2) {
		$_cp2r = realpath($_cp2) ?: $_cp2;
		$_out['paths_tried'][] = $_cp2r . ' [' . (file_exists($_cp2r) ? 'EXISTS' : 'missing') . ']';
		if (file_exists($_cp2r) && !$_out['conf_found']) {
			require_once $_cp2r;
			$_out['conf_found'] = true;
			$_out['conf_path'] = $_cp2r;
			$_out['db_host'] = $dolibarr_main_db_host ?? '';
			$_out['db_name'] = $dolibarr_main_db_name ?? '';
			$_out['db_user'] = $dolibarr_main_db_user ?? '';
			$_out['db_prefix'] = $dolibarr_main_db_prefix ?? 'llx_';
		}
	}
	if ($_out['conf_found'] && !empty($dolibarr_main_db_host)) {
		$_c2 = @mysqli_connect($dolibarr_main_db_host, $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_name, !empty($dolibarr_main_db_port) ? (int)$dolibarr_main_db_port : 3306);
		if ($_c2) {
			$_out['db_connected'] = true;
			$_pfx2 = $dolibarr_main_db_prefix ?? 'llx_';
			$_rr = @mysqli_query($_c2, "SELECT rowid, label, webhook_verify_token, status FROM " . $_pfx2 . "whatsapp_config ORDER BY rowid");
			if ($_rr) {
				while ($_row = mysqli_fetch_assoc($_rr)) {
					$_out['rows']++;
					$_match = !empty($_testToken) && hash_equals((string)$_row['webhook_verify_token'], (string)$_testToken);
					$_out['lines'][] = array('rowid' => $_row['rowid'], 'label' => $_row['label'], 'status' => $_row['status'], 'token_len' => strlen($_row['webhook_verify_token']), 'token_matches_input' => $_match);
					if ($_match) $_out['token_match'] = true;
				}
				mysqli_free_result($_rr);
			} else { $_out['db_error'] = mysqli_error($_c2); }
			mysqli_close($_c2);
		} else { $_out['db_error'] = mysqli_connect_error(); }
	}
	$_out['verdict'] = $_out['token_match'] ? 'WOULD_PASS' : 'WOULD_FAIL_403';
	echo json_encode($_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

// Disable Dolibarr authentication for webhook
define('NOCSRFCHECK', 1);
define('NOREQUIREUSER', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOREQUIRESOC', 1);
define('NOTOKENRENEWAL', 1);
define('NOLOGIN', 1);

// Checkpoint function for tracing crashes
function _webhookCheckpoint($step) {
	global $_webhookLogFile;
	if (!empty($_webhookLogFile)) {
		@file_put_contents($_webhookLogFile, date('Y-m-d H:i:s').' CHECKPOINT: '.$step."\n", FILE_APPEND|LOCK_EX);
	}
}

// Register shutdown function to catch fatal errors AND silent exits
register_shutdown_function(function() {
	global $_webhookLogFile, $_webhookReachedEnd;
	if (empty($_webhookLogFile)) return;
	$error = error_get_last();
	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
		@file_put_contents(
			$_webhookLogFile,
			date('Y-m-d H:i:s').' FATAL_ERROR: '.$error['message'].' in '.$error['file'].':'.$error['line']."\n",
			FILE_APPEND|LOCK_EX
		);
	} elseif (empty($_webhookReachedEnd)) {
		// Script exited before reaching the end — capture output to see what main.inc.php returned
		$output = ob_get_contents();
		if (empty($output)) {
			$output = '(empty buffer)';
		}
		// Also check headers
		$headers = '';
		if (function_exists('headers_list')) {
			$hdrs = headers_list();
			$headers = implode(' | ', $hdrs);
		}
		@file_put_contents(
			$_webhookLogFile,
			date('Y-m-d H:i:s').' SILENT_EXIT: HTTP='.http_response_code().
			' output=['.substr($output, 0, 500).']'.
			' headers=['.$headers."]\n",
			FILE_APPEND|LOCK_EX
		);
	}
});

_webhookCheckpoint('defines_set');

// Start output buffering so we can capture any output from main.inc.php
ob_start();

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	_webhookCheckpoint('trying_CONTEXT_DOC_ROOT: '.$_SERVER["CONTEXT_DOCUMENT_ROOT"].'/main.inc.php exists='.( file_exists($_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php") ? 'YES' : 'NO'));
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
	_webhookCheckpoint('after_CONTEXT_DOC_ROOT res='.$res);
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	_webhookCheckpoint('trying_SCRIPT_FILENAME: '.substr($tmp, 0, ($i + 1)).'/main.inc.php');
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
	_webhookCheckpoint('after_SCRIPT_FILENAME res='.$res);
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	_webhookCheckpoint('trying_relative: ../main.inc.php');
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	_webhookCheckpoint('FAIL_main_inc_not_found');
	die("Include of main fails");
}
_webhookCheckpoint('dolibarr_loaded');

require_once __DIR__.'/class/whatsappconfig.class.php';
_webhookCheckpoint('class_config_loaded');
require_once __DIR__.'/class/whatsappconversation.class.php';
require_once __DIR__.'/class/whatsappmessage.class.php';
require_once __DIR__.'/class/whatsappmanager.class.php';
require_once __DIR__.'/class/whatsappassignment.class.php';
require_once __DIR__.'/class/whatsappevent.class.php';
require_once __DIR__.'/class/whatsappchatbot.class.php';
require_once __DIR__.'/class/whatsappcsat.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
_webhookCheckpoint('all_classes_loaded');

// One-time migration: ensure tables support emojis (utf8mb4)
_whatsappMigrateUtf8mb4();

// Force utf8mb4 connection charset so emojis are stored/read correctly
if ($db->type === 'mysqli' || $db->type === 'mysql') {
	$db->query("SET NAMES 'utf8mb4'");
}
_webhookCheckpoint('utf8mb4_connection_set');

/**
 * Write debug log to file (readable from diagnostic endpoint)
 * @param string $message Log message
 */
function _whatsappDebugLog($message)
{
	global $conf;
	$logDir = '';
	if (!empty($conf->whatsappdati->dir_temp)) {
		$logDir = $conf->whatsappdati->dir_temp;
	} elseif (defined('DOL_DATA_ROOT')) {
		$logDir = DOL_DATA_ROOT . '/whatsappdati/temp';
	}
	if (empty($logDir)) return;
	if (!is_dir($logDir)) {
		@mkdir($logDir, 0775, true);
	}
	$logFile = $logDir . '/webhook_debug.log';
	// Rotate if > 500KB
	if (file_exists($logFile) && filesize($logFile) > 512000) {
		@rename($logFile, $logFile . '.old');
	}
	@file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * One-time migration: convert WhatsApp tables to utf8mb4 to support emojis.
 * Runs once, then stores a flag in Dolibarr config so it never runs again.
 */
function _whatsappMigrateUtf8mb4()
{
	global $db, $conf;

	// Already migrated? Skip.
	if (getDolGlobalString('WHATSAPPDATI_UTF8MB4_DONE')) {
		return;
	}

	$tables = array(
		'whatsapp_config',
		'whatsapp_conversations',
		'whatsapp_messages',
		'whatsapp_templates',
		'whatsapp_queue',
		'whatsapp_tags',
		'whatsapp_conversation_tags',
		'whatsapp_quick_replies',
		'whatsapp_chatbot_rules',
		'whatsapp_chatbot_log',
		'whatsapp_scheduled'
	);

	$allOk = true;
	foreach ($tables as $table) {
		$fullTable = MAIN_DB_PREFIX . $table;
		$sql = "ALTER TABLE " . $fullTable . " CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
		$res = $db->query($sql);
		if (!$res) {
			$err = $db->lasterror();
			// Table might not exist yet — that's OK, skip it
			if (stripos($err, "doesn't exist") !== false || stripos($err, 'does not exist') !== false) {
				_whatsappDebugLog('UTF8MB4 migration SKIPPED (table not found): ' . $fullTable);
			} else {
				_whatsappDebugLog('UTF8MB4 migration FAILED for ' . $fullTable . ': ' . $err);
				$allOk = false;
			}
		} else {
			_whatsappDebugLog('UTF8MB4 migration OK for ' . $fullTable);
		}
	}

	if ($allOk) {
		// Also fix the UNIQUE index on conversation_id which may be too long for utf8mb4
		// varchar(100) with utf8mb4 = 400 bytes, well within 767 byte index limit
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		dolibarr_set_const($db, 'WHATSAPPDATI_UTF8MB4_DONE', '1', 'chaine', 0, 'WhatsApp utf8mb4 migration completed', $conf->entity);
		_whatsappDebugLog('UTF8MB4 migration completed and flag saved');
	}
}

/**
 * Remove 4-byte UTF-8 characters (emojis) as fallback when DB doesn't support utf8mb4.
 * @param string|null $str Input string
 * @return string Sanitized string
 */
function _whatsappSanitizeUtf8($str)
{
	if ($str === null || $str === '') {
		return '';
	}
	return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $str);
}

/**
 * Check if the current time is outside configured business hours.
 * Returns the auto-reply message if outside hours, or empty string if inside hours.
 *
 * Config keys:
 *   WHATSAPPDATI_BH_ENABLED   - '1' to enable
 *   WHATSAPPDATI_BH_START     - e.g. '09:00'
 *   WHATSAPPDATI_BH_END       - e.g. '18:00'
 *   WHATSAPPDATI_BH_DAYS      - comma-separated day numbers (1=Mon,...,7=Sun)
 *   WHATSAPPDATI_BH_TIMEZONE  - e.g. 'America/Bogota' (falls back to server TZ)
 *   WHATSAPPDATI_BH_MESSAGE   - auto-reply text
 *
 * @return string  Auto-reply message if outside hours, empty string if inside hours or disabled
 */
function _whatsappGetOutOfHoursMessage()
{
	if (empty(getDolGlobalString('WHATSAPPDATI_BH_ENABLED'))) {
		return '';
	}

	$start    = getDolGlobalString('WHATSAPPDATI_BH_START', '09:00');
	$end      = getDolGlobalString('WHATSAPPDATI_BH_END', '18:00');
	$daysStr  = getDolGlobalString('WHATSAPPDATI_BH_DAYS', '1,2,3,4,5'); // Mon-Fri
	$timezone = getDolGlobalString('WHATSAPPDATI_BH_TIMEZONE', date_default_timezone_get());
	$message  = getDolGlobalString('WHATSAPPDATI_BH_MESSAGE');

	if (empty($message)) {
		$message = 'Gracias por escribirnos. En este momento estamos fuera de nuestro horario de atención. Le responderemos lo antes posible.';
	}

	try {
		$tz  = new DateTimeZone($timezone);
		$now = new DateTime('now', $tz);
	} catch (Exception $e) {
		$now = new DateTime();
	}

	// Check day of week (1=Mon ... 7=Sun)
	$currentDay = (int) $now->format('N');
	$allowedDays = array_map('intval', array_filter(explode(',', $daysStr)));
	if (!in_array($currentDay, $allowedDays)) {
		return $message;
	}

	// Check time range
	$currentTime = $now->format('H:i');
	if ($currentTime < $start || $currentTime >= $end) {
		return $message;
	}

	return ''; // Inside business hours
}

/**
 * Resolve which line (config row) this webhook request belongs to.
 * Strategy:
 *  1. If ?line=ID is present, use that specific line
 *  2. For POST: extract metadata.phone_number_id from payload and match
 *  3. Fallback: first active line
 *
 * @param  array|null $data   Parsed JSON payload (POST only)
 * @return WhatsAppConfig|null
 */
function resolveLineConfig($data = null)
{
	global $db;

	$lineId = GETPOSTINT('line');
	$config = new WhatsAppConfig($db);

	// Strategy 1: explicit line parameter
	if ($lineId > 0) {
		if ($config->fetch($lineId) > 0 && !empty($config->status)) {
			return $config;
		}
		dol_syslog('WhatsApp Webhook: Line ID '.$lineId.' not found or inactive', LOG_WARNING);
		return null;
	}

	// Strategy 2: match by phone_number_id from Meta payload metadata
	if (!empty($data['entry'])) {
		foreach ($data['entry'] as $entry) {
			if (!empty($entry['changes'])) {
				foreach ($entry['changes'] as $change) {
					if (!empty($change['value']['metadata']['phone_number_id'])) {
						$phoneNumberId = $change['value']['metadata']['phone_number_id'];
						$matched = new WhatsAppConfig($db);
						if ($matched->fetchByPhoneNumberId($phoneNumberId) > 0) {
							return $matched;
						}
						dol_syslog('WhatsApp Webhook: No line config for phone_number_id '.$phoneNumberId, LOG_WARNING);
						break 2;
					}
				}
			}
		}
	}

	// Strategy 3: fallback to first active line
	if ($config->fetchActive() > 0) {
		return $config;
	}

	return null;
}

/**
 * Process webhook (POST request from Meta)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	_webhookCheckpoint('POST_handler_reached');
	_whatsappDebugLog('POST received from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' Content-Length: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'none'));

	// Get POST body
	$input = file_get_contents('php://input');
	$data = json_decode($input, true);

	_whatsappDebugLog('Body length: ' . strlen($input) . ' JSON decode: ' . (is_array($data) ? 'OK' : 'FAIL') . ' Entries: ' . (isset($data['entry']) ? count($data['entry']) : '0'));

	// Resolve which line this webhook belongs to
	$config = resolveLineConfig($data);

	if (!$config) {
		_whatsappDebugLog('FAIL: Could not resolve any active line config');
		dol_syslog('WhatsApp Webhook: Could not resolve any active line config', LOG_ERR);
		http_response_code(200); // Still return 200 to avoid Meta retries
		echo json_encode(array('error' => 'No matching line'));
		exit;
	}

	$lineId = $config->id;
	_whatsappDebugLog('Config resolved: line=' . $lineId . ' label=' . $config->label . ' token_len=' . strlen($config->access_token) . ' secret_len=' . strlen($config->app_secret));

	// Verify webhook signature (X-Hub-Signature-256) using matched line's app_secret
	if (!empty($config->app_secret)) {
		$signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
		if (empty($signature)) {
			dol_syslog('WhatsApp Webhook: Missing X-Hub-Signature-256 header (line='.$lineId.') - proceeding without verification', LOG_WARNING);
			// Don't reject - some proxy/LB configurations strip headers
		} else {
			$expected_signature = 'sha256=' . hash_hmac('sha256', $input, $config->app_secret);
			if (!hash_equals($expected_signature, $signature)) {
				dol_syslog('WhatsApp Webhook: Invalid signature for line='.$lineId.' - expected='.substr($expected_signature, 0, 20).'... got='.substr($signature, 0, 20).'...', LOG_WARNING);
				http_response_code(401);
				echo json_encode(array('error' => 'Invalid signature'));
				exit;
			}
			dol_syslog('WhatsApp Webhook: Signature verified for line='.$lineId, LOG_DEBUG);
		}
	} else {
		dol_syslog('WhatsApp Webhook: No app_secret configured for line='.$lineId.' - skipping signature verification', LOG_WARNING);
	}
	
	// Log webhook receipt (redact message content and PII for privacy/GDPR)
	$entryCount = !empty($data['entry']) ? count($data['entry']) : 0;
	$msgCount = 0;
	if (!empty($data['entry'])) {
		foreach ($data['entry'] as $_e) {
			if (!empty($_e['changes'])) {
				foreach ($_e['changes'] as $_c) {
					if (!empty($_c['value']['messages'])) $msgCount += count($_c['value']['messages']);
				}
			}
		}
	}
	$logMsg = 'WhatsApp Webhook received POST (line='.$lineId.'): '.$entryCount.' entries, '.$msgCount.' messages';
	_whatsappDebugLog($logMsg);
	dol_syslog($logMsg, LOG_WARNING);
	error_log($logMsg);
	
	if (!empty($data['entry'])) {
		foreach ($data['entry'] as $entry) {
			if (!empty($entry['changes'])) {
				foreach ($entry['changes'] as $change) {
					if ($change['field'] === 'messages') {
						if (!empty($change['value']['messages'])) {
							try {
								processMessages($change['value'], $lineId, $config);
							} catch (Exception $e) {
							$errMsg = 'FATAL EXCEPTION: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine();
							_whatsappDebugLog($errMsg);
							_whatsappDebugLog('Stack: '.$e->getTraceAsString());
							dol_syslog('WhatsApp Webhook '.$errMsg, LOG_ERR);
							error_log('WhatsApp Webhook '.$errMsg);
						} catch (Error $e) {
							$errMsg = 'FATAL ERROR: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine();
							_whatsappDebugLog($errMsg);
							_whatsappDebugLog('Stack: '.$e->getTraceAsString());
							dol_syslog('WhatsApp Webhook '.$errMsg, LOG_ERR);
							error_log('WhatsApp Webhook '.$errMsg);
							}
						}
						if (!empty($change['value']['statuses'])) {
							try {
								processMessageStatus($change['value']);
							} catch (Exception $e) {
								dol_syslog('WhatsApp Webhook: processMessageStatus exception: '.$e->getMessage(), LOG_ERR);
							} catch (Error $e) {
								dol_syslog('WhatsApp Webhook: processMessageStatus error: '.$e->getMessage(), LOG_ERR);
							}
						}
					}
				}
			}
		}
	}
	
	// Always return 200 OK
	$_webhookReachedEnd = true;
	http_response_code(200);
	echo json_encode(array('status' => 'ok'));
	exit;
}

/**
 * Process incoming messages
 *
 * @param array           $value  Webhook value
 * @param int             $lineId Line ID
 * @param WhatsAppConfig  $config Line config
 */
function processMessages($value, $lineId, $config)
{
	global $db, $conf;
	
	if (empty($value['messages'])) {
		return;
	}
	
	dol_syslog('WhatsApp Webhook processMessages: START - '.count($value['messages']).' messages for line '.$lineId, LOG_WARNING);
	error_log('WhatsApp Webhook processMessages: START - '.count($value['messages']).' messages for line '.$lineId);
	_whatsappDebugLog('processMessages START: '.count($value['messages']).' msgs, line='.$lineId);
	
	foreach ($value['messages'] as $msg) {
		$phone_number = $msg['from'];
		$message_id = $msg['id'];
		$timestamp = $msg['timestamp'];

		dol_syslog('WhatsApp Webhook processMessages: Processing msg '.$message_id.' from '.$phone_number.' type='.$msg['type'], LOG_WARNING);
		_whatsappDebugLog('Processing msg='.$message_id.' type='.$msg['type']);

		// SECURITY: Validate phone number format (E.164: 7-15 digits)
		if (!preg_match('/^[1-9]\d{6,14}$/', $phone_number)) {
			dol_syslog('WhatsApp Webhook: Invalid phone number format, skipping message', LOG_WARNING);
			continue;
		}

		// H31: Deduplicate — skip if this message_id was already processed
		$sqlDedup = "SELECT rowid FROM ".MAIN_DB_PREFIX."whatsapp_messages WHERE message_id = '".$db->escape($message_id)."' AND entity = ".(int)$conf->entity." LIMIT 1";
		$resDedup = $db->query($sqlDedup);
		if ($resDedup && $db->num_rows($resDedup) > 0) {
			dol_syslog("WhatsApp Webhook: Duplicate message_id ".$message_id." — skipping", LOG_DEBUG);
			continue;
		}
		
		// Get or create conversation (scoped to this line)
		$conversation = new WhatsAppConversation($db);
		$result = $conversation->fetchByPhone($phone_number, $lineId);
		$isNewConversation = false;
		
		dol_syslog('WhatsApp Webhook processMessages: fetchByPhone result='.$result.' for phone='.$phone_number.' line='.$lineId, LOG_WARNING);
		error_log('WhatsApp Webhook processMessages: fetchByPhone result='.$result.' convId='.($conversation->id ?? 'null'));
		_whatsappDebugLog('fetchByPhone result='.$result.' convId='.($conversation->id ?? 'null'));
		
		if ($result <= 0) {
			$isNewConversation = true;
			$conversation->phone_number = $phone_number;
			$conversation->fk_line = $lineId;
			$conversation->contact_name = $value['contacts'][0]['profile']['name'] ?? $phone_number;
			$conversation->status = 'active';
			$webhookUser = new User($db);
			$adminUserId = getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1);
			$userFetchRes = $webhookUser->fetch($adminUserId);
			_whatsappDebugLog('User fetch id='.$adminUserId.' result='.$userFetchRes.' login='.($webhookUser->login ?? 'NULL'));
			$createResult = $conversation->create($webhookUser);
			
			dol_syslog('WhatsApp Webhook processMessages: conversation->create result='.$createResult.' id='.$conversation->id, LOG_WARNING);
			error_log('WhatsApp Webhook processMessages: conversation->create result='.$createResult.' id='.$conversation->id);
			_whatsappDebugLog('conversation->create result='.$createResult.' id='.$conversation->id);
			if ($createResult < 0) {
				_whatsappDebugLog('CONV CREATE ERROR: '.implode(', ', $conversation->errors).' | lasterror='.$db->lasterror().' | lastquery='.$db->lastqueryerror());
			}

			// Auto-associate patient: try to find a societe matching this phone number
			if ($conversation->id > 0) {
				$foundSocId = WhatsAppConversation::findSocByPhone($db, $phone_number);
				if ($foundSocId > 0) {
					$conversation->fk_soc = $foundSocId;
					$conversation->update($webhookUser);
					dol_syslog('WhatsApp Webhook: Auto-associated conversation '.$conversation->id.' with societe '.$foundSocId, LOG_DEBUG);
					_whatsappDebugLog('Auto-associated conv='.$conversation->id.' with soc='.$foundSocId);
				}
			}

			// Auto-assign based on line's assign_mode
			$lineAssignMode = !empty($config->assign_mode) ? $config->assign_mode : 'manual';
			$lineAgents = $config->getLineAgents($lineId);
			$assignHandler = new WhatsAppAssignment($db);

			if ($lineAssignMode === 'manual') {
				// Manual mode: add all line agents to multi-agent table but do NOT set primary assignment
				// Conversation remains unassigned (fk_user_assigned = NULL) until claimed
				foreach ($lineAgents as $agentUserId) {
					$assignHandler->addConversationAgent($conversation->id, $agentUserId);
				}
			} elseif (!empty($lineAgents) && in_array($lineAssignMode, array('roundrobin', 'leastactive'))) {
				// Auto-assign using line agents with the configured mode
				$assignedId = $assignHandler->autoAssignFromAgents($conversation->id, $lineAgents, $lineAssignMode);
				if ($assignedId > 0) {
					$conversation->fk_user_assigned = $assignedId;
					$conversation->update($webhookUser);
				}
			} elseif ($config->fk_user_default_agent > 0) {
				// Legacy fallback: single default agent
				$conversation->fk_user_assigned = $config->fk_user_default_agent;
				$conversation->update($webhookUser);
				$assignHandler->addConversationAgent($conversation->id, $config->fk_user_default_agent);
			}

			$eventEmitter = new WhatsAppEvent($db);
			$eventEmitter->emitNewConversation($conversation->id, $phone_number, $conversation->contact_name);
		} else {
			// Existing conversation: try to auto-associate patient if not yet linked
			if (empty($conversation->fk_soc) && $conversation->id > 0) {
				$foundSocId = WhatsAppConversation::findSocByPhone($db, $phone_number);
				if ($foundSocId > 0) {
					$webhookUser = new User($db);
					$webhookUser->fetch(getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1));
					$conversation->fk_soc = $foundSocId;
					$conversation->update($webhookUser);
					dol_syslog('WhatsApp Webhook: Late-associated conversation '.$conversation->id.' with societe '.$foundSocId, LOG_DEBUG);
				}
			}
		}
		
		// Create message with line reference
		$message = new WhatsAppMessage($db);
		$message->message_id = $message_id;
		$message->fk_conversation = $conversation->id;
		$message->fk_line = $lineId;
		$message->direction = 'inbound';
		$message->timestamp = $timestamp;
		$message->status = 'delivered';
		
		// Process message type
		if ($msg['type'] === 'text') {
			$message->message_type = 'text';
			$message->content = $msg['text']['body'];
		} elseif ($msg['type'] === 'image') {
			$message->message_type = 'image';
			$message->media_url = $msg['image']['id'];
			$message->media_mime_type = $msg['image']['mime_type'] ?? 'image/jpeg';
			$message->content = $msg['image']['caption'] ?? '';
		} elseif ($msg['type'] === 'document') {
			$message->message_type = 'document';
			$message->media_url = $msg['document']['id'];
			$message->media_mime_type = $msg['document']['mime_type'] ?? 'application/pdf';
			$message->media_filename = $msg['document']['filename'] ?? '';
			$message->content = $msg['document']['caption'] ?? $message->media_filename;
		} elseif ($msg['type'] === 'video') {
			$message->message_type = 'video';
			$message->media_url = $msg['video']['id'];
			$message->media_mime_type = $msg['video']['mime_type'] ?? 'video/mp4';
			$message->content = $msg['video']['caption'] ?? '';
		} elseif ($msg['type'] === 'audio') {
			$message->message_type = 'audio';
			$message->media_url = $msg['audio']['id'];
			$message->media_mime_type = $msg['audio']['mime_type'] ?? 'audio/ogg';
			$message->content = '';
		} elseif ($msg['type'] === 'sticker') {
			$message->message_type = 'image';
			$message->media_url = $msg['sticker']['id'];
			$message->media_mime_type = $msg['sticker']['mime_type'] ?? 'image/webp';
			$message->content = '';
		} elseif ($msg['type'] === 'contacts') {
			$message->message_type = 'contacts';
			$message->content = json_encode($msg['contacts'], JSON_UNESCAPED_UNICODE);
		} elseif ($msg['type'] === 'location') {
			$message->message_type = 'location';
			$lat = $msg['location']['latitude'] ?? '';
			$lon = $msg['location']['longitude'] ?? '';
			$locName = $msg['location']['name'] ?? '';
			$locAddr = $msg['location']['address'] ?? '';
			$message->content = json_encode(array(
				'latitude' => $lat,
				'longitude' => $lon,
				'name' => $locName,
				'address' => $locAddr
			), JSON_UNESCAPED_UNICODE);
		}
		
		// Use internal system user for webhook operations
		if (!isset($webhookUser)) {
			$webhookUser = new User($db);
			$webhookUser->fetch(1);
		}
		$msgCreateResult = $message->create($webhookUser);
		
		dol_syslog('WhatsApp Webhook processMessages: message->create result='.$msgCreateResult.' conv_id='.$conversation->id.' msg_type='.$message->message_type, LOG_WARNING);
		error_log('WhatsApp Webhook processMessages: message->create result='.$msgCreateResult.' conv_id='.$conversation->id.' type='.$message->message_type);
		_whatsappDebugLog('message->create result='.$msgCreateResult.' convId='.$conversation->id.' type='.$message->message_type);
		if ($msgCreateResult < 0) {
			_whatsappDebugLog('MSG CREATE ERROR: '.implode(', ', $message->errors).' | lasterror='.$db->lasterror().' | lastquery='.$db->lastqueryerror());
		}
		
		// Emit real-time event for new inbound message
		$eventEmitter = new WhatsAppEvent($db);
		$eventEmitter->emitNewMessage(
			$conversation->id,
			'inbound',
			$message->message_type,
			$message->content,
			$phone_number,
			$conversation->contact_name,
			$lineId
		);
		
		// CSAT: check if this message is a rating response to a pending survey
		try {
			$csatHandler = new WhatsAppCSAT($db);
			$manager = new WhatsAppManager($db, $lineId);
			if ($csatHandler->processInboundForCSAT($conversation->id, $message->content, $manager, $phone_number)) {
				dol_syslog('WhatsApp Webhook: CSAT response processed for conversation '.$conversation->id, LOG_INFO);
				// Skip chatbot processing for CSAT responses
				continue;
			}
		} catch (Exception $eCSAT) {
			dol_syslog('WhatsApp CSAT error: '.$eCSAT->getMessage(), LOG_WARNING);
		}

		// Business hours: send auto-reply if outside configured hours
		// Only for the first message in a new conversation or if no reply sent in last 4 hours
		try {
			$oohMessage = _whatsappGetOutOfHoursMessage();
			if (!empty($oohMessage)) {
				// Check if we already sent an OOH reply recently (avoid spamming)
				$sqlOoh = "SELECT rowid FROM ".MAIN_DB_PREFIX."whatsapp_messages";
				$sqlOoh .= " WHERE fk_conversation = ".(int) $conversation->id;
				$sqlOoh .= " AND direction = 'outbound' AND content = '".$db->escape($oohMessage)."'";
				$sqlOoh .= " AND timestamp > '".$db->idate(dol_now() - 14400)."'"; // 4 hours
				$sqlOoh .= " LIMIT 1";
				$resOoh = $db->query($sqlOoh);
				if ($resOoh && $db->num_rows($resOoh) == 0) {
					if (!isset($manager)) {
						$manager = new WhatsAppManager($db, $lineId);
					}
					$oohResult = $manager->sendTextMessage($phone_number, $oohMessage);
					if ($oohResult['success']) {
						$oohMsg = new WhatsAppMessage($db);
						$oohMsg->message_id = $oohResult['message_id'];
						$oohMsg->fk_conversation = $conversation->id;
						$oohMsg->fk_line = $lineId;
						$oohMsg->direction = 'outbound';
						$oohMsg->message_type = 'text';
						$oohMsg->content = $oohMessage;
						$oohMsg->status = 'sent';
						$oohMsg->timestamp = dol_now();
						if (!isset($webhookUser)) {
							$webhookUser = new User($db);
							$webhookUser->fetch(getDolGlobalInt('WHATSAPPDATI_ADMIN_USER_ID', 1));
						}
						$oohMsg->create($webhookUser);
						dol_syslog('WhatsApp Webhook: Out-of-hours auto-reply sent to '.$phone_number, LOG_INFO);
					}
				}
			}
		} catch (Exception $eOoh) {
			dol_syslog('WhatsApp OOH error: '.$eOoh->getMessage(), LOG_WARNING);
		}

		// Chatbot: check and execute matching rules for inbound text messages (H30: non-blocking)
		try {
			$chatbotEngine = new WhatsAppChatbot($db);
			$matchedRules = $chatbotEngine->findMatchingRules(
				$conversation->id,
				$message->content,
				$message->message_type,
				$isNewConversation,
				$lineId
			);
			foreach ($matchedRules as $rule) {
				if ($rule->delay_seconds > 0) {
					_whatsappQueueDelayedChatbot($rule, $conversation->id, $message->id, $phone_number, $lineId);
				} else {
					$chatbotEngine->executeRule($rule, $conversation->id, $message->id, $phone_number, $lineId);
				}
			}
		} catch (Exception $eChatbot) {
			dol_syslog('WhatsApp Chatbot error: '.$eChatbot->getMessage(), LOG_WARNING);
		}
		
		// Download media in background for inbound media messages
		if (in_array($message->message_type, array('image', 'video', 'audio', 'document')) && !empty($message->media_url)) {
			try {
				$manager = new WhatsAppManager($db, $lineId);
				$downloadResult = $manager->downloadMedia(
					$message->media_url,
					$conversation->id,
					$message->media_filename
				);
				
				if ($downloadResult['success']) {
					$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_messages SET";
					$sql .= " media_local_path = '".$db->escape($downloadResult['local_path'])."'";
					if (!empty($downloadResult['mime_type'])) {
						$sql .= ", media_mime_type = '".$db->escape($downloadResult['mime_type'])."'";
					}
					if (!empty($downloadResult['filename']) && empty($message->media_filename)) {
						$sql .= ", media_filename = '".$db->escape($downloadResult['filename'])."'";
					}
					$sql .= " WHERE rowid = ".((int) $message->id);
					$db->query($sql);
					
					dol_syslog("WhatsApp media downloaded: ".$downloadResult['local_path'], LOG_DEBUG);
				}
			} catch (Exception $e) {
				dol_syslog("WhatsApp media download failed: ".$e->getMessage(), LOG_WARNING);
			}
		}
		
		dol_syslog("WhatsApp message saved: ".$message_id, LOG_DEBUG);
	}
}

/**
 * Process message status updates
 *
 * @param array $value Webhook value
 */
function processMessageStatus($value)
{
	global $db, $conf;
	
	if (empty($value['statuses'])) {
		return;
	}
	
	foreach ($value['statuses'] as $status) {
		$message_id = $status['id'];
		$new_status = $status['status']; // sent, delivered, read, failed

		// Collect error details when Meta reports failure
		$errorDetail = '';
		if ($new_status === 'failed' && !empty($status['errors'])) {
			$errParts = array();
			foreach ($status['errors'] as $err) {
				$errCode  = isset($err['code'])    ? $err['code']    : 'unknown';
				$errTitle = isset($err['title'])   ? $err['title']   : '';
				$errMsg   = isset($err['message']) ? $err['message'] : '';
				dol_syslog('WhatsApp Webhook: message FAILED id='.$message_id.' code='.$errCode.' title='.$errTitle.' msg='.$errMsg, LOG_ERR);
				$errParts[] = '[' . $errCode . '] ' . ($errTitle ?: $errMsg);
			}
			$errorDetail = implode('; ', $errParts);
		}

		// Find and update message
		// SECURITY: Validate status value against whitelist
		if (!in_array($new_status, array('sent', 'delivered', 'read', 'failed'))) {
			dol_syslog('WhatsApp Webhook: Invalid status value: '.$new_status, LOG_WARNING);
			continue;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."whatsapp_messages";
		$sql .= " SET status = '".$db->escape($new_status)."'";
		if ($errorDetail !== '') {
			$sql .= ", error_message = '".$db->escape($errorDetail)."'";
		}
		$sql .= " WHERE message_id = '".$db->escape($message_id)."' AND entity = ".(int)$conf->entity;
		
		$db->query($sql);
		
		// Emit real-time event for status update
		$eventEmitter = new WhatsAppEvent($db);
		$eventEmitter->emitStatusUpdate($message_id, $new_status);
		
		dol_syslog("WhatsApp message status updated: ".$message_id." -> ".$new_status, LOG_DEBUG);
	}
}

/**
 * Queue a delayed chatbot response for background execution (H30)
 * Inserts into whatsapp_scheduled_messages table with delay offset.
 *
 * @param  object $rule            Chatbot rule object
 * @param  int    $conversationId  Conversation ID
 * @param  int    $messageId       Trigger message ID
 * @param  string $phone           Phone number
 * @return void
 */
function _whatsappQueueDelayedChatbot($rule, $conversationId, $messageId, $phone, $lineId = 0)
{
	global $db, $conf;

	$scheduledAt = dol_now() + (int) $rule->delay_seconds;

	// Build content depending on response type
	$messageType = 'text';
	$content = '';
	$templateName = '';
	if ($rule->response_type === 'template' && !empty($rule->response_template_name)) {
		$messageType = 'template';
		$templateName = $rule->response_template_name;
	} else {
		$content = $rule->response_text;
		// Replace chatbot variables
		$conversation = new WhatsAppConversation($db);
		if ($conversation->fetch($conversationId) > 0) {
			$content = str_replace(
				array('{contact_name}', '{phone}', '{date}', '{time}'),
				array($conversation->contact_name, $phone, dol_print_date(dol_now(), 'day'), dol_print_date(dol_now(), 'hour')),
				$content
			);
		}
	}

	$sql = "INSERT INTO ".MAIN_DB_PREFIX."whatsapp_scheduled (";
	$sql .= "phone_number, message_type, message_content, template_name,";
	$sql .= " scheduled_date, next_execution, status, fk_conversation, fk_line,";
	$sql .= " recurrence_type, entity, date_creation";
	$sql .= ") VALUES (";
	$sql .= "'".$db->escape($phone)."',";
	$sql .= " '".$db->escape($messageType)."',";
	$sql .= " ".(!empty($content) ? "'".$db->escape($content)."'" : "NULL").",";
	$sql .= " ".(!empty($templateName) ? "'".$db->escape($templateName)."'" : "NULL").",";
	$sql .= " '".$db->idate($scheduledAt)."',";
	$sql .= " '".$db->idate($scheduledAt)."',";
	$sql .= " 'pending',";
	$sql .= " ".((int) $conversationId).",";
	$sql .= " ".($lineId > 0 ? ((int) $lineId) : "NULL").",";
	$sql .= " 'once',";
	$sql .= " ".((int) $conf->entity).",";
	$sql .= " '".$db->idate(dol_now())."'";
	$sql .= ")";

	$res = $db->query($sql);
	if (!$res) {
		dol_syslog('WhatsApp Webhook: Failed to queue delayed chatbot response: '.$db->lasterror(), LOG_ERR);
	} else {
		dol_syslog('WhatsApp Webhook: Queued delayed chatbot rule '.$rule->id.' for '.$rule->delay_seconds.'s', LOG_DEBUG);
	}
}

$db->close();
