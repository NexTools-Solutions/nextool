<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Assets Bundle
 * -------------------------------------------------------------------------
 * Serve, em UM request, a concatenação dos CSS ou JS dos módulos ativos que
 * o PluginNextoolAssetBundler colapsou no boot (ver inc/assetbundler.class.php).
 * Substitui ~16-27 requests por page load (cada um pagando o bootstrap
 * completo do GLPI) por 1 por tipo.
 *
 * URL: front/module_bundle.php?type=js|css&files=mod:file,mod:file,...&h=<hash>
 *  - files: pares módulo:arquivo na ordem de registro (validados um a um:
 *    módulo ativo em files/_plugins, arquivo existente em front/, extensão
 *    coerente com o type). Nada aqui dá acesso além do que o
 *    module_assets.php já serviria individualmente, com a MESMA autenticação.
 *  - h: hash composto dos fv= individuais — só cache-busting (não validado).
 *
 * Cache: private, max-age=3600. O conteúdo agrega assets que variam por
 * sessão (lang/interface/perfil/config — fatores já embutidos nos fv que
 * compõem o h), então o cache é por browser/usuário.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */

// Define GLPI_ROOT (plugin pode estar em plugins/nextool/ ou files/_plugins/nextool/)
if (!defined('GLPI_ROOT')) {
   $candidate = dirname(__FILE__, 4);
   if (!@file_exists($candidate . '/inc/includes.php')) {
      $candidate = dirname(__FILE__, 5);
   }
   define('GLPI_ROOT', $candidate);
}

$type  = isset($_GET['type']) ? (string) $_GET['type'] : '';
$files = isset($_GET['files']) ? (string) $_GET['files'] : '';

if (!in_array($type, ['js', 'css'], true) || $files === '') {
   http_response_code(400);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Parâmetros inválidos. Use: module_bundle.php?type=js|css&files=mod:file,...');
}

// Bundle exige sessão autenticada (mesma política do module_assets.php).
require_once GLPI_ROOT . '/inc/includes.php';
Session::checkLoginUser();

// Libera o lock de sessão imediatamente (mesmo fix do module_assets.php):
// os wrappers incluídos apenas LEEM config/sessão.
if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}

// Remove os headers anti-cache default da sessão PHP (Expires 1981 + Pragma):
// eles anulariam o Cache-Control emitido no final.
header_remove('Expires');
header_remove('Pragma');

require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';

$suffix = '.' . $type . '.php'; // .js.php ou .css.php
$items  = explode(',', $files);

if (count($items) > 40) {
   http_response_code(400);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Bundle excede o limite de itens.');
}

// Valida e resolve cada par mod:file ANTES de servir qualquer byte.
$resolved = [];
foreach ($items as $item) {
   $parts = explode(':', $item, 2);
   if (count($parts) !== 2) {
      continue;
   }
   $mod  = preg_replace('/[^a-z0-9_-]/', '', $parts[0]);
   $file = basename($parts[1]);
   if ($mod === '' || $file === '' || substr($file, -strlen($suffix)) !== $suffix) {
      continue; // extensão incoerente com o type: ignora o item
   }
   $path = NEXTOOL_MODULES_BASE . '/' . $mod . '/front/' . $file;
   if (is_file($path)) {
      $resolved[] = ['mod' => $mod, 'file' => $file, 'path' => $path];
   }
}

if (empty($resolved)) {
   http_response_code(404);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Nenhum asset válido no bundle.');
}

// Concatena. ob_start garante que header() no FINAL ainda funcione mesmo que
// os wrappers emitam headers próprios (os do bundle sobrescrevem por último).
// Wrappers usam `return;` para early-exit (um `exit;` mataria o bundle — por
// isso a convenção exit->return nos assets bundláveis, 2026-06-10).
ob_start();
foreach ($resolved as $asset) {
   echo "\n/* ===== nextool bundle: {$asset['mod']}/{$asset['file']} ===== */\n";
   include($asset['path']);
   echo "\n";
}

header('Content-Type: ' . ($type === 'css' ? 'text/css' : 'application/javascript') . '; charset=UTF-8');
header('Cache-Control: private, max-age=3600');
ob_end_flush();
exit;
