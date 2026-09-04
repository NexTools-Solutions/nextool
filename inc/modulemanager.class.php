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
 * Manifesto de boot (nextool-dev#249, lote B; substitui o cache de 6.12.1):
 * - GLPI_CACHE_DIR/nextool_boot_manifest.cache guarda os metadados de descoberta
 *   (classe, arquivo, status, versão, stateless) + class-map dos módulos
 * - Caminho quente NÃO instancia módulo inativo: instâncias nascem sob demanda
 *   (getModule); getAllModules() materializa todos (só páginas admin)
 * - Invalidado quando arquivos mudam (chave de filemtime, recomputada no máximo
 *   1x por MANIFEST_TRUST_TTL) e expira 1 h após a construção
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

// moduleaudit e distributionclient (43 KB + dependencias) sao carregados sob
// demanda onde sao usados (logModuleAction / downloadModuleFromDistribution):
// eram linkados em TODO request pelo boot (#249). modulecatalog fica eager:
// e pequeno e tem chamadores externos.
require_once NEXTOOL_PHP_DIR . '/inc/modulecatalog.class.php';

class PluginNextoolModuleManager {

   /** @var PluginNextoolModuleManager Instância singleton */
   private static $instance = null;

   /**
    * Secao do profiler nativo (so em modo debug; ver plugin_nextool_prof_start em
    * setup.php). Guardado por function_exists: os testes CLI carregam esta classe
    * sem o setup.php.
    */
   private function nxProf(string $op, string $name): void {
      $fn = 'plugin_nextool_prof_' . $op;
      if (function_exists($fn)) {
         $fn($name);
      }
   }

   /** @var array Instâncias de módulo já criadas neste request (sob demanda) */
   private $modules = [];

   /**
    * Metadados de descoberta (manifesto de boot, nextool-dev#249 lote B):
    * module_key => [class, file, status, version, stateless]. As instâncias em
    * $modules são criadas SOB DEMANDA a partir daqui (getModule()) -- no caminho
    * quente os módulos inativos nunca são instanciados.
    * @var array<string, array>
    */
   private array $discovered = [];

   /** @var bool Descoberta concluída neste request (quente ou fria). */
   private bool $discoveryLoaded = false;

   /** @var bool A última descoberta rodou o caminho FRIO (diagnóstico/testes). */
   private bool $lastDiscoveryCold = false;

   /** @var array Módulos carregados e ativos */
   private $loadedModules = [];

   /** @var string Caminho para diretório de módulos */
   private $modulesPath;

   /** @var string Caminho para diretório de cache */
   private $cachePath;

   /** @var string Manifesto de boot (metadados + class-map), serializado */
   private $cacheFile = 'nextool_boot_manifest.cache';

   /** Formato anterior (até 6.12.1): apagado por higiene ao gravar/limpar. */
   private const LEGACY_CACHE_FILE = 'nextool_modules.cache';

   /** Versão do formato do manifesto (cabeçalho 'format'). */
   private const MANIFEST_FORMAT = 2;

   /**
    * Janela (s) em que o manifesto é aceito pelo mtime do próprio arquivo, sem
    * recomputar a chave de mtimes dos módulos (glob + ~90 stat). Sobrescrevível
    * por define('NEXTOOL_BOOT_MANIFEST_TTL', n) em config/local_define.php; 0 = sempre
    * recomputar (comportamento até 6.12.1).
    */
   private const MANIFEST_TRUST_TTL = 60;

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
    * Módulos cujos arquivos PHP foram SUBSTITUÍDOS nesta requisição (download/update/
    * redownload). A classe carregada em memória é a ANTIGA e continuará antiga até o
    * fim do request -- o PHP não redefine uma classe já carregada, e isso independe do
    * OPcache (invalidar o bytecode só afeta os PRÓXIMOS requests).
    *
    * Consequência: nesta requisição é PROIBIDO executar upgrade()/install()/runMigrations()
    * desses módulos e, principalmente, gravar version/schema-marker -- seria registrar
    * "migrado para a versão nova" tendo rodado a migração ANTIGA, e o estado TRAVA
    * (o bump apaga a divergência que faria o sync reexecutar, e o marker desliga o guard
    * de schema-drift). Ver issue #158.
    *
    * A migração real acontece na FASE 2: finalizeModuleUpgrade() (disparada pela UI) ou,
    * como rede de segurança, o CAMINHO B do syncInstalledVersionsFromDisk() no próximo boot.
    *
    * @var array<string, bool> module_key => true
    */
   private array $staleClassModules = [];

   /**
    * Construtor privado (padrão Singleton)
    */
   private function __construct() {
      require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
      // Nova estrutura: modules em GLPI_PLUGIN_DOC_DIR/nextool/modules (files/_plugins/nextool/modules)
      $this->modulesPath = NEXTOOL_MODULES_BASE;
      
      // Mesma resolução do manifesto de boot (inc/modulespath.inc.php): o autoloader
      // lê o arquivo antes de o manager existir, então os dois têm de concordar.
      if (function_exists('plugin_nextool_boot_manifest_path')) {
         $this->cachePath = dirname(plugin_nextool_boot_manifest_path());
      } elseif (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR)) {
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
      // Já descoberto neste request (quente ou frio) e não forçado: devolve as
      // instâncias já criadas (no caminho quente podem ser zero -- ver getModule()).
      if ($this->discoveryLoaded && !$forceRefresh) {
         return $this->modules;
      }

      // Caminho QUENTE: manifesto de boot válido -> só metadados, zero instância.
      if (!$forceRefresh) {
         $this->nxProf('start', 'mm:cacheValid');
         $cacheValid = $this->isCacheValid();
         $this->nxProf('stop', 'mm:cacheValid');
         if ($cacheValid) {
            $this->nxProf('start', 'mm:loadCache');
            $cached = $this->loadCache();
            $this->nxProf('stop', 'mm:loadCache');
            if ($cached !== false) {
               $this->modules           = [];
               $this->discovered        = $cached['modules'];
               $this->blockedModules    = $cached['blocked'];
               $this->legacyModules     = $cached['legacy'];
               $this->discoveryLoaded   = true;
               $this->lastDiscoveryCold = false;
               // Mantém o mapa stateless sempre em dia (auto-cura de arquivo com
               // ownership/permissão errada), a partir dos metadados -- sem instanciar.
               $this->nxProf('start', 'mm:stateless');
               $this->refreshStatelessCache();
               $this->nxProf('stop', 'mm:stateless');
               return $this->modules;
            }
         }
      }

      // Caminho FRIO: descobre do zero -- instancia todos, sincroniza versões com o
      // disco e grava o manifesto para os próximos requests.
      $this->modules           = [];
      $this->discovered        = [];
      $this->blockedModules    = [];
      $this->legacyModules     = [];
      $this->discoveryLoaded   = true;
      $this->lastDiscoveryCold = true;

      if (!is_dir($this->modulesPath)) {
         return $this->modules;
      }
      $this->nxProf('start', 'mm:discoverCold');

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
             $this->modules[$moduleKey]    = $module;
             $this->discovered[$moduleKey] = $this->describeModule($moduleKey, $className, $module, $manifestCheck['status']);
          }
      }

      // Sincronizar banco.version com disco quando divergente (módulos instalados).
      // Evita botão "Atualizar" visível em estado coerente onde o disco já está na
      // versão alvo mas o banco ficou desatualizado (redeploy, FTP, install manual).
      $this->syncInstalledVersionsFromDisk();

      // Grava o manifesto de boot (metadados + class-map)
      $this->saveCache();

      // Atualiza cache de módulos stateless (usado no boot antes do GLPI carregar)
      $this->refreshStatelessCache();
      $this->nxProf('stop', 'mm:discoverCold');

      return $this->modules;
   }

   /**
    * Metadados de um módulo para o manifesto de boot: o suficiente para o caminho
    * quente instanciar sob demanda e regravar o stateless sem executar código do módulo.
    * Nada de texto traduzível aqui (nome/descrição dependem do idioma da sessão).
    */
   private function describeModule(string $moduleKey, string $className, PluginNextoolBaseModule $module, string $status): array {
      $stateless = [];
      try {
         foreach ((array) $module->getStatelessFiles() as $file) {
            if (is_string($file) && $file !== '') {
               $stateless[] = $file;
            }
         }
      } catch (\Throwable $e) {
         $stateless = [];
      }
      return [
         'key'       => $moduleKey,
         'dir'       => $moduleKey,
         'class'     => $className,
         // relativo a modulesPath: uma mudança de GLPI_VAR_DIR invalida pelo cabeçalho
         'file'      => $moduleKey . '/inc/' . $moduleKey . '.class.php',
         'status'    => $status === 'legacy' ? 'legacy' : 'ok',
         'version'   => (string) $module->getVersion(),
         'stateless' => $stateless,
      ];
   }

   /** Zera a descoberta EM MEMÓRIA (o manifesto em disco continua válido). */
   private function resetDiscovery(): void {
      $this->modules         = [];
      $this->discovered      = [];
      $this->discoveryLoaded = false;
   }

   /**
    * Carrega módulos ativos
    * Inicializa apenas os módulos que estão habilitados no banco
    * 
    * @return array Módulos carregados
    */
   public function loadActiveModules() {
      $this->loadedModules = [];

      // Descobrir módulos disponíveis (quente: só metadados)
      if (!$this->discoveryLoaded) {
         $this->discoverModules();
      }

      // Pré-carrega cache em bulk -- mesma query que seria necessária para listar
      // módulos ativos, mas serve também para isInstalled/isEnabled em cascata
      // (hook redefine_menus, profile, etc.) sem novas queries no mesmo request.
      $this->nxProf('start', 'mm:preloadRows');
      $this->preloadModuleRowCache();
      $this->nxProf('stop', 'mm:preloadRows');

      foreach ($this->moduleRowCache as $moduleKey => $row) {
         if ($row === null) {
            continue;
         }
         if (((int)($row['is_enabled'] ?? 0)) !== 1) {
            continue;
         }
         // Instancia sob demanda (manifesto de boot): módulos inativos nunca são
         // instanciados no caminho quente.
         $module = $this->getModule($moduleKey);
         if ($module === null) {
            continue;
         }
         if ($this->checkDependencies($module)) {
            $this->nxProf('start', 'mm:lang:' . $moduleKey);
            $module->loadModuleLang();
            $this->nxProf('stop', 'mm:lang:' . $moduleKey);
            // onInit() pode disparar migração de schema idempotente (ex.: ensureSchema quando a
            // versão do módulo muda). A Migration do GLPI ecoa a tela de progresso ("Tarefa
            // concluída. (0 segundo)") direto no HTML (Migration::outputMessageToHtml). Como isto
            // roda no BOOT dos módulos (não é ação do usuário), capturamos e descartamos esse
            // output para não vazar uma mensagem fixa no topo da página (bug no GLPI 10).
            $this->nxProf('start', 'mm:onInit:' . $moduleKey);
            ob_start(static function () { return ''; }); // handler descarta (imune ao ob_flush da Migration)
            try {
               $module->onInit();
            } finally {
               ob_end_clean();
               $this->nxProf('stop', 'mm:onInit:' . $moduleKey);
            }
            $this->loadedModules[$moduleKey] = $module;
         }
      }

      return $this->loadedModules;
   }

   /**
    * Obtém todos os módulos disponíveis (descobertos), instanciando os que ainda
    * não foram -- na ordem do catálogo (a mesma do manifesto). Só páginas admin
    * (catálogo, aba de perfil) precisam de TODOS; o boot usa getModule() por chave.
    *
    * @return array Lista de módulos
    */
   public function getAllModules() {
      if (!$this->discoveryLoaded) {
         $this->discoverModules();
      }
      $all = [];
      foreach (array_keys($this->discovered) as $moduleKey) {
         $module = $this->getModule($moduleKey);
         if ($module !== null) {
            $all[$moduleKey] = $module;
         }
      }
      return $all;
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
    * Obtém módulo específico pelo module_key, instanciando sob demanda a partir do
    * manifesto de boot. Manifesto inconsistente com o disco (classe movida/renomeada,
    * arquivo removido dentro da janela de confiança) dispara UMA redescoberta a frio.
    *
    * @param string $moduleKey Chave do módulo
    * @return PluginNextoolBaseModule|null
    */
   public function getModule($moduleKey) {
      if (!$this->discoveryLoaded) {
         $this->discoverModules();
      }
      $moduleKey = (string) $moduleKey;
      if (isset($this->modules[$moduleKey])) {
         return $this->modules[$moduleKey];
      }
      $meta = $this->discovered[$moduleKey] ?? null;
      if ($meta === null) {
         return null;
      }
      $module = $this->instantiateFromMeta($moduleKey, $meta);
      if ($module === null) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ModuleManager] manifesto de boot inconsistente para %s -- redescoberta a frio\n",
            $moduleKey
         ));
         $this->clearCache();
         $this->discoverModules(true);
         return $this->modules[$moduleKey] ?? null;
      }
      return $this->modules[$moduleKey] = $module;
   }

   /**
    * getModule() para AÇÕES DE ADMIN (instalar, ativar/desativar): se o módulo não está
    * no manifesto, redescobre a frio UMA vez antes de desistir. Cobre arquivos de módulo
    * restaurados/copiados manualmente dentro da janela de confiança do manifesto (o
    * manifesto foi construído sem a pasta e ainda é aceito pelo mtime) -- caso real do
    * E2E do template (purge -> restaura a pasta -> instala) e de suporte por rsync.
    * Não usar em hot-path: chave inexistente custaria um caminho frio por request.
    */
   private function getModuleOrRediscover(string $moduleKey): ?PluginNextoolBaseModule {
      $module = $this->getModule($moduleKey);
      if ($module === null && !$this->lastDiscoveryCold) {
         $this->clearCache();
         $this->discoverModules(true);
         $module = $this->getModule($moduleKey);
      }
      return $module;
   }

   /** Instancia um módulo a partir dos metadados do manifesto; null se o disco divergir. */
   private function instantiateFromMeta(string $moduleKey, array $meta): ?PluginNextoolBaseModule {
      $file  = $this->modulesPath . '/' . (string) ($meta['file'] ?? '');
      $class = (string) ($meta['class'] ?? '');
      if ($class === '' || !is_file($file)) {
         return null;
      }
      require_once $file;
      if (!class_exists($class)) {
         return null;
      }
      $module = new $class();
      if (!$module instanceof PluginNextoolBaseModule || $module->getModuleKey() !== $moduleKey) {
         return null;
      }
      return $module;
   }

   /**
    * Instala um módulo
    * 
    * @param string $moduleKey Chave do módulo
    * @return array ['success' => bool, 'message' => string]
    */
   public function installModule($moduleKey) {
      global $DB;

      $module = $this->getModuleOrRediscover((string) $moduleKey);
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

      // Executa instalação do módulo. O install de alguns módulos (aiassist, mailanalyzer) roda
      // Migration::executeMigration, que ecoa a tela de progresso ("Tarefa concluída. (0 segundo)")
      // no HTML. Capturamos esse output para não vazar na UI (handler imune ao ob_flush interno).
      ob_start(static function () { return ''; });
      $installOk = $module->install();
      if (ob_get_level() > 0) { @ob_end_clean(); }
      if (!$installOk) {
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
            // A config persistida NÃO é sobrescrita na reinstalação (#228): a linha
            // existente carrega a configuração do cliente, e getConfig() já aplica os
            // defaults por baixo na leitura (chave nova ganha default sem regravar).
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

      // Executa desinstalação do módulo (apenas desregistra; NÃO executa uninstall.sql nem DROP TABLE).
      // Capturamos eventual output de Migration (tela de progresso) para não vazar na UI.
      ob_start(static function () { return ''; });
      $uninstallOk = $module->uninstall();
      if (ob_get_level() > 0) { @ob_end_clean(); }
      if (!$uninstallOk) {
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
      $module = $this->getModuleOrRediscover($moduleKey);
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
      $this->resetDiscovery();
      $this->resetRowCache();
      if (class_exists('PluginNextoolMainConfig')) {
         PluginNextoolMainConfig::clearModuleConfigTabsCache();
      }

      // Cron tasks: SIMÉTRICO ao ciclo do módulo. Ao DESABILITAR, desliga (state=0) as crons do
      // módulo (evita "função indefinida" quando o cron dispara com o módulo off). Ao REABILITAR,
      // RELIGA (state=1 / WAITING) -- senão um módulo desativado e reativado ficaria com as crons
      // mortas para sempre (o CronTask::register do install é idempotente e NÃO recupera o state).
      // Casa o itemtype por 'PluginNextool<Modulekey>%'. Só grava quando o state muda.
      $cronState = $enabled ? 1 : 0; // 1 = CronTask::STATE_WAITING, 0 = STATE_DISABLED
      $cronIterator = $DB->request([
         'FROM'  => 'glpi_crontasks',
         'WHERE' => ['itemtype' => ['LIKE', 'PluginNextool' . ucfirst($moduleKey) . '%']]
      ]);
      foreach ($cronIterator as $cronRow) {
         if ((int)$cronRow['state'] !== $cronState) {
            $DB->update('glpi_crontasks', ['state' => $cronState], ['id' => $cronRow['id']]);
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

      // Artefato unico: compat_glpi_majors (CSV "10,11") tem precedencia -- 1 module.json
      // roda em multiplas majors. Modulos antigos (so o inteiro glpi_major) seguem validos
      // pelo ramo de retrocompat abaixo.
      if (isset($manifest['compat_glpi_majors']) && trim((string) $manifest['compat_glpi_majors']) !== '') {
         $compatMajors = array_values(array_filter(array_map(
            static function ($v) { return (int) trim((string) $v); },
            explode(',', (string) $manifest['compat_glpi_majors'])
         )));
         if (!empty($compatMajors) && !in_array($currentGlpiMajor, $compatMajors, true)) {
            $msg = sprintf(
               'Modulo compativel com GLPI %s, mas este ambiente e GLPI %d',
               implode('/', $compatMajors),
               $currentGlpiMajor
            );
            Toolbox::logInFile('plugin_nextool', sprintf('[ModuleManifest] BLOCKED %s: %s', $moduleKey, $msg));
            return ['status' => 'blocked', 'message' => $msg];
         }
      } elseif (isset($manifest['glpi_major'])) {
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
      $auditFile = NEXTOOL_PHP_DIR . '/inc/moduleaudit.class.php';
      if (!class_exists('PluginNextoolModuleAudit', false) && is_file($auditFile)) {
         require_once $auditFile;
      }
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
    * @param array  $extraResult Chaves extras devolvidas ao chamador (ex.: pending_upgrade).
    *                            O $context vai só para o audit log; isto vai na RESPOSTA.
    * @return array
    */
   private function buildModuleActionResult($moduleKey, $action, $success, $message, array $context = [], array $extraResult = []) {
      $this->logModuleAction($moduleKey, $action, array_merge($context, [
         'result'  => $success ? 1 : 0,
         'message' => $message,
      ]));

      return array_merge($extraResult, [
         'success' => $success,
         'message' => $message,
      ]);
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

      // Sideeffect: verificar atualização do core após download bem-sucedido de módulo FREE.
      // Downloads FREE não passam por validateLicense, então este é o único ponto onde a
      // hint de core update é alimentada em ambientes que só usam módulos FREE.
      if ($result['success']) {
         try {
            require_once NEXTOOL_PHP_DIR . '/inc/coreupdater.class.php';
            $updater = new PluginNextoolCoreUpdater();
            $updater->check('stable', 'post_download_sideeffect');
         } catch (\Throwable $e) {
            Toolbox::logInFile('plugin_nextool', '[CoreUpdate] sideeffect check falhou: ' . $e->getMessage());
         }
      }

      // Download sobre módulo JÁ instalado troca os arquivos sem tocar em version: o disco
      // fica à frente do banco e a migração precisa da fase 2 (classe nova). Sinalizamos
      // para a UI concluir explicitamente; se ela não concluir, o CAMINHO B do
      // syncInstalledVersionsFromDisk() converge no próximo request. Ver issue #158.
      $needsFinalize = $result['success'] && $this->isInstalled($moduleKey);

      return $this->buildModuleActionResult(
         $moduleKey,
         $action,
         $result['success'],
         $result['message'],
         $needsFinalize ? array_merge($baseContext, ['pending_upgrade' => true]) : $baseContext,
         $needsFinalize ? ['pending_upgrade' => true] : []
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
         require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';
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
      // Os arquivos no disco sao os NOVOS a partir daqui. Invalidar o OPcache AGORA
      // evita o cabo de guerra entre workers com bytecode novo e velho ate a proxima
      // reciclagem (ver invalidateModuleOpcache). Nao afeta esta requisicao -- a classe
      // ja carregada continua a antiga, e e por isso que existe $staleClassModules.
      $this->invalidateModuleOpcache($moduleKey);
      // A partir daqui os arquivos em disco são os NOVOS, mas a classe em memória
      // continua a ANTIGA por todo o resto do request. Marcar ANTES do discoverModules()
      // é essencial: é ele quem chama syncInstalledVersionsFromDisk(), que sem esta marca
      // migraria/bumparia com a definição velha (issue #158).
      $this->staleClassModules[$moduleKey] = true;
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
         // FASE 1 (ver $staleClassModules): a classe em memória continua a ANTIGA -- os
         // arquivos foram trocados nesta mesma requisição. Não migramos e não bumpamos
         // version aqui; isso trava o estado (issue #158). A migração fica para a fase 2
         // (finalizeModuleUpgrade), disparada pela UI, com o sync do boot como retry.
         //
         // onEnable() ainda roda com a definição antiga: é reativação de hooks/cron do
         // que já estava ligado, não migração de schema -- e a fase 2 recarrega o módulo
         // logo em seguida.
         if ($wasEnabled) {
            $newModule->onEnable();
         }
      }

      $this->clearCache();

      return $this->buildModuleActionResult($moduleKey, $action, true,
         __('Arquivos do módulo substituídos. Dados do banco preservados. Concluindo a atualização...', 'nextool'),
         array_merge($baseContext, ['pending_upgrade' => true]),
         ['pending_upgrade' => true]);
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
            // FASE 1 (ver $staleClassModules): os arquivos acabaram de ser trocados, a
            // classe em memória ainda é a antiga. Não migramos nem bumpamos aqui --
            // só registramos a versão DISPONÍVEL. A migração vai na fase 2.
            $unblockedVersion = $module->getVersion();
            $DB->update(
               'glpi_plugin_nextool_main_modules',
               [
                  'available_version' => $download['version'] ?? $unblockedVersion,
                  'date_mod'          => date('Y-m-d H:i:s'),
               ],
               ['module_key' => $moduleKey]
            );
            $this->clearCache();
            return $this->buildModuleActionResult($moduleKey, $action, true,
               __('Arquivos do módulo baixados (compatibilidade restaurada). Concluindo a atualização...', 'nextool'),
               array_merge($baseContext, [
                  'from_version'    => $row['version'] ?? 'unknown',
                  'to_version'      => $unblockedVersion,
                  'pending_upgrade' => true,
               ]),
               ['pending_upgrade' => true]
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

      // ------------------------------------------------------------------
      // FASE 1 -- só troca de arquivos. NÃO roda upgrade() e NÃO grava version.
      //
      // A classe do módulo carregada nesta requisição é a ANTIGA (o PHP não redefine
      // classe já carregada; invalidar o OPcache só afeta os próximos requests). Rodar
      // upgrade() aqui executa a migração da versão VELHA e, pior, gravar version+marker
      // logo em seguida TRAVA o estado: some a divergência que faria o sync reexecutar e
      // o marker desliga o guard de schema-drift. Era o bug da issue #158 -- as tabelas
      // ficavam sem as linhas/colunas que a versão nova semeia e nem limpar cache curava.
      //
      // Só registramos a versão DISPONÍVEL. A migração real é a FASE 2:
      // finalizeModuleUpgrade(), que a UI dispara logo em seguida (já com o bytecode novo)
      // e que o CAMINHO B do syncInstalledVersionsFromDisk() refaz sozinho, com retry, se
      // a UI não chegar a disparar.
      // ------------------------------------------------------------------
      $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'available_version'  => $downloadedVersion ?: $targetVersion,
            'date_mod'           => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );

      $this->clearCache();

      return $this->buildModuleActionResult($moduleKey, $action, true,
         __('Arquivos do módulo baixados. Concluindo a atualização...', 'nextool'),
         array_merge($baseContext, [
            'from_version'    => $currentVersion,
            'to_version'      => $targetVersion,
            'pending_upgrade' => true,
         ]),
         ['pending_upgrade' => true]
      );
   }

   /**
    * FASE 2 do update: aplica a migração do módulo com o código NOVO já carregado.
    *
    * Precisa rodar numa requisição DIFERENTE da que baixou os arquivos -- é justamente
    * isso que garante que $module->upgrade() executa a definição nova (ver
    * $staleClassModules e issue #158). Chamada pela UI logo após a fase 1.
    *
    * Idempotente e seguro fora de ordem:
    *  - banco já na versão do disco  -> nada a fazer (o sync do boot pode ter convergido antes);
    *  - upgrade falha                -> version NÃO é bumpada, a divergência permanece e o
    *                                    CAMINHO B do sync tenta de novo no próximo request.
    *
    * @return array{success: bool, message: string}
    */
   public function finalizeModuleUpgrade(string $moduleKey): array {
      global $DB;

      $action = 'finalize_update';
      $baseContext = [
         'origin'            => 'module_finalize_update',
         'requested_modules' => [$moduleKey],
      ];

      // Guard de contrato: se os arquivos foram trocados NESTA requisição, a classe em
      // memória é a antiga e finalizar aqui reintroduziria exatamente o bug.
      if (isset($this->staleClassModules[$moduleKey])) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('A atualização precisa ser concluída em uma nova requisição. Recarregue a página.', 'nextool'),
            $baseContext);
      }

      $row = $this->getModuleRow($moduleKey);
      if ($row === null || !(bool) ($row['is_installed'] ?? 0)) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Módulo precisa estar instalado para atualizar.', 'nextool'), $baseContext);
      }

      $module = $this->getModule($moduleKey);
      if ($module === null) {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Módulo não encontrado no diretório local.', 'nextool'), $baseContext);
      }

      $dbVersion   = $row['version'] ?? null;
      $diskVersion = $module->getVersion();
      if ($diskVersion === null || $diskVersion === '') {
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Não foi possível ler a versão do módulo em disco.', 'nextool'), $baseContext);
      }

      if ($dbVersion === $diskVersion) {
         // Já convergiu (a fase 2 é idempotente: o sync do boot pode ter chegado antes).
         return $this->buildModuleActionResult($moduleKey, $action, true,
            sprintf(__('Módulo atualizado com sucesso para a versão %s.', 'nextool'), $diskVersion),
            array_merge($baseContext, ['to_version' => $diskVersion]));
      }

      if (!$this->runModuleUpgradeSafely($module, $dbVersion, $diskVersion, $moduleKey)) {
         // version NÃO é bumpada: a divergência fica de pé e o CAMINHO B do sync
         // retenta a cada request até convergir.
         return $this->buildModuleActionResult($moduleKey, $action, false,
            __('Os arquivos foram atualizados, mas as rotinas de migração do módulo falharam. O sistema tentará novamente automaticamente; consulte os logs do NexTool.', 'nextool'),
            array_merge($baseContext, ['from_version' => $dbVersion, 'to_version' => $diskVersion]));
      }

      // Guard de drift (#159): a migração acabou de rodar com o CÓDIGO NOVO; este é o
      // momento certo de conferir se o schema real bate com o declarado no install.sql.
      // Pega o caso clássico -- coluna acrescentada no CREATE TABLE sem o ALTER
      // correspondente no upgrade.sql, que nunca chega a quem já tinha a tabela.
      // Só ADICIONA o que falta; nunca dropa nem altera tipo. Não-fatal: um problema
      // aqui não pode reverter um upgrade que deu certo.
      $this->runSchemaGuard($moduleKey);

      $DB->update(
         'glpi_plugin_nextool_main_modules',
         [
            'version'  => $diskVersion,
            'date_mod' => date('Y-m-d H:i:s'),
         ],
         ['module_key' => $moduleKey]
      );
      $this->writeSchemaMarker($moduleKey, (string) $diskVersion);
      if ($dbVersion !== null && $dbVersion !== '' && $dbVersion !== $diskVersion) {
         @unlink($this->schemaMarkerPath($moduleKey, $dbVersion)); // higiene do marker antigo
      }

      $this->clearCache();
      $this->refreshModules();

      return $this->buildModuleActionResult($moduleKey, $action, true,
         sprintf(__('Módulo atualizado com sucesso para a versão %s.', 'nextool'), $diskVersion),
         array_merge($baseContext, [
            'from_version' => $dbVersion,
            'to_version'   => $diskVersion,
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
    * Invalida o cache de linhas de módulo.
    *
    * moduleRowCache e rowMapPreloaded são UM par indivisível: o fast-path de
    * getModuleRow() lê "preloaded=true + chave ausente" como "o módulo não
    * existe no banco". Zerar o cache sem baixar a flag faz getModuleRow()
    * responder null para TUDO, e isEnabled/isInstalled/getBillingTier passam a
    * mentir pelo resto do request.
    *
    * Era exatamente o que acontecia em setEnabledState(): ele limpava só o array.
    * O sintoma era brutal e silencioso -- depois de DESATIVAR um módulo, tentar
    * REATIVAR na mesma requisição falhava com "Módulo precisa ser instalado
    * primeiro", porque isInstalled() já respondia false para um módulo instalado
    * (reproduzido em 2026-08-22).
    *
    * Este método existe para que não haja um segundo lugar onde alguém possa
    * esquecer metade do par.
    */
   private function resetRowCache(): void {
      $this->moduleRowCache  = [];
      $this->rowMapPreloaded = false;
      // O memo de getConfig() no BaseModule guarda a MESMA coluna `config`
      // destas linhas - deixá-lo para trás devolveria config obsoleta.
      if (class_exists('PluginNextoolBaseModule')) {
         PluginNextoolBaseModule::invalidateConfigCache();
      }
   }

   /**
    * Devolve a coluna `config` já decodificada da linha PRÉ-CARREGADA, sem query.
    *
    * Existe para BaseModule::getConfig() evitar um SELECT que o
    * preloadModuleRowCache() já resolveu: loadActiveModules() pré-carrega TODAS
    * as linhas (SELECT *) na L253 e só então entra no loop de onInit() na L255 -
    * logo, todo getConfig() chamado de um onInit() estava repetindo em unitário
    * uma leitura já feita em bulk.
    *
    * Semântica de TRÊS estados, deliberada:
    *   null  => cache NÃO pré-carregado -> o caller DEVE ir ao banco (fallback)
    *   false => pré-carregado e o módulo não existe na tabela -> usar defaults
    *   array => config decodificado da linha em memória
    *
    * Não expandir getModulesStateMap() para isso: aquele método é consumido em
    * loops de menu/perfil e carregar o JSON de ~40 módulos ali seria regressão de
    * memória para nada.
    *
    * @return array|false|null
    */
   public function getPreloadedRawConfig(string $moduleKey) {
      if (!$this->rowMapPreloaded) {
         return null;
      }
      if (!array_key_exists($moduleKey, $this->moduleRowCache)) {
         return false;
      }
      $row = $this->moduleRowCache[$moduleKey];
      if ($row === null) {
         return false;
      }
      $decoded = json_decode($row['config'] ?? '{}', true);
      return is_array($decoded) ? $decoded : [];
   }

   /**
    * Invalida a linha cacheada de UM módulo (e o memo de config correspondente).
    *
    * Chamado por BaseModule::saveConfig() para que a próxima leitura no mesmo
    * request enxergue o que acabou de ser gravado.
    *
    * Baixa rowMapPreloaded de propósito: o fast-path de getModuleRow() responde
    * "não existe" para chave ausente com preload ligado, então remover uma chave
    * sem baixar a flag transformaria o módulo em inexistente. O custo é no
    * máximo uma query extra por save - operação rara, sempre num POST de config.
    *
    * @param string|null $moduleKey null limpa tudo.
    */
   public function invalidateModuleRow(?string $moduleKey = null): void {
      if ($moduleKey === null) {
         $this->resetRowCache();
         return;
      }
      unset($this->moduleRowCache[$moduleKey]);
      $this->rowMapPreloaded = false;
      if (class_exists('PluginNextoolBaseModule')) {
         PluginNextoolBaseModule::invalidateConfigCache($moduleKey);
      }
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

      // Prioridade 2: instancia (sob demanda) -- fallback de bootstrap para modulos nao sincronizados
      $instance = $this->getModule($moduleKey);
      if ($instance instanceof PluginNextoolBaseModule) {
         return strtoupper(trim((string) $instance->getBillingTier()));
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
    * versão de disco. Fica FORA do manifesto de boot (nextool_boot_manifest.cache): o clearCache() só
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
      global $DB;

      // Serializa upgrades do MESMO módulo entre processos (advisory lock MySQL).
      // Sem isto, dois workers rodando o upgrade simultaneamente quebravam: um DROP
      // do vencedor caía no meio do upgrade.sql do outro => erro 1146 + abort do
      // restante do arquivo (Portfolio 2026-07-28, contracthours 3.8.0->4.0.0).
      // Timeout 10s: upgrades são rápidos -- o perdedor espera e re-executa o
      // upgrade idempotente em seguida (inócuo), em vez de rodar em paralelo.
      // Falha ao obter o lock => retorna false: o caller mantém a divergência e o
      // próximo request converge (mesma semântica de upgrade que falhou).
      $lockName = 'nextool_module_upgrade_' . preg_replace('/[^a-z0-9_]/i', '', $moduleKey);
      $locked   = false;
      try {
         $res = $DB->doQuery("SELECT GET_LOCK('{$lockName}', 10) AS l");
         $row = $res ? $DB->fetchAssoc($res) : null;
         $locked = ((int)($row['l'] ?? 0) === 1);
      } catch (\Throwable $e) {
         // Backend sem suporte/erro no lock: seguir sem serialização (comportamento
         // antigo) é preferível a nunca migrar.
         $locked = true;
      }
      if (!$locked) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ModuleManager] upgrade de %s (%s -> %s) PULADO: outro processo detém o lock '%s' há >10s\n",
            $moduleKey, $from ?? 'null', $to ?? 'null', $lockName
         ));
         return false;
      }

      // Reconciliação de schema em BACKGROUND (carregamento, não ação do usuário): a
      // Migration do GLPI ecoa a tela de progresso direto no HTML. Capturamos e
      // descartamos esse output para não vazar mensagem fixa na UI (mesmo motivo do
      // schema-drift guard). As queries de schema rodam normalmente.
      ob_start(static function () { return ''; }); // handler descarta (imune ao ob_flush da Migration)
      try {
         $ok = $module->upgrade($from, $to) !== false;
         ob_end_clean();
         return $ok;
      } catch (\Throwable $e) {
         ob_end_clean();
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[ModuleManager] upgrade idempotente de %s (%s -> %s) FALHOU: %s\n",
            $moduleKey, $from ?? 'null', $to ?? 'null', $e->getMessage()
         ));
         return false;
      } finally {
         try {
            $DB->doQuery("SELECT RELEASE_LOCK('{$lockName}')");
         } catch (\Throwable $e) {
            // lock expira sozinho com a conexão; nada a fazer
         }
      }
   }

   /**
    * Roda o guard de drift de schema do módulo (issue #159).
    *
    * Compara o schema declarado no `sql/install.sql` com o real (via tabelas-sombra, ver
    * PluginNextoolSchemaGuard) e ADICIONA as colunas que faltarem. Não-fatal por contrato:
    * é uma rede de segurança, não pode derrubar o fluxo que a chamou.
    *
    * @return void
    */
   private function runSchemaGuard(string $moduleKey): void {
      try {
         // getModulePath() do BaseModule é protected; o manager tem o seu, público.
         $modulePath = $this->getModulePath($moduleKey);
         if (!is_string($modulePath) || $modulePath === '') {
            return;
         }
         $installSql = rtrim($modulePath, '/') . '/sql/install.sql';
         if (!is_file($installSql)) {
            return;
         }
         require_once NEXTOOL_PHP_DIR . '/inc/schemaguard.class.php';
         if (!class_exists('PluginNextoolSchemaGuard')) {
            return;
         }
         // O guard cria/dropa tabelas-sombra: silencia eco de Migration/DDL na UI.
         ob_start(static function () { return ''; });
         try {
            PluginNextoolSchemaGuard::inspectAndHeal($moduleKey, $installSql);
         } finally {
            if (ob_get_level() > 0) { @ob_end_clean(); }
         }
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[SCHEMA] guard de drift de %s não pôde rodar: %s\n", $moduleKey, $e->getMessage()
         ));
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

         // Arquivos substituídos NESTA requisição: a classe em memória é a antiga.
         // Migrar/bumpar aqui grava um estado mentiroso e trava o auto-reparo -- a
         // convergência fica para a fase 2 (finalizeModuleUpgrade) ou para o próximo
         // request, que já carrega a definição nova. Ver $staleClassModules e issue #158.
         if (isset($this->staleClassModules[$moduleKey])) {
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
            // is_callable, não method_exists: um runMigrations() protected (caso real:
            // geolocation) passa no method_exists, estoura Error na chamada e o guard
            // registra "NÃO reconciliado" em TODO boot sem nunca convergir.
            if (is_callable([$module, 'runMigrations'])) {
               // A Migration do GLPI ecoa a tela de progresso ("Tarefa concluída. (0 segundo)")
               // direto no HTML (Migration::outputMessageToHtml -> <p class="center">). Este guard
               // roda em BACKGROUND no carregamento da página (não é ação do usuário), então
               // capturamos e descartamos esse output para não vazar uma mensagem fixa na UI
               // (bug: "mensagem travada no hero" após desinstalar, no GLPI 10). A migração em si
               // (as queries de schema) roda normalmente -- só o eco visual é suprimido.
               ob_start(static function () { return ''; }); // handler descarta (imune ao ob_flush da Migration)
               try {
                  $module->runMigrations();
                  ob_end_clean();
                  Toolbox::logInFile('plugin_nextool', sprintf(
                     "[ModuleManager] schema-drift guard: %s v%s reconciliado (runMigrations idempotente aplicado)\n",
                     $moduleKey, $diskVersion
                  ));
               } catch (\Throwable $e) {
                  ob_end_clean();
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

         // Guard de drift (#159): mesmo racional do finalizeModuleUpgrade() -- a
         // migração acabou de rodar com o código novo; conferir o schema declarado
         // no install.sql antes de registrar a versão. Sem isto, o deploy
         // runtime-first (rsync/FTP) ficava sem a rede de segurança. Não-fatal.
         if ($isUpgrade) {
            $this->runSchemaGuard($moduleKey);
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
      global $DB;

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

      // O drop roda ANTES de apagar o diretório: getModuleDataTables() resolve as
      // tabelas pelo getDataTables() do objeto, que precisa do código em disco; na
      // ordem inversa o lookup degradava pro fallback por convenção de nome.
      $tablesDropped = $this->dropTablesForModule($moduleKey);

      if ($this->moduleDirectoryExists($moduleKey)) {
         try {
            $directoryRemoved = $this->deleteModuleDirectory($moduleKey);
         } catch (RuntimeException $e) {
            Toolbox::logInFile('plugin_nextool', sprintf('Falha ao remover diretório do módulo %s: %s', $moduleKey, $e->getMessage()));
            $directoryRemoved = false;
         }
      }

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
         // Sem isto ficava uma linha fantasma em main_modules: diretório e tabelas
         // somem, mas is_installed/version/config antigos sobrevivem -- e o
         // syncInstalledVersionsFromDisk() nunca a reconcilia (itera só o disco).
         // Zera em vez de deletar: available_version/billing_tier/is_available vêm
         // do catálogo e mantêm o card "disponível para instalar" sem novo sync.
         if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
            $DB->update('glpi_plugin_nextool_main_modules', [
               'is_installed' => 0,
               'is_enabled'   => 0,
               'config'       => null,
               'version'      => null,
               'date_mod'     => date('Y-m-d H:i:s'),
            ], ['module_key' => $moduleKey]);
         }
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

   /**
    * Invalida o OPcache dos arquivos PHP de um modulo cujos arquivos acabaram de
    * ser trocados no disco.
    *
    * POR QUE: em producao o `opcache.validate_timestamps` fica Off. Sem isto, apos a
    * troca de arquivos convivem workers PHP-FPM com bytecode NOVO e VELHO ate que
    * reciclem sozinhos. Se o upgrade REMOVE algo que a versao anterior recria (CronTask,
    * hook, registro), os dois lados se desfazem mutuamente: a migracao nunca converge,
    * cada request tenta de novo e disputa o advisory lock do ModuleManager -- o ambiente
    * fica lento ate os workers reciclarem. Aconteceu no Portfolio e na central JMBA em
    * 2026-09-02 (aiassist 1.14.0).
    *
    * Cirurgico de proposito: `opcache_invalidate()` por arquivo do modulo, NUNCA
    * `opcache_reset()` -- resetar o cache inteiro derrubaria o bytecode do GLPI todo
    * para punir a troca de um modulo. Fail-silent por contrato: OPcache indisponivel,
    * restrito por `opcache.restrict_api` ou arquivo ilegivel nao pode derrubar um
    * update que ja aconteceu no disco.
    *
    * @return int quantidade de arquivos invalidados
    */
   private function invalidateModuleOpcache(string $moduleKey): int {
      if (!function_exists('opcache_invalidate') || !function_exists('opcache_get_status')) {
         return 0;
      }
      try {
         $status = @opcache_get_status(false);
         if (!is_array($status) || empty($status['opcache_enabled'])) {
            return 0;
         }
      } catch (\Throwable $e) {
         return 0;
      }

      $dir = $this->modulesPath . '/' . $moduleKey;
      if (!is_dir($dir)) {
         return 0;
      }

      $count = 0;
      try {
         $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
         );
         foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
               continue;
            }
            // force=true: com validate_timestamps=Off o timestamp nao serve de criterio.
            if (@opcache_invalidate($file->getPathname(), true)) {
               $count++;
            }
         }
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[OPcache] Falha ao invalidar arquivos do modulo %s: %s', $moduleKey, $e->getMessage()
         ));
         return $count;
      }

      if ($count > 0) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[OPcache] %d arquivo(s) do modulo %s invalidados apos troca no disco.', $count, $moduleKey
         ));
      }
      return $count;
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

      // doQuery() existe a partir do GLPI 10.0.7; em 10.0.0-10.0.6 (dentro do MIN do
      // artefato único) só há query(). Mesmo shim de BaseModule::execDdl().
      $ddlMethod = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';

      foreach ($tables as $table) {
         if (!$DB->tableExists($table)) {
            continue;
         }
         // Usa DROP TABLE IF EXISTS direto para evitar erro quando purgeData/uninstall.sql já removeu a tabela
         try {
            $DB->$ddlMethod("DROP TABLE IF EXISTS `" . $DB->escape($table) . "`");
            $droppedAny = true;
         } catch (\Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[SQL] dropTablesForModule(%s): DROP de %s falhou: %s\n",
               $moduleKey, $table, $e->getMessage()
            ));
         }
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
    * Manifesto de boot válido? (caminho quente)
    *
    * Três gates, do mais barato ao mais caro:
    *  1. arquivo existe e o cabeçalho bate com este ambiente (formato, versão da
    *     base, major do GLPI, pasta de módulos) -- 1 leitura, memoizada por request;
    *  2. idade desde a CONSTRUÇÃO <= 1 h: garante a passagem periódica pelo caminho
    *     frio, onde vive o syncInstalledVersionsFromDisk() (rede de segurança #158);
    *  3. chave de mtimes dos módulos: recomputada (glob + ~90 stat) no máximo 1x por
    *     MANIFEST_TRUST_TTL segundos -- dentro da janela o manifesto é aceito pelo
    *     mtime do próprio arquivo, que o @touch abaixo renova.
    *
    * Troca de arquivos feita pelo PRÓPRIO plugin (download/update/uninstall) chama
    * clearCache(), que apaga o manifesto: a janela só adia a detecção de edição
    * manual/rsync em até 60 s.
    *
    * @return bool
    */
   private function isCacheValid() {
      $path = $this->cachePath . '/' . $this->cacheFile;
      if (!is_file($path)) {
         return false;
      }
      $data = $this->readManifest();
      if ($data === null) {
         return false;
      }
      if ((time() - (int) ($data['time'] ?? 0)) > $this->cacheExpiration) {
         return false;
      }
      $ttl   = defined('NEXTOOL_BOOT_MANIFEST_TTL') ? (int) NEXTOOL_BOOT_MANIFEST_TTL : self::MANIFEST_TRUST_TTL;
      $mtime = (int) @filemtime($path);
      if ($ttl > 0 && $mtime > 0 && (time() - $mtime) <= $ttl) {
         return true;
      }
      if (($data['key'] ?? '') !== $this->getCacheKey()) {
         return false;
      }
      if (!@touch($path)) {
         // Arquivo de outro dono (CLI rodado como root): regrava via tmp+rename para
         // readquirir a posse -- senão a chave seria recomputada em todo request.
         $this->writeManifestFile($data);
      }
      return true;
   }

   /**
    * Manifesto decodificado (memo por request em plugin_nextool_boot_manifest()),
    * validado contra este ambiente. null = ausente, corrompido ou de outro ambiente.
    */
   private function readManifest(): ?array {
      $data = function_exists('plugin_nextool_boot_manifest') ? plugin_nextool_boot_manifest() : null;
      if ($data === null) {
         return null;
      }
      $baseVersion = defined('PLUGIN_NEXTOOL_VERSION') ? PLUGIN_NEXTOOL_VERSION : '';
      $glpiMajor   = defined('GLPI_VERSION') ? (int) explode('.', GLPI_VERSION)[0] : 0;
      if (($data['base_version'] ?? null) !== $baseVersion
          || (int) ($data['glpi_major'] ?? -1) !== $glpiMajor
          || ($data['modules_base'] ?? null) !== $this->modulesPath) {
         return null;
      }
      return $data;
   }

   /**
    * Caminho quente: metadados do manifesto, sem instanciar nada.
    *
    * @return array{modules: array, blocked: array, legacy: array}|false
    */
   private function loadCache() {
      $data = $this->readManifest();
      if ($data === null || !is_array($data['modules'] ?? null)) {
         return false;
      }
      $modules = [];
      foreach ($data['modules'] as $moduleKey => $meta) {
         if (!is_string($moduleKey) || !is_array($meta) || empty($meta['class']) || empty($meta['file'])) {
            return false; // formato inesperado: reconstrói
         }
         $modules[$moduleKey] = $meta;
      }
      return [
         'modules' => $modules,
         'blocked' => is_array($data['blocked'] ?? null) ? $data['blocked'] : [],
         'legacy'  => is_array($data['legacy'] ?? null) ? array_values($data['legacy']) : [],
      ];
   }

   /**
    * Grava o manifesto de boot (caminho frio). Mantém o nome saveCache(): o teste de
    * update em duas fases o invoca por reflexão e espera `false` sob staleClassModules.
    *
    * @return bool
    */
   private function saveCache() {
      $path = $this->cachePath . '/' . $this->cacheFile;

      // Requisição que substituiu arquivos de módulo NÃO persiste: a chave (mtimes)
      // nasceria "válida" apontando para os arquivos NOVOS e o próximo request sairia
      // pelo caminho quente, que NÃO roda syncInstalledVersionsFromDisk() -- mataria a
      // convergência da fase 2 (issue #158). E APAGA o manifesto anterior: dentro da
      // janela de confiança ele seria aceito sem recomputar a chave.
      if (!empty($this->staleClassModules)) {
         @unlink($path);
         if (function_exists('plugin_nextool_boot_manifest')) {
            plugin_nextool_boot_manifest(true);
         }
         return false;
      }

      $classMap = $this->buildClassMap($this->readManifest());
      $data = [
         'format'       => self::MANIFEST_FORMAT,
         'key'          => $this->getCacheKey(),
         'time'         => time(),
         'base_version' => defined('PLUGIN_NEXTOOL_VERSION') ? PLUGIN_NEXTOOL_VERSION : '',
         'glpi_major'   => defined('GLPI_VERSION') ? (int) explode('.', GLPI_VERSION)[0] : 0,
         'php_version'  => PHP_VERSION,
         'modules_base' => $this->modulesPath,
         'modules'      => $this->discovered,
         'blocked'      => $this->blockedModules,
         'legacy'       => array_values(array_unique($this->legacyModules)),
         'classmap'     => $classMap['map'],
         'classmap_sig' => $classMap['sig'],
      ];
      $ok = $this->writeManifestFile($data);
      @unlink($this->cachePath . '/' . self::LEGACY_CACHE_FILE); // formato até 6.12.1
      return $ok;
   }

   /**
    * Escrita atômica (tmp + rename + 0644): substitui até um arquivo de outro dono
    * (o rename depende da pasta, não do arquivo). Fallback: escrita direta.
    */
   private function writeManifestFile(array $data): bool {
      $path = $this->cachePath . '/' . $this->cacheFile;
      $dir  = dirname($path);
      if (!is_dir($dir)) {
         @mkdir($dir, 0755, true);
      }
      $payload = serialize($data);
      $ok  = false;
      $tmp = @tempnam($dir, 'nextool_boot_');
      if ($tmp !== false) {
         if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0644);
            $ok = @rename($tmp, $path);
         }
         if (!$ok) {
            @unlink($tmp);
         }
      }
      if (!$ok) {
         $ok = @file_put_contents($path, $payload, LOCK_EX) !== false;
      }
      if (function_exists('plugin_nextool_boot_manifest')) {
         plugin_nextool_boot_manifest(true); // memo do request: a próxima leitura vê o arquivo novo
      }
      return $ok;
   }

   /**
    * Class-map dos módulos (lote B, #249): classe => arquivo relativo a modulesPath,
    * varrendo modules/<mk>/inc/** (até 3 níveis). Por módulo, uma assinatura barata
    * (mtime da pasta inc e subpastas) permite REAPROVEITAR as entradas do manifesto
    * anterior: só módulos cuja árvore mudou são varridos de novo. A varredura completa
    * custa ~40 ms e o caminho frio roda 1x/h e a cada discoverModules(true) das páginas
    * de config dos módulos -- sem o reaproveitamento essas páginas pagariam a conta.
    * Entrada obsoleta (classe movida) é inofensiva: o autoloader revalida e cai na
    * heurística.
    *
    * @return array{map: array<string,string>, sig: array<string,string>}
    */
   private function buildClassMap(?array $previous): array {
      $map     = [];
      $sig     = [];
      $prevMap = is_array($previous['classmap'] ?? null) ? $previous['classmap'] : [];
      $prevSig = is_array($previous['classmap_sig'] ?? null) ? $previous['classmap_sig'] : [];

      foreach (array_keys($this->discovered) as $moduleKey) {
         $incDir = $this->modulesPath . '/' . $moduleKey . '/inc';
         if (!is_dir($incDir)) {
            continue;
         }
         $signature       = $this->classMapSignature($incDir);
         $sig[$moduleKey] = $signature;

         $entries = null;
         if (isset($prevSig[$moduleKey]) && $prevSig[$moduleKey] === $signature) {
            $prefix  = $moduleKey . '/inc/';
            $entries = [];
            foreach ($prevMap as $class => $rel) {
               if (is_string($rel) && strncmp($rel, $prefix, strlen($prefix)) === 0) {
                  $entries[$class] = $rel;
               }
            }
         }
         if ($entries === null) {
            $entries = $this->scanClassMap($incDir);
         }
         foreach ($entries as $class => $rel) {
            if (!isset($map[$class])) {
               $map[$class] = $rel;
            }
         }
      }
      return ['map' => $map, 'sig' => $sig];
   }

   /** Assinatura da árvore inc/ de um módulo: mtime das pastas (muda ao criar/remover/renomear arquivo). */
   private function classMapSignature(string $incDir): string {
      $parts = [(string) @filemtime($incDir)];
      foreach (glob($incDir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
         $parts[] = basename($sub) . ':' . (string) @filemtime($sub);
         foreach (glob($sub . '/*', GLOB_ONLYDIR) ?: [] as $sub2) {
            $parts[] = basename($sub) . '/' . basename($sub2) . ':' . (string) @filemtime($sub2);
         }
      }
      return implode('|', $parts);
   }

   /** Varre modules/<mk>/inc/** e mapeia cada classe/interface/trait PluginNextool* ao seu arquivo. */
   private function scanClassMap(string $incDir): array {
      $entries = [];
      $base    = $this->modulesPath . '/';
      try {
         $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($incDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
         );
         $iterator->setMaxDepth(3);
         foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            if (substr($pathname, -4) !== '.php' || !$file->isFile()) {
               continue;
            }
            $src = @file_get_contents($pathname);
            if ($src === false
                || !preg_match_all('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait)\s+(PluginNextool\w+)/m', $src, $m)) {
               continue;
            }
            $rel = strncmp($pathname, $base, strlen($base)) === 0 ? substr($pathname, strlen($base)) : $pathname;
            foreach ($m[1] as $class) {
               if (!isset($entries[$class])) {
                  $entries[$class] = $rel;
               } elseif ($entries[$class] !== $rel) {
                  Toolbox::logInFile('plugin_nextool', sprintf(
                     "[ModuleManager] class-map: %s declarada em %s e em %s -- mantida a primeira\n",
                     $class, $entries[$class], $rel
                  ));
               }
            }
         }
      } catch (\Throwable $e) {
         // a varredura nunca derruba o boot: o módulo fica sem class-map (a heurística cobre)
      }
      return $entries;
   }

   /**
    * Limpa o manifesto de boot (disco + memória).
    * Útil quando módulos são adicionados/removidos manualmente.
    *
    * @return bool True se limpou com sucesso
    */
   public function clearCache() {
      $path = $this->cachePath . '/' . $this->cacheFile;

      $this->resetRowCache();
      if (class_exists('PluginNextoolMainConfig')) {
         PluginNextoolMainConfig::clearModuleConfigTabsCache();
      }

      // Limpa a memória SEMPRE (antes do return abaixo: até 6.12.1 a limpeza ficava
      // depois do return e era código inalcançável quando o arquivo existia).
      $this->resetDiscovery();
      $this->blockedModules = [];
      $this->legacyModules  = [];
      @unlink($this->cachePath . '/' . self::LEGACY_CACHE_FILE);

      $ok = true;
      if (file_exists($path)) {
         $ok = @unlink($path);
      }
      if (function_exists('plugin_nextool_boot_manifest')) {
         plugin_nextool_boot_manifest(true);
      }
      return $ok;
   }

   /**
    * Regenera o cache JSON de módulos stateless (getStatelessFiles()).
    *
    * Chamado automaticamente por discoverModules() e refreshModules().
    * O cache é lido pelo boot (setup.php) e pelo roteador AJAX (module_ajax.php)
    * antes que o GLPI esteja completamente carregado.
    *
    * Fonte: os metadados da descoberta (manifesto) -- no caminho quente NÃO há
    * instâncias; no frio o describeModule() já leu getStatelessFiles() de cada uma.
    */
   public function refreshStatelessCache(): void {
      require_once NEXTOOL_PHP_DIR . '/inc/statelessmodules.inc.php';

      if (!$this->discoveryLoaded) {
         return; // nunca gravar a partir de um estado não descoberto (apagaria o mapa)
      }

      $statelessMap = [];
      foreach ($this->discovered as $moduleKey => $meta) {
         $files = $meta['stateless'] ?? [];
         if (is_array($files) && !empty($files)) {
            $statelessMap[$moduleKey] = array_values($files);
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

   /**
    * Força atualização do manifesto: limpa e redescobre a frio.
    *
    * @return array Módulos descobertos (instâncias)
    */
   public function refreshModules() {
      $this->clearCache();
      return $this->discoverModules(true);
   }
}


