<?php
declare(strict_types=1);
/**
 * NexTool -- evento do anúncio antes do download de módulo gratuito (nextool-dev#283).
 *
 * POST event=impression|click|complete, ad_id, module. Soma no contador local; o acumulado vai agregado no próximo
 * /validate (PluginNextoolDownloadAds). Nada aqui identifica usuário: é só um +1 por (anúncio, módulo, evento).
 * Exige o mesmo direito de baixar módulos (o popup só abre no botão Download). CSRF validado pelo core (cabeçalho do XHR).
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';

PluginNextoolAjaxBootstrap::start([
   'permission_callback' => static fn(): bool => PluginNextoolPermissionManager::canInstallModules(),
   'errors'              => [
      'forbidden' => __('Você não tem permissão para instalar módulos.', 'nextool'),
   ],
]);

$event = isset($_POST['event']) ? (string) $_POST['event'] : '';
$adId = isset($_POST['ad_id']) ? (int) $_POST['ad_id'] : 0;
$module = isset($_POST['module']) ? strtolower(trim((string) $_POST['module'])) : '';

$ok = $adId > 0 && PluginNextoolDownloadAds::recordEvent($adId, $module, $event);
http_response_code($ok ? 200 : 422);
echo json_encode(['success' => $ok]);
exit;
