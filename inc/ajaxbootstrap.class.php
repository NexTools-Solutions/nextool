<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - AJAX Bootstrap
 * -------------------------------------------------------------------------
 * Helper compartilhado pelos endpoints AJAX do plugin. Consolida o boilerplate
 * de início (Content-Type, autenticação, permissão, método HTTP) para evitar
 * que cada endpoint replique a mesma sequência de 15-20 linhas. Resolve ME-01
 * do audit-deep.
 *
 * Uso típico:
 *
 *   include('../../../inc/includes.php');
 *   require_once NEXTOOL_PHP_DIR . '/inc/ajaxbootstrap.class.php';
 *   require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
 *
 *   PluginNextoolAjaxBootstrap::start([
 *      'login_mode'          => 'redirect',
 *      'permission_callback' => ['PluginNextoolPermissionManager', 'canAccessAdminTabs'],
 *   ]);
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAjaxBootstrap {

   /**
    * Configura o endpoint AJAX. Em caso de falha de autenticação, permissão
    * ou método HTTP, emite resposta JSON apropriada e termina a execução.
    *
    * @param array $opts {
    *   require_method?: string   método HTTP exigido (default 'POST'; 'ANY' = qualquer)
    *   require_login?: bool      default true
    *   login_mode?: 'json'|'redirect'  'json' devolve 403 JSON; 'redirect' chama
    *                             Session::checkLoginUser() que redireciona para login
    *   permission_callback?: callable|null  callback que retorna bool; null = sem check
    *   errors?: array<string,string>  override de mensagens (chaves: no_login, forbidden, bad_method)
    * }
    */
   public static function start(array $opts = []): void {
      $requireMethod = strtoupper($opts['require_method'] ?? 'POST');
      $requireLogin = $opts['require_login'] ?? true;
      $loginMode = $opts['login_mode'] ?? 'json';
      $permissionCallback = $opts['permission_callback'] ?? null;
      $errors = array_merge([
         'no_login'   => __('Sessão inválida.', 'nextool'),
         'forbidden'  => __('Sem permissão para acessar este recurso.', 'nextool'),
         'bad_method' => __('Método HTTP não permitido.', 'nextool'),
      ], $opts['errors'] ?? []);

      header('Content-Type: application/json; charset=UTF-8');

      // 1. Autenticação
      if ($requireLogin) {
         if ($loginMode === 'redirect') {
            Session::checkLoginUser();
         } elseif (!Session::getLoginUserID()) {
            self::respondAndExit(403, 'no_login', $errors['no_login']);
         }
      }

      // 2. Permissão
      if ($permissionCallback !== null && !call_user_func($permissionCallback)) {
         self::respondAndExit(403, 'forbidden', $errors['forbidden']);
      }

      // 3. Método HTTP
      if ($requireMethod !== 'ANY' && ($_SERVER['REQUEST_METHOD'] ?? '') !== $requireMethod) {
         self::respondAndExit(405, 'bad_method', $errors['bad_method']);
      }
   }

   private static function respondAndExit(int $httpCode, string $errorKey, string $message): void {
      http_response_code($httpCode);
      echo json_encode([
         'success' => false,
         'error'   => $errorKey,
         'message' => $message,
      ]);
      exit;
   }
}
