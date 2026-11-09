<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Recorte da resposta das rotas de API de módulo pelo schema (nextool-dev#295)
 * -------------------------------------------------------------------------
 * Middleware de resposta. Numa rota `/Nextool/<mk>/...` com resposta 2xx em JSON, recorta o corpo
 * pelo schema da Doc\Response do status devolvido (PluginNextoolApiContract::filter): só saem os
 * campos declarados, recursivamente. Cada campo a mais é token pago e dado pessoal que pode vazar
 * para a IA ou para o integrador.
 *
 * Só age onde o schema declara `properties`/`items`: rota sem schema completo responde inteira (o
 * /release cobra o schema; aqui não se derruba nada). Erro ao recortar = resposta original (o
 * recorte nunca quebra a rota).
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

use Glpi\Api\HL\Middleware\AbstractMiddleware;
use Glpi\Api\HL\Middleware\MiddlewareInput;
use Glpi\Api\HL\Middleware\ResponseMiddlewareInterface;
use Glpi\Http\JSONResponse;

final class PluginNextoolApiResponseFilterMiddleware extends AbstractMiddleware implements ResponseMiddlewareInterface {

   public function process(MiddlewareInput $input, callable $next): void {
      try {
         $response = $input->response;
         if ($response !== null) {
            $filtered = self::filterResponse($response, $input->route_path->getMethod());
            if ($filtered !== null) {
               $input->response = $filtered;
            }
         }
      } catch (Throwable $e) {
         // o recorte nunca altera a resposta quando falha
      }
      $next($input);
   }

   /**
    * Resposta recortada, ou null quando não há o que recortar (não 2xx, não JSON, sem schema
    * completo ou corpo igual).
    */
   public static function filterResponse(\Psr\Http\Message\ResponseInterface $response, ReflectionMethod $method): ?JSONResponse {
      $status = $response->getStatusCode();
      if ($status < 200 || $status > 299) {
         return null;
      }
      if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) {
         return null;
      }
      $schema = PluginNextoolApiContract::responseSchemaFor($method, $status);
      if ($schema === null) {
         return null;
      }
      $body = (string) $response->getBody();
      $data = json_decode($body, true);
      if (!is_array($data)) {
         return null;
      }
      $out = PluginNextoolApiContract::filter($data, $schema);
      if ($out === $data) {
         return null;
      }
      $headers = $response->getHeaders();
      unset($headers['Content-Type'], $headers['Content-Length'], $headers['content-type'], $headers['content-length']);
      return new JSONResponse($out, $status, $headers);
   }
}
