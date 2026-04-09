<?php
/**
 * Diagnostic endpoint - REMOVE AFTER DEBUGGING
 * Tests: main.inc.php loading, DB connection, config table, conversations, messages
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');

header('Content-Type: application/json; charset=utf-8');

$diag = array('timestamp' => date('Y-m-d H:i:s'), 'checks' => array());

// 1. Load Dolibarr
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
	$diag['checks']['dolibarr_load'] = 'FAIL - Include of main fails';
	echo json_encode($diag, JSON_PRETTY_PRINT);
	exit;
}

$diag['checks']['dolibarr_load'] = 'OK';
$diag['checks']['entity'] = $conf->entity;
$diag['checks']['user_id'] = !empty($user->id) ? $user->id : 'NOT_LOGGED_IN';
$diag['checks']['db_type'] = $db->type;
$diag['checks']['db_prefix'] = MAIN_DB_PREFIX;

// 2. Check whatsapp_config table
$sql = "SELECT rowid, label, phone_number_id, business_account_id, access_token, app_secret, status, entity FROM ".MAIN_DB_PREFIX."whatsapp_config ORDER BY rowid";
$resql = $db->query($sql);
$configs = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$configs[] = array(
			'rowid' => $obj->rowid,
			'label' => $obj->label,
			'phone_number_id' => substr($obj->phone_number_id, 0, 6).'...',
			'status' => $obj->status,
			'entity' => $obj->entity,
			'has_access_token' => !empty($obj->access_token) ? 'yes ('.strlen($obj->access_token).' chars)' : 'NO',
			'has_app_secret' => !empty($obj->app_secret) ? 'yes' : 'NO',
		);
	}
	$diag['checks']['config_table'] = 'OK - '.count($configs).' rows';
	$diag['configs'] = $configs;
} else {
	$diag['checks']['config_table'] = 'FAIL - '.$db->lasterror();
}

// 3. Check conversations table
$sql2 = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."whatsapp_conversations";
$resql2 = $db->query($sql2);
if ($resql2) {
	$obj2 = $db->fetch_object($resql2);
	$diag['checks']['conversations_count'] = (int) $obj2->cnt;
} else {
	$diag['checks']['conversations_table'] = 'FAIL - '.$db->lasterror();
}

// 4. Check messages table
$sql3 = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."whatsapp_messages";
$resql3 = $db->query($sql3);
if ($resql3) {
	$obj3 = $db->fetch_object($resql3);
	$diag['checks']['messages_count'] = (int) $obj3->cnt;
} else {
	$diag['checks']['messages_table'] = 'FAIL - '.$db->lasterror();
}

// 5. Check templates table
$sql4 = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."whatsapp_templates";
$resql4 = $db->query($sql4);
if ($resql4) {
	$obj4 = $db->fetch_object($resql4);
	$diag['checks']['templates_count'] = (int) $obj4->cnt;
} else {
	$diag['checks']['templates_table'] = 'FAIL - '.$db->lasterror();
}

// 6. Test fetchActiveLines
require_once dirname(__FILE__).'/../class/whatsappconfig.class.php';
$configObj = new WhatsAppConfig($db);
$activeLines = $configObj->fetchActiveLines();
$diag['checks']['active_lines'] = count($activeLines);
$diag['checks']['active_lines_ids'] = array_map(function($l) { return $l->id; }, $activeLines);

// 7. Check getEntity result
$diag['checks']['getEntity_result'] = getEntity('whatsappconfig');

// 8. Check if event/assignment/chatbot classes accessible
$classFiles = array(
	'whatsappconversation' => dirname(__FILE__).'/../class/whatsappconversation.class.php',
	'whatsappmessage' => dirname(__FILE__).'/../class/whatsappmessage.class.php',
	'whatsappmanager' => dirname(__FILE__).'/../class/whatsappmanager.class.php',
	'whatsappassignment' => dirname(__FILE__).'/../class/whatsappassignment.class.php',
	'whatsappevent' => dirname(__FILE__).'/../class/whatsappevent.class.php',
	'whatsappchatbot' => dirname(__FILE__).'/../class/whatsappchatbot.class.php',
	'whatsappcsat' => dirname(__FILE__).'/../class/whatsappcsat.class.php',
);
foreach ($classFiles as $name => $path) {
	$diag['checks']['class_'.$name] = file_exists($path) ? 'EXISTS' : 'MISSING at '.$path;
}

// 9. Check tables exist
$tables = array('whatsapp_config', 'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_templates',
	'whatsapp_tags', 'whatsapp_conversation_tags', 'whatsapp_quick_replies', 'whatsapp_events',
	'whatsapp_chatbot_rules', 'whatsapp_scheduled_messages', 'whatsapp_csat', 'whatsapp_agent_assignment');
foreach ($tables as $tbl) {
	$sqlChk = "SHOW TABLES LIKE '".MAIN_DB_PREFIX.$tbl."'";
	$resChk = $db->query($sqlChk);
	if ($resChk && $db->num_rows($resChk) > 0) {
		$diag['checks']['table_'.$tbl] = 'EXISTS';
	} else {
		$diag['checks']['table_'.$tbl] = 'MISSING';
	}
}

// 10. Webhook URL check
$diag['checks']['webhook_cwd'] = getcwd();
$diag['checks']['webhook_dir'] = __DIR__;
$diag['checks']['script_filename'] = $_SERVER['SCRIPT_FILENAME'] ?? 'not set';
$diag['checks']['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'not set';
$diag['checks']['context_document_root'] = $_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? 'not set';

// 11. Check Dolibarr syslog path
$diag['checks']['syslog_file'] = !empty($conf->global->SYSLOG_FILE) ? $conf->global->SYSLOG_FILE : 'not configured';
$diag['checks']['dolibarr_data_root'] = DOL_DATA_ROOT;

// 12. Check module dir_temp (critical for WhatsAppEvent)
$diag['checks']['module_dir_temp'] = !empty($conf->whatsappdati->dir_temp) ? $conf->whatsappdati->dir_temp : 'NOT SET';
$diag['checks']['module_dir_output'] = !empty($conf->whatsappdati->dir_output) ? $conf->whatsappdati->dir_output : 'NOT SET';

// 13. Check if module directories exist on disk
if (!empty($conf->whatsappdati->dir_temp)) {
	$diag['checks']['dir_temp_exists'] = is_dir($conf->whatsappdati->dir_temp) ? 'YES' : 'NO';
	$eventsDir = $conf->whatsappdati->dir_temp . '/events/' . $conf->entity;
	$diag['checks']['events_dir_exists'] = is_dir($eventsDir) ? 'YES' : 'NO';
}

// 14. Check PHP error log for recent WhatsApp entries
$phpErrorLog = ini_get('error_log');
$diag['checks']['php_error_log'] = !empty($phpErrorLog) ? $phpErrorLog : 'default (syslog)';
if (!empty($phpErrorLog) && file_exists($phpErrorLog) && is_readable($phpErrorLog)) {
	// Read last 50 lines and filter for WhatsApp
	$lines = array();
	$fp = fopen($phpErrorLog, 'r');
	if ($fp) {
		// Seek to end minus 50KB
		fseek($fp, max(0, filesize($phpErrorLog) - 51200));
		fgets($fp); // discard partial line
		while (!feof($fp)) {
			$line = fgets($fp);
			if ($line !== false && stripos($line, 'whatsapp') !== false) {
				$lines[] = trim($line);
			}
		}
		fclose($fp);
	}
	$diag['checks']['recent_whatsapp_errors'] = array_slice($lines, -10); // Last 10 entries
}

// 15. Check Dolibarr log for WhatsApp entries
if (!empty($conf->global->SYSLOG_FILE)) {
	$syslogPath = str_replace('DOL_DATA_ROOT', DOL_DATA_ROOT, $conf->global->SYSLOG_FILE);
	$diag['checks']['syslog_real_path'] = $syslogPath;
	if (file_exists($syslogPath) && is_readable($syslogPath)) {
		$lines = array();
		$fp = fopen($syslogPath, 'r');
		if ($fp) {
			fseek($fp, max(0, filesize($syslogPath) - 102400));
			fgets($fp);
			while (!feof($fp)) {
				$line = fgets($fp);
				if ($line !== false && stripos($line, 'whatsapp') !== false) {
					$lines[] = trim($line);
				}
			}
			fclose($fp);
		}
		$diag['checks']['recent_dolibarr_whatsapp_logs'] = array_slice($lines, -15);
	} else {
		$diag['checks']['syslog_readable'] = 'NO or does not exist at '.$syslogPath;
	}
}

// 16. Check WHATSAPPDATI_REALTIME_MODE config
$diag['checks']['realtime_mode'] = !empty($conf->global->WHATSAPPDATI_REALTIME_MODE) ? $conf->global->WHATSAPPDATI_REALTIME_MODE : 'NOT SET (default polling)';

// 18. Read webhook debug log (file-based, written by webhook.php)
$webhookLogFile = DOL_DATA_ROOT . '/whatsappdati/temp/webhook_debug.log';
if (file_exists($webhookLogFile) && is_readable($webhookLogFile)) {
	$logSize = filesize($webhookLogFile);
	$diag['checks']['webhook_debug_log_size'] = $logSize . ' bytes';
	// Read last 8KB
	$fp = fopen($webhookLogFile, 'r');
	if ($fp) {
		fseek($fp, max(0, $logSize - 8192));
		if ($logSize > 8192) fgets($fp); // discard partial line
		$lines = array();
		while (!feof($fp)) {
			$line = fgets($fp);
			if ($line !== false) $lines[] = rtrim($line);
		}
		fclose($fp);
		$diag['webhook_debug_log'] = array_slice($lines, -30); // Last 30 lines
	}
} else {
	$diag['checks']['webhook_debug_log'] = 'NO FILE at ' . $webhookLogFile;
}

// 17. Config line detail (without sensitive data)
if (!empty($configs)) {
	$configDetail = new WhatsAppConfig($db);
	if ($configDetail->fetch($configs[0]['rowid']) > 0) {
		$diag['config_line_id'] = $configDetail->id;
		$diag['config_line_status'] = $configDetail->status;
		$diag['config_line_phone_number_id_set'] = !empty($configDetail->phone_number_id) ? 'YES ('.strlen($configDetail->phone_number_id).' chars)' : 'NO';
		$diag['config_line_webhook_verify_token_set'] = !empty($configDetail->webhook_verify_token) ? 'YES' : 'NO';
		$diag['config_line_access_token_set'] = !empty($configDetail->access_token) ? 'YES ('.strlen($configDetail->access_token).' chars)' : 'NO';
		$diag['config_line_app_secret_set'] = !empty($configDetail->app_secret) ? 'YES ('.strlen($configDetail->app_secret).' chars)' : 'NO';
		$diag['config_line_country_code'] = $configDetail->country_code;
		$diag['config_line_webhook_url'] = $configDetail->webhook_url;
	}
}

// 19. Read ultra-early webhook log (pre-Dolibarr)
$earlyLogFile = DOL_DATA_ROOT . '/whatsappdati/temp/webhook_early.log';
if (file_exists($earlyLogFile) && is_readable($earlyLogFile)) {
	$logSize = filesize($earlyLogFile);
	$diag['checks']['webhook_early_log_size'] = $logSize . ' bytes';
	$fp = fopen($earlyLogFile, 'r');
	if ($fp) {
		fseek($fp, max(0, $logSize - 4096));
		if ($logSize > 4096) fgets($fp);
		$lines = array();
		while (!feof($fp)) {
			$line = fgets($fp);
			if ($line !== false) $lines[] = rtrim($line);
		}
		fclose($fp);
		$diag['webhook_early_log'] = array_slice($lines, -15);
	}
} else {
	$diag['checks']['webhook_early_log'] = 'NO FILE - Meta has NEVER sent a POST to this webhook';
}

// 20. Show configured webhook URL for verification
$diag['checks']['expected_webhook_url'] = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https')
	. '://' . ($_SERVER['HTTP_HOST'] ?? 'unknown')
	. str_replace('/ajax/diagnose.php', '/webhook.php', $_SERVER['SCRIPT_NAME']);

// 21. MySQL connection charset (critical for emojis)
$resCharset = $db->query("SHOW VARIABLES LIKE 'character_set_connection'");
if ($resCharset) {
	$objCharset = $db->fetch_object($resCharset);
	$diag['checks']['mysql_connection_charset'] = $objCharset ? $objCharset->Value : 'UNKNOWN';
} else {
	$diag['checks']['mysql_connection_charset'] = 'QUERY_FAILED';
}
$resCharsetClient = $db->query("SHOW VARIABLES LIKE 'character_set_client'");
if ($resCharsetClient) {
	$objCc = $db->fetch_object($resCharsetClient);
	$diag['checks']['mysql_client_charset'] = $objCc ? $objCc->Value : 'UNKNOWN';
}
$resCharsetResults = $db->query("SHOW VARIABLES LIKE 'character_set_results'");
if ($resCharsetResults) {
	$objCr = $db->fetch_object($resCharsetResults);
	$diag['checks']['mysql_results_charset'] = $objCr ? $objCr->Value : 'UNKNOWN';
}

// 22. Media diagnostics - check last 5 outbound media messages
$sqlMedia = "SELECT t.rowid, t.direction, t.message_type, t.media_url, t.media_mime_type,";
$sqlMedia .= " t.media_filename, t.media_local_path, t.status, t.fk_conversation";
$sqlMedia .= " FROM ".MAIN_DB_PREFIX."whatsapp_messages as t";
$sqlMedia .= " WHERE t.message_type IN ('image','video','audio','document')";
$sqlMedia .= " ORDER BY t.rowid DESC LIMIT 10";
$resMedia = $db->query($sqlMedia);
$mediaMessages = array();
if ($resMedia) {
	while ($objM = $db->fetch_object($resMedia)) {
		$mediaInfo = array(
			'rowid' => $objM->rowid,
			'direction' => $objM->direction,
			'message_type' => $objM->message_type,
			'media_mime_type' => $objM->media_mime_type,
			'media_filename' => $objM->media_filename,
			'has_media_url' => !empty($objM->media_url) ? 'YES ('.strlen($objM->media_url).' chars)' : 'NO',
			'media_local_path' => $objM->media_local_path,
			'local_path_exists' => !empty($objM->media_local_path) ? (file_exists($objM->media_local_path) ? 'YES' : 'NO') : 'N/A',
			'status' => $objM->status,
			'fk_conversation' => $objM->fk_conversation,
		);
		// If local path doesn't exist, try reconstructing
		if (!empty($objM->media_local_path) && !file_exists($objM->media_local_path)) {
			$basename = basename($objM->media_local_path);
			$altPath = $conf->whatsappdati->dir_output.'/media/'.$objM->fk_conversation.'/'.$basename;
			$mediaInfo['alt_path'] = $altPath;
			$mediaInfo['alt_path_exists'] = file_exists($altPath) ? 'YES' : 'NO';
		}
		$mediaMessages[] = $mediaInfo;
	}
}
$diag['media_messages'] = $mediaMessages;
$diag['checks']['media_dir_output'] = !empty($conf->whatsappdati->dir_output) ? $conf->whatsappdati->dir_output : 'NOT SET';
$mediaDir = $conf->whatsappdati->dir_output.'/media';
$diag['checks']['media_dir_exists'] = is_dir($mediaDir) ? 'YES' : 'NO';
if (is_dir($mediaDir)) {
	$diag['checks']['media_dir_writable'] = is_writable($mediaDir) ? 'YES' : 'NO';
	// List subdirectories (conversation folders)
	$subDirs = glob($mediaDir.'/*', GLOB_ONLYDIR);
	$diag['checks']['media_subdir_count'] = count($subDirs);
}

// 23. Test dol_buildpath for media URL
$diag['checks']['media_url_buildpath'] = dol_buildpath('/custom/whatsappdati/ajax/media.php', 1);
$diag['checks']['media_url_fallback'] = DOL_URL_ROOT.'/custom/whatsappdati/ajax/media.php';

// 24. Emoji storage test - write and read back an emoji
$testEmoji = '👋🎉😀';
$diag['checks']['emoji_test_input'] = $testEmoji;
$diag['checks']['emoji_test_input_bytes'] = strlen($testEmoji);
$sqlEmojiTest = "SELECT '".$db->escape($testEmoji)."' as emoji_result";
$resEmoji = $db->query($sqlEmojiTest);
if ($resEmoji) {
	$objE = $db->fetch_object($resEmoji);
	$diag['checks']['emoji_test_output'] = $objE->emoji_result;
	$diag['checks']['emoji_test_output_bytes'] = strlen($objE->emoji_result);
	$diag['checks']['emoji_test_pass'] = ($objE->emoji_result === $testEmoji) ? 'YES' : 'NO - emojis corrupted!';
} else {
	$diag['checks']['emoji_test'] = 'QUERY_FAILED - '.$db->lasterror();
}
// Now test with SET NAMES utf8mb4
$db->query("SET NAMES 'utf8mb4'");
$resEmoji2 = $db->query($sqlEmojiTest);
if ($resEmoji2) {
	$objE2 = $db->fetch_object($resEmoji2);
	$diag['checks']['emoji_test_after_utf8mb4'] = $objE2->emoji_result;
	$diag['checks']['emoji_test_after_utf8mb4_pass'] = ($objE2->emoji_result === $testEmoji) ? 'YES' : 'NO - still corrupted!';
}

// 25. Webhook verification test
// Usage: add ?wh_token=TU_VERIFY_TOKEN&wh_line=1 to test what Meta would get
$whToken = isset($_GET['wh_token']) ? $_GET['wh_token'] : '';
$whLine  = isset($_GET['wh_line'])  ? (int)$_GET['wh_line'] : 0;
$whTest  = array();

$sqlWh = "SELECT rowid, label, status, webhook_verify_token, webhook_url FROM ".MAIN_DB_PREFIX."whatsapp_config ORDER BY rowid";
$resWh = $db->query($sqlWh);
if ($resWh) {
	while ($objWh = $db->fetch_object($resWh)) {
		$storedToken = $objWh->webhook_verify_token;
		$match = ($whToken !== '') ? hash_equals((string)$storedToken, (string)$whToken) : null;
		$whTest[] = array(
			'rowid'        => $objWh->rowid,
			'label'        => $objWh->label,
			'status'       => $objWh->status,
			'webhook_url'  => $objWh->webhook_url,
			'token_len'    => strlen($storedToken),
			'token_value'  => $storedToken,
			'match_input'  => $match,
		);
	}
	$db->free($resWh);
}
$diag['webhook_lines'] = $whTest;

if ($whToken !== '') {
	$anyPass = false;
	foreach ($whTest as $wl) {
		if ($wl['match_input'] === true && ($whLine === 0 || (int)$wl['rowid'] === $whLine)) {
			$anyPass = true;
			break;
		}
	}
	$diag['webhook_verdict'] = $anyPass ? 'WOULD_PASS - webhook verification OK' : 'WOULD_FAIL_403 - token does not match any line';
} else {
	$diag['webhook_verdict'] = 'No token provided. Add ?wh_token=YOUR_VERIFY_TOKEN&wh_line=LINE_ID to test';
}

// Simulate the exact URL Meta would call
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$diag['webhook_test_url'] = $scheme.'://'.$host.dol_buildpath('/custom/whatsappdati/webhook.php', 1);

echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$db->close();
