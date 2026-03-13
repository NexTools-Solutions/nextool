<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Core Updater
 * -------------------------------------------------------------------------
 * Engine de atualização do plugin base nextool (check/preflight/prepare/apply)
 * com staging em diretório de dados do plugin.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/coreupdateclient.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/coreupdatelog.class.php';

class PluginNextoolCoreUpdater {

   private const STATE_NAMESPACE = 'plugin:nextool_core_update';
   private const DEFAULT_CHANNEL = 'stable';
   private const STAGING_STALE_WARNING_HOURS = 24;
   private const HIGH_LATENCY_WARNING_MS = 1500;
   private const MAX_BACKUP_RETENTION = 3;

   private PluginNextoolCoreUpdateClient $client;

   public function __construct(?PluginNextoolCoreUpdateClient $client = null) {
      $this->client = $client ?? PluginNextoolCoreUpdateClient::fromDistributionSettings();
   }

   public function check(string $channel = self::DEFAULT_CHANNEL, string $source = 'manual'): array {
      $started = microtime(true);
      $channel = $this->sanitizeChannel($channel);
      $state = self::getState();
      $currentVersion = $this->getInstalledCoreVersion();

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] check() iniciado: channel=%s source=%s current=%s\n",
         $channel,
         $source,
         $currentVersion
      ));

      try {
         $manifest = $this->client->requestManifest($channel, 'core_update_check_' . $source);
      } catch (RuntimeException $e) {
         $code = (int)$e->getCode();
         if ($code === 404) {
            $state = $this->persistState([
               'update_available' => 0,
            ]);

            $this->logAction('check', true, [
               'source' => $source,
               'current_version' => $currentVersion,
               'target_version' => null,
               'message' => 'Nenhuma release compatível encontrada.',
               'duration_ms' => (int)round((microtime(true) - $started) * 1000),
               'details' => ['channel' => $channel],
            ]);

            return [
               'success' => true,
               'message' => __('Nenhuma atualização de core disponível para este ambiente.', 'nextool'),
               'data' => [
                  'channel' => $channel,
                  'current_version' => $currentVersion,
                  'target_version' => null,
                  'update_available' => false,
                  'state' => $state,
               ],
            ];
         }

         $message = sprintf(__('Falha ao consultar atualização de core: %s', 'nextool'), $e->getMessage());

         $this->logAction('check', false, [
            'source' => $source,
            'current_version' => $currentVersion,
            'target_version' => null,
            'message' => $message,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'details' => ['channel' => $channel, 'error' => $e->getMessage(), 'http_code' => $code],
         ]);

         return [
            'success' => false,
            'message' => $message,
            'data' => [
               'channel' => $channel,
               'current_version' => $currentVersion,
               'state' => $state,
            ],
         ];
      }

      $targetVersion = (string)($manifest['version'] ?? '');
      $updateAvailable = $targetVersion !== '' && version_compare($targetVersion, $currentVersion, '>');

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] check() concluído: current=%s target=%s update_available=%s duration_ms=%d\n",
         $currentVersion,
         $targetVersion,
         $updateAvailable ? 'true' : 'false',
         (int)round((microtime(true) - $started) * 1000)
      ));

      $state = $this->persistState([
         'update_available' => $updateAvailable ? 1 : 0,
      ]);

      $this->logAction('check', true, [
         'source' => $source,
         'current_version' => $currentVersion,
         'target_version' => $targetVersion,
         'message' => $updateAvailable ? 'Atualização disponível.' : 'Core já está atualizado.',
         'duration_ms' => (int)round((microtime(true) - $started) * 1000),
         'details' => [
            'channel' => $channel,
            'update_available' => $updateAvailable ? 1 : 0,
         ],
      ]);

      return [
         'success' => true,
         'message' => $updateAvailable
            ? __('Atualização de core disponível.', 'nextool')
            : __('Core já está na versão mais recente.', 'nextool'),
         'data' => [
            'channel' => $channel,
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'update_available' => $updateAvailable,
            'manifest' => $this->sanitizeManifestForOutput($manifest),
            'state' => $state,
         ],
      ];
   }

   public function preflight(?array $manifest = null, string $action = 'preflight', array $options = []): array {
      $started = microtime(true);
      $checks = [];
      $blocking = [];
      $warnings = [];
      $state = self::getState();
      $action = trim($action) !== '' ? trim($action) : 'preflight';
      $skipConnectivity = !empty($options['skip_connectivity']);
      $skipLockCheck = !empty($options['skip_lock_check']);

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] preflight() iniciado: action=%s skip_connectivity=%s skip_lock_check=%s manifest_version=%s\n",
         $action,
         $skipConnectivity ? 'true' : 'false',
         $skipLockCheck ? 'true' : 'false',
         is_array($manifest) && isset($manifest['version']) ? $manifest['version'] : 'null'
      ));

      $pluginDir = $this->getPluginInstallPath();
      $pluginDirExists = is_dir($pluginDir);
      $this->appendCheck($checks, $blocking, 'plugin_dir_exists', 'blocker', $pluginDirExists,
         $pluginDirExists
            ? __('Diretório plugins/nextool encontrado.', 'nextool')
            : sprintf(__('Diretório plugins/nextool não encontrado: %s', 'nextool'), $pluginDir)
      );

      $webUser = $this->getWebProcessUser();

      $pluginDirWritable = $pluginDirExists && is_writable($pluginDir);
      $this->appendCheck($checks, $blocking, 'plugin_dir_writable', 'blocker', $pluginDirWritable,
         $pluginDirWritable
            ? __('Diretório plugins/nextool gravável.', 'nextool')
            : __('Diretório plugins/nextool sem permissão de escrita.', 'nextool'),
         $pluginDirWritable ? [] : ['path' => $pluginDir, 'web_user' => $webUser]
      );

      $backupDir = $this->getRuntimeRoot();
      $backupDirOk = is_dir($backupDir) && is_writable($backupDir);
      $this->appendCheck($checks, $blocking, 'backup_dir_writable', 'blocker', $backupDirOk,
         $backupDirOk
            ? __('Diretório de backup/staging gravável.', 'nextool')
            : __('Diretório de backup/staging sem permissão de escrita.', 'nextool'),
         $backupDirOk ? [] : ['path' => $backupDir, 'web_user' => $webUser]
      );

      if (!$skipLockCheck) {
         $lockInfo = $this->canAcquireLock();
         $this->appendCheck($checks, $blocking, 'lock_free', 'blocker', $lockInfo['ok'], $lockInfo['message']);
      } else {
         $this->appendCheck($checks, $blocking, 'lock_free', 'blocker', true, __('Lock ignorado (chamador já detém o lock).', 'nextool'));
      }

      $requiredExtensions = ['curl', 'zip', 'sodium'];
      $missingExtensions = [];
      foreach ($requiredExtensions as $extension) {
         if (!extension_loaded($extension)) {
            $missingExtensions[] = $extension;
         }
      }
      $extensionsOk = count($missingExtensions) === 0;
      $this->appendCheck($checks, $blocking, 'php_extensions', 'blocker', $extensionsOk,
         $extensionsOk
            ? __('Extensões PHP necessárias estão disponíveis.', 'nextool')
            : sprintf(__('Extensões PHP ausentes: %s', 'nextool'), implode(', ', $missingExtensions))
      );

      $connectivityResult = [
         'success' => false,
         'latency_ms' => null,
         'message' => __('Conectividade não avaliada.', 'nextool'),
      ];
      if (!$skipConnectivity) {
         try {
            $connectivityResult = $this->client->healthCheck(10);
         } catch (Throwable $e) {
            $connectivityResult = [
               'success' => false,
               'latency_ms' => null,
               'message' => $e->getMessage(),
            ];
         }
      }
      $connectivityOk = $skipConnectivity || !empty($connectivityResult['success']);
      $this->appendCheck($checks, $blocking, 'containerapi_connectivity', 'blocker', $connectivityOk,
         $connectivityOk
            ? __('Conectividade com ContainerAPI validada.', 'nextool')
            : sprintf(__('Falha de conectividade com ContainerAPI: %s', 'nextool'), (string)($connectivityResult['message'] ?? 'erro'))
      );

      $manifestToCheck = $manifest;
      if ($manifestToCheck === null && $action === 'apply') {
         $manifestToCheck = $this->readStagedManifest();
      }

      $compatibilityOk = true;
      if (is_array($manifestToCheck) && isset($manifestToCheck['version'])) {
         $compatibility = $this->validateCompatibility($manifestToCheck);
         $compatibilityOk = $compatibility['ok'];
         $this->appendCheck($checks, $blocking, 'release_compatibility', 'blocker', $compatibility['ok'], $compatibility['message']);
      }

      $diskOk = true;
      $diskMessage = __('Espaço em disco suficiente para staging.', 'nextool');
      $requiredBytes = 50 * 1024 * 1024;
      if (is_array($manifestToCheck) && !empty($manifestToCheck['package_size_bytes'])) {
         $requiredBytes = max($requiredBytes, (int)$manifestToCheck['package_size_bytes'] * 3);
      }
      if ($pluginDirExists) {
         $freeBytes = @disk_free_space($pluginDir);
         if ($freeBytes === false) {
            $diskOk = false;
            $diskMessage = __('Não foi possível avaliar espaço em disco de plugins/nextool.', 'nextool');
         } elseif ($freeBytes < $requiredBytes) {
            $diskOk = false;
            $diskMessage = sprintf(
               __('Espaço em disco insuficiente: disponível %s bytes, necessário >= %s bytes.', 'nextool'),
               (string)$freeBytes,
               (string)$requiredBytes
            );
         }
      }
      $this->appendCheck($checks, $blocking, 'disk_space', 'blocker', $diskOk, $diskMessage);

      if (is_array($manifestToCheck) && isset($manifestToCheck['signature']) && isset($manifestToCheck['signature_key_id'])) {
         $signatureOk = true;
         $signatureMessage = __('Assinatura Ed25519 do manifesto validada.', 'nextool');
         try {
            $this->assertManifestSignatureValid($manifestToCheck);
         } catch (Throwable $e) {
            $signatureOk = false;
            $signatureMessage = sprintf(__('Assinatura do manifesto inválida: %s', 'nextool'), $e->getMessage());
         }
         $this->appendCheck($checks, $blocking, 'signature_valid', 'blocker', $signatureOk, $signatureMessage);
      } else {
         $this->appendCheck(
            $checks,
            $blocking,
            'signature_valid',
            'blocker',
            false,
            __('Manifesto sem assinatura ou sem identificador da chave de assinatura.', 'nextool')
         );
      }

      if ($action === 'apply') {
         $integrity = $this->verifyStagedPackageIntegrity($manifestToCheck);
         $this->appendCheck($checks, $blocking, 'staged_integrity', 'blocker', $integrity['ok'], $integrity['message']);
      } else {
         $this->appendCheck(
            $checks,
            $blocking,
            'staged_integrity',
            'blocker',
            true,
            __('Integridade completa (hash + assinatura) será validada durante o prepare/apply.', 'nextool')
         );
      }

      if ($action === 'apply') {
         $integrityManifestPath = $this->getIntegrityManifestPath();
         $integrityManifestExists = is_file($integrityManifestPath);
         $this->appendCheck($checks, $blocking, 'integrity_manifest', 'blocker', $integrityManifestExists,
            $integrityManifestExists
               ? __('Manifesto de integridade (integrity.json) encontrado no staging.', 'nextool')
               : __('Manifesto de integridade (integrity.json) ausente no staging. Execute Prepare novamente.', 'nextool')
         );
      }

      $antiDowngradeOk = true;
      $antiDowngradeMessage = __('Anti-downgrade validado.', 'nextool');
      if (is_array($manifestToCheck) && !empty($manifestToCheck['version'])) {
         $currentVersion = $this->getInstalledCoreVersion();
         if (version_compare((string)$manifestToCheck['version'], $currentVersion, '<=')) {
            $antiDowngradeOk = false;
            $antiDowngradeMessage = sprintf(
               __('Versão alvo (%s) não é superior à versão atual (%s).', 'nextool'),
               (string)$manifestToCheck['version'],
               $currentVersion
            );
         }
      }
      $this->appendCheck($checks, $blocking, 'anti_downgrade', 'blocker', $antiDowngradeOk, $antiDowngradeMessage);

      // Warnings
      if (!empty($state['staged_at'])) {
         $stagingRecent = $this->isRecentTimestamp($state['staged_at'], self::STAGING_STALE_WARNING_HOURS * 3600);
         $this->appendCheck(
            $checks,
            $warnings,
            'staging_freshness',
            'warning',
            $stagingRecent,
            $stagingRecent
               ? __('Staging recente.', 'nextool')
               : __('Staging antigo detectado. Recomendado preparar novamente.', 'nextool')
         );
      }

      if (!$skipConnectivity && !empty($connectivityResult['latency_ms'])) {
         $latency = (int)$connectivityResult['latency_ms'];
         $latencyOk = $latency <= self::HIGH_LATENCY_WARNING_MS;
         $this->appendCheck(
            $checks,
            $warnings,
            'api_latency',
            'warning',
            $latencyOk,
            $latencyOk
               ? __('Latência da API dentro do esperado.', 'nextool')
               : sprintf(__('Latência elevada da API: %d ms.', 'nextool'), $latency)
         );
      }

      $ok = count($blocking) === 0;

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] preflight() concluído: ok=%s blocking_count=%d warning_count=%d duration_ms=%d\n",
         $ok ? 'true' : 'false',
         count($blocking),
         count($warnings),
         (int)round((microtime(true) - $started) * 1000)
      ));
      if (!empty($blocking)) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] preflight blocking_errors: %s\n",
            json_encode($blocking, JSON_UNESCAPED_UNICODE)
         ));
      }

      $this->logAction('preflight', $ok, [
         'source' => 'manual',
         'current_version' => $this->getInstalledCoreVersion(),
         'target_version' => is_array($manifestToCheck) ? ($manifestToCheck['version'] ?? null) : null,
         'message' => $ok ? 'Preflight concluído sem bloqueios.' : 'Preflight com bloqueios.',
         'duration_ms' => (int)round((microtime(true) - $started) * 1000),
         'details' => [
            'action' => $action,
            'blocking_count' => count($blocking),
            'warning_count' => count($warnings),
         ],
      ]);

      return [
         'success' => $ok,
         'ok' => $ok,
         'message' => $ok
            ? __('Preflight concluído sem bloqueios.', 'nextool')
            : __('Preflight encontrou bloqueios obrigatórios.', 'nextool'),
         'checks' => $checks,
         'blocking_errors' => $blocking,
         'warnings' => $warnings,
      ];
   }

   public function prepare(string $channel = self::DEFAULT_CHANNEL, string $source = 'manual'): array {
      $channel = $this->sanitizeChannel($channel);
      Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] prepare() iniciado: channel=%s source=%s\n", $channel, $source));

      // Auto-create runtime directories if they don't exist
      $this->ensureRuntimeDirs();

      return $this->withLock(function () use ($channel, $source) {
         $started = microtime(true);
         $currentVersion = $this->getInstalledCoreVersion();

         Toolbox::logInFile('plugin_nextool', "[DEBUG] [CoreUpdater] prepare() lock adquirido, solicitando manifesto.\n");
         $manifest = $this->client->requestManifest($channel, 'core_update_prepare_' . $source);
         $preflight = $this->preflight($manifest, 'prepare', ['skip_lock_check' => true]);
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() preflight retornou: ok=%s\n",
            !empty($preflight['ok']) ? 'true' : 'false'
         ));
         if (empty($preflight['ok'])) {
            $message = __('Prepare bloqueado pelo preflight.', 'nextool');
            $this->logAction('prepare', false, [
               'source' => $source,
               'current_version' => $currentVersion,
               'target_version' => $manifest['version'] ?? null,
               'message' => $message,
               'duration_ms' => (int)round((microtime(true) - $started) * 1000),
               'details' => ['preflight' => $preflight],
            ]);
            return [
               'success' => false,
               'message' => $message,
               'data' => ['preflight' => $preflight],
            ];
         }

         $targetVersion = (string)($manifest['version'] ?? '');
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() target=%s current=%s anti_downgrade_check\n",
            $targetVersion,
            $currentVersion
         ));
         if ($targetVersion === '' || version_compare($targetVersion, $currentVersion, '<=')) {
            $message = __('Nenhuma atualização elegível para staging (anti-downgrade).', 'nextool');
            $this->logAction('prepare', false, [
               'source' => $source,
               'current_version' => $currentVersion,
               'target_version' => $targetVersion,
               'message' => $message,
               'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ]);
            return [
               'success' => false,
               'message' => $message,
            ];
         }

         $alreadyStaged = $this->isAlreadyStagedVersion($targetVersion);
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() already_staged=%s, iniciando download/staging\n",
            $alreadyStaged ? 'true' : 'false'
         ));
         if ($alreadyStaged) {
            $integrity = $this->verifyStagedPackageIntegrity($manifest);
            if (!empty($integrity['ok']) && is_dir($this->getStagedPluginPath())) {
               // Gerar integrity.json se ausente (staging criado antes da implementação do manifesto)
               $integrityPath = $this->getIntegrityManifestPath();
               if (!is_file($integrityPath)) {
                  $this->generateIntegrityManifest();
               }
               $state = $this->persistState([
                  'update_available' => 1,
                  'staged_target_version' => $targetVersion,
                  'staged_source' => $source,
                  'staged_at' => date('Y-m-d H:i:s'),
               ]);

               return [
                  'success' => true,
                  'message' => __('Versão alvo já estava preparada no staging.', 'nextool'),
                  'data' => [
                     'current_version' => $currentVersion,
                     'target_version' => $targetVersion,
                     'state' => $state,
                     'manifest' => $this->sanitizeManifestForOutput($manifest),
                  ],
               ];
            }
         }

         $this->resetStagingDirectory();

         // Download para nome genérico (sem extensão de formato)
         $downloadPath = $this->getStagingRoot() . '/nextool-core.download';
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() baixando pacote para %s\n",
            $downloadPath
         ));
         $this->client->downloadPackage((string)$manifest['download_url'], $downloadPath);
         Toolbox::logInFile('plugin_nextool', "[DEBUG] [CoreUpdater] prepare() validando hash e assinatura.\n");
         $this->assertHashMatches($downloadPath, (string)$manifest['hash_sha256']);
         $this->assertManifestSignatureValid($manifest);

         // Detectar formato e renomear com extensão correta (PharData exige)
         $format = PluginNextoolFileHelper::detectArchiveFormat($downloadPath);
         if ($format === 'tar.gz') {
            $packagePath = $this->getStagingRoot() . '/nextool-core.tar.gz';
         } elseif ($format === 'zip') {
            $packagePath = $this->getStagingRoot() . '/nextool-core.zip';
         } else {
            @unlink($downloadPath);
            throw new RuntimeException(__('Formato de artefato de core não reconhecido.', 'nextool'));
         }
         if (!rename($downloadPath, $packagePath)) {
            @unlink($downloadPath);
            throw new RuntimeException(__('Falha ao preparar artefato de core para extração.', 'nextool'));
         }
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() formato detectado: %s, extraindo pacote para staging isolado.\n",
            $format
         ));

         $pluginRoot = $this->extractPackage($packagePath);
         $this->writeStagedManifest($manifest, $source);
         $this->generateIntegrityManifest();

         $state = $this->persistState([
            'update_available' => 1,
            'staged_target_version' => $targetVersion,
            'staged_source' => $source,
            'staged_at' => date('Y-m-d H:i:s'),
         ]);

         $this->logAction('prepare', true, [
            'source' => $source,
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'message' => 'Prepare concluído com staging publicado.',
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'details' => [
               'channel' => $channel,
               'staged_path' => $this->getStagedPluginPath(),
            ],
         ]);

         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] prepare() concluído com sucesso: %s -> %s duration_ms=%d\n",
            $currentVersion,
            $targetVersion,
            (int)round((microtime(true) - $started) * 1000)
         ));
         return [
            'success' => true,
            'message' => __('Staging do core preparado com sucesso.', 'nextool'),
            'data' => [
               'current_version' => $currentVersion,
               'target_version' => $targetVersion,
               'manifest' => $this->sanitizeManifestForOutput($manifest),
               'state' => $state,
            ],
         ];
      }, 'prepare', $source);
   }

   public function apply(string $source = 'manual'): array {
      Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] apply() iniciado: source=%s\n", $source));
      return $this->withLock(function () use ($source) {
         $started = microtime(true);
         $manifest = $this->readStagedManifest();
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] apply() manifest staged: %s\n",
            $manifest !== null && isset($manifest['version']) ? $manifest['version'] : 'null'
         ));
         if ($manifest === null) {
            return [
               'success' => false,
               'message' => __('Nenhum staging encontrado para aplicar.', 'nextool'),
            ];
         }

         $preflight = $this->preflight($manifest, 'apply', ['skip_lock_check' => true]);
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] apply() preflight ok=%s\n",
            !empty($preflight['ok']) ? 'true' : 'false'
         ));
         if (empty($preflight['ok'])) {
            $message = __('Apply bloqueado pelo preflight.', 'nextool');
            $this->logAction('apply', false, [
               'source' => $source,
               'current_version' => $this->getInstalledCoreVersion(),
               'target_version' => $manifest['version'] ?? null,
               'message' => $message,
               'duration_ms' => (int)round((microtime(true) - $started) * 1000),
               'details' => ['preflight' => $preflight],
            ]);
            return [
               'success' => false,
               'message' => $message,
               'data' => ['preflight' => $preflight],
            ];
         }

         $targetVersion = (string)($manifest['version'] ?? '');
         $applyResult = $this->applyByCopyAndReload($targetVersion, $source);
         if (empty($applyResult['success'])) {
            $this->logAction('apply', false, [
               'source' => $source,
               'current_version' => $this->getInstalledCoreVersion(),
               'target_version' => $targetVersion,
               'message' => (string)($applyResult['message'] ?? 'Falha no fluxo de apply.'),
               'duration_ms' => (int)round((microtime(true) - $started) * 1000),
               'details' => ['apply' => $applyResult],
            ]);
            return $applyResult;
         }

         $this->clearStagingArtifacts();
         $state = $this->persistState([
            'update_available' => 0,
            'staged_target_version' => null,
            'staged_source' => null,
            'staged_at' => null,
         ]);

         $this->logAction('apply', true, [
            'source' => $source,
            'current_version' => $applyResult['data']['previous_version'] ?? null,
            'target_version' => $manifest['version'] ?? null,
            'message' => 'Apply concluído com sucesso.',
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'details' => ['state' => $applyResult['data']['final_state'] ?? null],
         ]);

         return [
            'success' => true,
            'message' => __('Core atualizado com sucesso.', 'nextool'),
            'data' => array_merge($applyResult['data'] ?? [], [
               'state' => $state,
            ]),
         ];
      }, 'apply', $source);
   }

   public function cancelStaging(string $source = 'manual'): array {
      Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] cancelStaging() iniciado: source=%s\n", $source));
      return $this->withLock(function () use ($source) {
         $started = microtime(true);
         $state = self::getState();

         $stagedVersion = (string)($state['staged_target_version'] ?? '');
         $installedVersion = $this->getInstalledCoreVersion();

         $this->clearStagingArtifacts();

         $newState = $this->persistState([
            'update_available' => 0,
            'staged_target_version' => null,
            'staged_source' => null,
            'staged_at' => null,
         ]);

         $this->logAction('cancel_staging', true, [
            'source' => $source,
            'current_version' => $installedVersion,
            'target_version' => $stagedVersion !== '' ? $stagedVersion : null,
            'message' => 'Staging cancelado.',
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
         ]);

         return [
            'success' => true,
            'message' => __('Staging cancelado e estado limpo.', 'nextool'),
            'data' => ['state' => $newState],
         ];
      }, 'cancel_staging', $source);
   }

   public function listBackups(): array {
      $root = $this->getBackupsRoot();
      if (!is_dir($root)) {
         return [
            'success' => true,
            'message' => __('Nenhum backup disponível.', 'nextool'),
            'data'    => ['backups' => []],
         ];
      }

      $backups = [];
      foreach (scandir($root) ?: [] as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }
         $metaPath = $root . '/' . $entry . '/meta.json';
         if (is_file($metaPath)) {
            $meta = json_decode((string)file_get_contents($metaPath), true);
            if (is_array($meta) && !empty($meta['version'])) {
               $backups[] = $meta;
            }
         }
      }

      usort($backups, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

      return [
         'success' => true,
         'message' => sprintf(__('%d backup(s) disponível(is).', 'nextool'), count($backups)),
         'data'    => ['backups' => $backups],
      ];
   }

   public function restore(string $backupId, string $source = 'manual'): array {
      Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] restore() iniciado: backup_id=%s source=%s\n", $backupId, $source));
      return $this->withLock(function () use ($backupId, $source) {
         $started = microtime(true);

         $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $backupId);
         if ($sanitized === '' || $sanitized !== $backupId) {
            return [
               'success' => false,
               'message' => __('Identificador de backup inválido.', 'nextool'),
            ];
         }

         $backupDir = $this->getBackupsRoot() . '/' . $sanitized;
         $filesDir  = $backupDir . '/files';
         $metaPath  = $backupDir . '/meta.json';

         if (!is_dir($filesDir) || !is_file($metaPath)) {
            return [
               'success' => false,
               'message' => __('Backup não encontrado.', 'nextool'),
            ];
         }

         $meta = json_decode((string)file_get_contents($metaPath), true);
         if (!is_array($meta)) {
            return [
               'success' => false,
               'message' => __('Metadados de backup corrompidos.', 'nextool'),
            ];
         }

         $targetPath = $this->getPluginInstallPath();
         $currentVersion = $this->getInstalledCoreVersion();

         // Create backup of current state before restoring (best-effort)
         try {
            $this->createVersionedBackup($targetPath);
         } catch (Throwable $backupEx) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[WARN] [CoreUpdater] Não foi possível criar backup pré-restore: %s\n",
               $backupEx->getMessage()
            ));
         }

         $this->setMaintenanceFlag();

         try {
            // Overwrite plugin files from backup (preserving .git*)
            $this->overwriteFilesFromStaging($filesDir, $targetPath);
            $this->cleanupRemovedFiles($filesDir, $targetPath);
         } catch (Throwable $e) {
            $this->clearMaintenanceFlag();
            $this->resetOpcache();

            $this->logAction('restore', false, [
               'source'          => $source,
               'current_version' => $currentVersion,
               'target_version'  => $meta['version'] ?? null,
               'message'         => $e->getMessage(),
               'duration_ms'     => (int)round((microtime(true) - $started) * 1000),
            ]);

            return [
               'success' => false,
               'message' => sprintf(__('Falha ao restaurar: %s', 'nextool'), $e->getMessage()),
            ];
         }

         $this->clearMaintenanceFlag();
         $this->resetOpcache();

         $restoredVersion = (string)($meta['version'] ?? 'desconhecida');

         $this->persistState([
            'update_available' => 0,
            'staged_target_version' => null,
            'staged_source' => null,
            'staged_at' => null,
         ]);

         $this->logAction('restore', true, [
            'source'          => $source,
            'current_version' => $currentVersion,
            'target_version'  => $restoredVersion,
            'message'         => sprintf('Restaurado para versão %s.', $restoredVersion),
            'duration_ms'     => (int)round((microtime(true) - $started) * 1000),
         ]);

         return [
            'success' => true,
            'message' => sprintf(__('Restaurado para versão %s. Recarregue a página (F5).', 'nextool'), $restoredVersion),
            'data'    => [
               'restored_version' => $restoredVersion,
               'previous_version' => $currentVersion,
               'needs_reload'     => true,
            ],
         ];
      }, 'restore', $source);
   }

   public static function getState(): array {
      $values = Config::getConfigurationValues(self::STATE_NAMESPACE);

      $defaults = [
         'update_available' => 0,
         'staged_target_version' => null,
         'staged_source' => null,
         'staged_at' => null,
         'pending_apply_version' => null,
         'latest_available_version' => null,
      ];

      $state = array_merge($defaults, is_array($values) ? $values : []);
      $state['update_available'] = !empty($state['update_available']) ? 1 : 0;

      foreach (['staged_target_version', 'staged_source', 'staged_at'] as $key) {
         if (!isset($state[$key]) || trim((string)$state[$key]) === '') {
            $state[$key] = null;
         }
      }

      return $state;
   }

   private function persistState(array $updates): array {
      $state = array_merge(self::getState(), $updates);
      Config::setConfigurationValues(self::STATE_NAMESPACE, $state);
      return self::getState();
   }

   private function withLock(callable $callback, string $action, string $source): array {
      $lockPath = $this->getLockFilePath();
      $lockDir = dirname($lockPath);
      if (!is_dir($lockDir)) {
         @mkdir($lockDir, 0755, true);
      }

      $handle = @fopen($lockPath, 'c');
      if ($handle === false) {
         Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] withLock() falhou ao criar handle: %s\n", $lockPath));
         return [
            'success' => false,
            'message' => __('Não foi possível criar lock de atualização de core.', 'nextool'),
         ];
      }

      if (!@flock($handle, LOCK_EX | LOCK_NB)) {
         fclose($handle);
         Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] withLock() lock ocupado, rejeitando action=%s\n", $action));
         return [
            'success' => false,
            'message' => __('Já existe uma operação de atualização de core em execução.', 'nextool'),
         ];
      }

      Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] withLock() lock adquirido: action=%s source=%s\n", $action, $source));
      try {
         return $callback();
      } catch (Throwable $e) {
         $this->logAction($action, false, [
            'source' => $source,
            'current_version' => $this->getInstalledCoreVersion(),
            'target_version' => self::getState()['staged_target_version'] ?? null,
            'message' => $e->getMessage(),
            'details' => [
               'exception' => get_class($e),
            ],
         ]);

         return [
            'success' => false,
            'message' => sprintf(__('Falha na operação de atualização de core: %s', 'nextool'), $e->getMessage()),
         ];
      } finally {
         @flock($handle, LOCK_UN);
         fclose($handle);
         Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdater] withLock() lock liberado: action=%s\n", $action));
      }
   }

   private function canAcquireLock(): array {
      $lockPath = $this->getLockFilePath();
      $lockDir = dirname($lockPath);
      if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true)) {
         return [
            'ok' => false,
            'message' => __('Não foi possível criar diretório de lock para o updater.', 'nextool'),
         ];
      }

      $handle = @fopen($lockPath, 'c');
      if ($handle === false) {
         return [
            'ok' => false,
            'message' => __('Não foi possível abrir arquivo de lock do updater.', 'nextool'),
         ];
      }

      $ok = @flock($handle, LOCK_EX | LOCK_NB);
      if ($ok) {
         @flock($handle, LOCK_UN);
      }
      fclose($handle);

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] canAcquireLock() ok=%s\n",
         $ok ? 'true' : 'false'
      ));

      return [
         'ok' => (bool)$ok,
         'message' => $ok
            ? __('Lock do updater está livre.', 'nextool')
            : __('Lock do updater ocupado por outra execução.', 'nextool'),
      ];
   }

   private function validateCompatibility(array $manifest): array {
      $glpiVersion = defined('GLPI_VERSION') ? GLPI_VERSION : '0.0.0';
      $phpVersion = PHP_VERSION;

      $minGlpi = trim((string)($manifest['min_glpi'] ?? ''));
      $maxGlpi = trim((string)($manifest['max_glpi'] ?? ''));
      $minPhp = trim((string)($manifest['min_php'] ?? ''));
      $maxPhp = trim((string)($manifest['max_php'] ?? ''));

      if ($minGlpi !== '' && version_compare($glpiVersion, $minGlpi, '<')) {
         return [
            'ok' => false,
            'message' => sprintf(__('Release exige GLPI >= %s (atual: %s).', 'nextool'), $minGlpi, $glpiVersion),
         ];
      }
      if ($maxGlpi !== '' && version_compare($glpiVersion, $maxGlpi, '>')) {
         return [
            'ok' => false,
            'message' => sprintf(__('Release suporta GLPI até %s (atual: %s).', 'nextool'), $maxGlpi, $glpiVersion),
         ];
      }

      if ($minPhp !== '' && version_compare($phpVersion, $minPhp, '<')) {
         return [
            'ok' => false,
            'message' => sprintf(__('Release exige PHP >= %s (atual: %s).', 'nextool'), $minPhp, $phpVersion),
         ];
      }
      if ($maxPhp !== '' && version_compare($phpVersion, $maxPhp, '>')) {
         return [
            'ok' => false,
            'message' => sprintf(__('Release suporta PHP até %s (atual: %s).', 'nextool'), $maxPhp, $phpVersion),
         ];
      }

      return [
         'ok' => true,
         'message' => __('Compatibilidade release/GLPI/PHP validada.', 'nextool'),
      ];
   }

   private function verifyStagedPackageIntegrity(?array $manifest = null): array {
      $manifest = $manifest ?? $this->readStagedManifest();
      if (!is_array($manifest)) {
         return [
            'ok' => false,
            'message' => __('Manifesto de staging não encontrado.', 'nextool'),
         ];
      }

      $packagePath = $this->getStagedPackagePath();
      if (!is_file($packagePath)) {
         return [
            'ok' => false,
            'message' => __('Pacote de staging não encontrado.', 'nextool'),
         ];
      }

      try {
         $this->assertHashMatches($packagePath, (string)($manifest['hash_sha256'] ?? ''));
         $this->assertManifestSignatureValid($manifest);
      } catch (Throwable $e) {
         return [
            'ok' => false,
            'message' => $e->getMessage(),
         ];
      }

      return [
         'ok' => true,
         'message' => __('Integridade do staging validada (hash + assinatura).', 'nextool'),
      ];
   }

   private function assertHashMatches(string $filePath, string $expectedHash): void {
      $expected = strtolower(trim($expectedHash));
      if ($expected === '' || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
         throw new RuntimeException(__('Hash SHA256 esperado inválido no manifesto.', 'nextool'));
      }

      $real = hash_file('sha256', $filePath);
      if (!is_string($real) || preg_match('/^[a-f0-9]{64}$/', $real) !== 1) {
         throw new RuntimeException(__('Falha ao calcular SHA256 do pacote de core.', 'nextool'));
      }

      if (!hash_equals($expected, strtolower($real))) {
         throw new RuntimeException(__('Hash SHA256 do pacote de core não confere com o manifesto.', 'nextool'));
      }
   }

   private function assertManifestSignatureValid(array $manifest): void {
      if (!extension_loaded('sodium')) {
         throw new RuntimeException(__('Extensão sodium ausente para validação de assinatura Ed25519.', 'nextool'));
      }

      $keyId = trim((string)($manifest['signature_key_id'] ?? ''));
      $signatureRaw = base64_decode((string)($manifest['signature'] ?? ''), true);
      if ($keyId === '' || $signatureRaw === false || strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
         throw new RuntimeException(__('Assinatura do manifesto inválida ou ausente.', 'nextool'));
      }

      $trustedKeys = $this->getTrustedSignaturePublicKeys();
      if (!isset($trustedKeys[$keyId])) {
         throw new RuntimeException(sprintf(__('signature_key_id não confiável: %s', 'nextool'), $keyId));
      }

      $payload = $this->buildManifestSignaturePayload($manifest);
      $publicKey = $trustedKeys[$keyId];

      if (!sodium_crypto_sign_verify_detached($signatureRaw, $payload, $publicKey)) {
         throw new RuntimeException(__('Assinatura Ed25519 do manifesto não confere.', 'nextool'));
      }
   }

   /**
    * @return array<string,string> key_id => public_key_binary
    */
   private function getTrustedSignaturePublicKeys(): array {
      $values = Config::getConfigurationValues('plugin:nextool_distribution');
      $keys = [];

      $jsonCandidates = [];
      if (isset($values['core_signing_public_keys_json'])) {
         $jsonCandidates[] = (string)$values['core_signing_public_keys_json'];
      }
      $envJson = getenv('NEXTOOL_CORE_SIGNING_PUBLIC_KEYS');
      if ($envJson !== false) {
         $jsonCandidates[] = (string)$envJson;
      }

      foreach ($jsonCandidates as $json) {
         $json = trim($json);
         if ($json === '') {
            continue;
         }

         $decoded = json_decode($json, true);
         if (!is_array($decoded)) {
            continue;
         }

         foreach ($decoded as $keyId => $rawKey) {
            $normalizedId = trim((string)$keyId);
            $decodedKey = $this->decodePublicKey((string)$rawKey);
            if ($normalizedId !== '' && $decodedKey !== null) {
               $keys[$normalizedId] = $decodedKey;
            }
         }
      }

      $singleKeyId = trim((string)($values['core_signing_key_id'] ?? ''));
      $singleKeyRaw = trim((string)($values['core_signing_public_key'] ?? ''));
      if ($singleKeyId !== '' && $singleKeyRaw !== '') {
         $decoded = $this->decodePublicKey($singleKeyRaw);
         if ($decoded !== null) {
            $keys[$singleKeyId] = $decoded;
         }
      }

      $envSingleId = getenv('NEXTOOL_CORE_SIGNING_KEY_ID');
      $envSingleKey = getenv('NEXTOOL_CORE_SIGNING_PUBLIC_KEY');
      if ($envSingleId !== false && $envSingleKey !== false) {
         $decoded = $this->decodePublicKey((string)$envSingleKey);
         $normalizedId = trim((string)$envSingleId);
         if ($decoded !== null && $normalizedId !== '') {
            $keys[$normalizedId] = $decoded;
         }
      }

      // Bundled public key — fallback when no key is configured via DB or env.
      // This is the official NexTool signing key, safe to distribute with the plugin.
      if (count($keys) === 0) {
         $bundledKeys = [
            'nextool-core-ed25519-1' => 'PNK7FSuZccd9GQBvSTAkPJOpg98ENIiBoNH3M/0V/70=',
         ];
         foreach ($bundledKeys as $keyId => $rawKey) {
            $decoded = $this->decodePublicKey($rawKey);
            if ($decoded !== null) {
               $keys[$keyId] = $decoded;
            }
         }
      }

      if (count($keys) === 0) {
         throw new RuntimeException(__('Nenhuma chave pública confiável configurada para validar assinatura do core.', 'nextool'));
      }

      return $keys;
   }

   private function decodePublicKey(string $rawKey): ?string {
      $raw = trim($rawKey);
      if ($raw === '') {
         return null;
      }

      if (str_starts_with($raw, 'base64:')) {
         $raw = substr($raw, 7);
      }

      $binary = null;
      if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
         $hex = @hex2bin($raw);
         if ($hex !== false) {
            $binary = $hex;
         }
      }

      if ($binary === null) {
         $decoded = base64_decode($raw, true);
         if ($decoded !== false) {
            $binary = $decoded;
         }
      }

      if ($binary === null) {
         $binary = $raw;
      }

      if (strlen($binary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
         return null;
      }

      return $binary;
   }

   private function buildManifestSignaturePayload(array $manifest): string {
      $payload = [
         'version' => (string)($manifest['version'] ?? ''),
         'hash_sha256' => strtolower(trim((string)($manifest['hash_sha256'] ?? ''))),
         'min_glpi' => (string)($manifest['min_glpi'] ?? ''),
         'max_glpi' => (string)($manifest['max_glpi'] ?? ''),
         'release_notes_url' => (string)($manifest['release_notes_url'] ?? ''),
      ];

      $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($encoded) || $encoded === '') {
         throw new RuntimeException(__('Falha ao montar payload da assinatura.', 'nextool'));
      }

      return $encoded;
   }

   private function extractPackage(string $packagePath): string {
      $extractRoot = $this->getStagedExtractPath();
      if (is_dir($extractRoot)) {
         PluginNextoolFileHelper::deleteDirectory($extractRoot, true);
      }
      if (!@mkdir($extractRoot, 0755, true) && !is_dir($extractRoot)) {
         throw new RuntimeException(__('Não foi possível preparar diretório de extração do core.', 'nextool'));
      }

      if (str_ends_with($packagePath, '.tar.gz')) {
         // PharData — formato preferencial (built-in, sem dependência externa)
         try {
            $phar = new PharData($packagePath);
            $phar->extractTo($extractRoot, null, true);
         } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
               __('Falha ao extrair pacote de core: %s', 'nextool'),
               $e->getMessage()
            ));
         }
      } elseif (str_ends_with($packagePath, '.zip')) {
         // ZipArchive — fallback (requer ext-zip)
         if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
               __('A extensão php-zip não está instalada neste servidor. Solicite ao administrador que instale a extensão (ex: apt install php-zip ou yum install php-zip) e reinicie o PHP.', 'nextool')
            );
         }

         $zip = new ZipArchive();
         if ($zip->open($packagePath) !== true) {
            throw new RuntimeException(__('Não foi possível abrir pacote de core para extração.', 'nextool'));
         }

         for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string)$zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $entryName);

            if ($normalized === '' || str_contains($normalized, "\0")) {
               continue;
            }
            if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
               $zip->close();
               throw new RuntimeException(__('Pacote de core inválido (entrada insegura no ZIP).', 'nextool'));
            }

            $targetPath = $extractRoot . '/' . $normalized;
            if (str_ends_with($normalized, '/')) {
               if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
                  $zip->close();
                  throw new RuntimeException(__('Falha ao criar diretório durante extração do core.', 'nextool'));
               }
               continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
               $zip->close();
               throw new RuntimeException(__('Falha ao preparar diretório de arquivo extraído do core.', 'nextool'));
            }

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
               $zip->close();
               throw new RuntimeException(__('Falha ao ler entrada do ZIP do core.', 'nextool'));
            }

            $out = @fopen($targetPath, 'wb');
            if ($out === false) {
               fclose($stream);
               $zip->close();
               throw new RuntimeException(__('Falha ao escrever arquivo extraído do core.', 'nextool'));
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
         }

         $zip->close();
      } else {
         throw new RuntimeException(sprintf(
            __('Formato de artefato de core não suportado: %s', 'nextool'),
            pathinfo($packagePath, PATHINFO_EXTENSION)
         ));
      }

      $candidate = $extractRoot . '/nextool';
      if (is_dir($candidate) && is_file($candidate . '/setup.php')) {
         return $candidate;
      }

      $entries = array_values(array_filter(scandir($extractRoot) ?: [], static function ($entry) {
         return $entry !== '.' && $entry !== '..';
      }));

      if (count($entries) === 1) {
         $single = $extractRoot . '/' . $entries[0];
         if (is_dir($single) && is_file($single . '/setup.php')) {
            return $single;
         }
      }

      if (is_file($extractRoot . '/setup.php')) {
         return $extractRoot;
      }

      throw new RuntimeException(__('Pacote de core inválido: diretório do plugin nextool não encontrado após extração.', 'nextool'));
   }

   private function clearDirectoryContents(string $dir): void {
      if (!is_dir($dir)) {
         return;
      }
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($iterator as $item) {
         $path = $item->getRealPath();
         if ($item->isDir()) {
            @rmdir($path);
         } else {
            @unlink($path);
         }
      }
   }

   private function recursiveCopy(string $source, string $dest): void {
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
      PluginNextoolFileHelper::recursiveCopy($source, $dest);
   }

   /**
    * Apply Opção D: In-Place Safe Replace
    * Backup → per-file atomic overwrite → verify integrity → rollback on failure.
    */
   private function applyByCopyAndReload(string $targetVersion, string $source): array {
      $stagedPath = $this->getStagedPluginPath();
      $targetPath = $this->getPluginInstallPath();

      if (!is_dir($stagedPath) || !is_file($stagedPath . '/setup.php')) {
         return [
            'success' => false,
            'message' => __('Staging do plugin não encontrado ou inválido.', 'nextool'),
         ];
      }

      if (!is_writable($targetPath)) {
         return [
            'success' => false,
            'message' => __('Sem permissão de escrita no diretório plugins/nextool.', 'nextool'),
            'data' => [
               'error_code' => 'plugin_dir_write_permission_required',
               'plugin_dir' => $targetPath,
            ],
         ];
      }

      $integrityManifestPath = $this->getIntegrityManifestPath();
      if (!is_file($integrityManifestPath)) {
         return [
            'success' => false,
            'message' => __('Manifesto de integridade ausente. Execute Prepare novamente.', 'nextool'),
         ];
      }
      $integrityManifest = $this->readIntegrityManifest();
      if ($integrityManifest === null) {
         return [
            'success' => false,
            'message' => __('Manifesto de integridade inválido. Execute Prepare novamente.', 'nextool'),
         ];
      }

      // 1. Pre-update: force NOTUPDATED to prevent "version changed" deactivation (marketplace pattern)
      $this->preUpdatePluginState();

      // 2. Versioned Backup
      $backupMeta = $this->createVersionedBackup($targetPath);
      $backupFilesPath = $this->getBackupsRoot() . '/' . $backupMeta['backup_id'] . '/files';

      // 3. Maintenance flag
      $this->setMaintenanceFlag();

      try {
         // 4. Per-file atomic overwrite
         $this->overwriteFilesFromStaging($stagedPath, $targetPath);

         // 5. Cleanup removed files
         $this->cleanupRemovedFiles($stagedPath, $targetPath);

         // 5b. Verify integrity
         $integrityResult = $this->verifyIntegrity($targetPath, $integrityManifest);
         if (!$integrityResult['ok']) {
            throw new RuntimeException(sprintf(
               __('Verificação de integridade falhou após apply: %s', 'nextool'),
               $integrityResult['message'] ?? 'unknown'
            ));
         }
      } catch (Throwable $e) {
         // Rollback
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ERROR] [CoreUpdater] apply falhou, iniciando rollback: %s\n",
            $e->getMessage()
         ));
         try {
            $this->rollbackFromBackup($backupFilesPath, $targetPath);
         } catch (Throwable $rollbackEx) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[CRITICAL] [CoreUpdater] rollback também falhou: %s\n",
               $rollbackEx->getMessage()
            ));
         }
         $this->clearMaintenanceFlag();
         $this->resetOpcache();
         return [
            'success' => false,
            'message' => sprintf(__('Apply falhou com rollback: %s', 'nextool'), $e->getMessage()),
         ];
      }

      // 6. Reset opcache BEFORE activation so any file reads get fresh content
      $this->resetOpcache();

      // 7. Post-update: set version + activate directly in DB
      $this->postUpdatePluginActivation($targetVersion);

      // 7b. Verify state — GLPI's own boot hooks may override during concurrent requests
      $this->verifyAndForceActivated();

      // 8. Success
      $this->clearMaintenanceFlag();
      $this->persistState(['pending_apply_version' => $targetVersion]);

      global $CFG_GLPI;
      $rootDoc = $CFG_GLPI['root_doc'] ?? '';

      return [
         'success' => true,
         'message' => __('Atualização concluída com sucesso. Redirecionando...', 'nextool'),
         'data' => [
            'previous_version' => $this->getInstalledCoreVersion(),
            'target_version' => $targetVersion,
            'current_version' => $targetVersion,
            'final_state' => 'completed',
            'needs_reload' => true,
            'redirect_url' => $rootDoc . '/front/plugin.php',
         ],
      ];
   }

   private function createVersionedBackup(string $pluginPath): array {
      $version = $this->getInstalledCoreVersion();
      $backupId = $version . '_' . date('Ymd_His');
      $backupsRoot = $this->getBackupsRoot();
      $backupDir = $backupsRoot . '/' . $backupId;
      $filesDir = $backupDir . '/files';

      if (!is_dir($backupsRoot) && !@mkdir($backupsRoot, 0755, true)) {
         throw new RuntimeException(__('Não foi possível criar diretório de backups.', 'nextool'));
      }

      if (is_dir($backupDir)) {
         PluginNextoolFileHelper::deleteDirectory($backupDir, true);
      }
      if (!@mkdir($filesDir, 0755, true) && !is_dir($filesDir)) {
         throw new RuntimeException(__('Não foi possível criar diretório de backup.', 'nextool'));
      }

      $this->recursiveCopy($pluginPath, $filesDir);

      $meta = [
         'version'    => $version,
         'backup_id'  => $backupId,
         'created_at' => date('c'),
      ];

      file_put_contents(
         $backupDir . '/meta.json',
         json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
      );

      $this->pruneOldBackups();

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdater] Backup versionado criado: %s\n", $backupId
      ));

      return $meta;
   }

   private function pruneOldBackups(): void {
      $root = $this->getBackupsRoot();
      if (!is_dir($root)) {
         return;
      }

      $backups = [];
      foreach (scandir($root) ?: [] as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }
         $dir = $root . '/' . $entry;
         $metaPath = $dir . '/meta.json';
         if (is_dir($dir) && is_file($metaPath)) {
            $meta = json_decode((string)file_get_contents($metaPath), true);
            $backups[] = [
               'dir'  => $dir,
               'time' => is_array($meta) ? (string)($meta['created_at'] ?? '') : '',
            ];
         }
      }

      usort($backups, static fn($a, $b) => strcmp($b['time'], $a['time']));

      $excess = array_slice($backups, self::MAX_BACKUP_RETENTION);
      foreach ($excess as $old) {
         PluginNextoolFileHelper::deleteDirectory($old['dir'], true);
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] Backup antigo removido: %s\n", basename($old['dir'])
         ));
      }
   }

   private function overwriteFilesFromStaging(string $stagedPath, string $targetPath): void {
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($stagedPath, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $item) {
         $relativePath = substr($item->getPathname(), strlen($stagedPath) + 1);

         // Skip protected paths (.git/, .github/, .gitignore) during overwrite
         if ($this->isProtectedPath($relativePath)) {
            continue;
         }

         $destPath = $targetPath . '/' . $relativePath;

         if ($item->isDir()) {
            if (!is_dir($destPath) && !@mkdir($destPath, 0755, true)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao criar diretório durante apply: %s', 'nextool'),
                  $relativePath
               ));
            }
            continue;
         }

         $destDir = dirname($destPath);
         if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            throw new RuntimeException(sprintf(
               __('Falha ao criar diretório pai durante apply: %s', 'nextool'),
               $relativePath
            ));
         }

         // Atomic per-file: copy to .tmp then rename
         $srcFile = $item->getPathname();
         $tmpPath = $destPath . '.nextool_tmp';
         if (!@copy($srcFile, $tmpPath)) {
            @unlink($tmpPath);
            throw new RuntimeException(sprintf(
               __('Falha ao copiar arquivo durante apply: %s', 'nextool'),
               $relativePath
            ));
         }
         // Preserve original file permissions
         $perms = @fileperms($srcFile);
         if ($perms !== false) {
            @chmod($tmpPath, $perms & 0x1FF);
         }
         if (!@rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            throw new RuntimeException(sprintf(
               __('Falha ao renomear arquivo durante apply: %s', 'nextool'),
               $relativePath
            ));
         }
      }
   }

   private function cleanupRemovedFiles(string $stagedPath, string $targetPath): void {
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($targetPath, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($iterator as $item) {
         $relativePath = substr($item->getPathname(), strlen($targetPath) + 1);

         // Protect git-related files/directories from deletion
         if ($this->isProtectedPath($relativePath)) {
            continue;
         }

         $stagedEquivalent = $stagedPath . '/' . $relativePath;

         if ($item->isDir()) {
            if (!is_dir($stagedEquivalent)) {
               @rmdir($item->getPathname());
            }
         } else {
            if (!is_file($stagedEquivalent)) {
               @unlink($item->getPathname());
            }
         }
      }
   }

   private function isProtectedPath(string $relativePath): bool {
      $normalized = str_replace('\\', '/', $relativePath);
      $first = explode('/', $normalized)[0];
      return in_array($first, ['.git', '.github'], true) || $normalized === '.gitignore';
   }

   private function generateIntegrityManifest(): void {
      $stagedPath = $this->getStagedPluginPath();
      if (!is_dir($stagedPath)) {
         return;
      }

      $files = [];
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($stagedPath, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::LEAVES_ONLY
      );

      foreach ($iterator as $item) {
         if ($item->isFile()) {
            $relativePath = substr($item->getPathname(), strlen($stagedPath) + 1);
            $files[$relativePath] = hash_file('sha256', $item->getPathname());
         }
      }

      ksort($files);

      $manifest = [
         'version' => $this->getInstalledCoreVersion(),
         'files' => $files,
         'file_count' => count($files),
         'generated_at' => date('c'),
      ];

      $stagedManifest = $this->readStagedManifest();
      if (is_array($stagedManifest) && isset($stagedManifest['version'])) {
         $manifest['version'] = $stagedManifest['version'];
      }

      $path = $this->getIntegrityManifestPath();
      $dir = dirname($path);
      if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
         Toolbox::logInFile('plugin_nextool', "[WARN] [CoreUpdater] Não foi possível criar diretório para integrity.json\n");
         return;
      }

      file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
   }

   private function readIntegrityManifest(): ?array {
      $path = $this->getIntegrityManifestPath();
      if (!is_file($path)) {
         return null;
      }
      $data = json_decode(file_get_contents($path), true);
      return is_array($data) && !empty($data['files']) ? $data : null;
   }

   private function verifyIntegrity(string $pluginPath, array $integrityManifest): array {
      $expectedFiles = $integrityManifest['files'] ?? [];
      if (empty($expectedFiles)) {
         return ['ok' => false, 'message' => __('Manifesto de integridade sem lista de arquivos.', 'nextool')];
      }

      $mismatches = [];
      $missing = [];

      foreach ($expectedFiles as $relativePath => $expectedHash) {
         // Skip protected paths (.git/, .github/, .gitignore) — not copied during overwrite
         if ($this->isProtectedPath($relativePath)) {
            continue;
         }

         $filePath = $pluginPath . '/' . $relativePath;
         if (!is_file($filePath)) {
            $missing[] = $relativePath;
            continue;
         }
         $actualHash = hash_file('sha256', $filePath);
         if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
            $mismatches[] = $relativePath;
         }
      }

      if (!empty($missing) || !empty($mismatches)) {
         $parts = [];
         if (!empty($missing)) {
            $parts[] = sprintf(__('Arquivos ausentes: %s', 'nextool'), implode(', ', $missing));
         }
         if (!empty($mismatches)) {
            $parts[] = sprintf(__('Checksums divergentes: %s', 'nextool'), implode(', ', $mismatches));
         }
         return [
            'ok' => false,
            'message' => implode('; ', $parts),
            'missing_count' => count($missing),
            'mismatch_count' => count($mismatches),
         ];
      }

      return [
         'ok' => true,
         'message' => sprintf(__('Integridade verificada: %d arquivos OK.', 'nextool'), count($expectedFiles)),
      ];
   }

   private function rollbackFromBackup(string $backupPath, string $targetPath): void {
      if (!is_dir($backupPath)) {
         throw new RuntimeException(__('Diretório de backup não encontrado para rollback.', 'nextool'));
      }
      $this->clearDirectoryContentsExceptProtected($targetPath);
      $this->recursiveCopyExceptProtected($backupPath, $targetPath);
   }

   private function recursiveCopyExceptProtected(string $source, string $dest): void {
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $item) {
         $relativePath = substr($item->getPathname(), strlen($source) + 1);

         if ($this->isProtectedPath($relativePath)) {
            continue;
         }

         $destPath = $dest . '/' . $relativePath;

         if ($item->isDir()) {
            if (!is_dir($destPath) && !@mkdir($destPath, 0755, true)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao criar diretório durante rollback: %s', 'nextool'),
                  $relativePath
               ));
            }
         } else {
            $destDir = dirname($destPath);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao criar diretório pai durante rollback: %s', 'nextool'),
                  $relativePath
               ));
            }
            if (!@copy($item->getPathname(), $destPath)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao copiar arquivo durante rollback: %s', 'nextool'),
                  $relativePath
               ));
            }
         }
      }
   }

   private function clearDirectoryContentsExceptProtected(string $dir): void {
      if (!is_dir($dir)) {
         return;
      }
      $realDir = realpath($dir);
      if ($realDir === false) {
         return;
      }
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($iterator as $item) {
         $itemReal = $item->getRealPath();
         if ($itemReal === false) {
            continue;
         }
         $relativePath = substr($itemReal, strlen($realDir) + 1);
         if ($this->isProtectedPath($relativePath)) {
            continue;
         }
         if ($item->isDir()) {
            @rmdir($itemReal);
         } else {
            @unlink($itemReal);
         }
      }
   }

   private function setMaintenanceFlag(): void {
      $path = $this->getMaintenanceFlagPath();
      $dir = dirname($path);
      if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
         return;
      }
      file_put_contents($path, (string)time());
   }

   private function clearMaintenanceFlag(): void {
      $path = $this->getMaintenanceFlagPath();
      if (is_file($path)) {
         @unlink($path);
      }
   }

   /**
    * Pre-update: force plugin state to NOTUPDATED before overwriting files.
    * This prevents GLPI from triggering "Plugin version changed, deactivated"
    * on the next boot. Follows the same pattern as Marketplace/Controller.php.
    */
   private function preUpdatePluginState(): void {
      try {
         $plugin = new Plugin();
         if (
            $plugin->getFromDBbyDir('nextool')
            && !in_array($plugin->fields['state'], [Plugin::ANEW, Plugin::NOTINSTALLED, Plugin::NOTUPDATED])
         ) {
            $plugin->update([
               'id'    => $plugin->fields['id'],
               'state' => Plugin::NOTUPDATED,
            ]);
            Toolbox::logInFile('plugin_nextool',
               "[DEBUG] [CoreUpdater] Pre-update: estado forçado para NOTUPDATED.\n"
            );
         }
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[WARN] [CoreUpdater] Falha no pre-update state: %s\n", $e->getMessage()
         ));
      }
   }

   /**
    * Post-update: set correct version and state directly in DB.
    *
    * Strategy: ONLY direct DB writes, NO Plugin class methods (install/activate).
    *
    * Rationale:
    * - PHP still has the OLD setup.php in memory (include_once), so checkPluginState()
    *   and install() use stale code and trigger unpredictable state transitions.
    * - Plugin::install() queues flash messages and may call deactivate() internally,
    *   conflicting with our forced ACTIVATED state.
    * - By setting version + state=ACTIVATED directly, the next HTTP request boots
    *   with the NEW setup.php, sees matching version, and loads the plugin normally.
    * - DB migrations (plugin_nextool_install) are idempotent and will run on the
    *   next proper boot cycle if GLPI detects pending schema changes.
    */
   private function postUpdatePluginActivation(string $targetVersion): void {
      try {
         global $DB;

         $DB->update('glpi_plugins', [
            'version' => $targetVersion,
            'state'   => Plugin::ACTIVATED,
         ], [
            'directory' => 'nextool',
         ]);

         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdater] Post-update: DB atualizado — version=%s, state=ACTIVATED (%d)\n",
            $targetVersion,
            Plugin::ACTIVATED
         ));
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[WARN] [CoreUpdater] Falha na ativação pós-update: %s\n", $e->getMessage()
         ));
      }
   }

   /**
    * Re-read plugin state from DB and force ACTIVATED if it was overridden.
    * Mitigates a race condition where GLPI's own boot hooks detect the version
    * change between preUpdatePluginState() and postUpdatePluginActivation(),
    * setting state to NOTACTIVATED (4) before our DB write completes.
    */
   private function verifyAndForceActivated(): void {
      try {
         global $DB;

         $row = $DB->request([
            'SELECT' => ['state'],
            'FROM'   => 'glpi_plugins',
            'WHERE'  => ['directory' => 'nextool'],
            'LIMIT'  => 1,
         ])->current();

         $currentState = (int)($row['state'] ?? 0);
         if ($currentState !== Plugin::ACTIVATED) {
            $DB->update('glpi_plugins', [
               'state' => Plugin::ACTIVATED,
            ], [
               'directory' => 'nextool',
            ]);
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[WARN] [CoreUpdater] State divergiu para %d após postUpdate — re-forçado ACTIVATED.\n",
               $currentState
            ));
         }
      } catch (Throwable $e) {
         // Non-critical — the next page load will resolve via GLPI's own check
      }
   }

   private function resetOpcache(): void {
      if (function_exists('opcache_reset')) {
         @opcache_reset();
      }
      // Nota: NÃO enviar SIGUSR2 ao php-fpm aqui — mata o worker que está executando o apply.
      // opcache_reset() é suficiente para invalidar o cache da SAPI web.
   }

   private function getMaintenanceFlagPath(): string {
      return $this->getRuntimeRoot() . '/.maintenance';
   }

   private function getIntegrityManifestPath(): string {
      return $this->getStagingRoot() . '/integrity.json';
   }

   private function getBackupsRoot(): string {
      return $this->getRuntimeRoot() . '/backups';
   }

   private function getPluginInstallPath(): string {
      return rtrim($this->getPluginsDir(), '/') . '/nextool';
   }

   private function getWebProcessUser(): string {
      if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
         $info = posix_getpwuid(posix_geteuid());
         if (is_array($info) && !empty($info['name'])) {
            return $info['name'];
         }
      }
      return 'www-data';
   }

   private function isAlreadyStagedVersion(string $version): bool {
      $manifest = $this->readStagedManifest();
      if (!is_array($manifest)) {
         return false;
      }

      return isset($manifest['version']) && version_compare((string)$manifest['version'], $version, '==');
   }

   private function writeStagedManifest(array $manifest, string $source): void {
      $manifest['prepared_at'] = date('c');
      $manifest['prepared_source'] = $source;

      $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      if (!is_string($json)) {
         throw new RuntimeException(__('Falha ao serializar manifesto de staging.', 'nextool'));
      }

      $path = $this->getStagedManifestPath();
      $dir = dirname($path);
      if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
         throw new RuntimeException(__('Não foi possível criar diretório do manifesto de staging.', 'nextool'));
      }

      if (@file_put_contents($path, $json) === false) {
         throw new RuntimeException(__('Não foi possível salvar manifesto de staging.', 'nextool'));
      }
   }

   private function readStagedManifest(): ?array {
      $path = $this->getStagedManifestPath();
      if (!is_file($path)) {
         return null;
      }

      $json = @file_get_contents($path);
      if (!is_string($json) || trim($json) === '') {
         return null;
      }

      $decoded = json_decode($json, true);
      return is_array($decoded) ? $decoded : null;
   }

   private function resetStagingDirectory(): void {
      $root = $this->getStagingRoot();
      if (is_dir($root)) {
         PluginNextoolFileHelper::deleteDirectory($root, true);
      }

      if (!@mkdir($root, 0755, true) && !is_dir($root)) {
         throw new RuntimeException(__('Não foi possível criar diretório de staging do core.', 'nextool'));
      }
   }

   private function clearStagingArtifacts(): void {
      $root = $this->getStagingRoot();
      if (is_dir($root)) {
         PluginNextoolFileHelper::deleteDirectory($root, true);
      }
   }

   private function getInstalledCoreVersion(): string {
      if (class_exists('Plugin')) {
         $plugin = new Plugin();
         if ($plugin->getFromDBbyDir('nextool')) {
            $version = trim((string)($plugin->fields['version'] ?? ''));
            if ($version !== '') {
               return $version;
            }
         }
      }

      if (function_exists('plugin_version_nextool')) {
         $info = plugin_version_nextool();
         if (is_array($info) && !empty($info['version'])) {
            return (string)$info['version'];
         }
      }

      return '0.0.0';
   }

   private function getRuntimeRoot(): string {
      return rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update';
   }

   /**
    * Ensure runtime directories (staging, backups) exist.
    * Called at the start of prepare() so the preflight doesn't fail
    * with backup_dir_writable when directories were cleaned up externally.
    */
   private function ensureRuntimeDirs(): void {
      $dirs = [
         $this->getRuntimeRoot(),
         $this->getStagingRoot(),
         $this->getBackupsRoot(),
      ];
      foreach ($dirs as $dir) {
         if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
         }
      }
   }

   private function getStagingRoot(): string {
      return $this->getRuntimeRoot() . '/staging';
   }

   private function getStagedManifestPath(): string {
      return $this->getStagingRoot() . '/manifest.json';
   }

   private function getStagedPackagePath(): string {
      $root = $this->getStagingRoot();
      // Preferir .tar.gz (novo formato)
      $tarPath = $root . '/nextool-core.tar.gz';
      if (is_file($tarPath)) {
         return $tarPath;
      }
      // Fallback .zip (formato legado)
      return $root . '/nextool-core.zip';
   }

   private function getStagedExtractPath(): string {
      return $this->getStagingRoot() . '/extract';
   }

   /**
    * Path do plugin extraído no staging (files/_plugins/.../extract/nextool/).
    * Não é escaneado pelo plugin loader do GLPI — evita conflito de redeclaração.
    */
   private function getStagedPluginPath(): string {
      $extractRoot = $this->getStagedExtractPath();
      $candidate = $extractRoot . '/nextool';
      if (is_dir($candidate) && is_file($candidate . '/setup.php')) {
         return $candidate;
      }
      $entries = array_values(array_filter(scandir($extractRoot) ?: [], static fn($e) => $e !== '.' && $e !== '..'));
      if (count($entries) === 1) {
         $single = $extractRoot . '/' . $entries[0];
         if (is_dir($single) && is_file($single . '/setup.php')) {
            return $single;
         }
      }
      if (is_file($extractRoot . '/setup.php')) {
         return $extractRoot;
      }
      return $candidate;
   }

   private function getPluginsDir(): string {
      return defined('GLPI_PLUGIN_DIR') ? rtrim(GLPI_PLUGIN_DIR, '/') : (rtrim(GLPI_ROOT, '/') . '/plugins');
   }

   private function getLockFilePath(): string {
      $base = defined('GLPI_LOCK_DIR') ? GLPI_LOCK_DIR : (defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : (GLPI_ROOT . '/files/_lock'));
      return rtrim($base, '/') . '/nextool_core_update.lock';
   }

   private function appendCheck(array &$checks, array &$bucket, string $id, string $level, bool $ok, string $message, array $data = []): void {
      $entry = [
         'id' => $id,
         'level' => $level,
         'ok' => $ok,
         'message' => $message,
      ];
      if (!empty($data)) {
         $entry['data'] = $data;
      }
      $checks[] = $entry;

      if (!$ok) {
         $bucket[] = $entry;
      }
   }

   private function sanitizeManifestForOutput(array $manifest): array {
      $copy = $manifest;
      if (isset($copy['download_url'])) {
         $copy['download_url'] = (string)$copy['download_url'];
      }
      return $copy;
   }

   private function sanitizeChannel(string $channel): string {
      $clean = strtolower(trim($channel));
      if ($clean === '' || preg_match('/^[a-z0-9._-]+$/', $clean) !== 1) {
         return self::DEFAULT_CHANNEL;
      }
      return $clean;
   }

   private function isRecentTimestamp(?string $timestamp, int $maxAgeSeconds): bool {
      if ($timestamp === null || trim($timestamp) === '') {
         return false;
      }

      $ts = strtotime($timestamp);
      if ($ts === false) {
         return false;
      }

      return (time() - $ts) <= $maxAgeSeconds;
   }

   private function logAction(string $action, bool $status, array $context): void {
      PluginNextoolCoreUpdateLog::log([
         'action' => $action,
         'status' => $status ? 1 : 0,
         'source' => $context['source'] ?? null,
         'current_version' => $context['current_version'] ?? null,
         'target_version' => $context['target_version'] ?? null,
         'message' => $context['message'] ?? null,
         'duration_ms' => $context['duration_ms'] ?? null,
         'details' => $context['details'] ?? null,
      ]);
   }
}
