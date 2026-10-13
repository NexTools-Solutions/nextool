<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Audit
 * -------------------------------------------------------------------------
 * Fachada de gravação das ações em módulos (install/enable/disable/
 * uninstall/update...). Desde a unificação dos logs, as ações vão para a
 * tabela única glpi_plugin_nextool_main_logs (log_type = module), exibida na
 * aba Logs pela grade de PluginNextoolEventLog.
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

class PluginNextoolModuleAudit {

   /**
    * Registra uma ação de módulo
    *
    * @param array $data module_key, action, result, message, user_id, origin, source_ip,
    *                    license_status, plan, allowed_modules, requested_modules
    */
   public static function log(array $data) {
      return PluginNextoolEventLog::logModule($data);
   }
}
