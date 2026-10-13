<?php
declare(strict_types=1);
/**
 * NexTool -- imagem do anúncio antes do download de módulo gratuito (nextool-dev#283).
 *
 * Serve a cópia local que o /validate baixou da ContainerAPI (conferida pelo sha256). Assim o navegador do usuário
 * nunca fala com a NexTool: funciona com CSP e firewall restritos e não entrega o IP de quem vê o anúncio.
 * Só sha de anúncio guardado; exige ver a guia Módulos.
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';

Session::checkLoginUser();
if (!PluginNextoolPermissionManager::canViewAnyModule()) {
   http_response_code(403);
   exit;
}

$sha = isset($_GET['sha']) ? strtolower((string) $_GET['sha']) : '';
$path = PluginNextoolDownloadAds::imagePath($sha);
$mime = $path !== null ? PluginNextoolDownloadAds::imageMime($sha) : null;
if ($path === null || $mime === null) {
   http_response_code(404);
   exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
// O nome é o sha256 do conteúdo: a mesma URL nunca muda de imagem.
header('Cache-Control: private, max-age=86400, immutable');
readfile($path);
exit;
