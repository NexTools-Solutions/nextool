<?php
/**
 * F2 -- Verificação OFFLINE do token de entitlement (direito) emitido pelo ContainerAPI.
 *
 * O token é assinado com Ed25519 por uma chave PRÓPRIA do servidor (decisão D2). Formato:
 *   base64url(payload_json) "." base64url(signature_raw)
 * A assinatura cobre os BYTES EXATOS do payload transmitido (o plugin verifica sobre os bytes
 * recebidos -- sem reconstruir um payload canônico, ao contrário do manifesto do coreupdater).
 *
 * Garantias verificadas: (1) assinatura confere com a pública confiável do `kid`; (2) não expirado;
 * (3) anti-replay -- o `environment_id` do token casa com o identificador do PRÓPRIO ambiente
 * (um token roubado de outro ambiente não vale aqui).
 *
 * Cascata de chaves públicas (espelha coreupdater::getTrustedSignaturePublicKeys, mas com namespace
 * de ENTITLEMENT): Config DB `plugin:nextool_entitlement` (json ou single) -> env
 * NEXTOOL_ENTITLEMENT_SIGNING_PUBLIC_KEYS / *_KEY_ID+*_PUBLIC_KEY. SEM fallback embutido: sem chave
 * configurada, verify() retorna null e o plugin degrada para o comportamento legado (sem token).
 */
class PluginNextoolEntitlementToken {

   public const ENTITLEMENT_CONTEXT = 'plugin:nextool_entitlement';

   /**
    * Verifica o token e devolve os claims se VÁLIDO; senão null.
    *
    * @return array{v:int,environment_id:string,plan:string,allowed_modules:array,iat:int,exp:int,kid:string}|null
    */
   public static function verify(string $token, string $expectedEnvironmentId, ?int $now = null): ?array {
      $now = $now ?? time();
      $claims = self::decodeAndVerifySignature($token);
      if ($claims === null) {
         return null;
      }

      // exp (token expirado não vale)
      $exp = (int) ($claims['exp'] ?? 0);
      if ($exp > 0 && $exp <= $now) {
         return null;
      }

      // anti-replay: o environment_id do token tem que ser o DESTE ambiente
      $tokenEnv = trim((string) ($claims['environment_id'] ?? ''));
      $expected = trim($expectedEnvironmentId);
      if ($expected !== '' && ($tokenEnv === '' || !hash_equals($expected, $tokenEnv))) {
         return null;
      }

      return $claims;
   }

   /**
    * exp (timestamp) do token, sem validar assinatura -- usado para decidir a revalidação de cache.
    */
   public static function getExpiry(string $token): ?int {
      $claims = self::decodePayload($token);
      if ($claims === null || !isset($claims['exp'])) {
         return null;
      }
      $exp = (int) $claims['exp'];
      return $exp > 0 ? $exp : null;
   }

   /**
    * Decodifica o payload e valida a ASSINATURA Ed25519 (sem checar exp/environment). Retorna os
    * claims ou null. Base da verificação offline.
    *
    * @return array<string,mixed>|null
    */
   public static function decodeAndVerifySignature(string $token): ?array {
      if (!extension_loaded('sodium') || $token === '') {
         return null;
      }
      $parts = explode('.', $token);
      if (count($parts) !== 2) {
         return null;
      }

      $payloadJson = self::base64UrlDecode($parts[0]);
      $signature   = self::base64UrlDecode($parts[1]);
      if ($payloadJson === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
         return null;
      }

      $claims = json_decode($payloadJson, true);
      if (!is_array($claims)) {
         return null;
      }

      $kid = trim((string) ($claims['kid'] ?? ''));
      if ($kid === '') {
         return null;
      }

      $keys = self::getTrustedPublicKeys();
      if (!isset($keys[$kid])) {
         return null;
      }

      // a assinatura cobre os bytes EXATOS do payload transmitido
      if (!sodium_crypto_sign_verify_detached($signature, $payloadJson, $keys[$kid])) {
         return null;
      }

      return $claims;
   }

   /** Decodifica só o payload (sem verificar assinatura). @return array<string,mixed>|null */
   private static function decodePayload(string $token): ?array {
      if ($token === '') {
         return null;
      }
      $parts = explode('.', $token);
      if (count($parts) !== 2) {
         return null;
      }
      $payloadJson = self::base64UrlDecode($parts[0]);
      if ($payloadJson === null) {
         return null;
      }
      $claims = json_decode($payloadJson, true);
      return is_array($claims) ? $claims : null;
   }

   /**
    * @return array<string,string> kid => public_key_binary
    */
   public static function getTrustedPublicKeys(): array {
      $values = [];
      if (class_exists('Config')) {
         $values = Config::getConfigurationValues(self::ENTITLEMENT_CONTEXT);
      }
      $keys = [];

      // F2 -- chave pública de PRODUÇÃO embutida (espelha o coreupdater, que embute as chaves de
      // manifesto da assinatura). Distribuída COM o plugin -> todo cliente verifica out-of-the-box,
      // sem depender de provisionamento por instância. Config/env abaixo PODEM sobrescrever o mesmo
      // kid (rotação/teste). A privada correspondente vive só no ContainerAPI.
      $builtin = self::decodePublicKey('iaT9s0NXKlR1CflBgeipzBhZMdUqNJVwWeexQomIu/Y=');
      if ($builtin !== null) {
         $keys['nextool-entitlement-1'] = $builtin;
      }

      $jsonCandidates = [];
      if (isset($values['entitlement_signing_public_keys_json'])) {
         $jsonCandidates[] = (string) $values['entitlement_signing_public_keys_json'];
      }
      $envJson = getenv('NEXTOOL_ENTITLEMENT_SIGNING_PUBLIC_KEYS');
      if ($envJson !== false) {
         $jsonCandidates[] = (string) $envJson;
      }

      foreach ($jsonCandidates as $json) {
         $json = trim($json);
         if ($json === '') {
            continue;
         }
         $decoded = json_decode($json, true);
         if (!is_array($decoded)) {
            continue;
         }
         foreach ($decoded as $keyId => $rawKey) {
            $normalizedId = trim((string) $keyId);
            $decodedKey   = self::decodePublicKey((string) $rawKey);
            if ($normalizedId !== '' && $decodedKey !== null) {
               $keys[$normalizedId] = $decodedKey;
            }
         }
      }

      $singleKeyId  = trim((string) ($values['entitlement_signing_key_id'] ?? ''));
      $singleKeyRaw = trim((string) ($values['entitlement_signing_public_key'] ?? ''));
      if ($singleKeyId !== '' && $singleKeyRaw !== '') {
         $decoded = self::decodePublicKey($singleKeyRaw);
         if ($decoded !== null) {
            $keys[$singleKeyId] = $decoded;
         }
      }

      $envSingleId  = getenv('NEXTOOL_ENTITLEMENT_SIGNING_KEY_ID');
      $envSingleKey = getenv('NEXTOOL_ENTITLEMENT_SIGNING_PUBLIC_KEY');
      if ($envSingleId !== false && $envSingleKey !== false) {
         $decoded      = self::decodePublicKey((string) $envSingleKey);
         $normalizedId = trim((string) $envSingleId);
         if ($decoded !== null && $normalizedId !== '') {
            $keys[$normalizedId] = $decoded;
         }
      }

      return $keys;
   }

   private static function decodePublicKey(string $rawKey): ?string {
      $raw = trim($rawKey);
      if ($raw === '') {
         return null;
      }
      if (str_starts_with($raw, 'base64:')) {
         $raw = substr($raw, 7);
      }

      $binary = null;
      if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
         $hex = @hex2bin($raw);
         if ($hex !== false) {
            $binary = $hex;
         }
      }
      if ($binary === null) {
         $decoded = base64_decode($raw, true);
         if ($decoded !== false) {
            $binary = $decoded;
         }
      }
      if ($binary === null) {
         $binary = $raw;
      }
      if (strlen($binary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
         return null;
      }
      return $binary;
   }

   private static function base64UrlDecode(string $value): ?string {
      $value = strtr(trim($value), '-_', '+/');
      $pad = strlen($value) % 4;
      if ($pad > 0) {
         $value .= str_repeat('=', 4 - $pad);
      }
      $decoded = base64_decode($value, true);
      return $decoded === false ? null : $decoded;
   }
}
