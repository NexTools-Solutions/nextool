<?php
/**
 * Plugin NexTool Solutions v2.20.1 - Sistema Modular para GLPI 11
 * 
 * Plugin multiferramentas com arquitetura modular completa.
 * Cada ferramenta é um módulo independente que pode ser ativado/desativado.
 * 
 * Arquitetura:
 * - ModuleManager: Gerenciador central (auto-discovery de módulos)
 * - BaseModule: Classe abstrata base para todos os módulos
 * - Módulos: modules/[nome]/[nome].class.php
 * 
 * Documentação completa em: docs/
 * 
 * @version 2.20.1
 * @author Richard Loureiro - linkedin.com/in/richard-ti
 * @license GPLv3+
 * @link https://linkedin.com/in/richard-ti
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

function plugin_version_nextool() {
   return [
      'name'           => 'NexTool Solutions',
      'version'        => '2.20.1',
      'license'        => 'GPLv3+',
      'author'         => 'Richard Loureiro - linkedin.com/in/richard-ti',
      'homepage'       => 'https://ritech.site',
      'minGlpiVersion' => '11.0.0',
   ];
}

/**
 * Inicialização do plugin
 */
function plugin_init_nextool() {
   global $PLUGIN_HOOKS;

   $permissionfile = GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
   if (file_exists($permissionfile)) {
      require_once $permissionfile;
   }

   // Define a página de configuração do plugin (adiciona botão "Configurar" na lista de plugins)
   $PLUGIN_HOOKS['config_page']['nextool'] = 'front/config.php';

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

   // Carrega classe de setup
   $setupfile = GLPI_ROOT . '/plugins/nextool/inc/setup.class.php';
   if (file_exists($setupfile)) {
      require_once $setupfile;
      
      // Registra classe para adicionar tab em "Configurar → Geral"
      Plugin::registerClass('PluginNextoolSetup', [
         'addtabon' => ['Config']
      ]);
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
      
      // Só carrega módulos se plugin já foi instalado
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         try {
            require_once $basefile;
            require_once $managerfile;
            
            $manager = PluginNextoolModuleManager::getInstance();
            $manager->loadActiveModules();
            PluginNextoolPermissionManager::syncModuleRights();
            
         } catch (Exception $e) {
            Toolbox::logInFile('plugin_nextool', "Erro ao carregar módulos: " . $e->getMessage());
         }
      }
   }
}

/**
 * Verifica pré-requisitos
 */
function plugin_nextool_check_prerequisites() {
   if (version_compare(GLPI_VERSION, '11.0', 'lt')) {
      echo "Este plugin requer GLPI >= 11.0";
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

