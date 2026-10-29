<?php
declare(strict_types=1);
/** Resolve só a rota AJAX. Autenticação, CSRF e whitelist continuam no roteador. */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Alguns rewrites preservam REQUEST_URI, mas perdem QUERY_STRING/$_GET ou PATH_INFO.
 * Recuperar apenas module/file evita reintroduzir input cru nos demais parâmetros.
 * Nunca usa POST, cookies ou headers encaminhados para escolher um endpoint.
 *
 * @return array{module: string, file: string, source: string}
 */
function plugin_nextool_ajax_route(array $server, array $query): array {
   $empty = ['module' => '', 'file' => '', 'source' => 'missing'];
   $pair = static function (array $input, string $source) use ($empty): array {
      if (!is_string($input['module'] ?? null) || !is_string($input['file'] ?? null)) {
         return $empty;
      }
      return ['module' => $input['module'], 'file' => $input['file'], 'source' => $source];
   };
   $pathInfo = $server['PATH_INFO'] ?? '';
   if (is_string($pathInfo) && $pathInfo !== '') {
      $parts = explode('/', trim($pathInfo, '/'), 2);
      if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
         return ['module' => $parts[0], 'file' => $parts[1], 'source' => 'path_info'];
      }
   }
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
   if (!is_array($parts)
       || !preg_match('#/ajax/module_ajax\.php(?:/([^/]+)/([^/]+))?$#', $parts['path'] ?? '', $path)) {
      return $empty;
   }
   if (isset($path[1], $path[2])) {
      return ['module' => rawurldecode($path[1]), 'file' => rawurldecode($path[2]), 'source' => 'request_uri_path'];
   }
   parse_str($parts['query'] ?? '', $originalQuery);
   return $pair($originalQuery, 'request_uri_query');
}
