<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modMedTriggers extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 600100;
        $this->rights_class = 'medtriggers';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->family = "other";
        $this->module_position = '90';
        $this->version = '2.2.0';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://datilab.com';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->description = "Campos condicionales y selects dependientes para fichas de pacientes";
        $this->descriptionlong = "Módulo que permite: (1) mostrar/ocultar campos extrafields según checkbox, (2) selects dependientes con carga AJAX (ej: medicamento → concentración). Requiere módulo Gestion activo.";
        $this->picto = 'generic';
        $this->need_dolibarr_version = array(14, 0);
        $this->depends = array('modGestion');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("medtriggers@medtriggers");

        // Constantes del módulo
        $this->const = array(
            // Config toggle show/hide (v1)
            array(
                'MEDTRIGGERS_FIELD_CONFIG',
                'chaine',
                'guardian:fecha_entregado_guardian,fecha_cambio_guardian',
                'Configuración de campos condicionales (trigger:dependiente1,dependiente2;trigger2:dep1,dep2)',
                0,
                'current',
                1
            ),
        );

        // Partes del módulo
        $this->module_parts = array(
            'js' => array('/medtriggers/js/medtriggers.js?v='.$this->version),
            'triggers' => 1,
            'hooks' => array(
                'data' => array(
                    'thirdpartycard',
                    'patientcard',
                    'consultationcard',
                    'thirdpartycontact',
                    'societecard',
                    'cabinetmedcard',
                    'cabinetmedconsultation',
                    'globalcard',
                ),
                'entity' => '0',
            ),
        );

        $this->rights = array();
        $this->menu = array();
    }

    public function init($options = '')
    {
        // No se crean tablas propias: usa llx_gestion_medicamento y llx_gestion_medicamento_det del módulo Gestion
        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
