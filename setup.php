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

/** Versão do plugin (usada em plugin_version_nextool e migrations) */
define('PLUGIN_NEXTOOL_VERSION', '5.0.4');

/** GLPI mínimo e máximo suportados (requisitos oficiais Teclib/marketplace) */
define('PLUGIN_NEXTOOL_MIN_GLPI_VERSION', '11.0.0');
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
      }
   } catch (\Throwable $e) {
      // NUNCA crashar o GLPI — log silencioso
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
   // e sem esta flag TODO POST do plugin vira 403 "A ação que você requisitou
   // não é permitida" (module_action, Sincronizar, etc.), travando o GLPI do
   // cliente em loop. Incidente do portfolio em 2026-06-10 (update 4.3.0).
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

   // Apply pendente: após copy do staging para plugins/, executar Plugin::install na nova requisição.
   // Fast-path via flag-file (evita SELECT em glpi_configs a cada request).
   // Flag criada por PluginNextoolCoreUpdater::applyByCopyAndReload (espelhada com Config).
   $pendingApplyFlag = (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR))
      ? GLPI_CACHE_DIR . '/nextool_pending_apply'
      : null;
   if ($pendingApplyFlag !== null && is_file($pendingApplyFlag)) {
      // try/catch OBRIGATÓRIO: uma exceção aqui (install de módulo antigo
      // incompatível, permissão de arquivo, etc.) matava o init em TODO
      // request — plugin sem hooks, POSTs 403 e flag/update_available presos
      // para sempre (incidente do portfolio, 2026-06-10). Em falha: remove o
      // flag (sai do loop), loga o erro e segue o init — o update pode ser
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
            // BOOT-SAFE: escrita DIRETA em glpi_configs — NUNCA Config::setConfigurationValues()
            // aqui. setConfigurationValues() → CommonDBTM->update → post_updateItem →
            // logConfigChange → Log::constructHistory → SearchOption::getOptionsForItemtype('Config')
            // → plugin_fields_getAddSearchOptions() → "Class PluginFieldsContainer not found"
            // quando o plugin Fields ainda não foi carregado NESTE boot dos plugins. Essa
            // exceção matava o plugin_init e — no 4.3.0, antes do csrf_compliant — virava 403
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


   // F1a -- a identidade (client_identifier) NÃO é mais gerada localmente: é cunhada pelo
   // ContainerAPI via enroll no provisionamento ativo (front/config.save.php). Esta chamada
   // apenas garante o registro base de config e carrega a configuração; getConfig() não gera
   // mais identificador (installs novos ficam sem identidade até enrollar).
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

            // Bundle de assets: colapsa os N registros de module_assets.php
            // feitos pelos onInit acima em 1 URL por tipo (css/js) — reduz
            // ~16-27 requests com bootstrap completo por page load para 2.
            // Entradas com &nobundle=1 e assets fora do module_assets ficam
            // intactos. Ver inc/assetbundler.class.php.
            $bundlerFile = NEXTOOL_PHP_DIR . '/inc/assetbundler.class.php';
            if (file_exists($bundlerFile)) {
               require_once $bundlerFile;
               PluginNextoolAssetBundler::collapseHooks();
            }

            // Pina o mapeamento reverso tabela->itemtype dos tipos CORE que
            // classes de tab do ecossistema "emprestam" via getTable() (padrão
            // KB #26: PluginNextoolProfile e ProfileTab de módulos retornam
            // glpi_profiles). Se getTableForItemType() resolve a classe do
            // plugin ANTES do core, DbUtils grava glpiitemtypetables
            // [glpi_profiles] = PluginNextool... e o Search da lista de perfis
            // monta os links das células para /plugins/nextool/front/
            // profile.form.php — clicar num perfil cai no central (bug
            // 2026-06-10). A pinagem garante o itemtype canônico.
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
            // HI-07: sync de rights apenas quando a versão muda (file-flag versionada).
            // Antes: 31+ queries em glpi_profilerights em todo init universal.
            // Agora: zero queries quando flag da versão atual existe.
            // Eventos que mudam permissões (install, upgrade, mudança de catálogo,
            // criação de perfil) continuam chamando syncModuleRights diretamente.
            $rightsSyncFlag = (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR))
               ? GLPI_CACHE_DIR . '/nextool_rights_synced_v' . PLUGIN_NEXTOOL_VERSION
               : null;
            if ($rightsSyncFlag === null || !is_file($rightsSyncFlag)) {
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
               $PLUGIN_HOOKS['item_add']['nextool']['TicketTask'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddTicketTask'
               ];
               $PLUGIN_HOOKS['item_update']['nextool']['TicketValidation'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemUpdateTicketValidation'
               ];
               $PLUGIN_HOOKS['item_update']['nextool']['TicketTask'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemUpdateTicketTask'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['ITILFollowup'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddITILFollowup'
               ];
               $PLUGIN_HOOKS['item_add']['nextool']['ITILSolution'] = [
                  'PluginNextoolHookDispatcher',
                  'dispatchItemAddITILSolution'
               ];

               // post_show_item: timeline separator e outros hooks visuais.
               // Nota: nao pode usar HookManager para este hook (limitacao do core GLPI 11).
               // Callable estatico substituiu closure (LO-09 do audit-deep).
               $PLUGIN_HOOKS['post_show_item']['nextool'] = [PluginNextoolHookDispatcher::class, 'dispatchPostShowItemHook'];
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
                     // seção (ex.: 'management' — digitalsignature + autentique). O core
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
      CronTask::register('PluginNextoolCronCatalogSync', 'catalogSync', DAY_TIMESTAMP, [
         'comment' => 'Sincroniza o catálogo de módulos NexTool com a plataforma',
         'mode'    => CronTask::MODE_EXTERNAL,
      ]);
   }
}
