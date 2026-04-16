<?php
/* Copyright (C) 2025 DatiLab
 * API REST para pacientes (Societe con canvas patient@cabinetmed)
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * API class for patients
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Rcvpatients extends DolibarrApi
{
	/**
	 * @var array Fields for listing output
	 */
	public static $FIELDS = array('nom');

	/**
	 * @var Societe $company {@type Societe}
	 */
	public $company;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->company = new Societe($this->db);
	}

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
	 * @url GET /
	 * @throws RestException
	 */
	public function index($sortfield = "s.rowid", $sortorder = 'ASC', $limit = 100, $offset = 0, $nom = '', $n_documento = '', $eps = '', $programa = '', $datec_from = '', $datec_to = '')
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'patient', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		// Sanitize sort parameters
		$sortfield = preg_replace('/[^a-zA-Z0-9_\.]/', '', $sortfield);
		$sortorder = strtoupper($sortorder) === 'DESC' ? 'DESC' : 'ASC';
		$limit = min((int) $limit, 500);
		$offset = max((int) $offset, 0);

		$list = array();

		$sql = "SELECT s.rowid, s.nom, s.firstname, s.name_alias, s.address, s.zip, s.town,";
		$sql .= " s.fk_departement as state_id, s.fk_pays as country_id,";
		$sql .= " s.phone, s.fax, s.email,";
		$sql .= " s.datec, s.tms, s.status, s.canvas,";
		$sql .= " s.note_private, s.note_public,";
		$sql .= " ef.n_documento, ef.eps, ef.programa, ef.medicamento,";
		$sql .= " ef.operador_logistico, ef.medico_tratante";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields as ef ON ef.fk_object = s.rowid";
		$sql .= " WHERE s.entity IN (".getEntity('societe').")";
		$sql .= " AND s.canvas = 'patient@cabinetmed'";

		// Apply filters
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

		dol_syslog("API Rcvpatients::index", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, 'Error executing query: '.$this->db->lasterror());
		}

		$num = $this->db->num_rows($resql);

		while ($obj = $this->db->fetch_object($resql)) {
			$patient = array(
				'id'        => (int) $obj->rowid,
				'nom'       => $obj->nom,
				'firstname' => $obj->firstname,
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
				'extrafields' => array(
					'n_documento'        => $obj->n_documento,
					'eps'                => $obj->eps,
					'programa'           => $obj->programa,
					'medicamento'        => $obj->medicamento,
					'operador_logistico' => $obj->operador_logistico,
					'medico_tratante'    => $obj->medico_tratante,
				),
			);
			$list[] = $patient;
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
	 * @url GET {id}
	 * @throws RestException 401 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function get($id)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'patient', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->company->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Patient not found');
		}

		// Verify this is a patient
		if ($this->company->canvas !== 'patient@cabinetmed') {
			throw new RestException(404, 'Patient not found (not a patient record)');
		}

		$this->company->fetch_optionals();

		// Load cabinetmed_patient data
		$med_data = $this->_fetchCabinetmedPatient((int) $id);

		$data = array(
			'id'         => (int) $this->company->id,
			'nom'        => $this->company->nom,
			'firstname'  => $this->company->firstname,
			'name_alias' => $this->company->name_alias,
			'address'    => $this->company->address,
			'zip'        => $this->company->zip,
			'town'       => $this->company->town,
			'state_id'   => (int) $this->company->state_id,
			'country_id' => (int) $this->company->country_id,
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

		// Map extrafields
		if (is_array($this->company->array_options)) {
			foreach ($this->company->array_options as $key => $value) {
				$fieldname = preg_replace('/^options_/', '', $key);
				$data['extrafields'][$fieldname] = $value;
			}
		}

		return $data;
	}

	/**
	 * Create patient
	 *
	 * @param array $request_data Request data
	 * @return int  ID of created patient
	 *
	 * @url POST /
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'patient', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		if (empty($request_data['nom'])) {
			throw new RestException(400, 'Field "nom" (last name) is required');
		}

		// Populate Societe fields
		$this->company->nom        = $request_data['nom'];
		$this->company->firstname  = isset($request_data['firstname']) ? $request_data['firstname'] : '';
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

		// Force patient canvas and client type
		$this->company->canvas     = 'patient@cabinetmed';
		$this->company->client     = 1;
		$this->company->code_client = -1; // Auto-generate
		$this->company->particulier = 1;

		// Handle extrafields
		if (!empty($request_data['extrafields']) && is_array($request_data['extrafields'])) {
			$this->company->array_options = array();
			foreach ($request_data['extrafields'] as $key => $value) {
				$this->company->array_options['options_'.$key] = $value;
			}
		}

		$result = $this->company->create($user);
		if ($result <= 0) {
			$errors = ($this->company->error ? $this->company->error : implode(', ', $this->company->errors));
			throw new RestException(500, 'Error creating patient: '.$errors);
		}

		// Insert extrafields explicitly (in case create() didn't handle them)
		if (!empty($this->company->array_options)) {
			$this->company->insertExtraFields();
		}

		// Insert cabinetmed_patient record if medical data provided
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
	 * @url PUT {id}
	 * @throws RestException
	 */
	public function put($id, $request_data = null)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'patient', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->company->fetch((int) $id);
		if (!$result) {
			throw new RestException(404, 'Patient not found');
		}
		if ($this->company->canvas !== 'patient@cabinetmed') {
			throw new RestException(404, 'Patient not found (not a patient record)');
		}

		// Update only provided fields
		$fields = array('nom', 'firstname', 'name_alias', 'address', 'zip', 'town', 'state_id', 'country_id', 'phone', 'fax', 'email', 'note_public', 'note_private');
		foreach ($fields as $field) {
			if (isset($request_data[$field])) {
				$this->company->$field = $request_data[$field];
			}
		}

		// Keep patient type
		$this->company->canvas = 'patient@cabinetmed';
		$this->company->client = 1;

		// Handle extrafields
		if (!empty($request_data['extrafields']) && is_array($request_data['extrafields'])) {
			// Load existing extrafields first
			$this->company->fetch_optionals();
			if (!is_array($this->company->array_options)) {
				$this->company->array_options = array();
			}
			foreach ($request_data['extrafields'] as $key => $value) {
				$this->company->array_options['options_'.$key] = $value;
			}
		}

		$result = $this->company->update($this->company->id, $user);
		if ($result <= 0) {
			$errors = ($this->company->error ? $this->company->error : implode(', ', $this->company->errors));
			throw new RestException(500, 'Error updating patient: '.$errors);
		}

		// Save extrafields
		if (!empty($request_data['extrafields'])) {
			$this->company->insertExtraFields();
		}

		// Update cabinetmed_patient record if medical data provided
		if (!empty($request_data['medical']) && is_array($request_data['medical'])) {
			$this->_saveCabinetmedPatient((int) $id, $request_data['medical']);
		}

		return (int) $id;
	}

	// ----------------------------------------------------------------
	// Private helpers
	// ----------------------------------------------------------------

	/**
	 * Fetch cabinetmed_patient data for a given societe id
	 *
	 * @param int $fk_soc Societe ID
	 * @return array Medical data
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
	 * Insert or update cabinetmed_patient record
	 *
	 * @param int   $fk_soc Societe ID
	 * @param array $medical Medical fields
	 * @return void
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

		// Check if record exists
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmed_patient WHERE fk_soc = ".((int) $fk_soc);
		$resql = $this->db->query($sql);
		$exists = ($resql && $this->db->num_rows($resql) > 0);
		$this->db->free($resql);

		if ($exists) {
			// UPDATE
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
			// INSERT
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
}
