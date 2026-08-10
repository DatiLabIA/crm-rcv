<?php
/* Copyright (C) 2024-2026 DatiLab - GPL v3 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceMedTriggersTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "other";
        $this->description = "Limpia campos condicionales y valida selects dependientes";
        $this->version = '2.2.0';
        $this->picto = 'generic';
    }

    public function getName() { return $this->name; }
    public function getDesc() { return $this->description; }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        $validActions = array('COMPANY_MODIFY', 'COMPANY_CREATE');
        if (!in_array($action, $validActions)) return 0;

        // 1) Toggle show/hide: limpiar campos dependientes si checkbox desactivado
        $fieldConfig = $this->parseFieldConfig($conf->global->MEDTRIGGERS_FIELD_CONFIG);
        if (!empty($fieldConfig)) {
            foreach ($fieldConfig as $triggerField => $dependentFields) {
                $this->processConditionalFields($object, $triggerField, $dependentFields);
            }
        }

        // 2) Selects dependientes: limpiar concentracion si medicamento está vacío
        $this->validateDependentSelects($object);

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

    /**
     * Valida que si no hay medicamento seleccionado, la concentración se limpie.
     * También valida que la concentración corresponda al medicamento seleccionado.
     */
    private function validateDependentSelects($object)
    {
        if (!isset($object->array_options)) return;

        $medicamentoKey = 'options_medicamento';
        $concentracionKey = 'options_concentracion';

        $medicamentoVal = isset($object->array_options[$medicamentoKey]) ? $object->array_options[$medicamentoKey] : '';
        $concentracionVal = isset($object->array_options[$concentracionKey]) ? $object->array_options[$concentracionKey] : '';

        // Si no hay medicamento, limpiar concentración
        if (empty($medicamentoVal) && !empty($concentracionVal)) {
            $object->array_options[$concentracionKey] = null;
            $object->insertExtraFields();
            dol_syslog("MedTriggers: Concentración limpiada (sin medicamento)", LOG_DEBUG);
            return;
        }

        // Si hay ambos valores, validar que la concentración pertenezca al medicamento
        if (!empty($medicamentoVal) && !empty($concentracionVal)) {
            $sql = "SELECT d.rowid FROM ".MAIN_DB_PREFIX."gestion_medicamento_det d";
            $sql .= " INNER JOIN ".MAIN_DB_PREFIX."gestion_medicamento m ON m.rowid = d.fk_medicamento";
            $sql .= " WHERE d.fk_medicamento = ".((int) $medicamentoVal);
            $sql .= " AND d.rowid = ".((int) $concentracionVal);
            $sql .= " AND m.estado = 1";

            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) == 0) {
                // La concentración no pertenece al medicamento: limpiar
                $object->array_options[$concentracionKey] = null;
                $object->insertExtraFields();
                dol_syslog("MedTriggers: Concentración inválida para medicamento ".$medicamentoVal.", limpiada", LOG_WARNING);
            }
            if ($resql) $this->db->free($resql);
        }
    }
}
