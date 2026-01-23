<?php
/**
 * Implementação do provedor Gemini (Google) para o módulo AI Assist.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/providerinterface.class.php';

class PluginNextoolAiassistGeminiProvider implements PluginNextoolAiassistProviderInterface {

   /** @var string */
   private $apiKey;

   /** @var string */
   private $model;

   /** @var int */
   private $timeout;

   /** @var string */
   private $endpointBase;

   public function __construct(array $config) {
      $this->apiKey = $config['api_key'] ?? '';
      $this->model = $config['model'] ?? 'gemini-1.5-flash-latest';
      $this->timeout = max(5, (int)($config['timeout_seconds'] ?? 25));
      $this->endpointBase = rtrim($config['gemini_endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models', '/');
   }

   /**
    * {@inheritdoc}
    */
   public function chat(array $messages, array $options = []): array {
      if (empty($this->apiKey)) {
         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => [],
            'error' => __('Chave da API Gemini não configurada.', 'nextool')
         ];
      }

      $model = $options['model'] ?? $this->model;
      $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.2;
      $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : 600;

      $systemInstruction = $this->extractSystemInstruction($messages);
      $contents = $this->buildContents($messages);

      if (empty($contents)) {
         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => [],
            'error' => __('Nenhuma mensagem válida para enviar ao Gemini.', 'nextool')
         ];
      }

      $body = [
         'contents' => $contents,
         'generationConfig' => [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxTokens,
         ],
      ];

      if (!empty($options['response_mime_type'])) {
         $body['generationConfig']['responseMimeType'] = $options['response_mime_type'];
      }

      if (!empty($systemInstruction)) {
         $body['systemInstruction'] = [
            'parts' => [
               ['text' => $systemInstruction],
            ],
         ];
      }

      try {
         $client = new \GuzzleHttp\Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
         ]);

         $endpoint = sprintf(
            '%s/%s:generateContent',
            $this->endpointBase,
            rawurlencode($model)
         );

         $response = $client->request('POST', $endpoint, [
            'headers' => [
               'Content-Type' => 'application/json',
               'x-goog-api-key' => $this->apiKey,
            ],
            'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
         ]);

         $status = $response->getStatusCode();
         $payload = json_decode((string)$response->getBody(), true);

         if ($status < 200 || $status >= 300) {
            return [
               'success' => false,
               'content' => '',
               'tokens_prompt' => 0,
               'tokens_completion' => 0,
               'raw' => $payload,
               'error' => sprintf(__('Gemini respondeu com status %s.', 'nextool'), $status)
            ];
         }

         $parts = $payload['candidates'][0]['content']['parts'] ?? [];
         $texts = [];
         foreach ($parts as $part) {
            if (!empty($part['text'])) {
               $texts[] = $part['text'];
            }
         }
         $content = trim(implode("\n", $texts));
         $usage = $payload['usageMetadata'] ?? [];
         $finishReason = $payload['candidates'][0]['finishReason'] ?? null;

         return [
            'success' => ($content !== ''),
            'content' => $content,
            'tokens_prompt' => (int)($usage['promptTokenCount'] ?? 0),
            'tokens_completion' => (int)($usage['candidatesTokenCount'] ?? 0),
            'finish_reason' => $finishReason,
            'raw' => $payload,
            'error' => $content === '' ? __('Resposta vazia do Gemini.', 'nextool') : null
         ];
      } catch (\GuzzleHttp\Exception\RequestException $e) {
         $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
         $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
         $decodedError = json_decode($body, true);
         $apiMessage = $decodedError['error']['message'] ?? null;

         Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
            '[GEMINI] RequestException status=%s body=%s',
            $status ?: 'n/a',
            $body
         ));

         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => ['status' => $status, 'body' => $body],
            'error' => $apiMessage ?: __('Falha ao conectar na API do Gemini. Verifique a chave e permissões.', 'nextool')
         ];
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
            '[GEMINI] Erro inesperado: %s',
            $e->getMessage()
         ));

         return [
            'success' => false,
            'content' => '',
            'tokens_prompt' => 0,
            'tokens_completion' => 0,
            'raw' => [],
            'error' => $e->getMessage()
         ];
      }
   }

   private function extractSystemInstruction(array $messages): string {
      $systemParts = [];
      foreach ($messages as $message) {
         $role = $message['role'] ?? '';
         if ($role === 'system' && !empty($message['content'])) {
            $systemParts[] = trim((string)$message['content']);
         }
      }

      return trim(implode("\n\n", array_filter($systemParts)));
   }

   private function buildContents(array $messages): array {
      $contents = [];
      foreach ($messages as $message) {
         $role = $message['role'] ?? '';
         $content = trim((string)($message['content'] ?? ''));
         if ($content === '' || $role === 'system') {
            continue;
         }

         $geminiRole = $role === 'assistant' ? 'model' : 'user';
         $contents[] = [
            'role' => $geminiRole,
            'parts' => [
               ['text' => $content],
            ],
         ];
      }

      return $contents;
   }
}

