<?php
declare(strict_types=1);

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canAccessAdminTabs()) {
   http_response_code(403);
   echo json_encode(['success' => false, 'error' => 'forbidden']);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   http_response_code(405);
   echo json_encode(['error' => 'Method not allowed']);
   exit;
}

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
