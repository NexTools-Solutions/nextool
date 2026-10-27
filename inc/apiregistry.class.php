<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Registro das rotas de API dos módulos (API v2 do GLPI 11+)
 * -------------------------------------------------------------------------
 * nextool-dev#291. Monta o hook `api_controllers` com os controllers dos módulos ATIVOS que
 * passam na validação, o hook `api_middleware` (portão + auditoria) e cuida do cache de rotas
 * do core.
 *
 * Por que a validação é rigorosa: o core guarda as rotas num cache GLOBAL (`hlapi_routes`, 5 min)
 * que ele nunca invalida, e monta a lista hidratando TODAS as rotas do verbo pedido. Uma rota
 * sem #[RouteVersion], ou um hint que aponte para classe/método que sumiu, lança dentro do
 * Router::matchAll ANTES de qualquer middleware: a API v2 inteira do cliente responde 500,
 * inclusive as rotas nativas. Por isso:
 *  - controller inválido fica fora do hook (isolado: os demais seguem);
 *  - o hook é declarado em TODA requisição (o cache pode ser montado por um webhook ou cron);
 *  - healStaleRouteCache() apaga o cache em requisição de API quando ele aponta para controller
 *    NexTool que não existe mais ou não está registrado neste boot (módulo desativado, update
 *    concorrente, reativação do plugin);
 *  - invalidateRouteCache() roda em cada transição de ciclo de vida.
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

final class PluginNextoolApiRegistry {

   /** Chave do cache de rotas da API v2 no core (Glpi\Api\HL\Router::cacheRoutes). */
   public const CORE_ROUTE_CACHE_KEY = 'hlapi_routes';

   private const PATH_PATTERN = '#^/Nextool/([a-z0-9_]+)(/|$)#i';

   /** @var array<class-string, string> controller registrado neste boot => chave do módulo */
   private static array $owners = [];

   /** Motivo do resultado desta requisição, posto pelo controller e lido pela auditoria. */
   private static string $resultReason = '';

   /** A API v2 existe neste GLPI (11+). No 10 nada daqui é registrado. */
   public static function isAvailable(): bool {
      return class_exists(\Glpi\Api\HL\Controller\AbstractController::class)
         && class_exists('PluginNextoolApiController');
   }

   /**
    * Controllers dos módulos ativos para o hook `api_controllers`. Cada um é validado; o inválido
    * fica fora e vai para o log da base.
    *
    * @param array<string, PluginNextoolBaseModule> $activeModules getActiveModules()
    * @return class-string[]
    */
   public static function controllersForHook(array $activeModules): array {
      self::$owners = [];
      if (!self::isAvailable()) {
         return [];
      }
      foreach ($activeModules as $moduleKey => $module) {
         if (!$module instanceof PluginNextoolBaseModule) {
            continue;
         }
         try {
            $declared = $module->getApiControllers();
         } catch (Throwable $e) {
            self::logInvalid((string) $moduleKey, '-', 'getApiControllers() lançou: ' . $e->getMessage());
            continue;
         }
         foreach ($declared as $class) {
            if (!is_string($class) || $class === '') {
               continue;
            }
            try {
               $problem = self::validate($module, $class);
            } catch (Throwable $e) {
               $problem = 'validação lançou: ' . $e->getMessage();
            }
            if ($problem !== null) {
               self::logInvalid((string) $moduleKey, $class, $problem);
               continue;
            }
            self::$owners[$class] = (string) $moduleKey;
         }
      }
      return array_keys(self::$owners);
   }

   /**
    * Entradas do hook `api_middleware`: portão (requisição) e auditoria (resposta), só nas rotas
    * `/Nextool/`.
    *
    * @return array<int, array{middleware: string, priority: int, condition: callable}>
    */
   public static function middlewaresForHook(): array {
      if (!self::isAvailable()
          || !class_exists('PluginNextoolApiGuardMiddleware')
          || !class_exists('PluginNextoolApiAuditMiddleware')) {
         return [];
      }
      $isNextoolRoute = static fn($routePath): bool => self::moduleFromPath((string) $routePath->getRoutePath()) !== null;
      // Prioridade negativa: o Router encadeia do maior para o menor, então o portão roda DEPOIS dos
      // middlewares do core (IP, escopo OAuth, CRUD, RSQL), imediatamente antes do controller.
      return [
         ['middleware' => 'PluginNextoolApiGuardMiddleware', 'priority' => -10, 'condition' => $isNextoolRoute],
         ['middleware' => 'PluginNextoolApiAuditMiddleware', 'priority' => -10, 'condition' => $isNextoolRoute],
      ];
   }

   /** Módulo dono de um controller registrado NESTE boot (null = não registrado). */
   public static function moduleOf(string $class): ?string {
      return self::$owners[$class] ?? null;
   }

   /** Chave do módulo pelo caminho da rota (`/Nextool/<mk>/...`). */
   public static function moduleFromPath(string $path): ?string {
      return preg_match(self::PATH_PATTERN, $path, $m) === 1 ? strtolower($m[1]) : null;
   }

   /**
    * Problema do controller, ou null se ele cumpre o contrato. Pública para o E2E e o quick-test.
    */
   public static function validate(PluginNextoolBaseModule $module, string $class): ?string {
      if (!class_exists($class)) {
         return 'classe não encontrada';
      }
      if (!is_subclass_of($class, 'PluginNextoolApiController')) {
         return 'não estende PluginNextoolApiController';
      }
      $rc = new ReflectionClass($class);
      if (!$rc->isInstantiable()) {
         return 'classe abstrata ou não instanciável';
      }
      $ctor = $rc->getConstructor();
      if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
         return 'construtor com parâmetro obrigatório (o core instancia sem argumento)';
      }

      $moduleKey = (string) $module->getModuleKey();
      $classRoute = $rc->getAttributes(\Glpi\Api\HL\Route::class);
      if ($classRoute === [] || $classRoute[0]->newInstance()->path !== '/Nextool/' . $moduleKey) {
         return "falta #[Route('/Nextool/{$moduleKey}')] na classe";
      }
      foreach (['getRawKnownSchemas', 'getKnownSchemas'] as $schemaMethod) {
         if ($rc->hasMethod($schemaMethod)
             && $rc->getMethod($schemaMethod)->getDeclaringClass()->getName() !== \Glpi\Api\HL\Controller\AbstractController::class) {
            return "sobrescreve {$schemaMethod}() (assinatura difere entre GLPI 11 e 12)";
         }
      }

      $usable = array_map('intval', array_keys($module->getUsabilityRights()));
      $admin  = array_values(array_diff(array_map('intval', array_keys($module->getProfileRights())), $usable));
      $routes = 0;
      foreach ($rc->getMethods() as $method) {
         if ($method->getAttributes(\Glpi\Api\HL\Route::class) === []) {
            continue;
         }
         $name = $method->getName();
         if (!$method->isPublic()) {
            return "{$name}(): #[Route] em método não público";
         }
         if ($method->getAttributes(\Glpi\Api\HL\RouteVersion::class) === []) {
            return "{$name}(): falta #[RouteVersion]";
         }
         $rights = $method->getAttributes(PluginNextoolApiRight::class);
         if ($rights === []) {
            return "{$name}(): falta #[PluginNextoolApiRight]";
         }
         $right = $rights[0]->newInstance();
         if (!in_array($right->kind, [PluginNextoolApiRight::KIND_ADMIN, PluginNextoolApiRight::KIND_USE], true)) {
            return "{$name}(): kind inválido";
         }
         if ($right->bits === []) {
            return "{$name}(): bits vazio (não exigiria nada)";
         }
         $allowed = $right->kind === PluginNextoolApiRight::KIND_USE ? $usable : $admin;
         foreach ($right->bits as $bit) {
            if (!is_int($bit) || $bit <= 0 || ($bit & ($bit - 1)) !== 0) {
               return "{$name}(): bit composto ou inválido (um bit por item)";
            }
            if (!in_array($bit, $allowed, true)) {
               return "{$name}(): bit {$bit} não é um direito '{$right->kind}' do módulo";
            }
         }
         $routes++;
      }
      return $routes === 0 ? 'nenhuma rota declarada' : null;
   }

   /** Apaga o cache de rotas do core (próxima requisição remonta com os controllers deste boot). */
   public static function invalidateRouteCache(): void {
      global $GLPI_CACHE;
      try {
         if (is_object($GLPI_CACHE) && method_exists($GLPI_CACHE, 'delete')) {
            $GLPI_CACHE->delete(self::CORE_ROUTE_CACHE_KEY);
         }
      } catch (Throwable $e) {
         // fail-silent: o cache expira sozinho em 5 min
      }
   }

   /**
    * Só em requisição da API v2+: apaga o cache de rotas se ele cita controller NexTool que não
    * existe mais, método que sumiu, controller que não está registrado neste boot, ou se falta nele
    * um controller registrado. Lê só as chaves NexTool.
    */
   public static function healStaleRouteCache(): void {
      global $GLPI_CACHE;
      if (!self::isApiRequest() || !is_object($GLPI_CACHE)) {
         return;
      }
      try {
         $hints = $GLPI_CACHE->get(self::CORE_ROUTE_CACHE_KEY);
      } catch (Throwable $e) {
         return;
      }
      if (!is_array($hints) || $hints === []) {
         return;
      }
      $seen = [];
      foreach ($hints as $hint) {
         $key = is_array($hint) ? (string) ($hint['key'] ?? '') : '';
         if (strncmp($key, 'PluginNextool', 13) !== 0) {
            continue;
         }
         [$class, $method] = array_pad(explode('::', $key, 2), 2, '');
         if (!isset(self::$owners[$class]) || !class_exists($class) || !method_exists($class, $method)) {
            self::invalidateRouteCache();
            return;
         }
         $seen[$class] = true;
      }
      if (array_diff_key(self::$owners, $seen) !== []) {
         self::invalidateRouteCache();
      }
   }

   /** Requisição da API v2+ (`/api.php`, exceto a v1). */
   public static function isApiRequest(): bool {
      $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
      if (!str_contains($uri, '/api.php')) {
         return false;
      }
      return preg_match('#/api\.php/v1(?:[/?]|$)#', $uri) !== 1;
   }

   /**
    * Licença da API do módulo (decisão do owner, 2026-10-27): FREE sempre; PAID só com direito
    * vigente (a régua única PluginNextoolCloudCreds::entitled: status `active` dentro da graça
    * offline); DEV com direito vigente ou no plano Desenvolvimento. A TELA segue a regra própria
    * (módulo com histórico de licença continua ativo): esta restrição é só da API.
    */
   public static function licenseAllows(PluginNextoolModuleManager $manager, PluginNextoolBaseModule $module): bool {
      $moduleKey = (string) $module->getModuleKey();
      $tier = $manager->getBillingTier($moduleKey);
      if ($tier === 'FREE') {
         return true;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $licenseKey = $module->getCloudLicenseKey() ?? $moduleKey;
      if (PluginNextoolCloudCreds::entitled($licenseKey)) {
         return true;
      }
      if ($tier === 'DEV' && class_exists('PluginNextoolLicenseConfig') && class_exists('PluginNextoolLicenseValidator')) {
         $config = PluginNextoolLicenseConfig::getDefaultConfig();
         return PluginNextoolLicenseValidator::normalizePlan($config['plan'] ?? null) === 'DESENVOLVIMENTO';
      }
      return false;
   }

   /**
    * Evento de auditoria para o log do módulo. Só chaves (LGPD): fase, verbo, rota (padrão, sem
    * valores), usuário, alvo, status HTTP e motivo. Nunca corpo nem atributos.
    *
    * @param array{phase: string, verb?: string, route?: string, status?: int, reason?: string, target?: ?int} $event
    */
   public static function logEvent(string $moduleKey, array $event): void {
      try {
         if ($moduleKey === '' || !class_exists('PluginNextoolModuleManager')) {
            return;
         }
         $module = PluginNextoolModuleManager::getInstance()->getModule($moduleKey);
         if (!$module instanceof PluginNextoolBaseModule) {
            return;
         }
         $event += ['verb' => '', 'route' => '', 'status' => 0, 'reason' => '', 'target' => null];
         $event['users_id'] = (int) Session::getLoginUserID();
         $module->logApiCall($event);
      } catch (Throwable $e) {
         // fail-silent: log nunca derruba a requisição
      }
   }

   public static function setResultReason(string $reason): void {
      self::$resultReason = $reason;
   }

   /** Lê e zera o motivo (uma requisição = um resultado). */
   public static function takeResultReason(): string {
      $reason = self::$resultReason;
      self::$resultReason = '';
      return $reason;
   }

   private static function logInvalid(string $moduleKey, string $class, string $problem): void {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[api] controller recusado (módulo %s, %s): %s\n",
            $moduleKey, $class, $problem
         ));
      }
   }
}
