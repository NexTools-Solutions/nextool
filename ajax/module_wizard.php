<?php
declare(strict_types=1);
/**
 * Nextools - Assistente de primeira configuração dos módulos (AJAX).
 *
 * Ações (POST, JSON): pending, outline, step, save, test, pause, finish, reopen. Todas exigem o direito de CONFIGURAR o
 * módulo (o mesmo da tela de configuração). Cada passo grava pelo save do próprio módulo. Contrato dos passos em
 * inc/setupwizard.class.php.
 *
 * @license GPLv3+
 */

include('../../../inc/includes.php');
require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/setupwizard.class.php';

PluginNextoolAjaxBootstrap::start([
   'errors' => [
      'forbidden'  => __('Você não tem permissão para configurar este módulo.', 'nextool'),
      'bad_method' => __('Método inválido para esta ação.', 'nextool'),
   ],
]);

$respond = static function (array $payload, int $status = 200): void {
   if ($status !== 200) {
      http_response_code($status);
   }
   echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   exit;
};

$action = trim((string) ($_POST['action'] ?? ''));
$key = strtolower(trim((string) ($_POST['module'] ?? '')));
if ($action === '' || !preg_match('/^[a-z0-9_-]{1,64}$/', $key)) {
   $respond(['success' => false, 'message' => __('Parâmetros inválidos.', 'nextool')], 400);
}

// pending: pergunta barata das páginas de módulo; sem direito de configurar responde "não" (sem 403 no console).
if (!PluginNextoolPermissionManager::canAdmin($key, PluginNextoolPermissionManager::CONFIGURE)) {
   if ($action === 'pending') {
      $respond(['success' => true, 'pending' => false]);
   }
   $respond(['success' => false, 'message' => __('Você não tem permissão para configurar este módulo.', 'nextool')], 403);
}
if (!PluginNextoolSetupWizard::ready() || !PluginNextoolSetupWizard::hasWizard($key)) {
   $respond(['success' => true, 'pending' => false, 'available' => false]);
}

$stepName = trim((string) ($_POST['step'] ?? ''));
$input = $_POST;
foreach (['action', 'module', 'step', '_glpi_csrf_token'] as $k) {
   unset($input[$k]);
}

try {
   switch ($action) {
      case 'pending':
         $respond(['success' => true, 'pending' => PluginNextoolSetupWizard::isPending($key)]);

      case 'reopen':
         PluginNextoolSetupWizard::setStatus($key, PluginNextoolSetupWizard::STATUS_PENDING, '');
         // segue para o outline
      case 'outline':
         $respond(['success' => true, 'outline' => PluginNextoolSetupWizard::outline($key)]);

      case 'step':
      case 'save':
      case 'test':
         $steps = PluginNextoolSetupWizard::steps($key);
         $st = PluginNextoolSetupWizard::findStep($steps, $stepName);
         if ($st === null) {
            $respond(['success' => false, 'message' => __('Passo inválido.', 'nextool')], 400);
         }
         if ($action === 'step') {
            PluginNextoolSetupWizard::markStep($key, $stepName, false);
            $respond(['success' => true, 'html' => (string) call_user_func($st['render'])]);
         }
         if ($action === 'test') {
            if (!isset($st['test'])) {
               $respond(['success' => false, 'message' => __('Este passo não tem teste.', 'nextool')], 400);
            }
            $r = (array) call_user_func($st['test'], $input);
            $respond(['success' => true, 'ok' => !empty($r['ok']), 'message' => (string) ($r['message'] ?? '')]);
         }
         $r = (array) call_user_func($st['save'], $input);
         if (empty($r['ok'])) {
            $respond(['success' => true, 'ok' => false, 'message' => (string) ($r['message'] ?? __('Não foi possível salvar este passo.', 'nextool'))]);
         }
         PluginNextoolSetupWizard::markStep($key, $stepName, true);
         // O próximo passo é recalculado: um passo pode pular outro (ex.: modo gerenciado dispensa o provedor).
         $after = PluginNextoolSetupWizard::steps($key);
         $next = '';
         $found = false;
         foreach ($after as $s) {
            if ($found) {
               $next = $s['name'];
               break;
            }
            if ($s['name'] === $stepName) {
               $found = true;
            }
         }
         $respond(['success' => true, 'ok' => true, 'next' => $next, 'outline' => PluginNextoolSetupWizard::outline($key)]);

      case 'pause':
         PluginNextoolSetupWizard::setStatus($key, PluginNextoolSetupWizard::STATUS_PAUSED);
         $respond(['success' => true]);

      case 'finish':
         PluginNextoolSetupWizard::setStatus($key, PluginNextoolSetupWizard::STATUS_DONE);
         $respond(['success' => true]);
   }
} catch (\Throwable $e) {
   Toolbox::logInFile('plugin_nextool', '[wizard] ' . $key . ' ' . $action . ': ' . $e->getMessage() . "\n");
   $respond(['success' => false, 'message' => __('Erro inesperado no assistente.', 'nextool')], 500);
}

$respond(['success' => false, 'message' => __('Ação inválida.', 'nextool')], 400);
