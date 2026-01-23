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
 * - AJAX: /plugins/nextool/ajax/module_ajax.php?module=pendingsurvey&file=pendingsurvey.php
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

// Define GLPI_ROOT PRIMEIRO (necessário para caminhos)
if (!defined('GLPI_ROOT')) {
   // Calcula GLPI_ROOT: este arquivo está em plugins/nextool/ajax/module_ajax.php
   // GLPI_ROOT = 4 níveis acima
   define('GLPI_ROOT', dirname(__FILE__, 4));
}

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

// Verifica se módulo existe
$modulePath = GLPI_ROOT . '/plugins/nextool/modules/' . $moduleKey;
$filePath = $modulePath . '/ajax/' . $filename;

if (!file_exists($filePath)) {
   http_response_code(404);
   header('Content-Type: application/json; charset=UTF-8');
   echo json_encode([
      'error' => true,
      'title' => 'Item não encontrado',
      'message' => "Arquivo não encontrado: modules/{$moduleKey}/ajax/{$filename}"
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

// Verifica se o arquivo é stateless (não requer sessão/login)
// Restrito ao módulo mailinteractions para evitar exposição indevida
// Arquivos stateless definem suas próprias constantes e incluem includes.php diretamente
// Detecta padrões comuns: 'GLPI_ROOT', 'require __DIR__', 'NO_CHECK_FROMOUTSIDE', 'DO_NOT_CHECK_LOGIN'
$allowStateless = ($moduleKey === 'mailinteractions');
$fileContent = @file_get_contents($filePath);
$isStateless = ($allowStateless && $fileContent !== false &&
               (strpos($fileContent, 'NO_CHECK_FROMOUTSIDE') !== false ||
                strpos($fileContent, 'DO_NOT_CHECK_LOGIN') !== false ||
                (strpos($fileContent, 'require GLPI_ROOT') !== false && strpos($fileContent, '/inc/includes.php') !== false) ||
                (strpos($fileContent, 'require __DIR__') !== false && strpos($fileContent, '/inc/includes.php') !== false)));

if ($isStateless) {
   // Para arquivos stateless, não inclui includes.php aqui
   // O arquivo já define suas próprias constantes e inclui includes.php
   // IMPORTANTE: Define GLPI_ROOT antes de incluir para que __DIR__ funcione corretamente
   if (!defined('GLPI_ROOT')) {
      // GLPI_ROOT = 4 níveis acima do roteador (plugins/nextool/ajax/module_ajax.php)
      define('GLPI_ROOT', dirname(__FILE__, 4));
   }
   ob_start();
   include($filePath);
   ob_end_flush();
   exit;
}

// Para arquivos AJAX normais, inclui o GLPI normalmente (precisa de sessão, DB, etc)
include('../../../inc/includes.php');

// Carrega o arquivo do módulo
// O arquivo do módulo será executado no contexto atual (variáveis globais já estão disponíveis)
// Cada arquivo do módulo é responsável por suas próprias verificações de permissão e validações
include($filePath);

