<?php
declare(strict_types=1);
/**
 * NexTool -- Account Action Endpoint (AJAX) -- F3 vínculo de conta.
 *
 * Ações: generate_link_code (pede um código ao ContainerAPI), refresh_status (consulta o vínculo),
 * unlink (desfaz o vínculo). CSRF validado automaticamente pelo GLPI em inc/includes.php.
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';

PluginNextoolAjaxBootstrap::start([
   'permission_callback' => ['PluginNextoolPermissionManager', 'canManageModules'],
   'errors'              => [
      'forbidden'  => __('Você não tem permissão para gerenciar o NexTool.', 'nextool'),
      'bad_method' => __('Método inválido para esta ação.', 'nextool'),
   ],
]);

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

// Fluxo vinculo-first: "Gerar codigo" e o gatilho de identificacao. Se o ambiente ainda nao foi
// identificado (install novo), enrola sob demanda (cunha NX2-+segredo) ANTES de gerar o codigo de
// vinculo -- o aceite formal dos termos acontece no PORTAL, no momento do vinculo. So a base_url e
// pre-requisito real. As acoes refresh_status/unlink seguem exigindo ambiente ja identificado.
if ($action === 'generate_link_code' && ($identifier === '' || $secret === '')) {
   if ($baseUrl === '') {
      http_response_code(409);
      echo json_encode(['success' => false, 'message' => __('Configure a URL do ContainerAPI antes de vincular a conta.', 'nextool')]);
      exit;
   }
   $prov = PluginNextoolDistributionClient::enrollAndPersist($baseUrl);
   if (empty($prov['success'])) {
      http_response_code(502);
      echo json_encode(['success' => false, 'message' => $prov['message'] ?? __('Não foi possível identificar o ambiente. Tente novamente em instantes.', 'nextool')]);
      exit;
   }
   // Identidade cunhada -> marca o ambiente como ativado (para a UI nao pedir ativacao de novo).
   // NAO sincronizamos a licenca aqui: validateLicense(force_refresh) e a chamada mais cara do fluxo
   // e NAO e pre-requisito para emitir o link-code. Encadea-la entre o enroll e o requestLinkCode
   // tornava a 1a instalacao lenta/fragil (enroll + validate + link-code numa unica requisicao) --
   // o reload "resolvia" justamente por pular este bloco inteiro. O catalogo/licenca sincroniza no
   // fluxo normal de validate (refresh_status pos-vinculo, botao Sincronizar ou proximo load).
   // O aceite FORMAL dos termos e registrado no PORTAL.
   require_once NEXTOOL_PHP_DIR . '/inc/licenseconfig.class.php';
   PluginNextoolLicenseConfig::resetCache(['policies_accepted_at' => date('Y-m-d H:i:s')]);
   $settings   = PluginNextoolConfig::getDistributionSettings();
   $identifier = trim((string) ($settings['client_identifier'] ?? ''));
   $secret     = trim((string) ($settings['client_secret'] ?? ''));
}

// Ambiente cru: refresh_status NAO e erro -- so ainda nao ha vinculo. Responde "nao vinculado"
// para o modal abrir limpo (com o botao "Gerar codigo" disponivel, que e quem dispara o enroll).
if ($action === 'refresh_status' && ($identifier === '' || $secret === '')) {
   echo json_encode(['success' => true, 'linked' => false, 'environment_id' => '']);
   exit;
}

if ($baseUrl === '' || $identifier === '' || $secret === '') {
   http_response_code(409);
   echo json_encode([
      'success' => false,
      'message' => __('Ambiente ainda não identificado. Tente vincular a conta novamente.', 'nextool'),
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
