<?php
declare(strict_types=1);
/**
 * NexTool -- suporte aberto pelo plugin (2026-10-09).
 *
 * Ações:
 * - preview (POST): relatório técnico como texto, para a prévia do popup;
 * - download (GET): o mesmo relatório como arquivo (plano B quando a nuvem não responde);
 * - submit (POST): abre o atendimento no portal pela ContainerAPI, com o relatório só se o usuário marcou a caixa.
 *
 * Falha da plataforma responde 424, nunca 5xx: o proxy na frente do GLPI troca resposta 5xx por página HTML e o
 * JS perderia a mensagem (visto no DEV em 2026-10-09).
 *
 * Abrir atendimento exige o bit `BASE_SEND_CONTACT`; o relatório (prévia, download ou anexo) exige também gerenciar
 * o NexTool, porque descreve a estrutura do ambiente. CSRF validado pelo core (cabeçalho do XHR).
 * Desenho: /data/relatorios/suporte-no-plugin-2026-10-09/desenho.md
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';

$action = (string) ($_REQUEST['action'] ?? '');

PluginNextoolAjaxBootstrap::start([
   'require_method'      => $action === 'download' ? 'GET' : 'POST',
   'permission_callback' => static fn(): bool => PluginNextoolPermissionManager::canBase(PluginNextoolPermissionManager::BASE_SEND_CONTACT),
   'errors'              => [
      'forbidden' => __('Você não tem permissão para abrir atendimentos de suporte.', 'nextool'),
   ],
]);

require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/licenseconfig.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/supportreport.class.php';

/** Responde JSON e encerra. */
$respond = static function (int $code, array $data): void {
   http_response_code($code);
   echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
};

/** Texto cru do formulário: no GLPI 10 o core escapa o $_POST na entrada. */
$input = static function (string $key): string {
   $value = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
   if (PluginNextoolCompat::glpiMajor() < 11 && class_exists('\Glpi\Toolbox\Sanitizer')) {
      $value = (string) \Glpi\Toolbox\Sanitizer::unsanitize($value);
   }
   return trim(str_replace("\r\n", "\n", $value));
};

$canReport = PluginNextoolPermissionManager::canManageAdminTabs();

switch ($action) {
   case 'preview':
      if (!$canReport) {
         $respond(403, ['success' => false, 'message' => __('Você não tem permissão para gerar o relatório técnico.', 'nextool')]);
      }
      $text = PluginNextoolSupportReport::toText(PluginNextoolSupportReport::build());
      $respond(200, ['success' => true, 'text' => $text, 'bytes' => strlen($text)]);
      // no break (respond encerra)

   case 'download':
      if (!$canReport) {
         $respond(403, ['success' => false, 'message' => __('Você não tem permissão para gerar o relatório técnico.', 'nextool')]);
      }
      $settings = PluginNextoolConfig::getDistributionSettings();
      $env = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($settings['client_identifier'] ?? '')) ?: 'ambiente';
      $text = PluginNextoolSupportReport::toText(PluginNextoolSupportReport::build());
      header('Content-Type: application/json; charset=UTF-8');
      header('Content-Disposition: attachment; filename="nextool-relatorio-' . $env . '-' . date('Ymd-His') . '.json"');
      header('Cache-Control: no-store');
      echo $text;
      exit;

   case 'submit':
      $types = ['bug', 'suporte', 'duvida', 'sugestao'];
      $subject     = $input('subject');
      $description = $input('description');
      $type        = $input('type');
      $item        = $input('item');
      $withReport  = ($_POST['with_report'] ?? '') === '1';

      $items = [];
      foreach (PluginNextoolSupportReport::supportItems() as $row) {
         $items[$row['key']] = $row;
      }
      $len = static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
      if ($len($subject) < 3 || $len($subject) > 150) {
         $respond(422, ['success' => false, 'message' => __('Informe um assunto entre 3 e 150 caracteres.', 'nextool')]);
      }
      if ($len($description) < 10 || $len($description) > 10000) {
         $respond(422, ['success' => false, 'message' => __('Descreva a solicitação com pelo menos 10 caracteres.', 'nextool')]);
      }
      if (!in_array($type, $types, true) || !isset($items[$item])) {
         $respond(422, ['success' => false, 'message' => __('Escolha o item e o tipo da solicitação.', 'nextool')]);
      }
      if ($withReport && !$canReport) {
         $respond(403, ['success' => false, 'message' => __('Você não tem permissão para gerar o relatório técnico.', 'nextool')]);
      }

      $user = new User();
      $userId = (int) Session::getLoginUserID();
      $openedBy = ['name' => '', 'email' => ''];
      if ($userId > 0 && $user->getFromDB($userId)) {
         $openedBy['name'] = (string) $user->getFriendlyName();
         $openedBy['email'] = (string) UserEmail::getDefaultForUser($userId);
      }

      $settings = PluginNextoolConfig::getDistributionSettings();
      $baseUrl = trim((string) ($settings['base_url'] ?? ''));
      $identifier = trim((string) ($settings['client_identifier'] ?? ''));
      $secret = trim((string) ($settings['client_secret'] ?? ''));
      if ($baseUrl === '' || $identifier === '' || $secret === '') {
         $respond(409, ['success' => false, 'code' => 'account_link_required', 'message' => __('Vincule este ambiente a uma conta NexTool para abrir atendimentos.', 'nextool')]);
      }

      $payload = [
         'subject'     => $subject,
         'description' => $description,
         'type'        => $type,
         'item'        => $item,
         'item_name'   => (string) $items[$item]['name'],
         'opened_by'   => $openedBy,
         'locale'      => (string) ($_SESSION['glpilanguage'] ?? ''),
         'report'      => $withReport ? PluginNextoolSupportReport::build() : null,
      ];

      $unavailable = __('Não foi possível falar com a plataforma NexTool agora. Baixe o relatório e anexe pelo portal, ou tente de novo em instantes.', 'nextool');
      try {
         require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';
         $client = new PluginNextoolDistributionClient($baseUrl, $identifier, $secret);
         $result = $client->openSupportRequest($payload);
      } catch (PluginNextoolDistributionException $e) {
         $code = $e->getErrorCode();
         if ($e->getHttpCode() === 404 || $e->getHttpCode() >= 500) {
            // 404 = plataforma sem o endpoint (ainda); 5xx = fora do ar: a saída é a mesma para o usuário
            Toolbox::logInFile('plugin_nextool', '[ERROR] [SUPPORT] plataforma respondeu HTTP ' . $e->getHttpCode() . "\n");
            $respond(424, ['success' => false, 'code' => 'unreachable', 'message' => $unavailable]);
         }
         $message = $code === 'account_link_required'
            ? __('Vincule este ambiente a uma conta NexTool para abrir atendimentos.', 'nextool')
            : ($code === 'rate_limited'
               ? __('Muitos atendimentos abertos em pouco tempo. Tente novamente mais tarde.', 'nextool')
               : $e->getMessage());
         $respond($e->getHttpCode() >= 400 && $e->getHttpCode() < 500 ? $e->getHttpCode() : 424, ['success' => false, 'code' => $code, 'message' => $message]);
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', '[ERROR] [SUPPORT] falha ao abrir atendimento: ' . get_class($e) . "\n");
         $respond(424, ['success' => false, 'code' => 'unreachable', 'message' => $unavailable]);
      }

      $respond(200, [
         'success'           => true,
         'request_id'        => (string) ($result['request_id'] ?? ''),
         'url'               => (string) ($result['url'] ?? ''),
         'tier'              => (string) ($result['tier'] ?? ''),
         'type'              => (string) ($result['type'] ?? $type),
         'prazo_resposta_em' => $result['prazo_resposta_em'] ?? null,
      ]);
      // no break

   default:
      $respond(400, ['success' => false, 'message' => __('Ação inválida.', 'nextool')]);
}
