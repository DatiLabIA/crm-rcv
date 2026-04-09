<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/test_connection.php
 * \ingroup    whatsappdati
 * \brief      AJAX endpoint to test WhatsApp API connection with provided credentials
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
	header('Content-Type: application/json; charset=UTF-8');
	http_response_code(500);
	echo json_encode(array('success' => false, 'error' => 'Error interno: no se pudo cargar el entorno Dolibarr'));
	exit;
}

header('Content-Type: application/json; charset=UTF-8');

// Access control - admin only
if (!$user->admin) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'error' => 'Access denied'));
	exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(array('success' => false, 'error' => 'Method not allowed'));
	exit;
}

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
	// Fallback to POST params
	$input = array(
		'phone_number_id' => GETPOST('phone_number_id', 'alpha'),
		'business_account_id' => GETPOST('business_account_id', 'alpha'),
		'access_token' => GETPOST('access_token', 'alpha'),
		'app_id' => GETPOST('app_id', 'alpha'),
		'line_id' => GETPOSTINT('line_id'),
	);
}

$phoneNumberId = trim($input['phone_number_id'] ?? '');
$businessAccountId = trim($input['business_account_id'] ?? '');
$accessToken = trim($input['access_token'] ?? '');
$appId = trim($input['app_id'] ?? '');
$lineId = (int) ($input['line_id'] ?? 0);

// If access_token is masked, load from existing line
if (($accessToken === '••••••••' || empty($accessToken)) && $lineId > 0) {
	require_once '../class/whatsappconfig.class.php';
	$existingConfig = new WhatsAppConfig($db);
	if ($existingConfig->fetch($lineId) > 0) {
		$accessToken = $existingConfig->access_token;
	}
}

// Validate required fields
$errors = array();
if (empty($phoneNumberId)) {
	$errors[] = 'Phone Number ID es requerido';
}
if (empty($accessToken) || $accessToken === '••••••••') {
	$errors[] = 'Access Token es requerido';
}

if (!empty($errors)) {
	echo json_encode(array('success' => false, 'error' => implode('. ', $errors)));
	exit;
}

$results = array();
$allOk = true;

// ====================================
// Test 1: Verify Phone Number ID
// ====================================
$apiBase = 'https://graph.facebook.com/v25.0';
$url = $apiBase.'/'.$phoneNumberId.'?fields=verified_name,display_phone_number,quality_rating,platform_type';

$test1 = makeTestRequest($url, $accessToken);
if ($test1['success']) {
	$data = $test1['data'];
	$results[] = array(
		'test' => 'phone_number',
		'status' => 'ok',
		'label' => 'Número de Teléfono',
		'detail' => ($data['display_phone_number'] ?? '').($data['verified_name'] ? ' ('.$data['verified_name'].')' : ''),
		'extra' => array(
			'quality' => $data['quality_rating'] ?? 'N/A',
			'platform' => $data['platform_type'] ?? 'N/A',
		),
	);
} else {
	$allOk = false;
	$results[] = array(
		'test' => 'phone_number',
		'status' => 'error',
		'label' => 'Número de Teléfono',
		'detail' => $test1['error'],
	);
}

// ====================================
// Test 2: Verify Business Account ID (if provided)
// ====================================
if (!empty($businessAccountId)) {
	$url2 = $apiBase.'/'.$businessAccountId.'?fields=name,currency,timezone_id,message_template_namespace';
	$test2 = makeTestRequest($url2, $accessToken);
	if ($test2['success']) {
		$data2 = $test2['data'];
		$results[] = array(
			'test' => 'business_account',
			'status' => 'ok',
			'label' => 'Cuenta Business',
			'detail' => $data2['name'] ?? 'OK',
			'extra' => array(
				'currency' => $data2['currency'] ?? 'N/A',
				'namespace' => $data2['message_template_namespace'] ?? 'N/A',
			),
		);
	} else {
		$allOk = false;
		$results[] = array(
			'test' => 'business_account',
			'status' => 'error',
			'label' => 'Cuenta Business',
			'detail' => $test2['error'],
		);
	}
}

// ====================================
// Test 3: Verify App ID (if provided)
// NOTE: Reading app details via /{appId} requires an App Access Token.
// A WhatsApp System User token typically cannot query the /app node.
// This check is non-blocking: failure is reported as a warning only.
// ====================================
if (!empty($appId)) {
	$url3 = $apiBase.'/'.$appId.'?fields=name,category';
	$test3 = makeTestRequest($url3, $accessToken);
	if ($test3['success']) {
		$data3 = $test3['data'];
		$results[] = array(
			'test' => 'app',
			'status' => 'ok',
			'label' => 'Aplicación',
			'detail' => $data3['name'] ?? 'OK',
		);
	} else {
		// App check is a warning only — a System User token cannot read app details.
		// The line can be saved and used even when this check fails.
		$results[] = array(
			'test' => 'app',
			'status' => 'warning',
			'label' => 'Aplicación',
			'detail' => $test3['error'].' — No se puede validar la app con un token de usuario del sistema. Puede guardar la línea igualmente y configurar el webhook en Meta for Developers después.',
		);
	}
}

// ====================================
// Test 4: Test messaging capability
// ====================================
if (!empty($businessAccountId)) {
	$url4 = $apiBase.'/'.$businessAccountId.'/message_templates?limit=1';
} else {
	$url4 = $apiBase.'/'.$phoneNumberId.'?fields=id,display_phone_number';
}
$test4 = makeTestRequest($url4, $accessToken);
if ($test4['success']) {
	$templateCount = count($test4['data']['data'] ?? array());
	$results[] = array(
		'test' => 'messaging',
		'status' => 'ok',
		'label' => 'API de Mensajería',
		'detail' => 'Acceso configurado correctamente',
	);
} else {
	// Not critical - may not have template permissions
	$results[] = array(
		'test' => 'messaging',
		'status' => 'warning',
		'label' => 'API de Mensajería',
		'detail' => 'Acceso limitado: '.$test4['error'],
	);
}

echo json_encode(array(
	'success' => $allOk,
	'results' => $results,
));

/**
 * Make a test GET request to Meta Graph API
 *
 * @param  string $url         API URL
 * @param  string $accessToken Bearer token
 * @return array               Result array with success/error
 */
function makeTestRequest($url, $accessToken)
{
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_HTTPHEADER => array(
			'Authorization: Bearer '.$accessToken,
			'Content-Type: application/json',
		),
	));

	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($curlError) {
		return array('success' => false, 'error' => 'Error de conexión: '.$curlError);
	}

	$data = json_decode($response, true);

	if ($httpCode >= 200 && $httpCode < 300) {
		return array('success' => true, 'data' => $data);
	}

	$errorMsg = $data['error']['message'] ?? ('HTTP '.$httpCode);
	$errorCode = $data['error']['code'] ?? '';
	if ($errorCode) {
		$errorMsg .= ' (code: '.$errorCode.')';
	}

	return array('success' => false, 'error' => $errorMsg);
}
