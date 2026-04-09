<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    custom/cabinetmedfix/core/triggers/interface_99_modCabinetMedFix_DispensationTrigger.class.php
 * \ingroup cabinetmedfix
 * \brief   Medication inventory trigger: auto-reception on PO validate, validation + auto-expedition on SO validate
 *
 * Flow summary:
 * 0. LINEORDER_SUPPLIER_CREATE: Auto-fills supplier ref (SKU) with product ref if empty
 * 1. ORDER_SUPPLIER_VALIDATE: Auto-creates and validates a Reception with serial/batch from extrafield
 * 2. ORDER_VALIDATE (SRC_COLLECTION): Validates reception exists, then auto-creates Expedition as DRAFT (user reviews + validates manually)
 * 3. ORDER_VALIDATE (SRC_DONATION): No auto-expedition (user creates expedition manually and picks serial)
 * 4. SHIPPING_VALIDATE: For SRC_COLLECTION shipments, validates serial belongs to patient's reception
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceDispensationTrigger
 */
class InterfaceDispensationTrigger extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'cabinetmedfix';
		$this->description = 'Medication inventory: auto-reception, validation, and draft expedition';
		$this->version = '2.1.0';
		$this->picto = 'lot';
	}

	/**
	 * Function called when a Dolibarr business event occurs
	 *
	 * @param string    $action Event action code
	 * @param Object    $object Object the action is performed on
	 * @param User      $user   User performing the action
	 * @param Translate $langs  Translation object
	 * @param Conf      $conf   Configuration object
	 * @return int              <0 if KO, 0 if no action, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('cabinetmedfix')) {
			return 0;
		}

		$langs->load('cabinetmedfix@cabinetmedfix');

		// ====================================================================
		// LINEORDER_SUPPLIER_CREATE: Auto-fill supplier ref with product ref
		// ====================================================================
		if ($action === 'LINEORDER_SUPPLIER_CREATE') {
			return $this->autoFillSupplierRef($object, $user);
		}

		// ====================================================================
		// ORDER_SUPPLIER_VALIDATE: Auto-create Reception with serial from extrafield
		// ====================================================================
		if ($action === 'ORDER_SUPPLIER_VALIDATE') {
			return $this->autoCreateReceptionFromSupplierOrder($object, $user, $langs, $conf);
		}

		// ====================================================================
		// ORDER_VALIDATE: For SRC_COLLECTION → validate + auto-create expedition
		// For SRC_DONATION → no action (user creates expedition manually)
		// ====================================================================
		if ($action === 'ORDER_VALIDATE') {
			return $this->handleSalesOrderValidation($object, $user, $langs, $conf);
		}

		// ====================================================================
		// SHIPPING_VALIDATE: For SRC_COLLECTION → verify serials match patient
		// ====================================================================
		if ($action === 'SHIPPING_VALIDATE') {
			return $this->validateShipmentSerials($object, $user, $langs, $conf);
		}

		return 0;
	}

	// =========================================================================
	// 0. AUTO-FILL SUPPLIER REF on supplier order lines
	// =========================================================================

	/**
	 * When a line is added to a supplier order, if the supplier ref is empty
	 * and the line has a product, auto-fill it with the product's ref.
	 *
	 * @param CommandeFournisseurLigne $object The supplier order line
	 * @param User $user Current user
	 * @return int 0 if no action, 1 if updated
	 */
	private function autoFillSupplierRef($object, User $user)
	{
		// Only act if ref_supplier is empty and product is set
		if (!empty($object->ref_supplier) || empty($object->fk_product) || $object->fk_product <= 0) {
			return 0;
		}

		$productRef = $this->getProductRef((int) $object->fk_product);
		if (empty($productRef) || strpos($productRef, 'ID:') === 0) {
			return 0;
		}

		// Update the line's ref column directly
		$sql = "UPDATE ".MAIN_DB_PREFIX."commande_fournisseurdet";
		$sql .= " SET ref = '".$this->db->escape($productRef)."'";
		$sql .= " WHERE rowid = ".(int) $object->id;

		$resql = $this->db->query($sql);
		if ($resql) {
			dol_syslog("DispensationTrigger: Auto-filled supplier ref '".$productRef."' for line ".$object->id, LOG_INFO);
			return 1;
		}

		dol_syslog("DispensationTrigger: Failed to auto-fill supplier ref for line ".$object->id, LOG_WARNING);
		return 0;
	}

	// =========================================================================
	// 1. AUTO-RECEPTION from Supplier Order
	// =========================================================================

	/**
	 * When a supplier order is validated, auto-create and validate a Reception
	 * using the serial/batch number from the extrafield 'serial_batch' on each line.
	 *
	 * @param CommandeFournisseur $object The supplier order
	 * @param User      $user   Current user
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int              0 if no action, >0 if OK, <0 if KO
	 */
	private function autoCreateReceptionFromSupplierOrder($object, User $user, Translate $langs, Conf $conf)
	{
		// Check if any line has a serial_batch extrafield
		if (empty($object->lines)) {
			$object->fetch_lines();
		}

		$linesWithSerial = array();
		foreach ($object->lines as $line) {
			$serialRaw = '';
			if (!empty($line->array_options) && !empty($line->array_options['options_serial_batch'])) {
				$serialRaw = trim($line->array_options['options_serial_batch']);
			}
			if (empty($serialRaw) || empty($line->fk_product)) {
				continue;
			}

			// Read expiry date from the lot_expiry_date extrafield (stored as timestamp or date string)
			$expiryDateRaw = !empty($line->array_options['options_lot_expiry_date']) ? $line->array_options['options_lot_expiry_date'] : null;
			$expiryDate = null;
			if ($expiryDateRaw) {
				$expiryDate = is_numeric($expiryDateRaw) ? (int) $expiryDateRaw : dol_stringtotime((string) $expiryDateRaw);
			}

			// Support multiple serials on a single line separated by commas.
			// Each serial becomes one reception lot with qty=1.
			// If only one serial is listed, use the full line qty as before.
			$serials = array_values(array_filter(array_map('trim', explode(',', $serialRaw))));
			$multiSerial = count($serials) > 1;

			foreach ($serials as $serial) {
				$linesWithSerial[] = array(
					'line_id'     => $line->id,
					'fk_product'  => (int) $line->fk_product,
					'qty'         => $multiSerial ? 1 : (float) $line->qty,
					'serial'      => $serial,
					'expiry_date' => $expiryDate,
				);
			}
		}

		if (empty($linesWithSerial)) {
			dol_syslog("DispensationTrigger: Supplier order ".$object->ref." has no lines with serial_batch, skipping auto-reception", LOG_INFO);
			return 0; // No serial data → no auto-reception
		}

		// Get warehouse: use configured default
		$warehouseId = $this->getDefaultWarehouse($conf);
		if ($warehouseId <= 0) {
			dol_syslog("DispensationTrigger: No default warehouse configured, cannot auto-create reception", LOG_WARNING);
			$this->errors[] = $langs->trans('DispensationNoDefaultWarehouse');
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.dispatch.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';

		$this->db->begin();
		$error = 0;

		// Create reception
		$reception = new Reception($this->db);
		$reception->socid = (int) $object->socid;
		$reception->date_reception = dol_now();
		$reception->date_delivery = dol_now();
		$reception->origin = 'order_supplier';
		$reception->origin_id = (int) $object->id;
		$reception->note_private = $langs->trans('DispensationAutoReceptionNote', $object->ref);

		// Add lines using reception's addline method
		foreach ($linesWithSerial as $lineData) {
			// Priority 1: expiry date entered by the user on the purchase order line (options_lot_expiry_date).
			// Priority 2: dates from an already existing lot record (serial received before).
			// If neither is available, skipMandatoryDateCheck bypasses the mandatory-field error.
			$eatby = $lineData['expiry_date'];
			$sellby = $lineData['expiry_date'];

			if ($eatby === null) {
				// Fall back to existing lot dates if the serial was received before
				$existingLot = new Productlot($this->db);
				if ($existingLot->fetch(0, $lineData['fk_product'], $lineData['serial']) > 0) {
					$eatby = $existingLot->eatby ? $existingLot->eatby : null;
					$sellby = $existingLot->sellby ? $existingLot->sellby : null;
				}
			}

			// Skip mandatory date check only when no date is available at all,
			// so we never block creation when the workflow doesn't use expiry dates.
			$skipMandatoryDateCheck = ($eatby === null && $sellby === null);

			$result = $reception->addline(
				$warehouseId,              // entrepot_id
				$lineData['line_id'],      // id (supplier order line id)
				$lineData['qty'],          // qty
				array(),                   // array_options
				'',                        // comment
				$eatby,                    // eatby
				$sellby,                   // sellby
				$lineData['serial'],       // batch
				0,                         // cost_price
				$skipMandatoryDateCheck    // skip mandatory check only when no date available
			);

			if ($result < 0) {
				$error++;
				$this->errors[] = $reception->error;
				dol_syslog("DispensationTrigger: Failed to add reception line: ".$reception->error, LOG_ERR);
				break;
			}
		}

		if (!$error) {
			$receptionId = $reception->create($user);
			if ($receptionId <= 0) {
				$error++;
				$this->errors[] = $reception->error;
				dol_syslog("DispensationTrigger: Failed to create reception: ".$reception->error, LOG_ERR);
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		dol_syslog(
			"DispensationTrigger: Auto-created reception ".$reception->ref
			." from supplier order ".$object->ref
			." with ".count($linesWithSerial)." serial lines",
			LOG_INFO
		);
		setEventMessages($langs->trans('DispensationAutoReceptionCreated', $reception->ref), null, 'mesgs');
		return 1;
	}

	// =========================================================================
	// 2. SALES ORDER VALIDATION
	// =========================================================================

	/**
	 * Handle sales order validation.
	 * SRC_COLLECTION: validate reception exists + auto-create expedition
	 * SRC_DONATION: no action (user creates expedition manually and picks serial)
	 *
	 * @param Commande  $object The order being validated
	 * @param User      $user   Current user
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int              <0 if blocked, 0 if not applicable, >0 if OK
	 */
	private function handleSalesOrderValidation($object, User $user, Translate $langs, Conf $conf)
	{
		$demandReasonCode = $this->getOrderDemandReasonCode($object);

		// Only act on SRC_COLLECTION orders
		if ($demandReasonCode !== 'SRC_COLLECTION') {
			return 0;
		}

		$patientId = (int) $object->socid;
		if ($patientId <= 0) {
			return 0;
		}

		// Load order lines if not loaded
		if (empty($object->lines)) {
			$object->fetch_lines();
		}

		// --- Step 1: Validate that reception exists for each product ---
		$missingProducts = array();
		$serialsForExpedition = array(); // productId => array of {serial, qty, warehouse_id, batch_id}

		foreach ($object->lines as $line) {
			if (empty($line->fk_product) || $line->fk_product <= 0) {
				continue;
			}

			$productId = (int) $line->fk_product;
			$qtyNeeded = (float) $line->qty;

			// Find serials from this patient's receptions for this product
			$sql = "SELECT rb.batch, rb.qty as reception_qty, r.ref as reception_ref";
			$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch rb";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rb.fk_reception";
			$sql .= " WHERE r.fk_soc = ".$patientId;
			$sql .= " AND rb.fk_product = ".$productId;
			$sql .= " AND rb.batch IS NOT NULL AND rb.batch != ''";
			$sql .= " AND r.fk_statut > 0";
			$sql .= " AND r.entity IN (".getEntity('reception').")";
			$sql .= " ORDER BY r.date_creation DESC";

			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				// Found receptions — collect serials for auto-expedition
				$serials = array();
				while ($obj = $this->db->fetch_object($resql)) {
					// Check if this serial is still in stock
					$stockInfo = $this->getSerialStockInfo($obj->batch, $productId);
					if ($stockInfo && $stockInfo->qty > 0) {
						$serials[] = array(
							'serial'       => $obj->batch,
							'qty'          => min($qtyNeeded, $stockInfo->qty),
							'warehouse_id' => (int) $stockInfo->fk_entrepot,
							'batch_id'     => (int) $stockInfo->batch_id,
						);
						$qtyNeeded -= min($qtyNeeded, $stockInfo->qty);
						if ($qtyNeeded <= 0) break;
					}
				}
				$this->db->free($resql);

				if (!empty($serials)) {
					$serialsForExpedition[$line->id] = array(
						'fk_product' => $productId,
						'serials'    => $serials,
						'total_qty'  => (float) $line->qty,
					);
				} else {
					$productRef = $this->getProductRef($productId);
					$missingProducts[] = $productRef.' ('.$langs->trans('DispensationSerialNoStock').')';
				}
			} else {
				$productRef = $this->getProductRef($productId);
				$missingProducts[] = $productRef;
			}
		}

		// Block validation if products are missing receptions
		if (!empty($missingProducts)) {
			$productList = implode(', ', $missingProducts);
			$this->errors[] = $langs->trans('DispensationNoReceptionForPatient', $productList);
			dol_syslog(
				"DispensationTrigger: BLOCKED order ".$object->ref." - Missing receptions for: ".$productList,
				LOG_WARNING
			);
			return -1;
		}

		// --- Step 2: Auto-link the receptions used to this order ---
		// Collect the unique reception IDs that contributed serials and link them
		// via llx_element_element so they appear in the "Linked objects" block.
		$this->linkReceptionsToOrder($object, $patientId, $user);

		// --- Step 3: Auto-create expedition with matching serials ---
		if (!empty($serialsForExpedition)) {
			$result = $this->autoCreateExpeditionFromOrder($object, $serialsForExpedition, $user, $langs, $conf);
			if ($result < 0) {
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Link all validated receptions for a patient to a sales order via llx_element_element.
	 * Only links receptions that have stock available for the order's products.
	 * Skips receptions already linked (to allow calling more than once safely).
	 *
	 * @param Commande $order     The sales order
	 * @param int      $patientId The patient (socid)
	 * @param User     $user      Current user
	 * @return void
	 */
	private function linkReceptionsToOrder($order, $patientId, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';

		// Collect all validated reception IDs for this patient that contain any ordered product.
		// We intentionally skip the product_batch/product_stock join because:
		//   a) Step 1 already validated stock exists, and
		//   b) the join is too restrictive and may miss valid receptions.
		$receptionIds = array();
		foreach ($order->lines as $line) {
			if (empty($line->fk_product) || $line->fk_product <= 0) {
				continue;
			}
			$sql  = "SELECT DISTINCT r.rowid";
			$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch rb";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rb.fk_reception";
			$sql .= " WHERE r.fk_soc = ".(int) $patientId;
			$sql .= " AND rb.fk_product = ".(int) $line->fk_product;
			$sql .= " AND r.fk_statut > 0";
			$sql .= " AND r.entity IN (".getEntity('reception').")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$receptionIds[(int) $obj->rowid] = true;
				}
				$this->db->free($resql);
			}
		}

		foreach (array_keys($receptionIds) as $receptionId) {
			// add_object_linked($this=reception, origin='commande', origin_id=order->id) inserts:
			//   fk_source = order->id, sourcetype = 'commande'
			//   fk_target = reception->id, targettype = 'reception'
			// So duplicate check must match that exact direction.
			$sqlCheck  = "SELECT rowid FROM ".MAIN_DB_PREFIX."element_element";
			$sqlCheck .= " WHERE fk_source = ".(int) $order->id." AND sourcetype = 'commande'";
			$sqlCheck .= " AND fk_target = ".(int) $receptionId." AND targettype = 'reception'";
			$sqlCheck .= " LIMIT 1";
			$resCheck = $this->db->query($sqlCheck);
			if ($resCheck && $this->db->num_rows($resCheck) > 0) {
				$this->db->free($resCheck);
				continue; // already linked
			}
			if ($resCheck) {
				$this->db->free($resCheck);
			}

			// Link order → reception via add_object_linked on the reception side
			$reception = new Reception($this->db);
			$reception->id = $receptionId;
			$reception->element = 'reception';
			$reception->add_object_linked('commande', $order->id, $user, 1);
		}
	}

	/**
	 * Auto-create an Expedition (as draft) from a sales order with serial data.
	 * The expedition is left in draft so the user can review the selected serial
	 * and validate it manually. The SHIPPING_VALIDATE trigger will then run the
	 * final serial ownership check.
	 *
	 * @param Commande $order              The validated sales order
	 * @param array    $serialsForExpedition Line data with serials
	 * @param User     $user               Current user
	 * @param Translate $langs             Translations
	 * @param Conf     $conf               Configuration
	 * @return int                         <0 if KO, >0 if OK
	 */
	private function autoCreateExpeditionFromOrder($order, $serialsForExpedition, User $user, Translate $langs, Conf $conf)
	{
		require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';

		$this->db->begin();
		$error = 0;

		$expedition = new Expedition($this->db);
		$expedition->origin = 'commande';
		$expedition->origin_id = (int) $order->id;
		$expedition->socid = (int) $order->socid;
		$expedition->date_delivery = dol_now();
		$expedition->note_private = $langs->trans('DispensationAutoExpeditionNote', $order->ref);

		// Add lines with batch detail
		foreach ($serialsForExpedition as $orderLineId => $lineData) {
			$dbatch = array(
				'ix_l'   => $orderLineId,  // order line id
				'qty'    => $lineData['total_qty'],
				'detail' => array(),
			);

			foreach ($lineData['serials'] as $serialInfo) {
				$dbatch['detail'][] = array(
					'id_batch' => $serialInfo['batch_id'],  // id in llx_product_batch
					'q'        => $serialInfo['qty'],
				);
			}

			$result = $expedition->addline_batch($dbatch);
			if ($result < 0) {
				$error++;
				$this->errors = array_merge($this->errors, $expedition->errors);
				dol_syslog("DispensationTrigger: Failed to add expedition batch line: ".implode(', ', $expedition->errors), LOG_ERR);
				break;
			}
		}

		if (!$error) {
			$expeditionId = $expedition->create($user);
			if ($expeditionId <= 0) {
				$error++;
				$this->errors[] = $expedition->error;
				dol_syslog("DispensationTrigger: Failed to create expedition: ".$expedition->error, LOG_ERR);
			}
		}

		// Expedition is left as DRAFT intentionally — the user must review the
		// pre-selected serial/lot and validate the shipment manually.
		// Stock movement (decrement) happens on SHIPPING_VALIDATE, not here.

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		dol_syslog(
			"DispensationTrigger: Auto-created draft expedition ".$expedition->ref." from order ".$order->ref,
			LOG_INFO
		);
		setEventMessages($langs->trans('DispensationAutoExpeditionDraft', $expedition->ref), null, 'mesgs');
		return 1;
	}

	// =========================================================================
	// 3. SHIPMENT SERIAL VALIDATION (for manually created expeditions)
	// =========================================================================

	/**
	 * Validate that each serial in a shipment belongs to the patient's reception.
	 * This applies to SRC_COLLECTION shipments that were created manually (not auto-created).
	 *
	 * @param Expedition $object The shipment being validated
	 * @param User       $user   Current user
	 * @param Translate  $langs  Translations
	 * @param Conf       $conf   Configuration
	 * @return int               <0 if fails, 0 if not applicable, >0 if OK
	 */
	private function validateShipmentSerials($object, User $user, Translate $langs, Conf $conf)
	{
		$originType = !empty($object->origin) ? $object->origin : '';
		$originId = !empty($object->origin_id) ? (int) $object->origin_id : 0;

		if ($originType !== 'commande' || $originId <= 0) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$order = new Commande($this->db);
		$order->fetch($originId);

		$demandReasonCode = $this->getOrderDemandReasonCode($order);

		if ($demandReasonCode !== 'SRC_COLLECTION') {
			return 0;
		}

		$patientId = (int) $object->socid;
		if ($patientId <= 0) {
			return 0;
		}

		if (empty($object->lines)) {
			$object->fetch_lines();
		}

		$errors = array();

		foreach ($object->lines as $line) {
			if (empty($line->detail_batch) || !is_array($line->detail_batch)) {
				continue;
			}

			$productId = (int) $line->fk_product;
			$productRef = $this->getProductRef($productId);

			foreach ($line->detail_batch as $batchDetail) {
				$serial = $batchDetail->batch;
				if (empty($serial)) {
					continue;
				}

				// Check serial comes from patient's reception
				$sql = "SELECT rb.rowid";
				$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch rb";
				$sql .= " INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rb.fk_reception";
				$sql .= " WHERE r.fk_soc = ".$patientId;
				$sql .= " AND rb.batch = '".$this->db->escape($serial)."'";
				$sql .= " AND r.fk_statut > 0";
				$sql .= " AND r.entity IN (".getEntity('reception').")";
				$sql .= " LIMIT 1";

				$resql = $this->db->query($sql);
				if ($resql && $this->db->num_rows($resql) == 0) {
					$errors[] = $langs->trans('DispensationSerialNotFromPatient', $serial, $productRef);
				}
				if ($resql) {
					$this->db->free($resql);
				}
			}
		}

		if (!empty($errors)) {
			$this->errors = array_merge($this->errors, $errors);
			return -1;
		}

		return 1;
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Get the demand_reason_code for an order
	 *
	 * @param Commande $order The order object
	 * @return string         The demand reason code or empty string
	 */
	private function getOrderDemandReasonCode($order)
	{
		if (!empty($order->demand_reason_code)) {
			return $order->demand_reason_code;
		}

		if (!empty($order->demand_reason_id) && $order->demand_reason_id > 0) {
			$sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_input_reason WHERE rowid = ".(int) $order->demand_reason_id;
			$resql = $this->db->query($sql);
			if ($resql) {
				$obj = $this->db->fetch_object($resql);
				if ($obj) {
					return $obj->code;
				}
			}
		}

		return '';
	}

	/**
	 * Get product ref by ID
	 *
	 * @param int $productId Product ID
	 * @return string        Product ref
	 */
	private function getProductRef($productId)
	{
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".(int) $productId;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) return $obj->ref;
		}
		return 'ID:'.$productId;
	}

	/**
	 * Get stock info for a specific serial/batch and product
	 * Returns the product_batch record with qty and warehouse info
	 *
	 * @param string $serial    Serial/batch number
	 * @param int    $productId Product ID
	 * @return object|null      Object with qty, fk_entrepot, batch_id or null
	 */
	private function getSerialStockInfo($serial, $productId)
	{
		$sql = "SELECT pb.rowid as batch_id, pb.qty, ps.fk_entrepot";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_batch pb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " WHERE pb.batch = '".$this->db->escape($serial)."'";
		$sql .= " AND ps.fk_product = ".(int) $productId;
		$sql .= " AND pb.qty > 0";
		$sql .= " ORDER BY pb.qty DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			return $this->db->fetch_object($resql);
		}
		return null;
	}

	/**
	 * Get the default warehouse ID
	 * First checks for a configured default, then takes the first active warehouse
	 *
	 * @param Conf $conf Configuration
	 * @return int       Warehouse ID or 0
	 */
	private function getDefaultWarehouse($conf)
	{
		// Check for configured default warehouse
		$defaultId = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
		if ($defaultId > 0) {
			return $defaultId;
		}

		// Fallback: get first active warehouse
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot";
		$sql .= " WHERE statut = 1";
		$sql .= " AND entity IN (".getEntity('stock').")";
		$sql .= " ORDER BY rowid ASC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Find which expedition previously dispatched a serial
	 *
	 * @param string $serial    Serial number
	 * @param int    $productId Product ID
	 * @return string           Expedition ref or 'Unknown'
	 */
	private function findPreviousDispatch($serial, $productId)
	{
		global $langs;

		$sql = "SELECT e.ref";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet_batch eb";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.rowid = eb.fk_expeditiondet";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition";
		$sql .= " WHERE eb.batch = '".$this->db->escape($serial)."'";
		$sql .= " AND e.fk_statut > 0";
		$sql .= " ORDER BY e.date_creation DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) return $obj->ref;
		}
		return $langs->trans('Unknown');
	}
}
