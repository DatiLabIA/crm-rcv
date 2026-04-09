<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * AJAX endpoint: search product lot/serial numbers for the serial_batch autocomplete.
 *
 * Actions:
 *   action=search  → returns lots matching `term`, optionally filtered by `product_id`
 *   action=resolve → resolves a `supplier_price_id` to its `product_id`
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(403);
	exit('Forbidden');
}

// Require authenticated user
if (empty($user->id)) {
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode(array('error' => 'Not authenticated')));
}

header('Content-Type: application/json; charset=utf-8');

$action = GETPOST('action', 'aZ09');

// -------------------------------------------------------------------------
// Mode: resolve supplier price ID → product ID
// Used by JS after product selection from select_produits_fournisseurs
// -------------------------------------------------------------------------
if ($action === 'resolve') {
	$supplierPriceId = GETPOSTINT('supplier_price_id');
	if ($supplierPriceId <= 0) {
		echo json_encode(array('product_id' => 0));
		exit;
	}

	$sql = "SELECT fk_product FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sql .= " WHERE rowid = ".(int) $supplierPriceId;

	$resql = $db->query($sql);
	$productId = 0;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$productId = (int) $obj->fk_product;
		}
	}
	echo json_encode(array('product_id' => $productId));
	exit;
}

// -------------------------------------------------------------------------
// Mode: search lots by term (for jQuery UI autocomplete)
// Optional product_id filter narrows results to that product's lots
// -------------------------------------------------------------------------
if ($action === 'search') {
	$term = GETPOST('term', 'alphanohtml');
	$productId = GETPOSTINT('product_id');

	$sql = "SELECT DISTINCT pl.batch AS value, pl.batch AS label";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_lot pl";
	$sql .= " WHERE pl.entity IN (".getEntity('product').")";

	if ($productId > 0) {
		$sql .= " AND pl.fk_product = ".(int) $productId;
	}
	if (!empty($term)) {
		$sql .= " AND pl.batch LIKE '%".$db->escape($term)."%'";
	}

	$sql .= " ORDER BY pl.batch ASC LIMIT 50";

	$resql = $db->query($sql);
	$lots = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$lots[] = array('value' => $obj->value, 'label' => $obj->label);
		}
	}
	echo json_encode($lots);
	exit;
}

// Unknown action
echo json_encode(array());
exit;
