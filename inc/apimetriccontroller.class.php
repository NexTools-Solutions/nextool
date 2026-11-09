<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Rotas genéricas de métrica dos módulos (API v2 do GLPI 11+, nextool-dev#295)
 * -------------------------------------------------------------------------
 *   GET /api.php/v2.3/Nextool/Metric/Catalog                 descritores que o usuário pode consultar
 *   GET /api.php/v2.3/Nextool/Metric/{module}/{metric}       consulta (parâmetros planos, ver abaixo)
 *
 * Controller da BASE: registrado só quando algum módulo ativo declara getApiMetrics(). Fica fora do
 * portão por módulo (PluginNextoolApiGuardMiddleware): a regra inteira (usuário, módulo ativo,
 * licença, direito, escopo do itemtype) e a auditoria moram no PluginNextoolApiMetrics, o mesmo
 * serviço que o MyDashboards e o assistente de IA chamam em processo. Pela API o filtro `tickets`
 * (critério SQL) não é aceito.
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
if (!class_exists(\Glpi\Api\HL\Controller\AbstractController::class) || !class_exists('PluginNextoolApiController')) {
   return;
}

use Glpi\Api\HL\Doc as Doc;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\Request;
use Glpi\Http\Response;

#[Route(path: '/Nextool/Metric', tags: ['NexTool Metrics'])]
final class PluginNextoolApiMetricController extends PluginNextoolApiController {

   #[Route(path: '/Catalog', methods: ['GET'])]
   #[RouteVersion(introduced: '2.3')]
   #[Doc\Route(
      description: 'Lists the metrics of the active NexTool modules that the current user can query, with measures, dimensions and accepted filters.',
      parameters: [
         new Doc\Parameter(name: 'module', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Only metrics of this module key'),
      ],
      responses: [
         new Doc\Response(new Doc\Schema(type: Doc\Schema::TYPE_ARRAY, items: new Doc\Schema(type: Doc\Schema::TYPE_OBJECT, properties: [
            'module'         => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'id'             => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'label'          => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'description'    => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'itemtype'       => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'date_field'     => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'date_scope'     => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: [PluginNextoolApiMetrics::SCOPE_ITEM, PluginNextoolApiMetrics::SCOPE_COMPUTE]),
            'users_scope'    => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: [PluginNextoolApiMetrics::SCOPE_ITEM, PluginNextoolApiMetrics::SCOPE_COMPUTE]),
            'groups_scope'   => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: [PluginNextoolApiMetrics::SCOPE_ITEM, PluginNextoolApiMetrics::SCOPE_COMPUTE]),
            'deleted_scope'  => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: [PluginNextoolApiMetrics::DELETED_EXCLUDE, PluginNextoolApiMetrics::DELETED_INCLUDE]),
            'measures'       => new Doc\Schema(Doc\Schema::TYPE_OBJECT),
            'dimensions'     => new Doc\Schema(Doc\Schema::TYPE_OBJECT),
            'filters'        => new Doc\Schema(type: Doc\Schema::TYPE_ARRAY, items: new Doc\Schema(Doc\Schema::TYPE_STRING, enum: PluginNextoolApiMetrics::FILTERS)),
            'max_dimensions' => new Doc\Schema(Doc\Schema::TYPE_INTEGER),
            'widgettypes'    => new Doc\Schema(type: Doc\Schema::TYPE_ARRAY, items: new Doc\Schema(Doc\Schema::TYPE_STRING)),
            'embeddable'     => new Doc\Schema(Doc\Schema::TYPE_BOOLEAN),
            'effect'         => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: ['read']),
            'queryable'      => new Doc\Schema(Doc\Schema::TYPE_BOOLEAN),
         ])), 'Metric descriptors'),
      ]
   )]
   public function catalog(Request $request): Response {
      return $this->handle($request, function () use ($request): Response {
         $module = $request->hasParameter('module') ? strtolower(trim((string) $request->getParameter('module'))) : null;
         return $this->json(PluginNextoolApiMetrics::describe($module !== '' ? $module : null));
      });
   }

   #[Route(path: '/{module}/{metric}', methods: ['GET'], requirements: ['module' => '[a-z0-9_]+', 'metric' => '[a-z0-9_]+'])]
   #[RouteVersion(introduced: '2.3')]
   #[Doc\Route(
      description: 'Computes a module metric scoped to what the current user can see (profile and entities), grouped by the requested dimensions.',
      parameters: [
         new Doc\Parameter(name: 'module', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Module key', location: Doc\Parameter::LOCATION_PATH),
         new Doc\Parameter(name: 'metric', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Metric id from the catalog', location: Doc\Parameter::LOCATION_PATH),
         new Doc\Parameter(name: 'dimensions', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Comma separated dimension ids (empty = single total)'),
         new Doc\Parameter(name: 'granularity', schema: new Doc\Schema(Doc\Schema::TYPE_STRING, enum: PluginNextoolApiMetricQuery::GRANULARITIES), description: 'Bucket size of date dimensions (default month)'),
         new Doc\Parameter(name: 'date_from', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Period start, YYYY-MM-DD[ HH:MM:SS] (needs date_to)'),
         new Doc\Parameter(name: 'date_to', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Period end, YYYY-MM-DD[ HH:MM:SS] (needs date_from)'),
         new Doc\Parameter(name: 'entities', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Comma separated entity ids, intersected with the user entities'),
         new Doc\Parameter(name: 'users', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Comma separated assigned technician user ids (Ticket metrics)'),
         new Doc\Parameter(name: 'groups', schema: new Doc\Schema(Doc\Schema::TYPE_STRING), description: 'Comma separated assigned technician group ids (Ticket metrics)'),
         new Doc\Parameter(name: 'limit', schema: new Doc\Schema(Doc\Schema::TYPE_INTEGER), description: 'Maximum rows (default 1000, max 5000)'),
      ],
      responses: [
         new Doc\Response(new Doc\Schema(type: Doc\Schema::TYPE_OBJECT, properties: [
            'metric'      => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'module'      => new Doc\Schema(Doc\Schema::TYPE_STRING),
            'dimensions'  => new Doc\Schema(type: Doc\Schema::TYPE_ARRAY, items: new Doc\Schema(Doc\Schema::TYPE_STRING)),
            'granularity' => new Doc\Schema(Doc\Schema::TYPE_STRING, enum: PluginNextoolApiMetricQuery::GRANULARITIES),
            'measures'    => new Doc\Schema(Doc\Schema::TYPE_OBJECT),
            'rows'        => new Doc\Schema(type: Doc\Schema::TYPE_ARRAY, items: new Doc\Schema(Doc\Schema::TYPE_OBJECT)),
            'truncated'   => new Doc\Schema(Doc\Schema::TYPE_BOOLEAN),
         ]), 'Tidy rows: one object per dimension combination, dimension values as {id, name} for itemtype/enum'),
      ]
   )]
   public function query(Request $request): Response {
      return $this->handle($request, function () use ($request): Response {
         $module = (string) $request->getAttribute('module');
         $metric = (string) $request->getAttribute('metric');
         $p = static fn(string $name): ?string => $request->hasParameter($name) && is_scalar($request->getParameter($name))
            ? trim((string) $request->getParameter($name)) : null;
         $ids = static fn(?string $v): ?array => $v === null || $v === '' ? null : array_map('trim', explode(',', $v));

         $filters = [];
         if ($p('date_from') !== null || $p('date_to') !== null) {
            $filters['date'] = ['from' => (string) $p('date_from'), 'to' => (string) $p('date_to')];
         }
         foreach (['entities', 'users', 'groups'] as $f) {
            if (($list = $ids($p($f))) !== null) {
               $filters[$f] = $list;
            }
         }
         $req = ['dimensions' => (string) $p('dimensions'), 'filters' => $filters];
         if ($p('granularity') !== null) {
            $req['granularity'] = $p('granularity');
         }
         if ($p('limit') !== null) {
            $req['limit'] = $p('limit');
         }

         $r = PluginNextoolApiMetrics::query($module, $metric, $req, PluginNextoolApiMetrics::CHANNEL_HTTP);
         if ($r['status'] === 200) {
            return $this->json($r['data']);
         }
         $reason = (string) ($r['reason'] ?? '');
         return match (true) {
            $r['status'] === 404 => $this->notFound(),
            $r['status'] === 400 => $this->error(self::ERROR_INVALID_PARAMETER, (string) ($r['detail'] ?? $reason), 400, $reason),
            $r['status'] === 403 && str_starts_with($reason, 'LICENSE') => $this->error(
               self::ERROR_RIGHT_MISSING,
               __('A licença deste módulo não está ativa: a API do módulo exige licença vigente.', 'nextool'),
               403,
               $reason
            ),
            $r['status'] === 403 => $this->denied($reason),
            default => $this->error(self::ERROR_GENERIC, __('Erro interno ao processar a requisição.', 'nextool'), 500),
         };
      });
   }
}
