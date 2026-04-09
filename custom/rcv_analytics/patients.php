<?php
/* Copyright (C) 2024 DatiLab
 * Página: distribución estadística de pacientes (datos anonimizados - solo estadísticas)
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
    $filters['date_start']          = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['date_end']            = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
    $filters['medicamento']         = GETPOST('filter_medicamento', 'alpha');
    $filters['eps']                 = GETPOST('filter_eps', 'alpha');
    $filters['operador_logistico']  = GETPOST('filter_operador_logistico', 'alpha');
    $filters['tipo_de_poblacion']   = GETPOST('filter_tipo_de_poblacion', 'alpha');
    $filters['programa']            = GETPOST('filter_programa', 'alpha');
    $filters['diagnostico']         = GETPOST('filter_diagnostico', 'alpha');
    $filters['ips_primaria']        = GETPOST('filter_ips_primaria', 'alpha');
    $filters['estado_del_paciente'] = GETPOST('filter_estado_del_paciente', 'alpha');
    $filters['patient_date_start']  = $_date_start_ts ? dol_print_date($_date_start_ts, 'dayrfc') : '';
    $filters['patient_date_end']    = $_date_end_ts   ? dol_print_date($_date_end_ts,   'dayrfc') : '';
}

$cleanFilters = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
$engine->setFilters($cleanFilters);

// Opciones para filtros
$optMedicamentos = $engine->getUniqueFieldValues('medicamento');
$optEps          = $engine->getUniqueFieldValues('eps');
$optOperadores   = $engine->getUniqueFieldValues('operador_logistico');
$optTipoPob      = $engine->getUniqueFieldValues('tipo_de_poblacion');
$optProgramas    = $engine->getUniqueFieldValues('programa');
$optDiagnosticos = $engine->getUniqueFieldValues('diagnostico');
$optIps          = $engine->getUniqueFieldValues('ips_primaria');
$optEstados      = $engine->getUniqueFieldValues('estado_del_paciente');

// Distribuciones agregadas — no se devuelven datos individuales de pacientes
$totalPacientes = $engine->countPatients();
$distEps        = $engine->getPatientDistributionBy('eps');
$distMed        = $engine->getPatientDistributionBy('medicamento');
$distOp         = $engine->getPatientDistributionBy('operador_logistico');
$distEstado     = $engine->getPatientDistributionBy('estado_del_paciente');
$distProg       = $engine->getPatientDistributionBy('programa');
$distDiag       = $engine->getPatientDistributionBy('diagnostico');
$distTipoPob    = $engine->getPatientDistributionBy('tipo_de_poblacion');
$distRegimen    = $engine->getPatientDistributionBy('regimen');
$distAfiliacion = $engine->getPatientDistributionBy('tipo_de_afiliacion');

llxHeader('', $langs->trans('PacientesAnaliticas'), '', '', 0, 0,
    array('/includes/nnnick/chartjs/dist/chart.min.js', '/rcv_analytics/js/charts.min.js'),
    array('/rcv_analytics/css/analytics.css'));

$head = rcv_analytics_prepare_head();
print dol_get_fiche_head($head, 'patients', $langs->trans('Analiticas'), -1, 'user');
rcv_print_inline_styles();

// ─── Filtros responsive ────────────────────────────────────────────────────
print '<form method="GET" action="'.dol_buildpath('/rcv_analytics/patients.php', 1).'">';
print '<div class="rcv-filters">';
print '<div class="rcv-filter-dates">';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaCreacionDesde').'</label>'
    .$form->selectDate($_date_start_ts ?: -1, 'filter_date_start', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="rcv-filter-item"><label>'.$langs->trans('FechaCreacionHasta').'</label>'
    .$form->selectDate($_date_end_ts ?: -1, 'filter_date_end', 0, 0, 1, '', 1, 0).'</div>';
print '</div>';
print '<div class="rcv-filter-grid">';
rcv_print_filter_select('filter_eps',                $langs->trans('EPS'),               $optEps,          $filters['eps'] ?? '');
rcv_print_filter_select('filter_medicamento',        $langs->trans('Medicamento'),       $optMedicamentos, $filters['medicamento'] ?? '');
rcv_print_filter_select('filter_operador_logistico', $langs->trans('OperadorLogistico'), $optOperadores,   $filters['operador_logistico'] ?? '');
rcv_print_filter_select('filter_programa',           $langs->trans('Programa'),          $optProgramas,    $filters['programa'] ?? '');
rcv_print_filter_select('filter_diagnostico',        $langs->trans('Diagnostico'),       $optDiagnosticos, $filters['diagnostico'] ?? '');
rcv_print_filter_select('filter_estado_del_paciente',$langs->trans('EstadoPaciente'),    $optEstados,      $filters['estado_del_paciente'] ?? '');
rcv_print_filter_select('filter_ips_primaria',       $langs->trans('IPSPrimaria'),       $optIps,          $filters['ips_primaria'] ?? '');
rcv_print_filter_select('filter_tipo_de_poblacion',  $langs->trans('TipoPoblacion'),     $optTipoPob,      $filters['tipo_de_poblacion'] ?? '');
print '</div>';
print '<div class="rcv-filter-actions">';
print '<input type="submit" class="butAction" name="button_search" value="'.$langs->trans('Filtrar').'">';
print '<input type="submit" class="butActionDelete" name="button_removefilter" value="'.$langs->trans('LimpiarFiltros').'">';
print '</div>';
print '</div>';
print '</form>';

// ─── KPI total ─────────────────────────────────────────────────────────────
print '<div class="rcv-kpi-row">';
rcv_kpi_card($langs->trans('TotalPacientes'), $totalPacientes, 'user', '#2563eb');
print '</div>';

// ─── Bloques de distribución (tabla + chart por dimensión) ─────────────────
/**
 * Imprime un bloque de distribución: tabla de conteos + gráfica
 */
function rcv_dist_block($title, $rows, $chartId, $chartType = 'bar')
{
    global $langs;
    print '<div class="rcv-charts-row">';

    print '<div class="rcv-chart-box">';
    print '<h3>'.dol_escape_htmltag($title).'</h3>';
    if (empty($rows)) {
        print '<p class="opacitymedium">'.$langs->trans('NoRecordFound').'</p>';
    } else {
        print '<div class="rcv-table-wrapper">';
        print '<table class="noborder centpercent liste">';
        print '<tr class="liste_titre"><th>'.$langs->trans('Categoria').'</th>'
            .'<th style="text-align:right">'.$langs->trans('NPacientes').'</th></tr>';
        foreach ($rows as $r) {
            print '<tr class="oddeven"><td>'.dol_escape_htmltag($r['categoria']).'</td>'
                .'<td style="text-align:right"><strong>'.((int)$r['total']).'</strong></td></tr>';
        }
        print '</table></div>';
    }
    print '</div>';

    print '<div class="rcv-chart-box"><canvas id="'.dol_escape_htmltag($chartId).'"></canvas></div>';
    print '</div>';
}

rcv_dist_block($langs->trans('PacientesPorEPS'),         $distEps,        'chartEps');
rcv_dist_block($langs->trans('PacientesPorMedicamento'), $distMed,        'chartMed');
rcv_dist_block($langs->trans('PacientesPorOperador'),    $distOp,         'chartOp');
rcv_dist_block($langs->trans('EstadoPaciente'),          $distEstado,     'chartEstado',     'doughnut');
rcv_dist_block($langs->trans('Programa'),                $distProg,       'chartProg');
rcv_dist_block($langs->trans('Diagnostico'),             $distDiag,       'chartDiag');
rcv_dist_block($langs->trans('TipoPoblacion'),           $distTipoPob,    'chartTipoPob',    'doughnut');
rcv_dist_block($langs->trans('Regimen'),                 $distRegimen,    'chartRegimen',    'doughnut');
rcv_dist_block($langs->trans('TipoAfiliacion'),          $distAfiliacion, 'chartAfiliacion', 'doughnut');

// ─── Exportar ──────────────────────────────────────────────────────────────
print '<div style="margin:10px 0">';
print '<a class="butAction" href="'.dol_buildpath('/rcv_analytics/export.php', 1).'?type=patients'.rcv_filter_querystring($filters).'">'.$langs->trans('ExportarCSV').'</a>';
print '</div>';

// ─── Chart.js: renderizar gráficas ────────────────────────────────────────
$jEps        = json_encode(rcv_chart_data($distEps,        'categoria', 'total'));
$jMed        = json_encode(rcv_chart_data($distMed,        'categoria', 'total'));
$jOp         = json_encode(rcv_chart_data($distOp,         'categoria', 'total'));
$jEstado     = json_encode(rcv_chart_data($distEstado,     'categoria', 'total'));
$jProg       = json_encode(rcv_chart_data($distProg,       'categoria', 'total'));
$jDiag       = json_encode(rcv_chart_data($distDiag,       'categoria', 'total'));
$jTipoPob    = json_encode(rcv_chart_data($distTipoPob,    'categoria', 'total'));
$jRegimen    = json_encode(rcv_chart_data($distRegimen,    'categoria', 'total'));
$jAfiliacion = json_encode(rcv_chart_data($distAfiliacion, 'categoria', 'total'));

print '<script>
document.addEventListener("DOMContentLoaded", function() {
    rcvRenderBarChart("chartEps",         '.$jEps.',        "Pacientes");
    rcvRenderBarChart("chartMed",         '.$jMed.',        "Pacientes");
    rcvRenderBarChart("chartOp",          '.$jOp.',         "Pacientes");
    rcvRenderDoughnutChart("chartEstado", '.$jEstado.');
    rcvRenderBarChart("chartProg",        '.$jProg.',       "Pacientes");
    rcvRenderBarChart("chartDiag",        '.$jDiag.',       "Pacientes");
    rcvRenderDoughnutChart("chartTipoPob",'.$jTipoPob.');
    rcvRenderDoughnutChart("chartRegimen",'.$jRegimen.');
    rcvRenderDoughnutChart("chartAfiliacion",'.$jAfiliacion.');
});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
