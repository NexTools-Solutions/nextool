<?php
/**
 * NexTool -- Guardas comuns de endpoint STATELESS (webhook de provedor).
 *
 * O preambulo de um webhook (metodo, corpo vazio, JSON invalido, segredo
 * ausente, comparacao HMAC-ou-direta) existia copiado em 3 arquivos
 * (digitalsignature x2, autentique) e as copias ja divergiam em SEGURANCA: uma
 * aceitava LISTA de segredos (rotacao sem downtime) e respondia 403, as outras
 * aceitavam um so e respondiam 401 (audit-deep 2026-09-06, nextool-dev#254).
 *
 * O que NAO cabe aqui: os `define()` stateless (DO_NOT_CHECK_LOGIN etc.) e o
 * `require GLPI_ROOT/inc/includes.php` -- tem de estar no proprio arquivo, antes
 * de qualquer include, e cada webhook continua dono do parse do payload e do
 * efeito de negocio. Uso tipico:
 *
 *   $rawBody  = PluginNextoolWebhookGuard::requireJsonPost();      // 405/400 + exit
 *   $payload  = PluginNextoolWebhookGuard::decodeJson($rawBody);    // 400 + exit
 *   $provided = PluginNextoolWebhookGuard::providedSecret(['HTTP_X_MEU_SIGNATURE', 'HTTP_X_MEU_SECRET'], $payload);
 *   PluginNextoolWebhookGuard::requireSecret($rawBody, $provided, $segredos, 'meumodulo');   // 503/401 + exit
 *   PluginNextoolModuleManager::statelessModuleGate('meumodulo');                            // 200 + exit se desligado
 *
 * @since 6.15.0
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolWebhookGuard {

   /** Resposta JSON + encerramento. */
   public static function respond(int $http, array $body): void {
      if (!headers_sent()) {
         http_response_code($http);
         header('Content-Type: application/json; charset=UTF-8');
      }
      echo json_encode($body, JSON_UNESCAPED_UNICODE);
      exit;
   }

   /**
    * GET = validacao de URL pelo painel do provedor (200); so POST segue; corpo
    * vazio e 400. Devolve o corpo CRU (e o que se assina no HMAC).
    */
   public static function requireJsonPost(string $getMessage = 'Webhook endpoint. Use POST to send events.'): string {
      $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
      if ($method === 'GET') {
         self::respond(200, ['status' => 'ok', 'message' => $getMessage]);
      }
      if ($method !== 'POST') {
         self::respond(405, ['error' => 'method_not_allowed']);
      }
      $rawBody = file_get_contents('php://input');
      if ($rawBody === false || $rawBody === '') {
         self::respond(400, ['error' => 'empty_payload']);
      }
      return $rawBody;
   }

   /** JSON invalido = 400. */
   public static function decodeJson(string $rawBody): array {
      $payload = json_decode($rawBody, true);
      if (!is_array($payload)) {
         self::respond(400, ['error' => 'invalid_json']);
      }
      return $payload;
   }

   /**
    * Segredo apresentado pelo provedor, na ordem de precedencia: headers
    * informados (chaves de $_SERVER, ex.: HTTP_X_DOCUSEAL_SIGNATURE), depois
    * `?secret=` na URL, depois `secret` no corpo. Os dois ultimos existem por
    * compatibilidade -- segredo na URL vai parar em access log.
    *
    * @param string[] $serverKeys
    * @return array{value:string, source:string} source = header|query|body|none
    */
   public static function providedSecret(array $serverKeys, array $payload = []): array {
      foreach ($serverKeys as $k) {
         if (!empty($_SERVER[$k])) {
            return ['value' => trim((string)$_SERVER[$k]), 'source' => 'header'];
         }
      }
      if (isset($_GET['secret']) && is_string($_GET['secret']) && $_GET['secret'] !== '') {
         return ['value' => trim($_GET['secret']), 'source' => 'query'];
      }
      if (isset($payload['secret']) && is_string($payload['secret']) && $payload['secret'] !== '') {
         return ['value' => trim($payload['secret']), 'source' => 'body'];
      }
      return ['value' => '', 'source' => 'none'];
   }

   /**
    * Confere o segredo apresentado contra UMA LISTA de segredos aceitos (rotacao
    * sem downtime: o antigo e o novo valem durante a troca). Para cada segredo,
    * aceita HMAC-SHA256 do corpo cru OU o valor em claro, sempre com hash_equals.
    *
    * @param string[] $secrets segredos ja DECIFRADOS; vazios sao ignorados
    */
   public static function verify(string $rawBody, string $provided, array $secrets): bool {
      $provided = trim($provided);
      if ($provided === '') {
         return false;
      }
      foreach ($secrets as $secret) {
         $secret = trim((string)$secret);
         if ($secret === '') {
            continue;
         }
         if (hash_equals(hash_hmac('sha256', $rawBody, $secret), $provided)) {
            return true;
         }
         if (hash_equals($secret, $provided)) {
            return true;
         }
      }
      return false;
   }

   /**
    * Fail-closed: sem segredo configurado, 503 (o endpoint nao tem como se
    * autenticar e aceitar qualquer request abriria caminho para alterar estado
    * a partir da internet); segredo errado, 401. So volta quando a request esta
    * autenticada.
    *
    * @param string[] $secrets  segredos aceitos (ja decifrados)
    * @param string   $logChannel arquivo de log (Toolbox::logInFile) para as recusas
    */
   public static function requireSecret(string $rawBody, string $provided, array $secrets, string $logChannel = 'plugin_nextool'): void {
      $validos = array_values(array_filter(array_map('trim', array_map('strval', $secrets)), static fn($s) => $s !== ''));
      if ($validos === []) {
         Toolbox::logInFile($logChannel, "[webhook] recusado: segredo do webhook nao configurado\n");
         self::respond(503, ['error' => 'webhook_secret_not_configured']);
      }
      if (!self::verify($rawBody, $provided, $validos)) {
         Toolbox::logInFile($logChannel, "[webhook] recusado: segredo invalido\n");
         self::respond(401, ['error' => 'invalid_secret']);
      }
   }
}
