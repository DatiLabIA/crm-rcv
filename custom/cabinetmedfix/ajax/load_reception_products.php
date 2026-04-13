<?php
/**
 * AJAX endpoint: Load products from selected receptions into a draft sales order.
 *
 * POST params:
 *   token           string   Session CSRF token
 *   order_id        int      Draft commande rowid
 *   reception_ids[] int[]    Reception rowids to load products from
 *
 * Returns JSON: {success: true, added: N} | {error: "message"}
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Do not rotate session token on AJAX calls
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
	header('Content-Type: application/json');
	die(json_encode(array('error' => 'Bootstrap failed')));
}

if (empty($user->id)) {
	http_response_code(403);
	header('Content-Type: application/json; charset=utf-8');
	die(json_encode(array('error' => 'Not authenticated')));
}

header('Content-Type: application/json; charset=utf-8');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	die(json_encode(array('error' => 'Method not allowed')));
}

// Require permission to edit orders
if (!$user->hasRight('commande', 'creer')) {
	http_response_code(403);
	die(json_encode(array('error' => 'Sin permisos suficientes')));
}

$orderId = GETPOSTINT('order_id');
$rawIds  = isset($_POST['reception_ids']) ? $_POST['reception_ids'] : array();
$receptionIds = array_values(array_filter(array_map('intval', (array) $rawIds)));

if ($orderId <= 0 || empty($receptionIds)) {
	die(json_encode(array('error' => 'Parámetros inválidos')));
}

// Load and validate the order
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
$order = new Commande($db);
if ($order->fetch($orderId) <= 0) {
	die(json_encode(array('error' => 'Orden no encontrada')));
}
if ((int) $order->statut !== Commande::STATUS_DRAFT) {
	die(json_encode(array('error' => 'La orden no está en borrador')));
}

$patientId = (int) $order->socid;

// Build safe IN clause — IDs are already cast to int
$safeIds = implode(',', $receptionIds);

// Index existing order lines by product id (to sum qty if product already present)
$order->fetch_lines();
$existingLines = array(); // fk_product => line object
foreach ($order->lines as $line) {
	if (!empty($line->fk_product)) {
		$existingLines[(int) $line->fk_product] = $line;
	}
}

// Sum quantities per product from receptions that belong to this patient
$sqlLines  = "SELECT rb.fk_product, SUM(rb.qty) AS total_qty";
$sqlLines .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch rb";
$sqlLines .= " INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rb.fk_reception";
$sqlLines .= " WHERE rb.fk_reception IN (".$safeIds.")";
$sqlLines .= " AND r.fk_soc = ".$patientId;
$sqlLines .= " AND r.fk_statut > 0";
$sqlLines .= " AND rb.fk_product > 0";
$sqlLines .= " AND r.entity IN (".getEntity('reception').")";
$sqlLines .= " GROUP BY rb.fk_product";

$resLines = $db->query($sqlLines);
if (!$resLines) {
	die(json_encode(array('error' => 'Error de base de datos: '.$db->lasterror())));
}

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

// Log para diagnóstico
dol_syslog('load_reception_products: order_id='.$orderId.' receptionIds='.implode(',', $receptionIds).' patientId='.$patientId, LOG_DEBUG);

$numProducts = $db->num_rows($resLines);
dol_syslog('load_reception_products: productos encontrados en recepciones='.$numProducts, LOG_DEBUG);

if ($numProducts === 0) {
	die(json_encode(array('error' => 'No se encontraron productos en las recepciones seleccionadas para este paciente (socid='.$patientId.'). Verifica que las recepciones tengan productos y pertenezcan al mismo paciente de la orden.')));
}

$added  = 0;
$errors = array();

while ($obj = $db->fetch_object($resLines)) {
	$productId = (int) $obj->fk_product;
	$qty       = (float) $obj->total_qty;

	if ($qty <= 0) {
		continue;
	}

	// If product already in order, add qty to existing line instead of creating a new one
	if (isset($existingLines[$productId])) {
		$existingLine = $existingLines[$productId];
		$newQty       = (float) $existingLine->qty + $qty;
		$result = $order->updateline(
			$existingLine->rowid,
			$existingLine->desc,
			$existingLine->pu_ht,
			$newQty,
			$existingLine->remise_percent,
			$existingLine->tva_tx,
			$existingLine->localtax1_tx,
			$existingLine->localtax2_tx,
			'HT',
			$existingLine->info_bits,
			$existingLine->date_start,
			$existingLine->date_end,
			$existingLine->product_type
		);
		if ($result >= 0) {
			$added++;
			$existingLines[$productId]->qty = $newQty;
		} else {
			$errors[] = $existingLine->ref;
		}
		continue;
	}

	$product = new Product($db);
	if ($product->fetch($productId) <= 0) {
		continue;
	}

	$result = $order->addline(
		$product->label,  // desc
		0,                // pu_ht — precio 0, editable después
		$qty,             // qty
		0,                // txtva
		0,                // txlocaltax1
		0,                // txlocaltax2
		$productId,       // fk_product
		0,                // remise_percent
		0,                // info_bits
		0,                // fk_remise_except
		'HT',             // price_base_type
		0,                // pu_ttc
		'',               // date_start
		'',               // date_end
		$product->type    // type
	);

	if ($result > 0) {
		$added++;
		$existingLines[$productId] = (object) array('rowid' => $result, 'qty' => $qty);
	} else {
		$errors[] = $product->ref;
	}
}
$db->free($resLines);

// Link selected receptions to the order in element_element
require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
foreach ($receptionIds as $receptionId) {
	// Verify this reception belongs to the same patient
	$sqlOwner  = "SELECT rowid FROM ".MAIN_DB_PREFIX."reception";
	$sqlOwner .= " WHERE rowid = ".(int) $receptionId." AND fk_soc = ".$patientId;
	$sqlOwner .= " AND fk_statut > 0 LIMIT 1";
	$resOwner  = $db->query($sqlOwner);
	if (!$resOwner || $db->num_rows($resOwner) === 0) {
		if ($resOwner) {
			$db->free($resOwner);
		}
		continue;
	}
	$db->free($resOwner);

	// Skip if already linked
	$sqlChk  = "SELECT rowid FROM ".MAIN_DB_PREFIX."element_element";
	$sqlChk .= " WHERE fk_source = ".(int) $orderId." AND sourcetype = 'commande'";
	$sqlChk .= " AND fk_target = ".(int) $receptionId." AND targettype = 'reception' LIMIT 1";
	$resChk  = $db->query($sqlChk);
	if ($resChk && $db->num_rows($resChk) > 0) {
		$db->free($resChk);
		continue;
	}
	if ($resChk) {
		$db->free($resChk);
	}

	$reception          = new Reception($db);
	$reception->id      = $receptionId;
	$reception->element = 'reception';
	$reception->add_object_linked('commande', $orderId, $user, 1);
}

if ($added === 0 && !empty($errors)) {
	die(json_encode(array('error' => 'No se pudo agregar ningún producto. Fallaron: '.implode(', ', $errors))));
}

$response = array('success' => true, 'added' => $added);
if (!empty($errors)) {
	$response['warning'] = 'No se pudieron agregar: '.implode(', ', $errors);
}
dol_syslog('load_reception_products: resultado added='.$added.' errors='.count($errors), LOG_DEBUG);
die(json_encode($response));
