<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup    importfill    Module ImportFill
 * \brief       Upsert importer for Third Parties using extrafield n_documento
 * \file        core/modules/modImportFill.class.php
 * \ingroup     importfill
 * \brief       Description and activation file for module ImportFill
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Class modImportFill
 * Description and activation class for module ImportFill
 */
class modImportFill extends DolibarrModules
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

        // Module ID (use a unique number > 100000)
        $this->numero = 500100;

        // Module family
        $this->family = "other";
        $this->module_position = '90';

        // Module name
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        $this->description = "Upsert CSV importer for Third Parties using extrafield n_documento";
        $this->descriptionlong = "Smart CSV import that matches records by n_documento extrafield. Creates new Third Parties or fills empty fields on existing ones without overwriting data.";

        $this->editor_name = 'DatiLab';
        $this->editor_url = 'https://datilab.com';

        $this->version = '1.0.0';

        $this->const_name = 'MAIN_MODULE_IMPORTFILL';
        $this->picto = 'importfill@importfill';

        // Module parts
        $this->dirs = array(
            '/importfill/temp',
        );

        $this->config_page_url = array();

        // Dependencies
        $this->hidden = false;
        $this->depends = array('modSociete');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("importfill@importfill");

        // Constants
        $this->const = array();

        // Tabs
        $this->tabs = array();

        // Dictionaries
        $this->dictionaries = array();

        // Boxes
        $this->boxes = array();

        // Cronjobs
        $this->cronjobs = array();

        // Permissions
        $this->rights = array();
        $this->rights_class = 'importfill';
        $r = 0;

        $this->rights[$r][0] = $this->numero + 1;
        $this->rights[$r][1] = 'View import jobs';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + 2;
        $this->rights[$r][1] = 'Run imports';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $r++;

        $this->rights[$r][0] = $this->numero + 3;
        $this->rights[$r][1] = 'Administer ImportFill module';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'admin';
        $r++;

        // Menus
        $this->menu = array();
        $r = 0;

        // Top menu
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=tools',
            'type'     => 'left',
            'titre'    => 'ImportFill',
            'prefix'   => img_picto('', 'importfill@importfill', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'tools',
            'leftmenu' => 'importfill',
            'url'      => '/importfill/index.php',
            'langs'    => 'importfill@importfill',
            'position' => 100,
            'enabled'  => '$conf->importfill->enabled',
            'perms'    => '$user->hasRight("importfill", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=tools,fk_leftmenu=importfill',
            'type'     => 'left',
            'titre'    => 'NewImport',
            'mainmenu' => 'tools',
            'leftmenu' => 'importfill_new',
            'url'      => '/importfill/new.php',
            'langs'    => 'importfill@importfill',
            'position' => 101,
            'enabled'  => '$conf->importfill->enabled',
            'perms'    => '$user->hasRight("importfill", "write")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;

        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=tools,fk_leftmenu=importfill',
            'type'     => 'left',
            'titre'    => 'ImportHistory',
            'mainmenu' => 'tools',
            'leftmenu' => 'importfill_history',
            'url'      => '/importfill/index.php',
            'langs'    => 'importfill@importfill',
            'position' => 102,
            'enabled'  => '$conf->importfill->enabled',
            'perms'    => '$user->hasRight("importfill", "read")',
            'target'   => '',
            'user'     => 0,
        );
        $r++;
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/importfill/sql/');

        // Create documents directory
        $sql = array();

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
