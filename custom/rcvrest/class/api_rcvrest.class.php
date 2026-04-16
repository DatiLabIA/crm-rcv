<?php
/* Copyright (C) 2025 DatiLab
 * API REST unificada para pacientes y consultas médicas
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');

/**
 * API class for RCV medical CRM
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Rcvrest extends DolibarrApi
{
	/**
	 * @var Societe $company
	 */
	private $company;

	/**
	 * @var ExtConsultation $consultation
	 */
	private $consultation;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->company = new Societe($this->db);
		$this->consultation = new ExtConsultation($this->db);
	}

	// =================================================================
	//  PATIENTS
	// =================================================================

	/**
	 * List patients
	 *
	 * Return list of patients (thirdparties with canvas patient@cabinetmed)
	 *
	 * @param string $sortfield       Sort field {@from query}
	 * @param string $sortorder       Sort order (ASC or DESC) {@from query}
	 * @param int    $limit           Limit for list {@from query} {@min 0}
	 * @param int    $offset          Offset for list {@from query} {@min 0}
	 * @param string $nom             Filter by name (LIKE) {@from query}
	 * @param string $n_documento     Filter by document number (exact) {@from query}
	 * @param string $eps             Filter by EPS id {@from query}
	 * @param string $programa        Filter by program id {@from query}
	 * @param string $datec_from      Filter creation date from (YYYY-MM-DD) {@from query}
	 * @param string $datec_to        Filter creation date to (YYYY-MM-DD) {@from query}
	 * @return array                  Array of patient objects
	 *
	 * @url GET patients
	 * @throws RestException
	 */
	public function listPatients($sortfield = "s.rowid", $sortorder = 'ASC', $limit = 100, $offset = 0, $nom = '', $n_documento = '', $eps = '', $programa = '', $datec_from = '', $datec_to = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'patient', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$sortfield = preg_replace('/[^a-zA-Z0-9_\.]/', '', $sortfield);
		$sortorder = strtoupper($sortorder) === 'DESC' ? 'DESC' : 'ASC';
		$limit = min((int) $limit, 500);
		$offset = max((int) $offset, 0);

		$list = array();

		$sql = "SELECT s.rowid, s.nom, s.name_alias, s.address, s.zip, s.town,";
		$sql .= " s.fk_departement as state_id, s.fk_pays as country_id,";
		$sql .= " s.phone, s.fax, s.email,";
		$sql .= " s.datec, s.tms, s.status, s.canvas,";
		$sql .= " s.note_private, s.note_public,";
		$sql .= " ef.n_documento, ef.eps, ef.programa, ef.medicamento,";
		$sql .= " ef.operador_logistico, ef.medico_tratante, ef.estado_del_paciente,";
		$sql .= " geps.descripcion as eps_label,";
		$sql .= " gprog.nombre as programa_label,";
		$sql .= " gmed.etiqueta as medicamento_label,";
		$sql .= " gop.nombre as operador_logistico_label,";
		$sql .= " gmt.nombre as medico_tratante_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields as ef ON ef.fk_object = s.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."gestion_eps as geps ON geps.rowid = ef.eps";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."gestion_programa as gprog ON gprog.rowid = ef.programa";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."gestion_medicamento as gmed ON gmed.rowid = ef.medicamento";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."gestion_operador as gop ON gop.rowid = ef.operador_logistico";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."gestion_medico as gmt ON gmt.rowid = ef.medico_tratante";
		$sql .= " WHERE s.entity IN (".getEntity('societe').")";
		$sql .= " AND s.canvas = 'patient@cabinetmed'";

		if (!empty($nom)) {
			$sql .= " AND s.nom LIKE '%".$this->db->escape($nom)."%'";
		}
		if (!empty($n_documento)) {
			$sql .= " AND ef.n_documento = '".$this->db->escape($n_documento)."'";
		}
		if (!empty($eps)) {
			$sql .= " AND ef.eps = '".$this->db->escape($eps)."'";
		}
		if (!empty($programa)) {
			$sql .= " AND ef.programa = '".$this->db->escape($programa)."'";
		}
		if (!empty($datec_from)) {
			$sql .= " AND s.datec >= '".$this->db->escape($datec_from)." 00:00:00'";
		}
		if (!empty($datec_to)) {
			$sql .= " AND s.datec <= '".$this->db->escape($datec_to)." 23:59:59'";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $offset);

		dol_syslog("API Rcv::listPatients", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, 'Error executing query: '.$this->db->lasterror());
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$extrafields = array(
				'n_documento'        => $obj->n_documento,
				'eps'                => $obj->eps_label,
				'eps_id'             => $obj->eps,
				'programa'           => $obj->programa_label,
				'programa_id'        => $obj->programa,
				'medicamento'        => $obj->medicamento_label,
				'medicamento_id'     => $obj->medicamento,
				'operador_logistico' => $obj->operador_logistico_label,
				'operador_logistico_id' => $obj->operador_logistico,
				'medico_tratante'    => $obj->medico_tratante_label,
				'medico_tratante_id' => $obj->medico_tratante,
				'estado_del_paciente' => $obj->estado_del_paciente,
			);
			
			// Resolver labels de campos select estáticos
			$this->_resolveSelectStaticLabels($extrafields);
			
			$list[] = array(
				'id'        => (int) $obj->rowid,
				'nom'       => $obj->nom,
				'name_alias' => $obj->name_alias,
				'address'   => $obj->address,
				'zip'       => $obj->zip,
				'town'      => $obj->town,
				'state_id'  => (int) $obj->state_id,
				'country_id' => (int) $obj->country_id,
				'phone'     => $obj->phone,
				'fax'       => $obj->fax,
				'email'     => $obj->email,
				'datec'     => $obj->datec,
				'tms'       => $obj->tms,
				'status'    => (int) $obj->status,
				'extrafields' => $extrafields,
			);
		}
		$this->db->free($resql);

		return $list;
	}

	/**
	 * Get properties of a patient
	 *
	 * @param int $id ID of patient {@from path} {@min 1}
	 * @return array  Patient data
	 *
	 * @url GET patients/{id}
	 * @throws RestException
	 */
	public function getPatient($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'patient', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->company->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Patient not found');
		}
		if ($this->company->canvas !== 'patient@cabinetmed') {
			throw new RestException(404, 'Patient not found (not a patient record)');
		}

		$this->company->fetch_optionals();
		$med_data = $this->_fetchCabinetmedPatient((int) $id);

		// Resolver nombres de estado y país
		$state_name = '';
		$country_name = '';
		if ($this->company->state_id > 0) {
			$sql = "SELECT nom FROM ".MAIN_DB_PREFIX."c_departements WHERE rowid = ".(int)$this->company->state_id;
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$state_name = $obj->nom;
			}
		}
		if ($this->company->country_id > 0) {
			$sql = "SELECT label FROM ".MAIN_DB_PREFIX."c_country WHERE rowid = ".(int)$this->company->country_id;
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$country_name = $obj->label;
			}
		}

		$data = array(
			'id'         => (int) $this->company->id,
			'nom'        => $this->company->nom,
			'name_alias' => $this->company->name_alias,
			'address'    => $this->company->address,
			'zip'        => $this->company->zip,
			'town'       => $this->company->town,
			'state_id'   => (int) $this->company->state_id,
			'state'      => $state_name,
			'country_id' => (int) $this->company->country_id,
			'country'    => $country_name,
			'phone'      => $this->company->phone,
			'fax'        => $this->company->fax,
			'email'      => $this->company->email,
			'note_public'  => $this->company->note_public,
			'note_private' => $this->company->note_private,
			'datec'      => $this->company->datec,
			'tms'        => $this->company->date_modification,
			'status'     => (int) $this->company->status,
			'canvas'     => $this->company->canvas,
			'extrafields' => array(),
			'medical'    => $med_data,
		);

		if (is_array($this->company->array_options)) {
			foreach ($this->company->array_options as $key => $value) {
				$fieldname = preg_replace('/^options_/', '', $key);
				$data['extrafields'][$fieldname] = $value;
			}
			$this->_resolveExtrafieldsLabels($data['extrafields']);
		}

		return $data;
	}

	/**
	 * Create patient
	 *
	 * @param array $request_data Request data
	 * @return int  ID of created patient
	 *
	 * @url POST patients
	 * @throws RestException
	 */
	public function createPatient($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'patient', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		if (empty($request_data['nom'])) {
			throw new RestException(400, 'Field "nom" (last name) is required');
		}

		$this->company->nom        = $request_data['nom'];
		$this->company->name_alias = isset($request_data['name_alias']) ? $request_data['name_alias'] : '';
		$this->company->address    = isset($request_data['address']) ? $request_data['address'] : '';
		$this->company->zip        = isset($request_data['zip']) ? $request_data['zip'] : '';
		$this->company->town       = isset($request_data['town']) ? $request_data['town'] : '';
		$this->company->state_id   = isset($request_data['state_id']) ? (int) $request_data['state_id'] : 0;
		$this->company->country_id = isset($request_data['country_id']) ? (int) $request_data['country_id'] : 0;
		$this->company->phone      = isset($request_data['phone']) ? $request_data['phone'] : '';
		$this->company->fax        = isset($request_data['fax']) ? $request_data['fax'] : '';
		$this->company->email      = isset($request_data['email']) ? $request_data['email'] : '';
		$this->company->note_public  = isset($request_data['note_public']) ? $request_data['note_public'] : '';
		$this->company->note_private = isset($request_data['note_private']) ? $request_data['note_private'] : '';

		$this->company->canvas      = 'patient@cabinetmed';
		$this->company->client      = 1;
		$this->company->code_client = -1;
		$this->company->particulier = 1;

		if (!empty($request_data['extrafields']) && is_array($request_data['extrafields'])) {
			$this->company->array_options = array();
			foreach ($request_data['extrafields'] as $key => $value) {
				$this->company->array_options['options_'.$key] = $value;
			}
		}

		$result = $this->company->create(DolibarrApiAccess::$user);
		if ($result <= 0) {
			$errors = ($this->company->error ? $this->company->error : implode(', ', $this->company->errors));
			throw new RestException(500, 'Error creating patient: '.$errors);
		}

		if (!empty($this->company->array_options)) {
			$this->company->insertExtraFields();
		}

		if (!empty($request_data['medical']) && is_array($request_data['medical'])) {
			$this->_saveCabinetmedPatient($result, $request_data['medical']);
		}

		return $result;
	}

	/**
	 * Update patient
	 *
	 * @param int   $id           ID of patient {@from path} {@min 1}
	 * @param array $request_data Request data
	 * @return int  ID of updated patient
	 *
	 * @url PUT patients/{id}
	 * @throws RestException
	 */
	public function updatePatient($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'patient', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->company->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Patient not found');
		}
		if ($this->company->canvas !== 'patient@cabinetmed') {
			throw new RestException(404, 'Patient not found (not a patient record)');
		}

		// Fetch extrafields to have complete data
		$this->company->fetch_optionals();

		// CRITICAL: Create oldcopy BEFORE modifying any field
		// This is required for the ChangelogTrigger to detect changes
		$this->company->oldcopy = clone $this->company;

		$fields = array('nom', 'name_alias', 'address', 'zip', 'town', 'state_id', 'country_id', 'phone', 'fax', 'email', 'note_public', 'note_private');
		foreach ($fields as $field) {
			if (isset($request_data[$field])) {
				$this->company->$field = $request_data[$field];
			}
		}

		$this->company->canvas = 'patient@cabinetmed';
		$this->company->client = 1;

		if (!empty($request_data['extrafields']) && is_array($request_data['extrafields'])) {
			if (!is_array($this->company->array_options)) {
				$this->company->array_options = array();
			}
			foreach ($request_data['extrafields'] as $key => $value) {
				$this->company->array_options['options_'.$key] = $value;
			}
		}

		// Call update with call_trigger = 1 (default) to fire COMPANY_MODIFY trigger
		$result = $this->company->update($this->company->id, DolibarrApiAccess::$user, 1);
		if ($result <= 0) {
			$errors = ($this->company->error ? $this->company->error : implode(', ', $this->company->errors));
			throw new RestException(500, 'Error updating patient: '.$errors);
		}

		if (!empty($request_data['extrafields'])) {
			$this->company->insertExtraFields();
		}

		if (!empty($request_data['medical']) && is_array($request_data['medical'])) {
			$this->_saveCabinetmedPatient((int) $id, $request_data['medical']);
		}

		return (int) $id;
	}

	// =================================================================
	//  CONSULTATIONS
	// =================================================================

	/**
	 * List consultations
	 *
	 * @param string $sortfield       Sort field {@from query}
	 * @param string $sortorder       Sort order (ASC or DESC) {@from query}
	 * @param int    $limit           Limit for list {@from query} {@min 0}
	 * @param int    $offset          Offset for list {@from query} {@min 0}
	 * @param int    $fk_soc          Filter by patient id {@from query}
	 * @param int    $fk_user         Filter by assigned user id {@from query}
	 * @param int    $status          Filter by status {@from query}
	 * @param string $tipo_atencion   Filter by consultation type {@from query}
	 * @param string $date_start_from Filter date_start from (YYYY-MM-DD) {@from query}
	 * @param string $date_start_to   Filter date_start to (YYYY-MM-DD) {@from query}
	 * @return array                  Array of consultation objects
	 *
	 * @url GET consultations
	 * @throws RestException
	 */
	public function listConsultations($sortfield = "t.rowid", $sortorder = 'DESC', $limit = 100, $offset = 0, $fk_soc = 0, $fk_user = 0, $status = -1, $tipo_atencion = '', $date_start_from = '', $date_start_to = '')
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'consultation', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$sortfield = preg_replace('/[^a-zA-Z0-9_\.]/', '', $sortfield);
		$sortorder = strtoupper($sortorder) === 'DESC' ? 'DESC' : 'ASC';
		$limit = min((int) $limit, 500);
		$offset = max((int) $offset, 0);

		$list = array();

		$sql = "SELECT t.rowid, t.entity, t.fk_soc, t.fk_user,";
		$sql .= " t.date_start, t.date_end, t.tipo_atencion,";
		$sql .= " t.cumplimiento, t.razon_inc, t.mes_actual, t.proximo_mes,";
		$sql .= " t.dificultad, t.motivo, t.diagnostico, t.procedimiento,";
		$sql .= " t.insumos_enf, t.rx_num, t.medicamentos, t.observaciones,";
		$sql .= " t.status, t.custom_data,";
		$sql .= " t.recurrence_enabled, t.recurrence_interval, t.recurrence_unit,";
		$sql .= " t.recurrence_end_type, t.recurrence_end_date, t.recurrence_parent_id,";
		$sql .= " t.note_private, t.note_public,";
		$sql .= " t.datec, t.tms, t.fk_user_creat, t.fk_user_modif,";
		$sql .= " s.nom as patient_nom";
		$sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = t.fk_soc";
		$sql .= " WHERE t.entity IN (".getEntity('extconsultation').")";

		if ($fk_soc > 0) {
			$sql .= " AND t.fk_soc = ".((int) $fk_soc);
		}
		if ($fk_user > 0) {
			$sql .= " AND t.fk_user = ".((int) $fk_user);
		}
		if ($status >= 0) {
			$sql .= " AND t.status = ".((int) $status);
		}
		if (!empty($tipo_atencion)) {
			$sql .= " AND t.tipo_atencion = '".$this->db->escape($tipo_atencion)."'";
		}
		if (!empty($date_start_from)) {
			$sql .= " AND t.date_start >= '".$this->db->escape($date_start_from)." 00:00:00'";
		}
		if (!empty($date_start_to)) {
			$sql .= " AND t.date_start <= '".$this->db->escape($date_start_to)." 23:59:59'";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $offset);

		dol_syslog("API Rcv::listConsultations", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, 'Error executing query: '.$this->db->lasterror());
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$list[] = $this->_consultationRowToArray($obj);
		}
		$this->db->free($resql);

		return $list;
	}

	/**
	 * Get properties of a consultation
	 *
	 * @param int $id ID of consultation {@from path} {@min 1}
	 * @return array  Consultation data
	 *
	 * @url GET consultations/{id}
	 * @throws RestException
	 */
	public function getConsultation($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'consultation', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->consultation->fetch((int) $id);
		if ($result <= 0) {
			throw new RestException(404, 'Consultation not found');
		}

		return $this->_consultationObjToArray($this->consultation);
	}

	/**
	 * Create consultation
	 *
	 * @param array $request_data Request data
	 * @return int  ID of created consultation
	 *
	 * @url POST consultations
	 * @throws RestException
	 */
	public function createConsultation($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'consultation', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		if (empty($request_data['fk_soc'])) {
			throw new RestException(400, 'Field "fk_soc" (patient id) is required');
		}

		$this->_populateConsultation($this->consultation, $request_data);

		$result = $this->consultation->create(DolibarrApiAccess::$user, 1);
		if ($result <= 0) {
			$errors = ($this->consultation->error ? $this->consultation->error : implode(', ', $this->consultation->errors));
			throw new RestException(500, 'Error creating consultation: '.$errors);
		}

		// Asignar usuarios: siempre API user + usuarios adicionales opcionales
		$assigned_users = array(DolibarrApiAccess::$user->id); // Siempre incluir usuario de la API
		if (!empty($request_data['assigned_users']) && is_array($request_data['assigned_users'])) {
			foreach ($request_data['assigned_users'] as $user_id) {
				$user_id = (int) $user_id;
				if ($user_id > 0 && !in_array($user_id, $assigned_users)) {
					$assigned_users[] = $user_id;
				}
			}
		}
		
		// Asignar usuarios a la consulta
		$this->consultation->setAssignedUsers($assigned_users, DolibarrApiAccess::$user);

		return $result;
	}

	/**
	 * Update consultation
	 *
	 * @param int   $id           ID of consultation {@from path} {@min 1}
	 * @param array $request_data Request data
	 * @return int  ID of updated consultation
	 *
	 * @url PUT consultations/{id}
	 * @throws RestException
	 */
	public function updateConsultation($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('rcvrest', 'consultation', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->consultation->fetch((int) $id);
		if ($result <= 0) {
			throw new RestException(404, 'Consultation not found');
		}

		$this->_populateConsultation($this->consultation, $request_data);

		$result = $this->consultation->update(DolibarrApiAccess::$user, 1);
		if ($result < 0) {
			$errors = ($this->consultation->error ? $this->consultation->error : implode(', ', $this->consultation->errors));
			throw new RestException(500, 'Error updating consultation: '.$errors);
		}

		// Actualizar usuarios asignados si se envían en el request
		if (isset($request_data['assigned_users']) && is_array($request_data['assigned_users'])) {
			$assigned_users = array(DolibarrApiAccess::$user->id); // Siempre incluir usuario de la API
			foreach ($request_data['assigned_users'] as $user_id) {
				$user_id = (int) $user_id;
				if ($user_id > 0 && !in_array($user_id, $assigned_users)) {
					$assigned_users[] = $user_id;
				}
			}
			$this->consultation->setAssignedUsers($assigned_users, DolibarrApiAccess::$user);
		}

		return (int) $id;
	}

	// =================================================================
	//  PRIVATE HELPERS — Patients
	// =================================================================

	/**
	 * @param int $fk_soc
	 * @return array
	 */
	private function _fetchCabinetmedPatient($fk_soc)
	{
		$data = array();
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."cabinetmed_patient WHERE fk_soc = ".((int) $fk_soc);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			$obj = $this->db->fetch_object($resql);
			$fields = array(
				'note_antemed', 'note_antechirgen', 'note_antechirortho', 'note_anterhum',
				'note_other', 'note_traitclass', 'note_traitallergie', 'note_traitintol', 'note_traitspec',
				'alert_antemed', 'alert_antechirgen', 'alert_antechirortho', 'alert_anterhum',
				'alert_other', 'alert_traitclass', 'alert_traitallergie', 'alert_traitintol',
				'alert_traitspec', 'alert_note',
			);
			foreach ($fields as $f) {
				if (property_exists($obj, $f)) {
					$data[$f] = $obj->$f;
				}
			}
		}
		$this->db->free($resql);
		return $data;
	}

	/**
	 * @param int   $fk_soc
	 * @param array $medical
	 */
	private function _saveCabinetmedPatient($fk_soc, $medical)
	{
		$allowed = array(
			'note_antemed', 'note_antechirgen', 'note_antechirortho', 'note_anterhum',
			'note_other', 'note_traitclass', 'note_traitallergie', 'note_traitintol', 'note_traitspec',
			'alert_antemed', 'alert_antechirgen', 'alert_antechirortho', 'alert_anterhum',
			'alert_other', 'alert_traitclass', 'alert_traitallergie', 'alert_traitintol',
			'alert_traitspec', 'alert_note',
		);

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmed_patient WHERE fk_soc = ".((int) $fk_soc);
		$resql = $this->db->query($sql);
		$exists = ($resql && $this->db->num_rows($resql) > 0);
		$this->db->free($resql);

		if ($exists) {
			$sets = array();
			foreach ($medical as $key => $value) {
				if (in_array($key, $allowed)) {
					if (strpos($key, 'alert_') === 0) {
						$sets[] = $key." = ".(int) $value;
					} else {
						$sets[] = $key." = '".$this->db->escape($value)."'";
					}
				}
			}
			if (!empty($sets)) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."cabinetmed_patient SET ".implode(', ', $sets);
				$sql .= " WHERE fk_soc = ".((int) $fk_soc);
				$this->db->query($sql);
			}
		} else {
			$columns = array('fk_soc');
			$values = array((int) $fk_soc);
			foreach ($medical as $key => $value) {
				if (in_array($key, $allowed)) {
					$columns[] = $key;
					if (strpos($key, 'alert_') === 0) {
						$values[] = (int) $value;
					} else {
						$values[] = "'".$this->db->escape($value)."'";
					}
				}
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_patient (".implode(', ', $columns).")";
			$sql .= " VALUES (".implode(', ', $values).")";
			$this->db->query($sql);
		}
	}

	/**
	 * Resolve extrafield IDs to labels for relational fields
	 * Modifies the array in place: keeps original as field_id, overwrites field with label
	 *
	 * @param array &$extrafields Extrafields array (by reference)
	 */
	private function _resolveExtrafieldsLabels(&$extrafields)
	{
		// Mapa de campos relacionados con tablas de la BD
		$map = array(
			'eps'                => array('table' => 'gestion_eps', 'col' => 'descripcion'),
			'programa'           => array('table' => 'gestion_programa', 'col' => 'nombre'),
			'medicamento'        => array('table' => 'gestion_medicamento', 'col' => 'etiqueta'),
			'operador_logistico' => array('table' => 'gestion_operador', 'col' => 'nombre'),
			'medico_tratante'    => array('table' => 'gestion_medico', 'col' => 'nombre'),
		);

		foreach ($map as $field => $ref) {
			if (isset($extrafields[$field]) && $extrafields[$field] > 0) {
				$id = (int) $extrafields[$field];
				$sql = "SELECT ".$ref['col']." as label FROM ".MAIN_DB_PREFIX.$ref['table']." WHERE rowid = ".$id;
				$resql = $this->db->query($sql);
				if ($resql && ($obj = $this->db->fetch_object($resql))) {
					$extrafields[$field.'_id'] = (string) $id;
					$extrafields[$field] = $obj->label;
				}
			}
		}

		// Resolver concentración de medicamento
		if (isset($extrafields['concentracion']) && $extrafields['concentracion'] > 0) {
			$id = (int) $extrafields['concentracion'];
			$sql = "SELECT concentracion_display FROM ".MAIN_DB_PREFIX."gestion_medicamento_det WHERE rowid = ".$id;
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$extrafields['concentracion_id'] = (string) $id;
				$extrafields['concentracion'] = $obj->concentracion_display;
			}
		}

		// Resolver campos select con valores estáticos
		$this->_resolveSelectStaticLabels($extrafields);

		// Convertir fechas tipo timestamp a formato legible
		$dateFields = array('birthdate', 'fecha_entregado_guardian', 'fecha_cambio_guardian');
		foreach ($dateFields as $field) {
			if (isset($extrafields[$field]) && is_numeric($extrafields[$field]) && $extrafields[$field] > 0) {
				$extrafields[$field.'_timestamp'] = (int) $extrafields[$field];
				$extrafields[$field] = dol_print_date((int) $extrafields[$field], 'day', 'tzuser');
			}
		}
	}

	/**
	 * Resuelve los labels de campos select con valores estáticos
	 * 
	 * @param array $extrafields Array de extrafields a modificar por referencia
	 */
	private function _resolveSelectStaticLabels(&$extrafields)
	{
		$selectLabels = array(
			'estado_del_paciente' => array(
				1 => 'En Tránsito', 2 => 'En Proceso', 3 => 'Activo en Tratamiento',
				4 => 'Activo Independiente', 5 => 'Activo Por El Programa',
				6 => 'Reactivado', 7 => 'Suspendido', 8 => 'No trazable',
				9 => 'NAP', 10 => 'Inactivo',
			),
			'estado_vital' => array(1 => 'Vivo', 2 => 'Muerto'),
			'tipo_de_status' => array(
				1 => 'Trámite Completo', 2 => 'Trámite Intermedio - Reclama',
				3 => 'Trámite Intermedio - Autoriza', 4 => 'Independiente',
			),
			'regimen' => array(
				1 => 'Contributivo', 2 => 'Subsidiado', 3 => 'Especial',
				4 => 'Particular', 5 => 'Por confirmar',
			),
			'tipo_de_afiliacion' => array(
				1 => 'Beneficiario', 2 => 'Cotizante', 3 => 'Cabeza de Familia',
				4 => 'Por Confirmar', 5 => 'Otro', 6 => 'NA',
			),
			'tipo_de_poblacion' => array(
				1 => 'Población Mestiza', 2 => 'Población Afrocolombiana',
				3 => 'Población Indígena', 4 => 'Población Blanca',
				5 => 'Población Raizal', 6 => 'Población Palenquera',
				7 => 'Población Rrom o Gitana', 8 => 'Población Rural',
				9 => 'Población Urbana', 10 => 'Población Migrante',
			),
			'tipo_de_documento' => array(
				1 => 'Registro Civil', 2 => 'Tarjeta de Identidad',
				3 => 'Cédula de Ciudadanía', 4 => 'Cédula de Extranjería',
				8 => 'Permiso de Protección Temporal', 9 => 'Salvo Conducto',
				10 => 'Sin Identificación', 11 => 'NIT', 13 => 'NA',
				14 => 'Permiso Especial de Permanencia',
			),
		);

		foreach ($selectLabels as $field => $labels) {
			if (isset($extrafields[$field])) {
				$id = (int) $extrafields[$field];
				if ($id > 0 && isset($labels[$id])) {
					$extrafields[$field.'_id'] = $id;
					$extrafields[$field] = $labels[$id];
				}
			}
		}
	}

	// =================================================================
	//  PRIVATE HELPERS — Consultations
	// =================================================================

	/**
	 * @param ExtConsultation $obj
	 * @param array           $data
	 */
	private function _populateConsultation(&$obj, $data)
	{
		$fields = array(
			'fk_soc', 'fk_user', 'tipo_atencion', 'cumplimiento', 'razon_inc',
			'mes_actual', 'proximo_mes', 'dificultad', 'motivo', 'diagnostico',
			'procedimiento', 'insumos_enf', 'rx_num', 'medicamentos', 'observaciones',
			'status', 'note_private', 'note_public',
			'recurrence_enabled', 'recurrence_interval', 'recurrence_unit',
			'recurrence_end_type', 'recurrence_end_date', 'recurrence_parent_id',
		);

		foreach ($fields as $field) {
			if (isset($data[$field])) {
				$obj->$field = $data[$field];
			}
		}

		if (isset($data['date_start'])) {
			$obj->date_start = is_numeric($data['date_start']) ? (int) $data['date_start'] : strtotime($data['date_start']);
		}
		if (isset($data['date_end'])) {
			$obj->date_end = is_numeric($data['date_end']) ? (int) $data['date_end'] : strtotime($data['date_end']);
		}

		if (isset($data['custom_data'])) {
			if (is_array($data['custom_data'])) {
				$existing = array();
				if (!empty($obj->custom_data)) {
					$decoded = json_decode($obj->custom_data, true);
					if (is_array($decoded)) {
						$existing = $decoded;
					}
				}
				$merged = array_merge($existing, $data['custom_data']);
				$obj->custom_data = json_encode($merged);
			} elseif (is_string($data['custom_data'])) {
				$obj->custom_data = $data['custom_data'];
			}
		}
	}

	/**
	 * @param object $obj DB row
	 * @return array
	 */
	private function _consultationRowToArray($obj)
	{
		$custom_data = null;
		if (!empty($obj->custom_data)) {
			$decoded = json_decode($obj->custom_data, true);
			$custom_data = is_array($decoded) ? $decoded : $obj->custom_data;
		}

		// Cargar usuarios asignados directamente desde la tabla
		$assigned_user_ids = array();
		$sql = "SELECT fk_user FROM " . MAIN_DB_PREFIX . "cabinetmed_extcons_users";
		$sql .= " WHERE fk_extcons = " . ((int) $obj->rowid);
		$sql .= " ORDER BY fk_user";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($u = $this->db->fetch_object($resql)) {
				$assigned_user_ids[] = (int) $u->fk_user;
			}
			$this->db->free($resql);
		}

		return array(
			'id'             => (int) $obj->rowid,
			'fk_soc'         => (int) $obj->fk_soc,
			'fk_user'        => (int) $obj->fk_user,
			'assigned_users' => $assigned_user_ids,
			'patient_nom'    => $obj->patient_nom,
			'date_start'     => $obj->date_start,
			'date_end'       => $obj->date_end,
			'tipo_atencion'  => $obj->tipo_atencion,
			'cumplimiento'   => $obj->cumplimiento,
			'razon_inc'      => $obj->razon_inc,
			'mes_actual'     => $obj->mes_actual,
			'proximo_mes'    => $obj->proximo_mes,
			'dificultad'     => (int) $obj->dificultad,
			'motivo'         => $obj->motivo,
			'diagnostico'    => $obj->diagnostico,
			'procedimiento'  => $obj->procedimiento,
			'insumos_enf'    => $obj->insumos_enf,
			'rx_num'         => $obj->rx_num,
			'medicamentos'   => $obj->medicamentos,
			'observaciones'  => $obj->observaciones,
			'status'         => (int) $obj->status,
			'custom_data'    => $custom_data,
			'recurrence_enabled'  => (int) $obj->recurrence_enabled,
			'recurrence_interval' => (int) $obj->recurrence_interval,
			'recurrence_unit'     => $obj->recurrence_unit,
			'recurrence_end_type' => $obj->recurrence_end_type,
			'recurrence_end_date' => $obj->recurrence_end_date,
			'recurrence_parent_id' => $obj->recurrence_parent_id ? (int) $obj->recurrence_parent_id : null,
			'note_private'   => $obj->note_private,
			'note_public'    => $obj->note_public,
			'datec'          => $obj->datec,
			'tms'            => $obj->tms,
			'fk_user_creat'  => (int) $obj->fk_user_creat,
			'fk_user_modif'  => $obj->fk_user_modif ? (int) $obj->fk_user_modif : null,
		);
	}

	/**
	 * @param ExtConsultation $cons
	 * @return array
	 */
	private function _consultationObjToArray($cons)
	{
		$custom_data = null;
		if (!empty($cons->custom_data)) {
			$decoded = json_decode($cons->custom_data, true);
			$custom_data = is_array($decoded) ? $decoded : $cons->custom_data;
		}

		// Cargar usuarios asignados
		$cons->fetchAssignedUsers();
		$assigned_user_ids = array();
		if (!empty($cons->assigned_users)) {
			foreach ($cons->assigned_users as $au) {
				$assigned_user_ids[] = (int) $au['id'];
			}
		}

		return array(
			'id'             => (int) $cons->id,
			'fk_soc'         => (int) $cons->fk_soc,
			'fk_user'        => (int) $cons->fk_user,
			'assigned_users' => $assigned_user_ids,
			'date_start'     => $cons->date_start ? dol_print_date($cons->date_start, 'dayhour', 'gmt') : null,
			'date_end'       => $cons->date_end ? dol_print_date($cons->date_end, 'dayhour', 'gmt') : null,
			'tipo_atencion'  => $cons->tipo_atencion,
			'cumplimiento'   => $cons->cumplimiento,
			'razon_inc'      => $cons->razon_inc,
			'mes_actual'     => $cons->mes_actual,
			'proximo_mes'    => $cons->proximo_mes,
			'dificultad'     => (int) $cons->dificultad,
			'motivo'         => $cons->motivo,
			'diagnostico'    => $cons->diagnostico,
			'procedimiento'  => $cons->procedimiento,
			'insumos_enf'    => $cons->insumos_enf,
			'rx_num'         => $cons->rx_num,
			'medicamentos'   => $cons->medicamentos,
			'observaciones'  => $cons->observaciones,
			'status'         => (int) $cons->status,
			'custom_data'    => $custom_data,
			'recurrence_enabled'  => (int) $cons->recurrence_enabled,
			'recurrence_interval' => (int) $cons->recurrence_interval,
			'recurrence_unit'     => $cons->recurrence_unit,
			'recurrence_end_type' => $cons->recurrence_end_type,
			'recurrence_end_date' => $cons->recurrence_end_date,
			'recurrence_parent_id' => $cons->recurrence_parent_id ? (int) $cons->recurrence_parent_id : null,
			'note_private'   => $cons->note_private,
			'note_public'    => $cons->note_public,
			'datec'          => $cons->datec ? dol_print_date($cons->datec, 'dayhour', 'gmt') : null,
			'tms'            => $cons->tms ? dol_print_date($cons->tms, 'dayhour', 'gmt') : null,
			'fk_user_creat'  => (int) $cons->fk_user_creat,
			'fk_user_modif'  => $cons->fk_user_modif ? (int) $cons->fk_user_modif : null,
		);
	}
}
