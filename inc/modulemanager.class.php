<?php
declare(strict_types=1);
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
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/moduleaudit.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulecatalog.class.php';

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

   /** @var string[] Modulos em modo legado (sem module.json) */
   private $legacyModules = [];

   /** @var array<string, string> Modulos bloqueados por manifesto + motivo */
   private $blockedModules = [];

   /**
    * Cache local de tabelas descobertas via BaseModule::getDataTables().
    * Populado sob demanda por getModuleDataTables().
    * @var array<string, string[]>
    */
   private $moduleDataTablesCache = [];

   /**
    * Cache per-request das linhas de glpi_plugin_nextool_main_modules.
    * Evita queries repetidas em isInstalled/isEnabled/getBillingTier no mesmo request.
    * Invalidado em clearCache() e em rotas que mutam o estado de módulos.
    * @var array<string, ?array>
    */
   private array $moduleRowCache = [];

   /**
    * Indica se o moduleRowCache foi pré-carregado em bulk via preloadModuleRowCache().
    * Quando true, getModuleRow() pode responder null para keys ausentes sem ir ao banco.
    */
   private bool $rowMapPreloaded = false;

   /**
    * Construtor privado (padrão Singleton)
    */
   private function __construct() {
      require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
      // Nova estrutura: modules em GLPI_PLUGIN_DOC_DIR/nextool/modules (files/_plugins/nextool/modules)
      $this->modulesPath = NEXTOOL_MODULES_BASE;
      
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
            // Mantém o mapa stateless sempre em dia, mesmo quando o cache de
            // módulos é usado. Isso evita ficar “preso” com um nextool_stateless.json
            // antigo (ou com ownership/permissão incorreta) e quebrar webhooks.
            $this->refreshStatelessCache();
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

          // Guard de compatibilidade: valida module.json antes de carregar PHP
          $manifestCheck = $this->validateModuleManifest($moduleKey, $dir);
          if ($manifestCheck['status'] === 'blocked') {
             $this->blockedModules[$moduleKey] = $manifestCheck['message'];
             continue;
          }
          if ($manifestCheck['status'] === 'legacy') {
             $this->legacyModules[] = $moduleKey;
          }

          // Guard de path-resolution: bloqueia módulos com referência hardcoded a /plugins/nextool
          // quando o plugin nextool vive em marketplace/ (path hardcoded resolve para inexistente
          // e derruba o boot). Mensagem orienta o usuário a atualizar o módulo.
          if ($this->moduleHasHardcodedPluginPath($dir)) {
             $this->blockedModules[$moduleKey] = __(
                'Módulo desatualizado: contém referência hardcoded a /plugins/nextool. '
                . 'Atualize o módulo para a versão que utiliza NEXTOOL_PHP_DIR '
                . '(introduzido em NexTool 4.1.3).',
                'nextool'
             );
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

      // Sincronizar banco.version com disco quando divergente (módulos instalados).
      // Evita botão "Atualizar" visível em estado coerente onde o disco já está na
      // versão alvo mas o banco ficou desatualizado (redeploy, FTP, install manual).
      $this->syncInstalledVersionsFromDisk();

      // Salva no cache
      $this->saveCache();

      // Atualiza cache de módulos stateless (usado no boot antes do GLPI carregar)
      $this->refreshStatelessCache();

      return $this->modules;
   }

   /**
    * Carrega módulos ativos
    * Inicializa apenas os módulos que estão habilitados no banco
    * 
    * @return array Módulos carregados
    */
   public function loadActiveModules() {
      $this->loadedModules = [];

      // Descobrir módulos disponíveis
      if (empty($this->modules)) {
         $this->discoverModules();
      }

      // Pré-carrega cache em bulk -- mesma query que seria necessária para listar
      // módulos ativos, mas serve também para isInstalled/isEnabled em cascata
      // (hook redefine_menus, profile, etc.) sem novas queries no mesmo request.
      $this->preloadModuleRowCache();

      foreach ($this->moduleRowCache as $moduleKey => $row) {
         if ($row === null) {
            continue;
         }
         if (((int)($row['is_enabled'] ?? 0)) !== 1) {
            continue;
         }
         if (!isset($this->modules[$moduleKey])) {
            continue;
         }

         $module = $this->modules[$moduleKey];
         if ($this->checkDependencies($module)) {
            $module->loadModuleLang();
            $module->onInit();
            $this->loadedModules[$moduleKey] = $module;
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
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo não encontrado', 'nextool'), $baseContext);
      }

      // Verifica se já está instalado
      if ($module->isInstalled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo já está instalado', 'nextool'), $baseContext);
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

      // Verifica pré-requisitos
      $prereq = $module->checkPrerequisites();
      if (!$prereq['success']) {
         return $this->buildModuleActionResult($moduleKey, $action, false, $prereq['message'], $baseContext);
      }

      // Verifica dependências
      if (!$this->checkDependencies($module)) {
         $deps = implode(', ', $module->getDependencies());
         return $this->buildModuleActionResult($moduleKey, $action, false, sprintf(__('Dependências não atendidas: %s', 'nextool'), $deps), $baseContext);
      }

      // Executa instalação do módulo
      if (!$module->install()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao executar instalação do módulo', 'nextool'), $baseContext);
      }

      // Registra módulo no banco (marca como instalado) ou atualiza registro existente
      $existing = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1
      ]);

      $row = $existing->current();
      if ($row !== null) {
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
            __('Módulo instalado com sucesso', 'nextool'),
            $baseContext
         );
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao registrar módulo no banco', 'nextool'), $baseContext);
   }

   /**
    * Desinstala um módulo.
    * REGRA: NUNCA remove dados nem tabelas; apenas desativa e marca is_installed=0.
    * Para apagar dados/tabelas o usuário deve acionar "Apagar dados" (purgeModuleData).
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
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo não encontrado', 'nextool'), $baseContext);
      }

      // Verifica se está instalado
      if (!$module->isInstalled()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo não está instalado', 'nextool'), $baseContext);
      }

      // Desativa primeiro se estiver ativo
      if ($module->isEnabled()) {
         $this->disableModule($moduleKey);
      }

      // Executa desinstalação do módulo (apenas desregistra; NÃO executa uninstall.sql nem DROP TABLE)
      if (!$module->uninstall()) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao executar desinstalação do módulo', 'nextool'), $baseContext);
      }

      // Remove o direito único do módulo da matriz de perfil (senão fica órfão quando o
      // módulo sai). Os dados/tabelas do módulo permanecem no banco (design de uninstall);
      // só a permissão é removida. Reinstalar recria o direito via syncModuleRights.
      PluginNextoolPermissionManager::removeModuleRight($moduleKey);

      // Marca como não instalado; dados e tabelas do módulo permanecem no banco
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
         
         return $this->buildModuleActionResult($moduleKey, $action, true, __('Módulo desinstalado com sucesso', 'nextool'), $baseContext);
      }

      return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao remover módulo do banco', 'nextool'), $baseContext);
   }

   /**
    * Ativa um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function enableModule($moduleKey) {
      return $this->setEnabledState($moduleKey, 'enable');
   }

   /**
    * Desativa um módulo
    *
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function disableModule($moduleKey) {
      return $this->setEnabledState($moduleKey, 'disable');
   }

   /**
    * Helper central para enable/disable (ME-15 do audit-deep).
    * Unifica: getModule + pre-flight + license check + DB update + cleanup de cache +
    * cron disable (no path de disable) + hook onEnable/onDisable + audit.
    * Corrige bug pre-existente: early-return de "dependências não atendidas" não chamava
    * buildModuleActionResult, pulava audit.
    *
    * @param string $moduleKey Chave do módulo
    * @param 'enable'|'disable' $action
    * @return array ['success' => bool, 'message' => string]
    */
   private function setEnabledState(string $moduleKey, string $action): array {
      global $DB;

      $enabled = $action === 'enable';
      $module = $this->getModule($moduleKey);
      $baseContext = [
         'origin'            => $enabled ? 'module_enable' : 'module_disable',
         'requested_modules' => [$moduleKey],
      ];

      if (!$module) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Módulo não encontrado', 'nextool'), $baseContext);
      }

      // Pre-flight específico de enable/disable
      if ($enabled) {
         if (!$module->isInstalled()) {
            return $this->buildModuleActionResult($moduleKey, $action, false,
               __('Módulo precisa ser instalado primeiro', 'nextool'), $baseContext);
         }
         if ($module->isEnabled()) {
            return $this->buildModuleActionResult($moduleKey, $action, false,
               __('Módulo já está ativo', 'nextool'), $baseContext);
         }
         if (!$this->checkDependencies($module)) {
            $deps = implode(', ', $module->getDependencies());
            return $this->buildModuleActionResult($moduleKey, $action, false,
               sprintf(__('Dependências não atendidas: %s', 'nextool'), $deps),
               $baseContext);
         }
      } else {
         if (!$module->isEnabled()) {
            return $this->buildModuleActionResult($moduleKey, $action, false,
               __('Módulo já está inativo', 'nextool'), $baseContext);
         }
      }

      // Licença para módulos pagos (mesma check em ambos os caminhos)
      $billingTier = $this->getBillingTier($moduleKey);
      if ($billingTier !== 'FREE' && !$this->hasLicenseForModule($moduleKey)) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Este módulo requer uma licença. Nenhuma licença encontrada que inclua este módulo.', 'nextool'),
            $baseContext);
      }

      // DB update
      $result = $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'is_enabled' => $enabled ? 1 : 0,
            'date_mod'   => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );

      if (!$result) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            $enabled ? __('Falha ao ativar módulo', 'nextool')
                     : __('Falha ao desativar módulo', 'nextool'),
            $baseContext);
      }

      // Cleanup de cache (comum a enable e disable)
      if (!$enabled) {
         unset($this->loadedModules[$moduleKey]);
      }
      $this->modules = [];
      $this->moduleRowCache = [];
      if (class_exists('PluginNextoolMainConfig')) {
         PluginNextoolMainConfig::clearModuleConfigTabsCache();
      }

      // Disable de cron tasks só no path de disable (evita "função indefinida" em cron)
      if (!$enabled) {
         $cronIterator = $DB->request([
            'FROM'  => 'glpi_crontasks',
            'WHERE' => ['itemtype' => ['LIKE', 'PluginNextool' . ucfirst($moduleKey) . '%']]
         ]);
         foreach ($cronIterator as $cronRow) {
            $DB->update('glpi_crontasks', ['state' => 0], ['id' => $cronRow['id']]);
         }
      }

      // Hook do módulo
      if ($enabled) {
         $module->onEnable();
      } else {
         $module->onDisable();
      }

      return $this->buildModuleActionResult($moduleKey, $action, true,
         $enabled ? __('Módulo ativado com sucesso', 'nextool')
                  : __('Módulo desativado com sucesso', 'nextool'),
         $baseContext);
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
    * Valida module.json para compatibilidade de versao GLPI.
    *
    * Fase 1:
    * - module.json presente + glpi_major errado -> blocked
    * - module.json presente + correto -> ok
    * - module.json ausente -> legacy (warning, ainda carrega)
    *
    * @param string $moduleKey
    * @param string $moduleDir Caminho fisico do diretorio do modulo
    * @return array{status: string, message: string|null}
    */
   private function validateModuleManifest(string $moduleKey, string $moduleDir): array {
      $manifestPath = $moduleDir . '/module.json';

      if (!file_exists($manifestPath)) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[ModuleManifest] LEGACY %s: module.json ausente. Atualize o modulo.',
            $moduleKey
         ));
         return ['status' => 'legacy', 'message' => null];
      }

      $content = @file_get_contents($manifestPath);
      if ($content === false) {
         return ['status' => 'blocked', 'message' => 'Falha ao ler module.json'];
      }

      $manifest = json_decode($content, true);
      if (!is_array($manifest)) {
         return ['status' => 'blocked', 'message' => 'module.json invalido (JSON malformado)'];
      }

      $currentGlpiMajor = (int) explode('.', GLPI_VERSION)[0];

      if (isset($manifest['glpi_major'])) {
         if ((int) $manifest['glpi_major'] !== $currentGlpiMajor) {
            $msg = sprintf(
               'Modulo construido para GLPI %d, mas este ambiente e GLPI %d',
               (int) $manifest['glpi_major'],
               $currentGlpiMajor
            );
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[ModuleManifest] BLOCKED %s: %s',
               $moduleKey,
               $msg
            ));
            return ['status' => 'blocked', 'message' => $msg];
         }
      }

      if (isset($manifest['min_plugin_version']) && defined('PLUGIN_NEXTOOL_VERSION')) {
         if (version_compare(PLUGIN_NEXTOOL_VERSION, $manifest['min_plugin_version'], '<')) {
            $msg = sprintf(
               'Requer NexTool >= %s, versao atual %s',
               $manifest['min_plugin_version'],
               PLUGIN_NEXTOOL_VERSION
            );
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[ModuleManifest] BLOCKED %s: %s',
               $moduleKey,
               $msg
            ));
            return ['status' => 'blocked', 'message' => $msg];
         }
      }

      if (isset($manifest['module_key']) && $manifest['module_key'] !== $moduleKey) {
         $msg = sprintf(
            'module_key no manifesto (%s) difere do esperado (%s)',
            $manifest['module_key'],
            $moduleKey
         );
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[ModuleManifest] BLOCKED %s: %s',
            $moduleKey,
            $msg
         ));
         return ['status' => 'blocked', 'message' => $msg];
      }

      return ['status' => 'ok', 'message' => null];
   }

   /**
    * Detecta se um módulo contém referência hardcoded a /plugins/nextool.
    *
    * Quando o plugin nextool vive em marketplace/, o pattern hardcoded
    * "GLPI_ROOT . '/plugins/nextool" resolve para path inexistente e
    * derruba o boot via require_once. Esta verificação é feita ANTES
    * do require_once em discoverModules, permitindo bloquear o módulo
    * com mensagem clara em vez de gerar fatal error.
    *
    * Otimização: só roda quando o plugin nextool está em marketplace/
    * (em plugins/ o pattern hardcoded é equivalente a NEXTOOL_PHP_DIR
    * e não causa problema). Lê apenas o início de cada arquivo PHP
    * (4KB) -- suficiente para pegar requires no topo.
    *
    * @param string $moduleDir Diretório do módulo (NEXTOOL_MODULES_BASE/<key>)
    * @return bool true se contém hardcoded e deve ser bloqueado
    */
   private function moduleHasHardcodedPluginPath(string $moduleDir): bool {
      // Só relevante se o próprio plugin nextool está em marketplace/
      if (!defined('NEXTOOL_PHP_DIR') || strpos(NEXTOOL_PHP_DIR, '/marketplace/') === false) {
         return false;
      }

      $needle = "GLPI_ROOT . '/plugins/nextool";
      foreach (['inc', 'ajax', 'front'] as $sub) {
         $subDir = $moduleDir . '/' . $sub;
         if (!is_dir($subDir)) {
            continue;
         }
         foreach (glob($subDir . '/*.php') ?: [] as $phpFile) {
            $head = @file_get_contents($phpFile, false, null, 0, 4096);
            if ($head !== false && strpos($head, $needle) !== false) {
               return true;
            }
         }
      }
      return false;
   }

   /**
    * Retorna modulos em modo legado (sem module.json).
    * @return string[]
    */
   public function getLegacyModules(): array {
      return $this->legacyModules;
   }

   /**
    * Retorna modulos bloqueados por incompatibilidade de manifesto.
    * @return array<string, string> module_key => motivo
    */
   public function getBlockedModules(): array {
      return $this->blockedModules;
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
      $baseContext = ['origin' => 'remote_distribution'];

      // Em modo FREE ou SUSPENDED, não permitir download de módulos pagos.
      $billingTier = null;
      if (class_exists('PluginNextoolLicenseConfig')) {
         $config = PluginNextoolLicenseConfig::getDefaultConfig();
         $plan = isset($config['plan']) && $config['plan'] !== '' && $config['plan'] !== null
            ? strtoupper(trim((string)$config['plan']))
            : 'FREE';
         $licenseStatus = strtoupper(trim((string)($config['license_status'] ?? '')));

         if ($this->isFreePlan($plan)) {
            $billingTier = $this->getBillingTier($moduleKey);
            if ($billingTier !== 'FREE') {
               return $this->buildModuleActionResult(
                  $moduleKey,
                  $action,
                  false,
                  __('No modo FREE não é possível baixar novos módulos pagos. Os módulos já instalados continuam utilizáveis; atualizações e novos downloads ficam indisponíveis até vincular uma licença.', 'nextool'),
                  $baseContext
               );
            }
         }

         if ($licenseStatus === 'SUSPENDED') {
            $billingTier = $billingTier ?? $this->getBillingTier($moduleKey);
            if ($billingTier !== 'FREE') {
               return $this->buildModuleActionResult(
                  $moduleKey,
                  $action,
                  false,
                  __('Licença suspensa: download de módulos pagos bloqueado.', 'nextool'),
                  $baseContext
               );
            }
         }
      }

      $result = $this->downloadModuleFromDistribution($moduleKey);

      return $this->buildModuleActionResult(
         $moduleKey,
         $action,
         $result['success'],
         $result['message'],
         $baseContext
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
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf('Falha ao baixar módulo %s: %s', $moduleKey, $e->getMessage()));
         return [
            'success' => false,
            'message' => sprintf(__('Falha ao baixar módulo remoto: %s', 'nextool'), $e->getMessage()),
         ];
      }

      $details = sprintf(__('Módulo %s v%s baixado do ContainerAPI.', 'nextool'), $moduleKey, $result['version'] ?? 'unknown');
      Toolbox::logInFile('plugin_nextool', $details);
      $this->discoverModules(true);
      $this->syncAvailableVersion($moduleKey, $result['version'] ?? null);

      return [
         'success' => true,
         'message' => $details,
         'version' => $result['version'] ?? null,
      ];
   }

   /**
    * Substitui arquivos do modulo no disco sem tocar no banco de dados.
    * Baixa versao fresca do ContainerAPI (validacao completa: HMAC, licenca, entitlement).
    * Tabelas e dados do modulo sao preservados.
    *
    * @param string $moduleKey
    * @return array{success: bool, message: string}
    */
   public function redownloadModule(string $moduleKey): array {
      global $DB;

      $action = 'redownload';
      $baseContext = [
         'origin'            => 'module_redownload',
         'requested_modules' => [$moduleKey],
      ];

      $row = $this->getModuleRow($moduleKey);
      if ($row === null) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Módulo não encontrado no catálogo.', 'nextool'), $baseContext);
      }

      $wasEnabled = (bool) ($row['is_enabled'] ?? 0);

      // Desativar temporariamente se habilitado (evita onInit durante substituicao)
      if ($wasEnabled) {
         $module = $this->getModule($moduleKey);
         if ($module !== null) {
            $module->onDisable();
         }
      }

      // Remover arquivos do disco
      if ($this->moduleDirectoryExists($moduleKey)) {
         $this->deleteModuleDirectory($moduleKey);
      }

      // Baixar do ContainerAPI (validacao completa)
      $download = $this->downloadModuleFromDistribution($moduleKey);
      if (!$download['success']) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            $download['message'], $baseContext);
      }

      // Redescobrir modulos com manifestos frescos
      $this->blockedModules = [];
      $this->legacyModules = [];
      $this->discoverModules(true);

      $newModule = $this->getModule($moduleKey);
      if ($newModule !== null) {
         $newVersion = $newModule->getVersion();
         // discoverModules(true) acima já roda o guard de schema-drift (migra+marca).
         // Ainda assim, honramos "nunca bumpar sem upgrade bem-sucedido": rodamos a
         // migração idempotente antes do bump explícito. Só bumpa se convergir.
         if ($this->runModuleUpgradeSafely($newModule, $row['version'] ?? null, $newVersion, $moduleKey)) {
            $DB->update(
               'glpi_plugin_nextool_main_modules',
               [
                  'version'  => $newVersion,
                  'date_mod' => date('Y-m-d H:i:s'),
               ],
               ['module_key' => $moduleKey]
            );
            $this->writeSchemaMarker($moduleKey, (string) $newVersion);
         } else {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[ModuleManager] redownloadModule: upgrade de %s falhou pós-download; version NÃO bumpada\n",
               $moduleKey
            ));
         }

         if ($wasEnabled) {
            $newModule->onEnable();
         }
      }

      $this->clearCache();

      return $this->buildModuleActionResult($moduleKey, $action, true,
         __('Arquivos do módulo substituídos com sucesso. Dados do banco preservados.', 'nextool'),
         $baseContext);
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
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo precisa estar instalado para atualizar.', 'nextool'), $baseContext);
      }

      $module = $this->getModule($moduleKey);
      if ($module === null) {
         // Modulo bloqueado por manifesto: permitir download para corrigir compatibilidade
         if (isset($this->blockedModules[$moduleKey])) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[ModuleManifest] Tentando atualizar modulo bloqueado %s (%s)',
               $moduleKey,
               $this->blockedModules[$moduleKey]
            ));
            $download = $this->downloadModuleFromDistribution($moduleKey);
            if (!$download['success']) {
               return $this->buildModuleActionResult($moduleKey, $action, false, $download['message'], $baseContext);
            }
            // Redescobrir modulos para reavaliar manifesto
            $this->blockedModules = [];
            $this->legacyModules = [];
            $this->discoverModules(true);
            $module = $this->getModule($moduleKey);
            if ($module === null) {
               return $this->buildModuleActionResult($moduleKey, $action, false,
                  __('Módulo continua incompatível após download. Verifique a versão disponível.', 'nextool'),
                  $baseContext
               );
            }
            // Módulo estava BLOQUEADO -> não entrava em $this->modules, então o
            // guard de schema-drift do discoverModules o ignorava. Aqui rodamos a
            // migração idempotente explicitamente ANTES de bumpar (regra "nunca
            // bumpar sem upgrade bem-sucedido").
            $unblockedVersion = $module->getVersion();
            if (!$this->runModuleUpgradeSafely($module, $row['version'] ?? null, $unblockedVersion, $moduleKey)) {
               return $this->buildModuleActionResult($moduleKey, $action, false,
                  __('Falha ao aplicar rotinas de upgrade do módulo.', 'nextool'), $baseContext);
            }
            $DB->update(
               'glpi_plugin_nextool_main_modules',
               [
                  'version'           => $unblockedVersion,
                  'available_version' => $download['version'] ?? $unblockedVersion,
                  'date_mod'          => date('Y-m-d H:i:s'),
               ],
               ['module_key' => $moduleKey]
            );
            $this->writeSchemaMarker($moduleKey, (string) $unblockedVersion);
            $this->clearCache();
            return $this->buildModuleActionResult($moduleKey, $action, true,
               __('Módulo atualizado com sucesso (compatibilidade restaurada).', 'nextool'),
               array_merge($baseContext, [
                  'from_version' => $row['version'] ?? 'unknown',
                  'to_version'   => $module->getVersion(),
               ])
            );
         }
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Módulo não encontrado no diretório local.', 'nextool'), $baseContext);
      }

      // Em modo FREE ou SUSPENDED, não permitir atualização de módulos pagos.
      $billingTier = null;
      if (class_exists('PluginNextoolLicenseConfig')) {
         $config = PluginNextoolLicenseConfig::getDefaultConfig();
         $plan = isset($config['plan']) && $config['plan'] !== '' && $config['plan'] !== null
            ? strtoupper(trim((string)$config['plan']))
            : 'FREE';
         $licenseStatus = strtoupper(trim((string)($config['license_status'] ?? '')));

         if ($this->isFreePlan($plan)) {
            $billingTier = $this->getBillingTier($moduleKey);
            if ($billingTier !== 'FREE') {
               return $this->buildModuleActionResult(
                  $moduleKey,
                  $action,
                  false,
                  __('No modo FREE não há acesso a atualizações de módulos pagos. Os módulos já instalados continuam utilizáveis.', 'nextool'),
                  $baseContext
               );
            }
         }

         if ($licenseStatus === 'SUSPENDED') {
            $billingTier = $billingTier ?? $this->getBillingTier($moduleKey);
            if ($billingTier !== 'FREE') {
               return $this->buildModuleActionResult(
                  $moduleKey,
                  $action,
                  false,
                  __('Licença suspensa: atualização de módulos pagos bloqueada.', 'nextool'),
                  $baseContext
               );
            }
         }
      }

      // Para atualizar (baixar + aplicar upgrade), sempre validar com force_refresh.
      // Isso garante que um módulo não seja atualizado quando o contrato/status
      // mudou recentemente no ContainerAPI.
      $licenseCheck = $this->validateLicenseForModule($moduleKey, [
         'force_refresh' => true,
         'origin'        => 'module_update',
      ]);
      if (!$licenseCheck['success']) {
         $this->logModuleAction($moduleKey, $action, array_merge(
            $baseContext,
            $this->extractLicenseAuditFields($licenseCheck['validation'] ?? null),
            [
               'result'  => 0,
               'message' => $licenseCheck['message'] ?? __('Falha de licença', 'nextool'),
            ]
         ));
         return $licenseCheck;
      }

      $currentVersion = $row['version'] ?? null;
      $availableVersion = $row['available_version'] ?? null;
      $localVersion = $module->getVersion();
      if ($localVersion !== null && $availableVersion !== null && version_compare($localVersion, $availableVersion, '>=')) {
         // Bump "sincroniza pro disco" sem download. Se o banco estava ATRÁS do
         // disco, precisamos migrar ANTES de registrar a versão local -- senão
         // repetimos a brecha do drift silencioso. upgrade() é idempotente.
         if (
            $currentVersion !== null && $currentVersion !== ''
            && version_compare($localVersion, $currentVersion, '>')
            && !$this->runModuleUpgradeSafely($module, $currentVersion, $localVersion, $moduleKey)
         ) {
            return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao aplicar rotinas de upgrade do módulo.', 'nextool'), $baseContext);
         }
         $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'version'           => $localVersion,
               'available_version' => $localVersion,
               'date_mod'          => date('Y-m-d H:i:s'),
            ],
            ['module_key' => $moduleKey]
         );
         $this->writeSchemaMarker($moduleKey, $localVersion);
         $this->clearCache();
         $this->refreshModules();
         return $this->buildModuleActionResult($moduleKey, $action, true, __('Versão local já é a mais recente. Sincronização concluída.', 'nextool'), $baseContext);
      }

      $download = $this->downloadModuleFromDistribution($moduleKey);
      if (!$download['success']) {
         return $this->buildModuleActionResult($moduleKey, $action, false, $download['message'], $baseContext);
      }

      $this->discoverModules(true);
      $module = $this->getModule($moduleKey);
      if ($module === null) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Não foi possível carregar o módulo após o download.', 'nextool'), $baseContext);
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
         return $this->buildModuleActionResult($moduleKey, $action, true, __('Módulo já está na versão mais recente. Versão sincronizada.', 'nextool'), $baseContext);
      }

      $upgradeOk = $module->upgrade($currentVersion, $targetVersion);
      if (!$upgradeOk) {
         return $this->buildModuleActionResult($moduleKey, $action, false, __('Falha ao aplicar rotinas de upgrade do módulo.', 'nextool'), $baseContext);
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
      $this->writeSchemaMarker($moduleKey, (string) $targetVersion);

      $this->clearCache();
      $this->refreshModules();

      return $this->buildModuleActionResult($moduleKey, $action, true, __('Módulo atualizado com sucesso.', 'nextool'), array_merge($baseContext, [
         'from_version' => $currentVersion,
         'to_version'   => $targetVersion,
      ]));
   }

   private function getModuleRow(string $moduleKey): ?array {
      global $DB;

      if (array_key_exists($moduleKey, $this->moduleRowCache)) {
         return $this->moduleRowCache[$moduleKey];
      }

      // Fast-path: se o map foi pré-carregado em bulk e a key não está cacheada,
      // significa que o módulo não existe no banco -- responde sem nova query.
      if ($this->rowMapPreloaded) {
         return null;
      }

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return null;
      }

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1,
      ]);

      $row = count($iterator) ? $iterator->current() : null;
      $this->moduleRowCache[$moduleKey] = $row;
      return $row;
   }

   /**
    * Pré-carrega TODAS as linhas de glpi_plugin_nextool_main_modules em uma única query.
    * Após esta chamada, getModuleRow() responde inteiramente da memória --
    * útil em hot-paths que vão consultar muitos módulos (redefine_menus, profile).
    */
   private function preloadModuleRowCache(): void {
      global $DB;

      if ($this->rowMapPreloaded) {
         return;
      }

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         $this->rowMapPreloaded = true;
         return;
      }

      $iterator = $DB->request([
         'FROM' => 'glpi_plugin_nextool_main_modules',
      ]);

      foreach ($iterator as $row) {
         $key = $row['module_key'] ?? '';
         if ($key !== '') {
            $this->moduleRowCache[$key] = $row;
         }
      }

      $this->rowMapPreloaded = true;
   }

   /**
    * Retorna o estado (installed/enabled) de TODOS os módulos em uma única consulta.
    * Os callers que iteram sobre muitos módulos (menu, profile, hook redefine_menus)
    * devem usar este map em vez de chamar isInstalled()/isEnabled() em loop.
    *
    * @return array<string, array{installed: bool, enabled: bool}>
    */
   public function getModulesStateMap(): array {
      $this->preloadModuleRowCache();

      $map = [];
      foreach ($this->moduleRowCache as $key => $row) {
         if ($row === null) {
            continue;
         }
         $map[$key] = [
            'installed' => ((int)($row['is_installed'] ?? 0)) === 1,
            'enabled'   => ((int)($row['is_enabled'] ?? 0)) === 1,
         ];
      }
      return $map;
   }

   public function getBillingTier(string $moduleKey): string {
      // Prioridade 1: banco (sincronizado do ContainerAPI) - fonte de verdade
      $row = $this->getModuleRow($moduleKey);
      if ($row !== null && isset($row['billing_tier']) && $row['billing_tier'] !== '') {
         return strtoupper(trim((string)$row['billing_tier']));
      }

      // Prioridade 2: instancia em memoria (fallback de bootstrap para modulos nao sincronizados)
      if (isset($this->modules[$moduleKey]) && $this->modules[$moduleKey] instanceof PluginNextoolBaseModule) {
         return strtoupper(trim($this->modules[$moduleKey]->getBillingTier()));
      }

      return 'FREE';
   }

   public function getModulePath(string $moduleKey): ?string {
      if (!preg_match('/^[a-z0-9_]+$/i', $moduleKey)) {
         return null;
      }
      $path = $this->modulesPath . '/' . $moduleKey;
      return is_dir($path) ? $path : null;
   }

   public function isEnabled(string $moduleKey): bool {
      $row = $this->getModuleRow($moduleKey);
      return $row !== null && ((int)($row['is_enabled'] ?? 0) === 1);
   }

   public function isInstalled(string $moduleKey): bool {
      $row = $this->getModuleRow($moduleKey);
      return $row !== null && ((int)($row['is_installed'] ?? 0) === 1);
   }

   private function isFreePlan(string $plan): bool {
      $upper = strtoupper(trim($plan));
      return $upper === 'FREE';
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

   /**
    * Caminho do marker de "schema aplicado" de um módulo numa versão.
    *
    * A PRESENÇA do marker significa que as migrações idempotentes do módulo
    * (upgrade()/runMigrations) já foram confirmadas com sucesso para AQUELA
    * versão de disco. Fica FORA do nextool_modules.cache: o clearCache() só
    * apaga o cache de módulos, então toggles (install/enable/update) NÃO forçam
    * re-migração desnecessária. Um purge manual do diretório de cache apenas
    * dispara uma reverificação idempotente no próximo boot (feature, não bug).
    */
   private function schemaMarkerPath(string $moduleKey, string $version): string {
      $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $moduleKey . '_' . $version);
      return $this->cachePath . '/nextool_modschema_' . $safe;
   }

   private function hasSchemaMarker(string $moduleKey, string $version): bool {
      return is_file($this->schemaMarkerPath($moduleKey, $version));
   }

   private function writeSchemaMarker(string $moduleKey, string $version): void {
      @file_put_contents($this->schemaMarkerPath($moduleKey, $version), date('c'), LOCK_EX);
   }

   /**
    * Roda o upgrade() idempotente do módulo capturando exceções.
    *
    * Contrato: retorna true SÓ se a migração convergiu. Quem chama NUNCA deve
    * bumpar version se este método retornar false -- é a regra "nunca bumpar sem
    * upgrade bem-sucedido" que fecha a classe do drift silencioso.
    *
    * @return bool true se upgrade() convergiu (não lançou e não retornou false)
    */
   private function runModuleUpgradeSafely(
      PluginNextoolBaseModule $module,
      ?string $from,
      ?string $to,
      string $moduleKey
   ): bool {
      try {
         return $module->upgrade($from, $to) !== false;
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ModuleManager] upgrade idempotente de %s (%s -> %s) FALHOU: %s\n",
            $moduleKey, $from ?? 'null', $to ?? 'null', $e->getMessage()
         ));
         return false;
      }
   }

   /**
    * Sincroniza banco.version com $module->getVersion() (disco) para módulos instalados,
    * garantindo CONVERGÊNCIA DE SCHEMA (guard de schema-drift).
    *
    * Disco é a fonte de verdade do que está realmente carregado pelo plugin. Quando
    * o registro do banco fica defasado (redeploy, FTP, install manual fora do UI), o
    * UI mostra "Atualizar" e o backend rejeita por já estar na versão alvo. Esta
    * sincronização lazy evita essa incoerência.
    *
    * GUARD DE SCHEMA-DRIFT (2026-07): antes, quando banco.version == disco.version
    * este método dava 'continue' cego. Se algum caminho não-guardado tivesse bumpado
    * a version SEM rodar a migração (reinstall sobre schema antigo, redownload/blocked
    * update com bump direto, FTP, SQL manual, restore), o schema ficava atrás da versão
    * registrada e este guard DORMIA para sempre -- drift permanente e silencioso: a
    * version "mente" sobre o schema, a UI não salva (Unknown column) e a feature quebra.
    * Foi o incidente Angeloni (mercadoeletronico version=0.3.0, schema=0.2.0, faltando
    * client_request_prefix). Agora, mesmo com version igual, rodamos a migração
    * idempotente do módulo uma vez por versão de disco (gated por marker de arquivo):
    * custo em regime = 1 is_file por módulo/boot; a migração só executa quando o marker
    * está ausente (primeiro boot pós-deploy da versão, ou após purge de cache), e
    * AUTO-CURA ambientes já dessincronizados no próximo boot.
    */
   private function syncInstalledVersionsFromDisk(): void {
      global $DB;

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return;
      }

      // Evita N+1: 1 query bulk e leitura do cache, em vez de 1 SELECT por modulo.
      // Antes, este metodo (chamado em TODO boot) fazia ~1 query por modulo (~42/boot,
      // 71% das queries do request). preloadModuleRowCache() e idempotente; getModuleRow()
      // passa a responder da memoria (achado de perf 2026-06-30).
      $this->preloadModuleRowCache();

      foreach ($this->modules as $moduleKey => $module) {
         $diskVersion = $module->getVersion();
         if ($diskVersion === null || $diskVersion === '') {
            continue;
         }

         $row = $this->getModuleRow($moduleKey);
         if ($row === null || (int) ($row['is_installed'] ?? 0) !== 1) {
            continue;
         }

         $dbVersion = $row['version'] ?? null;

         // ----------------------------------------------------------------
         // CAMINHO A: banco JÁ em dia com o disco (version igual).
         // Não basta dar 'continue': o schema pode ter ficado atrás (bump sem
         // migração por caminho não-guardado, FTP, SQL manual, restore). Roda
         // a migração idempotente do módulo UMA VEZ por versão de disco, gated
         // por marker de arquivo -> regime permanente custa 1 is_file/módulo.
         // ----------------------------------------------------------------
         if ($dbVersion === $diskVersion) {
            if ($this->hasSchemaMarker($moduleKey, $diskVersion)) {
               continue; // schema já confirmado para esta versão: fast-path
            }
            // A classe de drift possível aqui é ESPECÍFICA: colunas/índices
            // incrementais adicionados por runMigrations() cuja migração nunca
            // rodou (bump sem upgrade, FTP, SQL manual). Rodamos SÓ runMigrations()
            // -- não o upgrade()/install() inteiro: install() re-semeia singletons
            // (INSERT IGNORE) e re-registra cron, gerando ruído e custo à toa. Um
            // módulo SEM runMigrations() tem o schema todo no install.sql (criado no
            // install), então não há drift incremental a curar: só marca.
            $healed = true;
            if (method_exists($module, 'runMigrations')) {
               try {
                  $module->runMigrations();
                  Toolbox::logInFile('plugin_nextool', sprintf(
                     "[ModuleManager] schema-drift guard: %s v%s reconciliado (runMigrations idempotente aplicado)\n",
                     $moduleKey, $diskVersion
                  ));
               } catch (\Throwable $e) {
                  $healed = false;
                  Toolbox::logInFile('plugin_nextool', sprintf(
                     "[ModuleManager] schema-drift guard: %s v%s NÃO reconciliado (runMigrations falhou: %s) -- schema pode estar ATRÁS da versão registrada\n",
                     $moduleKey, $diskVersion, $e->getMessage()
                  ));
               }
            }
            if ($healed) {
               $this->writeSchemaMarker($moduleKey, $diskVersion);
            }
            continue;
         }

         // ----------------------------------------------------------------
         // CAMINHO B: disco DIVERGE do banco.
         // Se disco > banco (deploy runtime-first, FTP, redeploy): migrar ANTES
         // de registrar a nova versão. Sem isso, apenas bumpar "mente" o estado:
         // o schema novo (colunas/tabelas de runMigrations()/upgrade.sql) nunca é
         // aplicado -> 500 'Unknown column' em runtime. upgrade() é idempotente
         // (addColumnIfNotExists, CREATE IF NOT EXISTS).
         // ----------------------------------------------------------------
         $isUpgrade = ($dbVersion !== null && $dbVersion !== '')
            ? version_compare($diskVersion, $dbVersion, '>')
            : true; // banco sem versão registrada: tratar como sincronização inicial
         if ($isUpgrade && !$this->runModuleUpgradeSafely($module, $dbVersion, $diskVersion, $moduleKey)) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[ModuleManager] syncInstalledVersionsFromDisk: upgrade(%s: %s -> %s) FALHOU, versão NÃO sincronizada\n",
               $moduleKey, $dbVersion ?? 'null', $diskVersion
            ));
            continue; // mantém divergente para nova tentativa no próximo request
         }

         $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'version'  => $diskVersion,
               'date_mod' => date('Y-m-d H:i:s'),
            ],
            ['id' => (int)$row['id']]
         );
         // Só marca schema-aplicado quando houve migração (upgrade). Numa sync
         // "para trás" (disco mais ANTIGO que o banco) não rodamos upgrade: não
         // gravamos marker para não mascarar uma eventual necessidade de heal.
         if ($isUpgrade) {
            $this->writeSchemaMarker($moduleKey, $diskVersion);
         }
         if ($dbVersion !== null && $dbVersion !== '' && $dbVersion !== $diskVersion) {
            @unlink($this->schemaMarkerPath($moduleKey, $dbVersion)); // higiene do marker antigo
         }

         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ModuleManager] syncInstalledVersionsFromDisk: %s banco=%s -> disco=%s%s\n",
            $moduleKey,
            $dbVersion ?? 'null',
            $diskVersion,
            $isUpgrade ? ' (migração aplicada)' : ''
         ));
      }
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

      $messageType = null;
      if ($success) {
         if ($directoryRemoved && !$tablesDropped && !$customPurgeSuccess) {
            $message = __('Arquivos do módulo removidos com sucesso.', 'nextool');
         } elseif ($this->moduleDirectoryExists($moduleKey)) {
            $message = __('Dados MySQL removidos, porém o diretório do módulo não pôde ser excluído. Verifique permissões em files/_plugins/nextool/modules/ e remova manualmente se necessário.', 'nextool');
            $messageType = WARNING;
         } else {
            $message = __('Dados do módulo removidos com sucesso.', 'nextool');
         }
      } else {
         $message = __('Não há dados para remover ou ocorreu uma falha.', 'nextool');
      }

      if ($success) {
         $this->clearCache();
         $this->refreshModules();
      }

      $result = $this->buildModuleActionResult($moduleKey, $action, $success, $message, ['origin' => 'module_data_management']);
      if ($messageType !== null) {
         $result['message_type'] = $messageType;
      }
      return $result;
   }

   public function getModuleDataTables(string $moduleKey): array {
      if (isset($this->moduleDataTablesCache[$moduleKey])) {
         return $this->moduleDataTablesCache[$moduleKey];
      }

      $module = $this->getModule($moduleKey);
      if ($module && method_exists($module, 'getDataTables')) {
         $tables = $module->getDataTables();
         if (!empty($tables)) {
            $this->moduleDataTablesCache[$moduleKey] = $tables;
            return $tables;
         }
      }

      // Fallback: convenção de nome - tabelas com prefixo glpi_plugin_nextool_{moduleKey}_.
      // Permite limpar corretamente módulos que não sobrescreveram getDataTables() (senão suas
      // tabelas ficariam órfãs no purge/uninstall). O '_' é wildcard no LIKE de listTables(),
      // então o prefixo fixo e a moduleKey são escapados; o sufixo '_' literal evita casar
      // chaves que sejam prefixo de outra (ex.: "mail" não captura "mailanalyzer").
      global $DB;
      $tables = [];
      if (isset($DB) && $moduleKey !== '') {
         $likeModule = str_replace(['_', '%'], ['\\_', '\\%'], $moduleKey);
         $pattern = 'glpi\\_plugin\\_nextool\\_' . $likeModule . '\\_%';
         foreach ($DB->listTables($pattern) as $row) {
            if (!empty($row['TABLE_NAME'])) {
               $tables[] = $row['TABLE_NAME'];
            }
         }
      }
      $this->moduleDataTablesCache[$moduleKey] = $tables;
      return $tables;
   }

   private function moduleDirectoryExists(string $moduleKey): bool {
      $path = $this->modulesPath . '/' . $moduleKey;
      return is_dir($path);
   }

   private function deleteModuleDirectory(string $moduleKey): bool {
      $dir = $this->modulesPath . '/' . $moduleKey;
      if (!is_dir($dir)) {
         return false;
      }

      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      PluginNextoolFileHelper::deleteDirectory($dir, true);
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
         // Usa DROP TABLE IF EXISTS direto para evitar erro quando purgeData/uninstall.sql já removeu a tabela
         $DB->doQuery("DROP TABLE IF EXISTS `" . $DB->escape($table) . "`");
         $droppedAny = true;
      }

      return $droppedAny;
   }

   // installAllModules() e uninstallAllModules() removidos (deprecated, não utilizados).
   // A instalação/desinstalação usa loop manual em hook.php com try/catch individual.

   /**
    * Verifica se existe alguma licença no snapshot local que inclua o módulo informado.
    *
    * @param string $moduleKey
    * @return bool
    */
   private function hasLicenseForModule(string $moduleKey): bool {
      if (!class_exists('PluginNextoolLicenseConfig')) {
         return true; // Sem sistema de licenças → permitir
      }
      $config = PluginNextoolLicenseConfig::getDefaultConfig();
      $snapshot = [];
      if (!empty($config['licenses_snapshot'])) {
         $decoded = json_decode((string)$config['licenses_snapshot'], true);
         if (is_array($decoded)) {
            $snapshot = $decoded;
         }
      }
      if (empty($snapshot)) {
         return false; // Sem licenças → não tem licença para este módulo
      }
      foreach ($snapshot as $license) {
         $modules = $license['allowed_modules'] ?? [];
         if (empty($modules) || in_array('*', $modules, true)) {
            return true;
         }
         if (in_array($moduleKey, $modules, true)) {
            return true;
         }
      }
      return false;
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

      $validation = PluginNextoolLicenseValidator::validateLicense([
         'force_refresh' => $forceRefresh,
         'context'       => [
            'requested_modules' => [$moduleKey],
            'origin'            => $origin,
         ],
      ]);

      $plan         = PluginNextoolLicenseValidator::normalizePlan($validation['plan'] ?? null);
      $isFreeTier   = ($plan === 'FREE');
      $billingTier  = $this->getBillingTier($moduleKey);
      $isFreeModule = ($billingTier === 'FREE');
      $isDevModule  = ($billingTier === 'DEV');

      // Módulos FREE devem sempre ser permitidos, independentemente do plano
      // Se o módulo é FREE, bypass completo (não valida licença/contrato/allowed_modules)
      if ($isFreeModule) {
         return [
            'success'    => true,
            'validation' => $validation,
         ];
      }

      // Módulos DEV: apenas plano DESENVOLVIMENTO pode usar (Enterprise e demais não têm acesso)
      if ($isDevModule && $plan !== 'DESENVOLVIMENTO') {
         return [
            'success' => false,
            'message' => __('Módulos de desenvolvimento (DEV) estão disponíveis apenas para o plano Desenvolvimento.', 'nextool'),
            'validation' => $validation,
         ];
      }

      // Em modo FREE: módulos pagos já instalados permanecem utilizáveis (enable/uso);
      // apenas download e update são bloqueados.
      if ($isFreeTier && $billingTier !== 'FREE') {
         $row = $this->getModuleRow($moduleKey);
         $isInstalled = $row !== null && ((int)($row['is_installed'] ?? 0) === 1);
         if ($isInstalled) {
            return [
               'success'    => true,
               'validation'  => $validation,
            ];
         }
         return [
            'success' => false,
            'message' => __('No modo FREE não é possível instalar novos módulos pagos. Os módulos já instalados continuam utilizáveis; vincule uma licença para novos downloads.', 'nextool'),
            'validation' => $validation,
         ];
      }

      // Para módulos PAID, validar licença
      if (empty($validation['valid'])) {
         $msg = isset($validation['message']) && $validation['message'] !== ''
            ? $validation['message']
            : __('Licença inválida ou não autorizada', 'nextool');

         return [
            'success' => false,
            'message' => sprintf(__('Licença inválida: %s', 'nextool'), $msg),
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
            'message' => __('Módulo não permitido nesta licença', 'nextool'),
            'validation' => $validation,
         ];
      }

      return [
         'success'    => true,
         'validation' => $validation,
      ];
   }

   /**
    * Chamado quando o ambiente passa a operar em modo FREE (ex.: falha de
    * comunicação com o ContainerAPI ou licença inválida).
    *
    * Comportamento: não desinstala nem desativa módulos já instalados.
    * Módulos já instalados permanecem utilizáveis. Apenas atualizações e
    * novos downloads de módulos pagos ficam bloqueados em modo FREE.
    *
    * @return void
    */
   public function enforceFreeTierForPaidModules(): void {
      // Não altera is_installed nem is_enabled. Módulos já instalados
      // continuam utilizáveis; só bloqueamos download e update quando
      // o plano é FREE (ver downloadRemoteModule/updateModule e
      // validateLicenseForModule para módulo já instalado).
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

         $manifestFile = $dir . '/module.json';
         if (file_exists($manifestFile)) {
            $filetimes[] = $manifestFile . ':' . filemtime($manifestFile);
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

      $data = @unserialize($cacheData, ['allowed_classes' => false]);
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

      $data = @unserialize($cacheData, ['allowed_classes' => false]);
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

         // Guard de compatibilidade: valida module.json antes de carregar PHP
         $moduleManifestDir = $this->modulesPath . '/' . $moduleDirName;
         $manifestCheck = $this->validateModuleManifest($moduleKey, $moduleManifestDir);
         if ($manifestCheck['status'] === 'blocked') {
            $this->blockedModules[$moduleKey] = $manifestCheck['message'];
            return false; // Cache stale, rebuild via discoverModules
         }
         if ($manifestCheck['status'] === 'legacy') {
            $this->legacyModules[] = $moduleKey;
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

      $this->moduleRowCache = [];
      $this->rowMapPreloaded = false;
      if (class_exists('PluginNextoolMainConfig')) {
         PluginNextoolMainConfig::clearModuleConfigTabsCache();
      }

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
   /**
    * Regenera o cache JSON de módulos stateless (getStatelessFiles()).
    *
    * Chamado automaticamente por discoverModules() e refreshModules().
    * O cache é lido pelo boot (setup.php) e pelo roteador AJAX (module_ajax.php)
    * antes que o GLPI esteja completamente carregado.
    */
   public function refreshStatelessCache(): void {
      require_once NEXTOOL_PHP_DIR . '/inc/statelessmodules.inc.php';

      $statelessMap = [];
      foreach ($this->modules as $moduleKey => $module) {
         if (method_exists($module, 'getStatelessFiles')) {
            $files = $module->getStatelessFiles();
            if (!empty($files)) {
               $statelessMap[$moduleKey] = $files;
            }
         }
      }

      $cacheFile = plugin_nextool_stateless_cache_path();
      $json = json_encode($statelessMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      if ($json === false) {
         $json = '{}';
      }

      // Evita escrita desnecessária, mas regrava caso o arquivo não seja gravável
      // (ex.: foi criado por root). Isso permite “auto-cura” via tmp+rename.
      $shouldWrite = true;
      if (file_exists($cacheFile)) {
         $current = @file_get_contents($cacheFile);
         if ($current === $json && is_writable($cacheFile)) {
            $shouldWrite = false;
         }
      }
      if (!$shouldWrite) {
         return;
      }

      // Escrita atômica (tmp + rename) para não depender de permissão no arquivo
      // final (rename depende do diretório). Isso corrige casos de root:root.
      $dir = dirname($cacheFile);
      if (!is_dir($dir)) {
         @mkdir($dir, 0755, true);
      }

      $tmpFile = @tempnam($dir, 'nextool_stateless_');
      if ($tmpFile === false) {
         @file_put_contents($cacheFile, $json, LOCK_EX);
         return;
      }

      $written = @file_put_contents($tmpFile, $json, LOCK_EX);
      if ($written === false) {
         @unlink($tmpFile);
         @file_put_contents($cacheFile, $json, LOCK_EX);
         return;
      }

      @chmod($tmpFile, 0644);
      if (!@rename($tmpFile, $cacheFile)) {
         @file_put_contents($cacheFile, $json, LOCK_EX);
         @unlink($tmpFile);
      }
   }

   public function refreshModules() {
      $this->clearCache();
      return $this->discoverModules(true);
   }
}


