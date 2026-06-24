<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - License Validator
 * -------------------------------------------------------------------------
 * Validador de licença do NexTool Solutions (plugin operacional).
 *
 * Responsável por:
 * - Ler configuração de licença/endpoints
 * - Decidir quando usar cache ou chamar a API remota (ContainerAPI)
 * - Atualizar o cache local (tabela glpi_plugin_nextool_main_license_config)
 * - Registrar tentativas (glpi_plugin_nextool_main_validation_attempts)
 *
 * A decisão de bloqueio/desativação de módulos é aplicada em outras
 * camadas (ModuleManager / UI), com base no snapshot retornado.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/logmaintenance.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/validationattempt.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/hmacsignaturetrait.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/entitlementtoken.class.php';

class PluginNextoolLicenseValidator {

   use PluginNextoolHmacSignatureTrait;

   /** Aliases de planos legados → nomes atuais (espelho do PlanNormalizer do ContainerAPI). */
   private const PLAN_ALIASES = [
      'STARTER' => 'DESENVOLVIMENTO',
      'PRO'     => 'LICENCIADO',
   ];

   /**
    * F2 -- capabilities anunciadas ao ContainerAPI (header X-Nextool-Caps). `entitlement-v1` =
    * este plugin verifica o token de entitlement (Ed25519, offline) e trata metadata vazia na
    * negação sem quebrar. Gate de compatibilidade por capability (nunca por versão numérica).
    */
   public const NEXTOOL_CAPABILITIES = 'entitlement-v1';

   /**
    * Namespaces de configuração persistidos via Config::setConfigurationValues por este validator.
    * Útil para uninstall do plugin (limpar todos os namespaces de uma vez).
    */
   public const CONFIG_NAMESPACES = [
      'plugin:nextool_billing',
      'plugin:nextool_entitlement',
      'plugin:nextool_core_update',
   ];

   /**
    * Encapsula Config::setConfigurationValues + try/catch + log de falha.
    * Logs vão para o arquivo plugin_nextool com prefixo do label.
    */
   private static function persistConfig(string $namespace, array $values, string $logLabel): void {
      try {
         Config::setConfigurationValues($namespace, $values);
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'LicenseValidator: falha ao persistir %s - %s',
            $logLabel,
            $e->getMessage()
         ));
      }
   }

   /**
    * Item 6 -- a URL da plataforma é controlada pelo servidor (campo read-only no plugin). Adota o
    * platform_url recebido no /validate SE for https válido (defesa contra redirecionamento indevido)
    * e diferente do atual. Atualiza a base_url do context plugin:nextool_distribution.
    */
   private static function adoptPlatformUrl(string $url): void {
      $url = rtrim(trim($url), '/');
      if ($url === '' || stripos($url, 'https://') !== 0 || filter_var($url, FILTER_VALIDATE_URL) === false) {
         return;
      }
      $dist = Config::getConfigurationValues('plugin:nextool_distribution');
      $current = isset($dist['base_url']) ? rtrim(trim((string) $dist['base_url']), '/') : '';
      if ($url === $current) {
         return;
      }
      $dist['base_url'] = $url;
      self::persistConfig('plugin:nextool_distribution', $dist, 'platform_url');
      Toolbox::logInFile('plugin_nextool', sprintf('LicenseValidator: platform_url adotada do servidor: %s', $url));
   }

   /**
    * Normaliza nome de plano, convertendo aliases legados para os nomes atuais.
    */
   public static function normalizePlan(?string $plan): ?string {
      if ($plan === null) {
         return null;
      }
      $upper = strtoupper(trim($plan));
      return self::PLAN_ALIASES[$upper] ?? $upper;
   }

   /**
    * Valida a licença atual
    *
    * @param array $options
    *   - force_refresh (bool): ignora cache e força chamada à API
    *   - context (array): informações adicionais (ex: módulos sendo usados)
    *
    * @return array
    *   - valid (bool)
    *   - message (string)
    *   - allowed_modules (array)
    *   - source (string) cache|remote|error
    *   - http_code (int|null)
    *   - response_time_ms (int|null)
    *   - consecutive_failures (int)
    */
   public static function validateLicense(array $options = []) {
      global $DB;

      $force_refresh = !empty($options['force_refresh']);
      if (PluginNextoolConfig::isDebugEnabled()) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [LicenseValidator] validateLicense() force_refresh=%s\n",
            $force_refresh ? 'true' : 'false'
         ));
      }
      $context       = isset($options['context']) && is_array($options['context'])
         ? $options['context']
         : [];

      // Pequena manutenção preventiva dos logs (limpa registros antigos a cada 12h)
      PluginNextoolLogMaintenance::maybeRun();

      // Config global do plugin (client_identifier, endpoint padrão)
      $globalConfig = PluginNextoolConfig::getConfig();
      $clientId     = $globalConfig['client_identifier'] ?? null;
      $globalEndpoint = null;
      $distributionSettings = PluginNextoolConfig::getDistributionSettings();
      $distributionBaseUrl = isset($distributionSettings['base_url'])
         ? trim((string) $distributionSettings['base_url'])
         : '';
      $distributionClientIdentifier = isset($distributionSettings['client_identifier'])
         ? trim((string) $distributionSettings['client_identifier'])
         : '';
      $distributionClientSecret = isset($distributionSettings['client_secret'])
         ? trim((string) $distributionSettings['client_secret'])
         : '';
      if ($distributionClientIdentifier === '' && !empty($clientId)) {
         $distributionClientIdentifier = trim((string) $clientId);
      }
      if ($distributionClientIdentifier !== '') {
         $clientId = $distributionClientIdentifier;
      }

      // Config específica de licença (tabela nova)
      $licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();

      $licenseKey = $licenseConfig['license_key'] ?? null;
      $plan       = self::normalizePlan($licenseConfig['plan'] ?? null);
      $apiEndpoint = null;
      $apiSecret   = null;
      $useDistributionValidation = $distributionBaseUrl !== ''
         && $distributionClientIdentifier !== ''
         && $distributionClientSecret !== '';
      if ($useDistributionValidation) {
         $apiEndpoint = rtrim($distributionBaseUrl, '/') . '/api/licensing/validate';
         $apiSecret   = $distributionClientSecret;
      }

      // Valida pré-condições mínimas
      // Para permitir registro de ambiente mesmo sem chave de licença,
      // apenas o endpoint é obrigatório. O identificador do ambiente é desejável,
      // mas sua ausência não deve impedir o uso em modo FREE tier.
      $origin     = isset($context['origin']) ? (string)$context['origin'] : '';
      $requestedModules = isset($context['requested_modules']) && is_array($context['requested_modules'])
         ? $context['requested_modules']
         : null;

      $userId = null;
      if (class_exists('Session')) {
         $userId = Session::getLoginUserID();
      }

      $logBase = [
         'origin'            => $origin,
         'requested_modules' => $requestedModules,
         'client_identifier' => $clientId,
         'plan'              => $plan,
         'force_refresh'     => $force_refresh ? 1 : 0,
         'user_id'           => $userId,
         'cache_hit'         => 0,
      ];

      $recordAttempt = function(array $payload) use (&$logBase, $context) {
         if (!self::shouldLogAttempt($context)) {
            return;
         }
         PluginNextoolValidationAttempt::logAttempt(array_merge($logBase, $payload));
      };

      if (!$licenseKey && $plan !== 'FREE') {
         $plan = 'FREE';
      }

      if (empty($apiEndpoint)) {
         $hasLicense = !empty($licenseKey);
         $message = $hasLicense
            ? __('Validação local concluída: licença registrada. O ContainerAPI confirmará esta chave durante o download de módulos.', 'nextool')
            : __('Validação local concluída: nenhuma chave informada. O ContainerAPI manterá este ambiente no modo FREE até que uma licença seja aplicada.', 'nextool');

         $recordAttempt([
            'result'           => true,
            'message'          => $message,
            'http_code'        => null,
            'response_time_ms' => null,
         ]);

         $state = [
            'plan'             => $plan,
            'license_status'   => $hasLicense
               ? ($licenseConfig['license_status'] ?? null)
               : null,
            'warnings'         => [],
            'licenses'         => [],
         ];

         self::updateLicenseCache(
            $licenseConfig,
            $licenseKey,
            $plan,
            null,
            null,
            true,
            $message,
            [],
            $state
         );

         return [
            'valid'               => true,
            'message'             => $message,
            'allowed_modules'     => [],
            'source'              => 'local',
            'http_code'           => null,
            'response_time_ms'    => null,
            'consecutive_failures'=> 0,
            'plan'                => $plan,
            'license_status'      => $state['license_status'],
         ];
      }

      // Verifica cache (última validação bem-sucedida recente)
      $now        = time();
      $cache_ttl  = 24 * 60 * 60; // 24h
      $lastResult = isset($licenseConfig['last_validation_result'])
         ? (int)$licenseConfig['last_validation_result']
         : null;
      $lastDate   = !empty($licenseConfig['last_validation_date'])
         ? strtotime($licenseConfig['last_validation_date'])
         : null;
      $origin     = isset($context['origin']) ? (string)$context['origin'] : '';

      // IMPORTANTE:
      // - Para chamadas gerais (ex.: instalação de módulo), podemos reutilizar o cache de 24h.
      // - Para o snapshot da tela de configuração (origin = config_status), queremos SEMPRE
      //   refletir o estado mais recente de contrato/status retornado pelo administrativo.
      if (
         !$force_refresh
         && $origin !== 'config_status'
         && $lastResult === 1
         && !empty($lastDate)
         && ($now - $lastDate) <= $cache_ttl
      ) {
         $modules = [];
         if (!empty($licenseConfig['cached_modules'])) {
            $decoded = json_decode($licenseConfig['cached_modules'], true);
            if (is_array($decoded)) {
               $modules = $decoded;
            }
         }

         $cachedWarnings = [];
         if (!empty($licenseConfig['warnings'])) {
            $decodedWarnings = json_decode($licenseConfig['warnings'], true);
            if (is_array($decodedWarnings)) {
               $cachedWarnings = $decodedWarnings;
            }
         }

         $cachedLicenses = [];
         if (!empty($licenseConfig['licenses_snapshot'])) {
            $decodedLicenses = json_decode($licenseConfig['licenses_snapshot'], true);
            if (is_array($decodedLicenses)) {
               $cachedLicenses = $decodedLicenses;
            }
         }

         return [
            'valid'               => true,
            'message'             => !empty($licenseConfig['last_validation_message'])
               ? $licenseConfig['last_validation_message']
               : __('Licença válida (cache recente)', 'nextool'),
            'allowed_modules'     => $modules,
            'source'              => 'cache',
            'http_code'           => null,
            'response_time_ms'    => null,
            'consecutive_failures'=> (int)($licenseConfig['consecutive_failures'] ?? 0),
            'license_status'      => $licenseConfig['license_status'] ?? null,
            'expires_at'          => $licenseConfig['expires_at'] ?? null,
            'warnings'            => $cachedWarnings,
            'plan'                => $plan,
            'licenses'            => $cachedLicenses,
         ];
      }

      // Cache negativo: resultado no_license/inválido recente (10 min)
      // Evita spam de chamadas à API para ambientes sem licença ativa.
      $negative_cache_ttl = 10 * 60; // 10 minutos
      if (
         !$force_refresh
         && $origin !== 'config_status'
         && $lastResult === 0
         && !empty($lastDate)
         && ($now - $lastDate) <= $negative_cache_ttl
      ) {
         return [
            'valid'               => false,
            'message'             => !empty($licenseConfig['last_validation_message'])
               ? $licenseConfig['last_validation_message']
               : __('Sem licença ativa (cache recente)', 'nextool'),
            'allowed_modules'     => [],
            'source'              => 'negative_cache',
            'http_code'           => null,
            'response_time_ms'    => null,
            'consecutive_failures'=> (int)($licenseConfig['consecutive_failures'] ?? 0),
            'license_status'      => $licenseConfig['license_status'] ?? 'no_license',
            'expires_at'          => null,
            'warnings'            => [],
            'plan'                => $plan ?? 'FREE',
            'licenses'            => [],
         ];
      }

      // Monta payload para API
      $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

      $clientInfo = [
         'plugin_version' => PluginNextoolConfig::getPluginVersion(),
         'glpi_version'   => defined('GLPI_VERSION') ? GLPI_VERSION : null,
         'php_version'    => PHP_VERSION,
      ];

      // Só envia environment_id se tivermos um identificador gerado; se não tiver,
      // o administrativo ainda pode tratar o ambiente como FREE tier.
      if (!empty($clientId)) {
         $clientInfo['environment_id'] = $clientId;
      }

      $shouldSendLicenseKey = empty($clientId) && !empty($licenseKey);

      $payload = [
         'license_key' => $shouldSendLicenseKey ? $licenseKey : null,
         'domain'      => $domain,
         'action'      => 'validate',
         'client_info' => $clientInfo,
      ];

      // Anexa estado local dos módulos (para futura sincronização de catálogo)
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         $localModules = [];
         $iterator = $DB->request([
            'SELECT' => ['module_key', 'name', 'version', 'billing_tier', 'is_enabled', 'is_available'],
            'FROM'   => 'glpi_plugin_nextool_main_modules',
         ]);
         foreach ($iterator as $row) {
            $localModules[] = [
               'module_key'   => $row['module_key'],
               'name'         => $row['name'],
               'version'      => $row['version'],
               'billing_tier' => $row['billing_tier'] ?? null,
               'is_enabled'   => (int)($row['is_enabled'] ?? 0) === 1,
               'is_available' => array_key_exists('is_available', $row)
                  ? ((int)$row['is_available'] === 1)
                  : true,
            ];
         }

         if (!empty($localModules)) {
            $payload['modules'] = $localModules;
         }
      }

      // Informação opcional de contexto (ex: módulos que querem usar)
      if (!empty($context)) {
         $payload['context'] = $context;
      }

      $httpCode        = null;
      $responseTimeMs  = null;

      if ($useDistributionValidation) {
         $responseData = self::callDistributionLicenseAPI(
            $apiEndpoint,
            $distributionClientIdentifier,
            $distributionClientSecret,
            $payload,
            $httpCode,
            $responseTimeMs
         );
      } else {
         $responseData = self::callValidationAPI($apiEndpoint, $apiSecret, $payload, $httpCode, $responseTimeMs);
      }

      $valid           = false;
      $message         = '';
      $allowedModules  = [];
      $licenseStatus   = null;
      $remoteExpiresAt = null;
      $warnings        = [];
      $licensesSnapshot = [];

      if ($responseData === null) {
         if ($httpCode === 404) {
            $message = __('Serviço de licença legado não encontrado (HTTP 404). Ambiente permanece em modo FREE.', 'nextool');
         } elseif ($httpCode !== null) {
            $message = sprintf(
               __('Falha ao comunicar com o servidor de licenças (HTTP %d).', 'nextool'),
               $httpCode
            );
         } else {
            $message = __('Falha ao comunicar com o servidor de licenças.', 'nextool');
         }

         $recordAttempt([
            'result'           => false,
            'message'          => $message,
            'http_code'        => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'license_status'   => null,
            'allowed_modules'  => json_encode([]),
         ]);
         $plan = 'FREE';
         $licenseStatus = null;

         if ($useDistributionValidation && $httpCode === 401) {
            $warnings[] = __('Assinatura HMAC rejeitada pelo ContainerAPI. Recrie o segredo HMAC na aba de licença e valide novamente.', 'nextool');
            Toolbox::logInFile(
               'plugin_nextool',
               sprintf('LicenseValidator: ContainerAPI retornou 401 (assinatura inválida) para %s.', $clientId ?: '(sem identificador)')
            );
         }

         // F2 -- graça offline: se há um token de entitlement assinado e ainda válido, confiamos nele
         // (resiliência ao ContainerAPI indisponível) em vez de degradar para FREE. Note que o bloco
         // de sucesso abaixo (sync de catálogo / entitlement anti-pirataria) NÃO roda aqui -- a graça
         // só preserva o direito já provado, sem aplicar alterações destrutivas offline.
         if (!self::applyOfflineEntitlement($clientId, $valid, $plan, $allowedModules, $licenseStatus, $warnings, $message)) {
            self::enforceFreeModeFallback('Falha ao comunicar com o ContainerAPI');
         }
      } else if (!empty($responseData['re_enroll_required'])) {
         // F3-B -- fork manual: descarta a identidade local e re-enrolla; encerra esta rodada em FREE
         // (o ambiente novo ainda não tem licença; o dono ativa o binding só no legítimo). A próxima
         // validação já usa a nova identidade.
         self::handleReEnrollSignal($distributionBaseUrl, $clientId);
         $valid = false;
         $plan = 'FREE';
         $allowedModules = [];
         $licenseStatus = null;
         $message = __('Ambiente em reconfiguração de identidade (re-enroll). Modo FREE até concluir.', 'nextool');
         self::enforceFreeModeFallback('re_enroll_required');
      } else {
         // Campos adicionais da nova fase 3 (podem ou não estar presentes conforme versão do administrativo)
         if (!empty($responseData['license_status'])) {
            $licenseStatus = strtoupper((string)$responseData['license_status']);
         }
         if (!empty($responseData['expires_at'])) {
            $remoteExpiresAt = (string)$responseData['expires_at'];
         }
         if (!empty($responseData['warnings']) && is_array($responseData['warnings'])) {
            $warnings = $responseData['warnings'];
         }
         if (!empty($responseData['licenses']) && is_array($responseData['licenses'])) {
            $licensesSnapshot = $responseData['licenses'];
         }

         if (isset($responseData['valid']) && $responseData['valid']) {
            $valid = true;

            // Plano retornado pelo administrativo (se houver)
            $planForMessage = null;
            if (isset($responseData['plan']) && is_string($responseData['plan']) && $responseData['plan'] !== '') {
               $planForMessage = self::normalizePlan($responseData['plan']);
               $plan = $planForMessage;
            }

            // Mensagem base (pode vir do administrativo)
            if (!empty($responseData['message'])) {
               $baseMessage = $responseData['message'];
            } else {
               $baseMessage = __('Licença válida', 'nextool');
            }

            // Enriquecemos a mensagem com o nome do plano quando conhecido
            if ($planForMessage !== null) {
               $message = sprintf('%s (%s)', $baseMessage, $planForMessage);
            } else {
               $message = $baseMessage;
            }
            if (!empty($responseData['allowed_modules']) && is_array($responseData['allowed_modules'])) {
               $allowedModules = $responseData['allowed_modules'];
            }
            // F2 -- verifica (anti-tamper) e persiste o token de entitlement assinado p/ uso offline.
            self::persistVerifiedEntitlement($clientId, $responseData);
         } else {
            $valid = false;
            // Pode vir "error" + "message" ou apenas "message"
            if (!empty($responseData['message'])) {
               $message = $responseData['message'];
            } elseif (!empty($responseData['error'])) {
               $message = (string)$responseData['error'];
            } else {
               $message = __('Licença inválida ou não autorizada.', 'nextool');
            }
         }

         // Atualiza plano se o administrativo informar explicitamente, mesmo em respostas inválidas
            if (isset($responseData['plan']) && is_string($responseData['plan']) && $responseData['plan'] !== '') {
               $plan = self::normalizePlan($responseData['plan']);
               $logBase['plan'] = $plan;
            }

         // Se o administrativo indicar que a licença não existe mais, limpamos a chave local.
         if (!empty($responseData['error']) && $responseData['error'] === 'license_not_found') {
            $licenseKey = null;
         }

         // Se o administrativo retornou explicitamente uma licença vinculada (ex: descoberta a partir do ambiente),
         // atualiza a chave de licença local antes de gravar o cache.
        if (isset($responseData['license_key']) && is_string($responseData['license_key']) && $responseData['license_key'] !== '') {
            $licenseKey = (string)$responseData['license_key'];
         } elseif (empty($licenseKey) && !empty($licensesSnapshot) && isset($licensesSnapshot[0]['license_key'])) {
            $candidate = (string)$licensesSnapshot[0]['license_key'];
            if ($candidate !== '') {
               $licenseKey = $candidate;
            }
         }

         // Aplica sincronização de catálogo de módulos, se fornecido pelo administrativo.
         // Regra: NÃO sincronar quando a chamada veio apenas do snapshot de status
         // da tela de configuração (origin = config_status). Assim, mudanças no
         // catálogo do ritecadmin só refletem localmente quando:
         //  - o usuário clicar em "Validar licença agora" (config.save.php), ou
         //  - houver validação explícita antes de instalar módulo.
         if (!empty($responseData['modules_catalog']) && is_array($responseData['modules_catalog'])) {
            $syncOrigin = isset($context['origin']) ? (string)$context['origin'] : '';
            if ($syncOrigin !== 'config_status') {
               $planForSync = self::normalizePlan($responseData['plan'] ?? null) ?? '';
               self::applyModulesCatalogSync($responseData['modules_catalog'], $planForSync);
            }
         }

         if (isset($responseData['core_update']) && is_array($responseData['core_update'])) {
            self::persistCoreUpdateHint($responseData['core_update']);
         }

         // Estado do vínculo de conta (server-driven): persiste p/ a UI (hero, modal, aba licença).
         // link_required: download FREE exige vínculo; linked/email: conta vinculada e qual.
         self::persistConfig('plugin:nextool_account_link', [
            'link_required' => !empty($responseData['account_link_required']) ? '1' : '0',
            'linked'        => !empty($responseData['account_linked']) ? '1' : '0',
            'email'         => isset($responseData['account_email']) ? (string) $responseData['account_email'] : '',
         ], 'account_link state');

         // Item 6: a URL da plataforma é controlada pelo servidor (campo read-only no plugin).
         if (!empty($responseData['platform_url'])) {
            self::adoptPlatformUrl((string) $responseData['platform_url']);
         }

         // Persistir payment_methods disponíveis (para modal dinâmico)
         if (!empty($responseData['payment_methods']) && is_array($responseData['payment_methods'])) {
            self::persistPaymentMethods($responseData['payment_methods']);
         }

         // Persistir alertas recebidos
         if (!empty($responseData['alerts']) && is_array($responseData['alerts'])) {
            self::persistAlerts($responseData['alerts']);
         }

         // Persistir e aplicar modules_entitlement (anti-pirataria)
         if (!empty($responseData['modules_entitlement']) && is_array($responseData['modules_entitlement'])) {
            self::persistModulesEntitlement($responseData['modules_entitlement']);
            // Aplicar entitlement APENAS se comunicação 100% OK e origin != config_status
            $syncOrigin = isset($context['origin']) ? (string)$context['origin'] : '';
            if ($valid && $syncOrigin !== 'config_status') {
               self::applyModulesEntitlement($responseData['modules_entitlement']);
            }
         }
      }

      if (!$valid) {
         $allowedModules = [];
         if (empty($plan)) {
            $plan = 'FREE';
         } else {
            $plan = self::normalizePlan($plan);
         }
         self::enforceFreeModeFallback('Licença inválida ou não autorizada');
      }

      // Registra tentativa (exceto em snapshots de status da tela de configuração)
      if (self::shouldLogAttempt($context)) {
         $recordAttempt([
            'result'           => $valid,
            'message'          => $message,
            'http_code'        => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'license_status'   => $licenseStatus,
            'plan'             => $plan,
            'allowed_modules'  => json_encode($allowedModules),
         ]);
      }

      self::logLicenseAlert([
         'license_status'  => $licenseStatus,
         'plan'            => $plan,
         'warnings'        => $warnings,
      ], $origin);

      // Atualiza cache na tabela de configuração de licença
      self::updateLicenseCache(
         $licenseConfig,
         $licenseKey,
         $plan,
         $apiEndpoint,
         $apiSecret,
         $valid,
         $message,
         $allowedModules,
         [
            'license_status'  => $licenseStatus,
            'expires_at'      => $remoteExpiresAt,
            'warnings'        => $warnings,
            'licenses'        => $licensesSnapshot,
         ]
      );

      $configAfter = PluginNextoolLicenseConfig::getDefaultConfig();

      return [
         'valid'               => $valid,
         'message'             => $message,
         'allowed_modules'     => $allowedModules,
         'source'              => 'remote',
         'http_code'           => $httpCode,
         'response_time_ms'    => $responseTimeMs,
         'consecutive_failures'=> (int)($configAfter['consecutive_failures'] ?? 0),
         'license_status'      => $licenseStatus,
         'expires_at'          => $remoteExpiresAt,
         'plan'                => $plan,
         'warnings'            => $warnings,
         'licenses'            => $licensesSnapshot,
      ];
   }

   /**
    * Aplica sincronização do catálogo de módulos retornado pelo administrativo.
    * Módulos DEV (billing_tier=DEV) são ocultados para ambientes sem plano DESENVOLVIMENTO:
    * - não são inseridos ou são marcados como indisponíveis;
    * - módulos DEV já existentes localmente e ausentes no catálogo (ex.: plano mudou)
    *   são desabilitados (is_available=0, is_enabled=0).
    *
    * @param array $catalog Catálogo retornado pelo ContainerAPI
    * @param string $plan Plano do ambiente (FREE, LICENCIADO, DESENVOLVIMENTO, ENTERPRISE)
    * @return void
    */
   protected static function applyModulesCatalogSync(array $catalog, string $plan = '') {
      global $DB;

      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return;
      }

      $schemaUpdated = false;
      $migration = new Migration(101);

      if ($DB->fieldExists($table, 'version')) {
         $migration->addPostQuery(
            "ALTER TABLE `{$table}` MODIFY `version` varchar(20) DEFAULT NULL COMMENT 'Versão instalada do módulo'"
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'available_version')) {
         $migration->addField(
            $table,
            'available_version',
            'varchar(20)',
            [
               'value'   => null,
               'comment' => 'Última versão disponível no catálogo oficial',
               'after'   => 'version',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'description')) {
         $migration->addField(
            $table,
            'description',
            'text',
            [
               'value'   => null,
               'comment' => 'Descrição do módulo',
               'after'   => 'name',
            ]
         );
         $schemaUpdated = true;
      }

      $migrationMinVer = new Migration(102);
      if (!$DB->fieldExists($table, 'min_version_nextools')) {
         $migrationMinVer->addField(
            $table,
            'min_version_nextools',
            'varchar(50)',
            [
               'value'   => null,
               'comment' => 'Versão mínima do plugin Nextool para este módulo',
               'after'   => 'available_version',
            ]
         );
         $migrationMinVer->executeMigration();
      }

      if (!$DB->fieldExists($table, 'website_url')) {
         $migUrl = new Migration(103);
         $migUrl->addField(
            $table,
            'website_url',
            'varchar(512)',
            [
               'value'   => null,
               'comment' => 'URL da pagina do modulo no site',
               'after'   => 'min_version_nextools',
            ]
         );
         $migUrl->executeMigration();
      }

      if (!$DB->fieldExists($table, 'icon')) {
         $migIcon = new Migration(104);
         $migIcon->addField(
            $table,
            'icon',
            'varchar(100)',
            [
               'value'   => 'ti ti-puzzle',
               'comment' => 'Classe CSS do icone (Tabler Icons)',
               'after'   => 'description',
            ]
         );
         $migIcon->executeMigration();
      }

      foreach ([
         105 => ['price_cents',        'int unsigned',  'website_url',       'Preco anual em centavos'],
         106 => ['category',           'varchar(50)',   'price_cents',       'Categoria do modulo'],
         107 => ['features_json',      'text',          'category',          'JSON array com features'],
         108 => ['screenshot_url',     'varchar(512)',  'features_json',     'URL do screenshot'],
         109 => ['download_count',     'int unsigned',  'screenshot_url',    'Total de downloads'],
         110 => ['compat_glpi_majors', 'varchar(20)',   'min_version_nextools', 'CSV de versoes major GLPI compativeis (ex: "10,11")'],
      ] as $ver => [$field, $type, $after, $comment]) {
         if (!$DB->fieldExists($table, $field)) {
            $m = new Migration($ver);
            $m->addField($table, $field, $type, ['value' => null, 'comment' => $comment, 'after' => $after]);
            $m->executeMigration();
         }
      }

      if ($schemaUpdated) {
         $migration->executeMigration();
      }

      $planUpper = strtoupper(trim($plan));
      $envHasDevLicense = ($planUpper === 'DESENVOLVIMENTO');
      $moduleKeysInCatalog = [];

      foreach ($catalog as $entry) {
         $moduleKey = isset($entry['module_key']) ? trim((string)$entry['module_key']) : '';
         if ($moduleKey === '') {
            continue;
         }
         $moduleKeysInCatalog[] = $moduleKey;

         $name        = isset($entry['name']) ? trim((string)$entry['name']) : '';
         $description = array_key_exists('description', $entry) ? trim((string)$entry['description']) : null;
         $version     = isset($entry['version']) ? trim((string)$entry['version']) : '';
         $billingTier = isset($entry['billing_tier']) ? strtoupper(trim((string)$entry['billing_tier'])) : '';
         $isEnabled   = !empty($entry['is_enabled']);
         $minVersionNextools = isset($entry['min_version_nextools']) ? trim((string)$entry['min_version_nextools']) : '';
         if ($minVersionNextools === '') {
            $minVersionNextools = null;
         }
         $websiteUrl = isset($entry['website_url']) ? trim((string)$entry['website_url']) : '';
         if ($websiteUrl === '') {
            $websiteUrl = null;
         }
         $icon = isset($entry['icon']) ? trim((string)$entry['icon']) : '';
         if ($icon === '') {
            $icon = null;
         }
         $priceCents = isset($entry['price_cents']) && $entry['price_cents'] !== null ? (int)$entry['price_cents'] : null;
         $category = isset($entry['category']) ? trim((string)$entry['category']) : null;
         if ($category === '') { $category = null; }
         $featuresJson = isset($entry['features_json']) ? trim((string)$entry['features_json']) : null;
         if ($featuresJson === '') { $featuresJson = null; }
         $screenshotUrl = isset($entry['screenshot_url']) ? trim((string)$entry['screenshot_url']) : null;
         if ($screenshotUrl === '') { $screenshotUrl = null; }
         $downloadCount = isset($entry['download_count']) ? (int)$entry['download_count'] : 0;

         // Bloco platforms (ContainerAPI 4.0+). Quando ausente, cai no fallback
         // legado: módulo é considerado compatível apenas com a plataforma atual.
         $compatMajors = null;
         if (isset($entry['platforms']) && is_array($entry['platforms'])) {
            $list = [];
            if (!empty($entry['platforms']['glpi_10']['available'])) { $list[] = '10'; }
            if (!empty($entry['platforms']['glpi_11']['available'])) { $list[] = '11'; }
            $compatMajors = $list ? implode(',', $list) : null;
         }

         if ($billingTier === '') {
            $billingTier = 'FREE';
         }

         $isAvailable = $isEnabled ? 1 : 0;
         // Módulos DEV: exibir apenas para ambientes com plano DESENVOLVIMENTO (defesa em profundidade)
         if ($billingTier === 'DEV' && !$envHasDevLicense) {
            $isAvailable = 0;
         }

        $iterator = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['module_key' => $moduleKey],
            'LIMIT' => 1,
        ]);

        if (count($iterator)) {
            $row = $iterator->current();
            $updateData = [
               'name'                  => $name !== '' ? $name : $moduleKey,
               'description'           => $description !== null ? ($description !== '' ? $description : null) : ($row['description'] ?? null),
               'billing_tier'          => $billingTier,
               'is_available'          => $isAvailable,
               'available_version'     => $version !== '' ? $version : null,
               'min_version_nextools'  => $minVersionNextools,
               'compat_glpi_majors'    => $compatMajors,
               'website_url'           => $websiteUrl,
               'icon'                  => $icon,
               'price_cents'           => $priceCents,
               'category'              => $category,
               'features_json'         => $featuresJson,
               'screenshot_url'        => $screenshotUrl,
               'download_count'        => $downloadCount,
               'date_mod'              => date('Y-m-d H:i:s'),
            ];
            if (empty($row['version']) && $version !== '') {
               $updateData['version'] = $version;
            }
            $DB->update(
               $table,
               $updateData,
               ['module_key' => $moduleKey]
            );
        } else {
            // Cria registro básico local para o módulo do catálogo (ainda não instalado)
            $DB->insert(
               $table,
               [
                  'module_key'             => $moduleKey,
                  'name'                   => $name !== '' ? $name : $moduleKey,
                  'description'            => $description !== '' ? $description : null,
                  'version'                => null,
                  'available_version'      => $version !== '' ? $version : null,
                  'min_version_nextools'   => $minVersionNextools,
                  'compat_glpi_majors'     => $compatMajors,
                  'website_url'            => $websiteUrl,
                  'icon'                   => $icon ?? 'ti ti-puzzle',
                  'price_cents'            => $priceCents,
                  'category'               => $category,
                  'features_json'          => $featuresJson,
                  'screenshot_url'         => $screenshotUrl,
                  'download_count'         => $downloadCount,
                  'is_installed'           => 0,
                  'billing_tier'           => $billingTier,
                  'is_enabled'             => 0,
                  'is_available'           => $isAvailable,
                  'config'                 => json_encode([]),
                  'date_creation'          => date('Y-m-d H:i:s'),
               ]
            );
         }
      }

      // Desabilita módulos DEV locais que não vieram no catálogo (ex.: plano mudou de DESENVOLVIMENTO para outro)
      if (!$envHasDevLicense) {
         $iterator = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['billing_tier' => 'DEV'],
         ]);
         foreach ($iterator as $row) {
            $mk = $row['module_key'] ?? '';
            if ($mk === '') {
               continue;
            }
            if (count($moduleKeysInCatalog) === 0 || !in_array($mk, $moduleKeysInCatalog, true)) {
               $DB->update(
                  $table,
                  ['is_available' => 0, 'is_enabled' => 0, 'date_mod' => date('Y-m-d H:i:s')],
                  ['module_key' => $mk]
               );
            }
         }
      }

      // Ocultar modulos que nao vieram no catalogo e nao estao instalados
      if (!empty($moduleKeysInCatalog)) {
         $allLocal = $DB->request([
            'FROM'   => $table,
            'SELECT' => ['module_key', 'is_installed', 'is_available'],
         ]);
         foreach ($allLocal as $row) {
            $mk = $row['module_key'] ?? '';
            if ($mk === '' || in_array($mk, $moduleKeysInCatalog, true)) {
               continue;
            }
            if ((int)($row['is_installed'] ?? 0) === 0 && (int)($row['is_available'] ?? 0) !== 0) {
               $DB->update(
                  $table,
                  ['is_available' => 0, 'date_mod' => date('Y-m-d H:i:s')],
                  ['module_key' => $mk]
               );
            }
         }
      }

      // HI-07: catálogo pode ter introduzido ou removido módulos -- ressincroniza
      // os profilerights uma vez aqui (em vez de re-sync universal a cada init).
      // Reseta a flag-file da versão atual: como o sync já rodou agora, o próximo
      // init vê a flag fresca e faz skip.
      PluginNextoolPermissionManager::syncModuleRights();
      if (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR) && defined('PLUGIN_NEXTOOL_VERSION')) {
         $currentFlag = GLPI_CACHE_DIR . '/nextool_rights_synced_v' . PLUGIN_NEXTOOL_VERSION;
         foreach (glob(GLPI_CACHE_DIR . '/nextool_rights_synced_v*') ?: [] as $oldFlag) {
            if ($oldFlag !== $currentFlag) {
               @unlink($oldFlag);
            }
         }
         @touch($currentFlag);
      }
   }

   /**
    * Chama o endpoint do ContainerAPI usando assinatura HMAC
    *
    * @param string $apiEndpoint
    * @param string $clientIdentifier
    * @param string $clientSecret
    * @param array  $payload
    * @param int|null $httpCode
    * @param int|null $responseTimeMs
    *
    * @return array|null
    */
   protected static function callDistributionLicenseAPI($apiEndpoint, $clientIdentifier, $clientSecret, array $payload, &$httpCode, &$responseTimeMs) {
      $httpCode = null;
      $responseTimeMs = null;

      if ($apiEndpoint === '' || $clientIdentifier === '' || $clientSecret === '') {
         return null;
      }

      $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($body === false) {
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: falha ao gerar payload JSON para ContainerAPI.');
         return null;
      }

      $headers = array_merge(
         ['Content-Type: application/json'],
         self::buildHmacHeadersV2($clientIdentifier, '/api/licensing/validate', $body, $clientSecret)
      );
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      $headers[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];
      // F2 -- anuncia a capability: o servidor passa a (a) emitir o token de entitlement e (b) zerar
      // a metadata na negação (este plugin trata metadata vazia sem quebrar -- TEST-03).
      $headers[] = 'X-Nextool-Caps: ' . self::NEXTOOL_CAPABILITIES;

      if (function_exists('curl_init')) {
         $ch = curl_init($apiEndpoint);
         if ($ch === false) {
            Toolbox::logInFile('plugin_nextool', 'LicenseValidator: curl_init() falhou ao chamar ContainerAPI.');
            return null;
         }

         $start = microtime(true);
         curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
         ]);

         $response = curl_exec($ch);
         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $error    = curl_error($ch);
         curl_close($ch);
         $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

         if ($response === false) {
            Toolbox::logInFile('plugin_nextool', 'LicenseValidator: falha cURL ao chamar ContainerAPI - ' . $error);
            return null;
         }

         $decoded = json_decode($response, true);
         if (!is_array($decoded)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr($response, 0, 500)));
            $contentHint = '';
            if ($snippet === '') {
               $contentHint = 'body vazio';
            } elseif (preg_match('/<(html|br|b|div|!DOCTYPE)/i', $snippet)) {
               $contentHint = 'HTML detectado (provável erro PHP ou página de proxy/WAF)';
            } else {
               $contentHint = 'conteúdo não-JSON (json_last_error: ' . json_last_error_msg() . ')';
            }
            Toolbox::logInFile('plugin_nextool', sprintf(
               'LicenseValidator: %s (HTTP %d). Body: %s',
               $contentHint,
               $httpCode ?: 0,
               $snippet
            ));
            return null;
         }

         return $decoded;
      }

      $contextOpts = [
         'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 15,
         ],
      ];

      if (stripos($apiEndpoint, 'https://') === 0) {
         $contextOpts['ssl'] = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
         ];
      }

      $context = stream_context_create($contextOpts);
      $start   = microtime(true);
      $response = @file_get_contents($apiEndpoint, false, $context);
      $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

      if ($response === false) {
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: stream falhou ao chamar ContainerAPI.');
         return null;
      }

      if (isset($http_response_header) && is_array($http_response_header)) {
         foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
               $httpCode = (int) $matches[1];
               break;
            }
         }
      }

      $decoded = json_decode($response, true);
      if (!is_array($decoded)) {
         $snippet = trim(preg_replace('/\s+/', ' ', substr($response, 0, 500)));
         $jsonError = json_last_error_msg();
         Toolbox::logInFile('plugin_nextool', sprintf(
            'LicenseValidator: resposta JSON inválida (stream) do ContainerAPI (HTTP %d). JSON Error: %s. Response (primeiros 500 chars): %s',
            $httpCode ?: 'unknown',
            $jsonError,
            $snippet
         ));
         return null;
      }

      return $decoded;
   }

   /**
    * Faz chamada HTTP ao endpoint de validação
    *
    * @param string      $apiEndpoint
    * @param string|null $apiSecret
    * @param array       $payload
    * @param int|null    $httpCode
    * @param int|null    $responseTimeMs
    *
    * @return array|null
    */
   protected static function callValidationAPI($apiEndpoint, $apiSecret, array $payload, &$httpCode, &$responseTimeMs) {
      global $DB;

      $httpCode       = null;
      $responseTimeMs = null;

      if (empty($apiEndpoint)) {
         return null;
      }

      // Tenta usar cURL se disponível
      if (function_exists('curl_init')) {
         $ch = curl_init();
         if ($ch === false) {
            Toolbox::logInFile('plugin_nextool', 'LicenseValidator: curl_init() falhou ao preparar chamada para ' . $apiEndpoint);
            return null;
         }

         $headers = [
            'Content-Type: application/json',
         ];

         if (!empty($apiSecret)) {
            $headers[] = 'X-License-Secret: ' . $apiSecret;
         }

         $body  = json_encode($payload);
         $start = microtime(true);

         curl_setopt_array($ch, [
            CURLOPT_URL            => $apiEndpoint,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
         ]);

         $response = curl_exec($ch);
         $end      = microtime(true);

         $responseTimeMs = (int)round(($end - $start) * 1000);

         if ($response === false) {
            $error = curl_error($ch);
            Toolbox::logInFile(
               'plugin_nextool',
               'LicenseValidator: erro cURL ao chamar ' . $apiEndpoint . ' - ' . $error
            );
            $httpCode = null;
            curl_close($ch);
            return null;
         }

         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

         if ($httpCode < 200 || $httpCode >= 300) {
            // Para evitar poluir os logs com HTML completo de páginas de erro do GLPI,
            // registramos apenas um resumo do body (primeiros caracteres em uma linha).
            $snippet = trim(preg_replace('/\s+/', ' ', substr($response, 0, 200)));
            Toolbox::logInFile(
               'plugin_nextool',
               sprintf(
                  'LicenseValidator: resposta HTTP %d de %s. Body (primeiros 200 chars): %s',
                  $httpCode,
                  $apiEndpoint,
                  $snippet
               )
            );
            curl_close($ch);
            return null;
         }

         curl_close($ch);

         $data = json_decode($response, true);
         if (!is_array($data)) {
            Toolbox::logInFile(
               'plugin_nextool',
               'LicenseValidator: resposta JSON inválida de ' . $apiEndpoint . ' - Body: ' . substr($response, 0, 1000)
            );
            return null;
         }

         return $data;
      }

      // Fallback sem cURL: tenta usar file_get_contents com stream_context
      $headers = [
         'Content-Type: application/json',
      ];
      if (!empty($apiSecret)) {
         $headers[] = 'X-License-Secret: ' . $apiSecret;
      }

      $body = json_encode($payload);
      $context = stream_context_create(array_merge([
         'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
         ],
      ], stripos($apiEndpoint, 'https://') === 0 ? [
         'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
         ],
      ] : []));

      $start    = microtime(true);
      $response = @file_get_contents($apiEndpoint, false, $context);
      $end      = microtime(true);

      $responseTimeMs = (int)round(($end - $start) * 1000);

      if ($response === false) {
         Toolbox::logInFile(
            'plugin_nextool',
            'LicenseValidator: file_get_contents() falhou ao chamar ' . $apiEndpoint
         );
         $httpCode = null;
         return null;
      }

      // Extrai HTTP code dos headers, se disponíveis
      if (isset($http_response_header) && is_array($http_response_header)) {
         foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
               $httpCode = (int)$matches[1];
               break;
            }
         }
      }

      if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 300)) {
         $snippet = trim(preg_replace('/\s+/', ' ', substr($response, 0, 200)));
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf(
               'LicenseValidator: resposta HTTP %d (stream) de %s. Body (primeiros 200 chars): %s',
               $httpCode,
               $apiEndpoint,
               $snippet
            )
         );
         return null;
      }

      $data = json_decode($response, true);
      if (!is_array($data)) {
         $snippet = trim(preg_replace('/\s+/', ' ', substr($response, 0, 200)));
         Toolbox::logInFile(
            'plugin_nextool',
            'LicenseValidator: resposta JSON inválida (stream) de ' . $apiEndpoint . ' - Body (primeiros 200 chars): ' . $snippet
         );
         return null;
      }

      return $data;
   }

   /**
    * Define se a tentativa de validação deve ser registrada em log,
    * considerando o contexto de chamada.
    *
    * - Snapshots de status da tela de configuração (origin = config_status)
    *   não geram linhas adicionais no histórico, evitando duplicidade
    *   quando o usuário apenas recarrega a tela.
    *
    * @param array $context
    * @return bool
    */
   protected static function shouldLogAttempt(array $context) {
      $origin = isset($context['origin']) ? (string)$context['origin'] : '';

      if ($origin === 'config_status') {
         return false;
      }

      return true;
   }

   /**
    * Atualiza cache da licença na tabela glpi_plugin_nextool_main_license_config
    *
    * @param array  $currentConfig
    * @param string      $licenseKey
    * @param string|null $plan
    * @param string      $apiEndpoint
    * @param string|null $apiSecret
    * @param bool        $valid
    * @param string      $message
    * @param array       $allowedModules
    *
    * @return void
    */
   protected static function updateLicenseCache(array $currentConfig, $licenseKey, $plan, $apiEndpoint, $apiSecret, $valid, $message, array $allowedModules, array $state = []) {
      global $DB;

      // Se tabela ainda não existir (ambiente não migrado), não tenta gravar cache
      if (!$DB->tableExists(PluginNextoolLicenseConfig::getTable())) {
         return;
      }

      $configObj = new PluginNextoolLicenseConfig();

      $input = [
         'license_key'            => $licenseKey,
         'plan'                   => $plan,
         'api_endpoint'           => $apiEndpoint,
         'api_secret'             => $apiSecret,
         'last_validation_date'   => date('Y-m-d H:i:s'),
         'last_validation_result' => $valid ? 1 : 0,
         'last_validation_message'=> $message,
         'cached_modules'         => json_encode(array_values($allowedModules)),
      ];

      if (array_key_exists('license_status', $state) && $state['license_status'] !== null) {
         $input['license_status'] = strtoupper((string)$state['license_status']);
      }

      if (array_key_exists('expires_at', $state)) {
         $input['expires_at'] = $state['expires_at'] ?: null;
      }

      if (array_key_exists('warnings', $state)) {
         if (!empty($state['warnings']) && is_array($state['warnings'])) {
            $input['warnings'] = json_encode(array_values($state['warnings']));
         } else {
            $input['warnings'] = null;
         }
      }

      if (array_key_exists('licenses', $state)) {
         if (!empty($state['licenses']) && is_array($state['licenses'])) {
            $input['licenses_snapshot'] = json_encode(array_values($state['licenses']));
         } else {
            $input['licenses_snapshot'] = null;
         }
      }

      $currentFailures = isset($currentConfig['consecutive_failures'])
         ? (int)$currentConfig['consecutive_failures']
         : 0;

      if ($valid) {
         $input['consecutive_failures'] = 0;
         $input['last_failure_date']    = null;
      } else {
         $input['consecutive_failures'] = $currentFailures + 1;
         $input['last_failure_date']    = date('Y-m-d H:i:s');
      }

      if (!empty($currentConfig['id'])) {
         $input['id'] = (int)$currentConfig['id'];
         $configObj->update($input);
      } else {
         $configObj->add($input);
      }
   }


   /**
    * Registra alertas críticos sobre a licença em arquivo de log
    *
    * @param array  $state
    * @param string $origin
    */
   protected static function logLicenseAlert(array $state, $origin) {
      $status        = $state['license_status'] ?? null;
      $plan          = $state['plan'] ?? null;
      $warnings      = isset($state['warnings']) && is_array($state['warnings']) ? $state['warnings'] : [];

      if (!empty($warnings) && in_array('license_expired', $warnings, true)) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('Aviso: licença expirada, contrato ativo (origin=%s, status=%s)', $origin, $status ?? 'UNKNOWN')
         );
      }
   }

   /**
    * F2 -- verifica (anti-tamper) o token de entitlement recebido e o persiste p/ uso offline. Se o
    * token vier mas NÃO conferir (assinatura/exp/environment alheio), registra e ignora -- nunca
    * confia em token não verificado. Sem token (servidor antigo), no-op.
    */
   protected static function persistVerifiedEntitlement(?string $clientId, array $responseData): void {
      $token = isset($responseData['license_token']) && is_string($responseData['license_token'])
         ? trim($responseData['license_token']) : '';
      if ($token === '') {
         return;
      }
      $claims = PluginNextoolEntitlementToken::verify($token, (string) ($clientId ?? ''));
      if ($claims === null) {
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: license_token recebido nao verificou (assinatura/exp/environment) -- ignorado.');
         return;
      }
      self::persistConfig(PluginNextoolEntitlementToken::ENTITLEMENT_CONTEXT, [
         'entitlement_token'           => $token,
         'entitlement_token_exp'       => (int) ($claims['exp'] ?? 0),
         'entitlement_token_synced_at' => date('Y-m-d H:i:s'),
      ], 'entitlement_token');
   }

   /**
    * F2 -- graça offline: quando o ContainerAPI está indisponível, usa o token de entitlement
    * assinado (se ainda válido: assinatura + exp + environment_id DESTE ambiente) como prova do
    * direito, em vez de degradar para FREE. Retorna true se aplicou a graça.
    */
   protected static function applyOfflineEntitlement(
      ?string $clientId,
      bool &$valid,
      ?string &$plan,
      array &$allowedModules,
      ?string &$licenseStatus,
      array &$warnings,
      string &$message
   ): bool {
      if (!class_exists('Config')) {
         return false;
      }
      $values = Config::getConfigurationValues(PluginNextoolEntitlementToken::ENTITLEMENT_CONTEXT);
      $token  = isset($values['entitlement_token']) ? (string) $values['entitlement_token'] : '';
      if ($token === '') {
         return false;
      }
      $claims = PluginNextoolEntitlementToken::verify($token, (string) ($clientId ?? ''));
      if ($claims === null) {
         return false; // expirado/invalidado -> sem graça, segue FREE
      }
      $valid          = true;
      $plan           = self::normalizePlan((string) ($claims['plan'] ?? 'FREE')) ?? 'FREE';
      $mods           = isset($claims['allowed_modules']) && is_array($claims['allowed_modules']) ? $claims['allowed_modules'] : [];
      $allowedModules = array_values($mods);
      $licenseStatus  = 'ACTIVE';
      $warnings[]     = __('Servidor de licenças indisponível: operando com o direito assinado em cache (offline).', 'nextool');
      $message        = __('Direito validado offline pelo token assinado (servidor indisponível).', 'nextool');
      Toolbox::logInFile('plugin_nextool', 'LicenseValidator: graca offline aplicada via token de entitlement assinado.');
      return true;
   }

   /**
    * F3-B -- honra o sinal `re_enroll_required` (fork manual): re-enrolla (o servidor cunha uma NOVA
    * identidade única), descarta a antiga e adota a nova. Se o enroll falhar, NÃO mexe (retenta no
    * próximo ciclo). O ambiente novo nasce sem licença -- o dono reativa o binding só no legítimo.
    */
   protected static function handleReEnrollSignal(string $baseUrl, ?string $oldIdentifier): void {
      $baseUrl = trim($baseUrl);
      if ($baseUrl === '') {
         return;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';

      $oldIdentifier = trim((string) $oldIdentifier);
      $dist = PluginNextoolConfig::getDistributionSettings();
      $currentSecret = isset($dist['client_secret']) ? trim((string) $dist['client_secret']) : '';

      // F5 -- migração COORDENADA pelo servidor: assina com o segredo ATUAL (prova de posse) e deixa
      // o servidor escolher o modo (rename in-place preservando histórico p/ único; fresh p/ clone
      // FREE; defer p/ PAID ambíguo). O plugin só ADOTA o {id, secret} retornado.
      if ($oldIdentifier !== '' && $currentSecret !== '') {
         $migrate = PluginNextoolDistributionClient::migrateEnvironment($baseUrl, $oldIdentifier, $currentSecret);
         if (is_string($migrate['environment_id']) && $migrate['environment_id'] !== ''
             && is_string($migrate['client_secret']) && $migrate['client_secret'] !== '') {
            self::adoptNewIdentity($oldIdentifier, $migrate['environment_id'], $migrate['client_secret']);
            Toolbox::logInFile('plugin_nextool', sprintf(
               'LicenseValidator: re_enroll_required honrado via migrate (modo=%s) -- identidade %s.',
               $migrate['mode'] ?? '?', $migrate['environment_id']
            ));
            return;
         }
         // defer/noop/not_eligible: o servidor respondeu, mas NÃO entregou nova identidade -> mantém
         // a atual (aguarda o admin fazer o fork manual). NÃO cair no enroll fresco (perderia o vínculo).
         if (($migrate['mode'] ?? null) !== null) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               'LicenseValidator: migrate modo=%s sem nova identidade -- mantém a atual (aguarda admin).',
               $migrate['mode']
            ));
            return;
         }
         // erro de rede/http -> tenta o enroll fresco como rede de segurança (comportamento F3-B).
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: migrate indisponível -- fallback para enroll fresco.');
      }

      // Fallback: sem segredo atual para assinar OU migrate indisponível -> enroll fresco.
      $enroll = PluginNextoolDistributionClient::enrollEnvironment($baseUrl);
      if ($enroll['environment_id'] === null || $enroll['client_secret'] === null) {
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: re_enroll_required recebido, mas o enroll falhou -- retenta no proximo ciclo.');
         return;
      }
      self::adoptNewIdentity($oldIdentifier, $enroll['environment_id'], $enroll['client_secret']);
      Toolbox::logInFile('plugin_nextool', sprintf('LicenseValidator: re_enroll_required honrado (enroll fresco) -- nova identidade %s cunhada.', $enroll['environment_id']));
   }

   /**
    * F5 -- adota a nova identidade server-issued: descarta a antiga (provisioning + secret local) e
    * persiste o novo par identifier+secret nas configs de distribuição.
    */
   private static function adoptNewIdentity(string $oldIdentifier, string $newId, string $newSecret): void {
      PluginNextoolConfig::clearProvisioning();
      PluginNextoolConfig::setClientIdentifier($newId);
      PluginNextoolConfig::setProvisioning($newId, $newSecret);
      $dist = PluginNextoolConfig::getDistributionSettings();
      $dist['client_identifier'] = $newId;
      $dist['client_secret']     = $newSecret;
      Config::setConfigurationValues('plugin:nextool_distribution', $dist);
   }

   protected static function enforceFreeModeFallback(string $reason): void {
      try {
         $manager = PluginNextoolModuleManager::getInstance();
         $manager->enforceFreeTierForPaidModules();
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'LicenseValidator: falha ao aplicar modo FREE - ' . $e->getMessage());
      }

      Toolbox::logInFile(
         'plugin_nextool',
         sprintf('LicenseValidator: %s. Ambiente operará em modo FREE.', $reason)
      );
   }

   /**
    * Persiste payment_methods disponíveis na config GLPI.
    */
   private static function persistPaymentMethods(array $methods): void {
      self::persistConfig('plugin:nextool_billing', [
         'payment_methods' => json_encode(array_values($methods)),
      ], 'payment_methods');
   }

   /**
    * Recupera payment_methods disponíveis da config GLPI.
    *
    * @return string[] Ex: ['card', 'boleto']
    */
   public static function getPaymentMethods(): array {
      $raw = Config::getConfigurationValue('plugin:nextool_billing', 'payment_methods') ?? '';
      if ($raw === '') {
         return ['card'];
      }
      $decoded = json_decode($raw, true);
      $methods = is_array($decoded) && !empty($decoded) ? $decoded : ['card'];
      // Pix temporariamente desabilitado -- conta Stripe ainda nao tem elegibilidade
      $methods = array_values(array_filter($methods, fn($m) => strtolower($m) !== 'pix'));
      return !empty($methods) ? $methods : ['card'];
   }

   /**
    * Persiste modules_entitlement na config GLPI para uso no config.form.php.
    */
   private static function persistModulesEntitlement(array $entitlement): void {
      self::persistConfig('plugin:nextool_entitlement', [
         'modules_entitlement' => json_encode($entitlement, JSON_UNESCAPED_SLASHES),
      ], 'modules_entitlement');
   }

   /**
    * Recupera modules_entitlement da config GLPI.
    *
    * @return array<string, array{status: string, ever_licensed: bool}>
    */
   public static function getModulesEntitlement(): array {
      $raw = Config::getConfigurationValue('plugin:nextool_entitlement', 'modules_entitlement') ?? '';
      if ($raw === '') {
         return [];
      }
      $decoded = json_decode($raw, true);
      return is_array($decoded) ? $decoded : [];
   }

   /**
    * Aplica proteção anti-pirataria baseada em modules_entitlement.
    *
    * Regras (aplicadas SOMENTE quando comunicação 100% OK):
    * - ever_licensed=true + expired: não faz nada (módulo pode ser instalado/ativado localmente)
    * - ever_licensed=false + never: desativa, desinstala e remove arquivos do módulo PAID
    * - Módulos FREE: NUNCA afetados
    *
    * @param array $entitlement Mapa module_key => {status, ever_licensed}
    */
   protected static function applyModulesEntitlement(array $entitlement): void {
      try {
         $manager = PluginNextoolModuleManager::getInstance();
      } catch (Throwable $e) {
         return;
      }

      foreach ($entitlement as $moduleKey => $info) {
         $status = $info['status'] ?? '';
         $everLicensed = !empty($info['ever_licensed']);

         // Nunca tocar em módulos com ever_licensed=true ou status != 'never'
         if ($everLicensed || $status !== 'never') {
            continue;
         }

         // Verificar se é realmente PAID (defesa em profundidade)
         $billingTier = $manager->getBillingTier($moduleKey);
         if (strtoupper($billingTier) === 'FREE' || strtoupper($billingTier) === 'DEV') {
            continue;
         }

         // Verificar se módulo existe localmente
         $modulePath = $manager->getModulePath($moduleKey);
         if ($modulePath === null || !is_dir($modulePath)) {
            continue; // Não está baixado, nada a fazer
         }

         Toolbox::logInFile('plugin_nextool', sprintf(
            '[ENTITLEMENT] Módulo PAID "%s" sem histórico de licença (ever_licensed=false). Desativando e removendo arquivos.',
            $moduleKey
         ));

         // Desativar se ativo
         if ($manager->isEnabled($moduleKey)) {
            $manager->disableModule($moduleKey);
         }

         // Desinstalar se instalado
         if ($manager->isInstalled($moduleKey)) {
            $manager->uninstallModule($moduleKey);
         }

         // Remover arquivos
         self::removeModuleFiles($modulePath);
      }
   }

   /**
    * Remove recursivamente o diretório de um módulo.
    */
   private static function removeModuleFiles(string $path): void {
      if (!is_dir($path)) {
         return;
      }

      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      PluginNextoolFileHelper::deleteDirectory($path, false);

      @rmdir($path);

      Toolbox::logInFile('plugin_nextool', sprintf(
         '[ENTITLEMENT] Arquivos removidos: %s', $path
      ));
   }

   /**
    * Persiste hint de atualização do core recebida do ContainerAPI.
    * Apenas atualiza update_available e latest_available_version;
    * NÃO sobrescreve staged_target_version/staged_source/staged_at (controlados pelo CoreUpdater).
    */
   private static function persistCoreUpdateHint(array $hint): void {
      $available = !empty($hint['available']);
      self::persistConfig('plugin:nextool_core_update', [
         'update_available'         => $available ? '1' : '0',
         'latest_available_version' => $available ? (string)($hint['latest_version'] ?? '') : '',
      ], 'core_update hint');
   }

   private static function persistAlerts(array $alerts): void {
      global $DB;
      $table = 'glpi_plugin_nextool_main_alerts';
      if (!$DB->tableExists($table)) {
         $DB->doQuery("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `remote_alert_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `body` TEXT NOT NULL,
            `alert_type` VARCHAR(20) NOT NULL DEFAULT 'info',
            `is_read` TINYINT NOT NULL DEFAULT 0,
            `date_received` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_read` TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY `uq_remote_alert` (`remote_alert_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      }
      foreach ($alerts as $alert) {
         $remoteId = (int)($alert['id'] ?? 0);
         if ($remoteId <= 0) { continue; }
         $existing = $DB->request(['FROM' => $table, 'WHERE' => ['remote_alert_id' => $remoteId], 'LIMIT' => 1]);
         if (count($existing) > 0) { continue; }
         try {
            $DB->insert($table, [
               'remote_alert_id' => $remoteId,
               'title'           => trim((string)($alert['title'] ?? '')),
               'body'            => trim((string)($alert['body'] ?? '')),
               'alert_type'      => trim((string)($alert['alert_type'] ?? 'info')),
            ]);
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', 'LicenseValidator: falha ao persistir alerta #' . $remoteId . ' - ' . $e->getMessage());
         }
      }
   }
}