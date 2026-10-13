<?php
declare(strict_types=1);
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

require_once __DIR__ . '/compat.class.php';

// Corpo na trait, classe declarada condicionalmente no fim do arquivo: ver inc/config.class.php.
trait PluginNextoolSetupBody {

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
         $GLOBALS['nextool_config_form_included'] = true; // ver cabeçalho de config.form.php (#252)
         include NEXTOOL_PHP_DIR . '/front/config.form.php';
         unset($GLOBALS['nextool_config_form_included']);
      }
      return true;
   }
}

// GLPI 12 tipou `$rightname` no core (fatal se a redeclaracao nao tiver o tipo); no 10/11 o tipo
// e fatal. A unica declaracao valida nos 3 majors e condicional: mesmo corpo (trait), so a
// propriedade muda. Trait com a propriedade NAO serve (conflito de composicao, medido em 2026-10-08).
if (PluginNextoolCompat::typedRightname()) {
   class PluginNextoolSetup extends CommonGLPI {
      use PluginNextoolSetupBody;
      public static string $rightname = 'config';
   }
} else {
   class PluginNextoolSetup extends CommonGLPI {
      use PluginNextoolSetupBody;
      public static $rightname = 'config';
   }
}
