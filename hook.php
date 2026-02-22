<?php
/**
 * Nextools - Hooks
 *
 * Registro de hooks do plugin: instalação, desinstalação, MassiveActions, giveItem, menus.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
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

function plugin_nextool_install() {
   global $DB;

   if (!is_dir(NEXTOOL_DOC_DIR)) {
      @mkdir(NEXTOOL_DOC_DIR, 0755, true);
   }
   if (!defined('NEXTOOL_MODULES_BASE')) {
      require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
   }
   if (!is_dir(NEXTOOL_MODULES_BASE)) {
      @mkdir(NEXTOOL_MODULES_BASE, 0755, true);
   }

   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/install.sql';
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

   $configfile = GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
   if (file_exists($configfile)) {
      require_once $configfile;
      if (class_exists('PluginNextoolConfig')) {
         try {
            PluginNextoolConfig::getConfig();
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao inicializar client_identifier durante install: " . $e->getMessage());
         }
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

   return true;
}

function plugin_nextool_upgrade($old_version) {
   $result = plugin_nextool_install();
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
   $modulesTable = 'glpi_plugin_nextool_main_modules';
   if ($DB->tableExists($modulesTable)) {
      $iterator = $DB->request([
         'FROM'  => $modulesTable,
         'WHERE' => ['is_installed' => 1]
      ]);
      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'] ?? '';
         if ($moduleKey !== '') {
            try {
               $manager->uninstallModule($moduleKey);
            } catch (Throwable $e) {
               Toolbox::logInFile('plugin_nextool', sprintf('Falha ao desinstalar módulo %s durante plugin_uninstall: %s', $moduleKey, $e->getMessage()));
            }
         }
      }
   }

   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/uninstall.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   // Remove diretório de dados do plugin em files/_plugins/nextool (módulos baixados; dados continuarão no banco)
   if (is_dir(NEXTOOL_DOC_DIR)) {
      nextool_delete_dir(NEXTOOL_DOC_DIR);
   }

   // Remove cache de descoberta de módulos e diretório temporário de downloads
   $manager->clearCache();

   $tmpRemoteDir = GLPI_TMP_DIR . '/nextool_remote';
   if (is_dir($tmpRemoteDir)) {
      nextool_delete_dir($tmpRemoteDir);
   }

   Toolbox::logInFile('plugin_nextool', 'Plugin desinstalado: módulos removidos, caches limpos e diretórios temporários apagados.');

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
   return PluginNextoolHookProvidersDispatcher::massiveActionsFieldsDisplay((array) $options);
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
   return PluginNextoolHookProvidersDispatcher::giveItem($itemtype, $ID, $data, $num);
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

   // Não exibir menus NexTool para perfis de interface simplificada (self-service/helpdesk)
   if (Session::getCurrentInterface() === 'helpdesk') {
      return $menu;
   }

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

   // Subitens administrativos: requer permissão de abas admin OU bypass global
   if ($canAccessAdmin || $hasGlobalAdmin) {
      $nextoolsItem['content']['contato'] = [
         'title' => __('Contato', 'nextool'),
         'page'  => $configBase . '&forcetab=PluginNextoolMainConfig$2',
         'icon'  => 'ti ti-headset',
      ];
      $nextoolsItem['content']['licenciamento'] = [
         'title' => __('Licenciamento', 'nextool'),
         'page'  => $configBase . '&forcetab=PluginNextoolMainConfig$3',
         'icon'  => 'ti ti-key',
      ];
      $nextoolsItem['content']['logs'] = [
         'title' => __('Logs', 'nextool'),
         'page'  => $configBase . '&forcetab=PluginNextoolMainConfig$4',
         'icon'  => 'ti ti-report-analytics',
      ];
   }

   // Abas dinâmicas: cada módulo instalado com config (exceto standalone)
   // Cada aba de módulo só aparece se o perfil tem READ no módulo
   $moduleConfigTabs = PluginNextoolMainConfig::getModuleConfigTabs();
   foreach ($moduleConfigTabs as $tabNum => $meta) {
      $moduleKey = $meta['module_key'] ?? '';
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
   // Aparece no menu quando instalado (mesmo desativado); some apenas ao desinstalar.
   try {
      if (class_exists('PluginNextoolModuleManager')) {
         $standaloneManager = PluginNextoolModuleManager::getInstance();
         if ($standaloneManager !== null) {
            foreach ($standaloneManager->getAllModules() as $mk => $mod) {
               if (!$mod->isInstalled()) {
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
         }
      }
   } catch (Throwable $e) {
      // Silenciar erros na construção do menu
   }

   // Ordem fixa: Módulos primeiro, depois Contato, Licenciamento, Logs, depois módulos
   $order = ['modulos', 'contato', 'licenciamento', 'logs'];
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
      $rdManager = PluginNextoolModuleManager::getInstance();
      foreach ($rdManager->getActiveModules() as $rdKey => $rdModule) {
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
   require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
   PluginNextoolFileHelper::deleteDirectory($dir, false);
}