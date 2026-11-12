<?php
/**
 * Conector Anthropic (Messages API). Porte do PluginNextoolAiassistAnthropicProvider (nextool-mod-aiassist#73).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/llmconnector.class.php';

class PluginNextoolLlmAnthropic extends PluginNextoolLlmConnector {

   public function __construct(array $conn, array $def) {
      parent::__construct($conn, $def);
      if ($this->model === '') {
         $this->model = 'claude-haiku-4-5';
      }
   }

   private function headers(): array {
      return [
         'x-api-key' => $this->apiKey,
         'anthropic-version' => PluginNextoolLlmProviders::ANTHROPIC_API_VERSION,
         'Content-Type' => 'application/json',
      ];
   }

   public function chat(array $messages, array $options = []): array {
      if ($this->apiKey === '') {
         return $this->missingKey();
      }
      $schema = is_array($options['json_schema'] ?? null) ? $options['json_schema'] : null;
      $body = $this->buildBody($messages, $options, $schema);
      if (empty($body['messages'])) {
         return $this->fail(sprintf(__('Nenhuma mensagem válida para enviar a %s.', 'nextool'), $this->label));
      }

      $resp = $this->request('POST', $this->endpoint, $this->headers(), $body);
      $formatFallback = null;
      // Formato estruturado ou temperatura recusados (modelo/conta sem o recurso): 1 nova tentativa com o formato só
      // pedido no prompt e sem temperatura. O parse local de quem chama segue como rede final.
      if ((int) $resp['status'] === 400 && (isset($body['output_config']) || isset($body['tools']) || isset($body['temperature']))) {
         unset($body['output_config'], $body['tools'], $body['tool_choice'], $body['temperature'], $body['thinking']);
         $formatFallback = 'prompt';
         $resp = $this->request('POST', $this->endpoint, $this->headers(), $body);
      }
      if (!self::isOk($resp)) {
         return $this->failFromResponse($resp);
      }

      $payload = $resp['json'];
      $texts = [];
      $toolInput = null;
      foreach ((array) ($payload['content'] ?? []) as $block) {
         if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $texts[] = $block['text'];
         } elseif (($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
            $toolInput = $block['input'];
         }
      }
      // Ferramenta forçada (modelos anteriores à 5.x): o JSON vem no input da ferramenta.
      $content = $toolInput !== null
         ? (string) json_encode($toolInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
         : trim(implode("\n", $texts));
      $usage = $payload['usage'] ?? [];
      $stop = $payload['stop_reason'] ?? null;
      // input_tokens da Anthropic NÃO inclui o cache: leitura e escrita vêm separadas.
      return $this->result($content, [
         'prompt' => $usage['input_tokens'] ?? 0,
         'completion' => $usage['output_tokens'] ?? 0,
         'cached' => $usage['cache_read_input_tokens'] ?? 0,
         'cache_write' => $usage['cache_creation_input_tokens'] ?? 0,
      ], $stop === 'max_tokens' ? 'MAX_TOKENS' : $stop, $formatFallback, (int) $resp['status'], $payload);
   }

   public function listModels(): array {
      if ($this->apiKey === '') {
         return self::modelsResult(false, 0, [], [], sprintf(__('Chave do provedor %s não configurada.', 'nextool'), $this->label));
      }
      $resp = $this->request('GET', (string) $this->def['models_url'], $this->headers());
      if (!self::isOk($resp)) {
         $f = $this->failFromResponse($resp);
         return self::modelsResult(false, (int) $resp['status'], [], [], (string) $f['error']);
      }
      $ids = [];
      $labels = [];
      foreach ((array) ($resp['json']['data'] ?? []) as $m) {
         $id = (string) ($m['id'] ?? '');
         if ($id === '' || !PluginNextoolLlmProviders::keepAnthropicModel($id)) {
            continue;
         }
         $ids[] = $id;
         $labels[$id] = (string) ($m['display_name'] ?? '');
      }
      return self::modelsResult(true, (int) $resp['status'], $ids, $labels);
   }

   /** Corpo da Messages API a partir das mensagens no formato OpenAI. */
   private function buildBody(array $messages, array $options, ?array $schema): array {
      $model = (string) ($options['model'] ?? $this->model);
      $body = [
         'model' => $model,
         'max_tokens' => isset($options['max_tokens']) ? max(1, (int) $options['max_tokens']) : 600, // obrigatório
         'messages' => $this->buildMessages($messages),
      ];
      $family5 = PluginNextoolLlmProviders::isAnthropicStructuredFamily($model);
      // Família 5.x: `temperature` dá 400. Nas anteriores segue como antes.
      if (isset($options['temperature']) && !$family5) {
         $body['temperature'] = (float) $options['temperature'];
      }
      if ($schema !== null) {
         if ($family5) {
            $body['output_config'] = ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema['schema']]];
            if (preg_match('/^claude-haiku-/', $model)) {
               $body['thinking'] = ['type' => 'disabled'];
            } elseif (preg_match('/^claude-sonnet-/', $model)) {
               $body['thinking'] = ['type' => 'between_tools'];
            } else {
               $body['max_tokens'] += 2000; // o Opus não desliga o raciocínio, cobrado como saída
            }
         } else {
            $body['tools'] = [['name' => 'emit', 'description' => 'Devolve o resultado estruturado.', 'input_schema' => $schema['schema']]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => 'emit'];
         }
      }
      $system = [];
      foreach ($messages as $m) {
         if (($m['role'] ?? '') === 'system' && !empty($m['content'])) {
            $system[] = trim((string) $m['content']);
         }
      }
      $system = trim(implode("\n\n", array_filter($system)));
      if ($system !== '') {
         // cache_system: o mesmo system em chamadas seguidas (tradução em lote) sai do cache da Anthropic.
         $body['system'] = !empty($options['cache_system'])
            ? [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]]
            : $system;
      }
      return $body;
   }

   /**
    * Papéis user/assistant alternados (a Messages API exige): "system" sai (vai no parâmetro system), vazias são
    * ignoradas, consecutivas do mesmo papel são mescladas e a primeira tem de ser do usuário.
    */
   private function buildMessages(array $messages): array {
      $out = [];
      foreach ($messages as $m) {
         $role = (string) ($m['role'] ?? '');
         if ($role === 'system') {
            continue;
         }
         $content = trim((string) ($m['content'] ?? ''));
         if ($content === '') {
            continue;
         }
         $apiRole = $role === 'assistant' ? 'assistant' : 'user';
         $last = count($out) - 1;
         if ($last >= 0 && $out[$last]['role'] === $apiRole) {
            $out[$last]['content'] .= "\n\n" . $content;
         } else {
            $out[] = ['role' => $apiRole, 'content' => $content];
         }
      }
      while (!empty($out) && $out[0]['role'] !== 'user') {
         array_shift($out);
      }
      return $out;
   }
}
