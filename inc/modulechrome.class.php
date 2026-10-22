<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Moldura padrão das telas de módulo
 * -------------------------------------------------------------------------
 * Peças que toda página de configuração de módulo mostra e que mudam juntas para todos os módulos
 * quando a base muda (decisão do owner, 2026-10-21):
 *  - tabFooter(): o mesmo rodapé das abas da configuração do NexTool (versões, ambiente, links), no fim
 *    de CADA aba do módulo. O módulo chama no displayTabContentForItem, depois do conteúdo da aba.
 * A vitrine de serviço do topo (hero) é o PluginNextoolOfferBanner, formato 'hero'.
 *
 * Módulo em base antiga: testar class_exists('PluginNextoolModuleChrome') antes de chamar.
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

class PluginNextoolModuleChrome {

   /** Rodapé padrão (front/tabs/config.footer.inc.php, o mesmo das abas da base). Nunca derruba a aba. */
   public static function tabFooter(): string {
      try {
         ob_start();
         include NEXTOOL_PHP_DIR . '/front/tabs/config.footer.inc.php';
         return (string) ob_get_clean();
      } catch (\Throwable $e) {
         if (ob_get_level() > 0) {
            ob_end_clean();
         }
         return '';
      }
   }
}
