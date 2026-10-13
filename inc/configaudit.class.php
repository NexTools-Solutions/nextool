<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Audit
 * -------------------------------------------------------------------------
 * Fachada de gravação das alterações de configuração/licença do NexTool
 * (seção, ação, resultado, usuário e detalhes). Desde a unificação dos logs,
 * os eventos vão para a tabela única glpi_plugin_nextool_main_logs
 * (log_type = config), exibida na aba Logs pela grade de PluginNextoolEventLog.
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

class PluginNextoolConfigAudit {

   /**
    * @param array $data section, action, result, message, user_id, source_ip, details
    */
   public static function log(array $data) {
      return PluginNextoolEventLog::logConfig($data);
   }
}
