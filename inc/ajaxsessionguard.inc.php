<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Guard de sessão dos endpoints AJAX de módulo
 * -------------------------------------------------------------------------
 * Helpers usados pelo roteador `ajax/module_ajax.php` para restaurar a sessão
 * do usuário SEM criar sessão nova quando o ID do cookie já não existe mais.
 *
 * Por que isso existe (incidente 2026-08):
 * `module_ajax.php` está registrado como stateless (necessário para webhooks
 * POST sem CSRF), então o Kernel do GLPI 11 não inicia a sessão e o roteador a
 * restaura à mão. Com `session.use_strict_mode=0` (default do php.ini da
 * imagem), `session_id($id); session_start();` ACEITA um ID que já foi coletado
 * pelo GC e cria uma sessão nova e vazia. O `Session::checkLoginUser()` estoura,
 * o AccessErrorListener do core chama `Session::destroy()` e o shutdown grava um
 * `sess_<id>` de 0 byte com mtime FRESCO. Como o polling dos módulos bate a cada
 * 15/30s, esse arquivo é renovado para sempre, o GC nunca o coleta, e o endpoint
 * responde 401 indefinidamente até o usuário recarregar a página.
 *
 * A regra aqui é simples: endpoint autenticado NUNCA cunha sessão. Ou a sessão
 * existe e é restaurada, ou a resposta é 401 sem tocar no storage.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!function_exists('plugin_nextool_session_exists')) {
   /**
    * A sessão com este ID existe no storage?
    *
    * Fail-OPEN de propósito: quando não dá para responder com certeza (handler
    * que não seja `files`, save_path inacessível), devolve `true` e deixa a
    * decisão para a camada seguinte do roteador (`use_strict_mode` + comparação
    * do session_id após o start), que é agnóstica de handler. Fail-closed aqui
    * deslogaria todo mundo num ambiente com Redis.
    *
    * @param string $sessId ID já validado contra o alfabeto de session id.
    */
   function plugin_nextool_session_exists(string $sessId): bool {
      if ($sessId === '') {
         return false;
      }
      if (strtolower((string) ini_get('session.save_handler')) !== 'files') {
         return true; // fail-open: quem decide é a camada strict-mode
      }

      // O save_path pode vir como "/caminho", "N;/caminho" ou "N;MODE;/caminho".
      $savePath = (string) session_save_path();
      if ($savePath !== '' && strpos($savePath, ';') !== false) {
         $parts    = explode(';', $savePath);
         $savePath = (string) end($parts);
      }
      if ($savePath === '' && defined('GLPI_SESSION_DIR')) {
         $savePath = (string) GLPI_SESSION_DIR;
      }
      if ($savePath === '' || !@is_dir($savePath)) {
         return true; // fail-open
      }

      return @is_file(rtrim($savePath, '/\\') . '/sess_' . $sessId);
   }
}

if (!function_exists('plugin_nextool_ajax_session_expired')) {
   /**
    * Responde 401 "sessão expirada" e encerra, SEM iniciar sessão e SEM emitir
    * cookie.
    *
    * O `header_remove('Set-Cookie')` é essencial: um `session_start()` que
    * regenerou o ID já enfileirou um Set-Cookie com um ID novo, e deixá-lo
    * passar sobrescreveria no browser o cookie da sessão boa do usuário.
    *
    * O header `X-NexTool-Session: expired` é o marcador estável para o JS -
    * mais confiável do que inferir semântica só do status 401, que também pode
    * vir de outros pontos.
    */
   function plugin_nextool_ajax_session_expired(): void {
      @ini_set('session.use_cookies', '0');

      if (!headers_sent()) {
         header_remove('Set-Cookie');
         http_response_code(401);
         header('Content-Type: application/json; charset=UTF-8');
         header('Cache-Control: no-store');
         header('X-NexTool-Session: expired');
      }

      echo json_encode([
         'error'           => true,
         'session_expired' => true,
         'title'           => __('Sessão expirada', 'nextool'),
         'message'         => __('Sua sessão expirou. Recarregue a página para entrar novamente.', 'nextool'),
      ]);
      exit;
   }
}
