<?php
/* Copyright (C) 2024 Your Company
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class ExtConsultation extends CommonObject
{
    public $element = 'extconsultation';
    public $table_element = 'cabinetmed_extcons';
    public $picto = 'action';
    
    // Properties
    public $rowid;
    public $entity;
    public $fk_soc;
    public $fk_user;
    public $date_start;
    public $date_end;
    public $tipo_atencion;
    public $cumplimiento;
    public $razon_inc;
    public $mes_actual;
    public $proximo_mes;
    public $dificultad;
    public $motivo;
    public $diagnostico;
    public $procedimiento;
    public $insumos_enf;
    public $rx_num;
    public $medicamentos;
    public $observaciones;
    public $custom_data;
    public $status;
    public $note_private;
    public $note_public;
    
    // Recurrence properties
    public $recurrence_enabled;
    public $recurrence_interval;
    public $recurrence_unit;
    public $recurrence_end_type;
    public $recurrence_end_date;
    public $recurrence_parent_id;
    
    public $datec;
    public $tms;
    public $fk_user_creat;
    public $fk_user_modif;
    
    /**
     * @var array Array de usuarios asignados (múltiples encargados)
     */
    public $assigned_users = array();
    
    /**
     * @var bool Indica si está marcada como favorita por el usuario actual
     */
    public $is_favorite = false;
    
    /**
     * Constructor
     */
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Create consultation in database
     */
    public function create($user, $notrigger = 0)
    {
        global $conf;
        
        $error = 0;
        $now = dol_now();
        
        // Clean parameters
        $this->entity = isset($this->entity) ? $this->entity : $conf->entity;
        $this->fk_soc = $this->fk_soc > 0 ? $this->fk_soc : 0;
        $this->fk_user = $this->fk_user > 0 ? $this->fk_user : $user->id;
        $this->cumplimiento = trim($this->cumplimiento);
        $this->razon_inc = trim($this->razon_inc);
        $this->motivo = trim($this->motivo);
        $this->diagnostico = trim($this->diagnostico);
        $this->procedimiento = trim($this->procedimiento);
        $this->insumos_enf = trim($this->insumos_enf);
        $this->rx_num = trim($this->rx_num);
        $this->medicamentos = trim($this->medicamentos);
        
        // Process custom_fields including date/datetime fields
        $custom_fields = GETPOST('custom_fields', 'array');
        if (!is_array($custom_fields)) {
            $custom_fields = array();
        }
        
        // Process date/datetime dynamic fields (they come as separate day/month/year/hour/min fields)
        $custom_fields = $this->processDateCustomFields($custom_fields);
        
        if (!empty($custom_fields)) {
            $this->custom_data = json_encode($custom_fields);
        } else {
            $this->custom_data = trim($this->custom_data); // Fallback por si acaso
        }
        if ($this->status === null || $this->status === '') {
            $this->status = 0;
        } else {
            $this->status = (int) $this->status;
        }
        $this->note_private = trim($this->note_private);
        $this->note_public = trim($this->note_public);
        
        $this->db->begin();
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (";
        $sql .= "entity";
        $sql .= ", fk_soc";
        $sql .= ", fk_user";
        $sql .= ", date_start";
        $sql .= ", date_end";
        $sql .= ", tipo_atencion";
        $sql .= ", cumplimiento";
        $sql .= ", razon_inc";
        $sql .= ", mes_actual";
        $sql .= ", proximo_mes";
        $sql .= ", dificultad";
        $sql .= ", motivo";
        $sql .= ", diagnostico";
        $sql .= ", procedimiento";
        $sql .= ", insumos_enf";
        $sql .= ", rx_num";
        $sql .= ", medicamentos";
        $sql .= ", observaciones";
        $sql .= ", status";
        $sql .= ", custom_data";
        $sql .= ", recurrence_enabled";
        $sql .= ", recurrence_interval";
        $sql .= ", recurrence_unit";
        $sql .= ", recurrence_end_type";
        $sql .= ", recurrence_end_date";
        $sql .= ", recurrence_parent_id";
        $sql .= ", note_private";
        $sql .= ", note_public";
        $sql .= ", datec";
        $sql .= ", fk_user_creat";
        $sql .= ") VALUES (";
        $sql .= $this->entity;
        $sql .= ", ".($this->fk_soc > 0 ? $this->fk_soc : "null");
        $sql .= ", ".($this->fk_user > 0 ? $this->fk_user : "null");
        $sql .= ", ".($this->date_start ? "'".$this->db->idate($this->date_start)."'" : "null");
        $sql .= ", ".($this->date_end ? "'".$this->db->idate($this->date_end)."'" : "null");
        $sql .= ", '".$this->db->escape($this->tipo_atencion)."'";
        $sql .= ", '".$this->db->escape($this->cumplimiento)."'";
        $sql .= ", '".$this->db->escape($this->razon_inc)."'";
        $sql .= ", '".$this->db->escape($this->mes_actual)."'";
        $sql .= ", '".$this->db->escape($this->proximo_mes)."'";
        $sql .= ", ".($this->dificultad ? 1 : 0);
        $sql .= ", '".$this->db->escape($this->motivo)."'";
        $sql .= ", '".$this->db->escape($this->diagnostico)."'";
        $sql .= ", '".$this->db->escape($this->procedimiento)."'";
        $sql .= ", '".$this->db->escape($this->insumos_enf)."'";
        $sql .= ", '".$this->db->escape($this->rx_num)."'";
        $sql .= ", '".$this->db->escape($this->medicamentos)."'";
        $sql .= ", '".$this->db->escape($this->observaciones)."'";
        $sql .= ", ".(int) $this->status;
        $sql .= ", '".$this->db->escape($this->custom_data)."'";
        $sql .= ", ".(int) $this->recurrence_enabled;
        $sql .= ", ".(int) ($this->recurrence_interval > 0 ? $this->recurrence_interval : 1);
        $sql .= ", '".$this->db->escape($this->recurrence_unit ? $this->recurrence_unit : 'weeks')."'";
        $sql .= ", '".$this->db->escape($this->recurrence_end_type ? $this->recurrence_end_type : 'forever')."'";
        $sql .= ", ".($this->recurrence_end_date ? "'".$this->db->escape($this->recurrence_end_date)."'" : "null");
        $sql .= ", ".($this->recurrence_parent_id > 0 ? (int) $this->recurrence_parent_id : "null");
        $sql .= ", '".$this->db->escape($this->note_private)."'";
        $sql .= ", '".$this->db->escape($this->note_public)."'";
        $sql .= ", '".$this->db->idate($now)."'";
        $sql .= ", ".$user->id;
        $sql .= ")";
        
        dol_syslog(get_class($this)."::create", LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $error++;
            $this->errors[] = "Error ".$this->db->lasterror();
        }
        
        if (!$error) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
            
            if (!$notrigger) {
                $result = $this->call_trigger('EXTCONSULTATION_CREATE', $user);
                if ($result < 0) $error++;
            }
            
            // Crear evento/agenda para el usuario asignado
            if (!$error && $this->fk_user > 0) {
                $result = $this->createAgendaEvent($user);
                if ($result < 0) {
                    // No marcamos error para no impedir la creación, solo log
                    dol_syslog(get_class($this)."::create - Warning: Could not create agenda event", LOG_WARNING);
                }
            }
        }
        
        if (!$error) {
            $this->db->commit();
            return $this->id;
        } else {
            $this->db->rollback();
            return -1;
        }
    }
    
    /**
     * Load consultation from database
     */
    public function fetch($id, $ref = '')
    {
        $sql = "SELECT";
        $sql .= " t.rowid,";
        $sql .= " t.entity,";
        $sql .= " t.fk_soc,";
        $sql .= " t.fk_user,";
        $sql .= " t.date_start,";
        $sql .= " t.date_end,";
        $sql .= " t.tipo_atencion,";
        $sql .= " t.cumplimiento,";
        $sql .= " t.razon_inc,";
        $sql .= " t.mes_actual,";
        $sql .= " t.proximo_mes,";
        $sql .= " t.dificultad,";
        $sql .= " t.motivo,";
        $sql .= " t.diagnostico,";
        $sql .= " t.procedimiento,";
        $sql .= " t.insumos_enf,";
        $sql .= " t.rx_num,";
        $sql .= " t.medicamentos,";
        $sql .= " t.observaciones,";
        $sql .= " t.status,";
        $sql .= " t.custom_data,";
        $sql .= " t.recurrence_enabled,";
        $sql .= " t.recurrence_interval,";
        $sql .= " t.recurrence_unit,";
        $sql .= " t.recurrence_end_type,";
        $sql .= " t.recurrence_end_date,";
        $sql .= " t.recurrence_parent_id,";
        $sql .= " t.note_private,";
        $sql .= " t.note_public,";
        $sql .= " t.datec,";
        $sql .= " t.tms,";
        $sql .= " t.fk_user_creat,";
        $sql .= " t.fk_user_modif";
        $sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
        $sql .= " WHERE t.rowid = ".((int) $id);
        
        dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql)) {
                $obj = $this->db->fetch_object($resql);
                
                $this->id = $obj->rowid;
                $this->rowid = $obj->rowid;
                $this->entity = $obj->entity;
                $this->fk_soc = $obj->fk_soc;
                $this->fk_user = $obj->fk_user;
                $this->date_start = $this->db->jdate($obj->date_start);
                $this->date_end = $this->db->jdate($obj->date_end);
                $this->tipo_atencion = $obj->tipo_atencion;
                $this->cumplimiento = $obj->cumplimiento;
                $this->razon_inc = $obj->razon_inc;
                $this->mes_actual = $obj->mes_actual;
                $this->proximo_mes = $obj->proximo_mes;
                $this->dificultad = $obj->dificultad;
                $this->motivo = $obj->motivo;
                $this->diagnostico = $obj->diagnostico;
                $this->procedimiento = $obj->procedimiento;
                $this->insumos_enf = $obj->insumos_enf;
                $this->rx_num = $obj->rx_num;
                $this->medicamentos = $obj->medicamentos;
                $this->observaciones = $obj->observaciones;
                $this->status = (int) $obj->status;
                $this->custom_data = $obj->custom_data;
                $this->recurrence_enabled = (int) $obj->recurrence_enabled;
                $this->recurrence_interval = (int) $obj->recurrence_interval;
                $this->recurrence_unit = $obj->recurrence_unit;
                $this->recurrence_end_type = $obj->recurrence_end_type;
                $this->recurrence_end_date = $obj->recurrence_end_date;
                $this->recurrence_parent_id = $obj->recurrence_parent_id ? (int) $obj->recurrence_parent_id : null;
                $this->note_private = $obj->note_private;
                $this->note_public = $obj->note_public;
                $this->datec = $this->db->jdate($obj->datec);
                $this->tms = $this->db->jdate($obj->tms);
                $this->fk_user_creat = $obj->fk_user_creat;
                $this->fk_user_modif = $obj->fk_user_modif;
            }
            $this->db->free($resql);
            // Decodificar JSON a un array accesible
        if (!empty($this->custom_data)) {
            $this->custom_fields_array = json_decode($this->custom_data, true);
        } else {
            $this->custom_fields_array = array();
        }
            return 1;
        } else {
            $this->error = "Error ".$this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Update consultation
     */
    public function update($user, $notrigger = 0)
    {
        global $conf;
        
        $error = 0;
        
        // Clean parameters
        $this->cumplimiento = trim($this->cumplimiento);
        $this->razon_inc = trim($this->razon_inc);
        $this->motivo = trim($this->motivo);
        $this->diagnostico = trim($this->diagnostico);
        $this->procedimiento = trim($this->procedimiento);
        $this->insumos_enf = trim($this->insumos_enf);
        $this->rx_num = trim($this->rx_num);
        $this->medicamentos = trim($this->medicamentos);
        
        // Process custom_fields including date/datetime fields
        $custom_fields = GETPOST('custom_fields', 'array');
        if (!is_array($custom_fields)) {
            $custom_fields = array();
        }
        
        // Process date/datetime dynamic fields (they come as separate day/month/year/hour/min fields)
        $custom_fields = $this->processDateCustomFields($custom_fields);
        
        // MERGE: mantener datos custom existentes y solo actualizar los que fueron enviados
        $existing_custom = array();
        if (!empty($this->custom_data)) {
            $decoded = json_decode($this->custom_data, true);
            if (is_array($decoded)) {
                $existing_custom = $decoded;
            }
        }
        
        // Lista de campos que tienen columna propia en la tabla (legacy).
        // Si alguno de ellos llegó en POST con su nombre de columna (no como custom_fields),
        // lo eliminamos del JSON para evitar datos huérfanos que podrían sobreescribir la columna en futuras ediciones.
        $legacy_column_fields = array('cumplimiento', 'razon_inc', 'mes_actual', 'proximo_mes',
                                      'dificultad', 'motivo', 'diagnostico', 'procedimiento',
                                      'insumos_enf', 'rx_num', 'medicamentos');
        foreach ($legacy_column_fields as $lcf) {
            if (GETPOSTISSET($lcf)) {
                // Este campo llegó como columna directa → eliminar del JSON para que la columna sea la fuente de verdad
                unset($existing_custom[$lcf]);
            }
        }
        
        if (!empty($custom_fields)) {
            // Fusionar: los nuevos valores sobreescriben, los existentes se mantienen
            $merged = array_merge($existing_custom, $custom_fields);
            $this->custom_data = json_encode($merged);
        } else {
            // Si no se enviaron custom_fields, mantener los existentes (sin los legacy ya saneados)
            if (!empty($existing_custom)) {
                $this->custom_data = json_encode($existing_custom);
            } else {
                $this->custom_data = '';
            }
        }
        $this->note_private = trim($this->note_private);
        $this->note_public = trim($this->note_public);
        
        $this->db->begin();
        
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET";
        $sql .= " fk_soc = ".($this->fk_soc > 0 ? $this->fk_soc : "null");
        $sql .= ", fk_user = ".($this->fk_user > 0 ? $this->fk_user : $user->id);
        $sql .= ", date_start = ".($this->date_start ? "'".$this->db->idate($this->date_start)."'" : "null");
        $sql .= ", date_end = ".($this->date_end ? "'".$this->db->idate($this->date_end)."'" : "null");
        $sql .= ", tipo_atencion = '".$this->db->escape($this->tipo_atencion)."'";
        $sql .= ", cumplimiento = '".$this->db->escape($this->cumplimiento)."'";
        $sql .= ", razon_inc = '".$this->db->escape($this->razon_inc)."'";
        $sql .= ", mes_actual = '".$this->db->escape($this->mes_actual)."'";
        $sql .= ", proximo_mes = '".$this->db->escape($this->proximo_mes)."'";
        $sql .= ", dificultad = ".($this->dificultad ? 1 : 0);
        $sql .= ", motivo = '".$this->db->escape($this->motivo)."'";
        $sql .= ", diagnostico = '".$this->db->escape($this->diagnostico)."'";
        $sql .= ", procedimiento = '".$this->db->escape($this->procedimiento)."'";
        $sql .= ", insumos_enf = '".$this->db->escape($this->insumos_enf)."'";
        $sql .= ", rx_num = '".$this->db->escape($this->rx_num)."'";
        $sql .= ", medicamentos = '".$this->db->escape($this->medicamentos)."'";
        $sql .= ", observaciones = '".$this->db->escape($this->observaciones)."'";
        $sql .= ", status = ".(int) $this->status;
        $sql .= ", custom_data = '".$this->db->escape($this->custom_data)."'";

        $sql .= ", recurrence_enabled = ".(int) $this->recurrence_enabled;
        $sql .= ", recurrence_interval = ".(int) ($this->recurrence_interval > 0 ? $this->recurrence_interval : 1);
        $sql .= ", recurrence_unit = '".$this->db->escape($this->recurrence_unit ? $this->recurrence_unit : 'weeks')."'";
        $sql .= ", recurrence_end_type = '".$this->db->escape($this->recurrence_end_type ? $this->recurrence_end_type : 'forever')."'";
        $sql .= ", recurrence_end_date = ".($this->recurrence_end_date ? "'".$this->db->escape($this->recurrence_end_date)."'" : "null");
        $sql .= ", recurrence_parent_id = ".($this->recurrence_parent_id > 0 ? (int) $this->recurrence_parent_id : "null");

        $sql .= ", note_private = '".$this->db->escape($this->note_private)."'";
        $sql .= ", note_public = '".$this->db->escape($this->note_public)."'";
        $sql .= ", fk_user_modif = ".$user->id;
        $sql .= " WHERE rowid = ".((int) $this->id);
        
        dol_syslog(get_class($this)."::update", LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $error++;
            $this->errors[] = "Error ".$this->db->lasterror();
        }
        
        if (!$error && !$notrigger) {
            $result = $this->call_trigger('EXTCONSULTATION_MODIFY', $user);
            if ($result < 0) $error++;
        }
        
        // Update the linked agenda event (status, dates, user)
        if (!$error) {
            $result = $this->updateAgendaEvent($user);
            if ($result < 0) {
                // Don't block update, just log warning
                dol_syslog(get_class($this)."::update - Warning: Could not update agenda event", LOG_WARNING);
            }
        }
        
        if (!$error) {
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }
    
    /**
     * Delete consultation
     */
    public function delete($user, $notrigger = 0)
    {
        $error = 0;
        
        $this->db->begin();
        
        if (!$error && !$notrigger) {
            $result = $this->call_trigger('EXTCONSULTATION_DELETE', $user);
            if ($result < 0) $error++;
        }
        
        // Delete linked agenda event first
        if (!$error) {
            $result = $this->deleteAgendaEvent($user);
            if ($result < 0) {
                // Don't block delete, just log warning
                dol_syslog(get_class($this)."::delete - Warning: Could not delete agenda event", LOG_WARNING);
            }
        }
        
        if (!$error) {
            $sql = "DELETE FROM ".MAIN_DB_PREFIX.$this->table_element;
            $sql .= " WHERE rowid = ".((int) $this->id);
            
            dol_syslog(get_class($this)."::delete", LOG_DEBUG);
            $resql = $this->db->query($sql);
            if (!$resql) {
                $error++;
                $this->errors[] = "Error ".$this->db->lasterror();
            }
        }
        
        if (!$error) {
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }
    
    /**
     * Delete the linked agenda event
     * 
     * @param  User    $user    User who deletes the event
     * @return int              >0 if OK, 0 if no event found, <0 if KO
     */
    public function deleteAgendaEvent($user)
    {
        // Find the linked event
        $eventId = $this->findLinkedAgendaEvent();
        
        if ($eventId <= 0) {
            return 0; // No event found
        }
        
        // Load and delete the event
        $actioncomm = new ActionComm($this->db);
        $result = $actioncomm->fetch($eventId);
        
        if ($result <= 0) {
            return 0;
        }
        
        $result = $actioncomm->delete($user);
        
        if ($result < 0) {
            $this->error = $actioncomm->error;
            $this->errors = $actioncomm->errors;
            dol_syslog(get_class($this)."::deleteAgendaEvent Error: ".$actioncomm->error, LOG_ERR);
            return -1;
        }
        
        dol_syslog(get_class($this)."::deleteAgendaEvent Event deleted id=".$eventId, LOG_DEBUG);
        return 1;
    }
    
    /**
     * Map consultation status to ActionComm percentage
     * 
     * In Dolibarr ActionComm:
     * - percentage = -1  → Not applicable (NA)
     * - percentage = 0   → Not started
     * - percentage = 50  → In progress (any value 1-99)
     * - percentage = 100 → Done/Completed
     * 
     * @return int  The percentage value for ActionComm
     */
    public function getEventPercentageFromStatus()
    {
        switch ((int) $this->status) {
            case self::STATUS_IN_PROGRESS:  // 0 - En progreso
                return 50;  // En progreso
            case self::STATUS_COMPLETED:    // 1 - Completada
                return 100; // Terminada
            case self::STATUS_CANCELED:     // 2 - Cancelada
                return -1;  // No aplicable
            default:
                return 0;   // No iniciado (fallback)
        }
    }
    
    /**
     * Find the agenda event linked to this consultation
     * 
     * @return int  Event ID if found, 0 if not found, <0 if error
     */
    public function findLinkedAgendaEvent()
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."actioncomm";
        $sql .= " WHERE fk_element = ".((int) $this->id);
        $sql .= " AND elementtype = 'extconsultation@cabinetmed_extcons'";
        $sql .= " ORDER BY rowid DESC LIMIT 1";
        
        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                return (int) $obj->rowid;
            }
            return 0; // Not found
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Create an agenda event/task for the assigned user
     * The event status will match the consultation status
     * 
     * @param  User    $user    User who creates the event
     * @return int              >0 if OK, <0 if KO
     */
    public function createAgendaEvent($user)
    {
        global $conf, $langs;
        
        if (empty($this->fk_user) || $this->fk_user <= 0) {
            return 0; // No user assigned, nothing to do
        }
        
        $langs->load("agenda");
        
        // Get patient info for the event title
        $patientName = '';
        if ($this->fk_soc > 0) {
            $patient = new Societe($this->db);
            if ($patient->fetch($this->fk_soc) > 0) {
                $patientName = $patient->name;
            }
        }
        
        // Get consultation type label
        $typeLabel = $this->getTypeLabel($langs);
        
        // Create the event
        $actioncomm = new ActionComm($this->db);
        
        // Event type: AC_OTH_AUTO = Automatic event
        $actioncomm->type_code = 'AC_OTH_AUTO';
        
        // Event label/title
        $actioncomm->label = 'Consulta: '.$typeLabel;
        if (!empty($patientName)) {
            $actioncomm->label .= ' - '.$patientName;
        }
        
        // Event description/note
        $actioncomm->note_private = 'Consulta registrada automáticamente.'."\n";
        $actioncomm->note_private .= 'Ref: CONS-'.sprintf("%06d", $this->id)."\n";
        $actioncomm->note_private .= 'Tipo: '.$typeLabel."\n";
        if (!empty($patientName)) {
            $actioncomm->note_private .= 'Paciente: '.$patientName."\n";
        }
        if (!empty($this->motivo)) {
            $actioncomm->note_private .= 'Motivo: '.$this->motivo."\n";
        }
        
        // Dates
        $actioncomm->datep = $this->date_start ? $this->date_start : dol_now();
        $actioncomm->datef = $this->date_end ? $this->date_end : $actioncomm->datep;
        
        // Assign to the user selected in the consultation
        $actioncomm->userownerid = $this->fk_user;
        
        // Link to the patient/third party
        if ($this->fk_soc > 0) {
            $actioncomm->socid = $this->fk_soc;
        }
        
        // Link to the consultation (element and elementid)
        $actioncomm->fk_element = $this->id;
        $actioncomm->elementtype = 'extconsultation@cabinetmed_extcons';
        
        // Entity and other fields
        $actioncomm->entity = $conf->entity;
        
        // Map consultation status to event percentage
        // En progreso (0) → 50%, Completada (1) → 100%, Cancelada (2) → -1 (NA)
        $actioncomm->percentage = $this->getEventPercentageFromStatus();
        
        $actioncomm->priority = 0;
        $actioncomm->fulldayevent = 0;
        $actioncomm->location = '';
        
        // Create the event
        $result = $actioncomm->create($user);
        
        if ($result < 0) {
            $this->error = $actioncomm->error;
            $this->errors = $actioncomm->errors;
            dol_syslog(get_class($this)."::createAgendaEvent Error: ".$actioncomm->error, LOG_ERR);
            return -1;
        }
        
        dol_syslog(get_class($this)."::createAgendaEvent Event created with id=".$result." and percentage=".$actioncomm->percentage, LOG_DEBUG);
        return $result;
    }
    
    /**
     * Update the linked agenda event when consultation is modified
     * Updates status, dates, and assigned user
     * 
     * @param  User    $user    User who updates the event
     * @return int              >0 if OK, 0 if no event found, <0 if KO
     */
    public function updateAgendaEvent($user)
    {
        global $conf, $langs;
        
        // Find the linked event
        $eventId = $this->findLinkedAgendaEvent();
        
        if ($eventId <= 0) {
            // No event found, create one if user is assigned
            if ($this->fk_user > 0) {
                return $this->createAgendaEvent($user);
            }
            return 0;
        }
        
        $langs->load("agenda");
        
        // Load the existing event
        $actioncomm = new ActionComm($this->db);
        $result = $actioncomm->fetch($eventId);
        
        if ($result <= 0) {
            $this->error = $actioncomm->error;
            return -1;
        }
        
        // Get patient info for the event title
        $patientName = '';
        if ($this->fk_soc > 0) {
            $patient = new Societe($this->db);
            if ($patient->fetch($this->fk_soc) > 0) {
                $patientName = $patient->name;
            }
        }
        
        // Get consultation type label
        $typeLabel = $this->getTypeLabel($langs);
        
        // Update event label
        $actioncomm->label = 'Consulta: '.$typeLabel;
        if (!empty($patientName)) {
            $actioncomm->label .= ' - '.$patientName;
        }
        
        // Update dates
        $actioncomm->datep = $this->date_start ? $this->date_start : dol_now();
        $actioncomm->datef = $this->date_end ? $this->date_end : $actioncomm->datep;
        
        // Update assigned user
        if ($this->fk_user > 0) {
            $actioncomm->userownerid = $this->fk_user;
        }
        
        // Update status (percentage) based on consultation status
        $actioncomm->percentage = $this->getEventPercentageFromStatus();
        
        // Update the event
        $result = $actioncomm->update($user);
        
        if ($result < 0) {
            $this->error = $actioncomm->error;
            $this->errors = $actioncomm->errors;
            dol_syslog(get_class($this)."::updateAgendaEvent Error: ".$actioncomm->error, LOG_ERR);
            return -1;
        }
        
        dol_syslog(get_class($this)."::updateAgendaEvent Event updated id=".$eventId." percentage=".$actioncomm->percentage, LOG_DEBUG);
        return $eventId;
    }
    
    /**
     * Get consultation types array from database
     */
    public static function getTypesArray($langs, $active_only = true)
    {
        global $db, $conf;
        
        $types = array();
        
        $sql = "SELECT code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
        $sql .= " WHERE entity = ".$conf->entity;
        if ($active_only) {
            $sql .= " AND active = 1";
        }
        $sql .= " ORDER BY position ASC, label ASC";
        
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $types[$obj->code] = $obj->label;
            }
            $db->free($resql);
        }
        
        // Fallback to defaults if no types defined
        if (empty($types)) {
            $types = array(
                'adherencia' => $langs->trans('AdherenceDispensation'),
                'control'    => $langs->trans('MedicalControl'),
                'enfermeria' => $langs->trans('NursingCare'),
                'farmacia'   => $langs->trans('Pharmacy'),
                'general'    => $langs->trans('GeneralConsultation')
            );
        }
        
        return $types;
    }
    
    /**
     * Get label for consultation type
     */
    public function getTypeLabel($langs)
    {
        $types = self::getTypesArray($langs);
        return isset($types[$this->tipo_atencion]) ? $types[$this->tipo_atencion] : $this->tipo_atencion;
    }

        const STATUS_IN_PROGRESS = 0;
    const STATUS_COMPLETED   = 1;
    const STATUS_CANCELED    = 2;

    public static function getStatusArray()
    {
        return array(
            self::STATUS_IN_PROGRESS => 'En progreso',
            self::STATUS_COMPLETED   => 'Completada',
            self::STATUS_CANCELED    => 'Cancelada',
        );
    }

    /**
     * Devuelve el estado como texto o badge con color
     * $mode = 0 → solo texto
     * $mode = 1 → HTML con color
     */
    public function getLibStatus($mode = 0)
    {
        $labels = self::getStatusArray();
        $label = isset($labels[$this->status]) ? $labels[$this->status] : (string) $this->status;

        if ($mode == 1) {
            // Colores: naranja, verde, rojo
            $color = '#ef6c00'; // en progreso
            if ($this->status == self::STATUS_COMPLETED) $color = '#2e7d32';
            if ($this->status == self::STATUS_CANCELED)  $color = '#c62828';

            return '<span class="badge" style="color:#fff;background-color:'.$color.';padding:2px 6px;border-radius:3px;">'.$label.'</span>';
        }

        return $label;
    }

    /**
     * Process date and datetime custom fields from POST
     * These fields come as separate day/month/year/hour/min components with prefix cf_date_ or cf_datetime_
     * 
     * @param array $custom_fields Existing custom fields array
     * @return array Updated custom fields array with processed dates
     */
    public function processDateCustomFields($custom_fields)
    {
        // Look for date fields: cf_date_FIELDNAME
        foreach ($_POST as $key => $val) {
            // Date fields (without time)
            if (preg_match('/^cf_date_(.+)day$/', $key, $matches)) {
                $field_name = $matches[1];
                $day = GETPOST('cf_date_'.$field_name.'day', 'int');
                $month = GETPOST('cf_date_'.$field_name.'month', 'int');
                $year = GETPOST('cf_date_'.$field_name.'year', 'int');
                
                if ($day > 0 && $month > 0 && $year > 0) {
                    $timestamp = dol_mktime(0, 0, 0, $month, $day, $year);
                    $custom_fields[$field_name] = $timestamp;
                }
            }
            
            // Datetime fields (with time)
            if (preg_match('/^cf_datetime_(.+)day$/', $key, $matches)) {
                $field_name = $matches[1];
                $day = GETPOST('cf_datetime_'.$field_name.'day', 'int');
                $month = GETPOST('cf_datetime_'.$field_name.'month', 'int');
                $year = GETPOST('cf_datetime_'.$field_name.'year', 'int');
                $hour = GETPOST('cf_datetime_'.$field_name.'hour', 'int');
                $min = GETPOST('cf_datetime_'.$field_name.'min', 'int');
                
                if ($day > 0 && $month > 0 && $year > 0) {
                    $timestamp = dol_mktime($hour, $min, 0, $month, $day, $year);
                    $custom_fields[$field_name] = $timestamp;
                }
            }
            
            // Multiselect fields: cf_multi_FIELDNAME
            if (preg_match('/^cf_multi_(.+)$/', $key, $matches)) {
                $field_name = $matches[1];
                $multi_values = GETPOST('cf_multi_'.$field_name, 'array');
                if (is_array($multi_values) && !empty($multi_values)) {
                    $custom_fields[$field_name] = $multi_values;
                } else {
                    $custom_fields[$field_name] = array();
                }
            }
            
            // Image-textarea fields: cf_imgtext_FIELDNAME
            // El input hidden arranca vacío; JS lo rellena al sincronizar el editor.
            // Si llega no-vacío → JS corrió y tiene el contenido real (texto e/o imágenes).
            // Si llega vacío  → JS NO corrió; usar el fallback textarea plain-text.
            if (preg_match('/^cf_imgtext_(.+)$/', $key, $matches)) {
                $field_name = $matches[1];
                $html_content = GETPOST('cf_imgtext_'.$field_name, 'restricthtml');
                if ($html_content !== '' && $html_content !== null) {
                    // JS corrió y sincronizó contenido (puede ser texto, imágenes o ambos)
                    $custom_fields[$field_name] = $html_content;
                } else {
                    // JS NO corrió: usar la textarea de fallback (custom_fields[fieldname])
                    $fallback = isset($custom_fields[$field_name]) ? (string) $custom_fields[$field_name] : '';
                    if (trim($fallback) !== '') {
                        $custom_fields[$field_name] = $fallback;
                    }
                    // Si ambos vacíos, el loop de cleanup lo elimina
                }
            }
        }
        
        // Limpieza final: eliminar valores vacíos y "-1" (valor por defecto de selectarray vacío en Dolibarr)
        foreach ($custom_fields as $k => $v) {
            if ($v === '-1' || $v === -1) {
                unset($custom_fields[$k]);
            } elseif (is_string($v) && trim($v) === '') {
                unset($custom_fields[$k]);
            } elseif (is_array($v) && empty($v)) {
                unset($custom_fields[$k]);
            }
        }
        
        return $custom_fields;
    }

    // =========================================================================
    // MÉTODOS PARA MÚLTIPLES ENCARGADOS
    // =========================================================================
    
    /**
     * Obtener usuarios asignados a esta consulta
     * 
     * @return array Array de objetos User asignados
     */
    public function fetchAssignedUsers()
    {
        $this->assigned_users = array();
        
        $sql = "SELECT eu.fk_user, eu.role, u.firstname, u.lastname, u.login, u.photo";
        $sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users as eu";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON eu.fk_user = u.rowid";
        $sql .= " WHERE eu.fk_extcons = ".((int) $this->id);
        $sql .= " ORDER BY eu.datec ASC";
        
        dol_syslog(get_class($this)."::fetchAssignedUsers", LOG_DEBUG);
        $resql = $this->db->query($sql);
        
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $this->assigned_users[] = array(
                    'id' => $obj->fk_user,
                    'role' => $obj->role,
                    'firstname' => $obj->firstname,
                    'lastname' => $obj->lastname,
                    'login' => $obj->login,
                    'photo' => $obj->photo
                );
            }
            $this->db->free($resql);
            return count($this->assigned_users);
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Asignar usuarios a esta consulta (reemplaza todos los existentes)
     * 
     * @param array  $user_ids   Array de IDs de usuario
     * @param User   $user       Usuario que realiza la acción
     * @param string $role       Rol por defecto
     * @return int               >0 si OK, <0 si KO
     */
    public function setAssignedUsers($user_ids, $user, $role = 'assigned')
    {
        $error = 0;
        $now = dol_now();
        
        $this->db->begin();
        
        // Primero eliminar todas las asignaciones existentes
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users";
        $sql .= " WHERE fk_extcons = ".((int) $this->id);
        
        dol_syslog(get_class($this)."::setAssignedUsers delete existing", LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $error++;
            $this->error = $this->db->lasterror();
        }
        
        // Insertar las nuevas asignaciones
        if (!$error && !empty($user_ids)) {
            foreach ($user_ids as $user_id) {
                $user_id = (int) $user_id;
                if ($user_id > 0) {
                    $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_users";
                    $sql .= " (fk_extcons, fk_user, role, datec, fk_user_creat)";
                    $sql .= " VALUES (".$this->id.", ".$user_id.", '".$this->db->escape($role)."', '".$this->db->idate($now)."', ".$user->id.")";
                    
                    dol_syslog(get_class($this)."::setAssignedUsers insert user ".$user_id, LOG_DEBUG);
                    $resql = $this->db->query($sql);
                    if (!$resql) {
                        $error++;
                        $this->error = $this->db->lasterror();
                        break;
                    }
                }
            }
        }
        
        // Actualizar fk_user principal (el primero de la lista) por compatibilidad
        if (!$error) {
            $main_user = !empty($user_ids) ? (int) $user_ids[0] : 0;
            $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
            $sql .= " SET fk_user = ".($main_user > 0 ? $main_user : "null");
            $sql .= " WHERE rowid = ".((int) $this->id);
            
            $resql = $this->db->query($sql);
            if (!$resql) {
                $error++;
                $this->error = $this->db->lasterror();
            }
        }
        
        if (!$error) {
            $this->db->commit();
            
            // Actualizar eventos de agenda para cada usuario asignado
            $this->updateAgendaEventsForAssignedUsers($user);
            
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }
    
    /**
     * Agregar un usuario asignado
     * 
     * @param int    $user_id    ID del usuario a agregar
     * @param User   $user       Usuario que realiza la acción
     * @param string $role       Rol del usuario
     * @return int               >0 si OK, <0 si KO
     */
    public function addAssignedUser($user_id, $user, $role = 'assigned')
    {
        $now = dol_now();
        $user_id = (int) $user_id;
        
        if ($user_id <= 0) {
            $this->error = "Invalid user ID";
            return -1;
        }
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_users";
        $sql .= " (fk_extcons, fk_user, role, datec, fk_user_creat)";
        $sql .= " VALUES (".$this->id.", ".$user_id.", '".$this->db->escape($role)."', '".$this->db->idate($now)."', ".$user->id.")";
        $sql .= " ON DUPLICATE KEY UPDATE role = '".$this->db->escape($role)."'";
        
        dol_syslog(get_class($this)."::addAssignedUser", LOG_DEBUG);
        $resql = $this->db->query($sql);
        
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Eliminar un usuario asignado
     * 
     * @param int  $user_id    ID del usuario a eliminar
     * @return int             >0 si OK, <0 si KO
     */
    public function removeAssignedUser($user_id)
    {
        $user_id = (int) $user_id;
        
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users";
        $sql .= " WHERE fk_extcons = ".((int) $this->id);
        $sql .= " AND fk_user = ".$user_id;
        
        dol_syslog(get_class($this)."::removeAssignedUser", LOG_DEBUG);
        $resql = $this->db->query($sql);
        
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Obtener array de IDs de usuarios asignados
     * 
     * @return array Array de IDs de usuario
     */
    public function getAssignedUserIds()
    {
        $ids = array();
        
        if (empty($this->assigned_users)) {
            $this->fetchAssignedUsers();
        }
        
        foreach ($this->assigned_users as $au) {
            $ids[] = $au['id'];
        }
        
        return $ids;
    }
    
    /**
     * Obtener HTML con los nombres de usuarios asignados
     * 
     * @param int $mode  0=texto simple, 1=con enlaces
     * @return string    HTML con los nombres
     */
    public function getAssignedUsersHTML($mode = 1)
    {
        require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
        
        if (empty($this->assigned_users)) {
            $this->fetchAssignedUsers();
        }
        
        if (empty($this->assigned_users)) {
            return '<span class="opacitymedium">-</span>';
        }
        
        $html = array();
        foreach ($this->assigned_users as $au) {
            if ($mode == 1) {
                $tmpuser = new User($this->db);
                $tmpuser->id = $au['id'];
                $tmpuser->firstname = $au['firstname'];
                $tmpuser->lastname = $au['lastname'];
                $tmpuser->login = $au['login'];
                $tmpuser->photo = $au['photo'];
                $html[] = $tmpuser->getNomUrl(1);
            } else {
                $html[] = dolGetFirstLastname($au['firstname'], $au['lastname']);
            }
        }
        
        return implode(', ', $html);
    }
    
    /**
     * Actualizar eventos de agenda para todos los usuarios asignados
     * 
     * @param User $user Usuario que realiza la acción
     * @return int       Número de eventos actualizados
     */
    protected function updateAgendaEventsForAssignedUsers($user)
    {
        // Por ahora mantenemos el evento para el usuario principal (fk_user)
        // Se puede expandir para crear eventos para todos los usuarios asignados
        return $this->updateAgendaEvent($user);
    }
    
    // =========================================================================
    // MÉTODOS PARA FAVORITOS
    // =========================================================================
    
    /**
     * Verificar si esta consulta está marcada como favorita por un usuario
     * 
     * @param int $user_id ID del usuario (0 = usuario actual)
     * @return bool        true si es favorita, false si no
     */
    public function isFavorite($user_id = 0)
    {
        global $user;
        
        if ($user_id <= 0) {
            $user_id = $user->id;
        }
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites";
        $sql .= " WHERE fk_extcons = ".((int) $this->id);
        $sql .= " AND fk_user = ".((int) $user_id);
        
        $resql = $this->db->query($sql);
        if ($resql) {
            $this->is_favorite = ($this->db->num_rows($resql) > 0);
            $this->db->free($resql);
            return $this->is_favorite;
        }
        
        return false;
    }
    
    /**
     * Marcar/desmarcar esta consulta como favorita
     * 
     * @param int  $user_id   ID del usuario (0 = usuario actual)
     * @param bool $favorite  true para marcar, false para desmarcar
     * @return int            1 si OK, -1 si KO
     */
    public function setFavorite($user_id = 0, $favorite = true)
    {
        global $user;
        
        if ($user_id <= 0) {
            $user_id = $user->id;
        }
        
        if ($favorite) {
            // Agregar a favoritos
            $now = dol_now();
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites";
            $sql .= " (fk_extcons, fk_user, datec)";
            $sql .= " VALUES (".$this->id.", ".$user_id.", '".$this->db->idate($now)."')";
            $sql .= " ON DUPLICATE KEY UPDATE datec = '".$this->db->idate($now)."'";
        } else {
            // Quitar de favoritos
            $sql = "DELETE FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites";
            $sql .= " WHERE fk_extcons = ".((int) $this->id);
            $sql .= " AND fk_user = ".((int) $user_id);
        }
        
        dol_syslog(get_class($this)."::setFavorite favorite=".($favorite ? 'yes' : 'no'), LOG_DEBUG);
        $resql = $this->db->query($sql);
        
        if ($resql) {
            $this->is_favorite = $favorite;
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Alternar el estado de favorito
     * 
     * @param int $user_id ID del usuario (0 = usuario actual)
     * @return int         1 si OK, -1 si KO
     */
    public function toggleFavorite($user_id = 0)
    {
        $is_fav = $this->isFavorite($user_id);
        return $this->setFavorite($user_id, !$is_fav);
    }
    
    /**
     * Obtener lista de IDs de consultas favoritas de un usuario
     * 
     * @param DoliDB $db      Conexión a la base de datos
     * @param int    $user_id ID del usuario (0 = usuario actual)
     * @return array          Array de IDs de consultas favoritas
     */
    public static function getFavoriteIds($db, $user_id = 0)
    {
        global $user;
        
        if ($user_id <= 0) {
            $user_id = $user->id;
        }
        
        $favorites = array();
        
        $sql = "SELECT fk_extcons FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_favorites";
        $sql .= " WHERE fk_user = ".((int) $user_id);
        
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $favorites[] = $obj->fk_extcons;
            }
            $db->free($resql);
        }
        
        return $favorites;
    }

    // =========================================================================
    // MÉTODOS PARA RECURRENCIA
    // =========================================================================
    
    /**
     * Generar las consultas recurrentes a partir de esta consulta padre
     * Crea todas las ocurrencias futuras basándose en la configuración de recurrencia
     * 
     * @param  User  $user              Usuario que crea las ocurrencias
     * @param  int   $max_occurrences   Máximo de ocurrencias a generar (seguridad contra loops infinitos)
     * @return int                      Número de ocurrencias creadas, <0 si error
     */
    public function generateRecurrences($user, $max_occurrences = 52)
    {
        if (empty($this->recurrence_enabled)) {
            return 0;
        }
        
        if (empty($this->date_start)) {
            $this->error = "Se requiere fecha de inicio para generar recurrencias";
            return -1;
        }
        
        $interval = max(1, (int) $this->recurrence_interval);
        $unit = in_array($this->recurrence_unit, array('days', 'weeks', 'months', 'years')) ? $this->recurrence_unit : 'weeks';
        $end_type = $this->recurrence_end_type;
        $end_date = $this->recurrence_end_date;
        
        // Para "forever", usar un horizonte amplio según la unidad
        if ($end_type !== 'date' || empty($end_date)) {
            // Horizonte: al menos 5 años desde hoy
            $horizon_timestamp = strtotime('+5 years');
            $end_timestamp = $horizon_timestamp;
        } else {
            $end_timestamp = strtotime($end_date.' 23:59:59');
        }
        
        // Tope de seguridad absoluto
        $absolute_max = 600; // Máximo 600 ocurrencias
        if ($max_occurrences > $absolute_max) $max_occurrences = $absolute_max;
        
        // Calcular duración de la consulta original (diferencia start-end)
        $duration = 0;
        if ($this->date_end > 0 && $this->date_start > 0) {
            $duration = $this->date_end - $this->date_start;
        }
        
        // Obtener usuarios asignados de la consulta padre
        $this->fetchAssignedUsers();
        $parent_user_ids = $this->getAssignedUserIds();
        
        // Obtener la fecha de la última hija existente para continuar desde ahí
        $last_child_date = $this->getLastChildDate();
        
        $count = 0;
        $current_start = ($last_child_date > 0) ? $last_child_date : $this->date_start;
        
        for ($i = 0; $i < $max_occurrences; $i++) {
            // Calcular la siguiente fecha
            $next_start = $this->addInterval($current_start, $interval, $unit);
            
            // Verificar si sobrepasamos la fecha fin / horizonte
            if ($end_timestamp && $next_start > $end_timestamp) {
                break;
            }
            
            // Verificar si ya existe una hija con esta fecha (evitar duplicados)
            if ($this->childExistsForDate($next_start)) {
                $current_start = $next_start;
                continue;
            }
            
            // Crear la consulta hija
            $child = new ExtConsultation($this->db);
            $child->entity = $this->entity;
            $child->fk_soc = $this->fk_soc;
            $child->fk_user = $this->fk_user;
            $child->date_start = $next_start;
            $child->date_end = ($duration > 0) ? ($next_start + $duration) : null;
            $child->tipo_atencion = $this->tipo_atencion;
            $child->cumplimiento = $this->cumplimiento;
            $child->razon_inc = $this->razon_inc;
            $child->mes_actual = $this->mes_actual;
            $child->proximo_mes = $this->proximo_mes;
            $child->dificultad = $this->dificultad;
            $child->motivo = $this->motivo;
            $child->diagnostico = $this->diagnostico;
            $child->procedimiento = $this->procedimiento;
            $child->insumos_enf = $this->insumos_enf;
            $child->rx_num = $this->rx_num;
            $child->medicamentos = $this->medicamentos;
            $child->observaciones = $this->observaciones;
            $child->custom_data = $this->custom_data;
            $child->status = self::STATUS_IN_PROGRESS; // Siempre en progreso las futuras
            $child->note_private = $this->note_private;
            $child->note_public = $this->note_public;
            
            // Marcar como hija (sin recurrencia propia)
            $child->recurrence_enabled = 0;
            $child->recurrence_parent_id = $this->id;
            
            $result = $child->create($user, 1); // notrigger=1 para performance
            
            if ($result < 0) {
                $this->error = $child->error;
                $this->errors = $child->errors;
                dol_syslog(get_class($this)."::generateRecurrences Error creating occurrence ".($i+1).": ".$child->error, LOG_ERR);
                return -1;
            }
            
            // Asignar los mismos usuarios a la consulta hija
            if (!empty($parent_user_ids)) {
                $child->setAssignedUsers($parent_user_ids, $user);
            }
            
            $count++;
            $current_start = $next_start;
        }
        
        dol_syslog(get_class($this)."::generateRecurrences Generated ".$count." occurrences from parent id=".$this->id, LOG_DEBUG);
        return $count;
    }
    
    /**
     * Obtener la fecha de la última hija generada
     * 
     * @return int Timestamp de la última hija, 0 si no hay
     */
    public function getLastChildDate()
    {
        $sql = "SELECT MAX(date_start) as last_date FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE recurrence_parent_id = ".((int) $this->id);
        
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj->last_date) {
                return $this->db->jdate($obj->last_date);
            }
        }
        return 0;
    }
    
    /**
     * Verificar si ya existe una hija para una fecha dada (±1 hora de tolerancia)
     * 
     * @param  int  $timestamp  Fecha a verificar
     * @return bool
     */
    public function childExistsForDate($timestamp)
    {
        $date_str = date('Y-m-d H:i:s', $timestamp);
        $date_min = date('Y-m-d H:i:s', $timestamp - 3600);
        $date_max = date('Y-m-d H:i:s', $timestamp + 3600);
        
        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE recurrence_parent_id = ".((int) $this->id);
        $sql .= " AND date_start BETWEEN '".$this->db->escape($date_min)."' AND '".$this->db->escape($date_max)."'";
        
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return ($obj->nb > 0);
        }
        return false;
    }
    
    /**
     * Extender recurrencias si la última hija generada está cerca en el tiempo.
     * Se llama desde las vistas de agenda para generar automáticamente más ocurrencias.
     * 
     * @param  User  $user  Usuario que ejecuta
     * @return int          Número de nuevas ocurrencias generadas
     */
    public function extendRecurrencesIfNeeded($user)
    {
        if (empty($this->recurrence_enabled)) {
            return 0;
        }
        
        // Si tiene fecha fin y ya la pasamos, no generar más
        if ($this->recurrence_end_type === 'date' && !empty($this->recurrence_end_date)) {
            if (strtotime($this->recurrence_end_date) < time()) {
                return 0;
            }
        }
        
        // Obtener la fecha de la última hija
        $last_child = $this->getLastChildDate();
        
        // Si la última hija es menos de 6 meses en el futuro, generar más
        $threshold = strtotime('+6 months');
        if ($last_child > 0 && $last_child > $threshold) {
            return 0; // Todavía hay suficientes ocurrencias futuras
        }
        
        // Generar más ocurrencias (hasta 5 años adelante desde hoy)
        return $this->generateRecurrences($user, 120);
    }
    
    /**
     * Método estático para extender todas las recurrencias activas que lo necesiten.
     * Se llama desde las vistas de agenda.
     * 
     * @param  DoliDB $db    Base de datos
     * @param  User   $user  Usuario
     * @param  int    $entity Entity
     * @return int           Total de nuevas ocurrencias generadas
     */
    public static function extendAllRecurrences($db, $user, $entity = 1)
    {
        $total = 0;
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."cabinetmed_extcons";
        $sql .= " WHERE recurrence_enabled = 1 AND entity = ".((int) $entity);
        $sql .= " AND (recurrence_parent_id IS NULL OR recurrence_parent_id = 0)";
        
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $parent = new ExtConsultation($db);
                $parent->fetch($obj->rowid);
                $result = $parent->extendRecurrencesIfNeeded($user);
                if ($result > 0) {
                    $total += $result;
                }
            }
        }
        
        return $total;
    }
    
    /**
     * Sumar un intervalo a una fecha (timestamp)
     * 
     * @param  int    $timestamp  Fecha base como timestamp Unix
     * @param  int    $interval   Cantidad de unidades a sumar
     * @param  string $unit       Unidad: days, weeks, months, years
     * @return int                Nuevo timestamp
     */
    private function addInterval($timestamp, $interval, $unit)
    {
        $dt = new DateTime();
        $dt->setTimestamp($timestamp);
        
        switch ($unit) {
            case 'days':
                $dt->modify('+'.$interval.' days');
                break;
            case 'weeks':
                $dt->modify('+'.($interval * 7).' days');
                break;
            case 'months':
                $dt->modify('+'.$interval.' months');
                break;
            case 'years':
                $dt->modify('+'.$interval.' years');
                break;
        }
        
        return $dt->getTimestamp();
    }
    
    /**
     * Eliminar todas las ocurrencias futuras (hijas) de esta consulta
     * Solo elimina las que están en estado "En progreso" (no completadas ni canceladas)
     * 
     * @param  User  $user   Usuario que realiza la eliminación
     * @return int           Número de ocurrencias eliminadas, <0 si error
     */
    public function deleteChildRecurrences($user)
    {
        $count = 0;
        
        // Obtener IDs de hijas en progreso (no tocar las ya completadas/canceladas)
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE recurrence_parent_id = ".((int) $this->id);
        $sql .= " AND status = ".self::STATUS_IN_PROGRESS;
        $sql .= " AND date_start > '".date('Y-m-d H:i:s')."'"; // Solo futuras
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        
        while ($obj = $this->db->fetch_object($resql)) {
            $child = new ExtConsultation($this->db);
            $child->fetch($obj->rowid);
            $result = $child->delete($user, 1);
            if ($result > 0) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Obtener las ocurrencias hijas de esta consulta recurrente
     * 
     * @return array  Array de objetos con información de las hijas
     */
    public function getChildRecurrences()
    {
        $children = array();
        
        $sql = "SELECT rowid, date_start, date_end, status";
        $sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE recurrence_parent_id = ".((int) $this->id);
        $sql .= " ORDER BY date_start ASC";
        
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $children[] = array(
                    'id' => $obj->rowid,
                    'date_start' => $this->db->jdate($obj->date_start),
                    'date_end' => $this->db->jdate($obj->date_end),
                    'status' => (int) $obj->status
                );
            }
        }
        
        return $children;
    }
    
    /**
     * Contar ocurrencias hijas
     * 
     * @return int Número de hijas
     */
    public function countChildRecurrences()
    {
        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE recurrence_parent_id = ".((int) $this->id);
        
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return (int) $obj->nb;
        }
        return 0;
    }
    
    /**
     * Obtener etiqueta de recurrencia legible
     * 
     * @return string  Texto legible de la configuración de recurrencia
     */
    public function getRecurrenceLabel()
    {
        if (empty($this->recurrence_enabled)) {
            return '';
        }
        
        $units = array(
            'days' => 'día(s)',
            'weeks' => 'semana(s)',
            'months' => 'mes(es)',
            'years' => 'año(s)'
        );
        
        $unit_label = isset($units[$this->recurrence_unit]) ? $units[$this->recurrence_unit] : $this->recurrence_unit;
        
        $label = 'Cada '.$this->recurrence_interval.' '.$unit_label;
        
        if ($this->recurrence_end_type === 'date' && !empty($this->recurrence_end_date)) {
            $label .= ' hasta '.dol_print_date(strtotime($this->recurrence_end_date), 'day');
        } else {
            $label .= ' (sin fecha fin)';
        }
        
        return $label;
    }
    
    /**
     * Verificar si esta consulta es una ocurrencia hija de una recurrencia
     * 
     * @return bool
     */
    public function isChildRecurrence()
    {
        return ($this->recurrence_parent_id > 0);
    }
    
    /**
     * Verificar si esta consulta es padre de una recurrencia
     * 
     * @return bool
     */
    public function isRecurrenceParent()
    {
        return ($this->recurrence_enabled > 0);
    }

    /**
     * Resuelve las opciones de un campo. Si comienza con "db:", consulta la tabla de la BD.
     * Formato: db:nombre_tabla:campo_clave:campo_etiqueta[:filtro_where_opcional]
     * Ejemplo: db:llx_societe:rowid:nom
     * Ejemplo: db:llx_product:rowid:label:fk_product_type=0
     * 
     * @param  string  $field_options  Las opciones tal como están en field_options
     * @param  DoliDB  $db             Conexión a BD
     * @return array   Array asociativo clave => etiqueta
     */
    public static function resolveFieldOptions($field_options, $db)
    {
        $opts_array = array();
        
        if (empty($field_options)) {
            return $opts_array;
        }
        
        // Detectar si es una referencia a tabla de BD
        if (strpos($field_options, 'db:') === 0) {
            return self::resolveDbFieldOptions($field_options, $db);
        }
        
        // Opciones estáticas normales (separadas por comas)
        $opts_parts = explode(',', $field_options);
        foreach ($opts_parts as $opt) {
            $opt = trim($opt);
            if ($opt === '') continue;
            if (strpos($opt, ':') !== false) {
                list($key, $label) = explode(':', $opt, 2);
                $opts_array[trim($key)] = trim($label);
            } else {
                $opts_array[$opt] = $opt;
            }
        }
        
        return $opts_array;
    }
    
    /**
     * Resuelve opciones desde una tabla de la base de datos.
     * 
     * @param  string  $field_options  Formato: db:tabla:campo_clave:campo_etiqueta[:filtro_where]
     * @param  DoliDB  $db             Conexión a BD
     * @return array   Array asociativo clave => etiqueta
     */
    public static function resolveDbFieldOptions($field_options, $db)
    {
        $opts_array = array();
        
        // Parsear: db:tabla:key:label[:where]
        $parts = explode(':', $field_options);
        if (count($parts) < 4) {
            return $opts_array;
        }
        
        $table = $db->escape(trim($parts[1]));
        $key_field = $db->escape(trim($parts[2]));
        $label_field = $db->escape(trim($parts[3]));
        $where_clause = isset($parts[4]) ? trim($parts[4]) : '';
        
        // Seguridad: validar que los nombres de campo/tabla son alfanuméricos + underscore + prefijo llx_
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) && !preg_match('/^llx_[a-zA-Z0-9_]+$/', $table)) {
            return $opts_array;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key_field)) {
            return $opts_array;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $label_field)) {
            return $opts_array;
        }
        
        // Si la tabla no tiene prefijo, agregarlo
        if (strpos($table, 'llx_') !== 0) {
            $table = MAIN_DB_PREFIX.$table;
        }
        
        $sql = "SELECT ".$key_field." as opt_key, ".$label_field." as opt_label";
        $sql .= " FROM ".$table;
        if (!empty($where_clause)) {
            // Permitir solo condiciones WHERE simples
            $sql .= " WHERE ".$where_clause;
        }
        $sql .= " ORDER BY ".$label_field." ASC";
        $sql .= " LIMIT 500"; // Seguridad: máximo 500 opciones
        
        dol_syslog("ExtConsultation::resolveDbFieldOptions sql=".$sql, LOG_DEBUG);
        
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $opts_array[$obj->opt_key] = $obj->opt_label;
            }
            $db->free($resql);
        } else {
            dol_syslog("ExtConsultation::resolveDbFieldOptions ERROR: ".$db->lasterror(), LOG_ERR);
        }
        
        return $opts_array;
    }
    
    /**
     * Obtener las tablas disponibles en la BD para autocompletado en el admin.
     * 
     * @param  DoliDB  $db  Conexión
     * @return array   Lista de nombres de tabla
     */
    public static function getAvailableDbTables($db)
    {
        $tables = array();
        $resql = $db->query("SHOW TABLES");
        if ($resql) {
            while ($row = $db->fetch_array($resql)) {
                $tables[] = $row[0];
            }
            $db->free($resql);
        }
        return $tables;
    }
    
    /**
     * Obtener las columnas de una tabla para autocompletado en el admin.
     * 
     * @param  DoliDB  $db         Conexión
     * @param  string  $table_name Nombre de la tabla
     * @return array   Lista de nombres de columna
     */
    public static function getTableColumns($db, $table_name)
    {
        $columns = array();
        $table_name = $db->escape($table_name);
        $resql = $db->query("SHOW COLUMNS FROM ".$table_name);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $columns[] = $obj->Field;
            }
            $db->free($resql);
        }
        return $columns;
    }

    /**
     * Sanitize HTML from contenteditable imgtext editor.
     * Keeps only safe formatting tags and base64/http(s) images.
     *
     * @param  string $html  Raw HTML from contenteditable
     * @return string        Sanitized HTML
     */
    public static function sanitizeImgTextHtml($html)
    {
        if (empty($html)) {
            return '';
        }
        
        // Paso 1: Eliminar scripts, iframes, objects, embeds completos
        $html = preg_replace('/<\s*(script|iframe|object|embed|applet|form|input|button|link|meta|base)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html);
        $html = preg_replace('/<\s*(script|iframe|object|embed|applet|form|input|button|link|meta|base)[^>]*\/?>/', '', $html);
        
        // Paso 2: Eliminar event handlers (on*)
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\S+/', '', $html);
        
        // Paso 3: Eliminar javascript: y vbscript: en atributos href/src
        $html = preg_replace('/\b(href|src)\s*=\s*["\']?\s*(javascript|vbscript)\s*:/i', '$1="removed:', $html);
        
        // Paso 4: En tags <img>, asegurar que src es data: o http(s)
        $html = preg_replace_callback('/<img\s([^>]*)>/i', function($matches) {
            $attrs = $matches[1];
            if (preg_match('/src\s*=\s*["\']?\s*(data:image\/|https?:\/\/)/i', $attrs)) {
                $safe_attrs = '';
                if (preg_match('/src\s*=\s*(["\'])(.+?)\1/i', $attrs, $src)) {
                    $safe_attrs .= ' src="'.$src[2].'"';
                } elseif (preg_match('/src\s*=\s*(\S+)/i', $attrs, $src)) {
                    $safe_attrs .= ' src="'.$src[1].'"';
                }
                if (preg_match('/style\s*=\s*(["\'])(.+?)\1/i', $attrs, $style)) {
                    $safe_style = preg_replace('/[^a-zA-Z0-9:;%\s.#\-,()]/', '', $style[2]);
                    $safe_attrs .= ' style="'.$safe_style.'"';
                }
                if (preg_match('/alt\s*=\s*(["\'])(.+?)\1/i', $attrs, $alt)) {
                    $safe_attrs .= ' alt="'.dol_escape_htmltag($alt[2]).'"';
                }
                return '<img'.$safe_attrs.'>';
            }
            return '';
        }, $html);
        
        // Paso 5: Whitelist final — solo permitir tags seguros de formato básico + img
        $html = strip_tags($html, '<img><br><p><div><span><strong><em><u><b><i><ul><ol><li><hr>');
        
        return $html;
    }

}