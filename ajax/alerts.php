<?php
declare(strict_types=1);

include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/nextool/inc/ajaxbootstrap.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';

PluginNextoolAjaxBootstrap::start([
   'login_mode'          => 'redirect',
   'permission_callback' => ['PluginNextoolPermissionManager', 'canAccessAdminTabs'],
]);

require_once GLPI_ROOT . '/plugins/nextool/inc/alertmanager.class.php';

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'mark_read') {
   $alertId = (int)($_POST['alert_id'] ?? 0);
   if ($alertId > 0) {
      PluginNextoolAlertManager::markAsRead($alertId);
   }
   echo json_encode(['success' => true]);
   exit;
}

if ($action === 'mark_all_read') {
   PluginNextoolAlertManager::markAllAsRead();
   echo json_encode(['success' => true]);
   exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
