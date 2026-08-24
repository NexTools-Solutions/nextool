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

   // ==========================================================================
   // Alertas LOCAIS (#162): gerados pelo PRÓPRIO plugin (ex.: update disponível
   // detectado pela cron de sincronia), sem origem remota. Convivem na mesma
   // tabela dos alertas do servidor: `local_key` (UNIQUE) identifica/deduplica;
   // `remote_alert_id` recebe um id sintético determinístico em faixa alta
   // (>= 0x80000000, inalcançável pelos auto-increment do admin) só para
   // satisfazer o NOT NULL + uq_remote_alert do schema legado.
   // ==========================================================================

   /**
    * Emite um alerta LOCAL com deduplicação por chave.
    *
    * - `$key` já existente => no-op (não duplica nem "ressuscita" alerta lido --
    *   a pivot de leituras não é tocada).
    * - `$key` nova => expira os irmãos ATIVOS da mesma família (prefixo até o
    *   primeiro ':', ex.: 'core_update:') e insere o alerta novo, publicando no
    *   canal de notificações (sino) uma única vez.
    *
    * @param string      $key     ex.: 'core_update:6.12.0', 'module_updates:<hash>'
    * @param string      $type    info|warning|critical|promo (vocabulário da UI)
    * @param string|null $dateEnd 'Y-m-d H:i:s' opcional (janela de exibição)
    * @return bool true = alerta NOVO inserido; false = dedup (já existia) ou falha
    */
   public static function raiseLocal(string $key, string $title, string $body, string $type = 'info', ?string $dateEnd = null): bool {
      global $DB;
      $key = trim($key);
      if ($key === '' || $title === '') {
         return false;
      }
      if (!in_array($type, ['info', 'warning', 'critical', 'promo'], true)) {
         $type = 'info';
      }
      try {
         self::ensureLocalKeyColumn();
         if (!$DB->tableExists(self::TABLE) || !$DB->fieldExists(self::TABLE, 'local_key', false)) {
            return false; // DDL falhou (permissão?) -- já logado; não quebrar o chamador
         }
         // Dedup canônico: mesma chave já emitida => no-op.
         $existing = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['local_key' => $key], 'LIMIT' => 1]);
         if (count($existing) > 0) {
            return false;
         }
         // Chave nova: o conteúdo mudou (versão/conjunto novo) -- o alerta anterior
         // da mesma família fica obsoleto e sai do popup (histórico preserva).
         $colon = strpos($key, ':');
         if ($colon !== false) {
            self::expireLocalFamily(substr($key, 0, $colon + 1));
         }
         $DB->insert(self::TABLE, [
            'remote_alert_id' => self::syntheticRemoteId($key),
            'local_key'       => $key,
            'title'           => $title,
            'body'            => $body,
            'alert_type'      => $type,
            'date_end'        => $dateEnd,
         ]);
         self::publishLocalNotification($key, $title, $body, $type);
         return true;
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha ao emitir alerta local ' . $key . ' - ' . $e->getMessage());
         return false;
      }
   }

   /**
    * Expira (date_end = agora) os alertas LOCAIS ainda ativos de uma família.
    * Usar quando a condição deixou de valer (ex.: core atualizado, módulos em dia)
    * para o popup antigo não continuar cobrando algo já resolvido.
    */
   public static function expireLocalFamily(string $familyPrefix): void {
      global $DB;
      if ($familyPrefix === '' || !$DB->tableExists(self::TABLE)
          || !$DB->fieldExists(self::TABLE, 'local_key', false)) {
         return;
      }
      $now = date('Y-m-d H:i:s');
      try {
         $DB->update(self::TABLE, ['date_end' => $now], [
            'local_key' => ['LIKE', $familyPrefix . '%'],
            'OR'        => [
               ['date_end' => null],
               ['date_end' => ['>', $now]],
            ],
         ]);
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha ao expirar família ' . $familyPrefix . ' - ' . $e->getMessage());
      }
   }

   /**
    * Garante a tabela de alertas (DDL espelho de LicenseValidator::persistAlerts,
    * que a cria lazy no 1º alerta remoto) e a coluna/índice `local_key`.
    */
   private static function ensureLocalKeyColumn(): void {
      global $DB;
      // Shim de DDL: doQuery() só existe a partir do GLPI 10.0.7.
      $ddlMethod = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
      try {
         if (!$DB->tableExists(self::TABLE)) {
            $DB->$ddlMethod("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
               `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               `remote_alert_id` INT UNSIGNED NOT NULL,
               `local_key` VARCHAR(191) NULL DEFAULT NULL,
               `title` VARCHAR(255) NOT NULL,
               `body` TEXT NOT NULL,
               `alert_type` VARCHAR(20) NOT NULL DEFAULT 'info',
               `is_read` TINYINT NOT NULL DEFAULT 0,
               `date_received` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
               `date_read` TIMESTAMP NULL DEFAULT NULL,
               `date_start` TIMESTAMP NULL DEFAULT NULL,
               `date_end` TIMESTAMP NULL DEFAULT NULL,
               UNIQUE KEY `uq_remote_alert` (`remote_alert_id`),
               UNIQUE KEY `uq_local_key` (`local_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
         }
         if (!$DB->fieldExists(self::TABLE, 'local_key', false)) {
            $DB->$ddlMethod("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `local_key` VARCHAR(191) NULL DEFAULT NULL, ADD UNIQUE KEY `uq_local_key` (`local_key`)");
         }
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha no DDL de local_key - ' . $e->getMessage());
      }
   }

   /** Id determinístico em faixa alta ([2^31, 2^32-1]) para o schema legado. */
   private static function syntheticRemoteId(string $key): int {
      return 0x80000000 | (crc32('local:' . $key) & 0x7FFFFFFF);
   }

   /**
    * Publica o alerta local no canal de notificações (sino por usuário via módulo
    * consumidor). Espelho de LicenseValidator::publishAlertNotification -- fail-silent
    * por contrato do canal: publicar nunca pode quebrar o emissor.
    */
   private static function publishLocalNotification(string $key, string $title, string $body, string $type): void {
      try {
         if (!class_exists('PluginNextoolHookDispatcher')
             || !method_exists('PluginNextoolHookDispatcher', 'dispatchNotification')) {
            return;
         }
         $severityMap = ['critical' => 'critical', 'warning' => 'warning', 'promo' => 'info', 'info' => 'info'];
         PluginNextoolHookDispatcher::dispatchNotification([
            'source_key' => 'nextool.local_alert',
            'title'      => $title,
            'message'    => strip_tags($body),
            'url'        => '/plugins/nextool/front/nextoolconfig.form.php?id=1&forcetab=' . rawurlencode('PluginNextoolMainConfig$4'),
            'severity'   => $severityMap[$type] ?? 'info',
            'audience'   => ['type' => 'base_admins'],
            'dedup_key'  => 'nextool.local_alert:' . $key,
         ]);
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'AlertManager: falha ao publicar alerta local ' . $key . ' no canal - ' . $e->getMessage());
      }
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
