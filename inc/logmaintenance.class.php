<?php
/**
 * Nextools - Log Maintenance
 *
 * Rotinas de manutenção de logs e auditoria do Nextools, responsáveis por purgar
 * registros antigos e evitar crescimento descontrolado das tabelas de histórico.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
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
         ['table' => 'glpi_plugin_nextool_main_validation_attempts', 'column' => 'attempt_date'],
         ['table' => 'glpi_plugin_nextool_main_module_audit',        'column' => 'action_date'],
         ['table' => 'glpi_plugin_nextool_main_config_audit',        'column' => 'event_date'],
      ];

      $affected = 0;
      foreach ($tables as $entry) {
         if (!$DB->tableExists($entry['table'])) {
            continue;
         }
         $result = $DB->delete($entry['table'], [
            $entry['column'] => ['<', $threshold]
         ]);
         if ($result && method_exists($DB, 'affectedRows')) {
            $affected += (int)$DB->affectedRows();
         }
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


