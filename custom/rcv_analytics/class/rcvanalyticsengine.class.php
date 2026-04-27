<?php
/* Copyright (C) 2024 DatiLab
 * Motor de analíticas para pacientes y consultas RCV
 */

/**
 * RcvAnalyticsEngine - Clase central para todas las queries de analíticas
 *
 * Trabaja sobre:
 *  - llx_societe + llx_societe_extrafields  (pacientes)
 *  - llx_cabinetmed_extcons                 (consultas extendidas)
 *  - llx_cabinetmed_extcons_types           (tipos de atención)
 *
 * Campos de extrafields de paciente disponibles:
 *  birthdate, tipo_de_afiliacion, estado_vital, tipo_de_status,
 *  estado_del_paciente, n_documento, tipo_de_documento, biologico,
 *  regimen, guardian, programa, concentracion, ips_primaria,
 *  medico_tratante, operador_logistico, eps, medicamento,
 *  tipo_de_poblacion, diagnostico, sede_operador_logistico
 */
class RcvAnalyticsEngine
{
    /** @var DoliDB */
    private $db;

    /** @var array Filtros activos */
    private $filters = array();

    /** Campos de extrafields del paciente en llx_societe_extrafields */
    const PATIENT_EXTRA_FIELDS = array(
        'birthdate', 'tipo_de_afiliacion', 'estado_vital', 'tipo_de_status',
        'estado_del_paciente', 'n_documento', 'tipo_de_documento', 'biologico',
        'regimen', 'guardian', 'programa', 'concentracion', 'ips_primaria',
        'medico_tratante', 'operador_logistico', 'eps', 'medicamento',
        'tipo_de_poblacion', 'diagnostico', 'sede_operador_logistico',
        'contacto_whatsapp', 'habeas_data', 'consentimiento_informado',
        'llamada_telefonica', 'advertencia_en_la_recoleccion_',
        'fecha_entregado_guardian', 'fecha_cambio_guardian',
    );

    /** Campos de la tabla de consultas extendidas */
    const EXTCONS_FIELDS = array(
        'tipo_atencion', 'cumplimiento', 'razon_inc', 'mes_actual',
        'proximo_mes', 'dificultad', 'motivo', 'diagnostico',
        'procedimiento', 'rx_num', 'medicamentos', 'status',
    );

    /**
     * Campos sellist: almacenan el rowid de la tabla de diccionario referenciada.
     * Formato: 'campo' => ['table' => 'nombre_tabla_sin_prefijo', 'label' => 'columna_label']
     */
    private static $SELLIST_TABLES = array(
        'medico_tratante'    => array('table' => 'gestion_medico',         'label' => 'nombre'),
        'operador_logistico' => array('table' => 'gestion_operador',        'label' => 'nombre'),
        'eps'                => array('table' => 'gestion_eps',             'label' => 'descripcion'),
        'medicamento'        => array('table' => 'gestion_medicamento',     'label' => 'etiqueta'),
        'programa'           => array('table' => 'gestion_programa',        'label' => 'nombre'),
        'concentracion'      => array('table' => 'gestion_medicamento_det', 'label' => 'concentracion_display'),
        'diagnostico'        => array('table' => 'gestion_diagnostico',     'label' => 'label'),
    );

    /**
     * Campos chkbxlst: almacenan IDs separados por coma (e.g. "3,7,12").
     * Nota: diagnostico se maneja via SELLIST_TABLES (almacena ID único como varchar).
     */
    private static $CHKBXLST_TABLES = array(
    );

    /**
     * Campos select: almacenan la clave entera; mapeamos a su etiqueta.
     */
    private static $SELECT_LABELS = array(
        'estado_del_paciente' => array(
            1 => 'En Tránsito', 2 => 'En Proceso', 3 => 'Activo en Tratamiento',
            4 => 'Activo Independiente', 5 => 'Activo Por El Programa',
            6 => 'Reactivado', 7 => 'Suspendido', 8 => 'No trazable',
            9 => 'NAP', 10 => 'Inactivo',
        ),
        'estado_vital' => array(1 => 'Vivo', 2 => 'Muerto'),
        'tipo_de_status' => array(
            1 => 'Trámite Completo', 2 => 'Trámite Intermedio - Reclama',
            3 => 'Trámite Intermedio - Autoriza', 4 => 'Independiente',
        ),
        'regimen' => array(
            1 => 'Contributivo', 2 => 'Subsidiado', 3 => 'Especial',
            4 => 'Particular', 5 => 'Por confirmar',
        ),
        'tipo_de_afiliacion' => array(
            1 => 'Beneficiario', 2 => 'Cotizante', 3 => 'Cabeza de Familia',
            4 => 'Por Confirmar', 5 => 'Otro', 6 => 'NA',
        ),
        'tipo_de_poblacion' => array(
            1 => 'Población Mestiza', 2 => 'Población Afrocolombiana',
            3 => 'Población Indígena', 4 => 'Población Blanca',
            5 => 'Población Raizal', 6 => 'Población Palenquera',
            7 => 'Población Rrom o Gitana', 8 => 'Población Rural',
            9 => 'Población Urbana', 10 => 'Población Migrante',
            11 => 'Ninguno',
        ),
        'tipo_de_documento' => array(
            1 => 'Registro Civil', 2 => 'Tarjeta de Identidad',
            3 => 'Cédula de Ciudadanía', 4 => 'Cédula de Extranjería',
            8 => 'Permiso de Protección Temporal', 9 => 'Salvo Conducto',
            10 => 'Sin Identificación', 11 => 'NIT', 13 => 'NA',
            14 => 'Permiso Especial de Permanencia',
        ),
    );

    public function __construct($db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // FILTROS
    // -------------------------------------------------------------------------

    /**
     * Define los filtros aplicables a las consultas.
     *
     * @param array $filters  Ejemplo:
     *   array(
     *     'date_start'          => '2025-01-01',
     *     'date_end'            => '2025-12-31',
     *     'medicamento'         => 'Adalimumab',
     *     'eps'                 => 'Sanitas',
     *     'operador_logistico'  => 'Audifarma',
     *     'tipo_de_poblacion'   => 'Biologico',
     *     'tipo_atencion'       => 'adherencia',
     *     'programa'            => 'RCV',
     *     'diagnostico'         => 'M05',
     *     'ips_primaria'        => '',
     *     'estado_del_paciente' => 'Activo',
     *     'medico_tratante'     => '',
     *   )
     */
    public function setFilters(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * Construye el fragmento WHERE para los filtros de paciente (extrafields)
     * y consulta, con todos los valores escapados.
     *
     * @param  bool $withExtcons  Si true, incluye joins con extcons
     * @return array  ['where' => string, 'joins' => string]
     */
    private function buildWhere($withExtcons = false)
    {
        $where  = ' WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.((int) $this->db->escape($GLOBALS['conf']->entity));
        $joins  = ' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields se ON se.fk_object = s.rowid';

        if ($withExtcons) {
            $joins .= ' INNER JOIN '.MAIN_DB_PREFIX.'cabinetmed_extcons c ON c.fk_soc = s.rowid AND c.entity = '.((int) $GLOBALS['conf']->entity);
        }

        // Filtro de fechas sobre consultas
        if ($withExtcons) {
            if (!empty($this->filters['date_start'])) {
                $where .= ' AND c.date_start >= \''.$this->db->escape($this->filters['date_start']).' 00:00:00\'';
            }
            if (!empty($this->filters['date_end'])) {
                $where .= ' AND c.date_start <= \''.$this->db->escape($this->filters['date_end']).' 23:59:59\'';
            }
            if (!empty($this->filters['tipo_atencion'])) {
                $val = $this->filters['tipo_atencion'];
                if (is_array($val)) {
                    $escaped = array();
                    foreach ($val as $v) {
                        if ((string)$v !== '') $escaped[] = '\''.$this->db->escape($v).'\'';
                    }
                    if (!empty($escaped)) {
                        $where .= ' AND c.tipo_atencion IN ('.implode(',', $escaped).')';
                    }
                } else {
                    $where .= ' AND c.tipo_atencion = \''.$this->db->escape($val).'\'';
                }
            }
            if (isset($this->filters['cumplimiento']) && $this->filters['cumplimiento'] !== '') {
                $where .= ' AND c.cumplimiento = \''.$this->db->escape($this->filters['cumplimiento']).'\'';
            }
        }

        // Filtros sobre extrafields del paciente
        $extraTextFilters = array(
            'medicamento', 'eps', 'operador_logistico', 'tipo_de_poblacion',
            'programa', 'diagnostico', 'ips_primaria', 'estado_del_paciente',
            'medico_tratante', 'sede_operador_logistico', 'tipo_de_afiliacion',
            'estado_vital', 'tipo_de_status', 'regimen', 'concentracion',
        );
        foreach ($extraTextFilters as $field) {
            if (!empty($this->filters[$field])) {
                $val = $this->filters[$field];
                $isSellistOrSelect = isset(self::$SELLIST_TABLES[$field]) || isset(self::$SELECT_LABELS[$field]);
                $isChkbxlst = isset(self::$CHKBXLST_TABLES[$field]);

                if (is_array($val)) {
                    // Multi-select: usar IN (...)
                    if ($isSellistOrSelect) {
                        $ids = array_map('intval', array_filter($val, 'is_numeric'));
                        if (!empty($ids)) {
                            $where .= ' AND se.'.$field.' IN ('.implode(',', $ids).')';
                        }
                    } elseif ($isChkbxlst) {
                        $parts = array();
                        foreach ($val as $v) {
                            if (is_numeric($v)) {
                                $parts[] = 'FIND_IN_SET('.(int)$v.', se.'.$field.') > 0';
                            }
                        }
                        if (!empty($parts)) {
                            $where .= ' AND ('.implode(' OR ', $parts).')';
                        }
                    } else {
                        $escaped = array();
                        foreach ($val as $v) {
                            if ((string)$v !== '') {
                                $escaped[] = '\''.$this->db->escape($v).'\'';
                            }
                        }
                        if (!empty($escaped)) {
                            $where .= ' AND se.'.$field.' IN ('.implode(',', $escaped).')';
                        }
                    }
                } else {
                    // Valor escalar: lógica existente
                    if ($isSellistOrSelect) {
                        $where .= ' AND se.'.$field.' = '.(int)$val;
                    } elseif ($isChkbxlst) {
                        $where .= ' AND FIND_IN_SET('.(int)$val.', se.'.$field.') > 0';
                    } else {
                        $where .= ' AND se.'.$field.' = \''.$this->db->escape($val).'\'';
                    }
                }
            }
        }

        // Filtros departamento y ciudad (sobre llx_societe directamente)
        if (!empty($this->filters['departamento'])) {
            $vals = (array)$this->filters['departamento'];
            $ids = array_map('intval', array_filter($vals, 'is_numeric'));
            if (!empty($ids)) {
                $where .= ' AND s.fk_departement IN ('.implode(',', $ids).')';
            }
        }
        if (!empty($this->filters['ciudad'])) {
            $vals = (array)$this->filters['ciudad'];
            $escaped = array();
            foreach ($vals as $v) {
                if ((string)$v !== '') {
                    $escaped[] = '\''.$this->db->escape($v).'\'';
                }
            }
            if (!empty($escaped)) {
                $where .= ' AND s.town IN ('.implode(',', $escaped).')';
            }
        }

        // Filtros de fecha sobre paciente
        if (!empty($this->filters['patient_date_start'])) {
            $where .= ' AND s.datec >= \''.$this->db->escape($this->filters['patient_date_start']).' 00:00:00\'';
        }
        if (!empty($this->filters['patient_date_end'])) {
            $where .= ' AND s.datec <= \''.$this->db->escape($this->filters['patient_date_end']).' 23:59:59\'';
        }

        return array('where' => $where, 'joins' => $joins);
    }

    // -------------------------------------------------------------------------
    // ► ANALÍTICAS DE PACIENTES
    // -------------------------------------------------------------------------

    /**
     * Cuenta de pacientes según el periodo de creación (agrupado por mes)
     *
     * @param  string $groupBy  'month'|'week'|'year'
     * @return array
     */
    public function getPatientsOverTime($groupBy = 'month')
    {
        $built = $this->buildWhere(false);

        switch ($groupBy) {
            case 'week':
                $dateExpr = 'DATE_FORMAT(s.datec, \'%Y-%u\')';
                $labelExpr = 'CONCAT(YEAR(s.datec), \'-S\', LPAD(WEEK(s.datec,3),2,\'0\'))';
                break;
            case 'year':
                $dateExpr = 'YEAR(s.datec)';
                $labelExpr = 'YEAR(s.datec)';
                break;
            default: // month
                $dateExpr = 'DATE_FORMAT(s.datec, \'%Y-%m\')';
                $labelExpr = 'DATE_FORMAT(s.datec, \'%Y-%m\')';
        }

        $sql = 'SELECT '.$labelExpr.' AS periodo, COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY '.$dateExpr
            .' ORDER BY '.$dateExpr.' ASC';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución de pacientes por un campo de extrafield con labels resueltos.
     * - sellist: hace JOIN a la tabla de diccionario
     * - chkbxlst: JOIN con FIND_IN_SET (ej: diagnostico)
     * - select: CASE WHEN para mapear clave→etiqueta
     * - varchar: agrupa por valor de texto directo
     *
     * @param  string $field  Campo de extrafield
     * @return array  [['categoria' => label, 'total' => N], ...]
     */
    public function getPatientDistributionBy($field)
    {
        if (!in_array($field, self::PATIENT_EXTRA_FIELDS)) {
            return array();
        }

        $built = $this->buildWhere(false);

        // ── sellist: JOIN a tabla de diccionario ──────────────────────────────
        if (isset(self::$SELLIST_TABLES[$field])) {
            $t        = self::$SELLIST_TABLES[$field];
            $refTable = MAIN_DB_PREFIX.$t['table'];
            $labelCol = $t['label'];

            $sql = 'SELECT COALESCE(NULLIF(TRIM(ref.'.$labelCol.'), \'\'), \'(Sin dato)\') AS categoria,'
                .' COUNT(DISTINCT s.rowid) AS total'
                .' FROM '.MAIN_DB_PREFIX.'societe s'
                .$built['joins']
                .' LEFT JOIN '.$refTable.' ref ON ref.rowid = se.'.$field
                .$built['where']
                .' GROUP BY ref.rowid, ref.'.$labelCol
                .' ORDER BY total DESC';

            return $this->fetchRows($sql);
        }

        // ── chkbxlst: JOIN con FIND_IN_SET ────────────────────────────────────
        if (isset(self::$CHKBXLST_TABLES[$field])) {
            $t        = self::$CHKBXLST_TABLES[$field];
            $refTable = MAIN_DB_PREFIX.$t['table'];
            $labelCol = $t['label'];

            $sql = 'SELECT COALESCE(NULLIF(TRIM(ref.'.$labelCol.'), \'\'), \'(Sin dato)\') AS categoria,'
                .' COUNT(DISTINCT s.rowid) AS total'
                .' FROM '.MAIN_DB_PREFIX.'societe s'
                .$built['joins']
                .' INNER JOIN '.$refTable.' ref ON FIND_IN_SET(CAST(ref.rowid AS CHAR), se.'.$field.') > 0'
                .$built['where']
                .' GROUP BY ref.rowid, ref.'.$labelCol
                .' ORDER BY total DESC';

            return $this->fetchRows($sql);
        }

        // ── select: CASE WHEN para clave→etiqueta ─────────────────────────────
        if (isset(self::$SELECT_LABELS[$field])) {
            $labels = self::$SELECT_LABELS[$field];
            $case   = 'CASE se.'.$field;
            foreach ($labels as $key => $label) {
                $case .= ' WHEN '.(int)$key.' THEN \''.$this->db->escape($label).'\'';
            }
            $case .= ' ELSE \'(Sin dato)\' END';

            $sql = 'SELECT '.$case.' AS categoria,'
                .' COUNT(DISTINCT s.rowid) AS total'
                .' FROM '.MAIN_DB_PREFIX.'societe s'
                .$built['joins']
                .$built['where']
                .' GROUP BY categoria'
                .' ORDER BY total DESC';

            return $this->fetchRows($sql);
        }

        // ── varchar: agrupación directa ───────────────────────────────────────
        $sql = 'SELECT COALESCE(NULLIF(TRIM(se.'.$field.'), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY se.'.$field
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Pacientes activos vs inactivos (por estado_del_paciente)
     */
    public function getPatientsStatusSummary()
    {
        return $this->getPatientDistributionBy('estado_del_paciente');
    }

    /**
     * Distribución de pacientes por departamento.
     * @return array [['categoria' => nom, 'total' => N], ...]
     */
    public function getPatientsByDepartamento()
    {
        $built = $this->buildWhere(false);
        $extraJoin = ' LEFT JOIN '.MAIN_DB_PREFIX.'c_departements dep ON dep.rowid = s.fk_departement';

        $sql = 'SELECT COALESCE(NULLIF(TRIM(dep.nom), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$extraJoin
            .$built['where']
            .' GROUP BY dep.rowid, dep.nom'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución de pacientes por ciudad.
     * @return array [['categoria' => town, 'total' => N], ...]
     */
    public function getPatientsByCiudad()
    {
        $built = $this->buildWhere(false);

        $sql = 'SELECT COALESCE(NULLIF(TRIM(s.town), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY s.town'
            .' ORDER BY total DESC'
            .' LIMIT 30';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución por EPS
     */
    public function getPatientsByEps()
    {
        return $this->getPatientDistributionBy('eps');
    }

    /**
     * Distribución por medicamento
     */
    public function getPatientsByMedicamento()
    {
        return $this->getPatientDistributionBy('medicamento');
    }

    /**
     * Distribución por operador logístico
     */
    public function getPatientsByOperador()
    {
        return $this->getPatientDistributionBy('operador_logistico');
    }

    /**
     * Distribución por IPS primaria
     */
    public function getPatientsByIps()
    {
        return $this->getPatientDistributionBy('ips_primaria');
    }

    /**
     * Distribución por tipo de población
     */
    public function getPatientsByTipoPoblacion()
    {
        return $this->getPatientDistributionBy('tipo_de_poblacion');
    }

    /**
     * Distribución por programa
     */
    public function getPatientsByPrograma()
    {
        return $this->getPatientDistributionBy('programa');
    }

    /**
     * Vista resumen de todos los campos categóricos del paciente de una sola vez
     * Útil para el dashboard principal
     *
     * @return array  ['eps' => [...], 'medicamento' => [...], ...]
     */
    public function getPatientsSummaryAll()
    {
        $fields = array(
            'eps', 'medicamento', 'operador_logistico', 'ips_primaria',
            'tipo_de_poblacion', 'programa', 'estado_del_paciente',
            'tipo_de_afiliacion', 'regimen', 'diagnostico',
        );
        $result = array();
        foreach ($fields as $f) {
            $result[$f] = $this->getPatientDistributionBy($f);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // ► ANALÍTICAS DE CONSULTAS
    // -------------------------------------------------------------------------

    /**
     * Total de consultas agrupadas por tipo de atención
     */
    public function getConsultationsByTipoAtencion()
    {
        $built = $this->buildWhere(true);

        $sql = 'SELECT COALESCE(NULLIF(TRIM(c.tipo_atencion), \'\'), \'(Sin tipo)\') AS tipo,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY c.tipo_atencion'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución de consultas por un campo del extrafield del paciente (se.field)
     * Devuelve filas con columnas 'categoria' y 'total'
     *
     * @param  string $field  Campo de societe_extrafields
     */
    public function getConsultationsByPatientField($field)
    {
        $allowed = array(
            'eps','medicamento','operador_logistico','tipo_de_poblacion',
            'programa','diagnostico','ips_primaria','estado_del_paciente',
            'regimen','tipo_de_afiliacion','medico_tratante',
        );
        if (!in_array($field, $allowed)) return array();

        $built = $this->buildWhere(true);

        if (isset(self::$SELLIST_TABLES[$field])) {
            $def = self::$SELLIST_TABLES[$field];
            $labelJoin = ' LEFT JOIN '.MAIN_DB_PREFIX.$def['table'].' lbl_'.$field.' ON lbl_'.$field.'.rowid = se.'.$field;
            $labelExpr = 'COALESCE(NULLIF(TRIM(lbl_'.$field.'.'.$def['label'].'), \'\'), \'(Sin dato)\')';
            $groupExpr = 'lbl_'.$field.'.rowid';
        } elseif (isset(self::$SELECT_LABELS[$field])) {
            $labels = self::$SELECT_LABELS[$field];
            $case = 'CASE se.'.$field;
            foreach ($labels as $key => $label) {
                $case .= ' WHEN '.(int)$key.' THEN \''.$this->db->escape($label).'\'';
            }
            $case .= ' ELSE \'(Sin dato)\' END';
            $labelJoin = '';
            $labelExpr = $case;
            $groupExpr = 'categoria';
        } else {
            $labelJoin = '';
            $labelExpr = 'COALESCE(NULLIF(TRIM(se.'.$field.'), \'\'), \'(Sin dato)\')';
            $groupExpr = 'se.'.$field;
        }

        $sql = 'SELECT '.$labelExpr.' AS categoria,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$labelJoin
            .$built['where']
            .' GROUP BY '.$groupExpr
            .' ORDER BY total DESC'
            .' LIMIT 20';

        return $this->fetchRows($sql);
    }

    /**
     * Evolución temporal de consultas agrupadas por mes
     *
     * @param  string $groupBy  'month'|'week'|'year'
     */
    public function getConsultationsOverTime($groupBy = 'month')
    {
        $built = $this->buildWhere(true);

        switch ($groupBy) {
            case 'week':
                $dateExpr = 'DATE_FORMAT(c.date_start, \'%Y-%u\')';
                $labelExpr = 'CONCAT(YEAR(c.date_start), \'-S\', LPAD(WEEK(c.date_start,3),2,\'0\'))';
                break;
            case 'year':
                $dateExpr = 'YEAR(c.date_start)';
                $labelExpr = 'YEAR(c.date_start)';
                break;
            default: // month
                $dateExpr = 'DATE_FORMAT(c.date_start, \'%Y-%m\')';
                $labelExpr = 'DATE_FORMAT(c.date_start, \'%Y-%m\')';
        }

        $sql = 'SELECT '.$labelExpr.' AS periodo,'
            .' COUNT(c.rowid) AS total,'
            .' COUNT(DISTINCT c.fk_soc) AS pacientes_unicos'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY '.$dateExpr
            .' ORDER BY '.$dateExpr.' ASC';

        return $this->fetchRows($sql);
    }

    /**
     * Analíticas de adherencia: distribución de cumplimiento
     */
    public function getAdherenciaDistribution()
    {
        $built = $this->buildWhere(true);

        $sql = 'SELECT COALESCE(NULLIF(TRIM(c.cumplimiento), \'\'), \'(Sin dato)\') AS cumplimiento,'
            .' COUNT(c.rowid) AS total,'
            .' COUNT(DISTINCT c.fk_soc) AS pacientes_unicos'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY c.cumplimiento'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Razones de incumplimiento en adherencias
     */
    public function getRazonIncumplimiento()
    {
        $built = $this->buildWhere(true);

        // Sólo consultas de adherencia con incumplimiento
        $builtWhere = $built['where'].' AND c.tipo_atencion = \'adherencia\''
            .' AND c.cumplimiento IS NOT NULL AND c.cumplimiento != \'\'';

        $sql = 'SELECT COALESCE(NULLIF(TRIM(c.razon_inc), \'\'), \'(Sin razón)\') AS razon,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$builtWhere
            .' GROUP BY c.razon_inc'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Tabla cruzada: consultas por tipo x mes
     * Retorna matrix útil para heatmaps o tablas dinámicas
     */
    public function getConsultationsCrossTable($groupByField = 'tipo_atencion', $groupBy = 'month')
    {
        $built  = $this->buildWhere(true);

        switch ($groupBy) {
            case 'year':
                $dateExpr = 'YEAR(c.date_start)';
                break;
            case 'week':
                $dateExpr = 'DATE_FORMAT(c.date_start, \'%Y-%u\')';
                break;
            default:
                $dateExpr = 'DATE_FORMAT(c.date_start, \'%Y-%m\')';
        }

        // Validar campo groupByField
        $allowedGroupFields = array_merge(
            array('tipo_atencion', 'cumplimiento', 'razon_inc', 'status'),
            array_map(function ($f) { return 'se.'.$f; }, array(
                'eps', 'medicamento', 'operador_logistico', 'ips_primaria',
                'tipo_de_poblacion', 'programa', 'estado_del_paciente', 'diagnostico'
            ))
        );

        $safeField = in_array($groupByField, array('tipo_atencion', 'cumplimiento', 'razon_inc', 'status'))
            ? 'c.'.$groupByField
            : 'se.'.preg_replace('/[^a-z0-9_]/', '', $groupByField);

        $sql = 'SELECT '.$dateExpr.' AS periodo,'
            .' COALESCE(NULLIF(TRIM('.$safeField.'), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY '.$dateExpr.', '.$safeField
            .' ORDER BY periodo ASC, total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * KPIs rápidos: totales globales aplicando los filtros activos
     *
     * @return array  Asociativo con métricas clave
     */
    public function getKpis()
    {
        $builtP = $this->buildWhere(false);
        $builtC = $this->buildWhere(true);

        // Total pacientes
        $sqlP = 'SELECT COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$builtP['joins']
            .$builtP['where'];
        $rowP = $this->fetchRows($sqlP);
        $totalPacientes = !empty($rowP[0]['total']) ? (int) $rowP[0]['total'] : 0;

        // Total consultas
        $sqlC = 'SELECT COUNT(c.rowid) AS total, COUNT(DISTINCT c.fk_soc) AS con_consulta'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$builtC['joins']
            .$builtC['where'];
        $rowC = $this->fetchRows($sqlC);
        $totalConsultas = !empty($rowC[0]['total']) ? (int) $rowC[0]['total'] : 0;
        $pacientesConConsulta = !empty($rowC[0]['con_consulta']) ? (int) $rowC[0]['con_consulta'] : 0;

        // Adherencias: total y % cumplimiento
        $filtersOrig = $this->filters;
        $this->filters['tipo_atencion'] = 'adherencia';
        $builtA = $this->buildWhere(true);
        $this->filters = $filtersOrig;

        $sqlA = 'SELECT COUNT(c.rowid) AS total,'
            .' SUM(CASE WHEN c.cumplimiento = \'100\' OR c.cumplimiento = \'si\' OR c.cumplimiento = \'1\' THEN 1 ELSE 0 END) AS cumplio'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$builtA['joins']
            .$builtA['where'].' AND c.tipo_atencion = \'adherencia\'';
        $rowA = $this->fetchRows($sqlA);
        $totalAdherencias = !empty($rowA[0]['total']) ? (int) $rowA[0]['total'] : 0;
        $cumplio = !empty($rowA[0]['cumplio']) ? (int) $rowA[0]['cumplio'] : 0;
        $pctCumplimiento = $totalAdherencias > 0 ? round(($cumplio / $totalAdherencias) * 100, 1) : 0;

        return array(
            'total_pacientes'         => $totalPacientes,
            'total_consultas'         => $totalConsultas,
            'pacientes_con_consulta'  => $pacientesConConsulta,
            'total_adherencias'       => $totalAdherencias,
            'pct_cumplimiento'        => $pctCumplimiento,
        );
    }

    /**
     * Lista detallada de pacientes con sus datos claves y totales de consulta
     * Con paginación y ordenamiento
     *
     * @param  int    $limit
     * @param  int    $offset
     * @param  string $sortfield
     * @param  string $sortorder
     * @return array
     */
    public function getPatientsList($limit = 50, $offset = 0, $sortfield = 's.datec', $sortorder = 'DESC')
    {
        $built = $this->buildWhere(false);

        // Joins adicionales para contar consultas
        $joins = $built['joins'];
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'cabinetmed_extcons c ON c.fk_soc = s.rowid';

        $allowedSortFields = array(
            's.rowid', 's.nom', 's.datec', 'se.eps', 'se.medicamento',
            'se.operador_logistico', 'se.estado_del_paciente',
            'total_consultas', 'ultima_consulta',
        );
        if (!in_array($sortfield, $allowedSortFields)) {
            $sortfield = 's.datec';
        }
        $sortorder = (strtoupper($sortorder) === 'ASC') ? 'ASC' : 'DESC';

        $sql = 'SELECT s.rowid, s.nom AS nombre, s.datec, s.email, s.phone,'
            .' se.n_documento, se.tipo_de_documento, se.eps, se.ips_primaria,'
            .' se.medicamento, se.operador_logistico, se.tipo_de_poblacion,'
            .' se.estado_del_paciente, se.programa, se.diagnostico,'
            .' se.medico_tratante, se.tipo_de_afiliacion,'
            .' COUNT(c.rowid) AS total_consultas,'
            .' MAX(c.date_start) AS ultima_consulta'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$joins
            .$built['where']
            .' GROUP BY s.rowid'
            .' ORDER BY '.$sortfield.' '.$sortorder
            .' LIMIT '.(int) $limit.' OFFSET '.(int) $offset;

        return $this->fetchRows($sql);
    }

    /**
     * Retorna todos los pacientes con etiquetas resueltas para exportación XLSX/CSV.
     * Sin límite de filas. Incluye JOINs a tablas de diccionario para campos sellist.
     *
     * @param  string $sortfield  Campo de ordenamiento
     * @param  string $sortorder  ASC | DESC
     * @return array  Filas con etiquetas resueltas
     */
    public function getPatientsForExport($sortfield = 's.nom', $sortorder = 'ASC')
    {
        $built = $this->buildWhere(false);
        $joins = $built['joins'];

        // JOINs a tablas de diccionario para resolver etiquetas
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_eps        AS d_eps  ON d_eps.rowid  = se.eps';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_medicamento AS d_med  ON d_med.rowid  = se.medicamento';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_operador    AS d_op   ON d_op.rowid   = se.operador_logistico';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_programa    AS d_prog ON d_prog.rowid = se.programa';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_medico      AS d_med2 ON d_med2.rowid = se.medico_tratante';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'gestion_diagnostico AS d_diag ON d_diag.rowid = se.diagnostico';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_departements      AS dep    ON dep.rowid    = s.fk_departement';
        $joins .= ' LEFT JOIN '.MAIN_DB_PREFIX.'cabinetmed_extcons  AS c      ON c.fk_soc     = s.rowid';

        $allowedSort = array('s.nom', 's.datec', 'd_eps.descripcion', 'd_med.etiqueta', 'd_prog.nombre');
        if (!in_array($sortfield, $allowedSort)) $sortfield = 's.nom';
        $sortorder = (strtoupper($sortorder) === 'DESC') ? 'DESC' : 'ASC';

        $sql = 'SELECT'
            .' s.rowid,'
            .' s.nom                   AS nombre,'
            .' s.datec                 AS fecha_creacion,'
            .' s.email,'
            .' s.phone,'
            .' s.town                  AS ciudad,'
            .' dep.nom                 AS departamento,'
            .' se.tipo_de_documento,'
            .' se.n_documento,'
            .' se.birthdate,'
            .' d_eps.descripcion       AS eps,'
            .' se.regimen,'
            .' se.tipo_de_afiliacion,'
            .' d_med.etiqueta          AS medicamento,'
            .' se.concentracion,'
            .' d_op.nombre             AS operador_logistico,'
            .' se.sede_operador_logistico,'
            .' d_prog.nombre           AS programa,'
            .' se.estado_del_paciente,'
            .' se.estado_vital,'
            .' se.ips_primaria,'
            .' d_med2.nombre           AS medico_tratante,'
            .' se.tipo_de_poblacion,'
            .' d_diag.label            AS diagnostico,'
            .' COUNT(c.rowid)          AS total_consultas,'
            .' MAX(c.date_start)       AS ultima_consulta'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$joins
            .$built['where']
            .' GROUP BY s.rowid, s.nom, s.datec, s.email, s.phone, s.town,'
            .' dep.nom, se.tipo_de_documento, se.n_documento, se.birthdate,'
            .' d_eps.descripcion, se.regimen, se.tipo_de_afiliacion,'
            .' d_med.etiqueta, se.concentracion, d_op.nombre,'
            .' se.sede_operador_logistico, d_prog.nombre, se.estado_del_paciente,'
            .' se.estado_vital, se.ips_primaria, d_med2.nombre,'
            .' se.tipo_de_poblacion, d_diag.label'
            .' ORDER BY '.$sortfield.' '.$sortorder;

        $rows = $this->fetchRows($sql);

        // Resolver etiquetas de campos SELECT (claves enteras → texto)
        foreach ($rows as &$row) {
            $row['tipo_de_documento']  = self::$SELECT_LABELS['tipo_de_documento'][(int)$row['tipo_de_documento']]  ?? $row['tipo_de_documento'];
            $row['regimen']            = self::$SELECT_LABELS['regimen'][(int)$row['regimen']]                       ?? $row['regimen'];
            $row['tipo_de_afiliacion'] = self::$SELECT_LABELS['tipo_de_afiliacion'][(int)$row['tipo_de_afiliacion']] ?? $row['tipo_de_afiliacion'];
            $row['tipo_de_poblacion']  = self::$SELECT_LABELS['tipo_de_poblacion'][(int)$row['tipo_de_poblacion']]   ?? $row['tipo_de_poblacion'];
            $row['estado_del_paciente']= self::$SELECT_LABELS['estado_del_paciente'][(int)$row['estado_del_paciente']] ?? $row['estado_del_paciente'];
            $row['estado_vital']       = self::$SELECT_LABELS['estado_vital'][(int)$row['estado_vital']]             ?? $row['estado_vital'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Cuenta total de pacientes para paginación
     */
    public function countPatients()
    {
        $built = $this->buildWhere(false);

        $sql = 'SELECT COUNT(DISTINCT s.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where'];

        $rows = $this->fetchRows($sql);
        return !empty($rows[0]['total']) ? (int) $rows[0]['total'] : 0;
    }

    /**
     * Tres barras de resumen: acumulado del período filtrado (hasta hoy),
     * mes actual y año actual. Aplica filtros no-fecha a los tres conteos;
     * el filtro de fecha sólo afecta la barra de "período".
     *
     * @return array  [['label' => string, 'total' => int], ...]
     */
    public function getPatientsPeriodSummary()
    {
        $origFilters    = $this->filters;
        $filtersNoDates = array_diff_key($origFilters, array_flip(array('patient_date_start', 'patient_date_end')));

        // Fecha de referencia: date_end del filtro si está activo, si no hoy
        $refDate  = !empty($origFilters['patient_date_end']) ? $origFilters['patient_date_end'] : date('Y-m-d');
        $refYear  = substr($refDate, 0, 4);
        $refMonth = substr($refDate, 5, 2);

        // ── 1. Período: desde date_start (o todo el tiempo) hasta refDate ──
        $this->filters = $filtersNoDates;
        if (!empty($origFilters['patient_date_start'])) {
            $this->filters['patient_date_start'] = $origFilters['patient_date_start'];
        }
        $this->filters['patient_date_end'] = $refDate;
        $built = $this->buildWhere(false);
        $row   = $this->fetchRows('SELECT COUNT(DISTINCT s.rowid) AS total FROM '.MAIN_DB_PREFIX.'societe s'.$built['joins'].$built['where']);
        $totalPeriod = !empty($row[0]['total']) ? (int)$row[0]['total'] : 0;
        $labelPeriod = !empty($origFilters['patient_date_start'])
            ? 'Desde '.$origFilters['patient_date_start']
            : 'Acumulado a '.$refDate;

        // ── 2. Último mes del período ─────────────────────────────────────────
        $this->filters = $filtersNoDates;
        $this->filters['patient_date_start'] = $refYear.'-'.$refMonth.'-01';
        $this->filters['patient_date_end']   = $refDate;
        $built = $this->buildWhere(false);
        $row   = $this->fetchRows('SELECT COUNT(DISTINCT s.rowid) AS total FROM '.MAIN_DB_PREFIX.'societe s'.$built['joins'].$built['where']);
        $totalMonth = !empty($row[0]['total']) ? (int)$row[0]['total'] : 0;

        // ── 3. Año del período ────────────────────────────────────────────────
        $this->filters = $filtersNoDates;
        $this->filters['patient_date_start'] = $refYear.'-01-01';
        $this->filters['patient_date_end']   = $refDate;
        $built = $this->buildWhere(false);
        $row   = $this->fetchRows('SELECT COUNT(DISTINCT s.rowid) AS total FROM '.MAIN_DB_PREFIX.'societe s'.$built['joins'].$built['where']);
        $totalYear = !empty($row[0]['total']) ? (int)$row[0]['total'] : 0;

        $this->filters = $origFilters;

        return array(
            array('label' => $labelPeriod,                     'total' => $totalPeriod),
            array('label' => 'Mes '.$refYear.'-'.$refMonth,    'total' => $totalMonth),
            array('label' => 'Año '.$refYear,                  'total' => $totalYear),
        );
    }

    /**
     * Valores únicos de un campo para poblar filtros select.
     * - sellist  → retorna [rowid => label]
     * - chkbxlst → retorna [rowid => label]
     * - select   → retorna [clave => etiqueta] (estático)
     * - varchar  → retorna array plano de strings únicos
     *
     * @param  string $field
     * @return array
     */
    public function getUniqueFieldValues($field)
    {
        if (!in_array($field, self::PATIENT_EXTRA_FIELDS)) {
            return array();
        }

        $entity = (int) $GLOBALS['conf']->entity;

        // ── sellist ───────────────────────────────────────────────────────────
        if (isset(self::$SELLIST_TABLES[$field])) {
            $t        = self::$SELLIST_TABLES[$field];
            $refTable = MAIN_DB_PREFIX.$t['table'];
            $labelCol = $t['label'];

            $sql = 'SELECT ref.rowid AS id, ref.'.$labelCol.' AS label'
                .' FROM '.$refTable.' ref'
                .' INNER JOIN '.MAIN_DB_PREFIX.'societe_extrafields se ON se.'.$field.' = ref.rowid'
                .' INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = se.fk_object'
                .' WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.$entity
                .' GROUP BY ref.rowid, ref.'.$labelCol
                .' ORDER BY label ASC';

            $rows   = $this->fetchRows($sql);
            $result = array();
            foreach ($rows as $r) {
                $result[(int)$r['id']] = (string)$r['label'];
            }
            return $result;
        }

        // ── chkbxlst ──────────────────────────────────────────────────────────
        if (isset(self::$CHKBXLST_TABLES[$field])) {
            $t        = self::$CHKBXLST_TABLES[$field];
            $refTable = MAIN_DB_PREFIX.$t['table'];
            $labelCol = $t['label'];

            $sql = 'SELECT ref.rowid AS id, ref.'.$labelCol.' AS label'
                .' FROM '.$refTable.' ref'
                .' WHERE EXISTS ('
                .'   SELECT 1 FROM '.MAIN_DB_PREFIX.'societe_extrafields se'
                .'   INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = se.fk_object'
                .'   WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.$entity
                .'   AND FIND_IN_SET(CAST(ref.rowid AS CHAR), se.'.$field.') > 0'
                .' )'
                .' ORDER BY label ASC';

            $rows   = $this->fetchRows($sql);
            $result = array();
            foreach ($rows as $r) {
                $result[(int)$r['id']] = (string)$r['label'];
            }
            return $result;
        }

        // ── select: mapa estático ─────────────────────────────────────────────
        if (isset(self::$SELECT_LABELS[$field])) {
            return self::$SELECT_LABELS[$field];
        }

        // ── varchar: valores distintos de la BD ───────────────────────────────
        $sql = 'SELECT DISTINCT TRIM(se.'.$field.') AS val'
            .' FROM '.MAIN_DB_PREFIX.'societe_extrafields se'
            .' INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = se.fk_object'
            .' WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.$entity
            .' AND se.'.$field.' IS NOT NULL AND se.'.$field.' != \'\''
            .' ORDER BY val ASC';

        $rows   = $this->fetchRows($sql);
        $result = array();
        foreach ($rows as $r) {
            $result[$r['val']] = $r['val'];
        }
        return $result;
    }

    /**
     * Obtiene todos los valores únicos de tipo_atencion en extcons
     */
    public function getUniqueTiposAtencion()
    {
        $entity = (int) $GLOBALS['conf']->entity;

        $sql = 'SELECT DISTINCT TRIM(c.tipo_atencion) AS val'
            .' FROM '.MAIN_DB_PREFIX.'cabinetmed_extcons c'
            .' WHERE c.entity = '.$entity
            .' AND c.tipo_atencion IS NOT NULL AND c.tipo_atencion != \'\''
            .' ORDER BY val ASC';

        $rows   = $this->fetchRows($sql);
        $result = array();
        foreach ($rows as $r) {
            $result[$r['val']] = $r['val'];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // UTILIDADES INTERNAS
    // -------------------------------------------------------------------------

    /**
     * Valores únicos de departamento presentes en pacientes.
     * @return array [rowid => nom]
     */
    public function getUniqueDepartamentos()
    {
        $entity = (int) $GLOBALS['conf']->entity;

        $sql = 'SELECT dep.rowid AS id, dep.nom AS label'
            .' FROM '.MAIN_DB_PREFIX.'c_departements dep'
            .' INNER JOIN '.MAIN_DB_PREFIX.'societe s ON s.fk_departement = dep.rowid'
            .' WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.$entity
            .' GROUP BY dep.rowid, dep.nom'
            .' ORDER BY dep.nom ASC';

        $rows = $this->fetchRows($sql);
        $result = array();
        foreach ($rows as $r) {
            $result[(int)$r['id']] = (string)$r['label'];
        }
        return $result;
    }

    /**
     * Valores únicos de ciudad (town) presentes en pacientes.
     * @return array [town => town]
     */
    public function getUniqueCiudades()
    {
        $entity = (int) $GLOBALS['conf']->entity;

        $sql = 'SELECT DISTINCT TRIM(s.town) AS val'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .' WHERE s.canvas = \'patient@cabinetmed\' AND s.entity = '.$entity
            .' AND s.town IS NOT NULL AND s.town != \'\''
            .' ORDER BY val ASC';

        $rows = $this->fetchRows($sql);
        $result = array();
        foreach ($rows as $r) {
            $result[$r['val']] = $r['val'];
        }
        return $result;
    }

    /**
     * Distribución de consultas por gestor (usuario que realizó la consulta).
     * @return array [['gestor' => nombre, 'total' => N, 'pacientes_unicos' => N], ...]
     */
    public function getConsultationsByGestor()
    {
        $built = $this->buildWhere(true);
        $extraJoin = ' LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = c.fk_user';

        $sql = 'SELECT'
            .' COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,\'\'), \' \', COALESCE(u.lastname,\'\'))), \'\'), \'(Sin asignar)\') AS gestor,'
            .' COUNT(c.rowid) AS total,'
            .' COUNT(DISTINCT c.fk_soc) AS pacientes_unicos'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$extraJoin
            .$built['where']
            .' GROUP BY c.fk_user, u.firstname, u.lastname'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución de consultas por departamento del paciente.
     * @return array [['categoria' => nom, 'total' => N], ...]
     */
    public function getConsultationsByDepartamento()
    {
        $built = $this->buildWhere(true);
        $extraJoin = ' LEFT JOIN '.MAIN_DB_PREFIX.'c_departements dep ON dep.rowid = s.fk_departement';

        $sql = 'SELECT COALESCE(NULLIF(TRIM(dep.nom), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$extraJoin
            .$built['where']
            .' GROUP BY dep.rowid, dep.nom'
            .' ORDER BY total DESC';

        return $this->fetchRows($sql);
    }

    /**
     * Distribución de consultas por ciudad del paciente.
     * @return array [['categoria' => town, 'total' => N], ...]
     */
    public function getConsultationsByCiudad()
    {
        $built = $this->buildWhere(true);

        $sql = 'SELECT COALESCE(NULLIF(TRIM(s.town), \'\'), \'(Sin dato)\') AS categoria,'
            .' COUNT(c.rowid) AS total'
            .' FROM '.MAIN_DB_PREFIX.'societe s'
            .$built['joins']
            .$built['where']
            .' GROUP BY s.town'
            .' ORDER BY total DESC'
            .' LIMIT 20';

        return $this->fetchRows($sql);
    }

    /**
     * Ejecuta una SQL y retorna array de arrays asociativos
     *
     * @param  string $sql
     * @return array
     */
    private function fetchRows($sql)
    {
        $result = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $result[] = (array) $obj;
            }
            $this->db->free($resql);
        }
        return $result;
    }
}
