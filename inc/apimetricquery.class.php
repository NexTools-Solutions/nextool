<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Pedido de métrica já validado, entregue ao `compute` do módulo (nextool-dev#295)
 * -------------------------------------------------------------------------
 * Montado SÓ pelo PluginNextoolApiMetrics, depois de validar dimensões, filtros e direitos. O módulo
 * não monta escopo: usa scopeWhere() na coluna que liga a consulta dele ao itemtype da métrica, e a
 * base garante perfil + entidades + período + filtros de técnico/grupo de quem VÊ.
 *
 *   $it = $DB->request([
 *      'SELECT'  => [$q->dateBucket('glpi_tickets.date', 'period'), 'COUNT DISTINCT' => 'glpi_tickets.id AS total'],
 *      'FROM'    => 'glpi_tickets',
 *      'WHERE'   => [$q->scopeWhere('glpi_tickets.id')],
 *      'GROUPBY' => ['period'],
 *      'LIMIT'   => $q->limit(),
 *   ]);
 *
 * Carrega no GLPI 10 (usa a classe de subconsulta que existir).
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

final class PluginNextoolApiMetricQuery {

   public const GRANULARITIES = ['day', 'week', 'month', 'year'];

   private const BUCKET_FORMAT = [
      'day'   => '%Y-%m-%d',
      'week'  => '%x-W%v',
      'month' => '%Y-%m',
      'year'  => '%Y',
   ];

   /**
    * @param string[] $dimensions dimensões pedidas (já validadas)
    * @param array{from: string, to: string}|null $dateRange
    * @param int[] $entities entidades efetivas (já cruzadas com as da sessão)
    * @param int[]|null $users filtro de técnico atribuído (null = sem filtro)
    * @param int[]|null $groups filtro de grupo técnico atribuído (null = sem filtro)
    * @param array<string, mixed> $scopeCriteria subconsulta de IDs visíveis do itemtype (montada pela base)
    */
   public function __construct(
      private string $moduleKey,
      private string $metricId,
      private string $itemtype,
      private array $dimensions,
      private string $granularity,
      private ?array $dateRange,
      private array $entities,
      private ?array $users,
      private ?array $groups,
      private array $scopeCriteria,
      private int $limit,
   ) {}

   public function moduleKey(): string {
      return $this->moduleKey;
   }

   public function metricId(): string {
      return $this->metricId;
   }

   public function itemtype(): string {
      return $this->itemtype;
   }

   /** @return string[] */
   public function dimensions(): array {
      return $this->dimensions;
   }

   public function hasDimension(string $id): bool {
      return in_array($id, $this->dimensions, true);
   }

   /** Granularidade das dimensões `date` (day|week|month|year). */
   public function granularity(): string {
      return $this->granularity;
   }

   /** @return array{from: string, to: string}|null período já aplicado no escopo (informativo) */
   public function dateRange(): ?array {
      return $this->dateRange;
   }

   /** @return int[] */
   public function entities(): array {
      return $this->entities;
   }

   /** @return int[]|null */
   public function users(): ?array {
      return $this->users;
   }

   /** @return int[]|null */
   public function groups(): ?array {
      return $this->groups;
   }

   /** Teto de linhas que o compute deve respeitar (a base corta o excedente de qualquer jeito). */
   public function limit(): int {
      return $this->limit;
   }

   /**
    * Critério WHERE que restringe a consulta do módulo aos itens visíveis para quem vê: a coluna
    * dada (ID do itemtype da métrica, ex.: `glpi_tickets.id` ou `glpi_tickettasks.tickets_id`) tem
    * de estar na subconsulta de escopo. Subconsulta, não JOIN: não duplica linhas na contagem.
    *
    * @return array<string, mixed>
    */
   public function scopeWhere(string $column): array {
      if (preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/i', $column) !== 1) {
         throw new InvalidArgumentException('Coluna de escopo inválida: ' . $column);
      }
      return [$column => self::subQuery($this->scopeCriteria)];
   }

   /**
    * Expressão de agrupamento por período para uma coluna de data, no formato da granularidade
    * pedida (`2026-11`, `2026-11-08`, `2026-W45`, `2026`), com o alias dado.
    */
   public function dateBucket(string $column, string $alias): object {
      if (preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/i', $column) !== 1 || preg_match('/^[a-z0-9_]+$/i', $alias) !== 1) {
         throw new InvalidArgumentException('Coluna ou alias inválido');
      }
      global $DB;
      $format = self::BUCKET_FORMAT[$this->granularity] ?? self::BUCKET_FORMAT['month'];
      return self::expression(sprintf(
         'DATE_FORMAT(%s, %s) AS %s',
         $DB->quoteName($column),
         $DB->quoteValue($format),
         $DB->quoteName($alias)
      ));
   }

   /** Subconsulta nativa (GLPI 11: Glpi\DBAL; GLPI 10: global). */
   public static function subQuery(array $criteria): object {
      $class = class_exists('Glpi\\DBAL\\QuerySubQuery') ? 'Glpi\\DBAL\\QuerySubQuery' : 'QuerySubQuery';
      return new $class($criteria);
   }

   /** Expressão SQL nativa (GLPI 11: Glpi\DBAL; GLPI 10: global). */
   public static function expression(string $sql): object {
      $class = class_exists('Glpi\\DBAL\\QueryExpression') ? 'Glpi\\DBAL\\QueryExpression' : 'QueryExpression';
      return new $class($sql);
   }
}
