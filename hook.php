<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hooks
 * -------------------------------------------------------------------------
 * Instalação: sql/install.sql (tabelas e seeds), geração do client_identifier.
 * Desinstalação: sql/uninstall.sql (tabelas operacionais), remoção de
 * diretórios dos módulos baixados. MassiveActions, giveItem, redefine_menus.
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
require_once __DIR__ . '/inc/modulemanager.class.php';
require_once __DIR__ . '/inc/basemodule.class.php';
require_once __DIR__ . '/inc/permissionmanager.class.php';
require_once __DIR__ . '/inc/hookprovidersdispatcher.class.php';
require_once __DIR__ . '/inc/nextoolmainconfig.class.php';

/**
 * Hook GLPI `getRuleActions` (Hooks::AUTO_GET_RULE_ACTIONS).
 *
 * O core do GLPI resolve a extensão de ações de regra por plugin como a função
 * global `plugin_<plugin>_getRuleActions` (Plugin::doOneHook → includeHook).
 * Aqui apenas delegamos ao dispatcher central, que mescla as ações dos módulos
 * ativos que registraram um provider para o tipo de regra (ex.: 'RuleRight').
 * Só é invocado para os itemtypes declarados em $PLUGIN_HOOKS['use_rules']['nextool'].
 *
 * @param array $params ['rule_itemtype' => string, 'values' => array]
 * @return array [actionKey => definição] - vazio se nenhum módulo contribuir
 */
function plugin_nextool_getRuleActions($params) {
   if (!class_exists('PluginNextoolHookDispatcher')) {
      return [];
   }
   return PluginNextoolHookDispatcher::dispatchRuleActions(is_array($params) ? $params : []);
}

/**
 * Search options adicionais em itemtypes nativos, contribuídas por módulos.
 * O GLPI chama esta função para cada itemtype pesquisado; módulos registram
 * providers via PluginNextoolHookDispatcher::registerSearchOptions() no onInit().
 *
 * @param string $itemtype
 * @return array [id => definição] - vazio se nenhum módulo contribui
 */
function plugin_nextool_getAddSearchOptions($itemtype) {
   if (!class_exists('PluginNextoolHookDispatcher')) {
      return [];
   }
   return PluginNextoolHookDispatcher::dispatchGetAddSearchOptions((string) $itemtype);
}

function plugin_nextool_install() {
   global $DB;

   if (!is_dir(NEXTOOL_DOC_DIR)) {
      @mkdir(NEXTOOL_DOC_DIR, 0755, true);
   }
   if (!defined('NEXTOOL_MODULES_BASE')) {
      require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
   }
   if (!is_dir(NEXTOOL_MODULES_BASE)) {
      @mkdir(NEXTOOL_MODULES_BASE, 0755, true);
   }

   $coreUpdateDir = rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update';
   if (!is_dir($coreUpdateDir)) {
      @mkdir($coreUpdateDir, 0755, true);
   }

   // Avisar se o diretorio de modulos nao pôde ser criado (permissoes do filesystem)
   if (!is_dir(NEXTOOL_MODULES_BASE) || !is_writable(NEXTOOL_MODULES_BASE)) {
      $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'php') : 'apache';
      Session::addMessageAfterRedirect(
         sprintf(
            __('NexTool: o diretorio de modulos (%s) nao esta gravavel. Execute: chown -R %s:%s %s', 'nextool'),
            NEXTOOL_MODULES_BASE,
            $owner,
            $owner,
            dirname(NEXTOOL_DOC_DIR)
         ),
         false,
         WARNING
      );
   }

   $sqlfile = NEXTOOL_PHP_DIR . '/sql/install.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   $version = plugin_version_nextool()['version'] ?? '0.0.0';
   $migration = new Migration($version);
   $modulesTable = 'glpi_plugin_nextool_main_modules';
   if ($DB->tableExists($modulesTable) && !$DB->fieldExists($modulesTable, 'description')) {
      $migration->addField($modulesTable, 'description', 'text', ['after' => 'name', 'comment' => 'Descrição do módulo']);
   }
   $migration->executeMigration();

   // F1a -- garante o registro base de config no install. NÃO gera mais client_identifier
   // localmente (a identidade é cunhada pelo ContainerAPI via enroll no provisionamento ativo).
   $configfile = NEXTOOL_PHP_DIR . '/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao inicializar a configuração base durante install: " . $e->getMessage());
         }
      }
   }

   // Migração idempotente: garantir que o vínculo de provisionamento (identifier
   // + segredo HMAC) viva no context resiliente PROVISIONING_CONTEXT, que sobrevive
   // ao uninstall. Clientes existentes têm o segredo só em
   // 'plugin:nextool_distribution' (apagado no uninstall) ou na tabela legacy
   // env_secrets. Roda no install E no upgrade (upgrade chama install). No
   // reinstall pós-uninstall, o provisioning já está preenchido -> no-op.
   if (class_exists('PluginNextoolConfig')) {
      try {
         $prov = PluginNextoolConfig::getProvisioning();
         if ($prov['client_secret'] === '') {
            $dist = Config::getConfigurationValues('plugin:nextool_distribution');
            $identifier = trim((string) ($dist['client_identifier'] ?? ''));
            if ($identifier === '') {
               $gc = PluginNextoolConfig::getConfig();
               $identifier = trim((string) ($gc['client_identifier'] ?? ''));
            }
            $secret = trim((string) ($dist['client_secret'] ?? ''));
            if ($identifier !== '' && $secret !== '') {
               PluginNextoolConfig::setProvisioning($identifier, $secret);
               Toolbox::logInFile('plugin_nextool', 'Provisionamento migrado para o context resiliente (plugin:nextool_provisioning).');
            }
         }
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'Migração de provisionamento falhou: ' . $e->getMessage());
      }
   }

   try {
      $manager = PluginNextoolModuleManager::getInstance();
      $manager->refreshModules();
      Toolbox::logInFile(
         'plugin_nextool',
         sprintf('Install health-check: %d módulos detectados após reinstalação.', count($manager->getAllModules()))
      );
   } catch (Throwable $e) {
      Toolbox::logInFile('plugin_nextool', 'Install health-check falhou: ' . $e->getMessage());
   }

   PluginNextoolPermissionManager::installRights();
   PluginNextoolPermissionManager::syncModuleRights();

   // F2 (5.0.0): registra a CronTask de sincronização do catálogo (idempotente-por-criação).
   if (function_exists('_plugin_nextool_register_crons')) {
      try {
         _plugin_nextool_register_crons();
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'Registro da CronTask catalogSync falhou: ' . $e->getMessage());
      }
   }

   return true;
}

function plugin_nextool_upgrade($old_version) {
   global $DB;

   $result = plugin_nextool_install();

   // Migração 3.7.0: remover coluna legada contract_active de 3 tabelas
   if (version_compare($old_version, '3.7.0', '<')) {
      $migFile = NEXTOOL_PHP_DIR . '/sql/migration_remove_contract_active.sql';
      if (file_exists($migFile)) {
         $DB->runFile($migFile);
         Toolbox::logInFile('plugin_nextool', "Upgrade {$old_version} → 3.7.0: migração contract_active executada.");
      }
   }

   PluginNextoolPermissionManager::syncModuleRights();
   return $result;
}

/**
 * Hook de desinstalação
 * 
 * Remove estrutura de banco de dados e desinstala módulos usando o SQL dedicado.
 */
function plugin_nextool_uninstall() {
   global $DB;

   $manager = PluginNextoolModuleManager::getInstance();
   // ATENÇÃO: NÃO desinstalamos os módulos aqui. Desinstalar o plugin base preserva os
   // módulos INTEGRALMENTE -- registro em glpi_plugin_nextool_main_modules (is_installed/
   // is_enabled/config), tabelas de dados e arquivos no runtime -- para que reinstalar o
   // base no mesmo GLPI traga os módulos de volta exatamente como estavam, sem reativação
   // manual. A remoção real de um módulo (tabelas + arquivos) é exclusiva do botão
   // "Apagar dados" por módulo (purgeModuleData), nunca do uninstall do plugin base.

   $sqlfile = NEXTOOL_PHP_DIR . '/sql/uninstall.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   // PRESERVA a pasta de módulos (NEXTOOL_DOC_DIR/modules) e tudo dentro dela. O uninstall
   // do base limpa APENAS o staging do self-updater (core-update/), que é transitório e
   // carrega o flag .maintenance -- deixá-lo poderia travar o plugin em manutenção no
   // reinstall. A pasta de módulos NUNCA é apagada aqui (só via "Apagar dados" por módulo).
   $coreUpdateStaging = rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update';
   if (is_dir($coreUpdateStaging)) {
      nextool_delete_dir($coreUpdateStaging);
   }

   // Remove cache de descoberta de módulos e diretório temporário de downloads
   $manager->clearCache();

   $tmpRemoteDir = GLPI_TMP_DIR . '/nextool_remote';
   if (is_dir($tmpRemoteDir)) {
      nextool_delete_dir($tmpRemoteDir);
   }

   // Remove configurações do self-updater e da distribuição em glpi_configs.
   $DB->delete('glpi_configs', ['context' => 'plugin:nextool_core_update']);
   $DB->delete('glpi_configs', ['context' => 'plugin:nextool_distribution']);
   // Credenciais de serviços gerenciados (token da instância Evolution): APAGAR no uninstall
   // (LGPD -- credencial não fica em banco de plugin desinstalado). São re-entregues pelo
   // servidor no primeiro Sincronizar após reinstalar.
   $DB->delete('glpi_configs', ['context' => 'plugin:nextool_managed_services']);
   // ATENÇÃO: NÃO apagar 'plugin:nextool_provisioning' (PluginNextoolConfig::PROVISIONING_CONTEXT).
   // O vínculo de provisionamento (client_identifier + segredo HMAC) é estado do
   // AMBIENTE, não config do plugin. Preservá-lo permite que reinstalar no mesmo
   // domínio reuse o segredo (o identifier é determinístico por domínio) e evita
   // o 409 do bootstrap (identifier_already_provisioned). Reset intencional é via
   // "Desvincular ambiente" (cliente) ou "Resetar provisionamento" (admin), nunca
   // no uninstall.

   Toolbox::logInFile('plugin_nextool', 'Plugin base desinstalado: tabelas-base removidas; MÓDULOS (registro, tabelas e arquivos), pasta de módulos e vínculo de provisionamento PRESERVADOS.');

   PluginNextoolPermissionManager::removeRights();

   return true;
}

/**
 * Hook MassiveActions - adiciona ações em massa customizadas por itemtype.
 *
 * O GLPI descobre essas ações via função global do plugin. Para evitar acoplamento
 * com módulos específicos, delegamos para providers registrados pelos módulos ativos.
 *
 * @param string $type
 * @return array<string,string>
 */
function plugin_nextool_MassiveActions($type) {
   return PluginNextoolHookProvidersDispatcher::getMassiveActions((string) $type);
}

/**
 * Hook MassiveActionsFieldsDisplay - exibe campos específicos no formulário de ação em massa "Atualizar".
 * Alguns itemtypes de plugin exigem renderização manual para evitar 500 no core.
 * Delegado para providers.
 *
 * @param array $options ['itemtype' => string, 'options' => array (search option)]
 * @return bool True se tratou o campo, false para deixar o core processar
 */
function plugin_nextool_MassiveActionsFieldsDisplay($options = []) {
   try {
      return PluginNextoolHookProvidersDispatcher::massiveActionsFieldsDisplay((array) $options);
   } catch (\Throwable $e) {
      error_log('[NexTool] MassiveActionsFieldsDisplay error: ' . $e->getMessage());
      return false;
   }
}

/**
 * Hook giveItem para Search - trata exibição de células de itemtypes de plugin.
 * Delegado para providers (evita acoplamento com módulos).
 *
 * @param string|null $itemtype
 * @param int $ID    ID da search option
 * @param array $data Dados da linha
 * @param int $num   Índice da coluna
 * @return string|false Valor formatado ou false para usar o padrão
 */
function plugin_nextool_giveItem($itemtype, $ID, $data, $num) {
   try {
      return PluginNextoolHookProvidersDispatcher::giveItem($itemtype, $ID, $data, $num);
   } catch (\Throwable $e) {
      error_log('[NexTool] giveItem error: ' . $e->getMessage());
      return false;
   }
}

/**
 * Hook redefine_menus - Cria menus de primeiro nível na barra principal.
 *
 * 1. "Nextools" (nativo do plugin) - menu principal com submenu por módulo/admin.
 * 2. Menus adicionais via getRedefineMenuItems() - módulos que precisam de menu
 *    de primeiro nível próprio declaram via método na BaseModule.
 *
 * @param array $menu Menu atual do GLPI
 * @return array Menu modificado
 */
function plugin_nextool_redefine_menus($menu) {
   if (empty($menu)) {
      return $menu;
   }

   // Interface simplificada (helpdesk): dashboard Contract Hours (legado hardcoded)
   // + itens declarados pelos módulos via getHelpdeskMenuItems() (genérico).
   if (Session::getCurrentInterface() === 'helpdesk') {
      try {
         if (PluginNextoolPermissionManager::haveRight('plugin_nextool_contracthours_report', READ)) {
            global $CFG_GLPI;
            $menu['consumo_horas'] = [
               'default' => ($CFG_GLPI['root_doc'] ?? '') . '/plugins/nextool/front/modules.php?module=contracthours&file=dashboard.php',
               'title'   => __('Consumo de Horas', 'nextool_contracthours'),
               'icon'    => 'ti ti-clock-play',
            ];
         }
      } catch (\Throwable $e) {
         // Silenciar se modulo nao carregado
      }

      // Itens de menu helpdesk dos módulos ativos (gate de permissão é do módulo)
      try {
         $hdManager = PluginNextoolModuleManager::getInstance();
         foreach ($hdManager->getActiveModules() as $hdModule) {
            if (!method_exists($hdModule, 'getHelpdeskMenuItems')) {
               continue;
            }
            foreach ($hdModule->getHelpdeskMenuItems() as $hdKey => $hdItem) {
               if (!empty($hdItem['default']) && !isset($menu[$hdKey])) {
                  $menu[$hdKey] = $hdItem;
               }
            }
         }
      } catch (\Throwable $e) {
         // Menu helpdesk nunca pode derrubar o GLPI
      }
      return $menu;
   }

   try {
      return _plugin_nextool_build_menus($menu);
   } catch (\Throwable $e) {
      error_log('[NexTool] redefine_menus error: ' . $e->getMessage());
      return $menu; // GLPI continua com menu padrão
   }
}

/**
 * Construção interna dos menus NexTool - isolada para que redefine_menus
 * possa capturar qualquer exceção sem derrubar o GLPI.
 */
function _plugin_nextool_build_menus($menu) {

   // Verificar permissões globais do NexTool
   $canViewModulesGlobal = PluginNextoolPermissionManager::canViewModules();
   $canAccessAdmin       = PluginNextoolPermissionManager::canAccessAdminTabs();
   $canViewAnyMod        = PluginNextoolPermissionManager::canViewAnyModule();
   $hasGlobalAdmin       = Session::haveRight('config', UPDATE);

   // Se o perfil não tem nenhuma permissão no NexTool, não exibir menu
   if (!$canViewModulesGlobal && !$canAccessAdmin && !$canViewAnyMod && !$hasGlobalAdmin) {
      return $menu;
   }

   global $CFG_GLPI;
   $rootDoc = $CFG_GLPI['root_doc'] ?? '';

   // ---- Menu nativo "Nextools" (independente de módulos) ----
   $nextoolsItem = [
      'title'   => __('Nextools', 'nextool'),
      'icon'    => 'ti ti-tool',
      'types'   => [],
      'content' => [],
   ];

   $configBase = $rootDoc . '/plugins/nextool/front/nextoolconfig.form.php?id=1';

   // Subitem "Módulos": requer permissão global de módulos OU acesso a algum módulo
   if ($canViewModulesGlobal || $canViewAnyMod || $hasGlobalAdmin) {
      $nextoolsItem['content']['modulos'] = [
         'title' => __('Módulos', 'nextool'),
         'page'  => $configBase . '&forcetab=PluginNextoolMainConfig$1',
         'icon'  => 'ti ti-puzzle',
      ];
   }

   // Subitens admin removidos do menu principal - acessíveis apenas via abas internas

   $modManager = null;
   try {
      if (class_exists('PluginNextoolModuleManager')) {
         $modManager = PluginNextoolModuleManager::getInstance();
      }
   } catch (Throwable $e) {
      // Silenciar - ModuleManager pode não estar disponível durante instalação/desinstalação do plugin
   }

   // Abas dinâmicas: cada módulo instalado com config (exceto standalone)
   // Cada aba de módulo só aparece se o perfil tem READ no módulo e o módulo está ativo
   $moduleConfigTabs = PluginNextoolMainConfig::getModuleConfigTabs();
   foreach ($moduleConfigTabs as $tabNum => $meta) {
      $moduleKey = $meta['module_key'] ?? '';

      if ($modManager !== null && $moduleKey !== '') {
         $mod = $modManager->getModule($moduleKey);
         if ($mod && !$mod->isEnabled()) {
            continue;
         }
      }

      if ($moduleKey !== '' && !PluginNextoolPermissionManager::canViewModule($moduleKey) && !$hasGlobalAdmin) {
         continue;
      }
      $key = 'module_' . $moduleKey;
      $nextoolsItem['content'][$key] = [
         'title' => $meta['name'],
         'page'  => $configBase . '&forcetab=PluginNextoolMainConfig$' . $tabNum,
         'icon'  => $meta['icon'],
      ];
   }

   // Módulos standalone instalados: submenu aponta para getConfigPage()
   // Aparece no menu quando instalado E ativo.
   if ($modManager !== null) {
      try {
         foreach ($modManager->getAllModules() as $mk => $mod) {
            if (!$mod->isInstalled() || !$mod->isEnabled()) {
               continue;
            }
            if (method_exists($mod, 'usesStandaloneConfig') && $mod->usesStandaloneConfig() && $mod->hasConfig()) {
               if (!PluginNextoolPermissionManager::canViewModule($mk)) {
                  continue;
               }
               $key = 'module_' . $mk;
               $nextoolsItem['content'][$key] = [
                  'title' => $mod->getName(),
                  'page'  => $mod->getConfigPage(),
                  'icon'  => $mod->getIcon(),
               ];
            }
         }
      } catch (Throwable $e) {
         // Silenciar erros na construção do menu
      }
   }

   // Ordem fixa: Módulos primeiro, depois módulos dinâmicos e standalone
   $order = ['modulos'];
   $content = $nextoolsItem['content'];
   $nextoolsItem['content'] = [];
   foreach ($order as $k) {
      if (isset($content[$k])) {
         $nextoolsItem['content'][$k] = $content[$k];
      }
   }
   foreach ($moduleConfigTabs as $tabNum => $meta) {
      $key = 'module_' . $meta['module_key'];
      if (isset($content[$key])) {
         $nextoolsItem['content'][$key] = $content[$key];
      }
   }
   // Módulos standalone (não presentes em $moduleConfigTabs) na ordem que restou
   foreach ($content as $k => $v) {
      if (!isset($nextoolsItem['content'][$k])) {
         $nextoolsItem['content'][$k] = $v;
      }
   }

   // Só inserir "Nextools" se tem conteúdo (perfil pode não ter nenhuma permissão)
   if (!empty($nextoolsItem['content'])) {
      $ordered = [];
      $inserted = false;
      foreach ($menu as $key => $value) {
         if ($key === 'nextools') continue;
         if (($key === 'ritecadmin' || $key === 'config') && !$inserted) {
            $ordered['nextools'] = $nextoolsItem;
            $inserted = true;
         }
         $ordered[$key] = $value;
      }
      if (!$inserted) {
         $ordered['nextools'] = $nextoolsItem;
      }
      $menu = $ordered;
   }

   // ---- Menus adicionais via getRedefineMenuItems() (genérico) ----
   try {
      if ($modManager === null) {
         $modManager = PluginNextoolModuleManager::getInstance();
      }
      foreach ($modManager->getActiveModules() as $rdKey => $rdModule) {
         if (!method_exists($rdModule, 'getRedefineMenuItems')) {
            continue;
         }
         if (!PluginNextoolPermissionManager::canViewModule($rdKey)) {
            continue;
         }
         $menuData = $rdModule->getRedefineMenuItems();
         if (is_array($menuData) && !empty($menuData['menu_key']) && !empty($menuData['menu'])) {
            $mKey = $menuData['menu_key'];
            if (empty($menu[$mKey])) {
               $menu[$mKey] = $menuData['menu'];
            } else {
               // Merge conteúdo se menu já existe
               if (!empty($menuData['menu']['content'])) {
                  $menu[$mKey]['content'] = array_merge(
                     $menu[$mKey]['content'] ?? [],
                     $menuData['menu']['content']
                  );
               }
            }
         }
      }
   } catch (Throwable $e) {
      // Silenciar erros na construção de menus de módulos
   }

   return $menu;
}

function nextool_delete_dir(string $dir): void {
   require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
   PluginNextoolFileHelper::deleteDirectory($dir, false);
}
