<?php
/**
 * Nextools - Billing Endpoint (AJAX)
 *
 * Endpoint AJAX para criar Stripe Checkout Sessions.
 * Comunica com o ContainerAPI via DistributionClient.
 *
 * @author Richard Loureiro
 * @license GPLv3+
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

// Verificar autenticacao
Session::checkLoginUser();

require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canManageModules()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'error'   => __('Sem permissão para gerenciar módulos.', 'nextool'),
   ]);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   http_response_code(405);
   echo json_encode(['error' => 'Method not allowed']);
   exit;
}

$action        = trim((string) ($_POST['action'] ?? ''));
$moduleKey     = trim((string) ($_POST['module'] ?? ''));
$paymentMethod = trim((string) ($_POST['payment_method'] ?? 'card'));

if ($action !== 'create_checkout' || $moduleKey === '') {
   http_response_code(400);
   echo json_encode(['error' => __('Parâmetros inválidos.', 'nextool')]);
   exit;
}

if (!in_array($paymentMethod, ['card', 'pix', 'boleto'], true)) {
   $paymentMethod = 'card';
}

require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';

$result = PluginNextoolDistributionClient::createCheckoutSession($moduleKey, $paymentMethod);

if ($result === null || empty($result['checkout_url'])) {
   $errorMsg = $result['error'] ?? $result['message'] ?? __('Falha ao criar sessão de checkout. Verifique se o módulo tem preço configurado.', 'nextool');
   echo json_encode([
      'success' => false,
      'error'   => $errorMsg,
   ]);
   exit;
}

echo json_encode([
   'success'      => true,
   'checkout_url' => $result['checkout_url'],
]);
