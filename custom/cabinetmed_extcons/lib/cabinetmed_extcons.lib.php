<?php
/* Copyright (C) 2024 Your Company
 */

/**
 * Prepare array with list of tabs
 *
 * @param   ExtConsultation  $object     Object related to tabs
 * @return  array                        Array of tabs
 */
function extconsultation_prepare_head($object)
{
    global $langs, $conf;

    $langs->load("cabinetmed_extcons@cabinetmed_extcons");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/cabinetmed_extcons/consultation_card.php', 1).'?id='.$object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'extconsultation');

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'extconsultation', 'remove');

    return $head;
}