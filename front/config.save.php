<?php
/**
 * Salva as configurações do plugin
 */

include ('../../../inc/includes.php');

Session::checkRight("config", UPDATE);

// CSRF token é verificado automaticamente pelo GLPI 11
// Não precisa chamar Session::checkCSRF() explicitamente

// Inclui classes adicionais
require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licensevalidator.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/configaudit.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

// Verificação de permissão depende da ação
$action = $_POST['action'] ?? '';

// Ação "validate_license" requer apenas READ (visualizar/consultar)
if ($action === 'validate_license') {
   PluginNextoolPermissionManager::assertCanAccessAdminTabs();
} else {
   // Outras ações requerem UPDATE (modificar)
   PluginNextoolPermissionManager::assertCanManageAdminTabs();
}

if (!function_exists('nextool_obtain_or_reuse_client_secret')) {
   function nextool_obtain_or_reuse_client_secret(string $baseUrl, string $clientIdentifier, ?bool &$reused = null): ?string {
      $reused = false;
      $secret = PluginNextoolDistributionClient::bootstrapClientSecret($baseUrl, $clientIdentifier);
      if ($secret === null) {
         $row = PluginNextoolDistributionClient::getEnvSecretRow($clientIdentifier);
         if ($row && !empty($row['client_secret'])) {
            $secret = (string)$row['client_secret'];
            $reused = true;
            Toolbox::logInFile('plugin_nextool', sprintf('HMAC reutilizado a partir do registro existente para %s.', $clientIdentifier));
         }
      }

      return $secret;
   }
}

// Ações específicas
$action = $_POST['action'] ?? '';

if ($action === 'regenerate_hmac') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $baseUrl          = trim((string)($distributionSettings['base_url'] ?? ''));
   $clientIdentifier = trim((string)($distributionSettings['client_identifier'] ?? ''));

   if ($baseUrl === '' || $clientIdentifier === '') {
     Session::addMessageAfterRedirect(
        __('Configure a URL do ContainerAPI e o identificador do ambiente antes de recriar o segredo HMAC.', 'nextool'),
        false,
        WARNING
     );
     Html::back();
     exit;
   }

   PluginNextoolDistributionClient::deleteEnvSecret($clientIdentifier);
   Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
      'client_secret' => null,
   ]));

   $reusedSecret = false;
   $secret = nextool_obtain_or_reuse_client_secret($baseUrl, $clientIdentifier, $reusedSecret);

   if ($secret === null) {
      Session::addMessageAfterRedirect(
         __('Não foi possível recriar o segredo HMAC. Verifique os logs e tente novamente.', 'nextool'),
         false,
         ERROR
      );
      Html::back();
      exit;
   }

   Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
      'client_secret' => $secret,
   ]));

   PluginNextoolConfigAudit::log([
      'section' => 'distribution',
      'action'  => 'regenerate_hmac',
      'result'  => 1,
      'message' => __('Segredo HMAC recriado com sucesso.', 'nextool'),
      'details' => [
         'environment_identifier' => $clientIdentifier,
         'reused_existing_secret' => $reusedSecret ? 1 : 0,
      ],
   ]);

   Session::addMessageAfterRedirect(
      $reusedSecret
         ? __('Segredo HMAC já existia e foi reutilizado com sucesso.', 'nextool')
         : __('Novo segredo HMAC provisionado automaticamente.', 'nextool'),
      false,
      INFO
   );

   Html::back();
   exit;
}

if ($action === 'accept_policies') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $baseUrl = trim((string)($distributionSettings['base_url'] ?? ''));
   $clientIdentifier = trim((string)($distributionSettings['client_identifier'] ?? ''));

   if ($baseUrl === '' || $clientIdentifier === '') {
      Session::addMessageAfterRedirect(
         __('Configure a URL do ContainerAPI e gere o identificador do ambiente antes de aceitar as políticas de uso.', 'nextool'),
         false,
         WARNING
      );
      Html::back();
      exit;
   }

   $needsBootstrap = $baseUrl !== '' && $clientIdentifier !== '' && empty($distributionSettings['client_secret']);
   if ($needsBootstrap) {
      $reusedSecret = false;
      $secret = nextool_obtain_or_reuse_client_secret($baseUrl, $clientIdentifier, $reusedSecret);
      if ($secret !== null) {
         Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
            'client_secret' => $secret,
         ]));
         Session::addMessageAfterRedirect(
            $reusedSecret
               ? __('Segredo HMAC já estava provisionado e foi reutilizado com sucesso.', 'nextool')
               : __('Segredo HMAC provisionado automaticamente com sucesso.', 'nextool'),
            false,
            INFO
         );
         $distributionSettings = PluginNextoolConfig::getDistributionSettings();
      } else {
         Session::addMessageAfterRedirect(
            __('Não foi possível obter o segredo HMAC automaticamente. Verifique a URL ou tente novamente mais tarde.', 'nextool'),
            false,
            WARNING
         );
         Html::back();
         exit;
      }
   }

   $manager = PluginNextoolModuleManager::getInstance();
   $manager->clearCache();
   $manager->refreshModules();
   PluginNextoolLicenseConfig::resetCache();

   $result = PluginNextoolLicenseValidator::validateLicense([
      'force_refresh' => true,
      'context'       => [
         'origin'            => 'policies_acceptance',
         'requested_modules' => ['catalog_bootstrap'],
      ],
   ]);

   if (!empty($result['valid'])) {
      Session::addMessageAfterRedirect(
         __('Políticas aceitas e catálogo sincronizado com sucesso. Os módulos oficiais foram liberados.', 'nextool'),
         false,
         INFO
      );
   } else {
      $message = $result['message'] ?? __('Não foi possível sincronizar o catálogo de módulos. Tente novamente em instantes.', 'nextool');
      Session::addMessageAfterRedirect(
         $message,
         false,
         WARNING
      );
   }

   PluginNextoolConfigAudit::log([
      'section' => 'validation',
      'action'  => 'policies_acceptance',
      'result'  => !empty($result['valid']) ? 1 : 0,
      'message' => $result['message'] ?? null,
      'details' => [
         'http_code'       => $result['http_code'] ?? null,
         'plan'            => $result['plan'] ?? null,
         'contract_active' => $result['contract_active'] ?? null,
         'license_status'  => $result['license_status'] ?? null,
      ],
   ]);

   Html::back();
   exit;
}

// Snapshot antes da alteração
$previousGlobalConfig = PluginNextoolConfig::getConfig();
$licenseRow = PluginNextoolLicenseConfig::getDefaultConfig();
$previousLicenseKey = $licenseRow['license_key'] ?? null;

// Calcula novos valores esperados (mesma regra do saveConfig)
$newIsActive = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
$newEndpoint = isset($_POST['endpoint_url']) ? trim((string)$_POST['endpoint_url']) : null;
if ($newEndpoint === '') {
   $newEndpoint = null;
}

// Salva configuração global
$config  = new PluginNextoolConfig();
$success = $config->saveConfig($_POST);

// Auditoria dos ajustes globais
$globalChanges = [];
if (array_key_exists('is_active', $previousGlobalConfig) && (int)$previousGlobalConfig['is_active'] !== $newIsActive) {
   $globalChanges['is_active'] = [
      'old' => (int)$previousGlobalConfig['is_active'],
      'new' => $newIsActive,
   ];
}
if (($previousGlobalConfig['endpoint_url'] ?? null) !== $newEndpoint) {
   $globalChanges['endpoint_url'] = [
      'old' => $previousGlobalConfig['endpoint_url'] ?? null,
      'new' => $newEndpoint,
   ];
}

if (!empty($globalChanges) || !$success) {
   PluginNextoolConfigAudit::log([
      'section' => 'global',
      'action'  => 'save',
      'result'  => $success ? 1 : 0,
      'message' => $success
         ? __('Configurações salvas com sucesso!', 'nextool')
         : __('Erro ao salvar configurações', 'nextool'),
      'details' => $globalChanges,
   ]);
}

if ($success) {
   $distributionValues = Config::getConfigurationValues('plugin:nextool_distribution');
   $currentBaseUrl = isset($distributionValues['base_url'])
      ? trim((string)$distributionValues['base_url'])
      : '';
   $targetBaseUrl = $newEndpoint ?? PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL;
   if ($currentBaseUrl !== $targetBaseUrl) {
      $distributionValues['base_url'] = $targetBaseUrl;
      Config::setConfigurationValues('plugin:nextool_distribution', $distributionValues);
      PluginNextoolConfigAudit::log([
         'section' => 'distribution',
         'action'  => 'update_base_url',
         'result'  => 1,
         'message' => __('URL do ContainerAPI atualizada.', 'nextool'),
         'details' => [
            'old_base_url' => $currentBaseUrl,
            'new_base_url' => $targetBaseUrl,
         ],
      ]);
   }

   Session::addMessageAfterRedirect(
      __('Configurações salvas com sucesso!', 'nextool'),
      false,
      INFO
   );
} else {
   Session::addMessageAfterRedirect(
      __('Erro ao salvar configurações', 'nextool'),
      false,
      ERROR
   );
}

// Se usuário clicou em "Validar licença agora", executa validação imediata
if (isset($_POST['action']) && $_POST['action'] === 'validate_license') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $distributionClientIdentifier = $distributionSettings['client_identifier']
      ?? ($previousGlobalConfig['client_identifier'] ?? '');
   $needsBootstrap = !empty($distributionSettings['base_url'])
      && !empty($distributionSettings['client_identifier'])
      && empty($distributionSettings['client_secret']);

   if ($needsBootstrap) {
      $reusedSecret = false;
      $secret = nextool_obtain_or_reuse_client_secret(
         $distributionSettings['base_url'],
         $distributionSettings['client_identifier'],
         $reusedSecret
      );

      if ($secret !== null) {
         Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
            'client_secret' => $secret,
         ]));
         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'bootstrap',
            'result'  => 1,
            'message' => __('Segredo HMAC provisionado automaticamente.', 'nextool'),
            'details' => [
               'base_url'               => $distributionSettings['base_url'],
               'reused_existing_secret' => $reusedSecret ? 1 : 0,
            ],
         ]);
         Session::addMessageAfterRedirect(
            $reusedSecret
               ? __('Segredo HMAC já existia e foi reutilizado automaticamente.', 'nextool')
               : __('Segredo HMAC provisionado automaticamente com sucesso.', 'nextool'),
            false,
            INFO
         );
         $distributionSettings = PluginNextoolConfig::getDistributionSettings();
         $distributionClientIdentifier = $distributionSettings['client_identifier']
            ?? ($previousGlobalConfig['client_identifier'] ?? '');
      } else {
         Session::addMessageAfterRedirect(
            __('Não foi possível obter o segredo HMAC automaticamente. Verifique a URL ou tente novamente mais tarde.', 'nextool'),
            false,
            WARNING
         );
      }
   }

   $manager = PluginNextoolModuleManager::getInstance();
   $manager->clearCache();
   $manager->refreshModules();
   PluginNextoolLicenseConfig::resetCache();

   $result = PluginNextoolLicenseValidator::validateLicense([
      'force_refresh' => true,
      'context'       => [
         'source' => 'config_form',
         'origin' => 'manual_validation',
      ],
   ]);

   $resultError = $result['error'] ?? null;
   if (!empty($result['valid'])) {
      // Usa diretamente a mensagem retornada pelo validador (já enriquecida com o plano),
      // evitando redundâncias do tipo "Licença válida: Licença válida (PRO)".
      $msg = $result['message'] ?? __('Licença válida', 'nextool');

      Session::addMessageAfterRedirect(
         $msg,
         false,
         INFO
      );
   } else {
      $msg = $result['message'] ?? __('Licença inválida ou não autorizada.', 'nextool');
      $licensesInfo = isset($result['licenses']) && is_array($result['licenses'])
         ? $result['licenses']
         : [];

      if ($resultError === 'unauthorized') {
         $msg = __('Ainda estamos provisionando este ambiente no ContainerAPI. Aguarde alguns instantes e clique novamente em "Validar licença agora" para concluir o bootstrap. O ambiente permanece em modo FREE.', 'nextool');
         Session::addMessageAfterRedirect(
            $msg,
            false,
            INFO
         );
      } elseif (empty($licensesInfo)) {
         Session::addMessageAfterRedirect(
            sprintf(__('Validação concluída (nenhuma licença ativa): %s. Ambiente permanece no modo FREE.', 'nextool'), $msg),
            false,
            INFO
         );
      } else {
         Session::addMessageAfterRedirect(
            sprintf(__('Licença inválida: %s. Ambiente operará em modo FREE até que uma licença ativa seja atribuída.', 'nextool'), $msg),
            false,
            INFO
         );
      }

      // Após uma validação inválida ou com contrato suspenso/inativo,
      // força o ambiente para modo FREE desativando/desinstalando
      // logicamente todos os módulos pagos já instalados.
      try {
         $manager = PluginNextoolModuleManager::getInstance();
         $manager->enforceFreeTierForPaidModules();
      } catch (Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            'Falha ao aplicar modo FREE após licença inválida: ' . $e->getMessage()
         );
      }
   }

   $logPayload = [
      'origin'            => 'manual_validation',
      'client_identifier' => $distributionClientIdentifier,
      'result'            => $result['valid'] ?? false,
      'http_code'         => $result['http_code'] ?? null,
      'error'             => $resultError,
      'message'           => $result['message'] ?? null,
      'plan'              => $result['plan'] ?? null,
      'license_status'    => $result['license_status'] ?? null,
      'contract_active'   => $result['contract_active'] ?? null,
      'licenses_count'    => isset($result['licenses']) && is_array($result['licenses']) ? count($result['licenses']) : 0,
   ];
   Toolbox::logInFile('plugin_nextool', 'Manual validation payload: ' . json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

   PluginNextoolConfigAudit::log([
      'section' => 'validation',
      'action'  => 'manual_validation',
      'result'  => !empty($result['valid']) ? 1 : 0,
      'message' => $msg,
      'details' => [
         'http_code'        => $result['http_code'] ?? null,
         'contract_active'  => $result['contract_active'] ?? null,
         'license_status'   => $result['license_status'] ?? null,
         'plan'             => $result['plan'] ?? null,
      ],
   ]);
}

// Redireciona de volta para a página de configuração
$forcetab = isset($_POST['forcetab']) ? $_POST['forcetab'] : '';
Html::back();

