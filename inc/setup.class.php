<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Setup Class
 * -------------------------------------------------------------------------
 * Classe de setup responsável por adicionar a aba do NexTool Solutions em
 * "Configurar → Geral" e integrar o formulário principal de configuração.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSetup extends CommonGLPI {

   static $rightname = 'config';

   /**
    * Retorna nome da aba que será exibida em "Configurar → Geral"
    * Usa mesmo estilo dos plugins NexTool (ícone + nome)
    */
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Config) {
         return "<span class='d-inline-flex align-items-center gap-2'><i class='ti ti-tool'></i><span>" . __('NexTool Solutions', 'nextool') . "</span></span>";
      }
      return '';
   }

   /**
    * Exibe conteúdo da aba
    * Inclui o formulário de configuração do plugin
    */
   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Config) {
         include GLPI_ROOT . '/plugins/nextool/front/config.form.php';
      }
      return true;
   }
}

