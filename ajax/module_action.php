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

// Carrega ModuleManager e MainConfig (para validar forcetab)
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licensevalidator.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/nextoolmainconfig.class.php';

$manager = PluginNextoolModuleManager::getInstance();

// Importante:
// Ações de módulo NÃO devem "desvalidar" o plano/licença do ambiente.
// Resetar o cache de licença aqui fazia o UI cair para "Plano não validado"
// após desinstalar um módulo, até o usuário clicar em "Sincronizar".
//
// Mantemos apenas a limpeza do cache de módulos (discovery) quando houver
// impacto real em arquivos/estrutura local.
$actionsThatResetModuleCache = ['download', 'purge_data'];
if (in_array($action, $actionsThatResetModuleCache, true)) {
   $manager->clearCache();
   $manager->refreshModules();
}

// Para download remoto, forçamos refresh do snapshot (ContainerAPI),
// pois o fluxo de distribuição depende do estado mais recente.
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
            Html::redirect(Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=' . urlencode('PluginNextoolMainConfig$1'));
            exit;
         }
      }
   }
}

// Executa ação
$result = ['success' => false, 'message' => 'Ação inválida', 'forcetab' => 'PluginNextoolMainConfig$1'];

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

// Adiciona mensagem na sessão do GLPI (INFO, WARNING ou ERROR conforme resultado)
$msgType = $result['message_type'] ?? ($result['success'] ? INFO : ERROR);
Session::addMessageAfterRedirect($result['message'], false, $msgType);

// Declarar variáveis globais do GLPI
global $CFG_GLPI;

// Ao ativar (enable) com sucesso: abrir a aba lateral do módulo na mesma tela do plugin
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
   // Tab de retorno: usar a mesma aba de onde veio o POST (forcetab), senão resultado ou padrão Módulos
   $returnTab = 'PluginNextoolMainConfig$1';
   if (isset($_POST['forcetab']) && $_POST['forcetab'] !== '') {
      $returnTab = $_POST['forcetab'];
   } elseif (isset($result['forcetab']) && $result['forcetab'] !== '') {
      $returnTab = $result['forcetab'];
   }
   // Validar contra abas conhecidas para evitar tab inválida
   $validTabs = PluginNextoolMainConfig::getValidTabIds();
   if (!in_array($returnTab, $validTabs, true)) {
      $returnTab = 'PluginNextoolMainConfig$1';
   }
}

$redirectUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=' . urlencode($returnTab);
Html::redirect($redirectUrl);


