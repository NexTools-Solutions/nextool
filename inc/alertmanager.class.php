<?php
declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAlertManager {

   private const TABLE = 'glpi_plugin_nextool_main_alerts';
   private const ALLOWED_HTML_TAGS = '<p><br><a><strong><b><em><i><u><ul><ol><li><span><div><h1><h2><h3><h4><h5><h6><hr>';

   public static function sanitizeBody(string $body): string {
      $clean = strip_tags($body, self::ALLOWED_HTML_TAGS);
      // Remove atributos perigosos que strip_tags preserva
      $clean = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
      $clean = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $clean);
      $clean = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="#blocked-', $clean);
      return $clean;
   }

   public static function getUnreadAlerts(): array {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return []; }
      $iterator = $DB->request([
         'FROM'  => self::TABLE,
         'WHERE' => ['is_read' => 0],
         'ORDER' => 'date_received DESC',
      ]);
      $alerts = [];
      foreach ($iterator as $row) { $alerts[] = $row; }
      return $alerts;
   }

   public static function getAlertHistory(int $limit = 30): array {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return []; }
      $iterator = $DB->request([
         'FROM'  => self::TABLE,
         'ORDER' => 'date_received DESC',
         'LIMIT' => $limit,
      ]);
      $alerts = [];
      foreach ($iterator as $row) { $alerts[] = $row; }
      return $alerts;
   }

   public static function markAsRead(int $id): void {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return; }
      $DB->update(self::TABLE, ['is_read' => 1, 'date_read' => date('Y-m-d H:i:s')], ['id' => $id]);
   }

   public static function markAllAsRead(): void {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return; }
      $DB->update(self::TABLE, ['is_read' => 1, 'date_read' => date('Y-m-d H:i:s')], ['is_read' => 0]);
   }

   public static function getUnreadCount(): int {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return 0; }
      return countElementsInTable(self::TABLE, ['is_read' => 0]);
   }

   public static function getTypeIcon(string $type): string {
      return match ($type) {
         'warning'  => 'ti ti-alert-triangle text-warning',
         'promo'    => 'ti ti-discount-2 text-purple',
         'critical' => 'ti ti-alert-octagon text-danger',
         default    => 'ti ti-info-circle text-info',
      };
   }

   public static function getTypeBadgeClass(string $type): string {
      return match ($type) {
         'warning'  => 'bg-warning text-dark',
         'promo'    => 'bg-purple text-white',
         'critical' => 'bg-danger text-white',
         default    => 'bg-info text-white',
      };
   }
}
