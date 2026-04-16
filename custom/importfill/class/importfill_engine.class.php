<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/importfill_engine.class.php
 * \ingroup importfill
 * \brief   Main processing engine for ImportFill
 */

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * Class ImportFillEngine
 * Handles the CSV import logic with upsert by n_documento
 */
class ImportFillEngine
{
    /** @var DoliDB */
    private $db;

    /** @var User */
    private $user;

    /** @var ImportFillJob */
    private $job;

    /** @var array Mapping configuration */
    private $mapping;

    /** @var string Import mode: fill_empty or overwrite */
    private $importMode;

    /** @var array Stats counters */
    private $stats;

    /** @var array Error messages */
    public $errors = array();

    /**
     * Constructor
     *
     * @param DoliDB        $db   Database handler
     * @param User          $user Current user
     * @param ImportFillJob $job  Job to process
     */
    public function __construct($db, $user, $job)
    {
        $this->db = $db;
        $this->user = $user;
        $this->job = $job;
        $this->mapping = $job->getMapping();
        $this->importMode = $job->import_mode ?: 'fill_empty';
        $this->stats = array(
            'total'   => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'start'   => dol_now(),
            'end'     => 0,
        );
    }

    /**
     * Normalize n_documento value
     * Removes spaces, dots, hyphens, and converts to uppercase
     *
     * @param string $value Raw value
     * @return string Normalized value
     */
    public function normalizeKey($value)
    {
        $value = trim($value);
        $value = str_replace(array(' ', '.', '-'), '', $value);
        $value = strtoupper($value);
        return $value;
    }

    /**
     * Set a core field directly in llx_societe via SQL.
     * Used for columns that Societe::update() does not handle.
     *
     * @param int    $societeId Societe ID
     * @param string $field     Column name
     * @param string $value     Value to set
     * @return bool
     */
    private function setCoreFieldDirect($societeId, $field, $value)
    {
        // Sanitize field name: only allow alphanumeric and underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return false;
        }

        $sql = "UPDATE ".MAIN_DB_PREFIX."societe";
        $sql .= " SET `".$this->db->escape($field)."` = '".$this->db->escape($value)."'";
        $sql .= " WHERE rowid = ".((int) $societeId);

        return ($this->db->query($sql) !== false);
    }

    /**
     * Get current value of a core field directly from llx_societe.
     * Used for columns that Societe::fetch() may not load as object properties.
     *
     * @param int    $societeId Societe ID
     * @param string $field     Column name
     * @return string|null Current value or null
     */
    private function getCoreFieldDirect($societeId, $field)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return null;
        }

        $sql = "SELECT `".$this->db->escape($field)."` as val";
        $sql .= " FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE rowid = ".((int) $societeId);

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return ($obj->val !== null) ? (string) $obj->val : null;
        }
        return null;
    }

    /**
     * Get all extrafield values for a societe directly from DB.
     *
     * @param int $societeId Societe ID
     * @return array Associative array of extrafield_name => value
     */
    private function getAllExtrafields($societeId)
    {
        $result = array();

        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."societe_extrafields";
        $sql .= " WHERE fk_object = ".((int) $societeId);

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $skipCols = array('rowid', 'tms', 'fk_object');
                foreach (get_object_vars($obj) as $key => $val) {
                    if (in_array($key, $skipCols)) {
                        continue;
                    }
                    $result[$key] = $val;
                }
            }
            $this->db->free($resql);
        }

        return $result;
    }

    /**
     * Find Societe by n_documento extrafield
     *
     * @param string $documento Normalized document number
     * @return int|false Societe ID or false if not found, -1 if multiple found
     */
    public function findSocieteByDocumento($documento)
    {
        global $conf;

        $sql = "SELECT fk_object";
        $sql .= " FROM ".MAIN_DB_PREFIX."societe_extrafields";
        $sql .= " WHERE n_documento = '".$this->db->escape($documento)."'";

        // Entity filter: join to societe to get entity
        $sql = "SELECT se.fk_object";
        $sql .= " FROM ".MAIN_DB_PREFIX."societe_extrafields AS se";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = se.fk_object";
        $sql .= " WHERE se.n_documento = '".$this->db->escape($documento)."'";
        $sql .= " AND s.entity IN (".getEntity('societe').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }

        $num = $this->db->num_rows($resql);

        if ($num === 0) {
            $this->db->free($resql);
            return false;
        }

        if ($num > 1) {
            $this->db->free($resql);
            return -1; // Multiple matches
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return (int) $obj->fk_object;
    }

    /**
     * Process a single CSV row
     *
     * @param int   $lineNum CSV line number
     * @param array $rowData CSV row data (indexed by column position)
     * @return string Action performed: create|update|skip|error
     */
    public function processRow($lineNum, $rowData)
    {
        $this->stats['total']++;

        // Extract n_documento from mapping
        $documento = '';
        $coreData = array();
        $extraData = array();

        foreach ($this->mapping as $csvIndex => $dest) {
            if (empty($dest) || $dest === '--') {
                continue;
            }

            $value = isset($rowData[$csvIndex]) ? trim($rowData[$csvIndex]) : '';

            if ($dest === 'extra.n_documento') {
                $documento = $this->normalizeKey($value);
            } elseif (strpos($dest, 'extra.') === 0) {
                $fieldName = substr($dest, 6);
                $extraData[$fieldName] = $value;
            } elseif (strpos($dest, 'core.') === 0) {
                $fieldName = substr($dest, 5);
                $coreData[$fieldName] = $value;
            } elseif (strpos($dest, 'lookup.') === 0) {
                $this->resolveLookup($dest, $value, $coreData);
            }
        }

        // Validate n_documento
        if (empty($documento)) {
            $this->stats['errors']++;
            $this->job->addLine($lineNum, '', 'error', 0, 'Empty or missing n_documento');
            return 'error';
        }

        // Lookup existing societe
        $societeId = $this->findSocieteByDocumento($documento);

        if ($societeId === -1) {
            $this->stats['errors']++;
            $this->job->addLine($lineNum, $documento, 'error', 0, 'Multiple records found for n_documento: '.$documento);
            return 'error';
        }

        if ($societeId !== false && $societeId > 0) {
            // UPDATE existing
            return $this->updateSociete($lineNum, $societeId, $documento, $coreData, $extraData);
        } else {
            // CREATE new
            return $this->createSociete($lineNum, $documento, $coreData, $extraData);
        }
    }

    /**
     * Create a new Societe record
     *
     * @param int    $lineNum    CSV line number
     * @param string $documento  Normalized n_documento
     * @param array  $coreData   Core field values
     * @param array  $extraData  Extrafield values
     * @return string create|error
     */
    private function createSociete($lineNum, $documento, $coreData, $extraData)
    {
        global $conf;

        $societe = new Societe($this->db);

        // Set only the minimum required for Societe::create()
        // (handles code generation, triggers, initial setup)
        if (!empty($coreData['nom'])) {
            $societe->name = $coreData['nom'];
            unset($coreData['nom']);
        } else {
            $societe->name = 'Import-'.$documento;
        }

        // Apply a few fields that create() handles well by known property name
        if (isset($coreData['client'])) {
            $societe->client = $coreData['client'];
            unset($coreData['client']);
        } else {
            $societe->client = 1;
        }
        if (isset($coreData['fournisseur'])) {
            $societe->fournisseur = $coreData['fournisseur'];
            unset($coreData['fournisseur']);
        }
        if (isset($coreData['status'])) {
            $societe->status = $coreData['status'];
            unset($coreData['status']);
        } else {
            $societe->status = 1;
        }
        if (isset($coreData['code_client'])) {
            $societe->code_client = $coreData['code_client'];
            unset($coreData['code_client']);
        }
        if (isset($coreData['code_fournisseur'])) {
            $societe->code_fournisseur = $coreData['code_fournisseur'];
            unset($coreData['code_fournisseur']);
        }

        $societe->entity = $conf->entity;

        // Create the societe with minimum fields
        $this->db->begin();

        $result = $societe->create($this->user);
        if ($result < 0) {
            $this->db->rollback();
            $this->stats['errors']++;
            $errMsg = is_array($societe->errors) ? implode(', ', $societe->errors) : $societe->error;
            $this->job->addLine($lineNum, $documento, 'error', 0, 'Create failed: '.$errMsg);
            return 'error';
        }

        // Now set ALL remaining core fields via direct SQL (avoids property name mismatches)
        foreach ($coreData as $field => $value) {
            if ($value !== '') {
                $this->setCoreFieldDirect($result, $field, $value);
            }
        }

        // Set n_documento extrafield
        $extraData['n_documento'] = $documento;

        // Ensure extrafields row exists
        $this->ensureExtrafieldsRow($result);

        // Set extrafields
        foreach ($extraData as $efName => $efValue) {
            if ($efValue !== '') {
                $this->setExtrafield($result, $efName, $efValue);
            }
        }

        $this->db->commit();
        $this->stats['created']++;

        $payload = array_merge(
            array('n_documento' => $documento),
            $coreData,
            $extraData
        );
        $this->job->addLine($lineNum, $documento, 'create', $result, 'Created new third party: '.$societe->name, $payload);

        return 'create';
    }

    /**
     * Update existing Societe (fill empty fields only)
     *
     * @param int    $lineNum    CSV line number
     * @param int    $societeId  Existing societe ID
     * @param string $documento  Normalized n_documento
     * @param array  $coreData   Core field values
     * @param array  $extraData  Extrafield values
     * @return string update|skip|error
     */
    private function updateSociete($lineNum, $societeId, $documento, $coreData, $extraData)
    {
        $changes = array();
        $hasChanges = false;

        $this->db->begin();

        // Process core fields - ALL via direct SQL to avoid property name mismatches
        foreach ($coreData as $field => $value) {
            if ($value === '') {
                continue;
            }

            // Read current value directly from DB column
            $currentValue = $this->getCoreFieldDirect($societeId, $field);
            $currentTrimmed = ($currentValue !== null) ? trim($currentValue) : '';

            $shouldUpdate = false;
            if ($this->importMode === 'fill_empty') {
                // Consider empty: null, empty string, or zero for numeric FK fields
                if ($currentValue === null || $currentTrimmed === '') {
                    $shouldUpdate = true;
                }
            } else {
                // Overwrite mode
                if ($currentTrimmed !== trim($value)) {
                    $shouldUpdate = true;
                }
            }

            if ($shouldUpdate) {
                if ($this->setCoreFieldDirect($societeId, $field, $value)) {
                    $changes[$field] = $value;
                    $hasChanges = true;
                }
            }
        }

        // Process extrafields
        // Read current extrafield values directly from DB
        $currentExtras = $this->getAllExtrafields($societeId);

        foreach ($extraData as $efName => $efValue) {
            if ($efValue === '') {
                continue;
            }

            $currentEfValue = isset($currentExtras[$efName]) ? trim((string) $currentExtras[$efName]) : '';
            $currentEfRaw = isset($currentExtras[$efName]) ? $currentExtras[$efName] : null;

            $shouldUpdate = false;
            if ($this->importMode === 'fill_empty') {
                if ($currentEfRaw === null || $currentEfValue === '') {
                    $shouldUpdate = true;
                }
            } else {
                if ($currentEfValue !== trim($efValue)) {
                    $shouldUpdate = true;
                }
            }

            if ($shouldUpdate) {
                $this->setExtrafield($societeId, $efName, $efValue);
                $changes['extra.'.$efName] = $efValue;
                $hasChanges = true;
            }
        }

        if (!$hasChanges) {
            $this->db->commit();
            $this->stats['skipped']++;
            $this->job->addLine($lineNum, $documento, 'skip', $societeId, 'No empty fields to fill');
            return 'skip';
        }

        // Update tms (modification timestamp)
        $this->setCoreFieldDirect($societeId, 'tms', $this->db->idate(dol_now()));

        $this->db->commit();
        $this->stats['updated']++;
        $this->job->addLine($lineNum, $documento, 'update', $societeId, 'Updated '.count($changes).' field(s)', $changes);

        return 'update';
    }

    /**
     * Resolve a lookup mapping destination to actual core field values.
     * Queries dictionary tables to convert IDs/codes to the right values.
     *
     * @param string $dest      Lookup destination (e.g. lookup.town_by_dept_id)
     * @param string $value     Raw value from CSV
     * @param array  &$coreData Core data array (modified by reference)
     */
    private function resolveLookup($dest, $value, &$coreData)
    {
        if ($value === '') {
            return;
        }

        switch ($dest) {
            case 'lookup.town_by_dept_id':
                // Value is a c_departements.rowid → resolve to department name and store in town
                $resolved = $this->getDepartementNameById((int) $value);
                if ($resolved !== false) {
                    $coreData['town'] = $resolved;
                    // Also set fk_departement if not already mapped
                    if (!isset($coreData['fk_departement'])) {
                        $coreData['fk_departement'] = (int) $value;
                    }
                } else {
                    // Fallback: store raw value so data is not lost
                    $coreData['town'] = $value;
                }
                break;

            case 'lookup.town_by_dept_code':
                // Value is a department code (e.g. DANE code) → resolve to name
                $resolved = $this->getDepartementByCode($value);
                if ($resolved !== false) {
                    $coreData['town'] = $resolved['nom'];
                    if (!isset($coreData['fk_departement'])) {
                        $coreData['fk_departement'] = $resolved['rowid'];
                    }
                } else {
                    $coreData['town'] = $value;
                }
                break;

            case 'lookup.town_by_ziptown_id':
                // Value is a c_ziptown.rowid → resolve to town name
                $resolved = $this->getZiptownById((int) $value);
                if ($resolved !== false) {
                    $coreData['town'] = $resolved['town'];
                    if (!empty($resolved['fk_county']) && !isset($coreData['fk_departement'])) {
                        $coreData['fk_departement'] = $resolved['fk_county'];
                    }
                    if (!empty($resolved['zip']) && !isset($coreData['zip'])) {
                        $coreData['zip'] = $resolved['zip'];
                    }
                } else {
                    $coreData['town'] = $value;
                }
                break;

            case 'lookup.dept_by_id':
                // Value is c_departements.rowid → validate it exists and set fk_departement
                $resolved = $this->getDepartementNameById((int) $value);
                if ($resolved !== false) {
                    $coreData['fk_departement'] = (int) $value;
                }
                break;

            case 'lookup.dept_by_code':
                // Value is a department code → resolve to rowid for fk_departement
                $resolved = $this->getDepartementByCode($value);
                if ($resolved !== false) {
                    $coreData['fk_departement'] = $resolved['rowid'];
                }
                break;
        }
    }

    /**
     * Get department name by rowid from c_departements
     *
     * @param int $rowid Department rowid
     * @return string|false Department name or false if not found
     */
    private function getDepartementNameById($rowid)
    {
        if (empty($rowid)) {
            return false;
        }

        $sql = "SELECT nom FROM ".MAIN_DB_PREFIX."c_departements WHERE rowid = ".((int) $rowid);
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return $obj->nom;
        }
        return false;
    }

    /**
     * Get department by code from c_departements
     *
     * @param string $code Department code (e.g. DANE code)
     * @return array|false Array with 'rowid' and 'nom', or false
     */
    private function getDepartementByCode($code)
    {
        if (empty($code)) {
            return false;
        }

        $sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."c_departements";
        $sql .= " WHERE code_departement = '".$this->db->escape(trim($code))."'";
        $sql .= " AND active = 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return array('rowid' => (int) $obj->rowid, 'nom' => $obj->nom);
        }
        return false;
    }

    /**
     * Get town info by rowid from c_ziptown
     *
     * @param int $rowid Ziptown rowid
     * @return array|false Array with 'town', 'zip', 'fk_county', or false
     */
    private function getZiptownById($rowid)
    {
        if (empty($rowid)) {
            return false;
        }

        $sql = "SELECT town, zip, fk_county FROM ".MAIN_DB_PREFIX."c_ziptown WHERE rowid = ".((int) $rowid);
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return array(
                'town' => $obj->town,
                'zip' => $obj->zip,
                'fk_county' => !empty($obj->fk_county) ? (int) $obj->fk_county : 0,
            );
        }
        return false;
    }

    /**
     * Ensure extrafields row exists for a societe
     *
     * @param int $societeId Societe ID
     */
    private function ensureExtrafieldsRow($societeId)
    {
        $sql = "SELECT fk_object FROM ".MAIN_DB_PREFIX."societe_extrafields WHERE fk_object = ".((int) $societeId);
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) === 0) {
            $sql2 = "INSERT INTO ".MAIN_DB_PREFIX."societe_extrafields (fk_object) VALUES (".((int) $societeId).")";
            $this->db->query($sql2);
        }
    }

    /**
     * Set a single extrafield value directly in database
     *
     * @param int    $societeId Societe ID
     * @param string $name      Extrafield name
     * @param string $value     Value to set
     * @return bool
     */
    private function setExtrafield($societeId, $name, $value)
    {
        $this->ensureExtrafieldsRow($societeId);

        $sql = "UPDATE ".MAIN_DB_PREFIX."societe_extrafields";
        $sql .= " SET ".$this->db->escape($name)." = '".$this->db->escape($value)."'";
        $sql .= " WHERE fk_object = ".((int) $societeId);

        $resql = $this->db->query($sql);
        return ($resql !== false);
    }

    /**
     * Process entire CSV file (batch)
     *
     * @return array Stats array
     */
    public function processBatch()
    {
        $filepath = $this->job->filepath;
        $delimiter = $this->job->delimiter_char ?: ',';
        $encoding = $this->job->encoding ?: 'UTF-8';
        $hasHeader = $this->job->has_header;

        if (!file_exists($filepath) || !is_readable($filepath)) {
            $this->errors[] = 'File not found or not readable: '.$filepath;
            $this->job->setStatus('failed');
            return $this->stats;
        }

        // Update job status
        $this->job->setStatus('running');

        $handle = fopen($filepath, 'r');
        if (!$handle) {
            $this->errors[] = 'Cannot open file: '.$filepath;
            $this->job->setStatus('failed');
            return $this->stats;
        }

        $lineNum = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNum++;

            // Skip header
            if ($lineNum === 1 && $hasHeader) {
                continue;
            }

            // Convert encoding
            if (strtoupper($encoding) !== 'UTF-8') {
                $row = array_map(function ($v) use ($encoding) {
                    return mb_convert_encoding($v, 'UTF-8', $encoding);
                }, $row);
            }

            // Remove BOM from first cell
            if ($lineNum <= 2 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }

            try {
                $this->processRow($lineNum, $row);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->job->addLine($lineNum, '', 'error', 0, 'Exception: '.$e->getMessage());
            }
        }

        fclose($handle);

        // Finalize
        $this->stats['end'] = dol_now();
        $this->job->saveStats($this->stats);

        if ($this->stats['errors'] > 0 && $this->stats['created'] === 0 && $this->stats['updated'] === 0) {
            $this->job->setStatus('failed');
        } else {
            $this->job->setStatus('done');
        }

        return $this->stats;
    }

    /**
     * Preview import (dry run on sample rows)
     *
     * @param int $maxRows Max rows to preview
     * @return array Preview results
     */
    public function preview($maxRows = 20)
    {
        $filepath = $this->job->filepath;
        $delimiter = $this->job->delimiter_char ?: ',';
        $encoding = $this->job->encoding ?: 'UTF-8';
        $hasHeader = $this->job->has_header;

        $results = array();

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return $results;
        }

        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return $results;
        }

        $lineNum = 0;
        $processed = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $processed < $maxRows) {
            $lineNum++;

            if ($lineNum === 1 && $hasHeader) {
                continue;
            }

            // Convert encoding
            if (strtoupper($encoding) !== 'UTF-8') {
                $row = array_map(function ($v) use ($encoding) {
                    return mb_convert_encoding($v, 'UTF-8', $encoding);
                }, $row);
            }

            if ($lineNum <= 2 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }

            // Extract n_documento
            $documento = '';
            foreach ($this->mapping as $csvIndex => $dest) {
                if ($dest === 'extra.n_documento') {
                    $documento = isset($row[$csvIndex]) ? $this->normalizeKey($row[$csvIndex]) : '';
                    break;
                }
            }

            $preview = array(
                'line'      => $lineNum,
                'documento' => $documento,
                'action'    => 'unknown',
                'details'   => '',
            );

            if (empty($documento)) {
                $preview['action'] = 'error';
                $preview['details'] = 'Empty n_documento';
            } else {
                $societeId = $this->findSocieteByDocumento($documento);
                if ($societeId === -1) {
                    $preview['action'] = 'error';
                    $preview['details'] = 'Multiple records found';
                } elseif ($societeId !== false && $societeId > 0) {
                    $preview['action'] = 'update';
                    $preview['details'] = 'Existing third party ID: '.$societeId;
                } else {
                    $preview['action'] = 'create';
                    $preview['details'] = 'New record will be created';
                }
            }

            $results[] = $preview;
            $processed++;
        }

        fclose($handle);
        return $results;
    }

    /**
     * Get current stats
     *
     * @return array
     */
    public function getStats()
    {
        return $this->stats;
    }
}
