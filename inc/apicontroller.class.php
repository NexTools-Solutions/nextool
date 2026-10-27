<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Controller base das rotas de API de módulo (API v2 do GLPI 11+)
 * -------------------------------------------------------------------------
 * Contrato (nextool-dev#291; detalhe na KB nextools: contexts/plugin/glpi11/api-v2.md):
 *  - o módulo declara as classes em getApiControllers(); a base as registra no hook
 *    `api_controllers` só se passarem no PluginNextoolApiRegistry;
 *  - #[Route('/Nextool/<module_key>')] na CLASSE (prefixo igual à chave do módulo);
 *  - todo método com #[Route] tem #[RouteVersion(introduced: '2.3')], #[PluginNextoolApiRight]
 *    e Doc\Route; o corpo roda dentro de $this->handle() (exceção vira 500 no formato padrão);
 *  - escopo por OBJETO continua obrigatório no método (assertCanItem): o atributo só confere o
 *    bit do módulo;
 *  - NUNCA sobrescrever getRawKnownSchemas()/getKnownSchemas() (assinatura mudou no GLPI 12);
 *  - o arquivo do controller começa com a mesma guarda abaixo (no GLPI 10 a classe-mãe não existe).
 *
 * O formato de erro é o do próprio core (AbstractController::getErrorResponseBody).
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

// GLPI 10 não tem a API v2: a classe-mãe não existe e a declaração abaixo seria fatal.
if (!class_exists(\Glpi\Api\HL\Controller\AbstractController::class)) {
   return;
}

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;

abstract class PluginNextoolApiController extends AbstractController {

   /** Chave do módulo dono do controller, lida do #[Route] da classe ('' se fora do contrato). */
   final public static function moduleKey(): string {
      $attrs = (new ReflectionClass(static::class))->getAttributes(Route::class);
      if ($attrs === []) {
         return '';
      }
      $path = (string) $attrs[0]->newInstance()->path;
      return preg_match('#^/Nextool/([a-z0-9_]+)$#', $path, $m) === 1 ? $m[1] : '';
   }

   /**
    * Executa o corpo da rota. Sem isto, uma exceção sairia do Router (o core a converte em 500
    * sem corpo) e pularia o middleware de auditoria: aqui ela vira 500 no formato padrão, vai para
    * o log de erros do GLPI e o motivo `exception` segue para a auditoria.
    *
    * @param callable(): Response $fn
    */
   final protected function handle(Request $request, callable $fn): Response {
      try {
         return $fn();
      } catch (Throwable $e) {
         \Glpi\Error\ErrorHandler::logCaughtException($e);
         PluginNextoolApiRegistry::setResultReason('exception');
         return $this->error(self::ERROR_GENERIC, __('Erro interno ao processar a requisição.', 'nextool'), 500);
      }
   }

   /** Motivo que acompanha o resultado na auditoria (ex.: SYNC_IN_PROGRESS), só chaves. */
   final protected function reason(string $reason): void {
      PluginNextoolApiRegistry::setResultReason($reason);
   }

   final protected function json(array $data, int $status = 200, array $headers = []): Response {
      return new JSONResponse($data, $status, $headers);
   }

   final protected function error(string $status, string $title, int $http, string|array|null $detail = null): Response {
      return new JSONResponse(self::getErrorResponseBody($status, $title, $detail), $http);
   }

   final protected function notFound(): Response {
      return self::getNotFoundErrorResponse();
   }

   final protected function denied(?string $detail = null): Response {
      return self::getAccessDeniedErrorResponse($detail);
   }

   final protected function invalid(array $errors): Response {
      return self::getInvalidParametersErrorResponse($errors);
   }

   /**
    * Escopo por objeto: o item existe E o usuário da sessão tem $right sobre ele. Responde 404 nos
    * dois casos (não revela que o item existe). null = pode seguir.
    *
    * @param class-string<CommonDBTM> $itemtype
    */
   final protected function assertCanItem(string $itemtype, int $id, int $right): ?Response {
      if ($id <= 0 || !is_a($itemtype, CommonDBTM::class, true)) {
         return $this->notFound();
      }
      $item = new $itemtype();
      return $item->can($id, $right) ? null : $this->notFound();
   }

   /** `{id}` da rota, quando há (alvo do log). */
   final public static function targetOf(Request $request): ?int {
      $id = $request->getAttribute('id');
      return is_numeric($id) ? (int) $id : null;
   }
}
