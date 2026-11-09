<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Contrato das rotas de API de módulo pensado para IA (nextool-dev#295)
 * -------------------------------------------------------------------------
 * Lê os atributos NATIVOS do GLPI (`Doc\Route`, `Doc\Parameter`, `Doc\Schema`, `Doc\Response`): o
 * módulo não declara nada fora do que o OpenAPI do core já usa. Três usos:
 *
 *  - describe(): descritor de cada rota (operação, caminho, efeito read/write, direito, parâmetros
 *    com tipo/enum/x-pii e schema da resposta). É o que o serviço em processo
 *    (PluginNextoolApiService) entrega ao assistente de IA e o que o lint confere;
 *  - filter(): recorta a resposta pelo schema declarado, recursivamente. Só age onde o schema
 *    declara `properties` (objeto) ou `items` (lista): resposta sem schema completo passa inteira,
 *    para nenhuma rota existente perder campos de uma hora para outra;
 *  - lint(): problemas de contrato da rota. NÃO barra em tempo de execução (derrubaria no cliente as
 *    rotas que já existem); quem barra é o `/release` (check-module-api-contract.sh).
 *
 * Regras do contrato (KB nextools: contexts/plugin/glpi11/api-v2.md, "Contrato pensado para IA"):
 *  - `Doc\Route.description` em INGLÊS (texto de atributo não passa por __(); é lido por IA e
 *    integradores), objetiva, com pelo menos 15 caracteres;
 *  - todo parâmetro com descrição e `Doc\Schema` tipado; valores fechados em `enum`; todo `{x}` do
 *    caminho declarado como parâmetro de caminho;
 *  - resposta 2xx com schema completo e INLINE: objeto com `properties` ou lista com `items` (de objeto
 *    com `properties`). `$ref` não conta: o recorte não resolve referência e devolveria tudo;
 *  - dado pessoal marcado com `extra_data: ['x-pii' => true]`, na saída E na entrada;
 *  - chave dinâmica: campo `TYPE_OBJECT` sem `properties` passa inteiro (objeto livre); objeto com
 *    `properties` E chaves extras declara `extra_data: ['additionalProperties' => true]` (ou um schema).
 *
 * Carrega no GLPI 10 sem efeito (só usa as classes Doc por instanceof/reflexão em tempo de chamada).
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

final class PluginNextoolApiContract {

   /** Extensão do schema que marca dado pessoal (o assistente mascara antes de mandar à IA). */
   public const PII = 'x-pii';

   private const ROUTE_ATTR = 'Glpi\\Api\\HL\\Route';
   private const DOC_ROUTE_ATTR = 'Glpi\\Api\\HL\\Doc\\Route';
   private const DOC_SCHEMA = 'Glpi\\Api\\HL\\Doc\\Schema';
   private const DOC_SCHEMA_REF = 'Glpi\\Api\\HL\\Doc\\SchemaReference';
   private const MIN_DESCRIPTION = 15;

   /**
    * Descritores de todas as rotas de um controller.
    *
    * @return array<int, array<string, mixed>>
    */
   public static function describe(string $class, string $moduleKey): array {
      if (!class_exists($class)) {
         return [];
      }
      $rc = new ReflectionClass($class);
      $base = self::classPath($rc);
      $out = [];
      foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
         $d = self::describeMethod($method, $moduleKey, $base);
         if ($d !== null) {
            $out[] = $d;
         }
      }
      return $out;
   }

   /**
    * Descritor de uma rota (null = método sem #[Route]).
    *
    * @return array<string, mixed>|null
    */
   public static function describeMethod(ReflectionMethod $method, string $moduleKey, ?string $basePath = null): ?array {
      $routes = $method->getAttributes(self::ROUTE_ATTR);
      if ($routes === []) {
         return null;
      }
      $route = $routes[0]->newInstance();
      $basePath ??= self::classPath($method->getDeclaringClass());
      $httpMethods = array_values(array_map('strtoupper', (array) $route->methods));

      $right = null;
      $rightAttrs = $method->getAttributes(PluginNextoolApiRight::class);
      if ($rightAttrs !== []) {
         $right = $rightAttrs[0]->newInstance();
      }

      $doc = self::docRoute($method);
      $parameters = [];
      $response = null;
      if ($doc !== null) {
         foreach ($doc->getParameters() as $param) {
            $schema = self::schemaToArray($param->getSchema());
            $parameters[] = [
               'name'        => (string) $param->getName(),
               'in'          => (string) $param->getLocation(),
               'required'    => (bool) $param->getRequired() || $param->getLocation() === 'path',
               'description' => (string) $param->getDescription(),
               'schema'      => $schema,
               'pii'         => !empty($schema[self::PII]),
            ];
         }
         $response = self::successSchema($doc, 200);
      }

      return [
         'operation'   => $moduleKey . '.' . $method->getName(),
         'module'      => $moduleKey,
         'path'        => $basePath . (string) $route->path,
         'methods'     => $httpMethods,
         'requirements'=> (array) $route->requirements,
         'effect'      => $right !== null ? $right->effectFor($httpMethods) : PluginNextoolApiRight::EFFECT_WRITE,
         'description' => $doc !== null ? trim((string) $doc->getDescription()) : '',
         'right'       => $right !== null ? ['kind' => $right->kind, 'bits' => array_values($right->bits)] : null,
         'parameters'  => $parameters,
         'response'    => $response,
         'pii_fields'  => $response !== null ? self::piiPaths($response) : [],
      ];
   }

   /**
    * Schema da resposta de sucesso para o status dado (o exato, senão o 200, senão o 1º 2xx).
    *
    * @return array<string, mixed>|null
    */
   public static function responseSchemaFor(ReflectionMethod $method, int $status): ?array {
      $doc = self::docRoute($method);
      return $doc !== null ? self::successSchema($doc, $status) : null;
   }

   /**
    * Recorta $data pelo schema: objeto com `properties` fica só com as chaves declaradas; lista
    * com `items` recorta cada item. Sem schema completo, devolve o dado como veio.
    *
    * @param array<string, mixed>|null $schema
    */
   public static function filter(mixed $data, ?array $schema): mixed {
      if ($schema === null || !is_array($data)) {
         return $data;
      }
      $type = (string) ($schema['type'] ?? '');
      if ($type === '' && isset($schema['properties'])) {
         $type = 'object'; // schema montado à mão sem type: properties basta
      }
      if ($type === 'object' && !empty($schema['properties']) && is_array($schema['properties'])) {
         if ($data !== [] && array_is_list($data)) {
            return $data; // não é objeto: não inventa recorte
         }
         $out = [];
         foreach ($schema['properties'] as $key => $propSchema) {
            if (array_key_exists($key, $data)) {
               $out[$key] = self::filter($data[$key], is_array($propSchema) ? $propSchema : null);
            }
         }
         // Chave dinâmica (OpenAPI `additionalProperties`, via extra_data): true = as chaves não declaradas
         // passam como vieram; schema = cada uma é recortada por ele. Ausente/false = só as declaradas.
         $extra = $schema['additionalProperties'] ?? false;
         if ($extra !== false && $extra !== null) {
            foreach (array_diff_key($data, $schema['properties']) as $key => $value) {
               $out[$key] = is_array($extra) ? self::filter($value, $extra) : $value;
            }
         }
         return $out;
      }
      if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
         if (!array_is_list($data)) {
            return $data;
         }
         return array_map(static fn($item) => self::filter($item, $schema['items']), $data);
      }
      return $data;
   }

   /**
    * Problemas de contrato das rotas do controller ([] = cumpre). Usado pelo /release.
    *
    * @return string[]
    */
   public static function lint(string $class, string $moduleKey): array {
      if (!class_exists($class)) {
         return ["{$class}: classe não encontrada"];
      }
      $problems = [];
      $rc = new ReflectionClass($class);
      $base = self::classPath($rc);
      foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
         if ($method->getAttributes(self::ROUTE_ATTR) === []) {
            continue;
         }
         $name = $method->getName();
         try {
            foreach (self::lintMethod($method, $moduleKey, $base) as $p) {
               $problems[] = "{$name}(): {$p}";
            }
         } catch (Throwable $e) {
            $problems[] = "{$name}(): atributos não instanciam ({$e->getMessage()})";
         }
      }
      return $problems;
   }

   /** @return string[] */
   private static function lintMethod(ReflectionMethod $method, string $moduleKey, string $base): array {
      $problems = [];
      $rightAttrs = $method->getAttributes(PluginNextoolApiRight::class);
      if ($rightAttrs !== []) {
         $effect = $rightAttrs[0]->newInstance()->effect;
         if ($effect !== null && !in_array($effect, [PluginNextoolApiRight::EFFECT_READ, PluginNextoolApiRight::EFFECT_WRITE], true)) {
            $problems[] = "effect inválido ('{$effect}'; use read ou write)";
         }
      }
      $doc = self::docRoute($method);
      if ($doc === null) {
         return array_merge($problems, ['falta #[Doc\\Route] (descrição, parâmetros e schema da resposta)']);
      }
      $d = self::describeMethod($method, $moduleKey, $base);

      $desc = (string) $d['description'];
      if (mb_strlen($desc) < self::MIN_DESCRIPTION) {
         $problems[] = 'descrição ausente ou curta demais (mínimo ' . self::MIN_DESCRIPTION . ' caracteres)';
      } elseif (preg_match('/[^\x20-\x7E]/', $desc) === 1) {
         $problems[] = 'descrição deve estar em inglês, sem acento (texto lido por IA e integradores)';
      }

      $declaredPath = [];
      foreach ($d['parameters'] as $p) {
         $label = "parâmetro '{$p['name']}'";
         if (trim((string) $p['description']) === '') {
            $problems[] = "{$label} sem descrição";
         } elseif (preg_match('/[^\x20-\x7E]/', (string) $p['description']) === 1) {
            $problems[] = "{$label}: descrição deve estar em inglês, sem acento";
         }
         if (($p['schema']['type'] ?? '') === '' && !isset($p['schema']['$ref'])) {
            $problems[] = "{$label} sem tipo no schema";
         }
         if ($p['in'] === 'path') {
            $declaredPath[] = $p['name'];
         }
      }
      if (preg_match_all('/\{([a-z0-9_]+)\}/i', (string) $d['path'], $m) > 0) {
         foreach (array_diff($m[1], $declaredPath) as $missing) {
            $problems[] = "{{$missing}} do caminho não está declarado como parâmetro de caminho (location: path)";
         }
      }

      $response = $d['response'];
      if ($response === null) {
         $problems[] = 'sem Doc\\Response de sucesso (2xx) com schema';
      } elseif (!self::isCompleteSchema($response)) {
         $problems[] = 'resposta sem schema completo (objeto sem properties, lista sem items ou $ref): a base não recorta os campos';
      }
      return $problems;
   }

   /**
    * Schema nativo (objeto Doc\Schema, referência ou array) em array OpenAPI, com enum, items,
    * properties e as extensões x-* do extra_data.
    *
    * @return array<string, mixed>
    */
   public static function schemaToArray(mixed $schema): array {
      $schemaClass = self::DOC_SCHEMA;
      $refClass = self::DOC_SCHEMA_REF;
      if ($schema instanceof $refClass) {
         $ref = method_exists($schema, 'getRef') ? (string) $schema->getRef() : (string) ($schema['ref'] ?? '');
         return ['$ref' => $ref];
      }
      if ($schema instanceof $schemaClass) {
         $out = ['type' => (string) $schema->getType()];
         if ($schema->getFormat()) {
            $out['format'] = (string) $schema->getFormat();
         }
         $enum = $schema['enum'];
         if (is_array($enum)) {
            $out['enum'] = array_values($enum);
         }
         // Cru, não getProperties(): aquele passa por toArray() e descarta o extra_data (x-pii) dos filhos.
         $props = $schema['properties'];
         if (is_array($props) && $props !== []) {
            $out['properties'] = [];
            foreach ($props as $key => $prop) {
               $out['properties'][$key] = self::schemaToArray($prop);
            }
         }
         $items = $schema->getItems();
         if ($items !== null) {
            $out['items'] = self::schemaToArray($items);
         }
         $extra = $schema['extra_data'];
         if (is_array($extra)) {
            foreach ($extra as $k => $v) {
               if (is_string($k) && !isset($out[$k])) {
                  $out[$k] = $k === 'additionalProperties' && !is_bool($v) ? self::schemaToArray($v) : $v;
               }
            }
         }
         return $out;
      }
      if (is_array($schema)) {
         $out = $schema;
         if (isset($schema['properties']) && is_array($schema['properties'])) {
            $out['properties'] = [];
            foreach ($schema['properties'] as $key => $prop) {
               $out['properties'][$key] = self::schemaToArray($prop);
            }
         }
         if (isset($schema['items'])) {
            $out['items'] = self::schemaToArray($schema['items']);
         }
         return $out;
      }
      return [];
   }

   /**
    * Caminhos (ponto) dos campos marcados com x-pii no schema; listas viram `[]`.
    *
    * @param array<string, mixed> $schema
    * @return string[]
    */
   public static function piiPaths(array $schema, string $prefix = ''): array {
      $paths = [];
      if (!empty($schema[self::PII]) && $prefix !== '') {
         $paths[] = $prefix;
      }
      foreach ((array) ($schema['properties'] ?? []) as $key => $prop) {
         if (is_array($prop)) {
            $paths = array_merge($paths, self::piiPaths($prop, $prefix === '' ? (string) $key : $prefix . '.' . $key));
         }
      }
      if (isset($schema['items']) && is_array($schema['items'])) {
         $paths = array_merge($paths, self::piiPaths($schema['items'], $prefix . '[]'));
      }
      return $paths;
   }

   /**
    * Schema completo o bastante para recortar: objeto com properties ou lista de itens completos. `$ref` NÃO
    * conta: o recorte não resolve a referência e devolveria o dado inteiro (achado da revisão de 08/11).
    */
   private static function isCompleteSchema(array $schema): bool {
      if (isset($schema['$ref'])) {
         return false;
      }
      $type = (string) ($schema['type'] ?? '');
      if ($type === 'object') {
         return !empty($schema['properties']);
      }
      if ($type === 'array') {
         return isset($schema['items']) && is_array($schema['items']) && self::isCompleteSchema($schema['items']);
      }
      return $type !== '';
   }

   private static function docRoute(ReflectionMethod $method): ?object {
      $attrs = $method->getAttributes(self::DOC_ROUTE_ATTR, ReflectionAttribute::IS_INSTANCEOF);
      return $attrs === [] ? null : $attrs[0]->newInstance();
   }

   private static function successSchema(object $doc, int $status): ?array {
      $exact = null;
      $ok = null;
      $first = null;
      foreach ($doc->getResponses() as $resp) {
         $code = (int) $resp->getStatusCode();
         if ($code < 200 || $code > 299 || $resp->getSchema() === null) {
            continue;
         }
         $schema = self::schemaToArray($resp->getSchema());
         $first ??= $schema;
         if ($code === $status) {
            $exact = $schema;
         }
         if ($code === 200) {
            $ok = $schema;
         }
      }
      return $exact ?? $ok ?? $first;
   }

   private static function classPath(ReflectionClass $rc): string {
      $attrs = $rc->getAttributes(self::ROUTE_ATTR);
      return $attrs === [] ? '' : (string) $attrs[0]->newInstance()->path;
   }
}
