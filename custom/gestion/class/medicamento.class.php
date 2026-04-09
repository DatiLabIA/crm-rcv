<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require_once __DIR__.'/gestioncommon.class.php';
require_once __DIR__.'/medicamento_concentracion.class.php';

class Medicamento extends GestionCommon
{
    public $element = 'medicamento';
    public $table_element = 'gestion_medicamento';
    public $ref;
    public $etiqueta;
    public $estado;
    public $lines = array();

    public function __construct($db) { $this->db = $db; }

    public function create($user)
    {
        $this->cleanAttributes();
        list($cf, $cv) = $this->getCommonInsertFields($user);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (ref, etiqueta, estado, $cf) VALUES ('".$this->db->escape($this->ref)."','".$this->db->escape($this->etiqueta)."', 1, $cv)";
        if ($this->db->query($sql)) { $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element); return $this->id; }
        $this->error = $this->db->lasterror(); return -1;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, ref, etiqueta, estado, datec, tms, entity FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$id;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            $this->id = $obj->rowid; $this->ref = $obj->ref; $this->etiqueta = $obj->etiqueta; $this->estado = $obj->estado;
            $this->datec = $this->db->jdate($obj->datec); $this->entity = $obj->entity;
            $this->db->free($resql); return 1;
        }
        return 0;
    }

    public function update($user)
    {
        $this->cleanAttributes();
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET ref='".$this->db->escape($this->ref)."', etiqueta='".$this->db->escape($this->etiqueta)."', ".$this->getCommonUpdateFields($user)." WHERE rowid = ".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    public function delete($user)
    {
        $this->db->begin();
        if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."gestion_medicamento_det WHERE fk_medicamento = ".(int)$this->id)) { $this->db->rollback(); return -1; }
        if ($this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$this->id)) { $this->db->commit(); return 1; }
        $this->db->rollback(); return -1;
    }

    public function fetchConcentraciones()
    {
        $this->lines = array();
        $sql = "SELECT rowid, concentracion, unidad, concentracion_display FROM ".MAIN_DB_PREFIX."gestion_medicamento_det WHERE fk_medicamento = ".(int)$this->id." ORDER BY rowid";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $line = new MedicamentoConcentracion($this->db);
                $line->id = $obj->rowid; $line->fk_medicamento = $this->id;
                $line->concentracion = $obj->concentracion; $line->unidad = $obj->unidad;
                $line->concentracion_display = $obj->concentracion_display;
                $this->lines[] = $line;
            }
            $this->db->free($resql);
        }
        return count($this->lines);
    }

    public function addLine($concentracion, $unidad, $user)
    {
        $line = new MedicamentoConcentracion($this->db);
        $line->fk_medicamento = $this->id; $line->concentracion = $concentracion; $line->unidad = $unidad;
        return $line->create($user);
    }

    public function deleteLine($lineid, $user)
    {
        $line = new MedicamentoConcentracion($this->db);
        $line->id = $lineid;
        return $line->delete($user);
    }
}
