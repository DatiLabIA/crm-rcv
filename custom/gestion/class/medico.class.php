<?php
/* Copyright (C) 2024 DatiLab - GPL v3 */
require_once __DIR__.'/gestioncommon.class.php';

class Medico extends GestionCommon
{
    public $element = 'medico';
    public $table_element = 'gestion_medico';
    public $ref;
    public $nombre;
    public $tipo_doc;
    public $numero_identificacion;
    public $tarjeta_profesional;
    public $ciudades = array();
    public $departamentos = array();
    public $especialidades = array();
    public $eps_ids = array(); // IDs de EPS asociadas

    public static $tipos_documento = array(
        'CC' => 'Cédula de Ciudadanía', 'CE' => 'Cédula de Extranjería',
        'PA' => 'Pasaporte', 'TI' => 'Tarjeta de Identidad', 'NIT' => 'NIT'
    );

    /**
     * Departamentos de Colombia
     */
    public static $lista_departamentos = array(
        'Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bogotá D.C.', 'Bolívar',
        'Boyacá', 'Caldas', 'Caquetá', 'Casanare', 'Cauca', 'Cesar', 'Chocó',
        'Córdoba', 'Cundinamarca', 'Guainía', 'Guaviare', 'Huila', 'La Guajira',
        'Magdalena', 'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 'Quindío',
        'Risaralda', 'San Andrés y Providencia', 'Santander', 'Sucre', 'Tolima',
        'Valle del Cauca', 'Vaupés', 'Vichada'
    );

    /**
     * Ciudades principales de Colombia
     */
    public static $lista_ciudades = array(
        'Arauca', 'Armenia', 'Barranquilla', 'Bogotá', 'Bucaramanga', 'Buenaventura',
        'Cali', 'Cartagena', 'Cúcuta', 'Florencia', 'Ibagué', 'Leticia', 'Manizales',
        'Medellín', 'Mitú', 'Mocoa', 'Montería', 'Neiva', 'Pasto', 'Pereira',
        'Popayán', 'Puerto Carreño', 'Puerto Inírida', 'Quibdó', 'Riohacha',
        'San Andrés', 'San José del Guaviare', 'Santa Marta', 'Sincelejo',
        'Tunja', 'Valledupar', 'Villavicencio', 'Yopal',
        'Apartadó', 'Barrancabermeja', 'Bello', 'Cartago', 'Chía', 'Dosquebradas',
        'Duitama', 'Envigado', 'Facatativá', 'Floridablanca', 'Fusagasugá',
        'Girardot', 'Girón', 'Guadalajara de Buga', 'Ipiales', 'Itagüí',
        'Lorica', 'Magangué', 'Maicao', 'Palmira', 'Piedecuesta',
        'Pitalito', 'Rionegro', 'Sabaneta', 'Sahagún', 'Santa Rosa de Cabal',
        'Santiago de Cali', 'Soacha', 'Sogamoso', 'Soledad', 'Tuluá',
        'Tumaco', 'Turbaco', 'Turbo', 'Uribia', 'Zipaquirá'
    );

    /**
     * Especialidades médicas
     */
    public static $lista_especialidades = array(
        'Alergología', 'Anestesiología', 'Cardiología', 'Cirugía Cardiovascular',
        'Cirugía de Cabeza y Cuello', 'Cirugía de Mano', 'Cirugía de Tórax',
        'Cirugía General', 'Cirugía Pediátrica', 'Cirugía Plástica',
        'Cirugía Vascular', 'Dermatología', 'Endocrinología',
        'Endoscopia Digestiva', 'Fisiatría', 'Gastroenterología',
        'Genética Médica', 'Geriatría', 'Ginecología y Obstetricia',
        'Hematología', 'Hepatología', 'Infectología', 'Inmunología',
        'Medicina del Dolor', 'Medicina del Trabajo', 'Medicina Deportiva',
        'Medicina Familiar', 'Medicina Física y Rehabilitación',
        'Medicina General', 'Medicina Interna', 'Medicina Nuclear',
        'Nefrología', 'Neonatología', 'Neumología', 'Neurocirugía',
        'Neurología', 'Nutriología', 'Oftalmología', 'Oncología',
        'Ortopedia y Traumatología', 'Otorrinolaringología', 'Patología',
        'Pediatría', 'Psiquiatría', 'Radiología e Imágenes Diagnósticas',
        'Reumatología', 'Toxicología', 'Urología'
    );

    public function __construct($db) { $this->db = $db; }

    public function create($user)
    {
        $this->cleanAttributes();
        $this->db->begin();

        list($cf, $cv) = $this->getCommonInsertFields($user);
        $ciudadesJson = json_encode(is_array($this->ciudades) ? $this->ciudades : array());
        $departamentosJson = json_encode(is_array($this->departamentos) ? $this->departamentos : array());
        $especialidadesJson = json_encode(is_array($this->especialidades) ? $this->especialidades : array());

        $sql = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_element." (ref, nombre, tipo_doc, numero_identificacion, tarjeta_profesional, ciudades, departamentos, especialidades, $cf) VALUES (";
        $sql .= "'".$this->db->escape($this->ref)."','".$this->db->escape($this->nombre)."','".$this->db->escape($this->tipo_doc)."','".$this->db->escape($this->numero_identificacion)."','".$this->db->escape($this->tarjeta_profesional)."','".$this->db->escape($ciudadesJson)."','".$this->db->escape($departamentosJson)."','".$this->db->escape($especialidadesJson)."', $cv)";

        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);

        // Guardar relaciones EPS
        if ($this->saveEpsRelations() < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $this->id;
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, ref, nombre, tipo_doc, numero_identificacion, tarjeta_profesional, ciudades, departamentos, especialidades, datec, tms, entity FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$id;
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            $this->id = $obj->rowid;
            $this->ref = $obj->ref;
            $this->nombre = $obj->nombre;
            $this->tipo_doc = $obj->tipo_doc;
            $this->numero_identificacion = $obj->numero_identificacion;
            $this->tarjeta_profesional = $obj->tarjeta_profesional;
            $this->ciudades = !empty($obj->ciudades) ? json_decode($obj->ciudades, true) : array();
            $this->departamentos = !empty($obj->departamentos) ? json_decode($obj->departamentos, true) : array();
            $this->especialidades = !empty($obj->especialidades) ? json_decode($obj->especialidades, true) : array();
            $this->datec = $this->db->jdate($obj->datec);
            $this->entity = $obj->entity;

            // Asegurar que sean arrays
            if (!is_array($this->ciudades)) $this->ciudades = array();
            if (!is_array($this->departamentos)) $this->departamentos = array();
            if (!is_array($this->especialidades)) $this->especialidades = array();

            $this->db->free($resql);

            // Cargar relaciones EPS
            $this->fetchEpsRelations();

            return 1;
        }
        return 0;
    }

    public function update($user)
    {
        $this->cleanAttributes();
        $this->db->begin();

        $ciudadesJson = json_encode(is_array($this->ciudades) ? $this->ciudades : array());
        $departamentosJson = json_encode(is_array($this->departamentos) ? $this->departamentos : array());
        $especialidadesJson = json_encode(is_array($this->especialidades) ? $this->especialidades : array());

        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET ";
        $sql .= "ref='".$this->db->escape($this->ref)."', ";
        $sql .= "nombre='".$this->db->escape($this->nombre)."', ";
        $sql .= "tipo_doc='".$this->db->escape($this->tipo_doc)."', ";
        $sql .= "numero_identificacion='".$this->db->escape($this->numero_identificacion)."', ";
        $sql .= "tarjeta_profesional='".$this->db->escape($this->tarjeta_profesional)."', ";
        $sql .= "ciudades='".$this->db->escape($ciudadesJson)."', ";
        $sql .= "departamentos='".$this->db->escape($departamentosJson)."', ";
        $sql .= "especialidades='".$this->db->escape($especialidadesJson)."', ";
        $sql .= $this->getCommonUpdateFields($user);
        $sql .= " WHERE rowid = ".(int)$this->id;

        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        // Actualizar relaciones EPS
        if ($this->saveEpsRelations() < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return 1;
    }

    public function delete($user)
    {
        $this->db->begin();
        // Eliminar relaciones EPS
        if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."gestion_medico_eps WHERE fk_medico = ".(int)$this->id)) {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            return -1;
        }
        if ($this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$this->table_element." WHERE rowid = ".(int)$this->id)) {
            $this->db->commit();
            return 1;
        }
        $this->error = $this->db->lasterror();
        $this->db->rollback();
        return -1;
    }

    /**
     * Cargar IDs de EPS asociadas desde tabla pivote
     */
    public function fetchEpsRelations()
    {
        $this->eps_ids = array();
        $sql = "SELECT fk_eps FROM ".MAIN_DB_PREFIX."gestion_medico_eps WHERE fk_medico = ".(int)$this->id;
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $this->eps_ids[] = (int)$obj->fk_eps;
            }
            $this->db->free($resql);
        }
        return count($this->eps_ids);
    }

    /**
     * Guardar relaciones EPS (elimina y recrea)
     */
    private function saveEpsRelations()
    {
        // Eliminar relaciones actuales
        if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."gestion_medico_eps WHERE fk_medico = ".(int)$this->id)) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        // Insertar nuevas relaciones
        if (is_array($this->eps_ids)) {
            foreach ($this->eps_ids as $eps_id) {
                $eps_id = (int)$eps_id;
                if ($eps_id > 0) {
                    $sql = "INSERT INTO ".MAIN_DB_PREFIX."gestion_medico_eps (fk_medico, fk_eps) VALUES (".(int)$this->id.", ".$eps_id.")";
                    if (!$this->db->query($sql)) {
                        $this->error = $this->db->lasterror();
                        return -1;
                    }
                }
            }
        }
        return 1;
    }

    /**
     * Obtener nombres de EPS asociadas
     */
    public function getEpsNames()
    {
        $names = array();
        if (empty($this->eps_ids)) return $names;

        $sql = "SELECT rowid, codigo, descripcion FROM ".MAIN_DB_PREFIX."gestion_eps WHERE rowid IN (".implode(',', array_map('intval', $this->eps_ids)).") ORDER BY descripcion";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $names[] = $obj->codigo.' - '.$obj->descripcion;
            }
            $this->db->free($resql);
        }
        return $names;
    }

    public function selectTipoDocumento($selected = '', $htmlname = 'tipo_doc')
    {
        $out = '<select class="flat minwidth100" name="'.$htmlname.'" id="'.$htmlname.'"><option value="">&nbsp;</option>';
        foreach (self::$tipos_documento as $code => $label) {
            $out .= '<option value="'.$code.'"'.($code == $selected ? ' selected' : '').'>'.dol_escape_htmltag($code.' - '.$label).'</option>';
        }
        return $out.'</select>';
    }

    /**
     * Genera un select2 multiselect para ciudades
     */
    public function selectCiudadesMulti($selected = array(), $htmlname = 'ciudades')
    {
        if (!is_array($selected)) $selected = array();
        $out = '<select class="flat minwidth300 select2-multi" name="'.$htmlname.'[]" id="'.$htmlname.'" multiple="multiple">';
        $allCiudades = self::$lista_ciudades;
        sort($allCiudades);
        foreach ($allCiudades as $ciudad) {
            $out .= '<option value="'.dol_escape_htmltag($ciudad).'"'.(in_array($ciudad, $selected) ? ' selected' : '').'>'.dol_escape_htmltag($ciudad).'</option>';
        }
        $out .= '</select>';
        return $out;
    }

    /**
     * Genera un select2 multiselect para departamentos
     */
    public function selectDepartamentosMulti($selected = array(), $htmlname = 'departamentos')
    {
        if (!is_array($selected)) $selected = array();
        $out = '<select class="flat minwidth300 select2-multi" name="'.$htmlname.'[]" id="'.$htmlname.'" multiple="multiple">';
        foreach (self::$lista_departamentos as $dep) {
            $out .= '<option value="'.dol_escape_htmltag($dep).'"'.(in_array($dep, $selected) ? ' selected' : '').'>'.dol_escape_htmltag($dep).'</option>';
        }
        $out .= '</select>';
        return $out;
    }

    /**
     * Genera un select2 multiselect para especialidades
     */
    public function selectEspecialidadesMulti($selected = array(), $htmlname = 'especialidades')
    {
        if (!is_array($selected)) $selected = array();
        $out = '<select class="flat minwidth300 select2-multi" name="'.$htmlname.'[]" id="'.$htmlname.'" multiple="multiple">';
        foreach (self::$lista_especialidades as $esp) {
            $out .= '<option value="'.dol_escape_htmltag($esp).'"'.(in_array($esp, $selected) ? ' selected' : '').'>'.dol_escape_htmltag($esp).'</option>';
        }
        $out .= '</select>';
        return $out;
    }

    /**
     * Genera un select2 multiselect para EPS (desde la tabla de EPS)
     */
    public function selectEpsMulti($selected = array(), $htmlname = 'eps_ids')
    {
        global $conf;
        if (!is_array($selected)) $selected = array();

        $sql = "SELECT rowid, codigo, descripcion FROM ".MAIN_DB_PREFIX."gestion_eps WHERE entity = ".$conf->entity." ORDER BY descripcion";
        $out = '<select class="flat minwidth300 select2-multi" name="'.$htmlname.'[]" id="'.$htmlname.'" multiple="multiple">';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $out .= '<option value="'.$obj->rowid.'"'.(in_array($obj->rowid, $selected) ? ' selected' : '').'>'.dol_escape_htmltag($obj->codigo.' - '.$obj->descripcion).'</option>';
            }
            $this->db->free($resql);
        }
        $out .= '</select>';
        return $out;
    }
}
