<?php
/* Copyright (C) 2024 DatiLab
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
        $this->version = '1.0.2';
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://datilab.com';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->description = "Módulo para mostrar/ocultar campos condicionales en fichas de pacientes";
        $this->descriptionlong = "Este módulo permite configurar campos extrafields que se muestran u ocultan dependiendo del valor de un campo checkbox.";
        $this->picto = 'generic';
        $this->need_dolibarr_version = array(14, 0);
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("medtriggers@medtriggers");

        $this->const = array(
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

        $this->module_parts = array(
            'js' => array('/medtriggers/js/medtriggers.js'),
            'triggers' => 1,
            'hooks' => array(
                'data' => array('thirdpartycard', 'patientcard', 'consultationcard'),
                'entity' => '0',
            ),
        );

        $this->rights = array();
        $this->menu = array();
    }

    public function init($options = '')
    {
        $result = $this->_load_tables('/medtriggers/sql/');
        if ($result < 0) return -1;
        return $this->_init(array(), $options);
    }

    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
