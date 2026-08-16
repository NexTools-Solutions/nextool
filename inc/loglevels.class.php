<?php
/**
 * NexTool Solutions - Helpers de NÍVEL de log (rótulo + badge)
 * -------------------------------------------------------------------------
 * Fonte ÚNICA da apresentação de nível nas grades de log dos módulos, promovida
 * ao plugin base (finding /audit-deep ME-06 -- antes duplicada entre workflow e
 * whatsappbot). Tolerante a AMBOS os vocabulários em circulação (WARN/WARNING,
 * FATAL/CRITICAL, maiúsculo ou minúsculo), então cada módulo pode delegar sem
 * ter de canonizar seus dados gravados.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolLogLevels {

   /** Rótulo traduzido do nível (aceita qualquer caixa/vocabulário). */
   public static function label(string $level): string {
      return match (strtoupper($level)) {
         'FATAL'           => __('Fatal', 'nextool'),
         'ERROR'           => __('Erro', 'nextool'),
         'WARN', 'WARNING' => __('Aviso', 'nextool'),
         'INFO'            => __('Informação', 'nextool'),
         'DEBUG'           => __('Debug', 'nextool'),
         default           => $level,
      };
   }

   /** Classe de badge Tabler por nível. */
   public static function badgeClass(string $level): string {
      return match (strtoupper($level)) {
         'FATAL', 'ERROR', 'CRITICAL' => 'bg-red text-white',
         'WARN', 'WARNING'            => 'bg-orange text-white',
         'INFO'                       => 'bg-blue-lt',
         'DEBUG'                      => 'bg-secondary text-white',
         default                      => 'bg-secondary text-white',
      };
   }

   /** Badge HTML pronto (rótulo + cor). */
   public static function badge(string $level): string {
      return "<span class='badge " . self::badgeClass($level) . "'>"
         . Html::entities_deep(self::label($level)) . "</span>";
   }

   /**
    * Opções para o dropdown de filtro (chave = valor gravado). Passe os níveis
    * que o módulo realmente usa (ex.: ['ERROR','WARN','INFO','DEBUG']).
    *
    * @param array<int,string> $levels
    * @return array<string,string>
    */
   public static function selectOptions(array $levels): array {
      $opts = [];
      foreach ($levels as $lvl) {
         $opts[$lvl] = self::label($lvl);
      }
      return $opts;
   }
}
