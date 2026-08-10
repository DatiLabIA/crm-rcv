<?php
/* Copyright (C) 2026 DatiLab <info@datilab.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file class/exportlabels.class.php
 * \brief Class to manage export label resolution
 */

class ExportLabels
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error message
     */
    public $error = '';

    /**
     * @var array Error messages
     */
    public $errors = array();

    /**
     * @var array Cache for resolved labels
     */
    private $cache = array();

    /**
     * @var array Loaded mapping configs (keyed by extrafield name)
     */
    private $mappings = null;

    /**
     * @var array Cached extrafield definitions (type, param) keyed by attrname
     */
    private $extrafield_defs = null;

    /**
     * @var string Table prefix
     */
    private $table_prefix;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->table_prefix = MAIN_DB_PREFIX;
    }

    // =========================================================================
    // DATABASE DISCOVERY
    // =========================================================================

    /**
     * Get all tables in the database (filtered by prefix)
     *
     * @return array List of table names
     */
    public function getTables()
    {
        $tables = array();
        $sql = "SHOW TABLES";
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_row($resql)) {
                // Only include tables with the configured prefix
                if (strpos($obj[0], $this->table_prefix) === 0) {
                    $tables[] = $obj[0];
                }
            }
            $this->db->free($resql);
        }

        sort($tables);
        return $tables;
    }

    /**
     * Get all columns from a given table
     *
     * @param string $table Table name (must start with prefix)
     * @return array List of field names
     */
    public function getFields($table)
    {
        $fields = array();

        // Security: only allow tables with the proper prefix
        if (!$this->isValidTableName($table)) {
            $this->error = 'Invalid table name';
            return $fields;
        }

        $sql = "SHOW COLUMNS FROM `".addslashes($table)."`";
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $fields[] = $obj->Field;
            }
            $this->db->free($resql);
        }

        return $fields;
    }

    /**
     * Get extrafields defined for societe (thirdparties)
     * Uses Dolibarr's native ExtraFields class for compatibility across versions
     *
     * @return array Array of extrafield objects (attrname, label, type, param)
     */
    public function getThirdpartyExtrafields()
    {
        // Return cached if already loaded
        if ($this->extrafield_defs !== null) {
            return $this->extrafield_defs;
        }

        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

        $extrafields = new ExtraFields($this->db);
        $extrafields->fetch_name_optionals_label('societe');

        $this->extrafield_defs = array();

        if (!empty($extrafields->attributes['societe']['label'])) {
            foreach ($extrafields->attributes['societe']['label'] as $attrname => $label) {
                $obj = new stdClass();
                $obj->attrname = $attrname;
                $obj->label = $label;
                $obj->type = isset($extrafields->attributes['societe']['type'][$attrname]) ? $extrafields->attributes['societe']['type'][$attrname] : '';
                $obj->param = isset($extrafields->attributes['societe']['param'][$attrname]) ? $extrafields->attributes['societe']['param'][$attrname] : '';
                $obj->pos = isset($extrafields->attributes['societe']['pos'][$attrname]) ? $extrafields->attributes['societe']['pos'][$attrname] : 0;
                $this->extrafield_defs[$attrname] = $obj;
            }
        }

        return $this->extrafield_defs;
    }

    // =========================================================================
    // MAPPING CONFIGURATION (CRUD)
    // =========================================================================

    /**
     * Get mapping config for a specific extrafield
     *
     * @param string $extrafield_name Extrafield attribute name
     * @return object|null Mapping config or null
     */
    public function getMappingConfig($extrafield_name)
    {
        // Lazy-load all mappings on first call
        if ($this->mappings === null) {
            $this->loadAllMappings();
        }

        return isset($this->mappings[$extrafield_name]) ? $this->mappings[$extrafield_name] : null;
    }

    /**
     * Load all active mappings into memory
     *
     * @return void
     */
    private function loadAllMappings()
    {
        $this->mappings = array();

        $sql = "SELECT rowid, extrafield_name, table_name, key_field, label_field, active";
        $sql .= " FROM ".$this->table_prefix."thirdpartyexportlabels_map";
        $sql .= " WHERE active = 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $this->mappings[$obj->extrafield_name] = $obj;
            }
            $this->db->free($resql);
        }
    }

    /**
     * Save or update a mapping configuration
     *
     * @param string $extrafield_name Extrafield name
     * @param string $table_name Source table
     * @param string $key_field ID field in source table
     * @param string $label_field Label field in source table
     * @return int >0 if OK, <0 if KO
     */
    public function saveMapping($extrafield_name, $table_name, $key_field, $label_field)
    {
        // Validate inputs
        if (!$this->isValidTableName($table_name)) {
            $this->error = 'Invalid table name: '.$table_name;
            return -1;
        }
        if (!$this->isValidFieldName($key_field) || !$this->isValidFieldName($label_field)) {
            $this->error = 'Invalid field name';
            return -2;
        }

        // Check if mapping exists
        $existing = $this->getMappingByName($extrafield_name);

        if ($existing) {
            // Update
            $sql = "UPDATE ".$this->table_prefix."thirdpartyexportlabels_map SET";
            $sql .= " table_name = '".$this->db->escape($table_name)."'";
            $sql .= ", key_field = '".$this->db->escape($key_field)."'";
            $sql .= ", label_field = '".$this->db->escape($label_field)."'";
            $sql .= ", active = 1";
            $sql .= " WHERE rowid = ".((int) $existing->rowid);
        } else {
            // Insert
            $sql = "INSERT INTO ".$this->table_prefix."thirdpartyexportlabels_map";
            $sql .= " (extrafield_name, table_name, key_field, label_field, active)";
            $sql .= " VALUES (";
            $sql .= "'".$this->db->escape($extrafield_name)."'";
            $sql .= ", '".$this->db->escape($table_name)."'";
            $sql .= ", '".$this->db->escape($key_field)."'";
            $sql .= ", '".$this->db->escape($label_field)."'";
            $sql .= ", 1)";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -3;
        }

        // Reset cached mappings
        $this->mappings = null;
        $this->cache = array();

        return 1;
    }

    /**
     * Delete a mapping
     *
     * @param string $extrafield_name Extrafield name
     * @return int >0 if OK, <0 if KO
     */
    public function deleteMapping($extrafield_name)
    {
        $sql = "DELETE FROM ".$this->table_prefix."thirdpartyexportlabels_map";
        $sql .= " WHERE extrafield_name = '".$this->db->escape($extrafield_name)."'";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->mappings = null;
        return 1;
    }

    /**
     * Get all mappings (active and inactive)
     *
     * @return array Array of mapping objects
     */
    public function getAllMappings()
    {
        $mappings = array();

        $sql = "SELECT rowid, extrafield_name, table_name, key_field, label_field, active";
        $sql .= " FROM ".$this->table_prefix."thirdpartyexportlabels_map";
        $sql .= " ORDER BY extrafield_name ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $mappings[$obj->extrafield_name] = $obj;
            }
            $this->db->free($resql);
        }

        return $mappings;
    }

    /**
     * Get a single mapping by extrafield name (regardless of active status)
     *
     * @param string $extrafield_name
     * @return object|null
     */
    private function getMappingByName($extrafield_name)
    {
        $sql = "SELECT rowid, extrafield_name, table_name, key_field, label_field, active";
        $sql .= " FROM ".$this->table_prefix."thirdpartyexportlabels_map";
        $sql .= " WHERE extrafield_name = '".$this->db->escape($extrafield_name)."'";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            return $this->db->fetch_object($resql);
        }
        return null;
    }

    // =========================================================================
    // LABEL RESOLUTION
    // =========================================================================

    /**
     * Resolve a value to its label using param options or mapping config
     *
     * Dolibarr extrafield types and resolution:
     * - 'select', 'radio'       -> resolve from inline param options (key=>label)
     * - 'checkbox'              -> multi-select from inline param options (comma-separated keys)
     * - 'sellist'               -> single-select from referenced DB table in param
     * - 'chkbxlst'              -> multi-select from referenced DB table in param
     * - 'boolean'               -> 0/1 -> Sí/No
     * - 'date', 'datetime'      -> format nicely (if no manual mapping)
     * - 'varchar','int','link'  -> manual mapping from admin config, or raw value
     *
     * @param string $extrafield_name Extrafield attribute name
     * @param mixed  $value           Raw value (ID or comma-separated IDs)
     * @return string Resolved label(s) or original value
     */
    public function getLabelFromExtrafield($extrafield_name, $value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Load extrafield definitions if not yet loaded
        $this->getThirdpartyExtrafields();
        $ef_def = isset($this->extrafield_defs[$extrafield_name]) ? $this->extrafield_defs[$extrafield_name] : null;

        if ($ef_def) {
            // --- Boolean (type 'boolean'): 0/1 -> Sí/No ---
            if ($ef_def->type == 'boolean') {
                return ($value == '1' || $value === true) ? 'Sí' : 'No';
            }

            // --- Select / Radio: single-select with inline param options ---
            if (in_array($ef_def->type, array('select', 'radio'))) {
                $options = $this->parseParamOptions($ef_def->param);
                if (!empty($options)) {
                    return $this->resolveFromOptions($options, $value);
                }
            }

            // --- Checkbox: multi-select with inline param options ---
            if ($ef_def->type == 'checkbox') {
                $options = $this->parseParamOptions($ef_def->param);
                if (!empty($options)) {
                    // checkbox stores as comma-separated keys
                    return $this->resolveFromOptions($options, $value);
                }
            }

            // --- Sellist / Chkbxlst: resolve from referenced DB table in param ---
            if (in_array($ef_def->type, array('sellist', 'chkbxlst'))) {
                $resolved = $this->resolveTableReference($ef_def->param, $value);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            // --- Date / Datetime: format nicely ---
            if (in_array($ef_def->type, array('date', 'datetime'))) {
                return $this->formatDateValue($value, $ef_def->type);
            }
        }

        // --- Manual mapping from admin config (varchar, int, link, or any other type) ---
        $config = $this->getMappingConfig($extrafield_name);

        if ($config) {
            // Handle multiselect (comma-separated values)
            if (strpos((string) $value, ',') !== false) {
                return $this->resolveMultiselect($config, $value);
            }
            return $this->resolveAuto($config, $value);
        }

        // --- No resolution available: return raw value ---
        return $value;
    }

    /**
     * Format a date/datetime value for export
     *
     * Dolibarr stores dates as Unix timestamps (int) or 'YYYY-MM-DD' strings
     *
     * @param mixed  $value Date value (timestamp or string)
     * @param string $type  'date' or 'datetime'
     * @return string Formatted date
     */
    private function formatDateValue($value, $type = 'date')
    {
        if (empty($value) || $value == '0') {
            return '';
        }

        // If it's a Unix timestamp (numeric)
        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($type == 'datetime') {
                return date('d/m/Y H:i', $ts);
            }
            return date('d/m/Y', $ts);
        }

        // If it's already a date string (YYYY-MM-DD or similar)
        $ts = strtotime($value);
        if ($ts !== false) {
            if ($type == 'datetime') {
                return date('d/m/Y H:i', $ts);
            }
            return date('d/m/Y', $ts);
        }

        return $value;
    }

    /**
     * Parse the 'param' field from an extrafield definition to extract options
     *
     * Dolibarr stores param in multiple formats depending on version and type:
     * - Already decoded array (by ExtraFields class): array('options' => array(...))
     * - JSON string: {"options":{"1":"Cedula","2":"Tarjeta de identidad","3":"Otro"}}
     * - PHP serialized: a:1:{s:7:"options";a:1:{s:37:"gestion_diagnostico:description:rowid";N;}}
     *
     * @param mixed $param Raw param value (string or array)
     * @return array Associative array key=>label, empty if not parseable
     */
    private function parseParamOptions($param)
    {
        if (empty($param)) {
            return array();
        }

        // If ExtraFields already decoded it to array
        if (is_array($param)) {
            if (isset($param['options']) && is_array($param['options'])) {
                return $param['options'];
            }
            return $param;
        }

        if (!is_string($param)) {
            return array();
        }

        // Try JSON first
        $decoded = json_decode($param, true);
        if (is_array($decoded) && isset($decoded['options'])) {
            return $decoded['options'];
        }

        // Try PHP serialized format: a:1:{s:7:"options";a:1:{...}}
        if (strpos($param, 'a:') === 0 || strpos($param, 's:') === 0) {
            $unserialized = @unserialize($param);
            if (is_array($unserialized)) {
                if (isset($unserialized['options']) && is_array($unserialized['options'])) {
                    return $unserialized['options'];
                }
                return $unserialized;
            }
        }

        return array();
    }

    /**
     * Resolve a value (or comma-separated values) from inline options
     *
     * @param array  $options Key=>Label map
     * @param string $value   Raw value (single or comma-separated)
     * @return string Resolved label(s)
     */
    private function resolveFromOptions($options, $value)
    {
        $value = (string) $value;

        // Handle comma-separated (multiselect / checkbox)
        if (strpos($value, ',') !== false) {
            $values = explode(',', $value);
            $labels = array();
            foreach ($values as $v) {
                $v = trim($v);
                if ($v !== '' && isset($options[$v])) {
                    $labels[] = $options[$v];
                } elseif ($v !== '') {
                    $labels[] = $v; // fallback raw
                }
            }
            return implode(', ', $labels);
        }

        // Single value
        if (isset($options[$value])) {
            return $options[$value];
        }

        return $value;
    }

    /**
     * Resolve a value from a sellist/chkbxlst extrafield (references another DB table)
     *
     * Dolibarr param format (as key in options array):
     * "llx_table:label_field:rowid::active=1"  (sellist)
     * "gestion_diagnostico:description:rowid"   (chkbxlst, may omit prefix)
     * Pattern: table:label_field:key_field[:filter_field:filter_condition]
     *
     * chkbxlst values are stored as: ,1,3,5, (with leading/trailing commas)
     *
     * @param mixed  $param Raw param
     * @param string $value ID or comma-separated IDs to resolve
     * @return string|null Resolved label(s) or null if cannot resolve
     */
    private function resolveTableReference($param, $value)
    {
        $options = $this->parseParamOptions($param);
        if (empty($options)) {
            return null;
        }

        // The key in options contains the table definition
        // Format: "table:label_field:rowid::filter" => null
        $definition = array_keys($options);
        $def_str = $definition[0];

        if (empty($def_str)) {
            return null;
        }

        // Parse the definition string
        $parts = explode(':', $def_str);
        if (count($parts) < 2) {
            return null;
        }

        $table_name = trim($parts[0]);
        $label_field = trim($parts[1]);
        $key_field = !empty($parts[2]) ? trim($parts[2]) : 'rowid';

        // Auto-add table prefix if not present
        if (strpos($table_name, $this->table_prefix) !== 0) {
            $table_name = $this->table_prefix.$table_name;
        }

        // Validate
        if (!$this->isValidTableName($table_name) || !$this->isValidFieldName($label_field) || !$this->isValidFieldName($key_field)) {
            return null;
        }

        // Clean chkbxlst format: ,1,3,5, -> 1,3,5
        $value = trim($value, ', ');

        // Handle multiple values (comma-separated)
        if (strpos($value, ',') !== false) {
            $values = explode(',', $value);
            $labels = array();
            foreach ($values as $v) {
                $v = trim($v);
                if ($v !== '' && $v !== '0') {
                    $labels[] = $this->resolveSingleFromTable($table_name, $key_field, $label_field, $v);
                }
            }
            return implode(', ', $labels);
        }

        if ($value === '' || $value === '0') {
            return $value;
        }

        return $this->resolveSingleFromTable($table_name, $key_field, $label_field, $value);
    }

    /**
     * Resolve a single ID value from a database table
     *
     * @param string $table       Full table name (with prefix)
     * @param string $key_field   ID column
     * @param string $label_field Label column
     * @param string $value       ID value
     * @return string Resolved label or raw value
     */
    private function resolveSingleFromTable($table, $key_field, $label_field, $value)
    {
        $cache_key = $table.'_'.$label_field.'_'.$value;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $sql = "SELECT `".$this->db->escape($label_field)."`";
        $sql .= " FROM `".$this->db->escape($table)."`";
        $sql .= " WHERE `".$this->db->escape($key_field)."` = ".((int) $value);

        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $label = $obj->{$label_field};
            $this->cache[$cache_key] = $label;
            $this->db->free($resql);
            return $label;
        }

        $this->cache[$cache_key] = $value;
        return $value;
    }

    /**
     * Resolve a single value to its label
     *
     * @param object $config Mapping configuration
     * @param mixed  $value  Single ID value
     * @return string Resolved label or original value
     */
    private function resolveAuto($config, $value)
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return $value;
        }

        // Check cache first
        $cache_key = $config->table_name.'_'.$config->label_field.'_'.$value;
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        // Validate table and field names before querying
        if (!$this->isValidTableName($config->table_name) || !$this->isValidFieldName($config->label_field) || !$this->isValidFieldName($config->key_field)) {
            return $value;
        }

        $sql = "SELECT `".$this->db->escape($config->label_field)."`";
        $sql .= " FROM `".$this->db->escape($config->table_name)."`";
        $sql .= " WHERE `".$this->db->escape($config->key_field)."` = ".((int) $value);

        $resql = $this->db->query($sql);

        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $label = $obj->{$config->label_field};
            $this->cache[$cache_key] = $label;
            $this->db->free($resql);
            return $label;
        }

        // Cache miss too (avoid repeated queries)
        $this->cache[$cache_key] = $value;
        return $value;
    }

    /**
     * Resolve comma-separated values (multiselect)
     *
     * @param object $config Mapping configuration
     * @param string $value  Comma-separated IDs
     * @return string Comma-separated labels
     */
    private function resolveMultiselect($config, $value)
    {
        $values = explode(',', $value);
        $labels = array();

        foreach ($values as $v) {
            $v = trim($v);
            if ($v !== '') {
                $labels[] = $this->resolveAuto($config, $v);
            }
        }

        return implode(', ', $labels);
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    /**
     * Export third parties with resolved extrafield labels
     *
     * @param array  $selected_extrafields  Array of extrafield names to include (empty = all)
     * @param string $filter_sql            Additional SQL WHERE clause (optional)
     * @return array Array with 'headers' and 'rows'
     */
    public function exportThirdparties($selected_extrafields = array(), $filter_sql = '')
    {
        // Get extrafields definition
        $all_extrafields = $this->getThirdpartyExtrafields();

        // If specific extrafields requested, filter
        if (!empty($selected_extrafields)) {
            $extrafields = array_intersect_key($all_extrafields, array_flip($selected_extrafields));
        } else {
            $extrafields = $all_extrafields;
        }

        // Build headers
        $headers = array('ID', 'Nom', 'Code client', 'Code fournisseur', 'Adresse', 'CP', 'Ville', 'Pays', 'Téléphone', 'Email', 'Statut');
        foreach ($extrafields as $attrname => $ef) {
            $headers[] = $ef->label ?: $attrname;
        }

        // Build query
        $sql = "SELECT s.rowid, s.nom, s.code_client, s.code_fournisseur,";
        $sql .= " s.address, s.zip, s.town, co.label as country_label,";
        $sql .= " s.phone, s.email, s.status";

        // Add extrafield columns
        if (!empty($extrafields)) {
            foreach ($extrafields as $attrname => $ef) {
                $sql .= ", se.".$this->db->escape($attrname);
            }
        }

        $sql .= " FROM ".$this->table_prefix."societe as s";

        // Join extrafields table
        if (!empty($extrafields)) {
            $sql .= " LEFT JOIN ".$this->table_prefix."societe_extrafields as se ON se.fk_object = s.rowid";
        }

        // Join country
        $sql .= " LEFT JOIN ".$this->table_prefix."c_country as co ON co.rowid = s.fk_pays";

        // Apply filter
        $sql .= " WHERE s.entity IN (".getEntity('societe').")";
        if (!empty($filter_sql)) {
            $sql .= " AND (".$filter_sql.")";
        }

        $sql .= " ORDER BY s.nom ASC";

        $rows = array();
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $row = array(
                    $obj->rowid,
                    $obj->nom,
                    $obj->code_client,
                    $obj->code_fournisseur,
                    $obj->address,
                    $obj->zip,
                    $obj->town,
                    $obj->country_label,
                    $obj->phone,
                    $obj->email,
                    $obj->status ? 'Actif' : 'Inactif',
                );

                // Add extrafield values (resolved)
                foreach ($extrafields as $attrname => $ef) {
                    $raw_value = isset($obj->$attrname) ? $obj->$attrname : '';
                    $row[] = $this->getLabelFromExtrafield($attrname, $raw_value);
                }

                $rows[] = $row;
            }
            $this->db->free($resql);
        } else {
            $this->error = $this->db->lasterror();
        }

        return array(
            'headers' => $headers,
            'rows' => $rows,
            'count' => count($rows),
        );
    }

    /**
     * Write export data to CSV file
     *
     * @param array  $data     Export data (from exportThirdparties)
     * @param string $filepath Output file path (if empty, outputs to browser)
     * @return int Number of rows written or -1 on error
     */
    public function writeCSV($data, $filepath = '')
    {
        if (empty($filepath)) {
            // Output to browser
            $fp = fopen('php://output', 'w');
        } else {
            $fp = fopen($filepath, 'w');
        }

        if (!$fp) {
            $this->error = 'Cannot open file for writing';
            return -1;
        }

        // BOM for Excel UTF-8 compatibility
        fwrite($fp, "\xEF\xBB\xBF");

        // Write headers
        fputcsv($fp, $data['headers'], ';');

        // Write rows
        foreach ($data['rows'] as $row) {
            fputcsv($fp, $row, ';');
        }

        fclose($fp);

        return $data['count'];
    }

    // =========================================================================
    // VALIDATION / SECURITY
    // =========================================================================

    /**
     * Validate a table name (must start with prefix, alphanumeric + underscore only)
     *
     * @param string $table Table name
     * @return bool
     */
    private function isValidTableName($table)
    {
        if (empty($table)) return false;
        if (strpos($table, $this->table_prefix) !== 0) return false;
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $table);
    }

    /**
     * Validate a field name (alphanumeric + underscore only)
     *
     * @param string $field Field name
     * @return bool
     */
    private function isValidFieldName($field)
    {
        if (empty($field)) return false;
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $field);
    }
}
