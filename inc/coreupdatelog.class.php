<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Core Update Log
 * -------------------------------------------------------------------------
 * Histórico operacional do self-updater do core (check/preflight/prepare/apply)
 * em glpi_plugin_nextool_core_updates.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/baseauditlog.class.php';

class PluginNextoolCoreUpdateLog extends PluginNextoolBaseAuditLog {

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_core_updates';
   }

   public static function log(array $data) {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $record = [
         'action'          => self::truncate($data['action'] ?? 'unknown', 32),
         'status'          => !empty($data['status']) ? 1 : 0,
         'source'          => self::truncate($data['source'] ?? null, 64),
         'current_version' => self::truncate($data['current_version'] ?? null, 64),
         'target_version'  => self::truncate($data['target_version'] ?? null, 64),
         'message'         => $data['message'] ?? null,
         'details'         => self::jsonEncodeIfArray($data['details'] ?? null),
         'duration_ms'     => isset($data['duration_ms']) ? max(0, (int)$data['duration_ms']) : null,
         'finished_at'     => date('Y-m-d H:i:s'),
         'user_id'         => self::resolveUserId(),
      ];

      $log = new self();
      return $log->add($record);
   }
}
