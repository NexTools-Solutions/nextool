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
    * Vida padrão do token no ContainerAPI (`ENTITLEMENT_TOKEN_TTL_SECONDS`, 7 dias). Só entra quando
    * este GLPI nunca guardou um token que confira: com token, a vida vem dele (ver offlineGraceSeconds).
    */
   public const DEFAULT_TTL_SECONDS = 604800;

   /** Memo por requisição da graça offline (o token só muda num /validate). */
   private static ?int $graceMemo = null;

   /**
    * Verifica o token e devolve os claims se VÁLIDO; senão null.
    *
    * @return array{v:int,environment_id:string,plan:string,allowed_modules:array,iat:int,exp:int,kid:string}|null
    */
   public static function verify(string $token, string $expectedEnvironmentId, ?int $now = null): ?array {
      if ($now === null) {
         // exp vem do relógio do ContainerAPI: compara com o horário oficial, não com o relógio local.
         require_once NEXTOOL_PHP_DIR . '/inc/clock.class.php';
         $now = PluginNextoolClock::now();
      }
      $claims = self::decodeAndVerifySignature($token);
      if ($claims === null) {
         return null;
      }
      // A prova do mapa de direito (verifyProof) é assinada com a mesma chave e tem `typ`: nunca vale
      // como token de licença.
      if (isset($claims['typ'])) {
         return null;
      }

      // exp é OBRIGATÓRIO: token sem validade (ou com exp 0) valeria para sempre na graça offline.
      // O servidor sempre emite com exp (cerca de 7 dias).
      $exp = (int) ($claims['exp'] ?? 0);
      if ($exp <= 0 || $exp <= $now) {
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

   /** `typ` da prova assinada do mapa de direito por módulo (ContainerAPI: EntitlementTokenIssuer::issueProof). */
   public const PROOF_TYPE = 'entitlement-proof';

   /** Chave da prova guardada, no ENTITLEMENT_CONTEXT. */
   public const PROOF_KEY = 'modules_entitlement_token';

   /** Memo por requisição da prova guardada (false = ainda não lida). */
   private static $proofMemo = false;

   /**
    * Verifica a prova assinada do mapa de direito por módulo (`modules_entitlement_token` do /validate).
    *
    * É por ela que a base decide a ativação de módulo pago (PluginNextoolModuleManager), e não mais pelo
    * retrato de licenças gravado no banco, que qualquer um com escrita no banco alterava. Confere a
    * assinatura, o `typ` (o token de licença não vale como prova) e o ambiente, que aqui é obrigatório.
    * O `exp` NÃO é conferido: quem já teve licença continua podendo usar o módulo (decisão do owner,
    * 2026-11-18), e a prova de `ever_licensed` não perde a validade com o tempo.
    *
    * @return array{modules: array<string, array{status: string, ever_licensed: bool}>, offline_disable_days: int, iat: int}|null
    */
   public static function verifyProof(string $token, string $expectedEnvironmentId): ?array {
      $claims = self::decodeAndVerifySignature($token);
      if ($claims === null || ($claims['typ'] ?? null) !== self::PROOF_TYPE) {
         return null;
      }
      $tokenEnv = trim((string) ($claims['environment_id'] ?? ''));
      $expected = trim($expectedEnvironmentId);
      if ($expected === '' || $tokenEnv === '' || !hash_equals($expected, $tokenEnv)) {
         return null;
      }
      if (!isset($claims['modules']) || !is_array($claims['modules'])) {
         return null;
      }
      $modules = [];
      foreach ($claims['modules'] as $key => $info) {
         if (!is_array($info)) {
            continue;
         }
         $modules[(string) $key] = [
            'status'        => (string) ($info['status'] ?? ''),
            'ever_licensed' => !empty($info['ever_licensed']),
         ];
      }
      $policy = isset($claims['policy']) && is_array($claims['policy']) ? $claims['policy'] : [];

      return [
         'modules'              => $modules,
         'offline_disable_days' => max(0, (int) ($policy['offline_disable_days'] ?? 0)),
         'iat'                  => (int) ($claims['iat'] ?? 0),
      ];
   }

   /**
    * A prova GUARDADA, conferida de novo contra o ambiente atual a cada requisição (o que está no banco
    * não é confiado: só vale o que a assinatura confirma). Null = sem prova, ou ela não confere.
    *
    * @return array{modules: array<string, array{status: string, ever_licensed: bool}>, offline_disable_days: int, iat: int}|null
    */
   public static function storedProof(): ?array {
      if (self::$proofMemo !== false) {
         return self::$proofMemo;
      }
      $proof = null;
      try {
         if (class_exists('Config') && class_exists('PluginNextoolConfig')) {
            $values = Config::getConfigurationValues(self::ENTITLEMENT_CONTEXT, [self::PROOF_KEY]);
            $token  = isset($values[self::PROOF_KEY]) ? trim((string) $values[self::PROOF_KEY]) : '';
            if ($token !== '') {
               $settings = PluginNextoolConfig::getDistributionSettings();
               $proof = self::verifyProof($token, (string) ($settings['client_identifier'] ?? ''));
            }
         }
      } catch (\Throwable $e) {
         $proof = null;
      }
      return self::$proofMemo = $proof;
   }

   /** Claims do token de licença GUARDADO, se ainda vale (assinatura, validade e ambiente). */
   public static function storedLicenseClaims(): ?array {
      try {
         if (!class_exists('Config') || !class_exists('PluginNextoolConfig')) {
            return null;
         }
         $values = Config::getConfigurationValues(self::ENTITLEMENT_CONTEXT, ['entitlement_token']);
         $token  = isset($values['entitlement_token']) ? trim((string) $values['entitlement_token']) : '';
         $env    = trim((string) (PluginNextoolConfig::getDistributionSettings()['client_identifier'] ?? ''));
         if ($token === '' || $env === '') {
            return null;
         }
         return self::verify($token, $env);
      } catch (\Throwable $e) {
         return null;
      }
   }

   /** A prova guardada mudou (novo /validate, uninstall, teste): a próxima leitura confere de novo. */
   public static function forgetStoredProof(): void {
      self::$proofMemo = false;
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
    * Graça offline da licença, em segundos: a vida do token (`exp - iat`) que o servidor emitiu.
    *
    * É a regra que a licença já usa: sem alcançar o ContainerAPI, o direito vale até o `exp` do último
    * token, que nasce no /validate com essa vida (7 dias por padrão, configurável no servidor). O direito
    * local às funções de nuvem e a credencial do vínculo usam a MESMA duração a partir da última
    * sincronização (N8 do plano da auditoria de 2026-09-24), para caírem junto com a licença, e não
    * dias depois dela.
    *
    * Lê o token GUARDADO, com a assinatura conferida e sem olhar o `exp`: só a duração interessa. Assim,
    * se o servidor passar a assinar com uma chave que esta base ainda não conhece, o token novo não é
    * guardado, mas a duração continua vindo do anterior. Sem token nenhum, vale o padrão do servidor.
    */
   public static function offlineGraceSeconds(): int {
      if (self::$graceMemo !== null) {
         return self::$graceMemo;
      }
      $vida = 0;
      if (class_exists('Config')) {
         $values = Config::getConfigurationValues(self::ENTITLEMENT_CONTEXT, ['entitlement_token']);
         $token  = isset($values['entitlement_token']) ? trim((string) $values['entitlement_token']) : '';
         $claims = $token !== '' ? self::decodeAndVerifySignature($token) : null;
         if ($claims !== null) {
            $vida = (int) ($claims['exp'] ?? 0) - (int) ($claims['iat'] ?? 0);
         }
      }

      return self::$graceMemo = ($vida > 0 ? $vida : self::DEFAULT_TTL_SECONDS);
   }

   /**
    * Decodifica o payload e valida a ASSINATURA Ed25519 (sem checar exp/environment). Retorna os
    * claims ou null. Base da verificação offline.
    *
    * @return array<string,mixed>|null
    */
   public static function decodeAndVerifySignature(string $token): ?array {
      // Testa a FUNÇÃO, não a extensão: o GLPI embarca o polyfill sodium_compat, que fornece a
      // verificação Ed25519 em servidor sem php-sodium. Exigir a extensão descartava o token nesses
      // servidores e a graça offline da licença nunca valia.
      if ($token === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
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
      $keys = [];

      // F2 -- chave pública de PRODUÇÃO embutida (espelha o coreupdater, que embute as chaves de
      // manifesto da assinatura). Distribuída COM o plugin -> todo cliente verifica out-of-the-box,
      // sem depender de provisionamento por instância. A privada correspondente vive só no ContainerAPI.
      // IMUTÁVEL (hardening 2026-07): o kid embutido nunca é sobrescrito. Kid novo (rotação/teste) só
      // entra por VARIÁVEL DE AMBIENTE do servidor; o banco (glpi_configs) não é mais fonte de chave
      // (2026-11): com ele, quem tinha escrita no banco do GLPI-cliente acrescentava uma chave própria
      // e aceitava um entitlement forjado na graça offline.
      $builtin = self::decodePublicKey('iaT9s0NXKlR1CflBgeipzBhZMdUqNJVwWeexQomIu/Y=');
      $builtinKeyIds = [];
      if ($builtin !== null) {
         $keys['nextool-entitlement-1']          = $builtin;
         $builtinKeyIds['nextool-entitlement-1'] = true;
      }

      $jsonCandidates = [];
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
            // Nunca sobrescreve o kid embutido.
            if ($normalizedId === '' || isset($builtinKeyIds[$normalizedId])) {
               continue;
            }
            $decodedKey = self::decodePublicKey((string) $rawKey);
            if ($decodedKey !== null) {
               $keys[$normalizedId] = $decodedKey;
            }
         }
      }

      $envSingleId  = getenv('NEXTOOL_ENTITLEMENT_SIGNING_KEY_ID');
      $envSingleKey = getenv('NEXTOOL_ENTITLEMENT_SIGNING_PUBLIC_KEY');
      if ($envSingleId !== false && $envSingleKey !== false) {
         $normalizedId = trim((string) $envSingleId);
         if ($normalizedId !== '' && !isset($builtinKeyIds[$normalizedId])) {
            $decoded = self::decodePublicKey((string) $envSingleKey);
            if ($decoded !== null) {
               $keys[$normalizedId] = $decoded;
            }
         }
      }

      // Garantia final: reafirma o builtin -- nunca removível/sobrescrível por config/env.
      if ($builtin !== null) {
         $keys['nextool-entitlement-1'] = $builtin;
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
      // 32 = tamanho da chave pública Ed25519 (a constante só existe com sodium ou com o polyfill carregado)
      if (strlen($binary) !== (defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32)) {
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
