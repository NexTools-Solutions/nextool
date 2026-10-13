<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - License Validation Attempts
 * -------------------------------------------------------------------------
 * Fachada de gravação das tentativas de validação de licença. Desde a
 * unificação dos logs, as tentativas vão para a tabela única
 * glpi_plugin_nextool_main_logs (log_type = validation), exibida na aba Logs
 * pela grade de PluginNextoolEventLog.
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

require_once __DIR__ . '/eventlog.class.php';

class PluginNextoolValidationAttempt {

   /**
    * Registra uma tentativa de validação
    *
    * @param array $data
    *   - result (bool|int)
    *   - message (string)
    *   - http_code (int|null)
    *   - response_time_ms (int|null)
    *   - origin (string)
    *   - requested_modules (array|string|null)
    *   - client_identifier (string)
    *   - license_status (string)
    *   - plan (string)
    *   - force_refresh (bool|int)
    *   - cache_hit (bool|int)
    *   - user_id (int)
    */
   public static function logAttempt(array $data) {
      return PluginNextoolEventLog::logValidation($data);
   }
}
