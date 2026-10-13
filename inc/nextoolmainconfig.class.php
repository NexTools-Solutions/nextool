<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Main Config
 * -------------------------------------------------------------------------
 * Item type nativo com abas verticais: Módulos, Contato, Licenciamento,
 * Logs e abas dinâmicas por módulo instalado com página de configuração.
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

require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
require_once __DIR__ . '/compat.class.php';

// Declaração em 3 partes (constantes na interface, corpo na trait, classe declarada
// condicionalmente no fim do arquivo): ver inc/config.class.php e PluginNextoolCompat::typedRightname().

interface PluginNextoolMainConfigConstants {

   const CONFIG_ID = 1;

   /** Tabs fixos (1-5): Módulos, Contato, Licenciamento, Alertas, Logs */
   const FIXED_TAB_COUNT = 5;
   /** Guias de divulgação (nextool-dev#277): números altos, fora da faixa das guias de módulo. */
   const TAB_SERVICOS  = 90;
   /** Guia "Novidades" antiga: saiu em 2026-10-13 (a aba 4 passou a se chamar Novidades). Link antigo para
    *  ela é redirecionado para a 4 em front/nextoolconfig.form.php; o número fica reservado. */
   const TAB_NOVIDADES = 91;
   /** Guia "Sobre" da base (ficha do plugin + como funciona o licenciamento), antes de Logs. */
   const TAB_SOBRE     = 92;
   /** Guia "Manutenção" da base: rotinas, provisionamento e desinstalação, antes de Sobre e Logs. */
   const TAB_MANUTENCAO = 93;
}

trait PluginNextoolMainConfigBody {

   // O $rightname='config' (declarado no fim do arquivo) cobre o bypass de admin global (config UPDATE).
   // Para perfis customizados com permissões granulares do plugin, os métodos
   // can*() abaixo delegam ao PermissionManager - caso contrário CommonDBTM::can()
   // exigiria config/READ e bloquearia qualquer perfil não-admin no display().
   public static function canView(): bool {
      return PluginNextoolPermissionManager::canViewAnyModule()
          || PluginNextoolPermissionManager::canAccessAdminTabs();
   }

   public static function canCreate(): bool {
      return PluginNextoolPermissionManager::canManageAdminTabs();
   }

   public static function canUpdate(): bool {
      return PluginNextoolPermissionManager::canManageAdminTabs()
          || PluginNextoolPermissionManager::canManageModules();
   }

   public static function canDelete(): bool {
      return PluginNextoolPermissionManager::canManageAdminTabs();
   }

   public static function canPurge(): bool {
      return PluginNextoolPermissionManager::canManageAdminTabs();
   }

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_config_display';
   }

   public static function getTypeName($nb = 0) {
      return __('NexTool Solutions', 'nextool');
   }

   /**
    * URL usada pelo botão "lista/pesquisa" do cabeçalho: redireciona para a configuração na aba Módulos.
    */
   public static function getSearchURL($full = true) {
      return PluginNextoolCompat::webDir() . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';
   }

   /**
    * Opções de pesquisa para Search::show(); o GLPI exige pelo menos 'id' como primeiro campo de critério.
    */
   public function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'   => 'common',
         'name' => __('NexTool Solutions', 'nextool'),
      ];

      $tab[] = [
         'id'            => '1',
         'table'         => $this->getTable(),
         'field'         => 'id',
         'name'          => __('ID', 'nextool'),
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

   public static function getFormURL($full = true) {
      return PluginNextoolCompat::webDir() . '/front/nextoolconfig.form.php';
   }

   /**
    * Retorna módulos instalados com página de configuração, para abas dinâmicas.
    * Ordenados por nome.
    * Leitura com $DB->request é adequada; para escrita nesta tabela preferir métodos
    * nativos do modelo (ex.: classe do tipo MainModule) conforme knowledge base.
    *
    * @return array<int, array{module_key: string, name: string, icon: string, config_url: string}>
    */
   /**
    * Cache per-request do resultado de getModuleConfigTabs().
    * Invalidado em clearModuleConfigTabsCache(), chamado por enableModule/disableModule
    * e por applyModulesCatalogSync quando o catálogo muda.
    */
   private static ?array $tabsCache = null;

   public static function getModuleConfigTabs(): array {
      global $DB;

      if (self::$tabsCache !== null) {
         return self::$tabsCache;
      }

      $tabs = [];
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return $tabs;
      }

      $iterator = $DB->request([
         'FROM'  => $table,
         'WHERE' => ['is_installed' => 1, 'is_enabled' => 1],
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

         $name = isset($row['name'])
            ? PluginNextoolModuleCatalog::localized($row['name_i18n'] ?? null, (string) $row['name'])
            : ($module->getName() ?? $moduleKey);
         $icon = $module->getIcon();

         $tabs[$tabNum] = [
            'module_key' => $moduleKey,
            'name'       => $name,
            'icon'       => $icon,
            'config_url' => $configPage,
         ];
         $tabNum++;
      }

      self::$tabsCache = $tabs;
      return $tabs;
   }

   public static function clearModuleConfigTabsCache(): void {
      self::$tabsCache = null;
   }

   /**
    * Retorna IDs de abas válidos (incluindo dinâmicas) para validação de forcetab.
    *
    * @return string[]
    */
   public static function getValidTabIds(): array {
      $ids = [
         'PluginNextoolMainConfig$1',
         'PluginNextoolMainConfig$3',
         'PluginNextoolMainConfig$4',
         'PluginNextoolMainConfig$5',
         'PluginNextoolMainConfig$' . self::TAB_SERVICOS,
         'PluginNextoolMainConfig$' . self::TAB_SOBRE,
         'PluginNextoolMainConfig$' . self::TAB_MANUTENCAO,
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

      // A ordem de exibição é a ordem deste array (o core preserva a inserção), não o número da
      // guia. Os números são fixos (links com forcetab): Logs continua sendo a 5, mas entra por
      // ÚLTIMO, depois das guias de divulgação e das guias de configuração dos módulos.
      $tabs = [
         1 => self::createTabEntry(__('Módulos', 'nextool'), 0, $item::getType(), 'ti ti-puzzle'),
         // 2 (Contato) saiu em 2026-10-09: o suporte é aberto pelo botão Suporte do hero. O número fica vago
         // (não renumerar: links com forcetab apontam para as demais).
         3 => self::createTabEntry(__('Licenciamento', 'nextool'), 0, $item::getType(), 'ti ti-key'),
         // 4 = Novidades (até 2026-10-13 "Alertas"): avisos do servidor e locais + novidades publicadas.
         4 => self::createTabEntry(__('Novidades', 'nextool'), 0, $item::getType(), 'ti ti-sparkles'),
         // nextool-dev#277: números fixos FORA da faixa das guias de módulo (que começam em FIXED_TAB_COUNT + 1):
         // aumentar o FIXED_TAB_COUNT renumeraria as guias de módulo e quebraria links com forcetab.
         self::TAB_SERVICOS  => self::createTabEntry(__('Serviços', 'nextool'), 0, $item::getType(), 'ti ti-briefcase'),
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

      $tabs[self::TAB_MANUTENCAO] = self::createTabEntry(__('Manutenção', 'nextool'), 0, $item::getType(), 'ti ti-tool');
      $tabs[self::TAB_SOBRE] = self::createTabEntry(__('Sobre', 'nextool'), 0, $item::getType(), 'ti ti-info-circle');
      $tabs[5] = self::createTabEntry(__('Logs', 'nextool'), 0, $item::getType(), 'ti ti-report-analytics');

      return $tabs;
   }

   /**
    * Rodapé da configuração (versões, ambiente e links fixos) no fim de TODA aba, das abas da base e das
    * de configuração dos módulos. Não-fatal: falha no rodapé nunca derruba a aba.
    */
   private static function nextoolRenderFooter(): void {
      try {
         include NEXTOOL_PHP_DIR . '/front/tabs/config.footer.inc.php';
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'Rodapé da configuração falhou: ' . $e->getMessage() . "\n");
      }
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if (!$item instanceof self) {
         return false;
      }

      $tabMap = [1 => 'modules', 3 => 'licenca', 4 => 'alertas', 5 => 'logs',
                 self::TAB_SERVICOS => 'servicos', self::TAB_SOBRE => 'sobre',
                 self::TAB_MANUTENCAO => 'manutencao'];
      $moduleTabs = self::getModuleConfigTabs();

      if (isset($tabMap[$tabnum])) {
         $tabKey = $tabMap[$tabnum];
         $GLOBALS['nextool_show_only_tab'] = $tabKey;
         $GLOBALS['nextool_config_form_included'] = true; // ver cabeçalho de config.form.php (#252)
         include NEXTOOL_PHP_DIR . '/front/config.form.php';
         unset($GLOBALS['nextool_show_only_tab'], $GLOBALS['nextool_config_form_included']);
         self::nextoolRenderFooter();
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
         require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
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
         $nextool_redirect_after_save = PluginNextoolCompat::webDir() . '/front/nextoolconfig.form.php?id=' . self::CONFIG_ID . '&forcetab=' . urlencode('PluginNextoolMainConfig$' . $tabnum);
         // Página de teste incluída na config central: sem abas, redirecionar de volta para esta URL
         if (str_ends_with($filename, '.test.php')) {
            $_GET['in_config_form'] = '1';
            $GLOBALS['nextool_config_form_test_url'] = $nextool_redirect_after_save . '&section=test';
         }
         if ($requestedSection !== '') {
            $nextool_redirect_after_save .= '&section=' . urlencode($requestedSection);
         }
         $realConfigPath = realpath($configPath);
         if ($realConfigPath === false || strpos($realConfigPath, realpath(NEXTOOL_MODULES_BASE)) !== 0) {
            echo '<div class="alert alert-danger m-3">' . __('Caminho de configuração inválido.', 'nextool') . '</div>';
            return true;
         }
         include $realConfigPath;
         unset($_GET['embedded'], $_GET['in_config_form'], $nextool_redirect_after_save);
         if (isset($GLOBALS['nextool_config_form_test_url'])) {
            unset($GLOBALS['nextool_config_form_test_url']);
         }
         echo '</div>';
         self::nextoolRenderFooter();
         return true;
      }

      return false;
   }
}

// GLPI 12 tipou `$rightname` no core (fatal se a redeclaracao nao tiver o tipo); no 10/11 o tipo
// e fatal. A unica declaracao valida nos 3 majors e condicional: mesmo corpo (trait), so a
// propriedade muda. Trait com a propriedade NAO serve (conflito de composicao, medido em 2026-10-08).
if (PluginNextoolCompat::typedRightname()) {
   class PluginNextoolMainConfig extends CommonDBTM implements PluginNextoolMainConfigConstants {
      use PluginNextoolMainConfigBody;
      public static string $rightname = 'config';
   }
} else {
   class PluginNextoolMainConfig extends CommonDBTM implements PluginNextoolMainConfigConstants {
      use PluginNextoolMainConfigBody;
      public static $rightname = 'config';
   }
}
