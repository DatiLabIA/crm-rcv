<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require_once __DIR__.'/gestioncommon.class.php';

class Eps extends GestionCommon
{
    public $element = 'ep';
    public $table_element = 'gestion_eps';
    public $codigo;
    public $descripcion;

    public function __construct($db) { $this->db = $db; }

    public function create($user)
    {
        $this->cleanAttributes();
        list($cf, $cv) = $this->getCommonInsertFields($user);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (codigo, descripcion, $cf) VALUES ('".$this->db->escape($this->codigo)."','".$this->db->escape($this->descripcion)."', $cv)";
        if ($this->db->query($sql)) { $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element); return $this->id; }
        $this->error = $this->db->lasterror(); return -1;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, codigo, descripcion, datec, tms, entity FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$id;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            $this->id = $obj->rowid; $this->codigo = $obj->codigo; $this->descripcion = $obj->descripcion;
            $this->datec = $this->db->jdate($obj->datec); $this->entity = $obj->entity;
            $this->db->free($resql); return 1;
        }
        return 0;
    }

    public function update($user)
    {
        $this->cleanAttributes();
        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET codigo='".$this->db->escape($this->codigo)."', descripcion='".$this->db->escape($this->descripcion)."', ".$this->getCommonUpdateFields($user)." WHERE rowid = ".(int)$this->id;
        if ($this->db->query($sql)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    public function delete($user)
    {
        if ($this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$this->id)) return 1;
        $this->error = $this->db->lasterror(); return -1;
    }

    public function selectEps($selected = 0, $htmlname = 'fk_eps')
    {
        global $conf;
        $sql = "SELECT rowid, codigo, descripcion FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE entity = ".$conf->entity." ORDER BY descripcion";
        $out = '<select class="flat minwidth200" name="'.$htmlname.'" id="'.$htmlname.'"><option value="0">&nbsp;</option>';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $out .= '<option value="'.$obj->rowid.'"'.($obj->rowid == $selected ? ' selected' : '').'>'.dol_escape_htmltag($obj->codigo.' - '.$obj->descripcion).'</option>';
            }
            $this->db->free($resql);
        }
        return $out.'</select>';
    }
}
