<?php
declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAlertManager {

   private const TABLE = 'glpi_plugin_nextool_main_alerts';
   /**
    * Leitura POR USUÁRIO (2026-08-06): antes o `is_read` era coluna do próprio
    * alerta -- o primeiro admin que clicava "Entendi" escondia o aviso para TODOS.
    * A leitura agora vive nesta pivot; a coluna `is_read` antiga é mantida como
    * legado (alertas dispensados globalmente antes da migração continuam ocultos).
    */
   private const READS_TABLE = 'glpi_plugin_nextool_main_alert_reads';
   private const ALLOWED_HTML_TAGS = '<p><br><a><strong><b><em><i><u><ul><ol><li><span><div><h1><h2><h3><h4><h5><h6><hr>';

   private static function ensureReadsTable(): void {
      global $DB;
      if ($DB->tableExists(self::READS_TABLE)) {
         return;
      }
      // Shim de DDL: doQuery() só existe a partir do GLPI 10.0.7.
      $ddlMethod = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
      try {
         $DB->$ddlMethod("CREATE TABLE IF NOT EXISTS `" . self::READS_TABLE . "` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `alert_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL,
            `date_read` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_alert_user` (`alert_id`, `users_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha ao criar tabela de leituras - ' . $e->getMessage());
      }
   }

   /** @return array<int, string> alert_id => date_read do usuário */
   private static function readsForUser(int $userId): array {
      global $DB;
      if ($userId <= 0 || !$DB->tableExists(self::READS_TABLE)) {
         return [];
      }
      $reads = [];
      foreach ($DB->request(['FROM' => self::READS_TABLE, 'WHERE' => ['users_id' => $userId]]) as $row) {
         $reads[(int)$row['alert_id']] = (string)$row['date_read'];
      }
      return $reads;
   }

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
      self::ensureReadsTable();
      $userId = (int)Session::getLoginUserID();
      $reads  = self::readsForUser($userId);
      $iterator = $DB->request([
         'FROM'  => self::TABLE,
         'WHERE' => ['is_read' => 0], // legado: dispensa GLOBAL pré-migração continua valendo
         'ORDER' => 'date_received DESC',
      ]);
      $alerts = [];
      foreach ($iterator as $row) {
         // Alerta expirado não abre popup (#107): o servidor para de enviá-lo ao fim
         // da janela, mas a cópia local ficava is_read=0 para sempre. Instalações
         // antigas sem a coluna date_end caem no comportamento anterior (sem filtro).
         if (self::isExpired($row)) { continue; }
         // Leitura POR USUÁRIO: dispensado por este usuário não reaparece para ele,
         // mas continua "novo" para os demais admins.
         if (isset($reads[(int)$row['id']])) { continue; }
         $alerts[] = $row;
      }
      return $alerts;
   }

   public static function getAlertHistory(int $limit = 30): array {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return []; }
      self::ensureReadsTable();
      $userId = (int)Session::getLoginUserID();
      $reads  = self::readsForUser($userId);
      $iterator = $DB->request([
         'FROM'  => self::TABLE,
         'ORDER' => 'date_received DESC',
         'LIMIT' => $limit,
      ]);
      $alerts = [];
      foreach ($iterator as $row) {
         // Histórico mostra tudo; o expirado fica visível com o marcador (aba Alertas).
         $row['is_expired'] = self::isExpired($row);
         // Estado de leitura DO USUÁRIO ATUAL (o is_read global vira legado).
         $id = (int)$row['id'];
         $row['is_read_user']   = isset($reads[$id]) || !empty($row['is_read']);
         $row['date_read_user'] = $reads[$id] ?? ($row['date_read'] ?? null);
         $alerts[] = $row;
      }
      return $alerts;
   }

   /** Janela de validade encerrada? (date_end nulo/ausente = sem expiração) */
   public static function isExpired(array $row): bool {
      $end = $row['date_end'] ?? null;
      return !empty($end) && strtotime((string)$end) < time();
   }

   public static function markAsRead(int $id): void {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return; }
      self::ensureReadsTable();
      $userId = (int)Session::getLoginUserID();
      if ($userId <= 0) { return; }
      // Leitura POR USUÁRIO: não toca mais o is_read global (que escondia para todos).
      try {
         if (!countElementsInTable(self::READS_TABLE, ['alert_id' => $id, 'users_id' => $userId])) {
            $DB->insert(self::READS_TABLE, ['alert_id' => $id, 'users_id' => $userId, 'date_read' => date('Y-m-d H:i:s')]);
         }
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha ao marcar alerta lido - ' . $e->getMessage());
      }
   }

   public static function markAllAsRead(): void {
      global $DB;
      if (!$DB->tableExists(self::TABLE)) { return; }
      $userId = (int)Session::getLoginUserID();
      if ($userId <= 0) { return; }
      foreach ($DB->request(['SELECT' => 'id', 'FROM' => self::TABLE]) as $row) {
         self::markAsRead((int)$row['id']);
      }
   }

   public static function getUnreadCount(): int {
      // Consistente com getUnreadAlerts(): por usuário, sem expirados.
      return count(self::getUnreadAlerts());
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
