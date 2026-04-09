<?php
/* Copyright (C) 2025 DatiLab <info@datilab.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/importfill.lib.php
 * \ingroup importfill
 * \brief   Library for ImportFill module
 */

/**
 * Prepare admin pages header
 *
 * @return array Tabs
 */
function importfillAdminPrepareHead()
{
    global $langs, $conf;

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/importfill/admin/setup.php', 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'importfill@importfill');

    return $head;
}

/**
 * Prepare job view tabs
 *
 * @param ImportFillJob $job Job object
 * @return array Tabs
 */
function importfillJobPrepareHead($job)
{
    global $langs;

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/importfill/log.php', 1).'?id='.$job->id;
    $head[$h][1] = $langs->trans("ImportFillJobLog");
    $head[$h][2] = 'log';
    $h++;

    return $head;
}

/**
 * Get file upload directory for a job
 *
 * @param int $jobId Job ID
 * @return string Directory path
 */
function importfillGetUploadDir($jobId = 0)
{
    global $conf;

    $dir = $conf->importfill->dir_output.'/jobs';
    if ($jobId > 0) {
        $dir .= '/job_'.$jobId;
    }

    return $dir;
}

/**
 * Get status badge HTML
 *
 * @param string $status Status code
 * @return string HTML badge
 */
function importfillStatusBadge($status)
{
    $badges = array(
        'draft'   => '<span class="badge badge-status0">Draft</span>',
        'mapped'  => '<span class="badge badge-status1">Mapped</span>',
        'running' => '<span class="badge badge-status4">Running</span>',
        'done'    => '<span class="badge badge-status6">Done</span>',
        'failed'  => '<span class="badge badge-status8">Failed</span>',
    );

    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-status0">'.$status.'</span>';
}
