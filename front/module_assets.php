<?php
/**
 * Nextools - Module Assets Router
 *
 * Roteador genérico para arquivos CSS/JS dos módulos do Nextools.
 * Serve assets sem passar pelos hooks do GLPI. Aceita PATH_INFO ou query string
 * (module=...&file=...). Funciona com qualquer módulo.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

// Define GLPI_ROOT PRIMEIRO (necessário para caminhos)
if (!defined('GLPI_ROOT')) {
   // Calcula GLPI_ROOT: este arquivo está em plugins/nextool/front/module_assets.php
   // GLPI_ROOT = 4 níveis acima
   define('GLPI_ROOT', dirname(__FILE__, 4));
}

// Detecta módulo e arquivo usando PATH_INFO (preferencial) ou query string
$moduleKey = '';
$filename = '';

// Tenta usar PATH_INFO primeiro (formato: /module_assets.php/[module]/[file])
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

// Valida parâmetros
if (empty($moduleKey) || empty($filename)) {
   http_response_code(400);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Parâmetros inválidos. Use: module_assets.php/[module]/[file] ou module_assets.php?module=[module]&file=[file]');
}

// Sanitiza parâmetros (segurança)
$moduleKey = preg_replace('/[^a-z0-9_-]/', '', $moduleKey);
$filename = basename($filename); // Remove caminhos (segurança)

// Assets do plugin exigem sessão autenticada.
require_once GLPI_ROOT . '/inc/includes.php';
Session::checkLoginUser();

// Verifica se módulo existe
require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
$modulePath = NEXTOOL_MODULES_BASE . '/' . $moduleKey;
$filePath = $modulePath . '/front/' . $filename;

if (!file_exists($filePath)) {
   http_response_code(404);
   header('Content-Type: text/plain; charset=UTF-8');
   die("Arquivo não encontrado: modules/{$moduleKey}/front/{$filename}");
}

// Verifica se é um arquivo PHP válido (apenas arquivos .css.php ou .js.php são permitidos)
$extension = pathinfo($filename, PATHINFO_EXTENSION);
if ($extension !== 'php') {
   http_response_code(400);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Apenas arquivos PHP são permitidos (ex: [module_key].css.php, [module_key].js.php)');
}

// Verifica se é um arquivo CSS ou JS (baseado no nome do arquivo)
$isCss = preg_match('/\.css\.php$/', $filename);
$isJs = preg_match('/\.js\.php$/', $filename);

if (!$isCss && !$isJs) {
   http_response_code(400);
   header('Content-Type: text/plain; charset=UTF-8');
   die('Apenas arquivos .css.php ou .js.php são permitidos');
}

// Carrega o arquivo diretamente (ele já define seus próprios headers)
// IMPORTANTE: Não inclui inc/includes.php para evitar headers HTML do GLPI
// Usa output buffering para garantir que nenhum output anterior interfira
ob_start();
include($filePath);
ob_end_flush();
exit;
