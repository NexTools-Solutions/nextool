<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Portão das rotas de API de módulo (middleware de requisição)
 * -------------------------------------------------------------------------
 * nextool-dev#291. Roda em toda rota `/Nextool/<mk>/...`, depois da autenticação OAuth (a
 * sessão do usuário já existe) e antes do controller. Na ordem, cada recusa responde no formato
 * padrão do core, entra no log do módulo e interrompe a cadeia (não chama $next):
 *  1. controller registrado neste boot e dono da rota (senão 404);
 *  2. módulo ativo (senão 404: não revela que a rota existe);
 *  3. licença (PluginNextoolApiRegistry::accessDenyReason): módulo FREE passa direto; PAID/DEV exigem a
 *     licença do módulo ativa (getApiLicenseKey()), senão 403 LICENSE_INACTIVE; o cloud link não é exigido;
 *  4. #[PluginNextoolApiRight] do método: ausente -> 403 (o registro já barra; defesa em
 *     profundidade); cada bit exigido via PermissionManager -> falta -> 403 ERROR_RIGHT_MISSING.
 *
 * A regra mora em PluginNextoolApiGuard::check(), a mesma da chamada em processo (PluginNextoolApiService).
 * Aqui só se traduz a recusa na resposta HTTP no formato do core.
 *
 * process() é void: a recusa vai em $input->response. O resultado da operação NÃO é conhecido
 * aqui (o controller roda depois): quem o registra é o PluginNextoolApiAuditMiddleware.
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

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Middleware\AbstractMiddleware;
use Glpi\Api\HL\Middleware\MiddlewareInput;
use Glpi\Api\HL\Middleware\RequestMiddlewareInterface;
use Glpi\Http\JSONResponse;

final class PluginNextoolApiGuardMiddleware extends AbstractMiddleware implements RequestMiddlewareInterface {

   public function process(MiddlewareInput $input, callable $next): void {
      $routePath = $input->route_path;
      $route = $routePath->getRoutePath();
      $pathModule = PluginNextoolApiRegistry::moduleFromPath($route);

      try {
         $class = $routePath->getController();
         $method = $routePath->getMethod();
      } catch (Throwable $e) {
         $this->deny($input, (string) $pathModule, 'ROUTE_UNRESOLVED', AbstractController::getNotFoundErrorResponse());
         return;
      }

      $denied = PluginNextoolApiGuard::check($class, $method, $pathModule);
      if ($denied !== null) {
         $this->deny($input, $denied['module'], $denied['reason'], match ($denied['kind']) {
            PluginNextoolApiGuard::DENY_NOT_FOUND => AbstractController::getNotFoundErrorResponse(),
            PluginNextoolApiGuard::DENY_LICENSE   => new JSONResponse(AbstractController::getErrorResponseBody(
               AbstractController::ERROR_RIGHT_MISSING,
               __('A licença deste módulo não está ativa: a API do módulo exige licença vigente.', 'nextool'),
               $denied['reason']
            ), 403),
            default => AbstractController::getAccessDeniedErrorResponse(),
         });
         return;
      }

      $next($input);
   }

   private function deny(MiddlewareInput $input, string $moduleKey, string $reason, \Glpi\Http\Response $response): void {
      $input->response = $response;
      PluginNextoolApiRegistry::logEvent($moduleKey, [
         'phase'  => 'denied',
         'verb'   => $input->request->getMethod(),
         'route'  => $input->route_path->getRoutePath(),
         'status' => $response->getStatusCode(),
         'reason' => $reason,
         'target' => class_exists('PluginNextoolApiController') ? PluginNextoolApiController::targetOf($input->request) : null,
      ]);
   }
}
