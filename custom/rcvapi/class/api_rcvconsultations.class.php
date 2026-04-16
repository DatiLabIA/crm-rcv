<?php
/* Copyright (C) 2025 DatiLab
 * API REST para consultas extendidas (cabinetmed_extcons)
 */

use Luracast\Restler\RestException;

dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');

/**
 * API class for extended consultations
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Rcvconsultations extends DolibarrApi
{
	/**
	 * @var ExtConsultation $consultation {@type ExtConsultation}
	 */
	public $consultation;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->consultation = new ExtConsultation($this->db);
	}

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
	 * @url GET /
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'DESC', $limit = 100, $offset = 0, $fk_soc = 0, $fk_user = 0, $status = -1, $tipo_atencion = '', $date_start_from = '', $date_start_to = '')
	{
		global $user, $conf;

		if (!$user->hasRight('rcvapi', 'consultation', 'read')) {
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
		$sql .= " s.nom as patient_nom, s.firstname as patient_firstname";
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

		dol_syslog("API Rcvconsultations::index", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, 'Error executing query: '.$this->db->lasterror());
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$item = $this->_objToArray($obj);
			$list[] = $item;
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
	 * @url GET {id}
	 * @throws RestException
	 */
	public function get($id)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'consultation', 'read')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->consultation->fetch((int) $id);
		if ($result <= 0) {
			throw new RestException(404, 'Consultation not found');
		}

		return $this->_consultationToArray($this->consultation);
	}

	/**
	 * Create consultation
	 *
	 * @param array $request_data Request data
	 * @return int  ID of created consultation
	 *
	 * @url POST /
	 * @throws RestException
	 */
	public function post($request_data = null)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'consultation', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		if (empty($request_data['fk_soc'])) {
			throw new RestException(400, 'Field "fk_soc" (patient id) is required');
		}

		$this->_populateConsultation($this->consultation, $request_data);

		$result = $this->consultation->create($user, 1);
		if ($result <= 0) {
			$errors = ($this->consultation->error ? $this->consultation->error : implode(', ', $this->consultation->errors));
			throw new RestException(500, 'Error creating consultation: '.$errors);
		}

		return $result;
	}

	/**
	 * Update consultation
	 *
	 * @param int   $id           ID of consultation {@from path} {@min 1}
	 * @param array $request_data Request data
	 * @return int  ID of updated consultation
	 *
	 * @url PUT {id}
	 * @throws RestException
	 */
	public function put($id, $request_data = null)
	{
		global $user;

		if (!$user->hasRight('rcvapi', 'consultation', 'write')) {
			throw new RestException(403, 'Not allowed');
		}

		$result = $this->consultation->fetch((int) $id);
		if ($result <= 0) {
			throw new RestException(404, 'Consultation not found');
		}

		$this->_populateConsultation($this->consultation, $request_data);

		$result = $this->consultation->update($user, 1);
		if ($result < 0) {
			$errors = ($this->consultation->error ? $this->consultation->error : implode(', ', $this->consultation->errors));
			throw new RestException(500, 'Error updating consultation: '.$errors);
		}

		return (int) $id;
	}

	// ----------------------------------------------------------------
	// Private helpers
	// ----------------------------------------------------------------

	/**
	 * Populate consultation object from request data
	 *
	 * @param ExtConsultation $obj  Object to populate
	 * @param array           $data Request data
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

		// Handle dates (accept ISO format YYYY-MM-DD HH:MM:SS or timestamp)
		if (isset($data['date_start'])) {
			$obj->date_start = is_numeric($data['date_start']) ? (int) $data['date_start'] : strtotime($data['date_start']);
		}
		if (isset($data['date_end'])) {
			$obj->date_end = is_numeric($data['date_end']) ? (int) $data['date_end'] : strtotime($data['date_end']);
		}

		// Handle custom_data (JSON object)
		if (isset($data['custom_data'])) {
			if (is_array($data['custom_data'])) {
				// Merge with existing custom_data if updating
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
	 * Convert DB row object to array
	 *
	 * @param object $obj Database row
	 * @return array
	 */
	private function _objToArray($obj)
	{
		$custom_data = null;
		if (!empty($obj->custom_data)) {
			$decoded = json_decode($obj->custom_data, true);
			$custom_data = is_array($decoded) ? $decoded : $obj->custom_data;
		}

		return array(
			'id'             => (int) $obj->rowid,
			'fk_soc'         => (int) $obj->fk_soc,
			'fk_user'        => (int) $obj->fk_user,
			'patient_nom'    => $obj->patient_nom,
			'patient_firstname' => $obj->patient_firstname,
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
	 * Convert ExtConsultation object to array
	 *
	 * @param ExtConsultation $cons Consultation object
	 * @return array
	 */
	private function _consultationToArray($cons)
	{
		$custom_data = null;
		if (!empty($cons->custom_data)) {
			$decoded = json_decode($cons->custom_data, true);
			$custom_data = is_array($decoded) ? $decoded : $cons->custom_data;
		}

		return array(
			'id'             => (int) $cons->id,
			'fk_soc'         => (int) $cons->fk_soc,
			'fk_user'        => (int) $cons->fk_user,
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
