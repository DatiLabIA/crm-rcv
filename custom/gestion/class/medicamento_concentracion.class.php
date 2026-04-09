<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class MedicamentoConcentracion extends CommonObject
{
    public $element = 'medicamento_concentracion';
    public $table_element = 'gestion_medicamento_det';
    public $fk_medicamento;
    public $concentracion;
    public $unidad;
    public $concentracion_display;

    /**
     * Unidades de medida disponibles para concentraciones de medicamentos
     */
    public static $unidades_medida = array(
        'mg'        => 'mg (miligramos)',
        'g'         => 'g (gramos)',
        'mcg'       => 'mcg (microgramos)',
        'ng'        => 'ng (nanogramos)',
        'ml'        => 'ml (mililitros)',
        'L'         => 'L (litros)',
        'UI'        => 'UI (Unidades Internacionales)',
        'mUI'       => 'mUI (mili Unidades Internacionales)',
        'mg/ml'     => 'mg/ml',
        'mg/g'      => 'mg/g',
        'mcg/ml'    => 'mcg/ml',
        'g/L'       => 'g/L',
        'g/100ml'   => 'g/100ml',
        'mg/5ml'    => 'mg/5ml',
        'UI/ml'     => 'UI/ml',
        'mEq'       => 'mEq (miliequivalentes)',
        'mEq/ml'    => 'mEq/ml',
        'mmol'      => 'mmol (milimoles)',
        'mmol/L'    => 'mmol/L',
        '%'         => '% (porcentaje)',
        'ppm'       => 'ppm (partes por millón)',
        'mg/cm2'    => 'mg/cm²',
        'mcg/dosis' => 'mcg/dosis',
        'mg/dosis'  => 'mg/dosis',
    );

    public function __construct($db) { $this->db = $db; }

    public function create($user)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (fk_medicamento, concentracion, unidad) VALUES (".(int)$this->fk_medicamento.",'".$this->db->escape(trim($this->concentracion))."','".$this->db->escape(trim($this->unidad))."')";
        if ($this->db->query($sql)) { $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element); return $this->id; }
        $this->error = $this->db->lasterror(); return -1;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, fk_medicamento, concentracion, unidad, concentracion_display FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$id;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            $this->id = $obj->rowid;
            $this->fk_medicamento = $obj->fk_medicamento;
            $this->concentracion = $obj->concentracion;
            $this->unidad = $obj->unidad;
            $this->concentracion_display = $obj->concentracion_display;
            $this->db->free($resql);
            return 1;
        }
        return 0;
    }

    public function update($user)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET ";
        $sql .= "concentracion = '".$this->db->escape(trim($this->concentracion))."', ";
        $sql .= "unidad = '".$this->db->escape(trim($this->unidad))."' ";
        $sql .= "WHERE rowid = ".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    public function delete($user)
    {
        if ($this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$this->id)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    /**
     * Genera un select para la unidad de medida
     */
    public static function selectUnidad($selected = '', $htmlname = 'unidad', $morecss = '')
    {
        $out = '<select class="flat minwidth100'.($morecss ? ' '.$morecss : '').'" name="'.$htmlname.'" id="'.$htmlname.'">';
        $out .= '<option value="">&nbsp;</option>';
        foreach (self::$unidades_medida as $code => $label) {
            $out .= '<option value="'.dol_escape_htmltag($code).'"'.($code == $selected ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
        }
        $out .= '</select>';
        return $out;
    }

    /**
     * Retorna la concentración concatenada con la unidad (ej: "500 mg")
     * Usa el campo generado de BD si está disponible, sino lo calcula en PHP
     */
    public function getConcentracionDisplay()
    {
        if (!empty($this->concentracion_display)) {
            return $this->concentracion_display;
        }
        // Fallback: calcular en PHP
        $display = trim($this->concentracion);
        if (!empty($this->unidad)) {
            $display .= ' '.$this->unidad;
        }
        return $display;
    }
}
