<?php
/**
 * Nextools - HMAC Secret Endpoint (AJAX)
 *
 * Endpoint seguro para leitura do segredo HMAC usado na assinatura de requisições
 * ao ContainerAPI. Uso exclusivo via AJAX (ex.: módulos que precisam do secret).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include('../../../inc/includes.php');

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

// CSRF: o GLPI valida automaticamente em inc/includes.php.
// Não revalidar aqui com Session::checkCSRF()/validateCSRF(), pois essas rotinas
// podem renderizar HTML de acesso negado e quebrar o contrato JSON do endpoint.

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';

// Por segurança, o segredo HMAC (client_secret) não é exposto para o browser.
// A chave deve permanecer apenas no servidor e ser gerenciada via sincronização/regeneração.
http_response_code(403);
echo json_encode([
   'success' => false,
   'message' => __('Por segurança, a chave de segurança não é exibida. Use a opção de gerar/regerar chave.', 'nextool'),
]);
exit;

