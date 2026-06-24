<?php
/**
 * NexTool -- CronTask de sincronização do catálogo de módulos (F2, 5.0.0).
 *
 * Sincroniza periodicamente o catálogo de módulos com a ContainerAPI (mesma rota do botão
 * "Sincronizar" manual: validateLicense -> applyModulesCatalogSync), resolvendo as versões
 * compatíveis com este plugin. Mantém o botão manual intacto.
 *
 * MODE_EXTERNAL obrigatório (poll/integração via cron CLI). MODE_INTERNAL (web-hit) trava em
 * state=2 e para de rodar -- ver learning_glpi_crontask_mode_internal_travamento.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolCronCatalogSync {

   public static function getTypeName($nb = 0) {
      return __('NexTool Catalog Sync', 'nextool');
   }

   public static function cronInfo($name) {
      if ($name === 'catalogSync') {
         return [
            'description' => __('Sincroniza o catálogo de módulos NexTool com a plataforma (resolve versões compatíveis com este plugin)', 'nextool'),
         ];
      }
      return [];
   }

   /**
    * Cron MODE_EXTERNAL: sincroniza o catálogo de módulos com a ContainerAPI.
    *
    * @return int >0 = sincronizou (comunicação remota); 0 = nada feito (ambiente não
    *             provisionado, erro tratado, ou resposta sem origem remota)
    */
   public static function cronCatalogSync(CronTask $task): int {
      if (!class_exists('PluginNextoolConfig') || !class_exists('PluginNextoolLicenseValidator')) {
         return 0;
      }

      // Guard: só sincroniza ambiente provisionado. FREE não-provisionado não deve martelar a
      // API (sem base_url/identifier/secret não há o que validar).
      try {
         $settings = PluginNextoolConfig::getDistributionSettings();
      } catch (Throwable $e) {
         $task->log('catalog_sync: falha ao ler settings de distribuição: ' . $e->getMessage());
         return 0;
      }
      $baseUrl    = trim((string) ($settings['base_url'] ?? ''));
      $identifier = trim((string) ($settings['client_identifier'] ?? ''));
      $secret     = trim((string) ($settings['client_secret'] ?? ''));
      if ($baseUrl === '' || $identifier === '' || $secret === '') {
         $task->log('catalog_sync: ambiente não provisionado — sync ignorado.');
         return 0;
      }

      // force_refresh ignora o cache de validação; origin=cron_sync passa pela sincronização do
      // catálogo (só config_status é suprimido).
      try {
         $result = PluginNextoolLicenseValidator::validateLicense([
            'force_refresh' => true,
            'context'       => ['origin' => 'cron_sync'],
         ]);
      } catch (Throwable $e) {
         $task->log('catalog_sync: erro na validação: ' . $e->getMessage());
         return 0;
      }

      $source = is_array($result) ? (string) ($result['source'] ?? '') : '';
      $valid  = is_array($result) ? (bool) ($result['valid'] ?? false) : false;
      $task->log(sprintf('catalog_sync: source=%s valid=%s', $source !== '' ? $source : 'n/d', $valid ? '1' : '0'));

      // "Fez algo" = houve comunicação remota (o catálogo foi reavaliado/sincronizado).
      return $source === 'remote' ? 1 : 0;
   }
}
