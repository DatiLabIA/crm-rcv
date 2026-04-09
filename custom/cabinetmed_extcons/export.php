<?php
/* Copyright (C) 2024 DatiLab
 * Export de consultas extendidas a XLSX
 * Filtros: tipo de consulta, persona asignada, rango de fechas
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/cabinetmed_extcons/class/extconsultation.class.php');

$langs->loadLangs(array("companies", "other", "agenda", "cabinetmed@cabinetmed", "cabinetmed_extcons@cabinetmed_extcons"));

// Parameters
$action = GETPOST('action', 'aZ09');

// Array filters — multiselect (POST fields use [] notation)
$filter_types = array();
$_raw_types = isset($_POST['filter_type']) ? (array) $_POST['filter_type'] : array();
foreach ($_raw_types as $_t) {
    $_t = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $_t));
    if ($_t !== '') $filter_types[] = $_t;
}

$filter_users = array();
$_raw_users = isset($_POST['filter_user']) ? (array) $_POST['filter_user'] : array();
foreach ($_raw_users as $_u) {
    $_u = (int) $_u;
    if ($_u > 0) $filter_users[] = $_u;
}

$filter_eps = array();
$_raw_eps = isset($_POST['filter_eps']) ? (array) $_POST['filter_eps'] : array();
foreach ($_raw_eps as $_e) {
    $_e = (int) $_e;
    if ($_e > 0) $filter_eps[] = $_e;
}

// Date filters
$filter_date_start = dol_mktime(0, 0, 0,
    GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear'));
$filter_date_end = dol_mktime(23, 59, 59,
    GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear'));

// Security
$permtoread = !empty($user->rights->cabinetmed->read) || !empty($user->rights->cabinetmed_extcons->read);
if (!$permtoread) accessforbidden();

$form = new Form($db);

// Status labels
$status_labels = array(0 => 'En progreso', 1 => 'Completada', 2 => 'Cancelada');

/*
 * ============================================================
 * EXPORT ACTION — outputs CSV directly, without HTML wrapper
 * ============================================================
 */
if ($action === 'export' && !empty($filter_types)) {

    // --- 1. Resolve type info (multiple) ---
    $type_labels_map = array(); // code => label
    $type_ids = array();
    {
        $types_escaped_sql = implode(',', array_map(function($t) use ($db) { return "'".$db->escape($t)."'"; }, $filter_types));
        $sql_type  = "SELECT t.rowid, t.code, t.label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types t";
        $sql_type .= " WHERE t.code IN (".$types_escaped_sql.") AND t.entity = ".$conf->entity;
        $res_type  = $db->query($sql_type);
        while ($res_type && $trow = $db->fetch_object($res_type)) {
            $type_labels_map[$trow->code] = $trow->label;
            $type_ids[] = (int) $trow->rowid;
        }
    }
    $type_label = implode(', ', array_values($type_labels_map));

    // --- 2. Load field definitions for all selected types (deduplicated by field_name) ---
    $field_defs = array();
    $field_names_seen = array();
    if (!empty($type_ids)) {
        $type_ids_str = implode(',', $type_ids);
        $sql_fd  = "SELECT field_name, field_label, field_type, field_options FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
        $sql_fd .= " WHERE fk_type IN (".$type_ids_str.") AND active = 1 ORDER BY fk_type ASC, position ASC";
        $res_fd  = $db->query($sql_fd);
        while ($res_fd && $f = $db->fetch_object($res_fd)) {
            if (!isset($field_names_seen[$f->field_name])) {
                $field_defs[] = $f;
                $field_names_seen[$f->field_name] = true;
            }
        }
    }

    // Pre-build options cache for select/radio/multiselect/boolean/checkbox fields
    $field_opts_cache = array();
    foreach ($field_defs as $fdef) {
        if (in_array($fdef->field_type, array('select', 'radio', 'multiselect', 'boolean', 'checkbox'))) {
            $field_opts_cache[$fdef->field_name] = ExtConsultation::resolveFieldOptions($fdef->field_options, $db);
        }
    }

    // --- 3. Main data query ---
    $sql  = "SELECT c.rowid, c.date_start, c.date_end, c.tipo_atencion, c.status,";
    $sql .= " c.motivo, c.diagnostico, c.procedimiento, c.insumos_enf, c.rx_num, c.medicamentos,";
    $sql .= " c.cumplimiento, c.razon_inc, c.mes_actual, c.proximo_mes, c.dificultad,";
    $sql .= " c.custom_data, c.note_public,";
    $sql .= " s.nom AS patient_name, ef.n_documento AS patient_cedula,";
    $sql .= " GROUP_CONCAT(DISTINCT TRIM(CONCAT(IFNULL(u.firstname,''), ' ', IFNULL(u.lastname,'')))";
    $sql .= "   ORDER BY u.lastname SEPARATOR ', ') AS assigned_users";
    $sql .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons c";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields ef ON ef.fk_object = c.fk_soc";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_users eu ON eu.fk_extcons = c.rowid";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = eu.fk_user";
    $sql .= " WHERE c.entity = ".$conf->entity;
    if (!empty($filter_types)) {
        $types_in_sql = implode(',', array_map(function($t) use ($db) { return "'".$db->escape($t)."'"; }, $filter_types));
        $sql .= " AND c.tipo_atencion IN (".$types_in_sql.")";
    }
    if (!empty($filter_users)) {
        $users_in_sql = implode(',', $filter_users);
        $sql .= " AND c.rowid IN (SELECT fk_extcons FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users";
        $sql .= " WHERE fk_user IN (".$users_in_sql."))";
    }
    if (!empty($filter_eps)) {
        $eps_in_sql = implode(',', $filter_eps);
        $sql .= " AND ef.eps IN (".$eps_in_sql.")";
    }
    if ($filter_date_start > 0) {
        $sql .= " AND c.date_start >= '".$db->idate($filter_date_start)."'";
    }
    if ($filter_date_end > 0) {
        $sql .= " AND c.date_start <= '".$db->idate($filter_date_end)."'";
    }
    $sql .= " GROUP BY c.rowid ORDER BY c.date_start DESC";

    $res_cons = $db->query($sql);
    if (!$res_cons) {
        // If query fails, fall back to HTML error
        setEventMessages($db->lasterror(), null, 'errors');
        $back_params = 'action=';
        foreach ($filter_types as $ft_v) {
            $back_params .= '&filter_type[]='.urlencode($ft_v);
        }
        header("Location: ".$_SERVER['PHP_SELF'].'?'.$back_params);
        exit;
    }

    // --- 4. Build XLSX ---
    require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
    require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
    require_once PHPEXCELNEW_PATH.'Spreadsheet.php';

    $safe_type = preg_replace('/[^a-zA-Z0-9_-]/', '_', implode('-', $filter_types));
    $filename  = 'consultas_'.$safe_type.'_'.dol_print_date(dol_now(), 'dayhour').'.xlsx';

    // Helper: clean cell value (strip HTML, decode entities)
    $cleanCell = function($value) {
        if (is_array($value)) $value = implode(', ', $value);
        $value = strip_tags((string) $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($value);
    };

    // --- 4a. Build all data in memory first (safer than row-by-row with styling) ---
    $headers = array(
        'Nombre del paciente',
        'Número de documento',
        'Tipo de consulta',
        'Fecha inicio',
        'Fecha fin',
        'Estado',
        'Encargado(s)',
    );
    foreach ($field_defs as $fdef) {
        $headers[] = $fdef->field_label;
    }
    $headers[] = 'Observaciones generales';

    $data = array($headers);

    while ($row = $db->fetch_object($res_cons)) {
        $custom_data = array();
        if (!empty($row->custom_data)) {
            $decoded = json_decode($row->custom_data, true);
            if (is_array($decoded)) {
                $custom_data = isset($decoded['custom_fields']) ? $decoded['custom_fields'] : $decoded;
            }
        }

        $line = array(
            $cleanCell($row->patient_name),
            $cleanCell($row->patient_cedula),
            isset($type_labels_map[$row->tipo_atencion]) ? $type_labels_map[$row->tipo_atencion] : $row->tipo_atencion,
            $row->date_start ? dol_print_date($db->jdate($row->date_start), 'dayhour') : '',
            $row->date_end   ? dol_print_date($db->jdate($row->date_end),   'dayhour') : '',
            isset($status_labels[(int)$row->status]) ? $status_labels[(int)$row->status] : '',
            $cleanCell($row->assigned_users),
        );

        foreach ($field_defs as $fdef) {
            $val = '';
            if (property_exists('ExtConsultation', $fdef->field_name)) {
                $val = isset($row->{$fdef->field_name}) ? $row->{$fdef->field_name} : '';
            } else {
                $val = isset($custom_data[$fdef->field_name]) ? $custom_data[$fdef->field_name] : '';
            }

            $ft = $fdef->field_type;
            if ($ft === 'boolean' || $ft === 'checkbox') {
                $val = ($val && $val !== '0' && $val !== '') ? 'Sí' : 'No';
            } elseif ($ft === 'select' || $ft === 'radio') {
                $opts = isset($field_opts_cache[$fdef->field_name]) ? $field_opts_cache[$fdef->field_name] : array();
                $val = isset($opts[(string)$val]) ? $opts[(string)$val] : (string)$val;
            } elseif ($ft === 'multiselect') {
                $opts = isset($field_opts_cache[$fdef->field_name]) ? $field_opts_cache[$fdef->field_name] : array();
                $values = is_array($val) ? $val : (($val !== '') ? explode(',', (string)$val) : array());
                $labels = array();
                foreach ($values as $v) {
                    $v = trim($v);
                    if ($v === '') continue;
                    $labels[] = isset($opts[$v]) ? $opts[$v] : $v;
                }
                $val = implode(', ', $labels);
            } else {
                $val = $cleanCell($val);
            }
            $line[] = trim((string)$val);
        }

        $line[] = $cleanCell(strip_tags((string)$row->note_public));
        $data[] = $line;
    }

    // --- 4b. Create spreadsheet from array ---
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Consultas');
    $sheet->fromArray($data, null, 'A1');

    $numCols  = count($headers);
    $numRows  = count($data);
    $lastCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($numCols);

    // Style header row
    $sheet->getStyle('A1:'.$lastCol.'1')->applyFromArray(array(
        'font'      => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill'      => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => '1A56A0')),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true),
        'borders'   => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('rgb' => 'AAAAAA'))),
    ));
    $sheet->getRowDimension(1)->setRowHeight(20);

    // Alternate row shading on data rows
    for ($r = 2; $r <= $numRows; $r++) {
        if ($r % 2 === 0) {
            $sheet->getStyle('A'.$r.':'.$lastCol.$r)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('EEF4FF');
        }
    }

    // Auto-width: use column letter
    for ($c = 1; $c <= $numCols; $c++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    $sheet->setAutoFilter('A1:'.$lastCol.'1');
    $sheet->freezePane('A2');

    // --- 4c. Stream to browser ---
    // Discard any output Dolibarr may have buffered (prevents file corruption)
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $writer = new XlsxWriter($spreadsheet);
    $writer->save('php://output');
    exit;
}

/*
 * ============================================================
 * HTML PAGE — filter form + preview table
 * ============================================================
 */

llxHeader('', 'Exportar consultas', '');

// --- Load consultation types for dropdown ---
$types_list = array('' => '-- Seleccione tipo --');
$sql_types  = "SELECT code, label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types";
$sql_types .= " WHERE entity = ".$conf->entity." AND active = 1 ORDER BY label ASC";
$res_types  = $db->query($sql_types);
while ($res_types && $t = $db->fetch_object($res_types)) {
    $types_list[$t->code] = $t->label;
}

// --- Load users for dropdown ---
$users_list = array(0 => '-- Todos los encargados --');
$sql_users  = "SELECT u.rowid, TRIM(CONCAT(IFNULL(u.firstname,''), ' ', IFNULL(u.lastname,''))) AS fullname";
$sql_users .= " FROM ".MAIN_DB_PREFIX."user u";
$sql_users .= " WHERE u.entity IN (0, ".$conf->entity.") AND u.statut = 1 AND u.employee = 1";
$sql_users .= " ORDER BY u.lastname ASC, u.firstname ASC";
$res_users  = $db->query($sql_users);
while ($res_users && $u = $db->fetch_object($res_users)) {
    $users_list[(int)$u->rowid] = trim($u->fullname);
}

// --- Load EPS for dropdown ---
$eps_items = array();
$sql_eps   = "SELECT rowid, descripcion FROM ".MAIN_DB_PREFIX."gestion_eps";
$sql_eps  .= " WHERE entity = ".$conf->entity." ORDER BY descripcion ASC";
$res_eps   = $db->query($sql_eps);
while ($res_eps && $ep = $db->fetch_object($res_eps)) {
    $eps_items[(int)$ep->rowid] = $ep->descripcion;
}

// --- Preview query (if form submitted without action=export) ---
$preview_rows   = array();
$preview_fields = array();
$preview_type_label = '';
$preview_count  = 0;
$PREVIEW_LIMIT  = 20;

if (!empty($filter_types)) {
    // Resolve types (multiple)
    $preview_type_labels_map = array();
    $preview_type_ids = array();
    {
        $prev_types_esc = implode(',', array_map(function($t) use ($db) { return "'".$db->escape($t)."'"; }, $filter_types));
        $sql_pt  = "SELECT t.rowid, t.code, t.label FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_types t";
        $sql_pt .= " WHERE t.code IN (".$prev_types_esc.") AND t.entity = ".$conf->entity;
        $res_pt  = $db->query($sql_pt);
        while ($res_pt && $trow_pt = $db->fetch_object($res_pt)) {
            $preview_type_labels_map[$trow_pt->code] = $trow_pt->label;
            $preview_type_ids[] = (int) $trow_pt->rowid;
        }
    }
    $preview_type_label = implode(', ', array_values($preview_type_labels_map));

    // Field definitions for all selected types (deduplicated)
    $preview_field_names_seen = array();
    if (!empty($preview_type_ids)) {
        $preview_type_ids_str = implode(',', $preview_type_ids);
        $sql_pf  = "SELECT field_name, field_label, field_type, field_options FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_fields";
        $sql_pf .= " WHERE fk_type IN (".$preview_type_ids_str.") AND active = 1 ORDER BY fk_type ASC, position ASC";
        $res_pf  = $db->query($sql_pf);
        while ($res_pf && $pf = $db->fetch_object($res_pf)) {
            if (!isset($preview_field_names_seen[$pf->field_name])) {
                $preview_fields[] = $pf;
                $preview_field_names_seen[$pf->field_name] = true;
            }
        }
    }

    // Pre-build options cache for preview
    $preview_opts_cache = array();
    foreach ($preview_fields as $pf_tmp) {
        if (in_array($pf_tmp->field_type, array('select', 'radio', 'multiselect', 'boolean', 'checkbox'))) {
            $preview_opts_cache[$pf_tmp->field_name] = ExtConsultation::resolveFieldOptions($pf_tmp->field_options, $db);
        }
    }

    // Count total
    $sql_cnt  = "SELECT COUNT(DISTINCT c.rowid) AS cnt FROM ".MAIN_DB_PREFIX."cabinetmed_extcons c";
    $sql_cnt .= " LEFT JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_users eu ON eu.fk_extcons = c.rowid";
    $sql_cnt .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields ef ON ef.fk_object = c.fk_soc";
    $sql_cnt .= " WHERE c.entity = ".$conf->entity;
    if (!empty($filter_types)) {
        $cnt_types_in = implode(',', array_map(function($t) use ($db) { return "'".$db->escape($t)."'"; }, $filter_types));
        $sql_cnt .= " AND c.tipo_atencion IN (".$cnt_types_in.")";
    }
    if (!empty($filter_users)) {
        $cnt_users_in = implode(',', $filter_users);
        $sql_cnt .= " AND c.rowid IN (SELECT fk_extcons FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users WHERE fk_user IN (".$cnt_users_in."))";
    }
    if (!empty($filter_eps)) {
        $eps_cnt_in = implode(',', $filter_eps);
        $sql_cnt .= " AND ef.eps IN (".$eps_cnt_in.")";
    }
    if ($filter_date_start > 0) $sql_cnt .= " AND c.date_start >= '".$db->idate($filter_date_start)."'";
    if ($filter_date_end   > 0) $sql_cnt .= " AND c.date_start <= '".$db->idate($filter_date_end)."'";
    $res_cnt = $db->query($sql_cnt);
    if ($res_cnt) {
        $row_cnt = $db->fetch_object($res_cnt);
        $preview_count = (int) $row_cnt->cnt;
    }

    // Preview rows (limited)
    $sql_prev  = "SELECT c.rowid, c.date_start, c.date_end, c.tipo_atencion, c.status, c.custom_data,";
    $sql_prev .= " c.motivo, c.diagnostico, c.procedimiento, c.insumos_enf, c.rx_num, c.medicamentos,";
    $sql_prev .= " c.cumplimiento, c.razon_inc, c.mes_actual, c.proximo_mes, c.dificultad, c.note_public,";
    $sql_prev .= " s.nom AS patient_name, ef.n_documento AS patient_cedula,";
    $sql_prev .= " GROUP_CONCAT(DISTINCT TRIM(CONCAT(IFNULL(u.firstname,''), ' ', IFNULL(u.lastname,'')))";
    $sql_prev .= "   ORDER BY u.lastname SEPARATOR ', ') AS assigned_users";
    $sql_prev .= " FROM ".MAIN_DB_PREFIX."cabinetmed_extcons c";
    $sql_prev .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = c.fk_soc";
    $sql_prev .= " LEFT JOIN ".MAIN_DB_PREFIX."societe_extrafields ef ON ef.fk_object = c.fk_soc";
    $sql_prev .= " LEFT JOIN ".MAIN_DB_PREFIX."cabinetmed_extcons_users eu ON eu.fk_extcons = c.rowid";
    $sql_prev .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = eu.fk_user";
    $sql_prev .= " WHERE c.entity = ".$conf->entity;
    if (!empty($filter_types)) {
        $prev_types_in = implode(',', array_map(function($t) use ($db) { return "'".$db->escape($t)."'"; }, $filter_types));
        $sql_prev .= " AND c.tipo_atencion IN (".$prev_types_in.")";
    }
    if (!empty($filter_users)) {
        $prev_users_in = implode(',', $filter_users);
        $sql_prev .= " AND c.rowid IN (SELECT fk_extcons FROM ".MAIN_DB_PREFIX."cabinetmed_extcons_users WHERE fk_user IN (".$prev_users_in."))";
    }
    if (!empty($filter_eps)) {
        $eps_prev_in = implode(',', $filter_eps);
        $sql_prev .= " AND ef.eps IN (".$eps_prev_in.")";
    }
    if ($filter_date_start > 0) $sql_prev .= " AND c.date_start >= '".$db->idate($filter_date_start)."'";
    if ($filter_date_end   > 0) $sql_prev .= " AND c.date_start <= '".$db->idate($filter_date_end)."'";
    $sql_prev .= " GROUP BY c.rowid ORDER BY c.date_start DESC LIMIT ".$PREVIEW_LIMIT;

    $res_prev = $db->query($sql_prev);
    while ($res_prev && $prow = $db->fetch_object($res_prev)) {
        $preview_rows[] = $prow;
    }
}

// Breadcrumb
print_fiche_titre('Exportar consultas a Excel', '', 'file-export');

// --- CSS + JS para dropdowns checkboxes ---
print '
<style>
.cm-multiselect-wrap { position:relative; display:inline-block; min-width:260px; }
.cm-multiselect-trigger {
  display:flex; align-items:center; justify-content:space-between;
  padding:6px 10px; border:1px solid #aaa; border-radius:4px;
  background:#fff; cursor:pointer; min-height:34px; user-select:none;
  font-size:.95em; gap:6px;
}
.cm-multiselect-trigger:hover { border-color:#555; }
.cm-multiselect-trigger .cm-ms-label { flex:1; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cm-multiselect-trigger .cm-ms-arrow { color:#888; font-size:.8em; flex-shrink:0; }
.cm-multiselect-trigger .cm-ms-badges { display:flex; flex-wrap:nowrap; gap:3px; flex:1; overflow:hidden; }
.cm-ms-badge {
  background:#1A56A0; color:#fff; border-radius:10px;
  padding:1px 7px; font-size:.78em; white-space:nowrap;
  display:inline-flex; align-items:center; gap:3px;
}
.cm-ms-badge .cm-ms-remove { cursor:pointer; opacity:.75; }
.cm-ms-badge .cm-ms-remove:hover { opacity:1; }
.cm-ms-more { background:#888; }
.cm-multiselect-dropdown {
  display:none; position:absolute; top:calc(100% + 3px); left:0;
  min-width:100%; max-width:380px;
  background:#fff; border:1px solid #aaa; border-radius:4px;
  box-shadow:0 4px 14px rgba(0,0,0,.15); z-index:9999;
  flex-direction:column;
}
.cm-multiselect-dropdown.open { display:flex; }
.cm-ms-search-wrap { padding:7px 8px; border-bottom:1px solid #e8e8e8; }
.cm-ms-search {
  width:100%; box-sizing:border-box;
  padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:.9em;
}
.cm-ms-actions { display:flex; gap:6px; padding:4px 8px 5px; border-bottom:1px solid #efefef; }
.cm-ms-actions a { font-size:.82em; color:#1A56A0; cursor:pointer; text-decoration:underline; }
.cm-ms-list { max-height:220px; overflow-y:auto; padding:4px 0; }
.cm-ms-item {
  display:flex; align-items:center; gap:8px;
  padding:5px 10px; cursor:pointer; font-size:.93em;
}
.cm-ms-item:hover { background:#f0f5ff; }
.cm-ms-item input[type=checkbox] { margin:0; cursor:pointer; accent-color:#1A56A0; }
.cm-ms-empty { padding:10px; color:#888; font-style:italic; font-size:.9em; text-align:center; }
.cm-ms-footer { padding:6px 8px; border-top:1px solid #efefef; text-align:right; }
.cm-ms-footer button {
  background:#1A56A0; color:#fff; border:none; border-radius:3px;
  padding:4px 14px; cursor:pointer; font-size:.9em;
}
.cm-ms-footer button:hover { background:#154d8a; }
</style>
<script>
(function(){
  function initMultiselect(wrapperId) {
    var wrap     = document.getElementById(wrapperId);
    if (!wrap) return;
    var trigger  = wrap.querySelector(".cm-multiselect-trigger");
    var dropdown = wrap.querySelector(".cm-multiselect-dropdown");
    var search   = wrap.querySelector(".cm-ms-search");
    var list     = wrap.querySelector(".cm-ms-list");
    var items    = list.querySelectorAll(".cm-ms-item");

    // Toggle dropdown
    trigger.addEventListener("click", function(e) {
      var isOpen = dropdown.classList.contains("open");
      closeAll();
      if (!isOpen) {
        dropdown.classList.add("open");
        if (search) { search.value = ""; filterItems(list, items, ""); search.focus(); }
      }
    });

    // Search
    if (search) {
      search.addEventListener("input", function() {
        filterItems(list, items, this.value.toLowerCase());
      });
      search.addEventListener("click", function(e) { e.stopPropagation(); });
    }

    // Checkbox change
    items.forEach(function(item) {
      var cb = item.querySelector("input[type=checkbox]");
      item.addEventListener("click", function(e) {
        if (e.target !== cb) cb.checked = !cb.checked;
        updateTrigger(wrap);
      });
    });

    // Select all / None
    var btnAll  = wrap.querySelector(".cm-ms-all");
    var btnNone = wrap.querySelector(".cm-ms-none");
    if (btnAll)  btnAll.addEventListener("click",  function(e){ e.preventDefault(); setAll(items, true);  updateTrigger(wrap); });
    if (btnNone) btnNone.addEventListener("click", function(e){ e.preventDefault(); setAll(items, false); updateTrigger(wrap); });

    // Close on outside click
    document.addEventListener("click", function(e) {
      if (!wrap.contains(e.target)) dropdown.classList.remove("open");
    });

    updateTrigger(wrap);
  }

  function closeAll() {
    document.querySelectorAll(".cm-multiselect-dropdown.open").forEach(function(d){ d.classList.remove("open"); });
  }

  function filterItems(list, items, q) {
    var shown = 0;
    items.forEach(function(item) {
      var text = item.dataset.label ? item.dataset.label.toLowerCase() : "";
      var match = !q || text.indexOf(q) !== -1;
      item.style.display = match ? "" : "none";
      if (match) shown++;
    });
    var empty = list.querySelector(".cm-ms-empty");
    if (empty) empty.style.display = shown === 0 ? "" : "none";
  }

  function setAll(items, checked) {
    items.forEach(function(item) {
      if (item.style.display !== "none") {
        var cb = item.querySelector("input[type=checkbox]");
        if (cb) cb.checked = checked;
      }
    });
  }

  function updateTrigger(wrap) {
    var labelEl = wrap.querySelector(".cm-ms-label");
    var badges  = wrap.querySelector(".cm-ms-badges");
    if (!labelEl && !badges) return;

    var checked = [];
    wrap.querySelectorAll(".cm-ms-item input[type=checkbox]:checked").forEach(function(cb) {
      checked.push({ value: cb.value, label: cb.dataset.label || cb.value });
    });

    if (badges) {
      badges.innerHTML = "";
      var MAX_SHOW = 3;
      checked.slice(0, MAX_SHOW).forEach(function(c) {
        var b = document.createElement("span");
        b.className = "cm-ms-badge";
        b.innerHTML = escHtml(c.label) + " <span class=\'cm-ms-remove\' data-val=\'" + escHtml(c.value) + "\'>&#x2715;</span>";
        b.querySelector(".cm-ms-remove").addEventListener("click", function(e) {
          e.stopPropagation();
          var val = this.dataset.val;
          wrap.querySelectorAll(".cm-ms-item input[type=checkbox]").forEach(function(cb) {
            if (cb.value === val) cb.checked = false;
          });
          updateTrigger(wrap);
        });
        badges.appendChild(b);
      });
      if (checked.length > MAX_SHOW) {
        var more = document.createElement("span");
        more.className = "cm-ms-badge cm-ms-more";
        more.textContent = "+" + (checked.length - MAX_SHOW) + " más";
        badges.appendChild(more);
      }
      if (checked.length === 0) {
        var placeholder = wrap.dataset.placeholder || "Seleccionar...";
        badges.innerHTML = "<span style=\'color:#999;font-size:.93em;\'>"+escHtml(placeholder)+"</span>";
      }
    } else if (labelEl) {
      if (checked.length === 0) {
        labelEl.textContent = wrap.dataset.placeholder || "Seleccionar...";
        labelEl.style.color = "#999";
      } else {
        labelEl.textContent = checked.map(function(c){ return c.label; }).join(", ");
        labelEl.style.color = "#333";
      }
    }
  }

  function escHtml(s) {
    return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#39;");
  }

  // Sync hidden selects before form submit
  function syncBeforeSubmit(formId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener("submit", function() {
      form.querySelectorAll(".cm-ms-hidden-select").forEach(function(sel) {
        var name = sel.dataset.name;
        // Remove any existing options
        while (sel.options.length) sel.remove(0);
        // Add checked items as selected options
        var wrapId = sel.dataset.wrap;
        var wrap = document.getElementById(wrapId);
        if (!wrap) return;
        wrap.querySelectorAll(".cm-ms-item input[type=checkbox]:checked").forEach(function(cb) {
          var opt = document.createElement("option");
          opt.value = cb.value;
          opt.selected = true;
          sel.appendChild(opt);
        });
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function() {
    initMultiselect("cm-ms-types");
    initMultiselect("cm-ms-users");
    initMultiselect("cm-ms-eps");
    syncBeforeSubmit("export-filter-form");
  });
})();
</script>
';

// Helper to render a multiselect dropdown widget
// $wrapperId: unique DOM id, $name: POST field name, $items: [value=>label], $selected: array of selected values, $placeholder: string
function renderMultiselect($wrapperId, $name, $items, $selected, $placeholder) {
    $out = '';
    $out .= '<div class="cm-multiselect-wrap" id="'.dol_escape_htmltag($wrapperId).'" data-placeholder="'.dol_escape_htmltag($placeholder).'">';
    // Hidden native select that gets populated before submit
    $out .= '<select name="'.dol_escape_htmltag($name).'" multiple class="cm-ms-hidden-select" data-name="'.dol_escape_htmltag($name).'" data-wrap="'.dol_escape_htmltag($wrapperId).'" style="display:none;"></select>';
    // Visible trigger
    $out .= '<div class="cm-multiselect-trigger">';
    $out .= '<span class="cm-ms-badges"></span>';
    $out .= '<span class="cm-ms-arrow">&#9660;</span>';
    $out .= '</div>';
    // Dropdown panel
    $out .= '<div class="cm-multiselect-dropdown">';
    $out .= '<div class="cm-ms-search-wrap"><input type="text" class="cm-ms-search" placeholder="Buscar..."></div>';
    $out .= '<div class="cm-ms-actions"><a class="cm-ms-all">Seleccionar todos</a> &middot; <a class="cm-ms-none">Ninguno</a></div>';
    $out .= '<div class="cm-ms-list">';
    foreach ($items as $val => $label) {
        $checked = in_array((string)$val, array_map('strval', $selected)) ? ' checked' : '';
        $out .= '<div class="cm-ms-item" data-label="'.dol_escape_htmltag($label).'">';
        $out .= '<input type="checkbox" value="'.dol_escape_htmltag($val).'" data-label="'.dol_escape_htmltag($label).'"'.$checked.'>';
        $out .= '<span>'.dol_escape_htmltag($label).'</span>';
        $out .= '</div>';
    }
    $out .= '<div class="cm-ms-empty" style="display:none;">Sin resultados</div>';
    $out .= '</div>'; // cm-ms-list
    $out .= '</div>'; // cm-ms-dropdown
    $out .= '</div>'; // cm-ms-wrap
    return $out;
}

/*
 * --- Filter form ---
 */
// Build types list without empty entry
$types_items = array();
foreach ($types_list as $tcode => $tlabel) {
    if ($tcode === '') continue;
    $types_items[$tcode] = $tlabel;
}
// Build users list without "todos" entry
$users_items = array();
foreach ($users_list as $uid => $uname) {
    if ($uid === 0) continue;
    $users_items[$uid] = $uname;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="export-filter-form">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<table class="border centpercent">';

// Row 1: Tipo de consulta + Persona asignada
print '<tr>';
print '<td class="titlefield fieldrequired" style="vertical-align:middle;"><label>Tipo de consulta</label></td>';
print '<td style="min-width:280px;">';
print renderMultiselect('cm-ms-types', 'filter_type[]', $types_items, $filter_types, 'Seleccionar tipo(s)...');
print '</td>';
print '<td class="titlefield" style="vertical-align:middle;"><label>Persona asignada</label></td>';
print '<td style="min-width:240px;">';
print renderMultiselect('cm-ms-users', 'filter_user[]', $users_items, $filter_users, 'Seleccionar persona(s)...');
print '</td>';
print '</tr>';

// Row 3: EPS
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle;"><label>EPS</label></td>';
print '<td style="min-width:280px;">';
print renderMultiselect('cm-ms-eps', 'filter_eps[]', $eps_items, $filter_eps, 'Seleccionar EPS...');
print '</td>';
print '<td colspan="2"></td>';
print '</tr>';

// Row 2: Date range
print '<tr>';
print '<td class="titlefield"><label>Fecha desde</label></td>';
print '<td>';
print $form->selectDate($filter_date_start > 0 ? $filter_date_start : -1, 'date_start', 0, 0, 1, '', 1, 1);
print '</td>';
print '<td class="titlefield"><label>Fecha hasta</label></td>';
print '<td>';
print $form->selectDate($filter_date_end > 0 ? $filter_date_end : -1, 'date_end', 0, 0, 1, '', 1, 1);
print '</td>';
print '</tr>';

print '</table>';

// Buttons
print '<div class="center" style="margin-top:15px;">';

print '<button type="submit" name="action" value="" class="button reposition" style="margin-right:10px;">';
print '<i class="fas fa-eye"></i> Ver vista previa';
print '</button>';

if (!empty($filter_types)) {
    print '<button type="submit" name="action" value="export" class="button butActionSave">';
    print '<i class="fas fa-file-excel" style="color:#1d6f42;"></i> Exportar a Excel';
    print '</button>';
} else {
    print '<button type="button" class="button" disabled title="Selecciona al menos un tipo de consulta">';
    print '<i class="fas fa-file-excel"></i> Exportar a Excel';
    print '</button>';
}

print '</div>';
print '</form>';

/*
 * --- Preview table ---
 */
if (!empty($filter_types)) {
    print '<br>';
    print '<div class="div-table-responsive">';

    // Summary bar
    print '<div class="fichecenter" style="margin-bottom:8px;">';
    print '<span class="badge badge-status4" style="font-size:1em;padding:5px 12px;">';
    print '<i class="fas fa-table"></i> ';
    print 'Tipo: <strong>'.dol_escape_htmltag($preview_type_label).'</strong>&nbsp;&nbsp;|&nbsp;&nbsp;';
    print 'Total: <strong>'.$preview_count.'</strong> consulta'.($preview_count != 1 ? 's' : '');
    if ($preview_count > $PREVIEW_LIMIT) {
        print ' &nbsp;<em>(mostrando primeras '.$PREVIEW_LIMIT.')</em>';
    }
    print '</span>';
    print '</div>';

    if (empty($preview_rows)) {
        print '<div class="info">No se encontraron consultas con los filtros seleccionados.</div>';
    } else {
        print '<table class="tagtable liste">';

        // Table header
        print '<thead><tr class="liste_titre">';
        print '<th>Nombre del paciente</th>';
        print '<th>Número de documento</th>';
        if (count($filter_types) > 1) print '<th>Tipo de consulta</th>';
        print '<th>Fecha inicio</th>';
        print '<th>Estado</th>';
        print '<th>Encargado(s)</th>';
        foreach ($preview_fields as $pf) {
            print '<th>'.dol_escape_htmltag($pf->field_label).'</th>';
        }
        print '<th>Observaciones generales</th>';
        print '</tr></thead>';

        print '<tbody>';
        $i = 0;
        foreach ($preview_rows as $prow) {
            $custom_data = array();
            if (!empty($prow->custom_data)) {
                $decoded = json_decode($prow->custom_data, true);
                if (is_array($decoded)) {
                    $custom_data = isset($decoded['custom_fields']) ? $decoded['custom_fields'] : $decoded;
                }
            }

            $tr_class = ($i % 2 === 0) ? 'oddeven' : 'oddeven';
            print '<tr class="'.$tr_class.'">';

            print '<td>'.dol_escape_htmltag(trim($prow->patient_name)).'</td>';
            print '<td>'.dol_escape_htmltag(trim($prow->patient_cedula)).'</td>';
            if (count($filter_types) > 1) {
                $prow_type_label = isset($preview_type_labels_map[$prow->tipo_atencion]) ? $preview_type_labels_map[$prow->tipo_atencion] : $prow->tipo_atencion;
                print '<td>'.dol_escape_htmltag($prow_type_label).'</td>';
            }
            print '<td>'.($prow->date_start ? dol_print_date($db->jdate($prow->date_start), 'dayhour') : '-').'</td>';

            // Status badge
            $st = (int) $prow->status;
            $st_class = ($st === 1) ? 'badge-status4' : (($st === 2) ? 'badge-status8' : 'badge-status1');
            $st_label = isset($status_labels[$st]) ? $status_labels[$st] : $st;
            print '<td><span class="badge '.$st_class.'">'.dol_escape_htmltag($st_label).'</span></td>';

            print '<td>'.dol_escape_htmltag(trim($prow->assigned_users)).'</td>';

            // Dynamic field values
            foreach ($preview_fields as $pf) {
                $val = '';
                if (property_exists('ExtConsultation', $pf->field_name)) {
                    $val = isset($prow->{$pf->field_name}) ? $prow->{$pf->field_name} : '';
                } else {
                    $val = isset($custom_data[$pf->field_name]) ? $custom_data[$pf->field_name] : '';
                }
                $pft = $pf->field_type;
                if ($pft === 'boolean' || $pft === 'checkbox') {
                    $val = ($val && $val !== '0' && $val !== '') ? 'Sí' : 'No';
                } elseif ($pft === 'select' || $pft === 'radio') {
                    $opts = isset($preview_opts_cache[$pf->field_name]) ? $preview_opts_cache[$pf->field_name] : array();
                    $val = isset($opts[(string)$val]) ? $opts[(string)$val] : (string)$val;
                } elseif ($pft === 'multiselect') {
                    $opts = isset($preview_opts_cache[$pf->field_name]) ? $preview_opts_cache[$pf->field_name] : array();
                    $values = is_array($val) ? $val : (($val !== '') ? explode(',', (string)$val) : array());
                    $labels = array();
                    foreach ($values as $v) {
                        $v = trim($v);
                        if ($v === '') continue;
                        $labels[] = isset($opts[$v]) ? $opts[$v] : $v;
                    }
                    $val = implode(', ', $labels);
                } else {
                    if (is_array($val)) $val = implode(', ', $val);
                    $val = strip_tags(html_entity_decode((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                print '<td>'.dol_escape_htmltag(trim(dol_trunc($val, 80))).'</td>';
            }

            // Note public
            $note = strip_tags(html_entity_decode((string)$prow->note_public, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            print '<td>'.dol_escape_htmltag(trim(dol_trunc($note, 60))).'</td>';

            print '</tr>';
            $i++;
        }
        print '</tbody>';
        print '</table>';
    }

    print '</div>';

    // Export button again at the bottom for convenience
    if ($preview_count > 0) {
        print '<div class="center" style="margin-top:12px;">';
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
        print '<input type="hidden" name="token"       value="'.newToken().'">';
        print '<input type="hidden" name="action"      value="export">';
        foreach ($filter_types as $ft_val) {
            print '<input type="hidden" name="filter_type[]" value="'.dol_escape_htmltag($ft_val).'">';
        }
        foreach ($filter_users as $fu_val) {
            print '<input type="hidden" name="filter_user[]" value="'.(int)$fu_val.'">';
        }
        foreach ($filter_eps as $fe_val) {
            print '<input type="hidden" name="filter_eps[]" value="'.(int)$fe_val.'">';
        }
        if ($filter_date_start > 0) {
            print '<input type="hidden" name="date_startday"   value="'.dol_print_date($filter_date_start,'%d').'">';
            print '<input type="hidden" name="date_startmonth" value="'.dol_print_date($filter_date_start,'%m').'">';
            print '<input type="hidden" name="date_startyear"  value="'.dol_print_date($filter_date_start,'%Y').'">';
        }
        if ($filter_date_end > 0) {
            print '<input type="hidden" name="date_endday"   value="'.dol_print_date($filter_date_end,'%d').'">';
            print '<input type="hidden" name="date_endmonth" value="'.dol_print_date($filter_date_end,'%m').'">';
            print '<input type="hidden" name="date_endyear"  value="'.dol_print_date($filter_date_end,'%Y').'">';
        }
        print '<button type="submit" class="button butActionSave">';
        print '<i class="fas fa-file-excel" style="color:#1d6f42;"></i>';
        print ' Exportar '.number_format($preview_count).' consulta'.($preview_count != 1 ? 's' : '').' a Excel';
        print '</button>';
        print '</form>';
        print '</div>';
    }
}

// Footer
llxFooter();
$db->close();
