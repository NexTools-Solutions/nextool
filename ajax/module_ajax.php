<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module AJAX Router
 * -------------------------------------------------------------------------
 * Roteador genérico para arquivos AJAX dos módulos do NexTool Solutions.
 * 
 * Este arquivo roteia requisições AJAX para arquivos dentro de
 * modules/[nome]/ajax/ e soluciona o problema de roteamento do Symfony
 * no GLPI 11, que intercepta URLs diretas para esses arquivos.
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
      'title' => __('Parâmetros inválidos', 'nextool'),
      'message' => __('Módulo e arquivo são obrigatórios. Use: module_ajax.php/[module]/[file] ou module_ajax.php?module=[nome]&file=[arquivo]', 'nextool')
   ]);
   exit;
}

// Sanitiza parâmetros (segurança)
$moduleKey = preg_replace('/[^a-z0-9_-]/', '', $moduleKey);
$filename = basename($filename); // Remove caminhos

// Bloqueia parciais de include (*.inc.php): são fragmentos require_once'd por handlers
// reais (ex.: aiassist.chat.boot.inc.php), nunca endpoints próprios. Servi-los pelo
// roteador executaria lógica de boot fora de contexto e furaria o gate de permissão.
if (preg_match('/\.inc\.php$/', $filename)) {
   http_response_code(404);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error'   => true,
      'title'   => __('Item não encontrado', 'nextool'),
      'message' => __('Recurso não encontrado.', 'nextool'),
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
      'title' => __('Item não encontrado', 'nextool'),
      'message' => __('Recurso não encontrado.', 'nextool'),
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
      'title' => __('Tipo inválido', 'nextool'),
      'message' => __('Apenas arquivos PHP são permitidos', 'nextool')
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

// GLPI 11 (Symfony): sessão e autoloader já carregados pelo Kernel.
// includes.php é stub no GLPI 11, mas mantemos para compatibilidade.
require_once GLPI_ROOT . '/inc/includes.php';

// Quando /ajax/module_ajax.php é registrado como stateless no SessionManager
// (necessário para permitir webhooks POST sem CSRF), o Kernel do GLPI 11:
//   1) Desabilita cookies (session.use_cookies=0)
//   2) NÃO chama session_start() (apenas Session::initVars para defaults)
// Resultado: session_status() = PHP_SESSION_NONE e session_id() = '' (vazio).
//
// Para endpoints autenticados (não-stateless), precisamos restaurar a sessão
// real do usuário: ler o session ID do cookie, setar via session_id() e chamar
// session_start() para carregar os dados da sessão do filesystem.
$sessName = session_name();
$sessId   = $_COOKIE[$sessName] ?? null;

if (!empty($sessId) && is_string($sessId) && session_id() !== $sessId) {
   // Fechar sessão vazia se o Kernel a iniciou (não deve acontecer para
   // stateless, mas proteção defensiva)
   if (session_status() === PHP_SESSION_ACTIVE) {
      session_abort();
   }
   @ini_set('session.use_cookies', '1');
   session_id($sessId);
   session_start();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
   @ini_set('session.use_cookies', '1');
   session_start();
}

Session::checkLoginUser();

// Reaplica CSRF para endpoints autenticados.
// (O CheckCsrfListener é bypassado quando o path é stateless.)
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$bodylessMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
if (!in_array($method, $bodylessMethods, true)) {
   $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
      && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

   if ($isXhr) {
      // Para AJAX, prioriza header (padrão GLPI 11), mas aceita fallback do body.
      $csrfToken = (string) ($_SERVER['HTTP_X_GLPI_CSRF_TOKEN'] ?? ($_POST['_glpi_csrf_token'] ?? ''));
      Session::checkCSRF(['_glpi_csrf_token' => $csrfToken], true);
   } else {
      Session::checkCSRF($_POST);
   }
}

// Libera o lock de sessão para requests de LEITURA (GET/HEAD) antes de incluir
// o handler. Endpoints de polling (contracthours get_status a cada 15s,
// smartnotify notifications a cada 30s, aiassist chat poll) seguravam o lock
// exclusivo do PHP durante todo o processamento, serializando os demais
// requests autenticados da mesma sessão (mesmo fix do module_assets.php).
// $_SESSION continua legível após o write_close. Handlers que ESCREVEM sessão
// (rotação de token CSRF, mensagens) são todos POST — auditado em 2026-06-09:
// accessmatrix/aiassist.action/geolocation.action exigem POST; o único GET que
// escrevia (contracthours timer.php get_status) foi corrigido para só
// rotacionar token em POST.
//
// RETROCOMPAT (incidente portfolio 2026-06-11): MÓDULOS ANTIGOS (ex.:
// contracthours <3.4.6) rotacionam token CSRF também em GET. Com a sessão já
// fechada, o token novo ia pro JSON mas NÃO persistia — o JS do módulo trocava
// o token global da página por esse token-fantasma e TODO POST seguinte (de
// qualquer tela) virava 403 "A ação que você requisitou não é permitida".
// Defesa: snapshot dos tokens antes do write_close + shutdown function (roda
// mesmo se o handler der exit) que detecta tokens novos em $_SESSION (memória)
// e os re-persiste reabrindo a sessão silenciosamente. Plugin novo + módulo
// velho deixa de ser uma combinação quebrada.
if (in_array($method, ['GET', 'HEAD'], true) && session_status() === PHP_SESSION_ACTIVE) {
   $nxPreCloseTokens = $_SESSION['glpicsrftokens'] ?? [];
   register_shutdown_function(static function () use ($nxPreCloseTokens): void {
      $memTokens = $_SESSION['glpicsrftokens'] ?? [];
      if (!is_array($memTokens)) {
         return;
      }
      $newTokens = array_diff_key($memTokens, is_array($nxPreCloseTokens) ? $nxPreCloseTokens : []);
      if ($newTokens === []) {
         return; // caminho comum (handler não gerou token) — custo zero
      }
      if (session_id() === '' || session_status() === PHP_SESSION_ACTIVE) {
         return; // sem sessão para reabrir, ou já reaberta por outrem
      }
      // Reabre a MESMA sessão sem emitir headers (output já foi enviado):
      // cookie já existe no browser e o cache limiter não pode reenviar nada.
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

