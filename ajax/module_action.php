<?php
/**
 * Nextools - Module Action Endpoint (AJAX)
 *
 * Endpoint AJAX para processar ações dos módulos: install, uninstall, enable,
 * disable, update, download, purge_data.
 * GLPI 10: CSRF validado automaticamente para rotas /ajax/ (header X-Glpi-Csrf-Token).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canManageModules()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Você não tem permissão para gerenciar os módulos do NexTool.', 'nextool'),
   ]);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   http_response_code(405);
   echo json_encode([
      'success' => false,
      'message' => __('Método inválido para esta ação.', 'nextool'),
   ]);
   exit;
}

// CSRF: o GLPI valida automaticamente em inc/includes.php.
// Não revalidar aqui com Session::checkCSRF()/validateCSRF(), pois essas rotinas
// podem renderizar HTML de acesso negado e quebrar o contrato JSON do endpoint.

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$moduleKeyRaw = isset($_POST['module']) ? trim((string) $_POST['module']) : '';
if ($action === '' || $moduleKeyRaw === '') {
   http_response_code(400);
   echo json_encode([
      'success' => false,
      'message' => __('Parâmetros inválidos.', 'nextool'),
   ]);
   exit;
}

if (!preg_match('/^[a-z0-9_-]+$/i', $moduleKeyRaw)) {
   http_response_code(400);
   echo json_encode([
      'success' => false,
      'message' => __('Módulo inválido.', 'nextool'),
   ]);
   exit;
}
$moduleKey = strtolower($moduleKeyRaw);

if (in_array($action, ['install', 'uninstall', 'enable', 'disable', 'update', 'download'], true)) {
   if (!PluginNextoolPermissionManager::canManageModule($moduleKey)) {
      http_response_code(403);
      echo json_encode([
         'success' => false,
         'message' => __('Você não tem permissão para gerenciar este módulo.', 'nextool'),
      ]);
      exit;
   }
}
if ($action === 'purge_data') {
   if (!PluginNextoolPermissionManager::canPurgeModuleDataForModule($moduleKey)) {
      http_response_code(403);
      echo json_encode([
         'success' => false,
         'message' => __('Você não tem permissão para apagar os dados deste módulo.', 'nextool'),
      ]);
      exit;
   }
}

require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licensevalidator.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/nextoolmainconfig.class.php';

$manager = PluginNextoolModuleManager::getInstance();

// Limpa cache de módulos apenas quando há impacto em arquivos/estrutura local.
$actionsThatResetModuleCache = ['download', 'purge_data'];
if (in_array($action, $actionsThatResetModuleCache, true)) {
   $manager->clearCache();
   $manager->refreshModules();
}

// Para download remoto, forçamos refresh do snapshot (ContainerAPI).
if ($action === 'download') {
   PluginNextoolLicenseValidator::validateLicense([
      'force_refresh' => true,
      'context'       => [
         'origin'            => 'module_action_download',
         'requested_modules' => [$moduleKey],
      ],
   ]);
}

// Bloqueio de update quando a versão do Nextool é insuficiente (min_version_nextools)
if ($action === 'update') {
   global $DB;
   $pluginVersion = null;
   if (function_exists('plugin_version_nextool')) {
      $info = plugin_version_nextool();
      $pluginVersion = isset($info['version']) ? (string) $info['version'] : null;
   }
   $table = 'glpi_plugin_nextool_main_modules';
   if ($DB->tableExists($table) && $DB->fieldExists($table, 'min_version_nextools')) {
      $iterator = $DB->request([
         'FROM'  => $table,
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1,
      ]);
      if (count($iterator)) {
         $row = $iterator->current();
         $minVer = isset($row['min_version_nextools']) && $row['min_version_nextools'] !== '' && $row['min_version_nextools'] !== null
            ? trim((string) $row['min_version_nextools']) : null;
         if ($minVer !== null && ($pluginVersion === null || $pluginVersion === '' || version_compare($pluginVersion, $minVer, '<'))) {
            $msg = sprintf(
               __('Para atualizar este módulo é necessário o Nextool versão %s ou superior. %s', 'nextool'),
               $minVer,
               __('Atualize o plugin em:', 'nextool') . ' ' . NEXTOOL_SITE_URL
            );
            Session::addMessageAfterRedirect($msg, false, ERROR);
            echo json_encode([
               'success'      => false,
               'message'      => $msg,
               'redirect_url' => Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=' . urlencode('PluginNextoolMainConfig$1'),
            ]);
            exit;
         }
      }
   }
}

$result = ['success' => false, 'message' => __('Ação inválida', 'nextool'), 'forcetab' => 'PluginNextoolMainConfig$1'];
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

$msgType = $result['message_type'] ?? (!empty($result['success']) ? INFO : ERROR);
Session::addMessageAfterRedirect($result['message'], false, $msgType);

// Ao ativar (enable) com sucesso: abrir a aba lateral do módulo
$returnTab = null;
if ($action === 'enable' && !empty($result['success'])) {
   $moduleTabs = PluginNextoolMainConfig::getModuleConfigTabs();
   foreach ($moduleTabs as $tabNum => $meta) {
      if (($meta['module_key'] ?? '') === $moduleKey) {
         $returnTab = 'PluginNextoolMainConfig$' . $tabNum;
         break;
      }
   }
}

if ($returnTab === null) {
   $returnTab = 'PluginNextoolMainConfig$1';
   if (isset($_POST['forcetab']) && $_POST['forcetab'] !== '') {
      $returnTab = (string) $_POST['forcetab'];
   } elseif (isset($result['forcetab']) && $result['forcetab'] !== '') {
      $returnTab = (string) $result['forcetab'];
   }
   $validTabs = PluginNextoolMainConfig::getValidTabIds();
   if (!in_array($returnTab, $validTabs, true)) {
      $returnTab = 'PluginNextoolMainConfig$1';
   }
}

$redirectUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=' . urlencode($returnTab);

echo json_encode([
   'success'      => !empty($result['success']),
   'message'      => (string) ($result['message'] ?? ''),
   'message_type' => $msgType,
   'redirect_url' => $redirectUrl,
]);
exit;
