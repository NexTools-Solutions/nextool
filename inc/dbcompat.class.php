<?php
/**
 * NexTool -- compatibilidade de banco entre GLPI 10 e 11 para TEXTO LIVRE vindo de fora.
 *
 * No GLPI 11 o `$DB->insert()`/`update()` escapa os valores. No GLPI 10 não: o `DBmysql::quoteValue()` só põe
 * aspas em volta, porque conta com o Sanitizer que o core aplica a `$_POST`/`$_GET`. Texto que NÃO passou por
 * ele (mensagem de exceção, resposta de API externa, cabeçalho HTTP, título de CVE) quebra o INSERT no primeiro
 * apóstrofo, e em código que engole o erro a linha simplesmente não é gravada. Foi o que aconteceu no
 * cvescanvulnerability (títulos de CVE; corrigido no módulo em 2026-09-24).
 *
 * Use SÓ para texto que não veio do Sanitizer do core: valor de formulário no GLPI 10 já chega escapado, e
 * escapar de novo gravaria a barra.
 *
 * @since 6.27.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolDbCompat {

   /** O banco deste GLPI escapa sozinho os valores do insert/update? (GLPI 11+) */
   public static function escapesValues(): bool {
      return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '11.0.0-dev', '>=');
   }

   /**
    * Linha pronta para `$DB->insert()`/`update()`: no GLPI 10, as strings saem escapadas; no 11, a linha volta
    * como veio. Números, nulos e booleanos não mudam.
    *
    * @param array<string,mixed> $row
    * @return array<string,mixed>
    */
   public static function row(array $row): array {
      global $DB;
      if (self::escapesValues() || !is_object($DB) || !method_exists($DB, 'escape')) {
         return $row;
      }
      foreach ($row as $key => $value) {
         // Nome de classe com namespace que existe (itemtype `Glpi\...`): o `quoteValue()` do GLPI 10 já o escapa
         // sozinho; escapar aqui gravaria a barra em dobro.
         if (is_string($value) && !self::isNsClassIdentifier($value)) {
            $row[$key] = $DB->escape($value);
         }
      }

      return $row;
   }

   /**
    * Valores que chegam para ser gravados, no formato cru. No GLPI 10 o valor de formulário vem escapado pelo core
    * (`d\'Ávila`); montar um JSON com ele dobra a barra e o apóstrofo volta a quebrar o SQL. Desfaz o escape só
    * onde a string inteira está escapada (a mesma regra do core, `Sanitizer::dbUnescape`): valor cru gerado pelo
    * código passa igual. No GLPI 11 não há o que desfazer.
    *
    * @param array<mixed> $values
    * @return array<mixed>
    */
   public static function unescapeIncoming(array $values): array {
      if (self::escapesValues() || !class_exists('Glpi\\Toolbox\\Sanitizer')
          || !method_exists('Glpi\\Toolbox\\Sanitizer', 'dbUnescapeRecursive')) {
         return $values;
      }

      return \Glpi\Toolbox\Sanitizer::dbUnescapeRecursive($values);
   }

   private static function isNsClassIdentifier(string $value): bool {
      return strpos($value, '\\') !== false
         && class_exists('Glpi\\Toolbox\\Sanitizer')
         && method_exists('Glpi\\Toolbox\\Sanitizer', 'isNsClassOrCallableIdentifier')
         && \Glpi\Toolbox\Sanitizer::isNsClassOrCallableIdentifier($value);
   }
}
