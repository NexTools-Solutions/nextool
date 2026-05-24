<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - HMAC Signature Trait
 * -------------------------------------------------------------------------
 * Assinatura HMAC v2 das requisições ao ContainerAPI (audit HI-01).
 * Wire-format v2: METHOD|PATH|BODY|TIMESTAMP (espelha verifyV2 do ContainerAPI),
 * incluindo método e path para prevenir replay de assinatura entre endpoints.
 * O ContainerAPI aceita v1 e v2; este plugin envia v2 (X-Signature-Version: 2).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

trait PluginNextoolHmacSignatureTrait {

   /**
    * Assinatura HMAC v2: METHOD|PATH|BODY|TIMESTAMP.
    */
   protected static function buildHmacSignatureV2(
      string $method,
      string $path,
      string $body,
      string $timestamp,
      string $secret
   ): string {
      return hash_hmac('sha256', strtoupper($method) . '|' . $path . '|' . $body . '|' . $timestamp, $secret);
   }

   /**
    * Monta os cabeçalhos HMAC v2 prontos para uma requisição.
    * O $path deve ser o endpoint canônico (sem query string) que o ContainerAPI
    * usa na verificação, ex.: '/api/licensing/validate'.
    *
    * @return array<int,string>
    */
   protected static function buildHmacHeadersV2(
      string $clientIdentifier,
      string $path,
      string $body,
      string $secret,
      string $method = 'POST'
   ): array {
      $timestamp = (string) time();
      return [
         'X-Client-Identifier: ' . $clientIdentifier,
         'X-Timestamp: ' . $timestamp,
         'X-Signature: ' . self::buildHmacSignatureV2($method, $path, $body, $timestamp, $secret),
         'X-Signature-Version: 2',
      ];
   }
}
