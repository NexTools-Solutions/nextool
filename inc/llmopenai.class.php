<?php
/**
 * Conector OpenAI (Chat Completions), também usado pelo provedor compatível com OpenAI (Ollama, vLLM, LiteLLM,
 * OpenRouter) e pelo Azure OpenAI. Porte do PluginNextoolAiassistOpenAiProvider (nextool-mod-aiassist#73).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/llmconnector.class.php';

class PluginNextoolLlmOpenAi extends PluginNextoolLlmConnector {

   /** @var string auto|minimal|low|medium|high (família gpt-5/o-series) */
   private $reasoningEffort;

   /** @var bool|null null = deduz pelo nome do modelo; o deployment do Azure não revela a família */
   private $reasoningOverride;

   public function __construct(array $conn, array $def) {
      parent::__construct($conn, $def);
      if (!in_array($this->auth, ['bearer', 'api-key', 'none'], true)) {
         $this->auth = 'bearer';
      }
      if ($this->model === '') {
         $this->model = 'gpt-5.4-mini';
      }
      $this->reasoningEffort = (string) ($conn['reasoning_effort'] ?? 'auto');
      $this->reasoningOverride = (array_key_exists('reasoning', $conn) && $conn['reasoning'] !== null) ? (bool) $conn['reasoning'] : null;
   }

   private function headers(bool $json = true): array {
      $h = $json ? ['Content-Type' => 'application/json'] : [];
      if ($this->auth === 'api-key') {
         $h['api-key'] = $this->apiKey;
      } elseif ($this->apiKey !== '' && $this->auth === 'bearer') {
         $h['Authorization'] = 'Bearer ' . $this->apiKey;
      }
      return $h;
   }

   public function chat(array $messages, array $options = []): array {
      if ($this->apiKey === '' && $this->auth !== 'none') {
         return $this->missingKey();
      }
      if ($this->endpoint === '') {
         return $this->fail(sprintf(__('Endereço do provedor %s não configurado.', 'nextool'), $this->label));
      }
      $model = (string) ($options['model'] ?? $this->model);
      $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.2;
      $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : 600;

      // Famílias gpt-5 / o-series: recusam `max_tokens` e temperature != 1 e gastam tokens de RACIOCÍNIO dentro do
      // limite de saída; sem reasoning_effort controlado, um resumo de 1 frase custava 3x mais tokens.
      $reasoning = $this->reasoningOverride ?? PluginNextoolLlmProviders::isReasoningFamily($model);
      $body = ['model' => $model, 'messages' => $messages];
      if ($reasoning) {
         $body['max_completion_tokens'] = $maxTokens * 2 + 200; // saída + raciocínio
         $effort = $this->reasoningEffort;
         if ($effort === '' || $effort === 'auto') {
            $effort = PluginNextoolLlmProviders::defaultReasoningEffort($model);
         }
         $body['reasoning_effort'] = $effort;
      } else {
         $body['temperature'] = $temperature;
         $body['max_completion_tokens'] = $maxTokens;
      }

      // Saída: JSON Schema estrito quando há esquema; senão json_object (exige "JSON" no prompt).
      $schema = is_array($options['json_schema'] ?? null) ? $options['json_schema'] : null;
      $wantsJson = $schema !== null || !empty($options['json_mode']) || (($options['response_mime_type'] ?? '') === 'application/json');
      if ($schema !== null) {
         $body['response_format'] = ['type' => 'json_schema', 'json_schema' => [
            'name' => (string) $schema['name'], 'strict' => true, 'schema' => $schema['schema'],
         ]];
      } elseif ($wantsJson) {
         $body['response_format'] = ['type' => 'json_object'];
      }
      $body = array_filter($body, static fn ($v) => $v !== null && $v !== '');

      $formatFallback = null;
      $resp = null;
      for ($attempt = 0; $attempt < 3; $attempt++) {
         $resp = $this->request('POST', $this->endpoint, $this->headers(), $body);
         if (self::isOk($resp) || (int) $resp['status'] !== 400) {
            break;
         }
         $raw = (string) ($resp['body'] ?? '');
         if (isset($body['reasoning_effort']) && stripos($raw, 'reasoning') !== false) {
            unset($body['reasoning_effort']); // modelo que não aceita o effort pedido (ex.: o-series com "minimal")
         } elseif (($body['response_format']['type'] ?? '') === 'json_schema') {
            $body['response_format'] = ['type' => 'json_object']; // servidor/Azure sem JSON Schema estrito
            $formatFallback = 'json_object';
         } elseif (($body['response_format']['type'] ?? '') === 'json_object') {
            unset($body['response_format']);
            $formatFallback = 'prompt';
         } else {
            break;
         }
      }
      if (!self::isOk($resp)) {
         return $this->failFromResponse($resp);
      }

      $payload = $resp['json'];
      $content = trim((string) ($payload['choices'][0]['message']['content'] ?? ''));
      $usage = $payload['usage'] ?? [];
      $finish = $payload['choices'][0]['finish_reason'] ?? null;
      // OpenAI já soma cache em prompt_tokens e raciocínio em completion_tokens: cached/reasoning são o detalhe.
      return $this->result($content, [
         'prompt' => $usage['prompt_tokens'] ?? 0,
         'completion' => $usage['completion_tokens'] ?? 0,
         'cached' => $usage['prompt_tokens_details']['cached_tokens'] ?? 0,
         'reasoning' => $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
      ], $finish === 'length' ? 'MAX_TOKENS' : $finish, $formatFallback, (int) $resp['status'], $payload);
   }

   public function listModels(): array {
      $official = ($this->def['models_url'] ?? null) !== null;
      if ($official) {
         $url = (string) $this->def['models_url'];
      } else {
         // Compatível: GET {base}/models. Azure (endpoint com ?api-version) não tem lista: o deployment é o modelo.
         $url = (string) preg_replace('#/chat/completions/?$#', '/models', $this->endpoint);
         if ($url === $this->endpoint || $url === '') {
            return self::modelsResult(false, 0, [], [], __('Este provedor não oferece lista de modelos.', 'nextool'));
         }
      }
      if ($official && $this->apiKey === '') {
         return self::modelsResult(false, 0, [], [], sprintf(__('Chave do provedor %s não configurada.', 'nextool'), $this->label));
      }
      $resp = $this->request('GET', $url, $this->headers(false));
      if (!self::isOk($resp)) {
         $f = $this->failFromResponse($resp);
         return self::modelsResult(false, (int) $resp['status'], [], [], (string) $f['error']);
      }
      $ids = [];
      foreach ((array) ($resp['json']['data'] ?? $resp['json']['models'] ?? []) as $m) {
         $id = is_array($m) ? (string) ($m['id'] ?? $m['name'] ?? '') : (string) $m;
         if ($id !== '' && (!$official || PluginNextoolLlmProviders::keepOpenAiModel($id))) {
            $ids[] = $id;
         }
      }
      sort($ids);
      return self::modelsResult(true, (int) $resp['status'], $ids);
   }
}
