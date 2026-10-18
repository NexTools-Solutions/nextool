<?php
declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Mensagens do log da base (glpi_plugin_nextool_main_logs) independentes de idioma.
 *
 * A mensagem chega já traduzida no idioma de quem fez a ação (ou em português, quando vem da
 * ContainerAPI). Para quem lê em outro idioma ela ficava no idioma de quem gravou. Os msgids
 * da base são as frases em português, então:
 *  - na GRAVAÇÃO, toSource() devolve a frase ao msgid (busca reversa no catálogo carregado,
 *    inclusive frases com %s/%d);
 *  - na EXIBIÇÃO, toSession() traduz o msgid para o idioma da sessão. Vale também para o
 *    histórico gravado em português antes desta mudança.
 * Texto que não casa com nenhum msgid (erro cru de rede, dado do usuário) passa intacto.
 */
final class PluginNextoolLogMessage {

   private const DOMAIN = 'nextool';

   /** Letras fora dos placeholders exigidas para casar um modelo com %s (evita casar "%s: %s"). */
   private const MIN_TEMPLATE_LETTERS = 8;

   /** Planos que a validação acrescenta entre parênteses ("... (DESENVOLVIMENTO)"). */
   private const PLAN_KEYS = ['FREE', 'DESENVOLVIMENTO', 'LICENCIADO', 'ENTERPRISE', 'BUSINESS'];

   /** @var array<string, array<string, string>> locale => [msgstr => msgid] */
   private static array $reverse = [];

   /** @var array<string, array<int, array{regex: string, positions: array<int, int>, format: string, target: string}>> */
   private static array $templates = [];

   /**
    * Frases fixas que a ContainerAPI devolve em português e a base grava no log. Nunca é
    * chamado: existe só para o xgettext levar os textos ao POT da base.
    */
   private static function serverMessagesForExtraction(): void {
      __('Snapshot consolidado das licenças do ambiente.', 'nextool');
      __('Licença válida para o módulo solicitado.', 'nextool');
      __('Nenhuma licença vinculada a este ambiente.', 'nextool');
      __('Nenhuma licença ativa encontrada para este ambiente.', 'nextool');
      __('Módulo não disponível para o conjunto de licenças ativas.', 'nextool');
      __('Ambiente sem vínculo ativo para a licença. Entre em contato com o suporte para reativar.', 'nextool');
      __('Muitas requisições de validação. Tente novamente mais tarde.', 'nextool');
      __('Este ambiente já está registrado. Entre em contato com o suporte NexTool para restabelecer o acesso.', 'nextool');
   }

   /**
    * Frase traduzida (idioma da sessão) -> msgid. Sem correspondência, devolve o texto como veio.
    */
   public static function toSource(?string $text): ?string {
      if ($text === null || trim($text) === '') {
         return $text;
      }
      $catalog = self::catalog();
      if ($catalog === []) {
         return $text;
      }
      $locale = self::locale();

      if (!isset(self::$reverse[$locale])) {
         $reverse = [];
         foreach ($catalog as $msgid => $msgstr) {
            if (is_string($msgstr) && $msgstr !== '' && !isset($reverse[$msgstr])) {
               $reverse[$msgstr] = (string) $msgid;
            }
         }
         self::$reverse[$locale] = $reverse;
      }
      if (isset(self::$reverse[$locale][$text])) {
         return self::$reverse[$locale][$text];
      }

      [$prefix, $plan] = self::splitPlan($text);
      if ($plan !== null && isset(self::$reverse[$locale][$prefix])) {
         return self::$reverse[$locale][$prefix] . ' (' . $plan . ')';
      }

      // Modelo com placeholder: casa pela tradução e remonta o msgid com os mesmos valores.
      return self::matchTemplates($text, 'reverse') ?? $text;
   }

   /**
    * msgid (ou frase já no idioma da sessão) -> idioma da sessão.
    */
   public static function toSession(?string $text): string {
      if ($text === null || $text === '') {
         return (string) $text;
      }
      $translated = __($text, self::DOMAIN);
      if ($translated !== $text) {
         return $translated;
      }

      [$prefix, $plan] = self::splitPlan($text);
      if ($plan !== null) {
         return sprintf('%s (%s)', self::toSession($prefix), PluginNextoolConfigViewState::getPlanLabel($plan));
      }

      return self::matchTemplates($text, 'forward') ?? $text;
   }

   /**
    * "Frase (PLANO)" -> ["Frase", "PLANO"]; sem sufixo de plano conhecido -> [$text, null].
    *
    * @return array{0: string, 1: string|null}
    */
   private static function splitPlan(string $text): array {
      if (preg_match('/^(.+) \(([A-Z_]+)\)$/su', $text, $m) && in_array($m[2], self::PLAN_KEYS, true)) {
         return [$m[1], $m[2]];
      }
      return [$text, null];
   }

   /**
    * @param string $direction 'forward' (msgid -> tradução) ou 'reverse' (tradução -> msgid)
    */
   private static function matchTemplates(string $text, string $direction): ?string {
      $key = self::locale() . '|' . $direction;
      if (!isset(self::$templates[$key])) {
         self::$templates[$key] = self::buildTemplates($direction);
      }
      foreach (self::$templates[$key] as $template) {
         if (!preg_match($template['regex'], $text, $m)) {
            continue;
         }
         $args = [];
         foreach ($template['positions'] as $group => $position) {
            $args[$position - 1] = $m[$group];
         }
         ksort($args);
         try {
            $result = vsprintf($template['target'], array_values($args));
         } catch (\Throwable $e) {
            // Tradução com placeholders diferentes do msgid: tenta o próximo modelo.
            continue;
         }
         if ($result !== '') {
            return $result;
         }
      }
      return null;
   }

   /**
    * @return array<int, array{regex: string, positions: array<int, int>, format: string, target: string}>
    */
   private static function buildTemplates(string $direction): array {
      $templates = [];
      foreach (self::catalog() as $msgid => $msgstr) {
         $msgid = (string) $msgid;
         if (!is_string($msgstr) || $msgstr === '' || !str_contains($msgid, '%')) {
            continue;
         }
         // forward: o texto gravado é o msgid; reverse: é a tradução da sessão.
         [$source, $target] = $direction === 'forward' ? [$msgid, $msgstr] : [$msgstr, $msgid];
         if ($source === $target) {
            continue;
         }
         $compiled = self::compile($source);
         if ($compiled === null) {
            continue;
         }
         $compiled['format'] = $source;
         $compiled['target'] = $target;
         $templates[] = $compiled;
      }
      // Mais texto fixo primeiro: o modelo mais específico ganha.
      usort($templates, static fn(array $a, array $b): int => $b['letters'] <=> $a['letters']);
      return array_map(static function (array $t): array {
         unset($t['letters']);
         return $t;
      }, $templates);
   }

   /**
    * Formato printf -> regex ancorada. Aceita %s, %d, %u e posicionais (%1$s).
    *
    * @return array{regex: string, positions: array<int, int>, letters: int}|null
    */
   private static function compile(string $format): ?array {
      $parts = preg_split('/(%%|%(?:\d+\$)?[sdu])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
      if ($parts === false) {
         return null;
      }
      $regex = '';
      $positions = [];
      $literal = '';
      $group = 0;
      $next = 1;
      foreach ($parts as $part) {
         if ($part === '%%') {
            $regex .= '%';
            $literal .= '%';
            continue;
         }
         if (preg_match('/^%(?:(\d+)\$)?([sdu])$/', $part, $p)) {
            $group++;
            $positions[$group] = $p[1] !== '' ? (int) $p[1] : $next++;
            $regex .= $p[2] === 's' ? '(.+?)' : '(-?\d+)';
            continue;
         }
         $regex .= preg_quote($part, '/');
         $literal .= $part;
      }
      $letters = preg_match_all('/\p{L}/u', $literal);
      if ($group === 0 || $letters < self::MIN_TEMPLATE_LETTERS) {
         return null;
      }
      return ['regex' => '/^' . $regex . '$/su', 'positions' => $positions, 'letters' => $letters];
   }

   /**
    * Catálogo da base no idioma da sessão (msgid => msgstr; plurais ficam de fora).
    *
    * @return array<string, mixed>
    */
   private static function catalog(): array {
      global $TRANSLATE;

      if (!is_object($TRANSLATE) || !method_exists($TRANSLATE, 'getAllMessages')) {
         return [];
      }
      try {
         $messages = $TRANSLATE->getAllMessages(self::DOMAIN);
      } catch (\Throwable $e) {
         return [];
      }
      if ($messages instanceof \ArrayObject) {
         return $messages->getArrayCopy();
      }
      return is_array($messages) ? $messages : [];
   }

   private static function locale(): string {
      return (string) ($_SESSION['glpilanguage'] ?? '');
   }
}
