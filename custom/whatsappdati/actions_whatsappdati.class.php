<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       actions_whatsappdati.class.php
 * \ingroup    whatsappdati
 * \brief      Hook actions class for WhatsApp integration
 */

// Load the actual hook implementation
require_once __DIR__ . '/core/hooks/WhatsAppDatiHook.class.php';

/**
 * Class ActionsWhatsappdati
 * 
 * Dolibarr hook class — delegates to WhatsAppDatiHook for implementation.
 * This file MUST exist in the module root directory and MUST be named 
 * exactly "actions_whatsappdati.class.php" for Dolibarr to discover it.
 */
class ActionsWhatsappdati extends WhatsAppDatiHook
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
	}
}
