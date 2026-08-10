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
    $filters['tipo_atencion']      = GETPOST('filter_tipo_atencion', 'array');
    $filters['estado_consulta']    = GETPOST('filter_estado_consulta', 'array');
    $filters['eps']                = GETPOST('filter_eps', 'array');
    $filters['medicamento']        = GETPOST('filter_medicamento', 'array');
    $filters['operador_logistico'] = GETPOST('filter_operador_logistico', 'array');
    $filters['tipo_de_poblacion']  = GETPOST('filter_tipo_de_poblacion', 'array');
    $filters['programa']           = GETPOST('filter_programa', 'array');
    $filters['diagnostico']        = GETPOST('filter_diagnostico', 'array');
    $filters['ips_primaria']       = GETPOST('filter_ips_primaria', 'array');
    $filters['estado_del_paciente']= GETPOST('filter_estado_del_paciente', 'array');
    $filters['regimen']            = GETPOST('filter_regimen', 'array');
    $filters['medico_tratante']    = GETPOST('filter_medico_tratante', 'array');
    $filters['departamento']       = GETPOST('filter_departamento', 'array');
    $filters['ciudad']             = GETPOST('filter_ciudad', 'array');
}

$cleanFilters = array_filter($filters, function ($v) {
    if (is_array($v)) return !empty(array_filter($v, 'strlen'));
    return $v !== '' && $v !== null;
});
$engine->setFilters($cleanFilters);

// ─── Exportar XLSX ─────────────────────────────────────────────────────────
if ($action === 'export' || !empty($exportType)) {
    $type = $exportType ?: GETPOST('export_type', 'alpha');
    // Agrupación temporal: llega del formulario de Consultas para que las hojas
    // de evolución coincidan con lo que el usuario ve en pantalla.
    $groupBy = GETPOST('filter_groupby', 'alpha');
    if (!in_array($groupBy, array('month', 'week', 'year'), true)) $groupBy = 'month';

    // Descripción legible de los filtros activos (se vuelca en la hoja "Filtros").
    // Sólo se resuelven los catálogos de los filtros realmente usados.
    $filterOptionMaps = array();
    foreach (array('eps', 'medicamento', 'operador_logistico', 'tipo_de_poblacion',
                   'programa', 'diagnostico', 'ips_primaria', 'estado_del_paciente',
                   'regimen', 'medico_tratante') as $fkey) {
        if (isset($cleanFilters[$fkey])) $filterOptionMaps[$fkey] = $engine->getUniqueFieldValues($fkey);
    }
    if (isset($cleanFilters['departamento']))    $filterOptionMaps['departamento']    = $engine->getUniqueDepartamentos();
    if (isset($cleanFilters['ciudad']))          $filterOptionMaps['ciudad']          = $engine->getUniqueCiudades();
    if (isset($cleanFilters['estado_consulta'])) $filterOptionMaps['estado_consulta'] = $engine->getConsultationStatusLabels();
    $filterDesc = rcv_describe_filters($cleanFilters, $filterOptionMaps);

    // Las exportaciones de paciente filtran por fecha de creación del paciente
    // (s.datec); las de consulta, por fecha de la consulta (c.date_start).
    // Sin este remapeo buildWhere(false) descartaría el rango de fechas.
    if ($type === 'patients' || $type === 'patients_list') {
        if (!empty($cleanFilters['date_start'])) {
            $cleanFilters['patient_date_start'] = $cleanFilters['date_start'];
            unset($cleanFilters['date_start']);
        }
        if (!empty($cleanFilters['date_end'])) {
            $cleanFilters['patient_date_end'] = $cleanFilters['date_end'];
            unset($cleanFilters['date_end']);
        }
        $engine->setFilters($cleanFilters);
    }

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

        // ── LISTADO PLANO DE PACIENTES ────────────────────────────────────
        case 'patients_list':
            $filename = 'pacientes_'.$dateStr.'.xlsx';
            $ws = $spreadsheet->getActiveSheet();
            $ws->setTitle('Pacientes');

            $columns = array(
                'nombre'               => 'Nombre',
                'tipo_de_documento'    => 'Tipo Documento',
                'n_documento'          => 'N° Documento',
                'birthdate'            => 'Fecha Nacimiento',
                'email'                => 'Email',
                'phone'                => 'Teléfono',
                'eps'                  => 'EPS',
                'regimen'              => 'Régimen',
                'tipo_de_afiliacion'   => 'Tipo Afiliación',
                'medicamento'          => 'Medicamento',
                'concentracion'        => 'Concentración',
                'operador_logistico'   => 'Operador Logístico',
                'sede_operador_logistico' => 'Sede Operador',
                'programa'             => 'Programa',
                'estado_del_paciente'  => 'Estado Paciente',
                'estado_vital'         => 'Estado Vital',
                'ips_primaria'         => 'IPS Primaria',
                'medico_tratante'      => 'Médico Tratante',
                'tipo_de_poblacion'    => 'Tipo Población',
                'diagnostico'          => 'Diagnóstico',
                'departamento'         => 'Departamento',
                'ciudad'               => 'Ciudad',
                'fecha_creacion'       => 'Fecha Creación',
                'total_consultas'      => 'Total Consultas',
                'ultima_consulta'      => 'Última Consulta',
            );

            // Encabezados
            $col = 1;
            foreach ($columns as $label) {
                $ws->setCellValueByColumnAndRow($col, 1, $label);
                $col++;
            }
            $ws->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)) . '1')
               ->applyFromArray($styleHeader);

            // Datos (el remapeo de fechas a patient_date_* ya se aplicó arriba)
            $patients = $engine->getPatientsForExport();
            $row = 2;
            foreach ($patients as $p) {
                $col = 1;
                foreach (array_keys($columns) as $field) {
                    $val = $p[$field] ?? '';
                    if ($field === 'fecha_creacion' || $field === 'ultima_consulta') {
                        $val = $val ? dol_print_date($db->jdate($val), 'dayrfc') : '';
                    } elseif ($field === 'birthdate') {
                        $val = $val ? dol_print_date($db->jdate($val), 'dayrfc') : '';
                    }
                    $ws->setCellValueByColumnAndRow($col, $row, $val);
                    if ($field === 'total_consultas') {
                        $ws->getStyleByColumnAndRow($col, $row)->applyFromArray($styleNumber);
                    } else {
                        $ws->getStyleByColumnAndRow($col, $row)->applyFromArray($styleData);
                    }
                    $col++;
                }
                // Alternar color de fila
                if ($row % 2 === 0) {
                    $ws->getStyle('A'.$row.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)).$row)
                       ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                       ->getStartColor()->setARGB('FFF8FAFC');
                }
                $row++;
            }

            // Ajuste automático de ancho de columnas
            foreach (range(1, count($columns)) as $c) {
                $ws->getColumnDimensionByColumn($c)->setAutoSize(true);
            }

            // Fijar fila de encabezado
            $ws->freezePane('A2');
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
            $overtime = $engine->getConsultationsOverTime($groupBy);
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

            // Hojas 3..N: mismas distribuciones que muestra la página de Consultas
            $consDims = array(
                'eps'             => array('Por EPS',        'Consultas por EPS'),
                'programa'        => array('Por Programa',   'Consultas por Programa'),
                'medico_tratante' => array('Por Médico',     'Consultas por Médico Tratante'),
                'regimen'         => array('Por Régimen',    'Consultas por Régimen'),
                'ips_primaria'    => array('Por IPS',        'Consultas por IPS Primaria'),
            );
            foreach ($consDims as $field => $info) {
                list($sheetTitle, $chartTitle) = $info;
                $ctype = ($field === 'regimen') ? 'pie' : 'bar';
                $addDistSheet($spreadsheet, $sheetTitle, $chartTitle,
                    $engine->getConsultationsByPatientField($field), $chartTitle, 'Total Consultas', $ctype);
            }
            $addDistSheet($spreadsheet, 'Por Departamento', 'Consultas por Departamento',
                $engine->getConsultationsByDepartamento(), 'Departamento', 'Total Consultas');
            $addDistSheet($spreadsheet, 'Por Ciudad', 'Consultas por Ciudad',
                $engine->getConsultationsByCiudad(), 'Ciudad', 'Total Consultas');

            // Hoja de gestores: incluye la columna extra de pacientes únicos
            $gestores = $engine->getConsultationsByGestor();
            if (!empty($gestores)) {
                $distG = array_map(function ($g) {
                    return array('categoria' => $g['gestor'], 'total' => $g['total']);
                }, $gestores);
                $addDistSheet($spreadsheet, 'Por Gestor', 'Consultas por Gestor', $distG, 'Gestor', 'Total Consultas');
                $wsG = $spreadsheet->getSheetByName('Por Gestor');
                if ($wsG) {
                    $wsG->setCellValue('C1', 'Pacientes Atendidos');
                    $wsG->getStyle('C1')->applyFromArray($styleSubHeader);
                    $wsG->getColumnDimension('C')->setWidth(20);
                    $rg = 2;
                    foreach ($gestores as $g) {
                        $wsG->setCellValue('C'.$rg, (int) $g['pacientes_unicos']);
                        $wsG->getStyle('C'.$rg)->applyFromArray($styleNumber);
                        $rg++;
                    }
                }
            }

            // Hoja tabla cruzada: tipo de atención × período
            $cross = $engine->getConsultationsCrossTable('tipo_atencion', $groupBy);
            if (!empty($cross)) {
                $tiposSet = array(); $periodosSet = array(); $matrix = array();
                foreach ($cross as $row) {
                    $tiposSet[$row['categoria']]  = true;
                    $periodosSet[$row['periodo']] = true;
                    $matrix[$row['periodo']][$row['categoria']] = (int) $row['total'];
                }
                $tiposList   = array_keys($tiposSet);
                $periodoList = array_keys($periodosSet);

                $wsX = $spreadsheet->createSheet();
                $wsX->setTitle('Tipo x Periodo');
                $wsX->setCellValue('A1', 'Período');
                $wsX->getColumnDimension('A')->setWidth(18);
                $col = 2;
                foreach ($tiposList as $tipo) {
                    $wsX->setCellValueByColumnAndRow($col, 1, $tipo);
                    $wsX->getColumnDimensionByColumn($col)->setWidth(18);
                    $col++;
                }
                $wsX->setCellValueByColumnAndRow($col, 1, 'Total');
                $wsX->getColumnDimensionByColumn($col)->setWidth(14);
                $wsX->getStyle('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'1')
                    ->applyFromArray($styleHeader);

                $rx = 2;
                $colTotals = array_fill_keys($tiposList, 0);
                foreach ($periodoList as $periodo) {
                    $wsX->setCellValue('A'.$rx, $periodo);
                    $wsX->getStyle('A'.$rx)->applyFromArray($styleData);
                    $c = 2; $rowTotal = 0;
                    foreach ($tiposList as $tipo) {
                        $val = $matrix[$periodo][$tipo] ?? 0;
                        $rowTotal += $val;
                        $colTotals[$tipo] += $val;
                        $wsX->setCellValueByColumnAndRow($c, $rx, $val);
                        $wsX->getStyleByColumnAndRow($c, $rx)->applyFromArray($styleNumber);
                        $c++;
                    }
                    $wsX->setCellValueByColumnAndRow($c, $rx, $rowTotal);
                    $wsX->getStyleByColumnAndRow($c, $rx)->applyFromArray($styleNumber);
                    $rx++;
                }
                // Fila de totales por columna
                $wsX->setCellValue('A'.$rx, 'Total');
                $c = 2; $grandTotal = 0;
                foreach ($tiposList as $tipo) {
                    $grandTotal += $colTotals[$tipo];
                    $wsX->setCellValueByColumnAndRow($c, $rx, $colTotals[$tipo]);
                    $c++;
                }
                $wsX->setCellValueByColumnAndRow($c, $rx, $grandTotal);
                $wsX->getStyle('A'.$rx.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$rx)
                    ->applyFromArray($styleSubHeader);
                $wsX->freezePane('B2');
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

    // ── Hoja "Filtros": deja constancia del alcance de los datos exportados ──
    $wsF = $spreadsheet->createSheet();
    $wsF->setTitle('Filtros');
    $wsF->setCellValue('A1', 'Filtro');
    $wsF->setCellValue('B1', 'Valor');
    $wsF->getStyle('A1:B1')->applyFromArray($styleSubHeader);
    $wsF->getColumnDimension('A')->setWidth(26);
    $wsF->getColumnDimension('B')->setWidth(80);
    $wsF->getStyle('B')->getAlignment()->setWrapText(true);

    $rf = 2;
    if (empty($filterDesc)) {
        $wsF->setCellValue('A'.$rf, 'Ninguno');
        $wsF->setCellValue('B'.$rf, 'Se exportaron todos los datos disponibles');
        $wsF->getStyle('A'.$rf.':B'.$rf)->applyFromArray($styleData);
        $rf++;
    } else {
        foreach ($filterDesc as $fd) {
            $wsF->setCellValue('A'.$rf, $fd[0]);
            $wsF->setCellValue('B'.$rf, $fd[1]);
            $wsF->getStyle('A'.$rf.':B'.$rf)->applyFromArray($styleData);
            $rf++;
        }
    }
    $rf++;
    $wsF->setCellValue('A'.$rf, 'Generado');
    $wsF->setCellValue('B'.$rf, dol_print_date(dol_now(), 'dayhourrfc'));
    $wsF->setCellValue('A'.($rf + 1), 'Usuario');
    $wsF->setCellValue('B'.($rf + 1), $user->getFullName($langs));

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
$optTiposAtencion = $engine->getUniqueTiposAtencion();
$optEstadoConsulta = $engine->getConsultationStatusLabels();
$optMedicamentos  = $engine->getUniqueFieldValues('medicamento');
$optEps           = $engine->getUniqueFieldValues('eps');
$optOperadores    = $engine->getUniqueFieldValues('operador_logistico');
$optTipoPob       = $engine->getUniqueFieldValues('tipo_de_poblacion');
$optProgramas     = $engine->getUniqueFieldValues('programa');
$optDiagnosticos  = $engine->getUniqueFieldValues('diagnostico');
$optEstados       = $engine->getUniqueFieldValues('estado_del_paciente');
$optIps           = $engine->getUniqueFieldValues('ips_primaria');
$optRegimenes     = $engine->getUniqueFieldValues('regimen');
$optMedicos       = $engine->getUniqueFieldValues('medico_tratante');
$optDepartamentos = $engine->getUniqueDepartamentos();
$optCiudades      = $engine->getUniqueCiudades();

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
rcv_print_filter_multisel('filter_tipo_atencion',      $langs->trans('TipoAtencion'),      $optTiposAtencion, $filters['tipo_atencion'] ?? array());
rcv_print_filter_multisel('filter_estado_consulta',    $langs->trans('EstadoConsulta'),    $optEstadoConsulta,$filters['estado_consulta'] ?? array());
rcv_print_filter_multisel('filter_eps',                $langs->trans('EPS'),               $optEps,           $filters['eps'] ?? array());
rcv_print_filter_multisel('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos,  $filters['medicamento'] ?? array());
rcv_print_filter_multisel('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,    $filters['operador_logistico'] ?? array());
rcv_print_filter_multisel('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPob,       $filters['tipo_de_poblacion'] ?? array());
rcv_print_filter_multisel('filter_programa',           $langs->trans('Programa'),          $optProgramas,     $filters['programa'] ?? array());
rcv_print_filter_multisel('filter_diagnostico',        $langs->trans('Diagnostico'),       $optDiagnosticos,  $filters['diagnostico'] ?? array());
rcv_print_filter_multisel('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,       $filters['estado_del_paciente'] ?? array());
rcv_print_filter_multisel('filter_ips_primaria',       $langs->trans('IPSPrimaria'),       $optIps,           $filters['ips_primaria'] ?? array());
rcv_print_filter_multisel('filter_regimen',            $langs->trans('Regimen'),           $optRegimenes,     $filters['regimen'] ?? array());
rcv_print_filter_multisel('filter_medico_tratante',    $langs->trans('MedicoTratante'),    $optMedicos,       $filters['medico_tratante'] ?? array());
rcv_print_filter_multisel('filter_departamento',       $langs->trans('Departamento'),      $optDepartamentos, $filters['departamento'] ?? array());
rcv_print_filter_multisel('filter_ciudad',             $langs->trans('Ciudad'),            $optCiudades,      $filters['ciudad'] ?? array());
print '</div>';
print '<div class="rcv-filter-actions">';
print '<a class="butActionDelete" href="'.dol_buildpath('/rcv_analytics/export.php', 1).'">'.$langs->trans('LimpiarFiltros').'</a>';
print '</div>';
print '</div>';

print '<div style="margin:16px 0 8px">';
print '<h4 style="margin:0 0 8px">'.$langs->trans('TipoExportacion').'</h4>';
print '<div style="display:flex;gap:12px;flex-wrap:wrap">';

$exports = array(
    'patients_list' => array('icon' => '👥', 'label' => 'Listado de Pacientes', 'desc' => '1 hoja: una fila por paciente con todos sus datos'),
    'patients'      => array('icon' => '📊', 'label' => 'Estadísticas Pacientes',  'desc' => '9 hojas: EPS, Medicamento, Operador, Estado, Programa, Diagnóstico, Población, Régimen, Afiliación'),
    'consultations' => array('icon' => '📋', 'label' => 'Consultas',  'desc' => '2 hojas: Distribución por tipo + Evolución temporal'),
    'adherencia'    => array('icon' => '📈', 'label' => 'Adherencia', 'desc' => '1 hoja: Cumplimiento + Pacientes únicos'),
);
$selectedType = array_key_exists($exportType, $exports) ? $exportType : 'patients_list';
foreach ($exports as $val => $info) {
    print '<label style="display:flex;align-items:flex-start;gap:8px;padding:12px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;min-width:220px">';
    print '<input type="radio" name="type" value="'.dol_escape_htmltag($val).'"'.($val === $selectedType ? ' checked' : '').' style="margin-top:2px">';
    print '<span><strong>'.$info['icon'].' '.$info['label'].'</strong><br><small style="color:#6b7280">'.$info['desc'].'</small></span>';
    print '</label>';
}
print '</div></div>';

print '<div style="margin-top:12px">';
print '<input type="submit" class="butAction" value="⬇ '.$langs->trans('DescargarXLSX').'">';
print ' <a class="butActionDelete" href="'.dol_buildpath('/rcv_analytics/index.php', 1).'">'.$langs->trans('Volver').'</a>';
print '</div>';
print '</form>';
rcv_print_multisel_js();

print dol_get_fiche_end();
llxFooter();
$db->close();
