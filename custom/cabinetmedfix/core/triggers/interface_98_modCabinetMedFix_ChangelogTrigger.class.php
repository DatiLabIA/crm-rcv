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
 * \file    custom/cabinetmedfix/core/triggers/interface_98_modCabinetMedFix_ChangelogTrigger.class.php
 * \ingroup cabinetmedfix
 * \brief   Trigger to track detailed field-level changes on thirdparties/patients
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceChangelogTrigger
 *
 * Tracks all field-level changes when a thirdparty (Societe) is modified,
 * including standard fields and extrafields. Changes are stored in
 * llx_cabinetmedfix_changelog for detailed audit trail.
 */
class InterfaceChangelogTrigger extends DolibarrTriggers
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
		$this->description = 'Tracks detailed field-level changes on thirdparties/patients';
		$this->version = '1.0.0';
		$this->picto = 'generic';
	}

	/**
	 * Function called when a Dolibarr business event occurs.
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

		// Handle thirdparty/patient modifications
		if ($action === 'COMPANY_MODIFY') {
			// Ensure patients always keep client=3 AND fournisseur=1 after any update
			$this->enforcePatientFlags($object, $user, $langs);
			return $this->logCompanyModify($object, $user, $langs, $conf);
		}

		// Handle thirdparty/patient creation
		if ($action === 'COMPANY_CREATE') {
			// Auto-mark new patients as client=3 and fournisseur=1
			$this->enforcePatientFlags($object, $user, $langs);
			return $this->logCompanyCreate($object, $user, $langs, $conf);
		}

		// Handle consultation save — enforce client=3/fournisseur=1 on the linked patient
		// (some update paths can accidentally reset client to 0 on the societe row)
		if ($action === 'EXTCONSULTATION_CREATE' || $action === 'EXTCONSULTATION_MODIFY') {
			$socId = isset($object->fk_soc) ? (int) $object->fk_soc : 0;
			if ($socId > 0) {
				$sqlCheck = "SELECT rowid, canvas FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".$socId;
				$resCheck = $this->db->query($sqlCheck);
				if ($resCheck && $this->db->num_rows($resCheck) > 0) {
					$row = $this->db->fetch_object($resCheck);
					if (!empty($row->canvas) && $row->canvas === 'patient@cabinetmed') {
						$sqlFix = "UPDATE ".MAIN_DB_PREFIX."societe";
						$sqlFix .= " SET client = 3, fournisseur = 1";
						$sqlFix .= " WHERE rowid = ".$socId;
						$sqlFix .= " AND (client != 3 OR fournisseur != 1)";
						$this->db->query($sqlFix);
						dol_syslog("CabinetMedFix: Enforced client=3/fournisseur=1 for patient id=".$socId." after ".$action, LOG_INFO);
					}
				}
			}
			return 0;
		}

		// Handle thirdparty/patient deletion — remove changelog entries to avoid FK constraint failure
		if ($action === 'COMPANY_DELETE') {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "cabinetmedfix_changelog WHERE fk_societe = " . ((int) $object->id);
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_syslog("CabinetMedFix ChangelogTrigger: Failed to delete changelog for societe id=" . $object->id . ": " . $this->db->lasterror(), LOG_ERR);
				return -1;
			}
			return 1;
		}

		return 0;
	}

	/**
	 * Log all field changes when a thirdparty is modified
	 *
	 * @param Object    $object The Societe object (with oldcopy)
	 * @param User      $user   User performing the change
	 * @param Translate $langs  Translation object
	 * @param Conf      $conf   Configuration object
	 * @return int              <0 if KO, 0 if no changes, >0 if OK
	 */
	private function logCompanyModify($object, User $user, Translate $langs, Conf $conf)
	{
		// Must have oldcopy to detect changes
		if (!is_object($object->oldcopy)) {
			dol_syslog("CabinetMedFix ChangelogTrigger: No oldcopy available for societe id=" . $object->id . ", cannot track changes", LOG_WARNING);
			return 0;
		}

		$changes = array();
		$now = dol_now();
		$ip = getUserRemoteIP();
		$useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$entity = !empty($conf->entity) ? $conf->entity : 1;

		// --- 1. Compare standard fields ---
		$standardFields = $this->getStandardFieldsMap($langs);
		foreach ($standardFields as $fieldName => $fieldLabel) {
			$oldVal = $this->getObjectFieldValue($object->oldcopy, $fieldName);
			$newVal = $this->getObjectFieldValue($object, $fieldName);

			if ($this->valuesAreDifferent($oldVal, $newVal)) {
				$changes[] = array(
					'field_name'  => $fieldName,
					'field_label' => $fieldLabel,
					'field_type'  => 'standard',
					'old_value'   => $this->formatValue($oldVal),
					'new_value'   => $this->formatValue($newVal),
				);
			}
		}

		// --- 2. Compare extrafields ---
		$extraChanges = $this->compareExtrafields($object, $langs);
		$changes = array_merge($changes, $extraChanges);

		// --- 3. Save changes to database ---
		if (empty($changes)) {
			dol_syslog("CabinetMedFix ChangelogTrigger: No changes detected for societe id=" . $object->id, LOG_DEBUG);
			return 0;
		}

		$error = 0;
		$this->db->begin();

		foreach ($changes as $change) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "cabinetmedfix_changelog";
			$sql .= " (fk_societe, fk_user, datec, action_type, field_name, field_label, field_type, old_value, new_value, ip_address, user_agent, entity)";
			$sql .= " VALUES (";
			$sql .= ((int) $object->id) . ", ";
			$sql .= ((int) $user->id) . ", ";
			$sql .= "'" . $this->db->idate($now) . "', ";
			$sql .= "'MODIFY', ";
			$sql .= "'" . $this->db->escape($change['field_name']) . "', ";
			$sql .= "'" . $this->db->escape($change['field_label']) . "', ";
			$sql .= "'" . $this->db->escape($change['field_type']) . "', ";
			$sql .= ($change['old_value'] === null ? "NULL" : "'" . $this->db->escape($change['old_value']) . "'") . ", ";
			$sql .= ($change['new_value'] === null ? "NULL" : "'" . $this->db->escape($change['new_value']) . "'") . ", ";
			$sql .= "'" . $this->db->escape($ip) . "', ";
			$sql .= "'" . $this->db->escape(dol_trunc($useragent, 255)) . "', ";
			$sql .= ((int) $entity);
			$sql .= ")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				$error++;
				dol_syslog("CabinetMedFix ChangelogTrigger: SQL Error: " . $this->db->lasterror(), LOG_ERR);
			}
		}

		// --- 4. Also create an ActionComm event with a summary ---
		if (!$error && isModEnabled('agenda')) {
			$this->createAgendaEvent($object, $user, $langs, $changes, $now);
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		$changeCount = count($changes);
		dol_syslog("CabinetMedFix ChangelogTrigger: Logged {$changeCount} field changes for societe id=" . $object->id, LOG_INFO);

		return 1;
	}

	/**
	 * Log the creation of a new thirdparty/patient
	 *
	 * @param Object    $object The Societe object
	 * @param User      $user   User performing the creation
	 * @param Translate $langs  Translation object
	 * @param Conf      $conf   Configuration object
	 * @return int              <0 if KO, >0 if OK
	 */
	private function logCompanyCreate($object, User $user, Translate $langs, Conf $conf)
	{
		$now = dol_now();
		$ip = getUserRemoteIP();
		$useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$entity = !empty($conf->entity) ? $conf->entity : 1;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "cabinetmedfix_changelog";
		$sql .= " (fk_societe, fk_user, datec, action_type, field_name, field_label, field_type, old_value, new_value, ip_address, user_agent, entity)";
		$sql .= " VALUES (";
		$sql .= ((int) $object->id) . ", ";
		$sql .= ((int) $user->id) . ", ";
		$sql .= "'" . $this->db->idate($now) . "', ";
		$sql .= "'CREATE', ";
		$sql .= "'_all_', ";
		$sql .= "'" . $this->db->escape($langs->trans('Creation')) . "', ";
		$sql .= "'system', ";
		$sql .= "NULL, ";
		$sql .= "'" . $this->db->escape($object->name) . "', ";
		$sql .= "'" . $this->db->escape($ip) . "', ";
		$sql .= "'" . $this->db->escape(dol_trunc($useragent, 255)) . "', ";
		$sql .= ((int) $entity);
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("CabinetMedFix ChangelogTrigger: SQL Error on create: " . $this->db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Enforce that a patient always has client=3 (customer+prospect) and fournisseur=1.
	 * Runs as a post-write safety net after ANY update to the societe row.
	 * Uses direct SQL to avoid re-triggering the full update chain.
	 *
	 * @param Object    $object The Societe object
	 * @param User      $user   User performing the action
	 * @param Translate $langs  Translation object
	 * @return void
	 */
	private function enforcePatientFlags($object, User $user, Translate $langs)
	{
		global $conf;

		if (empty($object->canvas) || $object->canvas !== 'patient@cabinetmed') {
			return;
		}

		// Fix client=3 if it was changed to anything else
		if (empty($object->client) || (int) $object->client !== 3) {
			$sqlClient = "UPDATE ".MAIN_DB_PREFIX."societe SET client = 3 WHERE rowid = ".(int) $object->id." AND client != 3";
			$this->db->query($sqlClient);
			$object->client = 3;
			dol_syslog("CabinetMedFix: Restored client=3 for patient ".$object->id, LOG_INFO);
		}

		if (!empty($object->fournisseur) && $object->fournisseur > 0) {
			return; // Already a supplier — only supplier code may need generating below
		}

		// Update fournisseur flag directly in DB to avoid re-triggering
		$sql = "UPDATE ".MAIN_DB_PREFIX."societe";
		$sql .= " SET fournisseur = 1";
		$sql .= " WHERE rowid = ".(int) $object->id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(
				"CabinetMedFix: Failed to auto-mark patient ".$object->id." as supplier: ".$this->db->lasterror(),
				LOG_ERR
			);
			return;
		}

		$object->fournisseur = 1;

		// Generate supplier code using the configured code module
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$supplierCode = '';

		// Use Dolibarr's code generation system
		$module = getDolGlobalString('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
		$dirsociete = array_merge(array('/core/modules/societe/'), $conf->modules_parts['societe']);
		$moduleLoaded = false;
		foreach ($dirsociete as $dirroot) {
			$res = dol_include_once($dirroot.$module.'.php');
			if ($res) {
				$moduleLoaded = true;
				break;
			}
		}

		if ($moduleLoaded) {
			$mod = new $module($this->db);
			if (!empty($mod->code_auto)) {
				// Auto-generate using the module
				$supplierCode = $mod->getNextValue($object, 1); // type=1 for supplier
			} elseif (!empty($mod->code_null)) {
				// Module allows null codes, no action needed
				$supplierCode = '';
			} else {
				// Fallback: generate a simple code
				$supplierCode = 'PROV-'.str_pad($object->id, 5, '0', STR_PAD_LEFT);
			}
		} else {
			// Module not found, use fallback
			$supplierCode = 'PROV-'.str_pad($object->id, 5, '0', STR_PAD_LEFT);
		}

		if (!empty($supplierCode) && $supplierCode != '-1') {
			$sqlCode = "UPDATE ".MAIN_DB_PREFIX."societe";
			$sqlCode .= " SET code_fournisseur = '".$this->db->escape($supplierCode)."'";
			$sqlCode .= " WHERE rowid = ".(int) $object->id;
			$sqlCode .= " AND (code_fournisseur IS NULL OR code_fournisseur = '' OR code_fournisseur = '-1')";
			$this->db->query($sqlCode);
			$object->code_fournisseur = $supplierCode;
		}

		$langs->load('cabinetmedfix@cabinetmedfix');
		dol_syslog(
			"CabinetMedFix: Auto-marked patient ".$object->id." (".$object->name.") as supplier"
			.(!empty($supplierCode) ? " with code ".$supplierCode : ""),
			LOG_INFO
		);
	}

	/**
	 * Compare extrafields between old and new object
	 *
	 * @param Object    $object The Societe object (with oldcopy)
	 * @param Translate $langs  Translation object
	 * @return array            Array of changes detected
	 */
	private function compareExtrafields($object, $langs)
	{
		$changes = array();

		// Load extrafield definitions
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label($object->table_element);

		if (empty($extrafields->attributes[$object->table_element]['label'])) {
			return $changes;
		}

		$labels = $extrafields->attributes[$object->table_element]['label'];
		$types = $extrafields->attributes[$object->table_element]['type'];
		$params = isset($extrafields->attributes[$object->table_element]['param']) ? $extrafields->attributes[$object->table_element]['param'] : array();

		// Collect all extrafield keys from both old and new
		$oldOptions = is_array($object->oldcopy->array_options) ? $object->oldcopy->array_options : array();
		$newOptions = is_array($object->array_options) ? $object->array_options : array();
		$allKeys = array_unique(array_merge(array_keys($oldOptions), array_keys($newOptions)));

		foreach ($allKeys as $key) {
			// Extrafield keys have format 'options_fieldname'
			if (strpos($key, 'options_') !== 0) {
				continue;
			}

			$fieldCode = substr($key, 8); // Remove 'options_' prefix

			// Skip separator fields
			if (isset($types[$fieldCode]) && $types[$fieldCode] === 'separate') {
				continue;
			}

			$oldVal = isset($oldOptions[$key]) ? $oldOptions[$key] : null;
			$newVal = isset($newOptions[$key]) ? $newOptions[$key] : null;

			if ($this->valuesAreDifferent($oldVal, $newVal)) {
				// Get human-readable label
				$label = isset($labels[$fieldCode]) ? $labels[$fieldCode] : $fieldCode;

				// Try to translate label
				$translatedLabel = $langs->trans($label);
				if ($translatedLabel === $label && !empty($extrafields->attributes[$object->table_element]['langfile'][$fieldCode])) {
					$langs->load($extrafields->attributes[$object->table_element]['langfile'][$fieldCode]);
					$translatedLabel = $langs->trans($label);
				}

				// For select/sellist fields, try to get the human-readable value
				$oldDisplay = $this->getExtraFieldDisplayValue($oldVal, $fieldCode, $types, $params, $langs);
				$newDisplay = $this->getExtraFieldDisplayValue($newVal, $fieldCode, $types, $params, $langs);

				$changes[] = array(
					'field_name'  => $key,
					'field_label' => $translatedLabel,
					'field_type'  => 'extrafield',
					'old_value'   => $this->formatValue($oldDisplay),
					'new_value'   => $this->formatValue($newDisplay),
				);
			}
		}

		return $changes;
	}

	/**
	 * Get the display value for a select/radio extrafield
	 *
	 * @param mixed     $value     The raw value
	 * @param string    $fieldCode The extrafield code
	 * @param array     $types     Extrafield types array
	 * @param array     $params    Extrafield params array
	 * @param Translate $langs     Translation object
	 * @return string              Human-readable value
	 */
	private function getExtraFieldDisplayValue($value, $fieldCode, $types, $params, $langs)
	{
		if ($value === null || $value === '') {
			return $value;
		}

		$type = isset($types[$fieldCode]) ? $types[$fieldCode] : '';

		// For select and radio types, resolve the key to its label
		if (in_array($type, array('select', 'radio'))) {
			if (isset($params[$fieldCode]['options']) && is_array($params[$fieldCode]['options'])) {
				if (isset($params[$fieldCode]['options'][$value])) {
					$optLabel = $params[$fieldCode]['options'][$value];
					$translated = $langs->trans($optLabel);
					return $value . ' (' . $translated . ')';
				}
			}
		}

		// For checkbox type, show as Yes/No
		if ($type === 'boolean') {
			return $value ? $langs->trans('Yes') : $langs->trans('No');
		}

		// For date fields
		if (in_array($type, array('date', 'datetime'))) {
			if (!empty($value)) {
				return dol_print_date($value, ($type === 'datetime' ? 'dayhour' : 'day'));
			}
		}

		// For password fields, don't show the actual value
		if ($type === 'password') {
			return '********';
		}

		return $value;
	}

	/**
	 * Create an agenda event summarizing the changes
	 *
	 * @param Object    $object  The Societe object
	 * @param User      $user    User performing the change
	 * @param Translate $langs   Translation object
	 * @param array     $changes Array of changes
	 * @param int       $now     Current timestamp
	 */
	private function createAgendaEvent($object, User $user, Translate $langs, $changes, $now)
	{
		$langs->load('cabinetmedfix@cabinetmedfix');

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$actioncomm = new ActionComm($this->db);
		$actioncomm->type_code = 'AC_OTH_AUTO';
		$actioncomm->code = 'AC_COMPANY_CHANGELOG';

		// Build summary
		$changeCount = count($changes);
		$label = $langs->trans('ChangelogModifySummary', $object->name, $changeCount);
		if ($label === 'ChangelogModifySummary') {
			// Fallback if translation not available
			$label = "Modificación de " . $object->name . " ({$changeCount} campos)";
		}

		$actioncomm->label = $label;

		// Build detailed note
		$note = $langs->trans('ChangelogDetailHeader', $object->name, dol_print_date($now, 'dayhour'), $user->getFullName($langs));
		if ($note === 'ChangelogDetailHeader') {
			$note = "Cambios en: " . $object->name . "\n";
			$note .= "Fecha: " . dol_print_date($now, 'dayhour') . "\n";
			$note .= "Usuario: " . $user->getFullName($langs) . "\n";
		}
		$note .= "\n" . str_repeat('-', 60) . "\n\n";

		foreach ($changes as $change) {
			$typeIndicator = ($change['field_type'] === 'extrafield') ? ' [Extra]' : '';
			$note .= "• " . $change['field_label'] . $typeIndicator . ":\n";
			$note .= "   Antes: " . ($change['old_value'] !== null && $change['old_value'] !== '' ? $change['old_value'] : '(vacío)') . "\n";
			$note .= "   Ahora: " . ($change['new_value'] !== null && $change['new_value'] !== '' ? $change['new_value'] : '(vacío)') . "\n\n";
		}

		$actioncomm->note_private = $note;
		$actioncomm->datep = $now;
		$actioncomm->datef = $now;
		$actioncomm->durationp = 0;
		$actioncomm->percentage = -1; // Not applicable
		$actioncomm->socid = $object->id;
		$actioncomm->authorid = $user->id;
		$actioncomm->userownerid = $user->id;
		$actioncomm->fk_element = $object->id;
		$actioncomm->elementid = $object->id;
		$actioncomm->elementtype = $object->element;

		$ret = $actioncomm->create($user);
		if ($ret <= 0) {
			dol_syslog("CabinetMedFix ChangelogTrigger: Error creating ActionComm: " . $actioncomm->error, LOG_ERR);
		}
	}

	/**
	 * Map of standard Societe fields to their human-readable labels
	 *
	 * @param Translate $langs Translation object
	 * @return array           fieldName => label
	 */
	private function getStandardFieldsMap($langs)
	{
		$langs->load('companies');
		$langs->load('other');

		return array(
			'name'                       => $langs->transnoentities('ThirdPartyName') ?: 'Nombre',
			'name_alias'                 => $langs->transnoentities('AliasNames') ?: 'Nombre alias',
			'ref_ext'                    => $langs->transnoentities('RefExt') ?: 'Ref. externa',
			'address'                    => $langs->transnoentities('Address') ?: 'Dirección',
			'zip'                        => $langs->transnoentities('Zip') ?: 'Código postal',
			'town'                       => $langs->transnoentities('Town') ?: 'Ciudad',
			'state_id'                   => $langs->transnoentities('State') ?: 'Estado/Provincia',
			'country_id'                 => $langs->transnoentities('Country') ?: 'País',
			'phone'                      => $langs->transnoentities('Phone') ?: 'Teléfono',
			'phone_mobile'               => $langs->transnoentities('PhoneMobile') ?: 'Teléfono móvil',
			'fax'                        => $langs->transnoentities('Fax') ?: 'Fax',
			'email'                      => $langs->transnoentities('Email') ?: 'Email',
			'url'                        => $langs->transnoentities('Url') ?: 'Sitio web',
			'socialnetworks'             => $langs->transnoentities('SocialNetworks') ?: 'Redes sociales',
			'parent'                     => $langs->transnoentities('ParentCompany') ?: 'Empresa padre',
			'note_private'               => $langs->transnoentities('NotePrivate') ?: 'Nota privada',
			'note_public'                => $langs->transnoentities('NotePublic') ?: 'Nota pública',
			'siren'                      => $langs->transnoentities('ProfId1') ?: 'SIREN/CIF',
			'siret'                      => $langs->transnoentities('ProfId2') ?: 'SIRET/NIF',
			'ape'                        => $langs->transnoentities('ProfId3') ?: 'APE/CNAE',
			'idprof4'                    => $langs->transnoentities('ProfId4') ?: 'ID Prof 4',
			'idprof5'                    => $langs->transnoentities('ProfId5') ?: 'ID Prof 5',
			'idprof6'                    => $langs->transnoentities('ProfId6') ?: 'ID Prof 6',
			'tva_intra'                  => $langs->transnoentities('VATIntra') ?: 'CIF/NIF IVA',
			'tva_assuj'                  => $langs->transnoentities('VATIsUsed') ?: 'Sujeto a IVA',
			'vat_reverse_charge'         => $langs->transnoentities('VATReverseCharge') ?: 'Inversión sujeto pasivo',
			'localtax1_assuj'            => 'Impuesto local 1',
			'localtax1_value'            => 'Valor impuesto local 1',
			'localtax2_assuj'            => 'Impuesto local 2',
			'localtax2_value'            => 'Valor impuesto local 2',
			'capital'                    => $langs->transnoentities('Capital') ?: 'Capital',
			'prefix_comm'               => $langs->transnoentities('Prefix') ?: 'Prefijo',
			'effectif_id'               => $langs->transnoentities('Workforce') ?: 'Plantilla',
			'stcomm_id'                 => $langs->transnoentities('StatusProsp') ?: 'Estado prospección',
			'typent_id'                 => $langs->transnoentities('ThirdPartyType') ?: 'Tipo de empresa',
			'forme_juridique_code'      => $langs->transnoentities('JuridicalStatus') ?: 'Forma jurídica',
			'mode_reglement_id'         => $langs->transnoentities('PaymentMode') ?: 'Modo de pago',
			'cond_reglement_id'         => $langs->transnoentities('PaymentConditions') ?: 'Condiciones de pago',
			'deposit_percent'           => $langs->transnoentities('DepositPercent') ?: 'Porcentaje anticipo',
			'transport_mode'            => $langs->transnoentities('TransportMode') ?: 'Modo de transporte',
			'mode_reglement_supplier_id'=> 'Modo pago proveedor',
			'cond_reglement_supplier_id'=> 'Condiciones pago proveedor',
			'fk_shipping_method'        => $langs->transnoentities('SendingMethod') ?: 'Método de envío',
			'client'                    => $langs->transnoentities('Customer') ?: 'Cliente',
			'fournisseur'               => $langs->transnoentities('Supplier') ?: 'Proveedor',
			'barcode'                   => $langs->transnoentities('Barcode') ?: 'Código de barras',
			'default_lang'              => $langs->transnoentities('DefaultLang') ?: 'Idioma',
			'canvas'                    => 'Canvas',
			'logo'                      => $langs->transnoentities('Logo') ?: 'Logo',
			'logo_squarred'             => 'Logo cuadrado',
			'outstanding_limit'         => $langs->transnoentities('OutstandingBill') ?: 'Límite riesgo',
			'order_min_amount'          => $langs->transnoentities('OrderMinAmount') ?: 'Pedido mínimo',
			'supplier_order_min_amount' => 'Pedido mínimo proveedor',
			'fk_prospectlevel'          => $langs->transnoentities('ProspectLevel') ?: 'Nivel prospección',
			'webservices_url'           => 'URL Web Services',
			'webservices_key'           => 'Clave Web Services',
			'fk_incoterms'             => $langs->transnoentities('IncotermLabel') ?: 'Incoterms',
			'location_incoterms'       => 'Ubicación Incoterms',
			'model_pdf'                => 'Plantilla PDF',
			'fk_multicurrency'         => 'Multimoneda ID',
			'multicurrency_code'       => $langs->transnoentities('Currency') ?: 'Moneda',
			'fk_account'               => $langs->transnoentities('BankAccount') ?: 'Cuenta bancaria',
			'fk_warehouse'             => $langs->transnoentities('Warehouse') ?: 'Almacén',
			'status'                   => $langs->transnoentities('Status') ?: 'Estado',
			'code_client'              => $langs->transnoentities('CustomerCode') ?: 'Código cliente',
			'code_fournisseur'         => $langs->transnoentities('SupplierCode') ?: 'Código proveedor',
			'code_compta'              => $langs->transnoentities('CustomerAccountancyCode') ?: 'Código contable cliente',
			'code_compta_fournisseur'  => $langs->transnoentities('SupplierAccountancyCode') ?: 'Código contable proveedor',
			'price_level'              => $langs->transnoentities('PriceLevel') ?: 'Nivel de precio',
		);
	}

	/**
	 * Get a field value from a Societe object, handling property name differences
	 *
	 * @param Object $object    The Societe object
	 * @param string $fieldName The field name
	 * @return mixed            The field value
	 */
	private function getObjectFieldValue($object, $fieldName)
	{
		// Map of logical field names to actual PHP property names
		$propertyMap = array(
			'name'   => 'nom',  // Societe uses 'nom' internally, but also has 'name' via __get
		);

		// Try the mapped property name first
		$propName = isset($propertyMap[$fieldName]) ? $propertyMap[$fieldName] : $fieldName;

		if (property_exists($object, $propName)) {
			return $object->$propName;
		}

		// Try direct property access as fallback
		if (property_exists($object, $fieldName)) {
			return $object->$fieldName;
		}

		// Try via isset (catches __get magic method)
		if (isset($object->$fieldName)) {
			return $object->$fieldName;
		}

		return null;
	}

	/**
	 * Check if two values are different (handles type coercion gracefully)
	 *
	 * @param mixed $old Old value
	 * @param mixed $new New value
	 * @return bool      True if values are different
	 */
	private function valuesAreDifferent($old, $new)
	{
		// Both null/empty string — no change
		if (($old === null || $old === '') && ($new === null || $new === '')) {
			return false;
		}

		// Compare as strings to handle numeric/string type differences
		// This correctly catches 0 -> 3, "0" -> "3", etc.
		return (string) $old !== (string) $new;
	}

	/**
	 * Check if a value is considered "empty" for display purposes
	 *
	 * @param mixed $val The value to check
	 * @return bool      True if empty
	 */
	private function isEmptyValue($val)
	{
		return ($val === null || $val === '');
	}

	/**
	 * Format a value for storage (handle arrays/objects)
	 *
	 * @param mixed $val The value to format
	 * @return string|null  Formatted string value
	 */
	private function formatValue($val)
	{
		if ($val === null) {
			return null;
		}
		if (is_array($val) || is_object($val)) {
			return json_encode($val, JSON_UNESCAPED_UNICODE);
		}
		return (string) $val;
	}
}
