<?php
/**
 * WhatsApp Module Diagnostic Page
 * Upload this to custom/whatsappdati/diag.php and open in browser.
 * DELETE after use.
 */

header('Content-Type: text/html; charset=utf-8');
echo '<html><head><title>WhatsApp Diagnostics</title>';
echo '<style>body{font-family:monospace;padding:20px;} .ok{color:green;} .err{color:red;} .warn{color:orange;} pre{background:#f5f5f5;padding:10px;overflow-x:auto;}</style>';
echo '</head><body>';
echo '<h2>WhatsApp Module Diagnostics</h2>';

$checks = array();

// 1. Test main.inc.php loading
echo '<h3>1. Dolibarr Core</h3>';
$t0 = microtime(true);
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
$t1 = microtime(true);
if (!$res) {
	echo '<p class="err">FAIL: Include of main.inc.php failed</p>';
	echo '</body></html>';
	exit;
}
echo '<p class="ok">OK: main.inc.php loaded in '.round(($t1-$t0)*1000).'ms</p>';

// 2. Test DB connection
echo '<h3>2. Database</h3>';
$t0 = microtime(true);
$dbres = $db->query("SELECT 1 AS test");
$t1 = microtime(true);
if ($dbres) {
	echo '<p class="ok">OK: DB connection works ('.round(($t1-$t0)*1000).'ms)</p>';
} else {
	echo '<p class="err">FAIL: DB query failed: '.$db->lasterror().'</p>';
}

// 3. Test whatsapp tables
echo '<h3>3. WhatsApp Tables</h3>';
$tables = array('llx_whatsapp_conversations', 'llx_whatsapp_messages', 'llx_whatsapp_config');
foreach ($tables as $tbl) {
	$t0 = microtime(true);
	$res = $db->query("SELECT COUNT(*) AS cnt FROM ".$tbl);
	$t1 = microtime(true);
	if ($res) {
		$obj = $db->fetch_object($res);
		echo '<p class="ok">OK: '.$tbl.' — '.$obj->cnt.' rows ('.round(($t1-$t0)*1000).'ms)</p>';
	} else {
		echo '<p class="err">FAIL: '.$tbl.' — '.$db->lasterror().'</p>';
	}
}

// 4. Test class loading
echo '<h3>4. Class Files</h3>';
$classes = array(
	'./class/whatsappconversation.class.php' => 'WhatsAppConversation',
	'./class/whatsappmessage.class.php'      => 'WhatsAppMessage',
	'./class/whatsappconfig.class.php'       => 'WhatsAppConfig',
);
foreach ($classes as $file => $class) {
	if (file_exists($file)) {
		require_once $file;
		if (class_exists($class)) {
			echo '<p class="ok">OK: '.$class.' loaded from '.$file.'</p>';
		} else {
			echo '<p class="err">FAIL: File exists but class '.$class.' not defined</p>';
		}
	} else {
		echo '<p class="err">FAIL: File not found: '.$file.'</p>';
	}
}

// 5. Test conversations AJAX endpoint
echo '<h3>5. Conversations Query (same as AJAX endpoint)</h3>';
$t0 = microtime(true);
$db->query("SET NAMES 'utf8mb4'");
$sql = "SELECT c.rowid, c.phone_number, c.contact_name, c.last_message_date, c.unread_count";
$sql .= " FROM ".MAIN_DB_PREFIX."whatsappdati_conversation AS c";
$sql .= " ORDER BY c.last_message_date DESC";
$sql .= " LIMIT 5";
$res = $db->query($sql);
$t1 = microtime(true);
if ($res) {
	$count = $db->num_rows($res);
	echo '<p class="ok">OK: Conversations query returned '.$count.' rows in '.round(($t1-$t0)*1000).'ms</p>';
} else {
	echo '<p class="err">FAIL: '.$db->lasterror().'</p>';
}

// 6. Test file sizes on server vs expected
echo '<h3>6. Key File Sizes (check against local)</h3>';
$files = array(
	'js/whatsappdati.js',
	'js/whatsapp_widget.js',
	'css/whatsappdati.css',
	'ajax/messages.php',
	'ajax/send_message.php',
	'webhook.php',
	'conversations.php',
	'ajax/conversations.php',
	'ajax/widget.php',
);
echo '<pre>';
foreach ($files as $f) {
	if (file_exists($f)) {
		$size = filesize($f);
		$lines = count(file($f));
		echo str_pad($f, 35).' | '.str_pad($size.' bytes', 15).' | '.$lines." lines\n";
	} else {
		echo str_pad($f, 35)." | FILE NOT FOUND\n";
	}
}
echo '</pre>';

// 7. Test session status
echo '<h3>7. Session & PHP Info</h3>';
echo '<p>PHP version: '.phpversion().'</p>';
echo '<p>Session status: '.session_status().' (1=disabled, 2=active, 0=none)</p>';
echo '<p>Session ID: '.session_id().'</p>';
echo '<p>Memory usage: '.round(memory_get_usage()/1024/1024, 2).'MB</p>';
echo '<p>Max execution time: '.ini_get('max_execution_time').'s</p>';

// 8. Check module enable status
echo '<h3>8. Module Status</h3>';
echo '<p>whatsappdati enabled: '.(!empty($conf->whatsappdati->enabled) ? '<span class="ok">YES</span>' : '<span class="err">NO</span>').'</p>';
echo '<p>User has conversation read: '.(!empty($user->rights->whatsappdati->conversation->read) ? '<span class="ok">YES</span>' : '<span class="err">NO</span>').'</p>';
echo '<p>Realtime mode: '.getDolGlobalString('WHATSAPPDATI_REALTIME_MODE', 'polling').'</p>';

// 9. Test AJAX endpoints individually
echo '<h3>9. AJAX Endpoint Test URLs</h3>';
$base = dol_buildpath('/custom/whatsappdati/', 1);
echo '<p>Open these in a new tab to test if they respond:</p>';
echo '<ul>';
echo '<li><a href="'.$base.'ajax/conversations.php" target="_blank">conversations.php</a> (should return JSON)</li>';
echo '<li><a href="'.$base.'ajax/widget.php?action=unread_count" target="_blank">widget.php?action=unread_count</a> (should return JSON)</li>';
echo '</ul>';

// 10. PHP error log (last 10 lines)
echo '<h3>10. Recent PHP Errors (if readable)</h3>';
$errorLog = ini_get('error_log');
echo '<p>Error log path: '.($errorLog ?: 'default').'</p>';
if ($errorLog && file_exists($errorLog) && is_readable($errorLog)) {
	$lines = file($errorLog);
	$lastLines = array_slice($lines, -15);
	// Filter for whatsapp-related entries only
	$waLines = array_filter($lastLines, function($l) {
		return stripos($l, 'whatsapp') !== false || stripos($l, 'Fatal') !== false || stripos($l, 'Parse error') !== false;
	});
	if ($waLines) {
		echo '<pre>'.htmlspecialchars(implode('', $waLines)).'</pre>';
	} else {
		echo '<p>No WhatsApp-related errors in last 15 log lines.</p>';
	}
} else {
	echo '<p class="warn">Cannot read error log file.</p>';
}

echo '<hr><p><em>Delete this file after diagnosis!</em></p>';
echo '</body></html>';
$db->close();
