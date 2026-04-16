<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/importfill_mapper.class.php
 * \ingroup importfill
 * \brief   Handles mapping CSV columns to Dolibarr fields
 */

/**
 * Class ImportFillMapper
 */
class ImportFillMapper
{
    /** @var DoliDB */
    private $db;

    /** @var array Available core fields for societe */
    private $coreFields = array();

    /** @var array Available extrafields */
    private $extraFields = array();

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->loadCoreFields();
        $this->loadExtraFields();
    }

    /**
     * Load ALL available core fields dynamically from llx_societe table structure.
     * Excludes only internal/system columns that should never be imported.
     * Provides friendly labels for well-known fields, auto-generates for the rest.
     */
    private function loadCoreFields()
    {
        $this->coreFields = array();

        // Columns that should NEVER be available for mapping (internal/system)
        // Only exclude the absolute minimum: primary key and entity (multi-company)
        $excludedColumns = array(
            'rowid',            // primary key, auto-generated
            'entity',           // multi-company entity, set by system
            'logo',             // logo filename, needs special file handling
            'logo_squarred',    // square logo, needs special file handling
        );

        // Friendly labels for well-known fields (others get auto-generated label)
        $friendlyLabels = array(
            'nom'                   => 'Company Name',
            'name_alias'            => 'Alias / Trade Name',
            'ref_ext'               => 'External Reference',
            'datec'                 => 'Creation Date (YYYY-MM-DD)',
            'tms'                   => 'Last Modification Timestamp',
            'fk_user_creat'         => 'Created By (User ID)',
            'fk_user_modif'         => 'Modified By (User ID)',
            'import_key'            => 'Import Key',
            'model_pdf'             => 'PDF Model',
            'last_main_doc'         => 'Last Generated Document Path',
            'canvas'                => 'Canvas Type',
            'address'               => 'Address',
            'zip'                   => 'Zip / Postal Code',
            'town'                  => 'City',
            'fk_departement'        => 'State/Department ID',
            'fk_pays'              => 'Country ID (fk_pays)',
            'state_id'              => 'State/Province ID',
            'country_id'            => 'Country ID',
            'phone'                 => 'Phone',
            'fax'                   => 'Fax',
            'email'                 => 'Email',
            'socialnetworks'        => 'Social Networks (JSON)',
            'url'                   => 'Website URL',
            'barcode'               => 'Barcode',
            'fk_barcode_type'       => 'Barcode Type ID',
            'client'                => 'Customer (0=no, 1=customer, 2=prospect, 3=both)',
            'fournisseur'           => 'Supplier (0/1)',
            'code_client'           => 'Customer Code',
            'code_fournisseur'      => 'Supplier Code',
            'code_compta'           => 'Accounting Code (customer)',
            'code_compta_fournisseur' => 'Accounting Code (supplier)',
            'parent'                => 'Parent Company ID',
            'siren'                 => 'SIREN / NIT',
            'siret'                 => 'SIRET',
            'ape'                   => 'APE / Activity Code',
            'idprof1'               => 'Prof ID 1 (SIREN/RUT/NIT)',
            'idprof2'               => 'Prof ID 2 (SIRET)',
            'idprof3'               => 'Prof ID 3 (NAF/APE)',
            'idprof4'               => 'Prof ID 4 (RCS)',
            'idprof5'               => 'Prof ID 5',
            'idprof6'               => 'Prof ID 6',
            'tva_intra'             => 'VAT Number (intra-community)',
            'tva_assuj'             => 'Subject to VAT (0/1)',
            'localtax1_assuj'       => 'Local Tax 1 Subject (0/1)',
            'localtax1_value'       => 'Local Tax 1 Rate',
            'localtax2_assuj'       => 'Local Tax 2 Subject (0/1)',
            'localtax2_value'       => 'Local Tax 2 Rate',
            'capital'               => 'Capital',
            'typent_id'             => 'Entity Type ID',
            'typent_code'           => 'Entity Type Code',
            'effectif'              => 'Number of Employees',
            'effectif_id'           => 'Employees Range ID',
            'forme_juridique_code'  => 'Legal Form Code',
            'fk_typent'             => 'Entity Type ID (fk)',
            'fk_forme_juridique'    => 'Legal Form ID',
            'fk_currency'           => 'Currency Code (e.g. COP, EUR)',
            'prefix_comm'           => 'Commercial Prefix',
            'fk_stcomm'             => 'Commercial Status ID',
            'fk_incoterms'          => 'Incoterms ID',
            'location_incoterms'    => 'Incoterms Location',
            'fk_multicurrency'      => 'Multicurrency ID',
            'multicurrency_code'    => 'Multicurrency Code',
            'default_lang'          => 'Default Language (e.g. es_CO)',
            'note_private'          => 'Private Note',
            'note_public'           => 'Public Note',
            'status'                => 'Status (0=closed, 1=active)',
            'fk_prospectlevel'      => 'Prospect Level',
            'price_level'           => 'Price Level',
            'outstanding_limit'     => 'Outstanding Limit',
            'order_min_amount'      => 'Minimum Order Amount',
            'supplier_order_min_amount' => 'Supplier Min Order Amount',
            'fk_shipping_method'    => 'Shipping Method ID',
            'fk_account'            => 'Default Bank Account ID',
            'buyer_default_payment_type' => 'Default Payment Type',
            'supplier_default_payment_type' => 'Supplier Payment Type',
            'remise_percent'        => 'Default Discount %',
            'remise_supplier_percent' => 'Supplier Discount %',
            'mode_reglement'        => 'Payment Mode ID',
            'cond_reglement'        => 'Payment Terms ID',
            'mode_reglement_supplier' => 'Supplier Payment Mode ID',
            'cond_reglement_supplier' => 'Supplier Payment Terms ID',
            'deposit_percent'       => 'Deposit Percent',
            'transport_mode'        => 'Transport Mode',
            'fk_warehouse'          => 'Default Warehouse ID',
            'webservices_url'       => 'Web Services URL',
            'webservices_key'       => 'Web Services Key',
        );

        // Read actual columns from llx_societe
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."societe";
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $colName = $obj->Field;

                // Skip excluded system columns
                if (in_array($colName, $excludedColumns)) {
                    continue;
                }

                // Determine type from MySQL type
                $mysqlType = strtolower($obj->Type);
                $simpleType = 'varchar';
                if (preg_match('/^(int|smallint|tinyint|mediumint|bigint)/', $mysqlType)) {
                    $simpleType = 'int';
                } elseif (preg_match('/^(double|float|decimal|numeric)/', $mysqlType)) {
                    $simpleType = 'double';
                } elseif (preg_match('/^(text|mediumtext|longtext)/', $mysqlType)) {
                    $simpleType = 'text';
                } elseif (preg_match('/^(date|datetime|timestamp)/', $mysqlType)) {
                    $simpleType = 'date';
                }

                // Determine if required (NOT NULL without DEFAULT and not auto_increment)
                $isRequired = ($obj->Null === 'NO' && $obj->Default === null && stripos($obj->Extra, 'auto_increment') === false);

                // Use friendly label if available, otherwise generate from column name
                if (isset($friendlyLabels[$colName])) {
                    $label = $friendlyLabels[$colName];
                } else {
                    // Auto-generate: fk_some_thing → "Fk Some Thing"
                    $label = ucwords(str_replace('_', ' ', $colName));
                }

                $this->coreFields[$colName] = array(
                    'label'    => $label,
                    'type'     => $simpleType,
                    'required' => $isRequired,
                    'db_type'  => $mysqlType,
                );
            }
            $this->db->free($resql);
        }

        // Fallback: if SHOW COLUMNS failed (shouldn't happen), load minimal set
        if (empty($this->coreFields)) {
            $this->coreFields = array(
                'nom'    => array('label' => 'Company Name', 'type' => 'varchar', 'required' => true, 'db_type' => 'varchar(128)'),
                'phone'  => array('label' => 'Phone',        'type' => 'varchar', 'required' => false, 'db_type' => 'varchar(20)'),
                'email'  => array('label' => 'Email',        'type' => 'varchar', 'required' => false, 'db_type' => 'varchar(128)'),
                'address'=> array('label' => 'Address',      'type' => 'varchar', 'required' => false, 'db_type' => 'varchar(255)'),
                'zip'    => array('label' => 'Zip Code',     'type' => 'varchar', 'required' => false, 'db_type' => 'varchar(25)'),
                'town'   => array('label' => 'City',         'type' => 'varchar', 'required' => false, 'db_type' => 'varchar(50)'),
                'status' => array('label' => 'Status (0/1)', 'type' => 'int',     'required' => false, 'db_type' => 'tinyint'),
            );
        }
    }

    /**
     * Load extrafields for societe using Dolibarr's ExtraFields class
     */
    private function loadExtraFields()
    {
        global $conf;

        $this->extraFields = array();

        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

        $extrafields = new ExtraFields($this->db);
        // fetch_name_optionals_label loads all extrafields for the given element type
        // It handles entity filtering internally
        $extrafields->fetch_name_optionals_label('societe');

        if (!empty($extrafields->attributes['societe']['label'])) {
            foreach ($extrafields->attributes['societe']['label'] as $attrName => $attrLabel) {
                $this->extraFields[$attrName] = array(
                    'label'    => $attrLabel,
                    'type'     => isset($extrafields->attributes['societe']['type'][$attrName]) ? $extrafields->attributes['societe']['type'][$attrName] : 'varchar',
                    'size'     => isset($extrafields->attributes['societe']['size'][$attrName]) ? $extrafields->attributes['societe']['size'][$attrName] : '',
                    'required' => isset($extrafields->attributes['societe']['required'][$attrName]) ? $extrafields->attributes['societe']['required'][$attrName] : 0,
                );
            }
        }
    }

    /**
     * Get all available destination fields
     *
     * @return array Array with 'core', 'extra', and 'lookup' keys
     */
    public function getAvailableFields()
    {
        return array(
            'core'   => $this->coreFields,
            'extra'  => $this->extraFields,
            'lookup' => $this->getLookupFields(),
        );
    }

    /**
     * Get lookup fields (resolve IDs to names from dictionary tables)
     *
     * @return array
     */
    public function getLookupFields()
    {
        return array(
            'town_by_dept_id' => array(
                'label' => 'Ciudad (resolver por ID de departamento)',
                'desc'  => 'El valor es un rowid de c_departements → guarda el nombre del departamento en Ciudad y asigna fk_departement',
            ),
            'town_by_dept_code' => array(
                'label' => 'Ciudad (resolver por código de departamento)',
                'desc'  => 'El valor es un código de departamento (ej. DANE) → resuelve nombre y asigna fk_departement',
            ),
            'town_by_ziptown_id' => array(
                'label' => 'Ciudad (resolver por ID de diccionario zip-ciudad)',
                'desc'  => 'El valor es un rowid de c_ziptown → guarda nombre de ciudad, código postal y departamento',
            ),
            'dept_by_id' => array(
                'label' => 'Departamento (resolver por ID)',
                'desc'  => 'El valor es un rowid de c_departements → valida que existe y asigna fk_departement',
            ),
            'dept_by_code' => array(
                'label' => 'Departamento (resolver por código)',
                'desc'  => 'El valor es un código de departamento → resuelve a rowid y asigna fk_departement',
            ),
        );
    }

    /**
     * Get core fields
     *
     * @return array
     */
    public function getCoreFields()
    {
        return $this->coreFields;
    }

    /**
     * Get extrafields
     *
     * @return array
     */
    public function getExtraFields()
    {
        return $this->extraFields;
    }

    /**
     * Parse CSV headers
     *
     * @param string $filepath   File path
     * @param string $delimiter  Delimiter character
     * @param string $encoding   File encoding
     * @param bool   $hasHeader  Whether file has header row
     * @return array|false Array of headers or false on error
     */
    public function parseCSVHeaders($filepath, $delimiter = ',', $encoding = 'UTF-8', $hasHeader = true)
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return false;
        }

        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return false;
        }

        $line = fgets($handle);
        fclose($handle);

        if (!$line) {
            return false;
        }

        // Convert encoding
        if (strtoupper($encoding) !== 'UTF-8') {
            $line = mb_convert_encoding($line, 'UTF-8', $encoding);
        }

        // Remove BOM
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

        $line = trim($line);
        $headers = str_getcsv($line, $delimiter);

        if (!$hasHeader) {
            // Generate generic column names
            $count = count($headers);
            $headers = array();
            for ($i = 0; $i < $count; $i++) {
                $headers[] = 'Column '.($i + 1);
            }
        }

        return $headers;
    }

    /**
     * Get sample data rows from CSV
     *
     * @param string $filepath   File path
     * @param string $delimiter  Delimiter
     * @param string $encoding   Encoding
     * @param bool   $hasHeader  Has header row
     * @param int    $maxRows    Max rows to return
     * @return array
     */
    public function getSampleData($filepath, $delimiter = ',', $encoding = 'UTF-8', $hasHeader = true, $maxRows = 5)
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return array();
        }

        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return array();
        }

        $rows = array();
        $lineNum = 0;

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false && count($rows) < $maxRows) {
            $lineNum++;

            // Skip header
            if ($lineNum === 1 && $hasHeader) {
                continue;
            }

            // Convert encoding
            if (strtoupper($encoding) !== 'UTF-8') {
                $line = array_map(function ($v) use ($encoding) {
                    return mb_convert_encoding($v, 'UTF-8', $encoding);
                }, $line);
            }

            // Remove BOM from first cell of first data line
            if (count($rows) === 0 && isset($line[0])) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', $line[0]);
            }

            $rows[] = $line;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Validate a mapping configuration
     *
     * @param array $mapping Mapping array (csv_col_index => destination)
     * @return array Array of errors (empty if valid)
     */
    public function validateMapping($mapping)
    {
        $errors = array();

        if (empty($mapping)) {
            $errors[] = 'No columns mapped';
            return $errors;
        }

        // Check n_documento is mapped
        $hasDocumento = false;
        $destinations = array();

        foreach ($mapping as $csvIndex => $dest) {
            if (empty($dest) || $dest === '--') {
                continue;
            }

            // Check for duplicate destinations
            if (in_array($dest, $destinations)) {
                $errors[] = 'Duplicate mapping for: '.$dest;
            }
            $destinations[] = $dest;

            // Check if n_documento is mapped
            if ($dest === 'extra.n_documento') {
                $hasDocumento = true;
            }

            // Validate destination exists
            if (strpos($dest, 'extra.') === 0) {
                $extraName = substr($dest, 6);
                if ($extraName !== 'n_documento' && !isset($this->extraFields[$extraName])) {
                    $errors[] = 'Unknown extrafield: '.$extraName;
                }
            } elseif (strpos($dest, 'core.') === 0) {
                $coreName = substr($dest, 5);
                if (!isset($this->coreFields[$coreName])) {
                    $errors[] = 'Unknown core field: '.$coreName;
                }
            } elseif (strpos($dest, 'lookup.') === 0) {
                $lookupName = substr($dest, 7);
                $lookupFields = $this->getLookupFields();
                if (!isset($lookupFields[$lookupName])) {
                    $errors[] = 'Unknown lookup field: '.$lookupName;
                }
            }
        }

        if (!$hasDocumento) {
            $errors[] = 'Mapping for n_documento is mandatory';
        }

        return $errors;
    }

    /**
     * Count total rows in CSV (excluding header)
     *
     * @param string $filepath  File path
     * @param bool   $hasHeader Has header row
     * @return int
     */
    public function countCSVRows($filepath, $hasHeader = true)
    {
        if (!file_exists($filepath)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($filepath, 'r');
        if ($handle) {
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }

        if ($hasHeader && $count > 0) {
            $count--;
        }

        return $count;
    }
}
