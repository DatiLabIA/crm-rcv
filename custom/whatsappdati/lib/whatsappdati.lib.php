<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       lib/whatsappdati.lib.php
 * \ingroup    whatsappdati
 * \brief      Library files with common functions for WhatsAppDati
 */

/**
 * Prepare admin pages header
 *
 * @return array Array of tabs
 */
function whatsappdatiAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("whatsappdati@whatsappdati");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/custom/whatsappdati/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/whatsappdati/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'whatsappdati');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'whatsappdati', 'remove');

	return $head;
}
