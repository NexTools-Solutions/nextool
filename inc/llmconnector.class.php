<?php
/**
 * Conector de provedor de IA do modo "Sua chave": o que é igual entre OpenAI, Anthropic e Gemini (fonte única desde
 * nextool-mod-aiassist#73, achados HI-05, ME-23 e ME-24 da auditoria de 11/11/2026).
 *
 * - Rede: endereço público sai por PluginNextoolCompat::httpClient(), com o proxy de Configurar > Geral (antes o modo
 *   direto ignorava o proxy e falhava atrás de proxy corporativo). Endereço da rede interna ou loopback digitado pelo
 *   administrador (Ollama, vLLM, LiteLLM) sai DIRETO, com o IP fixado pelo UrlGuard: o proxy corporativo não alcança a
 *   rede interna e o cliente HTTP do GLPI 12 bloqueia rede privada. Decisão do owner, 11/11/2026.
 * - Respostas 4xx/5xx chegam como resposta (http_errors desligado), igual no Guzzle do GLPI 10/11 e no adaptador do
 *   GLPI 12: o conector decide pelo status, nunca por exceção.
 * - Falha: um formato só, uma tabela status -> mensagem, corpo remoto cortado em 2.000 caracteres no log.
 *
 * Resultado de chat(): success, content, tokens_prompt, tokens_completion, tokens_cached, tokens_cache_write,
 * tokens_reasoning, finish_reason (MAX_TOKENS normalizado), format_fallback (json_object|prompt|null), status (HTTP; 0 =
 * sem resposta), raw, error.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

abstract class PluginNextoolLlmConnector {

   /** Tamanho máximo do corpo remoto gravado no log. */
   protected const LOG_BODY_MAX = 2000;

   /** @var string */
   protected $provider;
   /** @var string */
   protected $apiKey;
   /** @var string */
   protected $model;
   /** @var int */
   protected $timeout;
   /** @var string */
   protected $endpoint;
   /** @var string bearer|api-key|none|x-api-key|x-goog-api-key */
   protected $auth;
   /** @var string */
   protected $label;
   /** @var bool URL digitada pelo administrador: UrlGuard, sem redirecionamento e sem texto remoto na tela */
   protected $urlGuard;
   /** @var string canal do Toolbox::logInFile */
   protected $logChannel;
   /** @var array registro do provedor (PluginNextoolLlmProviders) */
   protected $def;
   /** @var array conexão completa, para opções próprias de cada conector */
   protected $conn;

   public function __construct(array $conn, array $def) {
      $this->conn = $conn;
      $this->def = $def;
      $this->provider = (string) ($conn['provider'] ?? '');
      $this->apiKey = (string) ($conn['api_key'] ?? '');
      $this->model = (string) ($conn['model'] ?? '');
      $this->timeout = max(5, (int) ($conn['timeout'] ?? 25));
      $this->endpoint = rtrim(trim((string) ($conn['endpoint'] ?? '')), '/');
      $this->auth = (string) ($conn['auth'] ?? 'bearer');
      $label = trim((string) ($conn['label'] ?? ''));
      $this->label = $label !== '' ? $label : PluginNextoolLlmProviders::label($this->provider);
      $this->urlGuard = !empty($conn['url_guard']);
      $this->logChannel = (string) ($conn['log'] ?? 'plugin_nextool');
   }

   /**
    * @param array $messages formato OpenAI: [['role' => 'system'|'user'|'assistant', 'content' => '...'], ...]
    * @param array $options  model, temperature, max_tokens, json_schema (['name','schema']), json_mode, response_mime_type,
    *                         cache_system (Anthropic: marca o system com cache_control, para chamadas repetidas)
    */
   abstract public function chat(array $messages, array $options = []): array;

   /**
    * Modelos de conversa do provedor (filtrados nos oficiais; crus no compatível e no Azure).
    *
    * @return array{ok:bool,status:int,ids:string[],labels:array<string,string>,error:string}
    */
   abstract public function listModels(): array;

   public function getLabel(): string {
      return $this->label;
   }

   public function getProvider(): string {
      return $this->provider;
   }

   // ------------------------------------------------------------------ falha e mensagens

   protected function fail(string $error, int $status = 0, array $raw = []): array {
      return [
         'success' => false, 'content' => '', 'tokens_prompt' => 0, 'tokens_completion' => 0, 'tokens_cached' => 0,
         'tokens_cache_write' => 0, 'tokens_reasoning' => 0, 'finish_reason' => null, 'format_fallback' => null,
         'status' => $status, 'raw' => $raw, 'error' => $error,
      ];
   }

   protected function missingKey(): array {
      return $this->fail(sprintf(__('Chave do provedor %s não configurada.', 'nextool'), $this->label));
   }

   /** Mensagem amigável por status HTTP (0 = sem resposta). Uma tabela só para todos os provedores e módulos. */
   public static function statusMessage(string $label, int $status): string {
      if ($status === 401 || $status === 403) {
         return sprintf(__('%1$s recusou a chave (HTTP %2$d). Confira a chave e as permissões.', 'nextool'), $label, $status);
      }
      if ($status === 402) {
         return sprintf(__('%s recusou por falta de créditos ou de faturamento ativo na conta (HTTP 402). Confira o pagamento no site do provedor.', 'nextool'), $label);
      }
      if ($status === 404) {
         return sprintf(__('%s não encontrou o modelo ou o endereço (HTTP 404). Escolha outro modelo ou atualize a lista de modelos.', 'nextool'), $label);
      }
      if ($status === 429) {
         return sprintf(__('%s recusou por limite de uso ou falta de créditos na conta do provedor (HTTP 429). Confira o saldo e os limites no site do provedor.', 'nextool'), $label);
      }
      if ($status === 0) {
         return sprintf(__('Não foi possível conectar em %s. Confira o endereço e se o servidor está no ar.', 'nextool'), $label);
      }
      return sprintf(__('%1$s respondeu com status %2$s.', 'nextool'), $label, $status);
   }

   /**
    * Falha a partir de uma resposta HTTP de erro. Log: status e corpo cortado. Tela: a mensagem por status e, nos
    * provedores oficiais, o motivo dado pela API (curto). URL do administrador: nunca o texto do servidor remoto.
    */
   protected function failFromResponse(array $resp): array {
      $status = (int) ($resp['status'] ?? 0);
      if (!empty($resp['guard_error'])) {
         return $this->fail((string) $resp['guard_error'], 0);
      }
      $detail = (string) ($resp['body'] ?? '');
      if ($detail === '') {
         $detail = (string) ($resp['transport_error'] ?? '');
      }
      $this->log(sprintf('status=%s body=%s', $status ?: 'n/a', mb_substr($detail, 0, self::LOG_BODY_MAX)));
      $msg = self::statusMessage($this->label, $status);
      $api = $this->apiErrorMessage(is_array($resp['json'] ?? null) ? $resp['json'] : []);
      if (!$this->urlGuard && $api !== '' && $status !== 0) {
         $msg .= ' ' . sprintf(__('Motivo informado: %s', 'nextool'), mb_substr($api, 0, 200));
      }
      return $this->fail($msg, $status, $this->urlGuard ? ['status' => $status] : ['status' => $status, 'body' => $resp['json'] ?? null]);
   }

   /** Texto do erro no corpo da API (formatos OpenAI, Anthropic e Gemini: error.message). */
   protected function apiErrorMessage(array $json): string {
      $m = $json['error']['message'] ?? ($json['message'] ?? '');
      return is_string($m) ? trim($m) : '';
   }

   protected function log(string $line): void {
      Toolbox::logInFile($this->logChannel, '[LLM-' . strtoupper($this->provider ?: 'provider') . '] ' . $line . "\n");
   }

   // ------------------------------------------------------------------ rede

   /**
    * Requisição HTTP. Nunca lança: transporte falho volta com status 0 e transport_error; URL recusada pela guarda
    * volta com guard_error.
    *
    * @return array{status:int,body:string,json:?array,transport_error?:string,guard_error?:string}
    */
   protected function request(string $method, string $url, array $headers, ?array $json = null): array {
      // Sem redirecionamento em nenhum provedor: as APIs não redirecionam e a chave vai em cabeçalho próprio
      // (x-api-key, x-goog-api-key, api-key), que o Guzzle não tira num redirecionamento para outro host.
      $opts = ['timeout' => $this->timeout, 'connect_timeout' => 10, 'http_errors' => false, 'allow_redirects' => false];
      $direct = null;
      if ($this->urlGuard) {
         require_once __DIR__ . '/llmurlguard.class.php';
         $chk = PluginNextoolLlmUrlGuard::check($url);
         if (!$chk['ok']) {
            return ['status' => 0, 'body' => '', 'json' => null, 'guard_error' => PluginNextoolLlmUrlGuard::message($chk['code'])];
         }
         $opts += PluginNextoolLlmUrlGuard::guzzleOptions($chk); // sem redirecionamento + IP fixo
         if (!empty($chk['private'])) {
            // Rede interna ou loopback: direto, sem proxy (ver cabeçalho). http:// só passa aqui: a guarda exige
            // https em endereço público.
            $direct = new \GuzzleHttp\Client($opts);
         }
      }
      $reqOpts = ['headers' => $headers];
      if ($json !== null) {
         $reqOpts['body'] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
      try {
         $client = $direct ?? PluginNextoolCompat::httpClient($opts);
         $response = $client->request($method, $url, $reqOpts);
         $body = (string) $response->getBody();
         $decoded = json_decode($body, true);
         return ['status' => (int) $response->getStatusCode(), 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
      } catch (\GuzzleHttp\Exception\RequestException $e) {
         // Guzzle com http_errors ligado por fora (não deveria) ou erro com resposta: trata como resposta.
         if ($e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            return ['status' => (int) $e->getResponse()->getStatusCode(), 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
         }
         return ['status' => 0, 'body' => '', 'json' => null, 'transport_error' => $e->getMessage()];
      } catch (\Throwable $e) {
         return ['status' => 0, 'body' => '', 'json' => null, 'transport_error' => $e->getMessage()];
      }
   }

   protected static function isOk(array $resp): bool {
      $st = (int) ($resp['status'] ?? 0);
      return $st >= 200 && $st < 300 && is_array($resp['json'] ?? null);
   }

   /** Resultado de sucesso no formato comum. */
   protected function result(string $content, array $tokens, ?string $finish, ?string $formatFallback, int $status, array $raw): array {
      return [
         'success' => $content !== '',
         'content' => $content,
         'tokens_prompt' => (int) ($tokens['prompt'] ?? 0),
         'tokens_completion' => (int) ($tokens['completion'] ?? 0),
         'tokens_cached' => (int) ($tokens['cached'] ?? 0),
         'tokens_cache_write' => (int) ($tokens['cache_write'] ?? 0),
         'tokens_reasoning' => (int) ($tokens['reasoning'] ?? 0),
         'finish_reason' => $finish,
         'format_fallback' => $formatFallback,
         'status' => $status,
         'raw' => $raw,
         'error' => $content === '' ? sprintf(__('Resposta vazia de %s.', 'nextool'), $this->label) : null,
      ];
   }

   /** Resultado de listModels() no formato comum. */
   protected static function modelsResult(bool $ok, int $status, array $ids = [], array $labels = [], string $error = ''): array {
      $ids = array_values(array_unique(array_filter($ids, static fn ($id) => is_string($id) && $id !== '' && mb_strlen($id) <= 200)));
      return ['ok' => $ok, 'status' => $status, 'ids' => $ids, 'labels' => $labels, 'error' => $error];
   }
}
