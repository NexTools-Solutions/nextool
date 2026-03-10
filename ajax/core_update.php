<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Core Update Endpoint (AJAX)
 * -------------------------------------------------------------------------
 * Ações internas do self-updater do core.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   http_response_code(405);
   echo json_encode([
      'success' => false,
      'message' => __('Método inválido para atualização de core.', 'nextool'),
   ]);
   exit;
}

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/coreupdater.class.php';

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$channel = isset($_POST['channel']) ? trim((string)$_POST['channel']) : 'stable';

Toolbox::logInFile('plugin_nextool', sprintf(
   "[DEBUG] [CoreUpdate] Requisição recebida: action=%s channel=%s user_id=%s\n",
   $action,
   $channel,
   class_exists('Session') ? (string)Session::getLoginUserID() : 'n/a'
));

$readActions = ['check', 'preflight', 'list_backups'];
$writeActions = ['prepare', 'apply', 'cancel_staging', 'restore'];
$allActions = array_merge($readActions, $writeActions);

if (!in_array($action, $allActions, true)) {
   Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdate] Ação inválida rejeitada: %s\n", $action));
   http_response_code(400);
   echo json_encode([
      'success' => false,
      'message' => __('Ação de core update inválida.', 'nextool'),
   ]);
   exit;
}

if (in_array($action, $readActions, true)) {
   PluginNextoolPermissionManager::assertCanAccessAdminTabs();
} else {
   PluginNextoolPermissionManager::assertCanManageAdminTabs();
}

$updater = new PluginNextoolCoreUpdater();
Toolbox::logInFile('plugin_nextool', sprintf("[DEBUG] [CoreUpdate] Iniciando execução da ação: %s\n", $action));

try {
   $result = null;

   switch ($action) {
      case 'check':
         $result = $updater->check($channel, 'manual');
         break;

      case 'preflight':
         $check = $updater->check($channel, 'manual_preflight');
         $manifest = null;
         if (!empty($check['success']) && isset($check['data']['manifest']) && is_array($check['data']['manifest'])) {
            $manifest = $check['data']['manifest'];
         }
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdate] preflight: check_success=%s manifest=%s\n",
            !empty($check['success']) ? 'true' : 'false',
            $manifest !== null && isset($manifest['version']) ? $manifest['version'] : 'null'
         ));

         $result = $updater->preflight($manifest, 'preflight');
         $result = [
            'success' => !empty($result['ok']),
            'message' => $result['message'] ?? __('Preflight concluído.', 'nextool'),
            'data' => [
               'preflight' => $result,
               'check' => $check,
            ],
         ];
         break;

      case 'prepare':
         $result = $updater->prepare($channel, 'manual');
         break;

      case 'apply':
         $result = $updater->apply('manual');
         break;

      case 'cancel_staging':
         $result = $updater->cancelStaging('manual');
         break;

      case 'list_backups':
         $result = $updater->listBackups();
         break;

      case 'restore':
         $backupId = isset($_POST['backup_id']) ? trim((string)$_POST['backup_id']) : '';
         if ($backupId === '') {
            $result = [
               'success' => false,
               'message' => __('Identificador de backup não informado.', 'nextool'),
            ];
         } else {
            $result = $updater->restore($backupId, 'manual');
         }
         break;
   }

   if (!is_array($result)) {
      Toolbox::logInFile('plugin_nextool', "[DEBUG] [CoreUpdate] Resultado inválido (não é array) retornado pelo updater.\n");
      throw new RuntimeException('Resultado inválido do updater.');
   }

   $httpStatus = 200;
   Toolbox::logInFile('plugin_nextool', sprintf(
      "[DEBUG] [CoreUpdate] Ação %s concluída: success=%s http=%d message=%s\n",
      $action,
      !empty($result['success']) ? 'true' : 'false',
      $httpStatus,
      isset($result['message']) ? substr((string)$result['message'], 0, 120) : 'n/a'
   ));
   if (!empty($result['data']) && is_array($result['data'])) {
      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdate] data keys: %s\n",
         implode(', ', array_keys($result['data']))
      ));
   }
   http_response_code($httpStatus);
   echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
   Toolbox::logInFile('plugin_nextool', sprintf('Core updater error [%s]: %s', $action, $e->getMessage()));
   http_response_code(500);
   echo json_encode([
      'success' => false,
      'message' => __('Erro interno. Consulte os logs.', 'nextool'),
   ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;
