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
 *  3. licença da API (PluginNextoolApiRegistry::licenseAllows; senão 403 LICENSE_INACTIVE);
 *  4. #[PluginNextoolApiRight] do método: ausente -> 403 (o registro já barra; defesa em
 *     profundidade); cada bit exigido via PermissionManager -> falta -> 403 ERROR_RIGHT_MISSING.
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

      $moduleKey = PluginNextoolApiRegistry::moduleOf($class);
      if ($moduleKey === null || $moduleKey !== $pathModule) {
         $this->deny($input, (string) $pathModule, 'NOT_REGISTERED', AbstractController::getNotFoundErrorResponse());
         return;
      }

      $manager = PluginNextoolModuleManager::getInstance();
      $active = $manager->getActiveModules();
      $module = is_array($active) ? ($active[$moduleKey] ?? null) : null;
      if (!$module instanceof PluginNextoolBaseModule) {
         $this->deny($input, $moduleKey, 'MODULE_INACTIVE', AbstractController::getNotFoundErrorResponse());
         return;
      }

      if (!PluginNextoolApiRegistry::licenseAllows($manager, $module)) {
         $this->deny($input, $moduleKey, 'LICENSE_INACTIVE', new JSONResponse(AbstractController::getErrorResponseBody(
            AbstractController::ERROR_RIGHT_MISSING,
            __('A licença deste módulo não está ativa: a API do módulo exige licença vigente.', 'nextool'),
            'LICENSE_INACTIVE'
         ), 403));
         return;
      }

      $attrs = $method->getAttributes(PluginNextoolApiRight::class);
      if ($attrs === []) {
         $this->deny($input, $moduleKey, 'RIGHT_UNDECLARED', AbstractController::getAccessDeniedErrorResponse());
         return;
      }
      $right = $attrs[0]->newInstance();
      require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
      $bits = $right->bits;
      $granted = $bits !== [];
      foreach ($bits as $bit) {
         $bit = (int) $bit;
         $ok = $right->kind === PluginNextoolApiRight::KIND_USE
            ? PluginNextoolPermissionManager::canUse($moduleKey, $bit)
            : ($right->kind === PluginNextoolApiRight::KIND_ADMIN && PluginNextoolPermissionManager::canAdmin($moduleKey, $bit));
         if (!$ok) {
            $granted = false;
            break;
         }
      }
      if (!$granted) {
         $this->deny($input, $moduleKey, 'RIGHT_MISSING', AbstractController::getAccessDeniedErrorResponse());
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
