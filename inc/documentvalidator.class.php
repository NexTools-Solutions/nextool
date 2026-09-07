<?php
/**
 * NexTool -- Validacao e formatacao de CPF e CNPJ (fonte unica no servidor).
 *
 * Promovido do modulo formextender (`PluginNextoolFormextenderDocumentValidator`),
 * que se declarava "a fonte unica da regra" enquanto o digitalsignature
 * reimplementava o mesmo modulo 11 em `normalizeCpf()` -- regra fiscal que ja
 * mudou uma vez (IN RFB 2.229/2024, CNPJ alfanumerico) em dois lugares
 * (audit-deep 2026-09-06, nextool-dev#259). Os modulos passam a delegar aqui.
 *
 * CNPJ ALFANUMERICO: as 12 primeiras posicoes aceitam letras; so os 2 digitos
 * verificadores continuam numericos. O calculo segue modulo 11, com cada
 * caractere valendo (ASCII - 48): '0'=>0 ... '9'=>9, 'A'=>17 ... 'Z'=>42. O CNPJ
 * numerico legado e subconjunto do novo formato.
 *
 * @since 6.15.0
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolDocumentValidator {

   public const TYPE_CPF  = 'cpf';
   public const TYPE_CNPJ = 'cnpj';

   public const CPF_LENGTH  = 11;
   public const CNPJ_LENGTH = 14;

   /** Pesos do 1o DV do CNPJ (12 posicoes de base). */
   private const CNPJ_WEIGHTS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

   /** Pesos do 2o DV do CNPJ (13 posicoes de base). */
   private const CNPJ_WEIGHTS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

   /**
    * Remove mascara e ruido, deixando so [0-9A-Z]. Nao filtra por tipo: um CPF
    * com letra sobrevive aqui e e reprovado por isValidCpf() -- melhor que sumir
    * com o caractere e validar algo que o usuario nao digitou.
    */
   public static function normalize(string $value): string {
      return preg_replace('/[^0-9A-Z]/', '', strtoupper($value)) ?? '';
   }

   public static function isValidCpf(string $value): bool {
      $digits = self::normalize($value);

      if (strlen($digits) !== self::CPF_LENGTH || !ctype_digit($digits)) {
         return false;
      }
      // 000.000.000-00, 111.111.111-11 etc. passam no modulo 11 mas nao existem.
      if (preg_match('/^(\d)\1{10}$/', $digits)) {
         return false;
      }
      foreach ([9, 10] as $position) {
         $sum = 0;
         for ($i = 0; $i < $position; $i++) {
            $sum += (int)$digits[$i] * (($position + 1) - $i);
         }
         if ((int)$digits[$position] !== self::checkDigit($sum)) {
            return false;
         }
      }
      return true;
   }

   public static function isValidCnpj(string $value): bool {
      $n = self::normalize($value);

      // 12 posicoes alfanumericas + 2 digitos verificadores numericos.
      if (!preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $n)) {
         return false;
      }
      // Base toda repetida (000000000000xx, AAAAAAAAAAAAxx) nao e CNPJ real.
      if (preg_match('/^(.)\1{11}$/', substr($n, 0, 12))) {
         return false;
      }
      if ((int)$n[12] !== self::cnpjCheckDigit(substr($n, 0, 12), self::CNPJ_WEIGHTS_DV1)) {
         return false;
      }
      return (int)$n[13] === self::cnpjCheckDigit(substr($n, 0, 13), self::CNPJ_WEIGHTS_DV2);
   }

   public static function isValid(string $value, string $type): bool {
      return $type === self::TYPE_CNPJ ? self::isValidCnpj($value) : self::isValidCpf($value);
   }

   /**
    * CPF normalizado (11 digitos) ou null se invalido -- o que o gate de
    * assinatura do digitalsignature precisa.
    */
   public static function normalizeCpf(string $value): ?string {
      $digits = preg_replace('/\D/', '', $value) ?? '';
      return self::isValidCpf($digits) ? $digits : null;
   }

   /**
    * Aplica a mascara do tipo. Valor sem o tamanho esperado volta como veio --
    * formatar um documento incompleto daria a impressao de que foi aceito.
    */
   public static function format(string $value, string $type): string {
      $n = self::normalize($value);

      if ($type === self::TYPE_CNPJ) {
         if (strlen($n) !== self::CNPJ_LENGTH) {
            return $value;
         }
         return substr($n, 0, 2) . '.' . substr($n, 2, 3) . '.' . substr($n, 5, 3)
            . '/' . substr($n, 8, 4) . '-' . substr($n, 12, 2);
      }
      if (strlen($n) !== self::CPF_LENGTH) {
         return $value;
      }
      return substr($n, 0, 3) . '.' . substr($n, 3, 3) . '.' . substr($n, 6, 3) . '-' . substr($n, 9, 2);
   }

   /** Modulo 11 padrao: resto < 2 vira 0. */
   private static function checkDigit(int $sum): int {
      $rest = $sum % 11;
      return $rest < 2 ? 0 : 11 - $rest;
   }

   /** Modulo 11 do CNPJ, com o caractere valendo (ASCII - 48). */
   private static function cnpjCheckDigit(string $base, array $weights): int {
      $sum = 0;
      $len = strlen($base);
      for ($i = 0; $i < $len; $i++) {
         $sum += (ord($base[$i]) - 48) * $weights[$i];
      }
      return self::checkDigit($sum);
   }
}
