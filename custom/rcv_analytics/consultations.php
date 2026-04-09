<?php
/* Copyright (C) 2024 DatiLab
 * Página: analíticas de consultas extendidas
 */

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

if (!$user->admin && !$user->hasRight('rcv_analytics', 'read')) accessforbidden();

$form   = new Form($db);
$engine = new RcvAnalyticsEngine($db);

$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha');

$_date_start_ts = $button_removefilter ? 0 : dol_mktime(0, 0, 0, GETPOSTINT('filter_date_startmonth'), GETPOSTINT('filter_date_startday'), GETPOSTINT('filter_date_startyear'));
$_date_end_ts   = $button_removefilter ? 0 : dol_mktime(23, 59, 59, GETPOSTINT('filter_date_endmonth'), GETPOSTINT('filter_date_endday'), GETPOSTINT('filter_date_endyear'));

$filters = array();
if (!$button_removefilter) {
    $filters['date_start']         = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['date_end']           = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
    $filters['tipo_atencion']      = GETPOST('filter_tipo_atencion', 'array');
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
    $filters['groupby']            = GETPOST('filter_groupby', 'alpha') ?: 'month';
}
$groupBy = $filters['groupby'] ?? 'month';

$cleanFilters = array_filter($filters, function ($v) {
    if (is_array($v)) return !empty(array_filter($v, 'strlen'));
    return $v !== '' && $v !== null;
});
$engine->setFilters($cleanFilters);

$kpis           = $engine->getKpis();
$consByTipo     = $engine->getConsultationsByTipoAtencion();
$consTime       = $engine->getConsultationsOverTime($groupBy);
$crossTipoMes   = $engine->getConsultationsCrossTable('tipo_atencion', $groupBy);
$consByEps      = $engine->getConsultationsByPatientField('eps');
$consByPrograma = $engine->getConsultationsByPatientField('programa');
$consByMedico   = $engine->getConsultationsByPatientField('medico_tratante');
$consByRegimen  = $engine->getConsultationsByPatientField('regimen');
$consByIps      = $engine->getConsultationsByPatientField('ips_primaria');
$consByDepto    = $engine->getConsultationsByDepartamento();
$consByCiudad   = $engine->getConsultationsByCiudad();
$consByGestor   = $engine->getConsultationsByGestor();

$optTiposAtencion = $engine->getUniqueTiposAtencion();
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

llxHeader('', $langs->trans('ConsultasAnaliticas'), '', '', 0, 0, array('/includes/nnnick/chartjs/dist/chart.min.js', '/rcv_analytics/js/charts.min.js'), array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'consultations', $langs->trans('Analiticas'), -1, 'action');
rcv_print_inline_styles();
print '<div class="rcv-wrap">';

// ─── Filtros responsive ─────────────────────────────────────────────────
print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/consultations.php', 1).'">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('AgruparPor').'</label>';
print '<select name="filter_groupby" class="flat">';
foreach (array('month' => 'Mes', 'week' => 'Semana', 'year' => 'Año') as $val => $lbl) {
    $sel = ($groupBy === $val) ? ' selected' : '';
    print '<option value="'.$val.'"'.$sel.'>'.$lbl.'</option>';
}
print '</select></div>';
print '</div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_multisel('filter_tipo_atencion',      $langs->trans('TipoAtencion'),      $optTiposAtencion, $filters['tipo_atencion'] ?? array());
rcv_print_filter_multisel('filter_eps',                $langs->trans('EPS'),               $optEps,           $filters['eps'] ?? array());
rcv_print_filter_multisel('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos,  $filters['medicamento'] ?? array());
rcv_print_filter_multisel('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,    $filters['operador_logistico'] ?? array());
rcv_print_filter_multisel('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPob,       $filters['tipo_de_poblacion'] ?? array());
rcv_print_filter_multisel('filter_programa',           $langs->trans('Programa'),          $optProgramas,     $filters['programa'] ?? array());
rcv_print_filter_multisel('filter_diagnostico',        $langs->trans('Diagnostico'),       $optDiagnosticos,  $filters['diagnostico'] ?? array());
rcv_print_filter_multisel('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,       $filters['estado_del_paciente'] ?? array());
rcv_print_filter_multisel('filter_departamento',       $langs->trans('Departamento'),      $optDepartamentos, $filters['departamento'] ?? array());
rcv_print_filter_multisel('filter_ciudad',             $langs->trans('Ciudad'),            $optCiudades,      $filters['ciudad'] ?? array());
rcv_print_filter_multisel('filter_ips_primaria',       $langs->trans('IPSPrimaria'),       $optIps,           $filters['ips_primaria'] ?? array());
rcv_print_filter_multisel('filter_regimen',            $langs->trans('Regimen'),           $optRegimenes,     $filters['regimen'] ?? array());
rcv_print_filter_multisel('filter_medico_tratante',    $langs->trans('MedicoTratante'),    $optMedicos,       $filters['medico_tratante'] ?? array());
print '</div>';
print '<div class="rcv-filter-actions">';
print '<input type="submit" class="butAction" name="button_search" value="'.$langs->trans('Filtrar').'">';
print '<input type="submit" class="butActionDelete" name="button_removefilter" value="'.$langs->trans('LimpiarFiltros').'">';
print '</div></div></form>';
rcv_print_multisel_js();

// ─── KPIs ──────────────────────────────────────────────────────────────────
print '<div class="rcv-kpi-row">';
rcv_kpi_card($langs->trans('TotalPacientes'), $kpis['total_pacientes'], 'user', '#2563eb');
rcv_kpi_card($langs->trans('TotalConsultas'), $kpis['total_consultas'], 'action', '#16a34a');
rcv_kpi_card($langs->trans('PacientesConConsulta'), $kpis['pacientes_con_consulta'], 'user', '#9333ea');
print '</div>';

// ─── Gráficas ──────────────────────────────────────────────────────────────
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorTipoAtencion').'</h3><canvas id="chartTipo"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorPeriodo').'</h3><canvas id="chartTime"></canvas></div>';
print '</div>';
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorEPS').'</h3><canvas id="chartEps"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorPrograma').'</h3><canvas id="chartPrograma"></canvas></div>';
print '</div>';
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorMedicoTratante').'</h3><canvas id="chartMedico"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorGestor').'</h3><canvas id="chartGestor"></canvas></div>';
print '</div>';
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorRegimen').'</h3><canvas id="chartRegimen"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorIPS').'</h3><canvas id="chartIps"></canvas></div>';
print '</div>';
print '<div class="rcv-charts-row">';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorDepartamento').'</h3><canvas id="chartDepto"></canvas></div>';
print '<div class="rcv-chart-box"><h3>'.$langs->trans('ConsultasPorCiudad').'</h3><canvas id="chartCiudad"></canvas></div>';
print '</div>';

// ─── Tabla de Gestores ────────────────────────────────────────────────────
if (!empty($consByGestor)) {
    print '<h4 class="rcv-section-title">'.$langs->trans('ConsultasPorGestor').'</h4>';
    print '<div class="rcv-table-wrapper"><table class="noborder liste">';
    print '<tr class="liste_titre"><th>'.$langs->trans('Gestor').'</th>';
    print '<th style="text-align:center">'.$langs->trans('TotalConsultas').'</th>';
    print '<th style="text-align:center">'.$langs->trans('PacientesAtendidos').'</th></tr>';
    foreach ($consByGestor as $row) {
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($row['gestor']).'</td>';
        print '<td style="text-align:center">'.(int)$row['total'].'</td>';
        print '<td style="text-align:center">'.(int)$row['pacientes_unicos'].'</td>';
        print '</tr>';
    }
    print '</table></div>';
}

// ─── Tabla cruzada: Tipo de atención × Periodo ────────────────────────────
if (!empty($crossTipoMes)) {
    print '<h4 class="rcv-section-title">'.$langs->trans('ConsultasPorTipoYPeriodo').'</h4>';
    $tiposSet   = array();
    $periodosSet = array();
    $matrix     = array();
    foreach ($crossTipoMes as $row) {
        $tiposSet[$row['categoria']] = true;
        $periodosSet[$row['periodo']] = true;
        $matrix[$row['periodo']][$row['categoria']] = (int) $row['total'];
    }
    $tiposList   = array_keys($tiposSet);
    $periodoList = array_keys($periodosSet);

    print '<div class="rcv-table-wrapper rcv-table-dense"><table class="noborder liste">';
    print '<tr class="liste_titre"><th>Período</th>';
    foreach ($tiposList as $tipo) {
        print '<th style="text-align:center">'.dol_escape_htmltag($tipo).'</th>';
    }
    print '<th style="text-align:center"><strong>Total</strong></th></tr>';

    $colTotals = array_fill_keys($tiposList, 0);
    $grandTotal = 0;
    foreach ($periodoList as $periodo) {
        $rowTotal = 0;
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($periodo).'</td>';
        foreach ($tiposList as $tipo) {
            $val = $matrix[$periodo][$tipo] ?? 0;
            $rowTotal += $val;
            $colTotals[$tipo] += $val;
            print '<td style="text-align:center">'.$val.'</td>';
        }
        $grandTotal += $rowTotal;
        print '<td style="text-align:center"><strong>'.$rowTotal.'</strong></td>';
        print '</tr>';
    }
    // Totales de columna
    print '<tr class="liste_titre"><td><strong>Total</strong></td>';
    foreach ($tiposList as $tipo) {
        print '<td style="text-align:center"><strong>'.$colTotals[$tipo].'</strong></td>';
    }
    print '<td style="text-align:center"><strong>'.$grandTotal.'</strong></td>';
    print '</tr>';
    print '</table></div>';

    // Stacked bar chart con los mismos datos
    $stackedData = array(
        'labels'   => $periodoList,
        'datasets' => array(),
    );
    $colors = array('#2563eb','#16a34a','#ea580c','#9333ea','#0891b2','#d97706','#dc2626','#059669');
    foreach ($tiposList as $i => $tipo) {
        $ds = array(
            'label' => $tipo,
            'data'  => array(),
            'backgroundColor' => $colors[$i % count($colors)],
        );
        foreach ($periodoList as $periodo) {
            $ds['data'][] = $matrix[$periodo][$tipo] ?? 0;
        }
        $stackedData['datasets'][] = $ds;
    }

    print '<div class="rcv-charts-row" style="margin-top:10px">';
    print '<div class="rcv-chart-box rcv-chart-wide"><h3>'.$langs->trans('ConsultasPorTipoYPeriodo').' — evolución</h3><canvas id="chartStacked"></canvas></div>';
    print '</div>';

    print '<script>
    document.addEventListener("DOMContentLoaded", function() {
        rcvRenderStackedBarChart("chartStacked", '.json_encode($stackedData).');
    });
    </script>';
}

$dataTipo      = rcv_chart_data($consByTipo,     'tipo',      'total');
$dataTime      = rcv_chart_data($consTime,       'periodo',   'total');
$dataEps       = rcv_chart_data($consByEps,      'categoria', 'total');
$dataPrograma  = rcv_chart_data($consByPrograma, 'categoria', 'total');
$dataMedico    = rcv_chart_data($consByMedico,   'categoria', 'total');
$dataGestor    = rcv_chart_data($consByGestor,   'gestor',    'total');
$dataRegimen   = rcv_chart_data($consByRegimen,  'categoria', 'total');
$dataIps       = rcv_chart_data($consByIps,      'categoria', 'total');
$dataDepto     = rcv_chart_data($consByDepto,    'categoria', 'total');
$dataCiudad    = rcv_chart_data($consByCiudad,   'categoria', 'total');

print '<script>
document.addEventListener("DOMContentLoaded", function() {
    rcvRenderDoughnutChart("chartTipo",    '.json_encode($dataTipo).');
    rcvRenderLineChart("chartTime",        '.json_encode($dataTime).', "Consultas");
    rcvRenderBarChart("chartEps",          '.json_encode($dataEps).', "Consultas por EPS");
    rcvRenderBarChart("chartPrograma",     '.json_encode($dataPrograma).', "Consultas por Programa");
    rcvRenderBarChart("chartMedico",       '.json_encode($dataMedico).', "Consultas por Médico");
    rcvRenderBarChart("chartGestor",       '.json_encode($dataGestor).', "Consultas por Gestor");
    rcvRenderDoughnutChart("chartRegimen", '.json_encode($dataRegimen).');
    rcvRenderBarChart("chartIps",          '.json_encode($dataIps).', "Consultas por IPS");
    rcvRenderBarChart("chartDepto",        '.json_encode($dataDepto).', "Consultas por Departamento");
    rcvRenderBarChart("chartCiudad",       '.json_encode($dataCiudad).', "Consultas por Ciudad");
});
</script>';

print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
