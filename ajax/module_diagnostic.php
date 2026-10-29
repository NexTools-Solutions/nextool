<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Modo diagnóstico dos módulos (estado / ligar / desligar)
 * -------------------------------------------------------------------------
 * Chamado pelo aviso de falha (front/nextool-session.js.php) e pelo painel
 * compartilhado (front/module_diagnostic.tab.inc.php). Só POST, só logado; o CSRF
 * é o do core (inc/includes.php no G10, CheckCsrfListener no G11, cabeçalhos no G12),
 * como no ajax/module_action.php. Não grava falha nenhuma: quem grava é o servidor.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulediagnostic.class.php';

PluginNextoolAjaxBootstrap::start();

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$moduleKey = PluginNextoolModuleDiagnostic::cleanKey(isset($_POST['module']) && is_string($_POST['module']) ? $_POST['module'] : '');

if ($moduleKey === '' || !in_array($action, ['status', 'enable', 'disable'], true)) {
   http_response_code(400);
   echo json_encode(['success' => false, 'message' => __('Parâmetros inválidos.', 'nextool')]);
   exit;
}

if ($action !== 'status') {
   if (!PluginNextoolModuleDiagnostic::canEnable($moduleKey)) {
      http_response_code(403);
      echo json_encode([
         'success' => false,
         'message' => __('Peça a um administrador do módulo para ativar o modo diagnóstico.', 'nextool'),
      ]);
      exit;
   }
   if (!PluginNextoolModuleDiagnostic::setActive($moduleKey, $action === 'enable')) {
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => __('Não foi possível alterar o modo diagnóstico.', 'nextool')]);
      exit;
   }
}

echo json_encode(['success' => true] + PluginNextoolModuleDiagnostic::status($moduleKey));
