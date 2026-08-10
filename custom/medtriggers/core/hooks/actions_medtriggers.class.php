<?php
/* Copyright (C) 2024-2026 DatiLab
 *
 * Hook para inyectar la configuración de selects dependientes
 * en las fichas de pacientes/terceros (incluye CabinetMed).
 *
 * NOTA: Desde v2.2.0, el JS es autocontenido y funciona sin este hook.
 *       Este hook inyecta window.MedTriggersConf como override opcional
 *       con la URL AJAX resuelta por dol_buildpath (más fiable que auto-detección).
 */

class ActionsMedTriggers
{
    /** @var DoliDB */
    public $db;
    /** @var string */
    public $error = '';
    /** @var string[] */
    public $errors = array();
    /** @var string[] */
    public $resprints = array();
    /** @var string */
    public $results = array();

    /** @var bool Evitar inyección duplicada en la misma página */
    private $alreadyInjected = false;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Inyecta la configuración JS en la página.
     * Se llama desde múltiples hook points para máxima cobertura.
     */
    private function injectConfig($parameters)
    {
        // Evitar inyección duplicada
        if ($this->alreadyInjected) return 0;

        // Inyectar configuración global para el JS
        $ajaxUrl = dol_buildpath('/medtriggers/ajax/get_concentraciones.php', 1);

        $dependentConfig = array(
            'medicamento' => array(
                'childField'     => 'concentracion',
                'ajaxParam'      => 'medicamento_id',
                'emptyLabel'     => '-- Seleccione concentración --',
            )
        );

        $jsConfig = '<script type="text/javascript">'."\n";
        $jsConfig .= 'window.MedTriggersConf = window.MedTriggersConf || {};'."\n";
        $jsConfig .= 'window.MedTriggersConf.ajaxUrl = '.json_encode($ajaxUrl).';'."\n";
        $jsConfig .= 'window.MedTriggersConf.dependentSelects = '.json_encode($dependentConfig).';'."\n";
        $jsConfig .= '</script>'."\n";

        $this->resprints = $jsConfig;
        $this->alreadyInjected = true;

        return 0;
    }

    /**
     * Hook formObjectOptions - Formularios de creación/edición
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectConfig($parameters);
    }

    /**
     * Hook addMoreActionsButtons - Fichas en modo vista
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectConfig($parameters);
    }

    /**
     * Hook formEditUserOptions - Formulario de edición del objeto
     */
    public function formEditUserOptions($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectConfig($parameters);
    }

    /**
     * Hook formCreateThirdpartyOptions - Formulario de creación de tercero
     */
    public function formCreateThirdpartyOptions($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectConfig($parameters);
    }
}
