<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Linha de formulário dos popups de módulo
 * -------------------------------------------------------------------------
 * Fonte única do layout dos campos em popup (decisão do owner, 2026-10-26): rótulo à
 * esquerda alinhado à direita, campo à direita, "?" e "*" dentro do rótulo -- o desenho
 * dos formulários do GLPI, aprovado no popup "Credencial segura" do vaultsend. Cada módulo
 * montava o seu à mão e o estilo divergia; módulo novo ou popup retrabalhado usa esta classe.
 *
 *  - field():   uma linha "rótulo | campo" (um campo por linha);
 *  - plain():   linha sem rótulo, alinhada com os campos (checkbox solto, texto de apoio);
 *  - section(): título de bloco composto em largura cheia (lista de regras, tabela);
 *  - help():    o "?" com tooltip (o initTooltips do core inicializa; não chamar bootstrap.Tooltip).
 *
 * O breakpoint `sm` mede a JANELA, não o modal: rótulos longos pedem `modal-lg`.
 * Módulo em base antiga: min_plugin_version da base que trouxe esta classe.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolFormRow {

   /**
    * Linha "rótulo | campo". $controlHtml já vem pronto (input, Dropdown::show, input-group...).
    *
    * $opts: for (id do controle, liga o clique no rótulo), required (bool, "*"), help (texto do "?"),
    * id (da LINHA, para mostrar/esconder rótulo e campo juntos), hidden (bool, linha começa escondida
    * por style="display:none", o que .toggle()/.show() do jQuery desfazem), class (extra na linha).
    */
   public static function field(string $label, string $controlHtml, array $opts = []): string {
      $for = (string) ($opts['for'] ?? '');
      return self::open($opts)
         . '<label class="col-form-label col-sm-4 text-sm-end"' . ($for !== '' ? ' for="' . self::e($for) . '"' : '') . '>'
         . self::labelWithTail($label, !empty($opts['required']), (string) ($opts['help'] ?? ''))
         . '</label>'
         . '<div class="col-sm-8 field-container">' . $controlHtml . '</div></div>';
   }

   /** Linha sem rótulo, na coluna dos campos. Mesmas $opts de field() (id, hidden, class). */
   public static function plain(string $html, array $opts = []): string {
      return self::open($opts)
         . '<div class="col-sm-8 offset-sm-4 field-container">' . $html . '</div></div>';
   }

   /**
    * Título de um bloco composto (lista, tabela) que ocupa a largura toda, abaixo dele.
    * $opts: required, help.
    */
   public static function section(string $label, array $opts = []): string {
      return '<div class="form-label fw-semibold mt-3 mb-2">' . self::e($label)
         . (!empty($opts['required']) ? ' <span class="required">*</span>' : '')
         . (($opts['help'] ?? '') !== '' ? self::help((string) $opts['help']) : '')
         . '</div>';
   }

   /** O "?" de ajuda, no padrão do core (form-help + tooltip em texto puro). */
   public static function help(string $text): string {
      return ' <span class="form-help" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="false" data-bs-title="'
         . self::e($text) . '">?</span>';
   }

   /**
    * Rótulo com a última palavra presa ao "*" e ao "?": um rótulo longo quebra antes dela,
    * e o "?" nunca cai sozinho na linha de baixo (achado do owner, 26/10).
    */
   private static function labelWithTail(string $label, bool $required, string $help): string {
      $tail = ($required ? ' <span class="required">*</span>' : '') . ($help !== '' ? self::help($help) : '');
      if ($tail === '') {
         return self::e($label);
      }
      $label = trim($label);
      $cut   = mb_strrpos($label, ' ');
      $head  = $cut === false ? '' : mb_substr($label, 0, $cut + 1);
      $last  = $cut === false ? $label : mb_substr($label, $cut + 1);
      return self::e($head) . '<span class="text-nowrap">' . self::e($last) . $tail . '</span>';
   }

   /** Abertura da linha: o id vem antes do style (há testes que leem `id="x" style="display:none"`). */
   private static function open(array $opts): string {
      $class = trim('form-field row align-items-center mb-2 ' . (string) ($opts['class'] ?? ''));
      $id    = (string) ($opts['id'] ?? '');
      return '<div class="' . self::e($class) . '"'
         . ($id !== '' ? ' id="' . self::e($id) . '"' : '')
         . (!empty($opts['hidden']) ? ' style="display:none"' : '')
         . '>';
   }

   private static function e(string $value): string {
      return (string) PluginNextoolCompat::escape($value);
   }
}
