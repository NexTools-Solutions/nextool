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
         $task->log('catalog_sync: ambiente não provisionado - sync ignorado.');
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

      // Notificação de updates (#162): só com dado FRESCO do servidor (source=remote);
      // cache/backoff/falha não devem emitir nem expirar alerta com informação velha.
      if ($source === 'remote') {
         self::notifyPendingUpdates($task);
      }

      // "Fez algo" = houve comunicação remota (o catálogo foi reavaliado/sincronizado).
      return $source === 'remote' ? 1 : 0;
   }

   /**
    * (#162) Detecta update disponível (core + módulos) e avisa o admin via alerta
    * LOCAL (popup/aba Alertas + sino), com dedup por versão/conjunto -- a mesma
    * situação nunca re-alerta a cada tick de 6h. Nada é baixado/instalado aqui.
    */
   private static function notifyPendingUpdates(CronTask $task): void {
      foreach (['coreupdater', 'alertmanager', 'modulecatalog'] as $inc) {
         $f = NEXTOOL_PHP_DIR . '/inc/' . $inc . '.class.php';
         if (is_file($f)) {
            require_once $f;
         }
      }
      if (!class_exists('PluginNextoolAlertManager')) {
         return;
      }

      // (a) Core: check ativo (antes só o botão Sincronizar manual detectava --
      // ambiente ocioso nunca ficava sabendo de versão nova da base).
      try {
         if (class_exists('PluginNextoolCoreUpdater')) {
            $coreCheck = (new PluginNextoolCoreUpdater())->check('stable', 'cron_sync');
            if (!empty($coreCheck['success'])) {
               $target  = trim((string) ($coreCheck['data']['target_version'] ?? ''));
               $current = trim((string) ($coreCheck['data']['current_version'] ?? ''));
               if (!empty($coreCheck['data']['update_available']) && $target !== '') {
                  PluginNextoolAlertManager::raiseLocal(
                     'core_update:' . $target,
                     sprintf(__('Atualização do NexTool disponível: versão %s', 'nextool'), $target),
                     sprintf(
                        __('Uma nova versão do plugin NexTool está disponível (%1$s → %2$s). Acesse Configurar > NexTool e use o botão "Atualização Disponível" para aplicar quando desejar.', 'nextool'),
                        $current !== '' ? $current : '?',
                        $target
                     ),
                     'warning'
                  );
               } else {
                  // Core em dia: expira alerta de versão que deixou de valer.
                  PluginNextoolAlertManager::expireLocalFamily('core_update:');
               }
            }
         }
      } catch (Throwable $e) {
         $task->log('catalog_sync: core check falhou: ' . $e->getMessage());
      }

      // (b) Módulos: alerta AGREGADO, chave = hash do conjunto módulo=versão --
      // conjunto idêntico = no-op; qualquer mudança expira o anterior e re-emite.
      try {
         if (!class_exists('PluginNextoolModuleCatalog')
             || !method_exists('PluginNextoolModuleCatalog', 'getPendingUpdates')) {
            return;
         }
         $pending = PluginNextoolModuleCatalog::getPendingUpdates();
         $task->log(sprintf('catalog_sync: modules_pending=%d', count($pending)));
         if ($pending === []) {
            PluginNextoolAlertManager::expireLocalFamily('module_updates:');
            return;
         }
         $pairs = [];
         $lines = [];
         foreach ($pending as $key => $info) {
            $pairs[] = $key . '=' . $info['available'];
            $lines[] = sprintf('<li>%s (%s → %s)</li>',
               htmlspecialchars($info['name']), htmlspecialchars($info['installed']), htmlspecialchars($info['available']));
         }
         sort($pairs);
         PluginNextoolAlertManager::raiseLocal(
            'module_updates:' . substr(md5(implode('|', $pairs)), 0, 12),
            sprintf(
               _n('%d módulo com atualização disponível', '%d módulos com atualização disponível', count($pending), 'nextool'),
               count($pending)
            ),
            '<p>' . __('Os módulos abaixo têm nova versão no catálogo oficial. Atualize pela tela de Módulos quando desejar.', 'nextool') . '</p>'
               . '<ul>' . implode('', $lines) . '</ul>',
            'info'
         );
      } catch (Throwable $e) {
         $task->log('catalog_sync: alerta de módulos falhou: ' . $e->getMessage());
      }
   }
}
