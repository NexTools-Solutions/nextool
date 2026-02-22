<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Setup
 * -------------------------------------------------------------------------
 * Plugin principal para GLPI 11. Sistema modular: ModuleManager (auto-discovery),
 * BaseModule (classe base), módulos em modules/[nome]/[nome].class.php.
 * Documentação completa em: docs/
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

require_once __DIR__ . '/inc/modulespath.inc.php';

/** Versão do plugin (usada em plugin_version_nextool e migrations) */
define('PLUGIN_NEXTOOL_VERSION', '3.5.6');

/** GLPI mínimo e máximo suportados (requisitos oficiais Teclib/marketplace) */
define('PLUGIN_NEXTOOL_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_NEXTOOL_MAX_GLPI_VERSION', '11.0.99');

/** URLs e metadados do projeto (centralizados para evitar hardcoding) */
define('NEXTOOL_AUTHOR_NAME', 'Richard Loureiro');
define('NEXTOOL_AUTHOR_URL', 'https://linkedin.com/in/richard-ti/');
define('NEXTOOL_SITE_URL', 'https://nextoolsolutions.ai');
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
      'author'      => 'Richard Loureiro - <a href="https://linkedin.com/in/richard-ti">linkedin.com/in/richard-ti</a>',
      'license'     => 'GPLv3+',
      'homepage'    => 'https://nextoolsolutions.ai',
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
   // Necessário para webhooks (POST) não caírem no CheckCsrfListener do Symfony.
   \Glpi\Http\SessionManager::registerPluginStatelessPath(
      'nextool',
      '#^/ajax/module_ajax\\.php$#'
   );

   \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
      'nextool',
      '#^/ajax/module_ajax\\.php#',
      \Glpi\Http\Firewall::STRATEGY_NO_CHECK
   );
}

/**
 * Inicialização do plugin
 */
function plugin_init_nextool() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['nextool'] = true;

   $permissionfile = GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
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
   $configfile = GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
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
   $setupfile = GLPI_ROOT . '/plugins/nextool/inc/setup.class.php';
   if (file_exists($setupfile)) {
      require_once $setupfile;
   }

   // Classe de configuração standalone (página com abas verticais nativas)
   $mainconfigfile = GLPI_ROOT . '/plugins/nextool/inc/nextoolmainconfig.class.php';
   if (file_exists($mainconfigfile)) {
      require_once $mainconfigfile;
      Plugin::registerClass('PluginNextoolMainConfig');
   }

   $profilefile = GLPI_ROOT . '/plugins/nextool/inc/profile.class.php';
   if (file_exists($profilefile)) {
      require_once $profilefile;
      Plugin::registerClass('PluginNextoolProfile', ['addtabon' => ['Profile']]);
   }

   // Carrega ModuleManager e inicializa módulos ativos
   // Verifica se tabela de módulos existe (plugin já instalado)
   $managerfile = GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
   $basefile = GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
   
   if (file_exists($managerfile) && file_exists($basefile)) {
      global $DB;

      $hookdispatcherfile = GLPI_ROOT . '/plugins/nextool/inc/hookdispatcher.class.php';
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

            $hookfile = GLPI_ROOT . '/plugins/nextool/hook.php';
            if (file_exists($hookfile)) {
               require_once $hookfile;
            }

            // Registra classes necessárias para Search/MassiveActions via providers dos módulos ativos
            $dispatcherFile = GLPI_ROOT . '/plugins/nextool/inc/hookprovidersdispatcher.class.php';
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
                     // Registra no hook menu_toadd (exceto módulos que usam redefine_menus)
                     if (empty($reg['uses_redefine_menus'])) {
                        if (!isset($PLUGIN_HOOKS['menu_toadd']['nextool'])) {
                           $PLUGIN_HOOKS['menu_toadd']['nextool'] = [];
                        }
                        $PLUGIN_HOOKS['menu_toadd']['nextool'][$reg['key']] = $reg['class'];
                     }
                  }
               }
            }
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao carregar módulos: " . $e->getMessage());
         }
      }
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

