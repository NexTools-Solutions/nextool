<?php
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
 * @author    Richard Loureiro
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
require_once GLPI_ROOT . '/plugins/nextool/inc/statelessmodules.inc.php';
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

// Para arquivos AJAX normais, inclui o GLPI (path absoluto para garantir sessão e CWD)
require_once GLPI_ROOT . '/inc/includes.php';

// GLPI 11: requisições a scripts em ajax/ podem ser atendidas fora do entry point (index.php);
// a sessão nem sempre é iniciada/restaurada. Usar Session do GLPI (path + start) para restaurar pelo cookie.
if (session_status() === PHP_SESSION_NONE && class_exists('Session')) {
   if (method_exists('Session', 'setPath')) {
      Session::setPath();
   }
   Session::start();
}

// Carrega o arquivo do módulo
// O arquivo do módulo será executado no contexto atual (variáveis globais já estão disponíveis)
// Cada arquivo do módulo é responsável por suas próprias verificações de permissão e validações
include($filePath);

