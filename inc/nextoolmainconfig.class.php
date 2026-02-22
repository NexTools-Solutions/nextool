<?php
/**
 * Nextools - Main Config
 *
 * Item type nativo com abas verticais: Módulos, Contato, Licenciamento, Logs
 * e abas dinâmicas por módulo instalado com página de configuração.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

class PluginNextoolMainConfig extends CommonDBTM {

   static $rightname = 'config';

   const CONFIG_ID = 1;

   /** Tabs fixos (1-4): Módulos, Contato, Licenciamento, Logs */
   const FIXED_TAB_COUNT = 4;

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_config_display';
   }

   public static function getTypeName($nb = 0) {
      return __('Nextools', 'nextool');
   }

   /**
    * URL usada pelo botão "lista/pesquisa" do cabeçalho: redireciona para a configuração na aba Módulos.
    */
   public static function getSearchURL($full = true) {
      return Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';
   }

   /**
    * Opções de pesquisa para Search::show(); o GLPI exige pelo menos 'id' como primeiro campo de critério.
    */
   public function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'   => 'common',
         'name' => __('Nextools', 'nextool'),
      ];

      $tab[] = [
         'id'            => '1',
         'table'         => $this->getTable(),
         'field'         => 'id',
         'name'          => __('ID'),
         'searchtype'    => ['equals', 'notequals', 'lessthan', 'morethan'],
         'datatype'      => 'number',
         'massiveaction' => false,
      ];

      return $tab;
   }

   public function defineTabs($options = []) {
      $ong = [];
      $this->addStandardTab(self::class, $ong, $options);
      return $ong;
   }

   /**
    * Retorna módulos instalados com página de configuração, para abas dinâmicas.
    * Ordenados por nome.
    * Leitura com $DB->request é adequada; para escrita nesta tabela preferir métodos
    * nativos do modelo (ex.: classe do tipo MainModule) conforme knowledge base.
    *
    * @return array<int, array{module_key: string, name: string, icon: string, config_url: string}>
    */
   public static function getModuleConfigTabs(): array {
      global $DB;

      $tabs = [];
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return $tabs;
      }

      $iterator = $DB->request([
         'FROM'  => $table,
         'WHERE' => ['is_installed' => 1],
         'ORDER' => 'name',
      ]);

      $manager = PluginNextoolModuleManager::getInstance();
      $tabNum = self::FIXED_TAB_COUNT + 1;

      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'] ?? '';
         if ($moduleKey === '') {
            continue;
         }

         if (!PluginNextoolPermissionManager::canViewModule($moduleKey)) {
            continue;
         }

         $module = $manager->getModule($moduleKey);
         if (!$module || !$module->hasConfig()) {
            continue;
         }

         // Módulos com página standalone não aparecem como aba no NexTool
         if (method_exists($module, 'usesStandaloneConfig') && $module->usesStandaloneConfig()) {
            continue;
         }

         $configPage = $module->getConfigPage();
         if ($configPage === null || $configPage === '') {
            continue;
         }

         // getConfigPage() já retorna URL completa (via getFrontPath)
         $configUrl = $configPage;
         $name = $row['name'] ?? $module->getName() ?? $moduleKey;
         $icon = $module->getIcon();

         $tabs[$tabNum] = [
            'module_key' => $moduleKey,
            'name'       => $name,
            'icon'       => $icon,
            'config_url' => $configUrl,
         ];
         $tabNum++;
      }

      return $tabs;
   }

   /**
    * Retorna IDs de abas válidos (incluindo dinâmicas) para validação de forcetab.
    *
    * @return string[]
    */
   public static function getValidTabIds(): array {
      $ids = [
         'PluginNextoolMainConfig$1',
         'PluginNextoolMainConfig$2',
         'PluginNextoolMainConfig$3',
         'PluginNextoolMainConfig$4',
      ];
      $moduleTabs = self::getModuleConfigTabs();
      foreach (array_keys($moduleTabs) as $tabNum) {
         $ids[] = 'PluginNextoolMainConfig$' . $tabNum;
      }
      return $ids;
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if (!$item instanceof self) {
         return '';
      }

      $tabs = [
         1 => self::createTabEntry(__('Módulos', 'nextool'), 0, $item::getType(), 'ti ti-puzzle'),
         2 => self::createTabEntry(__('Contato', 'nextool'), 0, $item::getType(), 'ti ti-headset'),
         3 => self::createTabEntry(__('Licenciamento', 'nextool'), 0, $item::getType(), 'ti ti-key'),
         4 => self::createTabEntry(__('Logs', 'nextool'), 0, $item::getType(), 'ti ti-report-analytics'),
      ];

      $moduleTabs = self::getModuleConfigTabs();
      foreach ($moduleTabs as $tabNum => $meta) {
         $tabs[$tabNum] = self::createTabEntry(
            $meta['name'],
            0,
            $item::getType(),
            $meta['icon']
         );
      }

      return $tabs;
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if (!$item instanceof self) {
         return false;
      }

      $tabMap = [1 => 'modules', 2 => 'contato', 3 => 'licenca', 4 => 'logs'];
      $moduleTabs = self::getModuleConfigTabs();

      if (isset($tabMap[$tabnum])) {
         $tabKey = $tabMap[$tabnum];
         $GLOBALS['nextool_show_only_tab'] = $tabKey;
         include GLPI_ROOT . '/plugins/nextool/front/config.form.php';
         unset($GLOBALS['nextool_show_only_tab']);
         return true;
      }

      if (isset($moduleTabs[$tabnum])) {
         $meta = $moduleTabs[$tabnum];
         $configUrl = $meta['config_url'];
         $moduleKey = $meta['module_key'];

         // Extrai nome do arquivo da URL (ex: modules.php?module=X&file=config.php)
         $query = parse_url($configUrl, PHP_URL_QUERY);
         $params = [];
         if ($query !== null) {
            parse_str($query, $params);
         }
         $filename = $params['file'] ?? 'config.php';

         // Se section=test na URL e o módulo tem página de teste ({moduleKey}.test.php), carrega dentro da aba
         $requestedSection = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
         require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
         if ($requestedSection === 'test') {
            $testFilename = $moduleKey . '.test.php';
            $testPath = NEXTOOL_MODULES_BASE . '/' . $moduleKey . '/front/' . $testFilename;
            if (is_file($testPath)) {
               $filename = $testFilename;
            }
         }
         $configPath = NEXTOOL_MODULES_BASE . '/' . $moduleKey . '/front/' . $filename;
         if (!is_file($configPath)) {
            echo '<div class="alert alert-warning m-3">' . __('Arquivo de configuração não encontrado.', 'nextool') . '</div>';
            return true;
         }

         echo '<div class="m-3">';
         $_GET['embedded'] = '1';
         $nextool_redirect_after_save = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=' . self::CONFIG_ID . '&forcetab=' . urlencode('PluginNextoolMainConfig$' . $tabnum);
         // Página de teste incluída na config central: sem abas, redirecionar de volta para esta URL
         if (str_ends_with($filename, '.test.php')) {
            $_GET['in_config_form'] = '1';
            $GLOBALS['nextool_config_form_test_url'] = $nextool_redirect_after_save . '&section=test';
         }
         if ($requestedSection !== '') {
            $nextool_redirect_after_save .= '&section=' . urlencode($requestedSection);
         }
         include $configPath;
         unset($_GET['embedded'], $_GET['in_config_form'], $nextool_redirect_after_save);
         if (isset($GLOBALS['nextool_config_form_test_url'])) {
            unset($GLOBALS['nextool_config_form_test_url']);
         }
         echo '</div>';
         return true;
      }

      return false;
   }
}
