<?php
/**
 * Nextools - Contact Form Endpoint (AJAX)
 *
 * Endpoint para envio do formulário de contato do Nextools, usado para solicitar
 * informações sobre planos, módulos e suporte.
 * GLPI 10: CSRF validado automaticamente para rotas /ajax/ (header X-Glpi-Csrf-Token).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
if (!Session::getLoginUserID()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Sessão inválida.', 'nextool'),
   ]);
   exit;
}

if (!PluginNextoolPermissionManager::canManageAdminTabs()) {
   http_response_code(403);
   echo json_encode([
      'success' => false,
      'message' => __('Você não tem permissão para enviar este contato.', 'nextool'),
   ]);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   echo json_encode([
      'success' => false,
      'message' => __('Requisição inválida.', 'nextool'),
   ]);
   exit;
}

// CSRF: o GLPI valida automaticamente em inc/includes.php.
// Não revalidar aqui com Session::checkCSRF()/validateCSRF(), pois essas rotinas
// podem renderizar HTML de acesso negado e quebrar o contrato JSON do endpoint.

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/distributionclient.class.php';
$config = PluginNextoolConfig::getConfig();

$honeypot = trim((string)($_POST['contact_extra_info'] ?? ''));
if ($honeypot !== '') {
   echo json_encode([
      'success' => true,
      'message' => __('Contato recebido. Nossa equipe retornará em breve.', 'nextool'),
   ]);
   exit;
}

$name = trim((string)($_POST['contact_name'] ?? ''));
$company = trim((string)($_POST['contact_company'] ?? ''));
$email = trim((string)($_POST['contact_email'] ?? ''));
$phone = trim((string)($_POST['contact_phone'] ?? ''));
$reason = trim((string)($_POST['contact_reason'] ?? ''));
$message = trim((string)($_POST['contact_message'] ?? ''));
$modulesOther = trim((string)($_POST['contact_modules_other'] ?? ''));
$consent = !empty($_POST['contact_consent']);
$source = trim((string)($_POST['contact_source'] ?? ''));
$sourceOther = trim((string)($_POST['contact_source_other'] ?? ''));

$modules = array_filter(array_map('trim', (array)($_POST['contact_modules'] ?? [])), function ($value) {
   return $value !== '';
});
$modules = array_values(array_unique($modules));

$allowedSources = [
   'canais_jmba',
   'indicacao',
   'linkedin',
   'telegram',
   'outros',
];

if ($source !== '' && !in_array($source, $allowedSources, true)) {
   $source = 'outros';
}

$allowedReasons = [
   'duvidas',
   'apresentacao',
   'desenvolvimento',
   'melhoria',
   'contratar',
   'outros'
];

$errors = [];
if ($name === '') {
   $errors[] = __('Informe seu nome.', 'nextool');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
   $errors[] = __('Informe um e-mail válido.', 'nextool');
}
if ($reason === '' || !in_array($reason, $allowedReasons, true)) {
   $errors[] = __('Selecione o motivo do contato.', 'nextool');
}
if ($source === '') {
   $errors[] = __('Selecione onde nos encontrou.', 'nextool');
}
if ($message === '') {
   $errors[] = __('Descreva sua necessidade no campo de mensagem.', 'nextool');
}

if (!empty($errors)) {
   echo json_encode([
      'success' => false,
      'message' => implode(' ', $errors),
   ]);
   exit;
}

$distributionSettings = PluginNextoolConfig::getDistributionSettings();
$baseUrl = rtrim((string)($distributionSettings['base_url'] ?? ''), '/');
$clientIdentifier = $distributionSettings['client_identifier'] ?? ($config['client_identifier'] ?? '');
$clientSecret = $distributionSettings['client_secret'] ?? '';

if ($baseUrl === '' || $clientIdentifier === '' || $clientSecret === '') {
   echo json_encode([
      'success' => false,
      'message' => __('Configure a distribuição remota e valide a licença antes de enviar o contato.', 'nextool'),
   ]);
   exit;
}

$payload = [
   'client_identifier' => $clientIdentifier,
   'name'              => $name,
   'company'           => $company,
   'email'             => $email,
   'phone'             => $phone,
   'reason'            => $reason,
   'modules'           => $modules,
   'modules_other'     => $modulesOther,
   'message'           => $message,
   'consent'           => $consent,
   'channel_preference'=> 'email',
];

if ($source !== '') {
   $payload['source'] = $source;
}
if ($source === 'outros' && $sourceOther !== '') {
   $payload['source_other'] = $sourceOther;
}

try {
   $client = new PluginNextoolDistributionClient($baseUrl, $clientIdentifier, $clientSecret);
   $response = $client->submitContactLead($payload);
   $ticketId = $response['ticket_id'] ?? null;
   echo json_encode([
      'success'   => true,
      'ticket_id' => $ticketId,
      'message'   => __('Contato enviado com sucesso! Nossa equipe retornará em breve.', 'nextool'),
   ]);
} catch (Throwable $e) {
   $rawMessage = (string)$e->getMessage();
   Toolbox::logInFile('plugin_nextool', 'Falha ao enviar contato para ContainerAPI: ' . $rawMessage);

   $userMessage = __('Não foi possível enviar seu contato. Tente novamente em instantes.', 'nextool');
   if (stripos($rawMessage, 'Contato já enviado recentemente') !== false
       || stripos($rawMessage, 'rate limit') !== false
   ) {
      $userMessage = __('Contato enviado recentemente. Aguarde 30 minutos antes de tentar novamente.', 'nextool');
   }

   echo json_encode([
      'success' => false,
      'message' => $userMessage,
   ]);
}

exit;

