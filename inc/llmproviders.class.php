<?php
/**
 * Registro dos provedores de IA do modo "Sua chave" e fábrica dos conectores (fonte única desde
 * nextool-mod-aiassist#73: antes cada módulo tinha a sua cópia, que já divergia).
 *
 * Um provedor novo = uma entrada em PROVIDERS + a classe do conector. Os módulos guardam só o que é deles (coluna da
 * chave, texto de ajuda, preferência de modelo); rótulo, endereço, versão da API, autenticação e detecção de família
 * de modelo moram aqui.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolLlmProviders {

   public const OPENAI        = 'openai';
   public const ANTHROPIC     = 'anthropic';
   public const GEMINI        = 'gemini';
   public const OPENAI_COMPAT = 'openai_compat';
   public const AZURE         = 'azure';

   /** Versão da API da Anthropic (header anthropic-version). */
   public const ANTHROPIC_API_VERSION = '2023-06-01';

   /**
    * class: conector; chat_url: endpoint padrão da conversa; models_url: lista de modelos (null = sem lista pública);
    * auth: como a chave vai no cabeçalho; custom_url: o endereço é digitado pelo administrador (passa pelo UrlGuard).
    */
   private const PROVIDERS = [
      self::OPENAI => [
         'class' => 'PluginNextoolLlmOpenAi', 'auth' => 'bearer', 'custom_url' => false,
         'chat_url' => 'https://api.openai.com/v1/chat/completions', 'models_url' => 'https://api.openai.com/v1/models',
      ],
      self::ANTHROPIC => [
         'class' => 'PluginNextoolLlmAnthropic', 'auth' => 'x-api-key', 'custom_url' => false,
         'chat_url' => 'https://api.anthropic.com/v1/messages', 'models_url' => 'https://api.anthropic.com/v1/models?limit=100',
      ],
      self::GEMINI => [
         'class' => 'PluginNextoolLlmGemini', 'auth' => 'x-goog-api-key', 'custom_url' => false,
         'chat_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
         'models_url' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200',
      ],
      self::OPENAI_COMPAT => [
         'class' => 'PluginNextoolLlmOpenAi', 'auth' => 'bearer', 'custom_url' => true, 'chat_url' => '', 'models_url' => null,
      ],
      self::AZURE => [
         'class' => 'PluginNextoolLlmOpenAi', 'auth' => 'api-key', 'custom_url' => true, 'chat_url' => '', 'models_url' => null,
      ],
   ];

   /** @return string[] chaves dos provedores conhecidos */
   public static function keys(): array {
      return array_keys(self::PROVIDERS);
   }

   public static function exists(string $provider): bool {
      return isset(self::PROVIDERS[$provider]);
   }

   /** @return array{class:string,auth:string,custom_url:bool,chat_url:string,models_url:?string}|null */
   public static function get(string $provider): ?array {
      return self::PROVIDERS[$provider] ?? null;
   }

   /** Rótulo do provedor para a tela e para as mensagens. */
   public static function label(string $provider): string {
      switch ($provider) {
         case self::OPENAI:        return __('OpenAI', 'nextool');
         case self::ANTHROPIC:     return __('Anthropic (Claude)', 'nextool');
         case self::GEMINI:        return __('Gemini', 'nextool');
         case self::OPENAI_COMPAT: return __('Compatível com OpenAI', 'nextool');
         case self::AZURE:         return __('Azure OpenAI', 'nextool');
      }
      return $provider;
   }

   /**
    * Conector do provedor. $conn: api_key, model, timeout (s), endpoint (sobrescreve o padrão; obrigatório no
    * compatível e no Azure), auth (só OpenAI: bearer|api-key|none), label, reasoning (?bool, família com raciocínio
    * quando o nome do modelo não diz, ex.: deployment do Azure), reasoning_effort, log (canal do Toolbox::logInFile).
    *
    * @throws InvalidArgumentException provedor desconhecido
    */
   public static function connector(string $provider, array $conn): PluginNextoolLlmConnector {
      $def = self::get($provider);
      if ($def === null) {
         throw new InvalidArgumentException('Provedor de IA desconhecido: ' . $provider);
      }
      require_once __DIR__ . '/llmconnector.class.php';
      require_once __DIR__ . '/' . strtolower(substr($def['class'], strlen('PluginNextool'))) . '.class.php';
      $conn['provider'] = $provider;
      $conn += ['auth' => $def['auth'], 'url_guard' => $def['custom_url']];
      if (!isset($conn['endpoint']) || trim((string) $conn['endpoint']) === '') {
         $conn['endpoint'] = $def['chat_url'];
      }
      $class = $def['class'];
      return new $class($conn, $def);
   }

   // ------------------------------------------------------------------ família de modelo

   /** OpenAI gpt-5/gpt-6/o-series: recusa `max_tokens` e temperature != 1 e gasta raciocínio dentro da saída. */
   public static function isReasoningFamily(string $model): bool {
      return (bool) preg_match('/^(gpt-5|gpt-6|o[1-9](-|$)|chatgpt-5)/i', $model);
   }

   /** reasoning_effort padrão ("auto"): minimal na família gpt-5, low nas demais (o-series não aceita minimal). */
   public static function defaultReasoningEffort(string $model): string {
      return preg_match('/^gpt-5/i', $model) ? 'minimal' : 'low';
   }

   /** Anthropic 5.x: rejeita ferramenta forçada e temperatura; a saída estruturada vai em output_config.format. */
   public static function isAnthropicStructuredFamily(string $model): bool {
      return (bool) preg_match('/^claude-(haiku|sonnet|opus)-5-5|^claude-(fable|mythos)-5/', $model);
   }

   /** JSON Schema -> subconjunto aceito pelo Gemini (sem additionalProperties, recursivo). */
   public static function geminiSchema(array $schema): array {
      unset($schema['additionalProperties']);
      if (isset($schema['properties']) && is_array($schema['properties'])) {
         foreach ($schema['properties'] as $k => $v) {
            $schema['properties'][$k] = self::geminiSchema((array) $v);
         }
      }
      if (isset($schema['items']) && is_array($schema['items'])) {
         $schema['items'] = self::geminiSchema($schema['items']);
      }
      return $schema;
   }

   // ------------------------------------------------------------------ filtros da lista de modelos

   /** Modelo de conversa da OpenAI (sem áudio, imagem, embeddings, moderação, legados e variantes datadas). */
   public static function keepOpenAiModel(string $id): bool {
      if (!preg_match('/^(gpt-|o[1-9](-|$)|chatgpt-)/', $id)) {
         return false;
      }
      if (preg_match('/realtime|audio|tts|transcribe|image|embedding|search|instruct|dall|whisper|moderation|codex|computer|vision|-\d{4}-\d{2}-\d{2}$|-\d{4}$/', $id)) {
         return false;
      }
      if (preg_match('/^gpt-3\.5|^gpt-4(-|$)|^gpt-4-turbo|^gpt-4o-\d{4}/', $id)) {
         return false; // legados
      }
      return true;
   }

   public static function keepAnthropicModel(string $id): bool {
      return (bool) preg_match('/^claude-/', $id) && !preg_match('/-\d{8}$/', $id);
   }

   public static function keepGeminiModel(string $id): bool {
      if (!preg_match('/^gemini-/', $id)) {
         return false;
      }
      return !preg_match('/embedding|imagen|veo|tts|live|audio|native|image|robotics|computer|-exp-\d{2}|-\d{3}$|-8b$|^gemini-1\./', $id);
   }
}
