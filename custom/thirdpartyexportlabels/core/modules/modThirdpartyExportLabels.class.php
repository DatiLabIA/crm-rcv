<?php
/* Copyright (C) 2026 DatiLab <info@datilab.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup thirdpartyexportlabels Module ThirdpartyExportLabels
 * \brief Export third parties with extrafield label resolution
 */

/**
 * \file core/modules/modThirdpartyExportLabels.class.php
 * \brief Module descriptor for ThirdpartyExportLabels
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Class modThirdpartyExportLabels
 * Module descriptor
 */
class modThirdpartyExportLabels extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Module identification
        $this->numero = 500200; // Unique module number
        $this->rights_class = 'thirdpartyexportlabels';
        $this->family = "other";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Export third parties with extrafields replacing IDs with labels";
        $this->descriptionlong = "Extends Dolibarr export to export third parties (societe) including extrafields, replacing stored IDs with human-readable labels via automatic database discovery.";
        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://datilab.com';
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'thirdpartyexportlabels@thirdpartyexportlabels';

        // Module parts
        $this->dirs = array();
        $this->config_page_url = array("setup.php@thirdpartyexportlabels");

        // Dependencies
        $this->hidden = false;
        $this->depends = array('modSociete');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("thirdpartyexportlabels@thirdpartyexportlabels");

        // Constants
        $this->const = array();

        // Admin pages
        if (!isset($conf->thirdpartyexportlabels) || !isset($conf->thirdpartyexportlabels->enabled)) {
            $conf->thirdpartyexportlabels = new stdClass();
            $conf->thirdpartyexportlabels->enabled = 0;
        }

        // Permissions
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + 1;
        $this->rights[$r][1] = 'Read export label mappings';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + 2;
        $this->rights[$r][1] = 'Configure export label mappings';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'setup';
        $r++;

        $this->rights[$r][0] = $this->numero + 3;
        $this->rights[$r][1] = 'Execute exports';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'export';
        $r++;

        // Menus
        $this->menu = array();
        $r = 0;

        // Top menu
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=tools',
            'type' => 'left',
            'titre' => 'ThirdpartyExportLabels',
            'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'tools',
            'leftmenu' => 'thirdpartyexportlabels',
            'url' => '/thirdpartyexportlabels/scripts/export_thirdparty.php',
            'langs' => 'thirdpartyexportlabels@thirdpartyexportlabels',
            'position' => 100 + $r,
            'enabled' => '$conf->thirdpartyexportlabels->enabled',
            'perms' => '$user->rights->thirdpartyexportlabels->read',
            'target' => '',
            'user' => 0,
        );
        $r++;

        // Admin/Setup menu
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=thirdpartyexportlabels',
            'type' => 'left',
            'titre' => 'Setup',
            'mainmenu' => 'tools',
            'leftmenu' => 'thirdpartyexportlabels_setup',
            'url' => '/thirdpartyexportlabels/admin/setup.php',
            'langs' => 'thirdpartyexportlabels@thirdpartyexportlabels',
            'position' => 100 + $r,
            'enabled' => '$conf->thirdpartyexportlabels->enabled',
            'perms' => '$user->rights->thirdpartyexportlabels->setup',
            'target' => '',
            'user' => 0,
        );
        $r++;
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options when enabling module
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/thirdpartyexportlabels/sql/');
        return $this->_init(array(), $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
