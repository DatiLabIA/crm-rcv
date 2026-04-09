<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/importfill_job.class.php
 * \ingroup importfill
 * \brief   Class to manage import jobs
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class ImportFillJob
 * Manages import job records
 */
class ImportFillJob extends CommonObject
{
    /** @var string Module element */
    public $element = 'importfill_job';

    /** @var string Table name */
    public $table_element = 'importfill_job';

    // Fields
    public $entity;
    public $datec;
    public $tms;
    public $fk_user_author;
    public $status;
    public $filename_original;
    public $filepath;
    public $mapping_json;
    public $options_json;
    public $stats_json;
    public $import_mode;
    public $delimiter_char;
    public $encoding;
    public $has_header;
    public $note_private;
    public $note_public;

    // Status constants
    const STATUS_DRAFT   = 'draft';
    const STATUS_MAPPED  = 'mapped';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

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
     * Create a new job
     *
     * @param User $user User creating the job
     * @return int >0 if OK, <0 if KO
     */
    public function create($user)
    {
        global $conf;

        $this->entity = $conf->entity;
        $this->datec = dol_now();
        $this->fk_user_author = $user->id;
        $this->status = self::STATUS_DRAFT;

        if (empty($this->import_mode)) {
            $this->import_mode = 'fill_empty';
        }
        if (empty($this->delimiter_char)) {
            $this->delimiter_char = ',';
        }
        if (empty($this->encoding)) {
            $this->encoding = 'UTF-8';
        }
        if (!isset($this->has_header)) {
            $this->has_header = 1;
        }

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."importfill_job (";
        $sql .= "entity, datec, fk_user_author, status,";
        $sql .= "filename_original, filepath, mapping_json, options_json,";
        $sql .= "import_mode, delimiter_char, encoding, has_header";
        $sql .= ") VALUES (";
        $sql .= ((int) $this->entity).",";
        $sql .= "'".$this->db->idate($this->datec)."',";
        $sql .= ((int) $this->fk_user_author).",";
        $sql .= "'".$this->db->escape($this->status)."',";
        $sql .= ($this->filename_original ? "'".$this->db->escape($this->filename_original)."'" : "NULL").",";
        $sql .= ($this->filepath ? "'".$this->db->escape($this->filepath)."'" : "NULL").",";
        $sql .= ($this->mapping_json ? "'".$this->db->escape($this->mapping_json)."'" : "NULL").",";
        $sql .= ($this->options_json ? "'".$this->db->escape($this->options_json)."'" : "NULL").",";
        $sql .= "'".$this->db->escape($this->import_mode)."',";
        $sql .= "'".$this->db->escape($this->delimiter_char)."',";
        $sql .= "'".$this->db->escape($this->encoding)."',";
        $sql .= ((int) $this->has_header);
        $sql .= ")";

        $this->db->begin();

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."importfill_job");

        $this->db->commit();
        return $this->id;
    }

    /**
     * Fetch a job by ID
     *
     * @param int $id Job ID
     * @return int 1 if OK, 0 if not found, <0 if KO
     */
    public function fetch($id)
    {
        $sql = "SELECT rowid, entity, datec, tms, fk_user_author, status,";
        $sql .= " filename_original, filepath, mapping_json, options_json, stats_json,";
        $sql .= " import_mode, delimiter_char, encoding, has_header,";
        $sql .= " note_private, note_public";
        $sql .= " FROM ".MAIN_DB_PREFIX."importfill_job";
        $sql .= " WHERE rowid = ".((int) $id);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        if ($this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);

            $this->id                = $obj->rowid;
            $this->entity            = $obj->entity;
            $this->datec             = $this->db->jdate($obj->datec);
            $this->tms               = $this->db->jdate($obj->tms);
            $this->fk_user_author    = $obj->fk_user_author;
            $this->status            = $obj->status;
            $this->filename_original = $obj->filename_original;
            $this->filepath          = $obj->filepath;
            $this->mapping_json      = $obj->mapping_json;
            $this->options_json      = $obj->options_json;
            $this->stats_json        = $obj->stats_json;
            $this->import_mode       = $obj->import_mode;
            $this->delimiter_char    = $obj->delimiter_char;
            $this->encoding          = $obj->encoding;
            $this->has_header        = $obj->has_header;
            $this->note_private      = $obj->note_private;
            $this->note_public       = $obj->note_public;

            $this->db->free($resql);
            return 1;
        }

        $this->db->free($resql);
        return 0;
    }

    /**
     * Update job record
     *
     * @param User $user User performing update
     * @return int >0 if OK, <0 if KO
     */
    public function update($user)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."importfill_job SET";
        $sql .= " status = '".$this->db->escape($this->status)."',";
        $sql .= " filename_original = ".($this->filename_original ? "'".$this->db->escape($this->filename_original)."'" : "NULL").",";
        $sql .= " filepath = ".($this->filepath ? "'".$this->db->escape($this->filepath)."'" : "NULL").",";
        $sql .= " mapping_json = ".($this->mapping_json ? "'".$this->db->escape($this->mapping_json)."'" : "NULL").",";
        $sql .= " options_json = ".($this->options_json ? "'".$this->db->escape($this->options_json)."'" : "NULL").",";
        $sql .= " stats_json = ".($this->stats_json ? "'".$this->db->escape($this->stats_json)."'" : "NULL").",";
        $sql .= " import_mode = '".$this->db->escape($this->import_mode)."',";
        $sql .= " delimiter_char = '".$this->db->escape($this->delimiter_char)."',";
        $sql .= " encoding = '".$this->db->escape($this->encoding)."',";
        $sql .= " has_header = ".((int) $this->has_header).",";
        $sql .= " note_private = ".($this->note_private ? "'".$this->db->escape($this->note_private)."'" : "NULL").",";
        $sql .= " note_public = ".($this->note_public ? "'".$this->db->escape($this->note_public)."'" : "NULL");
        $sql .= " WHERE rowid = ".((int) $this->id);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Delete job and its lines
     *
     * @param User $user User performing delete
     * @return int >0 if OK, <0 if KO
     */
    public function delete($user)
    {
        $this->db->begin();

        // Lines are deleted by CASCADE, but let's be explicit
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."importfill_job_line WHERE fk_job = ".((int) $this->id);
        $this->db->query($sql);

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."importfill_job WHERE rowid = ".((int) $this->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        // Remove stored file
        if ($this->filepath && file_exists($this->filepath)) {
            @unlink($this->filepath);
        }

        $this->db->commit();
        return 1;
    }

    /**
     * Add a line result to the job
     *
     * @param int    $line_num    CSV line number
     * @param string $key_value   Normalized n_documento value
     * @param string $action      create|update|skip|error
     * @param int    $fk_societe  Affected third party ID
     * @param string $message     Result description
     * @param array  $payload     Applied changes
     * @return int >0 if OK, <0 if KO
     */
    public function addLine($line_num, $key_value, $action, $fk_societe = 0, $message = '', $payload = array())
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."importfill_job_line (";
        $sql .= "fk_job, line_num, key_value, action, fk_societe, message, payload_json, datec";
        $sql .= ") VALUES (";
        $sql .= ((int) $this->id).",";
        $sql .= ((int) $line_num).",";
        $sql .= ($key_value ? "'".$this->db->escape($key_value)."'" : "NULL").",";
        $sql .= "'".$this->db->escape($action)."',";
        $sql .= ($fk_societe > 0 ? ((int) $fk_societe) : "NULL").",";
        $sql .= "'".$this->db->escape($message)."',";
        $sql .= (!empty($payload) ? "'".$this->db->escape(json_encode($payload))."'" : "NULL").",";
        $sql .= "'".$this->db->idate(dol_now())."'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return 1;
    }

    /**
     * Get lines for this job
     *
     * @param string $filterAction Filter by action (optional)
     * @param int    $limit        Max rows (0 = all)
     * @param int    $offset       Offset
     * @return array|int Array of lines or -1 on error
     */
    public function getLines($filterAction = '', $limit = 0, $offset = 0)
    {
        $sql = "SELECT rowid, fk_job, line_num, key_value, action, fk_societe, message, payload_json, datec";
        $sql .= " FROM ".MAIN_DB_PREFIX."importfill_job_line";
        $sql .= " WHERE fk_job = ".((int) $this->id);
        if ($filterAction) {
            $sql .= " AND action = '".$this->db->escape($filterAction)."'";
        }
        $sql .= " ORDER BY line_num ASC";
        if ($limit > 0) {
            $sql .= " LIMIT ".((int) $limit);
            if ($offset > 0) {
                $sql .= " OFFSET ".((int) $offset);
            }
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $lines = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $lines[] = $obj;
        }
        $this->db->free($resql);

        return $lines;
    }

    /**
     * Count lines by action
     *
     * @return array Associative array of action => count
     */
    public function countLinesByAction()
    {
        $sql = "SELECT action, COUNT(*) as cnt";
        $sql .= " FROM ".MAIN_DB_PREFIX."importfill_job_line";
        $sql .= " WHERE fk_job = ".((int) $this->id);
        $sql .= " GROUP BY action";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return array();
        }

        $result = array('create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0);
        while ($obj = $this->db->fetch_object($resql)) {
            $result[$obj->action] = (int) $obj->cnt;
        }
        $this->db->free($resql);

        return $result;
    }

    /**
     * Get mapping as array
     *
     * @return array
     */
    public function getMapping()
    {
        if (!empty($this->mapping_json)) {
            return json_decode($this->mapping_json, true) ?: array();
        }
        return array();
    }

    /**
     * Get options as array
     *
     * @return array
     */
    public function getOptions()
    {
        if (!empty($this->options_json)) {
            return json_decode($this->options_json, true) ?: array();
        }
        return array();
    }

    /**
     * Get stats as array
     *
     * @return array
     */
    public function getStats()
    {
        if (!empty($this->stats_json)) {
            return json_decode($this->stats_json, true) ?: array();
        }
        return array();
    }

    /**
     * Set status
     *
     * @param string $status New status
     * @return int 1 if OK, <0 if KO
     */
    public function setStatus($status)
    {
        $this->status = $status;
        $sql = "UPDATE ".MAIN_DB_PREFIX."importfill_job SET status = '".$this->db->escape($status)."'";
        $sql .= " WHERE rowid = ".((int) $this->id);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        return 1;
    }

    /**
     * Save stats
     *
     * @param array $stats Stats array
     * @return int 1 if OK, <0 if KO
     */
    public function saveStats($stats)
    {
        $this->stats_json = json_encode($stats);
        $sql = "UPDATE ".MAIN_DB_PREFIX."importfill_job SET stats_json = '".$this->db->escape($this->stats_json)."'";
        $sql .= " WHERE rowid = ".((int) $this->id);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        return 1;
    }

    /**
     * Fetch list of jobs
     *
     * @param string $sortfield Sort field
     * @param string $sortorder Sort order
     * @param int    $limit     Limit
     * @param int    $offset    Offset
     * @param string $filter    SQL filter
     * @return array|int Array of jobs or -1 on error
     */
    public function fetchAll($sortfield = 'datec', $sortorder = 'DESC', $limit = 0, $offset = 0, $filter = '')
    {
        global $conf;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."importfill_job";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        if ($filter) {
            $sql .= " AND ".$filter;
        }
        $sql .= $this->db->order($sortfield, $sortorder);
        if ($limit > 0) {
            $sql .= $this->db->plimit($limit, $offset);
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $jobs = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $job = new self($this->db);
            $job->fetch($obj->rowid);
            $jobs[] = $job;
        }
        $this->db->free($resql);

        return $jobs;
    }

    /**
     * Get status label
     *
     * @param int $mode 0=long, 1=short
     * @return string
     */
    public function getLibStatut($mode = 0)
    {
        $statusLabels = array(
            self::STATUS_DRAFT   => array('short' => 'Draft',   'long' => 'Draft'),
            self::STATUS_MAPPED  => array('short' => 'Mapped',  'long' => 'Mapping complete'),
            self::STATUS_RUNNING => array('short' => 'Running', 'long' => 'Running'),
            self::STATUS_DONE    => array('short' => 'Done',    'long' => 'Completed'),
            self::STATUS_FAILED  => array('short' => 'Failed',  'long' => 'Failed'),
        );

        $statusColors = array(
            self::STATUS_DRAFT   => 'status0',
            self::STATUS_MAPPED  => 'status1',
            self::STATUS_RUNNING => 'status4',
            self::STATUS_DONE    => 'status6',
            self::STATUS_FAILED  => 'status8',
        );

        $key = $mode ? 'short' : 'long';
        $label = isset($statusLabels[$this->status]) ? $statusLabels[$this->status][$key] : $this->status;
        $class = isset($statusColors[$this->status]) ? $statusColors[$this->status] : 'status0';

        return '<span class="badge badge-'.$class.'">'.$label.'</span>';
    }
}
