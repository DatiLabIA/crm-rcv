<?php
/* Copyright (C) 2026 DatiLab <info@datilab.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file ajax/get_fields.php
 * \brief AJAX endpoint to get columns from a database table
 */

// Prevent direct access without Dolibarr context
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) {
    http_response_code(500);
    die(json_encode(array('error' => 'Cannot load Dolibarr')));
}

dol_include_once('/thirdpartyexportlabels/class/exportlabels.class.php');

// Security check
if (!$user->admin) {
    http_response_code(403);
    die(json_encode(array('error' => 'Access denied')));
}

// Get parameters
$table = GETPOST('table', 'alpha');

if (empty($table)) {
    http_response_code(400);
    die(json_encode(array('error' => 'Missing table parameter')));
}

// Get fields
$exportLabels = new ExportLabels($db);
$fields = $exportLabels->getFields($table);

if (empty($fields) && !empty($exportLabels->error)) {
    http_response_code(400);
    die(json_encode(array('error' => $exportLabels->error)));
}

// Return JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($fields);
