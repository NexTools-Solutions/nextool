<?php
declare(strict_types=1);
/** Resolve só a rota dos roteadores de módulo. Autenticação, CSRF e whitelist continuam no roteador. */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Lê module/file da query ou da URL original, nesta ordem.
 *
 * Alguns rewrites preservam REQUEST_URI, mas perdem QUERY_STRING/$_GET.
 * $_SERVER['PATH_INFO'] NÃO é lido: há nginx que o preenche com a URL inteira
 * (`fastcgi_param PATH_INFO $uri`), o que virava módulo "plugins" e um 404; com
 * `error_page 404 /index.php` o nginx ainda repetia a requisição sem query e sem
 * corpo (cliente em 28/10/2026). A forma de caminho (`<script>/<module>/<file>`)
 * vem do próprio REQUEST_URI, que é a URL que o navegador pediu.
 * Recuperar apenas module/file evita reintroduzir input cru nos demais parâmetros.
 * Nunca usa POST, cookies ou headers encaminhados para escolher um endpoint.
 *
 * @param string $script Caminho do roteador relativo ao plugin (ex.: front/module_assets.php).
 * @return array{module: string, file: string, source: string}
 */
function plugin_nextool_ajax_route(array $server, array $query, string $script = 'ajax/module_ajax.php'): array {
   $empty = ['module' => '', 'file' => '', 'source' => 'missing'];
   $pair = static function (array $input, string $source) use ($empty): array {
      if (!is_string($input['module'] ?? null) || !is_string($input['file'] ?? null)) {
         return $empty;
      }
      return ['module' => $input['module'], 'file' => $input['file'], 'source' => $source];
   };
   if (isset($query['module'], $query['file'])) {
      return $pair($query, 'query');
   }
   // Não contornar uma entrada estruturada inválida usando outra representação da URL.
   foreach (['module', 'file'] as $key) {
      if (isset($query[$key]) && !is_string($query[$key])) {
         return $empty;
      }
   }
   $uri = $server['REQUEST_URI'] ?? '';
   if (!is_string($uri)) {
      return $empty;
   }
   $parts = parse_url($uri);
   $pattern = '#/' . preg_quote($script, '#') . '(?:/([^/]+)/([^/]+))?$#';
   if (!is_array($parts) || !preg_match($pattern, $parts['path'] ?? '', $path)) {
      return $empty;
   }
   if (isset($path[1], $path[2])) {
      return ['module' => rawurldecode($path[1]), 'file' => rawurldecode($path[2]), 'source' => 'request_uri_path'];
   }
   parse_str($parts['query'] ?? '', $originalQuery);
   return $pair($originalQuery, 'request_uri_query');
}

/**
 * True quando o servidor manda em PATH_INFO a URL inteira do script em vez do trecho
 * depois dele (ex.: `/plugins/nextool/front/modules.php` pedindo `.../front/modules.php`).
 * Sinal de configuração do nginx fora do padrão; usado no diagnóstico dos módulos.
 */
function plugin_nextool_path_info_is_full_uri(array $server): bool {
   $pathInfo = $server['PATH_INFO'] ?? '';
   $uri = $server['REQUEST_URI'] ?? '';
   if (!is_string($pathInfo) || $pathInfo === '' || !is_string($uri)) {
      return false;
   }
   $path = rawurldecode((string)(parse_url($uri, PHP_URL_PATH) ?? ''));
   if ($path === '' || !str_ends_with($path, $pathInfo)) {
      return false;
   }
   // PATH_INFO legítimo é o que vem DEPOIS do script: o trecho anterior termina em ".php".
   return preg_match('#\.php$#', substr($path, 0, -strlen($pathInfo))) !== 1;
}
