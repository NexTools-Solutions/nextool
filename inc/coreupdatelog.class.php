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

class PluginNextoolCoreUpdateLog extends CommonDBTM {

   public static $rightname = 'config';

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_core_updates';
   }

   public static function log(array $data) {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $details = $data['details'] ?? null;
      if (is_array($details)) {
         $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }

      $record = [
         'action'          => substr((string)($data['action'] ?? 'unknown'), 0, 32),
         'status'          => !empty($data['status']) ? 1 : 0,
         'source'          => isset($data['source']) ? substr((string)$data['source'], 0, 64) : null,
         'current_version' => isset($data['current_version']) ? substr((string)$data['current_version'], 0, 64) : null,
         'target_version'  => isset($data['target_version']) ? substr((string)$data['target_version'], 0, 64) : null,
         'message'         => $data['message'] ?? null,
         'details'         => $details,
         'duration_ms'     => isset($data['duration_ms']) ? max(0, (int)$data['duration_ms']) : null,
         'finished_at'     => date('Y-m-d H:i:s'),
         'user_id'         => class_exists('Session') ? Session::getLoginUserID() : null,
      ];

      $log = new self();
      return $log->add($record);
   }
}
