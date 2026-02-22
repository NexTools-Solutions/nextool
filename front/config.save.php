<?php
/**
 * Nextools - Config Save
 *
 * Processa POST do formulário de configuração: salvar opções, validar licença,
 * regenerar HMAC, aceitar políticas. Requer sessão e CSRF (validado pelo core no GLPI 10).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include ('../../../inc/includes.php');

Session::checkRight("config", UPDATE);

// CSRF no GLPI 10:
// O core valida automaticamente no `inc/includes.php` para qualquer POST.

// Inclui classes adicionais
require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licensevalidator.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/configaudit.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

/**
 * Realiza bootstrap automático do segredo HMAC quando necessário.
 *
 * @param array<string, mixed> $distributionSettings
 * @return array{
 *   attempted: bool,
 *   success: bool,
 *   reused_secret: bool,
 *   settings: array<string, mixed>,
 *   client_identifier: string
 * }
 */
function plugin_nextool_bootstrap_hmac_if_needed(array $distributionSettings, bool $logAudit = false): array
{
   $baseUrl = trim((string) ($distributionSettings['base_url'] ?? ''));
   $clientIdentifier = trim((string) ($distributionSettings['client_identifier'] ?? ''));
   $needsBootstrap = $baseUrl !== '' && $clientIdentifier !== '' && empty($distributionSettings['client_secret']);

   if (!$needsBootstrap) {
      return [
         'attempted'         => false,
         'success'           => true,
         'reused_secret'     => false,
         'settings'          => $distributionSettings,
         'client_identifier' => $clientIdentifier,
      ];
   }

   $reusedSecret = false;
   $secret = PluginNextoolDistributionClient::obtainOrReuseClientSecret(
      $baseUrl,
      $clientIdentifier,
      $reusedSecret
   );

   if ($secret === null) {
      return [
         'attempted'         => true,
         'success'           => false,
         'reused_secret'     => false,
         'settings'          => $distributionSettings,
         'client_identifier' => $clientIdentifier,
      ];
   }

   Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
      'client_secret' => $secret,
   ]));

   if ($logAudit) {
      PluginNextoolConfigAudit::log([
         'section' => 'distribution',
         'action'  => 'bootstrap',
         'result'  => 1,
        'message' => __('Chave de segurança gerada automaticamente.', 'nextool'),
         'details' => [
            'base_url'               => $baseUrl,
            'reused_existing_secret' => $reusedSecret ? 1 : 0,
         ],
      ]);
   }

   $updatedSettings = PluginNextoolConfig::getDistributionSettings();

   return [
      'attempted'         => true,
      'success'           => true,
      'reused_secret'     => $reusedSecret,
      'settings'          => $updatedSettings,
      'client_identifier' => trim((string) ($updatedSettings['client_identifier'] ?? $clientIdentifier)),
   ];
}

// Verificação de permissão depende da ação
$action = $_POST['action'] ?? '';

// Ação "validate_license" requer apenas READ (visualizar/consultar)
if ($action === 'validate_license') {
   PluginNextoolPermissionManager::assertCanAccessAdminTabs();
} else {
   // Outras ações requerem UPDATE (modificar)
   PluginNextoolPermissionManager::assertCanManageAdminTabs();
}

if ($action === 'regenerate_hmac') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $baseUrl          = trim((string)($distributionSettings['base_url'] ?? ''));
   $clientIdentifier = trim((string)($distributionSettings['client_identifier'] ?? ''));

   if ($baseUrl === '' || $clientIdentifier === '') {
     Session::addMessageAfterRedirect(
        __('Configure a URL da plataforma NexTool e o código do ambiente antes de gerar uma nova chave de segurança.', 'nextool'),
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
   $secret = PluginNextoolDistributionClient::obtainOrReuseClientSecret($baseUrl, $clientIdentifier, $reusedSecret);

   if ($secret === null) {
      Session::addMessageAfterRedirect(
         __('Não foi possível gerar uma nova chave de segurança. Tente novamente em instantes.', 'nextool'),
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
      'message' => __('Chave de segurança gerada com sucesso.', 'nextool'),
      'details' => [
         'environment_identifier' => $clientIdentifier,
         'reused_existing_secret' => $reusedSecret ? 1 : 0,
      ],
   ]);

   Session::addMessageAfterRedirect(
      $reusedSecret
         ? __('A chave de segurança já existia e foi reutilizada com sucesso.', 'nextool')
         : __('Nova chave de segurança gerada automaticamente.', 'nextool'),
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
         __('Configure a URL da plataforma NexTool e o código do ambiente antes de aceitar as políticas de uso.', 'nextool'),
         false,
         WARNING
      );
      Html::back();
      exit;
   }

   $bootstrap = plugin_nextool_bootstrap_hmac_if_needed($distributionSettings);
   if (!empty($bootstrap['attempted'])) {
      if (!empty($bootstrap['success'])) {
         Session::addMessageAfterRedirect(
            !empty($bootstrap['reused_secret'])
               ? __('A chave de segurança já existia e foi reutilizada com sucesso.', 'nextool')
               : __('Chave de segurança gerada automaticamente com sucesso.', 'nextool'),
            false,
            INFO
         );
         $distributionSettings = $bootstrap['settings'];
      } else {
         Session::addMessageAfterRedirect(
            __('Não foi possível gerar a chave de segurança automaticamente. Verifique a configuração e tente novamente mais tarde.', 'nextool'),
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
   PluginNextoolLicenseConfig::resetCache([
      // Aceite é uma ação "one-time" do ambiente operacional.
      // Não depende do sucesso da validação remota: o usuário já concordou
      // com a política de uso/coleta para fins de licenciamento.
      'policies_accepted_at' => date('Y-m-d H:i:s'),
   ]);

   $result = PluginNextoolLicenseValidator::validateLicense([
      'force_refresh' => true,
      'context'       => [
         'origin'            => 'policies_acceptance',
         'requested_modules' => ['catalog_bootstrap'],
      ],
   ]);

   if (!empty($result['valid'])) {
      Session::addMessageAfterRedirect(
         __('Políticas aceitas e sincronização concluída com sucesso. Os módulos disponíveis foram atualizados.', 'nextool'),
         false,
         INFO
      );
   } else {
      $message = $result['message'] ?? __('Não foi possível concluir a sincronização agora. Tente novamente em instantes.', 'nextool');
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

// Snapshot antes de qualquer alteração (também usado no fluxo de validação)
$previousGlobalConfig = PluginNextoolConfig::getConfig();

// Persistir configuração global apenas quando os campos vierem no POST.
// Isso evita sobrescrever dados em ações como validate_license via nextoolSyncForm.
$endpointWasPosted = array_key_exists('endpoint_url', $_POST);
$isActiveWasPosted = array_key_exists('is_active', $_POST);
$shouldPersistGlobal = in_array($action, ['', 'validate_license'], true)
   && ($endpointWasPosted || $isActiveWasPosted);

if ($shouldPersistGlobal) {
   // Mantém valores atuais quando o campo não vem no POST.
   $newIsActive = $isActiveWasPosted
      ? ((isset($_POST['is_active']) && $_POST['is_active'] == '1') ? 1 : 0)
      : (int) ($previousGlobalConfig['is_active'] ?? 1);

   if ($endpointWasPosted) {
      $newEndpoint = trim((string) $_POST['endpoint_url']);
      if ($newEndpoint === '') {
         $newEndpoint = null;
      }
   } else {
      $newEndpoint = $previousGlobalConfig['endpoint_url'] ?? null;
   }

   $configData = [
      'is_active'    => $newIsActive,
      'endpoint_url' => $newEndpoint,
   ];
   $success = PluginNextoolConfig::saveConfig($configData);

   // Auditoria dos ajustes globais
   $globalChanges = [];
   if (array_key_exists('is_active', $previousGlobalConfig) && (int) $previousGlobalConfig['is_active'] !== $newIsActive) {
      $globalChanges['is_active'] = [
         'old' => (int) $previousGlobalConfig['is_active'],
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

   // endpoint_url é refletido na configuração de distribuição apenas quando o campo foi enviado.
   if ($success && $endpointWasPosted) {
      $distributionValues = Config::getConfigurationValues('plugin:nextool_distribution');
      $currentBaseUrl = isset($distributionValues['base_url'])
         ? trim((string) $distributionValues['base_url'])
         : '';
      $targetBaseUrl = $newEndpoint ?? PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL;
      if ($currentBaseUrl !== $targetBaseUrl) {
         $distributionValues['base_url'] = $targetBaseUrl;
         Config::setConfigurationValues('plugin:nextool_distribution', $distributionValues);
         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'update_base_url',
            'result'  => 1,
            'message' => __('URL da plataforma NexTool atualizada.', 'nextool'),
            'details' => [
               'old_base_url' => $currentBaseUrl,
               'new_base_url' => $targetBaseUrl,
            ],
         ]);
      }
   }

   // No validate_license, não poluir feedback com mensagem de "salvo".
   if ($action !== 'validate_license') {
      if ($success) {
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
   }
}

// Se usuário clicou em "Validar licença agora", executa validação imediata
if (isset($_POST['action']) && $_POST['action'] === 'validate_license') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $distributionClientIdentifier = $distributionSettings['client_identifier']
      ?? ($previousGlobalConfig['client_identifier'] ?? '');
   $bootstrap = plugin_nextool_bootstrap_hmac_if_needed($distributionSettings, true);
   if (!empty($bootstrap['attempted'])) {
      if (!empty($bootstrap['success'])) {
         Session::addMessageAfterRedirect(
            !empty($bootstrap['reused_secret'])
               ? __('A chave de segurança já existia e foi reutilizada automaticamente.', 'nextool')
               : __('Chave de segurança gerada automaticamente com sucesso.', 'nextool'),
            false,
            INFO
         );
         $distributionSettings = $bootstrap['settings'];
         $distributionClientIdentifier = $bootstrap['client_identifier']
            ?? ($previousGlobalConfig['client_identifier'] ?? '');
      } else {
         Session::addMessageAfterRedirect(
            __('Não foi possível gerar a chave de segurança automaticamente. Verifique a configuração e tente novamente mais tarde.', 'nextool'),
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
         $msg = __('Ainda estamos preparando este ambiente na plataforma NexTool. Aguarde alguns instantes e clique novamente em "Sincronizar" para concluir. Enquanto isso, o ambiente permanece no plano gratuito.', 'nextool');
         Session::addMessageAfterRedirect(
            $msg,
            false,
            INFO
         );
      } elseif (empty($licensesInfo)) {
         $noLicenseMsg = __('Sincronização concluída: nenhuma licença ativa encontrada. O ambiente permanece no plano gratuito.', 'nextool');
         if ($msg !== '' && stripos((string) $msg, 'nenhuma licença') === false) {
            $noLicenseMsg .= ' ' . sprintf(__('Detalhes: %s.', 'nextool'), $msg);
         }
         Session::addMessageAfterRedirect(
            $noLicenseMsg,
            false,
            INFO
         );
      } else {
         Session::addMessageAfterRedirect(
            sprintf(__('Licença inválida: %s. O ambiente permanecerá no plano gratuito até que uma licença ativa seja atribuída.', 'nextool'), $msg),
            false,
            INFO
         );
      }

      // Ambiente em modo FREE: não desinstala nem desativa módulos já instalados;
      // eles permanecem utilizáveis. Apenas atualizações e novos downloads
      // de módulos pagos ficam bloqueados até vincular uma licença.
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
Html::back();

