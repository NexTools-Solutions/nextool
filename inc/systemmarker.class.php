<?php
declare(strict_types=1);
/**
 * NexTool - Marca de sistema no input (`_nextool_system` / `_nextool_system_task`)
 *
 * Convenção dos módulos: o que é gravado por CÓDIGO (motor de fluxo, automação,
 * transcrição, timer) leva uma dessas chaves no input, e os guardas de
 * pre_item_add / pre_item_update dos módulos deixam passar.
 *
 * O problema (nextool-dev#317): esses guardas leem o input CRU. O core chama o
 * hook antes do prepareInputFor*, e não filtra chave desconhecida do pedido; quem
 * pode editar o item acrescentava a chave ao formulário, à query string, à ação
 * em massa ou ao corpo da API e o guarda saía da frente.
 *
 * A regra, em fonte única: a marca só vale quando é o código que a põe. Se a
 * PRÓPRIA requisição HTTP traz a chave vinda de fora, o despachante da base a
 * remove do input antes de chamar os guardas dos módulos. Quem forja só perde a
 * isenção da própria requisição (inclusive a das gravações internas que ela
 * dispare); as requisições normais seguem como sempre.
 *
 * Lógica promovida do módulo `ticketrules` (issue #11 de lá), que já recusava a
 * marca forjada só para as próprias travas.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSystemMarker {

   /** Chaves de input que isentam uma gravação dos guardas dos módulos. */
   public const MARKERS = ['_nextool_system', '_nextool_system_task'];

   private const MAX_DEPTH = 8;

   /** Cache por requisição de requestCarriesMarker(). */
   private static ?bool $requestCarries = null;

   /** Uma linha de log por requisição. */
   private static bool $logged = false;

   /**
    * Fonte simulada (só testes): ['bags' => array[], 'raw' => string, 'type' => string].
    *
    * @var array{bags: array, raw: string, type: string}|null
    */
   private static ?array $testSource = null;

   /** O input (array ou objeto com ->input) leva alguma das marcas? */
   public static function inputHasMarker($itemOrInput): bool {
      $input = self::inputOf($itemOrInput);
      foreach (self::MARKERS as $marker) {
         if (!empty($input[$marker])) {
            return true;
         }
      }
      return false;
   }

   /**
    * A marca neste input é confiável? Falso quando não há marca ou quando a
    * requisição em curso a trouxe de fora. Para guarda de módulo que queira
    * perguntar à base em vez de ler o input.
    */
   public static function isTrusted($itemOrInput): bool {
      return self::inputHasMarker($itemOrInput) && !self::requestCarriesMarker();
   }

   /**
    * Remove do input as marcas quando a requisição as trouxe de fora. Chamado pelo
    * despachante antes dos guardas de pré-gravação. Sem marca no input, não custa
    * nada (a requisição nem é inspecionada).
    *
    * @param mixed $itemOrInput CommonDBTM (alterado no lugar) ou array de input
    * @return mixed O mesmo objeto, ou o array já sem as marcas
    */
   public static function stripForged($itemOrInput) {
      if (!self::inputHasMarker($itemOrInput) || !self::requestCarriesMarker()) {
         return $itemOrInput;
      }
      self::logOnce($itemOrInput);
      if (is_object($itemOrInput)) {
         if (is_array($itemOrInput->input ?? null)) {
            foreach (self::MARKERS as $marker) {
               unset($itemOrInput->input[$marker]);
            }
         }
         return $itemOrInput;
      }
      if (is_array($itemOrInput)) {
         foreach (self::MARKERS as $marker) {
            unset($itemOrInput[$marker]);
         }
      }
      return $itemOrInput;
   }

   /**
    * A requisição HTTP em curso traz uma das marcas vinda de fora?
    *
    * Olha $_GET, $_POST e $_REQUEST em qualquer nível (`input[_nextool_system]` da
    * ação em massa), valores de texto que carregam JSON (o `uploadManifest` do
    * upload pela API) e o corpo cru: JSON decodificado (a chave pode vir com escape
    * unicode, então comparar o texto cru não basta) ou formulário (PUT da API, que
    * não preenche $_POST). Linha de comando e cron nunca trazem marca de fora.
    */
   public static function requestCarriesMarker(): bool {
      if (self::$requestCarries !== null) {
         return self::$requestCarries;
      }
      if (self::$testSource === null && function_exists('isCommandLine') && isCommandLine()) {
         return self::$requestCarries = false;
      }

      $bags = self::$testSource !== null
         ? self::$testSource['bags']
         : [$_GET ?? [], $_POST ?? [], $_REQUEST ?? []];
      foreach ($bags as $bag) {
         if (is_array($bag) && self::arrayCarriesMarker($bag, 0)) {
            return self::$requestCarries = true;
         }
      }

      return self::$requestCarries = self::textCarriesMarker(self::rawBody());
   }

   /** Corpo cru da requisição ('' em multipart, que o PHP não reexpõe). */
   private static function rawBody(): string {
      if (self::$testSource !== null) {
         return self::$testSource['raw'];
      }
      $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
      if (str_contains($type, 'multipart/')) {
         return '';
      }
      $raw = @file_get_contents('php://input');
      return is_string($raw) ? $raw : '';
   }

   private static function arrayCarriesMarker(array $data, int $depth): bool {
      if ($depth > self::MAX_DEPTH) {
         // Estrutura mais funda do que qualquer pedido legítimo: não dá para afirmar
         // que está limpa, então vale como marca de fora (fail-closed).
         return true;
      }
      foreach ($data as $key => $value) {
         if (is_string($key) && in_array($key, self::MARKERS, true)) {
            return true;
         }
         if (is_array($value)) {
            if (self::arrayCarriesMarker($value, $depth + 1)) {
               return true;
            }
         } elseif (is_string($value) && self::textCarriesMarker($value)) {
            return true;
         }
      }
      return false;
   }

   /**
    * Um texto (corpo cru ou valor de campo) carrega a marca como CHAVE?
    * Só o que parece JSON ou formulário é interpretado: o nome da marca citado no
    * meio de um acompanhamento não é chave de input e não conta.
    */
   private static function textCarriesMarker(string $text): bool {
      $text = trim($text);
      if ($text === '') {
         return false;
      }
      $first = $text[0];
      if ($first === '{' || $first === '[') {
         $decoded = json_decode($text, true, 64);
         if (is_array($decoded)) {
            return self::arrayCarriesMarker($decoded, 0);
         }
         // JSON que não decodifica: o core também não o lê como input.
         return false;
      }
      // Formulário (a=b&c[d]=e). Só vale a pena interpretar se o nome da marca
      // aparece no texto, cru ou com escape de URL (%5F = "_").
      $probe = strtolower(rawurldecode($text));
      if (!str_contains($probe, '_nextool_system') || !str_contains($text, '=')) {
         return false;
      }
      $parsed = [];
      parse_str($text, $parsed);
      foreach (self::MARKERS as $marker) {
         if (array_key_exists($marker, $parsed)) {
            return true;
         }
      }
      foreach ($parsed as $value) {
         if (is_array($value) && self::keysCarryMarker($value, 0)) {
            return true;
         }
      }
      return false;
   }

   /** Só chaves, sem reinterpretar valores (evita recursão em texto dentro de texto). */
   private static function keysCarryMarker(array $data, int $depth): bool {
      if ($depth > self::MAX_DEPTH) {
         return true;
      }
      foreach ($data as $key => $value) {
         if (is_string($key) && in_array($key, self::MARKERS, true)) {
            return true;
         }
         if (is_array($value) && self::keysCarryMarker($value, $depth + 1)) {
            return true;
         }
      }
      return false;
   }

   private static function inputOf($itemOrInput): array {
      if (is_object($itemOrInput)) {
         return is_array($itemOrInput->input ?? null) ? $itemOrInput->input : [];
      }
      return is_array($itemOrInput) ? $itemOrInput : [];
   }

   private static function logOnce($itemOrInput): void {
      if (self::$logged) {
         return;
      }
      self::$logged = true;
      try {
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[SystemMarker] marca de sistema vinda da requisição foi ignorada (itemtype=%s, usuário=%d, caminho=%s)',
            is_object($itemOrInput) ? get_class($itemOrInput) : 'input',
            (int) Session::getLoginUserID(),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)
         ));
      } catch (Throwable $e) {
         // log é melhor esforço: nunca derruba a gravação
      }
   }

   /**
    * Só para testes: simula a requisição (bags no lugar de $_GET/$_POST/$_REQUEST
    * e o corpo cru) e zera o cache. null devolve a leitura real.
    *
    * @param array<int, array>|null $bags
    */
   public static function simulateRequestForTests(?array $bags, string $raw = ''): void {
      self::$testSource     = $bags === null ? null : ['bags' => $bags, 'raw' => $raw, 'type' => ''];
      self::$requestCarries = null;
      self::$logged         = false;
   }
}
