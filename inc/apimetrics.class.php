<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Métricas dos módulos: descritor + consulta com escopo de quem VÊ (nextool-dev#295)
 * -------------------------------------------------------------------------
 * Um serviço só para os três consumidores: MyDashboards (card `source: metric`), assistente de IA
 * (NexChat) e a API (`/Nextool/Metric/...`, PluginNextoolApiMetricController). O módulo declara em
 * `BaseModule::getApiMetrics()`; a base:
 *
 *  1. valida o descritor (o inválido fica fora e vai para o log da base);
 *  2. confere usuário logado (link público de painel não tem usuário: recusado, falha FECHADO),
 *     módulo ativo, licença (mesma regra da API: FREE passa) e os bits do `right`;
 *  3. confere que o usuário vê o itemtype e monta o ESCOPO como subconsulta de IDs:
 *     - Ticket: `Ticket::getCriteriaFromProfile()` (READALL = sem restrição de perfil). ATENÇÃO: o
 *       core devolve [] também para quem NÃO tem direito nenhum de chamado, o que equivaleria a "ver
 *       tudo": aqui isso é recusa (ITEMTYPE_NOT_VISIBLE);
 *     - outro ITIL (Problem, Change): só com READALL (o core não tem critério por perfil para eles);
 *     - outro CommonDBTM: `canView()`;
 *     - sempre: entidades da sessão (cruzadas com o filtro `entities`), `is_deleted = 0`, período na
 *       `date_field`, e em Ticket os filtros `users`/`groups` (técnico/grupo ATRIBUÍDO, como os filtros
 *       nativos do painel; com `date_scope`/`users_scope`/`groups_scope` = 'compute' quem aplica é o
 *       módulo, na coluna dele, #298) e `tickets` (critérios dos filtros nativos, só em processo e só no formato que
 *       eles geram: colunas de glpi_tickets, JOIN em tabela de vínculo, valores escalares; ticketCriteria());
 *  4. chama o `compute` do módulo com PluginNextoolApiMetricQuery;
 *  5. corta no teto de linhas, deixa só as chaves declaradas (dimensões pedidas + medidas), converte
 *     as medidas para número e resolve `{id, name}` das dimensões itemtype/enum;
 *  6. registra no log do módulo (verbo METRIC, rota `metric:<id>`, só chaves).
 *
 * Saída (linhas "tidy"; quem desenha widget é o consumidor):
 *   {metric, module, dimensions, granularity, measures, rows: [{<dim>: v|{id,name}, <medida>: n}], truncated}
 *
 * Duração sempre em SEGUNDOS (quem formata é o consumidor). Sem dependência da API v2: funciona no
 * GLPI 10 (o MyDashboards/NexChat chamam em processo).
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

final class PluginNextoolApiMetrics {

   /** Versão do contrato (descritor, pedido e saída). Consumidor confere isto, não method_exists. */
   public const API_VERSION = 1;

   public const CHANNEL_INPROCESS = 'inprocess';
   public const CHANNEL_HTTP = 'http';

   public const MEASURE_TYPES = ['count', 'sum', 'avg', 'duration'];
   public const DIMENSION_TYPES = ['date', 'enum', 'itemtype'];
   public const FILTERS = ['date', 'entities', 'users', 'groups', 'tickets'];

   /**
    * Quem aplica o SENTIDO de um filtro (nextool-dev#298). `item` (padrão): a base restringe o itemtype da
    * métrica (período na `date_field`, técnico/grupo ATRIBUÍDO ao chamado). `compute`: a base só valida e
    * entrega em `dateRange()`/`users()`/`groups()`, e o compute aplica na coluna dele (ex.: data, autor e
    * grupo da TAREFA). O escopo de quem VÊ (perfil + entidades) segue sempre com a base.
    */
   public const SCOPE_ITEM = 'item';
   public const SCOPE_COMPUTE = 'compute';
   /** chave do descritor => filtro que ela governa */
   public const SCOPE_KEYS = ['date_scope' => 'date', 'users_scope' => 'users', 'groups_scope' => 'groups'];

   public const MAX_DIMENSIONS = 3;
   public const MAX_ROWS = 5000;
   public const DEFAULT_ROWS = 1000;

   private const ID_PATTERN = '/^[a-z0-9_]{1,48}$/';
   private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';

   /** @var array<string, array<string, array<string, mixed>>> descritores válidos por módulo (cache da requisição) */
   private static array $cache = [];

   /**
    * Descritores (sem o compute) das métricas dos módulos ativos. Por padrão só as que o usuário
    * da sessão pode consultar agora.
    *
    * @return array<int, array<string, mixed>>
    */
   public static function describe(?string $moduleKey = null, bool $onlyQueryable = true): array {
      $out = [];
      foreach (self::activeModules($moduleKey) as $mk => $module) {
         foreach (self::descriptorsOf($mk, $module) as $d) {
            $queryable = self::accessDenied($mk, $module, $d) === null;
            if ($onlyQueryable && !$queryable) {
               continue;
            }
            $out[] = self::publicDescriptor($mk, $d) + ['queryable' => $queryable];
         }
      }
      return $out;
   }

   /**
    * Consulta a métrica na sessão atual.
    *
    * Pedido: `dimensions` (lista ou "a,b"), `granularity` (day|week|month|year, padrão month),
    * `filters` {date: {from, to}, entities: int[], users: int[], groups: int[], tickets: criteria},
    * `limit` (padrão 1000, teto 5000).
    *
    * @param array<string, mixed> $request
    * @return array{status: int, data: ?array, reason?: string, detail?: string}
    */
   public static function query(string $moduleKey, string $metricId, array $request = [], string $channel = self::CHANNEL_INPROCESS): array {
      $module = self::activeModules($moduleKey)[$moduleKey] ?? null;
      if ($module === null) {
         return self::fail($moduleKey, 'metric:' . $metricId, 404, 'MODULE_INACTIVE', $channel);
      }
      return self::queryOn($moduleKey, $module, $metricId, $request, $channel);
   }

   /**
    * query() sobre uma instância já resolvida (o módulo ATIVO). Pública para o E2E, que testa com
    * subclasse do módulo sem gravar no banco; consumidor usa query().
    *
    * @internal
    * @return array{status: int, data: ?array, reason?: string, detail?: string}
    */
   public static function queryOn(string $moduleKey, PluginNextoolBaseModule $module, string $metricId, array $request = [], string $channel = self::CHANNEL_INPROCESS): array {
      $route = 'metric:' . $metricId;
      $d = self::descriptorsOf($moduleKey, $module)[$metricId] ?? null;
      if ($d === null) {
         return self::fail($moduleKey, $route, 404, 'METRIC_NOT_FOUND', $channel);
      }
      $denied = self::accessDenied($moduleKey, $module, $d);
      if ($denied !== null) {
         return self::fail($moduleKey, $route, $denied[0], $denied[1], $channel);
      }

      try {
         $q = self::buildQuery($moduleKey, $d, $request, $channel);
      } catch (InvalidArgumentException $e) {
         return self::fail($moduleKey, $route, 400, 'INVALID_PARAMETER', $channel, $e->getMessage());
      }

      $rows = [];
      if ($q !== null) { // null = filtro de entidades sem nenhuma entidade visível: resultado vazio
         try {
            $rows = call_user_func($d['compute'], $q);
         } catch (Throwable $e) {
            if (class_exists(\Glpi\Error\ErrorHandler::class)) {
               \Glpi\Error\ErrorHandler::logCaughtException($e);
            }
            return self::fail($moduleKey, $route, 500, 'exception', $channel);
         }
         if (!is_iterable($rows)) {
            return self::fail($moduleKey, $route, 500, 'COMPUTE_INVALID_OUTPUT', $channel);
         }
      }

      $dims = $q !== null ? $q->dimensions() : self::requestedDimensions($d, $request);
      $limit = $q !== null ? $q->limit() : self::DEFAULT_ROWS;
      [$clean, $truncated] = self::cleanRows($d, $dims, $rows, $limit);

      PluginNextoolApiRegistry::logEvent($moduleKey, [
         'phase'   => 'result',
         'verb'    => 'METRIC',
         'route'   => $route,
         'status'  => 200,
         'reason'  => $truncated ? 'TRUNCATED' : '',
         'channel' => $channel,
      ]);
      return ['status' => 200, 'data' => [
         'metric'      => $metricId,
         'module'      => $moduleKey,
         'dimensions'  => $dims,
         'granularity' => $q !== null ? $q->granularity() : 'month',
         'measures'    => $d['measures'],
         'rows'        => $clean,
         'truncated'   => $truncated,
      ]];
   }

   /**
    * Problemas dos descritores do módulo ([] = válidos). Usado pelo /release.
    *
    * @return string[]
    */
   public static function lint(PluginNextoolBaseModule $module): array {
      try {
         $list = $module->getApiMetrics();
      } catch (Throwable $e) {
         return ['getApiMetrics() lançou: ' . $e->getMessage()];
      }
      $problems = [];
      $seen = [];
      foreach ($list as $i => $d) {
         $id = is_array($d) ? (string) ($d['id'] ?? "#{$i}") : "#{$i}";
         $p = is_array($d) ? self::validateDescriptor($module, $d) : 'descritor não é array';
         if ($p !== null) {
            $problems[] = "métrica {$id}: {$p}";
         }
         if (isset($seen[$id])) {
            $problems[] = "métrica {$id}: id repetido";
         }
         $seen[$id] = true;
      }
      return $problems;
   }

   /** Problema do descritor, ou null se válido. */
   public static function validateDescriptor(PluginNextoolBaseModule $module, array $d): ?string {
      global $DB;
      if (!is_string($d['id'] ?? null) || preg_match(self::ID_PATTERN, $d['id']) !== 1) {
         return 'id ausente ou fora de [a-z0-9_] (até 48)';
      }
      foreach (['label', 'description'] as $k) {
         if (!is_string($d[$k] ?? null) || trim($d[$k]) === '') {
            return "{$k} ausente (texto com __())";
         }
      }
      $itemtype = $d['itemtype'] ?? null;
      if (!is_string($itemtype) || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
         return 'itemtype ausente ou não é CommonDBTM';
      }
      if (!is_callable($d['compute'] ?? null)) {
         return 'compute não é chamável';
      }

      $right = $d['right'] ?? null;
      if (!is_array($right) || !in_array($right['kind'] ?? null, [PluginNextoolApiRight::KIND_USE, PluginNextoolApiRight::KIND_ADMIN], true)
          || !is_array($right['bits'] ?? null) || $right['bits'] === []) {
         return "right ausente ({bits: [...], kind: 'use'|'admin'})";
      }
      $usable = array_map('intval', array_keys($module->getUsabilityRights()));
      $admin = array_values(array_diff(array_map('intval', array_keys($module->getProfileRights())), $usable));
      $allowed = $right['kind'] === PluginNextoolApiRight::KIND_USE ? $usable : $admin;
      foreach ($right['bits'] as $bit) {
         if (!is_int($bit) || $bit <= 0 || ($bit & ($bit - 1)) !== 0 || !in_array($bit, $allowed, true)) {
            return "right: bit inválido ou que não é direito '{$right['kind']}' do módulo";
         }
      }

      if (!is_array($d['measures'] ?? null) || $d['measures'] === []) {
         return 'measures vazio';
      }
      foreach ($d['measures'] as $mid => $m) {
         if (!is_string($mid) || preg_match(self::ID_PATTERN, $mid) !== 1 || !is_array($m)
             || !in_array($m['type'] ?? null, self::MEASURE_TYPES, true) || !is_string($m['label'] ?? null)) {
            return "medida '{$mid}': precisa de label e type (" . implode('|', self::MEASURE_TYPES) . ')';
         }
      }
      foreach ((array) ($d['dimensions'] ?? []) as $did => $dim) {
         if (!is_string($did) || preg_match(self::ID_PATTERN, $did) !== 1 || !is_array($dim)
             || !in_array($dim['type'] ?? null, self::DIMENSION_TYPES, true) || !is_string($dim['label'] ?? null)) {
            return "dimensão '{$did}': precisa de label e type (" . implode('|', self::DIMENSION_TYPES) . ')';
         }
         if (isset($d['measures'][$did])) {
            return "dimensão '{$did}' tem o mesmo id de uma medida";
         }
         if ($dim['type'] === 'itemtype' && !(is_string($dim['itemtype'] ?? null) && is_a($dim['itemtype'], CommonDBTM::class, true))) {
            return "dimensão '{$did}': itemtype ausente ou não é CommonDBTM";
         }
         if ($dim['type'] === 'enum' && (!is_array($dim['values'] ?? null) || $dim['values'] === [])) {
            return "dimensão '{$did}': enum sem values (valor => rótulo)";
         }
      }
      $filters = (array) ($d['filters'] ?? []);
      if (array_diff($filters, self::FILTERS) !== []) {
         return 'filtro desconhecido (aceitos: ' . implode(', ', self::FILTERS) . ')';
      }
      foreach (self::SCOPE_KEYS as $key => $filter) {
         if (!array_key_exists($key, $d)) {
            continue;
         }
         if (!in_array($d[$key], [self::SCOPE_ITEM, self::SCOPE_COMPUTE], true)) {
            return "{$key}: 'item' ou 'compute'";
         }
         if (!in_array($filter, $filters, true)) {
            return "{$key} sem o filtro {$filter} em filters";
         }
      }
      $scope = static fn (string $key): string => (string) ($d[$key] ?? self::SCOPE_ITEM);
      if (in_array('date', $filters, true)) {
         $df = $d['date_field'] ?? null;
         if ($scope('date_scope') === self::SCOPE_COMPUTE) {
            // Com compute a base não usa date_field: declarar as duas coisas é ambíguo (e numa base anterior à
            // #298 a métrica sairia filtrando pela data do chamado, em silêncio). Ausente = base antiga recusa.
            if ($df !== null) {
               return "date_scope 'compute' dispensa date_field (o compute aplica o período)";
            }
         } elseif (!is_string($df) || preg_match('/^[a-z0-9_]+$/', $df) !== 1 || !$DB->fieldExists($itemtype::getTable(), $df, false)) {
            return 'filtro date exige date_field (coluna existente na tabela do itemtype) ou date_scope compute';
         }
      }
      if (in_array('tickets', $filters, true) && $itemtype !== Ticket::class) {
         return 'filtro tickets só vale para itemtype Ticket';
      }
      foreach (['users' => 'users_scope', 'groups' => 'groups_scope'] as $filter => $key) {
         if (in_array($filter, $filters, true) && $itemtype !== Ticket::class && $scope($key) !== self::SCOPE_COMPUTE) {
            return "filtro {$filter} fora de Ticket exige {$key} 'compute' (técnico/grupo atribuído só existe no chamado)";
         }
      }
      $max = $d['max_dimensions'] ?? null;
      if ($max !== null && (!is_int($max) || $max < 0 || $max > self::MAX_DIMENSIONS)) {
         return 'max_dimensions entre 0 e ' . self::MAX_DIMENSIONS;
      }
      return null;
   }

   // ------------------------------------------------------------------------------------------

   /** @return array<string, PluginNextoolBaseModule> */
   private static function activeModules(?string $only): array {
      if (!class_exists('PluginNextoolModuleManager')) {
         return [];
      }
      $active = PluginNextoolModuleManager::getInstance()->getActiveModules();
      $out = [];
      foreach (is_array($active) ? $active : [] as $mk => $module) {
         if ($module instanceof PluginNextoolBaseModule && ($only === null || $mk === $only)) {
            $out[(string) $mk] = $module;
         }
      }
      return $out;
   }

   /** @return array<string, array<string, mixed>> descritores válidos por id */
   private static function descriptorsOf(string $moduleKey, PluginNextoolBaseModule $module): array {
      $cacheKey = $moduleKey . '#' . spl_object_id($module); // por instância: o E2E usa subclasse
      if (isset(self::$cache[$cacheKey])) {
         return self::$cache[$cacheKey];
      }
      $valid = [];
      try {
         $list = $module->getApiMetrics();
      } catch (Throwable $e) {
         self::logInvalid($moduleKey, '-', 'getApiMetrics() lançou: ' . $e->getMessage());
         $list = [];
      }
      foreach ($list as $d) {
         if (!is_array($d)) {
            continue;
         }
         try {
            $problem = self::validateDescriptor($module, $d);
         } catch (Throwable $e) {
            $problem = 'validação lançou: ' . $e->getMessage();
         }
         $id = (string) ($d['id'] ?? '-');
         if ($problem !== null || isset($valid[$id])) {
            self::logInvalid($moduleKey, $id, $problem ?? 'id repetido');
            continue;
         }
         $d['dimensions'] = (array) ($d['dimensions'] ?? []);
         $d['filters'] = array_values((array) ($d['filters'] ?? []));
         $d['max_dimensions'] = $d['max_dimensions'] ?? min(2, count($d['dimensions']));
         foreach (array_keys(self::SCOPE_KEYS) as $key) {
            $d[$key] = (string) ($d[$key] ?? self::SCOPE_ITEM);
         }
         $valid[$id] = $d;
      }
      return self::$cache[$cacheKey] = $valid;
   }

   /** @return array<string, mixed> */
   private static function publicDescriptor(string $moduleKey, array $d): array {
      $dims = [];
      foreach ($d['dimensions'] as $id => $dim) {
         $pub = ['label' => $dim['label'], 'type' => $dim['type']];
         if ($dim['type'] === 'itemtype') {
            $pub['itemtype'] = $dim['itemtype'];
         } elseif ($dim['type'] === 'enum') {
            $pub['values'] = $dim['values'];
         } else {
            $pub['granularities'] = PluginNextoolApiMetricQuery::GRANULARITIES;
         }
         $dims[$id] = $pub;
      }
      return [
         'module'         => $moduleKey,
         'id'             => $d['id'],
         'label'          => $d['label'],
         'description'    => $d['description'],
         'itemtype'       => $d['itemtype'],
         'date_field'     => $d['date_field'] ?? null,
         'date_scope'     => $d['date_scope'],
         'users_scope'    => $d['users_scope'],
         'groups_scope'   => $d['groups_scope'],
         'measures'       => $d['measures'],
         'dimensions'     => $dims,
         'filters'        => $d['filters'],
         'max_dimensions' => $d['max_dimensions'],
         'widgettypes'    => array_values((array) ($d['widgettypes'] ?? [])),
         'embeddable'     => false,
         'effect'         => PluginNextoolApiRight::EFFECT_READ,
      ];
   }

   /**
    * Recusa de acesso, ou null. [status, motivo].
    *
    * @return array{0: int, 1: string}|null
    */
   private static function accessDenied(string $moduleKey, PluginNextoolBaseModule $module, array $d): ?array {
      if ((int) Session::getLoginUserID() <= 0) {
         return [403, 'NO_SESSION_USER'];
      }
      $license = PluginNextoolApiRegistry::accessDenyReason($module);
      if ($license !== null) {
         return [403, $license];
      }
      if (!PluginNextoolApiGuard::hasRights($moduleKey, $d['right']['bits'], $d['right']['kind'])) {
         return [403, 'RIGHT_MISSING'];
      }
      return self::profileCriteria($d['itemtype']) === null ? [403, 'ITEMTYPE_NOT_VISIBLE'] : null;
   }

   /**
    * Critério de perfil do itemtype para quem vê: [] = sem restrição; null = não vê.
    *
    * @return array<string, mixed>|null
    */
   private static function profileCriteria(string $itemtype): ?array {
      if ($itemtype === Ticket::class) {
         if (Session::haveRight('ticket', Ticket::READALL)) {
            return [];
         }
         $crit = Ticket::getCriteriaFromProfile();
         return empty($crit['WHERE']) ? null : $crit; // [] do core sem direito nenhum = NÃO vê
      }
      if (is_a($itemtype, CommonITILObject::class, true)) {
         $rightname = $itemtype::$rightname ?? '';
         return defined($itemtype . '::READALL') && $rightname !== '' && Session::haveRight($rightname, constant($itemtype . '::READALL'))
            ? [] : null;
      }
      return $itemtype::canView() ? [] : null;
   }

   /** @return string[] */
   private static function requestedDimensions(array $d, array $request): array {
      $dims = $request['dimensions'] ?? [];
      if (is_string($dims)) {
         $dims = $dims === '' ? [] : explode(',', $dims);
      }
      return array_values(array_unique(array_map(static fn($v) => trim((string) $v), (array) $dims)));
   }

   /**
    * Valida o pedido e monta o PluginNextoolApiMetricQuery (null = nenhuma entidade visível no
    * filtro pedido: resultado vazio sem chamar o compute).
    *
    * @throws InvalidArgumentException pedido inválido (mensagem curta, vai no detalhe do 400)
    */
   private static function buildQuery(string $moduleKey, array $d, array $request, string $channel): ?PluginNextoolApiMetricQuery {
      $dims = self::requestedDimensions($d, $request);
      foreach ($dims as $dim) {
         if (!isset($d['dimensions'][$dim])) {
            throw new InvalidArgumentException("dimensão desconhecida: {$dim}");
         }
      }
      if (count($dims) > (int) $d['max_dimensions']) {
         throw new InvalidArgumentException('dimensões demais (máximo ' . (int) $d['max_dimensions'] . ')');
      }
      $granularity = (string) ($request['granularity'] ?? 'month');
      if (!in_array($granularity, PluginNextoolApiMetricQuery::GRANULARITIES, true)) {
         throw new InvalidArgumentException('granularity inválida (day|week|month|year)');
      }
      $limit = (int) ($request['limit'] ?? self::DEFAULT_ROWS);
      if ($limit <= 0 || $limit > self::MAX_ROWS) {
         throw new InvalidArgumentException('limit entre 1 e ' . self::MAX_ROWS);
      }

      $filters = is_array($request['filters'] ?? null) ? $request['filters'] : [];
      foreach (array_keys($filters) as $f) {
         if ($filters[$f] === null) {
            unset($filters[$f]);
            continue;
         }
         if (!in_array($f, $d['filters'], true)) {
            throw new InvalidArgumentException("filtro não aceito por esta métrica: {$f}");
         }
      }
      if (isset($filters['tickets']) && $channel !== self::CHANNEL_INPROCESS) {
         throw new InvalidArgumentException('filtro tickets só em processo');
      }

      $dateRange = null;
      if (isset($filters['date'])) {
         $from = (string) ($filters['date']['from'] ?? '');
         $to = (string) ($filters['date']['to'] ?? '');
         if (preg_match(self::DATETIME_PATTERN, $from) !== 1 || preg_match(self::DATETIME_PATTERN, $to) !== 1 || $from > $to) {
            throw new InvalidArgumentException('date: {from, to} em AAAA-MM-DD[ HH:MM:SS], from <= to');
         }
         $dateRange = ['from' => $from, 'to' => $to];
      }

      $active = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
      $entities = $active;
      if (isset($filters['entities'])) {
         $entities = array_values(array_intersect($active, self::intList($filters['entities'], 'entities')));
         if ($entities === []) {
            return null;
         }
      }
      $users = isset($filters['users']) ? self::intList($filters['users'], 'users') : null;
      $groups = isset($filters['groups']) ? self::intList($filters['groups'], 'groups') : null;
      // Lista vazia não casa com ninguém: resultado vazio sem chamar o compute (com scope compute, o módulo
      // nunca recebe [] para tratar como "sem filtro").
      if ($users === [] || $groups === []) {
         return null;
      }
      $tickets = isset($filters['tickets']) ? self::ticketCriteria($filters['tickets']) : null;

      $scope = self::scopeCriteria($d, $entities, $dateRange, $users, $groups, $tickets);
      return new PluginNextoolApiMetricQuery(
         $moduleKey, (string) $d['id'], (string) $d['itemtype'], $dims, $granularity,
         $dateRange, $entities, $users, $groups, $scope, $limit
      );
   }

   /** Tabelas de vínculo de chamado aceitas em JOIN no filtro `tickets` (as dos filtros nativos do painel). */
   private const TICKET_LINK_TABLES = ['glpi_groups_tickets', 'glpi_tickets_users', 'glpi_suppliers_tickets', 'glpi_items_tickets'];

   /**
    * Filtro `tickets` (critérios dos filtros nativos do painel) só com o que eles geram: WHERE sobre colunas de
    * `glpi_tickets` e JOIN em tabela de vínculo ligada por `tickets_id = glpi_tickets.id`. Nenhum objeto (sem
    * QueryExpression/subconsulta), nenhuma outra tabela: mesmo vindo de argumento gerado pela IA, o critério só
    * estreita os chamados que o escopo já permite e não serve de oráculo sobre outra tabela (revisão de 08/11).
    *
    * @return array{WHERE: array, LEFT JOIN?: array}
    * @throws InvalidArgumentException
    */
   private static function ticketCriteria(mixed $c): array {
      if (!is_array($c) || array_diff(array_keys($c), ['WHERE', 'JOIN', 'LEFT JOIN']) !== []) {
         throw new InvalidArgumentException('tickets: só WHERE e JOIN dos filtros nativos');
      }
      $qualifiers = ['glpi_tickets' => true];
      $joins = [];
      foreach (array_merge((array) ($c['JOIN'] ?? []), (array) ($c['LEFT JOIN'] ?? [])) as $key => $on) {
         if (!is_string($key) || preg_match('/^([a-z_]+)(?:\s+as\s+([a-z0-9_]+))?$/i', trim($key), $m) !== 1
             || !in_array(strtolower($m[1]), self::TICKET_LINK_TABLES, true)) {
            throw new InvalidArgumentException('tickets: JOIN só em tabela de vínculo de chamado');
         }
         $alias = $m[2] ?? $m[1];
         if (($on['ON'] ?? null) !== [$alias => 'tickets_id', 'glpi_tickets' => 'id']) {
            throw new InvalidArgumentException('tickets: JOIN ligado por tickets_id = glpi_tickets.id');
         }
         $qualifiers[$alias] = true;
         $joins[trim($key)] = $on;
      }
      $out = ['WHERE' => self::ticketWhere((array) ($c['WHERE'] ?? []), $qualifiers)];
      if ($joins !== []) {
         $out['LEFT JOIN'] = $joins;
      }
      return $out;
   }

   /** @param array<string, true> $qualifiers */
   private static function ticketWhere(array $where, array $qualifiers): array {
      foreach ($where as $key => $value) {
         if (is_int($key) || in_array($key, ['AND', 'OR', 'NOT'], true)) {
            if (!is_array($value)) {
               throw new InvalidArgumentException('tickets: grupo lógico sem lista');
            }
            self::ticketWhere($value, $qualifiers);
            continue;
         }
         if (preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/i', (string) $key, $m) !== 1 || !isset($qualifiers[$m[1]])) {
            throw new InvalidArgumentException('tickets: coluna fora de glpi_tickets e dos vínculos');
         }
         self::assertPlainValue($value);
      }
      return $where;
   }

   private static function assertPlainValue(mixed $value): void {
      if (is_array($value)) {
         foreach ($value as $v) {
            self::assertPlainValue($v);
         }
         return;
      }
      if ($value !== null && !is_scalar($value)) {
         throw new InvalidArgumentException('tickets: valor tem de ser escalar ou lista');
      }
   }

   /** @return int[] */
   private static function intList(mixed $value, string $name): array {
      if (!is_array($value)) {
         throw new InvalidArgumentException("{$name}: lista de ids");
      }
      $out = [];
      foreach ($value as $v) {
         if (!is_numeric($v) || (int) $v < 0) {
            throw new InvalidArgumentException("{$name}: lista de ids");
         }
         $out[] = (int) $v;
      }
      return array_values(array_unique($out));
   }

   /**
    * Subconsulta dos IDs do itemtype visíveis para quem vê, com os filtros aplicados.
    *
    * @param int[] $entities
    * @param int[]|null $users
    * @param int[]|null $groups
    * @return array<string, mixed>
    */
   private static function scopeCriteria(array $d, array $entities, ?array $dateRange, ?array $users, ?array $groups, ?array $tickets): array {
      $itemtype = (string) $d['itemtype'];
      $item = new $itemtype();
      $table = $itemtype::getTable();
      $crit = ['SELECT' => "{$table}.id", 'DISTINCT' => true, 'FROM' => $table, 'WHERE' => []];

      if ($item->maybeDeleted()) {
         $crit['WHERE']["{$table}.is_deleted"] = 0;
      }
      if ($item->isEntityAssign()) {
         $crit['WHERE'][] = getEntitiesRestrictCriteria($table, '', $entities === [] ? [-1] : $entities, $item->maybeRecursive());
      }
      // accessDenied() já recusou quem não vê; defesa em profundidade: sem critério = nenhum item.
      $profile = self::profileCriteria($itemtype) ?? ['WHERE' => ["{$table}.id" => -1]];
      if (!empty($profile['LEFT JOIN'])) {
         $crit['LEFT JOIN'] = $profile['LEFT JOIN'];
      }
      if (!empty($profile['WHERE'])) {
         $crit['WHERE'][] = $profile['WHERE'];
      }
      if ($dateRange !== null && $d['date_scope'] !== self::SCOPE_COMPUTE && !empty($d['date_field'])) {
         $col = "{$table}." . $d['date_field'];
         $crit['WHERE'][] = [$col => ['>=', $dateRange['from']]];
         $crit['WHERE'][] = [$col => ['<=', $dateRange['to']]];
      }
      if ($itemtype === Ticket::class) {
         if ($users !== null && $d['users_scope'] !== self::SCOPE_COMPUTE) {
            $crit['WHERE'][] = ["{$table}.id" => PluginNextoolApiMetricQuery::subQuery([
               'SELECT' => 'tickets_id', 'FROM' => 'glpi_tickets_users',
               'WHERE'  => ['users_id' => $users === [] ? [-1] : $users, 'type' => CommonITILActor::ASSIGN],
            ])];
         }
         if ($groups !== null && $d['groups_scope'] !== self::SCOPE_COMPUTE) {
            $crit['WHERE'][] = ["{$table}.id" => PluginNextoolApiMetricQuery::subQuery([
               'SELECT' => 'tickets_id', 'FROM' => 'glpi_groups_tickets',
               'WHERE'  => ['groups_id' => $groups === [] ? [-1] : $groups, 'type' => CommonITILActor::ASSIGN],
            ])];
         }
         if ($tickets !== null && $tickets['WHERE'] !== []) {
            $sub = ['SELECT' => 'glpi_tickets.id', 'FROM' => 'glpi_tickets', 'WHERE' => (array) ($tickets['WHERE'] ?? [])];
            if (!empty($tickets['LEFT JOIN'])) {
               $sub['LEFT JOIN'] = $tickets['LEFT JOIN'];
            }
            $crit['WHERE'][] = ["{$table}.id" => PluginNextoolApiMetricQuery::subQuery($sub)];
         }
      }
      return $crit;
   }

   /**
    * Corta no teto, deixa só as chaves declaradas e resolve {id, name}.
    *
    * @param string[] $dims
    * @return array{0: array<int, array<string, mixed>>, 1: bool}
    */
   private static function cleanRows(array $d, array $dims, iterable $rows, int $limit): array {
      $out = [];
      $truncated = false;
      $names = [];
      foreach ($rows as $row) {
         if (count($out) >= $limit) {
            $truncated = true;
            break;
         }
         if (!is_array($row)) {
            continue;
         }
         $clean = [];
         foreach ($dims as $dim) {
            $def = $d['dimensions'][$dim];
            $v = $row[$dim] ?? null;
            if ($def['type'] === 'itemtype') {
               $id = (int) $v;
               $key = $def['itemtype'] . ':' . $id;
               // Entidade raiz é o id 0 (válido); nos demais itemtypes 0 = vazio.
               $named = $id > 0 || ($id === 0 && $def['itemtype'] === Entity::class);
               $names[$key] ??= $named ? (string) Dropdown::getDropdownName($def['itemtype']::getTable(), $id) : '';
               $clean[$dim] = ['id' => $id, 'name' => $names[$key]];
            } elseif ($def['type'] === 'enum') {
               $clean[$dim] = ['id' => $v, 'name' => (string) ($def['values'][$v] ?? $v)];
            } else {
               $clean[$dim] = $v === null ? null : (string) $v;
            }
         }
         foreach ($d['measures'] as $mid => $m) {
            $v = $row[$mid] ?? 0;
            $clean[$mid] = in_array($m['type'], ['count', 'duration'], true) ? (int) round((float) $v) : round((float) $v, 4);
         }
         $out[] = $clean;
      }
      return [$out, $truncated];
   }

   /** @return array{status: int, data: null, reason: string, detail?: string} */
   private static function fail(string $moduleKey, string $route, int $status, string $reason, string $channel, ?string $detail = null): array {
      PluginNextoolApiRegistry::logEvent($moduleKey, [
         'phase'   => $status >= 500 ? 'result' : 'denied',
         'verb'    => 'METRIC',
         'route'   => $route,
         'status'  => $status,
         'reason'  => $reason,
         'channel' => $channel,
      ]);
      $out = ['status' => $status, 'data' => null, 'reason' => $reason];
      if ($detail !== null) {
         $out['detail'] = $detail;
      }
      return $out;
   }

   private static function logInvalid(string $moduleKey, string $id, string $problem): void {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool', sprintf("[api] métrica recusada (módulo %s, %s): %s\n", $moduleKey, $id, $problem));
      }
   }
}
