<?php
/* Copyright (C) 2026 DatiLab
 * Export de pacientes a XLSX con filtros completos
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

$langs->loadLangs(array("companies", "other"));

if (!$user->admin && !$user->hasRight('cabinetmed', 'read')) accessforbidden();

$action = GETPOST('action', 'aZ09');
$form   = new Form($db);

// ── Etiquetas de campos select (int → texto) ──────────────────────────────────
$SELECT_LABELS = array(
    'estado_del_paciente' => array(
        1=>'En Tránsito', 2=>'En Proceso', 3=>'Activo en Tratamiento',
        4=>'Activo Independiente', 5=>'Activo Por El Programa',
        6=>'Reactivado', 7=>'Suspendido', 8=>'No trazable',
        9=>'NAP', 10=>'Inactivo',
    ),
    'estado_vital'        => array(1=>'Vivo', 2=>'Muerto'),
    'regimen'             => array(
        1=>'Contributivo', 2=>'Subsidiado', 3=>'Especial',
        4=>'Particular', 5=>'Por confirmar',
    ),
    'tipo_de_afiliacion'  => array(
        1=>'Beneficiario', 2=>'Cotizante', 3=>'Cabeza de Familia',
        4=>'Por Confirmar', 5=>'Otro', 6=>'NA',
    ),
    'tipo_de_poblacion'   => array(
        1=>'Población Mestiza', 2=>'Población Afrocolombiana',
        3=>'Población Indígena', 4=>'Población Blanca',
        5=>'Población Raizal', 6=>'Población Palenquera',
        7=>'Población Rrom o Gitana', 8=>'Población Rural',
        9=>'Población Urbana', 10=>'Población Migrante',
    ),
    'tipo_de_documento'   => array(
        1=>'Registro Civil', 2=>'Tarjeta de Identidad',
        3=>'Cédula de Ciudadanía', 4=>'Cédula de Extranjería',
        8=>'Permiso de Protección Temporal', 9=>'Salvo Conducto',
        10=>'Sin Identificación', 11=>'NIT', 13=>'NA',
        14=>'Permiso Especial de Permanencia',
    ),
);

// ── Helpers de lectura de POST ────────────────────────────────────────────────
function _intArr($key) {
    $vals = array();
    foreach ((array)(isset($_POST[$key]) ? $_POST[$key] : array()) as $v) {
        $v = (int)$v;
        if ($v > 0) $vals[] = $v;
    }
    return $vals;
}
function _strArr($key) {
    $vals = array();
    foreach ((array)(isset($_POST[$key]) ? $_POST[$key] : array()) as $v) {
        $v = trim((string)$v);
        if ($v !== '') $vals[] = $v;
    }
    return $vals;
}

// ── Lectura de filtros ────────────────────────────────────────────────────────
$filters = array(
    // FK int → tabla diccionario
    'eps'          => _intArr('filter_eps'),
    'medicamento'  => _intArr('filter_medicamento'),
    'operador'     => _intArr('filter_operador'),
    'programa'     => _intArr('filter_programa'),
    'medico'       => _intArr('filter_medico'),
    'diagnostico'  => _intArr('filter_diagnostico'),
    // Int → SELECT_LABELS
    'estado'       => _intArr('filter_estado'),
    'estadovital'  => _intArr('filter_estadovital'),
    'tipodoc'      => _intArr('filter_tipodoc'),
    'regimen'      => _intArr('filter_regimen'),
    'afiliacion'   => _intArr('filter_afiliacion'),
    'poblacion'    => _intArr('filter_poblacion'),
    // Ubicación
    'departamento' => _intArr('filter_departamento'),
    'ciudad'       => _strArr('filter_ciudad'),
    // Texto libre (valores distintos de la BD)
    'concentracion'=> _strArr('filter_concentracion'),
    'sede'         => _strArr('filter_sede'),
    'ips'          => _strArr('filter_ips'),
    // Fechas creación
    'date_start'   => dol_mktime(0, 0, 0,
        GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear')),
    'date_end'     => dol_mktime(23, 59, 59,
        GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear')),
    // Fechas nacimiento
    'birth_start'  => dol_mktime(0, 0, 0,
        GETPOSTINT('birth_startmonth'), GETPOSTINT('birth_startday'), GETPOSTINT('birth_startyear')),
    'birth_end'    => dol_mktime(23, 59, 59,
        GETPOSTINT('birth_endmonth'), GETPOSTINT('birth_endday'), GETPOSTINT('birth_endyear')),
);

// ── Construcción de SQL ───────────────────────────────────────────────────────
function buildPatientSql($db, $conf, $filters)
{
    $joins  = ' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields ef ON ef.fk_object = s.rowid';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_eps        d_eps  ON d_eps.rowid  = ef.eps';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_medicamento d_med  ON d_med.rowid  = ef.medicamento';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_operador    d_op   ON d_op.rowid   = ef.operador_logistico';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_programa    d_prog ON d_prog.rowid = ef.programa';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_medico      d_med2 ON d_med2.rowid = ef.medico_tratante';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_diagnostico d_diag ON d_diag.rowid = ef.diagnostico';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_departements      dep    ON dep.rowid    = s.fk_departement';
    $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'cabinetmed_extcons  c      ON c.fk_soc     = s.rowid';

    $where  = " WHERE s.canvas = 'patient@cabinetmed' AND s.entity IN (".getEntity('societe').')';

    // FK int
    if (!empty($filters['eps']))          $where .= ' AND ef.eps IN ('.implode(',', $filters['eps']).')';
    if (!empty($filters['medicamento']))  $where .= ' AND ef.medicamento IN ('.implode(',', $filters['medicamento']).')';
    if (!empty($filters['operador']))     $where .= ' AND ef.operador_logistico IN ('.implode(',', $filters['operador']).')';
    if (!empty($filters['programa']))     $where .= ' AND ef.programa IN ('.implode(',', $filters['programa']).')';
    if (!empty($filters['medico']))       $where .= ' AND ef.medico_tratante IN ('.implode(',', $filters['medico']).')';
    if (!empty($filters['diagnostico']))  $where .= ' AND ef.diagnostico IN ('.implode(',', $filters['diagnostico']).')';
    if (!empty($filters['departamento'])) $where .= ' AND s.fk_departement IN ('.implode(',', $filters['departamento']).')';

    // SELECT_LABELS int
    if (!empty($filters['estado']))      $where .= ' AND ef.estado_del_paciente IN ('.implode(',', $filters['estado']).')';
    if (!empty($filters['estadovital'])) $where .= ' AND ef.estado_vital IN ('.implode(',', $filters['estadovital']).')';
    if (!empty($filters['tipodoc']))     $where .= ' AND ef.tipo_de_documento IN ('.implode(',', $filters['tipodoc']).')';
    if (!empty($filters['regimen']))     $where .= ' AND ef.regimen IN ('.implode(',', $filters['regimen']).')';
    if (!empty($filters['afiliacion']))  $where .= ' AND ef.tipo_de_afiliacion IN ('.implode(',', $filters['afiliacion']).')';
    if (!empty($filters['poblacion']))   $where .= ' AND ef.tipo_de_poblacion IN ('.implode(',', $filters['poblacion']).')';

    // String
    if (!empty($filters['ciudad'])) {
        $esc = array_map(function($v) use ($db) { return "'".$db->escape($v)."'"; }, $filters['ciudad']);
        $where .= ' AND s.town IN ('.implode(',', $esc).')';
    }
    if (!empty($filters['concentracion'])) {
        $esc = array_map(function($v) use ($db) { return "'".$db->escape($v)."'"; }, $filters['concentracion']);
        $where .= ' AND ef.concentracion IN ('.implode(',', $esc).')';
    }
    if (!empty($filters['sede'])) {
        $esc = array_map(function($v) use ($db) { return "'".$db->escape($v)."'"; }, $filters['sede']);
        $where .= ' AND ef.sede_operador_logistico IN ('.implode(',', $esc).')';
    }
    if (!empty($filters['ips'])) {
        $esc = array_map(function($v) use ($db) { return "'".$db->escape($v)."'"; }, $filters['ips']);
        $where .= ' AND ef.ips_primaria IN ('.implode(',', $esc).')';
    }

    // Fechas creación
    if ($filters['date_start']  > 0) $where .= " AND s.datec >= '".$db->idate($filters['date_start'])."'";
    if ($filters['date_end']    > 0) $where .= " AND s.datec <= '".$db->idate($filters['date_end'])."'";

    // Fechas nacimiento
    if ($filters['birth_start'] > 0) $where .= " AND ef.birthdate >= '".$db->idate($filters['birth_start'])."'";
    if ($filters['birth_end']   > 0) $where .= " AND ef.birthdate <= '".$db->idate($filters['birth_end'])."'";

    return array('joins' => $joins, 'where' => $where);
}

$built = buildPatientSql($db, $conf, $filters);

// ── Columnas XLSX ─────────────────────────────────────────────────────────────
$COLUMNS = array(
    'nombre'                  => 'Nombre',
    'tipo_de_documento'       => 'Tipo Documento',
    'n_documento'             => 'N° Documento',
    'birthdate'               => 'Fecha Nacimiento',
    'email'                   => 'Email',
    'phone'                   => 'Teléfono',
    'eps'                     => 'EPS',
    'regimen'                 => 'Régimen',
    'tipo_de_afiliacion'      => 'Tipo Afiliación',
    'medicamento'             => 'Medicamento',
    'concentracion'           => 'Concentración',
    'operador_logistico'      => 'Operador Logístico',
    'sede_operador_logistico' => 'Sede Operador',
    'programa'                => 'Programa',
    'estado_del_paciente'     => 'Estado Paciente',
    'estado_vital'            => 'Estado Vital',
    'ips_primaria'            => 'IPS Primaria',
    'medico_tratante'         => 'Médico Tratante',
    'tipo_de_poblacion'       => 'Tipo Población',
    'diagnostico'             => 'Diagnóstico',
    'departamento'            => 'Departamento',
    'ciudad'                  => 'Ciudad',
    'fecha_creacion'          => 'Fecha Creación',
    'total_consultas'         => 'Total Consultas',
    'ultima_consulta'         => 'Última Consulta',
);

$SELECT_SQL = 'SELECT s.rowid,'
    .' s.nom AS nombre, s.datec AS fecha_creacion, s.email, s.phone, s.town AS ciudad,'
    .' dep.nom AS departamento,'
    .' ef.tipo_de_documento, ef.n_documento, ef.birthdate,'
    .' ef.regimen, ef.tipo_de_afiliacion, ef.concentracion,'
    .' ef.sede_operador_logistico, ef.estado_del_paciente, ef.estado_vital,'
    .' ef.ips_primaria, ef.tipo_de_poblacion,'
    .' d_eps.descripcion   AS eps,'
    .' d_med.etiqueta      AS medicamento,'
    .' d_op.nombre         AS operador_logistico,'
    .' d_prog.nombre       AS programa,'
    .' d_med2.nombre       AS medico_tratante,'
    .' d_diag.label        AS diagnostico,'
    .' COUNT(c.rowid)      AS total_consultas,'
    .' MAX(c.date_start)   AS ultima_consulta'
    .' FROM '.MAIN_DB_PREFIX.'societe s';

$GROUP_SQL = ' GROUP BY s.rowid, s.nom, s.datec, s.email, s.phone, s.town,'
    .' dep.nom, ef.tipo_de_documento, ef.n_documento, ef.birthdate,'
    .' ef.regimen, ef.tipo_de_afiliacion, ef.concentracion,'
    .' ef.sede_operador_logistico, ef.estado_del_paciente, ef.estado_vital,'
    .' ef.ips_primaria, ef.tipo_de_poblacion,'
    .' d_eps.descripcion, d_med.etiqueta, d_op.nombre,'
    .' d_prog.nombre, d_med2.nombre, d_diag.label';

function resolveSelectLabel($field, $val, $SELECT_LABELS) {
    if (!isset($SELECT_LABELS[$field])) return $val;
    return isset($SELECT_LABELS[$field][(int)$val]) ? $SELECT_LABELS[$field][(int)$val] : $val;
}

/*
 * ============================================================
 * EXPORT ACTION
 * ============================================================
 */
if ($action === 'export') {

    require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
    require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
    require_once PHPEXCELNEW_PATH.'Spreadsheet.php';

    $sql     = $SELECT_SQL.$built['joins'].$built['where'].$GROUP_SQL.' ORDER BY s.nom ASC';
    $res_pat = $db->query($sql);
    if (!$res_pat) {
        setEventMessages($db->lasterror(), null, 'errors');
        header('Location: '.$_SERVER['PHP_SELF']);
        exit;
    }

    $data = array(array_values($COLUMNS));
    while ($row = $db->fetch_object($res_pat)) {
        $line = array();
        foreach (array_keys($COLUMNS) as $field) {
            $val = isset($row->$field) ? $row->$field : '';
            if (in_array($field, array('fecha_creacion', 'ultima_consulta', 'birthdate'))) {
                $val = $val ? dol_print_date($db->jdate($val), 'dayrfc') : '';
            } elseif (in_array($field, array('tipo_de_documento','regimen','tipo_de_afiliacion','tipo_de_poblacion','estado_del_paciente','estado_vital'))) {
                $val = resolveSelectLabel($field, $val, $SELECT_LABELS);
            } elseif ($field === 'total_consultas') {
                $val = (int)$val;
            }
            $line[] = is_string($val) ? html_entity_decode(strip_tags($val), ENT_QUOTES|ENT_HTML5, 'UTF-8') : $val;
        }
        $data[] = $line;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Pacientes');
    $sheet->fromArray($data, null, 'A1');

    $numCols = count($COLUMNS);
    $numRows = count($data);
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($numCols);

    $sheet->getStyle('A1:'.$lastCol.'1')->applyFromArray(array(
        'font'      => array('bold'=>true, 'color'=>array('rgb'=>'FFFFFF')),
        'fill'      => array('fillType'=>Fill::FILL_SOLID, 'startColor'=>array('rgb'=>'1A56A0')),
        'alignment' => array('horizontal'=>Alignment::HORIZONTAL_CENTER, 'wrapText'=>true),
        'borders'   => array('allBorders'=>array('borderStyle'=>Border::BORDER_THIN, 'color'=>array('rgb'=>'AAAAAA'))),
    ));
    $sheet->getRowDimension(1)->setRowHeight(20);

    for ($r = 2; $r <= $numRows; $r++) {
        if ($r % 2 === 0) {
            $sheet->getStyle('A'.$r.':'.$lastCol.$r)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('EEF4FF');
        }
    }
    for ($c = 1; $c <= $numCols; $c++) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
    }

    $sheet->setAutoFilter('A1:'.$lastCol.'1');
    $sheet->freezePane('A2');

    $filename = 'pacientes_'.dol_print_date(dol_now(), 'dayhour').'.xlsx';

    while (ob_get_level()) ob_end_clean();
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
 * HTML PAGE
 * ============================================================
 */

// Contar total para preview
$sql_cnt  = 'SELECT COUNT(DISTINCT s.rowid) AS cnt FROM '.MAIN_DB_PREFIX.'societe s';
$sql_cnt .= $built['joins'].$built['where'];
$res_cnt  = $db->query($sql_cnt);
$total_count = 0;
if ($res_cnt) {
    $row_cnt = $db->fetch_object($res_cnt);
    $total_count = (int)$row_cnt->cnt;
}

$PREVIEW_LIMIT = 20;
$preview_rows  = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'export') {
    $sql_prev = $SELECT_SQL.$built['joins'].$built['where'].$GROUP_SQL.' ORDER BY s.nom ASC LIMIT '.$PREVIEW_LIMIT;
    $res_prev = $db->query($sql_prev);
    while ($res_prev && $prow = $db->fetch_object($res_prev)) {
        $preview_rows[] = $prow;
    }
}

// ── Cargar opciones de filtros ────────────────────────────────────────────────
function loadOptions($db, $table, $valCol, $labelCol, $orderCol = null) {
    $opts = array();
    $sql  = 'SELECT '.$valCol.', '.$labelCol.' FROM '.MAIN_DB_PREFIX.$table;
    $sql .= ' WHERE entity IN ('.getEntity('societe').') ORDER BY '.($orderCol ?: $labelCol).' ASC';
    $res  = $db->query($sql);
    while ($res && $r = $db->fetch_object($res)) {
        $opts[(int)$r->$valCol] = $r->$labelCol;
    }
    return $opts;
}

// Carga valores distintos de un campo texto en societe_extrafields (solo pacientes)
function loadDistinctExtrafield($db, $col) {
    $opts = array();
    $sql  = 'SELECT DISTINCT ef.'.$col.' FROM '.MAIN_DB_PREFIX.'societe_extrafields ef';
    $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = ef.fk_object';
    $sql .= " WHERE s.canvas = 'patient@cabinetmed' AND s.entity IN (".getEntity('societe').')';
    $sql .= ' AND ef.'.$col." IS NOT NULL AND ef.".$col." <> ''";
    $sql .= ' ORDER BY ef.'.$col.' ASC';
    $res  = $db->query($sql);
    while ($res && $r = $db->fetch_object($res)) {
        $v = $r->$col;
        $opts[$v] = $v;
    }
    return $opts;
}

$opt_eps         = loadOptions($db, 'gestion_eps',        'rowid', 'descripcion');
$opt_medicamento = loadOptions($db, 'gestion_medicamento', 'rowid', 'etiqueta');
$opt_operador    = loadOptions($db, 'gestion_operador',    'rowid', 'nombre');
$opt_programa    = loadOptions($db, 'gestion_programa',    'rowid', 'nombre');
$opt_medico      = loadOptions($db, 'gestion_medico',      'rowid', 'nombre');
$opt_diagnostico = loadOptions($db, 'gestion_diagnostico', 'rowid', 'label');

$opt_estado      = $SELECT_LABELS['estado_del_paciente'];
$opt_estadovital = $SELECT_LABELS['estado_vital'];
$opt_tipodoc     = $SELECT_LABELS['tipo_de_documento'];
$opt_regimen     = $SELECT_LABELS['regimen'];
$opt_afiliacion  = $SELECT_LABELS['tipo_de_afiliacion'];
$opt_poblacion   = $SELECT_LABELS['tipo_de_poblacion'];

$opt_concentracion = loadDistinctExtrafield($db, 'concentracion');
$opt_sede          = loadDistinctExtrafield($db, 'sede_operador_logistico');
$opt_ips           = loadDistinctExtrafield($db, 'ips_primaria');

// Departamentos con pacientes
$opt_departamento = array();
$sql_dep  = 'SELECT DISTINCT dep.rowid, dep.nom FROM '.MAIN_DB_PREFIX.'c_departements dep';
$sql_dep .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.fk_departement = dep.rowid';
$sql_dep .= " WHERE s.canvas='patient@cabinetmed' AND s.entity IN (".getEntity('societe').')';
$sql_dep .= ' ORDER BY dep.nom ASC';
$res_dep  = $db->query($sql_dep);
while ($res_dep && $r = $db->fetch_object($res_dep)) {
    $opt_departamento[(int)$r->rowid] = $r->nom;
}

// Ciudades con pacientes
$opt_ciudad = array();
$sql_cit  = 'SELECT DISTINCT s.town FROM '.MAIN_DB_PREFIX.'societe s';
$sql_cit .= " WHERE s.canvas='patient@cabinetmed' AND s.entity IN (".getEntity('societe').')';
$sql_cit .= " AND s.town IS NOT NULL AND s.town <> '' ORDER BY s.town ASC";
$res_cit  = $db->query($sql_cit);
while ($res_cit && $r = $db->fetch_object($res_cit)) {
    $opt_ciudad[$r->town] = $r->town;
}

// ── HTML ──────────────────────────────────────────────────────────────────────
llxHeader('', 'Exportar pacientes', '');
print_fiche_titre('Exportar pacientes a Excel', '', 'file-export');

print '
<style>
.cm-multiselect-wrap{position:relative;display:inline-block;min-width:220px;max-width:100%}
.cm-multiselect-trigger{display:flex;align-items:center;justify-content:space-between;padding:6px 10px;border:1px solid #aaa;border-radius:4px;background:#fff;cursor:pointer;min-height:34px;user-select:none;font-size:.95em;gap:6px}
.cm-multiselect-trigger:hover{border-color:#555}
.cm-ms-badges{display:flex;flex-wrap:nowrap;gap:3px;flex:1;overflow:hidden}
.cm-ms-arrow{color:#888;font-size:.8em;flex-shrink:0}
.cm-ms-badge{background:#1A56A0;color:#fff;border-radius:10px;padding:1px 7px;font-size:.78em;white-space:nowrap;display:inline-flex;align-items:center;gap:3px}
.cm-ms-badge .cm-ms-remove{cursor:pointer;opacity:.75}
.cm-ms-badge .cm-ms-remove:hover{opacity:1}
.cm-ms-more{background:#888}
.cm-multiselect-dropdown{display:none;position:absolute;top:calc(100% + 3px);left:0;min-width:100%;max-width:380px;background:#fff;border:1px solid #aaa;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.15);z-index:9999;flex-direction:column}
.cm-multiselect-dropdown.open{display:flex}
.cm-ms-search-wrap{padding:7px 8px;border-bottom:1px solid #e8e8e8}
.cm-ms-search{width:100%;box-sizing:border-box;padding:5px 8px;border:1px solid #ccc;border-radius:3px;font-size:.9em}
.cm-ms-actions{display:flex;gap:6px;padding:4px 8px 5px;border-bottom:1px solid #efefef}
.cm-ms-actions a{font-size:.82em;color:#1A56A0;cursor:pointer;text-decoration:underline}
.cm-ms-list{max-height:220px;overflow-y:auto;padding:4px 0}
.cm-ms-item{display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;font-size:.93em}
.cm-ms-item:hover{background:#f0f5ff}
.cm-ms-item input[type=checkbox]{margin:0;cursor:pointer;accent-color:#1A56A0}
.cm-ms-empty{padding:10px;color:#888;font-style:italic;font-size:.9em;text-align:center}
.cm-filter-section{background:#f7f9fc;border-left:3px solid #1A56A0;padding:5px 12px;margin:10px 0 4px;font-weight:bold;font-size:.9em;color:#1A56A0;text-transform:uppercase;letter-spacing:.03em}
</style>
<script>
(function(){
  function initMultiselect(wrapperId){
    var wrap=document.getElementById(wrapperId);if(!wrap)return;
    var trigger=wrap.querySelector(".cm-multiselect-trigger");
    var dropdown=wrap.querySelector(".cm-multiselect-dropdown");
    var search=wrap.querySelector(".cm-ms-search");
    var list=wrap.querySelector(".cm-ms-list");
    var items=list.querySelectorAll(".cm-ms-item");
    trigger.addEventListener("click",function(e){
      var isOpen=dropdown.classList.contains("open");
      document.querySelectorAll(".cm-multiselect-dropdown.open").forEach(function(d){d.classList.remove("open");});
      if(!isOpen){dropdown.classList.add("open");if(search){search.value="";filterItems(list,items,"");search.focus();}}
    });
    if(search){
      search.addEventListener("input",function(){filterItems(list,items,this.value.toLowerCase());});
      search.addEventListener("click",function(e){e.stopPropagation();});
    }
    items.forEach(function(item){
      var cb=item.querySelector("input[type=checkbox]");
      item.addEventListener("click",function(e){if(e.target!==cb)cb.checked=!cb.checked;updateTrigger(wrap);});
    });
    var btnAll=wrap.querySelector(".cm-ms-all");
    var btnNone=wrap.querySelector(".cm-ms-none");
    if(btnAll)  btnAll.addEventListener("click",function(e){e.preventDefault();setAll(items,true); updateTrigger(wrap);});
    if(btnNone) btnNone.addEventListener("click",function(e){e.preventDefault();setAll(items,false);updateTrigger(wrap);});
    document.addEventListener("click",function(e){if(!wrap.contains(e.target))dropdown.classList.remove("open");});
    updateTrigger(wrap);
  }
  function filterItems(list,items,q){
    var shown=0;
    items.forEach(function(item){
      var match=!q||item.dataset.label.toLowerCase().indexOf(q)!==-1;
      item.style.display=match?"":"none";if(match)shown++;
    });
    var em=list.querySelector(".cm-ms-empty");if(em)em.style.display=shown===0?"":"none";
  }
  function setAll(items,checked){
    items.forEach(function(item){if(item.style.display!=="none"){var cb=item.querySelector("input[type=checkbox]");if(cb)cb.checked=checked;}});
  }
  function updateTrigger(wrap){
    var badges=wrap.querySelector(".cm-ms-badges");if(!badges)return;
    var checked=[];
    wrap.querySelectorAll(".cm-ms-item input[type=checkbox]:checked").forEach(function(cb){checked.push({value:cb.value,label:cb.dataset.label||cb.value});});
    badges.innerHTML="";
    var MAX=3;
    checked.slice(0,MAX).forEach(function(c){
      var b=document.createElement("span");b.className="cm-ms-badge";
      b.innerHTML=escHtml(c.label)+\' <span class="cm-ms-remove" data-val="\'+escHtml(c.value)+\'">&#x2715;</span>\';
      b.querySelector(".cm-ms-remove").addEventListener("click",function(e){
        e.stopPropagation();var val=this.dataset.val;
        wrap.querySelectorAll(".cm-ms-item input[type=checkbox]").forEach(function(cb){if(cb.value===val)cb.checked=false;});
        updateTrigger(wrap);
      });
      badges.appendChild(b);
    });
    if(checked.length>MAX){var m=document.createElement("span");m.className="cm-ms-badge cm-ms-more";m.textContent="+"+(checked.length-MAX)+" mas";badges.appendChild(m);}
    if(checked.length===0){badges.innerHTML="<span style=\'color:#999;font-size:.93em;\'>"+escHtml(wrap.dataset.placeholder||"Seleccionar...")+"</span>";}
  }
  function escHtml(s){return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#39;");}
  function syncBeforeSubmit(formId){
    var form=document.getElementById(formId);if(!form)return;
    form.addEventListener("submit",function(){
      form.querySelectorAll(".cm-ms-hidden-select").forEach(function(sel){
        while(sel.options.length)sel.remove(0);
        var wrap=document.getElementById(sel.dataset.wrap);if(!wrap)return;
        wrap.querySelectorAll(".cm-ms-item input[type=checkbox]:checked").forEach(function(cb){
          var opt=document.createElement("option");opt.value=cb.value;opt.selected=true;sel.appendChild(opt);
        });
      });
    });
  }
  document.addEventListener("DOMContentLoaded",function(){
    [
      "cm-ms-eps","cm-ms-med","cm-ms-conc","cm-ms-op","cm-ms-sede","cm-ms-prog",
      "cm-ms-estado","cm-ms-estadovital",
      "cm-ms-tipodoc","cm-ms-regimen","cm-ms-afiliacion","cm-ms-poblacion",
      "cm-ms-medico","cm-ms-diag","cm-ms-ips",
      "cm-ms-depto","cm-ms-ciudad"
    ].forEach(function(id){initMultiselect(id);});
    syncBeforeSubmit("export-filter-form");
  });
})();
</script>
';

function renderMs($wrapperId, $name, $items, $selected, $placeholder) {
    $out  = '<div class="cm-multiselect-wrap" id="'.dol_escape_htmltag($wrapperId).'" data-placeholder="'.dol_escape_htmltag($placeholder).'">';
    $out .= '<select name="'.dol_escape_htmltag($name).'" multiple class="cm-ms-hidden-select" data-wrap="'.dol_escape_htmltag($wrapperId).'" style="display:none;"></select>';
    $out .= '<div class="cm-multiselect-trigger"><span class="cm-ms-badges"></span><span class="cm-ms-arrow">&#9660;</span></div>';
    $out .= '<div class="cm-multiselect-dropdown">';
    $out .= '<div class="cm-ms-search-wrap"><input type="text" class="cm-ms-search" placeholder="Buscar..."></div>';
    $out .= '<div class="cm-ms-actions"><a class="cm-ms-all">Todos</a> &middot; <a class="cm-ms-none">Ninguno</a></div>';
    $out .= '<div class="cm-ms-list">';
    foreach ($items as $val => $label) {
        $checked = in_array((string)$val, array_map('strval', $selected)) ? ' checked' : '';
        $out .= '<div class="cm-ms-item" data-label="'.dol_escape_htmltag($label).'">';
        $out .= '<input type="checkbox" value="'.dol_escape_htmltag($val).'" data-label="'.dol_escape_htmltag($label).'"'.$checked.'>';
        $out .= '<span>'.dol_escape_htmltag($label).'</span>';
        $out .= '</div>';
    }
    $out .= '<div class="cm-ms-empty" style="display:none;">Sin resultados</div>';
    $out .= '</div></div></div>';
    return $out;
}

// ── Formulario ────────────────────────────────────────────────────────────────
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="export-filter-form">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="border centpercent">';

// ─ Tratamiento ─
print '<tr><td colspan="4"><div class="cm-filter-section">Tratamiento</div></td></tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle;width:15%"><label>EPS</label></td>';
print '<td style="width:35%">'.renderMs('cm-ms-eps',  'filter_eps[]',         $opt_eps,          $filters['eps'],         'Todas las EPS...').'</td>';
print '<td class="titlefield" style="vertical-align:middle;width:15%"><label>Medicamento</label></td>';
print '<td style="width:35%">'.renderMs('cm-ms-med',  'filter_medicamento[]',  $opt_medicamento,  $filters['medicamento'], 'Todos los medicamentos...').'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Concentración</label></td>';
print '<td>'.renderMs('cm-ms-conc', 'filter_concentracion[]', $opt_concentracion, $filters['concentracion'], 'Todas las concentraciones...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Operador Logístico</label></td>';
print '<td>'.renderMs('cm-ms-op',   'filter_operador[]',     $opt_operador,     $filters['operador'],    'Todos los operadores...').'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Sede Operador</label></td>';
print '<td>'.renderMs('cm-ms-sede', 'filter_sede[]',         $opt_sede,         $filters['sede'],        'Todas las sedes...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Programa</label></td>';
print '<td>'.renderMs('cm-ms-prog', 'filter_programa[]',     $opt_programa,     $filters['programa'],    'Todos los programas...').'</td>';
print '</tr>';

// ─ Estado y clasificación ─
print '<tr><td colspan="4"><div class="cm-filter-section">Estado y clasificación</div></td></tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Estado del Paciente</label></td>';
print '<td>'.renderMs('cm-ms-estado',     'filter_estado[]',        $opt_estado,      $filters['estado'],      'Todos los estados...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Estado Vital</label></td>';
print '<td>'.renderMs('cm-ms-estadovital','filter_estadovital[]',   $opt_estadovital, $filters['estadovital'], 'Todos...').'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Tipo Documento</label></td>';
print '<td>'.renderMs('cm-ms-tipodoc',    'filter_tipodoc[]',       $opt_tipodoc,     $filters['tipodoc'],     'Todos los tipos...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Régimen</label></td>';
print '<td>'.renderMs('cm-ms-regimen',    'filter_regimen[]',       $opt_regimen,     $filters['regimen'],     'Todos los regímenes...').'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Tipo Afiliación</label></td>';
print '<td>'.renderMs('cm-ms-afiliacion', 'filter_afiliacion[]',    $opt_afiliacion,  $filters['afiliacion'],  'Todos los tipos...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Tipo Población</label></td>';
print '<td>'.renderMs('cm-ms-poblacion',  'filter_poblacion[]',     $opt_poblacion,   $filters['poblacion'],   'Todos los tipos...').'</td>';
print '</tr>';

// ─ Médico y diagnóstico ─
print '<tr><td colspan="4"><div class="cm-filter-section">Médico y diagnóstico</div></td></tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Médico Tratante</label></td>';
print '<td>'.renderMs('cm-ms-medico', 'filter_medico[]',      $opt_medico,      $filters['medico'],      'Todos los médicos...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Diagnóstico</label></td>';
print '<td>'.renderMs('cm-ms-diag',   'filter_diagnostico[]', $opt_diagnostico, $filters['diagnostico'], 'Todos los diagnósticos...').'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>IPS Primaria</label></td>';
print '<td>'.renderMs('cm-ms-ips', 'filter_ips[]', $opt_ips, $filters['ips'], 'Todas las IPS...').'</td>';
print '<td></td><td></td>';
print '</tr>';

// ─ Ubicación ─
print '<tr><td colspan="4"><div class="cm-filter-section">Ubicación</div></td></tr>';
print '<tr>';
print '<td class="titlefield" style="vertical-align:middle"><label>Departamento</label></td>';
print '<td>'.renderMs('cm-ms-depto',  'filter_departamento[]', $opt_departamento, $filters['departamento'], 'Todos los departamentos...').'</td>';
print '<td class="titlefield" style="vertical-align:middle"><label>Ciudad</label></td>';
print '<td>'.renderMs('cm-ms-ciudad', 'filter_ciudad[]',       $opt_ciudad,       $filters['ciudad'],       'Todas las ciudades...').'</td>';
print '</tr>';

// ─ Fechas ─
print '<tr><td colspan="4"><div class="cm-filter-section">Fechas</div></td></tr>';
print '<tr>';
print '<td class="titlefield"><label>Fecha creación desde</label></td>';
print '<td>'.$form->selectDate($filters['date_start'] > 0 ? $filters['date_start'] : -1, 'date_start', 0, 0, 1, '', 1, 1).'</td>';
print '<td class="titlefield"><label>Fecha creación hasta</label></td>';
print '<td>'.$form->selectDate($filters['date_end'] > 0 ? $filters['date_end'] : -1, 'date_end', 0, 0, 1, '', 1, 1).'</td>';
print '</tr>';
print '<tr>';
print '<td class="titlefield"><label>Fecha nacimiento desde</label></td>';
print '<td>'.$form->selectDate($filters['birth_start'] > 0 ? $filters['birth_start'] : -1, 'birth_start', 0, 0, 1, '', 1, 1).'</td>';
print '<td class="titlefield"><label>Fecha nacimiento hasta</label></td>';
print '<td>'.$form->selectDate($filters['birth_end'] > 0 ? $filters['birth_end'] : -1, 'birth_end', 0, 0, 1, '', 1, 1).'</td>';
print '</tr>';

print '</table>';

print '<div class="center" style="margin-top:15px;">';
print '<button type="submit" name="action" value="" class="button reposition" style="margin-right:10px;">';
print '&#128269; Ver vista previa';
print '</button>';
print '<button type="submit" name="action" value="export" class="button butActionSave">';
print '&#128190; Exportar a Excel';
print '</button>';
print '</div>';
print '</form>';

// ── Vista previa ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'export') {
    print '<br>';
    print '<div class="fichecenter" style="margin-bottom:8px;">';
    print '<span class="badge badge-status4" style="font-size:1em;padding:5px 12px;">';
    print 'Total: <strong>'.$total_count.'</strong> paciente'.($total_count != 1 ? 's' : '');
    if ($total_count > $PREVIEW_LIMIT) {
        print ' &nbsp;<em>(mostrando primeros '.$PREVIEW_LIMIT.')</em>';
    }
    print '</span>';
    print '</div>';

    $preview_cols = array(
        'nombre'              => 'Nombre',
        'n_documento'         => 'N° Documento',
        'eps'                 => 'EPS',
        'medicamento'         => 'Medicamento',
        'programa'            => 'Programa',
        'estado_del_paciente' => 'Estado',
        'estado_vital'        => 'E. Vital',
        'medico_tratante'     => 'Médico',
        'diagnostico'         => 'Diagnóstico',
        'departamento'        => 'Departamento',
        'ciudad'              => 'Ciudad',
        'fecha_creacion'      => 'Fecha Creación',
        'total_consultas'     => 'Consultas',
    );

    if (empty($preview_rows)) {
        print '<div class="info">No se encontraron pacientes con los filtros seleccionados.</div>';
    } else {
        print '<div class="div-table-responsive"><table class="tagtable liste">';
        print '<thead><tr class="liste_titre">';
        foreach ($preview_cols as $label) {
            print '<th>'.dol_escape_htmltag($label).'</th>';
        }
        print '</tr></thead><tbody>';

        foreach ($preview_rows as $prow) {
            print '<tr class="oddeven">';
            foreach (array_keys($preview_cols) as $field) {
                $val = isset($prow->$field) ? $prow->$field : '';
                if ($field === 'fecha_creacion') {
                    $val = $val ? dol_print_date($db->jdate($val), 'day') : '';
                } elseif (in_array($field, array('estado_del_paciente','estado_vital','regimen','tipo_de_afiliacion','tipo_de_poblacion','tipo_de_documento'))) {
                    $val = resolveSelectLabel($field, $val, $SELECT_LABELS);
                } elseif ($field === 'total_consultas') {
                    $val = (int)$val;
                }
                print '<td>'.dol_escape_htmltag((string)$val).'</td>';
            }
            print '</tr>';
        }
        print '</tbody></table></div>';
    }
}

llxFooter();
$db->close();
