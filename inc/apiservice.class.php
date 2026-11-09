<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Rotas de API de módulo chamadas DENTRO do processo (nextool-dev#295)
 * -------------------------------------------------------------------------
 * Para quem já está na sessão do usuário e não deve passar por HTTP/OAuth: o assistente de IA
 * (NexChat, módulo aiassist) e o MyDashboards. A chamada percorre o MESMO caminho da rota HTTP:
 *
 *   PluginNextoolApiGuard::check() -> método do controller -> PluginNextoolApiContract::filter()
 *   -> auditoria no log do módulo (canal `inprocess`).
 *
 * O controller é a única implementação: não existe "serviço paralelo" que possa divergir da API.
 *
 * Escrita (`effect: write`) só roda com $confirmedWrite = true: o chamador declara que o usuário
 * confirmou (o NexChat pergunta antes). Sem isso, 428 WRITE_NOT_CONFIRMED, sem executar nada.
 *
 * Consumidor confere `API_VERSION` (contrato) em vez de method_exists. GLPI 11+: no 10 não há
 * controller registrado e describe() sai vazio.
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

final class PluginNextoolApiService {

   /** Versão do contrato deste serviço (describe/call e o formato do descritor). */
   public const API_VERSION = 1;

   public const CHANNEL = 'inprocess';

   /**
    * Descritores das rotas registradas (PluginNextoolApiContract::describe). Por padrão só as que
    * o usuário da sessão pode chamar agora (módulo ativo, licença, direito).
    *
    * @return array<int, array<string, mixed>>
    */
   public static function describe(?string $moduleKey = null, bool $onlyCallable = true): array {
      $out = [];
      foreach (PluginNextoolApiRegistry::registeredControllers() as $class => $owner) {
         if ($moduleKey !== null && $owner !== $moduleKey) {
            continue;
         }
         try {
            foreach (PluginNextoolApiContract::describe($class, $owner) as $route) {
               $method = new ReflectionMethod($class, substr((string) $route['operation'], strlen($owner) + 1));
               $route['callable'] = PluginNextoolApiGuard::check($class, $method, $owner) === null;
               if ($onlyCallable && !$route['callable']) {
                  continue;
               }
               $out[] = $route;
            }
         } catch (Throwable $e) {
            continue; // controller com atributo quebrado não derruba a lista
         }
      }
      return $out;
   }

   /**
    * Executa a rota `<module_key>.<método>` na sessão atual.
    *
    * @param array<string, mixed> $params parâmetros de caminho, query e corpo, pelo nome
    * @return array{status: int, effect: string, data: mixed, reason?: string}
    */
   public static function call(string $operation, array $params = [], bool $confirmedWrite = false): array {
      [$moduleKey, $methodName] = array_pad(explode('.', $operation, 2), 2, '');
      $class = self::controllerFor($moduleKey, $methodName);
      if ($class === null) {
         return self::fail(404, 'OPERATION_NOT_FOUND', PluginNextoolApiRight::EFFECT_WRITE, $moduleKey, '', $operation);
      }
      $method = new ReflectionMethod($class, $methodName);
      $route = PluginNextoolApiContract::describeMethod($method, $moduleKey);
      $verb = (string) ($route['methods'][0] ?? 'GET');
      $effect = (string) $route['effect'];
      $pattern = (string) $route['path'];

      $denied = PluginNextoolApiGuard::check($class, $method, $moduleKey);
      if ($denied !== null) {
         return self::fail($denied['status'], $denied['reason'], $effect, $moduleKey, $verb, $pattern);
      }
      if ($effect === PluginNextoolApiRight::EFFECT_WRITE && !$confirmedWrite) {
         return self::fail(428, 'WRITE_NOT_CONFIRMED', $effect, $moduleKey, $verb, $pattern);
      }

      $request = self::buildRequest($verb, $pattern, (array) $route['requirements'], $params);
      if ($request === null) {
         return self::fail(400, 'INVALID_PATH_PARAMETER', $effect, $moduleKey, $verb, $pattern);
      }

      PluginNextoolApiRegistry::takeResultReason(); // zera resto de chamada anterior
      $level = ob_get_level();
      ob_start();
      try {
         $response = $method->invoke(new $class(), $request);
      } catch (Throwable $e) {
         if (class_exists(\Glpi\Error\ErrorHandler::class)) {
            \Glpi\Error\ErrorHandler::logCaughtException($e);
         }
         PluginNextoolApiRegistry::setResultReason('exception');
         $response = null;
      } finally {
         while (ob_get_level() > $level) {
            ob_end_clean();
         }
      }

      $status = $response !== null ? (int) $response->getStatusCode() : 500;
      $data = null;
      if ($response !== null) {
         $data = json_decode((string) $response->getBody(), true);
         if ($status >= 200 && $status <= 299 && is_array($data)) {
            $data = PluginNextoolApiContract::filter($data, PluginNextoolApiContract::responseSchemaFor($method, $status));
         }
      }
      $reason = PluginNextoolApiRegistry::takeResultReason();
      PluginNextoolApiRegistry::logEvent($moduleKey, [
         'phase'   => 'result',
         'verb'    => $verb,
         'route'   => $pattern,
         'status'  => $status,
         'reason'  => $reason,
         'target'  => isset($params['id']) && is_numeric($params['id']) ? (int) $params['id'] : null,
         'channel' => self::CHANNEL,
      ]);
      $out = ['status' => $status, 'effect' => $effect, 'data' => $data];
      if ($reason !== '') {
         $out['reason'] = $reason;
      }
      return $out;
   }

   /** Controller registrado do módulo que tem o método com #[Route]. */
   private static function controllerFor(string $moduleKey, string $methodName): ?string {
      if ($moduleKey === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $methodName) !== 1) {
         return null;
      }
      foreach (PluginNextoolApiRegistry::registeredControllers() as $class => $owner) {
         if ($owner !== $moduleKey || !method_exists($class, $methodName)) {
            continue;
         }
         $m = new ReflectionMethod($class, $methodName);
         if ($m->isPublic() && $m->getAttributes(\Glpi\Api\HL\Route::class) !== []) {
            return $class;
         }
      }
      return null;
   }

   /**
    * Request equivalente ao que o Router montaria: `{x}` do caminho vira atributo (validado pelo
    * requirement da rota), o resto vira parâmetro (e query, no GET). null = parâmetro de caminho
    * ausente ou fora do formato.
    *
    * @param array<string, mixed> $requirements
    * @param array<string, mixed> $params
    */
   private static function buildRequest(string $verb, string $pattern, array $requirements, array $params): ?\Glpi\Http\Request {
      $pathParams = [];
      $path = preg_replace_callback('/\{([a-z0-9_]+)\}/i', static function (array $m) use ($params, $requirements, &$pathParams) {
         $name = $m[1];
         $value = isset($params[$name]) && is_scalar($params[$name]) ? (string) $params[$name] : '';
         $req = $requirements[$name] ?? '[^/]+';
         if ($value === '' || !is_string($req) || preg_match('#^(?:' . $req . ')$#', $value) !== 1) {
            $pathParams = null;
            return '';
         }
         if (is_array($pathParams)) {
            $pathParams[$name] = $value;
         }
         return rawurlencode($value);
      }, $pattern);
      if ($pathParams === null || !is_string($path)) {
         return null;
      }

      $rest = array_diff_key($params, $pathParams);
      $request = new \Glpi\Http\Request($verb, $path);
      if ($verb === 'GET' || $verb === 'HEAD') {
         $request = $request->withQueryParams($rest);
      }
      foreach ($pathParams as $name => $value) {
         $request->setAttribute($name, $value);
      }
      foreach ($rest as $name => $value) {
         $request->setParameter((string) $name, $value);
      }
      return $request;
   }

   /** @return array{status: int, effect: string, data: null, reason: string} */
   private static function fail(int $status, string $reason, string $effect, string $moduleKey, string $verb, string $route): array {
      PluginNextoolApiRegistry::logEvent($moduleKey, [
         'phase'   => 'denied',
         'verb'    => $verb,
         'route'   => $route,
         'status'  => $status,
         'reason'  => $reason,
         'channel' => self::CHANNEL,
      ]);
      return ['status' => $status, 'effect' => $effect, 'data' => null, 'reason' => $reason];
   }
}
