<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceMedTriggersTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "other";
        $this->description = "Limpia campos condicionales cuando el trigger está desactivado";
        $this->version = '1.0.2';
        $this->picto = 'generic';
    }

    public function getName() { return $this->name; }
    public function getDesc() { return $this->description; }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        $validActions = array('COMPANY_MODIFY', 'COMPANY_CREATE');
        if (!in_array($action, $validActions)) return 0;

        $fieldConfig = $this->parseFieldConfig($conf->global->MEDTRIGGERS_FIELD_CONFIG);
        if (empty($fieldConfig)) return 0;

        foreach ($fieldConfig as $triggerField => $dependentFields) {
            $this->processConditionalFields($object, $triggerField, $dependentFields);
        }
        return 1;
    }

    private function parseFieldConfig($configString)
    {
        $result = array();
        if (empty($configString)) return $result;

        foreach (explode(';', $configString) as $config) {
            $config = trim($config);
            if (empty($config)) continue;
            
            $parts = explode(':', $config);
            if (count($parts) !== 2) continue;
            
            $trigger = trim($parts[0]);
            $dependents = array_map('trim', explode(',', $parts[1]));
            
            if (!empty($trigger) && !empty($dependents)) {
                $result[$trigger] = $dependents;
            }
        }
        return $result;
    }

    private function processConditionalFields($object, $triggerField, $dependentFields)
    {
        if (!isset($object->array_options)) return;

        $triggerKey = 'options_' . $triggerField;
        
        if (empty($object->array_options[$triggerKey])) {
            $needsUpdate = false;
            
            foreach ($dependentFields as $field) {
                $fieldKey = 'options_' . $field;
                if (!empty($object->array_options[$fieldKey])) {
                    $object->array_options[$fieldKey] = null;
                    $needsUpdate = true;
                    dol_syslog("MedTriggers: Limpiando '$field'", LOG_DEBUG);
                }
            }
            
            if ($needsUpdate) $object->insertExtraFields();
        }
    }
}
