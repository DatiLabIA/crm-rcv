<?php
/**
 * Webhook / DB diagnostic — standalone, no Dolibarr framework required.
 * Access: https://TU-DOMINIO/custom/whatsappdati/ajax/wh_diag.php
 * With token test: add ?token=TU_VERIFY_TOKEN&line=1
 */
header('Content-Type: application/json; charset=utf-8');

$out = array(
    'php'         => PHP_VERSION,
    'file'        => __FILE__,
    'dir'         => __DIR__,
    'doc_root'    => $_SERVER['DOCUMENT_ROOT'] ?? 'n/a',
    'script'      => $_SERVER['SCRIPT_FILENAME'] ?? 'n/a',
    'conf_found'  => false,
    'conf_path'   => null,
    'db_ok'       => false,
    'db_error'    => null,
    'config_rows' => array(),
    'token_test'  => null,
    'verdict'     => null,
    'paths_tried' => array(),
);

// ---- find conf.php ----
$dir = __DIR__;
$candidates = array();
for ($i = 0; $i < 6; $i++) {
    $dir = dirname($dir);
    $candidates[] = $dir . '/conf/conf.php';
    $candidates[] = $dir . '/conf.php';
}
foreach (array(
    $_SERVER['DOCUMENT_ROOT'] ?? '',
    $_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '',
) as $dr) {
    if ($dr) {
        $candidates[] = dirname($dr) . '/conf/conf.php';
        $candidates[] = $dr . '/../conf/conf.php';
        $candidates[] = $dr . '/conf/conf.php';
    }
}
$candidates = array_unique($candidates);

foreach ($candidates as $cp) {
    $exists = file_exists($cp);
    $out['paths_tried'][] = $cp . ($exists ? ' [FOUND]' : '');
    if ($exists && !$out['conf_found']) {
        require_once $cp;
        $out['conf_found'] = true;
        $out['conf_path']  = $cp;
        $out['db_host']    = $dolibarr_main_db_host ?? '';
        $out['db_port']    = $dolibarr_main_db_port ?? 3306;
        $out['db_name']    = $dolibarr_main_db_name ?? '';
        $out['db_user']    = $dolibarr_main_db_user ?? '';
        $out['db_prefix']  = $dolibarr_main_db_prefix ?? 'llx_';
    }
}

// ---- connect DB ----
if ($out['conf_found'] && !empty($dolibarr_main_db_host)) {
    $conn = @mysqli_connect(
        $dolibarr_main_db_host,
        $dolibarr_main_db_user,
        $dolibarr_main_db_pass,
        $dolibarr_main_db_name,
        !empty($dolibarr_main_db_port) ? (int)$dolibarr_main_db_port : 3306
    );
    if (!$conn) {
        $out['db_error'] = mysqli_connect_error();
    } else {
        $out['db_ok'] = true;
        $pfx = $dolibarr_main_db_prefix ?? 'llx_';

        // Check table exists
        $tr = @mysqli_query($conn, "SHOW TABLES LIKE '" . $pfx . "whatsapp_config'");
        $out['config_table_exists'] = ($tr && mysqli_num_rows($tr) > 0);

        if ($out['config_table_exists']) {
            $rr = @mysqli_query($conn, "SELECT rowid, label, status, webhook_verify_token FROM " . $pfx . "whatsapp_config ORDER BY rowid");
            if ($rr) {
                while ($row = mysqli_fetch_assoc($rr)) {
                    $out['config_rows'][] = array(
                        'rowid'       => $row['rowid'],
                        'label'       => $row['label'],
                        'status'      => $row['status'],
                        'token_len'   => strlen($row['webhook_verify_token']),
                        'token_value' => $row['webhook_verify_token'],   // shown for debug
                    );
                }
                mysqli_free_result($rr);
            }
        }

        // Token test
        $inputToken = $_GET['token'] ?? '';
        $inputLine  = isset($_GET['line']) ? (int)$_GET['line'] : 0;
        if ($inputToken !== '') {
            $out['token_test'] = array('input' => $inputToken, 'line' => $inputLine, 'match' => false);
            foreach ($out['config_rows'] as $cfgRow) {
                if ($inputLine > 0 && (int)$cfgRow['rowid'] !== $inputLine) continue;
                if (hash_equals($cfgRow['token_value'], $inputToken)) {
                    $out['token_test']['match'] = true;
                    $out['token_test']['matched_rowid'] = $cfgRow['rowid'];
                    break;
                }
            }
            $out['verdict'] = $out['token_test']['match'] ? 'WOULD_PASS ✅' : 'WOULD_FAIL 403 ❌';
        }

        mysqli_close($conn);
    }
}

// Strip token values from output if no test requested (security)
if (empty($_GET['token'])) {
    foreach ($out['config_rows'] as &$r) {
        $r['token_value'] = '(hidden — pass ?token=VALUE to test)';
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
