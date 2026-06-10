<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Setup
 * -------------------------------------------------------------------------
 * Plugin principal para GLPI 10. Sistema modular: ModuleManager (auto-discovery),
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

/** Versão do plugin (usada em plugin_version_nextool e migrations) */
define('PLUGIN_NEXTOOL_VERSION', '4.3.2');

/** GLPI mínimo e máximo suportados */
define('PLUGIN_NEXTOOL_MIN_GLPI_VERSION', '10.0.0');
define('PLUGIN_NEXTOOL_MAX_GLPI_VERSION', '10.9.9');

/** URLs e metadados do projeto (centralizados para evitar hardcoding) */
define('NEXTOOL_AUTHOR_NAME', 'Richard Loureiro');
define('NEXTOOL_AUTHOR_URL', 'https://linkedin.com/in/richard-ti/');
define('NEXTOOL_SITE_URL', 'https://nextoolsolutions.com');
define('NEXTOOL_WHATSAPP_URL', 'https://api.whatsapp.com/send?phone=5532984692962&text=Ol%C3%A1%2C%20gostaria%20de%20falar%20sobre%20os%20produtos%20da%20Nextools.');
define('NEXTOOL_BOOKING_URL', 'https://outlook.office.com/bookwithme/user/e52b9e3c38254d21b172fd4f08c18d8e%40jmbasolucoes.com.br?anonymous&ismsaljsauthenabled');
define('NEXTOOL_RELEASES_URL', 'https://github.com/RPGMais/nextool/releases');
define('NEXTOOL_TERMS_URL', 'https://github.com/RPGMais/nextool/blob/main/POLICIES_OF_USE.md');

/**
 * Retorno de plugin_version_*: exibido em Configurar → Plugins e usado pelo marketplace.
 * requirements: formato oficial desde GLPI 9.2 (minGlpiVersion está deprecado).
 */
function plugin_version_nextool() {
   return [
      'name'        => 'NexTool Solutions',
      'version'     => PLUGIN_NEXTOOL_VERSION,
      'author'      => 'Richard Loureiro - <a href="https://linkedin.com/in/richard-ti/" target="_blank" rel="noopener">LinkedIn</a>',
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
 * Hook executado durante o boot do GLPI, antes da sessão e da inicialização dos plugins.
 *
 * NÃO registra module_ajax.php como stateless no SessionManager. Se registrado,
 * o GLPI desabilita cookies (ini_set('session.use_cookies', '0')) e cria sessão
 * vazia a cada requisição — causando 401 "Sessão inválida" em todos os módulos
 * que precisam de autenticação (geolocation, aiassist, etc.).
 *
 * A decisão stateless é feita internamente pelo module_ajax.php via
 * plugin_nextool_stateless_files() para os poucos módulos que realmente precisam
 * (webhooks de mailinteractions/autentique).
 *
 * O Firewall recebe STRATEGY_NO_CHECK para module_ajax.php, permitindo que
 * o próprio roteador faça a validação (sessão ou stateless conforme o módulo).
 */
function plugin_nextool_boot() {
   try {
      if (method_exists('\Glpi\Http\Firewall', 'addPluginStrategyForLegacyScripts')) {
         \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'nextool',
            '#^/ajax/module_ajax\\.php#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
         );
      }
   } catch (\Throwable $e) {
      error_log('[NexTool] boot error: ' . $e->getMessage());
   }
}

/**
 * Inicialização do plugin
 */
function plugin_init_nextool() {
   global $PLUGIN_HOOKS, $CFG_GLPI;

   // csrf_compliant PRIMEIRO, incondicionalmente: se qualquer coisa abaixo
   // lançar (ex.: Plugin::install do pending_apply falhando), o init morre —
   // e sem esta flag TODO POST do plugin vira 403, travando o GLPI do cliente
   // em loop. Paridade GLPI 11 (incidente do portfolio, 2026-06-10).
   $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;

   // Maintenance mode: apply em andamento — skip init para evitar carregar código inconsistente
   if (defined('NEXTOOL_DOC_DIR')) {
      $maintenanceFlag = rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update/.maintenance';
      if (is_file($maintenanceFlag)) {
         $ts = (int)trim((string)@file_get_contents($maintenanceFlag));
         if ($ts > 0 && (time() - $ts) < 300) {
            $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;
            return;
         }
         // Flag expirado (> 5 min) — remover e continuar normalmente
         @unlink($maintenanceFlag);
      }
   }

   // Apply pendente: após copy do staging para plugins/, executar Plugin::install na nova requisição
   $coreUpdateState = Config::getConfigurationValues('plugin:nextool_core_update');
   $pendingVersion = $coreUpdateState['pending_apply_version'] ?? null;
   if ($pendingVersion !== null && $pendingVersion !== '') {
      // try/catch OBRIGATÓRIO (paridade GLPI 11): exceção aqui matava o init
      // em todo request — POSTs 403 e estado de update preso. Em falha: limpa
      // o pending (sai do loop), loga e segue; update re-tentável pela UI.
      // BOOT-SAFE: escrita DIRETA em glpi_configs — NUNCA Config::setConfigurationValues()
      // durante o boot. setConfigurationValues() dispara CommonDBTM->update → logConfigChange
      // → Log::constructHistory → SearchOption::getOptionsForItemtype('Config') →
      // plugin_<x>_getAddSearchOptions() (ex.: Fields) que referencia uma classe ainda não
      // carregada neste boot → "Class not found" → o init morre (no 4.3.0, antes do csrf, isso
      // virava 403 em TODO POST). getConfigurationValues() lê direto do DB (sem cache), então o
      // reset é visto no mesmo request. Incidente portfolio (G11) 2026-06-10; paridade no G10.
      $resetCoreUpdate = static function (array $vals): void {
         global $DB;
         foreach ($vals as $k => $v) {
            $DB->update('glpi_configs', ['value' => $v], [
               'context' => 'plugin:nextool_core_update',
               'name'    => $k,
            ]);
         }
      };
      try {
         $plugin = new Plugin();
         if ($plugin->getFromDBbyDir('nextool')) {
            $plugin->install($plugin->fields['id']);
            $plugin->getFromDB($plugin->fields['id']);
            if ((int)($plugin->fields['state'] ?? 0) !== Plugin::ACTIVATED) {
               $plugin->activate($plugin->fields['id']);
            }
            $resetCoreUpdate([
               'pending_apply_version' => '',
               'update_available'      => '0',
               'staged_target_version' => '',
               'staged_source'         => '',
               'staged_at'             => '',
            ]);
         }
      } catch (\Throwable $e) {
         error_log('[NexTool] pending_apply falhou (update pode ser re-tentado pela UI): ' . $e->getMessage());
         if (class_exists('Toolbox')) {
            Toolbox::logInFile('plugin_nextool', '[CORE-UPDATE] pending_apply EXCEPTION: ' . $e->getMessage());
         }
         $resetCoreUpdate(['pending_apply_version' => '']);
      }
   }

   $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;

   // CSS global escopado a .nextool-tab-card: oculta os controles de "pesquisa salva"
   // (SavedSearch) nas grades Search::show embarcadas em abas de modulo (bugados fora de
   // pagina de busca pura) e remove a 2a barra de rolagem. Nao afeta telas puras.
   $PLUGIN_HOOKS['add_css']['nextool'][] = 'front/nextool-tabs.css.php';

   try {
   Plugin::loadLang('nextool');

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


   // Gera e persiste o Identificador do Cliente no momento em que o plugin é carregado (ativado)
   // em vez de depender apenas da primeira leitura preguiçosa da configuração.
   // Isso garante que, após a ativação, o ambiente já tenha um client_identifier estável.
   $configfile = NEXTOOL_PHP_DIR . '/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
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
      Plugin::registerClass('PluginNextoolMainConfig');
   }

   $validationAttemptFile = NEXTOOL_PHP_DIR . '/inc/validationattempt.class.php';
   if (file_exists($validationAttemptFile)) {
      require_once $validationAttemptFile;
      Plugin::registerClass('PluginNextoolValidationAttempt');
      $CFG_GLPI['glpiitemtypetables']['glpi_plugin_nextool_main_validation_attempts'] = 'PluginNextoolValidationAttempt';
      $CFG_GLPI['glpitablesitemtype']['PluginNextoolValidationAttempt'] = 'glpi_plugin_nextool_main_validation_attempts';
      PluginNextoolValidationAttempt::ensureDisplayPreferences();
   }

   $profilefile = NEXTOOL_PHP_DIR . '/inc/profile.class.php';
   if (file_exists($profilefile)) {
      require_once $profilefile;
      Plugin::registerClass('PluginNextoolProfile', ['addtabon' => ['Profile']]);
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
      }

      // Só carrega módulos se plugin já foi instalado
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         try {
            require_once $basefile;
            require_once $managerfile;

            $manager = PluginNextoolModuleManager::getInstance();
            $manager->loadActiveModules();

            // Pina o mapeamento reverso tabela->itemtype dos tipos CORE que
            // classes de tab do ecossistema "emprestam" via getTable() (padrão
            // KB #26: PluginNextoolProfile retorna glpi_profiles). Se
            // getTableForItemType() resolve a classe do plugin ANTES do core,
            // o Search da lista de perfis monta os links das células para
            // /plugins/nextool/front/profile.form.php — clicar num perfil cai
            // no central. Paridade com o fix GLPI 11 (2026-06-10).
            $CFG_GLPI['glpiitemtypetables']['glpi_profiles'] = 'Profile';
            $CFG_GLPI['glpitablesitemtype']['Profile']       = 'glpi_profiles';

            $hookfile = NEXTOOL_PHP_DIR . '/hook.php';
            if (file_exists($hookfile)) {
               require_once $hookfile;
            }

            // Registra classes necessárias para Search/MassiveActions via providers dos módulos ativos
            $dispatcherFile = NEXTOOL_PHP_DIR . '/inc/hookprovidersdispatcher.class.php';
            if (file_exists($dispatcherFile)) {
               require_once $dispatcherFile;
               if (class_exists('PluginNextoolHookProvidersDispatcher')) {
                  PluginNextoolHookProvidersDispatcher::registerClasses();
               }
            }
            PluginNextoolPermissionManager::syncModuleRights();

            // Registra classes Config de módulos standalone instalados (inclusive desativados)
            // para que as páginas de configuração funcionem via AJAX (common.tabs.php)
            foreach ($manager->getAllModules() as $mk => $mod) {
               if ($mod->isInstalled() && method_exists($mod, 'usesStandaloneConfig') && $mod->usesStandaloneConfig()) {
                  $configClassName = 'PluginNextool' . ucfirst($mk) . 'Config';
                  $configFile = NEXTOOL_MODULES_BASE . '/' . $mk . '/inc/' . $mk . 'config.class.php';
                  if (is_file($configFile) && !class_exists($configClassName)) {
                     require_once $configFile;
                     if (class_exists($configClassName)) {
                        Plugin::registerClass($configClassName);
                     }
                  }
                  // Variante PageConfig (para módulos com conflito de nome)
                  $pageConfigClassName = 'PluginNextool' . ucfirst($mk) . 'PageConfig';
                  if (!class_exists($pageConfigClassName) && is_file($configFile)) {
                     // O arquivo já foi incluído; verificar se a classe PageConfig existe
                     if (class_exists($pageConfigClassName)) {
                        Plugin::registerClass($pageConfigClassName);
                     }
                  } elseif (class_exists($pageConfigClassName)) {
                     Plugin::registerClass($pageConfigClassName);
                  }
               }
            }

            // Mapeamento reverso tabela->itemtype para classes searchable de módulo já
            // carregadas (pelos onInit via require_once, que "furam" o autoloader do NexTool).
            // getItemTypeForTable() não resolve tabelas custom (ex: ..._log) -> retorna null e o
            // Search estoura getItemForItemtype(null) ao renderizar a grade. O autoloader mapeia
            // as classes que ELE carrega; este scan cobre as pré-carregadas pelos onInit.
            foreach (get_declared_classes() as $ntClass) {
               if (strncmp($ntClass, 'PluginNextool', 13) === 0 && is_subclass_of($ntClass, 'CommonDBTM')) {
                  $ntTable = $ntClass::getTable();
                  if (is_string($ntTable) && $ntTable !== '' && !isset($CFG_GLPI['glpiitemtypetables'][$ntTable])) {
                     $CFG_GLPI['glpiitemtypetables'][$ntTable] = $ntClass;
                  }
               }
            }

            // Menu "Nextools" (nativo) + menus de módulos via redefine_menus
            $PLUGIN_HOOKS['redefine_menus']['nextool'] = 'plugin_nextool_redefine_menus';

            // Dispatcher central para Ticket: vários módulos registram via register*;
            // registramos os handlers globais após loadActiveModules para que todos sejam chamados.
            if (class_exists('PluginNextoolHookDispatcher')) {
               $PLUGIN_HOOKS['pre_item_add']['nextool']['Ticket'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchPreItemAddTicket'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['Ticket'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddTicket'
               ];
               $PLUGIN_HOOKS['item_update']['nextool']['Ticket'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemUpdateTicket'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['TicketValidation'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddTicketValidation'
               ];
               $PLUGIN_HOOKS['item_update']['nextool']['TicketValidation'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemUpdateTicketValidation'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['TicketTask'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddTicketTask'
               ];
               // Port GLPI 11: 5983240 -- dispatcher item_update para TicketTask
               $PLUGIN_HOOKS['item_update']['nextool']['TicketTask'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemUpdateTicketTask'
               ];
               // Port GLPI 11: e248c1f -- dispatchers ITILFollowup e ITILSolution
               $PLUGIN_HOOKS['item_add']['nextool']['ITILFollowup'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddITILFollowup'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['ITILSolution'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddITILSolution'
               ];
               // Port GLPI 11: 7c9a403 -- dispatch post_show_item generico
               $PLUGIN_HOOKS['post_show_item']['nextool'] = function ($params) {
                  $item = $params['item'] ?? null;
                  if (!is_object($item)) { return; }
                  PluginNextoolHookDispatcher::dispatchPostShowItem(get_class($item), $params);
               };
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
                           Plugin::registerClass($reg['class']);
                        }
                     }
                     // Registra no hook menu_toadd (exceto módulos que usam redefine_menus).
                     // Acumula em array por seção: vários módulos podem registrar na mesma
                     // seção (ex.: 'management'). O core do GLPI 10 (Html.php) trata
                     // is_array($val) e aceita array de classes por seção do menu_toadd.
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
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao carregar módulos: " . $e->getMessage());
         }
      }
   }
   } catch (\Throwable $e) {
      error_log('[NexTool] init error: ' . $e->getMessage());
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
