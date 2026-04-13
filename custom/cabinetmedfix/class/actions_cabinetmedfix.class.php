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
 * \file    custom/cabinetmedfix/class/actions_cabinetmedfix.class.php
 * \ingroup cabinetmedfix
 * \brief   Hooks for CabinetMedFix module
 */

/**
 * Class ActionsCabinetMedFix
 */
class ActionsCabinetMedFix
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var array Hook results
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var array|null Saved original diagnostico param (for list page optimisation)
	 */
	private $diagOrigParam = null;

	/**
	 * @var array Diagnostico IDs from the current search filter
	 */
	private $diagSearchIds = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook called before updating a thirdparty
	 * 
	 * This ensures that patients keep their client=3 value when edited
	 * even if SOCIETE_DISABLE_CUSTOMERS and SOCIETE_DISABLE_PROSPECTS are enabled
	 *
	 * @param array $parameters Hook parameters
	 * @param object $object The object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0=OK, >0=KO
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter

		// Check if we're in an edit/add context for a thirdparty (patient)
		// IMPORTANT: doActions fires BEFORE Dolibarr loads POST data into $object.
		// CabinetMed card.php re-fetches object and loads POST data AFTER this hook.
		// So we must inject values into $_POST, NOT modify $object directly.
		if (in_array('thirdpartycard', explode(':', $parameters['context']))) {
			// Detect if this is a patient: check object canvas (for update) or POST/GET canvas (for add)
			$isPatient = (!empty($object->canvas) && $object->canvas == 'patient@cabinetmed')
				|| GETPOST('canvas', 'alpha') == 'patient@cabinetmed';

			if (($action == 'update' || $action == 'add') && $isPatient) {

				// --- Fix 1: Ensure patient is always client=3 (customer + prospect) ---
				// CabinetMed form may not include client/customer/prospect fields,
				// or may send client=1 (customer only). Always force 3 for patients.
				if (GETPOSTINT('client') != 3) {
					$_POST['client'] = 3;
					dol_syslog("CabinetMedFix: Injected POST[client]=3 for patient " . ($object->id ?: 'new'), LOG_INFO);
				}

				// --- Fix 2: Ensure patient is always fournisseur=1 (supplier) ---
				// Required for medication collection workflow (patient as supplier in receptions)
				if (!GETPOSTISSET('fournisseur') || GETPOSTINT('fournisseur') <= 0) {
					$_POST['fournisseur'] = 1;
					dol_syslog("CabinetMedFix: Injected POST[fournisseur]=1 for patient " . ($object->id ?: 'new'), LOG_INFO);
				}

				// --- Fix 3: Preserve or generate supplier code ---
				$postSupplierCode = GETPOSTISSET('supplier_code') ? GETPOST('supplier_code', 'alpha') : GETPOST('code_fournisseur', 'alpha');

				if ($action == 'update' && empty($postSupplierCode)) {
					// On update: POST has no supplier code. Preserve existing from DB, or generate new.
					if (!empty($object->code_fournisseur)) {
						// Preserve existing code from DB so it's not cleared
						$_POST['supplier_code'] = $object->code_fournisseur;
						dol_syslog("CabinetMedFix: Preserved existing supplier code " . $object->code_fournisseur, LOG_INFO);
					} else {
						// No existing code: generate one
						$newCode = $this->generateSupplierCodeForPatient($object);
						if (!empty($newCode)) {
							$_POST['supplier_code'] = $newCode;
							dol_syslog("CabinetMedFix: Generated supplier code " . $newCode . " for patient " . $object->id, LOG_INFO);
						}
					}
				}
				// For 'add' action: supplier code will be handled by:
				// - Dolibarr's verify() if code_auto=1 (auto-generation modules like elephant)
				// - The COMPANY_CREATE trigger as a safety net (fills in code after creation when ID is available)

				// --- Fix 4: Prevent race condition on customer code during concurrent patient creation ---
				// The creation form pre-generates a customer code client-side via getNextValue().
				// When two users open the form simultaneously, both get the same code and one fails
				// with a DB duplicate key error on save.
				// Fix: override the pre-generated code in POST with -1, so Patient::create() calls
				// get_codeclient() at actual INSERT time, generating a truly unique code.
				if ($action == 'add') {
					$codeModuleName = getDolGlobalString('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
					$codeDirs = array_merge(array('/core/modules/societe/'), $conf->modules_parts['societe']);
					foreach ($codeDirs as $dirroot) {
						if (dol_include_once($dirroot.$codeModuleName.'.php')) {
							break;
						}
					}
					if (class_exists($codeModuleName)) {
						$tmpCodeMod = new $codeModuleName($this->db);
						if (!empty($tmpCodeMod->code_auto)) {
							$_POST['customer_code'] = -1;
							dol_syslog("CabinetMedFix: Forced customer_code=-1 for patient add to prevent race condition", LOG_INFO);
						}
					}
				}
			}
		}

		// Context: thirdpartylist (patients list page)
		// Store search values before we modify anything
		if (in_array('thirdpartylist', explode(':', $parameters['context']))) {
			$raw = GETPOST('search_options_diagnostico');
			if (is_array($raw)) {
				$this->diagSearchIds = array_filter(array_map('intval', $raw));
			} elseif (!empty($raw)) {
				$this->diagSearchIds = array_filter(array_map('intval', explode(',', $raw)));
			}
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = '';
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Hook formObjectOptions - Replace heavy chkbxlst diagnostico field with Select2 AJAX
	 *
	 * Called from extrafields_edit.tpl.php, extrafields_add.tpl.php and extrafields_view.tpl.php
	 * before showOptionals() / the view loop.
	 *
	 * We only intercept in edit/create mode (where showInputField generates 16K <option> elements)
	 * and in inline-edit mode for the diagnostico field specifically.
	 * In view mode we let the default rendering happen (showOutputField is server-side, no crash).
	 *
	 * @param array $parameters Hook parameters
	 * @param object $object The object (Societe)
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0=OK (still run showOptionals for other fields)
	 */

	/**
	 * Hook tabContentCreateSupplierOrder — replaces the supplier order create form table.
	 *
	 * Problem: Dolibarr's default form calls select_company() with fournisseur=1 filter.
	 * Since ALL patients are marked fournisseur=1, this loads ~20K <option> elements into
	 * the DOM, freezing the browser before any JS can run.
	 *
	 * Solution: We intercept this hook to print a lightweight form that uses a Select2 AJAX
	 * widget for the supplier field — loading results on demand as the user types.
	 *
	 * When a supplier IS already selected (page reload after selection), we return 0 so
	 * Dolibarr handles it normally (it only shows the supplier name, no dropdown).
	 *
	 * @param array $parameters Hook parameters
	 * @param CommandeFournisseur $object The order object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 1=replace standard content, 0=let Dolibarr handle it
	 */
	public function tabContentCreateSupplierOrder($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $form, $extrafields;
		global $societe, $note_public, $note_private;

		if ($action !== 'create') {
			return 0;
		}

		// If supplier already selected (page reloaded after pick), Dolibarr's own form
		// just shows the name — no dropdown, no performance issue. Let it handle it.
		if (!empty($societe->id) && $societe->id > 0) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/class/doleditor.class.php';

		$ajaxUrl = dol_buildpath('/cabinetmedfix/ajax/search_supplier.php', 1);

		print '<table class="border centpercent">' . "\n";

		// Ref
		print '<tr><td class="titlefieldcreate">' . $langs->trans('Ref') . '</td>';
		print '<td>' . $langs->trans('Draft') . '</td></tr>' . "\n";

		// Supplier — empty select, converted to Select2 AJAX by JS below
		print '<tr><td class="fieldrequired">' . $langs->trans('Supplier') . '</td>';
		print '<td>';
		print img_picto('', 'company', 'class="pictofixedwidth"');
		print '<select id="socid" name="socid" class="minwidth300" style="width:50%">';
		print '<option value=""></option>';
		print '</select>';
		print ' <a href="' . DOL_URL_ROOT . '/societe/card.php?action=create&client=0&fournisseur=1'
			. '&backtopage=' . urlencode($_SERVER["PHP_SELF"] . '?action=create') . '">'
			. '<span class="fa fa-plus-circle valignmiddle paddingleft" title="'
			. $langs->trans("AddThirdParty") . '"></span></a>';
		print '</td></tr>' . "\n";

		// Planned delivery date (fecha de consumo)
		print '<tr><td>' . $langs->trans('DateDeliveryPlanned') . '</td><td>';
		print img_picto('', 'action', 'class="pictofixedwidth"');
		$usehourmin = getDolGlobalString('SUPPLIER_ORDER_USE_HOUR_FOR_DELIVERY_DATE') ? 1 : 0;
		print $form->selectDate(-1, 'liv_', $usehourmin, $usehourmin, 0, 'add');
		print '</td></tr>' . "\n";

		// Note public
		print '<tr><td>' . $langs->trans('NotePublic') . '</td><td>';
		$doleditor = new DolEditor('note_public', isset($note_public) ? $note_public : '', '', 80, 'dolibarr_notes', 'In', false, false, !getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PUBLIC') ? 0 : 1, ROWS_3, '90%');
		print $doleditor->Create(1);
		print '</td></tr>' . "\n";

		// Note private
		print '<tr><td>' . $langs->trans('NotePrivate') . '</td><td>';
		$doleditor = new DolEditor('note_private', isset($note_private) ? $note_private : '', '', 80, 'dolibarr_notes', 'In', false, false, !getDolGlobalString('FCKEDITOR_ENABLE_NOTE_PRIVATE') ? 0 : 1, ROWS_3, '90%');
		print $doleditor->Create(1);
		print '</td></tr>' . "\n";

		// Extra fields on the order header (if any defined)
		print $object->showOptionals($extrafields, 'create');

		print "</table>\n";

		// Initialize Select2 AJAX on the supplier field
		$nonce = getNonce();
		print '<script nonce="' . $nonce . '">' . "\n";
		print 'jQuery(document).ready(function($) {' . "\n";
		print '  if (!$.fn.select2) return;' . "\n";
		print '  $("#socid").select2({' . "\n";
		print '    ajax: {' . "\n";
		print '      url: ' . json_encode($ajaxUrl) . ',' . "\n";
		print '      dataType: "json",' . "\n";
		print '      delay: 300,' . "\n";
		print '      data: function(params) {' . "\n";
		print '        return { action: "search", term: params.term || "", page: params.page || 1 };' . "\n";
		print '      },' . "\n";
		print '      processResults: function(data) {' . "\n";
		print '        return { results: data.results || [], pagination: { more: !!data.more } };' . "\n";
		print '      },' . "\n";
		print '      cache: true' . "\n";
		print '    },' . "\n";
		print '    minimumInputLength: 2,' . "\n";
		print '    placeholder: ' . json_encode($langs->trans('SelectThirdParty')) . ',' . "\n";
		print '    allowClear: true,' . "\n";
		print '    width: "resolve"' . "\n";
		print '  });' . "\n";
		// Auto-reload page with selected supplier — same behaviour as default Dolibarr
		print '  $("#socid").on("select2:select", function() {' . "\n";
		print '    $("input[name=action]").val("create");' . "\n";
		print '    $("form[name=add]").submit();' . "\n";
		print '  });' . "\n";
		print '});' . "\n";
		print '</script>' . "\n";

		return 1; // Suppress Dolibarr's default form table (with 20K select options)
	}

	/**
	 * Hook tabContentCreateOrder — replaces the sales order create form table.
	 *
	 * Problem: Dolibarr's default form calls select_company() which loads ALL patients
	 * (client=1/2/3) into thousands of <option> elements, freezing the browser.
	 *
	 * Solution: When no customer is selected yet ($socid == 0), we intercept and show
	 * only a lightweight Select2 AJAX customer picker. Once a customer is selected,
	 * the form auto-submits with socid set and we return 0, letting Dolibarr render
	 * the full form normally (which only shows the company name, no dropdown).
	 *
	 * @param array      $parameters  Hook parameters
	 * @param Commande   $object      The sales order object
	 * @param string     $action      Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 1=replace standard content, 0=let Dolibarr handle it
	 */
	public function tabContentCreateOrder($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $socid;

		if ($action !== 'create') {
			return 0;
		}

		// If customer already selected, Dolibarr's own form just shows the name — no dropdown,
		// no performance issue. Let it handle it normally.
		if (!empty($socid) && $socid > 0) {
			return 0;
		}

		$ajaxUrl = dol_buildpath('/cabinetmedfix/ajax/search_customer.php', 1);

		// Minimal table: just Ref + Customer selector.
		// After the user picks a customer, the form auto-submits and reloads with socid > 0,
		// at which point our hook returns 0 and Dolibarr shows the full form.
		print '<table class="border centpercent">' . "\n";

		// Ref
		print '<tr><td class="titlefieldcreate">' . $langs->trans('Ref') . '</td>';
		print '<td>' . $langs->trans('Draft') . '</td></tr>' . "\n";

		// Customer — empty select, converted to Select2 AJAX by JS below
		print '<tr><td class="fieldrequired">' . $langs->trans('Customer') . '</td>';
		print '<td>';
		print img_picto('', 'company', 'class="pictofixedwidth"');
		print '<select id="socid" name="socid" class="minwidth300" style="width:50%">';
		print '<option value=""></option>';
		print '</select>';
		print ' <a href="' . DOL_URL_ROOT . '/societe/card.php?action=create&customer=3&fournisseur=0'
			. '&backtopage=' . urlencode($_SERVER["PHP_SELF"] . '?action=create') . '">'
			. '<span class="fa fa-plus-circle valignmiddle paddingleft" title="'
			. $langs->trans("AddThirdParty") . '"></span></a>';
		print '</td></tr>' . "\n";

		print "</table>\n";

		// Initialize Select2 AJAX
		$nonce = getNonce();
		print '<script nonce="' . $nonce . '">' . "\n";
		print 'jQuery(document).ready(function($) {' . "\n";
		print '  if (!$.fn.select2) return;' . "\n";
		print '  $("#socid").select2({' . "\n";
		print '    ajax: {' . "\n";
		print '      url: ' . json_encode($ajaxUrl) . ',' . "\n";
		print '      dataType: "json",' . "\n";
		print '      delay: 300,' . "\n";
		print '      data: function(params) {' . "\n";
		print '        return { action: "search", term: params.term || "", page: params.page || 1 };' . "\n";
		print '      },' . "\n";
		print '      processResults: function(data) {' . "\n";
		print '        return { results: data.results || [], pagination: { more: !!data.more } };' . "\n";
		print '      },' . "\n";
		print '      cache: true' . "\n";
		print '    },' . "\n";
		print '    minimumInputLength: 2,' . "\n";
		print '    placeholder: ' . json_encode($langs->trans('SelectThirdParty')) . ',' . "\n";
		print '    allowClear: true,' . "\n";
		print '    width: "resolve"' . "\n";
		print '  });' . "\n";
		// Auto-reload page with selected customer — same behaviour as default Dolibarr
		print '  $("#socid").on("select2:select", function() {' . "\n";
		print '    $("input[name=action]").val("create");' . "\n";
		print '    $("input[name=changecompany]").val("1");' . "\n";
		print '    $("form[name=crea_commande]").submit();' . "\n";
		print '  });' . "\n";
		print '});' . "\n";
		print '</script>' . "\n";

		return 1; // Suppress Dolibarr's default form table (with 20K select options)
	}

	/**
	 * Hook formAddObjectLine — runs just before the "add new line" form is rendered.
	 *
	 * For supplier orders (ordersuppliercard) we hide columns that are irrelevant
	 * in the medical dispensary workflow: VAT, unit price HT, unit price TTC,
	 * discount, line total, and the free-text HTML description editor.
	 *
	 * NOTE: formAddObjectLine does NOT print $hookmanager->resPrint in card.php,
	 * so any output set in that hook is silently discarded. This hook
	 * (formCreateProductSupplierOptions) fires inside objectline_create.tpl.php
	 * which DOES print resPrint — so this is the correct place to inject JS.
	 *
	 * @param array        $parameters  Hook parameters (htmlname=addproduct)
	 * @param CommonObject $object      The supplier order object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int 0=continue with default rendering
	 */
	public function formCreateProductSupplierOptions($parameters, &$object, &$action, $hookmanager)
	{
		$contexts = explode(':', $parameters['context']);

		if (!in_array('ordersuppliercard', $contexts)) {
			return 0;
		}

		$nonce = getNonce();
		$out = '';

		// --- CSS ---
		// Injected here as a <style> tag; modern browsers apply it globally regardless of position in DOM.
		// Column hiding is also done in tabContentViewSupplierOrder (via direct echo) for non-draft views.
		$out .= '<style>' . "\n";
		// 1. Hide irrelevant table columns
		$out .= '.linecolvat,.linecoluht,.linecoluttc,.linecoldiscount,.linecolht{display:none!important}' . "\n";
		// 2. Hide the CKEditor description textarea and its wrapper (not needed on medical POs).
		//    !important overrides inline styles that CKEditor may set.
		$out .= '#dp_desc,#cke_dp_desc{display:none!important}' . "\n";
		// 3. Lay out the custom-fields section as a horizontal flex strip so both extrafields
		//    (Serial/Lote and Fecha de vencimiento) appear side-by-side, each as label + control.
		$out .= '#extrafield_lines_area_create{' . "\n";
		$out .= '  display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px 32px;padding:6px 0;' . "\n";
		$out .= '}' . "\n";
		// Each field block: override Dolibarr's inline-block wrappers with flex so label and
		// input are vertically aligned and tightly paired.
		$out .= '#extrafield_lines_area_create [class*="fieldline_options_"]{' . "\n";
		$out .= '  display:flex!important;flex-direction:column;gap:2px;' . "\n";
		$out .= '}' . "\n";
		// Inner divs Dolibarr wraps around label and input — reset inline-block to normal flow.
		$out .= '#extrafield_lines_area_create [class*="fieldline_options_"]>div{' . "\n";
		$out .= '  display:block!important;' . "\n";
		$out .= '}' . "\n";
		// Label style: compact, bold, smaller text
		$out .= '#extrafield_lines_area_create [class*="fieldline_options_"]>div:first-child{' . "\n";
		$out .= '  font-size:.85em;font-weight:600;color:#555;white-space:nowrap;' . "\n";
		$out .= '}' . "\n";
		// Serial input: wider and accepts multiple serials
		$out .= 'input[name="options_serial_batch"]{min-width:280px!important}' . "\n";
		// Date selects: consistent height with other inputs
		$out .= 'select[name^="options_lot_expiry_date"]{height:28px}' . "\n";
		$out .= '</style>' . "\n";

		// --- JS: set placeholder + hide orphan <br> ---
		$out .= '<script nonce="' . $nonce . '">' . "\n";
		$out .= 'jQuery(function($){' . "\n";
		$out .= '  var dp=$("#dp_desc");if(dp.length)dp.prev("br").hide();' . "\n";
		// Set helpful placeholder so user knows multiple serials are supported
		$out .= '  $("input[name=\'options_serial_batch\']").attr("placeholder","SN001, SN002, SN003 (separar por coma)");' . "\n";
		$out .= '});' . "\n";
		// Also set placeholder on dynamically added fields (line edit)
		$out .= '$(document).on("focus","input[name=\'options_serial_batch\']",function(){' . "\n";
		$out .= '  if(!$(this).attr("placeholder"))$(this).attr("placeholder","SN001, SN002, SN003 (separar por coma)");' . "\n";
		$out .= '});' . "\n";
		$out .= '</script>' . "\n";

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Hook tabContentViewSupplierOrder — fires when viewing an existing supplier order (all statuses).
	 * Used to inject CSS/JS that hides irrelevant columns (VAT, prices, discount, total) in the
	 * line table. We echo directly here because card.php does not print $hookmanager->resPrint
	 * for this hook.
	 *
	 * @param array        $parameters  Hook parameters
	 * @param CommonObject $object      The supplier order object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int 0=continue with default rendering
	 */
	public function tabContentViewOrder($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('ordercard', $contexts)) {
			return 0;
		}

		// Only for DRAFT orders — validated orders show linked receptions via showLinkedObjectBlock
		if ((int) $object->statut !== 0) {
			return 0;
		}

		$patientSocid = (int) $object->socid;
		if ($patientSocid <= 0) {
			return 0;
		}

		// Fetch validated receptions not yet used in a confirmed order
		$sqlRec  = "SELECT r.rowid, r.ref, r.date_creation";
		$sqlRec .= " FROM ".MAIN_DB_PREFIX."reception r";
		$sqlRec .= " WHERE r.fk_soc = ".$patientSocid;
		$sqlRec .= " AND r.fk_statut > 0";
		$sqlRec .= " AND r.entity IN (".getEntity('reception').")";
		$sqlRec .= " AND NOT EXISTS (";
		$sqlRec .= "  SELECT 1 FROM ".MAIN_DB_PREFIX."element_element ee";
		$sqlRec .= "  INNER JOIN ".MAIN_DB_PREFIX."commande oc ON oc.rowid = ee.fk_source";
		$sqlRec .= "  WHERE ee.fk_target = r.rowid AND ee.targettype = 'reception'";
		$sqlRec .= "  AND ee.sourcetype = 'commande' AND oc.fk_statut >= 1";
		$sqlRec .= " )";
		$sqlRec .= " ORDER BY r.date_creation ASC";
		$resRec = $db->query($sqlRec);

		if (!$resRec || $db->num_rows($resRec) === 0) {
			return 0;
		}

		$ajaxUrl = dol_buildpath('/cabinetmedfix/ajax/load_reception_products.php', 1);
		$token   = !empty($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '';
		$orderId = (int) $object->id;

		$html  = '<div class="info" style="margin-bottom:12px;padding:10px 16px">'."\n";
		$html .= '<strong>Recepciones disponibles — selecciona una o varias para cargar los medicamentos:</strong>'."\n";
		$html .= '<div style="margin-top:8px">'."\n";

		while ($rec = $db->fetch_object($resRec)) {
			$dateStr = dol_print_date($db->jdate($rec->date_creation), 'day');
			$label   = dol_htmlentities($rec->ref).($dateStr ? ' <span class="opacitymedium" style="font-size:.9em">('.$dateStr.')</span>' : '');
			$html .= '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:20px;cursor:pointer;font-weight:normal">';
			$html .= '<input type="checkbox" class="cmfix-rcv-chk" value="'.(int) $rec->rowid.'" style="cursor:pointer;transform:scale(1.2)">';
			$html .= '&nbsp;'.$label;
			$html .= '</label>'."\n";
		}
		$db->free($resRec);

		$html .= '</div>'."\n";
		$html .= '<div style="margin-top:10px">'."\n";
		$html .= '<button type="button" id="cmfix-load-btn" class="button">';
		$html .= 'Cargar medicamentos en la orden';
		$html .= '</button>';
		$html .= ' <span id="cmfix-load-msg" style="margin-left:10px;font-style:italic"></span>';
		$html .= '</div>'."\n";
		$html .= '</div>'."\n";

		$html .= '<script>'."\n";
		$html .= '(function($) {'."\n";
		$html .= '  $("#cmfix-load-btn").on("click", function() {'."\n";
		$html .= '    var ids = $(".cmfix-rcv-chk:checked").map(function() { return $(this).val(); }).get();'."\n";
		$html .= '    if (!ids.length) {'."\n";
		$html .= '      $("#cmfix-load-msg").css("color","#c55").text("Selecciona al menos una recepción.");'."\n";
		$html .= '      return;'."\n";
		$html .= '    }'."\n";
		$html .= '    var btn = $(this).prop("disabled", true);'."\n";
		$html .= '    $("#cmfix-load-msg").css("color","#555").text("Cargando medicamentos...");'."\n";
		$html .= '    console.log("[cmfix] Enviando AJAX. order_id="+'.$orderId.'+", recepciones:", ids);'."\n";
		$html .= '    $.ajax({'."\n";
		$html .= '      url: '.json_encode($ajaxUrl).','."\n";
		$html .= '      method: "POST",'."\n";
		$html .= '      data: $.param({ order_id: '.$orderId.', token: '.json_encode($token).' }) + "&" +'."\n";
		$html .= '            ids.map(function(v){ return "reception_ids[]=" + encodeURIComponent(v); }).join("&"),'."\n";
		$html .= '      contentType: "application/x-www-form-urlencoded",'."\n";
		$html .= '      dataType: "text",'."\n";
		$html .= '      success: function(raw, status, xhr) {'."\n";
		$html .= '        console.log("[cmfix] Respuesta HTTP " + xhr.status + ":", raw);'."\n";
		$html .= '        var data;'."\n";
		$html .= '        try { data = JSON.parse(raw); } catch(e) {'."\n";
		$html .= '          btn.prop("disabled", false);'."\n";
		$html .= '          console.error("[cmfix] Respuesta no es JSON:", raw);'."\n";
		$html .= '          $("#cmfix-load-msg").css("color","#c55").html("Error: la respuesta del servidor no es JSON.<br><small>" + $("<span>").text(raw.substring(0,300)).html() + "</small>");'."\n";
		$html .= '          return;'."\n";
		$html .= '        }'."\n";
		$html .= '        if (data.success) {'."\n";
		$html .= '          var msg = "Cargado (" + data.added + " productos). Recargando...";'."\n";
		$html .= '          if (data.warning) msg += " Advertencia: " + data.warning;'."\n";
		$html .= '          $("#cmfix-load-msg").css("color","#3a3").text(msg);'."\n";
		$html .= '          window.location.reload();'."\n";
		$html .= '        } else {'."\n";
		$html .= '          btn.prop("disabled", false);'."\n";
		$html .= '          $("#cmfix-load-msg").css("color","#c55").text("Error: " + (data.error || "Error desconocido"));'."\n";
		$html .= '          console.error("[cmfix] Error del servidor:", data);'."\n";
		$html .= '        }'."\n";
		$html .= '      },'."\n";
		$html .= '      error: function(xhr, textStatus, errorThrown) {'."\n";
		$html .= '        btn.prop("disabled", false);'."\n";
		$html .= '        console.error("[cmfix] AJAX error:", xhr.status, textStatus, errorThrown, xhr.responseText);'."\n";
		$html .= '        var msg = "Error HTTP " + xhr.status;'."\n";
		$html .= '        try { var d = JSON.parse(xhr.responseText); msg += ": " + (d.error || errorThrown); } catch(e) { msg += ": " + (errorThrown || textStatus); }'."\n";
		$html .= '        if (xhr.status === 0) msg = "Error de red: no se pudo conectar al servidor.";'."\n";
		$html .= '        if (xhr.status === 403) msg = "Error 403: Sin permisos o sesión expirada. Intenta recargar la página.";'."\n";
		$html .= '        $("#cmfix-load-msg").css("color","#c55").text(msg);'."\n";
		$html .= '      }'."\n";
		$html .= '    });'."\n";
		$html .= '  });'."\n";
		$html .= '})(jQuery);'."\n";
		$html .= '</script>'."\n";

		echo $html;
		return 0;
	}

	public function tabContentViewSupplierOrder($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		$contexts = explode(':', $parameters['context']);

		if (!in_array('ordersuppliercard', $contexts)) {
			return 0;
		}

		// Ocultar columnas irrelevantes en líneas de productos.
		echo '<style>';
		echo '.linecolvat, .linecoluht, .linecoluttc, .linecoldiscount, .linecolht { display: none !important; }';
		echo '</style>';

		// Ocultar botón "Crear recepción" (dispatch.php) y reemplazarlo por "Ver recepción"
		// si ya existe una recepción vinculada automáticamente.
		// Nota: fetchObjectLinked() aún no fue llamado aquí, así que consultamos directamente.
		$receptionUrl = '';
		$sqlRec = "SELECT r.rowid FROM ".MAIN_DB_PREFIX."reception r"
			." INNER JOIN ".MAIN_DB_PREFIX."element_element ee ON ee.fk_target = r.rowid"
			." WHERE ee.sourcetype = 'order_supplier'"
			."   AND ee.targettype = 'reception'"
			."   AND ee.fk_source = ".(int) $object->id
			."   AND r.fk_statut >= 0"
			." LIMIT 1";
		$resRec = $db->query($sqlRec);
		if ($resRec && $db->num_rows($resRec) > 0) {
			$rowRec = $db->fetch_object($resRec);
			$receptionUrl = dol_buildpath('/reception/card.php', 1).'?id='.(int) $rowRec->rowid;
		}
		if ($resRec) {
			$db->free($resRec);
		}

		$nonce = getNonce();
		$js  = "\n".'<script nonce="'.$nonce.'">'."\n";
		$js .= 'jQuery(document).ready(function($) {'."\n";
		if ($receptionUrl) {
			// Ya existe recepción: ocultar botón de crear recepción manual y mostrar "Ver recepción"
			$js .= '  $("a[href*=\'dispatch.php\']").closest(".divButAction, .inline-block").hide();'."\n";
			$js .= '  var $btnArea = $(".tabsAction");'."\n";
			$js .= '  if ($btnArea.length) {'."\n";
			$js .= '    var label = '.json_encode($langs->trans('Reception')).';'."\n";
			$js .= '    var url   = '.json_encode($receptionUrl).';'."\n";
			$js .= '    $btnArea.prepend(\'<div class="inline-block divButAction"><a class="butAction" href="\'+url+\'">\'+label+\'</a></div>\');'."\n";
			$js .= '  }'."\n";
		}
		// Si no hay recepción, el botón dispatch.php permanece visible (crear recepción manual).
		$js .= '});'."\n";
		$js .= '</script>'."\n";
		echo $js;

		return 0;
	}

	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $db, $extrafields, $langs, $socid;

		$contexts = explode(':', $parameters['context']);

		// --- Hide irrelevant fields on medical order / intervention create forms ---
		// Only fires on action=create for the 3 target pages.
		// Uses jQuery (always available in Dolibarr) to hide the containing <tr>
		// of each irrelevant field — the field still submits, it's just invisible.
		if ($action === 'create') {
			// --- Hide irrelevant fields ---
			$fieldsToHide = array();
			if (in_array('ordercard', $contexts)) {
				$fieldsToHide = array('cond_reglement_id', 'mode_reglement_id', 'projectid');
			} elseif (in_array('ordersuppliercard', $contexts)) {
				$fieldsToHide = array('refsupplier', 'cond_reglement_id', 'mode_reglement_id', 'projectid');
			} elseif (in_array('fichintercard', $contexts)) {
				$fieldsToHide = array('projectid', 'contratid');
			}

			if (!empty($fieldsToHide)) {
				$js = "\n" . '<script nonce="' . getNonce() . '">' . "\n";
				$js .= 'jQuery(document).ready(function($) {' . "\n";
				foreach ($fieldsToHide as $fieldName) {
					$js .= '  $("[name=\'' . $fieldName . '\']").closest("tr").hide();' . "\n";
				}
				$js .= '});' . "\n";
				$js .= '</script>' . "\n";
				$this->resprints = (!empty($this->resprints) ? $this->resprints : '') . $js;
			}

			// --- Reception availability panel (ordercard, create, patient selected) ---
			if (in_array('ordercard', $contexts)) {
				$patientSocid = (int) (!empty($parameters['socid']) ? $parameters['socid'] : $socid);
				if ($patientSocid > 0) {
					$sqlRec  = "SELECT r.rowid, r.ref, r.date_creation";
					$sqlRec .= " FROM ".MAIN_DB_PREFIX."reception r";
					$sqlRec .= " WHERE r.fk_soc = ".$patientSocid;
					$sqlRec .= " AND r.fk_statut > 0";
					$sqlRec .= " AND r.entity IN (".getEntity('reception').")";
					$sqlRec .= " AND NOT EXISTS (";
					$sqlRec .= "  SELECT 1 FROM ".MAIN_DB_PREFIX."element_element ee";
					$sqlRec .= "  INNER JOIN ".MAIN_DB_PREFIX."commande oc ON oc.rowid = ee.fk_source";
					$sqlRec .= "  WHERE ee.fk_target = r.rowid AND ee.targettype = 'reception'";
					$sqlRec .= "  AND ee.sourcetype = 'commande' AND oc.fk_statut >= 1";
					$sqlRec .= " )";
					$sqlRec .= " ORDER BY r.date_creation ASC";
					$resRec = $db->query($sqlRec);

					if ($resRec && $db->num_rows($resRec) > 0) {
						$items = array();
						while ($rec = $db->fetch_object($resRec)) {
							$url     = dol_buildpath('/reception/card.php', 1).'?id='.(int) $rec->rowid;
							$dateStr = dol_print_date($db->jdate($rec->date_creation), 'day');
							$items[] = '<a href="'.dol_escape_htmltag($url).'" target="_blank">'.dol_htmlentities($rec->ref).'</a>'
								.($dateStr ? ' <span class="opacitymedium">('.$dateStr.')</span>' : '');
						}
						$db->free($resRec);
						$recHtml = implode(' &nbsp;&bull;&nbsp; ', $items);
					} else {
						$recHtml = '<span class="opacitymedium">—</span>';
					}

					$panel  = '<tr style="background:#f0f7ff">';
					$panel .= '<td class="titlefield"><strong>Recepciones disponibles</strong></td>';
					$panel .= '<td>'.$recHtml.'</td>';
					$panel .= '</tr>'."\n";

					$this->resprints = (!empty($this->resprints) ? $this->resprints : '') . $panel;
				}
			}

			if (!in_array('thirdpartycard', $contexts)) {
				// Devolver 1 para ordersuppliercard: evita que showOptionals muestre fk_entrepot_reception
				// (el campo fue eliminado, pero esto previene que reaparezca si algo lo recrea).
				if (in_array('ordersuppliercard', $contexts)) {
					return 1;
				}
				return 0;
			}
		}

		// --- Lot/serial selector on expedition create form ---
		// Replaces Dolibarr's default "one row per lot" interface with a single
		// dropdown per product line, so the user just picks the lot from a list.
		// The form's hidden batchl/qtyl fields are still submitted normally;
		// JavaScript zeroes all of them and only sets qty on the chosen lot.
		if (in_array('expeditioncard', $contexts) && $action === 'create') {
			$nonce = getNonce();
			$js  = "\n".'<script nonce="'.$nonce.'">'."\n";
			$js .= '(function($){'."\n";
			$js .= '  $(document).ready(function(){'."\n";
			// Collect all batch rows grouped by line index i
			$js .= '    var groups = {};'."\n";
			$js .= '    $("input[type=hidden][name^=batchl]").each(function(){'."\n";
			$js .= '      var m = /^batchl(\\d+)_(\\d+)$/.exec($(this).attr("name"));'."\n";
			$js .= '      if (!m) return;'."\n";
			$js .= '      var i = m[1], j = m[2];'."\n";
			$js .= '      var cell = $(this).closest("td");'."\n";
			$js .= '      var row  = cell.closest("tr");'."\n";
			$js .= '      var qtyInput = row.find("input.qtyl");'."\n";
			// Extract the lot name from the cell text (strips hidden field value artifact)
			$js .= '      var cellText = cell.clone().children("input").remove().end().text().trim().replace(/\\s+/g," ");'."\n";
			$js .= '      if (!groups[i]) groups[i] = [];'."\n";
			$js .= '      groups[i].push({j:j, cell:cell, row:row, qtyIn:qtyInput, text:cellText, preQty: parseFloat(qtyInput.val())||0});'."\n";
			$js .= '    });'."\n";
			$js .= '    $.each(groups, function(i, batches){'."\n";
			$js .= '      if (!batches.length) return;'."\n";
			// Determine qty needed for this line from qtyasked - already delivered
			$js .= '      var qtyAsked     = parseFloat($("#qtyasked"+i).text().trim()) || 1;'."\n";
			$js .= '      var qtyDelivered = parseFloat($("#qtydelivered"+i).find(".badge, span, b").first().text().trim()) || 0;'."\n";
			$js .= '      var qtyNeeded    = Math.max(1, qtyAsked - qtyDelivered);'."\n";
			// Build a select element
			$js .= '      var sel = $("<select>").addClass("minwidth300 select2bs5").css("max-width","500px");'."\n";
			$js .= '      sel.append($("<option>").val("").text("— Seleccione lote/serial —"));'."\n";
			$js .= '      var preSelected = "";'."\n";
			$js .= '      $.each(batches, function(idx, b){'."\n";
			$js .= '        var opt = $("<option>").val(b.j).text(b.text);'."\n";
			$js .= '        if (b.preQty > 0 && !preSelected) { opt.attr("selected","selected"); preSelected = b.j; }'."\n";
			$js .= '        sel.append(opt);'."\n";
			$js .= '      });'."\n";
			// Insert a new row before the first batch row, then hide original rows
			$js .= '      var newRow = $("<tr>").addClass("oddeven");'."\n";
			$js .= '      newRow.append($("<td>").attr("colspan","3"));'."\n";
			$js .= '      newRow.append($("<td>").addClass("center").html("<strong>"+qtyNeeded+"</strong>"));'."\n";
			$js .= '      var selCell = $("<td>").addClass("left").append(sel);'."\n";
			$js .= '      newRow.append(selCell);'."\n";
			$js .= '      batches[0].row.before(newRow);'."\n";
			// Zero all qty inputs and hide original rows
			$js .= '      $.each(batches, function(idx,b){ b.qtyIn.val(0); b.row.hide(); });'."\n";
			// On change: set qty for selected, zero all others
			$js .= '      function applySelection(selectedJ){'."\n";
			$js .= '        $.each(batches, function(idx,b){'."\n";
			$js .= '          b.qtyIn.val(b.j === selectedJ ? qtyNeeded : 0);'."\n";
			$js .= '        });'."\n";
			$js .= '      }'."\n";
			$js .= '      sel.on("change", function(){ applySelection($(this).val()); });'."\n";
			// Apply pre-selection if any lot was already set
			$js .= '      if (preSelected) { applySelection(preSelected); sel.val(preSelected); }'."\n";
			$js .= '    });'."\n";
			$js .= '  });'."\n";
			$js .= '})(jQuery);'."\n";
			$js .= '</script>'."\n";
			$this->resprints = (!empty($this->resprints) ? $this->resprints : '') . $js;
			return 0;
		}

		// --- Serial/lot autocomplete on supplier order line form ---
		// Fires on ordersuppliercard for non-create actions (view, editline, etc.)
		// where the "add line" / "edit line" form with options_serial_batch is visible.
		// Uses jQuery UI autocomplete (bundled in Dolibarr) + an AJAX endpoint that
		// queries llx_product_lot. Product ID is auto-detected from #idprodfournprice
		// value changes so suggestions are filtered to the selected product.
		if (in_array('ordersuppliercard', $contexts)) {
			$ajaxLotsUrl = dol_buildpath('/cabinetmedfix/ajax/get_product_lots.php', 1);
			$nonce = getNonce();
			$js  = "\n".'<script nonce="'.$nonce.'">'."\n";
			$js .= '(function($) {'."\n";
			$js .= '  var _lProdId = 0;'."\n";
			$js .= '  var _lUrl = '.json_encode($ajaxLotsUrl).';'."\n";
			// Track product selection to filter lot suggestions per-product
			$js .= '  $(document).on("change", "#idprodfournprice", function() {'."\n";
			$js .= '    var v = String($(this).val() || "");'."\n";
			$js .= '    var m = /^idprod_(\\d+)$/.exec(v);'."\n";
			$js .= '    if (m) { _lProdId = parseInt(m[1], 10); return; }'."\n";
			$js .= '    if (/^\\d+$/.test(v) && parseInt(v, 10) > 0) {'."\n";
			$js .= '      $.getJSON(_lUrl, {action: "resolve", supplier_price_id: v}, function(d) {'."\n";
			$js .= '        if (d && d.product_id) { _lProdId = d.product_id; }'."\n";
			$js .= '      });'."\n";
			$js .= '    } else { _lProdId = 0; }'."\n";
			$js .= '  });'."\n";
			// Autocomplete setup
			$js .= '  function _applyLotAC(el) {'."\n";
			$js .= '    if ($(el).data("lot-ac")) return;'."\n";
			$js .= '    $(el).data("lot-ac", 1).autocomplete({'."\n";
			$js .= '      minLength: 1,'."\n";
			$js .= '      source: function(req, resp) {'."\n";
			$js .= '        var p = {action: "search", term: req.term};'."\n";
			$js .= '        if (_lProdId > 0) { p.product_id = _lProdId; }'."\n";
			$js .= '        $.getJSON(_lUrl, p, resp).fail(function() { resp([]); });'."\n";
			$js .= '      }'."\n";
			$js .= '    });'."\n";
			$js .= '  }'."\n";
			// Apply to inputs already on the page and to line-edit inputs added dynamically
			$js .= '  $(document).ready(function() {'."\n";
			$js .= '    $("input[name=\'options_serial_batch\']").each(function() { _applyLotAC(this); });'."\n";
			$js .= '  });'."\n";
			$js .= '  $(document).on("focus", "input[name=\'options_serial_batch\']", function() { _applyLotAC(this); });'."\n";
			$js .= '})(jQuery);'."\n";
			$js .= '</script>'."\n";
			$this->resprints = (!empty($this->resprints) ? $this->resprints : '') . $js;
			if (!in_array('thirdpartycard', $contexts)) {
				return 1; // Suprimir extrafields_view.tpl.php loop para evitar que muestre fk_entrepot_reception como entero crudo
			}
		}

		// Only act on thirdparty context (patients) for the rest
		if (!in_array('thirdpartycard', $contexts)) {
			return 0;
		}

		// --- Gender dropdown fix: replace hardcoded options with all cabinetmed genders ---
		$this->fixGenderDropdown($object, $action);

		// --- Customer code field: hide on create when code_auto is enabled ---
		// The creation form (card_create.tpl.php) pre-generates the code via getNextValue().
		// This causes race conditions when two users create patients simultaneously.
		// doActions (Fix 4) already forces customer_code=-1 on POST so the code is regenerated
		// at INSERT time. Here we also hide the field so users don't see/interact with the
		// pre-generated value that will be discarded anyway.
		if ($action === 'create') {
			$codeModuleName = getDolGlobalString('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
			$codeDirs = array_merge(array('/core/modules/societe/'), $conf->modules_parts['societe']);
			foreach ($codeDirs as $dirroot) {
				if (dol_include_once($dirroot.$codeModuleName.'.php')) {
					break;
				}
			}
			if (class_exists($codeModuleName)) {
				$tmpCodeMod = new $codeModuleName($this->db);
				if (!empty($tmpCodeMod->code_auto)) {
					$js  = "\n".'<script nonce="'.getNonce().'">'."\n";
					$js .= 'jQuery(document).ready(function($) {'."\n";
					$js .= '  $("input#customer_code").closest("tr").hide();'."\n";
					$js .= '  console.log("CabinetMedFix: Customer code field hidden (code_auto enabled)");'."\n";
					$js .= '});'."\n";
					$js .= '</script>'."\n";
					$this->resprints = (!empty($this->resprints) ? $this->resprints : '').$js;
				}
			}
		}

		$tableElement = !empty($object->table_element) ? $object->table_element : 'societe';

		// Check if 'diagnostico' extrafield exists and is of type chkbxlst
		if (empty($extrafields->attributes[$tableElement]['type']['diagnostico'])) {
			return 0;
		}
		if ($extrafields->attributes[$tableElement]['type']['diagnostico'] !== 'chkbxlst') {
			return 0;
		}

		// Determine if we're in a form context (edit/create) where the 16K options crash
		$isFormMode = in_array($action, array('edit', 'create', 'update'));
		$isInlineEditDiag = ($action == 'edit_extras' && GETPOST('attribute', 'restricthtml') == 'diagnostico');

		if (!$isFormMode && !$isInlineEditDiag) {
			// View mode - let default rendering handle it (showOutputField is server-side, no crash)
			return 0;
		}

		// --- We're in edit/create mode: intercept the diagnostico field ---

		// Get current value
		$currentValue = '';
		if (!empty($object->array_options['options_diagnostico'])) {
			$currentValue = $object->array_options['options_diagnostico'];
		}

		// Remove diagnostico from extrafields so showOptionals()/view-loop skips it
		$savedAttrs = array();
		$attrKeys = array('label', 'type', 'size', 'default', 'computed', 'unique', 'required', 'param',
			'perms', 'list', 'pos', 'align', 'enabled', 'langfile', 'help', 'css', 'cssview', 'csslist',
			'hidden', 'mandatoryfieldsofedit', 'totalizable', 'alwayseditable');
		foreach ($attrKeys as $ak) {
			if (isset($extrafields->attributes[$tableElement][$ak]['diagnostico'])) {
				$savedAttrs[$ak] = $extrafields->attributes[$tableElement][$ak]['diagnostico'];
				unset($extrafields->attributes[$tableElement][$ak]['diagnostico']);
			}
		}
		if (isset($extrafields->attributes[$tableElement]['label']['diagnostico'])) {
			unset($extrafields->attributes[$tableElement]['label']['diagnostico']);
		}

		// Build AJAX URL
		$ajaxUrl = dol_buildpath('/cabinetmedfix/ajax/diagnostico_search.php', 1);
		$fieldLabel = !empty($savedAttrs['label']) ? $langs->transnoentities($savedAttrs['label']) : 'Diagnóstico';
		$isRequired = !empty($savedAttrs['required']) ? ' fieldrequired' : '';

		// Determine colspan from parameters
		$colspanValue = '3';
		if (!empty($parameters['colspanvalue'])) {
			$colspanValue = $parameters['colspanvalue'];
		}

		// Build our lightweight replacement HTML
		$out = '';

		if ($isInlineEditDiag) {
			// Inline edit mode: wrap in a form like the view template does
			$fieldid = ($object->table_element == 'societe') ? 'socid' : 'id';
			$out .= '<tr class="trextrafields_diagnostico">';
			$out .= '<td class="titlefield wordbreak">';
			$out .= '<table class="nobordernopadding centpercent"><tr><td>' . $fieldLabel . '</td></tr></table>';
			$out .= '</td>';
			$out .= '<td' . ($colspanValue ? ' colspan="' . $colspanValue . '"' : '') . '>';
			$out .= '<form enctype="multipart/form-data" action="' . $_SERVER["PHP_SELF"] . '?' . $fieldid . '=' . $object->id . '" method="post" name="formextra">';
			$out .= '<input type="hidden" name="action" value="update_extras">';
			$out .= '<input type="hidden" name="attribute" value="diagnostico">';
			$out .= '<input type="hidden" name="token" value="' . newToken() . '">';
			$out .= '<input type="hidden" name="' . $fieldid . '" value="' . $object->id . '">';
		} else {
			// Full edit/create form row
			$out .= '<tr class="trextrafields_diagnostico">';
			$out .= '<td class="titlefield wordbreak' . $isRequired . '">';
			$out .= $fieldLabel;
			if (!empty($savedAttrs['help'])) {
				$out .= ' ' . img_help(1, $langs->transnoentities($savedAttrs['help']));
			}
			$out .= '</td>';
			$out .= '<td' . ($colspanValue ? ' colspan="' . $colspanValue . '"' : '') . '>';
		}

		// Hidden field that Dolibarr checks to know the multiselect was present in the form
		$out .= '<input type="hidden" name="options_diagnostico_multiselect" value="1">';

		// Lightweight select - only pre-selected options, no 16K DOM nodes
		$out .= '<select id="options_diagnostico" class="diagnostico-select2-ajax" multiple name="options_diagnostico[]" style="width: 100%; min-width: 400px;">';

		if (!empty($currentValue)) {
			$ids = array_filter(array_map('intval', explode(',', $currentValue)));
			if (!empty($ids)) {
				$sql = "SELECT rowid, codigo, description FROM " . MAIN_DB_PREFIX . "gestion_diagnostico";
				$sql .= " WHERE rowid IN (" . implode(',', $ids) . ")";
				$resql = $db->query($sql);
				if ($resql) {
					while ($obj = $db->fetch_object($resql)) {
						$text = $obj->description;
						if (!empty($obj->codigo)) {
							$text = $obj->codigo . ' - ' . $obj->description;
						}
						$out .= '<option value="' . (int) $obj->rowid . '" selected>' . dol_htmlentities($text) . '</option>';
					}
					$db->free($resql);
				}
			}
		}

		$out .= '</select>';

		if ($isInlineEditDiag) {
			$out .= ' <input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans('Modify')) . '">';
			$out .= '</form>';
		}

		// Inline script to initialize Select2 AJAX
		$out .= "\n" . '<script nonce="' . getNonce() . '">' . "\n";
		$out .= 'jQuery(document).ready(function($) {' . "\n";
		$out .= '  if ($.fn.select2) {' . "\n";
		$out .= '    $("#options_diagnostico").select2({' . "\n";
		$out .= '      ajax: {' . "\n";
		$out .= '        url: "' . dol_escape_js($ajaxUrl) . '",' . "\n";
		$out .= '        dataType: "json",' . "\n";
		$out .= '        delay: 300,' . "\n";
		$out .= '        data: function(params) {' . "\n";
		$out .= '          return { action: "search", term: params.term || "", page: params.page || 1 };' . "\n";
		$out .= '        },' . "\n";
		$out .= '        processResults: function(data, params) {' . "\n";
		$out .= '          params.page = params.page || 1;' . "\n";
		$out .= '          return { results: data.results || [], pagination: { more: data.pagination ? data.pagination.more : false } };' . "\n";
		$out .= '        },' . "\n";
		$out .= '        cache: true' . "\n";
		$out .= '      },' . "\n";
		$out .= '      minimumInputLength: 2,' . "\n";
		$out .= '      placeholder: "Buscar diagnóstico por código o descripción...",' . "\n";
		$out .= '      allowClear: true,' . "\n";
		$out .= '      multiple: true,' . "\n";
		$out .= '      width: "100%",' . "\n";
		$out .= '      language: {' . "\n";
		$out .= '        inputTooShort: function() { return "Escribe al menos 2 caracteres para buscar..."; },' . "\n";
		$out .= '        noResults: function() { return "No se encontraron diagnósticos"; },' . "\n";
		$out .= '        searching: function() { return "Buscando..."; },' . "\n";
		$out .= '        loadingMore: function() { return "Cargando más resultados..."; }' . "\n";
		$out .= '      },' . "\n";
		$out .= '      templateResult: function(item) {' . "\n";
		$out .= '        if (item.loading) return item.text;' . "\n";
		$out .= '        var parts = (item.text || "").split(" - ");' . "\n";
		$out .= '        if (parts.length >= 2) {' . "\n";
		$out .= '          var $c = $("<div>");' . "\n";
		$out .= '          $c.append($("<strong>").css({"margin-right":"8px","color":"#2196F3"}).text(parts[0]));' . "\n";
		$out .= '          $c.append($("<span>").text(parts.slice(1).join(" - ")));' . "\n";
		$out .= '          return $c;' . "\n";
		$out .= '        }' . "\n";
		$out .= '        return item.text;' . "\n";
		$out .= '      },' . "\n";
		$out .= '      templateSelection: function(item) {' . "\n";
		$out .= '        if (!item.id) return item.text;' . "\n";
		$out .= '        var parts = (item.text || "").split(" - ");' . "\n";
		$out .= '        if (parts.length >= 2) return parts[0] + " - " + (parts.slice(1).join(" - ").length > 40 ? parts.slice(1).join(" - ").substring(0,40) + "..." : parts.slice(1).join(" - "));' . "\n";
		$out .= '        return item.text;' . "\n";
		$out .= '      }' . "\n";
		$out .= '    });' . "\n";
		$out .= '    console.log("CabinetMedFix: Diagnostico Select2 AJAX initialized");' . "\n";
		$out .= '  }' . "\n";
		$out .= '});' . "\n";
		$out .= '</script>' . "\n";

		$out .= '</td></tr>';

		// Append (not overwrite) — fixGenderDropdown may have already set resprints
		$this->resprints = (!empty($this->resprints) ? $this->resprints : '').$out;

		// DO NOT restore the diagnostico attributes.
		// showOptionals() / the view loop runs AFTER this returns using the same $extrafields object.
		// If we restore, it would still render the 16K options and crash.
		// Saving (setOptionalsFromPost) happens on a SEPARATE HTTP request where attributes are intact.

		return 0; // Return 0 so showOptionals still runs for other fields
	}

	/**
	 * Hook printFieldListWhere — fires AFTER extrafields_list_search_sql.tpl.php
	 *
	 * We modify the diagnostico extrafield param to add a WHERE (1=0) filter.
	 * This prevents showInputField from querying the 16K rows table for the
	 * search filter dropdown.
	 * The SQL WHERE clause for the actual search was already built before
	 * this hook fires, so filtering still works correctly.
	 *
	 * @param  array      $parameters  Hook parameters
	 * @param  object     $object      The object
	 * @param  string     $action      Current action
	 * @param  HookManager $hookmanager Hook manager
	 * @return int 0=OK
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		global $extrafields;

		if (!in_array('thirdpartylist', explode(':', $parameters['context']))) {
			return 0;
		}

		$te = !empty($object->table_element) ? $object->table_element : 'societe';

		// Check that diagnostico exists as a chkbxlst field
		if (empty($extrafields->attributes[$te]['type']['diagnostico'])
			|| $extrafields->attributes[$te]['type']['diagnostico'] !== 'chkbxlst') {
			return 0;
		}

		// Save original param so we can restore it after the filter input renders
		$this->diagOrigParam = $extrafields->attributes[$te]['param']['diagnostico'];

		// Modify param: add WHERE (1=0) so the options query returns 0 rows
		if (is_array($this->diagOrigParam) && !empty($this->diagOrigParam['options'])) {
			$origKey = array_keys($this->diagOrigParam['options'])[0];
			$parts   = explode(':', $origKey, 5);
			// Rebuild with (1:=:0) filter at position 4
			$newKey  = ($parts[0] ?? '') . ':' . ($parts[1] ?? '') . ':'
				. ($parts[2] ?? 'rowid') . ':' . ($parts[3] ?? '') . ':(1:=:0)';
			$extrafields->attributes[$te]['param']['diagnostico'] = array(
				'options' => array($newKey => null)
			);
			dol_syslog('CabinetMedFix: Diagnostico param modified for lightweight list filter', LOG_DEBUG);
		}

		return 0;
	}

	/**
	 * Hook printFieldListOption — fires AFTER extrafields_list_search_input.tpl.php
	 *
	 * 1. Restores the original diagnostico param (so column display works).
	 * 2. Outputs a JSON block with currently-selected search values + labels
	 *    so the JS can pre-populate the Select2 AJAX widget.
	 *
	 * @param  array      $parameters  Hook parameters
	 * @param  object     $object      The object
	 * @param  string     $action      Current action
	 * @param  HookManager $hookmanager Hook manager
	 * @return int 0=OK
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager)
	{
		global $extrafields;

		if (!in_array('thirdpartylist', explode(':', $parameters['context']))) {
			return 0;
		}

		$te = !empty($object->table_element) ? $object->table_element : 'societe';

		// Restore original param — critical for column value display later
		if ($this->diagOrigParam !== null) {
			$extrafields->attributes[$te]['param']['diagnostico'] = $this->diagOrigParam;
			$this->diagOrigParam = null;
		}

		// Output pre-selected search data as JSON for JS pre-population
		if (!empty($this->diagSearchIds)) {
			$preselected = array();
			$sql  = "SELECT rowid, codigo, description FROM " . MAIN_DB_PREFIX . "gestion_diagnostico";
			$sql .= " WHERE rowid IN (" . implode(',', $this->diagSearchIds) . ")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$text = $obj->description;
					if (!empty($obj->codigo)) {
						$text = $obj->codigo . ' - ' . $obj->description;
					}
					$preselected[] = array('id' => (int) $obj->rowid, 'text' => $text);
				}
				$this->db->free($resql);
			}
			$this->resprints = "\n" . '<script type="application/json" id="diag-search-preselect">'
				. json_encode($preselected) . '</script>' . "\n";
		}

		return 0;
	}

	/**
	 * Hook to fix getNomUrl for patients
	 * 
	 * This intercepts the getNomUrl method call for Societe objects
	 * and replaces URLs pointing to /societe/card.php with /custom/cabinetmed/card.php
	 * for patients with canvas=patient@cabinetmed
	 *
	 * @param array $parameters Hook parameters
	 * @param object $object The object (Societe)
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0=continue, 1=replace standard behavior
	 */
	public function getNomUrl($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		// Only process for Societe objects with patient canvas
		if (is_object($object) && get_class($object) == 'Societe') {
			if (!empty($object->canvas) && $object->canvas == 'patient@cabinetmed') {
				// Build custom URL for cabinetmed module
				$url = dol_buildpath('/cabinetmed/card.php', 1);
				$url .= '?socid=' . $object->id;
				$url .= '&canvas=' . urlencode($object->canvas);

				// Get parameters from getNomUrl call
				$withpicto = isset($parameters['withpicto']) ? $parameters['withpicto'] : 0;
				$option = isset($parameters['option']) ? $parameters['option'] : '';
				$maxlen = isset($parameters['maxlen']) ? $parameters['maxlen'] : 0;
				$notooltip = isset($parameters['notooltip']) ? $parameters['notooltip'] : 0;
				$save_lastsearch_value = isset($parameters['save_lastsearch_value']) ? $parameters['save_lastsearch_value'] : 0;
				$addlinktonotes = isset($parameters['addlinktonotes']) ? $parameters['addlinktonotes'] : 0;

				// Build the link HTML
				$label = '';
				if (empty($notooltip)) {
					$label = ' title="' . dol_escape_htmltag($object->name, 1) . '"';
				}

				$linkstart = '<a href="' . $url . '"' . $label . '>';
				$linkend = '</a>';

				$result = '';

				// Add picto if requested
				if ($withpicto) {
					$result .= $linkstart;
					$result .= img_object(($notooltip ? '' : $label), 'user-injured', 'class="pictofixedwidth"');
					$result .= $linkend . ' ';
				}

				// Add name
				$result .= $linkstart;
				$result .= '<span class="valignmiddle">';
				if ($maxlen > 0) {
					$result .= dol_trunc($object->name, $maxlen);
				} else {
					$result .= $object->name;
				}
				$result .= '</span>';
				$result .= $linkend;

				// Add name alias if requested
				if ($addlinktonotes && !empty($object->name_alias)) {
					$result .= ' <span class="opacitymedium">(' . $object->name_alias . ')</span>';
				}

				// Return the custom HTML
				$this->resprints = $result;
				return 1; // Replace standard behavior
			}
		}

		return 0; // Continue with standard behavior
	}

	/**
	 * Inject JS to fix the gender dropdown on patient forms.
	 *
	 * CabinetMed hardcodes the gender filter to only TE_UNKNOWN, TE_HOMME, TE_FEMME.
	 * This method queries all active cabinetmed genders from the DB and injects
	 * JavaScript to replace the <select> options, surviving CabinetMed updates.
	 *
	 * @param object $object The thirdparty/patient object
	 * @param string $action Current action (edit, create, etc.)
	 * @return void
	 */
	private function fixGenderDropdown($object, $action)
	{
		global $langs, $mysoc;

		// Only in form contexts where the dropdown is rendered
		if (!in_array($action, array('edit', 'create', 'update', ''))) {
			return;
		}

		// Query all active gender options (TE_UNKNOWN + all cabinetmed module entries)
		$sql = "SELECT id, code, libelle as label FROM ".MAIN_DB_PREFIX."c_typent";
		$sql .= " WHERE active = 1";
		$sql .= " AND (fk_country IS NULL OR fk_country = ".(empty($mysoc->country_id) ? '0' : (int) $mysoc->country_id).")";
		$sql .= " AND (code = 'TE_UNKNOWN' OR module = 'cabinetmed')";
		$sql .= " ORDER BY position, id";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$options = array();
		while ($obj = $this->db->fetch_object($resql)) {
			// Use translation if available, otherwise use DB label
			$label = ($langs->trans($obj->code) != $obj->code) ? $langs->trans($obj->code) : $obj->label;
			$options[] = array('id' => (int) $obj->id, 'label' => $label);
		}
		$this->db->free($resql);

		if (empty($options)) {
			return;
		}

		$jsonOptions = json_encode($options);
		$currentId = !empty($object->typent_id) ? (int) $object->typent_id : 0;

		// Inject JS to replace the select options after page load
		// Must destroy Select2 first, modify options, then reinitialize Select2
		$js = "\n".'<script nonce="'.getNonce().'">'."\n";
		$js .= 'jQuery(document).ready(function($) {'."\n";
		$js .= '  var sel = $("select[name=typent_id]");'."\n";
		$js .= '  if (sel.length === 0) { console.log("CabinetMedFix: typent_id select not found"); return; }'."\n";
		$js .= '  var opts = '.$jsonOptions.';'."\n";
		$js .= '  var current = '.$currentId.';'."\n";
		$js .= "\n";
		$js .= '  // Destroy Select2 if initialized'."\n";
		$js .= '  if (sel.hasClass("select2-hidden-accessible")) {'."\n";
		$js .= '    sel.select2("destroy");'."\n";
		$js .= '  }'."\n";
		$js .= "\n";
		$js .= '  // Replace options on the raw <select>'."\n";
		$js .= '  sel.empty();'."\n";
		$js .= '  sel.append($("<option>").val("").text(""));'."\n";
		$js .= '  $.each(opts, function(i, o) {'."\n";
		$js .= '    var opt = $("<option>").val(o.id).text(o.label);'."\n";
		$js .= '    if (o.id === current) opt.prop("selected", true);'."\n";
		$js .= '    sel.append(opt);'."\n";
		$js .= '  });'."\n";
		$js .= "\n";
		$js .= '  // Reinitialize Select2 with same config Dolibarr uses'."\n";
		$js .= '  sel.select2({'."\n";
		$js .= '    dir: "ltr",'."\n";
		$js .= '    width: "resolve",'."\n";
		$js .= '    minimumInputLength: 0,'."\n";
		$js .= '    language: (typeof select2arrayoflanguage === "undefined") ? "en" : select2arrayoflanguage,'."\n";
		$js .= '    containerCssClass: ":all:",'."\n";
		$js .= '    selectionCssClass: ":all:",'."\n";
		$js .= '    dropdownCssClass: "ui-dialog",'."\n";
		$js .= '    templateResult: function(data) { return data.text; },'."\n";
		$js .= '    templateSelection: function(data) { return data.text; }'."\n";
		$js .= '  });'."\n";
		$js .= "\n";
		$js .= '  console.log("CabinetMedFix: Gender dropdown updated with " + opts.length + " options (Select2 reinitialized)");'."\n";
		$js .= '});'."\n";
		$js .= '</script>'."\n";

		// Append to resprints (may already have diagnostico content)
		$this->resprints = (!empty($this->resprints) ? $this->resprints : '').$js;
	}

	/**
	 * Generate a supplier code for a patient using the configured Dolibarr code module.
	 * Used by doActions hook when injecting supplier data into POST.
	 *
	 * @param  Societe $object  The patient/thirdparty object (already fetched from DB)
	 * @return string           Generated code, or empty string if code_null is allowed
	 */
	protected function generateSupplierCodeForPatient($object)
	{
		global $conf;

		$codeModule = getDolGlobalString('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
		$dirsociete = array_merge(array('/core/modules/societe/'), $conf->modules_parts['societe']);
		$codeModuleLoaded = false;

		foreach ($dirsociete as $dirroot) {
			$res = dol_include_once($dirroot.$codeModule.'.php');
			if ($res) {
				$codeModuleLoaded = true;
				break;
			}
		}

		if (!$codeModuleLoaded) {
			dol_syslog("CabinetMedFix: Could not load code module " . $codeModule, LOG_WARNING);
			return 'PROV-'.str_pad($object->id, 5, '0', STR_PAD_LEFT);
		}

		$codeMod = new $codeModule($this->db);

		if (!empty($codeMod->code_auto)) {
			// Auto-generate (e.g., mod_codeclient_elephant)
			// Parameter 1 = type supplier
			$code = $codeMod->getNextValue($object, 1);
			return ($code && $code != -1) ? $code : '';
		} elseif (!empty($codeMod->code_null)) {
			// Null allowed (e.g., mod_codeclient_leopard with code_null=1)
			return '';
		} else {
			// Manual entry required but can't be null: use fallback
			return 'PROV-'.str_pad($object->id, 5, '0', STR_PAD_LEFT);
		}
	}
}
