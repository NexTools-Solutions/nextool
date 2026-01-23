<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Plugin Configuration Form
 * -------------------------------------------------------------------------
 * Formulário principal de configuração do plugin NexTool Solutions.
 * Este arquivo é incluído via setup.class.php::displayTabContentForItem()
 * e assume que o GLPI já carregou todos os includes necessários.
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

// Não precisa incluir includes.php pois já está carregado
// O arquivo é chamado via include no contexto do GLPI

global $DB;

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
$hmacSecretRow = null;
if ($distributionClientIdentifier !== '') {
   $hmacSecretRow = PluginNextoolDistributionClient::getEnvSecretRow($distributionClientIdentifier);
   if ($distributionClientSecret === '' && $hmacSecretRow && !empty($hmacSecretRow['client_secret'])) {
      $distributionClientSecret = (string)$hmacSecretRow['client_secret'];
   }
}

$distributionConfigured = $distributionBaseUrl !== '' && $distributionClientIdentifier !== '' && $distributionClientSecret !== '';
$configSaveUrl = Plugin::getWebDir('nextool') . '/front/config.save.php';

// Configuração de licença (tabela específica)
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/logmaintenance.class.php';
PluginNextoolLogMaintenance::maybeRun();
$licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();

// Valores iniciais de contrato/status/vencimento a partir do cache persistido
$contractActive    = null;
$licenseStatusCode = null;
$remoteExpiresAt   = null;

if (array_key_exists('contract_active', $licenseConfig)) {
   $raw = $licenseConfig['contract_active'];
   if ($raw === '' || $raw === null) {
      $contractActive = null;
   } else {
      $contractActive = (bool)$raw;
   }
}

if (!empty($licenseConfig['license_status'])) {
   $licenseStatusCode = strtoupper((string)$licenseConfig['license_status']);
}

if (!empty($licenseConfig['expires_at'])) {
   $remoteExpiresAt = $licenseConfig['expires_at'];
}

// Warnings persistidos no cache local (não dispara validação remota automaticamente)
$licenseWarnings = [];
if (!empty($licenseConfig['warnings'])) {
   $decodedWarnings = json_decode($licenseConfig['warnings'], true);
   if (is_array($decodedWarnings)) {
      $licenseWarnings = $decodedWarnings;
   }
}

// Lista de módulos permitidos em cache (se a API já devolveu essa informação)
$allowedModules = [];
$hasWildcardAll = false;
if (!empty($licenseConfig['cached_modules'])) {
   $decoded = json_decode($licenseConfig['cached_modules'], true);
   if (is_array($decoded)) {
      $allowedModules = $decoded;
      $hasWildcardAll = in_array('*', $allowedModules, true);
   }
}

$licensesSnapshot = [];
if (!empty($licenseConfig['licenses_snapshot'])) {
   $decodedLicenses = json_decode($licenseConfig['licenses_snapshot'], true);
   if (is_array($decodedLicenses)) {
      $licensesSnapshot = $decodedLicenses;
   }
}

// Determina o "tier"/plano atual da licença para exibição informativa
// Regra preferencial:
// - Usar sempre que possível o plano retornado pelo administrativo na última validação (via LicenseValidator)
// - Como fallback:
//   - se houver campo "plan" na tabela de licença, usar esse valor (UNKNOWN/FREE/STARTER/PRO/ENTERPRISE)
//   - caso contrário:
//     - last_validation_result = 1  => BUSINESS (licença válida)
//     - last_validation_result = 0  => FREE (modo limitado, inclusive para chave em branco)
//     - sem resultado de validação  => UNKNOWN
$licenseTier = 'UNKNOWN';
$lastResult  = isset($licenseConfig['last_validation_result'])
   ? (int)$licenseConfig['last_validation_result']
   : null;

// Inicialmente, usa o valor persistido como fallback
if (isset($licenseConfig['plan']) && is_string($licenseConfig['plan']) && $licenseConfig['plan'] !== '') {
   $licenseTier = strtoupper($licenseConfig['plan']);
} else {
   if ($lastResult === 1) {
      // Compatibilidade com versões antigas
      $licenseTier = 'BUSINESS';
   } elseif ($lastResult === 0) {
      $licenseTier = 'FREE';
   }
}

// Mapeamento de plano para rótulo amigável, descrição e estilo
$licensePlanLabel = $licenseTier;
$licensePlanDescription = '';
$licensePlanBadgeClass = 'bg-secondary';

switch ($licenseTier) {
   case 'FREE':
      $licensePlanLabel = 'Free';
      $licensePlanDescription = 'Plano gratuito com acesso limitado a módulos selecionados. Ideal para testar o Nextool antes de contratar.';
      $licensePlanBadgeClass = 'bg-teal';
      break;
   case 'STARTER':
      $licensePlanLabel = 'Starter';
      $licensePlanDescription = 'Plano de entrada para quem precisa de alguns módulos essenciais em produção.';
      $licensePlanBadgeClass = 'bg-blue';
      break;
   case 'PRO':
      $licensePlanLabel = 'Pro';
      $licensePlanDescription = 'Plano profissional com acesso ampliado a módulos avançados e mais flexibilidade de uso.';
      $licensePlanBadgeClass = 'bg-indigo';
      break;
   case 'ENTERPRISE':
      $licensePlanLabel = 'Enterprise';
      $licensePlanDescription = 'Plano corporativo com acesso a todos os módulos do Nextool, pensado para grandes ambientes.';
      $licensePlanBadgeClass = 'bg-purple';
      break;
   case 'BUSINESS':
      // Compatibilidade com etapas anteriores; podemos tratar como Pro por enquanto
      $licensePlanLabel = 'Business';
      $licensePlanDescription = 'Plano pago com acesso a módulos licenciados conforme seu contrato atual.';
      $licensePlanBadgeClass = 'bg-primary';
      break;
   case 'UNKNOWN':
   default:
      $licensePlanLabel = 'Não validado';
      $licensePlanDescription = 'Valide sua licença para descobrir seu plano, registrar seu ambiente e desbloquear módulos.';
      $licensePlanBadgeClass = 'bg-secondary';
      break;
}

// Flag auxiliar para ambiente em FREE tier (sem licença vinculada)
$isLicenseActive = ($licenseStatusCode === 'ACTIVE') && ($contractActive !== false);
$isFreeTier = (!$isLicenseActive) || $licenseStatusCode === 'FREE_TIER' || $licenseTier === 'FREE';

// Consideramos que o cliente "validou a licença" (ou aceitou termos)
// quando já existe um plano conhecido diferente de UNKNOWN (FREE, STARTER, PRO, ENTERPRISE, BUSINESS, etc.)
$hasValidatedPlan = ($licenseTier !== 'UNKNOWN');

// Flag para indicar se já existe uma licença atribuída no operacional
$hasAssignedLicense = !empty($licensesSnapshot);

// Flag para planos que liberam módulos pagos (ex.: ENTERPRISE)
$isEnterprisePlan = ($licenseTier === 'ENTERPRISE');


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
$requiresPolicyAcceptance = !$modulesUnlocked;

if ($requiresPolicyAcceptance) {
   $heroPlanLabel = __('Não validado', 'nextool');
   $heroPlanBadgeClass = 'bg-secondary';
   $heroPlanDescription = __('Aceite as Políticas de Uso para sincronizar com o ContainerAPI, registrar seu ambiente e liberar o catálogo oficial de módulos.', 'nextool');
} else {
   $heroPlanLabel = $isLicenseActive ? $licensePlanLabel : 'Free';
   $heroPlanBadgeClass = $isLicenseActive ? $licensePlanBadgeClass : 'bg-teal';
   $heroPlanDescription = $isLicenseActive
      ? $licensePlanDescription
      : __('Nenhuma licença ativa detectada. O ambiente opera no modo FREE até que uma licença válida seja vinculada.', 'nextool');
}

$modulesState = [];
$stats = [
   'total'     => 0,
   'installed' => 0,
   'enabled'   => 0,
   'disabled'  => 0,
];

// Catálogo já contém todos os módulos do banco
$allModuleKeys = array_keys($catalogMeta);
PluginNextoolPermissionManager::syncModuleRights($allModuleKeys);

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
   $moduleDownloaded = is_dir(GLPI_ROOT . '/plugins/nextool/modules/' . $moduleKey);
   $requiresRemoteDownload = !$moduleDownloaded && $catalogIsEnabled;
   $billingTier = strtoupper($meta['billing_tier'] ?? 'FREE');
   $isPaid = $billingTier !== 'FREE';
   
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

   if ($isInstalled) {
      $stats['installed']++;
      if ($isEnabled) {
         $stats['enabled']++;
      }
   }
   if (!$hasValidatedPlan) {
      $isAllowedByPlan = false;
   } elseif ($hasWildcardAll) {
      $isAllowedByPlan = true;
   } elseif (!empty($allowedModules)) {
      $isAllowedByPlan = in_array($moduleKey, $allowedModules, true);
   } else {
      $isAllowedByPlan = true;
   }

   $canUseModule = $isPaid
      ? ($contractActive !== false) && !$isFreeTier && $isAllowedByPlan
      : true;

   if (!$catalogIsEnabled) {
      $canUseModule = false;
   }

   $hasModuleData = $manager->moduleHasData($moduleKey) || $moduleDownloaded;
   $moduleHasConfig = $moduleInstance && $moduleInstance->hasConfig();
   $configUrl = ($moduleHasConfig && $moduleInstance) ? $moduleInstance->getConfigPage() : null;
   $moduleCanView = PluginNextoolPermissionManager::canViewModule($moduleKey);
   if (!$moduleCanView) {
      continue;
   }
   $moduleCanManage = PluginNextoolPermissionManager::canManageModule($moduleKey);
   $moduleCanPurge = PluginNextoolPermissionManager::canPurgeModuleDataForModule($moduleKey);

   $modulesState[] = [
      'module_key'        => $moduleKey,
      'name'              => $dbRow['name'] ?? ($meta['name'] ?? $moduleKey),
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
         'name' => 'Richard Loureiro',
         'url'  => 'https://linkedin.com/in/richard-ti/',
      ],
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
         'module_downloaded'       => $moduleDownloaded,
        'catalog_is_enabled'      => $catalogIsEnabled,
        'update_available'        => $updateAvailable,
        'upgrade_url'             => 'https://nextoolsolutions.ai',
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

$stats['disabled'] = $stats['installed'] - $stats['enabled'];

?>

<style>
   .btn-outline-licensing {
      background-color: #b3541e;
      border-color: #b3541e;
      color: #ffffff;
   }

   .btn-outline-licensing:hover,
   .btn-outline-licensing:focus {
      background-color: #e58d50;
      border-color: #e58d50;
      color: #ffffff;
   }

   .text-licensing {
      color: #b3541e !important;
   }

   .text-licensing-hero {
      color: #FACC15 !important;
   }

   .border-licensing {
      border-color: #b3541e !important;
   }

   .badge-licensing {
      background-color: #b3541e;
      color: #ffffff;
   }

   .btn-hero-validate {
      background-color: #FACC15;
      border-color: #FACC15;
      color: #111827;
   }

   .btn-hero-validate:hover,
   .btn-hero-validate:focus {
      background-color: #FEF9C3;
      border-color: #FEF9C3;
      color: #111827;
   }
</style>

<?php
$tabsRegistry = [
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
?>

<div class="m-3">

   <h3>NexTool Solutions - Conectando soluções, gerando valor</h3>

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

      <?php if ($canViewAdminTabs): ?>
      <!-- Hero de plano / ativação para abas administrativas -->
      <div class="card shadow-sm border-0 mt-3" style="background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%);">
         <div class="card-body text-white">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
               <div>
                  <h4 class="mb-1 d-flex align-items-center gap-2">
                     <i class="ti ti-crown"></i>
                     <span>Plano atual:</span>
                        <span class="badge <?php echo $heroPlanBadgeClass; ?>">
                           <?php echo Html::entities_deep($heroPlanLabel); ?>
                     </span>
                  </h4>
                           <p class="mb-2">
                              <?php echo Html::entities_deep($heroPlanDescription); ?>
                              <br>
                              <span class="small text-warning fw-semibold d-inline-flex align-items-center gap-1">
                                 <i class="ti ti-bolt"></i>
                                 Desbloqueie módulos pagos, integrações avançadas e automações sob demanda.
                              </span>
                              <br>
                              <span class="small text-info fw-semibold d-inline-flex align-items-center gap-1">
                                 <i class="ti ti-plug-connected"></i>
                                 Precisa de um módulo específico ou integração personalizada?
                                 <a href="https://outlook.office.com/bookwithme/user/e52b9e3c38254d21b172fd4f08c18d8e%40jmbasolucoes.com.br?anonymous&ismsaljsauthenabled" target="_blank" class="text-white text-decoration-underline">Agende uma reunião.</a>
                              </span>
                              <br>
                              <span class="small text-licensing-hero fw-semibold d-inline-flex align-items-center gap-1">
                                 <i class="ti ti-lifebuoy"></i>
                                 Planos de licenciamento com suporte oficial, atualizações contínuas e acompanhamento técnico.
                              </span>
                           </p>

                  <?php if ($contractActive === false && !$isFreeTier): ?>
                     <div class="alert alert-danger mt-3 mb-0">
                        <i class="ti ti-ban me-2"></i>
                        Contrato inativo: o acesso a módulos licenciados está bloqueado até a regularização da licença/contrato no RITEC Admin.
                     </div>
                  <?php elseif ($contractActive === true && $licenseStatusCode === 'EXPIRED'): ?>
                     <div class="alert alert-warning mt-3 mb-0 text-dark">
                        <i class="ti ti-alert-triangle me-2"></i>
                        Licença vencida com contrato ativo: os módulos continuam funcionando normalmente, mas recomenda-se renovar a licença para evitar interrupções futuras.
                     </div>
                  <?php endif; ?>
               </div>
               <div class="text-md-end">
                  <button type="button"
                          class="btn btn-hero-validate fw-semibold mb-2"
                          onclick="nextoolValidateLicense(this);"
                          <?php echo $canViewAdminTabs ? '' : ' disabled'; ?>>
                     <i class="ti ti-refresh me-1"></i>
                     Sincronizar
                  </button>
                  <div class="small text-white-50">
                     <a href="https://api.whatsapp.com/send?phone=5532984692962&text=Ol%C3%A1%2C%20gostaria%20de%20falar%20sobre%20os%20produtos%20da%20Nextools." target="_blank" class="text-white text-decoration-underline">
                        Atendimento rápido via WhatsApp
                     </a>
                  </div>
                  <div class="small mt-2">
                     <a href="https://ritech.site/" target="_blank" class="text-white text-decoration-underline">RITECH</a>
                     <span class="text-white-50 mx-1">/</span>
                     <a href="https://jmbasolucoes.com.br/" target="_blank" class="text-white text-decoration-underline">JMBA Soluções</a>
                     <span class="text-white-50 mx-1">/</span>
                     <a href="https://www.linkedin.com/in/richard-ti/" target="_blank" class="text-white text-decoration-underline">LinkedIn</a>
                     <span class="text-white-50 mx-1">/</span>
                     <a href="https://github.com/RPGMais/nextool/blob/main/POLICIES_OF_USE.md" target="_blank" class="text-white text-decoration-underline">Termos de uso</a>
                  </div>
               </div>

               <!-- Configuração de Distribuição Remota -->
            </div>
         </div>
      </div>
      <?php endif; ?>

      <div class="tab-content mt-4" id="nextool-config-tabs-content">

        <!-- TAB 1: Módulos -->
        <?php if ($canViewAnyModule): ?>
        <div class="tab-pane fade<?php echo $firstTabKey === 'modules' ? ' show active' : ''; ?>" id="rt-tab-modulos" role="tabpanel" aria-labelledby="rt-tab-modulos-link">
            <div class="d-flex flex-column gap-3">

               <!-- Card de Módulos -->
               <div class="card shadow-sm">
                  <div class="card-header mb-3 pt-2 border-top rounded-0">
                     <h4 class="card-title ms-5 mb-0">
                        <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
                           <i class="fs-2x ti ti-puzzle"></i>
                        </div>
                        <span>Módulos Disponíveis</span>
                        <span class="badge bg-secondary ms-2"><?php echo $stats['total']; ?> total</span>
                        <span class="badge bg-success ms-1"><?php echo $stats['enabled']; ?> ativos</span>
                     </h4>
                  </div>
                  <div class="card-body">
                     <?php if (!$canManageModules): ?>
                        <div class="alert alert-info">
                           <i class="ti ti-info-circle me-2"></i>
                           <?php echo __('Você possui acesso somente leitura. Os botões de download, instalação e atualização permanecem desabilitados.', 'nextool'); ?>
                        </div>
                     <?php endif; ?>
                     <?php if ($requiresPolicyAcceptance): ?>
                        <div class="alert alert-info mb-0">
                           <div class="d-flex flex-column gap-3 align-items-center text-center text-lg-start">
                              <div class="d-flex align-items-start">
                                 <i class="ti ti-info-circle fs-4 me-3"></i>
                                 <div>
                                    <p class="mb-2">
                                       Para visualizar e instalar os módulos oficiais da NexTool Solutions, é necessário aceitar as
                                       <a href="https://github.com/RPGMais/nextool/blob/main/POLICIES_OF_USE.md" target="_blank" class="text-decoration-underline fw-semibold">Políticas de Uso</a>.
                                    </p>
                                    <p class="mb-0 text-muted small">
                                       Esse processo valida o ambiente em nossos servidores de API, registra o aceite e atualiza a lista local de módulos.
                                    </p>
                                 </div>
                              </div>
                              <div class="w-100" style="max-width: 480px;">
                                 <?php if ($canManageAdminTabs): ?>
                                    <form method="post"
                                          class="d-flex flex-column gap-2 align-items-stretch"
                                          action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>">
                                       <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                                       <?php echo Html::hidden('action', ['value' => 'accept_policies']); ?>
                                       <?php echo Html::hidden('forcetab', ['value' => 'PluginNextoolSetup$1']); ?>
                                       <button type="submit" class="btn btn-primary w-100">
                                          <i class="ti ti-checkbox me-1"></i>
                                          Aceitar políticas e liberar módulos
                                       </button>
                                       <a href="https://github.com/RPGMais/nextool/blob/main/POLICIES_OF_USE.md"
                                          target="_blank"
                                          class="btn btn-link px-0 text-decoration-underline">
                                          Revisar políticas de uso
                                       </a>
                                    </form>
                                 <?php else: ?>
                                    <div class="alert alert-light border mb-0">
                                       <i class="ti ti-lock me-2"></i>
                                       <?php echo __('Somente usuários com permissão de gerenciamento podem liberar o catálogo de módulos.', 'nextool'); ?>
                                    </div>
                                 <?php endif; ?>
                              </div>
                           </div>
                        </div>
                    <?php elseif (empty($modulesState)): ?>
                        <div class="alert alert-info mb-0">
                           <i class="ti ti-info-circle me-2"></i>
                           <?php echo __('Nenhum módulo visível para este perfil. Ajuste as permissões do módulo ou crie um novo módulo em', 'nextool'); ?> <code>inc/modules/[nome]/</code>
                        </div>
                     <?php else: ?>
                        <div class="row g-3">
                           <?php foreach ($modulesState as $module): 
                              $borderClass = $module['is_enabled']
                                 ? 'border-success'
                                 : ($module['is_installed'] ? 'border-warning' : 'border-secondary');
                           ?>
                           <div class="col-md-6">
                              <div class="card border <?php echo $borderClass; ?> h-100">
                                 <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                       <div class="d-flex align-items-center gap-2">
                                          <i class="<?php echo $module['icon']; ?> fs-2x text-muted"></i>
                                          <div>
                                             <h5 class="card-title mb-0"><?php echo Html::entities_deep($module['name']); ?></h5>
                                             <?php
                                                $installedVersion = $module['installed_version'] ?? null;
                                                $availableVersion = $module['available_version'] ?? null;
                                                $versionLabel = $installedVersion
                                                   ? 'v' . $installedVersion
                                                   : ($availableVersion ? 'v' . $availableVersion : '—');
                                                if (!empty($module['update_available']) && $availableVersion) {
                                                   $versionLabel .= ' → v' . $availableVersion;
                                                }
                                             ?>
                                             <small class="text-muted">
                                                <?php echo Html::entities_deep($versionLabel); ?> •
                                             <?php if (is_array($module['author']) && !empty($module['author']['url'])): ?>
                                                   <a href="<?php echo Html::entities_deep($module['author']['url']); ?>"
                                                      target="_blank"
                                                      rel="noopener"
                                                      class="text-decoration-underline">
                                                      <?php echo Html::entities_deep($module['author']['name'] ?? ''); ?>
                                                   </a>
                                                <?php else: ?>
                                                   <?php echo Html::entities_deep(is_array($module['author']) ? ($module['author']['name'] ?? '') : $module['author']); ?>
                                                <?php endif; ?>
                                             </small>
                                          </div>
                                       </div>
                                       <div class="text-end">
                                          <p class="mb-1">
                                             <?php if ($module['is_paid']): ?>
                                               <span class="badge badge-licensing me-1">Módulo Licenciado</span>
                                             <?php else: ?>
                                                <span class="badge bg-teal me-1 text-white">Módulo FREE</span>
                                             <?php endif; ?>
                                             <?php if (!$module['catalog_is_enabled']): ?>
                                                <span class="badge bg-secondary">Indisponível</span>
                                             <?php elseif (!empty($module['update_available'])): ?>
                                                <span class="badge bg-warning text-dark">Atualização disponível</span>
                                             <?php endif; ?>
                                          </p>
                                       </div>
                                    </div>
                                    
                                    <p class="card-text text-muted small mb-3"><?php echo Html::entities_deep($module['description']); ?></p>

                                    <div class="d-flex gap-2 flex-wrap">
                                       <?php echo $module['actions_html']; ?>
                                    </div>

                                 </div>
                              </div>
                           </div>
                           <?php endforeach; ?>
                        </div>
                     <?php endif; ?>
                  </div>
               </div>

            </div>
         </div>
        <?php endif; ?>

        <!-- TAB 2: Licenças e Status -->
        <div class="tab-pane fade" id="rt-tab-licenca" role="tabpanel" aria-labelledby="rt-tab-licenca-link">
           <form method="post" action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>" id="configForm">
              <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
              <?php echo Html::hidden('forcetab', ['value' => 'PluginNextoolSetup$1']); ?>
              <div class="d-flex flex-column gap-3">
                 <div class="card shadow-sm">
                    <div class="card-header mb-3 pt-2 border-top rounded-0">
                       <h4 class="card-title ms-5 mb-0">
                          <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-blue s-1">
                             <i class="fs-2x ti ti-key"></i>
                          </div>
                          <span><?php echo __('Licenças e Status do Ambiente', 'nextool'); ?></span>
                       </h4>
                    </div>
                    <div class="card-body">
                       <?php if (empty($licensesSnapshot)): ?>
                          <div class="alert alert-info mb-4">
                             <i class="ti ti-info-circle me-2"></i>
                             <?php echo __('Nenhuma licença vinculada a este ambiente. Use o ritecadmin para associar licenças e clique em "Validar licença agora".', 'nextool'); ?>
                          </div>
                       <?php elseif ($contractActive === false): ?>
                          <div class="alert alert-danger mb-4">
                             <i class="ti ti-ban me-2"></i>
                             <?php echo __('Contrato inativo: módulos licenciados permanecerão bloqueados até a regularização no ritecadmin.', 'nextool'); ?>
                          </div>
                       <?php elseif ($licenseStatusCode === 'EXPIRED'): ?>
                          <div class="alert alert-warning mb-4">
                             <i class="ti ti-alert-triangle me-2"></i>
                             <?php echo __('Licença expirada. Os módulos ativos continuam funcionando, mas recomendamos renovar a validade.', 'nextool'); ?>
                          </div>
                       <?php elseif ($licenseStatusCode && $licenseStatusCode !== 'ACTIVE'): ?>
                          <div class="alert alert-info mb-4">
                             <i class="ti ti-info-circle me-2"></i>
                             <?php echo __('Estado atual da licença não é ACTIVE. O ambiente opera em modo FREE até que uma licença válida seja aplicada.', 'nextool'); ?>
                          </div>
                       <?php endif; ?>

                       <div class="row g-3">
                          <div class="col-md-6">
                             <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-semibold mb-3"><?php echo __('Licenças do ambiente', 'nextool'); ?></h6>
                                <?php if (empty($licensesSnapshot)): ?>
                                   <p class="text-muted mb-0">
                                      <?php echo __('Nenhum registro encontrado nos servidores NexTool. Entre em contato para acessar os modulos licenciados.', 'nextool'); ?>
                                   </p>
                                <?php else: ?>
                                   <div class="table-responsive">
                                      <table class="table table-sm align-middle mb-0">
                                         <thead>
                                            <tr>
                                               <th><?php echo __('Licença', 'nextool'); ?></th>
                                               <th><?php echo __('Plano', 'nextool'); ?></th>
                                               <th><?php echo __('Contrato', 'nextool'); ?></th>
                                               <th><?php echo __('Validade da licença', 'nextool'); ?></th>
                                               <th><?php echo __('Módulos permitidos', 'nextool'); ?></th>
                                            </tr>
                                         </thead>
                                         <tbody>
                                            <?php foreach ($licensesSnapshot as $licenseRow):
                                               $rowKey = $licenseRow['license_key'] ?? __('(desconhecida)', 'nextool');
                                               $rowPlan = strtoupper($licenseRow['plan'] ?? 'FREE');
                                               $rowContract = !empty($licenseRow['contract_active']);
                                               $rowExpires = $licenseRow['expires_at'] ?? null;
                                               $rowModules = [];
                                               if (!empty($licenseRow['allowed_modules']) && is_array($licenseRow['allowed_modules'])) {
                                                  $rowModules = $licenseRow['allowed_modules'];
                                               }
                                               $planBadge = [
                                                  'FREE'       => 'bg-teal',
                                                  'STARTER'    => 'bg-blue',
                                                  'PRO'        => 'bg-indigo',
                                                  'ENTERPRISE' => 'bg-purple',
                                               ][$rowPlan] ?? 'bg-secondary';
                                               $contractBadge = $rowContract ? 'bg-green' : 'bg-red';
                                               $validityDisplay = __('Sem expiração', 'nextool');
                                               if (!empty($rowExpires)) {
                                                  $formatted = $rowExpires;
                                                  if (class_exists('Html')) {
                                                     $formatted = Html::convDateTime($rowExpires);
                                                  }
                                                  $validityDisplay = $formatted;
                                               }
                                            ?>
                                            <tr>
                                               <td><code><?php echo Html::entities_deep($rowKey); ?></code></td>
                                               <td><span class="badge <?php echo $planBadge; ?>"><?php echo Html::entities_deep(ucfirst(strtolower($rowPlan))); ?></span></td>
                                               <td><span class="badge <?php echo $contractBadge; ?>"><?php echo $rowContract ? __('Ativo', 'nextool') : __('Inativo', 'nextool'); ?></span></td>
                                               <td><?php echo Html::entities_deep($validityDisplay); ?></td>
                                               <td>
                                                  <?php if (empty($rowModules) || in_array('*', $rowModules, true)): ?>
                                                     <span class="badge bg-purple"><?php echo __('Todos os módulos', 'nextool'); ?></span>
                                                  <?php else: ?>
                                                     <?php foreach ($rowModules as $moduleKey): ?>
                                                        <span class="badge bg-teal me-1 mb-1"><?php echo Html::entities_deep($moduleKey); ?></span>
                                                     <?php endforeach; ?>
                                                  <?php endif; ?>
                                               </td>
                                            </tr>
                                            <?php endforeach; ?>
                                         </tbody>
                                      </table>
                                   </div>
                                <?php endif; ?>
                             </div>
                          </div>
                          <div class="col-md-6">
                             <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-semibold mb-3"><?php echo __('Ambiente e módulos', 'nextool'); ?></h6>
                                <dl class="row mb-0 small">
                                   <dt class="col-5 text-muted"><?php echo __('Identificador do ambiente', 'nextool'); ?></dt>
                                   <dd class="col-7 mb-3">
                                      <?php if (!empty($config['client_identifier'])): ?>
                                         <div class="input-group input-group-sm">
                                            <input type="text"
                                                   class="form-control"
                                                   id="rt-client-identifier"
                                                   value="<?php echo Html::entities_deep($config['client_identifier']); ?>"
                                                   readonly>
                                            <button type="button"
                                                    class="btn btn-outline-secondary"
                                                    onclick="navigator.clipboard.writeText(document.getElementById('rt-client-identifier').value); this.innerText='Copiado!'; setTimeout(() => { this.innerText='Copiar'; }, 2000);">
                                               <i class="ti ti-copy me-1"></i><?php echo __('Copiar'); ?>
                                            </button>
                                         </div>
                                      <?php else: ?>
                                         <span class="text-muted"><?php echo __('Não configurado', 'nextool'); ?></span>
                                      <?php endif; ?>
                                   </dd>

                                   <dt class="col-5 text-muted"><?php echo __('URL do ContainerAPI', 'nextool'); ?></dt>
                                   <dd class="col-7 mb-3">
                                      <div class="input-group input-group-sm">
                                         <input type="url"
                                                class="form-control"
                                                id="rt-endpoint-url"
                                                name="endpoint_url"
                                                value="<?php echo Html::entities_deep($distributionBaseUrl); ?>"
                                                placeholder="<?php echo Html::entities_deep(PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL); ?>">
                                         <button type="button"
                                                 class="btn btn-outline-secondary"
                                                 onclick="const el=document.getElementById('rt-endpoint-url'); navigator.clipboard.writeText(el ? el.value : ''); this.innerText='Copiado!'; setTimeout(() => { this.innerText='Copiar'; }, 2000);">
                                            <i class="ti ti-copy me-1"></i><?php echo __('Copiar'); ?>
                                         </button>
                                      </div>
                                      <div class="form-text">
                                         <?php
                                            echo sprintf(
                                               __('Informe a URL pública do ContainerAPI. Deixe em branco para usar o padrão (%s).', 'nextool'),
                                               Html::entities_deep(PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL)
                                            );
                                         ?>
                                      </div>
                                   </dd>

                                   <dt class="col-5 text-muted"><?php echo __('Segredo HMAC', 'nextool'); ?></dt>
                                   <dd class="col-7 mb-3">
                                      <?php if ($distributionClientIdentifier === ''): ?>
                                         <span class="text-muted"><?php echo __('Defina primeiro o identificador do ambiente para habilitar o segredo HMAC.', 'nextool'); ?></span>
                                      <?php elseif ($distributionClientSecret !== ''): ?>
                                         <span class="badge bg-success me-2"><?php echo __('Provisionado', 'nextool'); ?></span>
                                         <?php if ($hmacSecretRow): ?>
                                            <div class="form-text">
                                               <?php
                                                  $createdAt = !empty($hmacSecretRow['date_creation'])
                                                     ? Html::convDateTime($hmacSecretRow['date_creation'])
                                                     : __('desconhecida', 'nextool');
                                                  $updatedAt = !empty($hmacSecretRow['date_mod'])
                                                     ? Html::convDateTime($hmacSecretRow['date_mod'])
                                                     : __('desconhecida', 'nextool');
                                                  echo sprintf(
                                                     __('Gerado em %1$s • Última atualização %2$s', 'nextool'),
                                                     Html::entities_deep($createdAt),
                                                     Html::entities_deep($updatedAt)
                                                  );
                                               ?>
                                            </div>
                                         <?php else: ?>
                                            <div class="form-text">
                                               <?php echo __('Atualizado após a última validação bem-sucedida.', 'nextool'); ?>
                                            </div>
                                         <?php endif; ?>
                                         <div class="d-flex flex-wrap gap-2 mt-2">
                                            <button type="button"
                                                   class="btn btn-outline-primary btn-sm"
                                                   data-secret="<?php echo Html::entities_deep($distributionClientSecret); ?>"
                                                   onclick="nextoolCopyHmacSecret(this);">
                                               <i class="ti ti-copy me-1"></i><?php echo __('Copiar segredo', 'nextool'); ?>
                                            </button>
                                            <button type="button"
                                                   class="btn btn-outline-danger btn-sm"
                                                   onclick="nextoolRegenerateHmac(this);">
                                               <i class="ti ti-refresh me-1"></i><?php echo __('Recriar segredo', 'nextool'); ?>
                                            </button>
                                         </div>
                                      <?php else: ?>
                                         <span class="text-muted d-block"><?php echo __('Aguardando validação para provisionar automaticamente.', 'nextool'); ?></span>
                                         <div class="d-flex flex-wrap gap-2 mt-2">
                                            <button type="button"
                                                   class="btn btn-outline-primary btn-sm"
                                                   onclick="nextoolRegenerateHmac(this);">
                                               <i class="ti ti-refresh me-1"></i><?php echo __('Gerar agora', 'nextool'); ?>
                                            </button>
                                            <a href="https://github.com/RPGMais/nextool/blob/main/POLICIES_OF_USE.md"
                                               target="_blank"
                                               class="btn btn-link px-0 text-decoration-underline">
                                               <?php echo __('Revisar políticas de uso', 'nextool'); ?>
                                            </a>
                                         </div>
                                      <?php endif; ?>
                                   </dd>

                                   <dt class="col-5 text-muted"><?php echo __('Módulos permitidos', 'nextool'); ?></dt>
                                   <dd class="col-7 mb-3">
                                      <?php if (empty($allowedModules)): ?>
                                         <span class="text-muted">
                                            <?php echo __('Nenhuma lista recebida ainda. Após validar a licença, os módulos liberados aparecerão aqui.', 'nextool'); ?>
                                         </span>
                                      <?php elseif (in_array('*', $allowedModules, true)): ?>
                                         <span class="badge bg-purple"><?php echo __('Todos os módulos liberados', 'nextool'); ?></span>
                                      <?php else: ?>
                                         <p class="mb-0">
                                            <?php foreach ($allowedModules as $allowedKey): ?>
                                               <span class="badge bg-teal me-1 mb-1"><?php echo Html::entities_deep($allowedKey); ?></span>
                                            <?php endforeach; ?>
                                         </p>
                                      <?php endif; ?>
                                   </dd>

                                   <?php if (!empty($licenseWarnings)): ?>
                                      <dt class="col-5 text-muted"><?php echo __('Avisos', 'nextool'); ?></dt>
                                      <dd class="col-7 mb-0">
                                         <ul class="list-unstyled mb-0">
                                            <?php foreach ($licenseWarnings as $warning): ?>
                                               <li><i class="ti ti-alert-triangle text-warning me-1"></i><?php echo Html::entities_deep($warning); ?></li>
                                            <?php endforeach; ?>
                                         </ul>
                                      </dd>
                                   <?php endif; ?>
                                </dl>
                             </div>
                          </div>
                       </div>
                    </div>
                 </div>
              </div>
           </form>
        </div>

        <!-- TAB 3: Logs -->

         <?php if ($canViewAdminTabs): ?>
         <div class="tab-pane fade<?php echo $firstTabKey === 'logs' ? ' show active' : ''; ?>" id="rt-tab-logs" role="tabpanel" aria-labelledby="rt-tab-logs-link">
            <div class="card shadow-sm">
               <div class="card-header mb-3 pt-2 border-top rounded-0">
                  <h4 class="card-title ms-5 mb-0">
                     <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-orange s-1">
                        <i class="fs-2x ti ti-report-analytics"></i>
                     </div>
                     <span>Logs de Licenciamento</span>
                  </h4>
               </div>
               <div class="card-body">
                  <p class="text-muted">
                     Histórico das últimas tentativas de validação de licença realizadas pelo Nextool. Útil para troubleshooting
                     de comunicação com o plugin administrativo (<code>ritecadmin</code>) e análise de eventuais falhas de rede ou configuração.
                  </p>

                  <hr class="my-4">

                  <?php PluginNextoolValidationAttempt::showSimpleList(); ?>

                  <hr class="my-5">

                  <h5 class="fw-semibold mb-2"><?php echo __('Auditoria de configuração/licença', 'nextool'); ?></h5>
                  <p class="text-muted">
                     <?php echo __('Registra quem alterou parâmetros globais, chave de licença ou executou validação manual, incluindo os valores anteriores.', 'nextool'); ?>
                  </p>
                  <?php PluginNextoolConfigAudit::showSimpleList(); ?>

                  <hr class="my-5">

                  <h5 class="fw-semibold mb-2"><?php echo __('Auditoria de ações de módulos', 'nextool'); ?></h5>
                  <p class="text-muted">
                     <?php echo __('Lista das últimas instalações, ativações, desativações e remoções de módulos, com usuário, origem e snapshot da licença.', 'nextool'); ?>
                  </p>
                  <?php PluginNextoolModuleAudit::showSimpleList(); ?>
               </div>
            </div>
         </div>
         <?php endif; ?>

        <!-- TAB CONTATO -->
        <?php if ($canViewAdminTabs): ?>
        <div class="tab-pane fade<?php echo $firstTabKey === 'contato' ? ' show active' : ''; ?>" id="rt-tab-contato" role="tabpanel" aria-labelledby="rt-tab-contato-link">
            <div class="card shadow-sm">
               <div class="card-header mb-3 pt-2 border-top rounded-0">
                  <h4 class="card-title ms-5 mb-0">
                     <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-info s-1">
                        <i class="fs-2x ti ti-headset"></i>
                     </div>
                     <span>Fale com o time NexTool Solutions</span>
                  </h4>
               </div>
               <div class="card-body">
                  <form id="nextool-contact-form"
                        action="<?php echo Plugin::getWebDir('nextool') . '/front/contact.form.php'; ?>"
                        method="post"
                        class="needs-validation"
                        novalidate>
                     <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                     <?php echo Html::hidden('contact_client_identifier', ['value' => Html::entities_deep($distributionClientIdentifier)]); ?>
                     <input type="text" name="contact_extra_info" class="d-none" tabindex="-1" autocomplete="off">

                     <div class="row g-3">
                        <div class="col-md-6">
                           <label class="form-label fw-semibold" for="contact-name">Nome completo *</label>
                           <input type="text" class="form-control" id="contact-name" name="contact_name" required>
                           <div class="invalid-feedback">Informe seu nome completo.</div>
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold" for="contact-company">Empresa / Organização</label>
                           <input type="text" class="form-control" id="contact-company" name="contact_company">
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold" for="contact-email">E-mail *</label>
                           <input type="email" class="form-control" id="contact-email" name="contact_email" required>
                           <div class="invalid-feedback">Informe um e-mail válido.</div>
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold" for="contact-phone">Telefone / WhatsApp</label>
                           <input type="text" class="form-control" id="contact-phone" name="contact_phone">
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold" for="contact-reason">Motivo do contato *</label>
                           <select class="form-select" id="contact-reason" name="contact_reason" required>
                              <option value="">Selecione</option>
                              <option value="duvidas">Dúvidas</option>
                              <option value="apresentacao">Apresentação técnica</option>
                              <option value="desenvolvimento">Desenvolvimento de plugin</option>
                              <option value="melhoria">Sugestão de melhoria</option>
                              <option value="contratar">Contratar licença</option>
                              <option value="outros">Outros</option>
                           </select>
                           <div class="invalid-feedback">Selecione o motivo do contato.</div>
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold d-block">Onde nos encontrou? *</label>
                           <div class="d-flex flex-wrap gap-2">
                              <input type="radio"
                                     class="btn-check"
                                     name="contact_source"
                                     id="contact-source-jmba"
                                     value="canais_jmba"
                                     required
                                     autocomplete="off">
                              <label class="btn btn-outline-secondary btn-sm" for="contact-source-jmba">Canais JMBA</label>

                              <input type="radio"
                                     class="btn-check"
                                     name="contact_source"
                                     id="contact-source-indicacao"
                                     value="indicacao"
                                     autocomplete="off">
                              <label class="btn btn-outline-secondary btn-sm" for="contact-source-indicacao">Indicação</label>

                              <input type="radio"
                                     class="btn-check"
                                     name="contact_source"
                                     id="contact-source-linkedin"
                                     value="linkedin"
                                     autocomplete="off">
                              <label class="btn btn-outline-secondary btn-sm" for="contact-source-linkedin">LinkedIn</label>

                              <input type="radio"
                                     class="btn-check"
                                     name="contact_source"
                                     id="contact-source-telegram"
                                     value="telegram"
                                     autocomplete="off">
                              <label class="btn btn-outline-secondary btn-sm" for="contact-source-telegram">Telegram</label>

                              <input type="radio"
                                     class="btn-check"
                                     name="contact_source"
                                     id="contact-source-outros"
                                     value="outros"
                                     autocomplete="off">
                              <label class="btn btn-outline-secondary btn-sm" for="contact-source-outros">Outros</label>
                           </div>
                           <div class="mt-2 d-none" id="contact-source-other-wrapper">
                              <input type="text"
                                     class="form-control form-control-sm"
                                     id="contact-source-other"
                                     name="contact_source_other"
                                     placeholder="Descreva o canal (ex.: evento, podcast, outro site)">
                           </div>
                        </div>
                        <div class="col-md-6">
                           <label class="form-label fw-semibold d-block">Módulos de interesse *</label>
                           <?php if (!empty($contactModuleOptions)): ?>
                              <div class="d-flex flex-wrap gap-2 mb-2">
                                 <?php foreach ($contactModuleOptions as $moduleKey => $moduleName): ?>
                                    <input type="checkbox"
                                           class="btn-check"
                                           name="contact_modules[]"
                                           id="contact-module-<?php echo Html::entities_deep($moduleKey); ?>"
                                           value="<?php echo Html::entities_deep($moduleKey); ?>">
                                    <label class="btn btn-outline-primary btn-sm"
                                           for="contact-module-<?php echo Html::entities_deep($moduleKey); ?>">
                                       <?php echo Html::entities_deep($moduleName); ?>
                                    </label>
                                 <?php endforeach; ?>
                                 <input type="checkbox"
                                        class="btn-check"
                                        name="contact_modules[]"
                                        id="contact-module-outros"
                                        value="outros">
                                 <label class="btn btn-outline-primary btn-sm"
                                        for="contact-module-outros">
                                    Outros
                                 </label>
                              </div>
                           <?php else: ?>
                              <p class="text-muted small mb-2">
                                 Nenhum módulo no catálogo. Atualize a licença para sincronizar a lista.
                              </p>
                           <?php endif; ?>
                           <div class="mt-2" id="contact-modules-other-wrapper">
                              <input type="text"
                                     class="form-control form-control-sm"
                                     placeholder="Outros módulos"
                                     name="contact_modules_other"
                                     id="contact-modules-other">
                           </div>
                        </div>
                        <div class="col-12">
                           <label class="form-label fw-semibold" for="contact-message">Como podemos ajudar? *</label>
                           <textarea class="form-control" id="contact-message" name="contact_message" rows="4" required></textarea>
                           <div class="invalid-feedback">Descreva sua necessidade.</div>
                        </div>
                        <div class="col-12">
                           <div class="form-check">
                              <input class="form-check-input" type="checkbox" value="1" id="contact-consent" name="contact_consent">
                              <label class="form-check-label" for="contact-consent">
                                 Autorizo a NexTool Solutions a entrar em contato com meus dados.
                              </label>
                           </div>
                        </div>
                     </div>

                    <div class="d-flex align-items-center gap-3 mt-4">
                       <button type="submit" class="btn btn-primary" <?php echo $canManageAdminTabs ? '' : ' disabled'; ?>>
                          <i class="ti ti-send me-1"></i>Enviar contato
                       </button>
                       <div id="nextool-contact-feedback" class="small"></div>
                    </div>
                    <?php if (!$canManageAdminTabs): ?>
                       <p class="text-muted small mt-2 mb-0">
                          <i class="ti ti-lock me-1"></i><?php echo __('Apenas administradores podem enviar este formulário.', 'nextool'); ?>
                       </p>
                    <?php endif; ?>
                  </form>
               </div>
            </div>
         </div>
         <?php endif; ?>

      </div>

</div>

<script type="text/javascript">
function nextoolActivateDefaultTab() {
   var tabsContainer = document.getElementById('nextool-config-tabs');
   if (!tabsContainer) {
      return;
   }
   var firstTab = tabsContainer.querySelector('button.nav-link');
   if (!firstTab) {
      return;
   }

   if (window.bootstrap && bootstrap.Tab) {
      bootstrap.Tab.getOrCreateInstance(firstTab).show();
   } else {
      firstTab.classList.add('active');
      var targetSelector = firstTab.getAttribute('data-bs-target');
      var target = targetSelector ? document.querySelector(targetSelector) : null;
      if (target) {
         target.classList.add('show', 'active');
         target.style.display = 'block';
      }
   }
}

if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', nextoolActivateDefaultTab);
} else {
   nextoolActivateDefaultTab();
}

document.addEventListener('glpi.load', nextoolActivateDefaultTab);

// Função compartilhada para validar licença a partir de qualquer aba
function nextoolValidateLicense(btn) {
   var form = null;
   if (btn && btn.form) {
      form = btn.form;
   } else {
      form = document.getElementById('configForm');
   }
   if (!form) {
      return false;
   }
   var msg = 'Ao validar a licença do Nextool, serão enviados dados técnicos do ambiente (domínio, ' +
      'identificador do cliente, chave de licença, IP do servidor e versões de GLPI/PHP/plugin) ao servidor administrativo ' +
      'apenas para fins de licenciamento, controle de ambientes e auditoria técnica. Nenhum dado de tickets, usuários finais ' +
      'ou anexos é coletado.\n\nVocê concorda com esta política de uso e coleta de dados para validação de licença?';

   if (!window.confirm(msg)) {
      return false;
   }

   var actionInput = form.querySelector('input[name="action"]');
   if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
   }
   actionInput.value = 'validate_license';
   form.submit();
   return false;
}

function nextoolRegenerateHmac(btn) {
   var form = document.getElementById('configForm');
   if (!form) {
      return false;
   }
   var confirmMsg = 'Gerar um novo segredo HMAC invalida o segredo atual imediatamente. ' +
      'Todos os ambientes ou integrações que utilizam o segredo antigo deixarão de funcionar ' +
      'até que o novo valor seja propagado. Deseja continuar?';
   if (!window.confirm(confirmMsg)) {
      return false;
   }

   var actionInput = form.querySelector('input[name="action"]');
   if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
   }
   actionInput.value = 'regenerate_hmac';
   form.submit();
   return false;
}

function nextoolCopyHmacSecret(btn) {
   if (!btn || !btn.dataset) {
      return;
   }
   var secret = btn.dataset.secret || '';
   if (secret === '') {
      return;
   }
   navigator.clipboard.writeText(secret).then(function () {
      var original = btn.innerHTML;
      btn.innerHTML = '<i class="ti ti-check me-1"></i><?php echo Html::entities_deep(__('Copiado!', 'nextool')); ?>';
      btn.disabled = true;
      setTimeout(function () {
         btn.innerHTML = original;
         btn.disabled = false;
      }, 2000);
   });
}

function nextoolInitContactForm() {
   var form = document.getElementById('nextool-contact-form');
   if (!form || form.dataset.bound === '1') {
      return;
   }
   form.dataset.bound = '1';
   var feedback = document.getElementById('nextool-contact-feedback');
   var submitButton = form.querySelector('button[type="submit"]');

   form.addEventListener('submit', function (event) {
      event.preventDefault();
      event.stopPropagation();
      form.classList.add('was-validated');
      if (!form.checkValidity()) {
         return;
      }

      var formData = new FormData(form);
      var csrfInput = form.querySelector('input[name="_glpi_csrf_token"]');
      var csrfToken = csrfInput ? csrfInput.value : '';
      if (submitButton) {
         submitButton.disabled = true;
      }
      if (feedback) {
         feedback.classList.remove('text-danger', 'text-success');
         feedback.classList.add('text-muted');
         feedback.textContent = 'Enviando contato...';
      }

      fetch(form.action, {
         method: 'POST',
         body: formData,
         headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken
         },
         credentials: 'same-origin'
      }).then(function (response) {
         return response.json().catch(function () {
            return {};
         });
      }).then(function (data) {
         if (feedback) {
            feedback.classList.remove('text-muted');
         }
         if (data && data.success) {
            form.reset();
            form.classList.remove('was-validated');
            if (feedback) {
               feedback.classList.add('text-success');
               feedback.textContent = data.message || 'Contato enviado com sucesso! Nossa equipe retornará em breve.';
            }
         } else {
            if (feedback) {
               feedback.classList.add('text-danger');
               feedback.textContent = (data && data.message) ? data.message : 'Não foi possível enviar o contato. Tente novamente em instantes.';
            }
         }
      }).catch(function () {
         if (feedback) {
            feedback.classList.remove('text-muted');
            feedback.classList.add('text-danger');
            feedback.textContent = 'Erro inesperado ao enviar o formulário.';
         }
      }).finally(function () {
         if (submitButton) {
            submitButton.disabled = false;
         }
      });
   });
}

function nextoolInitContactSourceField() {
   var wrapper = document.getElementById('contact-source-other-wrapper');
   if (!wrapper || wrapper.dataset.bound === '1') {
      return;
   }
   wrapper.dataset.bound = '1';

   var radios = document.querySelectorAll('input[name="contact_source"]');
   if (!radios.length) {
      return;
   }

   function refreshSourceOther() {
      var selected = document.querySelector('input[name="contact_source"]:checked');
      if (selected && selected.value === 'outros') {
         wrapper.classList.remove('d-none');
      } else {
         wrapper.classList.add('d-none');
      }
   }

   radios.forEach(function (radio) {
      radio.addEventListener('change', refreshSourceOther);
   });

   refreshSourceOther();
}

function nextoolInitContactModulesField() {
   var wrapper = document.getElementById('contact-modules-other-wrapper');
   if (!wrapper || wrapper.dataset.bound === '1') {
      return;
   }
   wrapper.dataset.bound = '1';

   var outrosCheckbox = document.getElementById('contact-module-outros');
   if (!outrosCheckbox) {
      return;
   }

   function refreshModulesOther() {
      if (outrosCheckbox.checked) {
         wrapper.classList.remove('d-none');
      } else {
         wrapper.classList.add('d-none');
      }
   }

   outrosCheckbox.addEventListener('change', refreshModulesOther);
   refreshModulesOther();
}

if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', function () {
      nextoolInitContactForm();
      nextoolInitContactSourceField();
      nextoolInitContactModulesField();
   });
} else {
   nextoolInitContactForm();
   nextoolInitContactSourceField();
   nextoolInitContactModulesField();
}
document.addEventListener('glpi.load', function () {
   nextoolInitContactForm();
   nextoolInitContactSourceField();
   nextoolInitContactModulesField();
});
</script>