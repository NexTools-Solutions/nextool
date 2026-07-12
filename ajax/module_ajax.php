<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module AJAX Router
 * -------------------------------------------------------------------------
 * Roteador genérico para arquivos AJAX dos módulos do NexTool Solutions.
 *
 * Uso:
 * - AJAX: /plugins/nextool/ajax/module_ajax.php?module=[module_key]&file=[arquivo].php
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

// Define GLPI_ROOT (plugin pode estar em plugins/nextool/ ou files/_plugins/nextool/)
if (!defined('GLPI_ROOT')) {
   $candidate = dirname(__FILE__, 4);
   if (!@file_exists($candidate . '/inc/includes.php')) {
      $candidate = dirname(__FILE__, 5);
   }
   define('GLPI_ROOT', $candidate);
}

require_once dirname(__DIR__) . '/inc/modulespath.inc.php';

// Detecta módulo e arquivo usando PATH_INFO (preferencial) ou query string
// PATH_INFO é mais confiável com Symfony, mas query string funciona como fallback
$moduleKey = '';
$filename = '';

// Tenta usar PATH_INFO primeiro (formato: /module_ajax.php/[module]/[file])
if (isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])) {
   $pathInfo = trim($_SERVER['PATH_INFO'], '/');
   $parts = explode('/', $pathInfo, 2);
   
   if (count($parts) >= 2) {
      $moduleKey = $parts[0];
      $filename = $parts[1];
   }
}

// Se não encontrou via PATH_INFO, tenta query string
if (empty($moduleKey) || empty($filename)) {
   $moduleKey = $_GET['module'] ?? '';
   $filename = $_GET['file'] ?? '';
}

if (empty($moduleKey) || empty($filename)) {
   http_response_code(400);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error' => true,
      'title' => 'Parâmetros inválidos',
      'message' => 'Módulo e arquivo são obrigatórios. Use: module_ajax.php/[module]/[file] ou module_ajax.php?module=[nome]&file=[arquivo]'
   ]);
   exit;
}

// Sanitiza parâmetros (segurança)
$moduleKey = preg_replace('/[^a-z0-9_-]/', '', $moduleKey);
$filename = basename($filename); // Remove caminhos

// Bloqueia parciais de include (*.inc.php): são fragmentos require_once'd por handlers
// reais, nunca endpoints próprios. Servi-los pelo roteador executaria lógica fora de
// contexto e furaria o gate de permissão do módulo.
if (preg_match('/\.inc\.php$/', $filename)) {
   http_response_code(404);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error'   => true,
      'title'   => 'Item não encontrado',
      'message' => 'Recurso não encontrado.',
   ]);
   exit;
}

$modulePath = NEXTOOL_MODULES_BASE . '/' . $moduleKey;
$filePath = $modulePath . '/ajax/' . $filename;

if (!file_exists($filePath)) {
   error_log("[NEXTOOL] module_ajax: file not found – {$moduleKey}/{$filename}");
   http_response_code(404);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error' => true,
      'title' => 'Item não encontrado',
      'message' => 'Recurso não encontrado.',
   ]);
   exit;
}

// Verifica extensão do arquivo (apenas PHP)
$extension = pathinfo($filename, PATHINFO_EXTENSION);
if ($extension !== 'php') {
   http_response_code(400);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error' => true,
      'title' => 'Tipo inválido',
      'message' => 'Apenas arquivos PHP são permitidos'
   ]);
   exit;
}

// Verifica se o arquivo é stateless (não requer sessão/login) via whitelist explícita
require_once NEXTOOL_PHP_DIR . '/inc/statelessmodules.inc.php';
$statelessFiles = plugin_nextool_stateless_files();
$isStateless = isset($statelessFiles[$moduleKey])
   && in_array($filename, $statelessFiles[$moduleKey], true);

if ($isStateless) {
   // Para arquivos stateless, não inclui includes.php aqui; o arquivo inclui includes.php
   // GLPI_ROOT já foi definido no topo (com suporte a plugin em plugins/ ou files/_plugins/)
   ob_start();
   include($filePath);
   ob_end_flush();
   exit;
}

// Carrega sessão e autoloader do GLPI
require_once GLPI_ROOT . '/inc/includes.php';
Session::checkLoginUser();

// Libera o lock de sessão para requests de LEITURA (GET/HEAD) antes de incluir
// o handler (paridade com o GLPI 11, 2026-06-10): endpoints de polling seguravam
// o lock exclusivo do PHP e serializavam os demais requests da mesma sessão.
// Handlers que ESCREVEM sessão (rotação de token CSRF) são POST-only -
// auditado: aiassist.action exige POST (linha ~129).
//
// RETROCOMPAT (incidente portfolio 2026-06-11, paridade GLPI 11): módulos
// ANTIGOS rotacionam token CSRF também em GET. Com a sessão já fechada, o
// token novo ia pro JSON mas NÃO persistia - o JS trocava o token global da
// página pelo token-fantasma e TODO POST seguinte virava 403. Defesa:
// snapshot dos tokens antes do write_close + shutdown function (roda mesmo
// com exit no handler) que re-persiste tokens novos reabrindo a sessão
// silenciosamente. Plugin novo + módulo velho deixa de ser combinação quebrada.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (in_array($method, ['GET', 'HEAD'], true) && session_status() === PHP_SESSION_ACTIVE) {
   $nxPreCloseTokens = $_SESSION['glpicsrftokens'] ?? [];
   register_shutdown_function(static function () use ($nxPreCloseTokens): void {
      $memTokens = $_SESSION['glpicsrftokens'] ?? [];
      if (!is_array($memTokens)) {
         return;
      }
      $newTokens = array_diff_key($memTokens, is_array($nxPreCloseTokens) ? $nxPreCloseTokens : []);
      if ($newTokens === []) {
         return; // caminho comum (handler não gerou token) - custo zero
      }
      if (session_id() === '' || session_status() === PHP_SESSION_ACTIVE) {
         return; // sem sessão para reabrir, ou já reaberta por outrem
      }
      // Reabre a MESMA sessão sem emitir headers (output já foi enviado).
      @ini_set('session.use_cookies', '0');
      @session_cache_limiter('');
      if (!@session_start()) {
         return;
      }
      $diskTokens = $_SESSION['glpicsrftokens'] ?? [];
      $_SESSION['glpicsrftokens'] = (is_array($diskTokens) ? $diskTokens : []) + $newTokens;
      session_write_close();
   });
   session_write_close();
}

// Carrega o arquivo do módulo
include($filePath);

