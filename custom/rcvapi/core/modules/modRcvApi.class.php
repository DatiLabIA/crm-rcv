<?php
/* Copyright (C) 2025 DatiLab
 * Módulo API REST para pacientes y consultas
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modRcvApi extends DolibarrModules
{
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		$this->numero = 502200;
		$this->rights_class = 'rcvapi';
		$this->family = "crm";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "API REST para pacientes y consultas médicas";
		$this->descriptionlong = "Expone endpoints REST para crear, modificar y consultar pacientes y consultas desde servicios externos";
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';
		$this->editor_name = 'DatiLab';
		$this->editor_url = 'https://www.datilab.com';

		$this->depends = array('modSociete', 'modCabinetMed');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(21, 0);
		$this->langfiles = array("rcvapi@rcvapi");

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Permisos
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Read patients via API';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'patient';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Create/modify patients via API';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'patient';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Read consultations via API';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'consultation';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Create/modify consultations via API';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'consultation';
		$this->rights[$r][5] = 'write';

		$this->menu = array();
		$this->module_parts = array();
	}

	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
