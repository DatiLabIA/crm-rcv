<?php
/* Copyright (C) 2026 CRM-RCV
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       custom/cabinetmedfix/core/modules/modCabinetMedFix.class.php
 * \ingroup    cabinetmedfix
 * \brief      Fix module for CabinetMed URL and client field issues
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module CabinetMedFix
 */
class modCabinetMedFix extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Id for module (must be unique)
		$this->numero = 500100;
		
		// Key text used to identify module
		$this->rights_class = 'cabinetmedfix';

		// Family can be 'base', 'crm', 'financial', 'hr', 'projects', 'products', 'ecm', 'technic' (Dolibarr lower than 3.5), 'interface' (Dolibarr lower than 3.5), 'other', ...
		// It is used to group modules by family in module setup page
		$this->family = "crm";
		
		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleCabinetMedFixName' not found (CabinetMedFix is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleCabinetMedFixDesc' not found (CabinetMedFix is name of module).
		$this->description = "Correcciones para URLs de pacientes y campo client en CabinetMed";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "Este módulo corrige automáticamente:\n";
		$this->descriptionlong .= "- URLs que apuntan a /societe/card.php en lugar de /custom/cabinetmed/card.php\n";
		$this->descriptionlong .= "- Campo 'client' que se establece en 0 al editar pacientes\n";
		$this->descriptionlong .= "- Validación de dispensación de medicamentos por recolección\n";
		$this->descriptionlong .= "- Auto-marcado de pacientes como proveedor para recolecciones\n";
		$this->descriptionlong .= "- Serial/lote en órdenes de compra con auto-recepción\n";
		$this->descriptionlong .= "- Auto-expedición para dispensación por recolección\n";
		$this->descriptionlong .= "\nEs completamente independiente y sobrevivirá a actualizaciones.";

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
		$this->version = '5.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where CABINETMEDFIX is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'generic';

		// Define some features supported by module (triggers, login, substitutions, menus, css, number module formats, and boxes)
		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'theme' => 0,
			'css' => array('/cabinetmedfix/css/responsive.css?v='.time()),
			'js' => array(
				'/cabinetmedfix/js/fix-patient-urls.js?v='.time(),
				'/cabinetmedfix/js/fix-patient-responsive.js?v='.time(),
				'/cabinetmedfix/js/fix-diagnostico-select2.js?v='.time(),
				'/cabinetmedfix/js/document-tags.js?v='.time()
			),
			'hooks' => array(
				'thirdpartycard',
				'thirdpartylist',
				'customerlist',
				'data',
				'thirdpartycomm',
				'documentcabinetmed',
				'ordercard',
				'expeditioncard',
				'ordersuppliercard',
				'fichintercard',
			),
			'tabs' => array(
				'thirdparty:+changelog:Historial cambios:cabinetmedfix@cabinetmedfix:/cabinetmedfix/changelog.php?socid=__ID__',
			),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array();

		// Config pages
		$this->config_page_url = array();

		// Dependencies
		$this->hidden = false; // A condition to hide module
		$this->depends = array('modCabinetMed'); // List of module class names as string that must be enabled if this module is enabled
		$this->requiredby = array(); // List of module class names as string to disable if this one is disabled
		$this->conflictwith = array(); // List of module class names as string this module is in conflict with
		$this->langfiles = array("cabinetmedfix@cabinetmedfix");
		$this->phpmin = array(7, 0); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(17, 0); // Minimum version of Dolibarr required by module
		$this->warnings_activation = array(); // Warning to show when we activate module
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module
		$this->automatic_activation = array();
		$this->always_enabled = false;

		$this->const = array();

		if (!isset($conf->cabinetmedfix) || !isset($conf->cabinetmedfix->enabled)) {
			$conf->cabinetmedfix = new stdClass();
			$conf->cabinetmedfix->enabled = 0;
		}

		// Tabs are defined in module_parts above

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		// Main menu entries to add
		$this->menu = array();
		$r = 0;
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		$result = $this->_load_tables('/cabinetmedfix/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafield for serial/batch number on supplier order lines
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// Eliminar fk_entrepot_reception si existe (campo descontinuado, no se transfiere a la recepción).
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'fk_entrepot_reception' AND elementtype = 'commande_fournisseur'");

		// Serial/Lote field on supplier order lines (commande_fournisseurdet)
		$extrafields->addExtraField(
			'serial_batch',                    // attrname
			'Serial / Lote',                   // label
			'varchar',                         // type
			100,                               // pos
			'50',                              // size
			'commande_fournisseurdet',         // elementtype (supplier order lines)
			0,                                 // unique
			0,                                 // required
			'',                                // default_value
			'',                                // param
			1,                                 // alwayseditable
			'',                                // perms
			'1',                               // list (visible in list)
			'Número de serial o lote del medicamento para esta línea', // help
			'',                                // computed
			'',                                // entity
			'cabinetmedfix@cabinetmedfix',     // langfile
			'1'                                // enabled
		);
		// Expiry date field on supplier order lines (commande_fournisseurdet)
		$extrafields->addExtraField(
			'lot_expiry_date',                 // attrname
			'Fecha de vencimiento',            // label
			'date',                            // type
			110,                               // pos (after serial_batch)
			'',                                // size (unused for date)
			'commande_fournisseurdet',         // elementtype (supplier order lines)
			0,                                 // unique
			0,                                 // required
			'',                                // default_value
			'',                                // param
			1,                                 // alwayseditable
			'',                                // perms
			'1',                               // list (visible in list)
			'Fecha de vencimiento del lote para registro en inventario', // help
			'',                                // computed
			'',                                // entity
			'cabinetmedfix@cabinetmedfix',     // langfile
			'1'                                // enabled
		);
		// fk_entrepot_reception eliminado — no se transfiere a la recepción.
		// Aseguramos que no exista aunque fuera creado en una versión anterior.
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'fk_entrepot_reception' AND elementtype = 'commande_fournisseur'");

		// Permissions
		$this->remove($options);

		$sql = array();

		// Add custom input reasons for medication dispensation (Donación, Recolección)
		// Use DELETE+INSERT to avoid duplicates on re-activation
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."c_input_reason WHERE code = 'SRC_DONATION'";
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."c_input_reason WHERE code = 'SRC_COLLECTION'";
		$sql[] = "INSERT INTO ".MAIN_DB_PREFIX."c_input_reason (rowid, code, label, active) VALUES (100, 'SRC_DONATION', 'Donación', 1)";
		$sql[] = "INSERT INTO ".MAIN_DB_PREFIX."c_input_reason (rowid, code, label, active) VALUES (101, 'SRC_COLLECTION', 'Recolección', 1)";

		// Add extra gender options (No binario, Trans, Otro) to c_typent dictionary
		// CabinetMed only includes TE_HOMME, TE_FEMME, TE_OTHER — we ensure all are present
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."c_typent WHERE code = 'TE_NOBINAR'";
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."c_typent WHERE code = 'TE_TRANS'";
		$sql[] = "INSERT INTO ".MAIN_DB_PREFIX."c_typent (id, code, libelle, active, module, position) VALUES (104, 'TE_NOBINAR', 'No binario', 1, 'cabinetmed', 4)";
		$sql[] = "INSERT INTO ".MAIN_DB_PREFIX."c_typent (id, code, libelle, active, module, position) VALUES (105, 'TE_TRANS', 'Trans', 1, 'cabinetmed', 5)";
		$sql[] = "UPDATE ".MAIN_DB_PREFIX."c_typent SET libelle = 'Otro', active = 1 WHERE code = 'TE_OTHER'";

		// Mark ALL existing patients as suppliers (fournisseur=1) for medication collection workflow
		// Patients are identified by canvas = 'patient@cabinetmed'
		$sql[] = "UPDATE ".MAIN_DB_PREFIX."societe SET fournisseur = 1 WHERE canvas = 'patient@cabinetmed' AND (fournisseur = 0 OR fournisseur IS NULL)";

		// Fix fk_entrepot_reception extrafield: force type='int' and clear invalid param.
		// addExtraField() never updates the type of an existing field, so if it was previously
		// created as type='sellist' (which causes SQL errors), we fix it here via direct SQL.
		// This UPDATE runs every time the module is enabled, safely fixing any stale record.
		// fk_entrepot_reception ya se elimina arriba con DELETE; esta línea se suprime.

		$initResult = $this->_init($sql, $options);

		// Generate supplier codes for patients that don't have one yet.
		// This must be done in PHP (not SQL) because the code generation depends on the configured module.
		// We only run this once (tracked by a config flag) to avoid unnecessary queries on every re-activation.
		if ($initResult) {
			$alreadyRan = getDolGlobalString('CABINETMEDFIX_SUPPLIER_CODES_GENERATED');
			if (empty($alreadyRan)) {
				$codesGenerated = $this->generateMissingSupplierCodes();
				if ($codesGenerated >= 0) {
					// Mark as done so we don't re-run on next activation
					dolibarr_set_const($this->db, 'CABINETMEDFIX_SUPPLIER_CODES_GENERATED', '1', 'chaine', 0, 'Supplier codes bulk generation completed', $conf->entity);
				}
			} else {
				dol_syslog("CabinetMedFix::init: Supplier codes already generated (flag set), skipping bulk generation", LOG_INFO);
			}
		}

		return $initResult;
	}

	/**
	 * Generate supplier codes for all patients that have fournisseur=1 but no code_fournisseur.
	 * Uses Dolibarr's configured code generation module (leopard, elephant, monkey, etc.)
	 *
	 * @return int Number of codes generated, or -1 on error
	 */
	private function generateMissingSupplierCodes()
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		// Find patients that are suppliers but have no supplier code
		$sql = "SELECT rowid, nom as name FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE canvas = 'patient@cabinetmed'";
		$sql .= " AND fournisseur = 1";
		$sql .= " AND (code_fournisseur IS NULL OR code_fournisseur = '' OR code_fournisseur = '-1')";
		$sql .= " AND entity IN (".getEntity('societe').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("CabinetMedFix::generateMissingSupplierCodes SQL Error: ".$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		$count = 0;
		$numRows = $this->db->num_rows($resql);

		if ($numRows == 0) {
			dol_syslog("CabinetMedFix::generateMissingSupplierCodes: All patients already have supplier codes", LOG_INFO);
			return 0;
		}

		// Check if code module is configured and supports auto-generation
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

		if (!$moduleLoaded) {
			dol_syslog("CabinetMedFix::generateMissingSupplierCodes: Cannot load code module ".$module, LOG_WARNING);
			// Fallback: generate simple codes based on rowid
			return $this->generateFallbackSupplierCodes($resql, $numRows);
		}

		$mod = new $module($this->db);

		// Check if this module auto-generates codes
		if (empty($mod->code_auto)) {
			// Module doesn't auto-generate (e.g., leopard) — codes can stay empty if code_null=1
			if (!empty($mod->code_null)) {
				dol_syslog("CabinetMedFix::generateMissingSupplierCodes: Module ".$module." allows null codes, skipping generation", LOG_INFO);
				return 0;
			}
			// code_null=0 but code_auto=0 — need to generate fallback codes
			return $this->generateFallbackSupplierCodes($resql, $numRows);
		}

		// Auto-generate codes using the configured module
		while ($obj = $this->db->fetch_object($resql)) {
			$societe = new Societe($this->db);
			$societe->id = $obj->rowid;
			$societe->name = $obj->name;

			// get_codefournisseur uses type=1 for supplier
			$societe->get_codefournisseur($societe, 1);

			if (!empty($societe->code_fournisseur) && $societe->code_fournisseur != '-1') {
				$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."societe";
				$sqlUpdate .= " SET code_fournisseur = '".$this->db->escape($societe->code_fournisseur)."'";
				$sqlUpdate .= " WHERE rowid = ".(int) $obj->rowid;

				$resUpdate = $this->db->query($sqlUpdate);
				if ($resUpdate) {
					$count++;
				} else {
					dol_syslog("CabinetMedFix::generateMissingSupplierCodes: Failed to update code for patient ".$obj->rowid.": ".$this->db->lasterror(), LOG_ERR);
				}
			}
		}

		$this->db->free($resql);
		dol_syslog("CabinetMedFix::generateMissingSupplierCodes: Generated ".$count." supplier codes out of ".$numRows." patients", LOG_INFO);
		return $count;
	}

	/**
	 * Fallback: generate simple supplier codes when the configured module doesn't auto-generate.
	 * Format: PROV-{rowid} (e.g., PROV-142)
	 *
	 * @param resource $resql  Query result with patient rows
	 * @param int      $numRows Number of rows
	 * @return int Number of codes generated
	 */
	private function generateFallbackSupplierCodes($resql, $numRows)
	{
		$count = 0;

		while ($obj = $this->db->fetch_object($resql)) {
			$code = 'PROV-'.str_pad($obj->rowid, 5, '0', STR_PAD_LEFT);

			// Ensure uniqueness
			$sqlCheck = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."societe WHERE code_fournisseur = '".$this->db->escape($code)."'";
			$resCheck = $this->db->query($sqlCheck);
			if ($resCheck) {
				$objCheck = $this->db->fetch_object($resCheck);
				if ($objCheck->nb > 0) {
					// Already exists, add suffix
					$code = 'PROV-'.str_pad($obj->rowid, 5, '0', STR_PAD_LEFT).'-'.substr(md5($obj->rowid.time()), 0, 4);
				}
			}

			$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."societe";
			$sqlUpdate .= " SET code_fournisseur = '".$this->db->escape($code)."'";
			$sqlUpdate .= " WHERE rowid = ".(int) $obj->rowid;

			$resUpdate = $this->db->query($sqlUpdate);
			if ($resUpdate) {
				$count++;
			} else {
				dol_syslog("CabinetMedFix::generateFallbackSupplierCodes: Failed for patient ".$obj->rowid.": ".$this->db->lasterror(), LOG_ERR);
			}
		}

		$this->db->free($resql);
		dol_syslog("CabinetMedFix::generateFallbackSupplierCodes: Generated ".$count." fallback supplier codes out of ".$numRows." patients", LOG_INFO);
		return $count;
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		// Remove the Acta de Entrega PDF model registration
		$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'pdf_actaentrega' AND type = 'shipping'";
		// Note: We do NOT clear CABINETMEDFIX_SUPPLIER_CODES_GENERATED on remove.
		// This prevents re-running bulk code generation on every deactivate/reactivate cycle.
		// To force re-generation, manually delete the constant from llx_const or run:
		//   DELETE FROM llx_const WHERE name = 'CABINETMEDFIX_SUPPLIER_CODES_GENERATED';
		return $this->_remove($sql, $options);
	}
}
