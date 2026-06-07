<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Base Audit Log
 * -------------------------------------------------------------------------
 * Classe abstract com helpers compartilhados pelas várias trilhas de
 * auditoria do plugin (ModuleAudit, ConfigAudit, CoreUpdateLog,
 * ValidationAttempt). Centraliza:
 *  - resolução implícita de user_id via Session
 *  - resolução de source_ip via REMOTE_ADDR
 *  - encode condicional JSON para campos que aceitam array
 *  - truncamento null-safe para campos com tamanho fixo
 *  - emissão de Log::history quando o GLPI tiver a API disponível
 *
 * As subclasses continuam responsáveis por declarar `getTable()` e
 * implementar seu próprio `log()` (cada trilha tem colunas distintas).
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

abstract class PluginNextoolBaseAuditLog extends CommonDBTM {

   public static $rightname = 'config';

   /**
    * Resolve user_id: usa o valor passado explicitamente, ou cai para Session::getLoginUserID().
    */
   protected static function resolveUserId(?int $explicit = null): ?int {
      if ($explicit !== null) {
         return $explicit;
      }
      if (class_exists('Session')) {
         $sessionUser = Session::getLoginUserID();
         return $sessionUser ? (int)$sessionUser : null;
      }
      return null;
   }

   /**
    * Resolve source_ip: usa explicito, ou cai para REMOTE_ADDR.
    */
   protected static function resolveSourceIp(?string $explicit = null): ?string {
      if ($explicit !== null && $explicit !== '') {
         return $explicit;
      }
      return $_SERVER['REMOTE_ADDR'] ?? null;
   }

   /**
    * Encoda como JSON se for array, caso contrário devolve o valor inalterado (incluindo null).
    */
   protected static function jsonEncodeIfArray(mixed $value): mixed {
      if (is_array($value)) {
         // array_values() SÓ para listas sequenciais (normaliza chaves esparsas).
         // Mapas associativos (ex.: 'details' com chaves nomeadas) preservam as
         // chaves -- senão formatDetails() exibe índices numéricos em vez dos nomes.
         $encodable = array_is_list($value) ? array_values($value) : $value;
         return json_encode($encodable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      return $value;
   }

   /**
    * Trunca string para $max caracteres preservando null.
    */
   protected static function truncate(mixed $value, int $max): ?string {
      if ($value === null) {
         return null;
      }
      return substr((string)$value, 0, $max);
   }

   /**
    * Emite Log::history em PluginNextoolMainConfig quando disponível.
    * Não-fatal: silencia se Log não existir ou não suportar history().
    */
   protected static function recordHistory(string $action, string $message): void {
      if (!class_exists('Log') || !method_exists('Log', 'history')) {
         return;
      }
      Log::history(
         1,
         'PluginNextoolMainConfig',
         [
            0,
            '',
            sprintf('[%s] %s', strtoupper($action), mb_substr($message, 0, 200))
         ],
         '',
         Log::HISTORY_LOG_SIMPLE_MESSAGE
      );
   }
}
