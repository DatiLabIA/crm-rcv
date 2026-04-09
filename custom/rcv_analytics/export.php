<?php
/* Copyright (C) 2024 DatiLab
 * Exportación XLSX de datos analíticos con gráficas
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Title;
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
dol_include_once('/rcv_analytics/class/rcvanalyticsengine.class.php');
dol_include_once('/rcv_analytics/lib/rcv_analytics.lib.php');

$langs->loadLangs(array("companies", "rcv_analytics@rcv_analytics"));

if (!$user->admin && !$user->hasRight('rcv_analytics', 'export')) accessforbidden();

$form   = new Form($db);
$engine = new RcvAnalyticsEngine($db);

$action              = GETPOST('action', 'aZ09');
$exportType          = GETPOST('type', 'alpha');
$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha');

$_date_start_ts = $button_removefilter ? 0 : dol_mktime(0, 0, 0, GETPOSTINT('filter_date_startmonth'), GETPOSTINT('filter_date_startday'), GETPOSTINT('filter_date_startyear'));
$_date_end_ts   = $button_removefilter ? 0 : dol_mktime(23, 59, 59, GETPOSTINT('filter_date_endmonth'), GETPOSTINT('filter_date_endday'), GETPOSTINT('filter_date_endyear'));

$filters = array();
if (!$button_removefilter) {
    $filters['date_start']         = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['date_end']           = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
    $filters['medicamento']        = GETPOST('filter_medicamento', 'alpha');
    $filters['eps']                = GETPOST('filter_eps', 'alpha');
    $filters['operador_logistico'] = GETPOST('filter_operador_logistico', 'alpha');
    $filters['tipo_de_poblacion']  = GETPOST('filter_tipo_de_poblacion', 'alpha');
    $filters['tipo_atencion']      = GETPOST('filter_tipo_atencion', 'alpha');
    $filters['programa']           = GETPOST('filter_programa', 'alpha');
    $filters['diagnostico']        = GETPOST('filter_diagnostico', 'alpha');
    $filters['ips_primaria']       = GETPOST('filter_ips_primaria', 'alpha');
    $filters['estado_del_paciente']= GETPOST('filter_estado_del_paciente', 'alpha');
}

$cleanFilters = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
$engine->setFilters($cleanFilters);

// ─── Exportar XLSX ─────────────────────────────────────────────────────────
if ($action === 'export' || !empty($exportType)) {
    $type = $exportType ?: GETPOST('export_type', 'alpha');

    require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
    require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
    require_once PHPEXCELNEW_PATH.'Spreadsheet.php';

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('RCV Analytics')
        ->setTitle('Analíticas RCV')
        ->setDescription('Exportación generada por el módulo RCV Analytics');

    $dateStr = dol_print_date(dol_now(), 'dayrfc');

    // ── Estilos reutilizables ────────────────────────────────────────────
    $styleHeader = array(
        'font'      => array('bold' => true, 'color' => array('argb' => 'FFFFFFFF')),
        'fill'      => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('argb' => 'FF1E3A5F')),
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER),
        'borders'   => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('argb' => 'FFAAAAAA'))),
    );
    $styleSubHeader = array(
        'font'      => array('bold' => true, 'color' => array('argb' => 'FF1E3A5F')),
        'fill'      => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('argb' => 'FFD6E4F0')),
        'borders'   => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('argb' => 'FFAAAAAA'))),
    );
    $styleData = array(
        'borders' => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('argb' => 'FFDDDDDD'))),
    );
    $styleNumber = array(
        'alignment' => array('horizontal' => Alignment::HORIZONTAL_RIGHT),
        'borders'   => array('allBorders' => array('borderStyle' => Border::BORDER_THIN, 'color' => array('argb' => 'FFDDDDDD'))),
    );

    /**
     * Añade una hoja con tabla + gráfica de barras/pastel
     */
    $addDistSheet = function (
        $spreadsheet, $sheetTitle, $chartTitle,
        array $rows, $col1Label, $col2Label,
        $chartType = 'bar',
        $isFirst = false
    ) use ($styleHeader, $styleSubHeader, $styleData, $styleNumber) {
        if ($isFirst) {
            $ws = $spreadsheet->getActiveSheet();
            $ws->setTitle(mb_substr($sheetTitle, 0, 31));
        } else {
            $ws = $spreadsheet->createSheet();
            $ws->setTitle(mb_substr($sheetTitle, 0, 31));
        }

        // Encabezados de tabla
        $ws->setCellValue('A1', $col1Label);
        $ws->setCellValue('B1', $col2Label);
        $ws->getStyle('A1:B1')->applyFromArray($styleSubHeader);
        $ws->getColumnDimension('A')->setWidth(35);
        $ws->getColumnDimension('B')->setWidth(18);

        // Datos
        $row = 2;
        foreach ($rows as $r) {
            $ws->setCellValue('A'.$row, $r['categoria']);
            $ws->setCellValue('B'.$row, (int) $r['total']);
            $ws->getStyle('A'.$row)->applyFromArray($styleData);
            $ws->getStyle('B'.$row)->applyFromArray($styleNumber);
            $row++;
        }
        $dataRows = $row - 2;

        if ($dataRows < 1) return;

        $sheetName = $ws->getTitle();

        // ── Gráfica ──────────────────────────────────────────────────────
        $labelsSeries = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$sheetName}'!\$A\$1", null, 1
        );
        $categorySeries = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$sheetName}'!\$A\$2:\$A\$".($dataRows + 1), null, $dataRows
        );
        $valueSeries = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheetName}'!\$B\$2:\$B\$".($dataRows + 1), null, $dataRows
        );

        $barDir   = ($chartType === 'bar') ? DataSeries::DIRECTION_BAR : null;
        $grouping = ($chartType === 'bar') ? DataSeries::GROUPING_CLUSTERED : null;
        $chartTypeConst = ($chartType === 'pie') ? DataSeries::TYPE_PIECHART : DataSeries::TYPE_BARCHART;

        $series = new DataSeries(
            $chartTypeConst,
            $grouping,
            range(0, 0),
            array($labelsSeries),
            array($categorySeries),
            array($valueSeries)
        );

        $plotArea = new PlotArea(null, array($series));
        $legend   = new Legend(Legend::POSITION_BOTTOM, null, false);
        $title    = new Title($chartTitle);

        $chart = new Chart(
            'chart_'.preg_replace('/[^a-z0-9]/i', '_', $sheetTitle),
            $title,
            $legend,
            $plotArea,
            true, 0, null, null
        );

        // Posición: columna D fila 1, ancho 12 cols × 20 filas
        $chart->setTopLeftPosition('D1');
        $chart->setBottomRightPosition('P20');

        $ws->addChart($chart);
    };

    switch ($type) {
        // ── PACIENTES ────────────────────────────────────────────────────
        case 'patients':
            $filename = 'pacientes_estadisticas_'.$dateStr.'.xlsx';
            $dimensions = array(
                'eps'                => array('EPS',                    'Pacientes por EPS'),
                'medicamento'        => array('Medicamento',            'Pacientes por Medicamento'),
                'operador_logistico' => array('Operador Logístico',     'Pacientes por Operador'),
                'estado_del_paciente'=> array('Estado Paciente',        'Estado del Paciente'),
                'programa'           => array('Programa',              'Pacientes por Programa'),
                'diagnostico'        => array('Diagnóstico',           'Distribución Diagnóstico'),
                'tipo_de_poblacion'  => array('Tipo de Población',     'Tipo de Población'),
                'regimen'            => array('Régimen',               'Distribución Régimen'),
                'tipo_de_afiliacion' => array('Tipo de Afiliación',    'Tipo de Afiliación'),
            );
            $first = true;
            foreach ($dimensions as $field => $info) {
                list($sheetTitle, $chartTitle) = $info;
                $dist = $engine->getPatientDistributionBy($field);
                $ctype = in_array($field, array('estado_del_paciente','tipo_de_poblacion','regimen','tipo_de_afiliacion'))
                    ? 'pie'
                    : 'bar';
                $addDistSheet($spreadsheet, $sheetTitle, $chartTitle, $dist, $sheetTitle, 'N° Pacientes', $ctype, $first);
                $first = false;
            }
            break;

        // ── CONSULTAS ────────────────────────────────────────────────────
        case 'consultations':
            $filename = 'consultas_'.$dateStr.'.xlsx';

            // Hoja 1: por tipo de atención
            $rows = $engine->getConsultationsByTipoAtencion();
            $dist = array_map(function($r){ return array('categoria'=>$r['tipo'], 'total'=>$r['total']); }, $rows);
            $addDistSheet($spreadsheet, 'Por Tipo de Atención', 'Consultas por Tipo', $dist, 'Tipo de Atención', 'Total Consultas', 'bar', true);

            // Hoja 2: consultas en el tiempo
            $ws2 = $spreadsheet->createSheet();
            $ws2->setTitle('Evolución Temporal');
            $ws2->setCellValue('A1', 'Período');
            $ws2->setCellValue('B1', 'Consultas');
            $ws2->getStyle('A1:B1')->applyFromArray($styleSubHeader);
            $ws2->getColumnDimension('A')->setWidth(20);
            $ws2->getColumnDimension('B')->setWidth(15);
            $overtime = $engine->getConsultationsOverTime('month');
            $r = 2;
            foreach ($overtime as $row) {
                $ws2->setCellValue('A'.$r, $row['periodo']);
                $ws2->setCellValue('B'.$r, (int)$row['total']);
                $ws2->getStyle('A'.$r)->applyFromArray($styleData);
                $ws2->getStyle('B'.$r)->applyFromArray($styleNumber);
                $r++;
            }
            $n = $r - 2;
            if ($n > 0) {
                $lbl  = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Evolución Temporal'!\$B\$1", null, 1);
                $cats = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Evolución Temporal'!\$A\$2:\$A\$".($n+1), null, $n);
                $vals = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Evolución Temporal'!\$B\$2:\$B\$".($n+1), null, $n);
                $series = new DataSeries(DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD, range(0,0), array($lbl), array($cats), array($vals));
                $plotArea = new PlotArea(null, array($series));
                $chart = new Chart('chart_evolucion', new Title('Evolución de Consultas'), new Legend(Legend::POSITION_BOTTOM, null, false), $plotArea, true, 0, null, null);
                $chart->setTopLeftPosition('D1');
                $chart->setBottomRightPosition('P20');
                $ws2->addChart($chart);
            }
            break;

        // ── ADHERENCIA ───────────────────────────────────────────────────
        case 'adherencia':
        default:
            $filename = 'adherencia_'.$dateStr.'.xlsx';
            $engine->setFilters(array_merge($cleanFilters, array('tipo_atencion' => 'adherencia')));
            $rows = $engine->getAdherenciaDistribution();
            $dist = array_map(function($r){ return array('categoria'=>$r['cumplimiento'], 'total'=>$r['total']); }, $rows);
            $addDistSheet($spreadsheet, 'Adherencia', 'Distribución de Adherencia', $dist, 'Cumplimiento', 'Total Consultas', 'pie', true);

            // Columna extra: pacientes únicos
            $ws = $spreadsheet->getActiveSheet();
            $ws->setCellValue('C1', 'Pacientes Únicos');
            $ws->getStyle('C1')->applyFromArray($styleSubHeader);
            $ws->getColumnDimension('C')->setWidth(18);
            $r = 2;
            foreach ($rows as $row) {
                $ws->setCellValue('C'.$r, (int)$row['pacientes_unicos']);
                $ws->getStyle('C'.$r)->applyFromArray($styleNumber);
                $r++;
            }
            break;
    }

    // ── Enviar XLSX ──────────────────────────────────────────────────────
    $spreadsheet->setActiveSheetIndex(0);
    $writer = new XlsxWriter($spreadsheet);
    $writer->setIncludeCharts(true);

    $safeName = preg_replace('/[^a-z0-9_.-]/i', '_', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$safeName.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $writer->save('php://output');
    exit;
}

// ─── Página de selección de exportación ───────────────────────────────────
$optMedicamentos  = $engine->getUniqueFieldValues('medicamento');
$optEps           = $engine->getUniqueFieldValues('eps');
$optOperadores    = $engine->getUniqueFieldValues('operador_logistico');
$optTipoPob       = $engine->getUniqueFieldValues('tipo_de_poblacion');
$optProgramas     = $engine->getUniqueFieldValues('programa');
$optEstados       = $engine->getUniqueFieldValues('estado_del_paciente');
$optTiposAtencion = $engine->getUniqueTiposAtencion();

llxHeader('', $langs->trans('Exportar'), '', '', 0, 0, array(), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'export', $langs->trans('Analiticas'), -1, 'export');
rcv_print_inline_styles();

print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/export.php', 1).'">';
print '<input type="hidden" name="action" value="export">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '</div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_select('filter_eps',                $langs->trans('EPS'),               $optEps,          $filters['eps'] ?? '');
rcv_print_filter_select('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos, $filters['medicamento'] ?? '');
rcv_print_filter_select('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,   $filters['operador_logistico'] ?? '');
rcv_print_filter_select('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPob,      $filters['tipo_de_poblacion'] ?? '');
print '<div class="rcv-filter-item"><label>'.$langs->trans('TipoAtencion').'</label>';
print '<select name="filter_tipo_atencion" class="flat">';
print '<option value="">-- '.$langs->trans('Todos').' --</option>';
foreach ($optTiposAtencion as $opt => $lbl) {
    $sel = (($filters['tipo_atencion'] ?? '') === (string)$opt) ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($opt).'"'.$sel.'>'.dol_escape_htmltag($lbl).'</option>';
}
print '</select></div>';
rcv_print_filter_select('filter_programa',           $langs->trans('Programa'),          $optProgramas,    $filters['programa'] ?? '');
rcv_print_filter_select('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,      $filters['estado_del_paciente'] ?? '');
print '</div></div>';

print '<div style="margin:16px 0 8px">';
print '<h4 style="margin:0 0 8px">'.$langs->trans('TipoExportacion').'</h4>';
print '<div style="display:flex;gap:12px;flex-wrap:wrap">';

$exports = array(
    'patients'      => array('icon' => '👥', 'label' => 'Pacientes',  'desc' => '9 hojas: EPS, Medicamento, Operador, Estado, Programa, Diagnóstico, Población, Régimen, Afiliación'),
    'consultations' => array('icon' => '📋', 'label' => 'Consultas',  'desc' => '2 hojas: Distribución por tipo + Evolución temporal'),
    'adherencia'    => array('icon' => '📊', 'label' => 'Adherencia', 'desc' => '1 hoja: Cumplimiento + Pacientes únicos'),
);
foreach ($exports as $val => $info) {
    print '<label style="display:flex;align-items:flex-start;gap:8px;padding:12px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;min-width:220px">';
    print '<input type="radio" name="type" value="'.dol_escape_htmltag($val).'" style="margin-top:2px">';
    print '<span><strong>'.$info['icon'].' '.$info['label'].'</strong><br><small style="color:#6b7280">'.$info['desc'].'</small></span>';
    print '</label>';
}
print '</div></div>';

print '<div style="margin-top:12px">';
print '<input type="submit" class="butAction" value="⬇ '.$langs->trans('DescargarXLSX').'">';
print ' <a class="butActionDelete" href="'.dol_buildpath('/rcv_analytics/index.php', 1).'">'.$langs->trans('Volver').'</a>';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/rcv_analytics/class/rcvanalyticsengine.class.php');
dol_include_once('/rcv_analytics/lib/rcv_analytics.lib.php');

$langs->loadLangs(array("companies", "rcv_analytics@rcv_analytics"));

if (!$user->admin && !$user->hasRight('rcv_analytics', 'export')) accessforbidden();

$form   = new Form($db);
$engine = new RcvAnalyticsEngine($db);

$action              = GETPOST('action', 'aZ09');
$exportType          = GETPOST('type', 'alpha');
$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha');

// Re-use timestamps already computed in the first block (same request scope)
$filters = array();
if (!$button_removefilter) {
    $filters['date_start']         = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['date_end']           = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
    $filters['medicamento']        = GETPOST('filter_medicamento', 'alpha');
    $filters['eps']                = GETPOST('filter_eps', 'alpha');
    $filters['operador_logistico'] = GETPOST('filter_operador_logistico', 'alpha');
    $filters['tipo_de_poblacion']  = GETPOST('filter_tipo_de_poblacion', 'alpha');
    $filters['tipo_atencion']      = GETPOST('filter_tipo_atencion', 'alpha');
    $filters['programa']           = GETPOST('filter_programa', 'alpha');
    $filters['diagnostico']        = GETPOST('filter_diagnostico', 'alpha');
    $filters['ips_primaria']       = GETPOST('filter_ips_primaria', 'alpha');
    $filters['estado_del_paciente']= GETPOST('filter_estado_del_paciente', 'alpha');
}

$cleanFilters = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
$engine->setFilters($cleanFilters);

// ─── Exportar CSV directo ──────────────────────────────────────────────────
if ($action === 'export' || !empty($exportType)) {
    $type = $exportType ?: GETPOST('export_type', 'alpha');

    switch ($type) {
        case 'patients':
            // Exportación anonimizada: estadísticas agregadas por cada dimensión clave.
            // No se exportan datos individuales de pacientes.
            $filename = 'pacientes_estadisticas_'.dol_print_date(dol_now(), 'dayrfc').'.csv';
            $dimensions = array(
                'eps'                => 'EPS',
                'medicamento'        => 'Medicamento',
                'operador_logistico' => 'Operador Logístico',
                'estado_del_paciente'=> 'Estado Paciente',
                'programa'           => 'Programa',
                'diagnostico'        => 'Diagnóstico',
                'tipo_de_poblacion'  => 'Tipo de Población',
                'regimen'            => 'Régimen',
                'tipo_de_afiliacion' => 'Tipo de Afiliación',
            );
            $headers  = array('Dimensión', 'Categoría', 'N° Pacientes');
            $dataRows = array();
            foreach ($dimensions as $field => $dimLabel) {
                $dist = $engine->getPatientDistributionBy($field);
                foreach ($dist as $r) {
                    $dataRows[] = array($dimLabel, $r['categoria'], (int)$r['total']);
                }
                if (!empty($dist)) {
                    $dataRows[] = array('', '', ''); // separador visual
                }
            }
            break;

        case 'adherencia':
            $engine->setFilters(array_merge($cleanFilters, array('tipo_atencion' => 'adherencia')));
            $rows = $engine->getAdherenciaDistribution();
            $filename = 'adherencia_'.dol_print_date(dol_now(), 'dayrfc').'.csv';
            $headers  = array('Cumplimiento', 'Total Consultas', 'Pacientes Únicos');
            $dataRows = array_map(function ($r) {
                return array($r['cumplimiento'], $r['total'], $r['pacientes_unicos']);
            }, $rows);
            break;

        case 'consultations':
        default:
            $rows = $engine->getConsultationsByTipoAtencion();
            $filename = 'consultas_por_tipo_'.dol_print_date(dol_now(), 'dayrfc').'.csv';
            $headers  = array('Tipo de Atención', 'Total Consultas');
            $dataRows = array_map(function ($r) {
                return array($r['tipo'], $r['total']);
            }, $rows);
            break;
    }

    // Enviar CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-z0-9_.-]/i', '_', $filename).'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para compatibilidad con Excel
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');
    foreach ($dataRows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

// ─── Página de selección de exportación ───────────────────────────────────
$optMedicamentos = $engine->getUniqueFieldValues('medicamento');
$optEps          = $engine->getUniqueFieldValues('eps');
$optOperadores   = $engine->getUniqueFieldValues('operador_logistico');
$optTipoPob      = $engine->getUniqueFieldValues('tipo_de_poblacion');
$optProgramas    = $engine->getUniqueFieldValues('programa');
$optEstados      = $engine->getUniqueFieldValues('estado_del_paciente');
$optTiposAtencion = $engine->getUniqueTiposAtencion();

llxHeader('', $langs->trans('Exportar'), '', '', 0, 0, array(), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'export', $langs->trans('Analiticas'), -1, 'export');
rcv_print_inline_styles();

print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/export.php', 1).'">';
print '<input type="hidden" name="action" value="export">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '</div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_select('filter_eps',                $langs->trans('EPS'),               $optEps,          $filters['eps'] ?? '');
rcv_print_filter_select('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos, $filters['medicamento'] ?? '');
rcv_print_filter_select('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,   $filters['operador_logistico'] ?? '');
rcv_print_filter_select('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPob,      $filters['tipo_de_poblacion'] ?? '');
print '<div class="rcv-filter-item"><label>'.$langs->trans('TipoAtencion').'</label>';
print '<select name="filter_tipo_atencion" class="flat">';
print '<option value="">-- '.$langs->trans('Todos').' --</option>';
foreach ($optTiposAtencion as $opt) {
    $sel = (($filters['tipo_atencion'] ?? '') === $opt) ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($opt).'"'.$sel.'>'.dol_escape_htmltag($opt).'</option>';
}
print '</select></div>';
rcv_print_filter_select('filter_programa',           $langs->trans('Programa'),          $optProgramas,    $filters['programa'] ?? '');
rcv_print_filter_select('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,      $filters['estado_del_paciente'] ?? '');
print '</div></div>';

// Tipo de exportación
print '<div style="margin:16px 0">';
print '<h4>'.$langs->trans('TipoExportacion').'</h4>';
print '<select name="type" class="flat minwidth200">';
print '<option value="patients">'.$langs->trans('ExportarPacientes').'</option>';
print '<option value="consultations">'.$langs->trans('ExportarConsultas').'</option>';
print '<option value="adherencia">'.$langs->trans('ExportarAdherencia').'</option>';
print '</select>';
print '</div>';

print '<div>';
print '<input type="submit" class="butAction" value="'.$langs->trans('DescargarCSV').'">';
print ' <a class="butActionRefused" href="'.dol_buildpath('/rcv_analytics/index.php', 1).'">'.$langs->trans('Volver').'</a>';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
