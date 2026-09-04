<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Setup
 * -------------------------------------------------------------------------
 * Plugin principal para GLPI 11. Sistema modular: ModuleManager (auto-discovery),
 * BaseModule (classe base), módulos em modules/[nome]/[nome].class.php.
 * Documentação completa em: docs/
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

require_once __DIR__ . '/inc/modulespath.inc.php';

// Compat de versao: shim da interface de Search do GLPI 11 (no-op no GLPI 11,
// declara stub no GLPI 10). Carregado ANTES de qualquer classe do plugin que
// faca `implements \Glpi\Search\DefaultSearchRequestInterface` (base e modulos).
require_once __DIR__ . '/inc/compat/searchcompat.php';

/** Versão do plugin (usada em plugin_version_nextool e migrations) */
define('PLUGIN_NEXTOOL_VERSION', '6.13.1');

/** GLPI mínimo e máximo suportados (requisitos oficiais Teclib/marketplace) */
define('PLUGIN_NEXTOOL_MIN_GLPI_VERSION', '10.0.0');
define('PLUGIN_NEXTOOL_MAX_GLPI_VERSION', '11.0.99');

/** URLs e metadados do projeto (centralizados para evitar hardcoding) */
define('NEXTOOL_AUTHOR_NAME', 'Richard Loureiro');
define('NEXTOOL_AUTHOR_URL', 'https://linkedin.com/in/richard-ti/');
define('NEXTOOL_SITE_URL', 'https://nextoolsolutions.com');
define('NEXTOOL_WHATSAPP_URL', 'https://api.whatsapp.com/send?phone=5532984692962&text=Ol%C3%A1%2C%20gostaria%20de%20falar%20sobre%20os%20produtos%20da%20Nextools.');
define('NEXTOOL_BOOKING_URL', 'https://outlook.office.com/bookwithme/user/e52b9e3c38254d21b172fd4f08c18d8e%40jmbasolucoes.com.br?anonymous&ismsaljsauthenabled');
define('NEXTOOL_RELEASES_URL', 'https://github.com/RPGMais/nextool/releases');
define('NEXTOOL_TERMS_URL', 'https://nextoolsolutions.com/termos-de-uso');

/**
 * Retorno de plugin_version_*: exibido em Configurar → Plugins e usado pelo marketplace.
 * requirements: formato oficial desde GLPI 9.2 (minGlpiVersion está deprecado).
 */
function plugin_version_nextool() {
   return [
      'name'        => 'NexTool Solutions',
      'version'     => PLUGIN_NEXTOOL_VERSION,
      'author'      => 'Richard Loureiro - <a href="https://linkedin.com/in/richard-ti/" target="_blank" rel="noopener">linkedin.com/in/richard-ti</a>',
      'license'     => 'GPLv3+',
      'homepage'    => 'https://nextoolsolutions.com',
      'requirements' => [
         'glpi' => [
            'min' => PLUGIN_NEXTOOL_MIN_GLPI_VERSION,
            'max' => PLUGIN_NEXTOOL_MAX_GLPI_VERSION,
         ],
      ],
   ];
}

/**
 * Profiler nativo do GLPI por etapa do boot (nextool-dev#249). So coleta em modo
 * DEBUG (Session::DEBUG_MODE) e quando a classe existe (GLPI 10.0.x recentes e
 * 11.x); fora disso custa 1 comparacao. As secoes aninham sob "nextool:init" (o
 * core abre essa secao em Plugin::load) e aparecem no debug bar e em
 * ajax/debug.php?ajax_id=<X-Glpi-Ajax-ID>. Nunca lanca: qualquer falha do
 * profiler nao pode derrubar o boot.
 */
function plugin_nextool_prof_enabled(): bool {
   static $on = null;
   if ($on === null) {
      try {
         $on = isset($_SESSION['glpi_use_mode'])
            && class_exists('Session')
            && (int) $_SESSION['glpi_use_mode'] === Session::DEBUG_MODE
            && class_exists('\Glpi\Debug\Profiler');
      } catch (\Throwable $e) {
         $on = false;
      }
   }
   return $on;
}
function plugin_nextool_prof_start(string $name): void {
   if (!plugin_nextool_prof_enabled()) {
      return;
   }
   try {
      $category = defined('\Glpi\Debug\Profiler::CATEGORY_PLUGINS') ? \Glpi\Debug\Profiler::CATEGORY_PLUGINS : 'plugins';
      \Glpi\Debug\Profiler::getInstance()->start('nextool:' . $name, $category);
   } catch (\Throwable $e) {
      // profiler nunca derruba o boot
   }
}
function plugin_nextool_prof_stop(string $name): void {
   if (!plugin_nextool_prof_enabled()) {
      return;
   }
   try {
      \Glpi\Debug\Profiler::getInstance()->stop('nextool:' . $name);
   } catch (\Throwable $e) {
      // idem
   }
}

/**
 * Fast-path de assets (nextool-dev#249): requests de front/module_bundle.php,
 * front/module_assets.php e dos wrappers front/*.js.php|*.css.php da base so
 * precisam de sessao + classes da base + descoberta de modulos (para
 * getModule()->loadModuleLang() e getConfig()). Eles NAO precisam dos 33
 * onInit(), do bundler, do hook.php, dos providers, do sync de rights, do
 * scan de classes nem dos menus -- que e o grosso do nextool:init. Cada pagina
 * dispara 2 a 3 desses requests, e no GLPI 11 eles seguram o lock da sessao
 * durante o boot inteiro. Decisao por PATH (nunca por query string): casar so
 * reduz trabalho, nunca concede nada (o guard 401 dos endpoints continua).
 *
 * Kill switch (sem schema): define('NEXTOOL_BOOT_FAST_PATH', false) em
 * config/local_define.php, ou arquivo GLPI_CACHE_DIR/nextool_boot_fast_path.off.
 */
function plugin_nextool_is_asset_request(): bool {
   static $is = null;
   if ($is !== null) {
      return $is;
   }
   $is = false;
   if (PHP_SAPI === 'cli') {
      return false;
   }
   $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
   if ($path === '' || substr($path, -10) === '/index.php') {
      $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
   }
   $is = (bool) preg_match('#/nextool/front/module_(?:bundle|assets)\.php(?:/|$)#', $path)
      || (bool) preg_match('#/nextool/front/[\w.-]+\.(?:js|css)\.php$#', $path);
   return $is;
}
function plugin_nextool_boot_fast_path_enabled(): bool {
   if (defined('NEXTOOL_BOOT_FAST_PATH')) {
      return (bool) NEXTOOL_BOOT_FAST_PATH;
   }
   if (defined('GLPI_CACHE_DIR') && is_file(GLPI_CACHE_DIR . '/nextool_boot_fast_path.off')) {
      return false;
   }
   return true;
}

/**
 * Hook executado durante o boot do GLPI, antes da sessão e da inicialização dos plugins.
 *
 * GLPI 11 (Symfony) aplica CSRF em qualquer POST não-stateless via CheckCsrfListener.
 * Para permitir webhooks (stateless) em module_ajax.php, registramos o path do
 * roteador como stateless no SessionManager para que o Kernel NÃO aplique CSRF.
 *
 * Importante: isso faz o GLPI desabilitar cookies e NÃO iniciar sessão por padrão
 * nesse path. Para não quebrar módulos autenticados (geolocation, aiassist, etc.),
 * o próprio module_ajax.php reabilita cookies, inicia a sessão e faz check CSRF
 * SOMENTE quando o arquivo do módulo NÃO é stateless.
 *
 * A decisão do que é stateless continua sendo feita internamente pelo module_ajax.php
 * via plugin_nextool_stateless_files() (whitelist explícita).
 *
 * O Firewall recebe STRATEGY_NO_CHECK para module_ajax.php, permitindo que
 * o próprio roteador faça a validação (sessão ou stateless conforme o módulo).
 */
function plugin_nextool_boot() {
   // Necessário para webhooks (POST) e endpoints stateless não caírem no CheckCsrfListener do Symfony.
   // Proteção defensiva: se os métodos não existirem (GLPI 11 antigo), o plugin não quebra o GLPI.
   try {
      if (class_exists('\Glpi\Http\SessionManager')
          && method_exists('\Glpi\Http\SessionManager', 'registerPluginStatelessPath')) {
         \Glpi\Http\SessionManager::registerPluginStatelessPath(
            'nextool',
            '#^/ajax/module_ajax\\.php$#'
         );
      }
      if (class_exists('\Glpi\Http\Firewall')
          && method_exists('\Glpi\Http\Firewall', 'addPluginStrategyForLegacyScripts')) {
         \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'nextool',
            '#^/ajax/module_ajax\\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
         );
         // Assets buscados pelo browser (script/link) NAO podem cair no fallback
         // AUTHENTICATED do firewall: ele responde 302 /?redirect=<asset> ao fetch
         // deslogado, e plugins de SSO (ex.: samlsso) persistem esse redirect e
         // despejam o usuario no asset cru apos o login (KB glpi-dev, armadilha 89).
         // - nextool-tabs.(js|css).php e os wrappers *-*.js/css.php: publicos ou
         //   roteados ao module_assets.php, que tem guard proprio (401 sem redirect);
         // - module_assets.php / module_bundle.php: exigem sessao INTERNAMENTE e
         //   negam em 401 seco, sem gerar redirect.
         // No GLPI 10 o Apache serve front/*.php direto (sem firewall) e o guard
         // interno cumpre o mesmo papel.
         \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'nextool',
            '#^/front/[\\w.-]+\\.(js|css)\\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
         );
         \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'nextool',
            '#^/front/module_(assets|bundle)\\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
         );
      }
   } catch (\Throwable $e) {
      // NUNCA crashar o GLPI - log silencioso
      error_log('[NexTool] boot error: ' . $e->getMessage());
   }
}

/**
 * Inicialização do plugin
 */
function plugin_init_nextool() {
   global $PLUGIN_HOOKS, $CFG_GLPI;

   // csrf_compliant PRIMEIRO, incondicionalmente: se qualquer coisa abaixo
   // lançar (ex.: Plugin::install do pending_apply falhando), o init morre -
   // e sem esta flag TODO POST do plugin vira 403 "A ação que você requisitou
   // não é permitida" (module_action, Sincronizar, etc.), travando o GLPI do
   // cliente em loop. Incidente do portfolio em 2026-06-10 (update 4.3.0).
   $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;

   // Maintenance mode: apply em andamento - skip init para evitar carregar código inconsistente
   if (defined('NEXTOOL_DOC_DIR')) {
      $maintenanceFlag = rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update/.maintenance';
      if (is_file($maintenanceFlag)) {
         $ts = (int)trim((string)@file_get_contents($maintenanceFlag));
         if ($ts > 0 && (time() - $ts) < 300) {
            $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;
            return;
         }
         // Flag expirado (> 5 min) - remover e continuar normalmente
         @unlink($maintenanceFlag);
      }
   }

   // Apply pendente: após copy do staging para plugins/, executar Plugin::install na nova requisição.
   // Fast-path via flag-file (evita SELECT em glpi_configs a cada request).
   // Flag criada por PluginNextoolCoreUpdater::applyByCopyAndReload (espelhada com Config).
   $pendingApplyFlag = (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR))
      ? GLPI_CACHE_DIR . '/nextool_pending_apply'
      : null;
   if ($pendingApplyFlag !== null && is_file($pendingApplyFlag)) {
      // try/catch OBRIGATÓRIO: uma exceção aqui (install de módulo antigo
      // incompatível, permissão de arquivo, etc.) matava o init em TODO
      // request - plugin sem hooks, POSTs 403 e flag/update_available presos
      // para sempre (incidente do portfolio, 2026-06-10). Em falha: remove o
      // flag (sai do loop), loga o erro e segue o init - o update pode ser
      // re-tentado pela UI com o GLPI utilizável.
      try {
         global $DB;
         $plugin = new Plugin();
         if ($plugin->getFromDBbyDir('nextool')) {
            $plugin->install($plugin->fields['id']);
            $plugin->getFromDB($plugin->fields['id']);
            if ((int)($plugin->fields['state'] ?? 0) !== Plugin::ACTIVATED) {
               $plugin->activate($plugin->fields['id']);
            }
            // BOOT-SAFE: escrita DIRETA em glpi_configs - NUNCA Config::setConfigurationValues()
            // aqui. setConfigurationValues() → CommonDBTM->update → post_updateItem →
            // logConfigChange → Log::constructHistory → SearchOption::getOptionsForItemtype('Config')
            // → plugin_fields_getAddSearchOptions() → "Class PluginFieldsContainer not found"
            // quando o plugin Fields ainda não foi carregado NESTE boot dos plugins. Essa
            // exceção matava o plugin_init e - no 4.3.0, antes do csrf_compliant - virava 403
            // ("A ação que você requisitou não é permitida") em TODO POST, travando o cliente
            // em loop. getConfigurationValues() lê direto do DB (sem cache), então o reset é
            // visto no mesmo request. Incidente portfolio 2026-06-10 (stack real:
            // setup.php → Config::setConfigurationValues → fields/hook.php:178).
            foreach ([
               'pending_apply_version' => '',
               'update_available'      => '0',
               'staged_target_version' => '',
               'staged_source'         => '',
               'staged_at'             => '',
            ] as $cfgName => $cfgValue) {
               $DB->update('glpi_configs', ['value' => $cfgValue], [
                  'context' => 'plugin:nextool_core_update',
                  'name'    => $cfgName,
               ]);
            }
         }
      } catch (\Throwable $e) {
         error_log('[NexTool] pending_apply falhou (update pode ser re-tentado pela UI): ' . $e->getMessage());
         if (class_exists('Toolbox')) {
            Toolbox::logInFile('plugin_nextool', '[CORE-UPDATE] pending_apply EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
         }
      } finally {
         @unlink($pendingApplyFlag);
      }
   }

   // CSS global escopado a .nextool-tab-card: oculta os controles de "pesquisa salva"
   // (SavedSearch) nas grades Search::show embarcadas em abas de modulo (bugados fora de
   // pagina de busca pura) e remove a 2a barra de rolagem. Nao afeta telas puras.
   $PLUGIN_HOOKS['add_css']['nextool'][] = 'front/nextool-tabs.css.php';

   // JS global: window.NexToolSession, guarda de sessao compartilhada pelos modulos
   // com polling. Precisa vir ANTES do JS dos modulos (que o consomem com guarda).
   // SEM query string no path: o GLPI 10 valida o registro com file_exists() na string
   // INTEIRA -- com "?fv=" o arquivo "nao existe", vira warning em TODO request e o
   // script fica FORA da pagina (fix de sessao inerte no G10; incidente paerro
   // 2026-08-10). O cache-bust ja vem do core: a URL final ganha ?v=<versao dos files
   // do plugin> nos 2 majors ($options['version'] em Html::script) -- bump de release
   // rebusta; edicao sem bump aguarda o max-age de 1h, aceitavel em producao.
   $PLUGIN_HOOKS['add_javascript']['nextool'][] = 'front/nextool-session.js.php';

   // JS global: intercepta o ENTER nas abas de modulo (grades Search::show e forms de
   // config) para nao recarregar a pagina. Servido via front/*.php porque o GLPI 11
   // (Symfony) NAO serve .js estatico de plugin por path direto (/plugins/X/js/*.js ->
   // 404) -- mesmo padrao do add_css. Ver front/nextool-tabs.js.php.
   $PLUGIN_HOOKS['add_javascript']['nextool'][] = 'front/nextool-tabs.js.php';

   try {
   // Base do scan de classes declaradas DURANTE o init (ver abaixo): so as
   // classes desta fatia interessam ao mapa tabela->itemtype.
   $nxDeclaredBefore = count(get_declared_classes());
   // Fast-path de assets: ver plugin_nextool_is_asset_request(). Definido como
   // constante para os endpoints (module_bundle/module_assets) saberem que o
   // onInit dos modulos NAO rodou e chamarem loadModuleLang() por conta propria.
   $nxAssetFastPath = plugin_nextool_is_asset_request() && plugin_nextool_boot_fast_path_enabled();
   if ($nxAssetFastPath && !defined('NEXTOOL_BOOT_FAST_PATH_ACTIVE')) {
      define('NEXTOOL_BOOT_FAST_PATH_ACTIVE', true);
   }
   plugin_nextool_prof_start('lang');
   Plugin::loadLang('nextool');
   plugin_nextool_prof_stop('lang');

   $permissionfile = NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
   if (file_exists($permissionfile)) {
      require_once $permissionfile;
   }

   // Define a página de configuração do plugin (engrenagem na lista Configurar → Plugins)
   $PLUGIN_HOOKS['config_page']['nextool'] = 'front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';

   // Habilita ações em massa (MassiveActions) para que o GLPI consulte:
   // - plugin_nextool_MassiveActions()
   // - plugin_nextool_MassiveActionsFieldsDisplay()
   // (ex.: módulos com MassiveActions na listagem Search)
   if (Session::getLoginUserID()) {
      $PLUGIN_HOOKS['use_massive_action']['nextool'] = 1;
   }


   // F1a -- a identidade (client_identifier) NÃO é mais gerada localmente: é cunhada pelo
   // ContainerAPI via enroll no provisionamento ativo (front/config.save.php). Esta chamada
   // apenas garante o registro base de config e carrega a configuração; getConfig() não gera
   // mais identificador (installs novos ficam sem identidade até enrollar).
   $configfile = NEXTOOL_PHP_DIR . '/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            if (!$nxAssetFastPath) {
               plugin_nextool_prof_start('getConfig');
               PluginNextoolConfig::getConfig();
               plugin_nextool_prof_stop('getConfig');
            }
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao inicializar client_identifier: " . $e->getMessage());
         }
      }
   }

   // Classe de setup mantida para uso interno; abas em Configurar → Geral foram removidas
   $setupfile = NEXTOOL_PHP_DIR . '/inc/setup.class.php';
   if (file_exists($setupfile)) {
      require_once $setupfile;
   }

   // Classe de configuração standalone (página com abas verticais nativas)
   $mainconfigfile = NEXTOOL_PHP_DIR . '/inc/nextoolmainconfig.class.php';
   if (file_exists($mainconfigfile)) {
      require_once $mainconfigfile;
      // (registerClass sem atributos removido na 6.13.0: e no-op no core e custa
      //  um preg_grep sobre todas as chaves de $CFG_GLPI por chamada -- #249)
   }

   $validationAttemptFile = NEXTOOL_PHP_DIR . '/inc/validationattempt.class.php';
   if (file_exists($validationAttemptFile)) {
      require_once $validationAttemptFile;
      $CFG_GLPI['glpiitemtypetables']['glpi_plugin_nextool_main_validation_attempts'] = 'PluginNextoolValidationAttempt';
      $CFG_GLPI['glpitablesitemtype']['PluginNextoolValidationAttempt'] = 'glpi_plugin_nextool_main_validation_attempts';
      // ensureDisplayPreferences() saiu daqui (1 SELECT em TODO request para um
      // estado que so muda no install): roda junto com o sync de rights, sob o
      // file-flag versionado nextool_rights_synced_v* (A5, #249).
   }

   $profilefile = NEXTOOL_PHP_DIR . '/inc/profile.class.php';
   if (file_exists($profilefile)) {
      require_once $profilefile;
      // Equivalente exato de Plugin::registerClass(..., ['addtabon' => ['Profile']])
      // sem o preg_grep das chaves de $CFG_GLPI (#249).
      CommonGLPI::registerStandardTab('Profile', 'PluginNextoolProfile');
   }

   // Carrega ModuleManager e inicializa módulos ativos
   // Verifica se tabela de módulos existe (plugin já instalado)
   $managerfile = NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
   $basefile = NEXTOOL_PHP_DIR . '/inc/basemodule.class.php';
   
   if (file_exists($managerfile) && file_exists($basefile)) {
      global $DB;

      $hookdispatcherfile = NEXTOOL_PHP_DIR . '/inc/hookdispatcher.class.php';
      if (file_exists($hookdispatcherfile)) {
         require_once $hookdispatcherfile;
         // Fonte de notificação da PRÓPRIA BASE: os avisos da NexTool (aba Alertas)
         // também são publicados no canal e entregues POR USUÁRIO no sino do módulo
         // de notificações, para quem enxerga as abas admin da base. A audiência é
         // 'base_admins', resolvida pelo consumidor na LEITURA (a base não é módulo
         // instalável -- não existe direito plugin_nextool_module_nextool para
         // required_bit; o sink trata este emissor como caso próprio).
         if (class_exists('PluginNextoolHookDispatcher')
             && method_exists('PluginNextoolHookDispatcher', 'registerNotificationSource')) {
            PluginNextoolHookDispatcher::registerNotificationSource('nextool.server_alert', [
               // rotulos lazy: traduzidos so no consumidor, nao em todo boot (#249)
               'label'       => ['Avisos da NexTool', 'nextool'],
               'description' => ['Comunicados oficiais recebidos do servidor NexTool (os mesmos da aba Alertas).', 'nextool'],
               'icon'        => 'ti ti-bell-ringing',
               'color'       => 'purple',
               'severity'    => 'info',
            ]);
         }
      }

      // Só carrega módulos se plugin já foi instalado
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         try {
            require_once $basefile;
            require_once $managerfile;

            $manager = PluginNextoolModuleManager::getInstance();

            if ($nxAssetFastPath) {
               // Request de asset: nada abaixo e necessario (ver
               // plugin_nextool_is_asset_request). A descoberta fica lazy: os
               // endpoints chamam getModule() so para o(s) modulo(s) servido(s).
               plugin_nextool_prof_start('asset_fast_path');
               plugin_nextool_prof_stop('asset_fast_path');
               return;
            }

            plugin_nextool_prof_start('loadActiveModules');
            $manager->loadActiveModules();
            plugin_nextool_prof_stop('loadActiveModules');

            // Bundle de assets: colapsa os N registros de module_assets.php
            // feitos pelos onInit acima em 1 URL por tipo (css/js) - reduz
            // ~16-27 requests com bootstrap completo por page load para 2.
            // Entradas com &nobundle=1 e assets fora do module_assets ficam
            // intactos. Ver inc/assetbundler.class.php.
            $bundlerFile = NEXTOOL_PHP_DIR . '/inc/assetbundler.class.php';
            if (file_exists($bundlerFile)) {
               require_once $bundlerFile;
               plugin_nextool_prof_start('bundler');
               PluginNextoolAssetBundler::collapseHooks();
               plugin_nextool_prof_stop('bundler');
            }

            // Pina o mapeamento reverso tabela->itemtype dos tipos CORE que
            // classes de tab do ecossistema "emprestam" via getTable() (padrão
            // KB #26: PluginNextoolProfile e ProfileTab de módulos retornam
            // glpi_profiles). Se getTableForItemType() resolve a classe do
            // plugin ANTES do core, DbUtils grava glpiitemtypetables
            // [glpi_profiles] = PluginNextool... e o Search da lista de perfis
            // monta os links das células para /plugins/nextool/front/
            // profile.form.php - clicar num perfil cai no central (bug
            // 2026-06-10). A pinagem garante o itemtype canônico.
            $CFG_GLPI['glpiitemtypetables']['glpi_profiles'] = 'Profile';
            $CFG_GLPI['glpitablesitemtype']['Profile']       = 'glpi_profiles';

            $hookfile = NEXTOOL_PHP_DIR . '/hook.php';
            if (file_exists($hookfile)) {
               plugin_nextool_prof_start('hook_include');
               require_once $hookfile;
               plugin_nextool_prof_stop('hook_include');
            }

            // Registra classes necessárias para Search/MassiveActions via providers dos módulos ativos
            $dispatcherFile = NEXTOOL_PHP_DIR . '/inc/hookprovidersdispatcher.class.php';
            if (file_exists($dispatcherFile)) {
               require_once $dispatcherFile;
               if (class_exists('PluginNextoolHookProvidersDispatcher')) {
                  plugin_nextool_prof_start('providers');
                  PluginNextoolHookProvidersDispatcher::registerClasses();
                  plugin_nextool_prof_stop('providers');
               }
            }
            // HI-07: sync de rights apenas quando a versão muda (file-flag versionada).
            // Antes: 31+ queries em glpi_profilerights em todo init universal.
            // Agora: zero queries quando flag da versão atual existe.
            // Eventos que mudam permissões (install, upgrade, mudança de catálogo,
            // criação de perfil) continuam chamando syncModuleRights diretamente.
            plugin_nextool_prof_start('rights');
            $rightsSyncFlag = (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR))
               ? GLPI_CACHE_DIR . '/nextool_rights_synced_v' . PLUGIN_NEXTOOL_VERSION
               : null;
            if ($rightsSyncFlag === null || !is_file($rightsSyncFlag)) {
               // Preferencias de exibicao da grade de tentativas de validacao: so
               // mudam no install; sincronizadas 1x por versao junto com os rights.
               if (class_exists('PluginNextoolValidationAttempt')) {
                  PluginNextoolValidationAttempt::ensureDisplayPreferences();
               }
               PluginNextoolPermissionManager::syncModuleRights();
               if ($rightsSyncFlag !== null) {
                  // Remove flags de versões anteriores antes de marcar a atual
                  foreach (glob(GLPI_CACHE_DIR . '/nextool_rights_synced_v*') ?: [] as $oldFlag) {
                     if ($oldFlag !== $rightsSyncFlag) {
                        @unlink($oldFlag);
                     }
                  }
                  @touch($rightsSyncFlag);
               }
            }

            // Sessao stale: o file-flag acima ressincroniza o BANCO quando a versao muda, mas a
            // sessao web carregada ANTES de um update (ex.: self-update, que nao passa por
            // plugin_nextool_install na sessao ativa do usuario) nao recebe os direitos novos --
            // e o gate da interface central le da SESSAO (Session::haveRight), nao do banco. Sem
            // isto, o super-direito so passaria a valer apos deslogar/relogar. Recarrega os
            // direitos 'nextool*'/'plugin_nextool*' do perfil ativo 1x por sessao por versao
            // (1 query; no-op quando nao ha login).
            if (isset($_SESSION['glpiactiveprofile']['id'])
                && (($_SESSION['nextool_session_rights_v'] ?? null) !== PLUGIN_NEXTOOL_VERSION)) {
               PluginNextoolPermissionManager::reloadActiveProfileRights();
               $_SESSION['nextool_session_rights_v'] = PLUGIN_NEXTOOL_VERSION;
            }
            plugin_nextool_prof_stop('rights');

            // O loop que registrava as classes Config/PageConfig de todos os modulos
            // instalados (inclusive desativados) foi removido na 6.13.0 (#249): o
            // class_exists() dele AUTOCARREGAVA ~50 classes de config por request, o
            // probe de PageConfig falhava 42x pela cadeia de autoloaders, e o
            // Plugin::registerClass() sem atributos e no-op. common.tabs.php resolve
            // PluginNextool<Mk>Config / PageConfig pelo autoloader central
            // (inc/modulespath.inc.php) sob demanda.

            // Mapeamento reverso tabela->itemtype para classes searchable de módulo já
            // carregadas (pelos onInit via require_once, que "furam" o autoloader do NexTool).
            // getItemTypeForTable() não resolve tabelas custom (ex: ..._log) -> retorna null e o
            // Search estoura getItemForItemtype(null) ao renderizar a grade. O autoloader mapeia
            // as classes que ELE carrega; este scan cobre as pré-carregadas pelos onInit.
            plugin_nextool_prof_start('classScan');
            foreach (array_slice(get_declared_classes(), $nxDeclaredBefore) as $ntClass) {
               if (strncmp($ntClass, 'PluginNextool', 13) === 0 && is_subclass_of($ntClass, 'CommonDBTM')) {
                  $ntTable = $ntClass::getTable();
                  if (is_string($ntTable) && $ntTable !== '' && !isset($CFG_GLPI['glpiitemtypetables'][$ntTable])) {
                     $CFG_GLPI['glpiitemtypetables'][$ntTable] = $ntClass;
                  }
               }
            }
            plugin_nextool_prof_stop('classScan');

            plugin_nextool_prof_start('dispatch_menus');
            // Menu "Nextools" (nativo) + menus de módulos via redefine_menus
            $PLUGIN_HOOKS['redefine_menus']['nextool'] = 'plugin_nextool_redefine_menus';

            // Dispatcher central: registrado DEPOIS do loadActiveModules, com ocupação
            // APPEND-AWARE (installHook). Módulo NOVO registra no registry (register*)
            // e é despachado pelo dispatcher; módulo ANTIGO que atribuiu callback
            // direto no slot durante o onInit() é ENCADEADO (dispatcher + legado),
            // nunca sobrescrito -- retrocompat na janela base-nova + módulo-velho
            // (regressão real de 2026-07-23: subtaskflow E2E 8 FAIL por clobber).
            if (class_exists('PluginNextoolHookDispatcher')) {
               $nxInstall = ['PluginNextoolHookDispatcher', 'installHook'];
               $nxInstall($PLUGIN_HOOKS, 'pre_item_add',    'Ticket',           'dispatchPreItemAddTicket');
               $nxInstall($PLUGIN_HOOKS, 'item_add',        'Ticket',           'dispatchItemAddTicket');
               $nxInstall($PLUGIN_HOOKS, 'item_update',     'Ticket',           'dispatchItemUpdateTicket');
               // pre_item_update: módulos podem BLOQUEAR a atualização antes da gravação
               // (abort via input=[] + mensagem; ver PluginNextoolValidationException).
               $nxInstall($PLUGIN_HOOKS, 'pre_item_update', 'Ticket',           'dispatchPreItemUpdateTicket');
               // Sub-itens de chamado (bloqueio na criação/edição): tarefa, acompanhamento,
               // solução. Habilita módulos a exigir mínimos/obrigatoriedades (ex.: ticketrules).
               $nxInstall($PLUGIN_HOOKS, 'pre_item_add',    'TicketTask',       'dispatchPreItemAddTicketTask');
               $nxInstall($PLUGIN_HOOKS, 'pre_item_update', 'TicketTask',       'dispatchPreItemUpdateTicketTask');
               $nxInstall($PLUGIN_HOOKS, 'pre_item_add',    'ITILFollowup',     'dispatchPreItemAddITILFollowup');
               $nxInstall($PLUGIN_HOOKS, 'pre_item_update', 'ITILFollowup',     'dispatchPreItemUpdateITILFollowup');
               $nxInstall($PLUGIN_HOOKS, 'pre_item_add',    'ITILSolution',     'dispatchPreItemAddITILSolution');
               $nxInstall($PLUGIN_HOOKS, 'item_add',        'TicketValidation', 'dispatchItemAddTicketValidation');
               $nxInstall($PLUGIN_HOOKS, 'item_add',        'TicketTask',       'dispatchItemAddTicketTask');
               $nxInstall($PLUGIN_HOOKS, 'item_update',     'TicketValidation', 'dispatchItemUpdateTicketValidation');
               $nxInstall($PLUGIN_HOOKS, 'item_update',     'TicketTask',       'dispatchItemUpdateTicketTask');
               // item_purge: pós-exclusão definitiva de sub-item. Módulos que mantêm
               // dados atrelados (ex.: contracthours timer<->TicketTask) limpam aqui.
               $nxInstall($PLUGIN_HOOKS, 'item_purge',      'TicketTask',       'dispatchItemPurgeTicketTask');
               $nxInstall($PLUGIN_HOOKS, 'item_add',        'ITILFollowup',     'dispatchItemAddITILFollowup');
               $nxInstall($PLUGIN_HOOKS, 'item_add',        'ITILSolution',     'dispatchItemAddITILSolution');

               // post_show_item: timeline separator e outros hooks visuais.
               // Nota: nao pode usar HookManager para este hook (limitacao do core GLPI 11).
               $nxInstall($PLUGIN_HOOKS, 'post_show_item', null, 'dispatchPostShowItemHook');

               // post_item_form: modificação de formulários nativos por módulos
               // (registrados via HookDispatcher::registerPostItemForm no onInit).
               $nxInstall($PLUGIN_HOOKS, 'post_item_form', null, 'dispatchPostItemFormHook');
            }

            // Registra menus de módulos ativos via getMenuRegistration()
            foreach ($manager->getActiveModules() as $moduleKey => $module) {
               if (method_exists($module, 'getMenuRegistration')) {
                  $reg = $module->getMenuRegistration();
                  if (is_array($reg) && !empty($reg['key']) && !empty($reg['class'])) {
                     // Se o módulo declarou class_file, carrega e registra a classe
                     if (!empty($reg['class_file'])) {
                        $classFile = NEXTOOL_MODULES_BASE . '/' . $moduleKey . '/' . $reg['class_file'];
                        if (file_exists($classFile)) {
                           require_once $classFile;
                           // registerClass sem atributos removido (no-op, #249)
                        }
                     }
                     // Registra no hook menu_toadd (exceto módulos que usam redefine_menus).
                     // Acumula em array por seção: vários módulos podem registrar na mesma
                     // seção (ex.: 'management' - digitalsignature + autentique). O core
                     // (Html.php) aceita array de classes por seção do menu_toadd.
                     if (empty($reg['uses_redefine_menus'])) {
                        if (!isset($PLUGIN_HOOKS['menu_toadd']['nextool'])) {
                           $PLUGIN_HOOKS['menu_toadd']['nextool'] = [];
                        }
                        $existing = $PLUGIN_HOOKS['menu_toadd']['nextool'][$reg['key']] ?? [];
                        if (!is_array($existing)) {
                           $existing = [$existing];
                        }
                        if (!in_array($reg['class'], $existing, true)) {
                           $existing[] = $reg['class'];
                        }
                        $PLUGIN_HOOKS['menu_toadd']['nextool'][$reg['key']] = $existing;
                     }
                  }
               }
            }
            plugin_nextool_prof_stop('dispatch_menus');
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao carregar módulos: " . $e->getMessage());
         }
      }
   }
   } catch (\Throwable $e) {
      error_log('[NexTool] init error: ' . $e->getMessage());
      // Plugin não carrega seus recursos, mas GLPI continua operando
   }
}

/**
 * Verifica pré-requisitos antes da instalação (GLPI/PHP).
 * Mensagens incompatíveis via Plugin::messageIncompatible quando disponível.
 */
function plugin_nextool_check_prerequisites() {
   if (version_compare(GLPI_VERSION, PLUGIN_NEXTOOL_MIN_GLPI_VERSION, 'lt')) {
      if (method_exists('Plugin', 'messageIncompatible')) {
         Plugin::messageIncompatible('core', PLUGIN_NEXTOOL_MIN_GLPI_VERSION, PLUGIN_NEXTOOL_MAX_GLPI_VERSION);
      } else {
         echo "Este plugin requer GLPI >= " . PLUGIN_NEXTOOL_MIN_GLPI_VERSION;
      }
      return false;
   }
   if (version_compare(GLPI_VERSION, PLUGIN_NEXTOOL_MAX_GLPI_VERSION, 'gt')) {
      if (method_exists('Plugin', 'messageIncompatible')) {
         Plugin::messageIncompatible('core', PLUGIN_NEXTOOL_MIN_GLPI_VERSION, PLUGIN_NEXTOOL_MAX_GLPI_VERSION);
      } else {
         echo "Este plugin suporta GLPI até " . PLUGIN_NEXTOOL_MAX_GLPI_VERSION;
      }
      return false;
   }
   return true;
}

/**
 * Verifica configuração
 */
function plugin_nextool_check_config() {
   return true;
}

/**
 * Registra as CronTasks do NexTool. Idempotente: CronTask::register faz early-return se a task
 * já existe (ver learning_crontask_register_idempotent). MODE_EXTERNAL obrigatório para
 * poll/integração (web-hit MODE_INTERNAL trava em state=2). Chamado no install/upgrade (F2).
 * Mudança futura de freq/mode exige UPDATE explícito em glpi_crontasks (register não atualiza).
 */
function _plugin_nextool_register_crons() {
   if (!class_exists('CronTask')) {
      return;
   }
   if (!class_exists('PluginNextoolCronCatalogSync')) {
      $cronFile = __DIR__ . '/inc/croncatalogsync.class.php';
      if (file_exists($cronFile)) {
         require_once $cronFile;
      }
   }
   if (class_exists('PluginNextoolCronCatalogSync')) {
      CronTask::register('PluginNextoolCronCatalogSync', 'catalogSync', 6 * HOUR_TIMESTAMP, [
         'comment' => 'Sincroniza o catálogo de módulos NexTool com a plataforma',
         'mode'    => CronTask::MODE_EXTERNAL,
      ]);
      // register() faz early-return se a task já existe -> não atualiza a frequência de quem
      // instalou com o antigo DAY_TIMESTAMP (24h). Migra 24h -> 6h SÓ para quem está no default
      // antigo, e SÓ UMA VEZ (#162): sem a flag, o UPDATE re-rodava a cada install/upgrade e
      // revertia um admin que tivesse escolhido deliberadamente 24h em Ações automáticas.
      global $DB;
      if (isset($DB) && $DB->tableExists('glpi_crontasks') && class_exists('Config')) {
         $flags = Config::getConfigurationValues('plugin:nextool_distribution', ['catalogsync_freq_migrated']);
         if (empty($flags['catalogsync_freq_migrated'])) {
            $DB->update('glpi_crontasks', ['frequency' => 6 * HOUR_TIMESTAMP], [
               'itemtype'  => 'PluginNextoolCronCatalogSync',
               'name'      => 'catalogSync',
               'frequency' => DAY_TIMESTAMP,
            ]);
            Config::setConfigurationValues('plugin:nextool_distribution', ['catalogsync_freq_migrated' => '1']);
         }
      }
   }
}