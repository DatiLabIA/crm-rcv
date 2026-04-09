<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       lib/whatsappdati_ajax.lib.php
 * \ingroup    whatsappdati
 * \brief      Shared helpers for AJAX endpoints (CSRF validation, etc.)
 */

/**
 * Validate the CSRF token sent by the client against the session token.
 * Must be called on every AJAX mutation (POST, PUT, DELETE) AFTER main.inc.php is loaded.
 *
 * When NOTOKENRENEWAL is defined (which all AJAX endpoints use), Dolibarr keeps
 * the same session token for the lifetime of the session instead of rotating it
 * on each request. This function verifies the client-submitted token matches.
 *
 * The token can arrive via:
 *   - GET/POST parameter "token"
 *   - JSON body field "token"
 *   - HTTP header "X-CSRF-Token"
 *
 * @param string $jsonInput  Optional raw JSON body (pass when you already read php://input)
 * @return void              Exits with HTTP 403 JSON response on failure
 */
function whatsappdatiCheckCSRFToken($jsonInput = '')
{
	$sessionToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '';
	if (empty($sessionToken)) {
		// Fallback for Dolibarr versions that use 'token'
		$sessionToken = isset($_SESSION['token']) ? $_SESSION['token'] : '';
	}

	// 1. Try GET/POST parameter
	$submittedToken = GETPOST('token', 'alpha');

	// 2. Try JSON body
	if (empty($submittedToken) && !empty($jsonInput)) {
		$decoded = json_decode($jsonInput, true);
		if (is_array($decoded) && !empty($decoded['token'])) {
			$submittedToken = $decoded['token'];
		}
	}

	// 3. Try HTTP header
	if (empty($submittedToken)) {
		$submittedToken = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
	}

	// Validate
	if (empty($sessionToken) || empty($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
		http_response_code(403);
		header('Content-Type: application/json');
		echo json_encode(array('success' => false, 'error' => 'Invalid or missing CSRF token'));
		exit;
	}
}

/**
 * Force utf8mb4 charset on the database connection.
 * Required so that 4-byte Unicode characters (emojis) are stored and
 * retrieved correctly even when Dolibarr's global charset is utf8 (3-byte).
 *
 * Call this once per request, right after main.inc.php is loaded.
 *
 * @param  DoliDB $db  Database handler
 * @return void
 */
function whatsappdatiForceUtf8mb4($db)
{
	// Only needed for MySQL/MariaDB
	if ($db->type === 'mysqli' || $db->type === 'mysql') {
		$db->query("SET NAMES 'utf8mb4'");
	}
}
