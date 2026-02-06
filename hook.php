<?php
/**
 * Hooks do plugin Nextool v2.x
 *
 * Fluxo de instalação:
 * 1. Executa sql/install.sql (cria tabelas básicas e seeds)
 * 2. Gera automaticamente o client_identifier
 *
 * Fluxo de desinstalação:
 * 1. Executa sql/uninstall.sql (remove apenas tabelas operacionais)
 * 2. Remove diretórios físicos dos módulos baixados
 *
 * @version 2.x-dev
 * @author Richard Loureiro - linkedin.com/in/richard-ti
 * @license GPLv3+
 * @link https://linkedin.com/in/richard-ti
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/inc/modulemanager.class.php';
require_once __DIR__ . '/inc/basemodule.class.php';
require_once __DIR__ . '/inc/permissionmanager.class.php';
require_once __DIR__ . '/inc/hookprovidersdispatcher.class.php';

function plugin_nextool_install() {
   global $DB;

   $sqlfile = GLPI_ROOT . '/plugins/nextool/sql/install.sql';
   if (file_exists($sqlfile)) {
      $DB->runFile($sqlfile);
   }

   $modulesTable = 'glpi_plugin_nextool_main_modules';
   if ($DB->tableExists($modulesTable) && !$DB->fieldExists($modulesTable, 'description')) {
      $DB->doQuery(
         "ALTER TABLE `{$modulesTable}`
          ADD COLUMN `description` text DEFAULT NULL
          COMMENT 'Descrição do módulo' AFTER `name`"
      );
   }

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

   // Remove diretórios de módulos baixados (dados continuarão no banco)
   $modulesDir = GLPI_ROOT . '/plugins/nextool/modules';
   if (is_dir($modulesDir)) {
      foreach (glob($modulesDir . '/*') as $entry) {
         if (is_dir($entry)) {
            nextool_delete_dir($entry);
         }
      }
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

function nextool_delete_dir(string $dir): void {
   if (!is_dir($dir)) {
      return;
   }

   $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
   );

   foreach ($iterator as $item) {
      $path = $item->getPathname();
      if ($item->isDir()) {
         @rmdir($path);
      } else {
         @unlink($path);
      }
   }

   @rmdir($dir);
}