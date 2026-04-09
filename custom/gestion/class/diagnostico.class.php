<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require_once __DIR__.'/gestioncommon.class.php';

class Diagnostico extends GestionCommon
{
    public $element = 'diagnostico';
    public $table_element = 'gestion_diagnostico';
    public $codigo;
    public $label;
    public $description;

    public function __construct($db) { $this->db = $db; }

    public function create($user)
    {
        $this->cleanAttributes();
        list($cf, $cv) = $this->getCommonInsertFields($user);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (codigo, label, description, $cf) VALUES ('".$this->db->escape($this->codigo)."','".$this->db->escape($this->label)."','".$this->db->escape($this->description)."', $cv)";
        if ($this->db->query($sql)) { $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element); return $this->id; }
        $this->error = $this->db->lasterror(); return -1;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, codigo, label, description, datec, tms, entity FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$id;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            $this->id = $obj->rowid; $this->codigo = $obj->codigo; $this->label = $obj->label; $this->description = $obj->description;
            $this->datec = $this->db->jdate($obj->datec); $this->entity = $obj->entity;
            $this->db->free($resql); return 1;
        }
        return 0;
    }

    public function update($user)
    {
        $this->cleanAttributes();
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET codigo='".$this->db->escape($this->codigo)."', label='".$this->db->escape($this->label)."', description='".$this->db->escape($this->description)."', ".$this->getCommonUpdateFields($user)." WHERE rowid = ".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    public function delete($user)
    {
        if ($this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$this->id)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }
}
