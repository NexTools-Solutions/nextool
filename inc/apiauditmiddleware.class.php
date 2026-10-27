<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Auditoria das rotas de API de módulo (middleware de resposta)
 * -------------------------------------------------------------------------
 * nextool-dev#291. Roda depois do controller numa rota `/Nextool/<mk>/...` e registra no log do
 * módulo o RESULTADO (status HTTP + motivo posto pelo controller, ex.: `exception`). As recusas
 * do portão já foram registradas por ele e não passam por aqui.
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

// GLPI 10 não tem a API v2.
if (!class_exists(\Glpi\Api\HL\Middleware\AbstractMiddleware::class)) {
   return;
}

use Glpi\Api\HL\Middleware\AbstractMiddleware;
use Glpi\Api\HL\Middleware\MiddlewareInput;
use Glpi\Api\HL\Middleware\ResponseMiddlewareInterface;

final class PluginNextoolApiAuditMiddleware extends AbstractMiddleware implements ResponseMiddlewareInterface {

   public function process(MiddlewareInput $input, callable $next): void {
      try {
         $class = $input->route_path->getController();
         $moduleKey = PluginNextoolApiRegistry::moduleOf($class);
         if ($moduleKey !== null && $input->response !== null) {
            PluginNextoolApiRegistry::logEvent($moduleKey, [
               'phase'  => 'result',
               'verb'   => $input->request->getMethod(),
               'route'  => $input->route_path->getRoutePath(),
               'status' => $input->response->getStatusCode(),
               'reason' => PluginNextoolApiRegistry::takeResultReason(),
               'target' => PluginNextoolApiController::targetOf($input->request),
            ]);
         }
      } catch (Throwable $e) {
         // auditoria nunca altera a resposta
      }
      $next($input);
   }
}
