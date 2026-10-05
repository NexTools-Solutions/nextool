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
    * Orçamento da execução (auditoria das crons, achado 52). O pior caso somava ~135 s (validate 15 s +
    * auto-cura 30+15 s + manifesto do core 60 s + endpoint do cloud link 15 s), segurando o cron.php do GLPI
    * inteiro. Passado o orçamento depois da validação, as etapas seguintes (aviso de versão, pré-requisitos,
    * reafirmação do endpoint) ficam para o próximo ciclo: nenhuma delas perde dado por esperar 6 h.
    */
   public const BUDGET_SECONDS = 45;

   /**
    * Cron MODE_EXTERNAL: sincroniza o catálogo de módulos com a ContainerAPI.
    *
    * @return int 1 = sincronizou (comunicação remota OK); 0 = nada a fazer (ambiente não provisionado, ou
    *             validação servida do cache local); -1 = falha (servidor fora, backoff de comunicação ou
    *             exceção), para o GLPI registrar a execução como erro.
    */
   public static function cronCatalogSync(CronTask $task): int {
      $inicio = microtime(true);
      if (!class_exists('PluginNextoolConfig') || !class_exists('PluginNextoolLicenseValidator')) {
         return 0;
      }

      // Guard: só sincroniza ambiente provisionado. FREE não-provisionado não deve martelar a
      // API (sem base_url/identifier/secret não há o que validar).
      try {
         $settings = PluginNextoolConfig::getDistributionSettings();
      } catch (Throwable $e) {
         $task->log('catalog_sync: falha ao ler settings de distribuição: ' . $e->getMessage());
         return -1;
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
         return -1;
      }

      $source = is_array($result) ? (string) ($result['source'] ?? '') : '';
      $valid  = is_array($result) ? (bool) ($result['valid'] ?? false) : false;
      $falhou = self::isRemoteFailure(is_array($result) ? $result : []);
      $task->log(sprintf('catalog_sync: source=%s valid=%s', $source !== '' ? $source : 'n/d', $valid ? '1' : '0'));
      if ($falhou) {
         // Servidor fora ou comunicação em backoff: o aviso de versão e os pré-requisitos ficam para o próximo
         // ciclo (sem dado fresco). A reafirmação do endpoint abaixo segue: ela fala com o cérebro, não com o
         // ContainerAPI.
         $task->log('catalog_sync: falha de comunicação com o servidor');
      }

      // Notificação de updates (#162): só com dado FRESCO do servidor (source=remote);
      // cache/backoff/falha não devem emitir nem expirar alerta com informação velha.
      if ($source === 'remote' && !$falhou) {
         if (self::budgetLeft($inicio) > 0) {
            self::notifyPendingUpdates($task);
         }
         if (self::budgetLeft($inicio) > 0) {
            self::runPrereqCheck($task);
         }
      }
      if (self::budgetLeft($inicio) <= 0) {
         $task->log(sprintf('catalog_sync: orçamento de %d s esgotado; etapas restantes no próximo ciclo', self::BUDGET_SECONDS));
         return $falhou ? -1 : ($source === 'remote' ? 1 : 0);
      }

      // Cloud link (§2.12 e contrato 1.1, §2.13.2): a validação acima é por onde a credencial chega,
      // então é aqui que a base REAFIRMA ao cérebro onde está o executor, em todo ciclo, mesmo sem
      // mudança, e lê de volta o estado do destino. Falha não afeta o catálogo e tenta de novo no
      // próximo ciclo.
      $clientFile = NEXTOOL_PHP_DIR . '/inc/cloudclient.class.php';
      if (is_file($clientFile)) {
         try {
            require_once $clientFile;
            if ($source === 'remote' && !$falhou) {
               // O /validate deste ciclo atende um pedido de ressincronização pelo kid que tenha ficado
               // para depois (servidor que não solta a resposta antes; ver PluginNextoolCloudResync).
               require_once NEXTOOL_PHP_DIR . '/inc/cloudresync.class.php';
               PluginNextoolCloudResync::satisfied();
            }
            $ep = PluginNextoolCloudClient::reportEndpoint();
            if ($ep['action'] !== 'skipped') {
               $task->log(sprintf(
                  'cloud_endpoint: %s%s%s',
                  $ep['action'],
                  $ep['error'] !== '' ? ' (' . $ep['error'] . ')' : '',
                  ($ep['destino'] ?? '') !== '' ? ' destino=' . $ep['destino'] : ''
               ));
            }
         } catch (Throwable $e) {
            $task->log('cloud_endpoint: erro: ' . $e->getMessage());
         }
      }

      // "Fez algo" = houve comunicação remota (o catálogo foi reavaliado/sincronizado).
      return $falhou ? -1 : ($source === 'remote' ? 1 : 0);
   }

   /**
    * Falha de comunicação com o servidor: comunicação em backoff, ou resposta remota sem corpo / sem HTTP /
    * HTTP 5xx (o mesmo critério com que o validator conta a falha de rede). 4xx não entra: é resposta do
    * servidor (licença, assinatura), já tratada e registrada pelo validator.
    *
    * @param array<string,mixed> $result retorno de PluginNextoolLicenseValidator::validateLicense()
    */
   public static function isRemoteFailure(array $result): bool {
      $source = (string) ($result['source'] ?? '');
      if (in_array($source, ['network_backoff', 'auth_backoff'], true)) {
         return true;
      }
      if ($source !== 'remote') {
         return false;
      }
      $http = $result['http_code'] ?? null;

      return $http === null || (int) $http === 0 || (int) $http >= 500;
   }

   /** Segundos que restam do orçamento desta execução. */
   private static function budgetLeft(float $inicio): float {
      return self::BUDGET_SECONDS - (microtime(true) - $inicio);
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

   /**
    * (nextool-dev#260) Pré-requisitos que exigem humano, com fatos frescos do sync
    * remoto (core check e catálogo acima). Carona no ciclo de 6 h -- não é cron nova.
    */
   private static function runPrereqCheck(CronTask $task): void {
      try {
         $f = NEXTOOL_PHP_DIR . '/inc/prereqcheck.class.php';
         if (is_file($f)) {
            require_once $f;
         }
         if (!class_exists('PluginNextoolPrereqCheck')) {
            return;
         }
         $r = PluginNextoolPrereqCheck::run('cron_sync', true);
         $task->log(sprintf('catalog_sync: prereq conditions=%s', implode(',', $r['conditions'] ?? []) ?: 'none'));
      } catch (Throwable $e) {
         $task->log('catalog_sync: prereq check falhou: ' . $e->getMessage());
      }
   }
}
