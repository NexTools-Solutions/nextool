<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Front Router
 * -------------------------------------------------------------------------
 * Roteador central para módulos do NexTool Solutions.
 * 
 * Este arquivo roteia requisições para arquivos front-end dos módulos e
 * soluciona o problema de roteamento do Symfony no GLPI 11, que intercepta
 * URLs diretas para arquivos dentro de modules/[nome]/front/.
 * 
 * Uso: 
 * - PHP: /plugins/nextool/front/modules.php?module=[module_key]&file=[arquivo].php
 * - CSS: /plugins/nextool/front/modules.php?module=[module_key]&file=[module_key].css.php
 * - JS:  /plugins/nextool/front/modules.php?module=[module_key]&file=[module_key].js.php
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

// Define GLPI_ROOT PRIMEIRO (necessário para caminhos)
if (!defined('GLPI_ROOT')) {
   // Calcula GLPI_ROOT: este arquivo está em plugins/nextool/front/modules.php
   // GLPI_ROOT = 4 níveis acima
   define('GLPI_ROOT', dirname(__FILE__, 4));
}

// Valida parâmetros PRIMEIRO (antes de qualquer include)
$moduleKey = $_GET['module'] ?? '';
$filename = $_GET['file'] ?? '';
$action = $_GET['action'] ?? '';

// Se action for especificado, usa action como filename (para webhook stateless)
if (!empty($action) && empty($filename)) {
   $filename = $action . '.php';
}

if (empty($moduleKey) || empty($filename)) {
   http_response_code(400);
   die('Parâmetros inválidos. Módulo e arquivo (ou action) são obrigatórios.');
}

// Sanitiza parâmetros (segurança)
$moduleKey = preg_replace('/[^a-z0-9_-]/', '', $moduleKey);
// Remove query string se vier colada no file (ex.: file=config.form.php?id=1)
$filename = basename(explode('?', $filename)[0]);

// Verifica se módulo existe
require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
$modulePath = NEXTOOL_MODULES_BASE . '/' . $moduleKey;
$filePath = $modulePath . '/front/' . $filename;

if (!file_exists($filePath)) {
   http_response_code(404);
   die("Arquivo não encontrado: modules/{$moduleKey}/front/{$filename}");
}

// Verifica extensão do arquivo
$extension = pathinfo($filename, PATHINFO_EXTENSION);
$basename = pathinfo($filename, PATHINFO_FILENAME);

// Arquivos CSS/JS (.css.php, .js.php) — servidos diretamente SEM incluir o HTML do GLPI
if (preg_match('/\.(css|js)\.php$/', $filename)) {
   
   // Para arquivos CSS/JS, não inclui includes.php (evita headers HTML)
   // Carrega o arquivo diretamente (ele já define seus próprios headers)
   // Usa output buffering para garantir que nenhum output anterior interfira
   ob_start();
   include($filePath);
   ob_end_flush();
   exit;
}

// Arquivos stateless (webhook.php) - definem suas próprias constantes antes de includes
// Verifica se o arquivo define constantes stateless
$fileContent = @file_get_contents($filePath);
$isStateless = ($fileContent !== false && 
                (strpos($fileContent, 'NO_CHECK_FROMOUTSIDE') !== false || 
                 strpos($fileContent, 'DO_NOT_CHECK_LOGIN') !== false));

if ($isStateless) {
   // Para arquivos stateless, não inclui includes.php aqui
   // O arquivo já define suas próprias constantes e inclui includes.php
   ob_start();
   include($filePath);
   ob_end_flush();
   exit;
}

// Para arquivos PHP normais, inclui o GLPI normalmente
include('../../../inc/includes.php');
Session::checkLoginUser();

// Declarar variáveis globais do GLPI
global $CFG_GLPI;

// Verifica se é um arquivo PHP válido
if ($extension !== 'php') {
   Html::header('Nextool - Erro', $_SERVER['PHP_SELF'], "config", "plugins");
   echo "<div class='alert alert-danger'>Apenas arquivos PHP são permitidos.</div>";
   Html::footer();
   exit;
}

// Carrega o arquivo do módulo
// O arquivo do módulo será executado no contexto atual (variáveis globais já estão disponíveis)
// Cada arquivo do módulo é responsável por suas próprias verificações de permissão e validações
include($filePath);

