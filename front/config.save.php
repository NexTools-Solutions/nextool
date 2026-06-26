<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Save
 * -------------------------------------------------------------------------
 * Processa POST do formulário de configuração: salvar opções, validar licença,
 * regenerar HMAC, aceitar políticas. Requer sessão e CSRF.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

include ('../../../inc/includes.php');

// O endpoint atende ações de leitura (validate_license) e escrita;
// permissões específicas são reforçadas abaixo por ação (assertCanAccessAdminTabs / assertCanManageAdminTabs).
Session::checkLoginUser();

/**
 * Redireciona para a aba do Nextool quando o forcetab vier no POST.
 * Mantém fallback para Html::back() em fluxos legados.
 */
function plugin_nextool_redirect_after_action(): void
{
   $forcetab = trim((string) ($_POST['forcetab'] ?? ''));
   if ($forcetab !== '' && preg_match('/^PluginNextoolMainConfig\$\d+$/', $forcetab) === 1) {
      Html::redirect(
         Plugin::getWebDir('nextool')
         . '/front/nextoolconfig.form.php?id=1&forcetab='
         . rawurlencode($forcetab)
      );
      return;
   }

   Html::back();
}

// CSRF no GLPI 10 e 11:
// O core valida automaticamente no `inc/includes.php` para qualquer POST.

// Inclui classes adicionais
require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/licenseconfig.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/licensevalidator.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/configaudit.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/coreupdater.class.php';

// UUID v4 para correlacionar todos os eventos desta ação no ContainerAPI
$GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();

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

   // F1a -- enroll server-issued: install NOVO (sem identidade) + ContainerAPI configurado ->
   // o servidor CUNHA o environment_id único (mata a colisão localhost, garante unicidade por GLPI).
   // Sem fallback local: se o enroll falhar, o ambiente segue sem identidade (degrada para FREE) e
   // re-tenta no próximo save. Ambientes legados (identifier já presente) NÃO passam por aqui.
   if ($baseUrl !== '' && $clientIdentifier === '') {
      $enroll = PluginNextoolDistributionClient::enrollEnvironment($baseUrl);
      if ($enroll['environment_id'] === null || $enroll['client_secret'] === null) {
         return [
            'attempted'         => true,
            'success'           => false,
            'reused_secret'     => false,
            'settings'          => $distributionSettings,
            'client_identifier' => '',
            'error'             => $enroll['error'],
            'error_message'     => $enroll['message'],
            'http_code'         => $enroll['http_code'],
            'retry_after'       => $enroll['retry_after'],
         ];
      }

      $clientIdentifier = $enroll['environment_id'];
      // Persiste a identidade cunhada: main_configs + context distribution + provisioning resiliente.
      PluginNextoolConfig::setClientIdentifier($clientIdentifier);
      $distributionSettings['client_identifier'] = $clientIdentifier;
      Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
         'client_identifier' => $clientIdentifier,
         'client_secret'     => $enroll['client_secret'],
      ]));
      PluginNextoolConfig::setProvisioning($clientIdentifier, $enroll['client_secret']);

      if ($logAudit) {
         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'enroll',
            'result'  => 1,
            'message' => __('Ambiente registrado no servidor de licenciamento (identidade emitida pelo servidor).', 'nextool'),
            'details' => ['base_url' => $baseUrl],
         ]);
      }

      return [
         'attempted'         => true,
         'success'           => true,
         'reused_secret'     => false,
         'settings'          => PluginNextoolConfig::getDistributionSettings(),
         'client_identifier' => $clientIdentifier,
         'error'             => null,
         'error_message'     => null,
         'http_code'         => $enroll['http_code'],
         'retry_after'       => null,
      ];
   }

   $needsBootstrap = $baseUrl !== '' && $clientIdentifier !== '' && empty($distributionSettings['client_secret']);

   if (!$needsBootstrap) {
      PluginNextoolConfig::debugLog("[DEBUG] [ConfigSave] bootstrap_hmac_if_needed: não necessário (secret já existe ou config incompleta)\n");
      return [
         'attempted'         => false,
         'success'           => true,
         'reused_secret'     => false,
         'settings'          => $distributionSettings,
         'client_identifier' => $clientIdentifier,
         'error'             => null,
         'error_message'     => null,
         'http_code'         => 0,
         'retry_after'       => null,
      ];
   }

   Toolbox::logInFile('plugin_nextool', sprintf(
      "[DEBUG] [ConfigSave] bootstrap_hmac_if_needed: tentando obter secret para %s\n",
      $clientIdentifier
   ));
   $result = PluginNextoolDistributionClient::obtainOrReuseClientSecret(
      $baseUrl,
      $clientIdentifier
   );

   if ($result['secret'] === null) {
      return [
         'attempted'         => true,
         'success'           => false,
         'reused_secret'     => false,
         'settings'          => $distributionSettings,
         'client_identifier' => $clientIdentifier,
         'error'             => $result['error'],
         'error_message'     => $result['message'],
         'http_code'         => $result['http_code'],
         'retry_after'       => $result['retry_after'],
      ];
   }

   Config::setConfigurationValues('plugin:nextool_distribution', array_merge($distributionSettings, [
      'client_secret' => $result['secret'],
   ]));

   // Persiste o vínculo de provisionamento no context que sobrevive ao uninstall.
   PluginNextoolConfig::setProvisioning(
      (string) ($distributionSettings['client_identifier'] ?? ''),
      (string) $result['secret']
   );

   if ($logAudit) {
      PluginNextoolConfigAudit::log([
         'section' => 'distribution',
         'action'  => 'bootstrap',
         'result'  => 1,
         'message' => __('Chave de segurança gerada automaticamente.', 'nextool'),
         'details' => [
            'base_url'               => $baseUrl,
            'reused_existing_secret' => $result['reused'] ? 1 : 0,
         ],
      ]);
   }

   $updatedSettings = PluginNextoolConfig::getDistributionSettings();

   return [
      'attempted'         => true,
      'success'           => true,
      'reused_secret'     => $result['reused'],
      'settings'          => $updatedSettings,
      'client_identifier' => trim((string) ($updatedSettings['client_identifier'] ?? $clientIdentifier)),
      'error'             => null,
      'error_message'     => null,
      'http_code'         => 0,
      'retry_after'       => null,
   ];
}

// Verificação de permissão depende da ação
$action = $_POST['action'] ?? '';

$validActions = ['', 'validate_license', 'accept_policies', 'unlink_environment'];
if (!in_array($action, $validActions, true)) {
   http_response_code(400);
   die(json_encode(['success' => false, 'message' => __('Ação inválida.', 'nextool')]));
}

// Ação "validate_license" requer apenas READ (visualizar/consultar)
if ($action === 'validate_license') {
   PluginNextoolPermissionManager::assertCanAccessAdminTabs();
} else {
   // Outras ações requerem UPDATE (modificar)
   PluginNextoolPermissionManager::assertCanManageAdminTabs();
}

// F5 -- ação 'regenerate_hmac' removida: a rotação de segredo/identidade é coordenada pelo servidor
// (re-enroll/migrate). O fluxo local sempre falhava por design (CR-01 recusa reemitir secret de
// ambiente já provisionado -> 409). Provisão inicial é automática (enroll na sincronização).

if ($action === 'unlink_environment') {
   // Desvincular ambiente: limpa o vínculo de provisionamento LOCAL (segredo
   // HMAC persistido + tabela legacy). Use ao descomissionar este ambiente ou
   // antes de re-provisionar do zero. NOTA: o ambiente continua provisionado no
   // SERVIDOR -- um novo bootstrap só terá sucesso após o administrador resetar
   // o provisionamento (ritecadmin), senão retornará 409.
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $clientIdentifier = trim((string)($distributionSettings['client_identifier'] ?? ''));

   PluginNextoolConfig::clearProvisioning();
   // Também limpa o segredo no context de distribuição (mantém base_url/identifier).
   $distValues = Config::getConfigurationValues('plugin:nextool_distribution');
   if (isset($distValues['client_secret'])) {
      unset($distValues['client_secret']);
      Config::setConfigurationValues('plugin:nextool_distribution', $distValues);
   }

   PluginNextoolConfigAudit::log([
      'section' => 'distribution',
      'action'  => 'unlink_environment',
      'result'  => 1,
      'message' => __('Vínculo de provisionamento local removido.', 'nextool'),
      'details' => ['environment_identifier' => $clientIdentifier],
   ]);

   Session::addMessageAfterRedirect(
      __('Vínculo de provisionamento removido localmente. Para provisionar novamente, solicite ao administrador o reset deste ambiente no servidor.', 'nextool'),
      false,
      INFO
   );

   plugin_nextool_redirect_after_action();
   exit;
}

if ($action === 'accept_policies') {
   $distributionSettings = PluginNextoolConfig::getDistributionSettings();
   $baseUrl = trim((string)($distributionSettings['base_url'] ?? ''));
   $clientIdentifier = trim((string)($distributionSettings['client_identifier'] ?? ''));

   // Deadlock-fix (regressao da F1a): a identidade NX2- e cunhada pelo SERVIDOR no enroll, que
   // roda logo abaixo em plugin_nextool_bootstrap_hmac_if_needed (quando base_url existe e
   // client_identifier esta vazio). Exigir client_identifier AQUI criava um impasse -- para
   // aceitar precisava da identidade que so o proprio aceite gera. So a base_url e pre-requisito
   // real: e o aceite das politicas que DISPARA o enroll (consentimento ANTES da telemetria).
   if ($baseUrl === '') {
      Session::addMessageAfterRedirect(
         __('Configure a URL do ContainerAPI antes de aceitar as políticas de uso.', 'nextool'),
         false,
         WARNING
      );
      plugin_nextool_redirect_after_action();
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
         // 409 = ambiente já provisionado no servidor, mas o segredo local foi perdido
         // (ex.: reinstalação/atualização do plugin). Por segurança o segredo não é
         // reentregue; re-tentar não resolve e só gera ruído. Orientar o reset do
         // provisionamento pelo administrador, em vez de mandar "tentar novamente".
         if ((int) ($bootstrap['http_code'] ?? 0) === 409) {
            $errorMessage = __('Este ambiente já está provisionado no servidor de licenciamento e, por segurança, o segredo não é reenviado automaticamente. Se o segredo local foi perdido, solicite ao administrador o reset do provisionamento deste ambiente; depois você poderá aceitar as políticas novamente.', 'nextool');
         } else {
            $errorMessage = $bootstrap['error_message']
               ?? __('Não foi possível gerar a chave de segurança automaticamente. Tente novamente em instantes.', 'nextool');
         }
         Session::addMessageAfterRedirect($errorMessage, false, WARNING);

         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap falhou durante aceite de políticas — error: %s, http_code: %d, message: %s',
            $bootstrap['error'] ?? '(unknown)',
            $bootstrap['http_code'] ?? 0,
            $bootstrap['error_message'] ?? '(none)'
         ));

         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'bootstrap_accept_policies',
            'result'  => 0,
            'message' => $errorMessage,
            'details' => [
               'error'       => $bootstrap['error'] ?? null,
               'http_code'   => $bootstrap['http_code'] ?? 0,
               'retry_after' => $bootstrap['retry_after'] ?? null,
            ],
         ]);

         plugin_nextool_redirect_after_action();
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
         'license_status'  => $result['license_status'] ?? null,
      ],
   ]);

   plugin_nextool_redirect_after_action();
   exit;
}

// Snapshot antes de qualquer alteração (também usado no fluxo de validação)
$previousGlobalConfig = PluginNextoolConfig::getConfig();

// Persistir configuração global apenas quando os campos vierem no POST.
// Isso evita sobrescrever dados em ações como validate_license via nextoolSyncForm.
// Item 6 (5.0.x): a URL da plataforma é controlada pelo servidor (campo read-only no licenciamento).
// O form nunca altera a base_url -- ela só muda via adoptPlatformUrl no sync. Ignora endpoint_url postado.
$endpointWasPosted = false;
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
      } elseif (!filter_var($newEndpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $newEndpoint)) {
         Session::addMessageAfterRedirect(
            __('URL do ContainerAPI inválida. Informe uma URL válida com protocolo (https://).', 'nextool'),
            false,
            ERROR
         );
         plugin_nextool_redirect_after_action();
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
         $errorMessage = $bootstrap['error_message']
            ?? __('Não foi possível gerar a chave de segurança automaticamente. Tente novamente em instantes.', 'nextool');
         Session::addMessageAfterRedirect($errorMessage, false, WARNING);

         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap falhou durante validação de licença — error: %s, http_code: %d, message: %s',
            $bootstrap['error'] ?? '(unknown)',
            $bootstrap['http_code'] ?? 0,
            $bootstrap['error_message'] ?? '(none)'
         ));

         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'bootstrap_validate_license',
            'result'  => 0,
            'message' => $errorMessage,
            'details' => [
               'error'       => $bootstrap['error'] ?? null,
               'http_code'   => $bootstrap['http_code'] ?? 0,
               'retry_after' => $bootstrap['retry_after'] ?? null,
            ],
         ]);
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
      // evitando redundâncias do tipo "Licença válida: Licença válida (LICENCIADO)".
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

      $resultHttpCode = $result['http_code'] ?? null;
      $isCommError = $resultHttpCode !== null && ($resultHttpCode >= 400 || $resultHttpCode === 0);

      // Verificar license_status ANTES de isCommError: HTTP 403 com license_status
      // é resposta de negócio (SUSPENDED, CANCELLED), não erro de comunicação.
      $licenseStatus = strtoupper((string)($result['license_status'] ?? ''));
      $planLabel = !empty($result['plan']) ? $result['plan'] : 'FREE';

      if ($resultError === 'unauthorized') {
         $msg = __('Ainda estamos preparando este ambiente na plataforma NexTool. Aguarde alguns instantes e clique novamente em "Sincronizar" para concluir. Enquanto isso, o ambiente permanece no plano gratuito.', 'nextool');
         Session::addMessageAfterRedirect(
            $msg,
            false,
            INFO
         );
      } elseif ($licenseStatus === 'SUSPENDED') {
         $contactUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?forcetab=PluginNextoolMainConfig%242';
         Session::addMessageAfterRedirect(
            sprintf(__('Sincronização concluída: a licença do plano %s está suspensa. Os módulos já instalados continuam funcionando. Regularize sua situação para restaurar o acesso a suporte técnico, atualizações e novos downloads.', 'nextool'), $planLabel)
            . ' <a href="' . htmlspecialchars($contactUrl) . '">' . __('Entre em contato com o suporte.', 'nextool') . '</a>',
            false,
            WARNING
         );
      } elseif ($licenseStatus === 'CANCELLED') {
         $contactUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?forcetab=PluginNextoolMainConfig%242';
         Session::addMessageAfterRedirect(
            sprintf(__('Sincronização concluída: a licença do plano %s foi cancelada. Os módulos licenciados ficarão bloqueados.', 'nextool'), $planLabel)
            . ' <a href="' . htmlspecialchars($contactUrl) . '">' . __('Entre em contato com o suporte para reativar.', 'nextool') . '</a>',
            false,
            WARNING
         );
      } elseif ($licenseStatus === 'INACTIVE') {
         if (strtoupper($planLabel) === 'FREE') {
            Session::addMessageAfterRedirect(
               __('Sincronização concluída com sucesso. Seu ambiente está no plano gratuito — todos os módulos gratuitos estão disponíveis.', 'nextool'),
               false,
               INFO
            );
         } else {
            Session::addMessageAfterRedirect(
               sprintf(__('Sincronização concluída: a licença do plano %s foi encontrada, mas ainda não está ativa. Entre em contato com o suporte para ativação.', 'nextool'), $planLabel),
               false,
               INFO
            );
         }
      } elseif ($isCommError) {
         // Erro de comunicação real (rate limit, timeout, indisponibilidade):
         // só chega aqui se NÃO há license_status na resposta.
         Session::addMessageAfterRedirect($msg, false, WARNING);
      } elseif (empty($licensesInfo)) {
         Session::addMessageAfterRedirect(
            __('Sincronização concluída com sucesso. Nenhuma licença comercial vinculada — o ambiente opera no plano gratuito.', 'nextool'),
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
      'licenses_count'    => isset($result['licenses']) && is_array($result['licenses']) ? count($result['licenses']) : 0,
   ];
   Toolbox::logInFile('plugin_nextool', 'Manual validation payload: ' . json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

   // Resultado para auditoria: sucesso = sincronização concluída (servidor respondeu).
   // Falha = erro real de comunicação (timeout, sem resposta, 5xx).
   // HTTP 200 + valid=false + plan=FREE é sincronização bem-sucedida, não falha.
   // HTTP 403 com license_status/plan = resposta de negócio válida (ex: SUSPENDED), não erro.
   $syncHttpCode = (int) ($result['http_code'] ?? 0);
   $syncSucceeded = ($syncHttpCode >= 200 && $syncHttpCode < 500) && $syncHttpCode !== 0;

   PluginNextoolConfigAudit::log([
      'section' => 'validation',
      'action'  => 'manual_validation',
      'result'  => $syncSucceeded ? 1 : 0,
      'message' => $msg,
      'details' => [
         'http_code'        => $result['http_code'] ?? null,
         'license_status'   => $result['license_status'] ?? null,
         'plan'             => $result['plan'] ?? null,
      ],
   ]);

   // Após "Sincronizar", atualiza o estado de core update para acender
   // automaticamente o botão do quick-flow quando houver versão nova.
   try {
      $coreUpdater = new PluginNextoolCoreUpdater();
      $coreCheck = $coreUpdater->check('stable', 'manual_sync');
      Toolbox::logInFile('plugin_nextool', sprintf(
         'Core check após sincronização: success=%s update_available=%s target=%s',
         !empty($coreCheck['success']) ? 'true' : 'false',
         (!empty($coreCheck['data']['update_available']) ? 'true' : 'false'),
         (string)($coreCheck['data']['target_version'] ?? '')
      ));
   } catch (Throwable $e) {
      Toolbox::logInFile('plugin_nextool', sprintf(
         'Falha ao atualizar estado de core update após sincronização: %s',
         $e->getMessage()
      ));
   }
}

// Redireciona de volta para a página de configuração
plugin_nextool_redirect_after_action();
