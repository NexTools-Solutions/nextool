<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Plugin Configuration Form
 * -------------------------------------------------------------------------
 * Formulário principal de configuração do plugin NexTool Solutions.
 * Este arquivo é incluído via setup.class.php::displayTabContentForItem()
 * e assume que o GLPI já carregou todos os includes necessários.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

// Não precisa incluir includes.php pois já está carregado
// O arquivo é chamado via include no contexto do GLPI

global $DB;

require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';

$canViewModules     = PluginNextoolPermissionManager::canViewModules();
$canManageModules   = PluginNextoolPermissionManager::canManageModules();
$canPurgeModules    = PluginNextoolPermissionManager::canPurgeModuleData();
$canViewAdminTabs   = PluginNextoolPermissionManager::canAccessAdminTabs();
$canManageAdminTabs = PluginNextoolPermissionManager::canManageAdminTabs();
$canViewAnyModule   = PluginNextoolPermissionManager::canViewAnyModule();

$coreUpdateAvailable = false;
if ($canViewAdminTabs) {
   require_once NEXTOOL_PHP_DIR . '/inc/coreupdater.class.php';
   $coreUpdateState = PluginNextoolCoreUpdater::getState();
   $coreUpdateAvailable = !empty($coreUpdateState['update_available'])
      || trim((string) ($coreUpdateState['staged_target_version'] ?? '')) !== ''
      || trim((string) ($coreUpdateState['latest_available_version'] ?? '')) !== '';
}

// Obtém configuração atual
$config    = PluginNextoolConfig::getConfig();
$distributionSettings = PluginNextoolConfig::getDistributionSettings();
$distributionBaseUrl  = $distributionSettings['base_url'] ?? '';
$distributionClientIdentifier = $distributionSettings['client_identifier'] ?? ($config['client_identifier'] ?? '');
$distributionClientSecret = $distributionSettings['client_secret'] ?? '';

require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';

$distributionConfigured = $distributionBaseUrl !== '' && $distributionClientIdentifier !== '' && $distributionClientSecret !== '';

// Estado do vínculo de conta (server-driven, persistido em plugin:nextool_account_link pelo validate).
// link_required: download FREE exige vínculo -> a UI troca "Download" por "Vincular conta".
$accountLinkState    = Config::getConfigurationValues('plugin:nextool_account_link');
$accountLinkRequired = ($accountLinkState['link_required'] ?? '0') === '1';
$accountLinked       = ($accountLinkState['linked'] ?? '0') === '1';
$accountEmail        = trim((string) ($accountLinkState['email'] ?? ''));

// Configuração de licença (tabela específica)
require_once NEXTOOL_PHP_DIR . '/inc/licenseconfig.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/logmaintenance.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/configviewstate.class.php';
PluginNextoolLogMaintenance::maybeRun();
$licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();
$modulesEntitlement = PluginNextoolLicenseValidator::getModulesEntitlement();
$licenseViewState = PluginNextoolConfigViewState::fromLicenseConfig($licenseConfig);
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
require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/basemodule.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/validationattempt.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulecatalog.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulecardhelper.class.php';

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
   $isSuspendedView = ($licenseStatusCode === 'SUSPENDED');
   if ($isLicenseActive || ($isSuspendedView && !$isFreeTier)) {
      $heroPlanLabel = $licensePlanLabel;
      $heroPlanBadgeClass = $isSuspendedView ? 'bg-warning text-dark' : $licensePlanBadgeClass;
      $heroPlanDescription = $isSuspendedView
         ? __('Licença suspensa: renove seu contrato para continuar com acesso a suporte e atualizações.', 'nextool')
         : $licensePlanDescription;
   } else {
      $heroPlanLabel = __('Gratuito', 'nextool');
      $heroPlanBadgeClass = 'bg-teal';
      $heroPlanDescription = __('Nenhuma licença ativa detectada.', 'nextool');
   }
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

$hasZipExtension = class_exists('ZipArchive');

foreach ($allModuleKeys as $moduleKey) {
   $meta = $catalogMeta[$moduleKey] ?? [];

   if (empty($meta)) {
      continue;
   }

   // Catálogo já vem com is_available do banco
   $catalogIsEnabled = (bool) ($meta['is_available'] ?? false);
   if (!$catalogIsEnabled) {
      // Não exibe módulos desativados no catálogo remoto.
      continue;
   }

   $stats['total']++;

   $moduleInstance = $loadedModules[$moduleKey] ?? null;
   $isInstalled = (bool) ($meta['is_installed'] ?? false);
   $isEnabled   = (bool) ($meta['is_enabled'] ?? false);
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

   $isSuspended = ($licenseStatusCode === 'SUSPENDED');

   // Entitlement do módulo (vem do modules_entitlement do ContainerAPI via cache)
   $moduleEntitlement = $modulesEntitlement[$moduleKey] ?? null;
   $everLicensed = $moduleEntitlement !== null && !empty($moduleEntitlement['ever_licensed']);

   // can_download_module: controla download e update (requer licença ativa)
   // can_use_module: controla instalar/ativar/desativar (permissivo se ever_licensed + baixado)
   if ($isDevModule) {
      $canDownloadModule = ($licenseTier === 'DESENVOLVIMENTO') && $isLicenseActive && !$isFreeTier && $catalogIsEnabled;
      $canUseModule = $canDownloadModule;
   } elseif ($isPaid) {
      // Download/Update: requer licença ativa
      $canDownloadModule = $isLicenseActive && !$isFreeTier && $isAllowedByPlan && $catalogIsEnabled;
      // Instalar/Ativar: livre se ever_licensed + módulo já baixado
      if ($moduleDownloaded && $everLicensed) {
         $canUseModule = $catalogIsEnabled;
      } elseif ($isSuspended && $moduleDownloaded) {
         $canUseModule = $isAllowedByPlan && $catalogIsEnabled;
      } else {
         $canUseModule = $canDownloadModule;
      }
   } else {
      $canDownloadModule = $catalogIsEnabled;
      $canUseModule = $catalogIsEnabled;
   }

   // Módulo incompatível com a major atual (ex.: módulo G11-only visto no GLPI 10) NÃO é
   // baixável: não há artefato para este GLPI e o card mostra "Licenciar"/"Tenho interesse"
   // (não "Download"). Zerar can_download aqui evita que ele apareça no filtro "Download"
   // (e no contador do chip) como se fosse baixável. O botão em si é decidido antes, pela
   // incompatibilidade, então zerar aqui não altera o CTA exibido.
   $isCompatibleWithMajor = PluginNextoolModuleCardHelper::isCompatibleWithCurrentGlpi(
      ['compat_glpi_majors' => $meta['compat_glpi_majors'] ?? null]
   );
   if (!$isCompatibleWithMajor) {
      $canDownloadModule = false;
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
   // Por acao (2026-07-28): a UI passa a usar os MESMOS helpers do endpoint --
   // botao oferecido == acao autorizada (antes tudo saia de CONFIGURE).
   $moduleCanInstall   = PluginNextoolPermissionManager::canInstallModules();
   $moduleCanToggle    = PluginNextoolPermissionManager::canToggleModule($moduleKey);
   $moduleCanUpdate    = PluginNextoolPermissionManager::canUpdateModule($moduleKey);
   $moduleCanUninstall = PluginNextoolPermissionManager::canUninstallModule($moduleKey);

   // Ordenação por grupo de estado:
   // 1=Update disponível, 2=Ativos ("Configurações"), 3=Instalados DESATIVADOS ("Ativar"),
   // 4=Disponível para instalar, 5=PAID JÁ LICENCIADO ("Download"), 6=Vitrine PAID (sem
   // licença, "Licenciar"), 7=Download (FREE), 8=Bloqueados
   // Buckets de exibição (menor = mais no topo). Dentro de cada um, ordena por downloads.
   // O que o cliente PAGOU vem antes do que ele ainda não comprou (grupo 5 > 6): antes o
   // PAID licenciado caía no mesmo balde dos FREE (abaixo da vitrine) e o módulo comprado
   // aparecia no FIM da lista -- o inverso da expectativa de quem já pagou.
   $sortGroup = 8; // default: bloqueados/edge cases
   if (!empty($updateAvailable)) {
      $sortGroup = 1;
   } elseif ($isEnabled && $moduleDownloaded) {
      $sortGroup = 2; // Ativo -> botão "Configurações" (ANTES dos desativados)
   } elseif ($isInstalled && !$isEnabled && $moduleDownloaded) {
      $sortGroup = 3; // Instalado mas DESATIVADO -> botão "Ativar"
   } elseif ($canUseModule && $moduleDownloaded) {
      $sortGroup = 4;
   } elseif ($isPaid && $canUseModule && $requiresRemoteDownload) {
      $sortGroup = 5; // PAID com entitlement ativo: comprado, falta baixar -> "Download"
   } elseif ($isPaid && !$canUseModule && $requiresRemoteDownload) {
      $sortGroup = 6; // Vitrine PAID: sem licença, botão "Licenciar" visível
   } elseif ($canUseModule && $requiresRemoteDownload) {
      $sortGroup = 7; // FREE disponível para download
   }

   // Módulos novos (<30 dias): apenas marca a flag $isNewModule; NÃO promove mais ao
   // topo -- dentro de cada bucket a prioridade é a quantidade de downloads (desempate 3).
   $isNewModule = false;
   $catalogCreation = $meta['date_creation'] ?? null;
   if ($catalogCreation !== null && !$isInstalled && $sortGroup >= 4) {
      $daysSinceCreation = (int) ((time() - strtotime($catalogCreation)) / 86400);
      if ($daysSinceCreation <= 30) {
         $isNewModule = true;
      }
   }

   $modulesState[] = [
      'module_key'        => $moduleKey,
      'name'              => $meta['name'] ?? $moduleKey,
      '_sort_group'       => $sortGroup,
      '_is_new'           => $isNewModule,
      // Módulo incompatível com a major atual (ex.: módulo G11-only visto no GLPI 10):
      // continua no catálogo, mas vai para o FIM da lista (critério 0 do usort).
      '_incompatible'     => !$isCompatibleWithMajor,
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
      'can_download'      => $canDownloadModule,
      'catalog_is_enabled'=> $catalogIsEnabled,
      'update_available'  => $updateAvailable,
      'has_module_data'   => $hasModuleData,
      'author'            => [
         'name' => NEXTOOL_AUTHOR_NAME,
         'url'  => NEXTOOL_AUTHOR_URL,
      ],
      'plugin_version'       => $currentPluginVersion,
      'min_version_nextools' => $meta['min_version_nextools'] ?? null,
      'website_url'          => $meta['website_url'] ?? null,
      'price_cents'          => $meta['price_cents'] ?? null,
      'category'             => $meta['category'] ?? null,
      'features'             => $meta['features'] ?? [],
      'screenshot_url'       => $meta['screenshot_url'] ?? null,
      'download_count'       => $meta['download_count'] ?? 0,
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
         'can_download_module'     => $canDownloadModule,
         'name'                    => $meta['name'] ?? $moduleKey,
         'has_module_data'         => $hasModuleData,
         'has_module_db_data'      => $hasModuleDbData,
         'module_downloaded'       => $moduleDownloaded,
         'catalog_is_enabled'      => $catalogIsEnabled,
         'update_available'        => $updateAvailable,
         'account_link_required'   => $accountLinkRequired,
         'plugin_version'          => $currentPluginVersion,
         'min_version_nextools'    => $meta['min_version_nextools'] ?? null,
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
         // Permissoes por ACAO (2026-07-28) -- mesmos helpers que o endpoint usa
         'can_install_module'      => $moduleCanInstall,
         'can_toggle_module'       => $moduleCanToggle,
         'can_update_module'       => $moduleCanUpdate,
         'can_uninstall_module'    => $moduleCanUninstall,
         'can_view_module'         => $moduleCanView,
         'is_license_suspended'    => $isSuspended,
         'has_zip_extension'       => $hasZipExtension,
         'website_url'             => $meta['website_url'] ?? null,
         'compat_glpi_majors'      => $meta['compat_glpi_majors'] ?? null,
      ]),
   ];
}

usort($modulesState, static function ($a, $b) {
   // (incompatíveis não são mais forçados ao fim: viraram vitrine com Licenciar/Interesse,
   //  precisam ficar visíveis para conversão -- sorteiam pelos buckets/downloads normais.)
   // 1. Grupo de estado (menor = mais prioritário)
   $ga = $a['_sort_group'] ?? 8;
   $gb = $b['_sort_group'] ?? 8;
   if ($ga !== $gb) {
      return $ga <=> $gb;
   }
   // 2. Dentro do mesmo grupo: PAID antes de FREE (nos grupos de descoberta). Os grupos
   //    5/6/7 já são homogêneos por tier -- isto pesa mesmo nos grupos 4 (baixado, pronto
   //    para instalar) e 8 (bloqueados), que continuam misturando PAID e FREE.
   if ($ga >= 4) {
      $pa = ($a['is_paid'] ?? false) ? 0 : 1;
      $pb = ($b['is_paid'] ?? false) ? 0 : 1;
      if ($pa !== $pb) {
         return $pa <=> $pb;
      }
   }
   // 3. Downloads DESC como desempate
   $da = (int) ($a['download_count'] ?? 0);
   $db = (int) ($b['download_count'] ?? 0);
   if ($da !== $db) {
      return $db <=> $da;
   }
   // 4. Nome como fallback final
   return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$stats['disabled'] = $stats['installed'] - $stats['enabled'];

include NEXTOOL_PHP_DIR . '/front/css/config.form.styles.inc.php';

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
      || (in_array($nextool_show_only_tab, ['contato', 'licenca', 'logs', 'alertas'], true) && $canViewAdminTabs);
   echo "<div class='m-3' id='nextool-config-form'>";
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
      'allowed' => $canViewAnyModule,
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

// Hero "Plano atual" reutilizável nas abas administrativas em modo standalone.
// Exibido para qualquer perfil que enxergue a tela (informativo). Botões Sincronizar
// e "Atualização Disponível" são ocultados para perfis sem canManageAdminTabs.
$nextool_hero_standalone = '';
if ($nextool_is_standalone && in_array($nextool_standalone_output_tab, ['modules', 'licenca', 'contato', 'logs', 'alertas'], true)) {
   ob_start();
   $nextoolHeroWithMarginTop = false;
   $nextoolHeroDisableSync = false;
   $nextoolHeroHideSync = $requiresPolicyAcceptance || !$canManageAdminTabs;
   $nextoolHeroShowCoreUpdate = $coreUpdateAvailable && $canManageAdminTabs;
   $nextoolHeroForcetabMap = [
      'modules' => 'PluginNextoolMainConfig$1',
      'contato' => 'PluginNextoolMainConfig$2',
      'licenca' => 'PluginNextoolMainConfig$3',
      'alertas' => 'PluginNextoolMainConfig$4',
      'logs'    => 'PluginNextoolMainConfig$5',
   ];
   $nextoolHeroForcetab = $nextoolHeroForcetabMap[$nextool_standalone_output_tab] ?? 'PluginNextoolMainConfig$1';
   include NEXTOOL_PHP_DIR . '/front/tabs/config.hero.inc.php';
   $nextool_hero_standalone = ob_get_clean();
}
?>

<?php if (!$nextool_is_standalone): ?>
<div class="m-3" id="nextool-config-form">
   <h3>NexTool Solutions - <?php echo __('Conectando soluções, gerando valor', 'nextool'); ?></h3>
     <!-- Abas internas do Nextool -->
     <?php if ($firstTabKey === null): ?>
        <div class="alert alert-warning mt-3">
           <i class="ti ti-lock me-2"></i>
           <?php echo __('Seu perfil não possui permissão para acessar as abas do NexTool.', 'nextool'); ?>
        </div>
     </div>
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
      <?php
         // Hero informativo: visível para todo perfil que enxerga a tela.
         // Sincronizar e "Atualização Disponível" são ocultados via flags abaixo
         // para perfis sem canManageAdminTabs.
         $nextoolHeroWithMarginTop = true;
         $nextoolHeroDisableSync = false;
         $nextoolHeroHideSync = $requiresPolicyAcceptance || !$canManageAdminTabs;
         $nextoolHeroShowCoreUpdate = $coreUpdateAvailable && $canManageAdminTabs;
         // Em modo não-standalone, o JS resolve a aba ativa dinamicamente.
         $nextoolHeroForcetab = '';
         include NEXTOOL_PHP_DIR . '/front/tabs/config.hero.inc.php';
      ?>
<?php endif; ?>

      <?php if (!$nextool_is_standalone): ?><div class="tab-content mt-4" id="nextool-config-tabs-content"><?php endif; ?>

        <?php include NEXTOOL_PHP_DIR . '/front/tabs/config.modules.tab.inc.php'; ?>

        <?php include NEXTOOL_PHP_DIR . '/front/tabs/config.licenca.tab.inc.php'; ?>

        <?php include NEXTOOL_PHP_DIR . '/front/tabs/config.logs.tab.inc.php'; ?>

        <?php include NEXTOOL_PHP_DIR . '/front/tabs/config.contato.tab.inc.php'; ?>

        <?php include NEXTOOL_PHP_DIR . '/front/tabs/config.alertas.tab.inc.php'; ?>

      <?php if (!$nextool_is_standalone): ?></div></div><?php endif; ?>

<?php
if ($nextool_is_standalone) {
   echo "</div>";
   unset($GLOBALS['nextool_show_only_tab']);
}

// Note: scripts and version vars are included in nextoolconfig.form.php (main page),
// NOT here - because this file is loaded via AJAX tab and <script> tags in innerHTML don't execute.
?>
