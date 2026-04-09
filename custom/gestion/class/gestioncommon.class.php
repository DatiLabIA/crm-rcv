<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

abstract class GestionCommon extends CommonObject
{
    public $entity;
    public $datec;
    public $tms;
    public $fk_user_creat;
    public $fk_user_modif;

    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0)
    {
        global $langs;
        $label = '<u>'.$langs->trans(ucfirst($this->element)).'</u><br><b>ID:</b> '.$this->id;
        $url = dol_buildpath('/gestion/'.$this->element.'s/card.php', 1).'?id='.$this->id;
        $linkstart = '<a href="'.$url.'" title="'.dol_escape_htmltag($label, 1).'" class="classfortooltip">';
        $result = $linkstart;
        if ($withpicto) $result .= img_object($label, 'generic', 'class="paddingright"');
        $result .= $this->ref ?? $this->id;
        $result .= '</a>';
        return $result;
    }

    public function cleanAttributes()
    {
        foreach (get_object_vars($this) as $key => $value) {
            if (is_string($value)) $this->$key = trim($value);
        }
    }

    protected function getCommonInsertFields($user)
    {
        global $conf;
        return array(
            'datec, fk_user_creat, entity',
            "'".$this->db->idate(dol_now())."', ".$user->id.", ".$conf->entity
        );
    }

    protected function getCommonUpdateFields($user)
    {
        return "fk_user_modif = ".$user->id;
    }
}
