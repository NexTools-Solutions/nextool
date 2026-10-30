<?php
/**
 * Chamada de IA GERENCIADA de qualquer módulo pelo cloud link (plataforma multi-módulo dos créditos de IA, 2026-10-29).
 *
 * `POST /ai/v1/<module>/<op>` no cérebro, que autentica o vínculo, confere o produto do MÓDULO (N5) e encaminha ao motor
 * da NexTool; o motor executa a função e o ContainerAPI cobra pela metodologia daquela função (custo real, preço por
 * faixa...). O módulo só manda o payload da função e recebe o resultado validado: nunca vê prompt, modelo nem chave.
 *
 * Extraído e generalizado do `PluginNextoolAiassistCloudGateway` do AI Assist, que segue na rota legada `/ai/v1/<op>`
 * (e no modo BYOK pela nuvem, que só ele tem). Transporte e assinatura: `PluginNextoolCloudClient::post()`.
 *
 * Contrato do envelope (nextool-cerebro docs/contrato-cloud-link-v1.md §10): sucesso {ok:true, result, usage,
 * balance_micros, trial}; recusa {ok:false, error:{code, message, retryable, retry_after_s?, detail?}}. Quem chama decide
 * SÓ pelo código, nunca pelo status HTTP. Códigos da cobrança por função (nada reservado nem cobrado):
 *   E_CONFIRM_REQUIRED   a faixa exige confirmação do preço; `detail.price_micros` é o valor a mostrar. Repetir com o
 *                        valor aceito no payload (cada módulo decide o campo; o Tradutor usa `max_price_micros`);
 *   E_PRICE_CHANGED      o preço passou do valor aceito (a política mudou): mostrar o novo e pedir de novo;
 *   E_UNITS_EXCEEDED     acima do limite por chamada (`detail.max_units`);
 *   E_FEATURE_NOT_PRICED a função não tem metodologia de cobrança configurada na NexTool.
 *
 * @since pós-6.49.0 (a versão sai no /release)
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiGateway {

   public const ROUTE_PREFIX = '/ai/v1/';

   /** Teto do corpo que o cérebro aceita (contrato §10.3). */
   public const ENVELOPE_MAX_BYTES = 65536;

   /** Tentativas que falharam por request_id: o ledger não reabre uma reserva liberada (aiassist #63). */
   private const RETRY_TTL_S = 86400;

   /** Recusas que não chegaram a reservar no ledger: repetir com o mesmo id é seguro. */
   private const RETRY_IGNORES = ['breaker_open', 'no_link', 'inactive', 'E_NOT_FOUND', 'E_VALIDATION', 'E_CONFIRM_REQUIRED',
      'E_PRICE_CHANGED', 'E_UNITS_EXCEEDED', 'E_FEATURE_NOT_PRICED', 'E_CREDITS_EXHAUSTED', 'E_PRODUCT_INACTIVE'];

   /** Sem vínculo utilizável: a base não enviou nada. */
   public const NO_LINK_CODES = ['no_link', 'inactive'];

   /** Falha de transporte: o pedido pode ter chegado ou não. */
   private const TRANSPORT_CODES = ['network', 'bad_json', 'bad_body'];

   /** Recusas que NÃO contam para o disjuntor: são do pedido, do preço ou do saldo, não da nuvem. */
   private const BREAKER_IGNORES = ['E_VALIDATION', 'E_CREDITS_EXHAUSTED', 'E_TRIAL_TIER', 'E_PRODUCT_INACTIVE', 'E_NOT_FOUND',
      'idempotency_mismatch', 'E_CONFIRM_REQUIRED', 'E_PRICE_CHANGED', 'E_UNITS_EXCEEDED', 'E_FEATURE_NOT_PRICED'];

   /** Disjuntor por função: 3 falhas seguidas abrem por 5 min. Estado na config do PRÓPRIO módulo (`ai_breaker`). */
   private const BREAKER_FAILS = 3;
   private const BREAKER_OPEN_S = 300;

   private const RE_KEY = '/^[a-z][a-z0-9_]{1,31}$/';
   private const RE_OP = '/^[a-z_]{3,24}$/';

   /** @var callable|null dublê da nuvem (só CLI) */
   private static $double = null;

   /** @var array<int, array{route:string, body:array, timeout:int}> */
   private static $sent = [];

   /** Liga (ou desliga, com null) o dublê. Só em CLI: em pedido web a chamada segue para a nuvem de verdade. */
   public static function useDouble(?callable $double): void {
      if (PHP_SAPI !== 'cli') {
         return;
      }
      self::$double = $double;
      self::$sent   = [];
   }

   /** Pedidos que o dublê recebeu, em ordem. */
   public static function sentRequests(): array {
      return self::$sent;
   }

   /**
    * `request_id` DETERMINÍSTICO: `<module>-<op>-<objeto>-<16 hex do payload>`. O mesmo pedido reenviado não é cobrado de
    * novo (o motor reconhece); payload diferente (inclusive o preço aceito) vira id diferente.
    */
   public static function requestId(string $module, string $op, string $objectKey, array $payload): string {
      $obj = preg_replace('/[^A-Za-z0-9_.:-]/', '', $objectKey);
      $obj = $obj === '' ? 'x' : mb_substr($obj, 0, 64);
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      return $module . '-' . $op . '-' . $obj . '-' . substr(hash('sha256', (string) $json), 0, 16);
   }

   /**
    * Chama uma função gerenciada.
    *
    * @param string $module  chave do módulo dono da função (ex.: `translate`)
    * @param string $op      função (ex.: `translate`)
    * @param array  $payload corpo da função
    * @param array  $opts    object_key, timeout_s (padrão 45), language, breaker (PluginNextoolBaseModule do módulo:
    *                        guarda o disjuntor na config dele), request_id (forçar)
    * @return array{ok:bool, result:?array, usage:array, balance_micros:?int, trial:?bool, code:string, http:int,
    *               retryable:bool, retry_after_s:?int, detail:array, transport:bool, no_link:bool, message:string, request_id?:string}
    */
   public static function call(string $module, string $op, array $payload, array $opts = []): array {
      if (!preg_match(self::RE_KEY, $module) || !preg_match(self::RE_OP, $op)) {
         return self::out(false, null, 'E_NOT_FOUND', 0, ['message' => 'funcao invalida']);
      }
      $breaker = ($opts['breaker'] ?? null) instanceof PluginNextoolBaseModule ? $opts['breaker'] : null;
      $fn = $module . '.' . $op;
      if ($breaker !== null && self::breakerOpen($breaker, $fn)) {
         return self::out(false, null, 'breaker_open', 0, ['message' => 'nuvem indisponivel para esta funcao (disjuntor)', 'retryable' => true]);
      }

      $timeoutS = max(1, min(55, (int) ($opts['timeout_s'] ?? 45)));
      $baseId = (string) ($opts['request_id'] ?? self::requestId($module, $op, (string) ($opts['object_key'] ?? 'env'), $payload));
      $body = [
         'request_id' => self::attemptId($baseId),
         'mode'       => 'managed',
         'language'   => (string) ($opts['language'] ?? self::language()),
         'timeout_ms' => $timeoutS * 1000,
         // o motor exige OBJETO: um payload vazio em PHP viraria `[]`
         'payload'    => $payload === [] ? new stdClass() : $payload,
      ];

      $route = self::ROUTE_PREFIX . $module . '/' . $op;
      $hop = min(60, $timeoutS + 5); // o salto espera o orçamento do motor + 5 s (o cérebro soma 2 s)
      if (self::$double !== null && PHP_SAPI === 'cli') {
         self::$sent[] = ['route' => $route, 'body' => $body, 'timeout' => $hop];
         $raw = (self::$double)($route, $body, $hop);
      } else {
         require_once NEXTOOL_PHP_DIR . '/inc/cloudclient.class.php';
         $raw = PluginNextoolCloudClient::post($route, $body, $hop);
      }
      $r = self::normalize(is_array($raw) ? $raw : []);
      if ($breaker !== null) {
         self::breakerRecord($breaker, $fn, $r);
      }
      if (!$r['ok'] && !in_array($r['code'], self::RETRY_IGNORES, true)) {
         self::recordFailedAttempt($baseId);
      }
      if ($r['balance_micros'] !== null) {
         require_once NEXTOOL_PHP_DIR . '/inc/aicredits.class.php';
         PluginNextoolAiCredits::rememberBalance($r['balance_micros']);
      }
      $r['request_id'] = $body['request_id'];
      return $r;
   }

   /**
    * Id da tentativa atual: o base enquanto nenhuma falhou; depois o número da tentativa entra no segmento do OBJETO
    * (`<module>-<op>-<objeto>.r<N>-<16 hex>`), porque o ledger não reabre a reserva liberada da tentativa anterior.
    */
   public static function attemptId(string $baseId): string {
      $n = self::failedAttempts($baseId);
      if ($n <= 0 || !preg_match('/^([a-z][a-z0-9_]{1,31}-[a-z_]{3,24}-)([A-Za-z0-9_.:-]{1,64})(-[0-9a-f]{16})$/', $baseId, $m)) {
         return $baseId;
      }
      $tag = '.r' . $n;
      return $m[1] . substr($m[2], 0, 64 - strlen($tag)) . $tag . $m[3];
   }

   private static function retryKey(string $baseId): string {
      return 'nxai_rid_' . md5($baseId);
   }

   private static function failedAttempts(string $baseId): int {
      global $GLPI_CACHE;
      try {
         return is_object($GLPI_CACHE) ? max(0, (int) $GLPI_CACHE->get(self::retryKey($baseId), 0)) : 0;
      } catch (\Throwable $e) {
         return 0;
      }
   }

   private static function recordFailedAttempt(string $baseId): void {
      global $GLPI_CACHE;
      try {
         if (is_object($GLPI_CACHE)) {
            $GLPI_CACHE->set(self::retryKey($baseId), self::failedAttempts($baseId) + 1, self::RETRY_TTL_S);
         }
      } catch (\Throwable $e) {
         // best-effort: sem cache, a próxima tentativa repete o id
      }
   }

   /**
    * Saldo e vínculo da nuvem no modo gerenciado, sem cobrar: o `balance` do motor (rota do AI Assist, a única que o
    * expõe; o produto exigido é o mesmo dos créditos). É o "Testar conexão" dos módulos.
    */
   public static function balance(): array {
      $body = ['request_id' => 'aiassist-balance-env-' . bin2hex(random_bytes(8)), 'mode' => 'managed', 'language' => self::language(),
         'timeout_ms' => 5000, 'payload' => new stdClass()];
      if (self::$double !== null && PHP_SAPI === 'cli') {
         self::$sent[] = ['route' => self::ROUTE_PREFIX . 'balance', 'body' => $body, 'timeout' => 10];
         $raw = (self::$double)(self::ROUTE_PREFIX . 'balance', $body, 10);
      } else {
         require_once NEXTOOL_PHP_DIR . '/inc/cloudclient.class.php';
         $raw = PluginNextoolCloudClient::post(self::ROUTE_PREFIX . 'balance', $body, 10);
      }
      $r = self::normalize(is_array($raw) ? $raw : []);
      $micros = $r['ok'] ? ($r['result']['balance_micros'] ?? null) : null;
      if (is_numeric($micros)) {
         require_once NEXTOOL_PHP_DIR . '/inc/aicredits.class.php';
         PluginNextoolAiCredits::rememberBalance((int) $micros);
      }
      return $r;
   }

   /** Idioma do GLPI (xx_YY). */
   public static function language(): string {
      $lang = (string) ($_SESSION['glpilanguage'] ?? '');
      if ($lang === '' && isset($GLOBALS['CFG_GLPI']['language'])) {
         $lang = (string) $GLOBALS['CFG_GLPI']['language'];
      }
      return preg_match('/^[a-z]{2}_[A-Z]{2}$/', $lang) ? $lang : 'pt_BR';
   }

   /**
    * Resposta da base -> forma única.
    *
    * @param array $raw {ok, http, data, error} do PluginNextoolCloudClient::post()
    */
   public static function normalize(array $raw): array {
      $http = (int) ($raw['http'] ?? 0);
      $data = is_array($raw['data'] ?? null) ? $raw['data'] : null;

      if (!empty($raw['ok']) && is_array($data) && ($data['ok'] ?? null) === true) {
         if (is_array($data['result'] ?? null)) {
            $r = self::out(true, $data['result'], '', $http, []);
            $r['usage'] = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $r['balance_micros'] = isset($data['balance_micros']) && is_numeric($data['balance_micros']) ? (int) $data['balance_micros'] : null;
            $r['trial'] = isset($data['trial']) ? (bool) $data['trial'] : null;
            return $r;
         }
         return self::out(false, null, 'bad_json', $http, []);
      }

      $err  = (is_array($data) && is_array($data['error'] ?? null)) ? $data['error'] : [];
      $code = (string) ($raw['error'] ?? '');
      if ($code === '' || (str_starts_with($code, 'http_') && !empty($err['code']))) {
         $code = (string) ($err['code'] ?? $code);
      }
      if ($code === '') {
         $code = $http > 0 ? 'http_' . $http : 'network';
      }
      return self::out(false, null, $code, $http, $err);
   }

   /** Mensagem para o usuário a partir do código (sem detalhe técnico; o código vai no log do módulo). */
   public static function userMessage(array $r): string {
      require_once NEXTOOL_PHP_DIR . '/inc/aicredits.class.php';
      $d = is_array($r['detail'] ?? null) ? $r['detail'] : [];
      switch ($r['code'] ?? '') {
         case 'E_CREDITS_EXHAUSTED':
         case 'E_PRODUCT_INACTIVE':
            return sprintf(__('Sem créditos de IA neste ambiente. Compre créditos em %s e use "Sincronizar" na tela do NexTool.', 'nextool'), PluginNextoolAiCredits::purchaseUrl());
         case 'E_CONFIRM_REQUIRED':
            return sprintf(__('Esta operação custa %s. Confirme para continuar.', 'nextool'), PluginNextoolAiCredits::brl((int) ($d['price_micros'] ?? 0)));
         case 'E_PRICE_CHANGED':
            return sprintf(__('O preço desta operação mudou para %s. Confirme o novo valor para continuar.', 'nextool'), PluginNextoolAiCredits::brl((int) ($d['price_micros'] ?? 0)));
         case 'E_UNITS_EXCEEDED':
            return sprintf(__('O texto passa do limite de %s caracteres por operação.', 'nextool'), number_format((int) ($d['max_units'] ?? 0), 0, ',', '.'));
         case 'E_FEATURE_NOT_PRICED':
            return __('Esta função ainda não está disponível no modo gerenciado. Fale com o suporte da NexTool.', 'nextool');
         case 'rate_limited':
            return __('Muitas chamadas em pouco tempo. Aguarde um instante e tente de novo.', 'nextool');
         case 'E_PROVIDER_TIMEOUT':
         case 'E_PROVIDER_FAILED':
            return __('O provedor não respondeu a tempo. Tente de novo.', 'nextool');
         case 'breaker_open':
            return __('A nuvem NexTool está indisponível no momento. Tente de novo em alguns minutos.', 'nextool');
         case 'no_link':
         case 'inactive':
            return __('Sem vínculo ativo com a NexTool. Use "Sincronizar" na tela do NexTool.', 'nextool');
         default:
            return __('A nuvem NexTool não conseguiu atender agora. Tente de novo.', 'nextool');
      }
   }

   private static function out(bool $ok, ?array $result, string $code, int $http, array $err): array {
      $transport = !$ok && (in_array($code, self::TRANSPORT_CODES, true) || (str_starts_with($code, 'http_') && $err === []));
      $retryAfter = isset($err['retry_after_s']) && is_numeric($err['retry_after_s']) ? max(0, (int) $err['retry_after_s']) : null;
      return [
         'ok'             => $ok,
         'result'         => $result,
         'usage'          => [],
         'balance_micros' => null,
         'trial'          => null,
         'code'           => $code,
         'http'           => $http,
         'message'        => (string) ($err['message'] ?? ''),
         'retryable'      => (bool) ($err['retryable'] ?? false),
         'retry_after_s'  => $retryAfter,
         'detail'         => is_array($err['detail'] ?? null) ? $err['detail'] : [],
         'transport'      => $transport,
         'no_link'        => in_array($code, self::NO_LINK_CODES, true),
      ];
   }

   // ── Disjuntor (estado em getConfig() do módulo dono: `ai_breaker`; falha de gravação é ignorada) ─────────────────

   private static function breakerOpen(PluginNextoolBaseModule $module, string $fn): bool {
      $st = self::breakerState($module);
      return (int) ($st[$fn]['open_until'] ?? 0) > time();
   }

   private static function breakerState(PluginNextoolBaseModule $module): array {
      try {
         $cfg = $module->getConfig();
         return is_array($cfg['ai_breaker'] ?? null) ? $cfg['ai_breaker'] : [];
      } catch (\Throwable $e) {
         return [];
      }
   }

   private static function breakerRecord(PluginNextoolBaseModule $module, string $fn, array $r): void {
      $falhou = !$r['ok'] && !in_array($r['code'], self::BREAKER_IGNORES, true);
      $st = self::breakerState($module);
      $cur = is_array($st[$fn] ?? null) ? $st[$fn] : ['fails' => 0, 'open_until' => 0];
      if (!$falhou) {
         if ((int) $cur['fails'] === 0 && (int) $cur['open_until'] === 0) {
            return;
         }
         $cur = ['fails' => 0, 'open_until' => 0];
      } else {
         $cur['fails'] = (int) $cur['fails'] + 1;
         if ($cur['fails'] >= self::BREAKER_FAILS) {
            $cur['open_until'] = time() + self::BREAKER_OPEN_S;
            $cur['fails'] = 0;
         }
      }
      $st[$fn] = $cur;
      try {
         $module->saveConfig(['ai_breaker' => $st]); // só a chave do disjuntor (o saveConfig faz o merge)
      } catch (\Throwable $e) {
         // best-effort
      }
   }
}
