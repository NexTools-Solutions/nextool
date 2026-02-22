<?php
/**
 * Endpoint seguro para leitura do segredo HMAC (uso exclusivo via AJAX).
 */

include ('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

if (!Session::getLoginUserID()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Sessão inválida.', 'nextool'),
   ]);
   exit;
}

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canManageAdminTabs()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Sem permissão para acessar a chave de segurança.', 'nextool'),
   ]);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   http_response_code(405);
   echo json_encode([
      'success' => false,
      'message' => __('Método inválido.', 'nextool'),
   ]);
   exit;
}

if (!isset($_POST['_glpi_csrf_token'])) {
   http_response_code(400);
   echo json_encode([
      'success' => false,
      'message' => __('Token CSRF ausente.', 'nextool'),
   ]);
   exit;
}
Session::validateCSRF($_POST['_glpi_csrf_token']);

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';

$distributionSettings = PluginNextoolConfig::getDistributionSettings();
$clientIdentifier = trim((string) ($distributionSettings['client_identifier'] ?? ''));
$clientSecret = trim((string) ($distributionSettings['client_secret'] ?? ''));

if ($clientSecret === '' && $clientIdentifier !== '') {
   $row = PluginNextoolDistributionClient::getEnvSecretRow($clientIdentifier);
   if ($row && !empty($row['client_secret'])) {
      $clientSecret = trim((string) $row['client_secret']);
   }
}

if ($clientSecret === '') {
   echo json_encode([
      'success' => false,
      'message' => __('Chave de segurança ainda não foi provisionada.', 'nextool'),
   ]);
   exit;
}

echo json_encode([
   'success' => true,
   'secret'  => $clientSecret,
]);
exit;
