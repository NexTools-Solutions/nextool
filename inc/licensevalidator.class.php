<?php
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

require_once GLPI_ROOT . '/plugins/nextool/inc/logmaintenance.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/validationattempt.class.php';

class PluginNextoolLicenseValidator {

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
      $plan       = $licenseConfig['plan'] ?? null;
      if ($plan !== null && strtoupper((string)$plan) === 'STARTER') {
         $plan = 'DESENVOLVIMENTO';
      }
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
            'contract_active'  => $licenseConfig['contract_active'] ?? null,
            'license_status'   => $hasLicense
               ? ($licenseConfig['license_status'] ?? null)
               : 'FREE_TIER',
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
            'contract_active'     => $state['contract_active'],
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
            'contract_active'     => isset($licenseConfig['contract_active'])
               ? (bool)$licenseConfig['contract_active']
               : null,
            'license_status'      => $licenseConfig['license_status'] ?? null,
            'expires_at'          => $licenseConfig['expires_at'] ?? null,
            'warnings'            => $cachedWarnings,
            'plan'                => $plan,
            'licenses'            => $cachedLicenses,
         ];
      }

      // Monta payload para API
      $domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');

      $clientInfo = [
         'plugin_version' => self::getPluginVersion(),
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
            'FROM' => 'glpi_plugin_nextool_main_modules',
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
      $contractActive  = null;
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
            'contract_active'  => null,
            'license_status'   => null,
            'allowed_modules'  => json_encode([]),
         ]);
         $plan = 'FREE';
         $licenseStatus = 'FREE_TIER';
         $contractActive = false;

         if ($useDistributionValidation && $httpCode === 401) {
            $warnings[] = __('Assinatura HMAC rejeitada pelo ContainerAPI. Recrie o segredo HMAC na aba de licença e valide novamente.', 'nextool');
            Toolbox::logInFile(
               'plugin_nextool',
               sprintf('LicenseValidator: ContainerAPI retornou 401 (assinatura inválida) para %s.', $clientId ?: '(sem identificador)')
            );
         }

         self::enforceFreeModeFallback('Falha ao comunicar com o ContainerAPI');
      } else {
         // Campos adicionais da nova fase 3 (podem ou não estar presentes conforme versão do administrativo)
         if (array_key_exists('contract_active', $responseData)) {
            $contractActive = (bool)$responseData['contract_active'];
         }
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
               $planForMessage = strtoupper(trim($responseData['plan']));
               if ($planForMessage === 'STARTER') {
                  $planForMessage = 'DESENVOLVIMENTO';
               }
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
               $plan = strtoupper(trim($responseData['plan']));
               if ($plan === 'STARTER') {
                  $plan = 'DESENVOLVIMENTO';
               }
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
               $planForSync = isset($responseData['plan']) ? strtoupper(trim((string)$responseData['plan'])) : '';
               if ($planForSync === 'STARTER') {
                  $planForSync = 'DESENVOLVIMENTO';
               }
               self::applyModulesCatalogSync($responseData['modules_catalog'], $planForSync);
            }
         }
      }

      if (!$valid) {
         $allowedModules = [];
         if (empty($plan)) {
            $plan = 'FREE';
         } else {
            $plan = strtoupper($plan);
            if ($plan === 'STARTER') {
               $plan = 'DESENVOLVIMENTO';
            }
         }
         if ($licenseStatus === null) {
            $licenseStatus = 'FREE_TIER';
         }
         if ($contractActive === null) {
            $contractActive = false;
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
            'contract_active'  => $contractActive,
            'plan'             => $plan,
            'allowed_modules'  => json_encode($allowedModules),
         ]);
      }

      self::logLicenseAlert([
         'contract_active' => $contractActive,
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
            'contract_active' => $contractActive,
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
         'contract_active'     => $contractActive,
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
    * @param string $plan Plano do ambiente (DESENVOLVIMENTO, ENTERPRISE, PRO, FREE, etc.)
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

      $timestamp = (string) time();
      $signature = hash_hmac('sha256', $body . '|' . $timestamp, $clientSecret);
      $headers = [
         'Content-Type: application/json',
         'X-Client-Identifier: ' . $clientIdentifier,
         'X-Timestamp: ' . $timestamp,
         'X-Signature: ' . $signature,
      ];

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
            $jsonError = json_last_error_msg();
            Toolbox::logInFile('plugin_nextool', sprintf(
               'LicenseValidator: resposta JSON inválida do ContainerAPI (HTTP %d). JSON Error: %s. Response (primeiros 500 chars): %s',
               $httpCode ?: 'unknown',
               $jsonError,
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

      if (array_key_exists('contract_active', $state)) {
         $value = $state['contract_active'];
         $input['contract_active'] = $value === null ? null : ((int)!empty($value));
      }

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
    * Obtém versão atual do plugin Nextool
    *
    * @return string|null
    */
   protected static function getPluginVersion() {
      if (function_exists('plugin_version_nextool')) {
         $info = plugin_version_nextool();
         if (is_array($info) && !empty($info['version'])) {
            return $info['version'];
         }
      }
      return null;
   }

   /**
    * Registra alertas críticos sobre a licença em arquivo de log
    *
    * @param array  $state
    * @param string $origin
    */
   protected static function logLicenseAlert(array $state, $origin) {
      $status        = $state['license_status'] ?? null;
      $contractActive= array_key_exists('contract_active', $state) ? $state['contract_active'] : null;
      $plan          = $state['plan'] ?? null;
      $warnings      = isset($state['warnings']) && is_array($state['warnings']) ? $state['warnings'] : [];

      if ($contractActive === false) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('ALERTA: contrato da licença está inativo (origin=%s, plan=%s)', $origin, $plan ?? 'UNKNOWN')
         );
      } elseif (!empty($warnings) && in_array('license_expired', $warnings, true)) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('Aviso: licença expirada, contrato ativo (origin=%s, status=%s)', $origin, $status ?? 'UNKNOWN')
         );
      }
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
}