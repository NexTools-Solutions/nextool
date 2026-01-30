<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Action Endpoint
 * -------------------------------------------------------------------------
 * Endpoint AJAX responsável por processar ações dos módulos do
 * NexTool Solutions (install, uninstall, enable, disable, update),
 * centralizando o gerenciamento via GLPI.
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

include ('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
PluginNextoolPermissionManager::assertCanManageModules();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   Session::addMessageAfterRedirect(__('Método inválido para esta ação.', 'nextool'), false, ERROR);
   Html::back();
}

if (!isset($_POST['_glpi_csrf_token'])) {
   Session::addMessageAfterRedirect(__('Token CSRF ausente', 'nextool'), false, ERROR);
   Html::back();
}
Session::validateCSRF($_POST['_glpi_csrf_token']);

$action = $_POST['action'] ?? '';
$moduleKey = $_POST['module'] ?? '';

if (empty($action) || empty($moduleKey)) {
   http_response_code(400);
   echo json_encode([
      'success' => false,
      'message' => 'Parâmetros inválidos'
   ]);
   exit;
}

if (in_array($action, ['install', 'uninstall', 'enable', 'disable', 'update', 'download'], true)) {
   PluginNextoolPermissionManager::assertCanManageModule($moduleKey);
}

if ($action === 'purge_data') {
   PluginNextoolPermissionManager::assertCanPurgeModuleDataForModule($moduleKey);
}

// Carrega ModuleManager
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licensevalidator.class.php';

$manager = PluginNextoolModuleManager::getInstance();

$actionsThatResetCache = ['download', 'install', 'uninstall', 'purge_data'];
if (in_array($action, $actionsThatResetCache, true)) {
   $manager->clearCache();
   $manager->refreshModules();
   PluginNextoolLicenseConfig::resetCache();
   if ($action === 'download') {
      PluginNextoolLicenseValidator::validateLicense([
         'force_refresh' => true,
         'context'       => [
            'origin'            => 'module_action_download',
            'requested_modules' => [$moduleKey],
         ],
      ]);
   }
}

// Executa ação
$result = ['success' => false, 'message' => 'Ação inválida', 'forcetab' => 'PluginNextoolSetup$1'];

switch ($action) {
   case 'install':
      $result = $manager->installModule($moduleKey);
      break;
   
   case 'uninstall':
      $result = $manager->uninstallModule($moduleKey);
      break;
   
   case 'enable':
      $result = $manager->enableModule($moduleKey);
      break;
   
   case 'disable':
      $result = $manager->disableModule($moduleKey);
      break;

   case 'download':
      $result = $manager->downloadRemoteModule($moduleKey);
      break;

case 'purge_data':
   $result = $manager->purgeModuleData($moduleKey);
   break;

case 'update':
   $result = $manager->updateModule($moduleKey);
   break;
}

// Adiciona mensagem na sessão do GLPI
if ($result['success']) {
   Session::addMessageAfterRedirect($result['message'], false, INFO);
} else {
   Session::addMessageAfterRedirect($result['message'], false, ERROR);
}

// Declarar variáveis globais do GLPI
global $CFG_GLPI;

// Determina tab de retorno (instalação → Licença; enable/disable → Módulos)
$returnTab = isset($result['forcetab']) && $result['forcetab'] !== ''
   ? $result['forcetab']
   : 'PluginNextoolSetup$1';

$hash = '#rt-tab-modulos';
if ($action === 'install') {
   $hash = '#rt-tab-licenca';
}

Html::redirect($CFG_GLPI['root_doc'] . '/front/config.form.php?forcetab=' . urlencode($returnTab) . $hash);


