<?php
/**
 * Nextools - Plugin Configuration Form
 *
 * Formulário principal de configuração do plugin Nextools.
 * Incluído via setup.class.php::displayTabContentForItem().
 * Assume que o GLPI já carregou todos os includes necessários.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

// Não precisa incluir includes.php pois já está carregado
// O arquivo é chamado via include no contexto do GLPI

global $DB;

require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

$canViewModules     = PluginNextoolPermissionManager::canViewModules();
$canManageModules   = PluginNextoolPermissionManager::canManageModules();
$canPurgeModules    = PluginNextoolPermissionManager::canPurgeModuleData();
$canViewAdminTabs   = PluginNextoolPermissionManager::canAccessAdminTabs();
$canManageAdminTabs = PluginNextoolPermissionManager::canManageAdminTabs();
$canViewAnyModule   = PluginNextoolPermissionManager::canViewAnyModule();

// Obtém configuração atual
$config    = PluginNextoolConfig::getConfig();
$distributionSettings = PluginNextoolConfig::getDistributionSettings();
$distributionBaseUrl  = $distributionSettings['base_url'] ?? '';
$distributionClientIdentifier = $distributionSettings['client_identifier'] ?? ($config['client_identifier'] ?? '');
$distributionClientSecret = $distributionSettings['client_secret'] ?? '';

require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';
// Segredo HMAC: não exibir em HTML, não registrar em logs (regra global de segurança).
$hmacSecretRow = null;
if ($distributionClientIdentifier !== '') {
   $hmacSecretRow = PluginNextoolDistributionClient::getEnvSecretRow($distributionClientIdentifier);
   if ($distributionClientSecret === '' && $hmacSecretRow && !empty($hmacSecretRow['client_secret'])) {
      $distributionClientSecret = (string)$hmacSecretRow['client_secret'];
   }
}

$distributionConfigured = $distributionBaseUrl !== '' && $distributionClientIdentifier !== '' && $distributionClientSecret !== '';

// Configuração de licença (tabela específica)
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/logmaintenance.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/configviewstate.class.php';
PluginNextoolLogMaintenance::maybeRun();
$licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();
$licenseViewState = PluginNextoolConfigViewState::fromLicenseConfig($licenseConfig);
$contractActive = $licenseViewState['contractActive'];
$licenseStatusCode = $licenseViewState['licenseStatusCode'];
$licenseWarnings = $licenseViewState['licenseWarnings'];
$allowedModules = $licenseViewState['allowedModules'];
$hasWildcardAll = $licenseViewState['hasWildcardAll'];
$licensesSnapshot = $licenseViewState['licensesSnapshot'];
$licenseTier = $licenseViewState['licenseTier'];
$licensePlanLabel = $licenseViewState['licensePlanLabel'];
$licensePlanDescription = $licenseViewState['licensePlanDescription'];
$licensePlanBadgeClass = $licenseViewState['licensePlanBadgeClass'];
$isLicenseActive = $licenseViewState['isLicenseActive'];
$isFreeTier = $licenseViewState['isFreeTier'];
$hasValidatedPlan = $licenseViewState['hasValidatedPlan'];
$hasAssignedLicense = $licenseViewState['hasAssignedLicense'];
$hasAcceptedPolicies = $licenseViewState['hasAcceptedPolicies'];
$requiresPolicyAcceptance = $licenseViewState['requiresPolicyAcceptance'];


// Carrega ModuleManager para listar módulos
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/validationattempt.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulecatalog.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulecardhelper.class.php';

$manager = PluginNextoolModuleManager::getInstance();
$loadedModules = $manager->getAllModules();

// Catálogo agora vem direto do banco (fonte única da verdade)
// PluginNextoolModuleCatalog::all() já lê de glpi_plugin_nextool_main_modules
$catalogMeta = PluginNextoolModuleCatalog::all();

$contactModuleOptions = [];
foreach ($catalogMeta as $moduleKey => $meta) {
   $contactModuleOptions[$moduleKey] = $meta['name'] ?? ucfirst($moduleKey);
}
ksort($contactModuleOptions);

// Verifica se pelo menos um módulo foi liberado (is_available = 1)
$modulesUnlocked = false;
foreach ($catalogMeta as $moduleKey => $meta) {
   if (!empty($meta['is_available'])) {
      $modulesUnlocked = true;
      break;
   }
}
// Aceite das Políticas de Uso deve ser solicitado apenas uma vez por ambiente.
// Usar "catálogo liberado" como proxy fazia o botão Sincronizar pedir confirmação
// repetidamente (ex.: após falhas temporárias ou alterações locais).

if ($requiresPolicyAcceptance) {
   $heroPlanLabel = __('Não validado', 'nextool');
   $heroPlanBadgeClass = 'bg-secondary';
   $heroPlanDescription = '';
} elseif (!$modulesUnlocked) {
   $heroPlanLabel = __('Catálogo pendente', 'nextool');
   $heroPlanBadgeClass = 'bg-secondary';
   $heroPlanDescription = __('As Políticas de Uso já foram aceitas. Clique em Sincronizar para atualizar o catálogo oficial de módulos.', 'nextool');
} else {
   $heroPlanLabel = $isLicenseActive ? $licensePlanLabel : __('Gratuito', 'nextool');
   $heroPlanBadgeClass = $isLicenseActive ? $licensePlanBadgeClass : 'bg-teal';
   $heroPlanDescription = $isLicenseActive
      ? $licensePlanDescription
      : __('Nenhuma licença ativa detectada. O ambiente permanece no plano gratuito até que uma licença válida seja vinculada.', 'nextool');
}

$modulesState = [];
$stats = [
   'total'     => 0,
   'installed' => 0,
   'enabled'   => 0,
   'disabled'  => 0,
];

// Mapeamento module_key -> tabNum para links "Configurações" apontarem para a aba do módulo em nextoolconfig
$moduleConfigTabMap = [];
if (class_exists('PluginNextoolMainConfig')) {
   foreach (PluginNextoolMainConfig::getModuleConfigTabs() as $tabNum => $tabMeta) {
      $moduleConfigTabMap[$tabMeta['module_key'] ?? ''] = $tabNum;
   }
}
$nextoolConfigBaseUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1';

// Catálogo já contém todos os módulos do banco
$allModuleKeys = array_keys($catalogMeta);
PluginNextoolPermissionManager::syncModuleRights($allModuleKeys);

$currentPluginVersion = null;
if (function_exists('plugin_version_nextool')) {
   $info = plugin_version_nextool();
   $currentPluginVersion = isset($info['version']) ? (string) $info['version'] : null;
}

foreach ($allModuleKeys as $moduleKey) {
   $meta = $catalogMeta[$moduleKey] ?? [];

   if (empty($meta)) {
      continue;
   }

   // Catálogo já vem com is_available do banco
   $catalogIsEnabled = (bool)($meta['is_available'] ?? false);
   if (!$catalogIsEnabled) {
      // Não exibe módulos desativados no catálogo remoto.
      continue;
   }

   $stats['total']++;

   $moduleInstance = $loadedModules[$moduleKey] ?? null;
   $isInstalled = (bool)($meta['is_installed'] ?? false);
   $isEnabled   = (bool)($meta['is_enabled'] ?? false);
   $installedVersion = $meta['version'] ?? null;
   $availableVersion = $meta['version'] ?? null; // Catálogo já retorna available_version
   $moduleDownloaded = is_dir(NEXTOOL_MODULES_BASE . '/' . $moduleKey);
   $requiresRemoteDownload = !$moduleDownloaded && $catalogIsEnabled;
   $billingTier = strtoupper($meta['billing_tier'] ?? 'FREE');
   $isPaid = ($billingTier !== 'FREE');
   $isDevModule = ($billingTier === 'DEV');
   
   // Para detectar update, precisamos buscar version instalada do banco
   $updateAvailable = false;
   if ($isInstalled && $DB->tableExists('glpi_plugin_nextool_main_modules')) {
      $rowCheck = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1
      ]);
      if (count($rowCheck)) {
         $row = $rowCheck->current();
         $installedVersion = $row['version'] ?? null;
         $availableVersion = $row['available_version'] ?? $installedVersion;
         $updateAvailable = ($installedVersion && $availableVersion)
            ? version_compare($availableVersion, $installedVersion, '>')
            : false;
      }
   }
   // Se a pasta do módulo não existe, não sinalizar atualização (trata como download pendente)
   if (!$moduleDownloaded) {
      $updateAvailable = false;
   }

   if ($isInstalled) {
      $stats['installed']++;
      if ($isEnabled) {
         $stats['enabled']++;
      }
   }
   if (!$hasValidatedPlan) {
      $isAllowedByPlan = false;
   } elseif ($isDevModule) {
      // Módulos DEV: apenas plano DESENVOLVIMENTO
      $isAllowedByPlan = ($licenseTier === 'DESENVOLVIMENTO');
   } elseif ($licenseTier === 'ENTERPRISE') {
      // Enterprise: todos exceto DEV (wildcard já cobre PAID/FREE)
      $isAllowedByPlan = true;
   } elseif ($hasWildcardAll) {
      $isAllowedByPlan = true;
   } elseif (!empty($allowedModules)) {
      $isAllowedByPlan = in_array($moduleKey, $allowedModules, true);
   } else {
      $isAllowedByPlan = true;
   }

   if ($isDevModule) {
      $canUseModule = ($licenseTier === 'DESENVOLVIMENTO') && ($contractActive !== false) && !$isFreeTier && $catalogIsEnabled;
   } elseif ($isPaid) {
      $canUseModule = ($contractActive !== false) && !$isFreeTier && $isAllowedByPlan && $catalogIsEnabled;
   } else {
      $canUseModule = $catalogIsEnabled;
   }

   $hasModuleDbData = $manager->moduleHasData($moduleKey);
   $hasModuleData   = $hasModuleDbData || $moduleDownloaded;
   $moduleHasConfig = $moduleInstance && $moduleInstance->hasConfig();
   $configUrl = null;
   if ($moduleHasConfig && $moduleInstance) {
      // Módulo standalone: sempre usa getConfigPage() direto (página própria)
      if (method_exists($moduleInstance, 'usesStandaloneConfig') && $moduleInstance->usesStandaloneConfig()) {
         $configUrl = $moduleInstance->getConfigPage();
      } else {
         $tabNum = $moduleConfigTabMap[$moduleKey] ?? null;
         if ($tabNum !== null) {
            $configUrl = $nextoolConfigBaseUrl . '&forcetab=' . urlencode('PluginNextoolMainConfig$' . $tabNum);
         } else {
            $configUrl = $moduleInstance->getConfigPage();
         }
      }
   }
   $moduleCanView = PluginNextoolPermissionManager::canViewModule($moduleKey);
   if (!$moduleCanView) {
      continue;
   }
   $moduleCanManage = PluginNextoolPermissionManager::canManageModule($moduleKey);
   $moduleCanPurge = PluginNextoolPermissionManager::canPurgeModuleDataForModule($moduleKey);

   // Ordenação: 1=Atualização disponível, 2=Ativos, 3=Instalados, 4=Disponível para instalar,
   // 5=Bloqueados, 6=Disponível para download (sempre por último)
   $sortGroup = 5;
   if (!empty($updateAvailable)) {
      $sortGroup = 1;
   } elseif ($isEnabled && $moduleDownloaded) {
      $sortGroup = 2;
   } elseif ($isInstalled && $moduleDownloaded) {
      $sortGroup = 3;
   } elseif ($canUseModule && $moduleDownloaded) {
      $sortGroup = 4;  // Pronto para instalar
   } elseif ($canUseModule && $requiresRemoteDownload) {
      $sortGroup = 6;  // Precisa baixar primeiro (sempre por último)
   }

   $modulesState[] = [
      'module_key'        => $moduleKey,
      'name'              => $meta['name'] ?? $moduleKey,
      '_sort_group'       => $sortGroup,
      'description'       => $meta['description'] ?? __('Descrição não fornecida.', 'nextool'),
      'version'           => $isInstalled && $installedVersion ? $installedVersion : $availableVersion,
      'installed_version' => $installedVersion,
      'available_version' => $availableVersion,
      'icon'              => $meta['icon'] ?? 'ti ti-puzzle',
      'billing_tier'      => $billingTier,
      'is_paid'           => $isPaid,
      'is_installed'      => $isInstalled,
      'is_enabled'        => $isEnabled,
      'module_downloaded' => $moduleDownloaded,
      'catalog_is_enabled'=> $catalogIsEnabled,
      'update_available'  => $updateAvailable,
      'has_module_data'   => $hasModuleData,
      'author'            => [
         'name' => NEXTOOL_AUTHOR_NAME,
         'url'  => NEXTOOL_AUTHOR_URL,
      ],
      'plugin_version'       => $currentPluginVersion,
      'min_version_nextools' => $meta['min_version_nextools'] ?? null,
      'actions_html'      => PluginNextoolModuleCardHelper::renderActions([
         'module_key'              => $moduleKey,
         'is_installed'            => $isInstalled,
         'is_enabled'              => $isEnabled,
         'is_paid'                 => $isPaid,
         'requires_remote_download'=> $requiresRemoteDownload,
         'has_validated_plan'      => $hasValidatedPlan,
         'has_assigned_license'    => $hasAssignedLicense,
         'distribution_configured' => $distributionConfigured,
         'can_use_module'          => $canUseModule,
         'has_module_data'         => $hasModuleData,
         'has_module_db_data'      => $hasModuleDbData,
         'module_downloaded'       => $moduleDownloaded,
         'catalog_is_enabled'      => $catalogIsEnabled,
         'update_available'        => $updateAvailable,
         'plugin_version'           => $currentPluginVersion,
         'min_version_nextools'     => $meta['min_version_nextools'] ?? null,
         'upgrade_url'             => NEXTOOL_SITE_URL,
         'data_url'                => Plugin::getWebDir('nextool') . '/front/module_data.php?module=' . urlencode($moduleKey),
         'config_url'              => $configUrl,
         'show_config_button'      => $isInstalled && $moduleHasConfig && $moduleCanView,
         'can_manage_admin_tabs'   => $canManageAdminTabs,
         'can_manage_modules'      => $canManageModules,
         'can_purge_modules'       => $canPurgeModules,
         'can_view_modules'        => $canViewModules,
         'can_manage_module'       => $moduleCanManage,
         'can_purge_module'        => $moduleCanPurge,
         'can_view_module'         => $moduleCanView,
      ]),
   ];
}

usort($modulesState, static function ($a, $b) {
   $ga = $a['_sort_group'] ?? 6;
   $gb = $b['_sort_group'] ?? 6;
   if ($ga !== $gb) {
      return $ga <=> $gb;
   }
   return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$stats['disabled'] = $stats['installed'] - $stats['enabled'];

include GLPI_ROOT . '/plugins/nextool/front/css/config.form.styles.inc.php';

$nextool_show_only_tab = $GLOBALS['nextool_show_only_tab'] ?? null;
$nextool_is_standalone = ($nextool_show_only_tab !== null);

if ($nextool_is_standalone) {
   $tabsRegistry = [
      'modules' => ['id' => 'rt-tab-modulos', 'label' => __('Módulos', 'nextool'), 'icon' => 'ti ti-puzzle', 'allowed' => $canViewAnyModule],
      'contato' => ['id' => 'rt-tab-contato', 'label' => __('Contato', 'nextool'), 'icon' => 'ti ti-headset', 'allowed' => $canViewAdminTabs],
      'licenca' => ['id' => 'rt-tab-licenca', 'label' => __('Licenciamento', 'nextool'), 'icon' => 'ti ti-key', 'allowed' => $canViewAdminTabs],
      'logs'    => ['id' => 'rt-tab-logs', 'label' => __('Logs', 'nextool'), 'icon' => 'ti ti-report-analytics', 'allowed' => $canViewAdminTabs],
   ];
   $canShow = ($nextool_show_only_tab === 'modules' && $canViewAnyModule)
      || (in_array($nextool_show_only_tab, ['contato', 'licenca', 'logs'], true) && $canViewAdminTabs);
   echo "<table class='tab_cadre_fixe nextool-config-table' id='nextool-config-form'><tr><td>";
   if (!$canShow) {
      echo "<div class='alert alert-warning'><i class='ti ti-lock me-2'></i>" . __('Sem permissão para acessar esta seção.', 'nextool') . "</div>";
   }
   $nextool_standalone_output_tab = $canShow ? $nextool_show_only_tab : null;
} else {
   $nextool_standalone_output_tab = null;
}

$tabsRegistry = $tabsRegistry ?? [
   'modules' => [
      'id'      => 'rt-tab-modulos',
      'label'   => __('Módulos', 'nextool'),
      'icon'    => 'ti ti-puzzle',
      'allowed' => $canViewAnyModule,  // Permite visualizar se tem permissão em ALGUM módulo
   ],
   'contato' => [
      'id'      => 'rt-tab-contato',
      'label'   => __('Contato', 'nextool'),
      'icon'    => 'ti ti-headset',
      'allowed' => $canViewAdminTabs,
   ],
   'licenca' => [
      'id'      => 'rt-tab-licenca',
      'label'   => __('Licenciamento', 'nextool'),
      'icon'    => 'ti ti-key',
      'allowed' => $canViewAdminTabs,
   ],
   'logs' => [
      'id'      => 'rt-tab-logs',
      'label'   => __('Logs', 'nextool'),
      'icon'    => 'ti ti-report-analytics',
      'allowed' => $canViewAdminTabs,
   ],
];
$firstTabKey = null;
foreach ($tabsRegistry as $key => $meta) {
   if ($meta['allowed']) {
      $firstTabKey = $key;
      break;
   }
}

// Hero "Plano atual" reutilizável nas abas administrativas em modo standalone
$nextool_hero_standalone = '';
if ($nextool_is_standalone && in_array($nextool_standalone_output_tab, ['modules', 'licenca', 'contato', 'logs'], true) && $canViewAdminTabs) {
   ob_start();
   $nextoolHeroWithMarginTop = false;
   $nextoolHeroDisableSync = false;
   $nextoolHeroHideSync = $requiresPolicyAcceptance;
   include GLPI_ROOT . '/plugins/nextool/front/tabs/config.hero.inc.php';
   $nextool_hero_standalone = ob_get_clean();
}
?>

<?php if (!$nextool_is_standalone): ?>
<table class="tab_cadre_fixe nextool-config-table" id="nextool-config-form"><tr><td>
   <h3>Nextools - Conectando soluções, gerando valor</h3>
     <!-- Abas internas do Nextool -->
     <?php if ($firstTabKey === null): ?>
        <div class="alert alert-warning mt-3">
           <i class="ti ti-lock me-2"></i>
           <?php echo __('Seu perfil não possui permissão para acessar as abas do NexTool.', 'nextool'); ?>
        </div>
        </td></tr></table>
     <?php return; ?>
     <?php endif; ?>
     <ul class="nav nav-tabs mt-3" id="nextool-config-tabs" role="tablist">
        <?php foreach ($tabsRegistry as $key => $tabMeta): if (!$tabMeta['allowed']) { continue; } ?>
        <?php $isActive = ($key === $firstTabKey) ? ' active' : ''; ?>
        <li class="nav-item" role="presentation">
           <button class="nav-link<?php echo $isActive; ?>"
                   id="<?php echo $tabMeta['id']; ?>-link"
                   type="button"
                   data-bs-toggle="tab"
                   data-bs-target="#<?php echo $tabMeta['id']; ?>"
                   role="tab">
              <i class="<?php echo $tabMeta['icon']; ?> me-1"></i><?php echo Html::entities_deep($tabMeta['label']); ?>
           </button>
        </li>
        <?php endforeach; ?>
     </ul>
      <?php if ($canViewAdminTabs): ?>
      <?php
         $nextoolHeroWithMarginTop = true;
         $nextoolHeroDisableSync = false;
         $nextoolHeroHideSync = $requiresPolicyAcceptance;
         include GLPI_ROOT . '/plugins/nextool/front/tabs/config.hero.inc.php';
      ?>
      <?php endif; ?>
<?php endif; ?>

      <?php if (!$nextool_is_standalone): ?><div class="tab-content mt-4" id="nextool-config-tabs-content"><?php endif; ?>

        <?php include GLPI_ROOT . '/plugins/nextool/front/tabs/config.modules.tab.inc.php'; ?>

        <?php include GLPI_ROOT . '/plugins/nextool/front/tabs/config.licenca.tab.inc.php'; ?>

        <?php include GLPI_ROOT . '/plugins/nextool/front/tabs/config.logs.tab.inc.php'; ?>

        <?php include GLPI_ROOT . '/plugins/nextool/front/tabs/config.contato.tab.inc.php'; ?>

      <?php if (!$nextool_is_standalone): ?></div><?php endif; ?>

<?php if (!$nextool_is_standalone): ?></td></tr></table><?php endif; ?>

<?php
if ($nextool_is_standalone) {
   echo "</td></tr></table>";
   unset($GLOBALS['nextool_show_only_tab']);
}
include GLPI_ROOT . '/plugins/nextool/front/config.form.scripts.inc.php';
?>