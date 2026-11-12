<?php
/**
 * Conector Google Gemini (generateContent). Porte do PluginNextoolAiassistGeminiProvider (nextool-mod-aiassist#73).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/llmconnector.class.php';

class PluginNextoolLlmGemini extends PluginNextoolLlmConnector {

   public function __construct(array $conn, array $def) {
      parent::__construct($conn, $def);
      if ($this->model === '') {
         $this->model = 'gemini-2.5-flash';
      }
   }

   private function headers(): array {
      return ['Content-Type' => 'application/json', 'x-goog-api-key' => $this->apiKey];
   }

   public function chat(array $messages, array $options = []): array {
      if ($this->apiKey === '') {
         return $this->missingKey();
      }
      $model = (string) ($options['model'] ?? $this->model);
      $system = [];
      $contents = [];
      foreach ($messages as $m) {
         $role = (string) ($m['role'] ?? '');
         $content = trim((string) ($m['content'] ?? ''));
         if ($content === '') {
            continue;
         }
         if ($role === 'system') {
            $system[] = $content;
            continue;
         }
         $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $content]]];
      }
      if (empty($contents)) {
         return $this->fail(sprintf(__('Nenhuma mensagem válida para enviar a %s.', 'nextool'), $this->label));
      }

      $body = [
         'contents' => $contents,
         'generationConfig' => [
            'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : 0.2,
            'maxOutputTokens' => isset($options['max_tokens']) ? (int) $options['max_tokens'] : 600,
         ],
      ];
      if (!empty($options['response_mime_type'])) {
         $body['generationConfig']['responseMimeType'] = (string) $options['response_mime_type'];
      } elseif (!empty($options['json_mode']) || is_array($options['json_schema'] ?? null)) {
         $body['generationConfig']['responseMimeType'] = 'application/json';
      }
      if (is_array($options['json_schema'] ?? null)) {
         $body['generationConfig']['responseSchema'] = PluginNextoolLlmProviders::geminiSchema((array) $options['json_schema']['schema']);
      }
      $system = trim(implode("\n\n", $system));
      if ($system !== '') {
         $body['systemInstruction'] = ['parts' => [['text' => $system]]];
      }

      $url = sprintf('%s/%s:generateContent', $this->endpoint, rawurlencode($model));
      $resp = $this->request('POST', $url, $this->headers(), $body);
      $formatFallback = null;
      if ((int) $resp['status'] === 400 && isset($body['generationConfig']['responseSchema'])) {
         // Esquema recusado pelo modelo: refaz só com o MIME JSON (o parse local é a rede final).
         unset($body['generationConfig']['responseSchema']);
         $formatFallback = 'json_object';
         $resp = $this->request('POST', $url, $this->headers(), $body);
      }
      if (!self::isOk($resp)) {
         return $this->failFromResponse($resp);
      }

      $payload = $resp['json'];
      $texts = [];
      foreach ((array) ($payload['candidates'][0]['content']['parts'] ?? []) as $part) {
         if (!empty($part['text'])) {
            $texts[] = $part['text'];
         }
      }
      $usage = $payload['usageMetadata'] ?? [];
      // candidatesTokenCount NÃO inclui o raciocínio (thoughtsTokenCount, cobrado como saída).
      return $this->result(trim(implode("\n", $texts)), [
         'prompt' => $usage['promptTokenCount'] ?? 0,
         'completion' => $usage['candidatesTokenCount'] ?? 0,
         'cached' => $usage['cachedContentTokenCount'] ?? 0,
         'reasoning' => $usage['thoughtsTokenCount'] ?? 0,
      ], $payload['candidates'][0]['finishReason'] ?? null, $formatFallback, (int) $resp['status'], $payload);
   }

   public function listModels(): array {
      if ($this->apiKey === '') {
         return self::modelsResult(false, 0, [], [], sprintf(__('Chave do provedor %s não configurada.', 'nextool'), $this->label));
      }
      $resp = $this->request('GET', (string) $this->def['models_url'], ['x-goog-api-key' => $this->apiKey]);
      if (!self::isOk($resp)) {
         $f = $this->failFromResponse($resp);
         return self::modelsResult(false, (int) $resp['status'], [], [], (string) $f['error']);
      }
      $ids = [];
      $labels = [];
      foreach ((array) ($resp['json']['models'] ?? []) as $m) {
         $id = (string) preg_replace('/^models\//', '', (string) ($m['name'] ?? ''));
         $methods = (array) ($m['supportedGenerationMethods'] ?? []);
         if ($id === '' || !in_array('generateContent', $methods, true) || !PluginNextoolLlmProviders::keepGeminiModel($id)) {
            continue;
         }
         $ids[] = $id;
         $labels[$id] = (string) ($m['displayName'] ?? '');
      }
      return self::modelsResult(true, (int) $resp['status'], $ids, $labels);
   }
}
