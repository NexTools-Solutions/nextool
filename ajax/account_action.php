<?php
declare(strict_types=1);
/**
 * NexTool -- Account Action Endpoint (AJAX) -- F3 vínculo de conta.
 *
 * Ações: generate_link_code (pede um código ao ContainerAPI), refresh_status (consulta o vínculo),
 * unlink (desfaz o vínculo).
 * GLPI 10: CSRF validado automaticamente para rotas /ajax/ (header X-Glpi-Csrf-Token).
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canManageModules()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Você não tem permissão para gerenciar o NexTool.', 'nextool'),
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

require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$allowed = ['generate_link_code', 'refresh_status', 'unlink'];
if (!in_array($action, $allowed, true)) {
   http_response_code(400);
   echo json_encode(['success' => false, 'message' => __('Ação inválida.', 'nextool')]);
   exit;
}

$settings   = PluginNextoolConfig::getDistributionSettings();
$baseUrl    = trim((string) ($settings['base_url'] ?? ''));
$identifier = trim((string) ($settings['client_identifier'] ?? ''));
$secret     = trim((string) ($settings['client_secret'] ?? ''));

if ($baseUrl === '' || $identifier === '' || $secret === '') {
   http_response_code(409);
   echo json_encode([
      'success' => false,
      'message' => __('Ambiente ainda não provisionado. Sincronize a licença primeiro.', 'nextool'),
   ]);
   exit;
}

$client = new PluginNextoolDistributionClient($baseUrl, $identifier, $secret);

try {
   switch ($action) {
      case 'generate_link_code':
         $data = $client->requestLinkCode();
         echo json_encode([
            'success'         => true,
            'link_code'       => $data['link_code'] ?? '',
            'expires_in'      => (int) ($data['expires_in'] ?? 600),
            'portal_link_url' => $data['portal_link_url'] ?? '',
            'environment_id'  => $identifier,
         ]);
         break;

      case 'refresh_status':
         $data = $client->getLinkStatus();
         $isLinked = (bool) ($data['linked'] ?? false);
         // Atualiza o estado persistido (cards FREE + rótulo do hero) já no refresh, sem esperar o
         // próximo /validate. Vinculado => link_required=0 (download FREE liberado de imediato).
         $persist = ['linked' => $isLinked ? '1' : '0', 'email' => (string) ($data['portal_email'] ?? '')];
         if ($isLinked) { $persist['link_required'] = '0'; }
         Config::setConfigurationValues('plugin:nextool_account_link', array_merge(
            Config::getConfigurationValues('plugin:nextool_account_link'), $persist
         ));
         echo json_encode([
            'success'        => true,
            'linked'         => $isLinked,
            'portal_email'   => $data['portal_email'] ?? null,
            'linked_at'      => $data['linked_at'] ?? null,
            'environment_id' => $identifier,
         ]);
         break;

      case 'unlink':
         $client->unlinkAccount();
         Config::setConfigurationValues('plugin:nextool_account_link', array_merge(
            Config::getConfigurationValues('plugin:nextool_account_link'),
            ['linked' => '0', 'email' => '']
         ));
         echo json_encode(['success' => true, 'message' => __('Conta desvinculada.', 'nextool')]);
         break;
   }
} catch (Throwable $e) {
   http_response_code(502);
   echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
