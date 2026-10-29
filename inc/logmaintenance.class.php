<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Log Maintenance
 * -------------------------------------------------------------------------
 * Rotinas de manutenção de logs/auditoria do NexTool Solutions, responsáveis
 * por purgar registros antigos e evitar crescimento descontrolado das
 * tabelas de histórico.
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

class PluginNextoolLogMaintenance {

   const CACHE_FILENAME = 'nextool_log_maintenance.ts';

   /**
    * Executa limpeza de logs caso o intervalo mínimo tenha passado.
    *
    * @param int $ttlHours
    * @param int $retentionDays
    */
   public static function maybeRun($ttlHours = 12, $retentionDays = 120) {
      $lastRun = self::getLastRunTimestamp();
      if ($lastRun !== null && (time() - $lastRun) < ($ttlHours * 3600)) {
         return false;
      }

      $purged = self::purgeOldRecords($retentionDays);
      if ($purged) {
         self::setLastRunTimestamp(time());
      }

      return $purged;
   }

   /**
    * Remove registros antigos das tabelas de auditoria.
    *
    * @param int $retentionDays
    * @return bool
    */
   public static function purgeOldRecords($retentionDays = 120) {
      global $DB;

      $threshold = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
      $tables = [
         ['table' => 'glpi_plugin_nextool_main_logs', 'column' => 'date'],
      ];

      $affected = 0;
      foreach ($tables as $entry) {
         if (!$DB->tableExists($entry['table'])) {
            continue;
         }
         $result = $DB->delete($entry['table'], [
            $entry['column'] => ['<', $threshold]
         ]);
         if ($result) {
            $affected += PluginNextoolCompat::affectedRows($DB);
         }
      }

      // LGPD (política de log: 30 dias). Falhas técnicas de módulo e as linhas DEBUG das
      // tabelas de log dos módulos não seguem os 120 dias acima; registros operacionais e
      // as retenções configuradas em cada módulo continuam como estão.
      $logThreshold = date('Y-m-d H:i:s', time() - (self::DIAGNOSTIC_RETENTION_DAYS * 86400));
      if ($DB->tableExists('glpi_plugin_nextool_main_logs')
          && $DB->delete('glpi_plugin_nextool_main_logs', ['log_type' => 'module_error', 'date' => ['<', $logThreshold]])) {
         $affected += PluginNextoolCompat::affectedRows($DB);
      }
      $affected += self::purgeModuleDebugRows($logThreshold);
      try {
         require_once __DIR__ . '/modulediagnostic.class.php';
         PluginNextoolModuleDiagnostic::expireWindows();
      } catch (\Throwable $e) {
         // a expiração também vale pelo isDebug(); a regravação fica para a próxima passada
      }

      if ($affected > 0) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'LogMaintenance: removidos %d registros antigos (retention=%d dias)',
            $affected,
            $retentionDays
         ));
      }

      return true;
   }

   const DIAGNOSTIC_RETENTION_DAYS = 30;

   /**
    * Linhas DEBUG (modo diagnóstico) das tabelas de LOG dos módulos mais velhas que o limite.
    * Só tabelas `*_log`/`*_logs` com `level` de TEXTO: há tabela de módulo com `level` numérico
    * (azuredevops_classifications), onde `level IN ('DEBUG')` vira `level = 0` ou erro no modo
    * estrito. Uma consulta só ao information_schema; falha numa tabela não para as outras.
    */
   private static function purgeModuleDebugRows(string $threshold): int {
      global $DB;
      $affected = 0;
      try {
         $iterator = $DB->request([
            'SELECT' => ['TABLE_NAME'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
               'TABLE_SCHEMA' => $DB->dbdefault,
               'COLUMN_NAME'  => 'level',
               'DATA_TYPE'    => ['varchar', 'char'],
               'TABLE_NAME'   => ['LIKE', 'glpi\\_plugin\\_nextool\\_%'],
            ],
         ]);
         $tables = [];
         foreach ($iterator as $row) {
            $tables[] = (string) ($row['TABLE_NAME'] ?? '');
         }
      } catch (\Throwable $e) {
         return 0;
      }
      foreach ($tables as $table) {
         if (preg_match('/^glpi_plugin_nextool_[a-z0-9_]+_logs?$/', $table) !== 1 || $table === 'glpi_plugin_nextool_main_logs') {
            continue;
         }
         try {
            $dateCol = null;
            foreach (['date_creation', 'log_date', 'date'] as $col) {
               if ($DB->fieldExists($table, $col)) {
                  $dateCol = $col;
                  break;
               }
            }
            if ($dateCol !== null && $DB->delete($table, ['level' => ['DEBUG', 'debug'], $dateCol => ['<', $threshold]])) {
               $affected += PluginNextoolCompat::affectedRows($DB);
            }
         } catch (\Throwable $e) {
            // segue para a próxima tabela
         }
      }
      return $affected;
   }

   private static function getCacheDir() {
      if (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR)) {
         return GLPI_CACHE_DIR;
      }
      return sys_get_temp_dir();
   }

   private static function getCacheFile() {
      return rtrim(self::getCacheDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CACHE_FILENAME;
   }

   private static function getLastRunTimestamp() {
      $file = self::getCacheFile();
      if (!file_exists($file)) {
         return null;
      }
      $content = trim(@file_get_contents($file));
      if ($content === '') {
         return null;
      }
      return (int)$content;
   }

   private static function setLastRunTimestamp($timestamp) {
      $file = self::getCacheFile();
      @file_put_contents($file, (string)$timestamp);
   }
}


