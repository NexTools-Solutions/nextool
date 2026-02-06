<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - ModuleManager
 * -------------------------------------------------------------------------
 * Gerenciador de módulos do NexTool Solutions.
 * Responsável por:
 * - Descobrir módulos disponíveis (com cache para melhor performance)
 * - Carregar módulos ativos
 * - Gerenciar instalação/desinstalação
 * - Ativar/desativar módulos
 * - Verificar dependências
 * 
 * Sistema de Cache:
 * - Cache armazena lista de módulos descobertos
 * - Cache é invalidado automaticamente quando arquivos mudam (filemtime)
 * - Cache expira após 1 hora (3600 segundos)
 * - Cache é limpo automaticamente ao instalar/desinstalar módulos
 * - Use clearCache() para limpar cache manualmente
 * - Use refreshModules() para forçar atualização do cache
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once GLPI_ROOT . '/plugins/nextool/inc/moduleaudit.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulecatalog.class.php';

class PluginNextoolModuleManager {

   /** @var PluginNextoolModuleManager Instância singleton */
   private static $instance = null;

   /** @var array Módulos descobertos */
   private $modules = [];

   /** @var array Módulos carregados e ativos */
   private $loadedModules = [];

   /** @var string Caminho para diretório de módulos */
   private $modulesPath;

   /** @var string Caminho para diretório de cache */
   private $cachePath;

   /** @var string Nome do arquivo de cache */
   private $cacheFile = 'nextool_modules.cache';

   /** @var int Tempo de expiração do cache em segundos (1 hora) */
   private $cacheExpiration = 3600;

   /** @var array<string, string[]> Mapeamento de tabelas utilizadas por cada módulo */
   private $moduleDataTables = [
      'helloworld' => [
         'glpi_plugin_nextool_helloworld',
      ],
      'aiassist' => [
         'glpi_plugin_nextool_aiassist_config',
         'glpi_plugin_nextool_aiassist_ticketdata',
         'glpi_plugin_nextool_aiassist_requests',
         'glpi_plugin_nextool_aiassist_quota',
         'glpi_plugin_nextool_aiassist_config_history',
      ],
      'autentique' => [
         'glpi_plugin_nextool_autentique_configs',
         'glpi_plugin_nextool_autentique_docs',
         'glpi_plugin_nextool_autentique_signers',
      ],
      'mailinteractions' => [
         'glpi_plugin_nextool_mailinteractions_tokens',
         'glpi_plugin_nextool_mailinteractions_configs',
         'glpi_plugin_nextool_mailinteractions_configs_logs',
         'glpi_plugin_nextool_mailinteractions_approvals',
         'glpi_plugin_nextool_mailinteractions_validations',
         'glpi_plugin_nextool_mailinteractions_satisfaction',
      ],
      'smartassign' => [
         'glpi_plugin_nextool_smartassign_assignments',
      ],
      'signaturepad' => [
         'glpi_plugin_nextool_signaturepad_signed_docs',
      ],
   ];

   /**
    * Construtor privado (padrão Singleton)
    */
   private function __construct() {
      // Nova estrutura: modules/[nome]/inc/[nome].class.php
      $this->modulesPath = GLPI_ROOT . '/plugins/nextool/modules';
      
      // Usa diretório de cache do GLPI se disponível, senão usa /tmp
      if (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR)) {
         $this->cachePath = GLPI_CACHE_DIR;
      } elseif (is_dir(GLPI_ROOT . '/files/_cache')) {
         $this->cachePath = GLPI_ROOT . '/files/_cache';
      } else {
         $this->cachePath = sys_get_temp_dir();
      }
      
      // Garante que diretório de cache existe
      if (!is_dir($this->cachePath)) {
         @mkdir($this->cachePath, 0755, true);
      }
   }

   /**
    * Obtém instância única do ModuleManager
    * 
    * @return PluginNextoolModuleManager
    */
   public static function getInstance() {
      if (self::$instance === null) {
         self::$instance = new self();
      }
      return self::$instance;
   }

   /**
    * Descobre todos os módulos disponíveis
    * Varre o diretório de módulos e carrega as classes
    * Usa cache para melhorar performance
    * 
    * @param bool $forceRefresh Força atualização do cache (ignora cache)
    * @return array Lista de módulos descobertos
    */
   public function discoverModules($forceRefresh = false) {
      // Se já está em memória e não forçado a recarregar, retorna
      if (!empty($this->modules) && !$forceRefresh) {
         return $this->modules;
      }

      // Tenta carregar do cache se não forçar atualização
      if (!$forceRefresh && $this->isCacheValid()) {
         $cachedModules = $this->loadCache();
         if ($cachedModules !== false) {
            $this->modules = $cachedModules;
            return $this->modules;
         }
      }

      // Descobre módulos do zero
      $this->modules = [];

      if (!is_dir($this->modulesPath)) {
         return $this->modules;
      }

      // PluginNextoolModuleCatalog::all() agora lê do banco (fonte única da verdade)
      // Fallback para bootstrap modules apenas na primeira instalação
      foreach (PluginNextoolModuleCatalog::all() as $moduleKey => $meta) {
          $dir = $this->modulesPath . '/' . $moduleKey;
          $classFile = $dir . '/inc/' . $moduleKey . '.class.php';

          if (!file_exists($classFile)) {
             continue;
          }

          require_once $classFile;
          $className = 'PluginNextool' . ucfirst($moduleKey);

          if (!class_exists($className)) {
             continue;
          }

          $module = new $className();
          if ($module instanceof PluginNextoolBaseModule) {
             $this->modules[$moduleKey] = $module;
          }
      }

      // Salva no cache
      $this->saveCache();

      return $this->modules;
   }

   /**
    * Carrega módulos ativos
    * Inicializa apenas os módulos que estão habilitados no banco
    * 
    * @return array Módulos carregados
    */
   public function loadActiveModules() {
      global $DB;

      $this->loadedModules = [];

      // Descobrir módulos disponíveis
      if (empty($this->modules)) {
         $this->discoverModules();
      }

      // Buscar módulos ativos no banco
      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['is_enabled' => 1]
      ]);

      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'];
         
         if (isset($this->modules[$moduleKey])) {
            $module = $this->modules[$moduleKey];
            
            // Verifica dependências
            if ($this->checkDependencies($module)) {
               // Inicializa módulo
               $module->onInit();
               $this->loadedModules[$moduleKey] = $module;
            }
         }
      }

      return $this->loadedModules;
   }

   /**
    * Obtém todos os módulos disponíveis (descobertos)
    * 
    * @return array Lista de módulos
    */
   public function getAllModules() {
      if (empty($this->modules)) {
         $this->discoverModules();
      }
      return $this->modules;
   }

   /**
    * Obtém módulos ativos
    * 
    * @return array Lista de módulos ativos
    */
   public function getActiveModules() {
      return $this->loadedModules;
   }

   /**
    * Obtém módulo específico pelo module_key
    * 
    * @param string $moduleKey Chave do módulo
    * @return PluginNextoolBaseModule|null
    */
   public function getModule($moduleKey) {
      if (empty($this->modules)) {
         $this->discoverModules();
      }
      return $this->modules[$moduleKey] ?? null;
   }

   /**
    * Instala um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function installModule($moduleKey) {
      global $DB;

      $module = $this->getModule($moduleKey);
      $action = 'install';
      $baseContext = [
         'origin'            => 'module_install',
         'requested_modules' => [$moduleKey],
      ];
      
      if (!$module) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não encontrado', $baseContext);
      }

      // Verifica se já está instalado
      if ($module->isInstalled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo já está instalado', $baseContext);
      }

      if (method_exists($module, 'requiresRemoteDownload') && $module->requiresRemoteDownload()) {
         return $this->buildModuleActionResult(
            $moduleKey,
            $action,
            false,
            __('Baixe o módulo antes de instalar usando o botão Download.', 'nextool'),
            $baseContext
         );
      }

      $licenseCheck = $this->validateLicenseForModule($moduleKey, [
         'force_refresh' => true,
         'origin'        => 'module_install',
      ]);

      if (!$licenseCheck['success']) {
         $this->logModuleAction($moduleKey, $action, array_merge(
            $baseContext,
            $this->extractLicenseAuditFields($licenseCheck['validation'] ?? null),
            [
               'result'  => false,
               'message' => $licenseCheck['message'] ?? 'Falha de licença',
            ]
         ));
         return $licenseCheck;
      }

      // Verifica pré-requisitos
      $prereq = $module->checkPrerequisites();
      if (!$prereq['success']) {
         return $this->buildModuleActionResult($moduleKey, $action, false, $prereq['message'], $baseContext);
      }

      // Verifica dependências
      if (!$this->checkDependencies($module)) {
         $deps = implode(', ', $module->getDependencies());
         return $this->buildModuleActionResult($moduleKey, $action, false, "Dependências não atendidas: {$deps}", $baseContext);
      }

      // Executa instalação do módulo
      if (!$module->install()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao executar instalação do módulo', $baseContext);
      }

      // Registra módulo no banco (marca como instalado) ou atualiza registro existente
      $existing = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1
      ]);

      if (count($existing)) {
         $row = $existing->current();
         $updateData = [
            'name'               => $module->getName(),
            'version'            => $module->getVersion(),
            'billing_tier'       => $this->getBillingTier($moduleKey),
            'is_installed'       => 1,
            // Não alteramos is_enabled aqui; ativação é responsabilidade do enableModule()
            'is_available' => isset($row['is_available']) ? $row['is_available'] : 0,
            'config'       => json_encode($module->getDefaultConfig()),
            'date_mod'     => date('Y-m-d H:i:s'),
         ];
         
         // Só atualiza available_version se ainda não existir (primeiro install)
         // Caso contrário, mantém a versão do catálogo oficial
         if (empty($row['available_version'])) {
            $updateData['available_version'] = $module->getVersion();
         }
         
         $result = $DB->update(
            'glpi_plugin_nextool_main_modules',
            $updateData,
            ['id' => $row['id']]
         );
      } else {
         $result = $DB->insert(
            'glpi_plugin_nextool_main_modules',
            [
               'module_key'    => $moduleKey,
               'name'          => $module->getName(),
               'version'       => $module->getVersion(),
               'available_version' => $module->getVersion(),
               'is_installed'  => 1,
               'billing_tier'  => $this->getBillingTier($moduleKey),
               'is_enabled'    => 0,
               'is_available'  => 0,
               'config'        => json_encode($module->getDefaultConfig()),
               'date_creation' => date('Y-m-d H:i:s')
            ]
         );
      }

      if ($result) {
         // Limpa cache para refletir mudanças
         $this->clearCache();
         $this->refreshModules();

         return $this->buildModuleActionResult(
            $moduleKey,
            $action,
            true,
            'Módulo instalado com sucesso',
            array_merge($baseContext, $this->extractLicenseAuditFields($licenseCheck['validation'] ?? null))
         );
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao registrar módulo no banco', $baseContext);
   }

   /**
    * Desinstala um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function uninstallModule($moduleKey) {
      global $DB;

      $module = $this->getModule($moduleKey);
      $action = 'uninstall';
      $baseContext = [
         'origin'            => 'module_uninstall',
         'requested_modules' => [$moduleKey],
      ];
      
      if (!$module) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não encontrado', $baseContext);
      }

      // Verifica se está instalado
      if (!$module->isInstalled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não está instalado', $baseContext);
      }

      // Desativa primeiro se estiver ativo
      if ($module->isEnabled()) {
         $this->disableModule($moduleKey);
      }

      // Executa desinstalação
      if (!$module->uninstall()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao executar desinstalação do módulo', $baseContext);
      }

      // Marca como não instalado (mantendo registro para fins de catálogo/licenciamento)
      $result = $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'is_installed' => 0,
            'is_enabled'   => 0,
            'date_mod'     => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );

      if ($result) {
         // Limpa cache para refletir mudanças
         $this->clearCache();
         $this->refreshModules();
         
         return $this->buildModuleActionResult($moduleKey, $action, true, 'Módulo desinstalado com sucesso', $baseContext);
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao remover módulo do banco', $baseContext);
   }

   /**
    * Ativa um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function enableModule($moduleKey) {
      global $DB;

      $module = $this->getModule($moduleKey);
      $action = 'enable';
      $baseContext = [
         'origin'            => 'module_enable',
         'requested_modules' => [$moduleKey],
      ];
      
      if (!$module) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não encontrado', $baseContext);
      }

      // Verifica se está instalado
      if (!$module->isInstalled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo precisa ser instalado primeiro', $baseContext);
      }

      // Verifica se já está ativo
      if ($module->isEnabled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo já está ativo', $baseContext);
      }

      $licenseCheck = $this->validateLicenseForModule($moduleKey, [
         'origin' => 'module_enable',
      ]);

      if (!$licenseCheck['success']) {
         $this->logModuleAction($moduleKey, $action, array_merge(
            $baseContext,
            $this->extractLicenseAuditFields($licenseCheck['validation'] ?? null),
            [
               'result'  => false,
               'message' => $licenseCheck['message'] ?? 'Falha de licença',
            ]
         ));
         return $licenseCheck;
      }

      // Verifica dependências
      if (!$this->checkDependencies($module)) {
         $deps = implode(', ', $module->getDependencies());
         return [
            'success' => false,
            'message' => "Dependências não atendidas: {$deps}"
         ];
      }

      // Ativa módulo
      $result = $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'is_enabled' => 1,
            'date_mod'   => date('Y-m-d H:i:s')
         ],
         ['module_key' => $moduleKey]
      );

      if ($result) {
         // Limpa cache de memória para forçar recarregamento
         // Cache de arquivo permanece (módulos não mudaram)
         $this->modules = [];

         $module->onEnable();
         
         return $this->buildModuleActionResult(
            $moduleKey,
            $action,
            true,
            'Módulo ativado com sucesso',
            array_merge($baseContext, $this->extractLicenseAuditFields($licenseCheck['validation'] ?? null))
         );
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao ativar módulo', $baseContext);
   }

   /**
    * Desativa um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function disableModule($moduleKey) {
      global $DB;

      $module = $this->getModule($moduleKey);
      $action = 'disable';
      $baseContext = [
         'origin'            => 'module_disable',
         'requested_modules' => [$moduleKey],
      ];
      
      if (!$module) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não encontrado', $baseContext);
      }

      // Verifica se está ativo
      if (!$module->isEnabled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo já está inativo', $baseContext);
      }

      // Desativa módulo
      $result = $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'is_enabled' => 0,
            'date_mod'   => date('Y-m-d H:i:s')
         ],
         ['module_key' => $moduleKey]
      );

      if ($result) {
         // Remove dos módulos carregados
         unset($this->loadedModules[$moduleKey]);
         
         // Limpa cache de memória para forçar recarregamento
         // Cache de arquivo permanece (módulos não mudaram)
         $this->modules = [];

         $module->onDisable();
         
         return $this->buildModuleActionResult($moduleKey, $action, true, 'Módulo desativado com sucesso', $baseContext);
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao desativar módulo', $baseContext);
   }

   /**
    * Verifica dependências de um módulo
    * 
    * @param PluginNextoolBaseModule $module Módulo a verificar
    * @return bool True se todas dependências estão atendidas
    */
   private function checkDependencies($module) {
      $dependencies = $module->getDependencies();
      
      if (empty($dependencies)) {
         return true;
      }

      foreach ($dependencies as $depKey) {
         $depModule = $this->getModule($depKey);
         
         // Dependência não existe
         if (!$depModule) {
            return false;
         }

         // Dependência não está instalada ou ativa
         if (!$depModule->isInstalled() || !$depModule->isEnabled()) {
            return false;
         }
      }

      return true;
   }

   /**
    * Monta contexto básico a partir de dados de licença
    *
    * @param array|null $validation
    * @return array
    */
   private function extractLicenseAuditFields($validation) {
      if (!is_array($validation)) {
         return [];
      }

      $fields = [];
      if (isset($validation['allowed_modules']) && is_array($validation['allowed_modules'])) {
         $fields['allowed_modules'] = $validation['allowed_modules'];
      }
      if (array_key_exists('contract_active', $validation)) {
         $fields['contract_active'] = $validation['contract_active'];
      }
      if (isset($validation['license_status'])) {
         $fields['license_status'] = $validation['license_status'];
      }
      if (isset($validation['plan'])) {
         $fields['plan'] = $validation['plan'];
      }
      return $fields;
   }

   /**
    * Grava auditoria de ação de módulo
    *
    * @param string $moduleKey
    * @param string $action
    * @param array  $options
    * @return void
    */
   private function logModuleAction($moduleKey, $action, array $options = []) {
      if (!class_exists('PluginNextoolModuleAudit')) {
         return;
      }

      $payload = array_merge([
         'module_key' => $moduleKey,
         'action'     => $action,
         'source_ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
      ], $options);

      PluginNextoolModuleAudit::log($payload);
   }

   /**
    * Helper central para construir resposta + log
    *
    * @param string $moduleKey
    * @param string $action
    * @param bool   $success
    * @param string $message
    * @param array  $context
    * @return array
    */
   private function buildModuleActionResult($moduleKey, $action, $success, $message, array $context = []) {
      $this->logModuleAction($moduleKey, $action, array_merge($context, [
         'result'  => $success ? 1 : 0,
         'message' => $message,
      ]));

      return [
         'success' => $success,
         'message' => $message,
      ];
   }

   public function downloadRemoteModule($moduleKey) {
      $action = 'download';
      $result = $this->downloadModuleFromDistribution($moduleKey);

      return $this->buildModuleActionResult(
         $moduleKey,
         $action,
         $result['success'],
         $result['message'],
         ['origin' => 'remote_distribution']
      );
   }

   private function downloadModuleFromDistribution(string $moduleKey): array {
      $settings = PluginNextoolConfig::getDistributionSettings();
      $baseUrl  = trim($settings['base_url'] ?? '');
      $clientIdentifier = trim($settings['client_identifier'] ?? '');
      $clientSecret = trim($settings['client_secret'] ?? '');

      if ($baseUrl === '' || $clientIdentifier === '' || $clientSecret === '') {
         return [
            'success' => false,
            'message' => __('Integração de distribuição não configurada.', 'nextool')
         ];
      }

      try {
         $client = new PluginNextoolDistributionClient($baseUrl, $clientIdentifier, $clientSecret);
         $result = $client->downloadModule($moduleKey);
      } catch (Exception $e) {
         Toolbox::logInFile('plugin_nextool', sprintf('Falha ao baixar módulo %s: %s', $moduleKey, $e->getMessage()));
         return [
            'success' => false,
            'message' => sprintf(__('Falha ao baixar módulo remoto: %s', 'nextool'), $e->getMessage()),
         ];
      }

      $details = sprintf('Módulo %s v%s baixado do ContainerAPI.', $moduleKey, $result['version'] ?? 'unknown');
      Toolbox::logInFile('plugin_nextool', $details);
      $this->discoverModules(true);
      $this->syncAvailableVersion($moduleKey, $result['version'] ?? null);

      return [
         'success' => true,
         'message' => $details,
         'version' => $result['version'] ?? null,
      ];
   }

   public function updateModule($moduleKey) {
      global $DB;

      $action = 'update';
      $baseContext = [
         'origin'            => 'module_update',
         'requested_modules' => [$moduleKey],
      ];

      $row = $this->getModuleRow($moduleKey);
      if ($row === null || !(bool)($row['is_installed'] ?? 0)) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo precisa estar instalado para atualizar.', $baseContext);
      }

      $module = $this->getModule($moduleKey);
      if ($module === null) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Módulo não encontrado no diretório local.', $baseContext);
      }

      $currentVersion = $row['version'] ?? null;
      $availableVersion = $row['available_version'] ?? null;
      $localVersion = $module->getVersion();
      if ($localVersion !== null && $availableVersion !== null && version_compare($localVersion, $availableVersion, '>=')) {
         $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'version'           => $localVersion,
               'available_version' => $localVersion,
               'date_mod'          => date('Y-m-d H:i:s'),
            ],
            ['module_key' => $moduleKey]
         );
         $this->clearCache();
         $this->refreshModules();
         return $this->buildModuleActionResult($moduleKey, $action, true, 'Versão local já é a mais recente. Sincronização concluída.', $baseContext);
      }

      $download = $this->downloadModuleFromDistribution($moduleKey);
      if (!$download['success']) {
         return $this->buildModuleActionResult($moduleKey, $action, false, $download['message'], $baseContext);
      }

      $this->discoverModules(true);
      $module = $this->getModule($moduleKey);
      if ($module === null) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Não foi possível carregar o módulo após o download.', $baseContext);
      }

      $downloadedVersion = $download['version'] ?? null;
      $targetVersion = $module->getVersion();
      if (
         $downloadedVersion !== null
         && $downloadedVersion !== ''
         && ($targetVersion === null || $targetVersion === '' || version_compare($downloadedVersion, $targetVersion, '>'))
      ) {
         $targetVersion = $downloadedVersion;
      }
      if ($targetVersion !== null && $currentVersion !== null && version_compare($targetVersion, $currentVersion, '<=')) {
         $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'version'           => $currentVersion,
               'available_version' => $currentVersion,
               'date_mod'          => date('Y-m-d H:i:s'),
            ],
            ['module_key' => $moduleKey]
         );
         $this->clearCache();
         $this->refreshModules();
         return $this->buildModuleActionResult($moduleKey, $action, true, 'Módulo já está na versão mais recente. Versão sincronizada.', $baseContext);
      }

      $upgradeOk = $module->upgrade($currentVersion, $targetVersion);
      if (!$upgradeOk) {
         return $this->buildModuleActionResult($moduleKey, $action, false, 'Falha ao aplicar rotinas de upgrade do módulo.', $baseContext);
      }

      $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'version'            => $targetVersion,
            'available_version'  => $downloadedVersion ?: $targetVersion,
            'date_mod'           => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );

      $this->clearCache();
      $this->refreshModules();

      return $this->buildModuleActionResult($moduleKey, $action, true, 'Módulo atualizado com sucesso.', array_merge($baseContext, [
         'from_version' => $currentVersion,
         'to_version'   => $targetVersion,
      ]));
   }

   private function getModuleRow(string $moduleKey): ?array {
      global $DB;

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return null;
      }

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1,
      ]);

      if (count($iterator)) {
         return $iterator->current();
      }

      return null;
   }

   private function syncAvailableVersion(string $moduleKey, ?string $version): void {
      global $DB;

      if ($version === null || !$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return;
      }

      $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'available_version' => $version,
            'date_mod'          => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );
   }

   public function moduleHasData(string $moduleKey): bool {
      global $DB;

      foreach ($this->getModuleDataTables($moduleKey) as $table) {
         if ($DB->tableExists($table)) {
            return true;
         }
      }

      return false;
   }

   public function purgeModuleData(string $moduleKey): array {
      $action = 'purge_data';
      $module = $this->getModule($moduleKey);
      $customPurgeSuccess = false;
      $directoryRemoved = false;
      $message = '';

      if ($module !== null && $this->moduleDirectoryExists($moduleKey)) {
         try {
            $customPurgeSuccess = (bool) $module->purgeData();
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', sprintf('Falha ao purgar dados do módulo %s: %s', $moduleKey, $e->getMessage()));
            $customPurgeSuccess = false;
         }
      }

      if ($this->moduleDirectoryExists($moduleKey)) {
         try {
            $directoryRemoved = $this->deleteModuleDirectory($moduleKey);
         } catch (RuntimeException $e) {
            Toolbox::logInFile('plugin_nextool', sprintf('Falha ao remover diretório do módulo %s: %s', $moduleKey, $e->getMessage()));
            $directoryRemoved = false;
         }
      }

      $tablesDropped = $this->dropTablesForModule($moduleKey);
      $success = $customPurgeSuccess || $tablesDropped || $directoryRemoved;

      if ($success) {
         if ($directoryRemoved && !$tablesDropped && !$customPurgeSuccess) {
            $message = __('Arquivos do módulo removidos com sucesso.', 'nextool');
         } else {
            $message = __('Dados do módulo removidos com sucesso.', 'nextool');
         }
      } else {
         $message = __('Não há dados para remover ou ocorreu uma falha.', 'nextool');
      }

      if ($directoryRemoved) {
         $this->clearCache();
         $this->refreshModules();
      }

      return $this->buildModuleActionResult($moduleKey, $action, $success, $message, ['origin' => 'module_data_management']);
   }

   public function getModuleDataTables(string $moduleKey): array {
      return $this->moduleDataTables[$moduleKey] ?? [];
   }

   private function moduleDirectoryExists(string $moduleKey): bool {
      $path = GLPI_ROOT . '/plugins/nextool/modules/' . $moduleKey;
      return is_dir($path);
   }

   private function deleteModuleDirectory(string $moduleKey): bool {
      $dir = GLPI_ROOT . '/plugins/nextool/modules/' . $moduleKey;
      if (!is_dir($dir)) {
         return false;
      }

      $items = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($items as $item) {
         if ($item->isDir()) {
            if (!@rmdir($item->getRealPath())) {
               throw new RuntimeException(sprintf(
                  __('Falha ao remover diretório %s. Verifique permissões.', 'nextool'),
                  $item->getRealPath()
               ));
            }
         } else {
            if (!@unlink($item->getRealPath())) {
               throw new RuntimeException(sprintf(
                  __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
                  $item->getRealPath()
               ));
            }
         }
      }

      if (!@rmdir($dir)) {
         throw new RuntimeException(sprintf(
            __('Falha ao limpar diretório %s. Verifique permissões.', 'nextool'),
            $dir
         ));
      }

      return true;
   }

   private function dropTablesForModule(string $moduleKey): bool {
      global $DB;

      $tables = $this->getModuleDataTables($moduleKey);
      if (empty($tables)) {
         return false;
      }

      $droppedAny = false;

      foreach ($tables as $table) {
         if (!$DB->tableExists($table)) {
            continue;
         }

         $sql = "DROP TABLE IF EXISTS `$table`";

         // GLPI 11: queries diretas devem usar doQuery() ao invés de query/queryOrDie()
         if (!$DB->doQuery($sql)) {
            Toolbox::logInFile(
               'plugin_nextool',
               sprintf(
                  'Falha ao remover tabela de dados do módulo %s (%s): %s',
                  $moduleKey,
                  $table,
                  method_exists($DB, 'error') ? $DB->error() : 'erro desconhecido'
               )
            );
            continue;
         }

         $droppedAny = true;
      }

      return $droppedAny;
   }

   /**
    * Instala todos os módulos disponíveis
    * 
    * @deprecated Não usado atualmente. Mantido para uso futuro/scripts.
    * @note A instalação do plugin usa loop manual em hook.php::plugin_nextool_install()
    *       para ter controle granular de erros por módulo.
    * @see plugin_nextool_install() em hook.php para implementação manual
    * 
    * @return array Resultado das instalações
    */
   public function installAllModules() {
      $results = [];
      
      foreach ($this->getAllModules() as $moduleKey => $module) {
         if (!$module->isInstalled()) {
            $results[$moduleKey] = $this->installModule($moduleKey);
         }
      }
      
      return $results;
   }

   /**
    * Desinstala todos os módulos
    * 
    * @deprecated Não usado atualmente. Mantido para uso futuro/scripts.
    * @note A desinstalação do plugin usa loop manual em hook.php::plugin_nextool_uninstall()
    *       para ter controle granular de erros por módulo com try/catch individual.
    * @see plugin_nextool_uninstall() em hook.php (linhas 78-94) para implementação manual
    * 
    * @return array Resultado das desinstalações
    */
   public function uninstallAllModules() {
      $results = [];
      
      foreach ($this->getAllModules() as $moduleKey => $module) {
         if ($module->isInstalled()) {
            $results[$moduleKey] = $this->uninstallModule($moduleKey);
         }
      }
      
      return $results;
   }

   /**
    * Valida se a licença atual permite instalar/ativar um módulo.
    *
    * @param string $moduleKey
    * @param array  $options
    * @return array
    */
   private function validateLicenseForModule($moduleKey, array $options = []) {
      if (!class_exists('PluginNextoolLicenseValidator')) {
         return [
            'success' => true
         ];
      }

      $origin = $options['origin'] ?? 'module_validation';
      $forceRefresh = !empty($options['force_refresh']);

      if (!$forceRefresh && in_array($origin, ['module_install', 'module_enable'], true)) {
         $forceRefresh = true;
      }

      $validation = PluginNextoolLicenseValidator::validateLicense([
         'force_refresh' => $forceRefresh,
         'context'       => [
            'requested_modules' => [$moduleKey],
            'origin'            => $origin,
         ],
      ]);

      $plan         = isset($validation['plan']) ? strtoupper((string)$validation['plan']) : null;
      $isFreeTier   = ($plan === 'FREE');
      $billingTier  = $this->getBillingTier($moduleKey);
      $isFreeModule = ($billingTier === 'FREE');

      // Módulos FREE devem sempre ser permitidos, independentemente do plano
      // Se o módulo é FREE, bypass completo (não valida licença/contrato/allowed_modules)
      if ($isFreeModule) {
         return [
            'success'    => true,
            'validation' => $validation,
         ];
      }

      // Para módulos PAID, validar licença e contrato
      if (isset($validation['contract_active']) && $validation['contract_active'] === false) {
         $msg = isset($validation['message']) && $validation['message'] !== ''
            ? $validation['message']
            : 'Contrato inativo para esta licença';

         return [
            'success' => false,
            'message' => 'Licença / contrato inválido: ' . $msg,
            'validation' => $validation,
         ];
      }

      if (empty($validation['valid'])) {
         $msg = isset($validation['message']) && $validation['message'] !== ''
            ? $validation['message']
            : 'Licença inválida ou não autorizada';

         return [
            'success' => false,
            'message' => 'Licença inválida: ' . $msg,
            'validation' => $validation,
         ];
      }

      // Verificar allowed_modules apenas para módulos PAID
      $allowedModules = [];
      if (isset($validation['allowed_modules']) && is_array($validation['allowed_modules'])) {
         $allowedModules = $validation['allowed_modules'];
      }

      $hasWildcardAll = !empty($allowedModules) && in_array('*', $allowedModules, true);

      if (
         !$hasWildcardAll
         && !empty($allowedModules)
         && !in_array($moduleKey, $allowedModules, true)
      ) {
         return [
            'success' => false,
            'message' => 'Módulo não permitido nesta licença',
            'validation' => $validation,
         ];
      }

      return [
         'success'    => true,
         'validation' => $validation,
      ];
   }

   /**
    * Força o modo FREE para módulos pagos já instalados/ativos.
    *
    * Cenário de uso principal:
    * - Após validação de licença que retorna inválida, cancelada ou com
    *   contrato inativo, todos os módulos PAID devem ser desinstalados
    *   logicamente (desativados e marcados como não instalados), mantendo
    *   apenas os dados para eventual recontratação futura.
    *
    * Importante:
    * - NÃO chama uninstall() dos módulos para evitar rotinas destrutivas;
    *   apenas ajusta flags na tabela glpi_plugin_nextool_main_modules.
    *
    * @return void
    */
   public function enforceFreeTierForPaidModules(): void {
      global $DB;

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return;
      }

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => [],
      ]);

      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'] ?? null;
         if ($moduleKey === null || $moduleKey === '') {
            continue;
         }

         // Determina o billing_tier efetivo (linha do banco com fallback
         // para o comportamento legado/getBillingTier()).
         $rowTier = isset($row['billing_tier']) && $row['billing_tier'] !== ''
            ? strtoupper((string)$row['billing_tier'])
            : null;
         $billingTier = $rowTier ?? $this->getBillingTier($moduleKey);

         if ($billingTier === 'FREE') {
            // Módulos FREE permanecem disponíveis mesmo em modo FREE tier.
            continue;
         }

         $isInstalled = (int)($row['is_installed'] ?? 0) === 1;
         $isEnabled   = (int)($row['is_enabled'] ?? 0) === 1;

         if (!$isInstalled && !$isEnabled) {
            // Nada para fazer se já está completamente desativado/desinstalado.
            continue;
         }

         // Marca módulo pago como não instalado e desativado,
         // preservando dados em banco.
         $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'is_installed' => 0,
               'is_enabled'   => 0,
               'date_mod'     => date('Y-m-d H:i:s'),
            ],
            ['id' => $row['id']]
         );

         // Garante que não será carregado nesta requisição.
         unset($this->loadedModules[$moduleKey]);

         // Registra auditoria da mudança automática, se possível.
         $this->logModuleAction($moduleKey, 'auto_disable_free_tier', [
            'origin'            => 'license_free_tier',
            'requested_modules' => [$moduleKey],
            'result'            => 1,
            'message'           => 'Módulo pago desativado automaticamente por ambiente em modo FREE/contrato inativo.',
         ]);
      }

      // Limpa cache de disco/memória para refletir novo estado.
      $this->clearCache();
   }

   /**
    * Obtém estatísticas dos módulos
    * 
    * @return array Estatísticas
    */
   public function getStats() {
      $total = count($this->modules);
      $installed = 0;
      $enabled = 0;

      foreach ($this->modules as $module) {
         if ($module->isInstalled()) {
            $installed++;
            if ($module->isEnabled()) {
               $enabled++;
            }
         }
      }

      return [
         'total'     => $total,
         'installed' => $installed,
         'enabled'   => $enabled,
         'disabled'  => $installed - $enabled
      ];
   }

   /**
    * Obtém o billing tier (FREE/PAID/...) de um módulo a partir da tabela
    * glpi_plugin_nextool_main_modules.billing_tier, com fallback para o
    * comportamento legado (helloworld/aiassist como FREE).
    *
    * @param string $moduleKey
    * @return string 'FREE' ou outro valor (por padrão, 'PAID')
    */
   public function getBillingTier($moduleKey) {
      global $DB;

      // Tenta ler do banco primeiro, se a tabela estiver disponível
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_nextool_main_modules',
            'WHERE' => ['module_key' => $moduleKey],
            'LIMIT' => 1
         ]);

         foreach ($iterator as $row) {
            if (isset($row['billing_tier']) && $row['billing_tier'] !== null && $row['billing_tier'] !== '') {
               return strtoupper((string)$row['billing_tier']);
            }
         }
      }

      // Verifica se o módulo declarou explicitamente um tier
      $moduleInstance = $this->getModule($moduleKey);
      if ($moduleInstance && method_exists($moduleInstance, 'getBillingTier')) {
         $declaredTier = strtoupper((string)$moduleInstance->getBillingTier());
         if ($declaredTier !== '') {
            return $declaredTier;
         }
      }

      // Fallback legado: antes da coluna billing_tier, os módulos helloworld e aiassist
      // eram considerados sempre FREE e os demais tratados como pagos/licenciados.
      $legacyFreeModules = ['helloworld', 'aiassist'];
      if (in_array($moduleKey, $legacyFreeModules, true)) {
         return 'FREE';
      }

      return 'FREE';
   }

   /**
    * Verifica se um módulo está marcado como disponível na lista de módulos.
    *
    * @param string $moduleKey
    * @return bool
    */
   public function isModuleAvailable($moduleKey) {
      global $DB;

      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_nextool_main_modules',
            'WHERE' => ['module_key' => $moduleKey],
            'LIMIT' => 1,
         ]);

         foreach ($iterator as $row) {
            if (isset($row['is_available'])) {
               return ((int)$row['is_available'] === 1);
            }
         }
      }

      // Se não houver registro, considera disponível por padrão
      return true;
   }

   /**
    * Obtém chave de cache baseada em filemtime dos arquivos de módulos
    * 
    * @return string Chave de cache
    */
   private function getCacheKey() {
      if (!is_dir($this->modulesPath)) {
         return '';
      }

      $directories = glob($this->modulesPath . '/*', GLOB_ONLYDIR);
      $filetimes = [];

      foreach ($directories as $dir) {
         $moduleName = basename($dir);
         
         // Nova estrutura: modules/[nome]/inc/[nome].class.php
         $classFile = $dir . '/inc/' . $moduleName . '.class.php';
         
         if (file_exists($classFile)) {
            $filetimes[] = $classFile . ':' . filemtime($classFile);
         }
      }

      // Ordena para garantir consistência
      sort($filetimes);
      
      return md5(implode('|', $filetimes));
   }

   /**
    * Verifica se cache é válido
    * 
    * @return bool True se cache é válido
    */
   private function isCacheValid() {
      $cacheFilePath = $this->cachePath . '/' . $this->cacheFile;
      
      // Se arquivo de cache não existe, cache não é válido
      if (!file_exists($cacheFilePath)) {
         return false;
      }

      // Verifica se cache expirou
      $cacheAge = time() - filemtime($cacheFilePath);
      if ($cacheAge > $this->cacheExpiration) {
         return false;
      }

      // Verifica se chave de cache mudou (arquivos foram modificados)
      $cachedKey = $this->getCacheKeyFromFile($cacheFilePath);
      $currentKey = $this->getCacheKey();
      
      if ($cachedKey !== $currentKey) {
         return false;
      }

      return true;
   }

   /**
    * Obtém chave de cache do arquivo
    * 
    * @param string $cacheFilePath Caminho do arquivo de cache
    * @return string Chave de cache
    */
   private function getCacheKeyFromFile($cacheFilePath) {
      $cacheData = @file_get_contents($cacheFilePath);
      if ($cacheData === false) {
         return '';
      }

      $data = @unserialize($cacheData);
      if ($data === false || !isset($data['key'])) {
         return '';
      }

      return $data['key'];
   }

   /**
    * Carrega módulos do cache
    * 
    * @return array|false Módulos em cache ou false se falhar
    */
   private function loadCache() {
      $cacheFilePath = $this->cachePath . '/' . $this->cacheFile;
      
      if (!file_exists($cacheFilePath)) {
         return false;
      }

      $cacheData = @file_get_contents($cacheFilePath);
      if ($cacheData === false) {
         return false;
      }

      $data = @unserialize($cacheData);
      if ($data === false || !isset($data['modules'])) {
         return false;
      }

      // Verifica se módulos são válidos
      if (!is_array($data['modules'])) {
         return false;
      }

      // Carrega classes necessárias usando lista do cache
      // Cache armazena módulos como: ['module_key' => 'nome_diretorio']
      $reloadedModules = [];
      
      foreach ($data['modules'] as $moduleKey => $moduleInfo) {
         // Obtém nome do diretório (se armazenado) ou tenta descobrir pelo module_key
         $moduleDirName = $moduleInfo['dir'] ?? $moduleKey;
         
         // Nova estrutura: modules/[nome]/inc/[nome].class.php
         $classFile = $this->modulesPath . '/' . $moduleDirName . '/inc/' . $moduleDirName . '.class.php';
         
         // Verifica se arquivo existe (validação rápida)
         if (!file_exists($classFile)) {
            // Cache inválido - arquivo não existe mais
            return false;
         }
         
         // Carrega classe
         require_once $classFile;
         
         $className = 'PluginNextool' . ucfirst($moduleDirName);
         if (!class_exists($className)) {
            // Cache inválido - classe não existe
            return false;
         }
         
         // Instancia módulo
         $module = new $className();
         
         if (!($module instanceof PluginNextoolBaseModule)) {
            // Cache inválido - módulo não é instância de BaseModule
            return false;
         }
         
         // Verifica se module_key corresponde
         if ($module->getModuleKey() !== $moduleKey) {
            // Cache inválido - module_key não corresponde
            return false;
         }
         
         $reloadedModules[$moduleKey] = $module;
      }

      return $reloadedModules;
   }

   /**
    * Salva módulos no cache
    * 
    * @return bool True se salvou com sucesso
    */
   private function saveCache() {
      $cacheFilePath = $this->cachePath . '/' . $this->cacheFile;
      
      // Prepara dados para cache (armazena apenas metadados, não instâncias)
      $cacheData = [
         'key'     => $this->getCacheKey(),
         'time'    => time(),
         'modules' => []
      ];

      foreach ($this->modules as $moduleKey => $module) {
         // Armazena module_key e nome do diretório para recarregamento rápido
         // Obtém nome do diretório a partir do caminho da classe
         $reflection = new ReflectionClass($module);
         $classFile = $reflection->getFileName();
         $moduleDirName = basename(dirname($classFile));
         
         $cacheData['modules'][$moduleKey] = [
            'key' => $moduleKey,
            'dir' => $moduleDirName
         ];
      }

      // Salva no arquivo
      $result = @file_put_contents($cacheFilePath, serialize($cacheData), LOCK_EX);
      
      return $result !== false;
   }

   /**
    * Limpa cache de módulos
    * Útil quando módulos são adicionados/removidos manualmente
    * 
    * @return bool True se limpou com sucesso
    */
   public function clearCache() {
      $cacheFilePath = $this->cachePath . '/' . $this->cacheFile;
      
      if (file_exists($cacheFilePath)) {
         return @unlink($cacheFilePath);
      }

      // Limpa cache da memória também
      $this->modules = [];
      
      return true;
   }

   /**
    * Força atualização do cache
    * Limpa cache e redescobre módulos
    * 
    * @return array Módulos descobertos
    */
   public function refreshModules() {
      $this->clearCache();
      return $this->discoverModules(true);
   }
}


